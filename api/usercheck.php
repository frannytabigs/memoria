<?php
require_once 'notallowed.php';
require_once 'responses.php'; 
require_once 'config.php';
require_once 'database.php'; // MUST INCLUDE DATABASE CONNECTION

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
    global $pdo; // Bring in the PDO connection
    $jwt = null;

    // 1. FIRST: Check for Authorization Header
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
    } 
    // 2. SECOND: Fallback to checking the HttpOnly Cookie
    else if (isset($_COOKIE['auth_token'])) {
        $jwt = $_COOKIE['auth_token'];
    }

    // 3. Reject if no token
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
        $userData = (array) $decoded->data;

        // 5. REAL-TIME DATABASE VERIFICATION
        $stmt = $pdo->prepare("SELECT status, role FROM users WHERE user_id = :id LIMIT 1");
        $stmt->execute([':id' => $userData['user_id']]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user was deleted or unverified by admin, kill the session
        if (!$dbUser || $dbUser['status'] !== 'Verified') {
            setcookie('auth_token', '', time() - 3600, '/'); 
            if ($force_exit) {
                Response::error("Account is unverified or restricted.", 401);
            }
            return false;
        }

        // Keep the payload fresh by injecting the latest DB role and status
        $userData['status'] = $dbUser['status'];
        $userData['role'] = $dbUser['role'];

        return $userData;
    }
    catch (Exception $e) {
        setcookie('auth_token', '', time() - 3600, '/');
        if ($force_exit) {
            Response::error("Invalid session", 401);
        }
        return false;
    }
}
?>