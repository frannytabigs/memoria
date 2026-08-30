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

// ---------------------------------------------------------
// SHARED: the live staging attached to a grave, if any.
// `reservations` is gone (see sql.txt) — grave_transitions replaced it, and
// uniq_live_staging guarantees at most one Staged row per grave.
// ---------------------------------------------------------
$fetchStaging = function (int $graveId) use ($pdo): ?array {
    $stmt = $pdo->prepare("
        SELECT
            t.transition_id, t.outgoing_destination, t.destination_grave_id,
            t.destination_notes, t.prior_grave_status, t.created_at AS staged_at,
            dg.grave_code AS destination_grave_code,
            i.interment_id AS incoming_interment_id, i.control_number,
            i.date_buried, i.lease_expiration_date, i.status AS incoming_status,
            d.deceased_id AS incoming_deceased_id, d.name AS incoming_deceased_name,
            c.contact_id AS incoming_contact_id, c.name AS incoming_contact_name,
            c.phone_number AS incoming_contact_phone
        FROM grave_transitions t
        INNER JOIN interments i ON t.incoming_interment_id = i.interment_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id AND d.deleted_at IS NULL
        LEFT JOIN contacts c ON i.contact_id = c.contact_id AND c.deleted_at IS NULL
        LEFT JOIN graves dg ON t.destination_grave_id = dg.grave_id
        WHERE t.grave_id = ? AND t.status = 'Staged' AND t.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$graveId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    return [
        'transition_id'      => (int)$row['transition_id'],
        'staged_at'          => $row['staged_at'],
        'prior_grave_status' => $row['prior_grave_status'],
        'outgoing_destination' => [
            'type'       => $row['outgoing_destination'],
            'grave_id'   => $row['destination_grave_id'] !== null ? (int)$row['destination_grave_id'] : null,
            'grave_code' => $row['destination_grave_code'],
            'notes'      => $row['destination_notes']
        ],
        'incoming' => [
            'interment_id'          => (int)$row['incoming_interment_id'],
            'control_number'        => $row['control_number'],
            'status'                => $row['incoming_status'],
            'date_buried'           => $row['date_buried'],
            'lease_expiration_date' => $row['lease_expiration_date'],
            'deceased' => [
                'deceased_id' => $row['incoming_deceased_id'] !== null ? (int)$row['incoming_deceased_id'] : null,
                'name'        => $row['incoming_deceased_name']
            ],
            'contact' => [
                'contact_id'   => $row['incoming_contact_id'] !== null ? (int)$row['incoming_contact_id'] : null,
                'name'         => $row['incoming_contact_name'],
                'phone_number' => $row['incoming_contact_phone']
            ]
        ]
    ];
};

if ($method === 'GET' && empty($resourceId)) {
    $searchTerm = trim((string)($_GET['search'] ?? ''));
    $searchTerm = substr($searchTerm, 0, 100);

    if ($searchTerm === '') {
        Response::error("A ?search= is required when searching graves. Or do graves/[grave_id]", 400);
    }

    $searchLike = '%' . $searchTerm . '%';

    // NOTE: the `interments i` join carries no status filter, so Pending
    // (staged) records are searchable through i / d / c as well. The
    // grave_transitions join only adds the staging-specific columns.
    $searchColumns = [
        'CAST(g.grave_id AS CHAR)',
        'g.grave_code',
        'CAST(g.row_num AS CHAR)',
        'CAST(g.col_num AS CHAR)',
        'g.status',
        'g.remarks',
        'CAST(g.block_id AS CHAR)',
        'b.block_name',
        'b.block_type',
        'b.remarks',
        'CAST(i.interment_id AS CHAR)',
        'i.control_number',
        'i.status',
        'i.remarks',
        'CAST(d.deceased_id AS CHAR)',
        'd.name',
        'd.sex',
        'CAST(d.date_of_birth AS CHAR)',
        'CAST(d.date_of_death AS CHAR)',
        'd.death_certificate',
        'd.last_known_address',
        'c.name',
        'c.address',
        'c.barangay',
        'c.phone_number',
        'c.email_address',
        'c.remarks',
        'CAST(t.transition_id AS CHAR)',
        't.outgoing_destination',
        't.destination_notes',
        't.prior_grave_status'
    ];

    // Derive the placeholder count from the column list so the two can never
    // drift apart the way a hand-counted array_fill() does.
    $searchWhere  = implode(" LIKE ? OR\n            ", $searchColumns) . " LIKE ?";
    $searchParams = array_fill(0, count($searchColumns), $searchLike);

    $searchSql = "
        SELECT DISTINCT
            g.grave_id, g.grave_code, g.row_num, g.col_num,
            g.status AS grave_status, g.remarks AS grave_remarks, g.block_id,
            b.block_name, b.block_type
        FROM graves g
        INNER JOIN blocks b ON g.block_id = b.block_id
        LEFT JOIN interments i ON i.grave_id = g.grave_id AND i.deleted_at IS NULL
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
        LEFT JOIN grave_transitions t
               ON t.grave_id = g.grave_id AND t.status = 'Staged' AND t.deleted_at IS NULL
        WHERE g.deleted_at IS NULL
        AND b.deleted_at IS NULL
        AND (
            {$searchWhere}
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
            // Callers filter their grave pickers on this; without it every
            // looked-up grave read as "type unknown" and matched everything.
            'block_type' => $row['block_type'],
            'grave_code' => $row['grave_code'],
            'status'     => $row['grave_status'],
            'row_num'    => (int)$row['row_num'],
            'col_num'    => (int)$row['col_num'],
            'remarks'    => $isAuthorizedStaff ? $row['grave_remarks'] : null,
            'interments' => [],
            'staging'    => null
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

            $searchGrave['staging'] = $fetchStaging((int)$row['grave_id']);
        }

        $searchGraves[] = $searchGrave;
    }

    // An empty result is a valid answer to a search, not an error. Returning
    // 404 here made every "no such code yet" lookup look like a broken request.
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
            b.block_name, b.block_type
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
        'block_type' => $graveRaw['block_type'],
        'grave_code' => $graveRaw['grave_code'],
        'status'     => $graveRaw['grave_status'],
        'row_num'    => (int)$graveRaw['row_num'],
        'col_num'    => (int)$graveRaw['col_num'],
        'remarks'    => $isAuthorizedStaff ? $graveRaw['grave_remarks'] : null,
        'interments' => [],
        'staging'    => null
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

        // 3. Fetch the live staging (if Monitor is mid-transition on this grave)
        $grave['staging'] = $fetchStaging((int)$resourceId);
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
                (SELECT COUNT(*) FROM grave_transitions WHERE grave_id = ? AND status = 'Staged' AND deleted_at IS NULL) as live_stagings
        ");
        $checkStmt->execute([$resourceId, $resourceId]);
        $counts = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($counts['active_bodies'] > 0 || $counts['live_stagings'] > 0) {
            Response::error("Conflict: Cannot change status to '{$newStatus}'. This grave currently contains active interments or a staged transition.", 409);
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
            (SELECT COUNT(*) FROM interments WHERE grave_id = g.grave_id AND deleted_at IS NULL) as any_records,
            (SELECT COUNT(*) FROM grave_transitions WHERE grave_id = g.grave_id AND status = 'Staged' AND deleted_at IS NULL) as live_stagings
        FROM graves g
        WHERE g.grave_id = ? AND g.deleted_at IS NULL LIMIT 1
    ");
    $stmt->execute([$resourceId]);
    $grave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$grave) Response::error("Grave not found", 404);

    // `interments.grave_id` is ON DELETE RESTRICT, so any historical record —
    // Exhumed and Moved to Family included — still pins this grave. Soft-deleting
    // it would orphan those rows in the ledger.
    if ($grave['status'] !== 'Vacant' || $grave['any_records'] > 0 || $grave['live_stagings'] > 0) {
        Response::error("Conflict: Cannot delete a grave that carries interment history, a staged transition, or is not marked as Vacant.", 409);
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