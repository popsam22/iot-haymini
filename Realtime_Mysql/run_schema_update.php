<?php
/**
 * Database Schema Update Script
 * Run this script to create the necessary tables for device segregation
 */

require_once 'config.php';

echo "=== IoT Device Segregation - Database Schema Update ===\n";
echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Read the SQL file
    $sqlFile = __DIR__ . '/schema_update.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Schema file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    if ($sql === false) {
        throw new Exception("Could not read schema file");
    }
    
    echo "✓ Schema file loaded successfully\n";
    echo "✓ Connected to database: " . $pdoConn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    echo "Processing " . count($statements) . " SQL statements...\n\n";
    
    $successCount = 0;
    $skipCount = 0;
    
    foreach ($statements as $index => $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            // Execute statement
            $result = $pdoConn->exec($statement);
            
            // Extract table/action info for logging
            $action = '';
            if (preg_match('/^(CREATE|ALTER|INSERT|UPDATE|SELECT)/i', trim($statement), $matches)) {
                $action = strtoupper($matches[1]);
            }
            
            if ($action === 'SELECT' && strpos($statement, 'already exists') !== false) {
                echo "⚠ SKIP: " . substr($statement, 0, 60) . "...\n";
                $skipCount++;
            } else {
                echo "✓ SUCCESS: $action - " . substr($statement, 0, 60) . "...\n";
                $successCount++;
            }
            
        } catch (PDOException $e) {
            // Check if it's a harmless "already exists" error
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠ SKIP: Already exists - " . substr($statement, 0, 60) . "...\n";
                $skipCount++;
            } else {
                echo "✗ ERROR: " . $e->getMessage() . "\n";
                echo "  Statement: " . substr($statement, 0, 100) . "...\n";
                // Continue with other statements instead of failing completely
            }
        }
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "✓ Successful operations: $successCount\n";
    echo "⚠ Skipped operations: $skipCount\n";
    
    // Verify the schema
    echo "\n=== VERIFICATION ===\n";
    
    $tables = ['organizations', 'devices', 'users', 'tblt_timesheet'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdoConn->query("SELECT COUNT(*) FROM $table");
            $count = $stmt->fetchColumn();
            echo "✓ Table '$table': $count records\n";
        } catch (PDOException $e) {
            echo "✗ Table '$table': ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    // Check if new columns exist
    echo "\n=== COLUMN VERIFICATION ===\n";
    $columns = [
        'tblt_timesheet' => ['device_serial', 'organization_id'],
        'users' => ['organization_id'],
        'devices' => ['organization_id']
    ];
    
    foreach ($columns as $table => $cols) {
        foreach ($cols as $col) {
            $stmt = $pdoConn->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $col]);
            $exists = $stmt->fetchColumn() > 0;
            echo ($exists ? "✓" : "✗") . " Column '$table.$col': " . ($exists ? "EXISTS" : "MISSING") . "\n";
        }
    }
    
    echo "\n=== SCHEMA UPDATE COMPLETED ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Please check your database connection and try again.\n";
    exit(1);
}
?>