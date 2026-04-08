<?php 

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
require_once 'responses.php'; 
require_once 'usercheck.php';

$userData = checkuser(); 

if ($userData['role'] !== 'admin') {
    Response::error("Forbidden", 403);
}



?>

