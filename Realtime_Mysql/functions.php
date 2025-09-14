<?php
// Authentication and database functions only (no routing or WebSocket server)
require 'mailer.php';
require "termii.php";
include_once 'config.php';
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();
error_reporting(E_ALL);

// JWT Configuration
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'AeFCfi9HELiSFoie4MV');
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRE_HOURS', 24);

function validatePassword($password) {
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return "Password must contain at least one symbol";
    }
    
    return true;
}

function generateJWT($adminData) {
    $payload = [
        'iss' => 'haymini-iot',
        'aud' => 'haymini-iot-api',
        'iat' => time(),
        'exp' => time() + (JWT_EXPIRE_HOURS * 3600),
        'admin_id' => $adminData['id'],
        'username' => $adminData['username'],
        'email' => $adminData['email'],
        'role' => $adminData['role'],
        'organization_id' => $adminData['organization_id']
    ];
    
    return JWT::encode($payload, JWT_SECRET, JWT_ALGORITHM);
}

function createDefaultSuperAdmin() {
    $pdoConn = getValidConnection();
    
    try {
        // Check if super admin already exists
        $stmt = $pdoConn->prepare("SELECT id FROM admins WHERE email = ?");
        $stmt->execute(['super.admin@haymini.net']);
        if ($stmt->fetch()) {
            return [
                'status' => 'exists',
                'message' => 'Default super admin already exists'
            ];
        }
        
        // Create default super admin with password 'Admin@2024'
        $password = 'Admin@2024';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdoConn->prepare("
            INSERT INTO admins (username, email, password_hash, role, status) 
            VALUES (?, ?, ?, 'super_admin', 'active')
        ");
        
        $stmt->execute(['super_admin', 'super.admin@haymini.net', $passwordHash]);
        
        error_log("Created default super admin: super.admin@haymini.net");
        
        return [
            'status' => 'success',
            'message' => 'Default super admin created successfully',
            'email' => 'super.admin@haymini.net',
            'password' => $password
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in createDefaultSuperAdmin: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to create default super admin'
        ];
    }
}