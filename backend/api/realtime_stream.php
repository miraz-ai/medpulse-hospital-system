<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Universal Real-Time Server-Sent Events (SSE) Stream
 * 
 * Streams real-time notifications to connected clients (Patient, Doctor, or Admin consoles)
 * filtered by channel (e.g. "patient:18", "doctor:21", "admin:all").
 * Supports reconnection catch-up via Last-Event-ID.
 */

declare(strict_types=1);

// Prevent output buffering
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', '0');
ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable FastCGI buffering

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';

// Auth check
$sessionUserId = $_SESSION['user_id'] ?? null;
$sessionRole   = $_SESSION['role'] ?? null;

// Allow channel param, e.g. "patient:18", "doctor:21", "admin:all"
$requestedChannel = trim((string)($_GET['channel'] ?? ''));

// If channel not passed, deduce from session
if ($requestedChannel === '' && $sessionUserId && $sessionRole) {
    $requestedChannel = strtolower($sessionRole) . ':' . $sessionUserId;
}

// Track last event ID from Last-Event-ID header or query param
$lastId = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last_id'] ?? 0);

// Close session writing so other requests aren't blocked by PHP session lock
session_write_close();

// Send initial connection packet
echo "event: CONNECTED\n";
echo "data: " . json_encode([
    'status'    => 'connected',
    'channel'   => $requestedChannel ?: 'global',
    'timestamp' => date('c'),
    'last_id'   => $lastId
]) . "\n\n";
flush();

$maxLoops = 120; // Run for up to ~120 seconds before standard SSE reconnect
$loopCount = 0;

while ($loopCount < $maxLoops && !connection_aborted()) {
    $loopCount++;

    try {
        // Query new notifications for this channel or user
        $query = "
            SELECT id, recipient_id, recipient_type, title, message, event_type, metadata, created_at
            FROM notifications
            WHERE id > :last_id
        ";
        $params = [':last_id' => $lastId];

        if ($requestedChannel !== '' && $requestedChannel !== 'admin:all' && $sessionRole !== 'Admin') {
            [$type, $id] = explode(':', $requestedChannel . ':0');
            $query .= " AND recipient_id = :rid AND recipient_type = :rtype";
            $params[':rid'] = (int)$id;
            $params[':rtype'] = strtoupper($type);
        }

        $query .= " ORDER BY id ASC LIMIT 20";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as $ev) {
            $lastId = (int)$ev['id'];
            $metadata = json_decode($ev['metadata'], true) ?: [];

            echo "id: {$lastId}\n";
            echo "event: {$ev['event_type']}\n";
            echo "data: " . json_encode([
                'id'             => $lastId,
                'event'          => $ev['event_type'],
                'recipient_id'   => (int)$ev['recipient_id'],
                'recipient_type' => $ev['recipient_type'],
                'title'          => $ev['title'],
                'message'        => $ev['message'],
                'created_at'     => $ev['created_at'],
                'payload'        => $metadata['data'] ?? $metadata
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }

    } catch (Throwable $e) {
        error_log("SSE Stream Error: " . $e->getMessage());
    }

    // Ping heartbeat every ~15 seconds to prevent NAT timeout
    if ($loopCount % 15 === 0) {
        echo ": heartbeat " . time() . "\n\n";
        flush();
    }

    sleep(1);
}

exit;
