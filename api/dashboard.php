<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'responses.php';
require_once 'checkuser.php';
require_once 'database.php';

$userData = checkuser();
// ==========================================
// --- ADMIN VIEW LOGS ENDPOINT ---
// ==========================================

// Define the method and action specifically for this file
$method = $_SERVER['REQUEST_METHOD'] ?? null;

if ($method === 'GET' ) {
    // This will exit with an error if not logged in
    $userData = checkuser();
    
    if ($userData['role'] !== 'Administrator' || $userData['status'] !== 'Verified') {
        Response::error("Unauthorized access. Administrator role required. For staff, it is a work in progress", 403);
    }

  $sql = "SELECT COUNT(*) FROM users WHERE status != :status";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['status' => 'Verified']);

// fetchColumn() returns exactly one value (the number of rows)
    $unverifiedCount = $stmt->fetchColumn();

// Output the number
    Response::success("Unverified users found so far in the dashboard okay",  ['unverifiedCount' => $unverifiedCount]);   
}

Response::error("Method not allowed", 405); // Only GET is allowed here



?>