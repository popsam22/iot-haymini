<?php
/**
 * Migration Script: Simplify daily_attendance.status ENUM
 *
 * Changes status values from ('present','absent','late','half_day','early_out')
 * to ('present','partial','absent').
 *
 * - Both punch_in and punch_out present  → present
 * - Only one of them present             → partial
 * - Neither                              → absent
 *
 * Safe to run multiple times.
 */

require_once 'config.php';

echo "=== Attendance Status Migration ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdoConn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

    // Step 1: Check table exists
    echo "Step 1: Checking daily_attendance table...\n";
    $stmt = $pdoConn->query("SHOW TABLES LIKE 'daily_attendance'");
    if ($stmt->rowCount() === 0) {
        echo "✗ ERROR: daily_attendance table does not exist.\n";
        exit(1);
    }
    echo "✓ Table exists\n\n";

    // Step 2: Show current ENUM definition
    echo "Step 2: Current column definition...\n";
    $stmt = $pdoConn->query("SHOW COLUMNS FROM daily_attendance LIKE 'status'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  Current: " . $col['Type'] . " DEFAULT " . $col['Default'] . "\n\n";

    // Step 3: Count rows before migration
    echo "Step 3: Pre-migration row counts by status...\n";
    $stmt = $pdoConn->query("SELECT IFNULL(status, 'NULL') as status, COUNT(*) as cnt FROM daily_attendance GROUP BY status");
    $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($before as $row) {
        echo "  {$row['status']}: {$row['cnt']}\n";
    }
    echo "\n";

    // Step 4: Remap old values to new ones before altering the ENUM
    echo "Step 4: Remapping old status values...\n";

    // late / early_out → if both punches exist: present, else partial
    $pdoConn->exec("
        UPDATE daily_attendance
        SET status = CASE
            WHEN punch_in_time IS NOT NULL AND punch_out_time IS NOT NULL THEN 'present'
            ELSE 'partial'
        END
        WHERE status IN ('late', 'early_out')
    ");
    echo "✓ Mapped late/early_out\n";

    // half_day → partial (only one punch)
    $pdoConn->exec("UPDATE daily_attendance SET status = 'partial' WHERE status = 'half_day'");
    echo "✓ Mapped half_day → partial\n";

    // NULL or empty → absent
    $pdoConn->exec("UPDATE daily_attendance SET status = 'absent' WHERE status IS NULL OR status = ''");
    echo "✓ Fixed NULL/empty → absent\n\n";

    // Step 5: Alter ENUM
    echo "Step 5: Altering ENUM definition...\n";
    $pdoConn->exec("
        ALTER TABLE daily_attendance
        MODIFY COLUMN status ENUM('present','partial','absent') NOT NULL DEFAULT 'absent'
    ");
    echo "✓ ENUM updated\n\n";

    // Step 6: Verify new column definition
    echo "Step 6: New column definition...\n";
    $stmt = $pdoConn->query("SHOW COLUMNS FROM daily_attendance LIKE 'status'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  New: " . $col['Type'] . " DEFAULT " . $col['Default'] . "\n\n";

    // Step 7: Backfill missing daily_attendance rows from attendance_logs
    // Punch records exist in attendance_logs but have no daily_attendance row,
    // causing daily_status to appear null in API responses (COALESCE masks the missing row).
    echo "Step 7: Backfilling missing daily_attendance rows from attendance_logs...\n";
    $backfilled = $pdoConn->exec("
        INSERT INTO daily_attendance
            (user_id, punching_code, organization_id, attendance_date,
             punch_in_time, punch_out_time, total_hours, status)
        SELECT
            al.user_id, al.punching_code, al.organization_id, al.punch_date,
            MIN(CASE WHEN al.punch_type = 'in'  THEN al.punch_time END),
            MAX(CASE WHEN al.punch_type = 'out' THEN al.punch_time END),
            CASE
                WHEN MIN(CASE WHEN al.punch_type='in'  THEN al.punch_time END) IS NOT NULL
                 AND MAX(CASE WHEN al.punch_type='out' THEN al.punch_time END) IS NOT NULL
                THEN ROUND(TIME_TO_SEC(TIMEDIFF(
                    MAX(CASE WHEN al.punch_type='out' THEN al.punch_time END),
                    MIN(CASE WHEN al.punch_type='in'  THEN al.punch_time END)
                )) / 3600, 2)
                ELSE 0
            END,
            CASE
                WHEN MIN(CASE WHEN al.punch_type='in'  THEN al.punch_time END) IS NOT NULL
                 AND MAX(CASE WHEN al.punch_type='out' THEN al.punch_time END) IS NOT NULL
                THEN 'present'
                WHEN MIN(CASE WHEN al.punch_type='in'  THEN al.punch_time END) IS NOT NULL
                  OR MAX(CASE WHEN al.punch_type='out' THEN al.punch_time END) IS NOT NULL
                THEN 'partial'
                ELSE 'absent'
            END
        FROM attendance_logs al
        LEFT JOIN daily_attendance da
            ON al.user_id = da.user_id
            AND al.punch_date = da.attendance_date
            AND al.organization_id = da.organization_id
        WHERE da.id IS NULL
        GROUP BY al.user_id, al.punching_code, al.organization_id, al.punch_date
        ON DUPLICATE KEY UPDATE
            punch_in_time  = VALUES(punch_in_time),
            punch_out_time = VALUES(punch_out_time),
            total_hours    = VALUES(total_hours),
            status         = VALUES(status)
    ");
    echo "✓ Backfilled {$backfilled} row(s)\n\n";

    // Step 8: Post-migration counts
    echo "Step 8: Post-migration row counts by status...\n";
    $stmt = $pdoConn->query("SELECT IFNULL(status, 'NULL') as status, COUNT(*) as cnt FROM daily_attendance GROUP BY status");
    $after = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($after as $row) {
        echo "  {$row['status']}: {$row['cnt']}\n";
    }

    echo "\n=== MIGRATION COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "\nDelete this file from the server after confirming the results.\n";

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
