<?php

define('ITS_ME_JUSTTOVERIFY', true);

require_once 'imagemanager.php';
require_once 'checkuser.php';
require_once 'logs.php';

$userData = checkUser();

if( $userData['status'] !== 'Verified') {
    systemLog("Unauthorized access attempt by user {$userData['username']} with status {$userData['status']}.", $userData['username']);
    Response::error("Unauthorized access. Only verified users can manage images.", 403);
    exit();
}

$manager = new ImageManager();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    // ---------------------------------------------------------
    // GET: List all images
    // ---------------------------------------------------------
    case 'GET':
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        // If they are searching, force the limit to 101 as requested.
        // Otherwise, use their requested limit (or default to 20).
        if ($search !== '') {
            $limit = 101;
        } else {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        }
        
        // Pass all three arguments to the manager
        $result = $manager->getAllImages($page, $limit, $search);
        systemLog("{$userData['name']} ({$userData['username']}) retrieved image list. Page: {$page}, Limit: {$limit}, Search: '{$search}'", $userData['user_id']);        Response::send(200, true, '', null, $result);
        break;

    // ---------------------------------------------------------
    // POST: Upload a new image
    // ---------------------------------------------------------
    case 'POST':
        // if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        //     Response::error('No image uploaded or an upload error occurred.', 400);
        // }

        if (!isset($_FILES['image'])) {
            Response::error('No image included in the request.', 400);
        }

        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            Response::error('An upload error occurred: ' . $_FILES['image']['error'] . '. Please try again.', 400);
        }
        
        $result = $manager->uploadImage($_FILES['image']['tmp_name'], $_FILES['image']['name']);
        
        if ($result['success']) {
            systemLog("User {$userData['username']} uploaded a new image. ({$result['file']})", $userData['username']);
            Response::send(201, true, '', null, $result); // Pass to $owned
        } else {
            Response::error($result['error'], 400);
        }
        break;

    // ---------------------------------------------------------
    // PUT: Replace an existing image
    // ---------------------------------------------------------
    case 'PUT':
        $fileToReplace = $_GET['file'] ?? '';
        $result = $manager->replaceImage($fileToReplace, "php://input");
        
        if ($result['success']) {
            systemLog("User {$userData['username']} replaced an existing image. ({$result['file']})", $userData['username']);
            Response::send(200, true, '', null, $result); // Pass to $owned
        } else {
            Response::error($result['error'], 400);
        }
        break;

    // ---------------------------------------------------------
    // DELETE: Delete an image
    // ---------------------------------------------------------
    case 'DELETE':
        $fileToDelete = $_GET['file'] ?? '';
        $result = $manager->deleteImage($fileToDelete);
        
        if ($result['success']) {
            systemLog("User {$userData['username']} deleted an image. ({$result['file']})", $userData['username']);
            Response::send(200, true, '', null, $result); // Pass to $owned
        } else {
            Response::error($result['error'], 404);
        }
        break;

    default:
        Response::error('Method not allowed.', 405);
        break;
}   

?>