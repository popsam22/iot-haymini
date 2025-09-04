<?php
/**
 * Reset and Create Database Schema
 * This will drop existing users table and recreate with new schema
 */

require_once 'config.php';

echo "=== Resetting Database Schema ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Set PDO to use buffered queries to avoid the previous error
    $pdoConn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    
    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
    
    // Step 1: Drop existing users table
    echo "Step 1: Dropping existing users table...\n";
    try {
        $pdoConn->exec("DROP TABLE IF EXISTS users");
        echo "✓ Users table dropped successfully\n\n";
    } catch (PDOException $e) {
        echo "⚠ Warning dropping users table: " . $e->getMessage() . "\n\n";
    }
    
    // Step 2: Create organizations table
    echo "Step 2: Creating organizations table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS organizations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $pdoConn->exec($sql);
    echo "✓ Organizations table created\n";
    
    // Step 3: Create devices table
    echo "Step 3: Creating devices table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS devices (
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
    
    // Step 4: Create new users table with organization support
    echo "Step 4: Creating new users table...\n";
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
    echo "✓ Users table created with organization support\n";
    
    // Step 5: Add columns to timesheet if they don't exist
    echo "Step 5: Updating timesheet table...\n";
    
    // Check if columns exist
    $stmt = $pdoConn->query("DESCRIBE tblt_timesheet");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('device_serial', $columns)) {
        $pdoConn->exec("ALTER TABLE tblt_timesheet ADD COLUMN device_serial VARCHAR(100)");
        echo "✓ Added device_serial column to timesheet\n";
    } else {
        echo "⚠ device_serial column already exists\n";
    }
    
    if (!in_array('organization_id', $columns)) {
        $pdoConn->exec("ALTER TABLE tblt_timesheet ADD COLUMN organization_id INT");
        echo "✓ Added organization_id column to timesheet\n";
    } else {
        echo "⚠ organization_id column already exists\n";
    }
    
    // Add indexes
    try {
        $pdoConn->exec("CREATE INDEX IF NOT EXISTS idx_organization_date ON tblt_timesheet (organization_id, date)");
        $pdoConn->exec("CREATE INDEX IF NOT EXISTS idx_device_serial ON tblt_timesheet (device_serial)");
        echo "✓ Added indexes to timesheet\n";
    } catch (PDOException $e) {
        echo "⚠ Index creation warning: " . $e->getMessage() . "\n";
    }
    
    // Step 6: Insert default organization
    echo "Step 6: Creating default organization...\n";
    $stmt = $pdoConn->prepare("INSERT IGNORE INTO organizations (name, description) VALUES (?, ?)");
    $stmt->execute(['Default Organization', 'Default organization for existing devices and users']);
    echo "✓ Default organization created\n";
    
    // Step 7: Update existing timesheet records to use default organization
    $defaultOrgId = $pdoConn->query("SELECT id FROM organizations WHERE name = 'Default Organization'")->fetchColumn();
    if ($defaultOrgId) {
        $pdoConn->exec("UPDATE tblt_timesheet SET organization_id = $defaultOrgId WHERE organization_id IS NULL");
        echo "✓ Updated existing timesheet records with default organization\n";
    }
    
    // Step 8: Verify the setup
    echo "\n=== VERIFICATION ===\n";
    
    $tables = ['organizations', 'devices', 'users', 'tblt_timesheet'];
    foreach ($tables as $table) {
        $stmt = $pdoConn->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✓ Table '$table': $count records\n";
    }
    
    echo "\n=== SCHEMA RESET COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "You can now proceed with the implementation!\n";
    
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>