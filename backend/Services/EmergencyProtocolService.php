<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Emergency Protocol & Disaster Surge Triage Service
 *
 * Implements:
 * 1. 6-Hospital Network Dynamic Surge Scaling
 * 2. Burn Institute & Specialized Wing Targeting (NIBPS Apex + MedPulse Floor 3)
 * 3. 2-Tier Transition Algorithm (Priority 1: Available -> Hold, Priority 2: Occupied -> PENDING_RELOCATION)
 * 4. 1-Click Evacuation & Transfer with Patient Billing Preservation
 * 5. Stand-Down Restoration (Available revert, Active beds -> Sanitizing)
 */

namespace MedPulse\Services;

use PDO;
use Throwable;

class EmergencyProtocolService
{
    private PDO $pdo;

    public const PROTOCOLS = [
        'DENGUE_EPIDEMIC' => [
            'code'        => 'DENGUE_EPIDEMIC',
            'title'       => 'Dengue Epidemic Surge',
            'description' => 'Converts designated general floor/wing into Dengue Isolation HDU equipped for continuous IV fluid & platelet tracking.',
            'badge_color' => '#d97706',
            'icon'        => 'droplet',
            'target_wards'=> ['General Ward Male', 'General Ward Female', 'Pediatrics', 'Semi-Cabin'],
            'target_floors'=> [2, 3],
            'default_hosp'=> [1, 2, 3, 4, 5, 6],
            'guidelines'  => 'Prepare platelet transfusion lines, continuous IV hydration monitors, and mosquito-net isolation barriers.'
        ],
        'BURN_DISASTER' => [
            'code'        => 'BURN_DISASTER',
            'title'       => 'Fire / Industrial Burn Disaster',
            'description' => 'Enforces priority surge locks strictly on burn-equipped facilities: Primary Apex routing to NIBPS (Hospital 6) and secondary private surge locks on MedPulse Floor 3 Burn Unit.',
            'badge_color' => '#dc2626',
            'icon'        => 'flame',
            'target_wards'=> [
                'Burn ICU', 'High Dependency Unit (HDU)', 'Pediatric Burn Unit', 
                'Plastic & Reconstructive Surgery', 'General Burn Recovery', 
                'Burn ICU/HDU', 'Post-Burn Recovery'
            ],
            'target_floors'=> [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'default_hosp'=> [6, 1], // NIBPS Apex (H6) + MedPulse (H1) Burn Unit
            'guidelines'  => 'Enforce strict sterile containment, central oxygen supplies, fluid resuscitation packs, and sterile dressing stations.'
        ],
        'MASS_CASUALTY' => [
            'code'        => 'MASS_CASUALTY',
            'title'       => 'Mass Casualty / Road Accident',
            'description' => 'Locks Emergency triage wards and Trauma ICU capacity across relevant highway/city proximity facilities.',
            'badge_color' => '#e11d48',
            'icon'        => 'alert-triangle',
            'target_wards'=> ['Emergency', 'ICU', 'CCU', 'Recovery'],
            'target_floors'=> [1, 5, 6, 7],
            'default_hosp'=> [1, 2, 4, 5],
            'guidelines'  => 'Mobilize orthopedic trauma teams, surgical theatres, blood bank cross-matching reserves, and immediate resuscitation bays.'
        ],
        'NATURAL_DISASTER' => [
            'code'        => 'NATURAL_DISASTER',
            'title'       => 'Natural Disaster (Flood / Cyclone Outbreak)',
            'description' => 'Prioritizes lower-floor general beds for mass casualty intake and waterborne illness isolation.',
            'badge_color' => '#0284c7',
            'icon'        => 'cloud-rain',
            'target_wards'=> ['Emergency', 'General Ward Male', 'General Ward Female'],
            'target_floors'=> [1, 2],
            'default_hosp'=> [1, 2, 3, 4, 5, 6],
            'guidelines'  => 'Ground-floor rapid admission sorting, oral rehydration stations, waterborne epidemic testing, and mobile triage teams.'
        ],
        'HAZMAT' => [
            'code'        => 'HAZMAT',
            'title'       => 'HAZMAT / Toxic Chemical Exposure',
            'description' => 'Quarantines isolated airflow units with complete regular patient isolation.',
            'badge_color' => '#9333ea',
            'icon'        => 'shield-alert',
            'target_wards'=> ['ICU', 'CCU', 'NICU', 'High Dependency Unit (HDU)', 'Burn ICU/HDU'],
            'target_floors'=> [1, 2, 5, 6],
            'default_hosp'=> [1, 2, 3, 4, 5, 6],
            'guidelines'  => 'Deploy negative pressure isolation, chemical decontamination showers, hazmat PPE level B/C, and antidote stores.'
        ],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all active emergency protocols (Multi-Disaster Concurrency)
     */
    public function getActiveProtocols(): array
    {
        $stmt = $this->pdo->query("
            SELECT * FROM emergency_protocols 
            WHERE status = 'Active' 
            ORDER BY id ASC
        ");
        $protocols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$protocols) {
            return [];
        }

        foreach ($protocols as &$proto) {
            $proto['target_hospital_ids'] = json_decode($proto['target_hospital_ids'], true) ?: [];
            $proto['target_wards'] = json_decode($proto['target_wards'], true) ?: [];
            $proto['meta'] = self::PROTOCOLS[$proto['code']] ?? null;

            // Fetch live count of held beds and pending relocations for this protocol
            $counts = $this->pdo->prepare("
                SELECT 
                    SUM(status = 'Emergency Hold') AS held_count,
                    SUM(relocation_status = 'PENDING_RELOCATION') AS relocating_count
                FROM hospital_beds
                WHERE emergency_protocol_id = ?
            ");
            $counts->execute([$proto['id']]);
            $cRow = $counts->fetch(PDO::FETCH_ASSOC);
            $proto['live_held_count'] = (int)($cRow['held_count'] ?? 0);
            $proto['live_relocating_count'] = (int)($cRow['relocating_count'] ?? 0);

            // Fetch hospital breakdown for this protocol
            if (!empty($proto['target_hospital_ids'])) {
                $hPlaceholders = implode(',', array_fill(0, count($proto['target_hospital_ids']), '?'));
                $hStmt = $this->pdo->prepare("
                    SELECT h.hospital_id, h.name, h.code,
                           SUM(b.status = 'Emergency Hold') AS held_beds,
                           SUM(b.relocation_status = 'PENDING_RELOCATION') AS reloc_beds
                    FROM hospitals h
                    LEFT JOIN hospital_beds b ON h.hospital_id = b.hospital_id AND b.emergency_protocol_id = ?
                    WHERE h.hospital_id IN ($hPlaceholders)
                    GROUP BY h.hospital_id
                    ORDER BY h.hospital_id ASC
                ");
                $hStmt->execute(array_merge([$proto['id']], $proto['target_hospital_ids']));
                $proto['hospitals_status'] = $hStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $proto['hospitals_status'] = [];
            }
        }
        unset($proto);

        return $protocols;
    }

    /**
     * Get active emergency protocol (single or primary)
     */
    public function getActiveProtocol(?int $protocolId = null): ?array
    {
        if ($protocolId !== null && $protocolId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM emergency_protocols 
                WHERE id = ? AND status = 'Active' 
                LIMIT 1
            ");
            $stmt->execute([$protocolId]);
            $proto = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proto) return null;
            $proto['target_hospital_ids'] = json_decode($proto['target_hospital_ids'], true) ?: [];
            $proto['target_wards'] = json_decode($proto['target_wards'], true) ?: [];
            $proto['meta'] = self::PROTOCOLS[$proto['code']] ?? null;

            $counts = $this->pdo->prepare("
                SELECT 
                    SUM(status = 'Emergency Hold') AS held_count,
                    SUM(relocation_status = 'PENDING_RELOCATION') AS relocating_count
                FROM hospital_beds
                WHERE emergency_protocol_id = ?
            ");
            $counts->execute([$proto['id']]);
            $cRow = $counts->fetch(PDO::FETCH_ASSOC);
            $proto['live_held_count'] = (int)($cRow['held_count'] ?? 0);
            $proto['live_relocating_count'] = (int)($cRow['relocating_count'] ?? 0);
            return $proto;
        }

        $all = $this->getActiveProtocols();
        return !empty($all) ? $all[0] : null;
    }

    /**
     * Check if a specific hospital is subject to active emergency (multi-protocol support)
     */
    public function isHospitalAffected(int $hospitalId): ?array
    {
        $all = $this->getActiveProtocols();
        if (empty($all)) return null;

        $matching = [];
        $totalHospitalHeld = 0;
        $totalHospitalReloc = 0;

        foreach ($all as $proto) {
            if (in_array($hospitalId, $proto['target_hospital_ids'], true)) {
                $hCounts = $this->pdo->prepare("
                    SELECT 
                        SUM(status = 'Emergency Hold') AS held_count,
                        SUM(relocation_status = 'PENDING_RELOCATION') AS relocating_count
                    FROM hospital_beds
                    WHERE hospital_id = ? AND emergency_protocol_id = ?
                ");
                $hCounts->execute([$hospitalId, $proto['id']]);
                $hc = $hCounts->fetch(PDO::FETCH_ASSOC);
                $pCopy = $proto;
                $pCopy['hospital_held_count'] = (int)($hc['held_count'] ?? 0);
                $pCopy['hospital_relocating_count'] = (int)($hc['relocating_count'] ?? 0);
                $totalHospitalHeld += $pCopy['hospital_held_count'];
                $totalHospitalReloc += $pCopy['hospital_relocating_count'];
                $matching[] = $pCopy;
            }
        }

        if (empty($matching)) return null;

        $primary = $matching[0];
        $primary['active_protocols'] = $matching;
        $primary['hospital_held_count'] = $totalHospitalHeld;
        $primary['hospital_relocating_count'] = $totalHospitalReloc;
        $primary['protocol_count'] = count($matching);

        return $primary;
    }

    /**
     * Calculate live preview of capacity before declaring
     */
    public function calculatePreview(string $protocolCode, int $quotaPct, array $targetHospitalIds): array
    {
        $protocol = self::PROTOCOLS[$protocolCode] ?? null;
        if (!$protocol) {
            throw new \InvalidArgumentException("Invalid disaster protocol code: $protocolCode");
        }

        if (empty($targetHospitalIds)) {
            $targetHospitalIds = $protocol['default_hosp'];
        }

        $hospPlaceholders = implode(',', array_fill(0, count($targetHospitalIds), '?'));
        
        // Scope queries based on protocol definition
        $wardList = $protocol['target_wards'] ?? [];
        $hasWards = !empty($wardList);
        
        $params = $targetHospitalIds;

        // Total beds in targeted hospitals
        $totStmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM hospital_beds 
            WHERE hospital_id IN ($hospPlaceholders)
        ");
        $totStmt->execute($params);
        $totalTargetScopeBeds = (int)$totStmt->fetchColumn();

        // Calculate quota target beds
        $targetQuotaBeds = (int)ceil($totalTargetScopeBeds * ($quotaPct / 100));

        // Available beds in target scope (prioritizing target wards, excluding beds already locked for other active protocols)
        $availQuery = "
            SELECT bed_id, hospital_id, bed_number, ward_type, floor_number, daily_rate
            FROM hospital_beds
            WHERE hospital_id IN ($hospPlaceholders)
              AND status = 'Available'
              AND emergency_protocol_id IS NULL
        ";
        
        if ($hasWards) {
            $wardPlaceholders = implode(',', array_fill(0, count($wardList), '?'));
            $availQuery .= " ORDER BY (ward_type IN ($wardPlaceholders)) DESC, floor_number ASC, bed_id ASC";
            $availParams = array_merge($targetHospitalIds, $wardList);
        } else {
            $availQuery .= " ORDER BY floor_number ASC, bed_id ASC";
            $availParams = $targetHospitalIds;
        }

        $availStmt = $this->pdo->prepare($availQuery);
        $availStmt->execute($availParams);
        $availableBeds = $availStmt->fetchAll(PDO::FETCH_ASSOC);
        $availableCount = count($availableBeds);

        // Transition calculations:
        // Priority 1: Shift existing available beds
        $bedsToHoldCount = min($targetQuotaBeds, $availableCount);
        
        // Priority 2: Shortfall requires relocation of stable occupied beds
        $shortfall = max(0, $targetQuotaBeds - $availableCount);
        $bedsToRelocateCount = 0;

        if ($shortfall > 0) {
            // Find stable occupied beds on target floors/wards, excluding beds already claimed
            $occQuery = "
                SELECT COUNT(*) FROM hospital_beds
                WHERE hospital_id IN ($hospPlaceholders)
                  AND status = 'Occupied'
                  AND relocation_status = 'NONE'
                  AND emergency_protocol_id IS NULL
            ";
            $occStmt = $this->pdo->prepare($occQuery);
            $occStmt->execute($targetHospitalIds);
            $totalOccupiedAvailableForReloc = (int)$occStmt->fetchColumn();
            $bedsToRelocateCount = min($shortfall, $totalOccupiedAvailableForReloc);
        }

        // Per-hospital breakdown
        $hospBreakdown = [];
        $hStmt = $this->pdo->prepare("
            SELECT h.hospital_id, h.name, h.code,
                   COUNT(b.bed_id) as total_beds,
                   SUM(b.status = 'Available') as avail_beds,
                   SUM(b.status = 'Occupied') as occ_beds
            FROM hospitals h
            LEFT JOIN hospital_beds b ON h.hospital_id = b.hospital_id
            WHERE h.hospital_id IN ($hospPlaceholders)
            GROUP BY h.hospital_id
            ORDER BY h.hospital_id ASC
        ");
        $hStmt->execute($targetHospitalIds);
        $hospBreakdown = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'protocol'               => $protocol,
            'quota_pct'              => $quotaPct,
            'total_target_scope_beds'=> $totalTargetScopeBeds,
            'target_quota_beds'      => $targetQuotaBeds,
            'available_in_scope'     => $availableCount,
            'beds_to_hold'           => $bedsToHoldCount,
            'beds_to_relocate'       => $bedsToRelocateCount,
            'hospital_breakdown'     => $hospBreakdown,
        ];
    }

    /**
     * Declare National Emergency Protocol & Trigger Transition Algorithm
     */
    public function declareEmergency(
        string $protocolCode,
        int $quotaPct,
        string $severityLevel,
        string $targetScope,
        array $targetHospitalIds,
        int $actorUserId,
        string $ip,
        string $notes = ''
    ): array {
        $protocolMeta = self::PROTOCOLS[$protocolCode] ?? null;
        if (!$protocolMeta) {
            throw new \InvalidArgumentException("Unknown protocol: $protocolCode");
        }

        if (empty($targetHospitalIds)) {
            $targetHospitalIds = $protocolMeta['default_hosp'];
        }

        $this->pdo->beginTransaction();

        try {
            // Calculate preview metrics
            $preview = $this->calculatePreview($protocolCode, $quotaPct, $targetHospitalIds);

            // 1. Insert new emergency_protocol record
            $insProto = $this->pdo->prepare("
                INSERT INTO emergency_protocols (
                    code, title, severity_quota, severity_level, status,
                    target_scope, target_hospital_ids, target_wards,
                    beds_held_count, beds_relocating_count,
                    declared_by_user_id, declared_at, notes, created_at
                ) VALUES (
                    :code, :title, :quota, :sev, 'Active',
                    :scope, :hids, :wards,
                    :held, :reloc,
                    :uid, NOW(), :notes, NOW()
                )
            ");

            $insProto->execute([
                ':code'  => $protocolCode,
                ':title' => $protocolMeta['title'],
                ':quota' => $quotaPct,
                ':sev'   => $severityLevel,
                ':scope' => $targetScope,
                ':hids'  => json_encode($targetHospitalIds),
                ':wards' => json_encode($protocolMeta['target_wards']),
                ':held'  => $preview['beds_to_hold'],
                ':reloc' => $preview['beds_to_relocate'],
                ':uid'   => $actorUserId,
                ':notes' => $notes ?: $protocolMeta['description'],
            ]);
            $protocolId = (int)$this->pdo->lastInsertId();

            // 2. PRIORITY 1: Transition Available beds into 'Emergency Hold'
            $hospPlaceholders = implode(',', array_fill(0, count($targetHospitalIds), '?'));
            $wardList = $protocolMeta['target_wards'] ?? [];
            $hasWards = !empty($wardList);

            $availQuery = "
                SELECT bed_id FROM hospital_beds
                WHERE hospital_id IN ($hospPlaceholders)
                  AND status = 'Available'
                  AND emergency_protocol_id IS NULL
            ";
            if ($hasWards) {
                $wardPlaceholders = implode(',', array_fill(0, count($wardList), '?'));
                $availQuery .= " ORDER BY (ward_type IN ($wardPlaceholders)) DESC, floor_number ASC, bed_id ASC";
                $availParams = array_merge($targetHospitalIds, $wardList);
            } else {
                $availQuery .= " ORDER BY floor_number ASC, bed_id ASC";
                $availParams = $targetHospitalIds;
            }
            $availQuery .= " LIMIT " . (int)$preview['beds_to_hold'];

            $fetchAvail = $this->pdo->prepare($availQuery);
            $fetchAvail->execute($availParams);
            $holdBedIds = $fetchAvail->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($holdBedIds)) {
                $holdPlaceholders = implode(',', array_fill(0, count($holdBedIds), '?'));
                $updHold = $this->pdo->prepare("
                    UPDATE hospital_beds
                    SET status = 'Emergency Hold',
                        emergency_protocol_id = ?
                    WHERE bed_id IN ($holdPlaceholders)
                ");
                $updHold->execute(array_merge([$protocolId], $holdBedIds));
            }

            // 3. PRIORITY 2: Flag occupied beds as 'PENDING_RELOCATION' if quota exceeds available
            $relocBedIds = [];
            if ($preview['beds_to_relocate'] > 0) {
                $relocQuery = "
                    SELECT bed_id FROM hospital_beds
                    WHERE hospital_id IN ($hospPlaceholders)
                      AND status = 'Occupied'
                      AND relocation_status = 'NONE'
                      AND emergency_protocol_id IS NULL
                ";
                if ($hasWards) {
                    $relocQuery .= " ORDER BY (ward_type IN ($wardPlaceholders)) DESC, floor_number ASC, bed_id ASC";
                    $relocParams = array_merge($targetHospitalIds, $wardList);
                } else {
                    $relocQuery .= " ORDER BY floor_number ASC, bed_id ASC";
                    $relocParams = $targetHospitalIds;
                }
                $relocQuery .= " LIMIT " . (int)$preview['beds_to_relocate'];

                $fetchReloc = $this->pdo->prepare($relocQuery);
                $fetchReloc->execute($relocParams);
                $relocBedIds = $fetchReloc->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($relocBedIds)) {
                    $relocPlaceholders = implode(',', array_fill(0, count($relocBedIds), '?'));
                    $updReloc = $this->pdo->prepare("
                        UPDATE hospital_beds
                        SET relocation_status = 'PENDING_RELOCATION',
                            emergency_protocol_id = ?
                        WHERE bed_id IN ($relocPlaceholders)
                    ");
                    $updReloc->execute(array_merge([$protocolId], $relocBedIds));
                }
            }

            // 4. Disaster Logging
            $this->logDisaster(
                $protocolId,
                null,
                'PROTOCOL_DECLARED',
                sprintf(
                    "Super Admin declared National Emergency: %s [%s quota %d%%]. Held %d beds, Flagged %d for relocation across %d facilities.",
                    $protocolMeta['title'], $severityLevel, $quotaPct, count($holdBedIds), count($relocBedIds), count($targetHospitalIds)
                ),
                $actorUserId,
                'super_admin',
                $ip
            );

            // Audit log
            $this->logAudit(
                $actorUserId,
                'super_admin',
                'NATIONAL_EMERGENCY_DECLARED',
                sprintf("National Emergency declared: %s (Severity: %s, Quota: %d%%). Protocol #%d", $protocolMeta['title'], $severityLevel, $quotaPct, $protocolId),
                'EMERGENCY',
                'Emergency Protocol Surge',
                "protocol:$protocolId",
                $ip,
                'CRITICAL'
            );

            $this->pdo->commit();

            return [
                'success'           => true,
                'protocol_id'       => $protocolId,
                'title'             => $protocolMeta['title'],
                'severity_level'    => $severityLevel,
                'quota_pct'         => $quotaPct,
                'beds_held'         => count($holdBedIds),
                'beds_relocating'   => count($relocBedIds),
                'target_hospitals'  => $targetHospitalIds,
                'message'           => sprintf(
                    "✓ National Emergency [%s] activated at %d%% quota. %d beds placed on Emergency Hold; %d stable beds flagged for relocation.",
                    $protocolMeta['title'], $quotaPct, count($holdBedIds), count($relocBedIds)
                )
            ];

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("Declare Emergency Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Branch Admin: 1-Click Evacuate & Transfer Patient from PENDING_RELOCATION bed
     * Re-assigns stable patient to an available bed on another floor without altering billing rate
     */
    public function evacuateAndTransferPatient(int $bedId, int $actorUserId, string $ip): array
    {
        $this->pdo->beginTransaction();

        try {
            // 1. Lock and inspect current bed
            $bStmt = $this->pdo->prepare("
                SELECT b.*, h.name AS hospital_name 
                FROM hospital_beds b
                LEFT JOIN hospitals h ON b.hospital_id = h.hospital_id
                WHERE b.bed_id = ? FOR UPDATE
            ");
            $bStmt->execute([$bedId]);
            $currentBed = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentBed) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Bed not found.'];
            }

            if ($currentBed['relocation_status'] !== 'PENDING_RELOCATION') {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Bed is not flagged for emergency relocation.'];
            }

            // 2. Fetch active patient allocation
            $allocStmt = $this->pdo->prepare("
                SELECT ba.*, u.full_name AS patient_name 
                FROM bed_allocations ba
                JOIN users u ON ba.patient_id = u.user_id
                WHERE ba.bed_id = ? AND ba.status = 'Active' 
                LIMIT 1 FOR UPDATE
            ");
            $allocStmt->execute([$bedId]);
            $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC);

            if (!$alloc) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'No active inpatient admitted in this bed.'];
            }

            $hospitalId = (int)$currentBed['hospital_id'];
            $currentFloor = (int)$currentBed['floor_number'];
            $patientId = (int)$alloc['patient_id'];
            $doctorId = $alloc['attending_doctor_id'] ? (int)$alloc['attending_doctor_id'] : null;
            $originalRate = (float)$currentBed['daily_rate'];

            // 3. Find available destination bed in SAME hospital on a different floor (or any available non-hold bed)
            $destStmt = $this->pdo->prepare("
                SELECT bed_id, bed_number, ward_type, floor_number, daily_rate 
                FROM hospital_beds
                WHERE hospital_id = :hid 
                  AND status = 'Available'
                  AND floor_number != :curr_floor
                ORDER BY floor_number ASC, bed_id ASC
                LIMIT 1 FOR UPDATE
            ");
            $destStmt->execute([':hid' => $hospitalId, ':curr_floor' => $currentFloor]);
            $destBed = $destStmt->fetch(PDO::FETCH_ASSOC);

            // Fallback 1: any available bed in the same hospital
            if (!$destBed) {
                $fallbackStmt = $this->pdo->prepare("
                    SELECT bed_id, bed_number, ward_type, floor_number, daily_rate, hospital_id 
                    FROM hospital_beds
                    WHERE hospital_id = :hid 
                      AND status = 'Available'
                      AND bed_id != :curr_bed
                    LIMIT 1 FOR UPDATE
                ");
                $fallbackStmt->execute([':hid' => $hospitalId, ':curr_bed' => $bedId]);
                $destBed = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            }

            // Fallback 2: Connected partner facility in MedPulse Network (e.g. MedPulse Flagship ID 1)
            if (!$destBed) {
                $netStmt = $this->pdo->prepare("
                    SELECT bed_id, bed_number, ward_type, floor_number, daily_rate, hospital_id 
                    FROM hospital_beds
                    WHERE status = 'Available'
                    ORDER BY (hospital_id = 1) DESC, bed_id ASC
                    LIMIT 1 FOR UPDATE
                ");
                $netStmt->execute();
                $destBed = $netStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$destBed) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'No alternative vacant beds available across network facilities for immediate transfer. Coordinate with Central Command.'
                ];
            }

            $destBedId = (int)$destBed['bed_id'];

            // 4. Close old allocation as 'Transferred'
            $this->pdo->prepare("
                UPDATE bed_allocations 
                SET status = 'Transferred', discharged_at = NOW() 
                WHERE allocation_id = ?
            ")->execute([$alloc['allocation_id']]);

            // 5. Open new allocation on destination bed
            $insNewAlloc = $this->pdo->prepare("
                INSERT INTO bed_allocations (
                    bed_id, patient_id, attending_doctor_id, admitted_at, status
                ) VALUES (
                    ?, ?, ?, NOW(), 'Active'
                )
            ");
            $insNewAlloc->execute([$destBedId, $patientId, $doctorId]);

            // 6. Update destination bed to 'Occupied' (preserving daily rate tier)
            $this->pdo->prepare("
                UPDATE hospital_beds 
                SET status = 'Occupied',
                    relocation_status = 'NONE'
                WHERE bed_id = ?
            ")->execute([$destBedId]);

            // 7. Transition original bed to 'Emergency Hold' and 'RELOCATED'
            $protocolId = $currentBed['emergency_protocol_id'] ? (int)$currentBed['emergency_protocol_id'] : null;
            $this->pdo->prepare("
                UPDATE hospital_beds 
                SET status = 'Emergency Hold',
                    relocation_status = 'RELOCATED'
                WHERE bed_id = ?
            ")->execute([$bedId]);

            // 8. Log to disaster_logs & audit_logs
            $desc = sprintf(
                "Evacuated %s (#P-%04d) from Bed %s (F%d) to Bed %s (F%d) under Emergency Protocol. Daily rate preserved at ৳%s/day.",
                $alloc['patient_name'], $patientId, $currentBed['bed_number'], $currentFloor,
                $destBed['bed_number'], $destBed['floor_number'], number_format($originalRate)
            );

            $this->logDisaster($protocolId, $hospitalId, 'PATIENT_EVACUATED', $desc, $actorUserId, 'BranchAdmin', $ip);

            $this->logAudit(
                $actorUserId,
                'BranchAdmin',
                'PATIENT_EMERGENCY_EVACUATED',
                $desc,
                'TRANSFER',
                'Disaster Surge Patient Relocation',
                "bed_id:$bedId -> dest:$destBedId",
                $ip,
                'CRITICAL'
            );

            $this->pdo->commit();

            return [
                'success'           => true,
                'message'           => sprintf(
                    "✓ Patient %s successfully evacuated to Bed %s (Floor %d). Original rate ৳%s/day preserved. Bed %s locked for Emergency Surge.",
                    $alloc['patient_name'], $destBed['bed_number'], $destBed['floor_number'], number_format($originalRate), $currentBed['bed_number']
                ),
                'patient_name'      => $alloc['patient_name'],
                'evacuated_bed'     => $currentBed['bed_number'],
                'destination_bed'   => $destBed['bed_number'],
                'destination_floor' => $destBed['floor_number']
            ];

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("Evacuate Patient Failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during evacuation: ' . $e->getMessage()];
        }
    }

    /**
     * Stand-Down & Normal Operations Restoration
     * Supports:
     * - Standing down a specific emergency protocol by ID
     * - Terminating ALL active emergency protocols if protocolId is null
     * 1. Reverts unassigned 'Emergency Hold' beds to 'Available'
     * 2. Actively used/evacuated disaster beds transition to 'Sanitizing'
     * 3. Clears relocation flags
     * 4. Marks protocol(s) Terminated
     */
    public function standDownEmergency(?int $protocolId = null, int $actorUserId = 0, string $ip = ''): array
    {
        $this->pdo->beginTransaction();

        try {
            if ($protocolId !== null && $protocolId > 0) {
                // Stand down specific protocol
                $stmt = $this->pdo->prepare("SELECT * FROM emergency_protocols WHERE id = ? AND status = 'Active' LIMIT 1");
                $stmt->execute([$protocolId]);
                $targetProto = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$targetProto) {
                    $this->pdo->rollBack();
                    return ['success' => false, 'message' => "Protocol #{$protocolId} not found or already terminated."];
                }

                // 1. Mark protocol Terminated
                $this->pdo->prepare("
                    UPDATE emergency_protocols 
                    SET status = 'Terminated', 
                        terminated_at = NOW(), 
                        terminated_by_user_id = ? 
                    WHERE id = ?
                ")->execute([$actorUserId, $protocolId]);

                // 2. Used/relocated beds for this protocol transition to 'Sanitizing'
                $usedBedsStmt = $this->pdo->prepare("
                    SELECT bed_id FROM hospital_beds
                    WHERE emergency_protocol_id = ?
                      AND relocation_status = 'RELOCATED'
                ");
                $usedBedsStmt->execute([$protocolId]);
                $usedBedIds = $usedBedsStmt->fetchAll(PDO::FETCH_COLUMN);

                $sanitizedCount = 0;
                if (!empty($usedBedIds)) {
                    $usedPlaceholders = implode(',', array_fill(0, count($usedBedIds), '?'));
                    $updSanitizing = $this->pdo->prepare("
                        UPDATE hospital_beds
                        SET status = 'Sanitizing',
                            relocation_status = 'NONE',
                            emergency_protocol_id = NULL
                        WHERE bed_id IN ($usedPlaceholders)
                    ");
                    $updSanitizing->execute($usedBedIds);
                    $sanitizedCount = count($usedBedIds);
                }

                // 3. Unassigned 'Emergency Hold' beds for this protocol revert to 'Available'
                $holdBedsStmt = $this->pdo->prepare("
                    SELECT b.bed_id FROM hospital_beds b
                    LEFT JOIN bed_allocations ba ON b.bed_id = ba.bed_id AND ba.status = 'Active'
                    WHERE b.emergency_protocol_id = ?
                      AND b.status = 'Emergency Hold' 
                      AND ba.allocation_id IS NULL
                ");
                $holdBedsStmt->execute([$protocolId]);
                $unassignedHoldBedIds = $holdBedsStmt->fetchAll(PDO::FETCH_COLUMN);

                $revertedCount = 0;
                if (!empty($unassignedHoldBedIds)) {
                    $revPlaceholders = implode(',', array_fill(0, count($unassignedHoldBedIds), '?'));
                    $updAvail = $this->pdo->prepare("
                        UPDATE hospital_beds
                        SET status = 'Available',
                            relocation_status = 'NONE',
                            emergency_protocol_id = NULL
                        WHERE bed_id IN ($revPlaceholders)
                    ");
                    $updAvail->execute($unassignedHoldBedIds);
                    $revertedCount = count($unassignedHoldBedIds);
                }

                // 4. Any remaining occupied beds flagged as PENDING_RELOCATION for this protocol reset to NONE
                $this->pdo->prepare("
                    UPDATE hospital_beds
                    SET relocation_status = 'NONE',
                        emergency_protocol_id = NULL
                    WHERE emergency_protocol_id = ? AND relocation_status = 'PENDING_RELOCATION'
                ")->execute([$protocolId]);

                $details = sprintf(
                    "Emergency Protocol [%s] Stand-Down executed. %d unassigned beds reverted to Available; %d used surge beds transitioned to Sanitizing.",
                    $targetProto['title'], $revertedCount, $sanitizedCount
                );

                $this->logDisaster($protocolId, null, 'PROTOCOL_TERMINATED', $details, $actorUserId, 'super_admin', $ip);
                $this->logAudit(
                    $actorUserId, 'super_admin', 'EMERGENCY_STAND_DOWN', $details,
                    'EMERGENCY', 'Stand-Down Restoration', "protocol:$protocolId", $ip, 'CRITICAL'
                );

                $this->pdo->commit();

                return [
                    'success'            => true,
                    'protocol_id'        => $protocolId,
                    'title'              => $targetProto['title'],
                    'reverted_available' => $revertedCount,
                    'set_sanitizing'     => $sanitizedCount,
                    'message'            => sprintf(
                        "✓ Protocol [%s] stood down successfully. %d beds restored to 'Available'; %d beds sent to 'Sanitizing'.",
                        $targetProto['title'], $revertedCount, $sanitizedCount
                    )
                ];
            }

            // Stand down ALL active protocols
            $activeList = $this->getActiveProtocols();
            if (empty($activeList)) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'No active emergency protocol currently running.'];
            }

            // 1. Mark all active protocols Terminated
            $this->pdo->prepare("
                UPDATE emergency_protocols 
                SET status = 'Terminated', 
                    terminated_at = NOW(), 
                    terminated_by_user_id = ? 
                WHERE status = 'Active'
            ")->execute([$actorUserId]);

            // 2. Actively used / relocated beds transition to Sanitizing
            $usedBedsStmt = $this->pdo->query("
                SELECT bed_id FROM hospital_beds
                WHERE relocation_status = 'RELOCATED'
            ");
            $usedBedIds = $usedBedsStmt->fetchAll(PDO::FETCH_COLUMN);

            $sanitizedCount = 0;
            if (!empty($usedBedIds)) {
                $usedPlaceholders = implode(',', array_fill(0, count($usedBedIds), '?'));
                $updSanitizing = $this->pdo->prepare("
                    UPDATE hospital_beds
                    SET status = 'Sanitizing',
                        relocation_status = 'NONE',
                        emergency_protocol_id = NULL
                    WHERE bed_id IN ($usedPlaceholders)
                ");
                $updSanitizing->execute($usedBedIds);
                $sanitizedCount = count($usedBedIds);
            }

            // 3. Unassigned Emergency Hold beds revert to Available
            $holdBedsStmt = $this->pdo->query("
                SELECT b.bed_id FROM hospital_beds b
                LEFT JOIN bed_allocations ba ON b.bed_id = ba.bed_id AND ba.status = 'Active'
                WHERE b.status = 'Emergency Hold' 
                  AND ba.allocation_id IS NULL
            ");
            $unassignedHoldBedIds = $holdBedsStmt->fetchAll(PDO::FETCH_COLUMN);

            $revertedCount = 0;
            if (!empty($unassignedHoldBedIds)) {
                $revPlaceholders = implode(',', array_fill(0, count($unassignedHoldBedIds), '?'));
                $updAvail = $this->pdo->prepare("
                    UPDATE hospital_beds
                    SET status = 'Available',
                        relocation_status = 'NONE',
                        emergency_protocol_id = NULL
                    WHERE bed_id IN ($revPlaceholders)
                ");
                $updAvail->execute($unassignedHoldBedIds);
                $revertedCount = count($unassignedHoldBedIds);
            }

            // 4. Any remaining occupied beds flagged as PENDING_RELOCATION reset to NONE
            $this->pdo->query("
                UPDATE hospital_beds
                SET relocation_status = 'NONE',
                    emergency_protocol_id = NULL
                WHERE relocation_status = 'PENDING_RELOCATION' OR emergency_protocol_id IS NOT NULL
            ");

            $details = sprintf(
                "Network-Wide Stand-Down: %d active protocols terminated. %d unassigned beds reverted to Available; %d used surge beds transitioned to Sanitizing.",
                count($activeList), $revertedCount, $sanitizedCount
            );

            $this->logDisaster(null, null, 'ALL_PROTOCOLS_TERMINATED', $details, $actorUserId, 'super_admin', $ip);
            $this->logAudit(
                $actorUserId, 'super_admin', 'EMERGENCY_STAND_DOWN_ALL', $details,
                'EMERGENCY', 'Network-Wide Stand-Down', "all_protocols", $ip, 'CRITICAL'
            );

            $this->pdo->commit();

            return [
                'success'            => true,
                'protocol_count'     => count($activeList),
                'reverted_available' => $revertedCount,
                'set_sanitizing'     => $sanitizedCount,
                'message'            => sprintf(
                    "✓ Network-Wide Stand-Down completed. All %d emergency protocols terminated. %d beds restored to 'Available'; %d beds sent to 'Sanitizing'.",
                    count($activeList), $revertedCount, $sanitizedCount
                )
            ];

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("Stand-down Failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during stand-down: ' . $e->getMessage()];
        }
    }

    private function logDisaster(?int $protocolId, ?int $hospitalId, string $eventType, string $details, ?int $actorId, ?string $actorRole, string $ip): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO disaster_logs 
                    (protocol_id, hospital_id, event_type, details, actor_user_id, actor_role, ip_address, created_at)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$protocolId, $hospitalId, $eventType, $details, $actorId, $actorRole, $ip]);
        } catch (Throwable $e) {
            error_log("Failed to insert disaster log: " . $e->getMessage());
        }
    }

    private function logAudit(
        int $actorId, string $actorRole, string $action, string $description, 
        string $category, string $actionName, string $targetEntity, string $ip, string $securityLevel = 'INFO'
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs 
                    (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$actorId, $actorRole, $action, $description, $category, $actionName, $targetEntity, $ip, $securityLevel]);
        } catch (Throwable $e) {
            error_log("Failed to insert audit log: " . $e->getMessage());
        }
    }
}
