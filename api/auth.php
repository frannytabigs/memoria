<?php

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
require_once 'responses.php'; 
// require_once 'ratelimit.php';
require_once 'logs.php';


$method = $_SERVER['REQUEST_METHOD'] ?? null;

if ($method === 'DELETE') {
    
    require_once 'usercheck.php';

    $userData = checkuser(false);
    if ($userData) systemLog($userData['name'] . " (" . $userData['username'] . ") logged out", $userData['id']); 

    setcookie('auth_token', '', time() - 3600, '/');
    Response::success("Logged out successfully");
}

if ($method === 'GET') {
   require_once 'usercheck.php';
   $userData = checkuser(); 
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

        if ($user['status'] !== 'Verified') {
            Response::error("Your account is not verified yet. Please wait for admin verification.", 403);
        }

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
                "role" => $user['role'],
                "status" => $user['status'],
                "name" => $user['name'],
                "email" => $user['email'],
                "phone_number" => $user['phone_number'],
                "created_at" => $user['created_at'],
                "updated_at" => $user['updated_at']
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

        systemLog($user['name'] . " (" . $user['username'] . ") logged in", $user['id']);
        Response::success("Login successful", $user); 

        // In the future i think to support other than website or web-app
        // Response::success("Login successful", [
        //     "user" => $user,
        //     "token" => $jwt 
        // ]);
        
    } else { 
        systemLog("Failed login attempt with username: " . $username, null);
        Response::error("Invalid username and password", 401);
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    systemLog("Database error during login attempt for username: " . $username . " " . $e->getMessage(), null);
    Response::error("An error occurred while logging in", 500);
}
?>