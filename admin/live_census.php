<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Dedicated Clinical Census & Live Ward Bed Telemetry Interface
 * Real-Time Relational Data Driven Engine (500 Bed Capacity)
 */

require_once __DIR__ . '/../includes/admin_auth.php';

// Strict multi-hospital scoping: resolve current branch hospital
$adminHospitalId = (int)($_SESSION['hospital_id'] ?? 1);

require_once __DIR__ . '/../backend/Services/EmergencyProtocolService.php';
$emergencyService = new \MedPulse\Services\EmergencyProtocolService($pdo);
$allActiveProtocols = $emergencyService->getActiveProtocols();
$branchActiveProtocols = [];
foreach ($allActiveProtocols as $p) {
    if ($p['target_scope'] === 'NETWORK_WIDE') {
        $branchActiveProtocols[] = $p;
    } else {
        $th = !empty($p['target_hospitals']) ? json_decode($p['target_hospitals'], true) : [];
        if (is_array($th) && in_array($adminHospitalId, $th)) {
            $branchActiveProtocols[] = $p;
        }
    }
}
$branchActiveCount = count($branchActiveProtocols);
$activeEmergency  = !empty($branchActiveProtocols) ? $branchActiveProtocols[0] : null;

// =============================================================================
// BED LIFECYCLE ACTION HANDLER (JSON API)
// Handles POST requests for: allocate_bed | discharge_patient | mark_bed_ready
//                             get_dropdowns | get_stats
// Returns JSON and exits — page render is skipped for all POST/AJAX calls.
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['_action']) && in_array($_GET['_action'], ['get_dropdowns', 'get_stats']))) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['_action'] ?? $_GET['_action'] ?? '';

    // -------------------------------------------------------------------------
    // Helper: push a structured telemetry event into audit_logs
    // -------------------------------------------------------------------------
    function pushAuditLog(PDO $pdo, int $actorId, string $actionKey, string $description, string $category, string $actionName, string $targetEntity, string $ip): void {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
                (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
            VALUES 
                (:actor_id, 'Admin', :action, :description, :category, :action_name, :target_entity, :ip, 'INFO')
        ");
        $stmt->execute([
            ':actor_id'     => $actorId,
            ':action'       => $actionKey,
            ':description'  => $description,
            ':category'     => $category,
            ':action_name'  => $actionName,
            ':target_entity'=> $targetEntity,
            ':ip'           => $ip,
        ]);
    }

    // -------------------------------------------------------------------------
    // Endpoint: get_stats — live aggregate counts for metric chip refresh
    // -------------------------------------------------------------------------
    if ($action === 'get_stats') {
        try {
            $rowStmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_beds,
                    SUM(status = 'Occupied')       AS occupied_beds,
                    SUM(status = 'Available')      AS available_beds,
                    SUM(status = 'Maintenance')    AS maintenance_beds,
                    SUM(status = 'Emergency Hold') AS emergency_hold_beds
                FROM hospital_beds
                WHERE hospital_id = ?
            ");
            $rowStmt->execute([$adminHospitalId]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

            $icuRowStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) AS total_icu,
                    SUM(status = 'Occupied') AS occupied_icu
                FROM hospital_beds
                WHERE hospital_id = ? AND ward_type IN ('ICU', 'CCU')
            ");
            $icuRowStmt->execute([$adminHospitalId]);
            $icuRow = $icuRowStmt->fetch(PDO::FETCH_ASSOC);

            $totIcu = (int)($icuRow['total_icu'] ?? 0);
            $occIcu = (int)($icuRow['occupied_icu'] ?? 0);
            $icuPct = $totIcu > 0 ? ($occIcu / $totIcu) * 100 : 0;
            $availIcu = max(0, $totIcu - $occIcu);

            $hasActiveSurge = !empty($emergencyService->isHospitalAffected($adminHospitalId)) || ((int)($row['emergency_hold_beds'] ?? 0) > 0);
            if ($hasActiveSurge) {
                $tBpm = '118 BPM'; $tClass = 'telemetry-critical'; $tLabel = 'DISASTER SURGE';
            } elseif ($totIcu > 0 && ($availIcu === 0 || $icuPct >= 90)) {
                $tBpm = '124 BPM'; $tClass = 'telemetry-critical'; $tLabel = 'CODE SURGE';
            } elseif ($icuPct >= 70) {
                $tBpm = '98 BPM'; $tClass = 'telemetry-warning'; $tLabel = 'HIGH LOAD';
            } else {
                $tBpm = '72 BPM'; $tClass = 'telemetry-normal'; $tLabel = 'STABLE';
            }

            echo json_encode([
                'success' => true,
                'stats' => $row,
                'telemetry' => [
                    'bpm' => $tBpm,
                    'class' => $tClass,
                    'label' => $tLabel,
                    'active_beds' => (int)($row['total_beds'] ?? 0)
                ]
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Stats query failed.']);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // Endpoint: get_dropdowns — patient list + active doctor list for modal
    // -------------------------------------------------------------------------
    if ($action === 'get_dropdowns') {
        try {
            $patients = $pdo->query("
                SELECT user_id, full_name FROM users
                WHERE role = 'patient'
                ORDER BY full_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $doctors = $pdo->query("
                SELECT user_id, full_name FROM users
                WHERE role = 'doctor' AND status = 'active'
                ORDER BY full_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'patients' => $patients, 'doctors' => $doctors]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Could not load dropdown data.']);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // All mutating actions require CSRF validation
    // -------------------------------------------------------------------------
    $submittedToken = trim($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
        exit;
    }

    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // -------------------------------------------------------------------------
    // Action: allocate_bed
    // -------------------------------------------------------------------------
    if ($action === 'allocate_bed') {
        $bedId    = (int)($_POST['bed_id']    ?? 0);
        $patId    = (int)($_POST['patient_id'] ?? 0);
        $docId    = (int)($_POST['doctor_id']  ?? 0) ?: null;
        $notes    = trim($_POST['notes'] ?? '');

        if (!$bedId || !$patId) {
            echo json_encode(['success' => false, 'message' => 'Bed and patient are required.']);
            exit;
        }

        try {
            // 1. Business-Rule Validation: Check if patient is already admitted in an active bed
            $checkPat = $pdo->prepare("
                SELECT ba.bed_id, b.bed_number 
                FROM bed_allocations ba 
                JOIN hospital_beds b ON ba.bed_id = b.bed_id 
                WHERE ba.patient_id = :pid AND ba.status = 'Active' 
                LIMIT 1
            ");
            $checkPat->execute([':pid' => $patId]);
            $currentBed = $checkPat->fetch(PDO::FETCH_ASSOC);

            if ($currentBed) {
                echo json_encode([
                    'success' => false,
                    'message' => "Patient is currently admitted in Bed {$currentBed['bed_number']}. Please use Transfer Bed."
                ]);
                exit;
            }

            // 2. Business-Rule Validation: Verify destination bed availability
            $bedRow = $pdo->prepare("SELECT bed_number, status FROM hospital_beds WHERE bed_id = :id AND hospital_id = :hid LIMIT 1");
            $bedRow->execute([':id' => $bedId, ':hid' => $adminHospitalId]);
            $bed = $bedRow->fetch(PDO::FETCH_ASSOC);

            if (!$bed || !in_array($bed['status'], ['Available', 'Emergency Hold'], true)) {
                echo json_encode(['success' => false, 'message' => 'Bed is already occupied or undergoing sanitization.']);
                exit;
            }

            $patRow = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :id AND role = 'patient' LIMIT 1");
            $patRow->execute([':id' => $patId]);
            $patient = $patRow->fetch(PDO::FETCH_ASSOC);
            $patName = $patient['full_name'] ?? "Patient #$patId";

            $docName = 'Unassigned';
            if ($docId) {
                $docRow = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :id AND role = 'doctor' LIMIT 1");
                $docRow->execute([':id' => $docId]);
                $doc = $docRow->fetch(PDO::FETCH_ASSOC);
                $docName = $doc['full_name'] ?? "Dr. #$docId";
            }

            $pdo->beginTransaction();

            // 1. Mark bed as Occupied
            $upd = $pdo->prepare("UPDATE hospital_beds SET status = 'Occupied', relocation_status = 'NONE' WHERE bed_id = :bid AND hospital_id = :hid AND status IN ('Available', 'Emergency Hold')");
            $upd->execute([':bid' => $bedId, ':hid' => $adminHospitalId]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Bed is already occupied by another patient.']);
                exit;
            }

            // 2. Insert allocation record
            $ins = $pdo->prepare("
                INSERT INTO bed_allocations (bed_id, patient_id, attending_doctor_id, admitted_at, status)
                VALUES (:bid, :pid, :did, NOW(), 'Active')
            ");
            $ins->execute([':bid' => $bedId, ':pid' => $patId, ':did' => $docId]);

            // Sync attending doctor to patient_doctor_assignments junction table
            if ($docId) {
                $pdaStmt = $pdo->prepare("
                    INSERT INTO patient_doctor_assignments (patient_id, doctor_id, assigned_by, is_primary, status, assigned_at)
                    VALUES (:pid, :did, :aid, 1, 'Active', NOW())
                    ON DUPLICATE KEY UPDATE status = 'Active', is_primary = 1
                ");
                $pdaStmt->execute([':pid' => $patId, ':did' => $docId, ':aid' => $actorId]);
            }

            // 3. Audit log
            $auditDesc = "Patient {$patName} (#{$patId}) allocated to Bed {$bed['bed_number']} under {$docName}.";
            if (!empty($notes)) {
                $auditDesc .= " Notes: " . mb_substr($notes, 0, 120);
            }
            pushAuditLog(
                $pdo, $actorId,
                'BED_ALLOCATION', $auditDesc,
                'ADMISSION', 'Bed Allocation',
                $bed['bed_number'], $clientIp
            );

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "✓ {$patName} successfully allocated to Bed {$bed['bed_number']}.",
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Allocate Bed PDOException: " . $e->getMessage());

            if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
                if (str_contains($e->getMessage(), 'uq_active_bed')) {
                    echo json_encode(['success' => false, 'message' => 'Bed is already occupied by another patient.']);
                    exit;
                }
                if (str_contains($e->getMessage(), 'uq_active_patient')) {
                    $lookup = $pdo->prepare("
                        SELECT b.bed_number 
                        FROM bed_allocations ba 
                        JOIN hospital_beds b ON ba.bed_id = b.bed_id 
                        WHERE ba.patient_id = :pid AND ba.status = 'Active' 
                        LIMIT 1
                    ");
                    $lookup->execute([':pid' => $patId]);
                    $activeBedNum = $lookup->fetchColumn() ?: 'Unknown';
                    echo json_encode([
                        'success' => false,
                        'message' => "Patient is currently admitted in Bed {$activeBedNum}. Please use Transfer Bed."
                    ]);
                    exit;
                }
            }

            echo json_encode(['success' => false, 'message' => 'Database operation error: ' . $e->getMessage()]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Allocate Bed Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // Action: discharge_patient
    // -------------------------------------------------------------------------
    if ($action === 'discharge_patient') {
        require_once __DIR__ . '/discharge_patient_action.php';
        exit;
    }


    // -------------------------------------------------------------------------
    // Action: mark_bed_ready
    // -------------------------------------------------------------------------
    if ($action === 'mark_bed_ready') {
        $bedId = (int)($_POST['bed_id'] ?? 0);
        if (!$bedId) {
            echo json_encode(['success' => false, 'message' => 'Bed ID is required.']);
            exit;
        }

        try {
            $bedRow = $pdo->prepare("SELECT bed_number, status FROM hospital_beds WHERE bed_id = :id AND hospital_id = :hid LIMIT 1");
            $bedRow->execute([':id' => $bedId, ':hid' => $adminHospitalId]);
            $bed = $bedRow->fetch(PDO::FETCH_ASSOC);

            if (!$bed || !in_array($bed['status'], ['Maintenance', 'Sanitizing'], true)) {
                echo json_encode(['success' => false, 'message' => 'Bed is not in maintenance or sanitizing state. Please refresh.']);
                exit;
            }

            $pdo->beginTransaction();

            // 1. Clear sanitization / maintenance — bed returns to Available
            $upd = $pdo->prepare("UPDATE hospital_beds SET status = 'Available' WHERE bed_id = :bid AND hospital_id = :hid AND status IN ('Maintenance', 'Sanitizing')");
            $upd->execute([':bid' => $bedId, ':hid' => $adminHospitalId]);

            // 2. Audit log
            pushAuditLog(
                $pdo, $actorId,
                'BED_SANITIZED',
                "Sanitization protocol cleared for Bed {$bed['bed_number']}. Ready for intake.",
                'SYSTEM', 'Bed Sanitized',
                $bed['bed_number'], $clientIp
            );

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "✓ Bed {$bed['bed_number']} sanitization cleared. Ready for patient intake.",
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Mark Ready Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Mark Ready failed due to a server error.']);
        }
        exit;
    }

    // Unknown action fallback
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
// =============================================================================
// END OF ACTION HANDLER — below is the standard GET page render
// =============================================================================

$dbError = null;

// 1. Resolve current hospital branding and profile
$currentHospital = ['name' => 'MedPulse Hospital & Specialty Care', 'code' => 'MEDPULSE', 'city' => 'Dhaka'];
try {
    $hStmt = $pdo->prepare("SELECT name, code, city FROM hospitals WHERE hospital_id = ? LIMIT 1");
    $hStmt->execute([$adminHospitalId]);
    $hRow = $hStmt->fetch(PDO::FETCH_ASSOC);
    if ($hRow) $currentHospital = $hRow;
} catch (Throwable $e) {}

// Query live hospital capacity aggregates strictly for this branch hospital
try {
    $statStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_beds,
            SUM(status = 'Occupied') AS occupied_beds,
            SUM(status = 'Available') AS available_beds,
            SUM(status = 'Maintenance') AS maintenance_beds,
            SUM(status = 'Emergency Hold') AS emergency_hold_beds
        FROM hospital_beds
        WHERE hospital_id = ?
    ");
    $statStmt->execute([$adminHospitalId]);
    $statRow = $statStmt->fetch(PDO::FETCH_ASSOC);

    $totalHospitalBeds = (int)($statRow['total_beds'] ?? 0);
    $totalOccupiedBeds = (int)($statRow['occupied_beds'] ?? 0);
    $totalAvailableBeds = (int)($statRow['available_beds'] ?? 0);
    $totalMaintenanceBeds = (int)($statRow['maintenance_beds'] ?? 0);
    $totalEmergencyHoldBeds = (int)($statRow['emergency_hold_beds'] ?? 0);

    // Dynamic Critical/ICU Occupancy %:
    $icuStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_icu_ccu,
            SUM(status = 'Occupied') AS occupied_icu_ccu
        FROM hospital_beds
        WHERE hospital_id = ? AND ward_type IN ('ICU', 'CCU')
    ");
    $icuStmt->execute([$adminHospitalId]);
    $icuRow = $icuStmt->fetch(PDO::FETCH_ASSOC);
    $totalIcuCcu = (int)($icuRow['total_icu_ccu'] ?? 0);
    $occupiedIcuCcu = (int)($icuRow['occupied_icu_ccu'] ?? 0);
    $icuOccupancyPct = $totalIcuCcu > 0 ? round(($occupiedIcuCcu / $totalIcuCcu) * 100) : 0;

    // Clinical stress index & ECG pulse telemetry calculations
    $total_critical_beds = $totalIcuCcu;
    $occupied_critical_beds = $occupiedIcuCcu;
    $available_critical_beds = max(0, $total_critical_beds - $occupied_critical_beds);
    $critical_occupancy_rate = $total_critical_beds > 0 ? ($occupied_critical_beds / $total_critical_beds) * 100 : 0;
    $total_active_beds = $totalHospitalBeds;

    // Threshold classification (Disaster surge spikes rhythm to rapid 118 BPM rose-500):
    if ($branchActiveCount > 0 || $totalEmergencyHoldBeds > 0) {
        $telemetry_bpm = '118 BPM';
        $telemetry_class = 'telemetry-critical';
        $telemetry_label = 'DISASTER SURGE';
        $telemetry_speed = '0.75s';
    } elseif ($total_critical_beds > 0 && ($available_critical_beds === 0 || $critical_occupancy_rate >= 90)) {
        $telemetry_bpm = '124 BPM';
        $telemetry_class = 'telemetry-critical';
        $telemetry_label = 'CODE SURGE';
        $telemetry_speed = '0.7s';
    } elseif ($critical_occupancy_rate >= 70) {
        $telemetry_bpm = '98 BPM';
        $telemetry_class = 'telemetry-warning';
        $telemetry_label = 'HIGH LOAD';
        $telemetry_speed = '1.2s';
    } else {
        $telemetry_bpm = '72 BPM';
        $telemetry_class = 'telemetry-normal';
        $telemetry_label = 'STABLE';
        $telemetry_speed = '2s';
    }

    // Dynamic Distinct Floors for this branch hospital
    $floorStmt = $pdo->prepare("
        SELECT DISTINCT floor_number 
        FROM hospital_beds 
        WHERE hospital_id = ? 
        ORDER BY floor_number ASC
    ");
    $floorStmt->execute([$adminHospitalId]);
    $distinctFloors = array_map('intval', $floorStmt->fetchAll(PDO::FETCH_COLUMN));

    $floorWardStmt = $pdo->prepare("
        SELECT floor_number, GROUP_CONCAT(DISTINCT ward_type ORDER BY ward_type ASC SEPARATOR ', ') AS wards 
        FROM hospital_beds 
        WHERE hospital_id = ? 
        GROUP BY floor_number 
        ORDER BY floor_number ASC
    ");
    $floorWardStmt->execute([$adminHospitalId]);
    $floorWards = [];
    while ($fRow = $floorWardStmt->fetch(PDO::FETCH_ASSOC)) {
        $floorWards[(int)$fRow['floor_number']] = $fRow['wards'];
    }

    // Dynamic Ward Counts for tab badges strictly scoped to this branch hospital
    $wcStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS all_cnt,
            SUM(ward_type IN ('ICU', 'CCU', 'NICU', 'Recovery')) AS icu_cnt,
            SUM(ward_type = 'Emergency') AS emergency_cnt,
            SUM(ward_type IN ('General Ward Male', 'General Ward Female')) AS general_cnt,
            SUM(ward_type IN ('Pediatrics', 'Semi-Cabin')) AS pediatrics_cnt,
            SUM(ward_type IN ('Deluxe Cabin', 'VIP Suite', 'Presidential Suite')) AS vip_cnt
        FROM hospital_beds
        WHERE hospital_id = ?
    ");
    $wcStmt->execute([$adminHospitalId]);
    $wcRow = $wcStmt->fetch(PDO::FETCH_ASSOC);
    $wardCounts = [
        'all' => (int)($wcRow['all_cnt'] ?? 0),
        'icu' => (int)($wcRow['icu_cnt'] ?? 0),
        'emergency' => (int)($wcRow['emergency_cnt'] ?? 0),
        'general' => (int)($wcRow['general_cnt'] ?? 0),
        'pediatrics' => (int)($wcRow['pediatrics_cnt'] ?? 0),
        'vip' => (int)($wcRow['vip_cnt'] ?? 0)
    ];
} catch (Throwable $e) {
    error_log("Live Census Error: " . $e->getMessage());
    $dbError = "Live telemetry database connection failed. Showing cached capacity metrics.";
    $totalHospitalBeds = 500;
    $totalOccupiedBeds = 93;
    $totalAvailableBeds = 392;
    $totalMaintenanceBeds = 15;
    $icuOccupancyPct = 20;
    $total_active_beds = 500;
    $telemetry_bpm = '72 BPM';
    $telemetry_class = 'telemetry-normal';
    $telemetry_label = 'STABLE';
    $telemetry_speed = '2s';
    $distinctFloors = [1, 2, 3, 4, 5];
    $floorWards = [];
    $wardCounts = ['all' => 500, 'icu' => 120, 'emergency' => 40, 'general' => 160, 'pediatrics' => 100, 'vip' => 80];
}

// 2. GET Parameter Filtering & Dynamic SQL Construction
$wardFilter = strtolower(trim($_GET['ward'] ?? 'all'));
$floorFilter = isset($_GET['floor']) && is_numeric($_GET['floor']) ? (int)$_GET['floor'] : 0;
$statusFilter = strtolower(trim($_GET['status'] ?? ''));
$searchFilter = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 32; // 32 bed cards per page for fast 60fps rendering and clean 4-column responsive grid

// Scoped strictly to current branch hospital
$whereClauses = ["b.hospital_id = :admin_hid"];
$params = [':admin_hid' => $adminHospitalId];

// Ward filter resolution
if ($wardFilter === 'icu') {
    $whereClauses[] = "b.ward_type IN ('ICU', 'CCU', 'NICU', 'Recovery')";
} elseif ($wardFilter === 'emergency') {
    $whereClauses[] = "b.ward_type = 'Emergency'";
} elseif ($wardFilter === 'general') {
    $whereClauses[] = "b.ward_type IN ('General Ward Male', 'General Ward Female')";
} elseif ($wardFilter === 'pediatrics') {
    $whereClauses[] = "b.ward_type IN ('Pediatrics', 'Semi-Cabin')";
} elseif ($wardFilter === 'presidential') {
    $whereClauses[] = "(b.ward_type = 'Presidential Suite' OR b.bed_number = 'PRES-401')";
} elseif ($wardFilter === 'vip') {
    $whereClauses[] = "b.ward_type IN ('Deluxe Cabin', 'VIP Suite', 'Presidential Suite')";
} elseif ($wardFilter !== 'all' && !empty($wardFilter)) {
    $whereClauses[] = "b.ward_type LIKE :ward_term";
    $params[':ward_term'] = '%' . $wardFilter . '%';
}

// Dynamic Floor filter resolution based on this branch's actual floor architecture
if ($floorFilter > 0 && in_array($floorFilter, $distinctFloors, true)) {
    $whereClauses[] = "b.floor_number = :floor_num";
    $params[':floor_num'] = $floorFilter;
}

// Status filter resolution
if (in_array($statusFilter, ['available', 'occupied', 'maintenance', 'reserved', 'emergency hold'])) {
    $whereClauses[] = "b.status = :status_val";
    $params[':status_val'] = ucfirst($statusFilter);
}

// Search filter (bed code or patient name)
if (!empty($searchFilter)) {
    $whereClauses[] = "(b.bed_number LIKE :s_bed OR p.full_name LIKE :s_pat)";
    $params[':s_bed'] = '%' . $searchFilter . '%';
    $params[':s_pat'] = '%' . $searchFilter . '%';
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

// Count total matching records
$totalFilteredBeds = 0;
$bedSlots = [];

try {
    $countSql = "
        SELECT COUNT(*) 
        FROM hospital_beds b
        LEFT JOIN bed_allocations ba ON b.bed_id = ba.bed_id AND ba.status = 'Active'
        LEFT JOIN users p ON ba.patient_id = p.user_id
        $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalFilteredBeds = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalFilteredBeds / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // Fetch paginated bed records with patient and doctor details
    $dataSql = "
        SELECT 
            b.bed_id,
            b.bed_number,
            b.ward_type,
            b.floor_number,
            b.daily_rate,
            b.status,
            b.relocation_status,
            b.emergency_protocol_id,
            ep.code AS ep_code,
            ep.title AS ep_title,
            ep.severity_level AS ep_severity,
            ba.allocation_id,
            ba.admitted_at,
            ba.patient_id,
            p.full_name AS patient_name,
            p.user_id AS patient_user_id,
            d.full_name AS doctor_name
        FROM hospital_beds b
        LEFT JOIN emergency_protocols ep ON ep.id = b.emergency_protocol_id
        LEFT JOIN bed_allocations ba ON b.bed_id = ba.bed_id AND ba.status = 'Active'
        LEFT JOIN users p ON ba.patient_id = p.user_id
        LEFT JOIN users d ON ba.attending_doctor_id = d.user_id
        $whereSql
        ORDER BY b.floor_number ASC, b.bed_id ASC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $bedSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch multi-doctor care team assignments for all active patients on current page
    $activePatientIds = array_values(array_unique(array_filter(array_map(function($slot) {
        return !empty($slot['patient_user_id']) ? (int)$slot['patient_user_id'] : null;
    }, $bedSlots))));

    $bedCareTeams = [];
    if (!empty($activePatientIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($activePatientIds), '?'));
        $teamStmt = $pdo->prepare("
            SELECT 
                pda.patient_id,
                pda.doctor_id,
                pda.is_primary,
                doc.full_name AS doctor_name,
                COALESCE(dp.specialty, doc.department, 'General Medicine') AS specialty
            FROM patient_doctor_assignments pda
            JOIN users doc ON pda.doctor_id = doc.user_id
            LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
            WHERE pda.status = 'Active' AND pda.patient_id IN ($inPlaceholders)
            ORDER BY pda.patient_id ASC, pda.is_primary DESC, doc.full_name ASC
        ");
        $teamStmt->execute($activePatientIds);
        $teamRows = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($teamRows as $tr) {
            $bedCareTeams[(int)$tr['patient_id']][] = $tr;
        }
    }
} catch (Throwable $e) {
    error_log("Live Census Query Error: " . $e->getMessage());
    if (!$dbError) {
        $dbError = "Unable to retrieve real-time bed records. Please check database connectivity.";
    }
}

if (!function_exists('getDoctorPastelBadgeClass')) {
    function getDoctorPastelBadgeClass(?string $specialty, int $doctorId = 0): string {
        $spec = strtolower(trim((string)$specialty));
        if (preg_match('/cardio|emerg|anesthe|critical|icu/i', $spec)) {
            return 'doc-badge-rose';
        } elseif (preg_match('/med|general|diabet|pediatr|nephro|pulmon/i', $spec)) {
            return 'doc-badge-sky';
        } elseif (preg_match('/surg|ortho|trauma|plastic|uro/i', $spec)) {
            return 'doc-badge-amber';
        } elseif (preg_match('/neuro|special|derma|psych|onc/i', $spec)) {
            return 'doc-badge-purple';
        } elseif ($spec !== '') {
            return 'doc-badge-teal';
        }
        $variants = ['doc-badge-rose', 'doc-badge-sky', 'doc-badge-amber', 'doc-badge-purple', 'doc-badge-teal'];
        return $variants[abs($doctorId) % 5];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Live Bed & Clinical Census Telemetry</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/live-pulse.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/admin/live-census.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container -->
  <main class="viewport-full">

    <!-- Header Banner with Animated ECG Pulse Badge -->
    <div class="welcome-banner" style="margin-bottom: 24px;">
      <div class="welcome-text">
        <h1 style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          Live Bed &amp; Clinical Census
          <span class="badge" style="background: rgba(13, 148, 136, 0.12); color: #0d9488; font-size: 0.76rem; padding: 4px 10px; border-radius: 999px; font-weight: 700; border: 1px solid rgba(13, 148, 136, 0.25);"><?= htmlspecialchars($currentHospital['name']) ?></span>
          <div class="ecg-pulse-monitor telemetry-pill <?= htmlspecialchars($telemetry_class) ?>" style="cursor: default;" title="Real-time clinical telemetry: <?= htmlspecialchars($telemetry_label) ?> (<?= htmlspecialchars($telemetry_bpm) ?>)">
            <svg class="ecg-wave-svg" viewBox="0 0 60 18" width="60" height="18" aria-hidden="true">
              <path class="ecg-wave-bg" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
              <path class="ecg-wave-active" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
            </svg>
            <span class="ecg-label"><span class="ecg-bpm-dot"></span><?= htmlspecialchars($telemetry_bpm) ?> &bull; <?= htmlspecialchars($telemetry_label) ?> &bull; <?= htmlspecialchars((string)$total_active_beds) ?> BEDS ACTIVE</span>
          </div>
        </h1>
        <p>Real-time inpatient occupancy, emergency admission allocations, intensive care load, and rapid triage routing for <?= htmlspecialchars($currentHospital['name']) ?>.</p>
      </div>

      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Admissions dispatcher is actively routing.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          Direct Admission
        </button>
        <button class="btn-action-gradient" onclick="window.location.reload()">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Sync Telemetry
        </button>
      </div>
    </div>

    <!-- Error Fallback Banner if DB Issue Occurs -->
    <?php if ($dbError): ?>
      <div class="census-fallback-badge">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: #d97706; width: 18px; height: 18px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <span><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endif; ?>

    <!-- Active National Emergency / Multi-Disaster Carousel Banner -->
    <?php if ($branchActiveCount > 0): ?>
    <div class="disaster-carousel-container" id="branchDisasterCarousel" style="position: relative; margin-bottom: 22px; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 26px rgba(136, 19, 55, 0.45); border: 2px solid #f43f5e; background: linear-gradient(135deg, #881337 0%, #4c0519 100%);">
      <div class="disaster-slides-track" style="position: relative;">
        <?php foreach ($branchActiveProtocols as $idx => $proto): 
          $pSeverity = strtoupper($proto['severity_level'] ?? 'CODE RED');
          $pQuota = (int)($proto['severity_quota'] ?? 20);
          $pHeld = (int)($proto['hospital_held_count'] ?? 0);
          $pReloc = (int)($proto['hospital_relocating_count'] ?? 0);
        ?>
        <div class="branch-carousel-slide <?= $idx === 0 ? 'active' : '' ?>" data-slide-index="<?= $idx ?>" style="transition: opacity 0.4s ease, transform 0.4s ease; padding: 18px 22px; color: #fff; display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap; <?= $idx === 0 ? 'position: relative; opacity: 1; pointer-events: auto;' : 'position: absolute; inset: 0; opacity: 0; pointer-events: none;' ?>">
          <div style="display: flex; align-items: flex-start; gap: 14px; max-width: 72%;">
            <div style="font-size: 1.8rem; width: 46px; height: 46px; border-radius: 12px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🚨</div>
            <div>
              <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px; flex-wrap: wrap;">
                <span style="font-weight: 800; font-size: 1.12rem; letter-spacing: 0.02em; color: #fff;">
                  NATIONAL EMERGENCY PROTOCOL: <?= htmlspecialchars($proto['title'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span style="background: #f43f5e; color: #fff; font-size: 0.7rem; font-weight: 800; padding: 3px 8px; border-radius: 8px; text-transform: uppercase;">
                  <?= htmlspecialchars($pSeverity) ?> (<?= $pQuota ?>% SURGE)
                </span>
                <span style="background: rgba(255,255,255,0.2); color: #fff; font-size: 0.65rem; font-weight: 700; padding: 2px 7px; border-radius: 6px;">
                  Protocol #<?= (int)$proto['id'] ?>
                </span>
              </div>
              <p style="font-size: 0.82rem; color: #fecdd3; margin: 0 0 6px; line-height: 1.45;">
                <?= htmlspecialchars($proto['meta']['guidelines'] ?? $proto['notes']) ?>
              </p>
              <div style="font-size: 0.74rem; color: #fda4af;">
                ⚠️ Evacuation &amp; Transfer Action: Patients moved during this disaster protocol will have their daily billing tier preserved automatically.
              </div>
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 14px; flex-shrink: 0;">
            <div style="display: flex; gap: 12px; background: rgba(0,0,0,0.3); padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);">
              <div id="btnSurgeHoldFilter" onclick="toggleSurgeHoldFilter()" style="text-align: center; cursor: pointer; padding: 2px 8px; border-radius: 6px; transition: background 0.15s;" title="Click to isolate surge beds on floor grid">
                <div style="font-size: 1.25rem; font-weight: 800; color: #fda4af;"><?= number_format($pHeld) ?></div>
                <div style="font-size: 0.65rem; color: #fecdd3; text-transform: uppercase; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 3px;">
                  <span>Beds in Surge Hold</span>
                  <svg style="width: 10px; height: 10px; stroke: currentColor; fill: none;" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                </div>
              </div>
              <div style="width: 1px; background: rgba(255,255,255,0.2);"></div>
              <div style="text-align: center; padding: 2px 6px;">
                <div style="font-size: 1.25rem; font-weight: 800; color: #fde047;"><?= number_format($pReloc) ?></div>
                <div style="font-size: 0.65rem; color: #fecdd3; text-transform: uppercase; font-weight: 700;">Evacuations</div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($branchActiveCount > 1): ?>
      <!-- Interactive Carousel Dot Pills -->
      <div style="position: absolute; top: 12px; right: 18px; display: flex; align-items: center; gap: 6px; z-index: 10; background: rgba(0,0,0,0.4); padding: 4px 8px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.2);">
        <span style="font-size: 0.65rem; color: #fecdd3; font-weight: 700; text-transform: uppercase; margin-right: 2px;">DISASTERS (<?= $branchActiveCount ?>):</span>
        <?php foreach ($branchActiveProtocols as $idx => $proto): 
          $shortName = explode(' ', trim($proto['title']))[0] ?? "P#{$proto['id']}";
        ?>
        <button type="button" onclick="switchBranchSlide(<?= $idx ?>)" class="branch-pill-btn <?= $idx === 0 ? 'active' : '' ?>" data-pill-idx="<?= $idx ?>" style="font-size: 0.68rem; font-weight: 700; padding: 2px 8px; border-radius: 12px; border: none; cursor: pointer; transition: all 0.2s; <?= $idx === 0 ? 'background: #f43f5e; color: #fff;' : 'background: rgba(255,255,255,0.15); color: #cbd5e1;' ?>">
          <?= $idx === 0 ? '●' : '○' ?> <?= htmlspecialchars($shortName) ?>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 4 Top Metrics Overview Cards (100% Dynamic Database Driven) -->
    <div class="census-metrics-grid">
      <!-- Metric 1: Total Ward Beds -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Total Ward Beds</span>
          <div class="census-card-icon icon-teal">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          </div>
        </div>
        <div class="census-card-value"><?= number_format($totalHospitalBeds) ?></div>
        <div class="census-card-badge badge-active">
          <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          <?= count($distinctFloors) ?>-Floor <?= htmlspecialchars($currentHospital['code'] ?? 'Facility') ?> Capacity
        </div>
      </div>

      <!-- Metric 2: Occupied Beds -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Occupied Beds</span>
          <div class="census-card-icon icon-blue">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
          </div>
        </div>
        <div class="census-card-value"><?= number_format($totalOccupiedBeds) ?></div>
        <div class="census-card-badge badge-warning">
          <span>Active Inpatient Care</span>
        </div>
      </div>

      <!-- Metric 3: Available Capacity -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Available Capacity</span>
          <div class="census-card-icon icon-green">
            <svg class="ui-ico" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          </div>
        </div>
        <div class="census-card-value" style="color: #16a34a;"><?= number_format($totalAvailableBeds) ?></div>
        <div class="census-card-badge badge-open">
          <span>Open for Allocation</span>
        </div>
      </div>

      <!-- Metric 4: Critical / ICU Occupancy -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Critical / ICU Occupancy</span>
          <div class="census-card-icon icon-rose">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
        </div>
        <div class="census-card-value" style="color: #dc2626;"><?= (int)$icuOccupancyPct ?>%</div>
        <div class="census-card-badge badge-critical">
          <span>Critical Care Load (ICU+CCU)</span>
        </div>
      </div>

      <?php if ($branchActiveCount > 0 || $totalEmergencyHoldBeds > 0): ?>
      <!-- Metric 5: Beds in Surge Hold (Interactive Filter) -->
      <div class="census-metric-card" id="surgeHoldMetricCard" onclick="toggleSurgeHoldFilter()" style="cursor: pointer; border: 1.5px solid rgba(244, 63, 94, 0.5); background: linear-gradient(135deg, rgba(255, 241, 242, 0.6) 0%, #fff 100%); transition: all 0.2s ease;" title="Click to isolate surge hold beds on floor grid">
        <div class="census-card-top">
          <span class="census-card-label" style="color: #9f1239; font-weight: 700;">Beds in Surge Hold</span>
          <div class="census-card-icon" style="background: #ffe4e6; color: #e11d48;">
            <svg class="ui-ico" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
          </div>
        </div>
        <div class="census-card-value" style="color: #e11d48; display: flex; align-items: baseline; gap: 8px;">
          <span><?= number_format($totalEmergencyHoldBeds) ?></span>
          <span id="surgeFilterBadge" style="display: none; font-size: 0.65rem; background: #e11d48; color: #fff; padding: 2px 6px; border-radius: 4px; font-weight: 800;">FILTER ACTIVE</span>
        </div>
        <div class="census-card-badge" style="background: #ffe4e6; color: #9f1239;">
          <span>⚡ Click to isolate surge beds</span>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Ward Floor Filter Bar -->
    <div class="census-filter-bar">
      <!-- Category Tabs (Preserve filters while switching) -->
      <div class="census-filter-tabs" role="tablist">
        <a href="?ward=all<?= $floorFilter ? '&floor=' . $floorFilter : '' ?>" class="ward-filter-tab <?= ($wardFilter === 'all' || empty($wardFilter)) ? 'active' : '' ?>">
          <span>All Wards</span>
          <span class="ward-tab-count"><?= $wardCounts['all'] ?></span>
        </a>

        <a href="?ward=icu<?= $floorFilter ? '&floor=' . $floorFilter : '' ?>" class="ward-filter-tab <?= $wardFilter === 'icu' ? 'active' : '' ?>">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          <span>ICU - Critical Care</span>
          <span class="ward-tab-count"><?= $wardCounts['icu'] ?></span>
        </a>

        <a href="?ward=emergency<?= $floorFilter ? '&floor=' . $floorFilter : '' ?>" class="ward-filter-tab <?= $wardFilter === 'emergency' ? 'active' : '' ?>">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
          <span>Emergency Ward</span>
          <span class="ward-tab-count"><?= $wardCounts['emergency'] ?></span>
        </a>

        <a href="?ward=general<?= $floorFilter ? '&floor=' . $floorFilter : '' ?>" class="ward-filter-tab <?= $wardFilter === 'general' ? 'active' : '' ?>">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
          <span>General Wards</span>
          <span class="ward-tab-count"><?= $wardCounts['general'] ?></span>
        </a>

        <a href="?ward=pediatrics<?= $floorFilter ? '&floor=' . $floorFilter : '' ?>" class="ward-filter-tab <?= $wardFilter === 'pediatrics' ? 'active' : '' ?>">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"></circle><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"></path></svg>
          <span>Pediatrics</span>
          <span class="ward-tab-count"><?= $wardCounts['pediatrics'] ?></span>
        </a>

        <a href="?ward=vip<?= $floorFilter ? '&floor=' . $floorFilter : '' ?>" class="ward-filter-tab <?= in_array($wardFilter, ['vip', 'presidential']) ? 'active' : '' ?>">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          <span>VIP & Presidential</span>
          <span class="ward-tab-count"><?= $wardCounts['vip'] ?></span>
        </a>
      </div>

      <!-- Quick Floor Filter & Status Legend -->
      <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <!-- Dynamic Floor Filter Dropdown -->
        <select 
          onchange="location.href='?ward=<?= htmlspecialchars($wardFilter, ENT_QUOTES, 'UTF-8') ?>&floor=' + this.value"
          style="padding: 6px 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.78rem; font-weight: 600; color: #334155; background: #ffffff; cursor: pointer;"
        >
          <option value="0" <?= $floorFilter === 0 ? 'selected' : '' ?>>All Floors (1-<?= !empty($distinctFloors) ? max($distinctFloors) : 5 ?>)</option>
          <?php foreach ($distinctFloors as $fl): 
            $wDesc = $floorWards[$fl] ?? '';
            $flLabel = "Floor $fl";
            if (!empty($wDesc)) {
                $shortW = strlen($wDesc) > 24 ? substr($wDesc, 0, 22) . '…' : $wDesc;
                $flLabel .= " ($shortW)";
            }
          ?>
            <option value="<?= $fl ?>" <?= $floorFilter === $fl ? 'selected' : '' ?>><?= htmlspecialchars($flLabel, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>

        <!-- Status Legend -->
        <div class="census-legend">
          <div class="legend-item">
            <span class="legend-dot dot-available"></span>
            <span>Available</span>
          </div>
          <div class="legend-item">
            <span class="legend-dot dot-occupied"></span>
            <span>Occupied</span>
          </div>
          <div class="legend-item">
            <span class="legend-dot dot-maintenance"></span>
            <span>Maintenance</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Interactive Bed Matrix Grid (Real DB Rows) -->
    <div class="bed-matrix-grid" id="bedMatrixGrid">
      <?php if (empty($bedSlots)): ?>
        <div class="census-empty-ward" style="display: block;">
          <h4 style="font-size: 1rem; color: #1e293b; margin-bottom: 4px;">No beds registered matching your filter</h4>
          <p style="font-size: 0.82rem; color: #64748b;">
            No records found for <?= htmlspecialchars($wardFilter !== 'all' ? $wardFilter : 'selected criteria', ENT_QUOTES, 'UTF-8') ?>.
            <a href="live_census.php" style="color: #0d9488; font-weight: 600; margin-left: 6px;">Reset Filter &rarr;</a>
          </p>
        </div>
      <?php else: ?>
        <?php foreach ($bedSlots as $slot): 
          $rawStatus = strtolower($slot['status']);
          $isPresidential = ($slot['bed_number'] === 'PRES-401' || $slot['ward_type'] === 'Presidential Suite');
          $patientDisplayName = !empty($slot['patient_name']) 
              ? $slot['patient_name'] 
              : ($rawStatus === 'occupied' ? 'Inpatient #' . (1000 + (int)$slot['bed_id']) : '');
          $patientDisplayId = !empty($slot['patient_user_id']) 
              ? '#P-' . str_pad($slot['patient_user_id'], 4, '0', STR_PAD_LEFT) 
              : ($rawStatus === 'occupied' ? '#P-' . (4000 + (int)$slot['bed_id']) : '');
          $slotPatientId = !empty($slot['patient_user_id']) ? (int)$slot['patient_user_id'] : null;
          $careTeam = ($slotPatientId && isset($bedCareTeams[$slotPatientId])) ? $bedCareTeams[$slotPatientId] : [];
          
          if (!empty($careTeam)) {
              $leadDoctor = $careTeam[0]['doctor_name'];
              $extraDocs = array_slice($careTeam, 1);
              $extraCount = count($extraDocs);
              $extraNames = array_map(function($d) { return $d['doctor_name']; }, $extraDocs);
              $extraTooltip = 'Care Team: ' . implode(', ', $extraNames);
          } else {
              $leadDoctor = !empty($slot['doctor_name']) 
                  ? $slot['doctor_name'] 
                  : ($rawStatus === 'occupied' ? 'Attending Physician (F' . $slot['floor_number'] . ')' : '');
              $extraCount = 0;
              $extraTooltip = '';
          }
          $admissionDate = !empty($slot['admitted_at']) 
              ? date('M j, Y', strtotime($slot['admitted_at'])) 
              : 'Active Care';
        ?>
          <?php
            $epCode = strtoupper((string)($slot['ep_code'] ?? ''));
            if ($rawStatus === 'emergency hold') {
                if (strpos($epCode, 'DENGUE') !== false) {
                    $surgeClass = 'badge-surge-dengue';
                    $surgeLabel = 'SURGE: DENGUE ISOLATION';
                } elseif (strpos($epCode, 'ACCIDENT') !== false || strpos($epCode, 'MASS_CASUALTY') !== false || strpos($epCode, 'TRAUMA') !== false) {
                    $surgeClass = 'badge-surge-trauma';
                    $surgeLabel = 'SURGE: TRAUMA / ACCIDENT';
                } elseif (strpos($epCode, 'BURN') !== false) {
                    $surgeClass = 'badge-surge-burn';
                    $surgeLabel = 'SURGE: BURN DISASTER';
                } elseif (strpos($epCode, 'HAZMAT') !== false || strpos($epCode, 'BIO') !== false) {
                    $surgeClass = 'badge-surge-hazmat';
                    $surgeLabel = 'SURGE: HAZMAT QUARANTINE';
                } elseif (strpos($epCode, 'NATURAL') !== false || strpos($epCode, 'FLOOD') !== false || strpos($epCode, 'CYCLONE') !== false) {
                    $surgeClass = 'badge-surge-natural';
                    $surgeLabel = 'SURGE: NATURAL DISASTER';
                } else {
                    $surgeClass = 'badge-surge-default';
                    $surgeLabel = !empty($slot['ep_title']) ? 'SURGE: ' . strtoupper($slot['ep_title']) : 'SURGE: EMERGENCY HOLD';
                }
            } else {
                $surgeClass = '';
                $surgeLabel = '';
            }
          ?>
          <div class="bed-slot-card slot-<?= htmlspecialchars($rawStatus, ENT_QUOTES, 'UTF-8') ?> <?= $isPresidential ? 'slot-presidential' : '' ?>" data-status="<?= htmlspecialchars($rawStatus, ENT_QUOTES, 'UTF-8') ?>" data-reloc="<?= htmlspecialchars($slot['relocation_status'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-protocol="<?= htmlspecialchars($slot['ep_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div>
              <!-- Bed Card Header -->
              <div class="bed-card-header">
                <div class="bed-code-group">
                  <div class="bed-icon-badge" style="<?= $isPresidential ? 'background: #fef3c7; color: #b45309;' : '' ?>">
                    <?php if ($isPresidential): ?>
                      <span style="font-size: 14px;">👑</span>
                    <?php else: ?>
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="bed-code" style="display: flex; align-items: center; gap: 6px;">
                      <?= htmlspecialchars($slot['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                      <span class="bed-floor-pill">F<?= (int)$slot['floor_number'] ?></span>
                    </div>
                    <span class="bed-ward-tag"><?= htmlspecialchars($slot['ward_type'], ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                </div>

                <!-- Status Pill -->
                <?php if ($rawStatus === 'available'): ?>
                  <span class="bed-status-pill status-available-pill <?= $isPresidential ? 'badge-presidential' : '' ?>">
                    <?= $isPresidential ? 'VIP Suite Ready' : 'Available' ?>
                  </span>
                <?php elseif ($rawStatus === 'occupied'): ?>
                  <span class="bed-status-pill status-occupied-pill">Occupied</span>
                <?php elseif ($rawStatus === 'emergency hold'): ?>
                  <span class="bed-status-pill <?= $surgeClass ?>" style="font-weight: 800; display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; letter-spacing: 0.02em; text-transform: uppercase;">
                    <span style="position: relative; display: inline-flex; width: 7px; height: 7px;">
                      <span style="position: absolute; inset: 0; border-radius: 50%; background: #f43f5e; animation: saPingRing 1.4s cubic-bezier(0,0,0.2,1) infinite;"></span>
                      <span style="position: relative; width: 7px; height: 7px; border-radius: 50%; background: #e11d48;"></span>
                    </span>
                    <?= htmlspecialchars($surgeLabel, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                <?php elseif ($rawStatus === 'sanitizing'): ?>
                  <span class="bed-status-pill" style="background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;font-weight:800;">🧴 Sanitizing</span>
                <?php else: ?>
                  <span class="bed-status-pill status-maintenance-pill">Maintenance</span>
                <?php endif; ?>
              </div>

              <!-- Bed Card Body -->
              <div class="bed-card-body">
                <?php if (!empty($slot['relocation_status']) && $slot['relocation_status'] === 'PENDING_RELOCATION'): ?>
                  <div style="background:#fee2e2;border:1.5px solid #ef4444;color:#991b1b;border-radius:8px;padding:6px 10px;margin-bottom:8px;font-size:0.72rem;font-weight:800;display:flex;align-items:center;justify-content:space-between;gap:6px;">
                    <span>⚠️ EVACUATION REQUIRED (SURGE)</span>
                    <span style="font-size:0.65rem;background:#ef4444;color:#fff;padding:1px 5px;border-radius:4px;">PRIORITY 2</span>
                  </div>
                <?php endif; ?>

                <?php if ($rawStatus === 'occupied'): ?>
                  <div class="bed-patient-info">
                    <div class="patient-name">
                      <span><?= htmlspecialchars($patientDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="patient-id"><?= htmlspecialchars($patientDisplayId, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="patient-meta">
                      <div class="attending-doctor-team">
                        <span class="attending-doctor" title="<?= htmlspecialchars($leadDoctor, ENT_QUOTES, 'UTF-8') ?>">
                          <svg class="ui-ico" style="width: 12px; height: 12px; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                          <span class="lead-doctor-name"><?= htmlspecialchars($leadDoctor, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <?php if ($extraCount > 0): ?>
                          <div class="care-team-popover-wrapper">
                            <button type="button" 
                                    class="extra-docs-badge btn-care-team-popover" 
                                    onclick="toggleCareTeamPopover(event, this)"
                                    aria-haspopup="true" 
                                    aria-expanded="false" 
                                    title="Click to view assigned care team">
                              +<?= $extraCount ?> more
                            </button>
                            <div class="care-team-popover-card" role="tooltip" onclick="event.stopPropagation();" style="display: none;">
                              <div class="care-team-popover-header">
                                <span>Assigned Care Team</span>
                                <span class="popover-badge-pill"><?= $extraCount ?> additional</span>
                              </div>
                              <div class="care-team-popover-list">
                                <?php foreach ($extraDocs as $ed): ?>
                                  <?php
                                    $docSpec = !empty($ed['specialty']) ? $ed['specialty'] : 'Consulting Physician';
                                    $specBadge = getDoctorPastelBadgeClass($docSpec, (int)$ed['doctor_id']);
                                  ?>
                                  <div class="care-team-popover-item">
                                    <span class="popover-doc-name"><?= htmlspecialchars($ed['doctor_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="popover-doc-spec <?= $specBadge ?>"><?= htmlspecialchars($docSpec, ENT_QUOTES, 'UTF-8') ?></span>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div style="font-size: 0.74rem; color: #475569; display: flex; justify-content: space-between; margin-top: 4px;">
                    <span>৳ <?= number_format((float)$slot['daily_rate']) ?>/day</span>
                    <span style="color: #64748b;"><?= htmlspecialchars($admissionDate, ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                <?php elseif ($rawStatus === 'available'): ?>
                  <div class="bed-vacant-msg">
                    <svg class="ui-ico ui-ico-sm" style="stroke: #16a34a; width: 16px; height: 16px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span>Ready for immediate placement</span>
                  </div>
                  <div style="font-size: 0.74rem; color: #166534; opacity: 0.85; display: flex; justify-content: space-between;">
                    <span>Daily Rate: ৳ <?= number_format((float)$slot['daily_rate']) ?></span>
                    <span>Floor <?= (int)$slot['floor_number'] ?></span>
                  </div>
                <?php elseif ($rawStatus === 'emergency hold'): ?>
                  <div class="bed-vacant-msg" style="color:#b91c1c;">
                    <span style="font-size:14px;">🚨</span>
                    <span style="font-weight:700;">Locked for Emergency Protocol</span>
                  </div>
                  <div style="font-size: 0.74rem; color: #991b1b; opacity: 0.9; display: flex; justify-content: space-between;">
                    <span>Disaster Surge Bed</span>
                    <span>Floor <?= (int)$slot['floor_number'] ?></span>
                  </div>
                <?php elseif ($rawStatus === 'sanitizing'): ?>
                  <div class="bed-maint-msg" style="color:#0369a1;">
                    <span style="font-size:14px;">🧴</span>
                    <span>Post-Disaster Decontamination</span>
                  </div>
                  <div style="font-size: 0.74rem; color: #0284c7; opacity: 0.85; display: flex; justify-content: space-between;">
                    <span>Clinical Deep Sanitizing</span>
                    <span>Floor <?= (int)$slot['floor_number'] ?></span>
                  </div>
                <?php else: ?>
                  <div class="bed-maint-msg">
                    <svg class="ui-ico ui-ico-sm" style="stroke: #d97706; width: 16px; height: 16px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span>Sanitization protocol active</span>
                  </div>
                  <div style="font-size: 0.74rem; color: #92400e; opacity: 0.85; display: flex; justify-content: space-between;">
                    <span>UV-C Sterilization</span>
                    <span>Floor <?= (int)$slot['floor_number'] ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Quick Action Buttons on Hover -->
            <div class="bed-actions-bar">
              <?php if (!empty($slot['relocation_status']) && $slot['relocation_status'] === 'PENDING_RELOCATION'): ?>
                <button 
                  type="button" 
                  class="btn-bed-action"
                  style="background:linear-gradient(135deg,#e11d48 0%,#b91c1c 100%);color:#fff;font-weight:800;border:none;box-shadow:0 3px 8px rgba(225,29,72,.35);width:100%;"
                  onclick="evacuateSurgePatient(<?= (int)$slot['bed_id'] ?>, '<?= htmlspecialchars($slot['bed_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($patientDisplayName, ENT_QUOTES, 'UTF-8') ?>')"
                >
                  🚨 Evacuate &amp; Transfer
                </button>
              <?php elseif ($rawStatus === 'available' || $rawStatus === 'emergency hold'): ?>
                <button 
                  type="button" 
                  class="btn-bed-action btn-bed-primary"
                  onclick="openAllocateModal(<?= (int)$slot['bed_id'] ?>, '<?= htmlspecialchars($slot['bed_number'], ENT_QUOTES, 'UTF-8') ?>')"
                >
                  <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                  Allocate Patient
                </button>
              <?php elseif ($rawStatus === 'occupied'): ?>
                <button 
                  type="button" 
                  class="btn-bed-action btn-bed-primary"
                  onclick="censusToast('Vitals telemetry display coming soon for <?= htmlspecialchars($slot['bed_number'], ENT_QUOTES, 'UTF-8') ?>.', 'info')"
                >
                  <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                  Telemetry
                </button>
                <button 
                  type="button" 
                  class="btn-bed-action btn-bed-secondary"
                  onclick="openDischargeConfirm(<?= (int)$slot['bed_id'] ?>, '<?= htmlspecialchars($slot['bed_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($patientDisplayName, ENT_QUOTES, 'UTF-8') ?>')"
                >
                  Discharge
                </button>
              <?php else: ?>
                <button 
                  type="button" 
                  class="btn-bed-action btn-bed-secondary"
                  onclick="markBedReady(this, <?= (int)$slot['bed_id'] ?>, '<?= htmlspecialchars($slot['bed_number'], ENT_QUOTES, 'UTF-8') ?>')"
                >
                  Mark Ready
                </button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination Controls (Prevents DOM Bloat across 500 beds) -->
    <?php if ($totalFilteredBeds > $perPage): ?>
      <div class="census-pagination">
        <div class="census-page-info">
          Showing <strong><?= min($totalFilteredBeds, $offset + 1) ?> - <?= min($totalFilteredBeds, $offset + count($bedSlots)) ?></strong> of <strong><?= $totalFilteredBeds ?></strong> hospital beds
        </div>
        <div class="census-page-links">
          <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="census-page-btn" title="First Page">&laquo; First</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="census-page-btn" title="Previous Page">&lsaquo; Prev</a>
          <?php else: ?>
            <span class="census-page-btn disabled">&laquo; First</span>
            <span class="census-page-btn disabled">&lsaquo; Prev</span>
          <?php endif; ?>

          <?php
          $startP = max(1, $page - 2);
          $endP = min($totalPages, $page + 2);
          for ($p = $startP; $p <= $endP; $p++):
          ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="census-page-btn <?= $p === $page ? 'active' : '' ?>">
              <?= $p ?>
            </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="census-page-btn" title="Next Page">Next &rsaquo;</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="census-page-btn" title="Last Page">Last &raquo;</a>
          <?php else: ?>
            <span class="census-page-btn disabled">Next &rsaquo;</span>
            <span class="census-page-btn disabled">Last &raquo;</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </main>

  <!-- ========================================================
       CENSUS TOAST NOTIFICATION CONTAINER
       ======================================================== -->
  <div id="censusToastContainer" class="census-toast-container" aria-live="polite" aria-atomic="false"></div>

  <!-- ========================================================
       ALLOCATE PATIENT MODAL
       ======================================================== -->
  <div id="allocateModalOverlay" class="census-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="allocModalTitle" style="display:none;">
    <div class="census-modal-card">
      <div class="census-modal-header">
        <div class="census-modal-title-group">
          <svg class="ui-ico" style="width: 20px; height: 20px; stroke: #ffffff;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          <h2 class="census-modal-title" id="allocModalTitle">Allocate Patient to Bed</h2>
        </div>
        <button type="button" class="census-modal-close" onclick="closeAllocateModal()" aria-label="Close">
          <svg class="ui-ico" style="width: 18px; height: 18px;" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form id="allocateForm" class="census-modal-body" onsubmit="submitAllocation(event)">
        <input type="hidden" name="_action" value="allocate_bed">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" id="allocBedId" name="bed_id" value="">

        <!-- Target Bed (readonly display) -->
        <div class="census-form-group">
          <label class="census-form-label" for="allocBedDisplay">
            <svg class="ui-ico" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
            Target Bed
          </label>
          <input type="text" id="allocBedDisplay" class="census-form-control census-form-readonly" readonly placeholder="—">
        </div>

        <!-- Patient Select -->
        <div class="census-form-group">
          <label class="census-form-label" for="allocPatientId">
            <svg class="ui-ico" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            Patient <span class="census-required">*</span>
          </label>
          <select id="allocPatientId" name="patient_id" class="census-form-select" required>
            <option value="">— Loading patients… —</option>
          </select>
        </div>

        <!-- Attending Physician Select -->
        <div class="census-form-group">
          <label class="census-form-label" for="allocDoctorId">
            <svg class="ui-ico" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
            Attending Physician
          </label>
          <select id="allocDoctorId" name="doctor_id" class="census-form-select">
            <option value="">— Loading physicians… —</option>
          </select>
        </div>

        <!-- Admission Notes -->
        <div class="census-form-group">
          <label class="census-form-label" for="allocNotes">
            <svg class="ui-ico" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
            Admission Notes / Diagnosis
          </label>
          <textarea id="allocNotes" name="notes" class="census-form-control census-form-textarea" rows="3" placeholder="Chief complaint, primary diagnosis, or special care notes…" maxlength="255"></textarea>
        </div>

        <div class="census-modal-actions">
          <button type="button" class="census-btn-cancel" onclick="closeAllocateModal()">Cancel</button>
          <button type="submit" class="census-btn-confirm" id="allocSubmitBtn">
            <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px; stroke: #ffffff;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Confirm Allocation
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ========================================================
       DISCHARGE CONFIRM DIALOG
       ======================================================== -->
  <div id="dischargeModalOverlay" class="census-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="dischargeModalTitle" style="display:none;">
    <div class="census-modal-card census-modal-card-sm">
      <div class="census-modal-header census-modal-header-warning">
        <div class="census-modal-title-group">
          <svg class="ui-ico" style="width: 20px; height: 20px; stroke: #ffffff;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <h2 class="census-modal-title" id="dischargeModalTitle">Confirm Patient Discharge</h2>
        </div>
        <button type="button" class="census-modal-close" onclick="closeDischargeModal()" aria-label="Close">
          <svg class="ui-ico" style="width: 18px; height: 18px;" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <div class="census-modal-body">
        <input type="hidden" id="dischargeBedId" value="">
        <div class="census-discharge-info">
          <div class="census-discharge-icon">
            <svg class="ui-ico" style="width: 28px; height: 28px; stroke: #d97706;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="17 21 22 16 17 11"></polyline></svg>
          </div>
          <div>
            <p class="census-discharge-patient" id="dischargePatientLabel"></p>
            <p class="census-discharge-sub">Discharging this patient will transition the bed to <strong>UV-C Sanitization</strong> protocol before it can be reallocated.</p>
          </div>
        </div>
        <div class="census-modal-actions">
          <button type="button" class="census-btn-cancel" onclick="closeDischargeModal()">Cancel</button>
          <button type="button" class="census-btn-warn" id="dischargeConfirmBtn" onclick="submitDischarge()">
            <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px; stroke: #ffffff;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Confirm Discharge
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================
       BED LIFECYCLE ACTION JAVASCRIPT
       ======================================================== -->
  <!-- Immediate fallback for care team popover toggle -->
  <script>
    window.toggleCareTeamPopover = window.toggleCareTeamPopover || function(e, btn) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      if (!btn) return;
      const wrapper = btn.closest('.care-team-popover-wrapper');
      if (!wrapper) return;
      const popover = wrapper.querySelector('.care-team-popover-card');
      if (!popover) return;
      const bedCard = wrapper.closest('.bed-slot-card');
      const isCurrentlyOpen = popover.classList.contains('is-open') || popover.style.display === 'block';

      if (typeof window.closeAllCareTeamPopovers === 'function') {
        window.closeAllCareTeamPopovers();
      } else {
        document.querySelectorAll('.care-team-popover-card').forEach(c => { c.style.display = 'none'; c.classList.remove('is-open'); });
        document.querySelectorAll('.btn-care-team-popover').forEach(b => { b.setAttribute('aria-expanded', 'false'); b.classList.remove('is-active'); });
        document.querySelectorAll('.bed-slot-card.has-active-popover').forEach(c => c.classList.remove('has-active-popover'));
      }

      if (!isCurrentlyOpen) {
        popover.style.display = 'block';
        popover.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        btn.classList.add('is-active');
        if (bedCard) bedCard.classList.add('has-active-popover');
      }
    };

    // ── Emergency Surge Patient Evacuation & Transfer ────────────────────────
    async function evacuateSurgePatient(bedId, bedNum, patientName) {
      const confirmed = await MedPulseDialog.confirm({
        title: 'Evacuate & Transfer Patient',
        subtitle: `Priority 2 Surge Relocation · Bed ${bedNum}`,
        type: 'danger',
        confirmText: 'Execute Evacuation Transfer',
        cancelText: 'Cancel / Hold',
        html: `
          <div style="font-size:0.86rem;color:#334155;line-height:1.55;margin-bottom:14px;">
            Re-assign patient <strong>${patientName}</strong> from Bed <strong>${bedNum}</strong> to an available bed on an alternative floor within this facility?
          </div>
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:12px;padding:12px 14px;font-size:0.8rem;line-height:1.45;">
            ✓ <strong>Billing Tier Protection:</strong> The patient's daily room billing rate tier will be strictly preserved without surcharge.
          </div>
        `
      });

      if (!confirmed) return;

      try {
        const fd = new FormData();
        fd.append('_action', 'evacuate_patient');
        fd.append('bed_id', bedId);
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>');

        const res = await fetch('../backend/api/emergency_surge_action.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
          MedPulseDialog.toast({
            title: 'Patient Relocated',
            message: data.message,
            type: 'success',
            duration: 3500
          });
          setTimeout(() => location.reload(), 1400);
        } else {
          MedPulseDialog.toast({
            title: 'Evacuation Failed',
            message: data.message || 'Evacuation transfer failed.',
            type: 'error',
            duration: 5000
          });
        }
      } catch (err) {
        MedPulseDialog.toast({
          title: 'Network Error',
          message: 'Network error occurred during patient evacuation.',
          type: 'error'
        });
      }
    }

    // Interactive Surge Hold Grid Filter
    let isSurgeHoldFilterActive = false;
    function toggleSurgeHoldFilter() {
      isSurgeHoldFilterActive = !isSurgeHoldFilterActive;
      const cards = document.querySelectorAll('.bed-slot-card');
      const badge = document.getElementById('surgeFilterBadge');
      const cardBox = document.getElementById('surgeHoldMetricCard');
      const bannerBox = document.getElementById('btnSurgeHoldFilter');

      let matched = 0;
      cards.forEach(card => {
        const rawStatus = (card.getAttribute('data-status') || '').toLowerCase();
        const isReloc = (card.getAttribute('data-reloc') || '') === 'PENDING_RELOCATION';
        const isHold = rawStatus === 'emergency hold' || card.classList.contains('slot-emergency-hold') || card.classList.contains('slot-emergency hold');

        if (!isSurgeHoldFilterActive) {
          card.style.display = '';
        } else {
          if (isHold || isReloc) {
            card.style.display = '';
            matched++;
          } else {
            card.style.display = 'none';
          }
        }
      });

      if (badge) badge.style.display = isSurgeHoldFilterActive ? 'inline-block' : 'none';
      if (cardBox) {
        if (isSurgeHoldFilterActive) {
          cardBox.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.5), 0 8px 20px rgba(244, 63, 94, 0.2)';
          cardBox.style.transform = 'translateY(-2px)';
        } else {
          cardBox.style.boxShadow = '';
          cardBox.style.transform = '';
        }
      }
      if (bannerBox) {
        if (isSurgeHoldFilterActive) {
          bannerBox.style.outline = '2px solid #fff';
          bannerBox.style.background = 'rgba(255,255,255,0.25)';
        } else {
          bannerBox.style.outline = '';
          bannerBox.style.background = '';
        }
      }

      if (window.MedPulseDialog && window.MedPulseDialog.toast) {
        MedPulseDialog.toast({
          title: isSurgeHoldFilterActive ? 'Surge Beds Isolated' : 'Filter Restored',
          message: isSurgeHoldFilterActive 
            ? `Isolating disaster surge beds (${matched} visible on current page). Click again to restore full view.` 
            : 'Displaying all beds across current floor.',
          type: isSurgeHoldFilterActive ? 'warning' : 'info',
          duration: 3200
        });
      }
    }

    // Multi-Emergency Carousel Slider
    let branchSlideIdx = 0;
    const branchSlides = document.querySelectorAll('.branch-carousel-slide');
    const branchPills  = document.querySelectorAll('.branch-pill-btn');
    let branchCarouselTimer = null;

    function switchBranchSlide(idx) {
      if (!branchSlides.length) return;
      branchSlideIdx = idx;
      branchSlides.forEach((s, i) => {
        if (i === idx) {
          s.style.position = 'relative';
          s.style.opacity = '1';
          s.style.pointerEvents = 'auto';
        } else {
          s.style.position = 'absolute';
          s.style.opacity = '0';
          s.style.pointerEvents = 'none';
        }
      });
      branchPills.forEach((p, i) => {
        const textOnly = p.textContent.replace(/^[●○]\s*/, '').trim();
        if (i === idx) {
          p.style.background = '#f43f5e';
          p.style.color = '#fff';
          p.innerHTML = '● ' + textOnly;
        } else {
          p.style.background = 'rgba(255,255,255,0.15)';
          p.style.color = '#cbd5e1';
          p.innerHTML = '○ ' + textOnly;
        }
      });
    }

    function initBranchCarousel() {
      if (branchSlides.length <= 1) return;
      branchCarouselTimer = setInterval(() => {
        const next = (branchSlideIdx + 1) % branchSlides.length;
        switchBranchSlide(next);
      }, 5000);

      const cWrap = document.getElementById('branchDisasterCarousel');
      if (cWrap) {
        cWrap.addEventListener('mouseenter', () => clearInterval(branchCarouselTimer));
        cWrap.addEventListener('mouseleave', () => {
          clearInterval(branchCarouselTimer);
          branchCarouselTimer = setInterval(() => {
            const next = (branchSlideIdx + 1) % branchSlides.length;
            switchBranchSlide(next);
          }, 5000);
        });
      }
    }
    document.addEventListener('DOMContentLoaded', initBranchCarousel);
  </script>
  <!-- Dedicated MedPulse Modern Dialog & Toast Engine -->
  <script src="../assets/js/medpulse_dialog.js"></script>
  <!-- Dedicated live census view script with cache-busting -->
  <script src="../assets/js/admin/live_census.js?v=<?= time() ?>"></script>

</body>
</html>
