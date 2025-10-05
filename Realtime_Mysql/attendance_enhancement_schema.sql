-- ========================================
-- ATTENDANCE SYSTEM ENHANCEMENT SCHEMA
-- ========================================

-- 1. Organization Punch Time Settings Table
CREATE TABLE IF NOT EXISTS organization_punch_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT NOT NULL,
    punch_in_start_time TIME DEFAULT '07:00:00',
    punch_in_end_time TIME DEFAULT '11:59:59',
    punch_out_start_time TIME DEFAULT '12:00:00',
    punch_out_end_time TIME DEFAULT '18:00:00',
    timezone VARCHAR(50) DEFAULT 'UTC',
    grace_period_minutes INT DEFAULT 15 COMMENT 'Grace period for late punch in/early punch out',
    require_both_punches BOOLEAN DEFAULT TRUE COMMENT 'Whether both punch in and out are required',
    auto_punch_out_time TIME DEFAULT '18:30:00' COMMENT 'Auto punch out time if user forgets',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT COMMENT 'Admin who created/modified settings',

    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
    UNIQUE KEY unique_org_settings (organization_id)
);

-- 2. Enhanced Timesheet Table (migrate from tblt_timesheet)
CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    punching_code VARCHAR(50) NOT NULL,
    user_id INT,
    organization_id INT NOT NULL,
    device_serial VARCHAR(100) NOT NULL,
    punch_date DATE NOT NULL,
    punch_time TIME NOT NULL,
    punch_datetime DATETIME NOT NULL COMMENT 'Full timestamp for sorting',
    punch_type ENUM('in', 'out', 'break_out', 'break_in') NOT NULL DEFAULT 'in',
    is_late BOOLEAN DEFAULT FALSE COMMENT 'Whether punch in was late',
    is_early BOOLEAN DEFAULT FALSE COMMENT 'Whether punch out was early',
    is_auto_generated BOOLEAN DEFAULT FALSE COMMENT 'System generated punch',
    notes TEXT COMMENT 'Additional notes or reasons',
    ip_address VARCHAR(45) COMMENT 'IP address of device',
    location_data JSON COMMENT 'GPS or location data if available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_punching_code (punching_code),
    INDEX idx_user_id (user_id),
    INDEX idx_organization_id (organization_id),
    INDEX idx_punch_date (punch_date),
    INDEX idx_punch_type (punch_type),
    INDEX idx_punch_datetime (punch_datetime),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- 3. Daily Attendance Summary Table
CREATE TABLE IF NOT EXISTS daily_attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    punching_code VARCHAR(50) NOT NULL,
    organization_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    punch_in_time TIME NULL,
    punch_out_time TIME NULL,
    total_hours DECIMAL(4,2) DEFAULT 0.00,
    break_duration DECIMAL(4,2) DEFAULT 0.00 COMMENT 'Total break time in hours',
    work_duration DECIMAL(4,2) DEFAULT 0.00 COMMENT 'Actual work time',
    status ENUM('present', 'absent', 'partial', 'late', 'early_out') NOT NULL DEFAULT 'absent',
    is_late BOOLEAN DEFAULT FALSE,
    is_early_out BOOLEAN DEFAULT FALSE,
    late_minutes INT DEFAULT 0,
    early_out_minutes INT DEFAULT 0,
    overtime_hours DECIMAL(4,2) DEFAULT 0.00,
    notes TEXT,
    auto_generated BOOLEAN DEFAULT FALSE COMMENT 'System calculated attendance',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_daily_attendance (user_id, attendance_date),
    INDEX idx_organization_date (organization_id, attendance_date),
    INDEX idx_status (status),
    INDEX idx_attendance_date (attendance_date),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- 4. Absence Tracking Table
CREATE TABLE IF NOT EXISTS absence_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    organization_id INT NOT NULL,
    absence_date DATE NOT NULL,
    absence_type ENUM('absent', 'no_punch_in', 'no_punch_out', 'both_missing') NOT NULL,
    reason TEXT COMMENT 'Reason for absence if provided',
    is_excused BOOLEAN DEFAULT FALSE,
    excused_by INT COMMENT 'Admin who excused the absence',
    excused_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_absence (user_id, absence_date),
    INDEX idx_organization_date (organization_id, absence_date),
    INDEX idx_absence_type (absence_type),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (excused_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- 5. Insert Default Punch Settings for Existing Organizations
INSERT INTO organization_punch_settings (organization_id, punch_in_start_time, punch_in_end_time, punch_out_start_time, punch_out_end_time)
SELECT id, '07:00:00', '11:59:59', '12:00:00', '18:00:00'
FROM organizations
WHERE id NOT IN (SELECT organization_id FROM organization_punch_settings);

-- 6. Data Migration View (for migrating from tblt_timesheet to attendance_logs)
CREATE OR REPLACE VIEW migration_timesheet_data AS
SELECT
    t.punchingcode,
    u.id as user_id,
    t.organization_id,
    t.device_serial,
    t.date as punch_date,
    t.time as punch_time,
    CONCAT(t.date, ' ', t.time) as punch_datetime,
    'in' as punch_type, -- Default to 'in' for existing data
    FALSE as is_late,
    FALSE as is_early,
    FALSE as is_auto_generated,
    NULL as notes,
    NULL as ip_address,
    NULL as location_data
FROM tblt_timesheet t
LEFT JOIN users u ON t.punchingcode = u.punching_code
WHERE t.punchingcode IS NOT NULL;

-- 7. Views for Quick Attendance Reports
CREATE OR REPLACE VIEW daily_attendance_summary AS
SELECT
    da.*,
    u.name as user_name,
    u.email as user_email,
    o.name as organization_name,
    CASE
        WHEN da.status = 'present' THEN 'Present'
        WHEN da.status = 'absent' THEN 'Absent'
        WHEN da.status = 'partial' THEN 'Partial Day'
        WHEN da.status = 'late' THEN 'Late Arrival'
        WHEN da.status = 'early_out' THEN 'Early Departure'
    END as status_display
FROM daily_attendance da
INNER JOIN users u ON da.user_id = u.id
INNER JOIN organizations o ON da.organization_id = o.id;

-- 8. View for Current Day Attendance
CREATE OR REPLACE VIEW today_attendance AS
SELECT
    u.id as user_id,
    u.punching_code,
    u.name as user_name,
    u.organization_id,
    o.name as organization_name,
    da.punch_in_time,
    da.punch_out_time,
    da.status,
    da.is_late,
    da.late_minutes,
    CASE
        WHEN da.punch_in_time IS NOT NULL AND da.punch_out_time IS NOT NULL THEN 'Complete'
        WHEN da.punch_in_time IS NOT NULL AND da.punch_out_time IS NULL THEN 'Punched In'
        WHEN da.punch_in_time IS NULL THEN 'Not Arrived'
    END as current_status
FROM users u
INNER JOIN organizations o ON u.organization_id = o.id
LEFT JOIN daily_attendance da ON u.id = da.user_id AND da.attendance_date = CURDATE()
WHERE u.status = 'active' AND o.status = 'active'
ORDER BY o.name, u.name;