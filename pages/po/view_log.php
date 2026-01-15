<?php
// view_log.php - สำหรับดู error log
header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/php_errors.log';

if (file_exists($logFile)) {
    echo "=== PHP ERROR LOG ===\n";
    echo "ไฟล์: $logFile\n";
    echo "ขนาด: " . number_format(filesize($logFile)) . " bytes\n";
    echo "แก้ไขล่าสุด: " . date('Y-m-d H:i:s', filemtime($logFile)) . "\n";
    echo str_repeat("=", 50) . "\n\n";
    
    // อ่าน 100 บรรทัดสุดท้าย
    $lines = file($logFile);
    $totalLines = count($lines);
    $showLines = min(100, $totalLines);
    
    echo "แสดง $showLines บรรทัดสุดท้าย (จากทั้งหมด $totalLines บรรทัด):\n\n";
    
    for ($i = $totalLines - $showLines; $i < $totalLines; $i++) {
        echo ($i + 1) . ": " . $lines[$i];
    }
} else {
    echo "ไม่พบไฟล์ php_errors.log\n";
    echo "ตำแหน่งที่มองหา: $logFile\n\n";
    
    // ลองหาไฟล์ log อื่น
    $possibleLogs = [
        __DIR__ . '/error.log',
        __DIR__ . '/errors.log',
        ini_get('error_log')
    ];
    
    echo "ตำแหน่งอื่นที่อาจจะมี log:\n";
    foreach ($possibleLogs as $log) {
        if ($log && file_exists($log)) {
            echo "✓ พบ: $log\n";
        } else {
            echo "✗ ไม่พบ: $log\n";
        }
    }
}
?>