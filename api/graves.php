<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser(false);

// ---------------------------------------------------------
// 1. GATEKEEPER & PRIVACY CLEARANCE
// ---------------------------------------------------------
$isAuthorizedStaff = (isset($userData['role']) && in_array($userData['role'], [ROLE_ADMIN, ROLE_GROUNDS, ROLE_OFFICE]));

if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!$isAuthorizedStaff) {
        Response::error("Forbidden. You do not have permission to modify graves.", 403);
    }
}

// --- REST ROUTING: PARSE THE URI ---
// Check our custom .htaccess parameter first, then fallback to standard PATH_INFO
$pathInfo = $_GET['path_info'] ?? $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts);

if ($method === 'GET' && empty($resourceId)) {
    $searchTerm = trim((string)($_GET['search'] ?? ''));
    $searchTerm = substr($searchTerm, 0, 100);

    if ($searchTerm === '') {
        Response::error("A ?search= is required when searching graves. Or do graves/[grave_id]", 400);
    }

    $searchLike = '%' . $searchTerm . '%';
    $searchParams = array_fill(0, 35, $searchLike);

    $searchSql = "
        SELECT DISTINCT
            g.grave_id, g.grave_code, g.row_num, g.col_num,
            g.status AS grave_status, g.remarks AS grave_remarks, g.block_id,
            b.block_name
        FROM graves g
        INNER JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN interments i ON i.grave_id = g.grave_id AND i.deleted_at IS NULL
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        LEFT JOIN reservations r ON r.grave_id = g.grave_id AND r.deleted_at IS NULL
        LEFT JOIN deceased rd ON r.deceased_id = rd.deceased_id
        LEFT JOIN contacts rc ON r.contact_id = rc.contact_id
        WHERE g.deleted_at IS NULL
        AND b.deleted_at IS NULL
        AND (
            CAST(g.grave_id AS CHAR) LIKE ? OR
            g.grave_code LIKE ? OR
            CAST(g.row_num AS CHAR) LIKE ? OR
            CAST(g.col_num AS CHAR) LIKE ? OR
            g.status LIKE ? OR
            g.remarks LIKE ? OR
            CAST(g.block_id AS CHAR) LIKE ? OR
            b.block_name LIKE ? OR
            b.block_type LIKE ? OR
            b.remarks LIKE ? OR
            CAST(i.interment_id AS CHAR) LIKE ? OR
            i.control_number LIKE ? OR
            i.status LIKE ? OR
            i.remarks LIKE ? OR
            CAST(d.deceased_id AS CHAR) LIKE ? OR
            d.name LIKE ? OR
            d.sex LIKE ? OR
            CAST(d.date_of_birth AS CHAR) LIKE ? OR
            CAST(d.date_of_death AS CHAR) LIKE ? OR
            d.death_certificate LIKE ? OR
            d.last_known_address LIKE ? OR
            c.name LIKE ? OR
            c.address LIKE ? OR
            c.barangay LIKE ? OR
            c.phone_number LIKE ? OR
            c.email_address LIKE ? OR
            c.remarks LIKE ? OR
            CAST(r.reservation_id AS CHAR) LIKE ? OR
            r.status LIKE ? OR
            r.remarks LIKE ? OR
            CAST(rd.deceased_id AS CHAR) LIKE ? OR
            rd.name LIKE ? OR
            rc.name LIKE ? OR
            rc.phone_number LIKE ? OR
            rc.email_address LIKE ?
        )
        ORDER BY b.block_name ASC, g.row_num ASC, g.col_num ASC
        LIMIT 45
    ";

    $searchStmt = $pdo->prepare($searchSql);
    $searchStmt->execute($searchParams);
    $searchRows = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
    $searchGraves = [];

    foreach ($searchRows as $row) {
        $searchGrave = [
            'grave_id'   => (int)$row['grave_id'],
            'block_id'   => (int)$row['block_id'],
            'block_name' => $row['block_name'],
            'grave_code' => $row['grave_code'],
            'status'     => $row['grave_status'],
            'row_num'    => (int)$row['row_num'],
            'col_num'    => (int)$row['col_num'],
            'remarks'    => $isAuthorizedStaff ? $row['grave_remarks'] : null,
            'interments' => [],
            'reservations' => []
        ];

        if ($isAuthorizedStaff) {
            $intermentStmt = $pdo->prepare("SELECT i.interment_id, i.control_number, i.date_buried, i.lease_expiration_date, i.remarks AS interment_remarks, d.deceased_id, d.name AS deceased_name, d.sex AS deceased_sex, c.contact_id, c.name AS contact_name, c.phone_number AS contact_phone FROM interments i LEFT JOIN deceased d ON i.deceased_id = d.deceased_id AND d.deleted_at IS NULL LEFT JOIN contacts c ON i.contact_id = c.contact_id AND c.deleted_at IS NULL WHERE i.grave_id = ? AND i.deleted_at IS NULL AND i.status IN ('Active', 'Expired')");
            $intermentStmt->execute([$row['grave_id']]);
            foreach ($intermentStmt->fetchAll(PDO::FETCH_ASSOC) as $interment) {
                $searchGrave['interments'][] = [
                    'interment_id' => (int)$interment['interment_id'],
                    'control_number' => $interment['control_number'],
                    'date_buried' => $interment['date_buried'],
                    'lease_expiration_date' => $interment['lease_expiration_date'],
                    'remarks' => $interment['interment_remarks'],
                    'deceased' => [
                        'deceased_id' => (int)$interment['deceased_id'],
                        'name' => $interment['deceased_name'],
                        'sex' => $interment['deceased_sex']
                    ],
                    'contact' => [
                        'contact_id' => (int)$interment['contact_id'],
                        'name' => $interment['contact_name'],
                        'phone_number' => $interment['contact_phone']
                    ]
                ];
            }

            $reservationStmt = $pdo->prepare("SELECT r.reservation_id, r.expiration_date AS reservation_expiration, r.remarks AS reservation_remarks, rc.contact_id AS reserver_contact_id, rc.name AS reserver_name, rc.phone_number AS reserver_phone, rd.deceased_id AS reserved_deceased_id, rd.name AS reserved_deceased_name FROM reservations r LEFT JOIN contacts rc ON r.contact_id = rc.contact_id AND rc.deleted_at IS NULL LEFT JOIN deceased rd ON r.deceased_id = rd.deceased_id AND rd.deleted_at IS NULL WHERE r.grave_id = ? AND r.deleted_at IS NULL AND r.status = 'Active'");
            $reservationStmt->execute([$row['grave_id']]);
            foreach ($reservationStmt->fetchAll(PDO::FETCH_ASSOC) as $reservation) {
                $searchGrave['reservations'][] = [
                    'reservation_id' => (int)$reservation['reservation_id'],
                    'expiration_date' => $reservation['reservation_expiration'],
                    'remarks' => $reservation['reservation_remarks'],
                    'reserver' => [
                        'contact_id' => (int)$reservation['reserver_contact_id'],
                        'name' => $reservation['reserver_name'],
                        'phone_number' => $reservation['reserver_phone']
                    ],
                    'reserved_for_deceased' => [
                        'deceased_id' => (int)$reservation['reserved_deceased_id'],
                        'name' => $reservation['reserved_deceased_name']
                    ]
                ];
            }
        }

        $searchGraves[] = $searchGrave;
    }

    if ($searchGraves === []){
        Response::error("No graves found in the search criteria (" . $searchTerm . ")", 404);
    }
    Response::success("Graves search completed successfully", [
        'search_term' => $searchTerm,
        'graves' => $searchGraves
    ]);
}

if (empty($resourceId) || !is_numeric($resourceId)) {
    Response::error("Valid Grave ID is required.", 400);
}

// ==========================================
// GET: RETRIEVE SINGLE GRAVE
// ==========================================
if ($method === 'GET') {
    
    // 1. Fetch the grave with its block info
    $stmt = $pdo->prepare("
        SELECT 
            g.grave_id, g.grave_code, g.row_num, g.col_num, g.status AS grave_status, g.remarks AS grave_remarks, g.block_id,
            b.block_name
        FROM graves g
        JOIN blocks b ON g.block_id = b.block_id
        WHERE g.grave_id = :id AND g.deleted_at IS NULL LIMIT 1
    ");
    
    $stmt->execute([':id' => $resourceId]);
    $graveRaw = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$graveRaw) Response::error("Grave not found", 404);

    // -- Construct Grave Object --
    $grave = [
        'grave_id'   => (int)$graveRaw['grave_id'],
        'block_id'   => (int)$graveRaw['block_id'],
        'block_name' => $graveRaw['block_name'],
        'grave_code' => $graveRaw['grave_code'],
        'status'     => $graveRaw['grave_status'],
        'row_num'    => (int)$graveRaw['row_num'],
        'col_num'    => (int)$graveRaw['col_num'],
        'remarks'    => $isAuthorizedStaff ? $graveRaw['grave_remarks'] : null,
        'interments' => [],
        'reservations' => []
    ];

    // 2. Fetch interments for this grave (only if authorized)
    if ($isAuthorizedStaff) {
        $intermentStmt = $pdo->prepare("
            SELECT
                i.interment_id, i.control_number, i.date_buried, 
                i.lease_expiration_date, i.remarks AS interment_remarks,
                d.deceased_id, d.name AS deceased_name, d.sex AS deceased_sex,
                c.contact_id, c.name AS contact_name, c.address AS contact_address,
                c.barangay AS contact_barangay, c.phone_number AS contact_phone, 
                c.email_address AS contact_email, c.remarks AS contact_remarks
            FROM interments i
            LEFT JOIN deceased d ON i.deceased_id = d.deceased_id AND d.deleted_at IS NULL
            LEFT JOIN contacts c ON i.contact_id = c.contact_id AND c.deleted_at IS NULL
            WHERE i.grave_id = :id 
            AND i.deleted_at IS NULL
            AND i.status IN ('Active', 'Expired')
        ");
        $intermentStmt->execute([':id' => $resourceId]);
        foreach ($intermentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grave['interments'][] = [
                'interment_id'          => (int)$row['interment_id'],
                'control_number'        => $row['control_number'],
                'date_buried'           => $row['date_buried'],
                'lease_expiration_date' => $row['lease_expiration_date'],
                'remarks'               => $row['interment_remarks'],
                'deceased' => [
                    'deceased_id' => (int)$row['deceased_id'],
                    'name'        => $row['deceased_name'],
                    'sex'         => $row['deceased_sex']
                ],
                'contact' => [
                    'contact_id'   => (int)$row['contact_id'],
                    'name'         => $row['contact_name'],
                    'address'      => $row['contact_address'],
                    'barangay'     => $row['contact_barangay'],
                    'phone_number' => $row['contact_phone'],
                    'email'        => $row['contact_email'],
                    'remarks'      => $row['contact_remarks']
                ]
            ];
        }

        // 3. Fetch reservations for this grave
        $reservationStmt = $pdo->prepare("
            SELECT
                r.reservation_id, r.expiration_date AS reservation_expiration, r.remarks AS reservation_remarks,
                rc.contact_id AS reserver_contact_id, rc.name AS reserver_name, rc.phone_number AS reserver_phone,
                rd.deceased_id AS reserved_deceased_id, rd.name AS reserved_deceased_name
            FROM reservations r
            LEFT JOIN contacts rc ON r.contact_id = rc.contact_id AND rc.deleted_at IS NULL
            LEFT JOIN deceased rd ON r.deceased_id = rd.deceased_id AND rd.deleted_at IS NULL
            WHERE r.grave_id = :id 
            AND r.deleted_at IS NULL
            AND r.status = 'Active'
        ");
        $reservationStmt->execute([':id' => $resourceId]);
        foreach ($reservationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grave['reservations'][] = [
                'reservation_id'  => (int)$row['reservation_id'],
                'expiration_date' => $row['reservation_expiration'],
                'remarks'         => $row['reservation_remarks'],
                'reserver' => [
                    'contact_id'   => (int)$row['reserver_contact_id'],
                    'name'         => $row['reserver_name'],
                    'phone_number' => $row['reserver_phone']
                ],
                'reserved_for_deceased' => [
                    'deceased_id' => (int)$row['reserved_deceased_id'],
                    'name'        => $row['reserved_deceased_name']
                ]
            ];
        }
    }

    Response::success("Grave retrieved successfully", $grave);
}

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

// ==========================================
// POST: NOT ALLOWED (Graves created via blocks)
// ==========================================
if ($method === 'POST') {
    Response::error("Method Not Allowed. Graves can only be created automatically via the blocks controller.", 405);
}

// ==========================================
// PUT: UPDATE GRAVE (Code, Status & Remarks)
// ==========================================
if ($method === 'PUT') {
    
    // 1. Fetch current grave state
    $stmt = $pdo->prepare("
        SELECT grave_id, grave_code, status, remarks 
        FROM graves 
        WHERE grave_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$resourceId]);
    $oldGrave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldGrave) Response::error("Grave not found", 404);

    $newGraveCode = isset($rawData['grave_code']) ? trim($rawData['grave_code']) : $oldGrave['grave_code'];
    $newStatus = isset($rawData['status']) ? trim($rawData['status']) : $oldGrave['status'];
    $newRemarks = isset($rawData['remarks']) ? trim($rawData['remarks']) : $oldGrave['remarks'];

    // 2. Dynamically fetch valid ENUM values from database (STRICT DB CHECK, no hardcodes)
    $enumQuery = $pdo->query("SHOW COLUMNS FROM graves LIKE 'status'");
    $enumRow = $enumQuery->fetch(PDO::FETCH_ASSOC);
    
    $validStatuses = [];
    if ($enumRow && preg_match("/^enum\(\'(.*)\'\)$/", $enumRow['Type'], $matches)) {
        $validStatuses = explode("','", $matches[1]);
    } else {
        Response::error("Server Error: Could not determine valid status values from the database schema.", 500);
    }

    if (!in_array($newStatus, $validStatuses)) {
        Response::error("Invalid status provided. Allowed values: " . implode(", ", $validStatuses), 400);
    }

    // 3. Track actual changes and check constraints
    $changes = [];
    
    if ($newGraveCode !== $oldGrave['grave_code']) {
        if (empty($newGraveCode)) {
            Response::error("Grave code cannot be empty.", 400);
        }
        
        // Verify code isn't taken by another active grave
        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM graves WHERE grave_code = ? AND grave_id != ? AND deleted_at IS NULL");
        $dupStmt->execute([$newGraveCode, $resourceId]);
        if ($dupStmt->fetchColumn() > 0) {
            Response::error("Conflict: The grave code '{$newGraveCode}' is already in use by another grave.", 409);
        }
        $changes[] = "Grave Code ({$oldGrave['grave_code']} -> {$newGraveCode})";
    }

    if ($newStatus !== $oldGrave['status']) $changes[] = "Status ({$oldGrave['status']} -> {$newStatus})";
    if ($newRemarks !== $oldGrave['remarks']) $changes[] = "Remarks updated";

    if (empty($changes)) {
        Response::error("No changes detected.", 400);
    }

    // 4. Safety check: Prevent inconsistent state
    if (in_array($newStatus, ['Vacant', 'Under Maintenance'])) {
        $checkStmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM interments WHERE grave_id = ? AND status IN ('Active', 'Expired') AND deleted_at IS NULL) as active_bodies,
                (SELECT COUNT(*) FROM reservations WHERE grave_id = ? AND status = 'Active' AND deleted_at IS NULL) as active_res
        ");
        $checkStmt->execute([$resourceId, $resourceId]);
        $counts = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($counts['active_bodies'] > 0 || $counts['active_res'] > 0) {
            Response::error("Conflict: Cannot change status to '{$newStatus}'. This grave currently contains active interments or reservations.", 409);
        }
    }

    // 5. Apply Updates
    try {
        $updateStmt = $pdo->prepare("
            UPDATE graves 
            SET grave_code = ?, status = ?, remarks = ?, updated_by = ?, updated_at = NOW() 
            WHERE grave_id = ?
        ");
        $updateStmt->execute([$newGraveCode, $newStatus, $newRemarks, $userData['user_id'], $resourceId]);

        systemLog($userData['name'] . " updated grave ID " . $resourceId . ". Changes: " . implode("; ", $changes), $userData['user_id']);
        
        Response::success("Grave updated successfully.", [
            "grave_id"   => (int)$resourceId,
            "grave_code" => $newGraveCode,
            "status"     => $newStatus,
            "remarks"    => $newRemarks
        ]);

    } catch (PDOException $e) {
        systemLog($userData['name'] . " failed to update grave ID: " . $resourceId . ". Error: " . $e->getMessage(), $userData['user_id']);
        Response::error("Database error while updating grave.", 500);
    }
}

// ==========================================
// DELETE: SOFT DELETE GRAVE
// ==========================================
if ($method === 'DELETE') {
    
    // Safety check: Can only delete if completely vacant
    $stmt = $pdo->prepare("
        SELECT g.grave_code, g.status,
            (SELECT COUNT(*) FROM interments WHERE grave_id = g.grave_id AND status IN ('Active', 'Expired') AND deleted_at IS NULL) as active_bodies,
            (SELECT COUNT(*) FROM reservations WHERE grave_id = g.grave_id AND status = 'Active' AND deleted_at IS NULL) as active_res
        FROM graves g
        WHERE g.grave_id = ? AND g.deleted_at IS NULL LIMIT 1
    ");
    $stmt->execute([$resourceId]);
    $grave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$grave) Response::error("Grave not found", 404);

    if ($grave['status'] !== 'Vacant' || $grave['active_bodies'] > 0 || $grave['active_res'] > 0) {
        Response::error("Conflict: Cannot delete a grave that contains active interments, reservations, or is not marked as Vacant.", 409);
    }

    try {
        // We append _DEL_ and the current timestamp to the grave code. 
        // This ensures unique indexes on `grave_code` won't trigger if you try to recreate the grave code later.
        $stmt = $pdo->prepare("
            UPDATE graves 
            SET 
                grave_code = CONCAT(grave_code, '_DEL_', UNIX_TIMESTAMP()),
                deleted_at = NOW(), 
                updated_by = ? 
            WHERE grave_id = ?
        ");
        $stmt->execute([$userData['user_id'], $resourceId]);

        systemLog($userData['name'] . " deleted grave: " . $grave['grave_code'], $userData['user_id']);
        Response::success("Grave successfully deleted.");

    } catch (PDOException $e) {
        systemLog($userData['name'] . " failed to delete grave ID: " . $resourceId . ". Error: " . $e->getMessage(), $userData['user_id']);
        Response::error("Database error while deleting grave.", 500);
    }
}

Response::error("Method not allowed", 405);
?>