<?php
/**
 * Force Reset Database Schema
 * Handles foreign key constraints properly
 */

require_once 'config.php';

echo "=== Force Resetting Database Schema ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdoConn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    
    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
    
    // Step 1: Disable foreign key checks temporarily
    echo "Step 1: Disabling foreign key checks...\n";
    $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✓ Foreign key checks disabled\n";
    
    // Step 2: Drop existing tables if they exist
    echo "Step 2: Dropping existing tables...\n";
    $tables = ['users', 'devices', 'organizations'];
    foreach ($tables as $table) {
        try {
            $pdoConn->exec("DROP TABLE IF EXISTS $table");
            echo "✓ Dropped table: $table\n";
        } catch (PDOException $e) {
            echo "⚠ Warning dropping $table: " . $e->getMessage() . "\n";
        }
    }
    
    // Step 3: Remove foreign key columns from timesheet if they exist
    echo "Step 3: Cleaning timesheet table...\n";
    try {
        // Check if foreign key constraint exists and drop it
        $stmt = $pdoConn->query("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'tblt_timesheet' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($constraints as $constraint) {
            $pdoConn->exec("ALTER TABLE tblt_timesheet DROP FOREIGN KEY $constraint");
            echo "✓ Dropped foreign key constraint: $constraint\n";
        }
        
        // Drop the columns if they exist
        $stmt = $pdoConn->query("DESCRIBE tblt_timesheet");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('device_serial', $columns)) {
            $pdoConn->exec("ALTER TABLE tblt_timesheet DROP COLUMN device_serial");
            echo "✓ Dropped device_serial column\n";
        }
        
        if (in_array('organization_id', $columns)) {
            $pdoConn->exec("ALTER TABLE tblt_timesheet DROP COLUMN organization_id");
            echo "✓ Dropped organization_id column\n";
        }
        
    } catch (PDOException $e) {
        echo "⚠ Warning cleaning timesheet: " . $e->getMessage() . "\n";
    }
    
    // Step 4: Re-enable foreign key checks
    echo "Step 4: Re-enabling foreign key checks...\n";
    $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✓ Foreign key checks enabled\n\n";
    
    // Step 5: Create organizations table
    echo "Step 5: Creating organizations table...\n";
    $sql = "CREATE TABLE organizations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $pdoConn->exec($sql);
    echo "✓ Organizations table created\n";
    
    // Step 6: Create devices table
    echo "Step 6: Creating devices table...\n";
    $sql = "CREATE TABLE devices (
        id INT PRIMARY KEY AUTO_INCREMENT,
        serial_number VARCHAR(100) UNIQUE NOT NULL,
        organization_id INT,
        device_name VARCHAR(255),
        device_model VARCHAR(100),
        ip_address VARCHAR(45),
        status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
        last_seen TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
        INDEX idx_serial_number (serial_number),
        INDEX idx_organization (organization_id)
    )";
    $pdoConn->exec($sql);
    echo "✓ Devices table created\n";
    
    // Step 7: Create new users table
    echo "Step 7: Creating users table...\n";
    $sql = "CREATE TABLE users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        punching_code VARCHAR(20) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        phone_number VARCHAR(20),
        organization_id INT,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
        INDEX idx_punching_code (punching_code),
        INDEX idx_organization (organization_id)
    )";
    $pdoConn->exec($sql);
    echo "✓ Users table created\n";
    
    // Step 8: Add columns back to timesheet
    echo "Step 8: Updating timesheet table...\n";
    $pdoConn->exec("ALTER TABLE tblt_timesheet ADD COLUMN device_serial VARCHAR(100)");
    $pdoConn->exec("ALTER TABLE tblt_timesheet ADD COLUMN organization_id INT");
    $pdoConn->exec("ALTER TABLE tblt_timesheet ADD FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL");
    $pdoConn->exec("CREATE INDEX idx_organization_date ON tblt_timesheet (organization_id, date)");
    $pdoConn->exec("CREATE INDEX idx_device_serial ON tblt_timesheet (device_serial)");
    echo "✓ Timesheet table updated\n";
    
    // Step 9: Insert default organization
    echo "Step 9: Creating default organization...\n";
    $stmt = $pdoConn->prepare("INSERT INTO organizations (name, description) VALUES (?, ?)");
    $stmt->execute(['Default Organization', 'Default organization for existing devices and users']);
    echo "✓ Default organization created\n";
    
    // Step 10: Update existing timesheet records
    $defaultOrgId = $pdoConn->query("SELECT id FROM organizations WHERE name = 'Default Organization'")->fetchColumn();
    if ($defaultOrgId) {
        $pdoConn->exec("UPDATE tblt_timesheet SET organization_id = $defaultOrgId WHERE organization_id IS NULL");
        echo "✓ Updated existing timesheet records\n";
    }
    
    // Step 11: Verify the setup
    echo "\n=== VERIFICATION ===\n";
    $tables = ['organizations', 'devices', 'users', 'tblt_timesheet'];
    foreach ($tables as $table) {
        $stmt = $pdoConn->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✓ Table '$table': $count records\n";
    }
    
    // Show table structures
    echo "\n=== TABLE STRUCTURES ===\n";
    foreach ($tables as $table) {
        echo "\n--- $table ---\n";
        $stmt = $pdoConn->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  {$col['Field']} ({$col['Type']}) {$col['Null']} {$col['Key']}\n";
        }
    }
    
    echo "\n=== SCHEMA RESET COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "All tables have been recreated with proper organization support!\n";
    
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    // Re-enable foreign keys even on error
    try {
        $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (Exception $e2) {}
    exit(1);
}
?>