<?php 

define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'textbee.php';
require_once 'logs.php';

header('Content-Type: application/json');

$userData = checkuser();

$method = $_SERVER['REQUEST_METHOD'] ?? null;


// --- HYBRID INPUT PARSER ---
$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [],
    $_POST ?? []
);

$role = $userData['role'];
if ( !in_array( $role , [ ROLE_ADMIN, ROLE_OFFICE ] ) ){
    Response::error("Forbidden. You do not have permission to perform this action.", 403);
}

if ($method === 'POST') {
    
    // Default to true if not provided
    $includeCemeteryName = $rawData['include_cemetery_name'] ?? true;

    if (
        !isset($rawData['phone_number']) ||
        !isset($rawData['message'])
    ) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "phone_number and message are required."
        ]);
        exit;
    }
    
    $smsStatus = sendSmsViaTextBee($rawData['phone_number'], $rawData['message'], $rawData['include_cemetery_name']);
    
    if (!$smsStatus['success']) {
        systemLog("Failed to send SMS to {$rawData['phone_number']}. Error: {$smsStatus['error']}. Content: {$rawData['message']}", $userData['user_id']);
        http_response_code(500);
        echo json_encode([
            "success" => false, 
            "message" => "Failed to send SMS: " . $smsStatus['error'] . ". Recheck your API KEYS and DEVICE ID"
        ]);
        exit; 
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "SMS sent successfully to " . $rawData['phone_number'] . " with the message content: " . $rawData['message']
    ]);
    systemLog("SMS sent to {$rawData['phone_number']}. Content: {$rawData['message']}", $userData['user_id']);
    exit;
}

Response::error("Method Not Allowed", 405);
?>