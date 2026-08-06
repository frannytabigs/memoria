<?php

require_once 'notallowed.php';

header('Content-Type: application/json');

function handleRateLimit($limit = 30, $period = 60) {
    $userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hash = md5($userIp);
    $cacheDir = __DIR__ . '/cache';
    
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
    
    $file = "$cacheDir/rate_$hash.json";
    $now = time();
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['requests' => [], 'blocked_until' => 0];

    $data['requests'] = array_filter($data['requests'], fn($ts) => $ts > ($now - $period));
    $currentUsage = count($data['requests']);

    header("X-RateLimit-Limit: $limit");
    header("X-RateLimit-Remaining: " . max(0, $limit - $currentUsage - 1));
    header("X-RateLimit-Reset: " . ($now + $period));

    if ($now < $data['blocked_until'] || $currentUsage >= $limit) {
        if ($data['blocked_until'] <= $now) {
            $data['blocked_until'] = $now + $period;
        }
        
        file_put_contents($file, json_encode($data));
        
        require_once 'logs.php';
        require_once 'checkuser.php';

        // FIX: Subtract Now from Blocked_Until to get a positive integer
        $remaining = $data['blocked_until'] - $now;

        $userData = checkuser(false);
        if ($userData) {
            systemLog($userData['name'] . " (" . $userData['username'] . ") exceeded rate limit. $limit requests in $period seconds only please. $remaining seconds remaining.", $userData['user_id']); 
        } else {
            systemLog("Rate limit exceeded for IP: $userIp. $limit requests in $period seconds only please. $remaining seconds remaining.", "Not logged in user");
        }
        
        Response::error("My apologies, please slow down, you are exhausting the system resources. Please try again later in " . $remaining . " seconds.", 429);
    }

    $data['requests'][] = $now;
    file_put_contents($file, json_encode($data));
}

handleRateLimit(30, 60); // Limit to 30 requests per 60 seconds

?>