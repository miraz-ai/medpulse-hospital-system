<?php
/**
 * MedPulse Enterprise HMS - Database Migration Runner for Task 1
 * Script: database/run_migration_12.php
 * Executes 12_doctor_payout_and_billing_linkage.sql safely and idempotently.
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'success' => false,
    'timestamp' => date('Y-m-d H:i:s'),
    'steps' => [],
    'verification' => []
];

try {
    // Step 1: Inspect before state
    $colsInvoiceItemsBefore = $pdo->query("SHOW COLUMNS FROM invoice_items")->fetchAll(PDO::FETCH_COLUMN);
    $colsInvoicesBefore     = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
    $response['steps'][] = [
        'step' => 'inspect_before',
        'invoice_items_columns' => $colsInvoiceItemsBefore,
        'invoices_columns' => $colsInvoicesBefore
    ];

    // Step 2: Read and execute the migration SQL
    $sqlFile = __DIR__ . '/12_doctor_payout_and_billing_linkage.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException("Migration SQL file not found: " . $sqlFile);
    }

    $sqlContent = file_get_contents($sqlFile);
    
    // Execute statements
    $pdo->exec($sqlContent);
    $response['steps'][] = [
        'step' => 'execute_migration',
        'file' => '12_doctor_payout_and_billing_linkage.sql',
        'status' => 'executed'
    ];

    // Step 3: Inspect after state
    $colsInvoiceItemsAfter = $pdo->query("SHOW COLUMNS FROM invoice_items")->fetchAll(PDO::FETCH_COLUMN);
    $colsInvoicesAfter     = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);

    // Verify invoice_items columns
    $requiredInvoiceItemCols = ['doctor_id', 'doctor_payout_status', 'doctor_payout_amount'];
    $missingInvoiceItemCols = array_diff($requiredInvoiceItemCols, $colsInvoiceItemsAfter);

    // Verify invoices columns
    $requiredInvoiceCols = ['admission_id', 'due_amount'];
    $missingInvoiceCols = array_diff($requiredInvoiceCols, $colsInvoicesAfter);

    // Step 4: Verify indexes
    $indexesInvoiceItems = $pdo->query("SHOW INDEX FROM invoice_items")->fetchAll();
    $indexesInvoices     = $pdo->query("SHOW INDEX FROM invoices")->fetchAll();

    $invoiceItemIndexNames = array_unique(array_column($indexesInvoiceItems, 'Key_name'));
    $invoiceIndexNames     = array_unique(array_column($indexesInvoices, 'Key_name'));

    // Step 5: Verify foreign keys
    $fks = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME IN ('invoices', 'invoice_items')
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ")->fetchAll();

    // Step 6: Verify sample records (INV-2026-081, INV-2026-079, etc.)
    $sampleInvoices = $pdo->query("
        SELECT i.invoice_id, i.invoice_number, i.patient_id, i.admission_id, 
               i.net_payable, i.paid_amount, i.due_amount, i.status,
               u.full_name as patient_name
        FROM invoices i
        JOIN users u ON i.patient_id = u.user_id
        WHERE i.invoice_number IN ('INV-2026-081', 'INV-2026-079', 'INV-2026-076', 'INV-2026-068')
        ORDER BY i.invoice_id ASC
    ")->fetchAll();

    $sampleItems = $pdo->query("
        SELECT ii.item_id, ii.invoice_id, ii.item_type, ii.description, 
               ii.total_price, ii.doctor_id, ii.doctor_payout_status, ii.doctor_payout_amount,
               doc.full_name as doctor_name
        FROM invoice_items ii
        LEFT JOIN users doc ON ii.doctor_id = doc.user_id
        ORDER BY ii.item_id ASC
    ")->fetchAll();

    $response['verification'] = [
        'invoice_items_has_all_columns' => empty($missingInvoiceItemCols),
        'missing_invoice_items_columns' => $missingInvoiceItemCols,
        'invoices_has_all_columns'      => empty($missingInvoiceCols),
        'missing_invoices_columns'      => $missingInvoiceCols,
        'invoice_items_indexes'         => $invoiceItemIndexNames,
        'invoices_indexes'              => $invoiceIndexNames,
        'foreign_keys'                  => $fks,
        'sample_invoices'               => $sampleInvoices,
        'sample_items'                  => $sampleItems
    ];

    $response['success'] = empty($missingInvoiceItemCols) && empty($missingInvoiceCols);

} catch (Throwable $e) {
    $response['success'] = false;
    $response['error']   = $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
