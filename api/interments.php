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
    $searchTerm = trim((string)($_GET['search'] ?? ''));
    $searchTerm = substr($searchTerm, 0, 100);
    
    // SCENARIO A: Fetch single record by ID OR Control Number
    if (is_numeric($resourceId) || !empty($controlNumberQuery)) {
        
        $sql = "
            SELECT 
                i.*, 
                g.grave_code, g.remarks AS grave_remarks, 
                b.block_name, b.block_id, b.remarks AS block_remarks, b.owner_contact_id,
                d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death,
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
                'death_certificate' => $record['death_certificate'],
                'date_of_death'      => $record['date_of_death'],
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

        Response::success("Record retrieved", $nestedInterment);
    }
    
   // SCENARIO B: Overview query (Paginated & Error-Proofed)
    
    $searchWhere = '';
    $searchParams = [];

    if ($searchTerm !== '') {
        $searchWhere = "
            AND (
                CAST(i.interment_id AS CHAR) LIKE ? OR
                i.control_number LIKE ? OR
                i.assistance_type LIKE ? OR
                i.burial_permit_number LIKE ? OR
                CAST(i.burial_permit_date AS CHAR) LIKE ? OR
                i.transfer_permit_number LIKE ? OR
                i.transfer_permit_issued_by LIKE ? OR
                CAST(i.transfer_permit_date AS CHAR) LIKE ? OR
                i.exhumation_permit_number LIKE ? OR
                CAST(i.exhumation_permit_date AS CHAR) LIKE ? OR
                CAST(i.date_buried AS CHAR) LIKE ? OR
                CAST(i.clearance_date AS CHAR) LIKE ? OR
                CAST(i.lease_expiration_date AS CHAR) LIKE ? OR
                i.status LIKE ? OR
                i.remarks LIKE ? OR
                CAST(g.grave_id AS CHAR) LIKE ? OR
                g.grave_code LIKE ? OR
                g.status LIKE ? OR
                g.remarks LIKE ? OR
                CAST(b.block_id AS CHAR) LIKE ? OR
                b.block_name LIKE ? OR
                b.block_type LIKE ? OR
                b.remarks LIKE ? OR
                CAST(d.deceased_id AS CHAR) LIKE ? OR
                d.name LIKE ? OR
                d.sex LIKE ? OR
                CAST(d.date_of_birth AS CHAR) LIKE ? OR
                CAST(d.date_of_death AS CHAR) LIKE ? OR
                d.death_certificate LIKE ? OR
                d.last_known_address LIKE ? OR
                d.remarks LIKE ? OR
                CAST(c.contact_id AS CHAR) LIKE ? OR
                c.name LIKE ? OR
                c.address LIKE ? OR
                c.barangay LIKE ? OR
                c.phone_number LIKE ? OR
                c.email_address LIKE ? OR
                c.remarks LIKE ?
            )
        ";
        $searchParams = array_fill(0, 38, '%' . $searchTerm . '%');
    }

    // 1. Sanitize Limit (Hard cap at 500, or 45 for search results)
    $rawLimit = $_GET['limit'] ?? 100;
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? (int)$rawLimit : 100;
    $limit = min($limit, $searchTerm !== '' ? 45 : 500);

    // 2. Count Total Records First
    $countSql = "
        SELECT COUNT(*)
        FROM interments i
        LEFT JOIN graves g ON i.grave_id = g.grave_id
        LEFT JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        WHERE i.deleted_at IS NULL
        $searchWhere
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($searchParams);
    $totalRecords = (int)$countStmt->fetchColumn();

    if ($searchTerm !== '') {
        $totalRecords = min($totalRecords, 45);
    }
    
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
            i.interment_id, i.control_number, i.assistance_type,
            i.burial_permit_number, i.burial_permit_date,
            i.transfer_permit_number, i.transfer_permit_issued_by, i.transfer_permit_date,
            i.exhumation_permit_number, i.exhumation_permit_date,
            i.date_buried, i.clearance_date, i.lease_expiration_date, i.status, i.remarks,
            g.grave_code, g.grave_id, g.remarks AS grave_remarks,
            b.block_name, b.block_id, b.owner_contact_id, b.remarks AS block_remarks,
            d.name AS deceased_name, d.deceased_id, d.sex AS deceased_sex,
            d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death,
            c.name AS contact_name, c.phone_number AS contact_phone, c.contact_id,
            c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
        FROM interments i
        LEFT JOIN graves g ON i.grave_id = g.grave_id
        LEFT JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        WHERE i.deleted_at IS NULL
        $searchWhere
        ORDER BY i.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    // Use bindValue so PDO treats these strictly as integers, not strings
    $stmt = $pdo->prepare($sql);
    $bindIndex = 1;
    foreach ($searchParams as $searchParam) {
        $stmt->bindValue($bindIndex++, $searchParam, PDO::PARAM_STR);
    }
    $stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $rawInterments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // --- MANUALLY MAP INTO NESTED JSON ARRAYS ---
    $formattedInterments = [];
    foreach ($rawInterments as $row) {
        $formattedInterments[] = [
            'interment_id'          => (int)$row['interment_id'],
            'control_number'        => $row['control_number'],
            'assistance_type'       => $row['assistance_type'],
            'burial_permit_number'  => $row['burial_permit_number'],
            'burial_permit_date'    => $row['burial_permit_date'],
            'transfer_permit_number'    => $row['transfer_permit_number'],
            'transfer_permit_issued_by' => $row['transfer_permit_issued_by'],
            'transfer_permit_date'      => $row['transfer_permit_date'],
            'exhumation_permit_number'  => $row['exhumation_permit_number'],
            'exhumation_permit_date'    => $row['exhumation_permit_date'],
            'date_buried'           => $row['date_buried'],
            'clearance_date'        => $row['clearance_date'],
            'lease_expiration_date' => $row['lease_expiration_date'],
            'status'                => $row['status'],
            'remarks'               => $row['remarks'],
            
            'grave' => [
                'grave_id'   => (int)$row['grave_id'],
                'grave_code' => $row['grave_code'],
                'remarks'    => $row['grave_remarks']
            ],
            
            'block' => [
                'block_id'         => (int)$row['block_id'],
                'block_name'       => $row['block_name'],
                'owner_contact_id' => $row['owner_contact_id'] ? (int)$row['owner_contact_id'] : null,
                'remarks'          => $row['block_remarks']
            ],
            
            'deceased' => [
                'deceased_id'       => (int)$row['deceased_id'],
                'name'              => $row['deceased_name'],
                'sex'               => $row['deceased_sex'],
                'remarks'           => $row['deceased_remarks'],
                'death_certificate' => $row['death_certificate'],
                'date_of_death'     => $row['date_of_death'],
                'is_archived'       => $row['deceased_deleted'] !== null
            ],
            
            'contact' => [
                'contact_id'   => (int)$row['contact_id'],
                'name'         => $row['contact_name'],
                'phone_number' => $row['contact_phone'],
                'remarks'      => $row['contact_remarks'],
                'is_archived'  => $row['contact_deleted'] !== null
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
    
    if ($formattedInterments === []){
        Response::error("No records found matching the search criteria (" . $searchTerm . ")", 404);
    }
    if (!empty($searchTerm)){
        Response::success("Interments retrieved", [
        "search_term" => $searchTerm,
        "pagination" => $paginationData,
        "interments" => $formattedInterments
    ]);
    }
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

        // 2. Validate Grave Status (Smart Co-Interment Logic)
        $graveStmt = $pdo->prepare("
            SELECT g.status, b.block_type 
            FROM graves g
            LEFT JOIN blocks b ON g.block_id = b.block_id
            WHERE g.grave_id = ? AND g.deleted_at IS NULL FOR UPDATE
        ");
        $graveStmt->execute([$graveId]);
        $graveInfo = $graveStmt->fetch(PDO::FETCH_ASSOC);

        if (!$graveInfo) throw new Exception("Selected grave does not exist.", 404);

        // If the grave is already occupied, we check if multiple occupants are allowed
        if ($graveInfo['status'] === 'Occupied') {
            
            $isBoneChamber = in_array($graveInfo['block_type'], ['Bone Chamber', 'Mass Grave', 'Cluster']);
            $isTransfer = ($assistanceType === 'Transfer' || $assistanceType === 'Other');
            $isManualCoInterment = filter_var($rawData['is_co_interment'] ?? false, FILTER_VALIDATE_BOOLEAN);

            // If none of the co-interment conditions are met, block it!
            if (!$isBoneChamber && (!$isTransfer && !$isManualCoInterment)) {
                throw new Exception("Conflict: This grave is already occupied by an active interment. To add ashes or transferred bones here, please enable Co-Interment.", 409);
            }
        }
        
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

// ==========================================
// PUT: UPDATE INTERMENT (Fix Typos/Edit)
// ==========================================
if ($method === 'PUT') {
    
    // Only Admins and Office Staff can edit the master ledger
    if (!$isFullAccess) {
        Response::error("Forbidden. You do not have permission to edit records.", 403);
    }
    
    if (!is_numeric($resourceId)) {
        Response::error("Interment ID is required to perform an update.", 400);
    }

    try {
        $pdo->beginTransaction();

        // 1. Verify the record exists and grab its linked IDs
        $stmtCheck = $pdo->prepare("SELECT deceased_id, contact_id FROM interments WHERE interment_id = ? AND deleted_at IS NULL FOR UPDATE");
        $stmtCheck->execute([$resourceId]);
        $currentRecord = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$currentRecord) throw new Exception("Interment record not found.", 404);

        // 2. Update the main Interment table
        $updateInterment = $pdo->prepare("
            UPDATE interments SET 
                control_number = COALESCE(?, control_number),
                assistance_type = COALESCE(?, assistance_type),
                assistance_other_remarks = COALESCE(?, assistance_other_remarks),
                burial_permit_number = COALESCE(?, burial_permit_number),
                burial_permit_date = COALESCE(?, burial_permit_date),
                transfer_permit_number = COALESCE(?, transfer_permit_number),
                transfer_permit_issued_by = COALESCE(?, transfer_permit_issued_by),
                transfer_permit_date = COALESCE(?, transfer_permit_date),
                exhumation_permit_number = COALESCE(?, exhumation_permit_number),
                exhumation_permit_date = COALESCE(?, exhumation_permit_date),
                date_buried = COALESCE(?, date_buried),
                clearance_date = COALESCE(?, clearance_date),
                lease_expiration_date = COALESCE(?, lease_expiration_date),
                status = COALESCE(?, status),
                remarks = COALESCE(?, remarks),
                updated_by = ?,
                updated_at = NOW()
            WHERE interment_id = ?
        ");
        
        $updateInterment->execute([
            $rawData['control_number'] ?? null,
            $rawData['assistance_type'] ?? null,
            $rawData['assistance_other_remarks'] ?? null,
            $rawData['burial_permit_number'] ?? null,
            $rawData['burial_permit_date'] ?? null,
            $rawData['transfer_permit_number'] ?? null,
            $rawData['transfer_permit_issued_by'] ?? null,
            $rawData['transfer_permit_date'] ?? null,
            $rawData['exhumation_permit_number'] ?? null,
            $rawData['exhumation_permit_date'] ?? null,
            $rawData['date_buried'] ?? null,
            $rawData['clearance_date'] ?? null,
            $rawData['lease_expiration_date'] ?? null,
            $rawData['status'] ?? null,
            $rawData['remarks'] ?? null,
            $userData['user_id'],
            $resourceId
        ]);

        // 3. Update the nested Deceased data (if provided)
        if (isset($rawData['deceased']) && is_array($rawData['deceased'])) {
            $dec = $rawData['deceased'];
            $updateDec = $pdo->prepare("
                UPDATE deceased SET 
                    name = COALESCE(?, name),
                    sex = COALESCE(?, sex),
                    date_of_birth = COALESCE(?, date_of_birth),
                    date_of_death = COALESCE(?, date_of_death),
                    death_certificate = COALESCE(?, death_certificate),
                    remarks = COALESCE(?, remarks),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE deceased_id = ?
            ");
            $updateDec->execute([
                $dec['name'] ?? null,
                $dec['sex'] ?? null,
                $dec['date_of_birth'] ?? null,
                $dec['date_of_death'] ?? null,
                $dec['death_certificate'] ?? null,
                $dec['remarks'] ?? null,
                $userData['user_id'],
                $currentRecord['deceased_id']
            ]);
        }

        // 4. Update the nested Contact data (if provided)
        if (isset($rawData['contact']) && is_array($rawData['contact']) && $currentRecord['contact_id']) {
            $con = $rawData['contact'];
            $updateCon = $pdo->prepare("
                UPDATE contacts SET 
                    name = COALESCE(?, name),
                    phone_number = COALESCE(?, phone_number),
                    remarks = COALESCE(?, remarks),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE contact_id = ?
            ");
            $updateCon->execute([
                $con['name'] ?? null,
                $con['phone_number'] ?? null,
                $con['remarks'] ?? null,
                $userData['user_id'],
                $currentRecord['contact_id']
            ]);
        }

        $pdo->commit();
        systemLog($userData['name'] . " edited interment ID: " . $resourceId, $userData['user_id']);
        Response::success("Interment record updated successfully.");

    } catch (Exception $e) {
        $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if (in_array($code, [400, 404, 409])) Response::error($e->getMessage(), $code);
        Response::error("Database error while updating the record.", 500);
    }
}

Response::error("Method not allowed", 405);
?>