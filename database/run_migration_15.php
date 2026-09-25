<?php
/**
 * MedPulse Enterprise HMS - Database Migration Runner for Multi-Hospital Support
 * Script: database/run_migration_15.php
 * Executes 15_multi_hospital_support.sql safely and idempotently.
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'success' => false,
    'timestamp' => date('Y-m-d H:i:s'),
    'steps' => [],
    'verification' => []
];

try {
    // Step 1: Read and execute migration script
    $sqlFile = __DIR__ . '/15_multi_hospital_support.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException("Migration SQL file not found: " . $sqlFile);
    }

    $sqlContent = file_get_contents($sqlFile);
    $pdo->exec($sqlContent);

    $response['steps'][] = [
        'step' => 'execute_migration',
        'file' => '15_multi_hospital_support.sql',
        'status' => 'executed'
    ];

    // Step 2: Verify hospitals
    $hospitals = $pdo->query("SELECT hospital_id, name, code, city, address, contact_number FROM hospitals ORDER BY hospital_id ASC")->fetchAll();
    
    // Step 3: Verify users role enum & super admin
    $userCols = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    $superAdmin = $pdo->query("SELECT user_id, full_name, email, phone, role, status, password_hash FROM users WHERE role = 'super_admin' LIMIT 1")->fetch();
    $passwordCheck = $superAdmin ? password_verify('admin123', $superAdmin['password_hash']) : false;

    // Step 4: Verify hospital_beds
    $bedsCols = $pdo->query("SHOW COLUMNS FROM hospital_beds")->fetchAll(PDO::FETCH_COLUMN);
    $bedsPerHospital = $pdo->query("SELECT hospital_id, COUNT(*) as bed_count FROM hospital_beds GROUP BY hospital_id")->fetchAll();

    // Step 5: Verify doctor_profiles
    $doctorCols = $pdo->query("SHOW COLUMNS FROM doctor_profiles")->fetchAll(PDO::FETCH_COLUMN);
    $doctorsPerHospital = $pdo->query("SELECT hospital_id, COUNT(*) as doctor_count FROM doctor_profiles GROUP BY hospital_id")->fetchAll();

    // Step 6: Verify foreign keys
    $fks = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME IN ('hospital_beds', 'doctor_profiles')
          AND REFERENCED_TABLE_NAME = 'hospitals'
    ")->fetchAll();

    $response['verification'] = [
        'hospitals' => $hospitals,
        'user_role_enum_type' => $userCols['Type'] ?? null,
        'super_admin_created' => (bool)$superAdmin,
        'super_admin_user' => $superAdmin ? [
            'user_id' => $superAdmin['user_id'],
            'full_name' => $superAdmin['full_name'],
            'email' => $superAdmin['email'],
            'role' => $superAdmin['role'],
            'status' => $superAdmin['status'],
            'password_verified_admin123' => $passwordCheck
        ] : null,
        'hospital_beds_has_hospital_id' => in_array('hospital_id', $bedsCols, true),
        'hospital_beds_has_price_per_day' => in_array('price_per_day', $bedsCols, true),
        'hospital_beds_distribution' => $bedsPerHospital,
        'doctor_profiles_has_hospital_id' => in_array('hospital_id', $doctorCols, true),
        'doctors_distribution' => $doctorsPerHospital,
        'foreign_keys' => $fks
    ];

    $response['success'] = count($hospitals) >= 3 
        && $superAdmin 
        && $passwordCheck 
        && in_array('hospital_id', $bedsCols, true) 
        && in_array('price_per_day', $bedsCols, true)
        && in_array('hospital_id', $doctorCols, true);

} catch (Throwable $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
