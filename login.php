<?php
// login.php (วางไว้ใน root directory)
require_once 'config/config.php';
require_once 'classes/Auth.php';

$auth = new Auth();
$error_message = '';
$success_message = '';

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header("Location: pages/dashboard.php");
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        if ($auth->login($username, $password)) {
            header("Location: pages/dashboard.php");
            exit();
        } else {
            $error_message = 'Invalid username or password.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Login</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
body {
    background: linear-gradient(135deg, #ff9a56 0%, #ffb347 50%, #ffd700 100%);
    margin: 0;
    padding: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.container {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
}

.login-container {
    background: white;
    border-radius: 15px;
    box-shadow: 0 20px 40px rgba(255, 154, 86, 0.3);
    max-width: 400px;
    width: 100%;
    padding: 40px;
    margin: auto;
    border: 2px solid #ffe4d1;
}
        
.btn-primary {
    background: linear-gradient(45deg, #ff9a56 0%, #ff7f50 100%);
    border: none;
    padding: 12px 30px;
    border-radius: 25px;
    box-shadow: 0 4px 15px rgba(255, 154, 86, 0.3);
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: linear-gradient(45deg, #ff7f50 0%, #ff6347 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(255, 154, 86, 0.4);
}

.form-control {
    border-radius: 25px;
    padding: 12px 20px;
    border: 2px solid #ffe4d1;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #ff9a56;
    box-shadow: 0 0 0 0.2rem rgba(255, 154, 86, 0.25);
}

.login-header {
    text-align: center;
    margin-bottom: 30px;
}

.login-header h2 {
    color: #ff7f50;
    margin-bottom: 10px;
}

.login-header p {
    color: #ff9a56;
}

.text-primary {
    color: #ff7f50 !important;
}

.form-label {
    color: #ff7f50;
    font-weight: 600;
}

.form-check-label {
    color: #ff9a56;
}

.alert-danger {
    background-color: #fff2f0;
    border-color: #ffcccb;
    color: #cc4125;
}

.alert-success {
    background-color: #f0fff4;
    border-color: #d4edda;
    color: #155724;
}

.bg-light {
    background-color: #fff8f5 !important;
    border: 1px solid #ffe4d1;
}

.text-muted {
    color: #b8860b !important;
}

hr {
    border-color: #ffe4d1;
}

/* เพิ่มเอฟเฟกต์ hover สำหรับ input */
.form-control:hover {
    border-color: #ffb347;
}

/* ปรับสีของไอคอน */
.fas {
    color: #ff9a56;
}

/* เพิ่มเอฟเฟกต์กระพริบเบาๆ สำหรับ login container */
.login-container {
    animation: subtle-glow 3s ease-in-out infinite alternate;
}

@keyframes subtle-glow {
    from {
        box-shadow: 0 20px 40px rgba(255, 154, 86, 0.3);
    }
    to {
        box-shadow: 0 20px 40px rgba(255, 154, 86, 0.2);
    }
}

/* ปรับสีของ checkbox */
.form-check-input:checked {
    background-color: #ff9a56;
    border-color: #ff9a56;
}

.form-check-input:focus {
    box-shadow: 0 0 0 0.25rem rgba(255, 154, 86, 0.25);
}
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-header">
                <h2><i class="fas fa-boxes text-primary"></i> <?= APP_NAME ?></h2>
                <p class="text-muted">Please sign in to your account</p>
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?= $error_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="username" 
                           value="<?= htmlspecialchars($username ?? '') ?>"
                           placeholder="Enter your username"
                           required>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="Enter your password"
                           required>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="remember_me">
                    <label class="form-check-label" for="remember_me">
                        Remember me
                    </label>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </div>
            </form>
            
            <hr class="my-4">
            
            <!-- Demo Credentials -->
            <div class="bg-light p-3 rounded">
                <small class="text-muted">
                    <strong>Demo Credentials:</strong><br>
                    <strong>Admin:</strong> admin / admin123<br>
                    <strong>Editor:</strong> user1 / user123<br>
                    <strong>Viewer:</strong> user2 / user123
                </small>
            </div>
            
            <div class="text-center mt-3">
                <small class="text-muted">
                    © <?= date('Y') ?> <?= APP_NAME ?>. Version <?= APP_VERSION ?>
                </small>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-focus on username field
        document.getElementById('username').focus();
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>