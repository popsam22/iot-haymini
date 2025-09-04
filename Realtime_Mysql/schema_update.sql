-- IoT Device Segregation Database Schema
-- This script creates the necessary tables for organization-based device segregation

-- Create organizations table
CREATE TABLE IF NOT EXISTS organizations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create devices table for device-organization mapping
CREATE TABLE IF NOT EXISTS devices (
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
);

-- Create or modify users table with organization support
CREATE TABLE IF NOT EXISTS users (
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
    INDEX idx_organization (organization_id),
    INDEX idx_status (status)
);

-- Check if tblt_timesheet exists and add new columns if they don't exist
-- Add device_serial column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'tblt_timesheet' 
    AND COLUMN_NAME = 'device_serial'
);

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE tblt_timesheet ADD COLUMN device_serial VARCHAR(100)', 
    'SELECT "device_serial column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add organization_id column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'tblt_timesheet' 
    AND COLUMN_NAME = 'organization_id'
);

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE tblt_timesheet ADD COLUMN organization_id INT', 
    'SELECT "organization_id column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'tblt_timesheet' 
    AND CONSTRAINT_NAME = 'fk_timesheet_organization'
);

SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE tblt_timesheet ADD CONSTRAINT fk_timesheet_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL', 
    'SELECT "Foreign key constraint already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_organization_date ON tblt_timesheet (organization_id, date);
CREATE INDEX IF NOT EXISTS idx_device_serial ON tblt_timesheet (device_serial);
CREATE INDEX IF NOT EXISTS idx_punchingcode_date ON tblt_timesheet (punchingcode, date);

-- Insert default organization for existing data
INSERT IGNORE INTO organizations (name, description) 
VALUES ('Default Organization', 'Default organization for existing devices and users');

-- Get the default organization ID
SET @default_org_id = (SELECT id FROM organizations WHERE name = 'Default Organization' LIMIT 1);

-- Update existing users to belong to default organization (only if organization_id is NULL)
UPDATE users 
SET organization_id = @default_org_id 
WHERE organization_id IS NULL;

-- Update existing timesheet records to belong to default organization (only if organization_id is NULL)
UPDATE tblt_timesheet 
SET organization_id = @default_org_id 
WHERE organization_id IS NULL;

-- Create audit log table for tracking changes
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    table_name VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_values JSON,
    new_values JSON,
    changed_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_table_record (table_name, record_id),
    INDEX idx_created_at (created_at)
);

-- Display final status
SELECT 
    'Database schema update completed successfully!' as Status,
    NOW() as Timestamp;

SELECT 
    'Organizations' as Table_Name, 
    COUNT(*) as Record_Count 
FROM organizations
UNION ALL
SELECT 
    'Devices' as Table_Name, 
    COUNT(*) as Record_Count 
FROM devices
UNION ALL
SELECT 
    'Users' as Table_Name, 
    COUNT(*) as Record_Count 
FROM users
UNION ALL
SELECT 
    'Timesheet Records' as Table_Name, 
    COUNT(*) as Record_Count 
FROM tblt_timesheet;