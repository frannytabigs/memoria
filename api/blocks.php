<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

$userData = checkuser();

$allowedRoles = ['Administrator', 'Office Staff'];
if (!in_array($userData['role'], $allowedRoles)) {
    Response::error("Forbidden. Invalid role.", 403);
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

// ==========================================
// 1. GET: RETRIEVE BLOCKS (Grouped or Filtered by Floor)
// ==========================================
if ($method === 'GET') {
    
    // Get a specific block and all its graves
    if (is_numeric($resourceId)) {
        $stmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $resourceId]);
        $block = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$block) Response::error("Block not found", 404);

        $stmt = $pdo->prepare("SELECT * FROM graves WHERE block_id = :id AND deleted_at IS NULL ORDER BY row_num, col_num");
        $stmt->execute([':id' => $resourceId]);
        $block['graves'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success("Block retrieved", $block);
    } 
    
    // Get all blocks with basic occupancy stats
    $floorFilter = $_GET['floor_level'] ?? 1; // Default to floor 1 if not specified to avoid returning all floors at once, which could be heavy on the frontend and backend.
    $params = [];
    
    $sql = "
        SELECT 
            b.*,
            (SELECT COUNT(*) FROM graves g WHERE g.block_id = b.block_id AND g.deleted_at IS NULL) AS total_actual_graves,
            (SELECT COUNT(*) FROM graves g WHERE g.block_id = b.block_id AND g.status = 'Vacant' AND g.deleted_at IS NULL) AS vacant_graves
        FROM blocks b
        WHERE b.deleted_at IS NULL
    ";

    // If the frontend asks for a specific floor, filter it in SQL
    if (is_numeric($floorFilter)) {
        $sql .= " AND b.floor_level = ?";
        $params[] = (int)$floorFilter;
    }

    $sql .= " ORDER BY b.floor_level ASC, b.block_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group the blocks perfectly by floor level for the frontend Map UI
    $groupedFloors = [];
    foreach ($blocks as $b) {
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
    
    if ($rows < 1 || $cols < 1 || $rows > 50 || $cols > 50) {
        Response::error("Grid dimensions must be between 1 and 50", 400);
    }

    try {
        $pdo->beginTransaction();

        // 1. Insert the Block
        $stmt = $pdo->prepare("INSERT INTO blocks (block_name, block_type, floor_level, total_rows, total_columns, max_capacity, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($rawData['block_name']), 
            trim($rawData['block_type']), 
            $floorLevel,
            $rows, 
            $cols, 
            ($rows * $cols), 
            $userData['user_id']
        ]);
        
        $blockId = $pdo->lastInsertId();

        // 2. Auto-Generate the Graves Grid
        $stmtGrave = $pdo->prepare("INSERT INTO graves (block_id, grave_code, row_num, col_num, status, created_by) VALUES (?, ?, ?, ?, 'Vacant', ?)");
        
        for ($r = 1; $r <= $rows; $r++) {
            for ($c = 1; $c <= $cols; $c++) {
                $graveCode = strtoupper(substr(trim($rawData['block_name']), 0, 3)) . "-" . $r . "-" . $c;
                $stmtGrave->execute([$blockId, $graveCode, $r, $c, $userData['user_id']]);
            }
        }

        $pdo->commit();
        systemLog($userData['name'] . " created a block: " . $rawData['block_name'], $userData['user_id']);
        
        $fetchStmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = ?");
        $fetchStmt->execute([$blockId]);
        $newBlock = $fetchStmt->fetch(PDO::FETCH_ASSOC);

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
// 3. PUT: EDIT BLOCK (Smart Delta Checking & Mass Override)
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
    
    // SMART DELTA CHECKER
    $changes = [];
    if ($newName !== $oldBlock['block_name']) $changes[] = "Name changed from '{$oldBlock['block_name']}' to '{$newName}'";
    if ($newType !== $oldBlock['block_type']) $changes[] = "Type changed from '{$oldBlock['block_type']}' to '{$newType}'";
    if ($newFloor !== (int)$oldBlock['floor_level']) $changes[] = "Floor level changed from {$oldBlock['floor_level']} to {$newFloor}";
    if ($newRows !== (int)$oldBlock['total_rows'] || $newCols !== (int)$oldBlock['total_columns']) {
        $changes[] = "Grid resized from {$oldBlock['total_rows']}x{$oldBlock['total_columns']} to {$newRows}x{$newCols}";
    }

    if (empty($changes)) {
        Response::error("Bad Request: No changes were detected. The submitted data exactly matches the current block.", 400);
    }

    $newPrefix = strtoupper(substr($newName, 0, 3));

    try {
        $pdo->beginTransaction();

        // Shrinking the Grid
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

        // Expanding the Grid
        if ($newRows > $oldBlock['total_rows'] || $newCols > $oldBlock['total_columns']) {
            $stmtGrave = $pdo->prepare("INSERT INTO graves (block_id, grave_code, row_num, col_num, status, created_by) VALUES (?, ?, ?, ?, 'Vacant', ?)");
            for ($r = 1; $r <= $newRows; $r++) {
                for ($c = 1; $c <= $newCols; $c++) {
                    if ($r > $oldBlock['total_rows'] || $c > $oldBlock['total_columns']) {
                        $graveCode = $newPrefix . "-" . $r . "-" . $c;
                        $stmtGrave->execute([$resourceId, $graveCode, $r, $c, $userData['user_id']]);
                    }
                }
            }
        }

        // The Mass Override
        $massUpdateStmt = $pdo->prepare("
            UPDATE graves 
            SET grave_code = CONCAT(?, '-', row_num, '-', col_num), 
                updated_by = ?, 
                updated_at = NOW()
            WHERE block_id = ? AND deleted_at IS NULL
        ");
        $massUpdateStmt->execute([$newPrefix, $userData['user_id'], $resourceId]);

        // Update the Block Master Record
        $updateStmt = $pdo->prepare("
            UPDATE blocks 
            SET block_name = ?, block_type = ?, floor_level = ?, total_rows = ?, total_columns = ?, max_capacity = ?, updated_by = ?, updated_at = NOW() 
            WHERE block_id = ?
        ");
        $updateStmt->execute([$newName, $newType, $newFloor, $newRows, $newCols, ($newRows * $newCols), $userData['user_id'], $resourceId]);

        $pdo->commit();
        
        $fetchStmt = $pdo->prepare("SELECT * FROM blocks WHERE block_id = ?");
        $fetchStmt->execute([$resourceId]);
        $updatedBlock = $fetchStmt->fetch(PDO::FETCH_ASSOC);

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

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM graves WHERE block_id = ? AND status != 'Vacant' AND deleted_at IS NULL");
    $stmt->execute([$resourceId]);
    
    if ($stmt->fetchColumn() > 0) {
        Response::error("Conflict: Cannot delete a block that contains active bodies, reservations, or pending exhumations.", 409);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE graves SET deleted_at = NOW(), updated_by = ? WHERE block_id = ? AND deleted_at IS NULL");
        $stmt->execute([$userData['user_id'], $resourceId]);

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