<?php
// production/debug_session.php - Session Debugging Tool
require_once 'config/config.php';
require_once 'classes/Auth.php';

$auth = new Auth();

// ถ้าต้องการล้าง session
if (isset($_GET['clear'])) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['environment'] = 'production';
    echo "<div style='color: green;'>✓ Session cleared for production environment!</div><br>";
}

// ถ้าต้องการล้าง session ของ environment อื่น
if (isset($_GET['clear_other'])) {
    $auth->clearOtherEnvironmentSessions();
    echo "<div style='color: green;'>✓ Other environment sessions cleared!</div><br>";
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Production Session Debug</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .info { background: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .warning { background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .error { background: #f8d7da; padding: 10px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .success { background: #d4edda; padding: 10px; margin: 10px 0; border-left: 4px solid #28a745; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .action-links { margin: 20px 0; }
        .action-links a { margin-right: 15px; padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 3px; }
        .action-links a:hover { background: #0056b3; }
        .action-links a.danger { background: #dc3545; }
        .action-links a.danger:hover { background: #c82333; }
    </style>
</head>
<body>
    <h1>Production Environment Session Debug</h1>
    
    <div class="action-links">
        <a href="debug_session.php">Refresh</a>
        <a href="debug_session.php?clear=1" class="danger">Clear Production Session</a>
        <a href="debug_session.php?clear_other=1" class="danger">Clear Other Environment Sessions</a>
        <a href="login.php">Go to Login</a>
        <a href="../login.php" target="_blank">Open Main Environment Login</a>
    </div>

    <?php
    // แสดงข้อมูล session
    echo "<div class='info'>";
    echo "<h3>📊 Current Session Information:</h3>";
    echo "<strong>Session ID:</strong> " . session_id() . "<br>";
    echo "<strong>Session Name:</strong> " . session_name() . "<br>";
    echo "<strong>Cookie Path:</strong> " . ini_get('session.cookie_path') . "<br>";
    echo "<strong>Environment:</strong> " . (defined('ENVIRONMENT') ? ENVIRONMENT : 'undefined') . "<br>";
    echo "<strong>Logged In:</strong> " . ($auth->isLoggedIn() ? 'Yes ✓' : 'No ✗') . "<br>";
    echo "</div>";

    // แสดงข้อมูลจาก Auth class
    $sessionInfo = $auth->getSessionInfo();
    echo "<div class='info'>";
    echo "<h3>🔐 Auth Class Information:</h3>";
    echo "<table>";
    foreach ($sessionInfo as $key => $value) {
        echo "<tr><th>" . ucfirst(str_replace('_', ' ', $key)) . "</th><td>" . 
             (is_null($value) ? '<em>null</em>' : 
              (is_bool($value) ? ($value ? 'true' : 'false') : 
               htmlspecialchars($value))) . "</td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // แสดงข้อมูล $_SESSION ทั้งหมด
    echo "<div class='info'>";
    echo "<h3>📋 Full Session Data:</h3>";
    if (empty($_SESSION)) {
        echo "<em>Session is empty</em>";
    } else {
        echo "<table>";
        foreach ($_SESSION as $key => $value) {
            echo "<tr><th>$key</th><td>" . 
                 (is_array($value) ? print_r($value, true) : htmlspecialchars($value)) . 
                 "</td></tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    // แสดงข้อมูล Server
    echo "<div class='info'>";
    echo "<h3>🖥️ Server Information:</h3>";
    echo "<strong>Server IP:</strong> " . $_SERVER['SERVER_ADDR'] . "<br>";
    echo "<strong>Client IP:</strong> " . $_SERVER['REMOTE_ADDR'] . "<br>";
    echo "<strong>Request URI:</strong> " . $_SERVER['REQUEST_URI'] . "<br>";
    echo "<strong>HTTP Host:</strong> " . $_SERVER['HTTP_HOST'] . "<br>";
    echo "</div>";

    // ทดสอบการเชื่อมต่อฐานข้อมูล
    $connectionTest = $auth->testConnection();
    echo "<div class='" . ($connectionTest['status'] ? 'success' : 'error') . "'>";
    echo "<h3>🗄️ Database Connection Test:</h3>";
    echo "<table>";
    foreach ($connectionTest as $key => $value) {
        echo "<tr><th>" . ucfirst(str_replace('_', ' ', $key)) . "</th><td>" . 
             (is_bool($value) ? ($value ? 'true' : 'false') : htmlspecialchars($value)) . 
             "</td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // ตรวจสอบการทำงานของ session environment
    echo "<div class='warning'>";
    echo "<h3>⚠️ Environment Check:</h3>";
    
    if (!isset($_SESSION['environment'])) {
        echo "❌ No environment set in session<br>";
    } elseif ($_SESSION['environment'] !== 'production') {
        echo "❌ Session environment mismatch: " . $_SESSION['environment'] . " (expected: production)<br>";
    } else {
        echo "✅ Session environment correctly set to: " . $_SESSION['environment'] . "<br>";
    }
    
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
        echo "✅ Config environment correctly set to: " . ENVIRONMENT . "<br>";
    } else {
        echo "❌ Config environment issue: " . (defined('ENVIRONMENT') ? ENVIRONMENT : 'undefined') . "<br>";
    }
    echo "</div>";
    
    ?>

    <div class="info">
        <h3>📝 Instructions:</h3>
        <ul>
            <li><strong>If sessions are shared:</strong> Click "Clear Other Environment Sessions"</li>
            <li><strong>If login doesn't work:</strong> Click "Clear Production Session" and try login again</li>
            <li><strong>To test separation:</strong> Login in main environment, then check this page</li>
            <li><strong>Expected behavior:</strong> Login in main should NOT affect production environment</li>
        </ul>
    </div>

</body>
</html>