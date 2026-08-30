<?php

define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    Response::error("Method not allowed", 405);
}

$userData = checkuser();
$userRole = $userData['role'] ?? null;

if (!in_array($userRole, [ROLE_ADMIN, ROLE_OFFICE], true)) {
    Response::error("Unauthorized access", 403);
}

$report = strtolower(trim((string)($_GET['report'] ?? 'interment')));
$filterType = strtolower(trim((string)($_GET['filter'] ?? 'none')));
$filterValue = trim((string)($_GET['value'] ?? 'all'));

if ($filterValue === '') {
    $filterValue = 'all';
}

$basePayload = [
    'report' => $report,
    'generated_at' => date('c'),
    'filters' => [
        'type' => $filterType,
        'value' => $filterValue,
    ],
];

try {
    switch ($report) {
        case 'capacity':
            $summaryStmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_graves,
                    SUM(CASE WHEN status = 'Occupied' THEN 1 ELSE 0 END) AS occupied,
                    SUM(CASE WHEN status = 'Vacant' THEN 1 ELSE 0 END) AS vacant,
                    SUM(CASE WHEN status = 'Reserved' THEN 1 ELSE 0 END) AS reserved,
                    SUM(CASE WHEN status = 'Pending Exhumation' THEN 1 ELSE 0 END) AS pending_exhumation,
                    SUM(CASE WHEN status = 'Under Maintenance' THEN 1 ELSE 0 END) AS under_maintenance
                FROM graves
                WHERE deleted_at IS NULL
            ");
            $summaryStmt->execute();
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $blockStmt = $pdo->prepare("
                SELECT
                    b.block_name,
                    b.block_type,
                    COUNT(g.grave_id) AS total_graves,
                    SUM(CASE WHEN g.status = 'Occupied' THEN 1 ELSE 0 END) AS occupied,
                    SUM(CASE WHEN g.status = 'Vacant' THEN 1 ELSE 0 END) AS vacant,
                    SUM(CASE WHEN g.status = 'Reserved' THEN 1 ELSE 0 END) AS reserved,
                    SUM(CASE WHEN g.status = 'Pending Exhumation' THEN 1 ELSE 0 END) AS pending_exhumation
                FROM blocks b
                LEFT JOIN graves g ON g.block_id = b.block_id AND g.deleted_at IS NULL
                WHERE b.deleted_at IS NULL
                GROUP BY b.block_id, b.block_name, b.block_type
                ORDER BY b.block_name ASC
            ");
            $blockStmt->execute();

            $payload = $basePayload;
            $payload['summary'] = [
                'total_graves' => (int)($summary['total_graves'] ?? 0),
                'occupied' => (int)($summary['occupied'] ?? 0),
                'vacant' => (int)($summary['vacant'] ?? 0),
                'reserved' => (int)($summary['reserved'] ?? 0),
                'pending_exhumation' => (int)($summary['pending_exhumation'] ?? 0),
                'under_maintenance' => (int)($summary['under_maintenance'] ?? 0),
            ];
            $payload['by_block'] = $blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $payload['total_records'] = count($payload['by_block']);
            Response::success("Cemetery capacity summary retrieved", $payload);
            break;

        case 'expirations':
            $expiredStmt = $pdo->prepare("
                SELECT
                    i.interment_id,
                    g.grave_code,
                    d.name AS deceased_name,
                    i.lease_expiration_date,
                    c.name AS contact_name,
                    c.phone_number,
                    i.remarks,
                    i.status
                FROM interments i
                INNER JOIN graves g ON g.grave_id = i.grave_id
                LEFT JOIN deceased d ON d.deceased_id = i.deceased_id
                LEFT JOIN contacts c ON c.contact_id = i.contact_id AND c.deleted_at IS NULL
                WHERE i.deleted_at IS NULL
                  AND i.status IN ('Active', 'Expired')
                  AND i.lease_expiration_date IS NOT NULL
                  AND i.lease_expiration_date < CURDATE()
                ORDER BY i.lease_expiration_date ASC
            ");
            $expiredStmt->execute();

            $expiringStmt = $pdo->prepare("
                SELECT
                    i.interment_id,
                    g.grave_code,
                    d.name AS deceased_name,
                    i.lease_expiration_date,
                    c.name AS contact_name,
                    c.phone_number,
                    i.remarks,
                    i.status
                FROM interments i
                INNER JOIN graves g ON g.grave_id = i.grave_id
                LEFT JOIN deceased d ON d.deceased_id = i.deceased_id
                LEFT JOIN contacts c ON c.contact_id = i.contact_id AND c.deleted_at IS NULL
                WHERE i.deleted_at IS NULL
                  AND i.status = 'Active'
                  AND i.lease_expiration_date IS NOT NULL
                  AND i.lease_expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ORDER BY i.lease_expiration_date ASC
            ");
            $expiringStmt->execute();

            $payload = $basePayload;
            $payload['expired'] = $expiredStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $payload['expiring'] = $expiringStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $payload['total_records'] = count($payload['expired']) + count($payload['expiring']);
            Response::success("Lease expiration report retrieved", $payload);
            break;

        case 'allgraves':
            $params = [];
            $where = " WHERE g.deleted_at IS NULL AND b.deleted_at IS NULL ";

            if ($filterType === 'remarks' && $filterValue !== 'all') {
                $where .= " AND g.status = :status ";
                $params[':status'] = $filterValue;
            }

            $sql = "
                SELECT
                    g.grave_id,
                    g.grave_code,
                    g.status,
                    g.remarks,
                    b.block_name,
                    b.block_type,
                    CASE
                        WHEN g.status = 'Vacant' THEN 'Vacant'
                        WHEN g.status = 'Reserved' THEN 'Reserved'
                        WHEN g.status = 'Occupied' THEN 'Occupied'
                        WHEN g.status = 'Pending Exhumation' THEN 'Pending Exhumation'
                        ELSE 'Other'
                    END AS display_status
                FROM graves g
                LEFT JOIN blocks b ON b.block_id = g.block_id
                $where
                ORDER BY g.grave_code ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $payload = $basePayload;
            $payload['rows'] = array_map(function ($row) {
                return [
                    'grave_id' => (int)($row['grave_id'] ?? 0),
                    'grave_code' => $row['grave_code'] ?? 'N/A',
                    'status' => $row['status'] ?? 'Unknown',
                    'remarks' => $row['remarks'] ?? '',
                    'block_name' => $row['block_name'] ?? 'N/A',
                    'block_type' => $row['block_type'] ?? 'N/A',
                    'display_status' => $row['display_status'] ?? 'Unknown',
                    'data_remarks' => $row['status'] ?? 'Unknown',
                ];
            }, $rows);
            $payload['total_records'] = count($payload['rows']);
            Response::success("All graves registry status retrieved", $payload);
            break;

        case 'interment':
        default:
            $params = [];
            $where = " WHERE i.deleted_at IS NULL ";

            if ($filterType === 'barangay' && $filterValue !== 'all') {
                $where .= " AND COALESCE(c.barangay, d.last_known_address, '') LIKE :barangay ";
                $params[':barangay'] = $filterValue;
            }

            if ($filterType === 'gender' && $filterValue !== 'all') {
                $where .= " AND d.sex = :gender ";
                $params[':gender'] = $filterValue;
            }

            if ($filterType === 'date' && $filterValue !== 'all') {
                $where .= " AND YEAR(COALESCE(i.date_buried, d.date_of_death)) = :year ";
                $params[':year'] = (int)$filterValue;
            }

            if ($filterType === 'remarks' && $filterValue !== 'all') {
                $where .= " AND CASE WHEN c.contact_id IS NOT NULL AND c.deleted_at IS NULL THEN 'Family' ELSE 'No Family' END = :remarks ";
                $params[':remarks'] = $filterValue;
            }

            $sql = "
                SELECT
                    i.interment_id,
                    i.control_number,
                    i.date_buried,
                    i.lease_expiration_date,
                    i.remarks AS interment_remarks,
                    d.deceased_id,
                    d.name AS deceased_name,
                    d.sex AS deceased_sex,
                    d.date_of_death,
                    d.last_known_address,
                    g.grave_code,
                    b.block_name,
                    b.block_type,
                    c.contact_id,
                    c.name AS contact_name,
                    c.phone_number,
                    c.address AS contact_address,
                    c.barangay AS contact_barangay,
                    CASE
                        WHEN c.contact_id IS NOT NULL AND c.deleted_at IS NULL THEN 'Family'
                        ELSE 'No Family'
                    END AS family_status
                FROM interments i
                LEFT JOIN graves g ON g.grave_id = i.grave_id
                LEFT JOIN blocks b ON b.block_id = g.block_id
                LEFT JOIN deceased d ON d.deceased_id = i.deceased_id
                LEFT JOIN contacts c ON c.contact_id = i.contact_id AND c.deleted_at IS NULL
                $where
                ORDER BY i.date_buried DESC, i.interment_id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $payload = $basePayload;
            $payload['rows'] = array_map(function ($row) {
                $barangay = trim((string)($row['contact_barangay'] ?? ''));
                if ($barangay === '') {
                    $barangay = 'Unspecified';
                }

                $contactName = trim((string)($row['contact_name'] ?? ''));
                $personLabel = $contactName !== '' ? $contactName : 'CSWS Officer';
                $familyStatus = $row['family_status'] ?? 'No Family';

                return [
                    'interment_id' => (int)($row['interment_id'] ?? 0),
                    'control_number' => $row['control_number'] ?? null,
                    'name' => $row['deceased_name'] ?? 'Unknown',
                    'date_of_death' => $row['date_of_death'] ?? null,
                    'date_buried' => $row['date_buried'] ?? null,
                    'burial_year' => $row['date_buried'] ? date('Y', strtotime($row['date_buried'])) : null,
                    'grave_code' => $row['grave_code'] ?? 'N/A',
                    'location' => trim((string)($row['block_name'] ?? '')) !== ''
                        ? ($row['block_name'] . ' - ' . ($row['grave_code'] ?? 'N/A'))
                        : ($row['grave_code'] ?? 'N/A'),
                    'contact_person' => $personLabel,
                    'contact_phone' => $row['phone_number'] ?? null,
                    'address' => $barangay,
                    'barangay' => $barangay,
                    'gender' => $row['deceased_sex'] ?? 'Unknown',
                    'remarks' => $familyStatus,
                    'family_status' => $familyStatus,
                    'lease_expiration_date' => $row['lease_expiration_date'] ?? null,
                    'interment_remarks' => $row['interment_remarks'] ?? null,
                    'data_barangay' => $barangay,
                    'data_gender' => $row['deceased_sex'] ?? 'Unknown',
                    'data_date' => $row['date_buried'] ? date('Y', strtotime($row['date_buried'])) : 'Unknown',
                    'data_remarks' => $familyStatus,
                ];
            }, $rows);

            $payload['total_records'] = count($payload['rows']);
            Response::success("Interment directory retrieved", $payload);
            break;
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    Response::error("Unable to generate report data. " . $e->getMessage(), 500);
}
?>
