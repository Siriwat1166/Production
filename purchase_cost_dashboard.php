<?php
// purchase_cost_dashboard.php - Purchase Cost Analytics Dashboard
require_once "config.php";

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$page_title = "Purchase Cost Dashboard";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>

    <!-- Bootstrap 5.3.0 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome 6.0.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Sarabun', 'Segoe UI', sans-serif;
        }

        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stat-card.card-primary {
            border-left-color: #0d6efd;
        }

        .stat-card.card-success {
            border-left-color: #198754;
        }

        .stat-card.card-warning {
            border-left-color: #ffc107;
        }

        .stat-card.card-info {
            border-left-color: #0dcaf0;
        }

        .stat-card.card-danger {
            border-left-color: #dc3545;
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .chart-container {
            position: relative;
            height: 350px;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .filter-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .section-title {
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #0d6efd;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.active {
            display: flex;
        }

        .badge-custom {
            font-size: 0.9rem;
            padding: 0.4rem 0.8rem;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-light" role="status" style="width: 3rem; height: 3rem;">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-line me-2"></i><?php echo $page_title; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="list.php">
                            <i class="fas fa-list me-1"></i>PO List
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory_view.php">
                            <i class="fas fa-boxes me-1"></i>Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="fas fa-user me-1"></i><?php echo $_SESSION['username'] ?? 'User'; ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Filters -->
        <div class="filter-card">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-calendar me-1"></i>วันที่เริ่มต้น</label>
                    <input type="date" class="form-control" id="dateFrom" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-calendar me-1"></i>วันที่สิ้นสุด</label>
                    <input type="date" class="form-control" id="dateTo" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-clock me-1"></i>ช่วงเวลา</label>
                    <select class="form-select" id="periodSelect">
                        <option value="custom">กำหนดเอง</option>
                        <option value="today">วันนี้</option>
                        <option value="week">สัปดาห์นี้</option>
                        <option value="month" selected>เดือนนี้</option>
                        <option value="quarter">ไตรมาสนี้</option>
                        <option value="year">ปีนี้</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-primary w-100" id="btnRefresh">
                        <i class="fas fa-sync-alt me-2"></i>Refresh Data
                    </button>
                </div>
            </div>
        </div>

        <!-- Summary Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card stat-card card-primary h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Purchase Value</h6>
                                <h3 class="mb-0" id="totalPurchase">0.00 THB</h3>
                                <small class="text-muted">รวมทั้งหมด</small>
                            </div>
                            <div class="stat-icon text-primary">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card stat-card card-success h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Orders</h6>
                                <h3 class="mb-0" id="totalOrders">0</h3>
                                <small class="text-muted">จำนวน PO</small>
                            </div>
                            <div class="stat-icon text-success">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card stat-card card-info h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Average Order Value</h6>
                                <h3 class="mb-0" id="avgOrderValue">0.00 THB</h3>
                                <small class="text-muted">ค่าเฉลี่ยต่อ PO</small>
                            </div>
                            <div class="stat-icon text-info">
                                <i class="fas fa-calculator"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cost Breakdown Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card stat-card card-success h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Material Cost</h6>
                                <h3 class="mb-0" id="totalMaterial">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-success">
                                <i class="fas fa-box"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card stat-card card-warning h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Freight Cost</h6>
                                <h3 class="mb-0" id="totalFreight">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-warning">
                                <i class="fas fa-truck"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card stat-card card-danger h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Service Cost</h6>
                                <h3 class="mb-0" id="totalService">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-danger">
                                <i class="fas fa-cog"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Purchase Cost Trend (12 Months)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="costTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Cost Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="costBreakdownChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Products Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-trophy me-2"></i>Top 10 Most Purchased Products
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover" id="topProductsTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">Rank</th>
                                    <th>Product Code</th>
                                    <th>Product Name</th>
                                    <th class="text-end">Total Quantity</th>
                                    <th class="text-end">Total Cost (THB)</th>
                                    <th class="text-end">Avg Unit Price</th>
                                    <th class="text-center">PO Count</th>
                                </tr>
                            </thead>
                            <tbody id="topProductsBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supplier Comparison Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-building me-2"></i>Supplier Cost Comparison
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover" id="supplierTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Supplier Code</th>
                                    <th>Supplier Name</th>
                                    <th class="text-center">Total POs</th>
                                    <th class="text-end">Total Amount (THB)</th>
                                    <th class="text-end">Avg PO Value</th>
                                    <th class="text-end">Material Cost</th>
                                    <th class="text-end">Freight Cost</th>
                                </tr>
                            </thead>
                            <tbody id="supplierBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Report Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-calendar-alt me-2"></i>Monthly Cost Report (Last 12 Months)
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover" id="monthlyTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Month</th>
                                    <th class="text-end">Material Cost</th>
                                    <th class="text-end">Freight Cost</th>
                                    <th class="text-end">Service Cost</th>
                                    <th class="text-end">Total Cost</th>
                                    <th class="text-center">PO Count</th>
                                </tr>
                            </thead>
                            <tbody id="monthlyBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Freight Analysis Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-truck-loading me-2"></i>Freight Cost Analysis
                    </h5>

                    <!-- Freight Summary Cards -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Total Freight POs</h6>
                                    <h4 id="freightPOCount">0</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Total Freight Amount</h6>
                                    <h4 id="freightTotalAmount">0.00 THB</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Avg Freight per PO</h6>
                                    <h4 id="freightAvgAmount">0.00 THB</h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Freight Details Table -->
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>PO Number</th>
                                    <th>Date</th>
                                    <th>Supplier</th>
                                    <th class="text-end">Freight Amount</th>
                                    <th class="text-center">Status</th>
                                    <th>Linked PO</th>
                                </tr>
                            </thead>
                            <tbody id="freightDetailsBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JavaScript -->
    <script src="js/purchase_cost_charts.js"></script>
</body>
</html>
