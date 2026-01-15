<?php
// production/pages/po/view_po.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

// Define Database class locally for this file only
if (!class_exists('Database')) {
    class Database {
        private $server;
        private $database;
        private $username;
        private $password;
        private $conn;
        
        public function __construct() {
            $this->server = DB_SERVER;
            $this->database = DB_NAME;
            $this->username = DB_USERNAME;
            $this->password = DB_PASSWORD;
        }
        
        public function getConnection() {
            $this->conn = null;
            
            try {
                $this->conn = new PDO(
                    "sqlsrv:Server=" . $this->server . ";Database=" . $this->database,
                    $this->username,
                    $this->password,
                    array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
                    )
                );
            } catch(PDOException $exception) {
                error_log("Database Connection Error: " . $exception->getMessage());
                throw new Exception("Database connection failed: " . $exception->getMessage());
            }
            
            return $this->conn;
        }
    }
}

require_once __DIR__ . '/../../classes/POManager.php';
require_once __DIR__ . '/../../classes/ViewPOManager.php';

$auth = new Auth();
$auth->requireLogin();

$poManager = new POManager();
$viewPOManager = new ViewPOManager();

// Get PO ID from URL
$poId = $_GET['id'] ?? null;

if (!$poId) {
    header('Location: po_list.php?error=invalid_po_id');
    exit;
}

// Get PO details
$poResult = $viewPOManager->getFullPODetails($poId);

if (!$poResult['success']) {
    header('Location: po_list.php?error=po_not_found');
    exit;
}

$po = $poResult['data'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_status':
            $newStatus = $_POST['status'] ?? '';
            $result = $viewPOManager->updatePOStatus($poId, $newStatus, $_SESSION['user_id']);
            echo json_encode($result);
            exit;
            
        case 'add_note':
            $note = $_POST['note'] ?? '';
            $result = $viewPOManager->addPONote($poId, $note, $_SESSION['user_id']);
            echo json_encode($result);
            exit;
    }
}

// Determine PO type for styling
$poTypeClass = strtolower($po['po_category']) . '-po';
$poTypeIcon = $po['po_category'] === 'MATERIAL' ? 'fas fa-boxes' : 
              ($po['po_category'] === 'FREIGHT' ? 'fas fa-truck' : 'fas fa-layer-group');
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Details: <?php echo htmlspecialchars($po['po_number']); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .material-po { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .freight-po { background: linear-gradient(135deg, #fd7e14 0%, #e55a4f 100%); }
        .combined-po { background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%); }
        
        .po-header {
            color: white;
            border-radius: 0.5rem;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .status-badge {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
        }
        
        .status-draft { background-color: #6c757d; }
        .status-pending { background-color: #ffc107; color: #000; }
        .status-approved { background-color: #28a745; }
        .status-completed { background-color: #17a2b8; }
        .status-cancelled { background-color: #dc3545; }
        
        .info-card {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: #212529;
            font-size: 1.1rem;
        }
        
        .linked-po-card {
            border-left: 4px solid #17a2b8;
            padding: 1rem;
            background: #f8f9fa;
            margin: 0.5rem 0;
        }
        
        .items-table {
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .allocation-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
            background: white;
        }
        
        .allocation-item:last-child {
            border-bottom: none;
        }
        
        .allocation-bar {
            height: 6px;
            background: linear-gradient(90deg, #28a745, #fd7e14);
            border-radius: 3px;
            margin: 0.25rem 0;
        }
        
        .action-buttons .btn {
            margin: 0.25rem;
        }
        
        .timeline-item {
            border-left: 2px solid #dee2e6;
            padding-left: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        
        .timeline-item::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            position: absolute;
            left: -5px;
            top: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .po-header {
                padding: 1rem;
            }
            
            .action-buttons {
                text-align: center;
            }
            
            .action-buttons .btn {
                width: 100%;
                margin: 0.25rem 0;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- PO Header -->
        <div class="po-header <?php echo $poTypeClass; ?>">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-3">
                        <i class="<?php echo $poTypeIcon; ?> fa-2x me-3"></i>
                        <div>
                            <h2 class="mb-0"><?php echo htmlspecialchars($po['po_number']); ?></h2>
                            <p class="mb-0 opacity-75"><?php echo ucfirst(strtolower($po['po_category'])); ?> Purchase Order</p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="info-label">Supplier</div>
                            <div class="info-value"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">PO Date</div>
                            <div class="info-value"><?php echo date('d M Y', strtotime($po['po_date'])); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 text-md-end">
                    <div class="mb-3">
                        <span class="status-badge status-<?php echo strtolower($po['status']); ?>">
                            <?php echo ucfirst($po['status']); ?>
                        </span>
                    </div>
                    
                    <div class="info-label">Total Amount</div>
                    <h3 class="mb-0"><?php echo number_format($po['total_amount'], 2); ?> THB</h3>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- PO Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> PO Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-card">
                                    <div class="info-label">PO Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($po['po_number']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-card">
                                    <div class="info-label">PO Date</div>
                                    <div class="info-value"><?php echo date('d M Y', strtotime($po['po_date'])); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-card">
                                    <div class="info-label">Supplier</div>
                                    <div class="info-value"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                                    <?php if ($po['supplier_code']): ?>
                                        <small class="text-muted">Code: <?php echo htmlspecialchars($po['supplier_code']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-card">
                                    <div class="info-label">Delivery Date</div>
                                    <div class="info-value">
                                        <?php echo $po['delivery_date'] ? date('d M Y', strtotime($po['delivery_date'])) : 'Not specified'; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($po['group_name']): ?>
                            <div class="col-12">
                                <div class="info-card">
                                    <div class="info-label">PO Group</div>
                                    <div class="info-value"><?php echo htmlspecialchars($po['group_name']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($po['notes']): ?>
                            <div class="col-12">
                                <div class="info-card">
                                    <div class="info-label">Notes</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($po['notes'])); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Linked POs -->
                <?php if ($po['linked_pos']): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-link"></i> Linked Purchase Orders</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($po['linked_pos'] as $linkedPO): ?>
                        <div class="linked-po-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">
                                        <i class="fas fa-<?php echo $linkedPO['po_category'] === 'MATERIAL' ? 'boxes' : 'truck'; ?>"></i>
                                        <?php echo htmlspecialchars($linkedPO['po_number']); ?>
                                    </h6>
                                    <p class="mb-0 text-muted">
                                        <?php echo ucfirst(strtolower($linkedPO['po_category'])); ?> PO - 
                                        <?php echo number_format($linkedPO['total_amount'], 2); ?> THB
                                    </p>
                                </div>
                                <div>
                                    <a href="view_po.php?id=<?php echo $linkedPO['po_id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PO Items -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> 
                            <?php echo $po['po_category'] === 'MATERIAL' ? 'Items' : 'Charges'; ?>
                            <span class="badge bg-secondary ms-2"><?php echo count($po['items']); ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="items-table">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th width="5%">#</th>
                                            <?php if ($po['po_category'] === 'MATERIAL'): ?>
                                                <th width="15%">Product Code</th>
                                                <th width="25%">Product Name</th>
                                                <th width="10%">Quantity</th>
                                                <th width="10%">Unit</th>
                                                <th width="10%">Unit Price</th>
                                                <th width="10%">Discount</th>
                                                <th width="15%">Total</th>
                                            <?php else: ?>
                                                <th width="20%">Charge Type</th>
                                                <th width="35%">Description</th>
                                                <th width="15%">Amount</th>
                                                <th width="10%">Currency</th>
                                                <th width="15%">Notes</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($po['items'] as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <?php if ($po['po_category'] === 'MATERIAL'): ?>
                                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($item['SSP_Code'] ?? $item['product_code'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($item['product_name'] ?? $item['item_description']); ?></td>
                                                <td class="text-end"><?php echo number_format($item['quantity'], 3); ?></td>
                                                <td><?php echo htmlspecialchars($item['purchase_unit_name'] ?? 'Unit'); ?></td>
                                                <td class="text-end"><?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="text-end"><?php echo number_format($item['discount_percent'] ?? 0, 2); ?>%</td>
                                                <td class="text-end fw-bold"><?php echo number_format($item['total_price'], 2); ?> THB</td>
                                            <?php else: ?>
                                                <td>
                                                    <?php 
                                                    $description = $item['item_description'];
                                                    $chargeType = explode(':', $description)[0];
                                                    echo htmlspecialchars($chargeType);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['item_description']); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($item['total_price'], 2); ?></td>
                                                <td>THB</td>
                                                <td class="text-muted"><?php echo htmlspecialchars($item['notes'] ?? ''); ?></td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="<?php echo $po['po_category'] === 'MATERIAL' ? 7 : 4; ?>" class="text-end">Total:</th>
                                            <th class="text-end"><?php echo number_format($po['total_amount'], 2); ?> THB</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cost Allocation (for Freight PO) -->
                <?php if ($po['po_category'] === 'FREIGHT' && $po['allocations']): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Cost Allocation</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <small class="text-muted">
                                Allocation Method: <span class="fw-bold"><?php echo $po['cost_allocation_method'] ?? 'Weight-based'; ?></span>
                            </small>
                        </div>
                        
                        <?php foreach ($po['allocations'] as $allocation): ?>
                        <div class="allocation-item">
                            <div class="flex-grow-1">
                                <div class="fw-bold text-primary"><?php echo htmlspecialchars($allocation['product_code']); ?></div>
                                <div class="text-muted small"><?php echo htmlspecialchars($allocation['product_name']); ?></div>
                                <div class="allocation-bar" style="width: <?php echo $allocation['allocation_percentage']; ?>%"></div>
                                <div class="small">
                                    Weight: <?php echo number_format($allocation['weight_ratio'], 2); ?> kg 
                                    (<?php echo number_format($allocation['allocation_percentage'], 2); ?>%)
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">+<?php echo number_format($allocation['allocation_amount'], 2); ?> THB</div>
                                <div class="small text-muted">Allocated</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Action Buttons -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cogs"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="action-buttons">
                            <a href="po_list.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                            
                            <?php if (in_array($po['status'], ['DRAFT', 'PENDING'])): ?>
                            <button class="btn btn-outline-primary" onclick="editPO(<?php echo $po['po_id']; ?>)">
                                <i class="fas fa-edit"></i> Edit PO
                            </button>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-info" onclick="printPO()">
                                <i class="fas fa-print"></i> Print PO
                            </button>
                            
                            <button class="btn btn-outline-success" onclick="exportPDF()">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            
                            <?php if ($po['po_category'] === 'MATERIAL' && !$po['freight_po_number']): ?>
                            <a href="create_freight_po.php?material_po_id=<?php echo $po['po_id']; ?>" class="btn btn-warning">
                                <i class="fas fa-truck"></i> Add Freight PO
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($auth->hasRole('admin')): ?>
                            <div class="dropdown mt-2">
                                <button class="btn btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-v"></i> More Actions
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="changeStatus('APPROVED')">
                                        <i class="fas fa-check"></i> Approve PO
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="changeStatus('CANCELLED')">
                                        <i class="fas fa-times"></i> Cancel PO
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="#" onclick="deletePO()">
                                        <i class="fas fa-trash"></i> Delete PO
                                    </a></li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calculator"></i> Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Items/Charges:</span>
                            <strong><?php echo count($po['items']); ?></strong>
                        </div>
                        
                        <?php if ($po['po_category'] === 'MATERIAL'): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Total Quantity:</span>
                            <strong><?php echo number_format($po['total_quantity'], 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Material Amount:</span>
                            <strong><?php echo number_format($po['material_amount'] ?? $po['total_amount'], 2); ?> THB</strong>
                        </div>
                        <?php else: ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Freight Amount:</span>
                            <strong><?php echo number_format($po['freight_amount'] ?? $po['total_amount'], 2); ?> THB</strong>
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="h6">Total Amount:</span>
                            <strong class="h5 text-primary"><?php echo number_format($po['total_amount'], 2); ?> THB</strong>
                        </div>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Timeline</h5>
                    </div>
                    <div class="card-body">
                        <div class="timeline-item">
                            <div class="fw-bold">PO Created</div>
                            <div class="text-muted small">
                                <?php echo date('d M Y H:i', strtotime($po['created_date'])); ?>
                                <?php if ($po['created_by_name']): ?>
                                    by <?php echo htmlspecialchars($po['created_by_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($po['updated_date'] && $po['updated_date'] !== $po['created_date']): ?>
                        <div class="timeline-item">
                            <div class="fw-bold">Last Updated</div>
                            <div class="text-muted small">
                                <?php echo date('d M Y H:i', strtotime($po['updated_date'])); ?>
                                <?php if ($po['updated_by_name']): ?>
                                    by <?php echo htmlspecialchars($po['updated_by_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($po['approval_date']): ?>
                        <div class="timeline-item">
                            <div class="fw-bold">PO Approved</div>
                            <div class="text-muted small">
                                <?php echo date('d M Y H:i', strtotime($po['approval_date'])); ?>
                                <?php if ($po['approved_by_name']): ?>
                                    by <?php echo htmlspecialchars($po['approved_by_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">Processing...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function editPO(poId) {
        const poCategory = '<?php echo $po["po_category"]; ?>';
        if (poCategory === 'MATERIAL') {
            window.location.href = `edit_material_po.php?id=${poId}`;
        } else {
            window.location.href = `edit_freight_po.php?id=${poId}`;
        }
    }
    
    function printPO() {
        window.print();
    }
    
    function exportPDF() {
        window.open(`export_po_pdf.php?id=<?php echo $po['po_id']; ?>`, '_blank');
    }
    
    function changeStatus(newStatus) {
        if (!confirm(`Are you sure you want to change status to ${newStatus}?`)) {
            return;
        }
        
        showLoading();
        
        $.ajax({
            url: 'view_po.php?id=<?php echo $po['po_id']; ?>',
            method: 'POST',
            data: {
                action: 'update_status',
                status: newStatus
            },
            dataType: 'json',
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showAlert('success', 'PO status updated successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', 'Failed to update status: ' + response.message);
                }
            },
            error: function() {
                hideLoading();
                showAlert('error', 'Request failed');
            }
        });
    }
    
    function deletePO() {
        if (!confirm('Are you sure you want to delete this PO? This action cannot be undone.')) {
            return;
        }
        
        showAlert('warning', 'Delete functionality not implemented yet');
    }
    
    function showLoading() {
        $('#loadingModal').modal('show');
    }
    
    function hideLoading() {
        $('#loadingModal').modal('hide');
    }
    
    function showAlert(type, message) {
        const alertClass = type === 'error' ? 'alert-danger' : 
                          type === 'warning' ? 'alert-warning' :
                          type === 'info' ? 'alert-info' : 'alert-success';
        
        const alert = `
            <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                 style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        // Remove existing alerts
        $('.alert').remove();
        
        $('body').append(alert);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $('.alert').alert('close');
        }, 5000);
    }
    
    // Print styles
    window.addEventListener('beforeprint', function() {
        document.body.classList.add('printing');
    });
    
    window.addEventListener('afterprint', function() {
        document.body.classList.remove('printing');
    });
    </script>
    
    <style media="print">
        .action-buttons, .btn, .dropdown { display: none !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; }
        .po-header { -webkit-print-color-adjust: exact; color-adjust: exact; }
        body { font-size: 12px; }
        .container-fluid { max-width: 100%; }
    </style>
</body>
</html>