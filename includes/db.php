<?php
$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$db = getenv('DB_NAME');

if ($host === false || $host === '' || $user === false || $user === '' || $pass === false || $db === false || $db === '') {
    die('Database environment variables are not configured.');
}

try {
    $dsn = "mysql:host=$host;port=3306;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    die('Database connection failed. Check the server logs.');
}
?>