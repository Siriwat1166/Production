<?php
// production/pages/po/po_list.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/POManager.php';

$auth = new Auth();
$auth->requireLogin();

$poManager = new POManager();

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'get_pos') {
    header('Content-Type: application/json');
    
    try {
        $filters = [
            'po_type' => $_GET['po_type'] ?? '',
            'status' => $_GET['status'] ?? '',
            'supplier_id' => $_GET['supplier_id'] ?? '', 
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
        
        // Debug log
        error_log("PO List AJAX Request - Filters: " . json_encode($filters));
        
        $result = $poManager->getPOList($filters);
        
        // Debug log
        error_log("PO List AJAX Response: " . json_encode([
            'success' => $result['success'],
            'count' => count($result['data'] ?? [])
        ]));
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("PO List AJAX Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => []
        ]);
    }
    exit;
}

// Get filter options with error handling
try {
    $suppliers = $poManager->getSuppliers();
    $poTypes = $poManager->getPOTypes();
} catch (Exception $e) {
    error_log("Error loading filter options: " . $e->getMessage());
    $suppliers = [];
    $poTypes = [];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order List - No Loading</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .po-type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .material-po { background-color: #28a745; }
        .freight-po { background-color: #fd7e14; }
        .combined-po { background-color: #6f42c1; }
        
        .status-badge {
            font-size: 0.75rem;
        }
        .status-draft { background-color: #6c757d; }
        .status-pending { background-color: #ffc107; color: #000; }
        .status-approved { background-color: #28a745; }
        .status-completed { background-color: #17a2b8; }
        .status-cancelled { background-color: #dc3545; }
        
        .linked-po {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .table-responsive {
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            border-radius: 0.5rem;
        }
        
        .action-buttons .btn {
            margin: 0 2px;
            padding: 0.25rem 0.5rem;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .no-data-message {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        /* Custom alert positioning */
        .custom-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-file-invoice"></i> Purchase Order Management
                            </h4>
                            <div class="btn-group">
                                <button type="button" class="btn btn-light" data-action="create-material-po">
                                    <i class="fas fa-plus"></i> Material PO
                                </button>
                                <button type="button" class="btn btn-warning" data-action="create-freight-po">
                                    <i class="fas fa-truck"></i> Freight PO
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="filter-section">
                    <form id="filterForm" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">PO Type</label>
                            <select class="form-select" name="po_type" id="po_type">
                                <option value="">All Types</option>
                                <option value="MATERIAL">Material</option>
                                <option value="FREIGHT">Freight</option>
                                <option value="COMBINED">Combined</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="">All Status</option>
                                <option value="DRAFT">Draft</option>
                                <option value="PENDING">Pending</option>
                                <option value="APPROVED">Approved</option>
                                <option value="COMPLETED">Completed</option>
                                <option value="CANCELLED">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Supplier</label>
                            <select class="form-select" name="supplier_id" id="supplier_id">
                                <option value="">All Suppliers</option>
                                <?php if (!empty($suppliers)): ?>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo htmlspecialchars($supplier['supplier_id']); ?>">
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" id="date_from">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" id="date_to">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" id="search" 
                                   placeholder="PO Number, Description...">
                        </div>
                    </form>
                    <div class="row mt-3">
                        <div class="col-12">
                            <button type="button" class="btn btn-primary" id="applyFilter">
                                <i class="fas fa-search"></i> Apply Filter
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearFilter">
                                <i class="fas fa-times"></i> Clear
                            </button>
                            <button type="button" class="btn btn-success" id="exportExcel">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button type="button" class="btn btn-info" id="refreshData">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connection Status -->
        <div id="connectionStatus" class="row mb-3" style="display: none;">
            <div class="col-12">
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="connectionMessage">Checking connection...</span>
                </div>
            </div>
        </div>

        <!-- PO List Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div id="tableContainer">
                            <div class="table-responsive">
                                <table id="poTable" class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>PO Number</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Supplier</th>
                                            <th>Total Amount</th>
                                            <th>Status</th>
                                            <th>Linked PO</th>
                                            <th>Group</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="poTableBody">
                                        <!-- Data will be loaded via AJAX -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Error Message Container -->
                        <div id="errorContainer" style="display: none;">
                            <div class="error-message">
                                <h5><i class="fas fa-exclamation-triangle"></i> Error Loading Data</h5>
                                <p id="errorMessage">Unable to load PO data. Please try again.</p>
                                <button type="button" class="btn btn-primary" onclick="retryLoad()">
                                    <i class="fas fa-redo"></i> Retry
                                </button>
                            </div>
                        </div>
                        
                        <!-- No Data Message -->
                        <div id="noDataContainer" class="no-data-message" style="display: none;">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <h5>No Purchase Orders Found</h5>
                            <p>No PO records match your current filters.</p>
                            <button type="button" class="btn btn-outline-primary" onclick="clearAllFilters()">
                                <i class="fas fa-filter"></i> Clear All Filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
    // Global variables
    let dataTable;
    let requestAbortController;

    // Declare all action functions first (before document ready)
    function createPO(type) {
        console.log('Creating PO of type:', type);
        if (type === 'MATERIAL') {
            window.location.href = 'create_material_po.php';
        } else if (type === 'FREIGHT') {
            window.location.href = 'create_freight_po.php';
        }
    }

    function viewPO(poId) {
        console.log('Viewing PO:', poId);
        window.location.href = `view_po.php?id=${poId}`;
    }

    function editPO(poId) {
        console.log('Editing PO:', poId);
        window.location.href = `edit_po.php?id=${poId}`;
    }

    function createFreightPO(materialPoId) {
        console.log('Creating Freight PO for Material PO:', materialPoId);
        window.location.href = `create_freight_po.php?material_po_id=${materialPoId}`;
    }

    function viewCostAnalysis(poId) {
        console.log('Opening cost analysis for PO ID:', poId);
        window.location.href = `cost_analysis.php?material_po_id=${poId}`;
    }

    function printPO(poId) {
        console.log('Printing PO:', poId);
        window.open(`print_po.php?id=${poId}`, '_blank');
    }

    function exportToExcel() {
        const filters = {
            po_type: $('#po_type').val() || '',
            status: $('#status').val() || '',
            supplier_id: $('#supplier_id').val() || '',
            date_from: $('#date_from').val() || '',
            date_to: $('#date_to').val() || '',
            search: $('#search').val() || ''
        };
        
        const params = new URLSearchParams(filters);
        window.open(`export_po_excel.php?${params.toString()}`, '_blank');
    }

    function retryLoad() {
        loadPOData();
    }

    function clearAllFilters() {
        $('#filterForm')[0].reset();
        loadPOData();
    }

    // Utility functions
    function formatDate(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('th-TH');
        } catch (error) {
            return dateString;
        }
    }

    function formatCurrency(amount) {
        if (!amount || isNaN(amount)) return '0.00 THB';
        return parseFloat(amount).toLocaleString('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' THB';
    }

    function showAlert(type, message) {
        // Remove existing alerts
        $('.custom-alert').remove();
        
        const alertClass = type === 'error' ? 'alert-danger' : 'alert-success';
        const icon = type === 'error' ? 'fas fa-exclamation-triangle' : 'fas fa-check-circle';
        
        const alert = $(`
            <div class="alert ${alertClass} alert-dismissible fade show custom-alert" role="alert">
                <i class="${icon} me-2"></i>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `);
        
        $('body').append(alert);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            alert.fadeOut(500, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Document ready function
    $(document).ready(function() {
        console.log('PO List initialized');
        console.log('Function availability check:', {
            viewPO: typeof viewPO,
            editPO: typeof editPO,
            createFreightPO: typeof createFreightPO,
            viewCostAnalysis: typeof viewCostAnalysis,
            printPO: typeof printPO,
            createPO: typeof createPO
        });
        
        initializeDataTable();
        
        // Initial load without loading screen
        setTimeout(() => {
            loadPOData();
        }, 500);
        
        // Event listeners for main controls
        $('#applyFilter').click(function() {
            loadPOData();
        });
        
        $('#clearFilter').click(function() {
            $('#filterForm')[0].reset();
            loadPOData();
        });
        
        $('#refreshData').click(function() {
            loadPOData();
        });
        
        $('#exportExcel').click(function() {
            exportToExcel();
        });
        
        // Enter key support for search
        $('#search').keypress(function(e) {
            if (e.which == 13) {
                loadPOData();
            }
        });
        
        // Auto-refresh every 30 seconds
        setInterval(function() {
            loadPOData(true); // Silent refresh
        }, 30000);
        
        // Set up event delegation for action buttons
        setupEventDelegation();
        
        console.log('All initialization complete');
    });

    function setupEventDelegation() {
        // Event delegation for header buttons
        $(document).on('click', '[data-action]', function(e) {
            e.preventDefault();
            const action = $(this).data('action');
            
            console.log('Header button clicked:', action);
            
            switch (action) {
                case 'create-material-po':
                    createPO('MATERIAL');
                    break;
                case 'create-freight-po':
                    createPO('FREIGHT');
                    break;
            }
        });

        // Event delegation for action buttons in table
        $(document).on('click', '.action-buttons button', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const action = button.data('action');
            const poId = button.data('po-id');
            
            console.log('Action button clicked:', action, 'PO ID:', poId);
            
            if (!poId) {
                console.error('No PO ID found for action:', action);
                showAlert('error', 'Invalid PO ID');
                return;
            }
            
            switch (action) {
                case 'view':
                    viewPO(poId);
                    break;
                case 'edit':
                    editPO(poId);
                    break;
                case 'add-freight':
                    createFreightPO(poId);
                    break;
                case 'cost-analysis':
                    viewCostAnalysis(poId);
                    break;
                case 'print':
                    printPO(poId);
                    break;
                default:
                    console.warn('Unknown action:', action);
            }
        });
        
        console.log('Event delegation setup complete');
    }

    function initializeDataTable() {
        try {
            dataTable = $('#poTable').DataTable({
                "paging": true,
                "lengthChange": true,
                "searching": false,
                "ordering": true,
                "info": true,
                "autoWidth": false,
                "responsive": true,
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "order": [[2, 'desc']],
                "columnDefs": [
                    { "orderable": false, "targets": [8] }
                ],
                "language": {
                    "lengthMenu": "แสดง _MENU_ รายการต่อหน้า",
                    "zeroRecords": "ไม่พบข้อมูล",
                    "info": "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    "infoEmpty": "แสดง 0 ถึง 0 จาก 0 รายการ",
                    "infoFiltered": "(กรองจากทั้งหมด _MAX_ รายการ)",
                    "paginate": {
                        "first": "แรก",
                        "last": "สุดท้าย",
                        "next": "ถัดไป",
                        "previous": "ก่อนหน้า"
                    }
                }
            });
            console.log('DataTable initialized successfully');
        } catch (error) {
            console.error('Error initializing DataTable:', error);
            showAlert('error', 'ไม่สามารถเริ่มต้นตารางได้');
        }
    }

    function loadPOData(silent = false) {
        // Cancel previous request
        if (requestAbortController) {
            requestAbortController.abort();
        }
        
        requestAbortController = new AbortController();
        
        hideContainers();
        
        const filters = {
            po_type: $('#po_type').val() || '',
            status: $('#status').val() || '',
            supplier_id: $('#supplier_id').val() || '',
            date_from: $('#date_from').val() || '',
            date_to: $('#date_to').val() || '',
            search: $('#search').val() || ''
        };
        
        console.log('Loading PO data with filters:', filters);
        
        // Use Fetch API with AbortController for better control
        fetch(window.location.pathname + '?action=get_pos&' + new URLSearchParams(filters), {
            method: 'GET',
            signal: requestAbortController.signal,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('PO data loaded:', data);
            
            if (data && data.success) {
                updateTable(data.data || []);
                showConnectionStatus(true);
                if (!silent) {
                    showAlert('success', `โหลดข้อมูลสำเร็จ พบ ${data.data ? data.data.length : 0} รายการ`);
                }
            } else {
                showError(data?.message || 'ไม่สามารถโหลดข้อมูลได้');
            }
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                console.log('Request was aborted');
                return;
            }
            
            console.error('Fetch error:', error);
            showConnectionStatus(false, error.message);
            
            let errorMsg = 'เกิดข้อผิดพลาดในการโหลดข้อมูล: ';
            if (error.message.includes('Failed to fetch')) {
                errorMsg += 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้';
            } else if (error.message.includes('NetworkError')) {
                errorMsg += 'ปัญหาเครือข่าย';
            } else {
                errorMsg += error.message;
            }
            
            showError(errorMsg);
        });
    }

    function updateTable(data) {
        try {
            hideContainers();
            
            if (!dataTable) {
                console.error('DataTable not initialized');
                showError('ตารางยังไม่ถูกเริ่มต้น');
                return;
            }
            
            dataTable.clear();
            
            if (data && data.length > 0) {
                $('#tableContainer').show();
                
                data.forEach(function(po) {
                    const row = [
                        po.po_number || '-',
                        getPOTypeBadge(po.po_category || po.po_type),
                        formatDate(po.po_date),
                        po.supplier_name || '-',
                        formatCurrency(po.total_amount),
                        getStatusBadge(po.status),
                        getLinkedPO(po),
                        po.group_name || '-',
                        getActionButtons(po)
                    ];
                    
                    dataTable.row.add(row);
                });
                
                dataTable.draw();
                console.log('Table updated with', data.length, 'rows');
            } else {
                $('#noDataContainer').show();
            }
        } catch (error) {
            console.error('Error updating table:', error);
            showError('เกิดข้อผิดพลาดในการแสดงผลตาราง: ' + error.message);
        }
    }

    function hideContainers() {
        $('#tableContainer, #errorContainer, #noDataContainer').hide();
    }

    function showError(message) {
        hideContainers();
        $('#errorMessage').text(message);
        $('#errorContainer').show();
    }

    function showConnectionStatus(isConnected, errorMessage = '') {
        if (isConnected) {
            $('#connectionStatus').hide();
        } else {
            $('#connectionMessage').text('Connection error: ' + errorMessage);
            $('#connectionStatus').show();
        }
    }

    function getPOTypeBadge(type) {
        const badges = {
            'MATERIAL': '<span class="badge material-po po-type-badge">Material</span>',
            'FREIGHT': '<span class="badge freight-po po-type-badge">Freight</span>',
            'COMBINED': '<span class="badge combined-po po-type-badge">Combined</span>'
        };
        return badges[type] || '<span class="badge bg-secondary po-type-badge">Unknown</span>';
    }

    function getStatusBadge(status) {
        const badges = {
            'DRAFT': '<span class="badge status-draft">Draft</span>',
            'PENDING': '<span class="badge status-pending">Pending</span>',
            'APPROVED': '<span class="badge status-approved">Approved</span>',
            'COMPLETED': '<span class="badge status-completed">Completed</span>',
            'CANCELLED': '<span class="badge status-cancelled">Cancelled</span>'
        };
        return badges[status] || '<span class="badge bg-secondary">Unknown</span>';
    }

    function getLinkedPO(po) {
        let linked = '';
        if (po.linked_po_number) {
            linked += `<div class="linked-po">→ ${po.linked_po_number}</div>`;
        }
        if (po.freight_po_number && po.po_category === 'MATERIAL') {
            linked += `<div class="linked-po">🚛 ${po.freight_po_number}</div>`;
        }
        if (po.material_po_number && po.po_category === 'FREIGHT') {
            linked += `<div class="linked-po">📦 ${po.material_po_number}</div>`;
        }
        return linked || '-';
    }

    function getActionButtons(po) {
        let buttons = `<div class="action-buttons">
            <button class="btn btn-sm btn-outline-primary" data-action="view" data-po-id="${po.po_id}" title="View">
                <i class="fas fa-eye"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" data-action="edit" data-po-id="${po.po_id}" title="Edit">
                <i class="fas fa-edit"></i>
            </button>`;
        
        if (po.po_category === 'MATERIAL' && !po.freight_po_id) {
            buttons += `
                <button class="btn btn-sm btn-outline-warning" data-action="add-freight" data-po-id="${po.po_id}" title="Add Freight">
                    <i class="fas fa-truck"></i>
                </button>`;
        }
        
        if (po.po_category === 'MATERIAL') {
            buttons += `
                <button class="btn btn-sm btn-outline-info" data-action="cost-analysis" data-po-id="${po.po_id}" title="Cost Analysis">
                    <i class="fas fa-chart-pie"></i>
                </button>`;
        }
        
        buttons += `
            <button class="btn btn-sm btn-outline-success" data-action="print" data-po-id="${po.po_id}" title="Print">
                <i class="fas fa-print"></i>
            </button>
        </div>`;
        
        return buttons;
    }
    </script>
</body>
</html>