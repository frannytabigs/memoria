<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';
require_once 'gravestate.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser(); 

// ---------------------------------------------------------
// 1. GATEKEEPER & PRIVACY CLEARANCE
// ---------------------------------------------------------
$role = $userData['role'];

// Define the two types of allowed access
$isFullAccess = in_array($role, [ROLE_ADMIN, ROLE_OFFICE]);
//$isReadOnly   = ($role === ROLE_GROUNDS && $method === 'GET');

// If the user has NEITHER of these permissions, kick them out
if (!$isFullAccess) { //&& !$isReadOnly) {
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
    $email = trim($ownerData['email_address'] ?? '');
    $remarks = trim($ownerData['remarks'] ?? '');
    
    // --- FIX: Format & Validate early, and THROW an exception on failure ---
    $phone = trim($ownerData['phone_number'] ?? '');
    if (!empty($phone)) {
        $phone = formatPhNumber($phone);
        if (!$phone) {
            // This triggers the catch() block in your POST method to rollback the DB
            throw new Exception("Invalid Philippines phone number format.", 400); 
        }
    }

    // Now query the DB using the properly formatted phone number
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
                b.block_name, b.block_id, b.block_type, b.remarks AS block_remarks, b.owner_contact_id,
                d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death, d.date_of_birth, d.last_known_address,
                c.name AS contact_name, c.phone_number AS contact_phone, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted, c.address AS contact_address, c.barangay AS contact_barangay
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
                'block_type'       => $record['block_type'],
                'owner_contact_id' => $record['owner_contact_id'] ? (int)$record['owner_contact_id'] : null,
                'remarks'          => $record['block_remarks']
            ],
            
            'deceased' => [
                'deceased_id'        => (int)$record['deceased_id'],
                'name'               => $record['deceased_name'],
                'sex'                => $record['deceased_sex'],
                'date_of_birth'      => $record['date_of_birth'],
                'date_of_death'      => $record['date_of_death'],
                'death_certificate'  => $record['death_certificate'],
                'last_known_address' => $record['last_known_address'],
                'remarks'            => $record['deceased_remarks'],
                'is_archived'        => $record['deceased_deleted'] !== null
            ],
            
            'contact' => [
                'contact_id'   => (int)$record['contact_id'],
                'name'         => $record['contact_name'],
                'address'      => $record['contact_address'],
                'barangay'     => $record['contact_barangay'],
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
            b.block_name, b.block_id, b.block_type, b.owner_contact_id, b.remarks AS block_remarks,
            -- How many bodies this grave physically holds, counted across the WHOLE
            -- ledger. Records used to derive its merged-xN badge by tallying the
            -- rows on the page it happened to be showing, so two co-interments that
            -- landed on different pages both read as unshared.
            (SELECT COUNT(*) FROM interments s
              WHERE s.grave_id = i.grave_id
                AND s.deleted_at IS NULL
                AND s.status IN ('Active', 'Expired')) AS grave_occupant_count,
            d.name AS deceased_name, d.deceased_id, d.sex AS deceased_sex,
            d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death, d.date_of_birth, d.last_known_address,
            c.name AS contact_name, c.phone_number AS contact_phone, c.contact_id,
            c.address AS contact_address, c.barangay AS contact_barangay,
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
                // 0 for an unassigned record; >1 means this grave is a co-interment.
                'occupant_count' => (int)$row['grave_occupant_count'],
                'remarks'    => $row['grave_remarks']
            ],

            'block' => [
                'block_id'         => (int)$row['block_id'],
                'block_name'       => $row['block_name'],
                // The real enum. Records was inferring the burial type by looking for
                // the word "niche" or "bone" in block_name, which silently mislabelled
                // every block whose name did not spell its own type out.
                'block_type'       => $row['block_type'],
                'owner_contact_id' => $row['owner_contact_id'] ? (int)$row['owner_contact_id'] : null,
                'remarks'          => $row['block_remarks']
            ],
            
            'deceased' => [
                'deceased_id'       => (int)$row['deceased_id'],
                'name'              => $row['deceased_name'],
                'sex'               => $row['deceased_sex'],
                'date_of_birth'     => $row['date_of_birth'],
                'date_of_death'     => $row['date_of_death'],
                'death_certificate' => $row['death_certificate'],
                'last_known_address'=> $row['last_known_address'],
                'remarks'           => $row['deceased_remarks'],
                'is_archived'       => $row['deceased_deleted'] !== null
            ],
            
            'contact' => [
                'contact_id'   => (int)$row['contact_id'],
                'name'         => $row['contact_name'],
                'address'      => $row['contact_address'],
                'barangay'     => $row['contact_barangay'],
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
    
    // An empty page is a valid answer, not an error. The old 404 here made
    // records.js render "the backend is not running" for an empty ledger and
    // for any search that simply matched nothing.
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

        // 2. Lock the grave row, then run the SHARED intake gate.
        //    The old check only rejected status === 'Occupied', which meant Records
        //    could insert straight into a grave that Monitor had already staged
        //    ('Reserved' / 'Pending Exhumation') or that was 'Under Maintenance'.
        //    graveIntakeBlocker() is the same gate api/reserve.php uses, so the two
        //    intake paths cannot disagree about whether a grave is available.
        $graveStmt = $pdo->prepare("
            SELECT grave_id
            FROM graves
            WHERE grave_id = ? AND deleted_at IS NULL
            FOR UPDATE
        ");
        $graveStmt->execute([$graveId]);
        if (!$graveStmt->fetchColumn()) throw new Exception("Selected grave does not exist.", 404);

        // Records calls this "Merge"; the payload has always shipped as
        // is_co_interment. Accept either so the UI wording and the API stay decoupled.
        $isMerge = filter_var(
            $rawData['is_merged'] ?? $rawData['is_co_interment'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $blocker = graveIntakeBlocker($pdo, (int)$graveId, $isMerge);
        if ($blocker) throw new Exception($blocker['message'], $blocker['code']);
        
        // 3. Insert Interment
        $stmt = $pdo->prepare("
            INSERT INTO interments (
                control_number, deceased_id, grave_id, contact_id, assistance_type,
                burial_permit_number, burial_permit_date, transfer_permit_number, transfer_permit_issued_by, transfer_permit_date,
                exhumation_permit_number, exhumation_permit_date, date_buried, clearance_date, lease_expiration_date, remarks, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $controlNumber, $deceasedId, $graveId, $contactId, $assistanceType,
            $rawData['burial_permit_number'] ?? null, $rawData['burial_permit_date'] ?? null,
            $rawData['transfer_permit_number'] ?? null, $rawData['transfer_permit_issued_by'] ?? null, $rawData['transfer_permit_date'] ?? null,
            $rawData['exhumation_permit_number'] ?? null, $rawData['exhumation_permit_date'] ?? null,
            $rawData['date_buried'] ?? null, $rawData['clearance_date'] ?? null, $rawData['lease_expiration_date'] ?? null,
            $rawData['remarks'] ?? null, $userData['user_id']
        ]);
        
        $newId = $pdo->lastInsertId();

        // 4. Update Grave Status dynamically
        $newGraveStatus = recomputeGraveStatus($pdo, $graveId);

        $pdo->commit();
        systemLog($userData['name'] . " created interment: " . $controlNumber . ($isMerge ? " (merged)" : ""), $userData['user_id']);
        Response::success("Interment record filed.", [
            "interment_id" => $newId,
            "grave_status" => $newGraveStatus,
            "merged"       => $isMerge
        ], 201);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        // Handle specific PDO error codes
        if ($e->getCode() == 23000) {
            Response::error("Conflict: Control number already exists.", 409);
        }

        // Check for date/time format errors in PDO exceptions
        $message = $e->getMessage();
        if (
            stripos($message, 'Incorrect datetime value') !== false ||
            stripos($message, 'Invalid date') !== false
        ) {
            Response::error("Invalid date/time format. Please use YYYY-MM-DD HH:MM:SS format.", 400);
        }

        Response::error("Database error while creating interment: " . $e->getMessage(), 500);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        
        // Handle specific HTTP error codes
        if (in_array($code, [400, 404, 409])) {
            Response::error($e->getMessage(), $code);
        }
        
        // Default error
        Response::error("Database error or missing data: " . $e->getMessage(), 500);
    }
}

// ==========================================
// DELETE: REMOVE (Soft Delete & Free Grave)
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Interment ID required", 400);

    try {
        $pdo->beginTransaction();

        // Fetch the whole row, not just grave_id: interments.grave_id is nullable,
        // and `!$graveId` used to report a legitimately unassigned record as 404.
        $stmt = $pdo->prepare("
            SELECT interment_id, grave_id, control_number, status
            FROM interments
            WHERE interment_id = ? AND deleted_at IS NULL
            FOR UPDATE
        ");
        $stmt->execute([$resourceId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) throw new Exception("Interment not found.", 404);

        // A record captured by a live staging belongs to Monitor. Soft-deleting it
        // from Records would leave grave_transitions pointing at a dead row, and
        // finalizing that transition would then quietly do nothing.
        $lock = stagingLockFor($pdo, (int)$resourceId);
        if ($lock) {
            throw new Exception(
                "Conflict: this record is part of a staged transition (#" . $lock['transition_id'] . "). " .
                "Finalize it or cancel it in the Monitor module first.",
                409
            );
        }

        $graveId = $record['grave_id'] !== null ? (int)$record['grave_id'] : null;

        // Delete Interment
        $delStmt = $pdo->prepare("UPDATE interments SET deleted_at = NOW(), updated_by = ? WHERE interment_id = ?");
        $delStmt->execute([$userData['user_id'], $resourceId]);

        // Recalculate grave status safely. A co-interred grave stays Occupied.
        $newGraveStatus = $graveId !== null ? recomputeGraveStatus($pdo, $graveId) : null;

        $pdo->commit();
        systemLog($userData['name'] . " deleted interment ID: " . $resourceId . " (" . $record['control_number'] . ")", $userData['user_id']);
        Response::success(
            $newGraveStatus === null
                ? "Interment deleted."
                : "Interment deleted. The grave is now " . $newGraveStatus . ".",
            ["grave_status" => $newGraveStatus]
        );

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if (in_array($code, [400, 404, 409])) Response::error($e->getMessage(), $code);
        Response::error("Database error while deleting the record. " . $e->getMessage(), 500);
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
        $stmtCheck = $pdo->prepare("SELECT deceased_id, contact_id, grave_id, status FROM interments WHERE interment_id = ? AND deleted_at IS NULL FOR UPDATE");
        $stmtCheck->execute([$resourceId]);
        $currentRecord = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$currentRecord) throw new Exception("Interment record not found.", 404);

        // 1b. If Monitor is holding this record inside a live staging, Records must
        //     not touch it. Flipping a staged 'Pending' row to 'Active' from here
        //     would place a body in the grave while the transition still believes
        //     the old occupant has to come out first.
        $lock = stagingLockFor($pdo, (int)$resourceId);
        if ($lock) {
            throw new Exception(
                "Conflict: this record is part of a staged transition (#" . $lock['transition_id'] . "). " .
                "Edit it in the Monitor module, or cancel the transition first.",
                409
            );
        }

        // 2. Build the interment UPDATE from the keys the client actually sent.
        //    The old version wrapped every column in COALESCE(?, col), which made it
        //    impossible to CLEAR a field: an emptied date either kept its old value
        //    or reached MySQL as '' and blew up. Absent key = leave alone,
        //    present-but-blank = clear.
        $textColumns = [
            'burial_permit_number', 'transfer_permit_number', 'transfer_permit_issued_by',
            'exhumation_permit_number', 'remarks'
        ];
        $dateColumns = [
            'burial_permit_date', 'transfer_permit_date', 'exhumation_permit_date',
            'date_buried', 'date_exhumed', 'clearance_date', 'lease_expiration_date'
        ];

        $sets = [];
        $vals = [];

        foreach ($textColumns as $col) {
            if (!array_key_exists($col, $rawData)) continue;
            $sets[] = "$col = ?";
            $vals[] = trim((string)$rawData[$col]);
        }

        foreach ($dateColumns as $col) {
            if (!array_key_exists($col, $rawData)) continue;
            $value = $rawData[$col] === null ? '' : trim((string)$rawData[$col]);
            $sets[] = "$col = ?";
            $vals[] = ($value === '') ? null : $value;
        }

        // control_number is UNIQUE NOT NULL. A blank one is treated as "unchanged"
        // rather than an error, so a readonly field that failed to populate cannot
        // fail the whole save.
        if (array_key_exists('control_number', $rawData)) {
            $newControl = trim((string)($rawData['control_number'] ?? ''));
            if ($newControl !== '') {
                $sets[] = "control_number = ?";
                $vals[] = $newControl;
            }
        }

        if (array_key_exists('assistance_type', $rawData)) {
            $newType = trim((string)$rawData['assistance_type']);
            if ($newType !== '') {
                if (!in_array($newType, ['Burial', 'Transfer', 'Other'], true)) {
                    throw new Exception("Invalid assistance type. Use Burial, Transfer or Other.", 400);
                }
                $sets[] = "assistance_type = ?";
                $vals[] = $newType;
            }
        }

        if (array_key_exists('status', $rawData)) {
            $newStatus = trim((string)$rawData['status']);
            if ($newStatus !== '') {
                if (!in_array($newStatus, ['Active', 'Expired', 'Exhumed', 'Moved to Family'], true)) {
                    // 'Pending' is deliberately excluded: it only means "waiting on a
                    // staged transition", and only api/reserve may create that state.
                    throw new Exception("Invalid status. Use Active, Expired, Exhumed or Moved to Family.", 400);
                }
                $sets[] = "status = ?";
                $vals[] = $newStatus;
            }
        }

        if ($sets !== []) {
            $sets[] = "updated_by = ?";
            $vals[] = $userData['user_id'];
            $sets[] = "updated_at = NOW()";

            $vals[] = $resourceId;
            $updateInterment = $pdo->prepare(
                "UPDATE interments SET " . implode(", ", $sets) . " WHERE interment_id = ?"
            );
            $updateInterment->execute($vals);
        }

        // 3. Update the nested Deceased data (if provided).
        //    Same rule as above: absent key = leave alone, present-but-blank = clear.
        if (isset($rawData['deceased']) && is_array($rawData['deceased'])) {
            $dec = $rawData['deceased'];
            $decSets = [];
            $decVals = [];

            foreach (['name', 'death_certificate', 'last_known_address', 'remarks'] as $col) {
                if (!array_key_exists($col, $dec)) continue;
                $value = trim((string)($dec[$col] ?? ''));
                // The name is NOT NULL and identifies the person; never blank it.
                if ($col === 'name' && $value === '') continue;
                $decSets[] = "$col = ?";
                $decVals[] = $value;
            }

            foreach (['date_of_birth', 'date_of_death'] as $col) {
                if (!array_key_exists($col, $dec)) continue;
                $value = $dec[$col] === null ? '' : trim((string)$dec[$col]);
                $decSets[] = "$col = ?";
                $decVals[] = ($value === '') ? null : $value;
            }

            if (array_key_exists('sex', $dec)) {
                $sexValue = trim((string)$dec['sex']);
                if ($sexValue !== '') {
                    if (!in_array($sexValue, ['Male', 'Female', 'Unknown'], true)) {
                        throw new Exception("Invalid sex. Use Male, Female or Unknown.", 400);
                    }
                    $decSets[] = "sex = ?";
                    $decVals[] = $sexValue;
                }
            }

            if ($decSets !== []) {
                $decSets[] = "updated_by = ?";
                $decVals[] = $userData['user_id'];
                $decSets[] = "updated_at = NOW()";
                $decVals[] = $currentRecord['deceased_id'];

                $updateDec = $pdo->prepare(
                    "UPDATE deceased SET " . implode(", ", $decSets) . " WHERE deceased_id = ?"
                );
                $updateDec->execute($decVals);
            }
        }

        // 4. Update the nested Contact data (if provided)
        if (isset($rawData['contact']) && is_array($rawData['contact']) && $currentRecord['contact_id']) {
            $con = $rawData['contact'];
            $conSets = [];
            $conVals = [];

            foreach (['name', 'address', 'barangay', 'email_address', 'remarks'] as $col) {
                if (!array_key_exists($col, $con)) continue;
                $value = trim((string)($con[$col] ?? ''));
                // The contact's name is NOT NULL; never blank it.
                if ($col === 'name' && $value === '') continue;
                $conSets[] = "$col = ?";
                $conVals[] = $value;
            }

            // Only validate the phone when one was actually sent. An explicit blank
            // clears it, since a contact without a number is a legitimate state.
            if (array_key_exists('phone_number', $con)) {
                $rawPhone = trim((string)($con['phone_number'] ?? ''));
                if ($rawPhone === '') {
                    $conSets[] = "phone_number = ?";
                    $conVals[] = null;
                } else {
                    $phone = formatPhNumber($rawPhone);
                    if (!$phone) throw new Exception("Invalid Philippines phone number format.", 400);
                    $conSets[] = "phone_number = ?";
                    $conVals[] = $phone;
                }
            }

            if ($conSets !== []) {
                $conSets[] = "updated_by = ?";
                $conVals[] = $userData['user_id'];
                $conSets[] = "updated_at = NOW()";
                $conVals[] = $currentRecord['contact_id'];

                $updateCon = $pdo->prepare(
                    "UPDATE contacts SET " . implode(", ", $conSets) . " WHERE contact_id = ?"
                );
                $updateCon->execute($conVals);
            }
        }

        // 5. The edit may have changed whether this record still occupies the grave
        //    ('Active' -> 'Exhumed' / 'Moved to Family' empties it). Without this the
        //    grave stayed 'Occupied' forever and never came back into Reserve's lists.
        $newGraveStatus = null;
        if ($currentRecord['grave_id'] !== null) {
            $newGraveStatus = recomputeGraveStatus($pdo, (int)$currentRecord['grave_id']);
        }

        $pdo->commit();
        systemLog($userData['name'] . " edited interment ID: " . $resourceId, $userData['user_id']);
        Response::success("Interment record updated successfully.", ["grave_status" => $newGraveStatus]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        $message = $e->getMessage();

        // Our own validation errors carry a real HTTP code, so let them through
        // FIRST. The date sniffing below matches on the bare word 'Date', which
        // used to swallow deliberate 400/404/409 messages and rewrite them as
        // "Invalid date/time format".
        if (in_array($code, [400, 404, 409])) {
            Response::error($message, $code);
        }

        // Check for invalid date/time format errors raised by the driver
        if (
            stripos($message, 'Incorrect datetime value') !== false ||
            stripos($message, 'Invalid date') !== false ||
            stripos($message, 'DATETIME') !== false ||
            stripos($message, 'TIMESTAMP') !== false ||
            stripos($message, 'Date') !== false
        ) {
            Response::error("Invalid date/time format. Please use YYYY-MM-DD.", 400);
        }

        // Default database error
        Response::error("Database error while updating the record. " . $message, 500);
    }
}

Response::error("Method not allowed", 405);
?>