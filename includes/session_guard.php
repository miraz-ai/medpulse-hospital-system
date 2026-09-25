<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Global Session & Anti-Caching Guard Middleware
 *
 * Implements:
 * 1. Strict No-Cache HTTP Headers (Kills BFCache / Disk Cache globally)
 * 2. Hardened Session Cookies & Initialization
 * 3. Complete Session Destruction with Past Cookie Expiry
 * 4. Role-Aware Redirection Architecture
 * 5. Client-Side History Guard Snippet (pageshow reload)
 */

// ── 1. Strict No-Cache HTTP Headers (Enforced Before Any Output) ───────────────
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

// ── 2. Hardened Session Initialization ─────────────────────────────────────────
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

// ── 3. Helper: Role-to-Dashboard Destination URL ──────────────────────────────
if (!function_exists('medpulseGetRoleDashboard')) {
    function medpulseGetRoleDashboard(?string $role, string $basePrefix = ''): string {
        $norm = strtolower(trim($role ?? ''));
        return match ($norm) {
            'super_admin'             => $basePrefix . 'super_admin/dashboard.php',
            'hospital_admin', 'admin' => $basePrefix . 'admin/executive_overview.php',
            'doctor'                  => $basePrefix . 'doctor/dashboard.php',
            'staff'                   => $basePrefix . 'staff/dashboard.php',
            'patient'                 => $basePrefix . 'patient/portal.php',
            default                   => $basePrefix . 'patient/portal.php'
        };
    }
}

// ── 4. Helper: Complete Explicit Session & Cookie Destruction ──────────────────
if (!function_exists('medpulseDestroySession')) {
    function medpulseDestroySession(string $redirectTarget = '../login.php?logged_out=1'): never {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent()) {
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Pragma: no-cache");
            header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
            header("Location: " . $redirectTarget);
        } else {
            echo '<script>window.location.replace(' . json_encode($redirectTarget) . ');</script>';
        }
        exit();
    }
}

// ── 5. Helper: Render Client-Side History Guard ───────────────────────────────
if (!function_exists('medpulseRenderHistoryGuard')) {
    function medpulseRenderHistoryGuard(): void {
        echo '<script>
          window.addEventListener("pageshow", function(event) {
            if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
              window.location.reload();
            }
          });
        </script>';
    }
}
