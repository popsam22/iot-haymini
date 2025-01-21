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

try{
	$pdoConn = new PDO("mysql:host=".$MYSQL_HOST,$MYSQL_USER,$MYSQL_PASS);
	$pdoConn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$pdoConn->exec("use ".$MYSQL_DB.";");

    // echo "Connected to the database successfully!";
}catch(PDOException $e){
	echo $e->getMessage();
}
?>
