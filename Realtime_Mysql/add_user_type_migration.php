<?php
/**
 * Migration Script: Add user_type column to existing users table
 *
 * This script adds the user_type column to the users table for existing databases.
 * Run this ONLY if you already have a users table and want to add the user_type feature.
 *
 * If you're setting up a fresh database, use reset_and_create_schema.php instead.
 */

require_once 'config.php';

echo "=== Adding user_type Column Migration ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Set PDO to use buffered queries
    $pdoConn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

    // Step 1: Check if users table exists
    echo "Step 1: Checking if users table exists...\n";
    $stmt = $pdoConn->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() === 0) {
        echo "✗ ERROR: users table does not exist. Please run reset_and_create_schema.php first.\n";
        exit(1);
    }
    echo "✓ Users table exists\n\n";

    // Step 2: Check if user_type column already exists
    echo "Step 2: Checking if user_type column already exists...\n";
    $stmt = $pdoConn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('user_type', $columns)) {
        echo "⚠ WARNING: user_type column already exists. No migration needed.\n";
        echo "\n=== MIGRATION SKIPPED ===\n";
        echo "The user_type column is already present in the users table.\n";
        exit(0);
    }
    echo "✓ user_type column does not exist, proceeding with migration\n\n";

    // Step 3: Add user_type column
    echo "Step 3: Adding user_type column to users table...\n";
    $sql = "ALTER TABLE users
            ADD COLUMN user_type ENUM('staff', 'student') NOT NULL DEFAULT 'student'
            AFTER phone_number";
    $pdoConn->exec($sql);
    echo "✓ user_type column added successfully\n\n";

    // Step 4: Add index on user_type for better query performance
    echo "Step 4: Adding index on user_type column...\n";
    try {
        $pdoConn->exec("ALTER TABLE users ADD INDEX idx_user_type (user_type)");
        echo "✓ Index on user_type column created successfully\n\n";
    } catch (PDOException $e) {
        // Index might already exist, continue anyway
        echo "⚠ Index creation warning: " . $e->getMessage() . "\n\n";
    }

    // Step 5: Verify the changes
    echo "Step 5: Verifying the migration...\n";
    $stmt = $pdoConn->query("DESCRIBE users");
    $columnsAfter = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userTypeColumn = null;
    foreach ($columnsAfter as $column) {
        if ($column['Field'] === 'user_type') {
            $userTypeColumn = $column;
            break;
        }
    }

    if ($userTypeColumn) {
        echo "✓ user_type column verified:\n";
        echo "  - Type: " . $userTypeColumn['Type'] . "\n";
        echo "  - Null: " . $userTypeColumn['Null'] . "\n";
        echo "  - Default: " . $userTypeColumn['Default'] . "\n\n";
    } else {
        echo "✗ ERROR: user_type column not found after migration\n";
        exit(1);
    }

    // Step 6: Count existing users
    $stmt = $pdoConn->query("SELECT COUNT(*) FROM users");
    $userCount = $stmt->fetchColumn();
    echo "Step 6: Database statistics:\n";
    echo "  - Total users: $userCount\n";
    if ($userCount > 0) {
        $stmt = $pdoConn->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
        $typeCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($typeCounts as $typeCount) {
            echo "  - {$typeCount['user_type']}: {$typeCount['count']}\n";
        }
        echo "\n";
        echo "⚠ NOTE: All existing users have been set to 'student' (default).\n";
        echo "   You may need to update specific users to 'staff' manually.\n";
        echo "   Use: UPDATE users SET user_type = 'staff' WHERE punching_code = 'CODE';\n\n";
    }

    echo "=== MIGRATION COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "\nThe user_type feature is now available!\n";
    echo "When creating new users, you can specify user_type as 'staff' or 'student'.\n";
    echo "If not specified, users will default to 'student'.\n";

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
