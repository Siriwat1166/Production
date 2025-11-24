<?php
// cleanup_project.php - ทำความสะอาดไฟล์ที่ไม่จำเป็น
echo "<h1>🧹 Project Cleanup</h1>";

// รายการไฟล์ที่จะลบ
$files_to_delete = [
    // Debug และ Testing Files
    'check_tables.php',
    'create_working_dashboard.php', 
    'debug_detailed.php',
    'debug_table_access.php',
    'fix_auth_syntax.php',
    'fix_auth_table.php',
    'fix_dashboard_paths.php',
    'fix_login_redirect.php',
    'replace_auth.php',
    'test_db.php',
    'test_login_fixed.php',
    'test_users.php',
    'update_passwords.php',
    'create_dashboard_in_pages.php',
    'fix_auth_table_script.php',
    
    // Optional: เก็บไว้สำหรับ debug
    // 'debug_login.php',
    // 'test_login_simple.php',
];

// รายการ pattern สำหรับไฟล์ backup
$backup_patterns = [
    'classes/Auth.php.backup.*',
    'classes/Auth.php.error.backup.*', 
    'classes/Auth.php.redirect.backup.*',
    'pages/dashboard.php.*.backup.*',
    'login.php.redirect.backup.*'
];

echo "<h2>Files to Delete:</h2>";

$deleted_count = 0;
$failed_count = 0;

// ลบไฟล์ทีละไฟล์
foreach ($files_to_delete as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            echo "<p style='color: green;'>✓ Deleted: $file</p>";
            $deleted_count++;
        } else {
            echo "<p style='color: red;'>✗ Failed to delete: $file</p>";
            $failed_count++;
        }
    } else {
        echo "<p style='color: gray;'>⚬ Not found: $file</p>";
    }
}

// ลบไฟล์ backup โดยใช้ glob pattern
echo "<h2>Backup Files:</h2>";

foreach ($backup_patterns as $pattern) {
    $backup_files = glob($pattern);
    if ($backup_files) {
        foreach ($backup_files as $backup_file) {
            if (unlink($backup_file)) {
                echo "<p style='color: green;'>✓ Deleted backup: $backup_file</p>";
                $deleted_count++;
            } else {
                echo "<p style='color: red;'>✗ Failed to delete backup: $backup_file</p>";
                $failed_count++;
            }
        }
    } else {
        echo "<p style='color: gray;'>⚬ No backup files found for pattern: $pattern</p>";
    }
}

// แสดงสรุป
echo "<h2>📊 Cleanup Summary:</h2>";
echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff;'>";
echo "<p><strong>✅ Files deleted:</strong> $deleted_count</p>";
echo "<p><strong>❌ Failed to delete:</strong> $failed_count</p>";
echo "</div>";

// แสดงไฟล์ที่เหลือ
echo "<h2>📁 Remaining Core Files:</h2>";

$core_files = [
    'classes/Auth.php' => 'Authentication class',
    'config/config.php' => 'Application configuration', 
    'config/database.php' => 'Database configuration',
    'pages/dashboard.php' => 'Main dashboard',
    'login.php' => 'Login page',
    'logout.php' => 'Logout script',
    'debug_login.php' => 'Debug tool (optional)',
    'test_login_simple.php' => 'Testing tool (optional)'
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f5f5f5;'><th>File</th><th>Purpose</th><th>Status</th></tr>";

foreach ($core_files as $file => $purpose) {
    $exists = file_exists($file);
    $status_color = $exists ? 'green' : 'red';
    $status_text = $exists ? '✓ Exists' : '✗ Missing';
    
    echo "<tr>";
    echo "<td><strong>$file</strong></td>";
    echo "<td>$purpose</td>";
    echo "<td style='color: $status_color;'>$status_text</td>";
    echo "</tr>";
}
echo "</table>";

// ตรวจสอบ folder structure
echo "<h2>📂 Project Structure:</h2>";

$folders = [
    'classes/' => 'PHP Classes',
    'config/' => 'Configuration Files', 
    'pages/' => 'Application Pages',
    'pages/materials/' => 'Material Management (future)',
    'pages/users/' => 'User Management (future)',
    'pages/reports/' => 'Reports (future)',
    'pages/suppliers/' => 'Supplier Management (future)'
];

foreach ($folders as $folder => $description) {
    $exists = is_dir($folder);
    $status_color = $exists ? 'green' : 'orange';
    $status_text = $exists ? '✓ Exists' : '⚬ Can be created';
    
    echo "<p><strong>$folder</strong> - $description <span style='color: $status_color;'>$status_text</span></p>";
}

// คำแนะนำสำหรับการพัฒนาต่อ
echo "<h2>🚀 Next Development Steps:</h2>";
echo "<div style='background: #f0fff0; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745;'>";
echo "<ol>";
echo "<li><strong>Materials Management:</strong> Create pages/materials/list.php, add.php</li>";
echo "<li><strong>User Management:</strong> Create pages/users/ for admin panel</li>";
echo "<li><strong>Reports:</strong> Create pages/reports/ for data visualization</li>";
echo "<li><strong>Suppliers:</strong> Create pages/suppliers/ for supplier management</li>";
echo "<li><strong>API:</strong> Develop REST API in api/ folder</li>";
echo "</ol>";
echo "</div>";

// Optional: Delete this cleanup script itself
echo "<h2>🗑️ Final Cleanup:</h2>";
echo "<p>Do you want to delete this cleanup script itself?</p>";
echo "<p><a href='?delete_self=1' style='background: red; color: white; padding: 10px; text-decoration: none; border-radius: 5px;'>Delete cleanup_project.php</a></p>";

if (isset($_GET['delete_self']) && $_GET['delete_self'] == '1') {
    if (unlink(__FILE__)) {
        echo "<p style='color: green;'>✓ Cleanup script deleted successfully!</p>";
        echo "<script>setTimeout(function(){ window.location.href = 'login.php'; }, 2000);</script>";
    } else {
        echo "<p style='color: red;'>✗ Failed to delete cleanup script</p>";
    }
}

echo "<hr>";
echo "<p><a href='login.php' style='background: blue; color: white; padding: 15px; text-decoration: none; border-radius: 5px;'>🏠 Go to Login Page</a></p>";
echo "<p><a href='pages/dashboard.php' style='background: green; color: white; padding: 15px; text-decoration: none; border-radius: 5px;'>📊 Go to Dashboard</a></p>";
?>