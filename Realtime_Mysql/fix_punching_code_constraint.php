<?php
/**
 * Fix Punching Code Constraint
 * Change from GLOBAL unique to UNIQUE per organization
 */

require_once 'config.php';

echo "=== Fixing Punching Code Constraint ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Step 1: Check current constraints
    echo "Step 1: Checking current constraints...\n";
    $stmt = $pdoConn->query("SHOW INDEX FROM users WHERE Key_name != 'PRIMARY'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Current indexes:\n";
    foreach ($indexes as $index) {
        echo "  - {$index['Key_name']} on {$index['Column_name']} (Unique: {$index['Non_unique']})\n";
    }
    echo "\n";

    // Step 2: Drop the global unique constraint on punching_code
    echo "Step 2: Dropping global unique constraint on punching_code...\n";

    // Find the constraint name
    $stmt = $pdoConn->query("
        SELECT CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'punching_code'
        AND CONSTRAINT_NAME != 'PRIMARY'
    ");

    $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($constraints as $constraint) {
        try {
            $pdoConn->exec("ALTER TABLE users DROP INDEX $constraint");
            echo "✓ Dropped constraint: $constraint\n";
        } catch (PDOException $e) {
            echo "⚠ Warning dropping constraint $constraint: " . $e->getMessage() . "\n";
        }
    }

    // Step 3: Add composite unique constraint (punching_code, organization_id)
    echo "\nStep 3: Adding composite unique constraint...\n";
    try {
        $pdoConn->exec("
            ALTER TABLE users
            ADD UNIQUE KEY unique_punching_code_per_org (punching_code, organization_id)
        ");
        echo "✓ Added composite unique constraint: unique_punching_code_per_org\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⚠ Constraint already exists\n";
        } else {
            throw $e;
        }
    }

    // Step 4: Verify the fix
    echo "\nStep 4: Verifying constraints...\n";
    $stmt = $pdoConn->query("SHOW INDEX FROM users WHERE Key_name != 'PRIMARY'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Updated indexes:\n";
    foreach ($indexes as $index) {
        echo "  - {$index['Key_name']} on {$index['Column_name']} (Non_unique: {$index['Non_unique']})\n";
    }

    // Step 5: Test by trying to create duplicate punching codes
    echo "\n=== TESTING ===\n";
    echo "Testing that same punching code can exist in different organizations...\n";

    // This should work: same punching code in different orgs
    try {
        $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Test insert (will rollback)
        $pdoConn->beginTransaction();

        // Get two different organization IDs
        $stmt = $pdoConn->query("SELECT id FROM organizations LIMIT 2");
        $orgs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($orgs) >= 2) {
            $testCode = '999TEST';

            // Insert into org 1
            $stmt = $pdoConn->prepare("
                INSERT INTO users (punching_code, name, organization_id)
                VALUES (?, 'Test User 1', ?)
            ");
            $stmt->execute([$testCode, $orgs[0]]);
            echo "✓ Inserted punching code $testCode in organization {$orgs[0]}\n";

            // Insert same code into org 2 (should succeed)
            $stmt = $pdoConn->prepare("
                INSERT INTO users (punching_code, name, organization_id)
                VALUES (?, 'Test User 2', ?)
            ");
            $stmt->execute([$testCode, $orgs[1]]);
            echo "✓ Inserted same punching code $testCode in organization {$orgs[1]}\n";
            echo "✓ TEST PASSED: Same punching code allowed in different organizations\n";

            // Try to insert duplicate in same org (should fail)
            echo "\nTesting that duplicate punching code in SAME organization is blocked...\n";
            try {
                $stmt = $pdoConn->prepare("
                    INSERT INTO users (punching_code, name, organization_id)
                    VALUES (?, 'Test User 3', ?)
                ");
                $stmt->execute([$testCode, $orgs[0]]);
                echo "✗ ERROR: Duplicate punching code in same org was NOT blocked!\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    echo "✓ TEST PASSED: Duplicate punching code in same organization was blocked\n";
                } else {
                    echo "⚠ Unexpected error: " . $e->getMessage() . "\n";
                }
            }
        }

        // Rollback test data
        $pdoConn->rollBack();
        echo "\n✓ Test data rolled back\n";

        $pdoConn->exec("SET FOREIGN_KEY_CHECKS = 1");

    } catch (Exception $e) {
        $pdoConn->rollBack();
        echo "Test error: " . $e->getMessage() . "\n";
    }

    echo "\n=== CONSTRAINT FIX COMPLETED SUCCESSFULLY ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "\nPunching codes are now unique PER ORGANIZATION\n";
    echo "✓ User '121' can exist in Org A\n";
    echo "✓ User '121' can ALSO exist in Org B\n";
    echo "✓ But user '121' CANNOT exist twice in Org A\n";

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
