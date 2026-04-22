<?php
require_once 'notallowed.php';
require_once 'responses.php'; 
require_once 'config.php';

// Manually require the JWT files IN THIS EXACT ORDER
require_once 'jwt/JWTExceptionWithPayloadInterface.php';
require_once 'jwt/BeforeValidException.php';
require_once 'jwt/ExpiredException.php';
require_once 'jwt/SignatureInvalidException.php';
require_once 'jwt/Key.php';
require_once 'jwt/JWT.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkuser($force_exit = true) {
    $jwt = null;

    // 1. FIRST: Check for Authorization Header (Safe for InfinityFree/Nginx)
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

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
        $JWT_SECRET = JWT_SECRET;
        $JWT_ALGO = JWT_ALGO;

        $decoded = JWT::decode($jwt, new Key($JWT_SECRET, $JWT_ALGO));
        return (array) $decoded->data;
    }
    catch (Exception $e) {
        // If the token is invalid/expired, clear the cookie just in case
        setcookie('auth_token', '', time() - 3600, '/');
        
        if ($force_exit) {
            Response::error("Invalid session", 401);
        }
        return false;
    }
}
?>