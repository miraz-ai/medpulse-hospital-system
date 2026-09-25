<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Database Connection & PDO Instance Configuration
 * 
 * Supports production environment variable injection while preserving local defaults.
 */

$host     = getenv('DB_HOST')     ?: 'localhost';
$dbname   = getenv('DB_NAME')     ?: 'medpulse_hms';
$username = getenv('DB_USER')     ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$port     = getenv('DB_PORT')     ?: '3306';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Database connection failed: " . $e->getMessage() . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'error',
            'message' => 'Internal database service unavailable. Please contact system administrator.'
        ]);
    }
    exit(1);
}