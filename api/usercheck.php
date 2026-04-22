<?php
// git add .
// git commit -m "message"
// git push 
require_once 'notallowed.php';
require_once 'responses.php'; 
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkuser($force_exit = true) {
    $jwt = null;

    // 1. FIRST: Check for Authorization Header (For Mobile Apps & External APIs)
    // We use apache_request_headers() to grab all headers safely
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? '';

    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
    } 
    // 2. SECOND: Fallback to checking the HttpOnly Cookie (For Web Browsers)
    else if (isset($_COOKIE['auth_token'])) {
        $jwt = $_COOKIE['auth_token'];
    }

    // 3. If no token was found in either location, reject the request
    if (!$jwt) {
        if ($force_exit) {
            Response::error("Not logged in", 401);
        }
        return false;
    }

    // 4. Verify and decode the JWT
    try {
        $JWT_SECRET = $_ENV['JWT_SECRET'];
        $JWT_ALGO = $_ENV['JWT_ALGO'];

        $decoded = JWT::decode($jwt, new Key($JWT_SECRET, $JWT_ALGO));
        return (array) $decoded->data;
    }
    catch (Exception $e) {
        // If the token is invalid/expired, clear the cookie just in case they were using one
        setcookie('auth_token', '', time() - 3600, '/');
        
        if ($force_exit) {
            Response::error("Invalid session", 401);
        }
        return false;
    }
}
?>