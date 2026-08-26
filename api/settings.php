<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'logs.php';
require_once 'imagemanager.php';
require_once 'checkuser.php';

$userData = checkuser(false);
$method = $_SERVER['REQUEST_METHOD'] ?? null;

// --- REST ROUTING: PARSE THE URI ---
// Check our custom .htaccess parameter first, then fallback to standard PATH_INFO
$pathInfo = $_GET['path_info'] ?? $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts);

if (!$userData) {
    try {
        if ($method === 'GET') {
            if ($resourceId) {
                // api/settings.php/1 - Fetch a specific setting
                $stmt = $pdo->prepare("SELECT * FROM settings WHERE setting_id = :id AND deleted_at IS NULL AND description NOT LIKE '%sensitive%'");
                $stmt->execute([':id' => $resourceId]);
                $setting = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$setting) {
                    Response::error("Public Setting not found", 404);
                }

                Response::success("Public System setting retrieved", $setting);
            } else {
                // api/settings.php - Fetch all settings
                $stmt = $pdo->prepare("SELECT * FROM settings WHERE deleted_at IS NULL AND description NOT LIKE '%sensitive%' ORDER BY created_at DESC");
                $stmt->execute();
                $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                Response::success("Public System settings retrieved", $settings);
            }
        }
    } catch (PDOException $e) {
        systemLog("Database Error: " . $e->getMessage(), "Unauthenticated");
        Response::error("An internal server error occurred while processing the database request. " . $e->getMessage(), 500);
    }
}
$manager = new ImageManager();

// Strict Gatekeeper: Only Verified Admins
if ($userData['role'] !== ROLE_ADMIN) {
    Response::error("Forbidden: Only administrators can access this resource", 403);
}


$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

// Determine the method (Handle method spoofing via $rawData)
if ($method === 'POST' && !empty($rawData['_method'])) {
    $method = strtoupper($rawData['_method']);
}

// Track newly uploaded files for rollback
$uploadedFilename = null;

//did not include pagination since this wont be big i guess? lol hopefully
try {
    switch ($method) {
        
        case 'GET':
            if ($resourceId) {
                // api/settings.php/1 - Fetch a specific setting
                $stmt = $pdo->prepare("SELECT * FROM settings WHERE setting_id = :id AND deleted_at IS NULL");
                $stmt->execute([':id' => $resourceId]);
                $setting = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$setting) {
                    Response::error("Setting not found", 404);
                }

                systemLog("{$userData['name']} ({$userData['username']}) retrieved setting ID: {$resourceId}.", $userData['user_id']);
                Response::success("System setting retrieved", $setting);
                
            } else {
                // api/settings.php - Fetch all settings
                $stmt = $pdo->prepare("SELECT * FROM settings WHERE deleted_at IS NULL ORDER BY created_at DESC");
                $stmt->execute();
                $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                systemLog("{$userData['name']} ({$userData['username']}) retrieved all system settings.", $userData['user_id']);
                Response::success("System settings retrieved", $settings);
            }
            break;


        case 'POST':
            // --- BULK MODE ---
            if (isset($rawData['bulk_settings']) && is_array($rawData['bulk_settings'])) {
                
                $pdo->beginTransaction(); // Start transaction
                $uploadedFiles = []; // Track all files uploaded in this bulk request for rollback

                try {
                    foreach ($rawData['bulk_settings'] as $index => $setting) {
                        $sKey = trim($setting['setting_key'] ?? '');
                        $sValue = trim($setting['setting_value'] ?? '');
                        $sDesc = trim($setting['description'] ?? '');

                        if (empty($sKey)) continue; // Skip invalid entries

                        // Handle potential bulk image upload 
                        // Note: PHP formats bulk files as $_FILES['bulk_images']['name'][$index]
                        if (isset($_FILES['bulk_images']['name'][$index]) && $_FILES['bulk_images']['error'][$index] === UPLOAD_ERR_OK) {
                            $requestedName = "setting_" . preg_replace('/[^A-Za-z0-9\-]/', '', $sKey) . "_" . time();
                            
                            // Check if replacing an existing image
                            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = :key AND deleted_at IS NULL");
                            $stmt->execute([':key' => $sKey]);
                            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($existing && !empty($existing['setting_value'])) {
                                $result = $manager->replaceImage($existing['setting_value'], $_FILES['bulk_images']['tmp_name'][$index]);
                            } else {
                                $result = $manager->uploadImage(
                                    $_FILES['bulk_images']['tmp_name'][$index], 
                                    $_FILES['bulk_images']['name'][$index], 
                                    $requestedName
                                );
                            }

                            if (!$result['success']) {
                                throw new Exception("Image upload failed for {$sKey}: " . $result['error']);
                            }
                            $sValue = $result['filepath'];
                            $uploadedFiles[] = $sValue; // Track for rollback
                        }

                        // UPSERT LOGIC (Insert or Update if setting_key exists)
                        $sql = "INSERT INTO settings (setting_key, setting_value, description, created_by) 
                                VALUES (:key, :value, :desc, :user_id)
                                ON DUPLICATE KEY UPDATE 
                                setting_value = VALUES(setting_value), 
                                description = VALUES(description), 
                                updated_by = VALUES(created_by)";
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ':key' => $sKey,
                            ':value' => $sValue,
                            ':desc' => $sDesc,
                            ':user_id' => $userData['user_id']
                        ]);
                    }

                    $pdo->commit(); // Save all changes to the database
                    systemLog("{$userData['name']} ({$userData['username']}) performed a bulk settings update.", $userData['user_id']);
                    Response::success("Bulk settings saved successfully");

                } catch (Exception $e) {
                    $pdo->rollBack(); // Undo all database changes
                    
                    // Delete any images that were uploaded before the crash
                    foreach ($uploadedFiles as $file) {
                        $manager->deleteImage($file);
                    }
                    
                    systemLog("Bulk Update Error: " . $e->getMessage(), $userData['user_id']);
                    Response::error("Bulk update failed: " . $e->getMessage(), 500);
                }
                
                break; // End Bulk Mode
            }

            // --- SINGLE MODE (Your existing code goes here) ---
            $settingKey = trim($rawData['setting_key'] ?? '');
            $settingValue = trim($rawData['setting_value'] ?? '');
            $description = trim($rawData['description'] ?? '');
            
            $hasImage = isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;

            if ($settingKey === '' || ($settingValue === '' && !$hasImage) || $description === '') {
                Response::error("setting_key, setting_description, and either a setting_value or an image are required", 400);
            }
            
            // ... (Keep the rest of your single POST logic here) ...
            
            break;


        case 'PUT':
        case 'PATCH':
            // Prioritize the URL ID, fallback to payload ID
            $settingId = $resourceId ?? $rawData['setting_id'] ?? null;
            $settingKey = trim($rawData['setting_key'] ?? '');
            $settingValue = trim($rawData['setting_value'] ?? '');
            $description = trim($rawData['description'] ?? '');
            
            if (!$settingId || empty($settingKey)) {
                Response::error("Setting ID (via URL or payload) and Setting Key are required for updates", 400);
            }

            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $settingId]);
            $existingSetting = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingSetting) {
                Response::error("Setting not found or has been deleted", 404);
            }

            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $targetFile = $existingSetting['setting_value'];
                $result = $manager->replaceImage($targetFile, $_FILES['image']['tmp_name']);
                
                if (!$result['success']) {
                    Response::error($result['error'], $result['status_code'] ?? 400);
                }
                
                $settingValue = $result['filepath'] ?? $targetFile; 
                if (isset($result['filepath'])) {
                    $uploadedFilename = $result['filepath'];
                }
            }

            $sql = "UPDATE settings 
                    SET setting_key = :key, setting_value = :value, description = :description, updated_by = :user_id 
                    WHERE setting_id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':key' => $settingKey,
                ':value' => $settingValue,
                ':description' => $description,
                ':user_id' => $userData['user_id'],
                ':id' => $settingId
            ]);

            systemLog("{$userData['name']} ({$userData['username']}) updated setting ID: {$settingId}", $userData['user_id']);
            Response::success("Setting updated successfully", ["setting_id" => $settingId]);
            break;


        case 'DELETE':
            // Use the URL ID
            $settingId = $resourceId ?? $rawData['setting_id'] ?? null;
            
            if (!$settingId) {
                Response::error("Setting ID is required for deletion (e.g., DELETE /api/setting.php/1)", 400);
            }

            $sql = "UPDATE settings 
                    SET deleted_at = CURRENT_TIMESTAMP, updated_by = :user_id 
                    WHERE setting_id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $userData['user_id'],
                ':id' => $settingId
            ]);

            if ($stmt->rowCount() === 0) {
                Response::error("Setting not found or already deleted", 404);
            }

            systemLog("{$userData['name']} ({$userData['username']}) soft-deleted setting ID: {$settingId}", $userData['user_id']);
            Response::success("Setting deleted successfully", []);
            break;


        default:
            Response::error("Method not allowed", 405);
            break;
    }

} catch (PDOException $e) {
    if ($uploadedFilename !== null) {
        $cleanupResult = $manager->deleteImage($uploadedFilename);
        if (!$cleanupResult['success']) {
            error_log("Could not clean up setting image {$uploadedFilename}: {$cleanupResult['error']}");
            systemLog("Could not clean up setting image {$uploadedFilename}: {$cleanupResult['error']}", $userData['user_id']);
        }
    }

    systemLog("Database Error: " . $e->getMessage(), $userData['user_id']);
    Response::error("An internal server error occurred while processing the database request. " . $e->getMessage(), 500);
}
?>