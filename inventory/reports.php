<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ดูสต็อกสินค้า - Stock Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #8B4513;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
        }

        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: var(--primary-color);
            box-shadow: 0 2px 10px rgba(139, 69, 19, 0.3);
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
        }

        .stock-card {
            border-left: 4px solid var(--info-color);
        }

        .movement-in {
            border-left: 4px solid var(--success-color);
        }

        .movement-out {
            border-left: 4px solid var(--danger-color);
        }

        .stock-status {
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: bold;
        }

        .status-good { background: #d4edda; color: #155724; }
        .status-low { background: #fff3cd; color: #856404; }
        .status-out { background: #f8d7da; color: #721c24; }

        .movement-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
        }

        .search-box {
            border-radius: 25px;
            border: 2px solid #e9ecef;
            padding: 10px 20px;
        }

        .search-box:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15);
        }

        .alert-loading {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="fas fa-boxes me-2"></i>ดูสต็อกสินค้า
            </span>
            <div class="d-flex">
                <button class="btn btn-outline-light btn-sm me-2" onclick="refreshData()">
                    <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                </button>
                <a href="direct-issue.php" class="btn btn-light btn-sm">
                    <i class="fas fa-plus me-1"></i>จ่ายออก
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- Search & Filter -->
        <div class="row mb-4">
            <div class="col-md-6">
                <input type="text" class="form-control search-box" id="searchInput" placeholder="ค้นหาชื่อสินค้า หรือรหัสสินค้า...">
            </div>
            <div class="col-md-3">
                <select class="form-select" id="warehouseFilter">
                    <option value="">ทุกคลัง</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" id="statusFilter">
                    <option value="">ทุกสถานะ</option>
                    <option value="good">มีสต็อก</option>
                    <option value="low">สต็อกต่ำ</option>
                    <option value="out">หมดสต็อก</option>
                </select>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>รายการสินค้าทั้งหมด</h6>
                                <h3 id="totalProducts">-</h3>
                            </div>
                            <i class="fas fa-boxes fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>รับเข้าวันนี้</h6>
                                <h3 id="todayReceived">-</h3>
                            </div>
                            <i class="fas fa-arrow-down fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>จ่ายออกวันนี้</h6>
                                <h3 id="todayIssued">-</h3>
                            </div>
                            <i class="fas fa-arrow-up fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>สต็อกต่ำ</h6>
                                <h3 id="lowStock">-</h3>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stock List -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>รายการสต็อกปัจจุบัน
                            <span id="stockCount" class="badge bg-secondary ms-2">0</span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="stockList" class="p-3">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">กำลังโหลดข้อมูลสต็อก...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Movements -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>การเคลื่อนไหวล่าสุด
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="recentMovements" class="p-3">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">กำลังโหลดข้อมูลการเคลื่อนไหว...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global data
        let stockData = [];
        let movementData = [];
        let warehouses = [];
        let isLoading = false;

        // API endpoints
        const API_BASE = '../api/stock-api.php';

        // Load data from real API
        async function loadData() {
            if (isLoading) return;
            isLoading = true;

            try {
                // Load all data simultaneously
                const [stockRes, movementRes, statsRes, warehousesRes] = await Promise.all([
                    fetch(`${API_BASE}?action=get_stock_summary`),
                    fetch(`${API_BASE}?action=get_recent_movements`),
                    fetch(`${API_BASE}?action=get_summary_stats`),
                    fetch(`${API_BASE}?action=get_warehouses`)
                ]);

                // Check for errors
                if (!stockRes.ok) throw new Error(`Stock API error: ${stockRes.status}`);
                if (!movementRes.ok) throw new Error(`Movement API error: ${movementRes.status}`);
                if (!statsRes.ok) throw new Error(`Stats API error: ${statsRes.status}`);
                if (!warehousesRes.ok) throw new Error(`Warehouses API error: ${warehousesRes.status}`);

                // Parse JSON
                stockData = await stockRes.json();
                movementData = await movementRes.json();
                const stats = await statsRes.json();
                warehouses = await warehousesRes.json();

                // Update UI
                updateSummary(stats);
                populateWarehouseFilter();
                renderStockList();
                renderMovements();

                console.log('Data loaded successfully', {
                    stockCount: stockData.length,
                    movementCount: movementData.length,
                    warehouseCount: warehouses.length
                });

            } catch (error) {
                console.error('Error loading data:', error);
                showError('เกิดข้อผิดพลาดในการโหลดข้อมูล: ' + error.message);
            } finally {
                isLoading = false;
            }
        }

        function showError(message) {
            const stockList = document.getElementById('stockList');
            const movementList = document.getElementById('recentMovements');
            
            const errorHtml = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ${message}
                    <button class="btn btn-sm btn-outline-danger ms-3" onclick="refreshData()">
                        ลองใหม่
                    </button>
                </div>
            `;
            
            stockList.innerHTML = errorHtml;
            movementList.innerHTML = errorHtml;
        }

        function updateSummary(stats) {
            document.getElementById('totalProducts').textContent = stats.total_products || 0;
            document.getElementById('todayReceived').textContent = stats.today_received || 0;
            document.getElementById('todayIssued').textContent = stats.today_issued || 0;
            document.getElementById('lowStock').textContent = stats.low_stock || 0;
        }

        function populateWarehouseFilter() {
            const select = document.getElementById('warehouseFilter');
            // Clear existing options (except first)
            select.innerHTML = '<option value="">ทุกคลัง</option>';
            
            warehouses.forEach(w => {
                const option = document.createElement('option');
                option.value = w.warehouse_name;
                option.textContent = w.warehouse_name;
                select.appendChild(option);
            });
        }

        function renderStockList(data = stockData) {
            const container = document.getElementById('stockList');
            const countBadge = document.getElementById('stockCount');
            
            countBadge.textContent = data.length;
            
            if (data.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted">ไม่พบข้อมูลสต็อก</div>';
                return;
            }

            let html = '';
            data.forEach(item => {
                const lastUpdate = item.last_updated ? 
                    new Date(item.last_updated).toLocaleDateString('th-TH', {
                        year: 'numeric', month: 'short', day: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    }) : 'ไม่ทราบ';

                html += `
                    <div class="card stock-card mb-3">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="card-title mb-2">
                                        ${item.product_name}
                                        <span class="stock-status status-${item.stock_status}">${item.stock_status_text}</span>
                                    </h6>
                                    <div class="row g-2 small text-muted">
                                        <div class="col-md-6">
                                            <i class="fas fa-barcode me-1"></i>รหัส: ${item.product_code || 'N/A'}
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-warehouse me-1"></i>${item.warehouse_name || 'ไม่ระบุ'}
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-map-marker-alt me-1"></i>ตำแหน่ง: ${item.location_display || 'ไม่ระบุ'}
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-clock me-1"></i>อัปเดต: ${lastUpdate}
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="mb-2">
                                        <div class="fw-bold text-primary fs-5">
                                            ${item.available_stock_formatted} ${item.unit_symbol || ''}
                                        </div>
                                        <small class="text-muted">พร้อมใช้</small>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted">
                                            รวม: ${item.current_stock_formatted} | 
                                            จอง: ${item.reserved_stock_formatted}
                                        </small>
                                    </div>

                                    ${parseFloat(item.current_pallet) > 0 ? `
                                    <div class="mb-2">
                                        <div class="text-info">
                                            <i class="fas fa-pallet me-1"></i>
                                            ${item.available_pallet}/${item.current_pallet} พาเลท
                                        </div>
                                    </div>` : ''}

                                    <div>
                                        <small class="text-muted">
                                            ต้นทุน: ฿${item.average_cost_formatted}
                                        </small>
                                        <br>
                                        <small class="text-success">
                                            มูลค่า: ฿${item.stock_value_formatted}
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function renderMovements(data = movementData) {
            const container = document.getElementById('recentMovements');
            
            if (data.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted">ไม่พบข้อมูลการเคลื่อนไหว</div>';
                return;
            }

            let html = '';
            data.forEach(item => {
                const cardClass = item.movement_type === 'RECEIPT' ? 'movement-in' : 'movement-out';
                const badgeClass = `bg-${item.movement_type_color}`;
                
                const moveDate = new Date(item.movement_date).toLocaleDateString('th-TH', {
                    month: 'short', day: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });

                const todayBadge = item.is_today ? '<span class="badge bg-warning text-dark ms-1">วันนี้</span>' : '';

                html += `
                    <div class="card ${cardClass} mb-2">
                        <div class="card-body py-2">
                            <div class="row align-items-center">
                                <div class="col-md-5">
                                    <div class="fw-bold">${item.product_name}</div>
                                    <small class="text-muted">
                                        <i class="fas fa-warehouse me-1"></i>${item.warehouse_name || 'ไม่ระบุ'}
                                        ${item.batch_lot ? ` | Batch: ${item.batch_lot}` : ''}
                                    </small>
                                </div>
                                <div class="col-md-3 text-center">
                                    <span class="badge ${badgeClass} movement-badge">
                                        <i class="fas ${item.movement_type_icon} me-1"></i>${item.movement_type_text}
                                    </span>
                                    <div class="fw-bold">
                                        ${item.quantity_formatted} ${item.unit_symbol || ''}
                                    </div>
                                    ${item.quantity_pallet > 0 ? `<small class="text-muted">${item.quantity_pallet} พาเลท</small>` : ''}
                                </div>
                                <div class="col-md-2 text-center">
                                    <div class="small text-muted">${moveDate} ${todayBadge}</div>
                                    ${parseFloat(item.total_cost) > 0 ? `<small class="text-success">฿${item.total_cost_formatted}</small>` : ''}
                                </div>
                                <div class="col-md-2 text-end">
                                    <div class="small text-muted">${item.reference_type_text}</div>
                                    <div class="small fw-bold">${item.reference_number || 'N/A'}</div>
                                    ${item.created_by_name ? `<small class="text-muted">${item.created_by_name}</small>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        // Search and filter functions
        function filterData() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const warehouseFilter = document.getElementById('warehouseFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;

            let filtered = [...stockData]; // Create copy

            if (searchTerm) {
                filtered = filtered.filter(item => 
                    (item.product_name || '').toLowerCase().includes(searchTerm) ||
                    (item.product_code || '').toLowerCase().includes(searchTerm)
                );
            }

            if (warehouseFilter) {
                filtered = filtered.filter(item => item.warehouse_name === warehouseFilter);
            }

            if (statusFilter) {
                filtered = filtered.filter(item => item.stock_status === statusFilter);
            }

            renderStockList(filtered);
        }

        function refreshData() {
            // Show loading states
            document.getElementById('stockList').innerHTML = `
                <div class="text-center py-4 alert-loading alert">
                    <div class="spinner-border text-info" role="status" style="width: 2rem; height: 2rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 mb-0">กำลังรีเฟรชข้อมูล...</p>
                </div>
            `;
            
            document.getElementById('recentMovements').innerHTML = `
                <div class="text-center py-4 alert-loading alert">
                    <div class="spinner-border text-info" role="status" style="width: 2rem; height: 2rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 mb-0">กำลังรีเฟรชการเคลื่อนไหว...</p>
                </div>
            `;

            // Reset summary
            ['totalProducts', 'todayReceived', 'todayIssued', 'lowStock'].forEach(id => {
                document.getElementById(id).textContent = '-';
            });

            // Reload data
            loadData();
        }

        // Auto-refresh every 5 minutes
        function startAutoRefresh() {
            setInterval(() => {
                if (!document.hidden && !isLoading) {
                    console.log('Auto-refreshing data...');
                    loadData();
                }
            }, 5 * 60 * 1000); // 5 minutes
        }

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Stock Dashboard initializing...');
            
            // Load initial data
            loadData();
            
            // Start auto-refresh
            startAutoRefresh();

            // Event listeners
            document.getElementById('searchInput').addEventListener('input', debounce(filterData, 300));
            document.getElementById('warehouseFilter').addEventListener('change', filterData);
            document.getElementById('statusFilter').addEventListener('change', filterData);

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'r') {
                    e.preventDefault();
                    refreshData();
                }
                if (e.key === 'F5') {
                    e.preventDefault();
                    refreshData();
                }
            });

            console.log('Stock Dashboard initialized');
        });

        // Handle page visibility change
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && !isLoading) {
                // Page became visible, refresh data if it's been more than 2 minutes
                const lastLoad = localStorage.getItem('stockDashboardLastLoad');
                const now = Date.now();
                if (!lastLoad || (now - parseInt(lastLoad)) > 2 * 60 * 1000) {
                    console.log('Page visible - refreshing stale data');
                    loadData();
                }
            }
        });

        // Store last load time
        function markLastLoad() {
            localStorage.setItem('stockDashboardLastLoad', Date.now().toString());
        }

        // Update loadData to mark timestamp
        const originalLoadData = loadData;
        loadData = async function() {
            await originalLoadData();
            markLastLoad();
        };
    </script>
</body>
</html>