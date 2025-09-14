-- Migration: Create admins table and default super admin
-- Run this SQL to add authentication system to your database

-- Step 1: Create admins table
CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin') NOT NULL DEFAULT 'admin',
    organization_id INT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign key constraint
    CONSTRAINT fk_admin_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    
    -- Ensure admin role has organization_id, super_admin doesn't
    CONSTRAINT chk_admin_org CHECK (
        (role = 'super_admin' AND organization_id IS NULL) OR 
        (role = 'admin' AND organization_id IS NOT NULL)
    )
);

-- Step 2: Create indexes for performance
CREATE INDEX idx_admins_email ON admins(email);
CREATE INDEX idx_admins_username ON admins(username);
CREATE INDEX idx_admins_role ON admins(role);
CREATE INDEX idx_admins_organization ON admins(organization_id);

-- Step 3: Insert default super admin account
-- Password: 'Admin@2024' (hashed with PHP password_hash)
-- You'll need to update this with actual hash after running PHP password_hash('Admin@2024', PASSWORD_DEFAULT)
INSERT INTO admins (username, email, password_hash, role, status) VALUES 
('super_admin', 'super.admin@haymini.net', '$2y$10$placeholder_will_be_replaced', 'super_admin', 'active');

-- Step 4: Show table structure
DESCRIBE admins;
SELECT * FROM admins;
