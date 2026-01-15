<?php
// pages/dashboard.php - หน้าหลักของระบบ
require_once "../config/config.php";
require_once "../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin(); // ต้อง login ก่อน

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'];
$role = $_SESSION['role'];

// สำหรับแสดงเวลาปัจจุบัน
$current_time = date('Y-m-d H:i:s');
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background-color: #f8f9fa;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #43cea2 0%, #185a9d 100%);
            color: white;
        }
        
        .role-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .role-admin { background-color: #dc3545; color: white; }
        .role-editor { background-color: #fd7e14; color: white; }
        .role-viewer { background-color: #17a2b8; color: white; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-boxes"></i> <?= APP_NAME ?></a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    </li>
                    <?php if ($auth->hasRole('editor')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="materialsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-box"></i> Materials
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="materials/list.php"><i class="fas fa-list"></i> View All</a></li>
                            <?php if ($auth->hasRole('admin')): ?>
                            <li><a class="dropdown-item" href="materials/add.php"><i class="fas fa-plus"></i> Add New</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasRole('admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="users/"><i class="fas fa-users"></i> Users</a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($full_name) ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#"><i class="fas fa-user-edit"></i> Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-cog"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Welcome Card -->
        <div class="row">
            <div class="col-12">
                <div class="card welcome-card">
                    <div class="card-body text-center py-5">
                        <h1 class="card-title"><i class="fas fa-home"></i> Welcome to <?= APP_NAME ?>!</h1>
                        <p class="card-text fs-5">Hello, <strong><?= htmlspecialchars($full_name) ?></strong></p>
                        <p class="card-text">
                            Role: <span class="role-badge role-<?= $role ?>"><?= ucfirst($role) ?></span>
                        </p>
                        <p class="card-text"><small>Login time: <?= $current_time ?></small></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <i class="fas fa-box fa-3x mb-3"></i>
                        <h3>150</h3>
                        <p>Total Materials</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <i class="fas fa-truck fa-3x mb-3"></i>
                        <h3>25</h3>
                        <p>Suppliers</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-3x mb-3"></i>
                        <h3>12</h3>
                        <p>Users</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                        <h3>98%</h3>
                        <p>System Health</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if ($auth->hasRole('editor')): ?>
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <a href="materials/add.php" class="btn btn-primary btn-lg">
                                        <i class="fas fa-plus"></i> Add Material
                                    </a>
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <a href="materials/list.php" class="btn btn-info btn-lg">
                                        <i class="fas fa-search"></i> Browse Materials
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <a href="reports/" class="btn btn-success btn-lg">
                                        <i class="fas fa-chart-bar"></i> View Reports
                                    </a>
                                </div>
                            </div>
                            
                            <?php if ($auth->hasRole('admin')): ?>
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <a href="users/" class="btn btn-warning btn-lg">
                                        <i class="fas fa-user-cog"></i> Manage Users
                                    </a>
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <a href="suppliers/" class="btn btn-secondary btn-lg">
                                        <i class="fas fa-truck"></i> Manage Suppliers
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-clock"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Welcome to the Material Management System! This is your dashboard where you can manage materials, suppliers, and system settings.
                        </div>
                        
                        <p><strong>Your Access Level (<?= ucfirst($role) ?>):</strong></p>
                        <ul>
                            <?php if ($role === 'admin'): ?>
                            <li>✓ Full system access</li>
                            <li>✓ Manage users and permissions</li>
                            <li>✓ Add, edit, delete materials</li>
                            <li>✓ View all reports</li>
                            <li>✓ System configuration</li>
                            <?php elseif ($role === 'editor'): ?>
                            <li>✓ Add, edit materials</li>
                            <li>✓ View materials and reports</li>
                            <li>✓ Manage suppliers</li>
                            <li>✗ Limited user management</li>
                            <?php else: ?>
                            <li>✓ View materials</li>
                            <li>✓ View basic reports</li>
                            <li>✗ Cannot add/edit materials</li>
                            <li>✗ Cannot manage users</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-refresh time every minute
        setInterval(function() {
            location.reload();
        }, 60000);
        
        // Add some interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Add click effects to cards
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 100);
                });
            });
        });
    </script>
</body>
</html>