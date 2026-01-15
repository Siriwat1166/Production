<?php
// production/pages/po/cost_analysis.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/POManager.php';

$auth = new Auth();
$auth->requireLogin();

$poManager = new POManager();

// Get Material PO ID from URL parameter
$materialPoId = $_GET['material_po_id'] ?? null;
$freightPoId = $_GET['freight_po_id'] ?? null;

// Debug information
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';
$errorMessage = '';
$materialPO = null;
$freightPO = null;

if (!$materialPoId) {
    $errorMessage = 'No Material PO ID provided in URL parameter.';
    if (!$debugMode) {
        // Only redirect if not in debug mode
        header('Location: po_list.php?error=' . urlencode($errorMessage));
        exit;
    }
} else {
    // Get Material PO details
    $materialPOResult = $poManager->getPODetails($materialPoId);
    if (!$materialPOResult['success']) {
        $errorMessage = 'Material PO not found: ' . ($materialPOResult['message'] ?? 'Unknown error');
        if (!$debugMode) {
            header('Location: po_list.php?error=' . urlencode($errorMessage));
            exit;
        }
    } else {
        $materialPO = $materialPOResult['data'];
    }
}

// Get Freight PO details if exists
if ($materialPO) {
    if ($freightPoId) {
        $freightPOResult = $poManager->getPODetails($freightPoId);
        if ($freightPOResult['success']) {
            $freightPO = $freightPOResult['data'];
        }
    } else {
        // Find linked freight PO
        if ($materialPO['freight_po_id']) {
            $freightPOResult = $poManager->getPODetails($materialPO['freight_po_id']);
            if ($freightPOResult['success']) {
                $freightPO = $freightPOResult['data'];
            }
        }
    }
}

// Calculate totals
$materialTotal = $materialPO ? ($materialPO['total_amount'] ?? 0) : 0;
$freightTotal = 0;
$freightCharges = [];

if ($freightPO && isset($freightPO['items'])) {
    foreach ($freightPO['items'] as $item) {
        $freightTotal += $item['total_price'] ?? 0;
        $freightCharges[] = [
            'type' => $item['item_description'] ?? 'Freight Cost',
            'amount' => $item['total_price'] ?? 0,
            'description' => $item['notes'] ?? ''
        ];
    }
}

$grandTotal = $materialTotal + $freightTotal;
$freightPercentage = $grandTotal > 0 ? ($freightTotal / $grandTotal) * 100 : 0;

// Calculate weight-based allocation for freight costs
$materialItems = $materialPO ? ($materialPO['items'] ?? []) : [];
$totalWeight = 0;

// Calculate total weight
foreach ($materialItems as $item) {
    $totalWeight += $item['stock_quantity'] ?? 0;
}

// Add allocation data to each item
foreach ($materialItems as &$item) {
    $itemWeight = $item['stock_quantity'] ?? 0;
    $weightPercentage = $totalWeight > 0 ? ($itemWeight / $totalWeight) * 100 : 0;
    $allocatedFreight = ($weightPercentage / 100) * $freightTotal;
    $totalCost = ($item['total_price'] ?? 0) + $allocatedFreight;
    $costPerUnit = $itemWeight > 0 ? $totalCost / $itemWeight : 0;
    
    $item['weight_percentage'] = $weightPercentage;
    $item['allocated_freight'] = $allocatedFreight;
    $item['total_cost_with_freight'] = $totalCost;
    $item['cost_per_unit'] = $costPerUnit;
}

// Calculate freight charge percentages
foreach ($freightCharges as &$charge) {
    $charge['percentage'] = $freightTotal > 0 ? ($charge['amount'] / $freightTotal) * 100 : 0;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Analysis<?php echo $materialPO ? ' - ' . htmlspecialchars($materialPO['po_number']) : ' - Debug Mode'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .cost-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0;
        }
        
        .freight-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0;
        }
        
        .cost-table {
            background: white;
            border-radius: 0 0 0.5rem 0.5rem;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .table-header {
            background-color: #495057;
            color: white;
        }
        
        .table-header th {
            border: none;
            padding: 1rem 0.75rem;
            font-weight: 600;
        }
        
        .cost-row td {
            padding: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
        }
        
        .total-row {
            background-color: #f8f9fa;
            font-weight: bold;
            border-top: 2px solid #495057;
        }
        
        .total-row td {
            padding: 1rem 0.75rem;
        }
        
        .weight-percentage {
            font-weight: bold;
            color: #17a2b8;
        }
        
        .cost-percentage {
            font-weight: bold;
            color: #fd7e14;
        }
        
        .amount-success {
            color: #28a745;
            font-weight: 600;
        }
        
        .amount-danger {
            color: #dc3545;
            font-weight: 600;
        }
        
        .progress-custom {
            height: 8px;
            border-radius: 4px;
        }
        
        .breadcrumb-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .breadcrumb-custom .breadcrumb-item a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
        }
        
        .breadcrumb-custom .breadcrumb-item.active {
            color: white;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .icon-large {
            font-size: 1.2rem;
            margin-right: 0.5rem;
        }

        .no-freight-message {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <!-- Breadcrumb -->
        <div class="breadcrumb-custom">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="po_list.php"><i class="fas fa-home"></i> Home</a></li>
                    <li class="breadcrumb-item"><a href="po_list.php">Purchase Orders</a></li>
                    <li class="breadcrumb-item active">Cost Analysis</li>
                </ol>
            </nav>
        </div>

        <!-- Error/Debug Message -->
        <?php if ($errorMessage): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning" role="alert">
                    <h5><i class="fas fa-exclamation-triangle"></i> Debug Information</h5>
                    <p><?php echo htmlspecialchars($errorMessage); ?></p>
                    <hr>
                    <p><strong>URL Parameters:</strong></p>
                    <ul>
                        <li>material_po_id: <?php echo htmlspecialchars($materialPoId ?? 'Not provided'); ?></li>
                        <li>freight_po_id: <?php echo htmlspecialchars($freightPoId ?? 'Not provided'); ?></li>
                        <li>debug: <?php echo isset($_GET['debug']) ? 'Enabled' : 'Disabled'; ?></li>
                    </ul>
                    <p><strong>Try these solutions:</strong></p>
                    <ul>
                        <li>Make sure the URL includes <code>?material_po_id=1</code> (replace 1 with actual PO ID)</li>
                        <li>Check if the Material PO exists in the database</li>
                        <li>Add <code>&debug=1</code> to the URL to see more details</li>
                    </ul>
                    <a href="po_list.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to PO List
                    </a>
                    <?php if (!$debugMode): ?>
                    <a href="<?php echo $_SERVER['REQUEST_URI']; ?>&debug=1" class="btn btn-info">
                        <i class="fas fa-bug"></i> Enable Debug Mode
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($materialPO): ?>
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card summary-card">
                    <div class="card-body text-center">
                        <i class="fas fa-boxes fa-2x mb-2"></i>
                        <h6>Material Cost</h6>
                        <h4><?php echo number_format($materialTotal, 0); ?> THB</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card">
                    <div class="card-body text-center">
                        <i class="fas fa-truck fa-2x mb-2"></i>
                        <h6>Freight Cost</h6>
                        <h4><?php echo number_format($freightTotal, 0); ?> THB</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card">
                    <div class="card-body text-center">
                        <i class="fas fa-calculator fa-2x mb-2"></i>
                        <h6>Total Cost</h6>
                        <h4><?php echo number_format($grandTotal, 0); ?> THB</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card">
                    <div class="card-body text-center">
                        <i class="fas fa-percentage fa-2x mb-2"></i>
                        <h6>Freight %</h6>
                        <h4><?php echo number_format($freightPercentage, 2); ?>%</h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Material Purchase Cost -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="cost-header">
                    <h5 class="mb-0">
                        <i class="fas fa-boxes icon-large"></i>
                        <?php echo htmlspecialchars($materialPO['po_number']); ?> - <?php echo htmlspecialchars($materialPO['supplier_name'] ?? 'Material Purchase'); ?>
                    </h5>
                </div>
                <div class="cost-table">
                    <table class="table table-hover mb-0">
                        <thead class="table-header">
                            <tr>
                                <th>Item</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Unit Price (THB)</th>
                                <th class="text-end">Material Cost (THB)</th>
                                <th class="text-end">Weight %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materialItems as $index => $item): ?>
                            <tr class="cost-row">
                                <td>
                                    <strong><?php echo htmlspecialchars($item['product_code'] ?? $item['SSP_Code'] ?? 'Item ' . ($index + 1)); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($item['product_name'] ?? $item['Name'] ?? ''); ?></small>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($item['stock_quantity'] ?? $item['quantity'] ?? 0, 2); ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($item['stock_unit_name'] ?? $item['unit_name'] ?? ''); ?></small>
                                </td>
                                <td class="text-end amount-success">
                                    <?php echo number_format($item['unit_price'] ?? 0, 2); ?>
                                </td>
                                <td class="text-end amount-success">
                                    <?php echo number_format($item['total_price'] ?? 0, 0); ?>
                                </td>
                                <td class="text-end weight-percentage">
                                    <?php echo number_format($item['weight_percentage'] ?? 0, 2); ?>%
                                    <div class="progress progress-custom mt-1">
                                        <div class="progress-bar bg-info" style="width: <?php echo min($item['weight_percentage'] ?? 0, 100); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td><strong>TOTAL</strong></td>
                                <td class="text-end"><strong><?php echo number_format($totalWeight, 2); ?></strong></td>
                                <td class="text-end"><strong>Average: <?php echo $totalWeight > 0 ? number_format($materialTotal / $totalWeight, 2) : '0.00'; ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($materialTotal, 0); ?> THB</strong></td>
                                <td class="text-end"><strong>100.00%</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Freight & Logistics Cost -->
        <?php if ($freightPO && !empty($freightCharges)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="freight-header">
                    <h5 class="mb-0">
                        <i class="fas fa-truck icon-large"></i>
                        <?php echo htmlspecialchars($freightPO['po_number'] ?? 'Freight PO'); ?> - Clearing & Logistics Costs
                    </h5>
                </div>
                <div class="cost-table">
                    <table class="table table-hover mb-0">
                        <thead class="table-header">
                            <tr>
                                <th>Cost Type</th>
                                <th class="text-end">Amount (THB)</th>
                                <th class="text-end">Percentage</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($freightCharges as $charge): ?>
                            <tr class="cost-row">
                                <td><?php echo htmlspecialchars($charge['type']); ?></td>
                                <td class="text-end amount-danger"><?php echo number_format($charge['amount'], 0); ?></td>
                                <td class="text-end cost-percentage">
                                    <?php echo number_format($charge['percentage'], 2); ?>%
                                    <div class="progress progress-custom mt-1">
                                        <div class="progress-bar bg-warning" style="width: <?php echo min($charge['percentage'], 100); ?>%"></div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($charge['description']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td><strong>TOTAL CLEARING COSTS</strong></td>
                                <td class="text-end"><strong><?php echo number_format($freightTotal, 0); ?> THB</strong></td>
                                <td class="text-end"><strong>100.00%</strong></td>
                                <td><strong>All additional costs</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- No Freight Data -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="no-freight-message">
                    <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                    <h5>No Freight PO Linked</h5>
                    <p>This Material PO does not have any linked Freight PO yet.</p>
                    <a href="create_freight_po.php?material_po_id=<?php echo $materialPoId; ?>" class="btn btn-primary">
                        <i class="fas fa-truck"></i> Create Freight PO
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cost Allocation Preview -->
        <?php if ($freightPO && !empty($freightCharges)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%); color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie icon-large"></i>
                            Freight Cost Allocation to Material Items
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-header">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-end">Original Cost (THB)</th>
                                        <th class="text-end">Weight %</th>
                                        <th class="text-end">Allocated Freight (THB)</th>
                                        <th class="text-end">Total Cost (THB)</th>
                                        <th class="text-end">Cost per Unit (THB)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materialItems as $index => $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_code'] ?? $item['SSP_Code'] ?? 'Item ' . ($index + 1)); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['product_name'] ?? $item['Name'] ?? ''); ?></small>
                                        </td>
                                        <td class="text-end"><?php echo number_format($item['total_price'] ?? 0, 0); ?></td>
                                        <td class="text-end"><?php echo number_format($item['weight_percentage'] ?? 0, 2); ?>%</td>
                                        <td class="text-end amount-danger"><?php echo number_format($item['allocated_freight'] ?? 0, 0); ?></td>
                                        <td class="text-end"><strong><?php echo number_format($item['total_cost_with_freight'] ?? 0, 0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($item['cost_per_unit'] ?? 0, 2); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="total-row">
                                    <tr>
                                        <td><strong>TOTAL</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($materialTotal, 0); ?></strong></td>
                                        <td class="text-end"><strong>100.00%</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($freightTotal, 0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($grandTotal, 0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo $totalWeight > 0 ? number_format($grandTotal / $totalWeight, 2) : '0.00'; ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <a href="po_list.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to PO List
                                </a>
                                <?php if ($materialPO): ?>
                                <a href="view_po.php?id=<?php echo $materialPoId; ?>" class="btn btn-outline-primary ms-2">
                                    <i class="fas fa-eye"></i> View Material PO
                                </a>
                                <?php endif; ?>
                                <?php if ($freightPO): ?>
                                <a href="view_po.php?id=<?php echo $freightPO['po_id']; ?>" class="btn btn-outline-warning ms-2">
                                    <i class="fas fa-truck"></i> View Freight PO
                                </a>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button type="button" class="btn btn-success me-2" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                                <button type="button" class="btn btn-primary me-2" onclick="printReport()">
                                    <i class="fas fa-print"></i> Print Report
                                </button>
                                <button type="button" class="btn btn-info" onclick="shareReport()">
                                    <i class="fas fa-share-alt"></i> Share Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$materialPO && !$errorMessage): ?>
        <!-- No Data Message -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-info text-center" role="alert">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>No Material PO Selected</h5>
                    <p>Please select a Material PO to view cost analysis.</p>
                    <a href="po_list.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> Go to PO List
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add interactive features
        document.addEventListener('DOMContentLoaded', function() {
            // Debug information
            console.log('Cost Analysis Debug Info:', {
                materialPoId: <?php echo json_encode($materialPoId); ?>,
                freightPoId: <?php echo json_encode($freightPoId); ?>,
                errorMessage: <?php echo json_encode($errorMessage); ?>,
                materialPO: <?php echo $materialPO ? json_encode($materialPO['po_number'] ?? 'Unknown') : 'null'; ?>,
                freightPO: <?php echo $freightPO ? json_encode($freightPO['po_number'] ?? 'Unknown') : 'null'; ?>
            });

            <?php if ($materialPO): ?>
            // Highlight rows on hover
            const rows = document.querySelectorAll('.cost-row');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });

            // Animate progress bars
            const progressBars = document.querySelectorAll('.progress-bar');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.transition = 'width 1s ease-in-out';
                    bar.style.width = width;
                }, 100);
            });
            <?php endif; ?>

            console.log('Cost Analysis page loaded successfully');
        });

        function exportToExcel() {
            // Implementation for Excel export
            showAlert('info', 'Excel export feature will be implemented');
        }

        function printReport() {
            window.print();
        }

        function shareReport() {
            // Implementation for sharing
            showAlert('info', 'Share feature will be implemented');
        }

        function showAlert(type, message) {
            const alertClass = type === 'error' ? 'alert-danger' : 
                              type === 'warning' ? 'alert-warning' :
                              type === 'info' ? 'alert-info' : 'alert-success';
            
            const alert = document.createElement('div');
            alert.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
            alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alert);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }
    </script>
</body>
</html>