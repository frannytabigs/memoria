<?php

require_once 'notallowed.php';

class ImageManager {
    private $uploadDir;
    private $baseUrl;
    private $maxFileSize;
    
    // Allowed strict MIME types
    private $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // Default max file size is 10MB (10 * 1024 * 1024 bytes)
    public function __construct($uploadDirPath = null, $baseUrlPath = null, $maxFileSize = 10485760) {
        $this->uploadDir = $uploadDirPath ?? __DIR__ . '/images/';
        $this->maxFileSize = $maxFileSize;
        
        // 1. Determine the correct protocol (http vs https)
        $protocol = 'http';
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $protocol = 'https';
        } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $protocol = 'https';
        }

        // 2. Determine the correct host (Ngrok URL vs Local URL)
        $host = $_SERVER['HTTP_HOST'];
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
            if (strpos($host, ',') !== false) {
                $host = explode(',', $host)[0];
            }
        }

        // 3. Build the final URL
        $this->baseUrl = $baseUrlPath ?? $protocol . '://' . $host . dirname($_SERVER['REQUEST_URI']) . '/images/';
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Helper to map verified MIME types to their proper extensions.
     */
    private function getExtensionFromMime($mimeType) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        ];
        return $map[$mimeType] ?? false;
    }

    private function getSafeFilename($filename) {
        return preg_replace('/[^a-zA-Z0-9_\.-]/', '', basename($filename));
    }

    /**
     * Core Security Function: Validates size, extension, mime type, and image integrity.
     */
    private function validateImage($filePath, $originalName) {
        if (filesize($filePath) > $this->maxFileSize) {
            return 'File exceeds the maximum allowed size of ' . ($this->maxFileSize / 1024 / 1024) . 'MB.';
        }

        // Strict MIME Type verification using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if (!in_array($mime, $this->allowedMimeTypes)) {
            return 'Invalid file content. Only standard images are allowed.';
        }

        if (@getimagesize($filePath) === false) {
            return 'File is corrupted or is not a valid image.';
        }

        // Return the true MIME type so we can use it to force the correct extension
        return ['success' => true, 'mime' => $mime];
    }

    /**
     * GET ALL IMAGES WITH PAGINATION AND SEARCH
     * Defaults to Page 1, Limit 20 (or 101 if searching)
     */
    public function getAllImages($page = 1, $limit = 20, $search = '') {
        // 1. Scan the directory exactly ONCE for better performance
        $allFiles = scandir($this->uploadDir);
        $files = [];

        // ---------------------------------------------------------
        // IF SEARCHING: Filter up to 101 matches
        // ---------------------------------------------------------
        if (!empty($search)) {
            $searchTerm = trim($search);

            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                if (stripos($file, $searchTerm) !== false) {
                    $files[] = $file;

                    // Stop immediately after 101 matches
                    if (count($files) >= 101) {
                        break;
                    }
                }
            }
             
            $images = [];
            // FIX: Changed from $pagedFiles to $files
            foreach ($files as $file) { 
                $images[] = [
                    'filename' => $file,
                    'url' => $this->baseUrl . $file,
                    'size_in_bytes' => filesize($this->uploadDir . $file),
                    'type' => mime_content_type($this->uploadDir . $file)
                ];
            }
        
            // Unified return structure so the frontend doesn't break
            return [
                'success' => true, 
                'count' => count($images),
                'total_images' => count($images),
                'current_page' => 1,
                'total_pages' => 1,
                'search_term' => $search, 
                'images' => $images,
                'per_page' => 101
            ];
        } 
        
        // ---------------------------------------------------------
        // IF NOT SEARCHING: Apply standard pagination
        // ---------------------------------------------------------
        else {
            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $files[] = $file;
            }

            // Ensure limit and page are positive integers
            $limit = max(1, (int)$limit);
            $page = max(1, (int)$page);
            
            // Calculate pagination metadata
            $totalImages = count($files);
            $totalPages = max(1, (int)ceil($totalImages / $limit));
            
            // Cap the requested page to prevent integer overflows
            $page = max(1, min($page, $totalPages));
            
            // Calculate the starting index (offset) safely
            $offset = ($page - 1) * $limit;
            
            // Slice the array to get only the images for the requested page
            $pagedFiles = array_slice($files, $offset, $limit);
            
            $images = [];
            foreach ($pagedFiles as $file) {
                $images[] = [
                    'filename' => $file,
                    'url' => $this->baseUrl . $file,
                    'size_in_bytes' => filesize($this->uploadDir . $file),
                    'type' => mime_content_type($this->uploadDir . $file)   
                ];
            }
            
            return [
                'success' => true, 
                'count' => count($images),
                'total_images' => $totalImages,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'images' => $images,
                'per_page' => $limit
            ];
        }
    }
    /**
     * Updated Upload Image method handling the secure naming
     */
    public function uploadImage($tmpFilePath, $originalName, $requestedName = '') {
        if (!file_exists($tmpFilePath)) {
            return ['success' => false, 'error' => 'Source file does not exist.'];
        }

        $validation = $this->validateImage($tmpFilePath, $originalName);
        if (!isset($validation['success'])) {
            return ['success' => false, 'error' => $validation]; // Returns the error string
        }

        // 1. Force the true extension based on the actual file content, NOT user input
        $trueExt = $this->getExtensionFromMime($validation['mime']);

        // 2. Determine the base name (fallback to original name if no custom name provided)
        $baseName = trim($requestedName) !== '' ? trim($requestedName) : pathinfo($originalName, PATHINFO_FILENAME);
        
        // 3. Strictly sanitize the base name: letters, numbers, dashes, underscores ONLY
        $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '', $baseName);
        
        // 4. Fallback if the sanitization stripped everything
        if (empty($cleanName)) {
            $cleanName = 'img_' . bin2hex(random_bytes(5));
        }

        // 5. Construct the final safe filename
        $newName = $cleanName . '.' . $trueExt;
        $destination = $this->uploadDir . $newName;

        // 6. Prevent overwriting: If a file with this name exists, append a unique ID
        if (file_exists($destination)) {
            $newName = $cleanName . '_has_same_file_name_lol' . uniqid() . '.' . $trueExt;
            $destination = $this->uploadDir . $newName;
        }

        // Use move_uploaded_file for uploaded files for extra security
        if (is_uploaded_file($tmpFilePath)) {
            $saved = move_uploaded_file($tmpFilePath, $destination);
        } else {
            $saved = rename($tmpFilePath, $destination);
        }

        if ($saved) {
            return [
                'success' => true,
                'message' => 'Image uploaded successfully.',
                'filename' => $newName,
                'url' => $this->baseUrl . $newName,
                'size_in_bytes' => filesize($destination),
                'type' => $validation['mime']
            ];
        }

        return ['success' => false, 'error' => 'Failed to save image on the server. Filename: ' . $newName, 'status_code' => 500];
    }

    public function replaceImage($targetFilename, $sourceStreamOrFile) {
        $safeOldFile = $this->getSafeFilename($targetFilename);
        if (!$safeOldFile) return ['success' => false, 'error' => 'No target filename provided.'];

        $oldDestination = $this->uploadDir . $safeOldFile;
        if (!file_exists($oldDestination)) {
            return ['success' => false, 'error' => 'Target image does not exist.', 'status_code' => 404];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'img_replace_');
        
        if (is_string($sourceStreamOrFile) && $sourceStreamOrFile === 'php://input') {
            file_put_contents($tempFile, fopen("php://input", 'r'));
        } else {
            copy($sourceStreamOrFile, $tempFile);
        }

        // Validate the incoming file to ensure it's a safe, actual image
        $validation = $this->validateImage($tempFile, $safeOldFile);
        if (!isset($validation['success'])) {
            unlink($tempFile);
            return ['success' => false, 'error' => $validation];
        }

        // 1. Get the true extension from the newly uploaded file's MIME type
        $trueExt = $this->getExtensionFromMime($validation['mime']);
        
        // EXTRACT OLD EXTENSION: Get the old file's extension for the message comparison
        $oldExt = strtolower(pathinfo($safeOldFile, PATHINFO_EXTENSION));

        // 2. Determine the base name (we'll keep the original name but force the new extension)
        $baseName = pathinfo($safeOldFile, PATHINFO_FILENAME);
        
        // 3. Strictly sanitize the base name
        $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '', $baseName);
        if (empty($cleanName)) {
            $cleanName = 'img_' . bin2hex(random_bytes(5));
        }

        // 4. Construct the new filename
        $newFilename = $cleanName . '.' . $trueExt;
        $newDestination = $this->uploadDir . $newFilename;

        // 5. Collision prevention: If the new name is different from the old name, 
        // ensure we don't accidentally overwrite an unrelated existing image.
        if ($newFilename !== $safeOldFile && file_exists($newDestination)) {
            $newFilename = $cleanName . '_' . uniqid() . '.' . $trueExt;
            $newDestination = $this->uploadDir . $newFilename;
        }

        // 6. Apply the replacement
        if (rename($tempFile, $newDestination)) {
            
            // 7. Cleanup: If the filename changed (either the name itself or just the extension),
            // we need to delete the old file so it doesn't linger on the server.
            if ($newFilename !== $safeOldFile) {
                unlink($oldDestination);
            }
            
            // 8. DYNAMIC MESSAGE: Check if the extension changed
            $message = 'Image replaced successfully.';
            if ($oldExt !== $trueExt) {
                $message = "Image replaced successfully from {$oldExt} to {$trueExt}.";
            }

            return [
                'success' => true, 
                'message' => $message,
                'filename' => $newFilename,
                'url' => $this->baseUrl . $newFilename,
                'size_in_bytes' => filesize($newDestination),
                'type' => $validation['mime']
            ];
        }

        // ... end of replaceImage function
        unlink($tempFile); 
        return ['success' => false, 'error' => 'Failed to update image. Filename: ' . $safeOldFile, 'status_code' => 500];
        }

    public function deleteImage($targetFilename) {
        $safeFile = $this->getSafeFilename($targetFilename);
        
        // Return 400 Bad Request if no filename is given
        if (!$safeFile) return ['success' => false, 'error' => 'No filename provided.', 'status_code' => 400];

        $destination = $this->uploadDir . $safeFile;
        if (file_exists($destination)) {
            unlink($destination);
            return ['success' => true, 'message' => 'Image deleted successfully. Filename: ' . $safeFile];
        }
        
        // FIX: Return 404 Not Found if the file doesn't exist
        return ['success' => false, 'error' => 'Image not found. Filename: ' . $safeFile, 'status_code' => 404];
    }

    public function renameImage($oldFilename, $newRequestedName) {
        $safeOldFile = $this->getSafeFilename($oldFilename);
        if (!$safeOldFile) return ['success' => false, 'error' => 'No target filename provided.'];

        $oldDestination = $this->uploadDir . $safeOldFile;
        if (!file_exists($oldDestination)) {
            return ['success' => false, 'error' => 'Target image does not exist.', 'status_code' => 404];
        }

        if (trim($newRequestedName) === '') {
            return ['success' => false, 'error' => 'New filename cannot be empty.'];
        }

        // 1. Get the extension of the existing file so we don't accidentally change its filetype
        $ext = strtolower(pathinfo($safeOldFile, PATHINFO_EXTENSION));

        // 2. Sanitize the requested new name
        $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '', trim($newRequestedName));
        if (empty($cleanName)) {
            $cleanName = 'img_' . bin2hex(random_bytes(5));
        }

        // 3. Construct the new filename
        $newFilename = $cleanName . '.' . $ext;
        $newDestination = $this->uploadDir . $newFilename;

        // 4. Check if the new name is exactly the same as the old name
        if ($newFilename === $safeOldFile) {
            return ['success' => true, 'message' => 'Name is already unchanged.', 'filename' => $safeOldFile, 'url' => $this->baseUrl . $safeOldFile];
        }

        // 5. Collision prevention
        if (file_exists($newDestination)) {
             $newFilename = $cleanName . '_' . uniqid() . '.' . $ext;
             $newDestination = $this->uploadDir . $newFilename;
        }

        // 6. Rename the file
        if (rename($oldDestination, $newDestination)) {
            return [
                'success' => true, 
                'message' => 'Image renamed successfully.',
                'filename' => $newFilename,
                'url' => $this->baseUrl . $newFilename,
                'size_in_bytes' => filesize($newDestination),
                'type' => mime_content_type($newDestination)
            ];
        }

        return ['success' => false, 'error' => 'Failed to rename image on the server. Old Filename: ' . $safeOldFile . ', New Filename: ' . $newFilename, 'status_code' => 500];    }
    }
?>