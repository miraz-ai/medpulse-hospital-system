<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

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
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method. Send POST data."]);
    exit;
}

$raw_identifier = trim($_POST["identifier"] ?? $_POST["email"] ?? "");
$password       = $_POST["password"] ?? "";
$selected_tab   = trim($_POST["selected_tab"] ?? $_POST["login_role"] ?? "Patient");

// Normalize legacy Staff value to Doctor/Staff
if ($selected_tab === "Staff") {
    $selected_tab = "Doctor/Staff";
}

if ($raw_identifier === "" || $password === "") {
    echo json_encode(["status" => "error", "message" => "Email or phone and password are required."]);
    exit;
}

// Bangladeshi phone sanitization & identifier normalization
$clean_phone = preg_replace("/[\s\-\(\)\+]/", "", $raw_identifier);
if (str_starts_with($clean_phone, "8801")) {
    $clean_phone = substr($clean_phone, 2);
}
$identifier = preg_match("/^01[3-9]\d{8}$/", $clean_phone) ? $clean_phone : strtolower($raw_identifier);

try {
    // 1. Identifier Lookup (Do not restrict by role in WHERE clause)
    // Note: Distinct parameter names are used because ATTR_EMULATE_PREPARES is false
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, gender, password_hash, role, status FROM users WHERE email = :ident_email OR phone = :ident_phone LIMIT 1");
    $stmt->execute([
        ":ident_email" => $identifier,
        ":ident_phone" => $identifier
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Validation Sequence:
    // a. Account existence check
    if (!$user) {
        echo json_encode(["status" => "error", "message" => "No account found with this email or phone."]);
        exit;
    }

    // b. Password verification
    if (!password_verify($password, $user["password_hash"])) {
        echo json_encode(["status" => "error", "message" => "Invalid password. Please try again."]);
        exit;
    }

    // c. Immediate Status Gatekeeper (Checked BEFORE role or session)
    if (strcasecmp($user["status"], "pending") === 0) {
        echo json_encode([
            "status"   => "error",
            "message"  => "Your account is awaiting administrative approval. Our hospital authority will review your medical credentials before granting portal access.",
            "redirect" => "login.php?error=pending_approval"
        ]);
        exit;
    }

    if (strcasecmp($user["status"], "suspended") === 0) {
        echo json_encode([
            "status"   => "error",
            "message"  => "Your account has been suspended by hospital administration. Please contact administration.",
            "redirect" => "login.php?error=account_suspended"
        ]);
        exit;
    }

    if (strcasecmp($user["status"], "rejected") === 0) {
        echo json_encode([
            "status"   => "error",
            "message"  => "Your account access request has been declined by the administration. Please contact hospital HR.",
            "redirect" => "login.php?error=account_declined"
        ]);
        exit;
    }

    // d. Role Matching Check (After valid password and active status check)
    // Patient tab check
    if ($selected_tab === "Patient" && $user["role"] !== "Patient") {
        echo json_encode([
            "status"  => "error",
            "message" => "Access restricted: This account is registered as a " . $user["role"] . ". Please switch to the " . ($user["role"] === "Admin" ? "Admin" : "Doctor/Staff") . " tab to log in."
        ]);
        exit;
    }

    // Doctor/Staff tab check
    if ($selected_tab === "Doctor/Staff" && !in_array($user["role"], ["Doctor", "Staff"], true)) {
        echo json_encode([
            "status"  => "error",
            "message" => ($user["role"] === "Admin")
                ? "Access restricted: This account is registered as an Admin. Please switch to the Admin tab to log in."
                : "Access restricted: This account is registered as a Patient. Please switch to the Patient tab to log in."
        ]);
        exit;
    }

    // Admin tab check
    if ($selected_tab === "Admin" && $user["role"] !== "Admin") {
        echo json_encode([
            "status"  => "error",
            "message" => "Access restricted: This account is registered as a " . $user["role"] . ". Please switch to the appropriate tab."
        ]);
        exit;
    }

    if ($user["status"] === "active") {
        session_regenerate_id(true);
        $_SESSION["user_id"]       = (int)$user["user_id"];
        $_SESSION["full_name"]     = $user["full_name"];
        $_SESSION["email"]         = $user["email"];
        $_SESSION["phone"]         = $user["phone"] ?? "";
        $_SESSION["gender"]        = $user["gender"] ?? "";
        $_SESSION["role"]          = $user["role"];
        $_SESSION["status"]        = $user["status"];
        $_SESSION["last_activity"] = time();

        // Strict Role-Based Redirection Flow
        if ($user["role"] === "Patient") {
            $redirectUrl = "patient_dashboard.php";
        } elseif ($user["role"] === "Admin") {
            $redirectUrl = "admin_dashboard.php";
        } elseif (in_array($user["role"], ["Doctor", "Staff"], true)) {
            $redirectUrl = "dashboard.php";
        } else {
            $redirectUrl = "patient_dashboard.php";
        }

        echo json_encode([
            "status"   => "success",
            "message"  => "Access granted. Synchronizing clinical telemetry...",
            "redirect" => $redirectUrl
        ]);
        exit;
    }

    // Fallback for unexpected status
    echo json_encode([
        "status"  => "error",
        "message" => "Account is not active. Please contact administration."
    ]);
    exit;

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    exit;
}
