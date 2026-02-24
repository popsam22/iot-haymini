<?php
require_once 'config.php';

echo "=== Checking Users Table Constraints ===\n\n";

// Check the users table constraint
$stmt = $pdoConn->query("
    SELECT CONSTRAINT_NAME, COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME LIKE '%unique%'
");

echo "Users table unique constraints:\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  " . $row['CONSTRAINT_NAME'] . " on " . $row['COLUMN_NAME'] . "\n";
}

// Show the full table structure
echo "\nUsers table structure:\n";
$stmt = $pdoConn->query('DESCRIBE users');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("  %-20s %-20s %-5s %-5s\n",
        $row['Field'],
        $row['Type'],
        $row['Key'],
        $row['Null']
    );
}

// Test: Try to find users with same punching code in different organizations
echo "\n=== Testing Punching Code Scoping ===\n";
$stmt = $pdoConn->query("
    SELECT punching_code, organization_id, name, COUNT(*) as count
    FROM users
    GROUP BY punching_code, organization_id
    HAVING COUNT(*) > 1
");

$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($duplicates) > 0) {
    echo "WARNING: Found duplicate punching codes within same organization:\n";
    foreach ($duplicates as $dup) {
        echo "  Punching Code: {$dup['punching_code']}, Org: {$dup['organization_id']}, Count: {$dup['count']}\n";
    }
} else {
    echo "✓ No duplicate punching codes within same organization\n";
}

// Show punching codes across organizations
echo "\n=== Punching Codes Across Organizations ===\n";
$stmt = $pdoConn->query("
    SELECT punching_code, COUNT(DISTINCT organization_id) as org_count,
           GROUP_CONCAT(DISTINCT organization_id) as orgs
    FROM users
    GROUP BY punching_code
    HAVING COUNT(DISTINCT organization_id) > 1
");

$crossOrg = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($crossOrg) > 0) {
    echo "Punching codes used in multiple organizations (this is ALLOWED):\n";
    foreach ($crossOrg as $item) {
        echo "  Code: {$item['punching_code']} used in organizations: {$item['orgs']}\n";
    }
} else {
    echo "No punching codes shared across organizations yet\n";
}
?>
