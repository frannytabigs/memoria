<?php

require_once 'responses.php';

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
        if ($data['blocked_until'] == 0) {
            $data['blocked_until'] = $now + $period;
        }
        
        file_put_contents($file, json_encode($data));
        
        Response::error("Slow down! Please try again later", 429);
    }

    $data['requests'][] = $now;
    file_put_contents($file, json_encode($data));
}

handleRateLimit(1,1);

?>