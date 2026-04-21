<?php
// git add .
// git commit -m "message"
//git push 
require_once 'notallowed.php';
require_once 'responses.php'; 
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkuser($force_exit = true){
    if (!isset($_COOKIE['auth_token'])) {
        if ($force_exit) {
            Response::error("Not logged in", 401);
        }
        return false;
    }
    try {

    $jwt = $_COOKIE['auth_token'];
    $JWT_SECRET = $_ENV['JWT_SECRET'];
    $JWT_ALGO = $_ENV['JWT_ALGO'];

    $decoded = JWT::decode($jwt, new Key($JWT_SECRET, $JWT_ALGO));
    return (array) $decoded->data;
    
    }

    catch (Exception $e) {
    setcookie('auth_token', '', time() - 3600, '/');
    Response::error("Invalid session", 401);
    }
}
?>