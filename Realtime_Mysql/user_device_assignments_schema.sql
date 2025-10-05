-- User-Device Assignment Table Schema
-- This table manages which users are allowed to punch on which devices

CREATE TABLE IF NOT EXISTS user_device_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    device_id INT NOT NULL,
    assigned_by INT NULL COMMENT 'Admin ID who made the assignment',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key constraints
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES admins(id) ON DELETE SET NULL,

    -- Unique constraint to prevent duplicate assignments
    UNIQUE KEY unique_assignment (user_id, device_id),

    -- Indexes for performance
    INDEX idx_user_id (user_id),
    INDEX idx_device_id (device_id),
    INDEX idx_status (status),
    INDEX idx_assigned_at (assigned_at)
);

-- Optional: Create a view for active assignments with user and device details
CREATE OR REPLACE VIEW active_assignments AS
SELECT
    uda.id as assignment_id,
    uda.assigned_at,
    uda.assigned_by,
    u.id as user_id,
    u.punching_code,
    u.name as user_name,
    u.email as user_email,
    u.organization_id,
    d.id as device_id,
    d.serial_number,
    d.device_name,
    d.device_model,
    o.name as organization_name,
    a.username as assigned_by_username
FROM user_device_assignments uda
INNER JOIN users u ON uda.user_id = u.id
INNER JOIN devices d ON uda.device_id = d.id
INNER JOIN organizations o ON u.organization_id = o.id
LEFT JOIN admins a ON uda.assigned_by = a.id
WHERE uda.status = 'active'
  AND u.status = 'active'
  AND d.status = 'active'
ORDER BY uda.assigned_at DESC;