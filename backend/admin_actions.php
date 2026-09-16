<?php
/**
 * MedPulse Administrative User Management Actions
 * Handles status updates (Approve, Reject, Suspend, Activate, Delete) with CSRF and RBAC enforcement.
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

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// 1. Method Guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// 2. Strict Admin RBAC Authorization Guard
if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Access denied. Administrative privileges required.'
    ]);
    exit;
}

// 3. CSRF Protection Gatekeeper
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Security token mismatch. Request rejected.'
    ]);
    exit;
}

// 4. Input Validation
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$action = trim($_POST['action'] ?? '');
$allowedActions = ['approve', 'reject', 'suspend', 'activate', 'delete'];

if (!$userId || !in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid parameters. Valid user_id and supported action are required.'
    ]);
    exit;
}

try {
    // 5. Target User Lookup
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, role, status FROM users WHERE user_id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Target personnel account not found.']);
        exit;
    }

    // Protect Admin accounts from any modification
    if ($targetUser['role'] === 'Admin') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Cannot modify root administrative accounts.']);
        exit;
    }

    // 6. Action Execution Pipeline
    if ($action === 'delete') {
        $delStmt = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
        $delStmt->execute([':id' => $userId]);

        echo json_encode([
            'status'    => 'success',
            'message'   => "Account for {$targetUser['full_name']} ({$targetUser['role']}) has been permanently deleted.",
            'user_id'   => $userId,
            'action'    => 'delete',
            'user_name' => $targetUser['full_name'],
            'user_role' => $targetUser['role']
        ]);
        exit;
    }

    $statusMap = [
        'approve'  => 'active',
        'activate' => 'active',
        'reject'   => 'rejected',
        'suspend'  => 'suspended'
    ];
    $newStatus = $statusMap[$action];

    $updateStmt = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
    $updateStmt->execute([
        ':status' => $newStatus,
        ':id'     => $userId
    ]);

    $actionVerbs = [
        'approve'  => 'approved & activated',
        'activate' => 'reactivated',
        'reject'   => 'declined',
        'suspend'  => 'suspended'
    ];
    $verb = $actionVerbs[$action];

    echo json_encode([
        'status'     => 'success',
        'message'    => "{$targetUser['full_name']} ({$targetUser['role']}) has been successfully {$verb}.",
        'user_id'    => $userId,
        'action'     => $action,
        'new_status' => $newStatus,
        'user_name'  => $targetUser['full_name'],
        'user_role'  => $targetUser['role']
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin action PDOException: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database operation error. Please try again.']);
    exit;
}
