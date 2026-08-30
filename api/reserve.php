<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';
require_once 'gravestate.php'; // NEW: Our single source of truth for grave statuses

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser();

// ---------------------------------------------------------
// 1. GATEKEEPER & PRIVACY CLEARANCE
// ---------------------------------------------------------
$role = $userData['role'] ?? null;

// Grounds Staff can view the catalog, but cannot create reservations
$isAuthorizedStaff = in_array($role, [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS]);
$canModify = in_array($role, [ROLE_ADMIN, ROLE_OFFICE]);

if (!$isAuthorizedStaff) {
    Response::error("Forbidden. You do not have permission to access the reservation catalog.", 403);
}

// STRICT BOUNDARY ENFORCEMENT: Reserve handles Intake only.
// Edits belong to Monitor. Deletes belong to Records.
if ($method === 'PUT' || $method === 'DELETE') {
    Response::error("Method not allowed. Use the Monitor module to edit staged reservations.", 405);
}

if ($method === 'POST' && !$canModify) {
    Response::error("Forbidden. Only Office Staff and Admins can process new reservations.", 403);
}

// --- REST ROUTING: PARSE THE URI ---
$pathInfo = $_GET['path_info'] ?? $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts);

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [],
    $_POST ?? []
);

// How many days ahead counts as "expiring soon".
const RESERVE_EXPIRING_WINDOW_DAYS = 30;

// ---------------------------------------------------------
// HELPERS: DATA FORMATTING & RESOLUTION
// ---------------------------------------------------------
// $availability tells the formatter which side of CURDATE() this row is on, so
// an expired lease never reports a positive days_remaining and vice versa.
$formatIntermentRow = function ($row, $availability = null) {
    $overdue   = isset($row['days_overdue']) ? (int)$row['days_overdue'] : 0;
    $remaining = isset($row['days_remaining']) ? (int)$row['days_remaining'] : 0;

    if ($availability === 'expired') {
        $remaining = 0;
    } elseif ($availability === 'expiring') {
        $overdue = 0;
    }

    return [
        'availability'              => $availability,
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
        'date_exhumed'              => $row['date_exhumed'] ?? null,
        'clearance_date'            => $row['clearance_date'],
        'lease_expiration_date'     => $row['lease_expiration_date'],
        'days_remaining'            => max(0, $remaining),
        'days_overdue'              => max(0, $overdue),
        'status'                    => $row['status'],
        'remarks'                   => $row['remarks'],
        'grave' => [
            // interments.grave_id is nullable, so never blind-cast it.
            'grave_id'     => $row['grave_id'] !== null ? (int)$row['grave_id'] : null,
            'grave_code'   => $row['grave_code'],
            'grave_status' => $row['grave_status'] ?? null,
            'remarks'      => $row['grave_remarks'] ?? null
        ],
        'block' => [
            'block_id'         => isset($row['block_id']) && $row['block_id'] !== null ? (int)$row['block_id'] : null,
            'block_name'       => $row['block_name'],
            'block_type'       => $row['block_type'] ?? null,
            'owner_contact_id' => !empty($row['owner_contact_id']) ? (int)$row['owner_contact_id'] : null,
            'remarks'          => $row['block_remarks'] ?? null
        ],
        'deceased' => [
            'deceased_id'        => (int)$row['deceased_id'],
            'name'               => $row['deceased_name'],
            'sex'                => $row['deceased_sex'] ?? null,
            'date_of_birth'      => $row['date_of_birth'] ?? null,
            'date_of_death'      => $row['date_of_death'] ?? null,
            'death_certificate'  => $row['death_certificate'] ?? null,
            'last_known_address' => $row['last_known_address'] ?? null,
            'remarks'            => $row['deceased_remarks'] ?? null,
            'is_archived'        => ($row['deceased_deleted'] ?? null) !== null
        ],
        'contact' => [
            'contact_id'    => $row['contact_id'] !== null ? (int)$row['contact_id'] : null,
            'name'          => $row['contact_name'],
            'phone_number'  => $row['contact_phone'],
            'address'       => $row['contact_address'] ?? null,
            'barangay'      => $row['contact_barangay'] ?? null,
            'email_address' => $row['contact_email'] ?? null,
            'remarks'       => $row['contact_remarks'] ?? null,
            'is_archived'   => ($row['contact_deleted'] ?? null) !== null
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
    $email = trim($ownerData['email_address'] ?? '');

    // --- FIX: Format & Validate early, and THROW an exception on failure ---
    $phone = trim($ownerData['phone_number'] ?? '');
    if (!empty($phone)) {
        $phone = formatPhNumber($phone);
        if (!$phone) {
            throw new Exception("Invalid Philippines phone number format.", 400);
        }
    }

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
    if (!in_array($sex, ['Male', 'Female', 'Unknown'], true)) {
        throw new Exception("Invalid sex. Use Male, Female or Unknown.", 400);
    }

    $dob = !empty($decData['date_of_birth']) ? $decData['date_of_birth'] : null;
    $dod = !empty($decData['date_of_death']) ? $decData['date_of_death'] : null;

    $stmt = $pdo->prepare("SELECT deceased_id FROM deceased WHERE name = ? AND sex = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$name, $sex]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) return $existingId;

    $certificate = trim($decData['death_certificate'] ?? '');
    $lastAddress = trim($decData['last_known_address'] ?? '');
    $insertStmt = $pdo->prepare("
        INSERT INTO deceased (name, sex, date_of_birth, date_of_death, death_certificate, last_known_address, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $name, $sex, $dob, $dod,
        $certificate !== '' ? $certificate : null,
        $lastAddress,
        $userId
    ]);
    return $pdo->lastInsertId();
};

// ==========================================
// GET: RETRIEVE AVAILABLE GRAVES CATALOG
// ==========================================
if ($method === 'GET') {
    $rawLimit = $_GET['limit'] ?? 50;
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? min((int)$rawLimit, 500) : 50;
    $searchTerm = substr(trim((string)($_GET['search'] ?? '')), 0, 100);

    // A named placeholder may only appear ONCE per statement when PDO is not
    // emulating prepares, and this catalog is a three-branch UNION that filters on
    // the same search term in every branch. Each branch therefore gets its own
    // prefix, generated from the column list so nothing is counted by hand.
    $buildSearch = function (array $columns, string $prefix) use ($searchTerm) {
        if ($searchTerm === '') return ['', []];

        $clauses = [];
        $binds = [];
        foreach ($columns as $index => $column) {
            $key = ':' . $prefix . $index;
            $clauses[] = "$column LIKE $key";
            $binds[$key] = '%' . $searchTerm . '%';
        }
        return [' AND (' . implode(' OR ', $clauses) . ')', $binds];
    };

    $occupiedSearchColumns = [
        'g.grave_code', 'b.block_name', 'b.block_type',
        'i.control_number', 'i.remarks',
        'd.name', 'c.name', 'c.phone_number'
    ];
    $vacantSearchColumns = ['g.grave_code', 'b.block_name', 'b.block_type'];

    list($searchExpired,  $bindExpired)  = $buildSearch($occupiedSearchColumns, 'ex');
    list($searchExpiring, $bindExpiring) = $buildSearch($occupiedSearchColumns, 'xp');
    list($searchVacant,   $bindVacant)   = $buildSearch($vacantSearchColumns, 'vc');

    $catalogBindings = array_merge($bindExpired, $bindExpiring, $bindVacant);

    $occupiedJoins = "
        FROM interments i
        JOIN graves g        ON i.grave_id = g.grave_id
        LEFT JOIN blocks b   ON g.block_id = b.block_id
        LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id
    ";
    $vacantJoins = "
        FROM graves g
        LEFT JOIN blocks b ON g.block_id = b.block_id
    ";

    // The catalog must only advertise graves that api/reserve POST will actually
    // accept, so it applies the same exclusions graveIntakeBlocker() does:
    //   - Reserved / Pending Exhumation  -> already inside a live staging
    //   - Under Maintenance              -> administratively closed
    // The archive guards were previously missing from the two lease branches, so a
    // soft-deleted grave or block still showed up as reservable.
    // (b.deleted_at IS NULL also passes for a grave with no block, since a missing
    //  LEFT JOIN row yields NULL.)
    $liveGrave = "g.deleted_at IS NULL
                  AND b.deleted_at IS NULL
                  AND g.status NOT IN ('Reserved', 'Pending Exhumation', 'Under Maintenance')";

    $whereExpired = "WHERE i.deleted_at IS NULL
                       AND i.status IN ('Active', 'Expired')
                       AND i.lease_expiration_date IS NOT NULL
                       AND i.lease_expiration_date < CURDATE()
                       AND $liveGrave
                       $searchExpired";

    $whereExpiring = "WHERE i.deleted_at IS NULL
                        AND i.status = 'Active'
                        AND i.lease_expiration_date IS NOT NULL
                        AND i.lease_expiration_date BETWEEN CURDATE()
                            AND DATE_ADD(CURDATE(), INTERVAL " . RESERVE_EXPIRING_WINDOW_DAYS . " DAY)
                        AND $liveGrave
                        $searchExpiring";

    $whereVacant = "WHERE g.status = 'Vacant'
                      AND g.deleted_at IS NULL
                      AND b.deleted_at IS NULL
                      $searchVacant";

    // ONE keyset union over all three sources. The old version ran three separate
    // queries against a single shared LIMIT/OFFSET and a total_pages derived from
    // max(count) — so page 2 skipped `limit` rows of EVERY list independently and
    // the short lists silently ran dry while the page counter kept going.
    $catalogUnion = "
        SELECT 'expired' AS availability, i.interment_id AS interment_id,
               g.grave_id AS grave_id, i.lease_expiration_date AS sort_date
        $occupiedJoins
        $whereExpired

        UNION ALL

        SELECT 'expiring', i.interment_id, g.grave_id, i.lease_expiration_date
        $occupiedJoins
        $whereExpiring

        UNION ALL

        SELECT 'vacant', NULL, g.grave_id, NULL
        $vacantJoins
        $whereVacant
    ";

    // 1. Breakdown + total in a single pass, so the summary line and the page can
    //    never disagree about what the filter matched.
    $breakdownStmt = $pdo->prepare("
        SELECT availability, COUNT(*) AS row_count
        FROM ($catalogUnion) AS catalog
        GROUP BY availability
    ");
    foreach ($catalogBindings as $key => $value) {
        $breakdownStmt->bindValue($key, $value);
    }
    $breakdownStmt->execute();

    $counts = ['vacant' => 0, 'expired' => 0, 'expiring' => 0];
    foreach ($breakdownStmt->fetchAll(PDO::FETCH_ASSOC) as $bucket) {
        $counts[$bucket['availability']] = (int)$bucket['row_count'];
    }
    $totalRecords = array_sum($counts);

    // 2. Pagination Math (one list, one offset)
    $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;
    $rawPage = $_GET['page'] ?? 1;
    $page = (!is_numeric($rawPage) || $rawPage < 1) ? 1 : min((int)$rawPage, $totalPages);
    $offset = ($page - 1) * $limit;

    // 3. The page itself: keys only. Vacant first, then the overdue leases, then
    //    the ones about to lapse — the order reserve.js already renders in.
    $pageStmt = $pdo->prepare("
        SELECT availability, interment_id, grave_id
        FROM ($catalogUnion) AS catalog
        ORDER BY FIELD(availability, 'vacant', 'expired', 'expiring'),
                 sort_date ASC, grave_id ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($catalogBindings as $key => $value) {
        $pageStmt->bindValue($key, $value);
    }
    $pageStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $pageStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $pageStmt->execute();
    $pageKeys = $pageStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Hydrate the page in two batch reads instead of per-row queries.
    $intermentIds = [];
    $vacantGraveIds = [];
    foreach ($pageKeys as $key) {
        if ($key['availability'] === 'vacant') {
            $vacantGraveIds[] = (int)$key['grave_id'];
        } elseif ($key['interment_id'] !== null) {
            $intermentIds[] = (int)$key['interment_id'];
        }
    }
    $intermentIds = array_values(array_unique($intermentIds));
    $vacantGraveIds = array_values(array_unique($vacantGraveIds));

    $intermentsById = [];
    if ($intermentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($intermentIds), '?'));
        $hydrateStmt = $pdo->prepare("
            SELECT i.*,
                   DATEDIFF(CURDATE(), i.lease_expiration_date) AS days_overdue,
                   DATEDIFF(i.lease_expiration_date, CURDATE()) AS days_remaining,
                   g.grave_code, g.status AS grave_status, g.remarks AS grave_remarks,
                   b.block_id, b.block_name, b.block_type, b.owner_contact_id,
                   b.remarks AS block_remarks,
                   d.name AS deceased_name, d.sex AS deceased_sex, d.date_of_birth,
                   d.date_of_death, d.death_certificate, d.last_known_address,
                   d.remarks AS deceased_remarks, d.deleted_at AS deceased_deleted,
                   c.name AS contact_name, c.phone_number AS contact_phone,
                   c.address AS contact_address, c.barangay AS contact_barangay,
                   c.email_address AS contact_email,
                   c.remarks AS contact_remarks, c.deleted_at AS contact_deleted
            FROM interments i
            LEFT JOIN graves g   ON i.grave_id = g.grave_id
            LEFT JOIN blocks b   ON g.block_id = b.block_id
            LEFT JOIN deceased d ON i.deceased_id = d.deceased_id
            LEFT JOIN contacts c ON i.contact_id = c.contact_id
            WHERE i.interment_id IN ($placeholders)
        ");
        $hydrateStmt->execute($intermentIds);
        foreach ($hydrateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $intermentsById[(int)$row['interment_id']] = $row;
        }
    }

    $vacantById = [];
    if ($vacantGraveIds !== []) {
        $placeholders = implode(',', array_fill(0, count($vacantGraveIds), '?'));
        $vacantStmt = $pdo->prepare("
            SELECT g.grave_id, g.grave_code, g.row_num, g.col_num, g.remarks,
                   b.block_id, b.block_name, b.block_type
            FROM graves g
            LEFT JOIN blocks b ON g.block_id = b.block_id
            WHERE g.grave_id IN ($placeholders)
        ");
        $vacantStmt->execute($vacantGraveIds);
        foreach ($vacantStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vacantById[(int)$row['grave_id']] = [
                'availability' => 'vacant',
                'grave_id'     => (int)$row['grave_id'],
                'grave_code'   => $row['grave_code'],
                'row_num'      => $row['row_num'] !== null ? (int)$row['row_num'] : null,
                'col_num'      => $row['col_num'] !== null ? (int)$row['col_num'] : null,
                'remarks'      => $row['remarks'],
                'block_id'     => $row['block_id'] !== null ? (int)$row['block_id'] : null,
                'block_name'   => $row['block_name'],
                'block_type'   => $row['block_type']
            ];
        }
    }

    // 5. Rebuild in page order. The three legacy arrays are kept because reserve.js
    //    reads them, and `catalog` carries the same rows already interleaved in the
    //    order the union produced them.
    $catalogRows = [];
    $vacantData = [];
    $expiredData = [];
    $expiringData = [];

    foreach ($pageKeys as $key) {
        if ($key['availability'] === 'vacant') {
            $entry = $vacantById[(int)$key['grave_id']] ?? null;
            if (!$entry) continue;
            $vacantData[] = $entry;
            $catalogRows[] = $entry;
            continue;
        }

        $row = $intermentsById[(int)$key['interment_id']] ?? null;
        if (!$row) continue;

        $entry = $formatIntermentRow($row, $key['availability']);
        if ($key['availability'] === 'expired') {
            $expiredData[] = $entry;
        } else {
            $expiringData[] = $entry;
        }
        $catalogRows[] = $entry;
    }

    // An empty catalog means every grave is taken, or the search matched nothing.
    // Both are valid answers — the old 404 made reserve.js print an error banner.
    Response::success("Reservation Catalog retrieved", [
        "search_term" => $searchTerm !== '' ? $searchTerm : null,
        "pagination" => [
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_pages'   => $totalPages,
            'total_records' => $totalRecords,
            'breakdown'     => [
                'expired_count'  => $counts['expired'],
                'expiring_count' => $counts['expiring'],
                'vacant_count'   => $counts['vacant']
            ]
        ],
        "expiring_window_days" => RESERVE_EXPIRING_WINDOW_DAYS,
        "catalog"  => $catalogRows,
        "vacant"   => $vacantData,
        "expired"  => $expiredData,
        "expiring" => $expiringData
    ]);
}

// ==========================================
// POST: INTAKE NEW PENDING INTERMENT & STAGE TRANSITION
// ==========================================
if ($method === 'POST') {

    $graveId = !empty($rawData['grave_id']) ? (int)$rawData['grave_id'] : null;
    $controlNumber = trim($rawData['control_number'] ?? '');
    $oldDestination = $rawData['old_occupant_destination'] ?? null;

    if (!$graveId || empty($controlNumber)) {
        Response::error("Control number and grave ID are required.", 400);
    }

    try {
        $pdo->beginTransaction();

        // 1. Lock the grave row, then run the SHARED intake gate. Reserve is the one
        //    intake path that WELCOMES an occupied grave — that is the whole feature —
        //    so co-interment is allowed here and the outgoing snapshot below decides
        //    what happens to whoever is already inside.
        $graveStmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
        $graveStmt->execute([$graveId]);
        $currentGraveStatus = $graveStmt->fetchColumn();

        if (!$currentGraveStatus) throw new Exception("Grave does not exist.", 404);

        $blocker = graveIntakeBlocker($pdo, $graveId, true);
        if ($blocker) throw new Exception($blocker['message'], $blocker['code']);

        // 2. Resolve Snapshot & Destination if the grave is Occupied
        $outgoingIdsJson = '[]';
        $destType = 'none';
        $destGraveId = null;
        $destNotes = null;
        $priorStatus = 'Vacant';

        // Trust the occupant count, not the stored label. recomputeGraveStatus keeps
        // graves.status honest, but a grave that was left mislabelled still holds
        // bodies, and skipping the snapshot would let execute_transition bury the
        // new record on top of them.
        $outStmt = $pdo->prepare("
            SELECT interment_id
            FROM interments
            WHERE grave_id = ? AND status IN ('Active', 'Expired') AND deleted_at IS NULL
        ");
        $outStmt->execute([$graveId]);
        $outgoingIds = array_map('intval', $outStmt->fetchAll(PDO::FETCH_COLUMN));

        if ($outgoingIds !== []) {
            $priorStatus = 'Occupied';
            $outgoingIdsJson = json_encode($outgoingIds);

            // Validate Destination payload
            if (!is_array($oldDestination) || empty($oldDestination['type'])) {
                throw new Exception(
                    "This grave holds " . count($outgoingIds) . " record(s). Say where those remains are going before reserving it.",
                    400
                );
            }

            // Shared with Monitor's edit + finalize paths, so all three agree on
            // which graves may receive remains.
            $destination = resolveOutgoingDestination($pdo, $oldDestination, $graveId);
            $destType    = $destination['type'];
            $destGraveId = $destination['grave_id'];
            $destNotes   = $destination['notes'];

            // Hold the destination row for the rest of the transaction so two
            // reservations cannot both claim the same empty niche.
            if ($destGraveId !== null) {
                $lockDest = $pdo->prepare("SELECT grave_id FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
                $lockDest->execute([$destGraveId]);
                if (!$lockDest->fetchColumn()) throw new Exception("Destination grave does not exist.", 404);
            }
        } else {
            $priorStatus = ($currentGraveStatus === 'Under Maintenance') ? $currentGraveStatus : 'Vacant';
        }

        // 3. Resolve the new family & body
        $contactId = $resolveContact($rawData['contact'] ?? [], $pdo, $userData['user_id']);
        $deceasedId = $resolveDeceased($rawData['deceased'] ?? [], $pdo, $userData['user_id']);

        if (!$contactId) throw new Exception("Requesting Party information is required.", 400);
        if (!$deceasedId) throw new Exception("Deceased information is required.", 400);

        $assistanceType = trim($rawData['assistance_type'] ?? 'Burial');
        if ($assistanceType === '') $assistanceType = 'Burial';
        if (!in_array($assistanceType, ['Burial', 'Transfer', 'Other'], true)) {
            throw new Exception("Invalid assistance type. Use Burial, Transfer or Other.", 400);
        }

        // Blank strings must reach DATE columns as NULL, never as ''.
        $dateOrNull = function ($value) {
            $value = $value === null ? '' : trim((string)$value);
            return $value === '' ? null : $value;
        };

        // 4. Create the Incoming Pending Interment (No JSON Hacks!)
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
            $controlNumber, $deceasedId, $graveId, $contactId,
            $assistanceType,
            trim($rawData['burial_permit_number'] ?? ''),
            $dateOrNull($rawData['burial_permit_date'] ?? null),
            trim($rawData['transfer_permit_number'] ?? ''),
            trim($rawData['transfer_permit_issued_by'] ?? ''),
            $dateOrNull($rawData['transfer_permit_date'] ?? null),
            trim($rawData['exhumation_permit_number'] ?? ''),
            $dateOrNull($rawData['exhumation_permit_date'] ?? null),
            $dateOrNull($rawData['date_buried'] ?? null),
            $dateOrNull($rawData['clearance_date'] ?? null),
            $dateOrNull($rawData['lease_expiration_date'] ?? null),
            trim($rawData['remarks'] ?? ''), // Pure text note now!
            $userData['user_id']
        ]);

        $newIntermentId = $pdo->lastInsertId();

        // 5. Create the authoritative Transition Staging Record
        $transitionStmt = $pdo->prepare("
            INSERT INTO grave_transitions (
                grave_id, incoming_interment_id, outgoing_interment_ids,
                outgoing_destination, destination_grave_id, destination_notes,
                status, prior_grave_status, staged_by
            ) VALUES (?, ?, ?, ?, ?, ?, 'Staged', ?, ?)
        ");
        $transitionStmt->execute([
            $graveId, $newIntermentId, $outgoingIdsJson,
            $destType, $destGraveId, $destNotes,
            $priorStatus, $userData['user_id']
        ]);

        // 6. Magically lock the grave with our new helper
        $newGraveStatus = recomputeGraveStatus($pdo, $graveId);

        $pdo->commit();
        systemLog($userData['name'] . " initiated staging for Grave ID: " . $graveId, $userData['user_id']);

        Response::success("Intake successful. Grave is now locked for staging.", [
            "pending_interment_id" => $newIntermentId,
            "new_grave_status"     => $newGraveStatus,
            "outgoing_count"       => count($outgoingIds)
        ], 201);

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $code = $e->getCode() ?: 500;
        $message = $e->getMessage();

        // Handle specific HTTP error codes FIRST. The date sniffing below matches on
        // the bare word 'Date', which used to swallow deliberate 400/404/409
        // messages like "A different destination grave is required."
        if (in_array($code, [400, 404, 409])) {
            Response::error($message, $code);
        }

        // Check for invalid date/time format errors
        if (
            stripos($message, 'Incorrect datetime value') !== false ||
            stripos($message, 'Invalid date') !== false ||
            stripos($message, 'DATETIME') !== false ||
            stripos($message, 'TIMESTAMP') !== false ||
            stripos($message, 'Date') !== false
        ) {
            Response::error("Invalid date/time format. Please use YYYY-MM-DD.", 400);
        }

        if (stripos($message, 'Duplicate entry') !== false) {
            // Either the control number, or uniq_live_staging catching a race that
            // slipped past the intake gate.
            Response::error("Conflict: that control number is already in use, or this grave was just reserved by someone else.", 409);
        }

        // Default database error
        Response::error("Database error while filing the reservation. " . $message, 500);
    }
}

Response::error("Method not allowed", 405);
?>
