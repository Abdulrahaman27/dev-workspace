<?php
// Update these credentials to match your local MySQL / hosting setup.
$dbHost = '127.0.0.1';
$dbName = 'dev_workspace';
$dbUser = 'root';
$dbPort = 3307;
$dbPass = ''; // Your local MySQL password

$uploadDir = __DIR__ . '/uploads/';
$uploadUrl = 'uploads/';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4;port={$dbPort}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check config.php and import database.sql.');
}
