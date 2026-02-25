<?php

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
require_once 'responses.php'; 

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    Response::error("Method not allowed", 405); 
}

if (empty($_POST['username']) || empty($_POST['password'])) {
    Response::error("Username and password are required", 400);
}

$username = $_POST['username'];
$password = $_POST['password'];

try {
    $sql = "SELECT * FROM user WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['passwordHash'])) {
        unset($user['passwordHash']);
        Response::success("Login successful", $user); 
    } else { 
        Response::error("Invalid username and password", 401);
    }

} catch (PDOException $e) {
    Response::error("A server error occurred while logging in: " . $e->getMessage(), 500);
}

// require_once('vendor/autoload.php');
// use Firebase\JWT\JWT;

// // ... Check username and password ...

// $secretKey = 'YOUR_SUPER_SECRET_KEY';

// // Create the JWT (Make it short-lived for security, e.g., 30 minutes)
// $payload = [
//     'iat' => time(),
//     'exp' => time() + (30 * 60), 
//     'data' => [
//         'userId' => $user['id'],
//         'role' => $user['role']
//     ]
// ];
// $jwt = JWT::encode($payload, $secretKey, 'HS256');

// // Set the Ironclad Cookie
// setcookie(
//     "auth_token",         // Name
//     $jwt,                 // The JWT value
//     time() + (30 * 60),   // Expiration (must match JWT exp)
//     "/",                  // Path (available across the whole API)
//     "",                   // Domain (leave blank for current domain)
//     true,                 // SECURE: Only send over HTTPS
//     true                  // HTTPONLY: JavaScript cannot read this!
// );

// // PHP 7.3+ Alternative for adding SameSite (Protects against CSRF)
// /*
// setcookie('auth_token', $jwt, [
//     'expires' => time() + (30 * 60),
//     'path' => '/',
//     'secure' => true,
//     'httponly' => true,
//     'samesite' => 'Strict' // <-- Blocks Cross-Site Request Forgery
// ]);
// */

// // Notice we do NOT send the token in the body.
// echo json_encode(["message" => "Logged in successfully."]);
?>