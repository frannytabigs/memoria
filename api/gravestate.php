<?php
// api/gravestate.php
//
// Single source of truth for "what state is this grave in, and may it take a
// body right now?". Every module that touches interments or transitions must
// go through here so Records, Reserve and Monitor cannot disagree.

require_once 'notallowed.php';

/**
 * The one live staging (Staged + not deleted) for a grave, if any.
 * `grave_transitions.uniq_live_staging` guarantees there is at most one.
 *
 * @return array|null
 */
function getLiveStaging(PDO $pdo, int $graveId): ?array {
    $stmt = $pdo->prepare("
        SELECT transition_id, incoming_interment_id, outgoing_interment_ids,
               outgoing_destination, destination_grave_id, destination_notes,
               prior_grave_status, staged_by, created_at
        FROM grave_transitions
        WHERE grave_id = ? AND status = 'Staged' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$graveId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * How many bodies physically occupy a grave right now.
 * 'Expired' still counts — the lease lapsed but the remains are still in there.
 */
function countGraveOccupants(PDO $pdo, int $graveId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM interments
        WHERE grave_id = ? AND status IN ('Active', 'Expired') AND deleted_at IS NULL
    ");
    $stmt->execute([$graveId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Recomputes and updates the authoritative status of a grave.
 * Call this whenever an interment or transition is created, updated, or deleted.
 *
 * @return string 'Vacant' | 'Occupied' | 'Reserved' | 'Pending Exhumation' | 'Under Maintenance'
 */
function recomputeGraveStatus(PDO $pdo, int $graveId): string {
    // Read the stored status first: 'Under Maintenance' is an administrative
    // flag that no automatic recomputation is allowed to silently clear.
    $currentStmt = $pdo->prepare("SELECT status FROM graves WHERE grave_id = ? LIMIT 1");
    $currentStmt->execute([$graveId]);
    $currentStatus = $currentStmt->fetchColumn();
    if ($currentStatus === false) {
        // Nothing to recompute; caller is responsible for validating the id.
        return 'Vacant';
    }

    $liveStaging = getLiveStaging($pdo, $graveId);

    if ($liveStaging) {
        // If the grave had bodies before staging it is pending an exhumation.
        // If it was empty, it is merely reserved for the incoming record.
        $computedStatus = ($liveStaging['prior_grave_status'] === 'Occupied')
            ? 'Pending Exhumation'
            : 'Reserved';
    } elseif (countGraveOccupants($pdo, $graveId) > 0) {
        $computedStatus = 'Occupied';
    } elseif ($currentStatus === 'Under Maintenance') {
        // Empty and flagged for maintenance: keep the flag. Only an explicit
        // PUT on api/graves may lift it.
        $computedStatus = 'Under Maintenance';
    } else {
        $computedStatus = 'Vacant';
    }

    if ($computedStatus !== $currentStatus) {
        $updateStmt = $pdo->prepare("UPDATE graves SET status = ?, updated_at = NOW() WHERE grave_id = ?");
        $updateStmt->execute([$computedStatus, $graveId]);
    }

    return $computedStatus;
}

/**
 * May this grave accept a NEW body right now?
 *
 * This is the shared gate for Records (api/interments POST) and Reserve
 * (api/reserve POST). Without it, Records can insert straight into a grave
 * that Monitor is mid-transition on.
 *
 * @param bool $allowCoInterment caller confirmed a merge (co-interment)
 * @return array|null null when the grave is available, otherwise
 *                    ['message' => string, 'code' => int]
 */
function graveIntakeBlocker(PDO $pdo, int $graveId, bool $allowCoInterment = false): ?array {
    $stmt = $pdo->prepare("SELECT grave_code, status FROM graves WHERE grave_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$graveId]);
    $grave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$grave) {
        return ['message' => "The selected grave does not exist or has been archived.", 'code' => 404];
    }

    $code = $grave['grave_code'];

    if (getLiveStaging($pdo, $graveId)) {
        return [
            'message' => "Conflict: grave {$code} already has a staged reservation waiting in the Monitor module. Finalize or cancel it there before adding another record.",
            'code'    => 409
        ];
    }

    if ($grave['status'] === 'Under Maintenance') {
        return [
            'message' => "Conflict: grave {$code} is marked Under Maintenance and cannot accept a burial.",
            'code'    => 409
        ];
    }

    $occupants = countGraveOccupants($pdo, $graveId);
    if ($occupants > 0 && !$allowCoInterment) {
        return [
            'message' => "Conflict: grave {$code} already holds " . $occupants . " record(s). To add ashes or transferred bones here, enable Merge (co-interment).",
            'code'    => 409
        ];
    }

    return null;
}

/**
 * The destinations grave_transitions.outgoing_destination accepts for remains
 * that actually have to come out. The column also allows 'none', but that value
 * means "the grave was empty when it was staged" and is never a user choice.
 */
const OUTGOING_DESTINATIONS = ['specific_grave', 'common_bone_chamber', 'family_custody', 'other'];

/**
 * Validates an outgoing-destination payload and resolves it to storable columns.
 *
 * Shared by Reserve (choosing the destination at intake) and Monitor (editing it,
 * and re-checking it at finalize time). Keeping one definition is what stops the
 * two modules from disagreeing about which graves may receive remains.
 *
 * Accepts ['type' => ..., 'grave_id' => ..., 'grave_code' => ..., 'notes' => ...].
 *
 * @throws Exception with a 400/404/409 code the caller can pass straight through
 * @return array ['type' => string, 'grave_id' => ?int, 'notes' => ?string]
 */
function resolveOutgoingDestination(PDO $pdo, array $payload, int $sourceGraveId): array {
    $type = strtolower(trim((string)($payload['type'] ?? '')));
    if (!in_array($type, OUTGOING_DESTINATIONS, true)) {
        throw new Exception(
            "Invalid destination. Choose one of: " . implode(', ', OUTGOING_DESTINATIONS) . ".",
            400
        );
    }

    $notes = trim((string)($payload['notes'] ?? ''));
    $destGraveId = null;

    if ($type === 'specific_grave') {
        $destGraveId = !empty($payload['grave_id']) ? (int)$payload['grave_id'] : null;

        // The UI may send a human-typed grave code instead of an id.
        if (!$destGraveId && !empty($payload['grave_code'])) {
            $codeStmt = $pdo->prepare("SELECT grave_id FROM graves WHERE grave_code = ? AND deleted_at IS NULL LIMIT 1");
            $codeStmt->execute([trim((string)$payload['grave_code'])]);
            $found = $codeStmt->fetchColumn();
            if (!$found) throw new Exception("The destination grave code does not exist.", 404);
            $destGraveId = (int)$found;
        }

        if (!$destGraveId) {
            throw new Exception("A destination grave is required when moving the remains to a specific grave.", 400);
        }
        if ($destGraveId === $sourceGraveId) {
            throw new Exception("The destination grave must be different from the grave being cleared.", 400);
        }

        // Same gate Records and Reserve use for an intake. Co-interment is allowed
        // on purpose: bone chambers and family plots routinely hold more than one
        // set of remains, so "already occupied" must not disqualify a destination.
        $blocker = graveIntakeBlocker($pdo, $destGraveId, true);
        if ($blocker) throw new Exception($blocker['message'], $blocker['code']);
    } elseif ($type === 'other' && $notes === '') {
        throw new Exception("Destination notes are required when the destination is 'other'.", 400);
    }

    return ['type' => $type, 'grave_id' => $destGraveId, 'notes' => $notes !== '' ? $notes : null];
}

/**
 * Is this interment currently captured by a live staging — either as the
 * incoming record or inside an outgoing snapshot? If so, only Monitor may
 * touch it, otherwise a Records edit/delete desynchronizes the transition.
 *
 * @return array|null the live transition row, or null
 */
function stagingLockFor(PDO $pdo, int $intermentId): ?array {
    $stmt = $pdo->prepare("
        SELECT transition_id, grave_id, incoming_interment_id, outgoing_interment_ids
        FROM grave_transitions
        WHERE status = 'Staged'
          AND deleted_at IS NULL
          AND (
                incoming_interment_id = ?
                OR JSON_CONTAINS(COALESCE(outgoing_interment_ids, '[]'), CAST(? AS JSON))
              )
        LIMIT 1
    ");
    $stmt->execute([$intermentId, (string)$intermentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
?>
