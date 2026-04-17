<?php
// db.php — Databaseverbinding
define('DB_HOST', 'localhost');
define('DB_NAME', 'bar');
define('DB_USER', 'bar');
define('DB_PASS', 'Qub2jtkUmA4Na.iv_ygUxgEY6igBu9');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Databaseverbinding mislukt: ' . $e->getMessage()]));
}
