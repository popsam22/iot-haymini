<?php
// Setup script to create default super admin account
require_once 'Realtime_Mysql/ws.php';

echo "Setting up default super admin account...\n";

$result = createDefaultSuperAdmin();

if ($result['status'] === 'success' || $result['status'] === 'exists') {
    echo "✅ Success: " . $result['message'] . "\n";
    
    if ($result['status'] === 'success') {
        echo "Default Super Admin Credentials:\n";
        echo "Email: " . $result['email'] . "\n";
        echo "Password: " . $result['password'] . "\n";
    }
} else {
    echo "❌ Error: " . $result['message'] . "\n";
}
