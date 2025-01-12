<?php
define('SERVER_IP', '192.168.8.101');
// define('SERVER_IP', '172.20.10.5');
define('SERVER_PORT', '7788');
// define('MYSQL_HOST', 'localhost');
define('MYSQL_HOST', 'punch.clq6gw02aji0.us-east-1.rds.amazonaws.com');
// punch.cpiu0a4smj6j.us-east-1.rds.amazonaws.com
// define('MYSQL_DB', 'Demo');
define('MYSQL_DB', 'punch');
// define('MYSQL_USER', 'root');
define('MYSQL_USER', 'admin');
// define('MYSQL_PASS', '');
define('MYSQL_PASS', '8AeFCfi9HELiSFoie4MV');
// pbxATdZmvLdSvQ2kNYDs
define('MAX_THREADS', 32);
try{
	$pdoConn = new PDO("mysql:host=".MYSQL_HOST,MYSQL_USER,MYSQL_PASS);
	$pdoConn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$pdoConn->exec("use ".MYSQL_DB.";");
}catch(PDOException $e){
	echo $e->getMessage();
}
?>
