<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Global Multi-Tenant Facility Isolation & Session Scoping Engine
 *
 * Enforces strict per-hospital data boundaries.
 * Blocks and logs cross-tenant URL / parameter hijacking with immediate HTTP 403 & session termination.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

class TenantScope
{
    /**
     * Determine if current user has super_admin global privilege
     */
    public static function isSuperAdmin(): bool
    {
        $role = strtolower(trim($_SESSION['role'] ?? ''));
        return ($role === 'super_admin');
    }

    /**
     * Get the active hospital_id bound to the current session.
     */
    public static function getHospitalId(): int
    {
        return (int)($_SESSION['hospital_id'] ?? 1);
    }

    /**
     * Detect and terminate any cross-tenant parameter tampering attempt.
     * Prevents branch admins, doctors, or staff from passing ?hospital_id=X to view or manipulate other branches.
     */
    public static function detectTampering(?PDO $pdo = null): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        // Super admins have global cross-network authority
        if (self::isSuperAdmin()) {
            return;
        }

        $sessionHospitalId = (int)($_SESSION['hospital_id'] ?? 0);
        if ($sessionHospitalId <= 0) {
            return;
        }

        // Check for attempted facility parameter tampering across GET, POST, and REQUEST
        $probedKeys = ['hospital_id', 'facility_id', 'branch_id'];
        $suspectValue = null;
        $suspectSource = null;

        foreach ($probedKeys as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                $suspectValue = (int)$_GET[$k];
                $suspectSource = "GET[{$k}]";
                break;
            }
            if (isset($_POST[$k]) && $_POST[$k] !== '') {
                $suspectValue = (int)$_POST[$k];
                $suspectSource = "POST[{$k}]";
                break;
            }
            if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') {
                $suspectValue = (int)$_REQUEST[$k];
                $suspectSource = "REQUEST[{$k}]";
                break;
            }
        }

        if ($suspectValue !== null && $suspectValue !== $sessionHospitalId) {
            self::handleSecurityBreach($pdo, $sessionHospitalId, $suspectValue, $suspectSource);
        }
    }

    /**
     * Log the breach to audit logs, destroy the session, and emit an HTTP 403 Forbidden.
     */
    private static function handleSecurityBreach(?PDO $pdo, int $sessionHospitalId, int $attemptedHospitalId, string $source): never
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = $_SESSION['role'] ?? 'UNKNOWN';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        $desc = "Cross-tenant isolation breach blocked! User #{$userId} ({$role}, Branch #{$sessionHospitalId}) attempted unauthorized access to Facility #{$attemptedHospitalId} via {$source}. URI: {$requestUri}";
        error_log("[SECURITY ALERT][TenantScope] {$desc}");

        if ($pdo) {
            try {
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs 
                        (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                    VALUES 
                        (:actor_id, :role, 'TENANT_ISOLATION_BREACH', 'Cross-Tenant Hijack Attempt', :desc, 'SECURITY', :target, :ip, 'CRITICAL')
                ");
                $audit->execute([
                    ':actor_id' => $userId,
                    ':role'     => $role,
                    ':desc'     => $desc,
                    ':target'   => "Hospital #{$attemptedHospitalId}",
                    ':ip'       => $clientIp
                ]);
            } catch (Throwable $e) {
                // Non-blocking fallback
            }
        }

        // Destroy session completely with past cookie expiry
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // Emit HTTP 403 Forbidden
        http_response_code(403);
        if (!headers_sent()) {
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Pragma: no-cache");
            header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
        }

        $isJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
               || (!empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
               || (!empty($_POST['ajax']) || !empty($_GET['ajax']));

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'code'    => 403,
                'message' => '403 Forbidden: Cross-tenant facility tampering detected. Security incident logged and session terminated.',
                'action'  => 'session_terminated'
            ]);
        } else {
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>403 Forbidden — MedPulse Security Guard</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                        background: #0f172a;
                        color: #f8fafc;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        min-height: 100vh;
                        margin: 0;
                        padding: 20px;
                    }
                    .guard-card {
                        background: rgba(30, 41, 59, 0.95);
                        border: 1px solid rgba(239, 68, 68, 0.35);
                        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 30px rgba(239, 68, 68, 0.15);
                        border-radius: 16px;
                        max-width: 520px;
                        padding: 36px 32px;
                        text-align: center;
                    }
                    .guard-icon {
                        width: 64px;
                        height: 64px;
                        margin: 0 auto 20px;
                        color: #ef4444;
                        background: rgba(239, 68, 68, 0.12);
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    h1 {
                        font-size: 1.5rem;
                        font-weight: 700;
                        color: #f87171;
                        margin: 0 0 12px;
                    }
                    p {
                        color: #94a3b8;
                        font-size: 0.95rem;
                        line-height: 1.6;
                        margin: 0 0 24px;
                    }
                    .audit-notice {
                        background: rgba(15, 23, 42, 0.8);
                        border: 1px dashed rgba(239, 68, 68, 0.3);
                        padding: 12px;
                        border-radius: 8px;
                        font-family: monospace;
                        font-size: 0.8rem;
                        color: #fca5a5;
                        margin-bottom: 24px;
                        word-break: break-all;
                    }
                    .btn-login {
                        display: inline-block;
                        background: #0284c7;
                        color: #ffffff;
                        text-decoration: none;
                        font-weight: 600;
                        font-size: 0.95rem;
                        padding: 12px 28px;
                        border-radius: 8px;
                        transition: background 0.2s;
                    }
                    .btn-login:hover {
                        background: #0369a1;
                    }
                </style>
            </head>
            <body>
                <div class="guard-card">
                    <div class="guard-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <h1>403 Forbidden — Access Denied</h1>
                    <p>Cross-tenant facility isolation breach detected. Direct parameter manipulation between hospitals is strictly prohibited by MedPulse Enterprise Security Policy.</p>
                    <div class="audit-notice">
                        INCIDENT_ID: SEC-BR-<?= strtoupper(bin2hex(random_bytes(4))) ?> | IP: <?= htmlspecialchars($clientIp, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <a href="/login.php?breach_reset=1" class="btn-login">Return to Secure Login</a>
                </div>
            </body>
            </html>
            <?php
        }
        exit(1);
    }

    /**
     * Enforce tenant isolation for the current page and bind hospital_id.
     * Returns the validated hospital_id integer.
     */
    public static function enforce(PDO $pdo, array $allowedRoles = []): int
    {
        // 1. Session check
        if (empty($_SESSION['user_id'])) {
            if (!headers_sent()) {
                header("Location: /login.php");
            }
            exit();
        }

        // 2. Role check if provided
        if (!empty($allowedRoles)) {
            $currentRole = strtolower(trim($_SESSION['role'] ?? ''));
            $normalizedAllowed = array_map('strtolower', $allowedRoles);
            if (!in_array($currentRole, $normalizedAllowed, true)) {
                http_response_code(403);
                die('403 Forbidden: Insufficient role privileges for this operational area.');
            }
        }

        // 3. Resolve & verify session hospital_id from DB
        $userId = (int)$_SESSION['user_id'];
        $currentHospId = (int)($_SESSION['hospital_id'] ?? 0);

        if ($currentHospId <= 0) {
            $stmt = $pdo->prepare("SELECT hospital_id, role FROM users WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            $resolvedHospId = (int)($u['hospital_id'] ?? 0);

            if ($resolvedHospId <= 0 && ($u['role'] ?? '') === 'Doctor') {
                $docStmt = $pdo->prepare("SELECT hospital_id FROM doctors WHERE user_id = :uid LIMIT 1");
                $docStmt->execute([':uid' => $userId]);
                $resolvedHospId = (int)$docStmt->fetchColumn();
            }

            if ($resolvedHospId <= 0) {
                $resolvedHospId = 1; // Default fallback to MedPulse Central
            }

            $_SESSION['hospital_id'] = $resolvedHospId;
            $currentHospId = $resolvedHospId;
        }

        // 4. Anti-tampering check against incoming request parameters
        self::detectTampering($pdo);

        return $currentHospId;
    }

    /**
     * Validates whether a bed belongs strictly to the authenticated tenant.
     */
    public static function validateBedOwnership(PDO $pdo, int $bedId): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        $stmt = $pdo->prepare("
            SELECT hospital_id FROM beds WHERE bed_id = :bid OR id = :bid2 
            UNION 
            SELECT hospital_id FROM hospital_beds WHERE bed_id = :bid3
            LIMIT 1
        ");
        $stmt->execute([':bid' => $bedId, ':bid2' => $bedId, ':bid3' => $bedId]);
        $ownerHospId = $stmt->fetchColumn();

        return ($ownerHospId !== false && (int)$ownerHospId === self::getHospitalId());
    }

    /**
     * Validates whether a bed reservation belongs strictly to the authenticated tenant.
     */
    public static function validateReservationOwnership(PDO $pdo, int $reservationId): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        $stmt = $pdo->prepare("SELECT hospital_id FROM bed_reservations WHERE id = :rid LIMIT 1");
        $stmt->execute([':rid' => $reservationId]);
        $ownerHospId = $stmt->fetchColumn();

        return ($ownerHospId !== false && (int)$ownerHospId === self::getHospitalId());
    }

    /**
     * Validates whether a doctor belongs strictly to the authenticated tenant.
     */
    public static function validateDoctorOwnership(PDO $pdo, int $doctorId): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        $stmt = $pdo->prepare("
            SELECT hospital_id FROM doctors WHERE id = :did OR doctor_id = :did2 OR user_id = :did3 LIMIT 1
        ");
        $stmt->execute([':did' => $doctorId, ':did2' => $doctorId, ':did3' => $doctorId]);
        $ownerHospId = $stmt->fetchColumn();

        return ($ownerHospId !== false && (int)$ownerHospId === self::getHospitalId());
    }

    /**
     * Validates whether an appointment belongs strictly to the authenticated tenant.
     */
    public static function validateAppointmentOwnership(PDO $pdo, int $appointmentId): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        $stmt = $pdo->prepare("
            SELECT hospital_id FROM appointments WHERE id = :aid OR appointment_id = :aid2 LIMIT 1
        ");
        $stmt->execute([':aid' => $appointmentId, ':aid2' => $appointmentId]);
        $ownerHospId = $stmt->fetchColumn();

        return ($ownerHospId !== false && (int)$ownerHospId === self::getHospitalId());
    }
}
