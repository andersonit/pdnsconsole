<?php
// == Database Configuration Sample ==
// Copy this file to config.php and update with your database credentials

// Development database settings
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'pdnsconsole');
define('DB_USER', 'pdnscadmin');
define('DB_PASS', 'your_password_here');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// Database connection options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]);
?>