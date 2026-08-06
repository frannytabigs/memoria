<?php

require_once 'notallowed.php';

class ImageManager {
    private $uploadDir;
    private $baseUrl;
    private $maxFileSize;
    
    // Allowed strict extensions and MIME types
    private $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
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

    private function getSafeFilename($filename) {
        return preg_replace('/[^a-zA-Z0-9_\.-]/', '', basename($filename));
    }

    /**
     * Core Security Function: Validates size, extension, mime type, and image integrity.
     */
    private function validateImage($filePath, $originalName) {
        // 1. Check File Size
        if (filesize($filePath) > $this->maxFileSize) {
            return 'File exceeds the maximum allowed size of ' . ($this->maxFileSize / 1024 / 1024) . 'MB.';
        }

        // 2. Check Extension Whitelist
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            return 'Invalid file extension. Only JPG, PNG, GIF, and WEBP are allowed.';
        }

        // 3. Strictly Verify MIME Type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if (!in_array($mime, $this->allowedMimeTypes)) {
            return 'Invalid file content. The file does not match its extension.';
        }

        // 4. Deep Inspection: Verify it has valid image dimensions
        if (@getimagesize($filePath) === false) {
            return 'File is corrupted or is not a valid image.';
        }

        return true;
    }

    /**
     * GET ALL IMAGES WITH PAGINATION AND SEARCH
     * Defaults to Page 1, Limit 20 (or 101 if searching)
     */
    public function getAllImages($page = 1, $limit = 20, $search = '') {
        // Get all files, filter out '.' and '..'
        $files = array_values(array_diff(scandir($this->uploadDir), array('.', '..')));
        
        // SEARCH FILTER: If a search term is provided, filter the array
        if (!empty($search)) {
            $searchTerm = trim($search);
            $files = array_filter($files, function($file) use ($searchTerm) {
                // stripos makes the search case-insensitive
                return stripos($file, $searchTerm) !== false;
            });
            // Re-index the array after filtering
            $files = array_values($files);
        }

        // Ensure limit is a positive integer
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
                'url' => $this->baseUrl . $file
            ];
        }
        
        return [
            'success' => true, 
            'count' => count($images),
            'total_images' => $totalImages,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'search_term' => $search, // Echo back what was searched
            'images' => $images
        ];
    }

    public function uploadImage($tmpFilePath, $originalName) {
        if (!file_exists($tmpFilePath)) {
            return ['success' => false, 'error' => 'Source file does not exist.'];
        }

        // Run Security Checks
        $validation = $this->validateImage($tmpFilePath, $originalName);
        if ($validation !== true) {
            return ['success' => false, 'error' => $validation];
        }

        $safeName = $this->getSafeFilename($originalName);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $newName = uniqid('img_') . '.' . $ext;
        $destination = $this->uploadDir . $newName;

        if (rename($tmpFilePath, $destination)) {
            return [
                'success' => true,
                'message' => 'Image uploaded successfully.',
                'filename' => $newName,
                'url' => $this->baseUrl . $newName
            ];
        }

        return ['success' => false, 'error' => 'Failed to save image.'];
    }

    public function replaceImage($targetFilename, $sourceStreamOrFile) {
        $safeFile = $this->getSafeFilename($targetFilename);
        if (!$safeFile) return ['success' => false, 'error' => 'No filename provided.'];

        $destination = $this->uploadDir . $safeFile;
        if (!file_exists($destination)) {
            return ['success' => false, 'error' => 'Target image does not exist.'];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'img_replace_');
        
        if (is_string($sourceStreamOrFile) && $sourceStreamOrFile === 'php://input') {
            file_put_contents($tempFile, fopen("php://input", 'r'));
        } else {
            copy($sourceStreamOrFile, $tempFile);
        }

        $validation = $this->validateImage($tempFile, $safeFile);
        if ($validation !== true) {
            unlink($tempFile);
            return ['success' => false, 'error' => $validation];
        }

        if (rename($tempFile, $destination)) {
            return [
                'success' => true, 
                'message' => 'Image updated successfully.',
                'filename' => $safeFile,
                'url' => $this->baseUrl . $safeFile
            ];
        }

        unlink($tempFile); 
        return ['success' => false, 'error' => 'Failed to update image.'];
    }

    public function deleteImage($targetFilename) {
        $safeFile = $this->getSafeFilename($targetFilename);
        if (!$safeFile) return ['success' => false, 'error' => 'No filename provided.'];

        $destination = $this->uploadDir . $safeFile;
        if (file_exists($destination)) {
            unlink($destination);
            return ['success' => true, 'message' => 'Image deleted successfully.'];
        }
        
        return ['success' => false, 'error' => 'Image not found.'];
    }
}
?>