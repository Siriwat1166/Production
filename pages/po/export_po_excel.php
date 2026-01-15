    <?php
// production/pages/po/export_po_excel.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/POManager.php';

$auth = new Auth();
$auth->requireLogin();

$poManager = new POManager();

// Get filters from URL parameters
$filters = [
    'po_type' => $_GET['po_type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Get export data
$exportData = $poManager->exportPOData($filters);

if (!$exportData['success']) {
    die('Error: ' . $exportData['message']);
}

// Set headers for CSV download
$filename = $exportData['filename'];
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for proper UTF-8 encoding in Excel
fwrite($output, "\xEF\xBB\xBF");

// Write headers
fputcsv($output, $exportData['headers']);

// Write data
foreach ($exportData['data'] as $row) {
    fputcsv($output, $row);
}

// Close output stream
fclose($output);
exit;
?>