<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logs.php';
require_once 'gravestate.php'; // The single source of truth for statuses

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$userData = checkuser();

// ---------------------------------------------------------
// 1. GATEKEEPER & PRIVACY CLEARANCE
// ---------------------------------------------------------
$role = $userData['role'] ?? null;

$isAuthorizedStaff = in_array($role, [ROLE_ADMIN, ROLE_OFFICE]);

if (!$isAuthorizedStaff) {
    Response::error("Forbidden. You do not have permission to access the staging area.", 403);
}

// --- REST ROUTING: PARSE THE URI ---
$pathInfo = $_GET['path_info'] ?? $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts);

$rawData = array_merge(
    json_decode(file_get_contents("php://input"), true) ?: [],
    $_POST ?? []
);

// ---------------------------------------------------------
// HELPER: FORMAT INTERMENT (Standardized)
// ---------------------------------------------------------
// Every field the Monitor edit modal can display must appear here. When it does
// not, the modal renders a blank input and then saves that blank back over real
// data — that is how date_of_birth, address, barangay and email were being wiped.
$formatIntermentRow = function ($row) {
    if (!$row) return null;
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
        'date_exhumed'              => $row['date_exhumed'] ?? null,
        'clearance_date'            => $row['clearance_date'],
        'status'                    => $row['status'],
        'lease_expiration_date'     => $row['lease_expiration_date'],
        'remarks'                   => $row['remarks'], // Pure text now!
        'grave' => [
            // interments.grave_id is nullable, so never blind-cast it.
            'grave_id'   => $row['grave_id'] !== null ? (int)$row['grave_id'] : null,
            'grave_code' => $row['grave_code'],
            'remarks'    => $row['grave_remarks']
        ],
        'block' => [
            'block_id'         => $row['block_id'] !== null ? (int)$row['block_id'] : null,
            'block_name'       => $row['block_name'],
            'owner_contact_id' => $row['owner_contact_id'] ? (int)$row['owner_contact_id'] : null,
            'remarks'          => $row['block_remarks']
        ],
        'deceased' => [
            'deceased_id'        => (int)$row['deceased_id'],
            'name'               => $row['deceased_name'],
            'sex'                => $row['deceased_sex'],
            'date_of_birth'      => $row['date_of_birth'] ?? null,
            'date_of_death'      => $row['date_of_death'],
            'death_certificate'  => $row['death_certificate'],
            'last_known_address' => $row['last_known_address'] ?? null,
            'remarks'            => $row['deceased_remarks'],
            'is_archived'        => $row['deceased_deleted'] !== null
        ],
        'contact' => [
            'contact_id'    => $row['contact_id'] !== null ? (int)$row['contact_id'] : null,
            'name'          => $row['contact_name'],
            'phone_number'  => $row['contact_phone'],
            'address'       => $row['contact_address'] ?? null,
            'barangay'      => $row['contact_barangay'] ?? null,
            'email_address' => $row['contact_email'] ?? null,
            'remarks'       => $row['contact_remarks'],
            'is_archived'   => $row['contact_deleted'] !== null
        ]
    ];
};

// One SELECT shape for both the incoming and the outgoing batch fetch, so the two
// sides of the Monitor table can never drift apart again.
$intermentBatchSql = function (int $count): string {
    $placeholders = implode(',', array_fill(0, $count, '?'));
    return "
        SELECT i.*,
               g.grave_code, g.remarks AS grave_remarks,
               b.block_id, b.block_name, b.owner_contact_id, b.remarks AS block_remarks,
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
    ";
};

// ==========================================
// GET: THE STAGING AREA DASHBOARD
// ==========================================
if ($method === 'GET') {

    $rawLimit = $_GET['limit'] ?? 50;
    $limit = (is_numeric($rawLimit) && (int)$rawLimit > 0) ? min((int)$rawLimit, 500) : 50;

    $searchTerm = substr(trim((string)($_GET['search'] ?? '')), 0, 100);
    $searchWhere = "";
    $searchBindings = [];

    // Distinct generated placeholders (:s0, :s1, ...) keep PDO from throwing HY093
    // and let the column list grow without anybody re-counting by hand.
    if ($searchTerm !== '') {
        $searchColumns = [
            'g.grave_code', 'g.status', 'b.block_name',
            'CAST(t.transition_id AS CHAR)', 't.outgoing_destination',
            't.destination_notes', 't.prior_grave_status', 'dg.grave_code',
            'i_inc.control_number', 'i_inc.remarks',
            'CAST(i_inc.lease_expiration_date AS CHAR)',
            'd_inc.name', 'c_inc.name', 'c_inc.phone_number'
        ];

        $clauses = [];
        foreach ($searchColumns as $index => $column) {
            $key = ':s' . $index;
            $clauses[] = "$column LIKE $key";
            $searchBindings[$key] = '%' . $searchTerm . '%';
        }
        $searchWhere = " AND (" . implode(" OR ", $clauses) . ")";
    }

    // Identical FROM/JOIN chain for the count and the page, so a search can never
    // report a total the page cannot produce.
    $stagingFrom = "
        FROM grave_transitions t
        JOIN graves g          ON t.grave_id = g.grave_id
        LEFT JOIN blocks b     ON g.block_id = b.block_id
        LEFT JOIN graves dg    ON t.destination_grave_id = dg.grave_id
        LEFT JOIN interments i_inc ON t.incoming_interment_id = i_inc.interment_id
        LEFT JOIN deceased d_inc   ON i_inc.deceased_id = d_inc.deceased_id
        LEFT JOIN contacts c_inc   ON i_inc.contact_id = c_inc.contact_id
        LEFT JOIN users u          ON t.staged_by = u.user_id
        WHERE t.status = 'Staged' AND t.deleted_at IS NULL
        $searchWhere
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) $stagingFrom");
    foreach ($searchBindings as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;

    $rawPage = $_GET['page'] ?? 1;
    $page = (!is_numeric($rawPage) || $rawPage < 1) ? 1 : min((int)$rawPage, $totalPages);
    $offset = ($page - 1) * $limit;

    $paginationData = [
        'current_page'  => $page,
        'per_page'      => $limit,
        'total_pages'   => $totalPages,
        'total_records' => $totalRecords
    ];

    // 1. Fetch live transitions (filtered by search)
    $stmtTransitions = $pdo->prepare("
        SELECT
            t.transition_id, t.grave_id, t.incoming_interment_id, t.outgoing_interment_ids,
            t.outgoing_destination, t.destination_grave_id, t.destination_notes,
            t.prior_grave_status, t.created_at AS staged_at,
            g.grave_code, g.status AS grave_status, g.remarks AS grave_remarks,
            b.block_id, b.block_name,
            dg.grave_code AS destination_grave_code,
            u.name AS staged_by_name
        $stagingFrom
        ORDER BY t.created_at ASC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($searchBindings as $key => $value) {
        $stmtTransitions->bindValue($key, $value);
    }
    $stmtTransitions->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtTransitions->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtTransitions->execute();

    $transitions = $stmtTransitions->fetchAll(PDO::FETCH_ASSOC);

    // An empty staging area is the normal state of a well-run cemetery, not a 404.
    if ($transitions === []) {
        Response::success("Staging area retrieved", [
            "search_term"  => $searchTerm !== '' ? $searchTerm : null,
            "pagination"   => $paginationData,
            "staging_list" => []
        ]);
    }

    // 2. Extract IDs for batch querying
    $incomingIds = array_values(array_filter(array_column($transitions, 'incoming_interment_id')));
    $outgoingIds = [];
    foreach ($transitions as $t) {
        $ids = json_decode((string)$t['outgoing_interment_ids'], true);
        if (is_array($ids)) {
            foreach ($ids as $id) {
                if (is_numeric($id)) $outgoingIds[] = (int)$id;
            }
        }
    }
    $outgoingIds = array_values(array_unique($outgoingIds));

    // 3. Fetch Incoming Interments
    $incomingRecords = [];
    if ($incomingIds !== []) {
        $stmtIncoming = $pdo->prepare($intermentBatchSql(count($incomingIds)));
        $stmtIncoming->execute($incomingIds);
        foreach ($stmtIncoming->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $incomingRecords[(int)$row['interment_id']] = $formatIntermentRow($row);
        }
    }

    // 4. Fetch Outgoing Interments
    $outgoingRecords = [];
    if ($outgoingIds !== []) {
        $stmtOutgoing = $pdo->prepare($intermentBatchSql(count($outgoingIds)));
        $stmtOutgoing->execute($outgoingIds);
        foreach ($stmtOutgoing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outgoingRecords[(int)$row['interment_id']] = $formatIntermentRow($row);
        }
    }

    // 5. Build the Side-by-Side Response
    $stagingList = [];
    foreach ($transitions as $t) {
        $destination = [
            'type'       => $t['outgoing_destination'],
            'grave_id'   => $t['destination_grave_id'] !== null ? (int)$t['destination_grave_id'] : null,
            'grave_code' => $t['destination_grave_code'],
            'notes'      => $t['destination_notes']
        ];

        $incRecord = $incomingRecords[(int)$t['incoming_interment_id']] ?? null;
        if ($incRecord) {
            // monitor.js reads the destination off the incoming record when it
            // opens the edit modal; the row-level copy below is the canonical one.
            $incRecord['old_occupant_destination'] = $destination;
        }

        // Every snapshotted occupant, not just the first. execute_transition
        // exhumes all of them, so showing one was actively misleading: a grave
        // holding three sets of bones looked like it held one.
        $outIds = json_decode((string)$t['outgoing_interment_ids'], true);
        $outRecords = [];
        if (is_array($outIds)) {
            foreach ($outIds as $id) {
                if (isset($outgoingRecords[(int)$id])) $outRecords[] = $outgoingRecords[(int)$id];
            }
        }

        $stagingList[] = [
            'transition' => [
                'transition_id'      => (int)$t['transition_id'],
                'staged_at'          => $t['staged_at'],
                'staged_by'          => $t['staged_by_name'],
                'prior_grave_status' => $t['prior_grave_status'],
                'destination'        => $destination
            ],
            'grave' => [
                'grave_id'     => (int)$t['grave_id'],
                'grave_code'   => $t['grave_code'],
                'grave_status' => $t['grave_status'],
                'block_id'     => $t['block_id'] !== null ? (int)$t['block_id'] : null,
                'block_name'   => $t['block_name'],
                'remarks'      => $t['grave_remarks']
            ],
            'outgoing_occupants' => $outRecords,
            'outgoing_count'     => count($outRecords),
            // Retained so an older cached monitor.js keeps rendering something
            // sensible instead of an empty column.
            'outgoing_occupant'  => $outRecords[0] ?? null,
            'incoming_occupant'  => $incRecord
        ];
    }

    Response::success("Staging area retrieved", [
        "search_term"  => $searchTerm !== '' ? $searchTerm : null,
        "pagination"   => $paginationData,
        "staging_list" => $stagingList
    ]);
}

// ==========================================
// PUT: EXECUTE TRANSITION OR EDIT RECORD
// ==========================================
if ($method === 'PUT') {
    $action = $rawData['action'] ?? 'edit_pending';

    // ---------------------------------------------------------
    // ACTION A: EXECUTE TRANSITION (The Checkmark)
    // ---------------------------------------------------------
    if ($action === 'execute_transition') {
        $graveId = !empty($rawData['grave_id']) ? (int)$rawData['grave_id'] : null;
        if (!$graveId) Response::error("Grave ID is required to execute transition.", 400);

        try {
            $pdo->beginTransaction();

            // Lock the source grave first, then the transition, so two staff
            // members hitting the checkmark at the same moment serialize here.
            $graveLock = $pdo->prepare("SELECT grave_id, grave_code FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
            $graveLock->execute([$graveId]);
            $sourceGrave = $graveLock->fetch(PDO::FETCH_ASSOC);
            if (!$sourceGrave) throw new Exception("The grave does not exist or has been archived.", 404);

            $tStmt = $pdo->prepare("SELECT * FROM grave_transitions WHERE grave_id = ? AND status = 'Staged' AND deleted_at IS NULL FOR UPDATE");
            $tStmt->execute([$graveId]);
            $transition = $tStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transition) throw new Exception("No active staging transition found for this grave.", 404);

            $destinationType = $transition['outgoing_destination'];
            $destGraveId = $transition['destination_grave_id'] !== null ? (int)$transition['destination_grave_id'] : null;

            $outgoingIds = [];
            $decodedOut = json_decode((string)$transition['outgoing_interment_ids'], true);
            if (is_array($decodedOut)) {
                foreach ($decodedOut as $id) {
                    if (is_numeric($id)) $outgoingIds[] = (int)$id;
                }
            }
            $outgoingIds = array_values(array_unique($outgoingIds));

            // Re-validate the destination AT EXECUTION TIME. It was chosen days or
            // weeks ago in Reserve; the destination grave may have been filled,
            // archived or staged since. The old code trusted the stored value and
            // carried a comment claiming the grave "will be locked at the end of
            // the transaction" — nothing ever locked it.
            if ($outgoingIds !== []) {
                if (!in_array($destinationType, OUTGOING_DESTINATIONS, true)) {
                    throw new Exception(
                        "This grave still holds " . count($outgoingIds) . " record(s) but no destination is set for them. " .
                        "Use Edit to say where the remains are going, then finalize.",
                        409
                    );
                }

                if ($destinationType === 'specific_grave') {
                    if (!$destGraveId) throw new Exception("The staged destination grave is missing. Use Edit to set it, then finalize.", 409);
                    if ($destGraveId === $graveId) throw new Exception("The destination grave cannot be the grave being cleared.", 409);

                    $destLock = $pdo->prepare("SELECT grave_id, grave_code FROM graves WHERE grave_id = ? AND deleted_at IS NULL FOR UPDATE");
                    $destLock->execute([$destGraveId]);
                    $destGrave = $destLock->fetch(PDO::FETCH_ASSOC);
                    if (!$destGrave) throw new Exception("The destination grave no longer exists. Use Edit to pick another one.", 409);

                    $blocker = graveIntakeBlocker($pdo, $destGraveId, true);
                    if ($blocker) throw new Exception($blocker['message'], $blocker['code']);
                }
            }

            // Execute Outgoing Exhumation/Transfer (using the exact IDs from the snapshot!)
            $movedCount = 0;
            if ($outgoingIds !== []) {
                $outPlaceholders = implode(',', array_fill(0, count($outgoingIds), '?'));

                if ($destinationType === 'specific_grave') {
                    // Move the remains to the destination grave. date_buried is the
                    // ORIGINAL burial date and is historical record — overwriting it
                    // with CURDATE() destroyed it. date_exhumed carries the date the
                    // remains were lifted and moved.
                    $moveParams = array_merge([$destGraveId, $userData['user_id']], $outgoingIds);
                    $moveOldStmt = $pdo->prepare("
                        UPDATE interments
                        SET grave_id = ?, status = 'Active', date_exhumed = CURDATE(),
                            updated_by = ?, updated_at = NOW()
                        WHERE interment_id IN ($outPlaceholders) AND deleted_at IS NULL
                    ");
                    $moveOldStmt->execute($moveParams);
                    $movedCount = $moveOldStmt->rowCount();
                } else {
                    // Standard exhumation or custody release
                    $newStatus = ($destinationType === 'family_custody') ? 'Moved to Family' : 'Exhumed';
                    $exhumeParams = array_merge([$newStatus, $userData['user_id']], $outgoingIds);
                    $exhumeStmt = $pdo->prepare("
                        UPDATE interments
                        SET status = ?, date_exhumed = CURDATE(), updated_by = ?, updated_at = NOW()
                        WHERE interment_id IN ($outPlaceholders) AND deleted_at IS NULL
                    ");
                    $exhumeStmt->execute($exhumeParams);
                    $movedCount = $exhumeStmt->rowCount();
                }
            }

            // Activate Incoming Interment. Pinning grave_id here guarantees the
            // activated record lands in the grave the transition is about, whatever
            // an intervening edit may have done.
            $activateStmt = $pdo->prepare("
                UPDATE interments
                SET grave_id = ?, status = 'Active', date_buried = COALESCE(date_buried, CURDATE()),
                    updated_by = ?, updated_at = NOW()
                WHERE interment_id = ? AND status = 'Pending' AND deleted_at IS NULL
            ");
            $activateStmt->execute([$graveId, $userData['user_id'], $transition['incoming_interment_id']]);

            // The old code marked the transition 'Completed' without ever checking
            // this. If the pending record had already been activated or deleted, the
            // transition closed with nobody actually buried.
            if ($activateStmt->rowCount() === 0) {
                throw new Exception(
                    "The incoming record is no longer pending — it may have already been finalized or cancelled. Refresh the Monitor list.",
                    409
                );
            }

            // Complete the Transition Record
            $completeStmt = $pdo->prepare("
                UPDATE grave_transitions
                SET status = 'Completed', confirmed_by = ?, confirmed_at = NOW(), updated_at = NOW()
                WHERE transition_id = ?
            ");
            $completeStmt->execute([$userData['user_id'], $transition['transition_id']]);

            // Recompute statuses using our helper!
            $sourceStatus = recomputeGraveStatus($pdo, $graveId);
            $destStatus = null;
            if ($destinationType === 'specific_grave' && $destGraveId) {
                $destStatus = recomputeGraveStatus($pdo, $destGraveId);
            }

            $pdo->commit();

            // A short count means a snapshotted record vanished between staging and
            // finalizing. The physical work still happened, so the transition stands,
            // but the discrepancy has to be on the record.
            if ($movedCount !== count($outgoingIds)) {
                systemLog(
                    "Transition #{$transition['transition_id']} on grave {$sourceGrave['grave_code']} expected " .
                    count($outgoingIds) . " outgoing record(s) but updated {$movedCount}.",
                    $userData['user_id']
                );
            }

            systemLog($userData['name'] . " executed physical transition for grave " . $sourceGrave['grave_code'], $userData['user_id']);
            Response::success("Transition complete. Record finalized.", [
                'grave_status'             => $sourceStatus,
                'destination_grave_status' => $destStatus,
                'outgoing_updated'         => $movedCount
            ]);

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getCode() ?: 500;
            $message = $e->getMessage();

            // Our own validation errors carry a real HTTP code, so let them through
            // first — the date sniffing below matches on the bare word 'Date' and
            // used to rewrite deliberate conflict messages.
            if (in_array($code, [400, 404, 409])) {
                Response::error($message, $code);
            }

            if (
                stripos($message, 'Incorrect datetime value') !== false ||
                stripos($message, 'Invalid date') !== false ||
                stripos($message, 'DATETIME') !== false ||
                stripos($message, 'TIMESTAMP') !== false ||
                stripos($message, 'Date') !== false
            ) {
                Response::error("Invalid date/time format. Please use YYYY-MM-DD.", 400);
            }

            Response::error("Database error while finalizing the transition. " . $message, 500);
        }
    }

    // ---------------------------------------------------------
    // ACTION B: EDIT PENDING (The Pencil Icon)
    // ---------------------------------------------------------
    else {
        $intermentId = !empty($rawData['interment_id']) ? (int)$rawData['interment_id'] : (is_numeric($resourceId) ? (int)$resourceId : 0);
        if (!$intermentId) Response::error("Pending interment ID is required.", 400);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM interments WHERE interment_id = ? AND status = 'Pending' AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute([$intermentId]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pending) throw new Exception("Pending reservation not found.", 404);

            $tStmt = $pdo->prepare("SELECT * FROM grave_transitions WHERE incoming_interment_id = ? AND status = 'Staged' AND deleted_at IS NULL FOR UPDATE");
            $tStmt->execute([$intermentId]);
            $transition = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$transition) throw new Exception("This record has no live staging transition. Refresh the Monitor list.", 404);

            // ---- Deceased -------------------------------------------------
            // Absent key = leave alone. Present-but-blank = clear. The old version
            // passed `$deceasedData['date_of_birth'] ?? null` straight into the
            // UPDATE, so every save from a modal that did not send the field wrote
            // a hard NULL over the real date of birth.
            $deceasedData = $rawData['deceased'] ?? [];
            if (is_array($deceasedData) && $deceasedData !== [] && $pending['deceased_id']) {
                $decSets = [];
                $decVals = [];

                foreach (['name', 'death_certificate', 'last_known_address', 'remarks'] as $col) {
                    if (!array_key_exists($col, $deceasedData)) continue;
                    $value = trim((string)($deceasedData[$col] ?? ''));
                    if ($col === 'name' && $value === '') continue; // NOT NULL, identifies the person
                    $decSets[] = "$col = ?";
                    $decVals[] = $value;
                }

                foreach (['date_of_birth', 'date_of_death'] as $col) {
                    if (!array_key_exists($col, $deceasedData)) continue;
                    $value = $deceasedData[$col] === null ? '' : trim((string)$deceasedData[$col]);
                    $decSets[] = "$col = ?";
                    $decVals[] = ($value === '') ? null : $value;
                }

                if (array_key_exists('sex', $deceasedData)) {
                    $sexValue = trim((string)$deceasedData['sex']);
                    if ($sexValue !== '') {
                        if (!in_array($sexValue, ['Male', 'Female', 'Unknown'], true)) {
                            throw new Exception("Invalid sex. Use Male, Female or Unknown.", 400);
                        }
                        $decSets[] = "sex = ?";
                        $decVals[] = $sexValue;
                    }
                }

                if ($decSets !== []) {
                    $decSets[] = "updated_by = ?";
                    $decVals[] = $userData['user_id'];
                    $decSets[] = "updated_at = NOW()";
                    $decVals[] = $pending['deceased_id'];

                    $deceasedStmt = $pdo->prepare(
                        "UPDATE deceased SET " . implode(", ", $decSets) . " WHERE deceased_id = ? AND deleted_at IS NULL"
                    );
                    $deceasedStmt->execute($decVals);
                }
            }

            // ---- Contact --------------------------------------------------
            $contactData = $rawData['contact'] ?? [];
            if (is_array($contactData) && $contactData !== [] && $pending['contact_id']) {
                $conSets = [];
                $conVals = [];

                foreach (['name', 'address', 'barangay', 'email_address', 'remarks'] as $col) {
                    if (!array_key_exists($col, $contactData)) continue;
                    $value = trim((string)($contactData[$col] ?? ''));
                    if ($col === 'name' && $value === '') continue; // NOT NULL
                    $conSets[] = "$col = ?";
                    $conVals[] = $value;
                }

                if (array_key_exists('phone_number', $contactData)) {
                    $rawPhone = trim((string)($contactData['phone_number'] ?? ''));
                    if ($rawPhone === '') {
                        $conSets[] = "phone_number = ?";
                        $conVals[] = null;
                    } else {
                        $phone = formatPhNumber($rawPhone);
                        if (!$phone) throw new Exception("Invalid Philippines phone number format.", 400);
                        $conSets[] = "phone_number = ?";
                        $conVals[] = $phone;
                    }
                }

                if ($conSets !== []) {
                    $conSets[] = "updated_by = ?";
                    $conVals[] = $userData['user_id'];
                    $conSets[] = "updated_at = NOW()";
                    $conVals[] = $pending['contact_id'];

                    $contactStmt = $pdo->prepare(
                        "UPDATE contacts SET " . implode(", ", $conSets) . " WHERE contact_id = ? AND deleted_at IS NULL"
                    );
                    $contactStmt->execute($conVals);
                }
            }

            // ---- The pending interment itself -----------------------------
            // `remarks` used to be written as trim($rawData['remarks'] ?? ''), which
            // blanked the note on every save that did not resend it.
            $sets = [];
            $vals = [];

            foreach ([
                'burial_permit_number', 'transfer_permit_number', 'transfer_permit_issued_by',
                'exhumation_permit_number', 'remarks'
            ] as $col) {
                if (!array_key_exists($col, $rawData)) continue;
                $sets[] = "$col = ?";
                $vals[] = trim((string)$rawData[$col]);
            }

            foreach ([
                'burial_permit_date', 'transfer_permit_date', 'exhumation_permit_date',
                'date_buried', 'clearance_date', 'lease_expiration_date'
            ] as $col) {
                if (!array_key_exists($col, $rawData)) continue;
                $value = $rawData[$col] === null ? '' : trim((string)$rawData[$col]);
                $sets[] = "$col = ?";
                $vals[] = ($value === '') ? null : $value;
            }

            if (array_key_exists('control_number', $rawData)) {
                $newControl = trim((string)($rawData['control_number'] ?? ''));
                if ($newControl !== '') { // UNIQUE NOT NULL: a blank means "unchanged"
                    $sets[] = "control_number = ?";
                    $vals[] = $newControl;
                }
            }

            if (array_key_exists('assistance_type', $rawData)) {
                $newType = trim((string)$rawData['assistance_type']);
                if ($newType !== '') {
                    if (!in_array($newType, ['Burial', 'Transfer', 'Other'], true)) {
                        throw new Exception("Invalid assistance type. Use Burial, Transfer or Other.", 400);
                    }
                    $sets[] = "assistance_type = ?";
                    $vals[] = $newType;
                }
            }

            if ($sets !== []) {
                $sets[] = "updated_by = ?";
                $vals[] = $userData['user_id'];
                $sets[] = "updated_at = NOW()";
                $vals[] = $intermentId;

                $update = $pdo->prepare(
                    "UPDATE interments SET " . implode(", ", $sets) .
                    " WHERE interment_id = ? AND status = 'Pending' AND deleted_at IS NULL"
                );
                $update->execute($vals);
            }

            // ---- The outgoing destination ---------------------------------
            // Now validated against the ENUM and it actually persists
            // destination_grave_id, so picking "move to a specific grave" no longer
            // degrades into a plain exhumation at finalize time. Editing this is
            // allowed for every destination type, including specific_grave — the
            // old code refused it and told the user to cancel and start over.
            $destData = $rawData['old_occupant_destination'] ?? null;
            if (is_array($destData) && !empty($destData['type'])) {
                $hasOutgoing = trim((string)$transition['outgoing_interment_ids']) !== ''
                    && json_decode((string)$transition['outgoing_interment_ids'], true) !== [];

                if (!$hasOutgoing) {
                    throw new Exception("This grave was empty when it was staged, so there is no outgoing destination to set.", 400);
                }

                $destination = resolveOutgoingDestination($pdo, $destData, (int)$transition['grave_id']);

                $tUpdate = $pdo->prepare("
                    UPDATE grave_transitions
                    SET outgoing_destination = ?, destination_grave_id = ?, destination_notes = ?, updated_at = NOW()
                    WHERE transition_id = ? AND status = 'Staged' AND deleted_at IS NULL
                ");
                $tUpdate->execute([
                    $destination['type'], $destination['grave_id'], $destination['notes'],
                    $transition['transition_id']
                ]);
            }

            $pdo->commit();
            systemLog($userData['name'] . " updated pending interment ID: " . $intermentId, $userData['user_id']);
            Response::success("Pending reservation updated.");

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getCode() ?: 500;
            $message = $e->getMessage();

            if (in_array($code, [400, 404, 409])) {
                Response::error($message, $code);
            }

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
                Response::error("Conflict: that control number is already in use.", 409);
            }

            Response::error("Database error while updating the record. " . $message, 500);
        }
    }
}

// ==========================================
// DELETE: CANCEL A STAGED TRANSITION
// ==========================================
if ($method === 'DELETE') {
    if (!is_numeric($resourceId)) Response::error("Interment ID required to cancel staging.", 400);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT transition_id, grave_id, prior_grave_status
            FROM grave_transitions
            WHERE incoming_interment_id = ? AND status = 'Staged' AND deleted_at IS NULL
            FOR UPDATE
        ");
        $stmt->execute([$resourceId]);
        $transition = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transition) throw new Exception("Active transition not found for this pending record.", 404);

        // Cancel the transition. The generated active_grave_id column drops out of
        // uniq_live_staging the moment status stops being 'Staged', which frees the
        // grave for a new reservation.
        $cancelStmt = $pdo->prepare("UPDATE grave_transitions SET status = 'Cancelled', updated_at = NOW() WHERE transition_id = ?");
        $cancelStmt->execute([$transition['transition_id']]);

        // Soft delete the pending incoming interment. Only 'Pending' — never touch a
        // record that has somehow already been activated.
        $delStmt = $pdo->prepare("
            UPDATE interments
            SET deleted_at = NOW(), updated_by = ?
            WHERE interment_id = ? AND status = 'Pending' AND deleted_at IS NULL
        ");
        $delStmt->execute([$userData['user_id'], $resourceId]);

        // Recalculate grave status! Never blindly guess 'Vacant' — the old occupants
        // are still in there and prior_grave_status says so.
        $newGraveStatus = recomputeGraveStatus($pdo, (int)$transition['grave_id']);

        $pdo->commit();
        systemLog($userData['name'] . " cancelled pending reservation ID: " . $resourceId, $userData['user_id']);
        Response::success("Reservation cancelled. The grave is now " . $newGraveStatus . ".", [
            'grave_status' => $newGraveStatus
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $e->getCode() ?: 500;
        if (in_array($code, [400, 404, 409])) Response::error($e->getMessage(), $code);
        // Never swallow the reason — a blank 500 here is unfixable from the UI.
        Response::error("Database error while cancelling. " . $e->getMessage(), 500);
    }
}

Response::error("Method not allowed", 405);
?>
