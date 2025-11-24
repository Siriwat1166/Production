<?php
// debug_login.php - หน้าทดสอบ login
require_once 'config/config.php';
require_once 'classes/Auth.php';

$auth = new Auth();
$debug_result = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        $debug_result = $auth->testLogin($username, $password);
    }
}

// Get all users for reference
$all_users = $auth->getAllUsers();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Login - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row">
            <div class="col-12">
                <h1 class="text-center mb-4">
                    <i class="fas fa-bug"></i> Debug Login
                </h1>
            </div>
        </div>
        
        <div class="row">
            <!-- Login Test Form -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-vial"></i> Test Login</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="username" 
                                       name="username" 
                                       value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>"
                                       required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="password" 
                                       name="password" 
                                       value="<?= htmlspecialchars($_POST['password'] ?? 'admin123') ?>"
                                       required>
                                <small class="text-muted">Using text field to see the password</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-bug"></i> Test Login
                            </button>
                        </form>
                        
                        <hr>
                        
                        <h6>Quick Test Buttons:</h6>
                        <div class="btn-group-vertical w-100" role="group">
                            <button onclick="testUser('admin', 'admin123')" class="btn btn-outline-danger btn-sm">
                                Test Admin
                            </button>
                            <button onclick="testUser('user1', 'user123')" class="btn btn-outline-warning btn-sm">
                                Test Editor
                            </button>
                            <button onclick="testUser('user2', 'user123')" class="btn btn-outline-info btn-sm">
                                Test Viewer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Debug Results -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-search"></i> Debug Results</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($debug_result): ?>
                            <?php if (isset($debug_result['error'])): ?>
                                <div class="alert alert-danger">
                                    <strong>Error:</strong> <?= htmlspecialchars($debug_result['error']) ?>
                                </div>
                            <?php elseif ($debug_result['user_found']): ?>
                                <div class="alert alert-info">
                                    <strong>User Found!</strong>
                                </div>
                                
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Username:</strong></td>
                                        <td><?= htmlspecialchars($debug_result['user_data']['username']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Full Name:</strong></td>
                                        <td><?= htmlspecialchars($debug_result['user_data']['full_name']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Role:</strong></td>
                                        <td><?= htmlspecialchars($debug_result['user_data']['role']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Is Active:</strong></td>
                                        <td>
                                            <span class="badge bg-<?= $debug_result['user_data']['is_active'] ? 'success' : 'danger' ?>">
                                                <?= $debug_result['user_data']['is_active'] ? 'Yes' : 'No' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Password Input:</strong></td>
                                        <td><code>'<?= htmlspecialchars($debug_result['password_input']) ?>'</code></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Password Stored:</strong></td>
                                        <td><code>'<?= htmlspecialchars($debug_result['password_stored']) ?>'</code></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Plain Text Match:</strong></td>
                                        <td>
                                            <span class="badge bg-<?= $debug_result['password_match_plain'] ? 'success' : 'danger' ?>">
                                                <?= $debug_result['password_match_plain'] ? 'YES' : 'NO' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Hash Verify Match:</strong></td>
                                        <td>
                                            <span class="badge bg-<?= $debug_result['password_match_verify'] ? 'success' : 'danger' ?>">
                                                <?= $debug_result['password_match_verify'] ? 'YES' : 'NO' ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                                
                                <?php if ($debug_result['password_match_plain'] || $debug_result['password_match_verify']): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check"></i> Password should work! Try the actual login.
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-danger">
                                        <i class="fas fa-times"></i> Password mismatch detected!
                                    </div>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <strong>User Not Found:</strong> <?= htmlspecialchars($debug_result['username_searched']) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted">
                                <i class="fas fa-info-circle"></i> Enter credentials above to test
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- All Users Reference -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-database"></i> All Users in Database</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Role</th>
                                        <th>Password Hash</th>
                                        <th>Active</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_users as $user): ?>
                                    <tr>
                                        <td><?= $user['user_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'editor' ? 'warning' : 'info') ?>">
                                                <?= ucfirst($user['role']) ?>
                                            </span>
                                        </td>
                                        <td><code><?= htmlspecialchars($user['password_hash']) ?></code></td>
                                        <td>
                                            <span class="badge bg-<?= $user['is_active'] ? 'success' : 'secondary' ?>">
                                                <?= $user['is_active'] ? 'Yes' : 'No' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="login.php" class="btn btn-success me-2">
                    <i class="fas fa-sign-in-alt"></i> Go to Login Page
                </a>
                <a href="test_users.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-users"></i> Manage Users
                </a>
                <a href="test_db.php" class="btn btn-outline-secondary">
                    <i class="fas fa-database"></i> DB Test
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testUser(username, password) {
            document.getElementById('username').value = username;
            document.getElementById('password').value = password;
            document.querySelector('form').submit();
        }
    </script>
</body>
</html>