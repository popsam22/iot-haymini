<?php
/**
 * Update Punching Code Constraint
 * Changes punching_code from globally unique to unique per organization
 */

require_once 'config.php';

echo "=== Updating Punching Code Constraint ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdoConn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

    // Step 1: Check current constraints
    echo "Step 1: Checking current constraints...\n";
    $stmt = $pdoConn->query("SHOW INDEXES FROM users WHERE Key_name != 'PRIMARY'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Current indexes:\n";
    foreach ($indexes as $index) {
        echo "  - {$index['Key_name']} on column {$index['Column_name']} (Unique: " . ($index['Non_unique'] == 0 ? 'Yes' : 'No') . ")\n";
    }
    echo "\n";

    // Step 2: Drop the unique constraint on punching_code if it exists
    echo "Step 2: Removing old unique constraint on punching_code...\n";
    try {
        // Try to drop the unique key (it might be named 'punching_code' or have a different name)
        $pdoConn->exec("ALTER TABLE users DROP INDEX punching_code");
        echo "✓ Dropped unique constraint on punching_code\n\n";
    } catch (PDOException $e) {
        // If the constraint doesn't exist or has a different name, try to find and drop it
        if (strpos($e->getMessage(), "check that column/key exists") !== false) {
            echo "⚠ Unique constraint 'punching_code' not found, checking for alternative names...\n";

            // Find any unique constraint on punching_code column
            $stmt = $pdoConn->query("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND CONSTRAINT_TYPE = 'UNIQUE'
            ");
            $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($constraints as $constraint) {
                try {
                    $pdoConn->exec("ALTER TABLE users DROP INDEX `$constraint`");
                    echo "✓ Dropped unique constraint: $constraint\n";
                } catch (PDOException $e2) {
                    echo "⚠ Could not drop constraint $constraint: " . $e2->getMessage() . "\n";
                }
            }
            echo "\n";
        } else {
            throw $e;
        }
    }

    // Step 3: Drop the old index if it exists
    echo "Step 3: Removing old index idx_punching_code if exists...\n";
    try {
        $pdoConn->exec("ALTER TABLE users DROP INDEX idx_punching_code");
        echo "✓ Dropped old index idx_punching_code\n\n";
    } catch (PDOException $e) {
        echo "⚠ Index idx_punching_code not found or already removed\n\n";
    }

    // Step 4: Add composite unique constraint
    echo "Step 4: Adding composite unique constraint on (punching_code, organization_id)...\n";
    try {
        $pdoConn->exec("
            ALTER TABLE users
            ADD UNIQUE KEY unique_punching_code_per_org (punching_code, organization_id)
        ");
        echo "✓ Added composite unique constraint: unique_punching_code_per_org\n\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Duplicate key name") !== false) {
            echo "⚠ Composite unique constraint already exists\n\n";
        } else {
            throw $e;
        }
    }

    // Step 5: Add index for performance
    echo "Step 5: Adding performance index on (organization_id, punching_code)...\n";
    try {
        $pdoConn->exec("
            CREATE INDEX idx_org_punching_code ON users (organization_id, punching_code)
        ");
        echo "✓ Added performance index: idx_org_punching_code\n\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Duplicate key name") !== false) {
            echo "⚠ Performance index already exists\n\n";
        } else {
            throw $e;
        }
    }

    // Step 6: Verify the changes
    echo "=== VERIFICATION ===\n";
    $stmt = $pdoConn->query("SHOW INDEXES FROM users WHERE Key_name != 'PRIMARY'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Updated indexes:\n";
    foreach ($indexes as $index) {
        echo "  - {$index['Key_name']} on column {$index['Column_name']} (Unique: " . ($index['Non_unique'] == 0 ? 'Yes' : 'No') . ")\n";
    }

    // Check for the specific constraint we added
    $hasCompositeUnique = false;
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'unique_punching_code_per_org') {
            $hasCompositeUnique = true;
            break;
        }
    }

    if ($hasCompositeUnique) {
        echo "\n✓ Composite unique constraint successfully created!\n";
    } else {
        echo "\n⚠ Warning: Could not verify composite unique constraint\n";
    }

    echo "\n=== UPDATE COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "Punching codes can now be reused across different organizations!\n";

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
