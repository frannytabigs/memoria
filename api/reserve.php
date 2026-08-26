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
$isAuthorizedStaff = in_array($role, [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS]);
$canModify = in_array($role, [ROLE_ADMIN, ROLE_OFFICE]);

if (!$isAuthorizedStaff) {
    Response::error("Forbidden. You do not have permission to access the reservation catalog.", 403);
}

if (in_array($method, ['POST', 'PUT'], true) && !$canModify) {
    Response::error("Forbidden. Only Office Staff and Admins can process new reservations.", 403);
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

$normalizeDestination = function ($destination, $pdo, $graveId) {
    if (is_string($destination)) {
        $destination = ['type' => trim($destination)];
    }
    if (!is_array($destination)) return null;

    $type = strtolower(trim($destination['type'] ?? $destination['destination_type'] ?? ''));
    $allowedTypes = ['specific_grave', 'common_bone_chamber', 'family_custody', 'other'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new Exception("A valid old occupant destination is required.", 400);
    }

    $normalized = [
        'type' => $type,
        'grave_id' => null,
        'notes' => trim($destination['notes'] ?? $destination['destination_notes'] ?? '')
    ];

    if ($type === 'specific_grave') {
        $destinationGraveId = !empty($destination['grave_id']) ? (int)$destination['grave_id'] : null;
        if (!$destinationGraveId || $destinationGraveId === (int)$graveId) {
            throw new Exception("A different destination grave is required.", 400);
        }

        $stmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
        $stmt->execute([$destinationGraveId]);
        $destinationStatus = $stmt->fetchColumn();
        if (!$destinationStatus) throw new Exception("Destination grave does not exist.", 404);
        if ($destinationStatus !== 'Vacant') {
            throw new Exception("Destination grave is not vacant.", 409);
        }
        $normalized['grave_id'] = $destinationGraveId;
    } elseif ($type === 'other' && $normalized['notes'] === '') {
        throw new Exception("Destination notes are required for 'other'.", 400);
    }

    return $normalized;
};

// ---------------------------------------------------------
// HELPERS: DATA FORMATTING & RESOLUTION
// ---------------------------------------------------------
$formatIntermentRow = function($row) {
    return [
        'interment_id'              => (int)$row['interment_id'],
        'control_number'            => $row['control_number'],
        'assistance_type'           => $row['assistance_type'],
        'burial_permit_number'      => $row['burial_permit_number'],
        'burial_permit_date'        => $row['burial_permit_date'],
        'transfer_permit_number'    => $row['transfer_permit_number'],
        'transfer_permit_issued_by' => $row['transfer_permit_issued_by'],
        'transfer_permit_date'      => $row['transfer_permit_date'],
        'exhumation_permit_number'  => $row['exhumation_permit_number'],
        'exhumation_permit_date'    => $row['exhumation_permit_date'],
        'date_buried'               => $row['date_buried'],
        'clearance_date'            => $row['clearance_date'],
        'lease_expiration_date'     => $row['lease_expiration_date'],
        'days_remaining'            => isset($row['days_remaining']) ? (int)$row['days_remaining'] : 0,
        'days_overdue'              => isset($row['days_overdue']) ? (int)$row['days_overdue'] : 0,
        'status'                    => $row['status'],
        'remarks'                   => $row['remarks'],
        'grave' => [
            'grave_id'   => (int)$row['grave_id'],
            'grave_code' => $row['grave_code'],
            'remarks'    => $row['grave_remarks'] ?? null
        ],
        'block' => [
            'block_id'         => (int)$row['block_id'],
            'block_name'       => $row['block_name'],
            'owner_contact_id' => !empty($row['owner_contact_id']) ? (int)$row['owner_contact_id'] : null,
            'remarks'          => $row['block_remarks'] ?? null
        ],
        'deceased' => [
            'deceased_id'   => (int)$row['deceased_id'],
            'name'          => $row['deceased_name'],
            'sex'           => $row['deceased_sex'] ?? null,
            'remarks'       => $row['deceased_remarks'] ?? null,
            'death_certificate' => $row['death_certificate'] ?? null,
            'date_of_death' => $row['date_of_death'] ?? null,
            'is_archived'   => $row['deceased_deleted'] !== null
        ],
        'contact' => [
            'contact_id'   => (int)$row['contact_id'],
            'name'         => $row['contact_name'],
            'phone_number' => $row['contact_phone'],
            'remarks'      => $row['contact_remarks'] ?? null,
            'is_archived'  => ($row['contact_deleted'] ?? null) !== null
        ]
    ];
};

$resolveContact = function($ownerData, $pdo, $userId) {
    if (!is_array($ownerData) || empty($ownerData)) return null;
    if (!empty($ownerData['contact_id'])) return (int)$ownerData['contact_id'];

    $name = trim($ownerData['name'] ?? '');
    if (empty($name)) throw new Exception("Contact name is required.", 400);

    $address = trim($ownerData['address'] ?? '');
    $barangay = trim($ownerData['barangay'] ?? '');
    $phone = trim($ownerData['phone_number'] ?? '');
    $email = trim($ownerData['email_address'] ?? '');

    $stmt = $pdo->prepare("SELECT contact_id FROM contacts WHERE name = ? AND IFNULL(phone_number, '') = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$name, $phone]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) return $existingId;

    $insertStmt = $pdo->prepare("INSERT INTO contacts (name, address, barangay, phone_number, email_address, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([$name, $address, $barangay, $phone, $email, $userId]);
    return $pdo->lastInsertId();
};

$resolveDeceased = function($decData, $pdo, $userId) {
    if (!is_array($decData) || empty($decData)) return null; 
    if (!empty($decData['deceased_id'])) return (int)$decData['deceased_id'];

    $name = trim($decData['name'] ?? '');
    if (empty($name)) return null; 

    $sex = $decData['sex'] ?? 'Unknown';
    $dob = !empty($decData['date_of_birth']) ? $decData['date_of_birth'] : null;
    $dod = !empty($decData['date_of_death']) ? $decData['date_of_death'] : null;

    $stmt = $pdo->prepare("SELECT deceased_id FROM deceased WHERE name = ? AND sex = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$name, $sex]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) return $existingId;

    $certificate = trim($decData['death_certificate'] ?? '');
    $insertStmt = $pdo->prepare("INSERT INTO deceased (name, sex, date_of_birth, date_of_death, death_certificate, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([$name, $sex, $dob, $dod, $certificate !== '' ? $certificate : null, $userId]);
    return $pdo->lastInsertId();
};

// ==========================================
// GET: RETRIEVE AVAILABLE GRAVES CATALOG
// ==========================================
if ($method === 'GET') {
    $rawLimit = $_GET['limit'] ?? 50; 
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? min((int)$rawLimit, 500) : 50;

    $whereExpired  = "WHERE i.deleted_at IS NULL AND i.status IN ('Active', 'Expired') AND i.lease_expiration_date < CURDATE()";
    $whereExpiring = "WHERE i.deleted_at IS NULL AND i.status = 'Active' AND i.lease_expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    $whereVacant   = "WHERE g.status = 'Vacant' AND g.deleted_at IS NULL AND b.deleted_at IS NULL";

    $countExpired  = (int)$pdo->query("SELECT COUNT(*) FROM interments i $whereExpired")->fetchColumn();
    $countExpiring = (int)$pdo->query("SELECT COUNT(*) FROM interments i $whereExpiring")->fetchColumn();
    $countVacant   = (int)$pdo->query("SELECT COUNT(*) FROM graves g LEFT JOIN blocks b ON g.block_id = b.block_id $whereVacant")->fetchColumn();

    $maxRecords = max($countExpired, $countExpiring, $countVacant);
    $totalPages = $maxRecords > 0 ? (int)ceil($maxRecords / $limit) : 1;

    $rawPage = $_GET['page'] ?? 1;
    $page = (!is_numeric($rawPage) || $rawPage < 1) ? 1 : min((int)$rawPage, $totalPages);
    $offset = ($page - 1) * $limit;

    // --- A. EXPIRED ---
    $stmtExpired = $pdo->prepare("
        SELECT i.*, DATEDIFF(CURDATE(), i.lease_expiration_date) AS days_overdue, 0 AS days_remaining,
        g.grave_id, g.grave_code, g.remarks AS grave_remarks,
        b.block_id, b.block_name, b.owner_contact_id, b.remarks AS block_remarks,
        d.deceased_id, d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks,
        d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death,
        c.contact_id, c.name AS contact_name, c.phone_number AS contact_phone, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
        FROM interments i LEFT JOIN graves g ON i.grave_id = g.grave_id LEFT JOIN blocks b ON g.block_id = b.block_id LEFT JOIN deceased d ON i.deceased_id = d.deceased_id LEFT JOIN contacts c ON i.contact_id = c.contact_id
        $whereExpired ORDER BY i.lease_expiration_date ASC LIMIT :limit OFFSET :offset
    ");
    $stmtExpired->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtExpired->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtExpired->execute();
    $expiredData = array_map($formatIntermentRow, $stmtExpired->fetchAll(PDO::FETCH_ASSOC));

    // --- B. EXPIRING ---
    $stmtExpiring = $pdo->prepare("
        SELECT i.*, DATEDIFF(i.lease_expiration_date, CURDATE()) AS days_remaining, 0 AS days_overdue,
        g.grave_id, g.grave_code, g.remarks AS grave_remarks,
        b.block_id, b.block_name, b.owner_contact_id, b.remarks AS block_remarks,
        d.deceased_id, d.name AS deceased_name, d.sex AS deceased_sex, d.remarks AS deceased_remarks,
        d.deleted_at AS deceased_deleted, d.death_certificate, d.date_of_death,
        c.contact_id, c.name AS contact_name, c.phone_number AS contact_phone, c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
        FROM interments i LEFT JOIN graves g ON i.grave_id = g.grave_id LEFT JOIN blocks b ON g.block_id = b.block_id LEFT JOIN deceased d ON i.deceased_id = d.deceased_id LEFT JOIN contacts c ON i.contact_id = c.contact_id
        $whereExpiring ORDER BY i.lease_expiration_date ASC LIMIT :limit OFFSET :offset
    ");
    $stmtExpiring->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtExpiring->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtExpiring->execute();
    $expiringData = array_map($formatIntermentRow, $stmtExpiring->fetchAll(PDO::FETCH_ASSOC));

    // --- C. VACANT ---
    $stmtVacant = $pdo->prepare("
        SELECT g.grave_id, g.grave_code, b.block_name, b.block_type 
        FROM graves g LEFT JOIN blocks b ON g.block_id = b.block_id 
        $whereVacant ORDER BY b.block_name ASC, g.row_num ASC, g.col_num ASC LIMIT :limit OFFSET :offset
    ");
    $stmtVacant->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtVacant->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtVacant->execute();
    $vacantData = $stmtVacant->fetchAll(PDO::FETCH_ASSOC);

    Response::success("Reservation Catalog retrieved", [
        "pagination" => [
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_pages'   => $totalPages,
            'breakdown'     => ['expired_count' => $countExpired, 'expiring_count' => $countExpiring, 'vacant_count' => $countVacant]
        ],
            "expired"  => $expiredData,
            "expiring" => $expiringData,
            "vacant"   => $vacantData
        
    ]);
}

// ==========================================
// POST: INTAKE NEW PENDING INTERMENT (The "+ Button")
// ==========================================
if ($method === 'POST') {
    
    $graveId = !empty($rawData['grave_id']) ? (int)$rawData['grave_id'] : null;
    $controlNumber = trim($rawData['control_number'] ?? '');
    
    // This is the crucial question for replacing an old body!
    $oldDestination = $rawData['old_occupant_destination'] ?? null;

    if (!$graveId || empty($controlNumber)) {
        Response::error("Control number and grave ID are required.", 400);
    }

    try {
        $pdo->beginTransaction();

        // 1. Check current grave status
        $graveStmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
        $graveStmt->execute([$graveId]);
        $currentGraveStatus = $graveStmt->fetchColumn();

        if (!$currentGraveStatus) throw new Exception("Grave does not exist.", 404);
        if (in_array($currentGraveStatus, ['Reserved', 'Pending Exhumation', 'Under Maintenance'])) {
            throw new Exception("Conflict: This grave is already flagged for an upcoming operation and cannot be selected.", 409);
        }

        $destination = null;
        if ($currentGraveStatus === 'Occupied') {
            $destination = $normalizeDestination($oldDestination, $pdo, $graveId);
        }

        // 2. Resolve the new family & body
        $contactId = $resolveContact($rawData['contact'] ?? [], $pdo, $userData['user_id']);
        $deceasedId = $resolveDeceased($rawData['deceased'] ?? [], $pdo, $userData['user_id']);

        if (!$contactId) throw new Exception("Contact/Requesting Party information is required.", 400);
        if (!$deceasedId) throw new Exception("Deceased information is required.", 400);

        $combinedRemarks = trim($rawData['remarks'] ?? '');
        if ($destination) {
            $combinedRemarks = json_encode([
                '_workflow' => 'pending_transition',
                'old_occupant_destination' => $destination,
                'remarks' => $combinedRemarks
            ], JSON_UNESCAPED_SLASHES);
        }

        $stmt = $pdo->prepare("
            INSERT INTO interments (
                control_number, deceased_id, grave_id, contact_id, assistance_type, 
                burial_permit_number, burial_permit_date, transfer_permit_number,
                transfer_permit_issued_by, transfer_permit_date, exhumation_permit_number,
                exhumation_permit_date, date_buried, clearance_date, lease_expiration_date,
                status, remarks, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
        ");
        $stmt->execute([
            $controlNumber, 
            $deceasedId, // Can be null if they are just reserving for themselves while alive
            $graveId, 
            $contactId, 
            $rawData['assistance_type'] ?? 'Burial',
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
            $combinedRemarks,
            $userData['user_id']
        ]);
        
        $newIntermentId = $pdo->lastInsertId();

        // 4. Update the Grave Status so it vanishes from the catalog!
        $newGraveStatus = ($currentGraveStatus === 'Vacant') ? 'Reserved' : 'Pending Exhumation';
        
        $updateGrave = $pdo->prepare("UPDATE graves SET status = ?, updated_by = ?, updated_at = NOW() WHERE grave_id = ?");
        $updateGrave->execute([$newGraveStatus, $userData['user_id'], $graveId]);

        $pdo->commit();
        systemLog($userData['name'] . " initiated replacement/reservation for Grave ID: " . $graveId, $userData['user_id']);
        
        Response::success("Intake successful. Grave is now locked for staging.", [
            "pending_interment_id" => $newIntermentId,
            "new_grave_status" => $newGraveStatus
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if (in_array($code, [400, 404, 409])) Response::error($e->getMessage(), $code);
        Response::error("Database error or missing data.", 500);
    } 
}

// ==========================================
// PUT: CORRECT A PENDING RESERVATION
// ==========================================
if ($method === 'PUT') {
    $intermentId = !empty($rawData['interment_id']) ? (int)$rawData['interment_id'] : (is_numeric($resourceId) ? (int)$resourceId : 0);
    if (!$intermentId) Response::error("Pending interment ID is required.", 400);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM interments WHERE interment_id = ? AND status = 'Pending' AND deleted_at IS NULL FOR UPDATE");
        $stmt->execute([$intermentId]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pending) throw new Exception("Pending reservation not found.", 404);

        $graveStmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
        $graveStmt->execute([$pending['grave_id']]);
        $graveStatus = $graveStmt->fetchColumn();
        if (!$graveStatus) throw new Exception("Reserved grave not found.", 404);

        if (array_key_exists('old_occupant_destination', $rawData)) {
            $destination = $normalizeDestination($rawData['old_occupant_destination'], $pdo, $pending['grave_id']);
        } else {
            $existing = json_decode($pending['remarks'] ?? '', true);
            $destination = is_array($existing) && ($existing['_workflow'] ?? '') === 'pending_transition'
                ? ($existing['old_occupant_destination'] ?? null)
                : null;
        }
        if ($graveStatus === 'Pending Exhumation' && !$destination) {
            throw new Exception("Old occupant destination is required for this reservation.", 400);
        }

        $deceasedData = $rawData['deceased'] ?? [];
        if (is_array($deceasedData) && $pending['deceased_id']) {
            $deceasedStmt = $pdo->prepare("UPDATE deceased SET name = COALESCE(NULLIF(?, ''), name), sex = COALESCE(NULLIF(?, ''), sex), date_of_birth = ?, date_of_death = ?, death_certificate = ?, updated_by = ? WHERE deceased_id = ? AND deleted_at IS NULL");
            $deceasedStmt->execute([
                trim($deceasedData['name'] ?? ''), $deceasedData['sex'] ?? '',
                $deceasedData['date_of_birth'] ?? null, $deceasedData['date_of_death'] ?? null,
                $deceasedData['death_certificate'] ?? null, $userData['user_id'], $pending['deceased_id']
            ]);
        }

        $contactData = $rawData['contact'] ?? [];
        if (is_array($contactData) && $pending['contact_id']) {
            $contactStmt = $pdo->prepare("UPDATE contacts SET name = COALESCE(NULLIF(?, ''), name), address = ?, barangay = ?, phone_number = ?, email_address = ?, updated_by = ? WHERE contact_id = ? AND deleted_at IS NULL");
            $contactStmt->execute([
                trim($contactData['name'] ?? ''), $contactData['address'] ?? null, $contactData['barangay'] ?? null,
                $contactData['phone_number'] ?? null, $contactData['email_address'] ?? null,
                $userData['user_id'], $pending['contact_id']
            ]);
        }

        $remarks = trim($rawData['remarks'] ?? '');
        if ($destination) {
            $remarks = json_encode([
                '_workflow' => 'pending_transition',
                'old_occupant_destination' => $destination,
                'remarks' => $remarks
            ], JSON_UNESCAPED_SLASHES);
        }

        $update = $pdo->prepare("UPDATE interments SET control_number = ?, assistance_type = ?, burial_permit_number = ?, burial_permit_date = ?, transfer_permit_number = ?, transfer_permit_issued_by = ?, transfer_permit_date = ?, exhumation_permit_number = ?, exhumation_permit_date = ?, date_buried = ?, clearance_date = ?, lease_expiration_date = ?, remarks = ?, updated_by = ? WHERE interment_id = ? AND status = 'Pending' AND deleted_at IS NULL");
        $update->execute([
            trim($rawData['control_number'] ?? $pending['control_number']), $rawData['assistance_type'] ?? $pending['assistance_type'],
            $rawData['burial_permit_number'] ?? $pending['burial_permit_number'], $rawData['burial_permit_date'] ?? $pending['burial_permit_date'],
            $rawData['transfer_permit_number'] ?? $pending['transfer_permit_number'], $rawData['transfer_permit_issued_by'] ?? $pending['transfer_permit_issued_by'], $rawData['transfer_permit_date'] ?? $pending['transfer_permit_date'],
            $rawData['exhumation_permit_number'] ?? $pending['exhumation_permit_number'], $rawData['exhumation_permit_date'] ?? $pending['exhumation_permit_date'],
            $rawData['date_buried'] ?? $pending['date_buried'], $rawData['clearance_date'] ?? $pending['clearance_date'], $rawData['lease_expiration_date'] ?? $pending['lease_expiration_date'],
            $remarks, $userData['user_id'], $intermentId
        ]);

        $pdo->commit();
        systemLog($userData['name'] . " updated pending interment ID: " . $intermentId, $userData['user_id']);
        Response::success("Pending reservation updated.");
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if (in_array($code, [400, 404, 409])) Response::error($e->getMessage(), $code);
        Response::error("Database error while updating reservation.", 500);
    }
}

Response::error("Method not allowed", 405);
?>