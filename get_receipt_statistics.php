<?php
// api/get_receipt_statistics.php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    
    // รับเข้าวันนี้
    $query_today = "SELECT COUNT(*) as count FROM Goods_Receipt 
                    WHERE CAST(receipt_date AS DATE) = ?";
    $stmt = $db->prepare($query_today);
    $stmt->execute([$today]);
    $today_receipts = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // รอตรวจสอบ
    $query_pending = "SELECT COUNT(*) as count FROM Goods_Receipt 
                      WHERE status = 'pending'";
    $stmt = $db->prepare($query_pending);
    $stmt->execute();
    $pending_receipts = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // อนุมัติแล้ว
    $query_approved = "SELECT COUNT(*) as count FROM Goods_Receipt 
                       WHERE status = 'approved'";
    $stmt = $db->prepare($query_approved);
    $stmt->execute();
    $approved_receipts = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // มูลค่ารวมเดือนนี้
    $query_month_value = "SELECT ISNULL(SUM(total_amount), 0) as total 
                          FROM Goods_Receipt 
                          WHERE receipt_date >= ? AND receipt_date <= ?";
    $stmt = $db->prepare($query_month_value);
    $stmt->execute([$month_start, $month_end]);
    $month_value = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'today_receipts' => $today_receipts,
            'pending_receipts' => $pending_receipts,
            'approved_receipts' => $approved_receipts,
            'month_value' => $month_value
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Receipt Statistics Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>