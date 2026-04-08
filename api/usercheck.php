<?php
// git add .
// git commit -m "message"
//git push 

require_once 'responses.php'; 
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkuser(){
    if (!isset($_COOKIE['auth_token'])) {
    Response::error("Not logged in", 401);
    }
    try {

    $jwt = $_COOKIE['auth_token'];
    $JWT_SECRET = $_ENV['JWT_SECRET'];
    $JWT_ALGO = $_ENV['JWT_ALGO'];

    $decoded = JWT::decode($jwt, new Key($JWT_SECRET, $JWT_ALGO));
    return $decoded->data;

    }

    catch (Exception $e) {
    setcookie('auth_token', '', time() - 3600, '/');
    Response::error("Invalid session", 401);
    }
}
?>