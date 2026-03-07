<?php

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
require_once 'responses.php'; 
// require_once 'ratelimit.php';



$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    setcookie('auth_token', '', time() - 3600, '/');
    Response::success("Logged out successfully");
}

if ($method === 'GET') {
   require_once 'admincheck.php';
   $userData = checkadmin(); 
   Response::success("Logged in", ["user" => $userData]);
}


if ($method !== 'POST') {
    Response::error("Method not allowed", 405); 
}

if (empty($_POST['username']) || empty($_POST['password'])) {
    Response::error("Username and password are required", 400);
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


$username = trim($_POST['username']);
$password = $_POST['password'];

try {

    $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        
        unset($user['password_hash']);

        $JWT_SECRET = $_ENV['JWT_SECRET'];
        $JWT_ALGO = $_ENV['JWT_ALGO'];
        $JWT_EXPIRATION = intval($_ENV['JWT_EXPIRATION']);

        $payload = [
            "iss" => $_ENV['APP_URL'],
            "iat" => time(),
            "exp" => time() + $JWT_EXPIRATION,
            "data" => [
                "id" => $user['id'],
                "username" => $user['username'],
                "role" => $user['role']
            ]   
        ];

        $jwt = JWT::encode($payload, $JWT_SECRET, $JWT_ALGO);

        setcookie('auth_token', $jwt, [
            'expires' => time() + $JWT_EXPIRATION,
            'path' => '/',
            'secure' => false, // set true in HTTPS (production)
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        Response::success("Login successful", $user); 
    } else { 
        Response::error("Invalid username and password", 401);
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    Response::error("An error occurred while logging in", 500);
}
?>