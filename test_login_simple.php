<?php
// test_login_simple.php - ทดสอบ login แบบง่าย
session_start();
require_once 'config/database.php';
require_once 'classes/Auth.php';

echo "<h1>Simple Login Test</h1>";

// Clear session
session_unset();

// Test users
$test_users = [
    ['admin', 'admin123'],
    ['user1', 'user123'],
    ['user2', 'user123']
];

$auth = new Auth();

// Test connection first
echo "<h2>1. Connection Test:</h2>";
$conn_test = $auth->testConnection();
if ($conn_test['status']) {
    echo "<p style='color: green;'>✓ {$conn_test['message']} ({$conn_test['user_count']} users)</p>";
} else {
    echo "<p style='color: red;'>✗ {$conn_test['message']}</p>";
    exit;
}

// Test each user
echo "<h2>2. Login Tests:</h2>";
foreach ($test_users as $i => list($username, $password)) {
    echo "<h3>Test " . ($i + 1) . ": $username / $password</h3>";
    
    // Clear session before each test
    session_unset();
    
    // Test login
    $login_result = $auth->login($username, $password);
    
    if ($login_result) {
        echo "<p style='color: green; font-weight: bold;'>✓ LOGIN SUCCESS!</p>";
        echo "<p>Session data:</p>";
        echo "<ul>";
        echo "<li>User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "</li>";
        echo "<li>Username: " . ($_SESSION['username'] ?? 'Not set') . "</li>";
        echo "<li>Full Name: " . ($_SESSION['full_name'] ?? 'Not set') . "</li>";
        echo "<li>Role: " . ($_SESSION['role'] ?? 'Not set') . "</li>";
        echo "</ul>";
        
        // Test if logged in
        if ($auth->isLoggedIn()) {
            echo "<p style='color: green;'>✓ isLoggedIn() returns TRUE</p>";
        } else {
            echo "<p style='color: red;'>✗ isLoggedIn() returns FALSE</p>";
        }
        
    } else {
        echo "<p style='color: red; font-weight: bold;'>✗ LOGIN FAILED</p>";
        
        // Debug why it failed
        $debug_result = $auth->testLogin($username, $password);
        if (isset($debug_result['error'])) {
            echo "<p style='color: red;'>Error: {$debug_result['error']}</p>";
        } elseif (!$debug_result['user_found']) {
            echo "<p style='color: red;'>User not found in table: {$debug_result['table_used']}</p>";
        } else {
            echo "<p>User found but password mismatch:</p>";
            echo "<ul>";
            echo "<li>Input: '{$debug_result['password_input']}'</li>";
            echo "<li>Stored: '{$debug_result['password_stored']}'</li>";
            echo "<li>Plain match: " . ($debug_result['password_match_plain'] ? 'YES' : 'NO') . "</li>";
            echo "<li>Is active: " . ($debug_result['is_active'] ? 'YES' : 'NO') . "</li>";
            echo "</ul>";
        }
    }
    
    echo "<hr>";
}

echo "<h2>3. Manual Database Check:</h2>";
try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $stmt = $conn->prepare("SELECT username, password_hash, is_active FROM Users WHERE username IN ('admin', 'user1', 'user2')");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Username</th><th>Password Hash</th><th>Is Active</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['password_hash']}</td>";
        echo "<td>" . ($user['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Database error: " . $e->getMessage() . "</p>";
}

echo "<h2>Next Steps:</h2>";
echo "<p><a href='login.php'>Try Real Login Page</a> | <a href='debug_login.php'>Debug Login Page</a></p>";
?>