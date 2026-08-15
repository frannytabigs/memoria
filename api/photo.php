<?php

define('ITS_ME_JUSTTOVERIFY', true);

require_once 'imagemanager.php';
require_once 'checkuser.php';
require_once 'logs.php';

$userData = checkUser();    

$manager = new ImageManager();
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------
// URL PARSING (To support /photo/[filename])
// ---------------------------------------------------------
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($requestUri, '/'));

$targetFile = '';
$photoIndex = array_search('photo', $pathParts);
if ($photoIndex !== false && isset($pathParts[$photoIndex + 1])) {
    $targetFile = urldecode($pathParts[$photoIndex + 1]);
}

$jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($method) {
    // ---------------------------------------------------------
    // GET: Retrieve photos 
    // ---------------------------------------------------------
    // ---------------------------------------------------------
    // GET: Retrieve photos or a single photo stream
    // ---------------------------------------------------------
    case 'GET':
        // If a specific file is requested (e.g., GET /photo/1.gif)
        if (!empty($targetFile)) {
            $filePath = __DIR__ . '/images/' . basename($targetFile);
            
            if (file_exists($filePath)) {
                // Serve the image file directly
                $mime = mime_content_type($filePath);
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit;
            } else {
                Response::error('Photo not found.', 404);
            }
        }

        // Otherwise, return the paginated JSON list of photos
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        $result = $manager->getAllImages($page, 20, $search);
        $searchTerm = '';
        if ($search !== '') {
            $searchTerm = ",Search: '{$search}'";
        } 
        systemLog("{$userData['name']} ({$userData['username']}) retrieved photo list. Page: {$page} {$searchTerm}", $userData['user_id']);        
       
        if ($search !== '') {
            $dataresult = [
                'photos' => $result['images'],
                'search_term' => $search
            ];
        } else {
            $dataresult = [
                'pagination' => [
                    'total_photos' => $result['total_images'], 
                    'current_page' => $result['current_page'],
                    'total_pages' => $result['total_pages'],
                    'per_page' => $result['per_page']
                ],
                'photos' => $result['images'] 
            ];
        }
        Response::success("Photos fetched successfully", $dataresult);
        break;

    // ---------------------------------------------------------
    // POST: Upload a new photo 
    // ---------------------------------------------------------
    case 'POST':
        if (!isset($_FILES['image'])) {
            Response::error('No photo included in the request.', 400);
        }

        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            Response::error('An upload error occurred: ' . $_FILES['image']['error'] . '. Please try again.', 400);
        }

        $requestedName = trim($_POST['filename'] ?? ($jsonBody['filename'] ?? ''));    

        $result = $manager->uploadImage(
            $_FILES['image']['tmp_name'], 
            $_FILES['image']['name'], 
            $requestedName
        );

        if ($result['success']) {
            systemLog("{$userData['name']} ({$userData['username']}) uploaded a new photo. ({$result['filename']})", $userData['user_id']);
            $dataresult = [
                'filename' => $result['filename'],
                'size_in_bytes' => $result['size_in_bytes'],
                'type' => $result['type'],
                'url' => $result['url']
            ];
            // Changed from 'images' => [$dataresult] to a singular 'photo' object
            Response::success("Photo uploaded successfully. Filename: " . $result['filename'], ['photo' => $dataresult], 201);
        } else {
            Response::error($result['error'], 400);
        }
        break;

    // ---------------------------------------------------------
    // PUT: Replace an existing photo
    // ---------------------------------------------------------
    case 'PUT':
        if (empty($targetFile)) {
            Response::error('No target filename provided in the URL.', 400);
        }
        
        $result = $manager->replaceImage($targetFile, "php://input");
        
        if ($result['success']) {
            systemLog("{$userData['name']} ({$userData['username']}). {$result['message']} ({$result['filename']})", $userData['user_id']);
            // Changed from 'images' array wrapper to a singular 'photo' object
            Response::success("Photo replaced successfully. Filename: " . $result['filename'], [
                'photo' => [
                    'filename' => $result['filename'],
                    'size_in_bytes' => $result['size_in_bytes'],
                    'type' => $result['type'],
                    'url' => $result['url']
                ]
            ]);
        } else {
            Response::error($result['error'], $result['status_code'] ?? 400);
        }
        break;

    // ---------------------------------------------------------
    // DELETE: Remove a photo
    // ---------------------------------------------------------
    case 'DELETE':
        if (empty($targetFile)) {
            Response::error('No target filename provided in the URL.', 400);
        }

        $result = $manager->deleteImage($targetFile);
        
        if ($result['success']) {
            systemLog("{$userData['name']} ({$userData['username']}) deleted a photo. ({$targetFile})", $userData['user_id']);
            Response::success("Photo deleted successfully. Filename: {$targetFile}");
        } else {
            Response::error($result['error'], $result['status_code'] ?? 400);
        }
        break;

    // ---------------------------------------------------------
    // PATCH: Rename an existing photo
    // ---------------------------------------------------------
    case 'PATCH':
        if (empty($targetFile)) {
            Response::error('No target filename provided in the URL.', 400);
        }

        $newName = trim($jsonBody['newfilename'] ?? '');

        if (empty($newName)) {
            Response::error('Missing "newfilename" in request body.', 400);
        }

        $result = $manager->renameImage($targetFile, $newName);
        
        if ($result['success']) {
            systemLog("User {$userData['username']} renamed photo {$targetFile} to {$result['filename']}", $userData['user_id']);
            // Changed from 'images' array wrapper to a singular 'photo' object
            Response::success("Photo renamed successfully. From: " . $targetFile . " To: " . $result['filename'], [ 
                'photo' => [
                    'filename' => $result['filename'],
                    'size_in_bytes' => $result['size_in_bytes'],
                    'type' => $result['type'],
                    'url' => $result['url']
                ]
            ]);
        } else {
            Response::error($result['error'], $result['status_code'] ?? 400); 
        }
        break;

    default:
        Response::error('Method not allowed.', 405);
        break;
}   
?>