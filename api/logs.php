<?php
require_once 'notallowed.php';
require_once 'config.php';

/**
 * Writes an encrypted log entry to logs.txt
 * * @param string $action The description of what happened
 * @param int|string $userId The ID of the user who did it (optional)
 */
function systemLog($action, $userId = 'System') {
    $secretKey = LOG_SECRET_KEY; // Use the constant
    $method = 'aes-256-cbc';
    
    // 1. Generate a random Initialization Vector (IV) for this specific line
    $ivLength = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($ivLength);
    
    // 2. Format the message
    $date = date('Y-m-d H:i:s');
    $rawMessage = "[$date] $action (User ID: $userId)";
    
    // 3. Encrypt the message
    $encrypted = openssl_encrypt($rawMessage, $method, $secretKey, 0, $iv);
    
    // 4. Combine the IV and Encrypted text, then Base64 encode it so it looks like clean text
    $payload = base64_encode($iv . '::' . $encrypted) . PHP_EOL;
    
    // 5. Append to the file. LOCK_EX prevents two scripts from writing at the exact same time!
    $logFile = __DIR__ . '/logs.txt';
    file_put_contents($logFile, $payload, FILE_APPEND | LOCK_EX);
}

/**
 * Reads and decrypts the logs.txt file
 * * @return array An array of decrypted log strings
 */
function readLogs() {
    
    $secretKey = LOG_SECRET_KEY; // Use the constant
    $method = 'aes-256-cbc';
    
    $logFile = __DIR__ . '/logs.txt';
    $decryptedLogs = [];

    if (!file_exists($logFile)) {
        return []; // Return empty array if no logs exist yet
    }

    // Read the file line by line
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $decoded = base64_decode($line);
        
        // Split the IV and the Encrypted text back apart
        if (strpos($decoded, '::') !== false) {
            list($iv, $encrypted) = explode('::', $decoded, 2);
            
            // Decrypt it
            $decrypted = openssl_decrypt($encrypted, $method, $secretKey, 0, $iv);
            
            if ($decrypted !== false) {
                $decryptedLogs[] = $decrypted;
            }
        }
    }
    
    // Reverse the array so the newest logs show up first
    return array_reverse($decryptedLogs);
}
?>