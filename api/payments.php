<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

// Allow checkuser to return null for public users (clients) without throwing an error
$userData = checkuser(false); 

// ---------------------------------------------------------
// 1. ROLE DEFINITIONS & GATEKEEPER
// ---------------------------------------------------------
$role = $userData['role'] ?? null;

$isAdmin   = ($role === ROLE_ADMIN);
$isOffice  = ($role === ROLE_OFFICE);
$isGrounds = ($role === ROLE_GROUNDS);

$isAuthorizedStaff = in_array($role, [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS]);

// If it's NOT a POST request, strictly lock it down to authorized staff
if ($method !== 'POST') {
    if (!$isAuthorizedStaff) {
        Response::error("Forbidden. You do not have access to view or modify payment records.", 403);
    }
}

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts); 

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [], 
    $_POST ?? []
);

// ==========================================
// GET: RETRIEVE PAYMENTS (Paginated)
// ==========================================
if ($method === 'GET') {
    
    // SCENARIO 1: Fetch Single Record
    if (is_numeric($resourceId)) {
        $sql = "
            SELECT 
                p.payment_id, p.reference_number, p.payment_channel, p.amount, p.purpose, 
                p.image_link, p.remarks_payer, p.remarks_office, p.remarks_grounds, p.created_at,
                p.confirmed_office_staff, u1.name AS office_staff_name,
                p.confirmed_ground_staff, u2.name AS ground_staff_name
            FROM payments p
            LEFT JOIN users u1 ON p.confirmed_office_staff = u1.user_id
            LEFT JOIN users u2 ON p.confirmed_ground_staff = u2.user_id
            WHERE p.deleted_at IS NULL AND p.payment_id = ? LIMIT 1
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$resourceId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) Response::error("Payment not found", 404);
        $rawPayments = [$record]; 
        $paginationData = null;
    } 
    // SCENARIO 2: Fetch All (Role-Filtered & Paginated)
    else {
        // 1. Setup Base Where Clause
        $whereClause = "WHERE p.deleted_at IS NULL";
        if ($isGrounds) {
            $whereClause .= " AND p.confirmed_office_staff IS NOT NULL"; // Grounds only sees office-confirmed
        }

        // 2. Pagination Math & Limits
        $rawLimit = $_GET['limit'] ?? 100;
        $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? (int)$rawLimit : 100;
        $limit = min($limit, 500); 

        // Count Total
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p " . $whereClause);
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;

        // Determine Page
        $rawPage = $_GET['page'] ?? 1;
        $page = (!is_numeric($rawPage) || $rawPage < 1) ? 1 : min((int)$rawPage, $totalPages); 
        $offset = ($page - 1) * $limit;

        // 3. Fetch Data
        $sql = "
            SELECT 
                p.payment_id, p.reference_number, p.payment_channel, p.amount, p.purpose, 
                p.image_link, p.remarks_payer, p.remarks_office, p.remarks_grounds, p.created_at,
                p.confirmed_office_staff, u1.name AS office_staff_name,
                p.confirmed_ground_staff, u2.name AS ground_staff_name
            FROM payments p
            LEFT JOIN users u1 ON p.confirmed_office_staff = u1.user_id
            LEFT JOIN users u2 ON p.confirmed_ground_staff = u2.user_id
            " . $whereClause . "
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $rawPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paginationData = [
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_records' => $totalRecords,
            'total_pages'   => $totalPages
        ];
    }

    // --- FORMAT NESTED JSON ---
    $formattedPayments = [];
    foreach ($rawPayments as $row) {
        $status = "Pending Office";
        if ($row['confirmed_office_staff'] && !$row['confirmed_ground_staff']) {
            $status = "Pending Grounds";
        } elseif ($row['confirmed_office_staff'] && $row['confirmed_ground_staff']) {
            $status = "Completed";
        }

        $formattedPayments[] = [
            'payment_id'       => (int)$row['payment_id'],
            'reference_number' => $row['reference_number'],
            'created_at'       => $row['created_at'],
            'overall_status'   => $status,
            
            'details' => [
                'channel'       => $row['payment_channel'],
                'amount'        => (float)$row['amount'],
                'purpose'       => $row['purpose'],
                'image_link'    => $row['image_link'],
                'remarks_payer' => $row['remarks_payer']
            ],
            
            'audit' => [
                'office_confirmed' => [
                    'is_confirmed' => $row['confirmed_office_staff'] !== null,
                    'staff_id'     => $row['confirmed_office_staff'],
                    'staff_name'   => $row['office_staff_name'],
                    'remarks'      => $row['remarks_office']
                ],
                'grounds_confirmed' => [
                    'is_confirmed' => $row['confirmed_ground_staff'] !== null,
                    'staff_id'     => $row['confirmed_ground_staff'],
                    'staff_name'   => $row['ground_staff_name'],
                    'remarks'      => $row['remarks_grounds']
                ]
            ]
        ];
    }

    if (is_numeric($resourceId)) {
        Response::success("Payment retrieved", ["payment" => $formattedPayments[0]]);
    } else {
        Response::success("Payments retrieved", ["pagination" => $paginationData, "payments" => $formattedPayments]);
    }
}

// ==========================================
// POST: CREATE NEW PAYMENT LOG (OPEN TO CLIENTS)
// ==========================================
if ($method === 'POST') {
    
    // Notice: There is no permission check here! Anyone can POST.

    $refNum  = trim($rawData['reference_number'] ?? '');
    $channel = trim($rawData['payment_channel'] ?? '');
    $amount  = trim($rawData['amount'] ?? 0);
    $purpose = trim($rawData['purpose'] ?? '');
    $image   = trim($rawData['image_link'] ?? '');

    if (empty($refNum) || empty($channel) || empty($amount) || empty($purpose) || empty($image)) {
        Response::error("Reference number, channel, amount, purpose, and image link are required.", 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (reference_number, payment_channel, amount, purpose, image_link, remarks_payer, remarks_office) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $refNum, $channel, $amount, $purpose, $image, 
            trim($rawData['remarks_payer'] ?? ''), 
            trim($rawData['remarks_office'] ?? '') // Admins can optionally include an office remark immediately
        ]);
        
        $newId = $pdo->lastInsertId();
        
        // Safely log the creation, even if it's an anonymous public client
        $loggerName = $userData['name'] ?? "Public Client";
        $loggerId = $userData['user_id'] ?? null;
        systemLog($loggerName . " uploaded payment proof: " . $refNum, $loggerId);
        
        Response::success("Payment proof submitted successfully. Pending office review.", ["payment_id" => $newId], 201);

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) Response::error("Conflict: Reference number already exists.", 409);
        Response::error("Database error while creating payment.", 500);
    }
}

// ==========================================
// PUT: CONFIRMATIONS & EDITS
// ==========================================
if ($method === 'PUT') {
    if (!is_numeric($resourceId)) Response::error("Payment ID required", 400);

    $action = $rawData['action'] ?? 'edit_details';

    try {
        // --- OFFICE ACTIONS ---
        if ($action === 'confirm_office') {
            if (!$isAdmin && !$isOffice) Response::error("Only Office staff or Admins can confirm payments.", 403);
            
            $stmt = $pdo->prepare("UPDATE payments SET confirmed_office_staff = ?, remarks_office = COALESCE(?, remarks_office), updated_at = NOW() WHERE payment_id = ?");
            $stmt->execute([$userData['user_id'], $rawData['remarks_office'] ?? null, $resourceId]);
            systemLog($userData['name'] . " confirmed payment receipt for ID: " . $resourceId, $userData['user_id']);
            
            Response::success("Payment confirmed by Office.");
        } 
        elseif ($action === 'unconfirm_office') {
            if (!$isAdmin && !$isOffice) Response::error("Forbidden.", 403);

            // Prevent office from voiding if grounds already started/finished
            $checkStmt = $pdo->prepare("SELECT confirmed_ground_staff FROM payments WHERE payment_id = ? AND deleted_at IS NULL");
            $checkStmt->execute([$resourceId]);
            if ($checkStmt->fetchColumn()) {
                Response::error("Cannot unconfirm: Grounds staff has already completed work based on this payment.", 409);
            }

            $stmt = $pdo->prepare("UPDATE payments SET confirmed_office_staff = NULL, updated_at = NOW() WHERE payment_id = ?");
            $stmt->execute([$resourceId]);
            systemLog($userData['name'] . " unconfirmed payment receipt for ID: " . $resourceId, $userData['user_id']);
            
            Response::success("Payment confirmation reverted.");
        }

        // --- GROUNDS ACTIONS ---
        elseif ($action === 'confirm_grounds') {
            if (!$isAdmin && !$isGrounds) Response::error("Only Grounds staff or Admins can mark ground work as done.", 403);
            
            $checkStmt = $pdo->prepare("SELECT confirmed_office_staff FROM payments WHERE payment_id = ? AND deleted_at IS NULL");
            $checkStmt->execute([$resourceId]);
            if (!$checkStmt->fetchColumn()) {
                Response::error("Cannot complete ground work: Payment has not been confirmed by the office yet.", 400);
            }

            $stmt = $pdo->prepare("UPDATE payments SET confirmed_ground_staff = ?, remarks_grounds = COALESCE(?, remarks_grounds), updated_at = NOW() WHERE payment_id = ?");
            $stmt->execute([$userData['user_id'], $rawData['remarks_grounds'] ?? null, $resourceId]);
            systemLog($userData['name'] . " confirmed ground work completed for payment ID: " . $resourceId, $userData['user_id']);
            
            Response::success("Work completion confirmed by Grounds.");
        }
        elseif ($action === 'unconfirm_grounds') {
            if (!$isAdmin && !$isGrounds) Response::error("Forbidden.", 403);

            $stmt = $pdo->prepare("UPDATE payments SET confirmed_ground_staff = NULL, updated_at = NOW() WHERE payment_id = ?");
            $stmt->execute([$resourceId]);
            systemLog($userData['name'] . " unconfirmed ground work for payment ID: " . $resourceId, $userData['user_id']);
            
            Response::success("Ground work confirmation reverted.");
        }
        
        // --- GENERAL EDIT ---
        else {
            if (!$isAdmin && !$isOffice) Response::error("Forbidden.", 403);
            
            $newRef = $rawData['reference_number'] ?? null;
            $newAmt = $rawData['amount'] ?? null;
            $newPurp = $rawData['purpose'] ?? null;

            $stmt = $pdo->prepare("
                UPDATE payments 
                SET 
                    reference_number = COALESCE(?, reference_number),
                    amount = COALESCE(?, amount),
                    purpose = COALESCE(?, purpose),
                    remarks_office = COALESCE(?, remarks_office), 
                    remarks_grounds = COALESCE(?, remarks_grounds), 
                    updated_at = NOW() 
                WHERE payment_id = ?
            ");
            $stmt->execute([
                $newRef, $newAmt, $newPurp, 
                $rawData['remarks_office'] ?? null, $rawData['remarks_grounds'] ?? null, 
                $resourceId
            ]);
            Response::success("Payment details updated.");
        }

    } catch (PDOException $e) {
        Response::error("Database error while updating payment.", 500);
    }
}

// ==========================================
// DELETE: REMOVE (Soft Delete)
// ==========================================
if ($method === 'DELETE') {
    if (!$isAdmin && !$isOffice) {
        Response::error("Forbidden. You do not have permission to delete payments.", 403);
    }
    if (!is_numeric($resourceId)) Response::error("Payment ID required", 400);

    try {
        $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE payment_id = ?");
        $stmt->execute([$resourceId]);

        systemLog($userData['name'] . " deleted payment ID: " . $resourceId, $userData['user_id']);
        Response::success("Payment successfully deleted.");
    } catch (PDOException $e) {
        Response::error("Database error.", 500);
    }
}

Response::error("Method not allowed", 405);
?>