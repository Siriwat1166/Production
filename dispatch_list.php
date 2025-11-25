<?php
// dispatch_list.php - รายการจ่ายออกสินค้า
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'editor', 'viewer']);

try {
    $pdo = new PDO(
        "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME,
        DB_USERNAME,
        DB_PASSWORD,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
        )
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ===== AJAX HANDLERS =====
if (isset($_GET['ajax']) || isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    try {
        $action = $_GET['ajax'] ?? $_POST['action'] ?? '';

        switch ($action) {
            case 'get_dispatch_list':
                $dateFrom = $_GET['date_from'] ?? '';
                $dateTo = $_GET['date_to'] ?? '';
                $warehouseId = $_GET['warehouse_id'] ?? '';
                $productCode = $_GET['product_code'] ?? '';
                $mpr = $_GET['mpr'] ?? '';

                $sql = "
                    SELECT 
                        sm.movement_id,
                        sm.movement_date,
                        mp.SSP_Code as product_code,
                        mp.Name as product_name,
                        w.warehouse_name,
                        wl.location_code,
                        sm.quantity,
                        sm.quantity_pallet,
                        sm.quantity_kg,
                        sm.batch_lot as lot_supplier,
                        sm.notes,
                        sm.destination_location,
                        sm.paperboard_type,
                        sm.machine_code,
                        u.unit_symbol,
                        ISNULL(usr.username, 'User ID: ' + CAST(sm.created_by AS VARCHAR(10))) as created_by_name,
                        sm.movement_date as created_at
                    FROM Stock_Movements sm
                    JOIN Master_Products_ID mp ON sm.product_id = mp.id
                    JOIN Warehouses w ON sm.warehouse_id = w.warehouse_id
                    LEFT JOIN Warehouse_Locations wl ON sm.location_id = wl.location_id
                    LEFT JOIN Units u ON sm.unit_id = u.unit_id
                    LEFT JOIN dbo.Users usr ON sm.created_by = usr.user_id
                    WHERE sm.reference_type = 'DISPATCH'
                ";

                $params = [];

                if ($dateFrom) {
                    $sql .= " AND CAST(sm.movement_date AS DATE) >= ?";
                    $params[] = $dateFrom;
                }

                if ($dateTo) {
                    $sql .= " AND CAST(sm.movement_date AS DATE) <= ?";
                    $params[] = $dateTo;
                }

                if ($warehouseId) {
                    $sql .= " AND sm.warehouse_id = ?";
                    $params[] = $warehouseId;
                }

                if ($productCode) {
                    $sql .= " AND mp.SSP_Code LIKE ?";
                    $params[] = '%' . $productCode . '%';
                }

                if ($mpr) {
                    // ค้นหาจากคอลัมน์ใหม่ก่อน, ถ้าไม่มีค่อยค้นจาก notes
                    $sql .= " AND (sm.notes LIKE ? OR sm.notes LIKE ?)";
                    $params[] = '%MPR: ' . $mpr . '%';
                    $params[] = '%' . $mpr . '%';
                }

                $sql .= " ORDER BY sm.movement_date DESC";

                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $results = $stmt->fetchAll();
                    
                    echo json_encode(['success' => true, 'data' => $results]);
                } catch (Exception $e) {
                    error_log("Query Error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_dispatch_detail':
                $movementId = (int)($_GET['movement_id'] ?? 0);
                
                if ($movementId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        sm.movement_id,
                        sm.product_id,
                        sm.warehouse_id,
                        sm.location_id,
                        sm.movement_date,
                        sm.quantity,
                        sm.quantity_pallet,
                        sm.quantity_kg,
                        sm.batch_lot,
                        sm.unit_id,
                        sm.notes,
                        sm.destination_location,
                        sm.paperboard_type,
                        sm.machine_code,
                        mp.SSP_Code,
                        mp.Name as product_name,
                        w.warehouse_name,
                        wl.location_code,
                        u.unit_symbol,
                        ISNULL(usr.username, 'User ID: ' + CAST(sm.created_by AS VARCHAR(10))) as created_by_name,
                        sm.movement_date as created_at
                    FROM Stock_Movements sm
                    JOIN Master_Products_ID mp ON sm.product_id = mp.id
                    JOIN Warehouses w ON sm.warehouse_id = w.warehouse_id
                    LEFT JOIN Warehouse_Locations wl ON sm.location_id = wl.location_id
                    LEFT JOIN Units u ON sm.unit_id = u.unit_id
                    LEFT JOIN dbo.Users usr ON sm.created_by = usr.user_id
                    WHERE sm.movement_id = ? AND sm.reference_type = 'DISPATCH'
                ");
                $stmt->execute([$movementId]);
                $detail = $stmt->fetch();

                if ($detail) {
                    // Parse notes (สำหรับข้อมูลเก่าที่ยังไม่มีคอลัมน์ใหม่)
                    $notes = $detail['notes'];
                    preg_match('/MPR: ([^|]+)/', $notes, $mprMatch);
                    preg_match('/Lot MPR: ([^|]+)/', $notes, $lotMprMatch);
                    preg_match('/Req: ([^|]+)/', $notes, $reqMatch);
                    preg_match('/To: ([^|]+)/', $notes, $toMatch);
                    preg_match('/Type: ([^|]+)/', $notes, $typeMatch);
                    preg_match('/Machine: ([^|]+)/', $notes, $machineMatch);

                    // ใช้ค่าจากคอลัมน์ใหม่ก่อน, ถ้าไม่มีค่อย parse จาก notes
                    $detail['mpr'] = trim($mprMatch[1] ?? '');
                    $detail['lot_mpr'] = trim($lotMprMatch[1] ?? '');
                    $detail['requisition'] = trim($reqMatch[1] ?? '');
                    $detail['destination'] = $detail['destination_location'] ?: trim($toMatch[1] ?? '');
                    $detail['paperboard_type'] = $detail['paperboard_type'] ?: trim($typeMatch[1] ?? '');
                    $detail['machine'] = $detail['machine_code'] ?: trim($machineMatch[1] ?? '');

                    echo json_encode(['success' => true, 'data' => $detail]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
                }
                break;

            case 'cancel_dispatch':
                $body = file_get_contents('php://input');
                $data = json_decode($body, true);
                $movementId = (int)($data['movement_id'] ?? 0);
                $reason = $data['reason'] ?? 'ยกเลิกโดยผู้ใช้';

                if ($movementId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    // ดึงข้อมูล dispatch
                    $stmt = $pdo->prepare("
                        SELECT product_id, warehouse_id, location_id, quantity, quantity_pallet
                        FROM Stock_Movements
                        WHERE movement_id = ? AND reference_type = 'DISPATCH' AND movement_type = 'OUT'
                    ");
                    $stmt->execute([$movementId]);
                    $dispatch = $stmt->fetch();

                    if (!$dispatch) {
                        throw new Exception('ไม่พบรายการจ่ายออก');
                    }

                    // คืนสต็อก
                    $stmt = $pdo->prepare("
                        UPDATE Inventory_Stock
                        SET current_stock = current_stock + ?,
                            available_stock = available_stock + ?,
                            current_pallet = current_pallet + ?,
                            available_pallet = available_pallet + ?,
                            last_updated = GETDATE()
                        WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                    ");
                    $stmt->execute([
                        $dispatch['quantity'],
                        $dispatch['quantity'],
                        $dispatch['quantity_pallet'] ?? 0,
                        $dispatch['quantity_pallet'] ?? 0,
                        $dispatch['product_id'],
                        $dispatch['warehouse_id'],
                        $dispatch['location_id']
                    ]);

                    // ถ้าไม่มี record ให้สร้างใหม่
                    if ($stmt->rowCount() === 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO Inventory_Stock (
                                product_id, warehouse_id, location_id,
                                current_stock, available_stock,
                                current_pallet, available_pallet,
                                last_updated, last_movement_date
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
                        ");
                        $stmt->execute([
                            $dispatch['product_id'],
                            $dispatch['warehouse_id'],
                            $dispatch['location_id'],
                            $dispatch['quantity'],
                            $dispatch['quantity'],
                            $dispatch['quantity_pallet'] ?? 0,
                            $dispatch['quantity_pallet'] ?? 0
                        ]);
                    }

                    // บันทึก Movement IN (คืนสต็อก)
                    $userId = $_SESSION['user_id'] ?? 1;
                    $stmt = $pdo->prepare("
                        INSERT INTO Stock_Movements (
                            product_id, warehouse_id, location_id,
                            movement_type, quantity, quantity_pallet,
                            reference_type, movement_date, created_by, notes
                        ) VALUES (?, ?, ?, 'IN', ?, ?, 'CANCEL_DISPATCH', GETDATE(), ?, ?)
                    ");
                    $stmt->execute([
                        $dispatch['product_id'],
                        $dispatch['warehouse_id'],
                        $dispatch['location_id'],
                        $dispatch['quantity'],
                        $dispatch['quantity_pallet'] ?? 0,
                        $userId,
                        'ยกเลิกรายการจ่ายออก ID: ' . $movementId . ' | เหตุผล: ' . $reason
                    ]);

                    // อัปเดตสถานะ movement เดิม (เพิ่ม flag ว่ายกเลิกแล้ว)
                    $stmt = $pdo->prepare("
                        UPDATE Stock_Movements
                        SET notes = notes + ' | [CANCELLED: ' + ? + ']'
                        WHERE movement_id = ?
                    ");
                    $stmt->execute([$reason, $movementId]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'ยกเลิกรายการสำเร็จและคืนสต็อกแล้ว']);

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Cancel dispatch error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            default:
                echo json_encode(['error' => 'Unknown action']);
        }

    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    ob_end_flush();
    exit;
}

// Load master data
$warehouses = $pdo->query("
    SELECT warehouse_id, warehouse_name 
    FROM Warehouses 
    WHERE is_active = 1 OR is_active IS NULL 
    ORDER BY warehouse_name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการจ่ายออกสินค้า</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
    min-height: 100vh;
    color: #8B4513;
}

.container-fluid {
    max-width: 98%;
    padding: 0 2rem;
}

.header-section {
    background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
    color: white;
    padding: 1.5rem 0;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(139, 69, 19, 0.4);
    border-bottom: 3px solid #FF8C00;
}

.header-section .container-fluid {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-section h5 {
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
    color: white;
}

.header-section small {
    font-size: 0.85rem;
    opacity: 0.9;
}

.btn-back-arrow {
    color: #FFE5CC !important;
    text-decoration: none;
    font-size: 1.5rem;
    padding: 0.5rem;
    margin-right: 1rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.4);
}

.btn-back-arrow:hover {
    transform: translateX(-3px);
    color: white !important;
}

.btn-back-arrow i {
    color: #FFE5CC !important;
}

.btn-back-arrow:hover i {
    color: white !important;
}

.btn-header {
    background: linear-gradient(135deg, #FF8C00, #FFA500);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s;
    box-shadow: 0 2px 8px rgba(255, 140, 0, 0.3);
    display: inline-block;
    white-space: nowrap;
}

.btn-header:hover {
    background: linear-gradient(135deg, #FFA500, #FF8C00);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 140, 0, 0.4);
}

.text-light {
    color: rgba(255, 255, 255, 0.9) !important;
}

.d-flex {
    display: flex;
}

.align-items-center {
    align-items: center;
}

.justify-content-between {
    justify-content: space-between;
}

.w-100 {
    width: 100%;
}

.mb-0 {
    margin-bottom: 0;
}

.me-2 {
    margin-right: 0.5rem;
}

.gap-2 {
    gap: 0.5rem;
}
        .btn-back {
            background: linear-gradient(135deg, #D2691E 0%, #CD853F 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(210, 105, 30, 0.3);
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #CD853F 0%, #DEB887 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(210, 105, 30, 0.4);
        }

.filter-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
    margin-bottom: 1.5rem;
    border: 2px solid rgba(139, 69, 19, 0.2);
    width: 100%;
}

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            color: #8B4513;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 2px solid #DEB887;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            background: #FAFAF8;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8B4513;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.1);
        }

        .btn-filter {
            background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(139, 69, 19, 0.3);
        }

        .btn-filter:hover {
            background: linear-gradient(135deg, #A0522D 0%, #8B4513 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 69, 19, 0.4);
        }

        .btn-clear {
            background: linear-gradient(135deg, #CD853F 0%, #DEB887 100%);
            color: #5D3A1A;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(205, 133, 63, 0.3);
        }

        .btn-clear:hover {
            background: linear-gradient(135deg, #DEB887 0%, #F5DEB3 100%);
            transform: translateY(-2px);
        }

.table-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
    border: 2px solid rgba(139, 69, 19, 0.2);
    width: 100%;
    overflow-x: auto;
}

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1400px;
        }

        th {
    background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
    color: white;
    padding: 12px 8px;
    text-align: center;
    font-weight: 600;
    font-size: 13px;
    white-space: nowrap;
    border: 1px solid #6d4c41;
}

td {
    padding: 10px 8px;
    vertical-align: middle;
    border-bottom: 1px solid rgba(139, 69, 19, 0.1);
    font-size: 13px;
    color: #3e2723;
}

tr:hover td {
    background-color: rgba(255, 140, 0, 0.1);
}

tr:nth-child(even) td {
    background-color: rgba(245, 222, 179, 0.3);
}

tr:nth-child(even):hover td {
    background-color: rgba(255, 140, 0, 0.1);
}
.form-control, .form-select {
    background: white;
    border: 2px solid rgba(139, 69, 19, 0.2);
    color: #3e2723;
    border-radius: 10px;
    padding: 0.5rem 1rem;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    background: #fffbf5;
    border-color: #8B4513;
    color: #3e2723;
    box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15);
}

.form-control::placeholder {
    color: #a1887f;
}

.form-label {
    color: #6d4c41;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}
        .btn-action {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .btn-view {
            background: linear-gradient(135deg, #4A90E2 0%, #5BA3F5 100%);
            color: white;
        }

        .btn-view:hover {
            background: linear-gradient(135deg, #5BA3F5 0%, #4A90E2 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(74, 144, 226, 0.4);
        }

        .btn-cancel {
            background: linear-gradient(135deg, #E53E3E 0%, #C53030 100%);
            color: white;
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #C53030 0%, #9B2C2C 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(229, 62, 62, 0.4);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(139, 69, 19, 0.5);
            animation: fadeIn 0.3s;
            overflow-y: auto;
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 1000px;
            margin: 50px auto;
            animation: slideDown 0.3s;
            box-shadow: 0 10px 40px rgba(139, 69, 19, 0.3);
            border-top: 6px solid #8B4513;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .modal-header h2 {
            font-size: 22px;
            font-weight: 600;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .close-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            line-height: 1;
            padding: 0;
        }

        .close-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .detail-section {
            margin-bottom: 25px;
        }

        .detail-section h3 {
            color: #8B4513;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #DEB887;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .detail-item {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .detail-item label {
            color: #A0522D;
            font-weight: 600;
            min-width: 150px;
            font-size: 14px;
        }

        .detail-item .value {
            color: #5D3A1A;
            font-weight: 600;
            font-size: 14px;
            flex: 1;
        }

        .detail-label {
            color: #A0522D;
            font-weight: 600;
            min-width: 140px;
        }

        .detail-value {
            color: #5D3A1A;
            font-weight: 600;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #A0522D;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #CD853F;
        }

        .alert-cancelled {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            padding: 15px 20px;
            border-radius: 8px;
            text-align: center;
            margin-top: 20px;
            font-weight: 600;
            border-left: 4px solid #ef4444;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
        }

        .notes-box {
            margin-top: 15px;
            padding: 10px;
            background: #FFF8F0;
            border-radius: 6px;
            border-left: 4px solid #D2691E;
            color: #5D3A1A;
            line-height: 1.6;
        }

        .notes-box .notes-label {
            color: #A0522D;
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }

        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .filter-grid,
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-item .value {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header-section">
        <div class="container-fluid" style="max-width: 98%;">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div class="d-flex align-items-center">
                    <a href="../pages/dashboard.php" class="btn-back-arrow">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-truck-loading me-2"></i>รายการจ่ายออกสินค้า (Dispatch List)
                        </h5>
                        <small class="text-light">ระบบจัดการรายการจ่ายออกสินค้าทั้งหมด</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="dispatch_goods.php" class="btn-header">+ จ่ายออกใหม่</a>
                    <span class="text-white">
                        <i class="fas fa-user-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Filter Section -->
        <div class="filter-card">
            <div class="filter-grid">
                <div class="form-group">
                    <label>วันที่เริ่มต้น</label>
                    <input type="date" id="dateFrom" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="form-group">
                    <label>วันที่สิ้นสุด</label>
                    <input type="date" id="dateTo" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>คลังสินค้า</label>
                    <select id="warehouseFilter">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?php echo $wh['warehouse_id']; ?>">
                                <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>รหัสสินค้า</label>
                    <input type="text" id="productCodeFilter" placeholder="ค้นหารหัสสินค้า...">
                </div>
                <div class="form-group">
                    <label>MPR</label>
                    <input type="text" id="mprFilter" placeholder="ค้นหา MPR...">
                </div>
            </div>
            <div>
                <button class="btn-filter" onclick="loadData()">🔍 ค้นหา</button>
                <button class="btn-clear" onclick="clearFilter()">🔄 ล้างตัวกรอง</button>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-card">
            <div id="tableContainer">
                <div class="loading">🔄 กำลังโหลดข้อมูล...</div>
            </div>
        </div>
    </div> <!-- ✅ ปิด container-fluid -->

    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>รายละเอียดการจ่ายออก</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button> <!-- ✅ แก้เป็น close-btn -->
            </div>
            <div class="modal-body" id="modalBody"> <!-- ✅ เพิ่ม class="modal-body" -->
                <div class="loading">กำลังโหลด...</div>
            </div>
        </div>
    </div>

    <script>
    let currentData = [];

    // Load data on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadData();
    });

    async function loadData() {
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;
        const warehouseId = document.getElementById('warehouseFilter').value;
        const productCode = document.getElementById('productCodeFilter').value;
        const mpr = document.getElementById('mprFilter').value;

        const params = new URLSearchParams({
            ajax: 'get_dispatch_list',
            date_from: dateFrom,
            date_to: dateTo,
            warehouse_id: warehouseId,
            product_code: productCode,
            mpr: mpr
        });

        try {
            const res = await fetch(`?${params}`);
            const result = await res.json();

            if (result.success) {
                currentData = result.data;
                renderTable(result.data);
            } else {
                document.getElementById('tableContainer').innerHTML = 
                    '<div class="no-data">❌ เกิดข้อผิดพลาด: ' + (result.error || 'Unknown') + '</div>';
            }
        } catch (err) {
            console.error('Load error:', err);
            document.getElementById('tableContainer').innerHTML = 
                '<div class="no-data">❌ เกิดข้อผิดพลาด: ' + err.message + '</div>';
        }
    }

    function renderTable(data) {
        if (data.length === 0) {
            document.getElementById('tableContainer').innerHTML = 
                '<div class="no-data">📭 ไม่พบข้อมูล</div>';
            return;
        }

        let html = `
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>วันที่จ่าย</th>
                        <th>รหัสสินค้า</th>
                        <th>ชื่อสินค้า</th>
                        <th>คลัง</th>
                        <th>Location</th>
                        <th>จำนวน</th>
                        <th>Pallet</th>
                        <th>LOT</th>
                        <th>ผู้บันทึก</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
        `;

        data.forEach((row, index) => {
            const isCancelled = row.notes && row.notes.includes('[CANCELLED');
            const statusBadge = isCancelled 
                ? '<span class="badge badge-cancelled">ยกเลิกแล้ว</span>'
                : '<span class="badge badge-success">Dispatched</span>';

            html += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${formatDateTime(row.movement_date)}</td>
                    <td><strong>${row.product_code}</strong></td>
                    <td>${row.product_name}</td>
                    <td>${row.warehouse_name}</td>
                    <td>${row.location_code || '-'}</td>
                    <td>${parseFloat(row.quantity).toFixed(2)} ${row.unit_symbol || ''}</td>
                    <td>${row.quantity_pallet || 0}</td>
                    <td>${row.lot_supplier || '-'}</td>
                    <td>${row.created_by_name || '-'}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn-action btn-view" onclick="viewDetail(${row.movement_id})">ดู</button>
                        ${!isCancelled ? `<button class="btn-action btn-cancel" onclick="cancelDispatch(${row.movement_id})">ยกเลิก</button>` : ''}
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;

        document.getElementById('tableContainer').innerHTML = html;
    }

    async function viewDetail(movementId) {
        try {
            const res = await fetch(`?ajax=get_dispatch_detail&movement_id=${movementId}`);
            const result = await res.json();

            if (result.success) {
                const data = result.data;
                const isCancelled = data.notes && data.notes.includes('[CANCELLED');

                let html = `
                    <div class="detail-section">
                        <h3>ข้อมูลทั่วไป</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>รหัสสินค้า:</label>
                                <div class="value">${data.SSP_Code}</div>
                            </div>
                            <div class="detail-item">
                                <label>ชื่อสินค้า:</label>
                                <div class="value">${data.product_name}</div>
                            </div>
                            <div class="detail-item">
                                <label>คลังสินค้า:</label>
                                <div class="value">${data.warehouse_name}</div>
                            </div>
                            <div class="detail-item">
                                <label>Location:</label>
                                <div class="value">${data.location_code || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>จำนวน:</label>
                                <div class="value">${parseInt(data.quantity)} ${data.unit_symbol || ''}</div>
                            </div>
                            <div class="detail-item">
                                <label>Pallet:</label>
                                <div class="value">${data.quantity_pallet || 0}</div>
                            </div>
                            <div class="detail-item">
                                <label>น้ำหนัก (KG):</label>
                                <div class="value">${data.quantity_kg ? parseInt(data.quantity_kg) : '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>LOT FROM SUPPLIER:</label>
                                <div class="value">${data.batch_lot || '-'}</div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h3>ข้อมูลการจ่าย</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>MPR:</label>
                                <div class="value">${data.mpr || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>LOT MPR:</label>
                                <div class="value">${data.lot_mpr || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>Requisition:</label>
                                <div class="value">${data.requisition || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>Destination:</label>
                                <div class="value">${data.destination || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>Paperboard Type:</label>
                                <div class="value">${data.paperboard_type || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>Machine:</label>
                                <div class="value">${data.machine || '-'}</div>
                            </div>
                            <div class="detail-item">
                                <label>วันที่จ่าย:</label>
                                <div class="value">${formatDateTime(data.movement_date)}</div>
                            </div>
                            <div class="detail-item">
                                <label>ผู้บันทึก:</label>
                                <div class="value">${data.created_by_name || '-'}</div>
                            </div>
                        </div>
                        ${data.notes && !isCancelled ? `
                        <div class="notes-box">
                            <span class="notes-label">หมายเหตุ:</span>
                            ${data.notes}
                        </div>
                        ` : ''}
                    </div>
                    ${isCancelled ? '<div class="alert-cancelled">⚠️ รายการนี้ถูกยกเลิกแล้ว</div>' : ''}
                `;

                document.getElementById('modalBody').innerHTML = html;
                document.getElementById('viewModal').classList.add('show');
            } else {
                alert('ไม่พบข้อมูล');
            }
        } catch (err) {
            console.error('View error:', err);
            alert('เกิดข้อผิดพลาด');
        }
    }

    async function cancelDispatch(movementId) {
        const reason = prompt('กรุณาระบุเหตุผลในการยกเลิก:');
        if (!reason) return;

        if (!confirm('ยืนยันการยกเลิกรายการจ่ายออก?\n\n⚠️ สต็อกจะถูกคืนกลับเข้าคลัง')) return;

        try {
            const res = await fetch('?ajax=cancel_dispatch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ movement_id: movementId, reason: reason })
            });
            const result = await res.json();

            if (result.success) {
                alert('✅ ' + result.message);
                loadData(); // Reload table
            } else {
                alert('❌ ' + (result.error || 'เกิดข้อผิดพลาด'));
            }
        } catch (err) {
            console.error('Cancel error:', err);
            alert('❌ เกิดข้อผิดพลาด');
        }
    }

    function closeModal() {
        document.getElementById('viewModal').classList.remove('show');
    }

    function clearFilter() {
        document.getElementById('dateFrom').value = '<?php echo date('Y-m-01'); ?>';
        document.getElementById('dateTo').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('warehouseFilter').value = '';
        document.getElementById('productCodeFilter').value = '';
        document.getElementById('mprFilter').value = '';
        loadData();
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleString('th-TH', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Close modal on outside click
    window.onclick = function(event) {
        const modal = document.getElementById('viewModal');
        if (event.target == modal) {
            closeModal();
        }
    }
    </script>
</body>
</html>