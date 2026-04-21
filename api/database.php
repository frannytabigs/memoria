<?php

require_once 'notallowed.php';
require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME']; 
$user = $_ENV['DB_USER'];   
$pass = $_ENV['DB_PASS'];   
$charset = $_ENV['DB_CHARSET'];

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";


$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
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