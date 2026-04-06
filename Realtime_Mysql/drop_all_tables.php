<?php
/**
 * Drop All Tables - Clean Database Reset
 */

require_once 'config.php';

echo "=== Dropping All Tables ===\n";

try {
    $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = [
        'user_device_assignments',
        'daily_attendance',
        'attendance_logs',
        'organization_punch_settings',
        'admins',
        'users',
        'devices',
        'organizations',
        'tblt_timesheet'
    ];

    foreach ($tables as $table) {
        $pdoConn->exec("DROP TABLE IF EXISTS $table");
        echo "✓ Dropped: $table\n";
    }

    $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n=== All tables dropped successfully ===\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
