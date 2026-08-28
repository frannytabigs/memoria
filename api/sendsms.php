<?php 

define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'textbee.php';
require_once 'logs.php';

$userData = checkuser();

$method = $_SERVER['REQUEST_METHOD'] ?? null;


// --- HYBRID INPUT PARSER ---
$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [],
    $_POST ?? []
);

if ($method === 'POST') {
    
    // Default to true if not provided
    $includeCemeteryName = $rawData['include_cemetery_name'] ?? true;

    if (
        !isset($rawData['phone_number']) ||
        !isset($rawData['message'])
    ) {
        echo json_encode([
            "success" => false,
            "message" => "phone_number and message are required."
        ]);
        exit;
    }
    
    $smsStatus = sendSmsViaTextBee($rawData['phone_number'], $rawData['message'], $rawData['include_cemetery_name']);
    
    if (!$smsStatus['success']) {
        systemLog("Failed to send SMS to {$rawData['phone_number']}. Error: {$smsStatus['error']}. Content: {$rawData['message']}", $userData['user_id']);
        
        echo json_encode([
            "success" => false, 
            "message" => "Saved changes, but failed to send SMS: " . $smsStatus['error']
        ]);
        exit; 
    }

    echo json_encode([
        "success" => true,
        "message" => "SMS sent successfully to " . $rawData['phone_number'] . " with the message content: " . $rawData['message']
    ]);
    systemLog("SMS sent to {$rawData['phone_number']}. Content: {$rawData['message']}", $userData['user_id']);
    exit;
}

Response::error("Method Not Allowed", 405);
?>