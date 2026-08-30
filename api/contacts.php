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
// GET: RETRIEVE CONTACT RECORDS
// ==========================================
if ($method === 'GET') {
    
    // SCENARIO A: Fetch a Single Record
    if (is_numeric($resourceId)) {
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE contact_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$resourceId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) Response::error("Contact not found", 404);
        
        Response::success("Contact retrieved successfully", $contact);
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
    $countSql = "SELECT COUNT(*) FROM contacts " . $whereClause;
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
    $sql = "SELECT contact_id, name, address, barangay, phone_number, email_address, remarks 
            FROM contacts " . $whereClause . " 
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

    // An empty page is a valid answer — "nobody matched that search" is not a
    // failed request. The old 404 made every no-hit lookup print an error banner.
    if (!empty($searchTerm)){
        Response::success("Records retrieved successfully", [
            "search_term" => $searchTerm,
            "pagination" => $paginationData,
            "contacts"   => $records
        ]);
    }

    Response::success("Contacts retrieved successfully", [
        "pagination" => $paginationData,
        "contacts"   => $records
    ]);
}

// ==========================================
// POST: CREATE NEW RECORD
// ==========================================
if ($method === 'POST') {
    
    $name = trim($rawData['name'] ?? '');
    if (empty($name)) {
        Response::error("The contact name is required.", 400);
    }

    $address = trim($rawData['address'] ?? '');
    $barangay = trim($rawData['barangay'] ?? '');
    $phone = trim($rawData['phone_number'] ?? '');
    $email = trim($rawData['email_address'] ?? '');
    $remarks = trim($rawData['remarks'] ?? '');

    // Normalize before storing. api/sendsms.php dials whatever is in this column,
    // and reserve/interments already run every number through this helper — a raw
    // "0917 123 4567" saved here would not match a "+639171234567" saved there,
    // so the same person could end up as two contacts.
    if ($phone !== '') {
        $phone = formatPhNumber($phone);
        if (!$phone) {
            Response::error("Invalid Philippines phone number format.", 400);
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO contacts (name, address, barangay, phone_number, email_address, remarks, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $address, $barangay, $phone, $email, $remarks, $userData['user_id']]);
        
        $newId = $pdo->lastInsertId();
        systemLog($userData['name'] . " added contact record: " . $name, $userData['user_id']);
        Response::success("Contact created successfully", ["contact_id" => $newId], 201);

    } catch (PDOException $e) {
        Response::error("Database error while creating contact.", 500);
    }
}

// ==========================================
// PUT: EDIT RECORD
// ==========================================
if ($method === 'PUT') {
    if (!is_numeric($resourceId)) Response::error("Contact ID required", 400);

    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE contact_id = ? AND deleted_at IS NULL");
    $stmt->execute([$resourceId]);
    $oldRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldRecord) Response::error("Contact not found", 404);

    $newName = trim($rawData['name'] ?? $oldRecord['name']);
    if (empty($newName)) {
        Response::error("The contact name cannot be empty.", 400);
    }

    $newAddress = array_key_exists('address', $rawData) ? trim($rawData['address']) : $oldRecord['address'];
    $newBarangay = array_key_exists('barangay', $rawData) ? trim($rawData['barangay']) : $oldRecord['barangay'];
    $newPhone = array_key_exists('phone_number', $rawData) ? trim($rawData['phone_number']) : $oldRecord['phone_number'];
    if (array_key_exists('phone_number', $rawData) && $newPhone !== '') {
        $newPhone = formatPhNumber($newPhone);
        if (!$newPhone) {
            Response::error("Invalid Philippines phone number format.", 400);
        }
    }
    $newEmail = array_key_exists('email_address', $rawData) ? trim($rawData['email_address']) : $oldRecord['email_address'];
    $newRemarks = array_key_exists('remarks', $rawData) ? trim($rawData['remarks']) : $oldRecord['remarks'];

    try {
        $updateStmt = $pdo->prepare("
            UPDATE contacts 
            SET name = ?, address = ?, barangay = ?, phone_number = ?, email_address = ?, remarks = ?, updated_by = ?, updated_at = NOW() 
            WHERE contact_id = ?
        ");
        $updateStmt->execute([$newName, $newAddress, $newBarangay, $newPhone, $newEmail, $newRemarks, $userData['user_id'], $resourceId]);

        systemLog($userData['name'] . " updated contact ID: " . $resourceId, $userData['user_id']);
        Response::success("Contact updated successfully.");

    } catch (PDOException $e) {
        Response::error("Database error while updating contact.", 500);
    }
}

// ==========================================
// DELETE: REMOVE RECORD (Soft Delete)
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Contact ID required", 400);

    // Cross-check: this person must not own a block or be the contact person on
    // any interment. `reservations` no longer exists — grave_transitions replaced
    // it, and a staging reaches its contact through interments.contact_id, which
    // the second subquery already covers.
    $checkStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM blocks WHERE owner_contact_id = ? AND deleted_at IS NULL) +
            (SELECT COUNT(*) FROM interments WHERE contact_id = ? AND deleted_at IS NULL)
    ");
    $checkStmt->execute([$resourceId, $resourceId]);

    if ($checkStmt->fetchColumn() > 0) {
        Response::error("Conflict: Cannot delete this contact because they own a block or are linked to an interment record.", 409);
    }

    try {
        $stmt = $pdo->prepare("UPDATE contacts SET deleted_at = NOW(), updated_by = ? WHERE contact_id = ?");
        $stmt->execute([$userData['user_id'], $resourceId]);

        systemLog($userData['name'] . " deleted contact ID: " . $resourceId, $userData['user_id']);
        Response::success("Contact successfully deleted.");

    } catch (PDOException $e) {
        Response::error("Database error while deleting contact.", 500);
    }
}

Response::error("Method not allowed", 405);
?>