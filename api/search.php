<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'database.php';
require_once 'responses.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

if ($method !== 'GET') {
    // Added the missing semicolon here
    Response::error("Method not allowed", 405);
}

// Grab the search query from the URL (e.g., search.php?q=Hannah)
$searchQuery = $_GET['q'] ?? '';

if (empty($searchQuery)) {
    Response::error("Search query is required", 400);
}

try {

    $sql = "
        SELECT 
            d.deceased_id AS id,
            d.name,
            d.date_of_birth AS dateOfBirth,
            d.date_of_death AS dateOfDeath,
            b.block_name AS block,
            g.row_num AS `row`,
            g.col_num AS `column`,
            b.block_type AS graveType
        FROM deceased d
        JOIN interments i ON d.deceased_id = i.deceased_id
        JOIN graves g ON i.grave_id = g.grave_id
        JOIN blocks b ON g.block_id = b.block_id
        WHERE d.name LIKE :search
          AND i.status = 'Active' 
        LIMIT 11
    ";

    $stmt = $pdo->prepare($sql);
    
    // Using wildcard % to allow partial name searches
    $stmt->execute(['search' => '%' . $searchQuery . '%']);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)){
        Response::error("No results found for: ". $searchQuery, 404);
    }
    Response::success("Search results for: " . $searchQuery, $results);


} catch (PDOException $e) {
    // Logs the actual error but sends a generic message to the frontend for security
    error_log("Search Error: " . $e->getMessage());
    Response::error("An error occurred while searching the records.", 500);
}
?>