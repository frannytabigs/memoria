<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser(); 

// ---------------------------------------------------------
// 1. GATEKEEPER
// ---------------------------------------------------------
$role = $userData['role'];
$isAuthorizedStaff = in_array($role, [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS]);

if (!$isAuthorizedStaff) {
    Response::error("Forbidden. You do not have permission to access the monitor.", 403);
}

// Ensure only GET and PUT are used here.
if ($method !== 'GET' && $method !== 'PUT') {
    Response::error("Method not allowed. Use reserve.php for intake, or GET/PUT here for staging.", 405);
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

$readTransitionData = function ($remarks) {
    if (!is_string($remarks) || $remarks === '') return [null, $remarks];
    $decoded = json_decode($remarks, true);
    if (!is_array($decoded) || ($decoded['_workflow'] ?? '') !== 'pending_transition') {
        return [null, $remarks];
    }
    return [$decoded['old_occupant_destination'] ?? null, $decoded['remarks'] ?? ''];
};

// ---------------------------------------------------------
// HELPER: FORMAT INTERMENT (Reused for consistency)
// ---------------------------------------------------------
$formatIntermentRow = function($row) use ($readTransitionData) {
    if (!$row) return null;
    [$destination, $remarks] = $readTransitionData($row['remarks'] ?? null);
    return [
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
        'date_exhumed'          => $row['date_exhumed'] ?? null,
        'clearance_date'        => $row['clearance_date'],
        'status'                => $row['status'],
        'lease_expiration_date' => $row['lease_expiration_date'],
        'remarks'               => $remarks,
        'old_occupant_destination' => $destination,
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
};

// ==========================================
// GET: THE STAGING AREA DASHBOARD
// ==========================================
if ($method === 'GET') {
    
    $rawLimit = $_GET['limit'] ?? 50; 
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? min((int)$rawLimit, 500) : 50;

    // We only care about graves in the middle of a transition!
    $whereClause = "WHERE g.status IN ('Pending Exhumation', 'Reserved') AND g.deleted_at IS NULL";
    
    $countStmt = $pdo->query("SELECT COUNT(*) FROM graves g $whereClause");
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;

    $rawPage = $_GET['page'] ?? 1;
    $page = (!is_numeric($rawPage) || $rawPage < 1) ? 1 : min((int)$rawPage, $totalPages);
    $offset = ($page - 1) * $limit;

    // 1. Fetch the Graves currently in transition
    $stmtGraves = $pdo->prepare("
        SELECT 
            g.grave_id, g.grave_code, g.status AS grave_status, g.remarks AS grave_remarks,
            b.block_id, b.block_name
        FROM graves g
        LEFT JOIN blocks b ON g.block_id = b.block_id
        $whereClause
        ORDER BY g.updated_at ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmtGraves->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtGraves->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtGraves->execute();
    
    $graves = $stmtGraves->fetchAll(PDO::FETCH_ASSOC);

    // If no graves are in transition, return early
    if (empty($graves)) {
        Response::success("Staging area retrieved", [
            "pagination" => ['current_page' => $page, 'per_page' => $limit, 'total_pages' => $totalPages, 'total_records' => 0],
            "staging_list" => []
        ]);
    }

    // 2. Extract Grave IDs to efficiently fetch the related interments
    $graveIds = array_column($graves, 'grave_id');
    $placeholders = implode(',', array_fill(0, count($graveIds), '?'));

    // 3. Fetch OUTGOING Interments (The ones being replaced: Active or Expired)
    $stmtOutgoing = $pdo->prepare("
        SELECT i.*, g.grave_code, g.remarks AS grave_remarks,
            b.block_id, b.block_name, b.owner_contact_id, b.remarks AS block_remarks,
            d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks,
            d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death,
            c.name AS contact_name, c.phone_number AS contact_phone, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
        FROM interments i
        LEFT JOIN graves g ON i.grave_id = g.grave_id
        LEFT JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        WHERE i.grave_id IN ($placeholders) AND i.status IN ('Active', 'Expired') AND i.deleted_at IS NULL
    ");
    $stmtOutgoing->execute($graveIds);
    $outgoingRecords = [];
    foreach ($stmtOutgoing->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $outgoingRecords[$row['grave_id']] = $formatIntermentRow($row);
    }

    // 4. Fetch INCOMING Interments (The new ones waiting: Pending)
    $stmtIncoming = $pdo->prepare("
        SELECT i.*, g.grave_code, g.remarks AS grave_remarks,
            b.block_id, b.block_name, b.owner_contact_id, b.remarks AS block_remarks,
            d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks,
            d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death,
            c.name AS contact_name, c.phone_number AS contact_phone, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
        FROM interments i
        LEFT JOIN graves g ON i.grave_id = g.grave_id
        LEFT JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        WHERE i.grave_id IN ($placeholders) AND i.status = 'Pending' AND i.deleted_at IS NULL
    ");
    $stmtIncoming->execute($graveIds);
    $incomingRecords = [];
    foreach ($stmtIncoming->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $incomingRecords[$row['grave_id']] = $formatIntermentRow($row);
    }

    // 5. Build the Side-by-Side JSON Structure
    $stagingList = [];
    foreach ($graves as $grave) {
        $gid = $grave['grave_id'];
        $stagingList[] = [
            'grave' => [
                'grave_id'     => (int)$gid,
                'grave_code'   => $grave['grave_code'],
                'grave_status' => $grave['grave_status'],
                'block_name'   => $grave['block_name']
            ],
            // Pair them side-by-side! (Outgoing might be null if it was a Vacant grave)
            'outgoing_occupant' => $outgoingRecords[$gid] ?? null,
            'incoming_occupant' => $incomingRecords[$gid] ?? null
        ];
    }

    Response::success("Staging area retrieved", [
        "pagination" => [
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_pages'   => $totalPages,
            'total_records' => $totalRecords
        ],
        "staging_list" => $stagingList
    ]);
}

// ==========================================
// PUT: THE "CHECK MARK" (EXECUTE TRANSITION)
// ==========================================
if ($method === 'PUT') {
    
    $action = $rawData['action'] ?? '';
    
    // We expect the frontend to send: { "action": "execute_transition", "grave_id": 12 }
    if ($action !== 'execute_transition') {
        Response::error("Invalid action parameter.", 400);
    }

    $graveId = !empty($rawData['grave_id']) ? (int)$rawData['grave_id'] : null;
    if (!$graveId) {
        Response::error("Grave ID is required to execute transition.", 400);
    }

    try {
        $pdo->beginTransaction();

        // 1. Verify the grave is actually in a transition state
        $graveStmt = $pdo->prepare("SELECT status, grave_code FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
        $graveStmt->execute([$graveId]);
        $graveInfo = $graveStmt->fetch(PDO::FETCH_ASSOC);

        if (!$graveInfo) throw new Exception("Grave not found.", 404);
        if (!in_array($graveInfo['status'], ['Pending Exhumation', 'Reserved'])) {
            throw new Exception("Conflict: Grave is not in a staging state.", 409);
        }

        $pendingStmt = $pdo->prepare("SELECT interment_id, remarks FROM interments WHERE grave_id = ? AND status = 'Pending' AND deleted_at IS NULL FOR UPDATE");
        $pendingStmt->execute([$graveId]);
        $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pendingRow) throw new Exception("No 'Pending' incoming occupant found for this grave.", 404);

        [$destination] = $readTransitionData($pendingRow['remarks']);
        if ($graveInfo['status'] === 'Pending Exhumation') {
            if (!is_array($destination) || empty($destination['type'])) {
                throw new Exception("Old occupant destination must be completed before transition.", 400);
            }
            if ($destination['type'] === 'specific_grave') {
                $destinationGraveId = !empty($destination['grave_id']) ? (int)$destination['grave_id'] : null;
                if (!$destinationGraveId || $destinationGraveId === $graveId) {
                    throw new Exception("A different destination grave is required.", 400);
                }
                $destinationStmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
                $destinationStmt->execute([$destinationGraveId]);
                if ($destinationStmt->fetchColumn() !== 'Vacant') {
                    throw new Exception("Destination grave is no longer vacant.", 409);
                }
            }
            if ($destination['type'] === 'other' && trim($destination['notes'] ?? '') === '') {
                throw new Exception("Destination notes are required for 'other'.", 400);
            }
        }

        // 2. EXHUME THE OLD: Update any Active/Expired records to Exhumed
        $exhumeStmt = $pdo->prepare("
            UPDATE interments 
            SET status = CASE WHEN ? = 'family_custody' THEN 'Moved to Family' ELSE 'Exhumed' END,
                date_exhumed = CURDATE(), updated_by = ?, updated_at = NOW()
            WHERE grave_id = ? AND status IN ('Active', 'Expired') AND deleted_at IS NULL
        ");
        $exhumeStmt->execute([$destination['type'] ?? '', $userData['user_id'], $graveId]);

        if (($destination['type'] ?? '') === 'specific_grave') {
            $destinationGraveId = (int)$destination['grave_id'];
            $moveOldStmt = $pdo->prepare("UPDATE interments SET grave_id = ? WHERE grave_id = ? AND status IN ('Exhumed', 'Moved to Family') AND updated_by = ? AND deleted_at IS NULL");
            $moveOldStmt->execute([$destinationGraveId, $graveId, $userData['user_id']]);

            $occupyDestinationStmt = $pdo->prepare("UPDATE graves SET status = 'Occupied', updated_by = ?, updated_at = NOW() WHERE grave_id = ? AND status = 'Vacant' AND deleted_at IS NULL");
            $occupyDestinationStmt->execute([$userData['user_id'], $destinationGraveId]);
            if ($occupyDestinationStmt->rowCount() !== 1) {
                throw new Exception("Destination grave could not be occupied.", 409);
            }
        }

        // 3. ACTIVATE THE NEW: Update the Pending record to Active
        $activateStmt = $pdo->prepare("
            UPDATE interments 
            SET status = 'Active', date_buried = CURDATE(), updated_by = ?, updated_at = NOW() 
            WHERE grave_id = ? AND status = 'Pending' AND deleted_at IS NULL
        ");
        $activateStmt->execute([$userData['user_id'], $graveId]);
        
        // 4. LOCK THE GRAVE: Update the physical slot to Occupied
        $occupyStmt = $pdo->prepare("
            UPDATE graves 
            SET status = 'Occupied', updated_by = ?, updated_at = NOW() 
            WHERE grave_id = ?
        ");
        $occupyStmt->execute([$userData['user_id'], $graveId]);

        $pdo->commit();
        systemLog($userData['name'] . " executed physical transition for Grave: " . $graveInfo['grave_code'], $userData['user_id']);
        
        Response::success("Transition complete! Old occupant exhumed, new occupant activated.");

    } catch (Exception $e) {
        $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if (in_array($code, [400, 404, 409])) Response::error($e->getMessage(), $code);
        Response::error("Database error during execution.", 500);
    }
}

Response::error("Method not allowed", 405);
?>