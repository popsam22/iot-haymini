<?php
/**
 * Complete Database Schema Setup
 * Creates ALL required tables for the attendance system
 */

require_once 'config.php';

echo "=== Complete Database Schema Setup ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdoConn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

    // Disable foreign key checks
    $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Step 1: Create organizations table
    echo "Step 1: Creating organizations table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS organizations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        address VARCHAR(255),
        contact_person VARCHAR(255),
        email VARCHAR(255),
        phone VARCHAR(50),
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Organizations table created\n";

    // Step 2: Create devices table
    echo "Step 2: Creating devices table...\n";
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
        INDEX idx_organization (organization_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Devices table created\n";

    // Step 3: Create users table
    echo "Step 3: Creating users table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        punching_code VARCHAR(20) NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        phone_number VARCHAR(20),
        user_type ENUM('staff', 'student') NOT NULL DEFAULT 'student',
        organization_id INT,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
        UNIQUE KEY unique_punching_code_per_org (punching_code, organization_id),
        INDEX idx_org_punching_code (organization_id, punching_code),
        INDEX idx_organization (organization_id),
        INDEX idx_user_type (user_type),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Users table created\n";

    // Step 4: Create admins table
    echo "Step 4: Creating admins table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS admins (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('super_admin', 'admin') NOT NULL,
        organization_id INT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
        INDEX idx_email (email),
        INDEX idx_organization (organization_id),
        INDEX idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Admins table created\n";

    // Step 5: Ensure tblt_timesheet table exists with proper columns
    echo "Step 5: Creating/updating tblt_timesheet table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS tblt_timesheet (
        timesheetid INT(11) NOT NULL AUTO_INCREMENT,
        punchingcode DECIMAL(11,0) NOT NULL,
        date DATE DEFAULT NULL,
        time TIME DEFAULT NULL,
        Tid VARCHAR(50) DEFAULT NULL,
        device_serial VARCHAR(100) DEFAULT NULL,
        organization_id INT DEFAULT NULL,
        PRIMARY KEY (timesheetid),
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
        INDEX idx_punchingcode (punchingcode),
        INDEX idx_date (date),
        INDEX idx_organization_date (organization_id, date),
        INDEX idx_device_serial (device_serial)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
    $pdoConn->exec($sql);
    echo "✓ Timesheet table created/verified\n";

    // Step 6: Create attendance_logs table
    echo "Step 6: Creating attendance_logs table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS attendance_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        punching_code VARCHAR(20) NOT NULL,
        organization_id INT NOT NULL,
        punch_date DATE NOT NULL,
        punch_time TIME NOT NULL,
        punch_datetime DATETIME NOT NULL,
        punch_type ENUM('in', 'out') NOT NULL,
        device_serial VARCHAR(100),
        ip_address VARCHAR(45),
        is_late BOOLEAN DEFAULT FALSE,
        is_early BOOLEAN DEFAULT FALSE,
        is_auto_generated BOOLEAN DEFAULT FALSE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        INDEX idx_user_date (user_id, punch_date),
        INDEX idx_organization_date (organization_id, punch_date),
        INDEX idx_punch_code_date_time (punching_code, punch_date, punch_time),
        INDEX idx_punch_type (punch_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Attendance logs table created\n";

    // Step 7: Create daily_attendance table
    echo "Step 7: Creating daily_attendance table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS daily_attendance (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        punching_code VARCHAR(20) NOT NULL,
        organization_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        punch_in_time TIME,
        punch_out_time TIME,
        total_hours DECIMAL(5,2),
        is_late BOOLEAN DEFAULT FALSE,
        is_early_out BOOLEAN DEFAULT FALSE,
        late_minutes INT DEFAULT 0,
        early_out_minutes INT DEFAULT 0,
        overtime_hours DECIMAL(5,2) DEFAULT 0,
        status ENUM('present', 'absent', 'late', 'half_day', 'early_out') DEFAULT 'absent',
        auto_generated BOOLEAN DEFAULT FALSE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_date (user_id, attendance_date),
        INDEX idx_organization_date (organization_id, attendance_date),
        INDEX idx_status (status),
        INDEX idx_date (attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Daily attendance table created\n";

    // Step 8: Create organization_punch_settings table
    echo "Step 8: Creating organization_punch_settings table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS organization_punch_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        organization_id INT NOT NULL,
        punch_in_start_time TIME DEFAULT '07:00:00',
        punch_in_end_time TIME DEFAULT '11:59:59',
        punch_out_start_time TIME DEFAULT '12:00:00',
        punch_out_end_time TIME DEFAULT '18:00:00',
        auto_punch_out_time TIME DEFAULT '18:00:00',
        late_threshold_minutes INT DEFAULT 15,
        early_out_threshold_minutes INT DEFAULT 30,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        UNIQUE KEY unique_org_settings (organization_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Organization punch settings table created\n";

    // Step 9: Create absence_records table
    echo "Step 9: Creating absence_records table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS absence_records (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        organization_id INT NOT NULL,
        absence_date DATE NOT NULL,
        absence_type ENUM('absent', 'excused', 'on_duty', 'other') DEFAULT 'absent',
        excused_by INT NULL,
        excused_at TIMESTAMP NULL,
        reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        FOREIGN KEY (excused_by) REFERENCES admins(id) ON DELETE SET NULL,
        UNIQUE KEY unique_user_absence_date (user_id, absence_date),
        INDEX idx_organization_date (organization_id, absence_date),
        INDEX idx_absence_type (absence_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ Absence records table created\n";

    // Step 10: Create user_device_assignments table
    echo "Step 10: Creating user_device_assignments table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS user_device_assignments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        device_id INT NOT NULL,
        assigned_by INT,
        status ENUM('active', 'inactive') DEFAULT 'active',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_by) REFERENCES admins(id) ON DELETE SET NULL,
        UNIQUE KEY unique_user_device (user_id, device_id),
        INDEX idx_user (user_id),
        INDEX idx_device (device_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoConn->exec($sql);
    echo "✓ User device assignments table created\n";

    // Re-enable foreign key checks
    $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Step 11: Verification
    echo "\n=== VERIFICATION ===\n";
    $tables = [
        'organizations',
        'devices',
        'users',
        'admins',
        'tblt_timesheet',
        'attendance_logs',
        'daily_attendance',
        'organization_punch_settings',
        'absence_records',
        'user_device_assignments'
    ];

    foreach ($tables as $table) {
        $stmt = $pdoConn->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✓ Table '$table': $count records\n";
    }

    echo "\n=== SCHEMA SETUP COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "All required tables have been created!\n\n";
    echo "Next steps:\n";
    echo "1. Run 'php setup_default_admin.php' to create super admin\n";
    echo "2. Create organizations and users\n";
    echo "3. Start receiving attendance punches\n";

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    try {
        $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (Exception $e2) {}
    exit(1);
}
?>
