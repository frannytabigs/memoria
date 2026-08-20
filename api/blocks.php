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
        Response::error("Forbidden. You do not have permission to modify blocks.", 403);
    }
}

// --- REST-ish ROUTING ---
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts); 

// ==========================================
// GET: RETRIEVE BLOCKS (STRICT RELATIONAL JSON)
// ==========================================
if ($method === 'GET') {
    
    // SCENARIO A: Fetch a Single Block with its fully structured Graves, Interments, and Reservations
    if (is_numeric($resourceId)) {
        
        // 1. Fetch Block & Owner Data
        $stmt = $pdo->prepare("
            SELECT 
                b.block_id, b.block_name, b.coordinates, b.block_type, b.floor_level, 
                b.total_rows, b.total_columns, b.area_sqm, b.remarks AS block_remarks,
                b.grid_config,
                c.contact_id AS owner_id, c.name AS owner_name, c.address AS owner_address, 
                c.barangay AS owner_barangay, c.phone_number AS owner_phone, c.email_address AS owner_email,
                c.deleted_at AS owner_deleted_at
            FROM blocks b
            LEFT JOIN contacts c ON b.owner_contact_id = c.contact_id
            WHERE b.block_id = :id AND b.deleted_at IS NULL LIMIT 1
        ");
        $stmt->execute([':id' => $resourceId]);
        $blockRaw = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$blockRaw) Response::error("Block not found", 404);

        // -- Construct Relational Block Object --
        $block = [
            'block_id'      => $blockRaw['block_id'],
            'block_name'    => $blockRaw['block_name'],
            'block_type'    => $blockRaw['block_type'],
            'floor_level'   => $blockRaw['floor_level'],
            'area_sqm'      => $blockRaw['area_sqm'],
            'total_rows'    => $blockRaw['total_rows'],
            'total_columns' => $blockRaw['total_columns'],
            'grid_config'   => json_decode($blockRaw['grid_config'] ?? "{}", true),
            'coordinates'   => json_decode($blockRaw['coordinates'] ?? "{}", true),
            'remarks'       => $isAuthorizedStaff ? $blockRaw['block_remarks'] : null,
            'owner'         => null,
            'graves'        => []
        ];

        // -- Construct Relational Owner Object --
        if ($isAuthorizedStaff && !empty($blockRaw['owner_name'])) {
            $block['owner'] = [
                'contact_id'   => $blockRaw['owner_id'],
                'name'         => $blockRaw['owner_name'],
                'address'      => $blockRaw['owner_address'],
                'barangay'     => $blockRaw['owner_barangay'],
                'phone_number' => $blockRaw['owner_phone'],
                'email'        => $blockRaw['owner_email'],
                'is_archived'  => $blockRaw['owner_deleted_at'] !== null
            ];
        }

        // 2. Fetch grave slots first
        $graveStmt = $pdo->prepare("
            SELECT grave_id, grave_code, row_num, col_num, status AS grave_status, remarks AS grave_remarks
            FROM graves
            WHERE block_id = :id AND deleted_at IS NULL
            ORDER BY row_num ASC, col_num ASC
        ");
        $graveStmt->execute([':id' => $resourceId]);
        $graveRows = $graveStmt->fetchAll(PDO::FETCH_ASSOC);

        $graveInterments = [];
        $graveReservations = [];

        if ($isAuthorizedStaff && count($graveRows) > 0) {
            
            $intermentStmt = $pdo->prepare("
                SELECT
                    i.grave_id, i.interment_id, i.control_number, i.date_buried, 
                    i.lease_expiration_date, i.remarks AS interment_remarks,
                    d.deceased_id AS i_deceased_id, d.name AS i_deceased_name, d.sex AS i_deceased_sex, d.deleted_at AS i_deceased_deleted,
                    c.contact_id AS i_contact_id, c.name AS i_contact_name, c.phone_number AS i_contact_phone, c.deleted_at AS i_contact_deleted
                FROM interments i
                INNER JOIN graves g ON i.grave_id = g.grave_id
                LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
                LEFT JOIN contacts c ON i.contact_id = c.contact_id
                WHERE g.block_id = :id 
                AND g.deleted_at IS NULL
                AND i.deleted_at IS NULL
                AND i.status IN ('Active', 'Expired')
            ");
            $intermentStmt->execute([':id' => $resourceId]);
            foreach ($intermentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $graveInterments[$row['grave_id']][] = [
                    'interment_id'          => (int)$row['interment_id'],
                    'control_number'        => $row['control_number'],
                    'date_buried'           => $row['date_buried'],
                    'lease_expiration_date' => $row['lease_expiration_date'],
                    'remarks'               => $row['interment_remarks'],
                    'deceased' => [
                        'deceased_id' => (int)$row['i_deceased_id'],
                        'name'        => $row['i_deceased_name'],
                        'sex'         => $row['i_deceased_sex'],
                        'is_archived' => $row['i_deceased_deleted'] !== null
                    ],
                    'contact' => [
                        'contact_id'   => (int)$row['i_contact_id'],
                        'name'         => $row['i_contact_name'],
                        'phone_number' => $row['i_contact_phone'],
                        'is_archived'  => $row['i_contact_deleted'] !== null
                    ]
                ];
            }

            $reservationStmt = $pdo->prepare("
                SELECT
                    r.grave_id, r.reservation_id, r.expiration_date AS reservation_expiration, r.remarks AS reservation_remarks,
                    rc.contact_id AS r_contact_id, rc.name AS r_contact_name, rc.phone_number AS r_contact_phone, rc.deleted_at AS r_contact_deleted,
                    rd.deceased_id AS r_deceased_id, rd.name AS r_deceased_name, rd.deleted_at AS r_deceased_deleted
                FROM reservations r
                INNER JOIN graves g ON r.grave_id = g.grave_id
                LEFT JOIN contacts rc ON r.contact_id = rc.contact_id
                LEFT JOIN deceased rd ON r.deceased_id = rd.deceased_id
                WHERE g.block_id = :id 
                AND g.deleted_at IS NULL
                AND r.deleted_at IS NULL
                AND r.status = 'Active'
            ");
            $reservationStmt->execute([':id' => $resourceId]);
            foreach ($reservationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $graveReservations[$row['grave_id']][] = [
                    'reservation_id'  => (int)$row['reservation_id'],
                    'expiration_date' => $row['reservation_expiration'],
                    'remarks'         => $row['reservation_remarks'],
                    'reserver' => [
                        'contact_id'   => (int)$row['r_contact_id'],
                        'name'         => $row['r_contact_name'],
                        'phone_number' => $row['r_contact_phone'],
                        'is_archived'  => $row['r_contact_deleted'] !== null
                    ],
                    'reserved_for_deceased' => [
                        'deceased_id' => (int)$row['r_deceased_id'],
                        'name'        => $row['r_deceased_name'],
                        'is_archived' => $row['r_deceased_deleted'] !== null
                    ]
                ];
            }
        }

        // 3. Map it all together cleanly
        $gravesMap = [];
        foreach ($graveRows as $row) {
            $gId = (int)$row['grave_id'];
            $gravesMap[] = [
                'grave_id'     => $gId,
                'grave_code'   => $row['grave_code'],
                'status'       => $row['grave_status'],
                'row_num'      => (int)$row['row_num'],
                'col_num'      => (int)$row['col_num'],
                'remarks'      => $isAuthorizedStaff ? $row['grave_remarks'] : null,
                'interments'   => $isAuthorizedStaff ? ($graveInterments[$gId] ?? []) : [],
                'reservations' => $isAuthorizedStaff ? ($graveReservations[$gId] ?? []) : []
            ];
        }

        $block['graves'] = $gravesMap;
        Response::success("Block retrieved successfully", ["block" => $block]);
    }
    
    // SCENARIO B: Fetching all blocks overview (Grouped by Floor)
    $floorFilter = $_GET['floor_level'] ?? null; 
    $params = [];
    
    $sql = "
        SELECT 
            b.block_id, b.block_name, b.block_type, b.floor_level, 
            b.total_rows, b.total_columns, b.area_sqm, b.remarks AS block_remarks,
            c.contact_id AS owner_id, c.name AS owner_name, c.phone_number AS owner_phone, c.remarks AS contact_remarks, c.deleted_at AS owner_deleted_at,
            (SELECT COUNT(*) FROM graves g WHERE g.block_id = b.block_id AND g.deleted_at IS NULL) AS total_actual_graves,
            (SELECT COUNT(*) FROM graves g WHERE g.block_id = b.block_id AND g.status = 'Vacant' AND g.deleted_at IS NULL) AS vacant_graves
        FROM blocks b
        LEFT JOIN contacts c ON b.owner_contact_id = c.contact_id
        WHERE b.deleted_at IS NULL
    ";

    if (is_numeric($floorFilter)) {
        $sql .= " AND b.floor_level = ?";
        $params[] = (int)$floorFilter;
    }

    $sql .= " ORDER BY b.floor_level ASC, b.block_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groupedFloors = [];
    foreach ($blocks as $b) {
        $cleanBlock = [
            'block_id'   => $b['block_id'],
            'block_name' => $b['block_name'],
            'block_type' => $b['block_type'],
            'capacities' => [
                'total_graves'  => $b['total_actual_graves'],
                'vacant_graves' => $b['vacant_graves']
            ],
            'remarks' => $isAuthorizedStaff ? $b['block_remarks'] : null,
            'owner'   => null
        ];

        if ($isAuthorizedStaff && !empty($b['owner_name'])) {
            $cleanBlock['owner'] = [
                'contact_id'   => $b['owner_id'],
                'name'         => $b['owner_name'],
                'phone_number' => $b['owner_phone'],
                'remarks'      => $b['contact_remarks'],
                'is_archived'  => $b['owner_deleted_at'] !== null
            ];
        }

        $groupedFloors[$b['floor_level']][] = $cleanBlock;
    }

    Response::success("Blocks retrieved successfully", ["floors" => $groupedFloors]);
}

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

// ==========================================
// CONTACT RESOLVER HELPER
// ==========================================
// Safely resolves or creates a contact inside an active database transaction.
$resolveContact = function($ownerData, $pdo, $userId) {
    if (!is_array($ownerData) || empty($ownerData)) return null;

    // 1. Check by ID if provided
    if (!empty($ownerData['contact_id'])) {
        $stmt = $pdo->prepare("SELECT contact_id FROM contacts WHERE contact_id = ? AND deleted_at IS NULL");
        $stmt->execute([$ownerData['contact_id']]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new Exception("The provided contact_id does not exist or was deleted.", 400);
        }
        return $id;
    }

    $name = trim($ownerData['name'] ?? '');
    $address = trim($ownerData['address'] ?? '');
    $barangay = trim($ownerData['barangay'] ?? '');
    $phone = trim($ownerData['phone_number'] ?? '');
    $email = trim($ownerData['email_address'] ?? '');
    $remarks_contact = trim($ownerData['remarks'] ?? '');

    if (empty($name)) {
        throw new Exception("Contact name is required when creating a new owner.", 400);
    }

    // 2. Strict Deduplication Match
    // IFNULL is used to safely match empty strings with NULL database values
    $stmt = $pdo->prepare("
        SELECT contact_id FROM contacts 
        WHERE name = ? 
          AND IFNULL(address, '') = ? 
          AND IFNULL(barangay, '') = ? 
          AND IFNULL(phone_number, '') = ? 
          AND IFNULL(email_address, '') = ? 
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$name, $address, $barangay, $phone, $email]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        return $existingId;
    }

    // 3. Create New Contact
    $insertStmt = $pdo->prepare("
        INSERT INTO contacts (name, address, barangay, phone_number, email_address, created_by, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([$name, $address, $barangay, $phone, $email, $userId, $remarks_contact]);
    return $pdo->lastInsertId();
};

// ==========================================
// POST: CREATE BLOCK & AUTO-GENERATE GRAVES
// ==========================================
if ($method === 'POST') {
    
    if (empty($rawData['block_name']) || empty($rawData['block_type']) || empty($rawData['total_rows']) || empty($rawData['total_columns'])) {
        Response::error("Missing required fields", 400);
    }

    $rows = (int)$rawData['total_rows'];
    $cols = (int)$rawData['total_columns'];
    $floorLevel = (int)($rawData['floor_level'] ?? 1);
    $blockRemarks = isset($rawData['remarks']) ? trim($rawData['remarks']) : null;
    
    $gridConfig = $rawData['grid_config'] ?? [
        'row_format' => 'numeric', 
        'col_format' => 'numeric', 
        'prefix' => strtoupper(substr(trim($rawData['block_name']), 0, 3))
    ];
    
    if ($rows < 1 || $cols < 1 || $rows > 500 || $cols > 500) {
        Response::error("Grid dimensions must be between 1 and 500", 400);
    }

    $toAlpha = function($num) {
        $letter = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $num = (int)(($num - $mod) / 26);
        }
        return $letter;
    };

    try {
        $pdo->beginTransaction();

        // Resolve Contact nested inside the transaction
        $ownerContactId = null;
        if (!empty($rawData['owner'])) {
            $ownerContactId = $resolveContact($rawData['owner'], $pdo, $userData['user_id']);
        }

        // 1. Insert Block
        $stmt = $pdo->prepare("INSERT INTO blocks (block_name, block_type, floor_level, total_rows, total_columns, grid_config, owner_contact_id, created_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($rawData['block_name']), 
            trim($rawData['block_type']), 
            $floorLevel, $rows, $cols, 
            json_encode($gridConfig),
            $ownerContactId,
            $userData['user_id'],
            $blockRemarks
        ]);
        
        $blockId = $pdo->lastInsertId();

        // 2. Generate Graves
        $stmtGrave = $pdo->prepare("INSERT INTO graves (block_id, grave_code, row_num, col_num, status, created_by) VALUES (?, ?, ?, ?, 'Vacant', ?)");
        
        $rowFormat = $gridConfig['row_format'] ?? 'numeric';
        $colFormat = $gridConfig['col_format'] ?? 'numeric';
        $prefix = $gridConfig['prefix'] ?? '';

        for ($r = 1; $r <= $rows; $r++) {
            for ($c = 1; $c <= $cols; $c++) {
                $rStr = ($rowFormat === 'alpha') ? $toAlpha($r) : $r;
                $cStr = ($colFormat === 'alpha') ? $toAlpha($c) : $c;
                $graveCode = !empty($prefix) ? "{$prefix}-{$rStr}-{$cStr}" : "{$rStr}-{$cStr}";

                $stmtGrave->execute([$blockId, $graveCode, $r, $c, $userData['user_id']]);
            }
        }

        $pdo->commit();
        systemLog($userData['name'] . " created block: " . $rawData['block_name'], $userData['user_id']);
        Response::success("Block and " . ($rows * $cols) . " graves generated successfully", ["block_id" => $blockId], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if ($code == 400) Response::error($e->getMessage(), 400);
        Response::error("Database error or missing data.", 500);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) Response::error("Block name already exists.", 409);
        Response::error("Database error while creating block.", 500);
    }
}

// ==========================================
// PUT: EDIT BLOCK (Delta Check & Resizing)
// ==========================================
if ($method === 'PUT') {
    if (!is_numeric($resourceId)) Response::error("Block ID required", 400);

    $stmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = ? AND deleted_at IS NULL");
    $stmt->execute([$resourceId]);
    $oldBlock = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldBlock) Response::error("Block not found", 404);

    $newRows = (int)($rawData['total_rows'] ?? $oldBlock['total_rows']);
    $newCols = (int)($rawData['total_columns'] ?? $oldBlock['total_columns']);
    $newName = trim($rawData['block_name'] ?? $oldBlock['block_name']);
    $newType = trim($rawData['block_type'] ?? $oldBlock['block_type']);
    $newFloor = (int)($rawData['floor_level'] ?? $oldBlock['floor_level']);
    $newRemarks = array_key_exists('remarks', $rawData) ? trim($rawData['remarks']) : $oldBlock['remarks'];
    
    $oldConfig = json_decode($oldBlock['grid_config'], true) ?: [];
    $newConfig = $rawData['grid_config'] ?? $oldConfig;

    $toAlpha = function($num) {
        $letter = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $num = (int)(($num - $mod) / 26);
        }
        return $letter;
    };

    try {
        $pdo->beginTransaction();

        // Resolve new owner first to accurately check for changes
        $newOwnerId = $oldBlock['owner_contact_id'];
        if (array_key_exists('owner', $rawData)) {
            $newOwnerId = empty($rawData['owner']) ? null : $resolveContact($rawData['owner'], $pdo, $userData['user_id']);
        }

        // Check for changes to prevent empty requests
        $changes = [];
        if ($newName !== $oldBlock['block_name']) $changes[] = "Name";
        if ($newType !== $oldBlock['block_type']) $changes[] = "Type";
        if ($newFloor !== (int)$oldBlock['floor_level']) $changes[] = "Floor level";
        if ($newRows !== (int)$oldBlock['total_rows'] || $newCols !== (int)$oldBlock['total_columns']) $changes[] = "Grid size";
        if (json_encode($newConfig) !== json_encode($oldConfig)) $changes[] = "Grid naming config";
        if ($newOwnerId !== $oldBlock['owner_contact_id']) $changes[] = "Owner";
        if ($newRemarks !== $oldBlock['remarks']) $changes[] = "Remarks";

        if (empty($changes)) {
            $pdo->rollBack();
            Response::error("No changes detected.", 400);
        }

        // SCENARIO 1: Shrinking Grid
        if ($newRows < $oldBlock['total_rows'] || $newCols < $oldBlock['total_columns']) {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM graves 
                WHERE block_id = ? AND (row_num > ? OR col_num > ?) AND status != 'Vacant' AND deleted_at IS NULL
            ");
            $checkStmt->execute([$resourceId, $newRows, $newCols]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                Response::error("Cannot shrink block: Occupied or reserved graves exist outside new dimensions.", 409);
            }

            $delStmt = $pdo->prepare("
                UPDATE graves SET deleted_at = NOW(), updated_by = ? 
                WHERE block_id = ? AND (row_num > ? OR col_num > ?) AND deleted_at IS NULL
            ");
            $delStmt->execute([$userData['user_id'], $resourceId, $newRows, $newCols]);
        }

        // SCENARIO 2: Expanding Grid
        if ($newRows > $oldBlock['total_rows'] || $newCols > $oldBlock['total_columns']) {
            $stmtGrave = $pdo->prepare("INSERT INTO graves (block_id, grave_code, row_num, col_num, status, created_by) VALUES (?, ?, ?, ?, 'Vacant', ?)");
            for ($r = 1; $r <= $newRows; $r++) {
                for ($c = 1; $c <= $newCols; $c++) {
                    if ($r > $oldBlock['total_rows'] || $c > $oldBlock['total_columns']) {
                        $rStr = ($newConfig['row_format'] === 'alpha') ? $toAlpha($r) : $r;
                        $cStr = ($newConfig['col_format'] === 'alpha') ? $toAlpha($c) : $c;
                        $prefix = $newConfig['prefix'] ?? '';
                        $graveCode = !empty($prefix) ? "{$prefix}-{$rStr}-{$cStr}" : "{$rStr}-{$cStr}";

                        $stmtGrave->execute([$resourceId, $graveCode, $r, $c, $userData['user_id']]);
                    }
                }
            }
        }

        // SCENARIO 3: Rename Active Grave Codes if Config Changed
        if ($newName !== $oldBlock['block_name'] || json_encode($newConfig) !== json_encode($oldConfig)) {
            $gravesStmt = $pdo->prepare("SELECT grave_id, row_num, col_num FROM graves WHERE block_id = ? AND deleted_at IS NULL");
            $gravesStmt->execute([$resourceId]);
            $existingGraves = $gravesStmt->fetchAll(PDO::FETCH_ASSOC);

            $updateGraveStmt = $pdo->prepare("UPDATE graves SET grave_code = ?, updated_by = ?, updated_at = NOW() WHERE grave_id = ?");

            foreach ($existingGraves as $g) {
                $rStr = ($newConfig['row_format'] === 'alpha') ? $toAlpha($g['row_num']) : $g['row_num'];
                $cStr = ($newConfig['col_format'] === 'alpha') ? $toAlpha($g['col_num']) : $g['col_num'];
                $prefix = $newConfig['prefix'] ?? '';
                $newCode = !empty($prefix) ? "{$prefix}-{$rStr}-{$cStr}" : "{$rStr}-{$cStr}";

                $updateGraveStmt->execute([$newCode, $userData['user_id'], $g['grave_id']]);
            }
        }

        // Update Block Record
        $updateStmt = $pdo->prepare("
            UPDATE blocks 
            SET block_name = ?, block_type = ?, floor_level = ?, total_rows = ?, total_columns = ?, grid_config = ?, owner_contact_id = ?, updated_by = ?, updated_at = NOW(), remarks = ? 
            WHERE block_id = ?
        ");
        $updateStmt->execute([$newName, $newType, $newFloor, $newRows, $newCols, json_encode($newConfig), $newOwnerId, $userData['user_id'], $newRemarks, $resourceId]);

        $pdo->commit();
        systemLog($userData['name'] . " updated block ID: " . $resourceId, $userData['user_id']);
        Response::success("Block updated successfully.", ["updated_fields" => $changes]);

    } catch (Exception $e) {
        $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if ($code == 400) Response::error($e->getMessage(), 400);
        Response::error("Database error or missing data.", 500);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) Response::error("Conflict: Generated grave code already exists.", 409);
        Response::error("Database error while updating block.", 500);
    }
}

// ==========================================
// DELETE: REMOVE BLOCK
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Block ID required", 400);

    // Cross-check for existing dependencies (Interments / Reservations)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT g.grave_id) 
        FROM graves g
        LEFT JOIN interments i ON g.grave_id = i.grave_id AND i.deleted_at IS NULL AND i.status IN ('Active', 'Expired')
        LEFT JOIN reservations r ON g.grave_id = r.grave_id AND r.deleted_at IS NULL AND r.status = 'Active'
        WHERE g.block_id = ? 
        AND g.deleted_at IS NULL
        AND (g.status != 'Vacant' OR i.interment_id IS NOT NULL OR r.reservation_id IS NOT NULL)
    ");
    $stmt->execute([$resourceId]);
    
    if ($stmt->fetchColumn() > 0) {
        Response::error("Conflict: Cannot delete a block containing active bodies, reservations, or linked paperwork.", 409);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE graves SET deleted_at = NOW(), updated_by = ? WHERE block_id = ? AND deleted_at IS NULL");
        $stmt->execute([$userData['user_id'], $resourceId]);

        $stmt = $pdo->prepare("UPDATE blocks SET deleted_at = NOW(), updated_by = ? WHERE block_id = ?");
        $stmt->execute([$userData['user_id'], $resourceId]);

        $pdo->commit();
        systemLog($userData['name'] . " deleted block ID: " . $resourceId, $userData['user_id']);
        Response::success("Block and vacant graves successfully deleted.");

    } catch (PDOException $e) {
        $pdo->rollBack();
        Response::error("Database error while deleting block.", 500);
    }
}

Response::error("Method not allowed", 405);
?>