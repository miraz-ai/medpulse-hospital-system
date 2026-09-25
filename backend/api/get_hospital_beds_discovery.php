<?php
/**
 * MedPulse Enterprise Hospital Management System
 * API: get_hospital_beds_discovery.php
 * -----------------------------------------------------------------------
 * Returns real-time aggregated bed availability grouped by hospital and
 * ward type, supporting multi-hospital comparison for patients.
 *
 * Filters (GET):
 *   hospital_id  : int|'all'   – filter by specific hospital
 *   category     : string      – 'icu','general','cabin','critical','all'
 *   max_price    : float       – maximum price_per_day filter
 *   bed_id       : int         – fetch a single specific bed row (for Choose Bed)
 *
 * Response:
 *   { success, hospitals: [...], summary: { total_beds, total_available } }
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/db.php';

// RBAC: Must be a logged-in Patient
if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role'] ?? '') !== 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

// ------------------------------------------------------------------
// Input Sanitisation
// ------------------------------------------------------------------
$hospitalId  = trim((string)($_GET['hospital_id'] ?? 'all'));
$category    = strtolower(trim((string)($_GET['category'] ?? 'all')));
$maxPrice    = isset($_GET['max_price']) && is_numeric($_GET['max_price'])
                ? (float)$_GET['max_price'] : null;

// Ward-type groupings for category filter
$categoryMap = [
    'icu'      => ["'ICU'", "'CCU'", "'NICU'"],
    'critical' => ["'ICU'", "'CCU'", "'NICU'", "'Emergency'"],
    'general'  => ["'General Ward Male'", "'General Ward Female'", "'Pediatrics'", "'Recovery'"],
    'cabin'    => ["'Semi-Cabin'", "'Deluxe Cabin'", "'VIP Suite'", "'Presidential Suite'"],
];

try {
    // ------------------------------------------------------------------
    // 1. Build Aggregate Query – Beds grouped by hospital × ward_type
    // ------------------------------------------------------------------
    $whereClauses = [];
    $params       = [];

    if ($hospitalId !== 'all' && ctype_digit($hospitalId)) {
        $whereClauses[] = 'b.hospital_id = :hospital_id';
        $params[':hospital_id'] = (int)$hospitalId;
    }

    if ($category !== 'all' && isset($categoryMap[$category])) {
        $inList         = implode(',', $categoryMap[$category]);
        $whereClauses[] = "b.ward_type IN ($inList)";
    }

    if ($maxPrice !== null) {
        $whereClauses[] = 'b.price_per_day <= :max_price';
        $params[':max_price'] = $maxPrice;
    }

    $whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $aggregateSQL = "
        SELECT
            h.hospital_id,
            h.name            AS hospital_name,
            h.code            AS hospital_code,
            h.city,
            h.address,
            h.contact_number,
            b.ward_type,
            b.price_per_day,
            COUNT(b.bed_id)                                             AS total_beds,
            SUM(CASE WHEN b.status = 'Available'    THEN 1 ELSE 0 END) AS available_beds,
            SUM(CASE WHEN b.status = 'Occupied'     THEN 1 ELSE 0 END) AS occupied_beds,
            SUM(CASE WHEN b.status = 'Maintenance'  THEN 1 ELSE 0 END) AS maintenance_beds,
            SUM(CASE WHEN b.status = 'Reserved'     THEN 1 ELSE 0 END) AS reserved_beds,
            MIN(b.bed_id)                                               AS sample_bed_id
        FROM hospital_beds b
        JOIN hospitals h ON b.hospital_id = h.hospital_id
        $whereSQL
        GROUP BY h.hospital_id, h.name, h.code, h.city, h.address, h.contact_number,
                 b.ward_type, b.price_per_day
        HAVING available_beds > 0 OR total_beds > 0
        ORDER BY h.hospital_id ASC, b.ward_type ASC
    ";

    $stmt = $pdo->prepare($aggregateSQL);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------------
    // 2. Structure per hospital
    // ------------------------------------------------------------------
    $hospitalsMap = [];
    $totalBeds     = 0;
    $totalAvail    = 0;

    foreach ($rows as $row) {
        $hid = (int)$row['hospital_id'];

        if (!isset($hospitalsMap[$hid])) {
            $hospitalsMap[$hid] = [
                'hospital_id'    => $hid,
                'hospital_name'  => $row['hospital_name'],
                'hospital_code'  => $row['hospital_code'],
                'city'           => $row['city'],
                'address'        => $row['address'],
                'contact_number' => $row['contact_number'],
                'total_beds'     => 0,
                'available_beds' => 0,
                'wards'          => [],
            ];
        }

        $avail = (int)$row['available_beds'];
        $total = (int)$row['total_beds'];

        $hospitalsMap[$hid]['wards'][] = [
            'ward_type'        => $row['ward_type'],
            'price_per_day'    => (float)$row['price_per_day'],
            'total_beds'       => $total,
            'available_beds'   => $avail,
            'occupied_beds'    => (int)$row['occupied_beds'],
            'maintenance_beds' => (int)$row['maintenance_beds'],
            'reserved_beds'    => (int)$row['reserved_beds'],
            'occupancy_pct'    => $total > 0 ? round((($total - $avail) / $total) * 100) : 0,
            'sample_bed_id'    => (int)$row['sample_bed_id'],
        ];

        $hospitalsMap[$hid]['total_beds']     += $total;
        $hospitalsMap[$hid]['available_beds'] += $avail;
        $totalBeds  += $total;
        $totalAvail += $avail;
    }

    // ------------------------------------------------------------------
    // 3. Fetch all hospitals list (for filter dropdown – unfiltered)
    // ------------------------------------------------------------------
    $allHospitals = $pdo->query(
        "SELECT hospital_id, name, code, city FROM hospitals ORDER BY hospital_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'hospitals'  => array_values($hospitalsMap),
        'all_hospitals_list' => $allHospitals,
        'summary'    => [
            'total_beds'      => $totalBeds,
            'total_available' => $totalAvail,
            'total_occupied'  => $totalBeds - $totalAvail,
            'occupancy_rate'  => $totalBeds > 0
                ? round((($totalBeds - $totalAvail) / $totalBeds) * 100, 1)
                : 0,
        ],
        'filters_applied' => [
            'hospital_id' => $hospitalId,
            'category'    => $category,
            'max_price'   => $maxPrice,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Hospital Beds Discovery API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve bed data. Please try again.',
    ]);
}
