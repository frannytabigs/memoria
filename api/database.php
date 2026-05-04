<?php
require_once 'notallowed.php';
require_once 'config.php'; // Load our new native config

header('Content-Type: application/json');

$host = DB_HOST;
$db   = DB_NAME; 
$user = DB_USER;   
$pass = DB_PASS;   
$charset = DB_CHARSET;

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Add this line right here! It forces Philippine Time (+08:00) on connection.
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'" 
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // echo "Database connection successful!"; 
} catch (\PDOException $e) {
    error_log($e->getMessage()); 
    require_once 'responses.php';
    require_once 'logs.php';
    systemLog("Database connection failed: " . $e->getMessage());
    Response::error("Database connection failed", 500);
}
?>