<?php

// Ensure database connection is available
require_once 'database.php';
require_once 'notallowed.php';

/**
 * Sends an SMS message using the TextBee API.
 *
 * @param string $phoneNumber The recipient's phone number (include country code, e.g., +63...)
 * @param string $message The content of the SMS.
 * @return array Returns an associative array with 'success' (boolean) and optional 'error' details.
 */
function sendSmsViaTextBee($phoneNumber, $message) {
    global $pdo; // Use the global PDO instance for database access
    try {
        // 1. Fetch the TextBee API Key from the settings table
        $keyStmt = $pdo->prepare("SELECT setting_value  FROM settings WHERE setting_key = 'textbee_api_key' LIMIT 1");
        $keyStmt->execute();
        $keyResult = $keyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$keyResult || empty($keyResult['setting_value'])) {
            return ['success' => false, 'error' => 'TextBee API key is missing in the database.'];
        }
        $apiKey = $keyResult['setting_value'];

        // 2. Fetch the TextBee Device ID (You can hardcode this if you prefer, 
        // but keeping it in the DB alongside the API key is best practice)
        $deviceStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'textbee_device_id' LIMIT 1");
        $deviceStmt->execute();
        $deviceResult = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deviceResult || empty($deviceResult['setting_value'])) {
            return ['success' => false, 'error' => 'TextBee Device ID is missing in the database.'];
        }
        $deviceId = $deviceResult['setting_value'];

        // 3. Prepare the TextBee API endpoint and payload
        $url = "https://api.textbee.dev/api/v1/gateway/devices/{$deviceId}/sendSMS";
        
        $payload = json_encode([
            "receivers" => [ $phoneNumber ],
            "smsBody" => $message
        ]);

        // 4. Initialize and execute the cURL request
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey 
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);

        // 5. Evaluate the results
        if ($curlError) {
            error_log("TextBee cURL Error: " . $curlError);
            return ['success' => false, 'error' => 'Connection failed: ' . $curlError];
        }

        // TextBee returns 200 OK on success
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'response' => json_decode($response, true)];
        } else {
            error_log("TextBee API Error (Code $httpCode): " . $response);
            return ['success' => false, 'error' => 'API rejected the request. Code: ' . $httpCode];
        }

    } catch (PDOException $e) {
        error_log("Database error in sendSmsViaTextBee: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database failure while fetching credentials.'. $e];
    }
}

?>