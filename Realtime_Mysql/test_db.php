<?php
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

try {
    $pdoConn = new PDO(
        "mysql:host={$_ENV['MYSQL_HOST']};dbname={$_ENV['MYSQL_DB']}",
        $_ENV['MYSQL_USER'],
        $_ENV['MYSQL_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Database connection successful!\n\n";

    // Check tblt_timesheet table
    $stmt = $pdoConn->query('SELECT COUNT(*) as count FROM tblt_timesheet');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "tblt_timesheet records: " . $result['count'] . "\n";

    // Check recent records
    $stmt = $pdoConn->query('SELECT * FROM tblt_timesheet ORDER BY date DESC, time DESC LIMIT 5');
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Recent records found: " . count($records) . "\n";

    if (count($records) > 0) {
        echo "\nLatest record:\n";
        print_r($records[0]);
    }

    // Check attendance_logs table
    $stmt = $pdoConn->query('SELECT COUNT(*) as count FROM attendance_logs');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nattendance_logs records: " . $result['count'] . "\n";

    // Check users table
    $stmt = $pdoConn->query('SELECT COUNT(*) as count FROM users');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "users records: " . $result['count'] . "\n";

    // Check organizations table
    $stmt = $pdoConn->query('SELECT COUNT(*) as count FROM organizations');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "organizations records: " . $result['count'] . "\n";

    // Check devices table
    $stmt = $pdoConn->query('SELECT COUNT(*) as count FROM devices');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "devices records: " . $result['count'] . "\n\n";

    // Test the main logs query
    echo "Testing main logs query...\n";
    $sql = 'SELECT t.timesheetid, t.punchingcode, t.date, t.time, t.organization_id,
                   u.id as user_id, u.name, o.name as organization_name
            FROM tblt_timesheet t
            LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
            LEFT JOIN organizations o ON t.organization_id = o.id
            ORDER BY t.date DESC, t.time DESC
            LIMIT 5';

    $stmt = $pdoConn->query($sql);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Logs query returned: " . count($logs) . " records\n";

    if (count($logs) > 0) {
        echo "\nSample log record:\n";
        print_r($logs[0]);
    }

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
