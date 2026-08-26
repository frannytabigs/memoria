<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser(); 

// ---------------------------------------------------------
// 1. GATEKEEPER & PRIVACY CLEARANCE
// ---------------------------------------------------------
$role = $userData['role'];

// Define the two types of allowed access
$isFullAccess = in_array($role, [ROLE_ADMIN, ROLE_OFFICE]);
$isReadOnly   = ($role === ROLE_GROUNDS && $method === 'GET');

// If the user has NEITHER of these permissions, kick them out
if (!$isFullAccess && !$isReadOnly) {
    Response::error("Forbidden. You do not have permission to perform this action.", 403);
}

// --- REST ROUTING: PARSE THE URI ---
// Check our custom .htaccess parameter first, then fallback to standard PATH_INFO
$pathInfo = $_GET['path_info'] ?? $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts);

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

// ==========================================
// GET: RETRIEVE DECEASED RECORDS
// ==========================================
if ($method === 'GET') {
    
    // SCENARIO A: Fetch a Single Record
    if (is_numeric($resourceId)) {
        $stmt = $pdo->prepare("SELECT * FROM deceased WHERE deceased_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$resourceId]);
        $deceased = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deceased) Response::error("Record not found", 404);
        
        Response::success("Record retrieved successfully", $deceased);
    }
    
    // SCENARIO B: Fetch all records (Paginated & Search-Aware)
    $searchTerm = $_GET['search'] ?? '';
    
    // 1. Sanitize Limit (Hard cap at 500)
    $rawLimit = $_GET['limit'] ?? 100;
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? (int)$rawLimit : 100;
    $limit = min($limit, 500); 

    // 2. Build the WHERE clause dynamically for both Count and Data queries
    $whereClause = "WHERE deleted_at IS NULL";
    $searchParams = [];
    
    if (!empty($searchTerm)) {
        $whereClause .= " AND name LIKE :search";
        $searchParams[':search'] = '%' . trim($searchTerm) . '%';
    }

    // 3. Count Total Records First (Including search filters!)
    $countSql = "SELECT COUNT(*) FROM deceased " . $whereClause;
    $countStmt = $pdo->prepare($countSql);
    // Bind search parameters if they exist
    foreach ($searchParams as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalRecords = (int)$countStmt->fetchColumn();
    
    // 4. Calculate Total Pages Safely
    $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;

    // 5. Sanitize Page (Handle extreme numbers and out-of-bounds)
    $rawPage = $_GET['page'] ?? 1;
    if (!is_numeric($rawPage) || $rawPage < 1) {
        $page = 1; 
    } else {
        $page = min((int)$rawPage, $totalPages); 
    }

    // 6. Calculate Offset
    $offset = ($page - 1) * $limit;

    // 7. Fetch the Paginated Data
    $sql = "SELECT deceased_id, name, sex, date_of_birth, date_of_death, death_certificate, last_known_address, remarks 
            FROM deceased " . $whereClause . " 
            ORDER BY name ASC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind search parameters again for the main query
    foreach ($searchParams as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    
    // Bind limit and offset securely
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Return Data & Pagination Meta-data
    $paginationData = [
        'current_page'  => $page,
        'per_page'      => $limit,
        'total_records' => $totalRecords,
        'total_pages'   => $totalPages
    ];

    if ($records === []){
        Response::error("No records found matching the search criteria (" . $searchTerm . ")", 404);
    }
    if (!empty($searchTerm)){
        Response::success("Records retrieved successfully", [
            "search_term" => $searchTerm,
            "pagination" => $paginationData,
            "deceased"   => $records
        ]);
    }

    Response::success("Records retrieved successfully", [
        "pagination" => $paginationData,
        "deceased"   => $records
    ]);
}

// ==========================================
// POST: CREATE NEW RECORD
// ==========================================
if ($method === 'POST') {
    
    $name = trim($rawData['name'] ?? '');
    if (empty($name)) {
        Response::error("The name of the deceased is required.", 400);
    }

    $sex = $rawData['sex'] ?? 'Unknown';
    $dob = !empty($rawData['date_of_birth']) ? $rawData['date_of_birth'] : null;
    $dod = !empty($rawData['date_of_death']) ? $rawData['date_of_death'] : null;
    $cert = trim($rawData['death_certificate'] ?? '');
    $address = trim($rawData['last_known_address'] ?? '');
    $remarks = trim($rawData['remarks'] ?? '');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO deceased (name, sex, date_of_birth, date_of_death, death_certificate, last_known_address, remarks, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $sex, $dob, $dod, $cert, $address, $remarks, $userData['user_id']]);
        
        $newId = $pdo->lastInsertId();
        systemLog($userData['name'] . " added deceased record: " . $name, $userData['user_id']);
        Response::success("Record created successfully", ["deceased_id" => $newId], 201);

    } catch (PDOException $e) {
        Response::error("Database error while creating record.", 500);
    }
}

// ==========================================
// PUT: EDIT RECORD
// ==========================================
if ($method === 'PUT') {
    if (!is_numeric($resourceId)) Response::error("Deceased ID required", 400);

    $stmt = $pdo->prepare("SELECT * FROM deceased WHERE deceased_id = ? AND deleted_at IS NULL");
    $stmt->execute([$resourceId]);
    $oldRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldRecord) Response::error("Record not found", 404);

    $newName = trim($rawData['name'] ?? $oldRecord['name']);
    $newSex = $rawData['sex'] ?? $oldRecord['sex'];
    
    // Check if dates exist in payload, otherwise keep old. If explicitly empty string, set to null.
    $newDob = array_key_exists('date_of_birth', $rawData) ? ($rawData['date_of_birth'] ?: null) : $oldRecord['date_of_birth'];
    $newDod = array_key_exists('date_of_death', $rawData) ? ($rawData['date_of_death'] ?: null) : $oldRecord['date_of_death'];
    
    $newCert = array_key_exists('death_certificate', $rawData) ? trim($rawData['death_certificate']) : $oldRecord['death_certificate'];
    $newAddress = array_key_exists('last_known_address', $rawData) ? trim($rawData['last_known_address']) : $oldRecord['last_known_address'];
    $newRemarks = array_key_exists('remarks', $rawData) ? trim($rawData['remarks']) : $oldRecord['remarks'];

    try {
        $updateStmt = $pdo->prepare("
            UPDATE deceased 
            SET name = ?, sex = ?, date_of_birth = ?, date_of_death = ?, death_certificate = ?, last_known_address = ?, remarks = ?, updated_by = ?, updated_at = NOW() 
            WHERE deceased_id = ?
        ");
        $updateStmt->execute([$newName, $newSex, $newDob, $newDod, $newCert, $newAddress, $newRemarks, $userData['user_id'], $resourceId]);

        systemLog($userData['name'] . " updated deceased ID: " . $resourceId, $userData['user_id']);
        Response::success("Record updated successfully.");

    } catch (PDOException $e) {
        Response::error("Database error while updating record.", 500);
    }
}

// ==========================================
// DELETE: REMOVE RECORD (Soft Delete)
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Deceased ID required", 400);

    // Cross-check: Ensure this person isn't tied to an active interment or reservation
    $checkStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM interments WHERE deceased_id = ? AND deleted_at IS NULL AND status != 'Exhumed') +
            (SELECT COUNT(*) FROM reservations WHERE deceased_id = ? AND deleted_at IS NULL AND status = 'Active')
    ");
    $checkStmt->execute([$resourceId, $resourceId]);
    
    if ($checkStmt->fetchColumn() > 0) {
        Response::error("Conflict: Cannot delete this record because it is tied to an active interment or reservation.", 409);
    }

    try {
        $stmt = $pdo->prepare("UPDATE deceased SET deleted_at = NOW(), updated_by = ? WHERE deceased_id = ?");
        $stmt->execute([$userData['user_id'], $resourceId]);

        systemLog($userData['name'] . " deleted deceased ID: " . $resourceId, $userData['user_id']);
        Response::success("Record successfully deleted.");

    } catch (PDOException $e) {
        Response::error("Database error while deleting record.", 500);
    }
}

Response::error("Method not allowed", 405);
?>