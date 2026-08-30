<?php

define('ITS_ME_JUSTTOVERIFY', true);
// require_once 'database.php';
// require_once 'responses.php'; 
require_once 'logs.php';
// require_once 'config.php';

// // Manually require the JWT files
// require_once 'jwt/JWTExceptionWithPayloadInterface.php'; // <-- ADD THIS AT THE TOP
// require_once 'jwt/BeforeValidException.php';
// require_once 'jwt/ExpiredException.php';
// require_once 'jwt/SignatureInvalidException.php';
// require_once 'jwt/Key.php';
// require_once 'jwt/JWT.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once 'checkuser.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

if ($method === 'DELETE') {
    $userData = checkuser(false);
    if ($userData) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") logged out", $userData['user_id']); 
        setcookie('auth_token', '', time() - JWT_EXPIRATION, '/');
        Response::success("Logged out successfully");
    }
    Response::error("Not logged in", 401);
}

if ($method === 'GET') {
   $userData = checkuser(); 
   Response::success("Logged in",  $userData);
}


if ($method !== 'POST') {
    Response::error("Method not allowed", 405); 
}

$userData = checkuser(false); // Check if user is already logged in, but don't force exit
if ($userData) {

    setcookie('auth_token', '', time() - JWT_EXPIRATION, '/');
    Response::error("Already logged in as " . $userData['username'] . ". Try again, I logged you out", 400);
}

// --- HYBRID INPUT PARSER ---
$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

if (empty($rawData['username']) || empty($rawData['password'])) {
    Response::error("Username and password are required", 400);
}

$username = trim($rawData['username']);
$password = $rawData['password'];

try {

    $sql = "SELECT * FROM users WHERE username = :username AND deleted_at is NULL LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        
        // --- NEW: Save the hash before unsetting it ---
        $currentPasswordHash = $user['password_hash'];
        unset($user['password_hash']);

        if ($user['status'] !== STATUS_VERIFIED) {
            Response::error("Your account is not verified yet. Please wait for admin verification.", 403);
        }

        $JWT_SECRET = JWT_SECRET;
        $JWT_ALGO = JWT_ALGO;
        $JWT_EXPIRATION = intval(JWT_EXPIRATION);

        $payload = [
            "iss" => APP_URL,
            "iat" => time(),
            "exp" => time() + $JWT_EXPIRATION,
            "data" => [
                "user_id" => $user['user_id'],
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

        $jwt = JWT::encode($payload, $JWT_SECRET . $currentPasswordHash, $JWT_ALGO);

        setcookie('auth_token', $jwt, [
            'expires' => time() + $JWT_EXPIRATION,
            'path' => '/',
            'secure' => false, // set true in HTTPS (production)
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        systemLog($user['name'] . " (" . $user['username'] . ") logged in", $user['user_id']);
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