<?php
/**
 * MedPulse Enterprise HMS — Doctors Directory (Canonical Route)
 * Forwards to specialists.php
 */
declare(strict_types=1);

header("Location: specialists.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit();
