<?php
/**
 * MedPulse Enterprise HMS — Multi-Branch Dedicated Staff Account Provisioner
 *
 * Seeds/ensures each active hospital/branch in the system has a valid
 * reception/admission desk staff account for cross-facility testing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "============================================================\n";
echo " MedPulse Multi-Branch Dedicated Staff Provisioner\n";
echo "============================================================\n\n";

try {
    // 1. Inspect hospitals table
    $hospStmt = $pdo->query("SELECT * FROM hospitals ORDER BY hospital_id ASC");
    $hospitals = $hospStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($hospitals)) {
        echo "[ERROR] No hospitals found in the database.\n";
        exit(1);
    }

    echo "Found " . count($hospitals) . " hospital facilities in database.\n\n";

    $passwordPlain = 'Staff@123';
    $passwordHash  = password_hash($passwordPlain, PASSWORD_BCRYPT, ['cost' => 10]);

    $provisioned = [];

    foreach ($hospitals as $hosp) {
        $hospitalId   = (int)$hosp['hospital_id'];
        $hospitalName = trim($hosp['name']);
        $rawCode      = trim($hosp['code'] ?? '');

        // Determine branch slug
        if (!empty($rawCode)) {
            $branchSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawCode));
        } else {
            // Slugify from name
            $words = explode(' ', strtolower($hospitalName));
            $branchSlug = preg_replace('/[^a-zA-Z0-9]/', '', $words[0] ?? 'branch');
        }

        $fullName    = "Reception Desk - {$hospitalName}";
        $email       = "staff.{$branchSlug}@medpulse.com";
        $phone       = '0189' . str_pad((string)$hospitalId, 7, '0', STR_PAD_LEFT);
        $role        = 'Staff';
        $status      = 'active';
        $department  = 'Frontdesk Registrar';
        $roleTitle   = 'Admission Desk Officer';

        // Check if user already exists with this email
        $chkUser = $pdo->prepare("SELECT user_id, email, phone FROM users WHERE email = ? LIMIT 1");
        $chkUser->execute([$email]);
        $existingUser = $chkUser->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            $userId = (int)$existingUser['user_id'];
            // Update user to ensure active staff status and branch binding
            $updUser = $pdo->prepare("
                UPDATE users 
                SET full_name = ?, 
                    role = ?, 
                    status = ?, 
                    hospital_id = ?, 
                    department = ?, 
                    password_hash = ?
                WHERE user_id = ?
            ");
            $updUser->execute([
                $fullName,
                $role,
                $status,
                $hospitalId,
                $department,
                $passwordHash,
                $userId
            ]);
            $userAction = "Updated existing user (ID: {$userId})";
        } else {
            // Ensure phone uniqueness
            $chkPhone = $pdo->prepare("SELECT user_id FROM users WHERE phone = ? LIMIT 1");
            $chkPhone->execute([$phone]);
            if ($chkPhone->fetch()) {
                $phone = '0188' . str_pad((string)$hospitalId, 7, '0', STR_PAD_LEFT);
            }

            // Insert into users
            $insUser = $pdo->prepare("
                INSERT INTO users (full_name, email, phone, gender, password_hash, role, status, hospital_id, department, created_at)
                VALUES (?, ?, ?, 'Male', ?, ?, ?, ?, ?, NOW())
            ");
            $insUser->execute([
                $fullName,
                $email,
                $phone,
                $passwordHash,
                $role,
                $status,
                $hospitalId,
                $department
            ]);
            $userId = (int)$pdo->lastInsertId();
            $userAction = "Created new user (ID: {$userId})";
        }

        // Link into staff table
        $chkStaff = $pdo->prepare("SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
        $chkStaff->execute([$userId]);
        $existingStaff = $chkStaff->fetch(PDO::FETCH_ASSOC);

        if ($existingStaff) {
            $staffId = (int)$existingStaff['staff_id'];
            $updStaff = $pdo->prepare("
                UPDATE staff 
                SET hospital_id = ?, 
                    department = ?, 
                    role_title = ?, 
                    status = ?
                WHERE staff_id = ?
            ");
            $updStaff->execute([
                $hospitalId,
                $department,
                $roleTitle,
                'active',
                $staffId
            ]);
            $staffAction = "Updated staff record (Staff ID: {$staffId})";
        } else {
            $insStaff = $pdo->prepare("
                INSERT INTO staff (user_id, hospital_id, department, role_title, status, created_at)
                VALUES (?, ?, ?, ?, 'active', NOW())
            ");
            $insStaff->execute([
                $userId,
                $hospitalId,
                $department,
                $roleTitle
            ]);
            $staffId = (int)$pdo->lastInsertId();
            $staffAction = "Created staff record (Staff ID: {$staffId})";
        }

        $provisioned[] = [
            'hospital_id'   => $hospitalId,
            'hospital_name' => $hospitalName,
            'slug'          => $branchSlug,
            'staff_id'      => $staffId,
            'user_id'       => $userId,
            'name'          => $fullName,
            'email'         => $email,
            'phone'         => $phone,
            'role_title'    => $roleTitle,
            'department'    => $department,
            'status'        => 'active',
            'action'        => "{$userAction} | {$staffAction}"
        ];
    }

    // Display summary table
    printf("%-4s | %-6s | %-8s | %-32s | %-32s | %-24s\n", "HID", "STF_ID", "USER_ID", "FACILITY NAME", "EMAIL (LOGIN IDENTIFIER)", "DESIGNATION");
    echo str_repeat("-", 125) . "\n";
    foreach ($provisioned as $p) {
        printf(
            "%-4d | %-6d | %-8d | %-32.32s | %-32.32s | %-24.24s\n",
            $p['hospital_id'],
            $p['staff_id'],
            $p['user_id'],
            $p['hospital_name'],
            $p['email'],
            $p['role_title']
        );
    }
    echo str_repeat("-", 125) . "\n";
    echo "\nAll dedicated branch staff accounts are provisioned and active.\n";
    echo "Default Password for all seeded staff: {$passwordPlain}\n";

} catch (Throwable $e) {
    echo "[EXCEPTION] " . $e->getMessage() . "\n";
    exit(1);
}
