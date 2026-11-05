<?php

require_once 'notallowed.php';
require_once 'reusable_functions.php';

// Check if someone pasted a full URL into DB_HOST by mistake
$raw_db_host = env('DB_HOST', 'localhost');
$parsed_url = parse_url($raw_db_host);

if ($parsed_url && isset($parsed_url['host'])) {
    // If it was a full URL (e.g. mysql://user:pass@host:port/dbname)
    define('DB_HOST', $parsed_url['host']);
    define('DB_USER', isset($parsed_url['user']) ? $parsed_url['user'] : env('DB_USER', 'root'));
    define('DB_PASS', isset($parsed_url['pass']) ? $parsed_url['pass'] : env('DB_PASS', ''));
    define('DB_NAME', isset($parsed_url['path']) ? ltrim($parsed_url['path'], '/') : env('DB_NAME', 'memoria_db'));
} else {
    // Regular separate variables
    define('DB_HOST', $raw_db_host);
    define('DB_NAME', env('DB_NAME', 'memoria_db'));
    define('DB_USER', env('DB_USER', 'root'));
    define('DB_PASS', env('DB_PASS', ''));
}

define('DB_CHARSET', 'utf8mb4');
define('DB_TIMEZONE', env('DB_TIMEZONE', '+08:00'));

define('JWT_SECRET', env('JWT_SECRET', 'SIR_PAPASARAMIINTAWNPLEASEHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHU'));
define('JWT_ALGO', 'HS256');
define('JWT_EXPIRATION', 18000); // in seconds
define('APP_URL', env('APP_URL', 'http://yourdomain.com'));
define('LOG_SECRET_KEY', env('LOG_SECRET_KEY', 'your_log_secret_key'));
define('ENCRYPTION_KEY', env('ENCRYPTION_KEY', 'SIR_PAPASARAMIINTAWNPLEASEHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHUHU!')); 

// --- ENUM CONSTANTS ---
const ROLE_ADMIN = 'Administrator';
const ROLE_OFFICE = 'Office Staff';
const ROLE_GROUNDS = 'Grounds Staff'; 

const STATUS_VERIFIED = 'Verified';
const STATUS_UNVERIFIED = 'Unverified'; 

const ALLOWED_ROLES = [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS]; 
const ALLOWED_STATUSES = [STATUS_VERIFIED, STATUS_UNVERIFIED];
