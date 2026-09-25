<?php
/**
 * MedPulse Enterprise Authentication — Registration Controller Endpoint
 * Handles incoming patient & clinical personnel registration requests.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../backend/register_action.php';
    exit;
}

// GET requests redirect to registration view on login.php
header('Location: ../login.php?tab=register');
exit;
