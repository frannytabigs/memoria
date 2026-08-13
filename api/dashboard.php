<?php
define('ITS_ME_JUSTTOVERIFY', true);

// require_once 'responses.php';
require_once 'checkuser.php';
// require_once 'database.php'; // Ensure $pdo is initialized here

// ==========================================
// --- DASHBOARD VIEW LOGS ENDPOINT ---
// ==========================================

$method = $_SERVER['REQUEST_METHOD'] ?? null;

if ($method !== 'GET') {
    Response::error("Method not allowed", 405);
}

$userData = checkuser();

if ($userData['status'] !== 'Verified') {
    Response::error("Unauthorized access. Your account is still not verified", 403);
}

// 1. Verify they have a valid role before doing anything
$allowedRoles = ['Administrator', 'Office Staff', 'Grounds Staff'];
if (!in_array($userData['role'], $allowedRoles)) {
    Response::error("Forbidden. Invalid role.", 403);
}

// Initialize the response array that we will dynamically build
$dashboardData = [];

// ==========================================
// TIER 1: EVERYONE SEES THIS (Admin, Office, Grounds)
// ==========================================

// Available (Vacant) Graves
$stmt = $pdo->query("SELECT COUNT(*) FROM graves WHERE status = 'Vacant' AND deleted_at IS NULL");
$dashboardData['available_graves'] = (int)$stmt->fetchColumn();

// Grave Status Distribution
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
$rawData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); 
    
$monthlyExpiration = [];
$currentYear = date('Y');
    
for ($m = 1; $m <= 12; $m++) {
    $monthKey = $currentYear . '-' . str_pad($m, 2, "0", STR_PAD_LEFT);
    $monthlyExpiration[$monthKey] = isset($rawData[$monthKey]) ? (int)$rawData[$monthKey] : 0;
}
$dashboardData['monthly_lease_expiration'] = $monthlyExpiration;

// ==========================================
// TIER 2: OFFICE STAFF & ADMIN ONLY
// ==========================================
if (in_array($userData['role'], ['Administrator', 'Office Staff'])) {
    
    // Total Interment Records
    $stmt = $pdo->query("SELECT COUNT(*) FROM interments WHERE deleted_at IS NULL");
    $dashboardData['total_interment_records'] = (int)$stmt->fetchColumn();

    // Expired Leases List
    $stmt = $pdo->query("
        SELECT 
            d.name AS deceased_name, 
            g.grave_code, 
            i.lease_expiration_date, 
            c.name AS contact_name, 
            c.phone_number, 
            i.remarks 
        FROM interments i
        JOIN graves g ON i.grave_id = g.grave_id
        JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id 
        WHERE i.status = 'Active' 
        AND i.lease_expiration_date <= CURDATE() 
        AND i.deleted_at IS NULL 
        ORDER BY i.lease_expiration_date ASC 
        LIMIT 5
    ");
    $dashboardData['expired_leases'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Expiring Leases List
    $stmt = $pdo->query("
        SELECT 
            d.name AS deceased_name, 
            g.grave_code, 
            i.lease_expiration_date, 
            c.name AS contact_name, 
            c.phone_number, 
            i.remarks 
        FROM interments i
        JOIN graves g ON i.grave_id = g.grave_id
        JOIN deceased d ON i.deceased_id = d.deceased_id
        LEFT JOIN contacts c ON i.contact_id = c.contact_id 
        WHERE i.status = 'Active' 
        AND i.lease_expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND i.deleted_at IS NULL 
        ORDER BY i.lease_expiration_date ASC 
        LIMIT 5
    ");
    $dashboardData['expiring_leases_list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==========================================
// TIER 3: ADMINISTRATOR ONLY
// ==========================================
if ($userData['role'] === 'Administrator') {
    // Unverified Accounts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status != :status AND deleted_at IS NULL");
    $stmt->execute(['status' => 'Verified']);
    $dashboardData['unverified_accounts'] = (int)$stmt->fetchColumn();
}

// ==========================================
// TIER 4: SIMPLE PAYMENT CONFIRMATION COUNTS
// ==========================================
$unconfirmedPayments = [];

$stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE confirmed_office_staff IS NULL AND deleted_at IS NULL");
$unconfirmedPayments['office_staff'] = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE confirmed_ground_staff IS NULL AND deleted_at IS NULL");
$unconfirmedPayments['grounds_staff'] = (int)$stmt->fetchColumn();


// Only attach the payments object to the dashboard if the user's role allows them to see it
if (!empty($unconfirmedPayments)) {
    $dashboardData['unconfirmed_payments'] = $unconfirmedPayments;
}
// Finally, output the perfectly tailored JSON payload
Response::success($userData['role'] . " Dashboard data retrieved successfully", $dashboardData);
?>