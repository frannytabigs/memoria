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
    // GET: Retrieve images
    // ---------------------------------------------------------
    case 'GET':
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        // $limit = ($search !== '') ? 101 : (isset($_GET['limit']) ? (int)$_GET['limit'] : 20);
        
        $result = $manager->getAllImages($page, 20, $search);
        $searchTerm = '';
        if ($search !== '') {
            $searchTerm = "Search: '{$search}'";
        } 
        systemLog("{$userData['name']} ({$userData['username']}) retrieved image list. Page: {$page}, Limit: 20, {$searchTerm}", $userData['user_id']);        
       
        if ($search !== '') {
            $dataresult = [
                'images' => $result['images'],
                'search_term' => $search
            ];
        }
        else {
            $dataresult = [
           'pagination' => [
                'total_images' => $result['total_images'],
                'current_page' => $result['current_page'],
                'total_pages' => $result['total_pages'],
                'per_page' => $result['per_page']
            ],
            'images' => $result['images']
        ];
        }
        Response::success("Images fetched successfully", $dataresult);
        break;

    // ---------------------------------------------------------
    // POST: Upload a new image 
    // ---------------------------------------------------------
    case 'POST':
        if (!isset($_FILES['image'])) {
            Response::error('No image included in the request.', 400);
        }

        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            Response::error('An upload error occurred: ' . $_FILES['image']['error'] . '. Please try again.', 400);
        }

        // Grab the requested filename if they provided one, otherwise pass empty string
        $requestedName = trim($_POST['filename'] ?? '');

        // Pass the tmp_name, original name, and requested name to the manager
        $result = $manager->uploadImage(
            $_FILES['image']['tmp_name'], 
            $_FILES['image']['name'], 
            $requestedName
        );

        if ($result['success']) {
            systemLog("User {$userData['username']} uploaded a new image. ({$result['filename']})", $userData['user_id']);
            $dataresult = [
                'filename' => $result['filename'],
                'size_in_bytes' => $result['size_in_bytes'],
                'type' => $result['type'],
                'url' => $result['url']
            ];
            Response::success("Image uploaded successfully" . ' Filename: ' . $result['filename'], ['images' => [$dataresult]], 201);
        } else {
            Response::error($result['error'], 400);
        }
        break;

    // ---------------------------------------------------------
    // PUT: Replace an existing image
    // ---------------------------------------------------------
    case 'PUT':
        $fileToReplace = $_GET['filename'] ?? '';
        
        // Pass the requested name as the third parameter
        $result = $manager->replaceImage($fileToReplace, "php://input");
        
        if ($result['success']) {
            systemLog("{$userData['name']} ({$userData['username']}). {$result['message']} ({$result['filename']})", $userData['user_id']);
            Response::success("Image replaced successfully" . ' Filename: ' . $result['filename'], [
                'images' => [[
                    'filename' => $result['filename'],
                    'size_in_bytes' => $result['size_in_bytes'],
                    'type' => $result['type'],
                    'url' => $result['url']
                ]]
            ]);
        } else {
            Response::error($result['error'], $result['status_code'] ?? 400);
        }
        break;

    // ---------------------------------------------------------
    // DELETE: Remove an image
    // ---------------------------------------------------------
    case 'DELETE':
        $fileToDelete = $_GET['filename'] ?? '';
        $result = $manager->deleteImage($fileToDelete);
        
        if ($result['success']) {
            systemLog("User {$userData['username']} deleted an image. ({$fileToDelete})", $userData['user_id']);
            Response::success("Image deleted successfully. " . "Filename: {$fileToDelete}");
        } else {
            Response::error($result['error'], $result['status_code'] ?? 400);
        }
        break;

    // ---------------------------------------------------------
    // PATCH: Rename an existing image (No file upload)
    // ---------------------------------------------------------
    case 'PATCH':
        $fileToRename = $_GET['filename'] ?? '';
        $newName = $_GET['newfilename'] ?? '';

        $result = $manager->renameImage($fileToRename, $newName);
        
        if ($result['success']) {
            systemLog("User {$userData['username']} renamed image {$fileToRename} to {$result['filename']}", $userData['user_id']);
            Response::success("Image renamed successfully. From: " . $fileToRename . " To: " . $result['filename'], [ 'images' => [[
                'filename' => $result['filename'],
                'size_in_bytes' => $result['size_in_bytes'],
                'type' => $result['type'],
                'url' => $result['url']
            ]]]);
        } else {
            Response::error($result['error'], $result['status_code'] ?? 400); 
        }
        break;

    default:
        Response::error('Method not allowed.', 405);
        break;
}   
?>