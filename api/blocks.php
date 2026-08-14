<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

$userData = checkuser(false); // Check if user is logged in, but don't force exit

// ---------------------------------------------------------
// 1. STRICT GATEKEEPER FOR MODIFICATIONS
// Only Admin and Office Staff can create, edit, or delete blocks.
// ---------------------------------------------------------

$allowedRoles = ['Administrator', 'Office Staff', 'Grounds Staff'];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!isset($userData['role']) || !in_array($userData['role'], $allowedRoles)) {
        Response::error("Forbidden. You do not have permission to modify blocks.", 403);
    }
}

// --- REST ROUTING: PARSE THE URI ---
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts); // Gets the Block ID if provided

// --- HYBRID INPUT PARSER ---
$formData = $_POST ?? [];
$jsonStream = file_get_contents("php://input");
$jsonData = json_decode($jsonStream, true) ?: []; 
$rawData = array_merge($jsonData, $formData);

// ---------------------------------------------------------
// 2. SMART PRIVACY CHECKER FOR VIEWING
// ---------------------------------------------------------
// If they are Admin or Office Staff, they get to see names and dates.
// If they are a guest, unverified, or Grounds Staff, they ONLY see the map status.
// Determine clearance level for sensitive data
$isAuthorizedStaff = (isset($userData['role']) && in_array($userData['role'], $allowedRoles));

// ==========================================
// 3. GET: RETRIEVE BLOCKS 
// ==========================================
if ($method === 'GET') {
    
    
    // SCENARIO A: Fetching a single Block and its Grid
    if (is_numeric($resourceId)) {
        
        // 1. Fetch the Block and its Owner (if Private/Mausoleum)
        $stmt = $pdo->prepare("
            SELECT 
                b.block_id, b.block_name, b.coordinates, b.block_type, b.floor_level, 
                b.total_rows, b.total_columns, b.area_sqm, b.remarks AS block_remarks,
                c.name AS owner_name, c.address AS owner_address, c.barangay AS owner_barangay, 
                c.phone_number AS owner_phone, c.email_address AS owner_email, c.remarks AS owner_remarks
            FROM blocks b
            LEFT JOIN contacts c ON b.owner_contact_id = c.contact_id
            WHERE b.block_id = :id AND b.deleted_at IS NULL LIMIT 1
        ");
        $stmt->execute([':id' => $resourceId]);
        $block = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$block) Response::error("Block not found", 404);

        // -----------------------------------------------------
        // Data Formatting & Scrubbing (Standardizing the JSON)
        // -----------------------------------------------------
        if ($isAuthorizedStaff && !empty($block['owner_name'])) {
            // Group the flat owner columns into a clean nested object
            $block['owner_details'] = [
                'name'         => $block['owner_name'],
                'address'      => $block['owner_address'],
                'barangay'     => $block['owner_barangay'],
                'phone_number' => $block['owner_phone'],
                'email'        => $block['owner_email'],
                'remarks'      => $block['owner_remarks']
            ];
        }

        // Always delete the messy flat columns from the root JSON object
        unset($block['owner_name'], $block['owner_address'], $block['owner_barangay'], $block['owner_phone'], $block['owner_email'], $block['owner_remarks']);

        // Scrub remarks if unauthorized
        if (!$isAuthorizedStaff) {
            unset($block['block_remarks']);
        }

        // 2. Fetch the Graves, Bodies, Interment Contacts, AND Reservations
        $stmt = $pdo->prepare("
            SELECT 
                g.grave_id, g.grave_code, g.row_num, g.col_num, g.status AS grave_status, g.remarks AS grave_remarks,
                i.interment_id, i.control_number, i.date_buried, i.lease_expiration_date,
                d.name AS deceased_name,
                c.name AS contact_name, c.address AS contact_address, 
                c.barangay AS contact_barangay, c.phone_number AS contact_phone, c.email_address AS contact_email, c.remarks AS contact_remarks,
                r.reservation_id, r.expiration_date AS reservation_expiration, r.remarks AS reservation_remarks,
                rc.name AS reserver_name, rc.phone_number AS reserver_phone
            FROM graves g
            LEFT JOIN interments i ON g.grave_id = i.grave_id AND i.status IN ('Active', 'Expired') AND i.deleted_at IS NULL
            LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
            LEFT JOIN contacts c ON i.contact_id = c.contact_id
            LEFT JOIN reservations r ON g.grave_id = r.grave_id AND r.status = 'Active' AND r.deleted_at IS NULL
            LEFT JOIN contacts rc ON r.contact_id = rc.contact_id
            WHERE g.block_id = :id AND g.deleted_at IS NULL
            ORDER BY g.row_num ASC, g.col_num ASC
        ");
        $stmt->execute([':id' => $resourceId]);
        $rawGraves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $gravesMap = [];
        foreach ($rawGraves as $row) {
            $gId = $row['grave_id'];
            
            // Core visual mapping data
            if (!isset($gravesMap[$gId])) {
                $gravesMap[$gId] = [
                    'grave_code'  => $row['grave_code'],
                    'status'      => $row['grave_status'],
                    'row_num'     => $row['row_num'],
                    'col_num'     => $row['col_num']
                ];
                
                if ($isAuthorizedStaff) {
                    $gravesMap[$gId]['remarks'] = $row['grave_remarks'];
                    $gravesMap[$gId]['occupants'] = [];
                    $gravesMap[$gId]['reservation'] = null; // Default to null if no active reservation
                }
            }
            
            // Occupants list (Appends multiple bodies for Bone Chambers)
            if ($isAuthorizedStaff && !empty($row['interment_id'])) {
                // Prevent duplicate bodies if a join multiplies the rows
                $bodyExists = false;
                foreach ($gravesMap[$gId]['occupants'] as $occ) {
                    if ($occ['interment_id'] === $row['interment_id']) $bodyExists = true;
                }
                
                if (!$bodyExists) {
                    $gravesMap[$gId]['occupants'][] = [
                        'interment_id'          => $row['interment_id'],
                        'deceased_name'         => $row['deceased_name'],
                        'control_number'        => $row['control_number'],
                        'date_buried'           => $row['date_buried'],
                        'lease_expiration_date' => $row['lease_expiration_date'],
                        'contact_details'       => [
                            'name'         => $row['contact_name'],
                            'address'      => $row['contact_address'],
                            'barangay'     => $row['contact_barangay'],
                            'phone_number' => $row['contact_phone'],
                            'email'        => $row['contact_email'],
                            'remarks'      => $row['contact_remarks']
                        ]
                    ];
                }
            }

            // Reservation details (Only attaches if a reservation exists)
            if ($isAuthorizedStaff && !empty($row['reservation_id']) && $gravesMap[$gId]['reservation'] === null) {
                $gravesMap[$gId]['reservation'] = [
                    'reservation_id'  => $row['reservation_id'],
                    'reserver_name'   => $row['reserver_name'],
                    'reserver_phone'  => $row['reserver_phone'],
                    'expiration_date' => $row['reservation_expiration'],
                    'remarks'         => $row['reservation_remarks']
                ];
            }
        }

        $block['graves'] = array_values($gravesMap);
        Response::success("Block retrieved successfully", $block);
    } 
    
   // SCENARIO B: Fetching all blocks (Grouped by Floor)
    $floorFilter = $_GET['floor_level'] ?? 1; // Defaults to 1 to limit resources
    $params = [];
    
    $sql = "
        SELECT 
            b.block_id, b.block_name, b.block_type, b.floor_level, 
            b.total_rows, b.total_columns, b.area_sqm, b.remarks AS block_remarks,
            c.name AS owner_name, c.address AS owner_address, c.barangay AS owner_barangay, 
            c.phone_number AS owner_phone, c.email_address AS owner_email, c.remarks AS owner_remarks,
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

    // Group by floor and apply strict nested structuring
    $groupedFloors = [];
    foreach ($blocks as $b) {
        
        if ($isAuthorizedStaff && !empty($b['owner_name'])) {
            $b['owner_details'] = [
                'name'         => $b['owner_name'],
                'address'      => $b['owner_address'],
                'barangay'     => $b['owner_barangay'],
                'phone_number' => $b['owner_phone'],
                'email'        => $b['owner_email'],
                'remarks'      => $b['owner_remarks']
            ];
        }
        
        // Clean up ALL the flat keys (Added owner_remarks here!)
        unset($b['owner_name'], $b['owner_address'], $b['owner_barangay'], $b['owner_phone'], $b['owner_email'], $b['owner_remarks']);
        
        if (!$isAuthorizedStaff) {
            unset($b['block_remarks']);
        }
        
        $groupedFloors[$b['floor_level']][] = $b;
    }

    Response::success("Blocks retrieved successfully", ["floors" => $groupedFloors]);
}

// ==========================================
// 2. POST: CREATE BLOCK & AUTO-GENERATE GRAVES
// ==========================================
if ($method === 'POST') {
    
    if (empty($rawData['block_name']) || empty($rawData['block_type']) || empty($rawData['total_rows']) || empty($rawData['total_columns'])) {
        Response::error("Missing required fields", 400);
    }

    $rows = (int)$rawData['total_rows'];
    $cols = (int)$rawData['total_columns'];
    $floorLevel = isset($rawData['floor_level']) ? (int)$rawData['floor_level'] : 1;
    
    // Parse the grid config (Defaulting to numeric if the frontend forgets it)
    $gridConfig = isset($rawData['grid_config']) && is_array($rawData['grid_config']) 
        ? $rawData['grid_config'] 
        : ['row_format' => 'numeric', 'col_format' => 'numeric', 'prefix' => strtoupper(substr(trim($rawData['block_name']), 0, 3))];
    
    $jsonGridConfig = json_encode($gridConfig);
    
    if ($rows < 1 || $cols < 1 || $rows > 100 || $cols > 100) {
        Response::error("Currently, grid dimensions must be between 1 and 100", 400);
    }

    // --- HELPER FUNCTION: Turns 1 into A, 2 into B, 27 into AA ---
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

        // 1. Insert the Block
        $stmt = $pdo->prepare("INSERT INTO blocks (block_name, block_type, floor_level, total_rows, total_columns, grid_config, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($rawData['block_name']), 
            trim($rawData['block_type']), 
            $floorLevel,
            $rows, 
            $cols, 
            $jsonGridConfig,
            $userData['user_id']
        ]);
        
        $blockId = $pdo->lastInsertId();

        // 2. Auto-Generate the Graves Grid based on grid_config rules
        $stmtGrave = $pdo->prepare("INSERT INTO graves (block_id, grave_code, row_num, col_num, status, created_by) VALUES (?, ?, ?, ?, 'Vacant', ?)");
        
        $rowFormat = $gridConfig['row_format'] ?? 'numeric';
        $colFormat = $gridConfig['col_format'] ?? 'numeric';
        $prefix = $gridConfig['prefix'] ?? '';

        for ($r = 1; $r <= $rows; $r++) {
            for ($c = 1; $c <= $cols; $c++) {
                
                // Format the Row and Column
                $rStr = ($rowFormat === 'alpha') ? $toAlpha($r) : $r;
                $cStr = ($colFormat === 'alpha') ? $toAlpha($c) : $c;
                
                // Construct the final code (e.g., NIC-A-1 or A-1 if no prefix)
                if (!empty($prefix)) {
                    $graveCode = "{$prefix}-{$rStr}-{$cStr}";
                } else {
                    $graveCode = "{$rStr}-{$cStr}";
                }

                $stmtGrave->execute([$blockId, $graveCode, $r, $c, $userData['user_id']]);
            }
        }

        $pdo->commit();
        systemLog($userData['name'] . " created a block: " . $rawData['block_name'], $userData['user_id']);
        
        $fetchStmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = ?");
        $fetchStmt->execute([$blockId]);
        $newBlock = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode the config so the JSON response looks clean
        $newBlock['grid_config'] = json_decode($newBlock['grid_config'], true);
        
        // Return the calculated capacities dynamically
        $newBlock['total_actual_graves'] = ($rows * $cols);
        $newBlock['vacant_graves'] = ($rows * $cols);

        Response::success(
            "Block and " . ($rows * $cols) . " graves generated successfully", 
            ["block" => $newBlock], 
            201
        );

    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) {
            systemLog($userData['name'] . " failed to create block: " . $e->getMessage() . " Block name already exists.", $userData['user_id']);
            Response::error("Block name already exists.", 409);
        }
        systemLog($userData['name'] . " failed to create block: " . $e->getMessage(), $userData['user_id']);
        Response::error("Database error while creating block.", 500);
    }
}

// ==========================================
// 3. PUT: EDIT BLOCK (Smart Delta & Grid Config)
// ==========================================
if ($method === 'PUT') {
    if (!is_numeric($resourceId)) Response::error("Block ID required", 400);

    $stmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = ? AND deleted_at IS NULL");
    $stmt->execute([$resourceId]);
    $oldBlock = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldBlock) Response::error("Block not found", 404);

    $newRows = isset($rawData['total_rows']) ? (int)$rawData['total_rows'] : (int)$oldBlock['total_rows'];
    $newCols = isset($rawData['total_columns']) ? (int)$rawData['total_columns'] : (int)$oldBlock['total_columns'];
    $newName = isset($rawData['block_name']) ? trim($rawData['block_name']) : $oldBlock['block_name'];
    $newType = isset($rawData['block_type']) ? trim($rawData['block_type']) : $oldBlock['block_type'];
    $newFloor = isset($rawData['floor_level']) ? (int)$rawData['floor_level'] : (int)$oldBlock['floor_level'];
    
    // Parse old and new grid configs
    $oldConfig = json_decode($oldBlock['grid_config'], true) ?: [];
    $newConfig = isset($rawData['grid_config']) && is_array($rawData['grid_config']) 
        ? $rawData['grid_config'] 
        : $oldConfig;
    
    // SMART DELTA CHECKER
    $changes = [];
    if ($newName !== $oldBlock['block_name']) $changes[] = "Name changed to '{$newName}'";
    if ($newType !== $oldBlock['block_type']) $changes[] = "Type changed to '{$newType}'";
    if ($newFloor !== (int)$oldBlock['floor_level']) $changes[] = "Floor level changed to {$newFloor}";
    if ($newRows !== (int)$oldBlock['total_rows'] || $newCols !== (int)$oldBlock['total_columns']) {
        $changes[] = "Grid resized to {$newRows}x{$newCols}";
    }
    if (json_encode($newConfig) !== json_encode($oldConfig)) {
        $changes[] = "Grid naming configuration updated";
    }

    if (empty($changes)) {
        Response::error("Bad Request: No changes were detected. The submitted data exactly matches the current block.", 400);
    }

    // --- HELPER FUNCTION: Turns 1 into A, 2 into B ---
    $toAlpha = function($num) {
        $letter = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $num = (int)(($num - $mod) / 26);
        }
        return $letter;
    };

    $rowFormat = $newConfig['row_format'] ?? 'numeric';
    $colFormat = $newConfig['col_format'] ?? 'numeric';
    $prefix = $newConfig['prefix'] ?? '';

    try {
        $pdo->beginTransaction();

        // SCENARIO 1: Shrinking the Grid
        if ($newRows < $oldBlock['total_rows'] || $newCols < $oldBlock['total_columns']) {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM graves 
                WHERE block_id = ? AND (row_num > ? OR col_num > ?) AND status != 'Vacant' AND deleted_at IS NULL
            ");
            $checkStmt->execute([$resourceId, $newRows, $newCols]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                Response::error("Cannot shrink block: There are occupied or reserved graves outside the new dimensions.", 409);
            }

            $delStmt = $pdo->prepare("
                UPDATE graves SET deleted_at = NOW(), updated_by = ? 
                WHERE block_id = ? AND (row_num > ? OR col_num > ?) AND deleted_at IS NULL
            ");
            $delStmt->execute([$userData['user_id'], $resourceId, $newRows, $newCols]);
        }

        // SCENARIO 2: Expanding the Grid
        if ($newRows > $oldBlock['total_rows'] || $newCols > $oldBlock['total_columns']) {
            $stmtGrave = $pdo->prepare("INSERT INTO graves (block_id, grave_code, row_num, col_num, status, created_by) VALUES (?, ?, ?, ?, 'Vacant', ?)");
            for ($r = 1; $r <= $newRows; $r++) {
                for ($c = 1; $c <= $newCols; $c++) {
                    if ($r > $oldBlock['total_rows'] || $c > $oldBlock['total_columns']) {
                        
                        $rStr = ($rowFormat === 'alpha') ? $toAlpha($r) : $r;
                        $cStr = ($colFormat === 'alpha') ? $toAlpha($c) : $c;
                        $graveCode = !empty($prefix) ? "{$prefix}-{$rStr}-{$cStr}" : "{$rStr}-{$cStr}";

                        $stmtGrave->execute([$resourceId, $graveCode, $r, $c, $userData['user_id']]);
                    }
                }
            }
        }

        // SCENARIO 3: Mass Override (If config or name changed, rewrite all active grave codes)
        if ($newName !== $oldBlock['block_name'] || json_encode($newConfig) !== json_encode($oldConfig)) {
            
            // Fetch all non-deleted graves to rewrite their codes
            $gravesStmt = $pdo->prepare("SELECT grave_id, row_num, col_num FROM graves WHERE block_id = ? AND deleted_at IS NULL");
            $gravesStmt->execute([$resourceId]);
            $existingGraves = $gravesStmt->fetchAll(PDO::FETCH_ASSOC);

            $updateGraveStmt = $pdo->prepare("UPDATE graves SET grave_code = ?, updated_by = ?, updated_at = NOW() WHERE grave_id = ?");

            foreach ($existingGraves as $g) {
                $rStr = ($rowFormat === 'alpha') ? $toAlpha($g['row_num']) : $g['row_num'];
                $cStr = ($colFormat === 'alpha') ? $toAlpha($g['col_num']) : $g['col_num'];
                $newCode = !empty($prefix) ? "{$prefix}-{$rStr}-{$cStr}" : "{$rStr}-{$cStr}";

                $updateGraveStmt->execute([$newCode, $userData['user_id'], $g['grave_id']]);
            }
        }

        // 4. Update the Block Master Record
        $updateStmt = $pdo->prepare("
            UPDATE blocks 
            SET block_name = ?, block_type = ?, floor_level = ?, total_rows = ?, total_columns = ?, grid_config = ?, updated_by = ?, updated_at = NOW() 
            WHERE block_id = ?
        ");
        $updateStmt->execute([$newName, $newType, $newFloor, $newRows, $newCols, json_encode($newConfig), $userData['user_id'], $resourceId]);

        $pdo->commit();
        
        $fetchStmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = ?");
        $fetchStmt->execute([$resourceId]);
        $updatedBlock = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        $updatedBlock['grid_config'] = json_decode($updatedBlock['grid_config'], true);
        $updatedBlock['total_actual_graves'] = ($newRows * $newCols);
        
        $vacantStmt = $pdo->prepare("SELECT COUNT(*) FROM graves WHERE block_id = ? AND status = 'Vacant' AND deleted_at IS NULL");
        $vacantStmt->execute([$resourceId]);
        $updatedBlock['vacant_graves'] = (int)$vacantStmt->fetchColumn();

        $successMessage = "Block updated successfully. " . implode(". ", $changes) . ".";
        systemLog($userData['name'] . " updated block ID: " . $resourceId . ". Changes: " . implode("; ", $changes), $userData['user_id']);
        
        Response::success($successMessage, ["block" => $updatedBlock]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) { 
            systemLog($userData['name'] . " failed to update block ID: " . $resourceId . ". Error: Grave code conflict.", $userData['user_id']);
            Response::error("Conflict: A grave code generated by this update already exists elsewhere.", 409);
        }
        systemLog($userData['name'] . " failed to update block ID: " . $resourceId . ". Error: " . $e->getMessage(), $userData['user_id']);
        Response::error("Database error while updating block.", 500);
    }
}

// ==========================================
// 4. DELETE: REMOVE BLOCK
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Block ID required", 400);

    // 1. The Bulletproof Cross-Check
    // Checks the grave status, AND explicitly looks for rogue paperwork in interments or reservations
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
        Response::error("Conflict: Cannot delete a block that contains active bodies, reservations, pending exhumations, or linked paperwork.", 409);
    }

    try {
        $pdo->beginTransaction();

        // 2. Soft delete all graves inside the block
        $stmt = $pdo->prepare("UPDATE graves SET deleted_at = NOW(), updated_by = ? WHERE block_id = ? AND deleted_at IS NULL");
        $stmt->execute([$userData['user_id'], $resourceId]);

        // 3. Soft delete the block itself
        $stmt = $pdo->prepare("UPDATE blocks SET deleted_at = NOW(), updated_by = ? WHERE block_id = ?");
        $stmt->execute([$userData['user_id'], $resourceId]);

        $pdo->commit();
        systemLog($userData['name'] . " deleted block ID: " . $resourceId, $userData['user_id']);
        Response::success("Block and all associated vacant graves successfully deleted.");

    } catch (PDOException $e) {
        $pdo->rollBack();
        systemLog($userData['name'] . " failed to delete block ID: " . $resourceId . ". Error: " . $e->getMessage(), $userData['user_id']);
        Response::error("Database error while deleting block.", 500);
    }
}

Response::error("Method not allowed", 405);
?>