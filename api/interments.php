<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser(); 

// ---------------------------------------------------------
// 1. GATEKEEPER & PRIVACY CLEARANCE
// ---------------------------------------------------------
$role = $userData['role'] ?? null;

// Define the two types of allowed access
$isFullAccess = in_array($role, [ROLE_ADMIN, ROLE_OFFICE]);
$isReadOnly   = ($role === ROLE_GROUNDS && $method === 'GET');

// If the user has NEITHER of these permissions, kick them out
if (!$isFullAccess && !$isReadOnly) {
    Response::error("Forbidden. You do not have permission to perform this action.", 403);
}

// --- REST-ish ROUTING ---
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts); 

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

// ==========================================
// HELPERS: NESTED RESOLUTION
// ==========================================

$resolveContact = function($ownerData, $pdo, $userId) {
    if (!is_array($ownerData) || empty($ownerData)) return null;

    if (!empty($ownerData['contact_id'])) {
        $stmt = $pdo->prepare("SELECT contact_id FROM contacts WHERE contact_id = ? AND deleted_at IS NULL");
        $stmt->execute([$ownerData['contact_id']]);
        $id = $stmt->fetchColumn();
        if (!$id) throw new Exception("The provided contact_id does not exist.", 400);
        return $id;
    }

    $name = trim($ownerData['name'] ?? '');
    if (empty($name)) throw new Exception("Contact name is required.", 400);

    $address = trim($ownerData['address'] ?? '');
    $barangay = trim($ownerData['barangay'] ?? '');
    $phone = trim($ownerData['phone_number'] ?? '');
    $email = trim($ownerData['email_address'] ?? '');
    $remarks = trim($ownerData['remarks'] ?? '');

    $stmt = $pdo->prepare("
        SELECT contact_id FROM contacts 
        WHERE name = ? AND IFNULL(address, '') = ? AND IFNULL(barangay, '') = ? 
        AND IFNULL(phone_number, '') = ? AND IFNULL(email_address, '') = ? AND deleted_at IS NULL LIMIT 1
    ");
    $stmt->execute([$name, $address, $barangay, $phone, $email]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) return $existingId;

    $insertStmt = $pdo->prepare("INSERT INTO contacts (name, address, barangay, phone_number, email_address, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([$name, $address, $barangay, $phone, $email, $remarks, $userId]);
    return $pdo->lastInsertId();
};

$resolveDeceased = function($decData, $pdo, $userId) {
    if (!is_array($decData) || empty($decData)) throw new Exception("Deceased information is required.", 400);

    if (!empty($decData['deceased_id'])) {
        $stmt = $pdo->prepare("SELECT deceased_id FROM deceased WHERE deceased_id = ? AND deleted_at IS NULL");
        $stmt->execute([$decData['deceased_id']]);
        $id = $stmt->fetchColumn();
        if (!$id) throw new Exception("The provided deceased_id does not exist.", 400);
        return $id;
    }

    $name = trim($decData['name'] ?? '');
    if (empty($name)) throw new Exception("Deceased name is required.", 400);

    $sex = $decData['sex'] ?? 'Unknown';
    $dob = !empty($decData['date_of_birth']) ? $decData['date_of_birth'] : null;
    $dod = !empty($decData['date_of_death']) ? $decData['date_of_death'] : null;
    $cert = trim($decData['death_certificate'] ?? '');
    $address = trim($decData['last_known_address'] ?? '');
    $remarks = trim($decData['remarks'] ?? '');

    $stmt = $pdo->prepare("
        SELECT deceased_id FROM deceased 
        WHERE name = ? AND sex = ? AND IFNULL(date_of_birth, '0000-00-00') = IFNULL(?, '0000-00-00') AND deleted_at IS NULL LIMIT 1
    ");
    $stmt->execute([$name, $sex, $dob]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) return $existingId;

    $insertStmt = $pdo->prepare("INSERT INTO deceased (name, sex, date_of_birth, date_of_death, death_certificate, last_known_address, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([$name, $sex, $dob, $dod, $cert, $address, $remarks, $userId]);
    return $pdo->lastInsertId();
};


// ==========================================
// GET: RETRIEVE INTERMENTS
// ==========================================
if ($method === 'GET') {
    
    // Check for query parameter fallback
    $controlNumberQuery = $_GET['control_number'] ?? null;
    
    // SCENARIO A: Fetch single record by ID OR Control Number
    if (is_numeric($resourceId) || !empty($controlNumberQuery)) {
        
        $sql = "
            SELECT 
                i.*, 
                g.grave_code, g.remarks AS grave_remarks, 
                b.block_name, b.block_id, b.remarks AS block_remarks, b.owner_contact_id,
                d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted,
                c.name AS contact_name, c.phone_number AS contact_phone, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
            FROM interments i
            LEFT JOIN graves g ON i.grave_id = g.grave_id
            LEFT JOIN blocks b ON g.block_id = b.block_id
            LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
            LEFT JOIN contacts c ON i.contact_id = c.contact_id
            WHERE i.deleted_at IS NULL 
        ";
        
        $params = [];
        
        // Dynamically append the correct WHERE clause
        if (is_numeric($resourceId)) {
            $sql .= " AND i.interment_id = ? LIMIT 1";
            $params[] = $resourceId;
        } else {
            $sql .= " AND i.control_number = ? LIMIT 1";
            $params[] = $controlNumberQuery;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) Response::error("Interment record not found", 404);

        // --- MANUALLY MAP INTO NESTED JSON STRUCTURE ---
        $nestedInterment = [
            'interment_id'              => (int)$record['interment_id'],
            'control_number'            => $record['control_number'],
            'assistance_type'           => $record['assistance_type'],
            'assistance_other_remarks'  => $record['assistance_other_remarks'],
            'burial_permit_number'      => $record['burial_permit_number'],
            'burial_permit_date'        => $record['burial_permit_date'],
            'transfer_permit_number'    => $record['transfer_permit_number'],
            'transfer_permit_issued_by' => $record['transfer_permit_issued_by'],
            'transfer_permit_date'      => $record['transfer_permit_date'],
            'exhumation_permit_number'  => $record['exhumation_permit_number'],
            'exhumation_permit_date'    => $record['exhumation_permit_date'],
            'date_buried'               => $record['date_buried'],
            'clearance_date'            => $record['clearance_date'],
            'lease_expiration_date'     => $record['lease_expiration_date'],
            'status'                    => $record['status'],
            'remarks'                   => $record['remarks'],
            
            'grave' => [
                'grave_id'   => (int)$record['grave_id'],
                'grave_code' => $record['grave_code'],
                'remarks'    => $record['grave_remarks']
            ],
            
            'block' => [
                'block_id'         => (int)$record['block_id'],
                'block_name'       => $record['block_name'],
                'owner_contact_id' => $record['owner_contact_id'] ? (int)$record['owner_contact_id'] : null,
                'remarks'          => $record['block_remarks']
            ],
            
            'deceased' => [
                'deceased_id' => (int)$record['deceased_id'],
                'name'        => $record['deceased_name'],
                'sex'         => $record['deceased_sex'],
                'remarks'     => $record['deceased_remarks'],
                'is_archived' => $record['deceased_deleted'] !== null
            ],
            
            'contact' => [
                'contact_id'   => (int)$record['contact_id'],
                'name'         => $record['contact_name'],
                'phone_number' => $record['contact_phone'],
                'remarks'      => $record['contact_remarks'],
                'is_archived'  => $record['contact_deleted'] !== null
            ]
        ];

        Response::success("Record retrieved", ["interment" => $nestedInterment]);
    }
    
   // SCENARIO B: Overview query (Paginated & Error-Proofed)
    
    // 1. Sanitize Limit (Hard cap at 500 to prevent memory crashes)
    $rawLimit = $_GET['limit'] ?? 100;
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? (int)$rawLimit : 100;
    $limit = min($limit, 500); 

    // 2. Count Total Records First
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM interments WHERE deleted_at IS NULL");
    $countStmt->execute();
    $totalRecords = (int)$countStmt->fetchColumn();
    
    // 3. Calculate Total Pages Safely (Avoid division by zero)
    $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;

    // 4. Sanitize Page (Handle extreme numbers, strings, and out-of-bounds)
    $rawPage = $_GET['page'] ?? 1;
    
    if (!is_numeric($rawPage) || $rawPage < 1) {
        $page = 1; // Fallback to 1 if they type letters or negatives
    } else {
        // If they type a massive number, PHP casts it to a max integer.
        // We then use min() to strictly cap it at the actual total pages.
        $page = min((int)$rawPage, $totalPages); 
    }

    // 5. Calculate Offset
    $offset = ($page - 1) * $limit;

    // 6. Fetch the Paginated Data
    $sql = "
        SELECT 
            i.interment_id, i.control_number, i.date_buried, i.lease_expiration_date, i.status, i.remarks AS interment_remarks,
            g.grave_code, g.remarks AS grave_remarks, g.grave_id, 
            b.block_name, b.block_id, b.remarks AS block_remarks, 
            d.name AS deceased_name, d.deceased_id, d.last_known_address, d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted,
            c.name AS contact_name, c.phone_number AS contact_phone_number, c.email_address AS contact_email_address, c.contact_id, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
        FROM interments i
        LEFT JOIN graves g ON i.grave_id = g.grave_id
        LEFT JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        WHERE i.deleted_at IS NULL
        ORDER BY i.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    // Use bindValue so PDO treats these strictly as integers, not strings
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $rawInterments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // --- MANUALLY MAP INTO NESTED JSON ARRAYS ---
    $formattedInterments = [];
    foreach ($rawInterments as $row) {
        $formattedInterments[] = [
            'interment_id'          => (int)$row['interment_id'],
            'control_number'        => $row['control_number'],
            'date_buried'           => $row['date_buried'],
            'lease_expiration_date' => $row['lease_expiration_date'],
            'status'                => $row['status'],
            'remarks'               => $row['interment_remarks'],
            
            'grave' => [
                'grave_id'   => (int)$row['grave_id'],
                'grave_code' => $row['grave_code'],
                'remarks'    => $row['grave_remarks']
            ],
            
            'block' => [
                'block_id'   => (int)$row['block_id'],
                'block_name' => $row['block_name'],
                'remarks'    => $row['block_remarks']
            ],
            
            'deceased' => [
                'deceased_id'        => (int)$row['deceased_id'],
                'name'               => $row['deceased_name'],
                'last_known_address' => $row['last_known_address'],
                'remarks'            => $row['deceased_remarks'],
                'is_archived'        => $row['deceased_deleted'] !== null
            ],
            
            'contact' => [
                'contact_id'    => (int)$row['contact_id'],
                'name'          => $row['contact_name'],
                'phone_number'  => $row['contact_phone_number'],
                'email_address' => $row['contact_email_address'],
                'remarks'       => $row['contact_remarks'],
                'is_archived'   => $row['contact_deleted'] !== null
            ]
        ];
    }

    // --- RETURN DATA & PAGINATION META-DATA ---
    $paginationData = [
        'current_page'  => $page,
        'per_page'      => $limit,
        'total_records' => $totalRecords,
        'total_pages'   => $totalPages
    ];

    Response::success("Interments retrieved", [
        "pagination" => $paginationData,
        "interments" => $formattedInterments
    ]);
}

// ==========================================
// POST: CREATE NEW INTERMENT
// ==========================================
if ($method === 'POST') {
    
    $controlNumber = trim($rawData['control_number'] ?? '');
    $graveCode = trim($rawData['grave_code'] ?? '');
    $graveId = !empty($rawData['grave_id']) ? (int)$rawData['grave_id'] : null;
    $assistanceType = $rawData['assistance_type'] ?? 'Burial';

    // Prioritize mapping the grave_code to a grave_id if the frontend passed a code instead
    if (!empty($graveCode)) {
        $stmtGraveCode = $pdo->prepare("SELECT grave_id FROM graves WHERE grave_code = ? AND deleted_at IS NULL");
        $stmtGraveCode->execute([$graveCode]);
        $resolvedGraveId = $stmtGraveCode->fetchColumn();
        
        if ($resolvedGraveId) {
            $graveId = $resolvedGraveId; // Overwrite with the resolved ID
        } else {
            Response::error("The provided grave_code does not exist.", 404);
        }
    }

    // Now validate that we have exactly what we need
    if (empty($controlNumber) || !$graveId) {
        Response::error("Control number and a valid grave ID (or grave_code) are required.", 400);
    }

    try {
        $pdo->beginTransaction();

        // 1. Resolve Nested Entities
        $deceasedId = $resolveDeceased($rawData['deceased'] ?? [], $pdo, $userData['user_id']);
        $contactId = !empty($rawData['contact']) ? $resolveContact($rawData['contact'], $pdo, $userData['user_id']) : null;

        // 2. Validate Grave Status (Must be Vacant or Reserved by this family)
        $graveStmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
        $graveStmt->execute([$graveId]);
        $graveStatus = $graveStmt->fetchColumn();

        if (!$graveStatus) throw new Exception("Selected grave does not exist.", 404);
        if ($graveStatus === 'Occupied') throw new Exception("Conflict: The selected grave is already occupied.", 409);

        // 3. Insert Interment
        $stmt = $pdo->prepare("
            INSERT INTO interments (
                control_number, deceased_id, grave_id, contact_id, assistance_type, assistance_other_remarks,
                burial_permit_number, burial_permit_date, transfer_permit_number, transfer_permit_issued_by, transfer_permit_date,
                exhumation_permit_number, exhumation_permit_date, date_buried, clearance_date, lease_expiration_date, remarks, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $controlNumber, $deceasedId, $graveId, $contactId, $assistanceType, 
            $rawData['assistance_other_remarks'] ?? null,
            $rawData['burial_permit_number'] ?? null, $rawData['burial_permit_date'] ?? null,
            $rawData['transfer_permit_number'] ?? null, $rawData['transfer_permit_issued_by'] ?? null, $rawData['transfer_permit_date'] ?? null,
            $rawData['exhumation_permit_number'] ?? null, $rawData['exhumation_permit_date'] ?? null,
            $rawData['date_buried'] ?? null, $rawData['clearance_date'] ?? null, $rawData['lease_expiration_date'] ?? null,
            $rawData['remarks'] ?? null, $userData['user_id']
        ]);
        
        $newId = $pdo->lastInsertId();

        // 4. Update Grave to Occupied
        $updateGrave = $pdo->prepare("UPDATE graves SET status = 'Occupied', updated_by = ?, updated_at = NOW() WHERE grave_id = ?");
        $updateGrave->execute([$userData['user_id'], $graveId]);

        $pdo->commit();
        systemLog($userData['name'] . " created interment: " . $controlNumber, $userData['user_id']);
        Response::success("Interment processed and grave occupied.", ["interment_id" => $newId], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if ($code == 400 || $code == 404 || $code == 409) Response::error($e->getMessage(), $code);
        Response::error("Database error or missing data.", 500);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) Response::error("Conflict: Control number already exists.", 409);
        Response::error("Database error while creating interment.", 500);
    }
}

// ==========================================
// DELETE: REMOVE (Soft Delete & Free Grave)
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Interment ID required", 400);

    try {
        $pdo->beginTransaction();

        // Find the linked grave first
        $stmt = $pdo->prepare("SELECT grave_id FROM interments WHERE interment_id = ? AND deleted_at IS NULL");
        $stmt->execute([$resourceId]);
        $graveId = $stmt->fetchColumn();

        if (!$graveId) throw new Exception("Interment not found.", 404);

        // Delete Interment
        $delStmt = $pdo->prepare("UPDATE interments SET deleted_at = NOW(), updated_by = ? WHERE interment_id = ?");
        $delStmt->execute([$userData['user_id'], $resourceId]);

        // Free the Grave back to Vacant
        $freeGrave = $pdo->prepare("UPDATE graves SET status = 'Vacant', updated_by = ?, updated_at = NOW() WHERE grave_id = ?");
        $freeGrave->execute([$userData['user_id'], $graveId]);

        $pdo->commit();
        systemLog($userData['name'] . " deleted interment ID: " . $resourceId . " and freed grave.", $userData['user_id']);
        Response::success("Interment deleted and grave status reverted to Vacant.");

    } catch (Exception $e) {
        $pdo->rollBack();
        if ($e->getCode() == 404) Response::error($e->getMessage(), 404);
        Response::error("Database error.", 500);
    }
}

Response::error("Method not allowed", 405);
?>