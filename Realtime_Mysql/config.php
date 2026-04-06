<?php
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

$SERVER_IP = $_ENV['SERVER_IP'];
$SERVER_PORT = $_ENV['SERVER_PORT'] ?? 7788;
$MYSQL_HOST = $_ENV['MYSQL_HOST'];
$MYSQL_DB = $_ENV['MYSQL_DB'];
$MYSQL_USER = $_ENV['MYSQL_USER'];
$MYSQL_PASS= $_ENV['MYSQL_PASS'];
$MAX_THREADS = $_ENV['MAX_THREADS'];

// Function to create database connection
function createDatabaseConnection() {
    global $MYSQL_HOST, $MYSQL_USER, $MYSQL_PASS, $MYSQL_DB;
    
    try {
        $pdo = new PDO(
            "mysql:host=".$MYSQL_HOST.";port=25060;dbname=".$MYSQL_DB.";charset=utf8mb4",
            $MYSQL_USER,
            $MYSQL_PASS,
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt'
            )
        );
        
        // Test the connection
        $pdo->query('SELECT 1');
        error_log("Database connection established successfully");
        return $pdo;
        
    } catch(PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw $e;
    }
}

// Function to validate and reconnect if needed
function validateConnection($pdo) {
    try {
        $pdo->query('SELECT 1');
        return true;
    } catch (PDOException $e) {
        error_log("Database connection lost: " . $e->getMessage());
        return false;
    }
}

// Function to get valid database connection
function getValidConnection() {
    global $pdoConn;
    
    if (!$pdoConn || !validateConnection($pdoConn)) {
        error_log("Reconnecting to database...");
        $pdoConn = createDatabaseConnection();
    }
    
    return $pdoConn;
}

try{
	$pdoConn = createDatabaseConnection();
}catch(PDOException $e){
	error_log("Initial database connection failed: " . $e->getMessage());
	exit("Database connection failed");
}
?>
