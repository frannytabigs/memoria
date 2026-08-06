<?php
// api/config.example.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'memoria_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('DB_TIMEZONE', '+08:00');

define('JWT_SECRET', 'your_jwt_secret_here_32_letters_makesuretolongbecause ofHS256_IDKITEITHERLOL');
define('JWT_ALGO', 'HS256');
define('JWT_EXPIRATION', 3600);
define('APP_URL', 'http://yourdomain.com');
define('LOG_SECRET_KEY', 'your_log_secret_key');
?>