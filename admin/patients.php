<?php
/**
 * MedPulse Enterprise - Patients Management Route Alias
 */
if (isset($_GET['tab']) && in_array(strtolower($_GET['tab']), ['inpatient', 'admissions', 'admitted'], true)) {
    header("Location: admissions.php", true, 302);
    exit();
}
if (isset($_GET['view']) && in_array(strtolower($_GET['view']), ['inpatient', 'admissions'], true)) {
    header("Location: admissions.php", true, 302);
    exit();
}
header("Location: manage_patients.php", true, 302);
exit();
