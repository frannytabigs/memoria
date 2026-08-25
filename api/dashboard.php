<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';

// ==========================================
// --- DASHBOARD VIEW LOGS ENDPOINT ---
// ==========================================

$method = $_SERVER['REQUEST_METHOD'] ?? null;

if ($method !== 'GET') {
    Response::error("Method not allowed", 405);
}

$userData = checkuser();
$userRole = $userData['role'];

// Validate against allowed roles explicitly for Tier 1
$allowedRoles = [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS];
if (!in_array($userRole, $allowedRoles)) {
    Response::error("Unauthorized access", 403);
}

// Initialize the response array
$dashboardData = [];

// ==========================================
// TIER 1: EVERYONE SEES THIS (Admin, Office, Grounds)
// ==========================================

// Grave Status Distribution (Calculates all statuses in one pass)
$stmt = $pdo->query("
    SELECT 
        category, COUNT(*) AS count
    FROM (
        SELECT 
            g.grave_id,
            CASE 
                WHEN g.status = 'Vacant' THEN 'Vacant'
                WHEN g.status = 'Reserved' THEN 'Reserved'
                WHEN i.lease_expiration_date <= CURDATE() THEN 'Expired'
                WHEN i.lease_expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring'
                WHEN g.status = 'Occupied' THEN 'Occupied'
                ELSE 'Other'
            END AS category
        FROM graves g
        LEFT JOIN (
            SELECT grave_id, MIN(lease_expiration_date) AS lease_expiration_date
            FROM interments
            WHERE status = 'Active' AND deleted_at IS NULL
            GROUP BY grave_id
        ) i ON g.grave_id = i.grave_id
        WHERE g.deleted_at IS NULL
    ) AS categorized_graves
    GROUP BY category
");

$graveDistribution = [
    'Vacant' => 0,
    'Expired' => 0,
    'Occupied' => 0,
    'Expiring' => 0,
    'Reserved' => 0
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cat = $row['category'];
    if (array_key_exists($cat, $graveDistribution)) {
        $graveDistribution[$cat] = (int)$row['count'];
    }
}
$dashboardData['grave_status_distribution'] = $graveDistribution;

// Re-use the distribution result to get available graves without a second query
$dashboardData['available_graves'] = $graveDistribution['Vacant'];

// Expiring Leases Count
$stmt = $pdo->query("
    SELECT COUNT(*) FROM interments 
    WHERE status = 'Active' 
    AND lease_expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
    AND deleted_at IS NULL
");
$dashboardData['expiring_leases_count'] = (int)$stmt->fetchColumn();

// Monthly Lease Expiration
$stmt = $pdo->query("
    SELECT DATE_FORMAT(lease_expiration_date, '%Y-%m') AS exp_month, COUNT(*) AS count 
    FROM interments 
    WHERE status = 'Active' 
    AND YEAR(lease_expiration_date) = YEAR(CURDATE()) 
    AND deleted_at IS NULL 
    GROUP BY exp_month 
    ORDER BY exp_month ASC 
");
$rawData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []; 
    
$monthlyExpiration = [];
$currentYear = date('Y');
    
for ($m = 1; $m <= 12; $m++) {
    $monthKey = $currentYear . '-' . str_pad($m, 2, "0", STR_PAD_LEFT);
    $monthlyExpiration[$monthKey] = (int)($rawData[$monthKey] ?? 0);
}
$dashboardData['monthly_lease_expiration'] = $monthlyExpiration;

// Payment Summary
$paymentWhere = "WHERE deleted_at IS NULL";
$paymentParams = [];
if ($userRole === ROLE_GROUNDS) {
    $paymentWhere .= " AND confirmed_office_staff IS NOT NULL";
}

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_count,
        -- COALESCE(SUM(amount), 0) AS total_amount,
        SUM(CASE WHEN confirmed_office_staff IS NULL THEN 1 ELSE 0 END) AS pending_office,
        SUM(CASE WHEN confirmed_office_staff IS NOT NULL AND confirmed_ground_staff IS NULL THEN 1 ELSE 0 END) AS pending_grounds,
        SUM(CASE WHEN confirmed_office_staff IS NOT NULL AND confirmed_ground_staff IS NOT NULL THEN 1 ELSE 0 END) AS completed
    FROM payments
    $paymentWhere
");
$stmt->execute($paymentParams);
$paymentSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$dashboardData['payments'] = [
    'total_count' => (int)($paymentSummary['total_count'] ?? 0),
    // 'total_amount' => (float)($paymentSummary['total_amount'] ?? 0),
    'pending_office' => (int)($paymentSummary['pending_office'] ?? 0),
    'pending_grounds' => (int)($paymentSummary['pending_grounds'] ?? 0),
    'completed' => (int)($paymentSummary['completed'] ?? 0)
];

// ==========================================
// TIER 3: ADMINISTRATOR ONLY
// ==========================================
if ($userRole === ROLE_ADMIN) {
    // Unverified Accounts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status != :status AND deleted_at IS NULL");
    $stmt->execute(['status' => 'Verified']);
    $dashboardData['unverified_accounts'] = (int)$stmt->fetchColumn();
}

// Finally, output the perfectly tailored JSON payload
Response::success($userRole . " Dashboard data retrieved successfully", $dashboardData);
?>