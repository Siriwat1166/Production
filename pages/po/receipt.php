<?php
// goods_receipt.php - หน้ารับสินค้าพร้อมระบบแปลงหน่วย
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ดึงข้อมูล PO และ Items ที่สั่งซื้อไว้
$po_id = intval($_GET['po_id'] ?? 0);

// ดึงข้อมูล PO Header
$po_stmt = $conn->prepare("
    SELECT ph.*, s.supplier_name 
    FROM PO_Header ph 
    LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id 
    WHERE ph.po_id = ?
");
$po_stmt->execute([$po_id]);
$po_data = $po_stmt->fetch(PDO::FETCH_ASSOC);

if (!$po_data) {
    die("ไม่พบข้อมูล PO");
}

// ดึงรายการสินค้าใน PO
$items_stmt = $conn->prepare("
    SELECT pi.*, mp.SSP_Code, mp.Name, mp.Name2,
           pu.unit_name as purchase_unit_name, pu.unit_symbol as purchase_unit_symbol,
           su.unit_name as stock_unit_name, su.unit_symbol as stock_unit_symbol,
           sp.gsm, sp.W_mm, sp.L_mm, sp.Weight_kg_per_sheet
    FROM PO_Items pi
    LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
    LEFT JOIN Units pu ON pi.purchase_unit_id = pu.unit_id
    LEFT JOIN Units su ON pi.stock_unit_id = su.unit_id
    LEFT JOIN Specific_Paperboard sp ON mp.id = sp.product_id
    WHERE pi.po_id = ? AND pi.status != 'Cancelled'
    ORDER BY pi.line_number
");
$items_stmt->execute([$po_id]);
$po_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายการหน่วยทั้งหมด สำหรับ dropdown
$units_stmt = $conn->prepare("SELECT unit_id, unit_name, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name");
$units_stmt->execute();
$units = $units_stmt->fetchAll(PDO::FETCH_ASSOC);

// ประมวลผลการบันทึกการรับสินค้า
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // สร้าง GR Header
        $gr_number = 'GR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $receipt_date = $_POST['receipt_date'];
        $notes = $_POST['notes'] ?? '';
        
        $gr_stmt = $conn->prepare("
            INSERT INTO Goods_Receipt (gr_number, po_id, receipt_date, status, received_by, notes, created_date)
            OUTPUT INSERTED.gr_id
            VALUES (?, ?, ?, 'Received', ?, ?, GETDATE())
        ");
        $gr_stmt->execute([$gr_number, $po_id, $receipt_date, $_SESSION['user_id'], $notes]);
        $gr_result = $gr_stmt->fetch(PDO::FETCH_ASSOC);
        $gr_id = $gr_result['gr_id'];
        
        // บันทึกรายการสินค้าที่รับ
        foreach ($_POST['items'] as $item_index => $item_data) {
            if (empty($item_data['received_quantity'])) continue;
            
            $po_item_id = intval($item_data['po_item_id']);
            $received_quantity = floatval($item_data['received_quantity']);
            $received_unit_id = intval($item_data['received_unit_id']);
            $conversion_rate = floatval($item_data['conversion_rate'] ?? 1);
            
            // คำนวณจำนวนในหน่วย stock
            $stock_quantity = $received_quantity * $conversion_rate;
            
            // ดึงข้อมูล PO Item เพื่อคำนวณราคา
            $po_item_stmt = $conn->prepare("SELECT * FROM PO_Items WHERE po_item_id = ?");
            $po_item_stmt->execute([$po_item_id]);
            $po_item = $po_item_stmt->fetch(PDO::FETCH_ASSOC);
            
            $unit_cost = $po_item['unit_price'];
            $total_cost = $stock_quantity * $unit_cost;
            
            // บันทึก GR Items
            $gr_item_stmt = $conn->prepare("
                INSERT INTO Goods_Receipt_Items 
                (gr_id, po_item_id, product_id, quantity_ordered, quantity_received, quantity_accepted,
                 received_unit_id, stock_unit_id, conversion_factor, stock_quantity, unit_cost, total_cost)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $gr_item_stmt->execute([
                $gr_id, $po_item_id, $po_item['product_id'], $po_item['quantity'],
                $received_quantity, $received_quantity, $received_unit_id, $po_item['stock_unit_id'],
                $conversion_rate, $stock_quantity, $unit_cost, $total_cost
            ]);
            
            // อัพเดท PO Items - จำนวนที่รับแล้ว
            $update_po_stmt = $conn->prepare("
                UPDATE PO_Items 
                SET received_quantity = ISNULL(received_quantity, 0) + ?,
                    pending_quantity = quantity - (ISNULL(received_quantity, 0) + ?)
                WHERE po_item_id = ?
            ");
            $update_po_stmt->execute([$stock_quantity, $stock_quantity, $po_item_id]);
        }
        
        $conn->commit();
        $message = "บันทึกการรับสินค้าเรียบร้อยแล้ว หมายเลข GR: " . $gr_number;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ฟังก์ชันคำนวณการแปลงหน่วยสำหรับกระดาษ
function calculatePaperConversion($gsm, $width_mm, $length_mm, $weight_kg_per_sheet) {
    if ($weight_kg_per_sheet > 0) {
        // ใช้น้ำหนักต่อแผ่นที่กำหนดไว้
        return $weight_kg_per_sheet;
    }
    
    // คำนวณจากสูตร: (กว้าง × ยาว × GSM) / 1,000,000
    return ($width_mm * $length_mm * $gsm) / 1000000;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับสินค้า - PO: <?= htmlspecialchars($po_data['po_number']) ?></title>
</head>
<body>
    <div class="container">
        <h2>รับสินค้า - PO: <?= htmlspecialchars($po_data['po_number']) ?></h2>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="po-info">
            <h3>ข้อมูล PO</h3>
            <p><strong>เลขที่ PO:</strong> <?= htmlspecialchars($po_data['po_number']) ?></p>
            <p><strong>Supplier:</strong> <?= htmlspecialchars($po_data['supplier_name']) ?></p>
            <p><strong>วันที่ PO:</strong> <?= date('d/m/Y', strtotime($po_data['po_date'])) ?></p>
            <p><strong>มูลค่ารวม:</strong> ฿<?= number_format($po_data['total_amount'], 2) ?></p>
        </div>

        <form method="POST" id="receiptForm">
            <div class="receipt-info">
                <h3>ข้อมูลการรับสินค้า</h3>
                <label>วันที่รับสินค้า:</label>
                <input type="date" name="receipt_date" value="<?= date('Y-m-d') ?>" required>
                
                <label>หมายเหตุ:</label>
                <textarea name="notes" rows="3" placeholder="หมายเหตุการรับสินค้า"></textarea>
            </div>

            <div class="items-section">
                <h3>รายการสินค้าที่รับ</h3>
                <table border="1" cellpadding="5" cellspacing="0" width="100%">
                    <thead>
                        <tr>
                            <th>รหัสสินค้า</th>
                            <th>ชื่อสินค้า</th>
                            <th>สั่งซื้อ</th>
                            <th>หน่วยซื้อ</th>
                            <th>จำนวนรับ</th>
                            <th>หน่วยรับ</th>
                            <th>แปลงเป็น</th>
                            <th>หน่วย Stock</th>
                            <th>อัตราแปลง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($po_items as $index => $item): ?>
                        <tr data-item-index="<?= $index ?>">
                            <td><?= htmlspecialchars($item['SSP_Code']) ?></td>
                            <td>
                                <?= htmlspecialchars($item['Name']) ?>
                                <?php if ($item['Name2']): ?>
                                    <br><small><?= htmlspecialchars($item['Name2']) ?></small>
                                <?php endif; ?>
                                
                                <!-- แสดงข้อมูลกระดาษถ้ามี -->
                                <?php if ($item['gsm']): ?>
                                    <br><small>GSM: <?= $item['gsm'] ?>, ขนาด: <?= $item['W_mm'] ?>×<?= $item['L_mm'] ?> mm</small>
                                    <?php if ($item['Weight_kg_per_sheet']): ?>
                                        <br><small>น้ำหนัก/แผ่น: <?= $item['Weight_kg_per_sheet'] ?> kg</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($item['quantity'], 2) ?></td>
                            <td><?= htmlspecialchars($item['purchase_unit_name']) ?></td>
                            <td>
                                <input type="hidden" name="items[<?= $index ?>][po_item_id]" value="<?= $item['po_item_id'] ?>">
                                <input type="number" 
                                       name="items[<?= $index ?>][received_quantity]" 
                                       step="0.01" 
                                       placeholder="0.00"
                                       onchange="calculateConversion(<?= $index ?>)">
                            </td>
                            <td>
                                <select name="items[<?= $index ?>][received_unit_id]" onchange="calculateConversion(<?= $index ?>)">
                                    <option value="">เลือกหน่วย</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?= $unit['unit_id'] ?>" 
                                                data-symbol="<?= htmlspecialchars($unit['unit_symbol']) ?>">
                                            <?= htmlspecialchars($unit['unit_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <span id="converted_<?= $index ?>">0.00</span>
                            </td>
                            <td><?= htmlspecialchars($item['stock_unit_name']) ?></td>
                            <td>
                                <input type="number" 
                                       name="items[<?= $index ?>][conversion_rate]" 
                                       id="conversion_<?= $index ?>"
                                       step="0.000001" 
                                       value="1.000000"
                                       onchange="calculateConversion(<?= $index ?>)">
                                
                                <!-- ข้อมูลสำหรับคำนวณแปลงหน่วยกระดาษ -->
                                <input type="hidden" id="gsm_<?= $index ?>" value="<?= $item['gsm'] ?? 0 ?>">
                                <input type="hidden" id="width_<?= $index ?>" value="<?= $item['W_mm'] ?? 0 ?>">
                                <input type="hidden" id="length_<?= $index ?>" value="<?= $item['L_mm'] ?? 0 ?>">
                                <input type="hidden" id="weight_per_sheet_<?= $index ?>" value="<?= $item['Weight_kg_per_sheet'] ?? 0 ?>">
                                
                                <?php if ($item['gsm']): ?>
                                    <br><button type="button" onclick="autoCalculatePaper(<?= $index ?>)">คำนวณอัตโนมัติ</button>
                                    <br><small id="conversion_info_<?= $index ?>"></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="submit-section">
                <button type="submit">บันทึกการรับสินค้า</button>
                <a href="list.php">ยกเลิก</a>
            </div>
        </form>
    </div>

    <script>
        // ฟังก์ชันคำนวณการแปลงหน่วย
        function calculateConversion(index) {
            const receivedQty = parseFloat(document.querySelector(`input[name="items[${index}][received_quantity]"]`).value) || 0;
            const conversionRate = parseFloat(document.getElementById(`conversion_${index}`).value) || 1;
            
            const convertedQty = receivedQty * conversionRate;
            document.getElementById(`converted_${index}`).textContent = convertedQty.toFixed(3);
        }

        // ฟังก์ชันคำนวณอัตราแปลงกระดาษอัตโนมัติ
        function autoCalculatePaper(index) {
            const receivedUnitSelect = document.querySelector(`select[name="items[${index}][received_unit_id]"]`);
            const receivedUnitText = receivedUnitSelect.options[receivedUnitSelect.selectedIndex]?.text || '';
            
            const gsm = parseFloat(document.getElementById(`gsm_${index}`).value) || 0;
            const width = parseFloat(document.getElementById(`width_${index}`).value) || 0;
            const length = parseFloat(document.getElementById(`length_${index}`).value) || 0;
            const weightPerSheet = parseFloat(document.getElementById(`weight_per_sheet_${index}`).value) || 0;
            
            if (!gsm || !width || !length) {
                alert('ไม่มีข้อมูล GSM หรือขนาดกระดาษ');
                return;
            }
            
            let conversionRate = 1;
            let weightPerSheetActual = 0;
            
            // คำนวณน้ำหนักต่อแผ่น
            if (weightPerSheet > 0) {
                weightPerSheetActual = weightPerSheet;
            } else {
                weightPerSheetActual = (width * length * gsm) / 1000000;
            }
            
            // ถ้ารับเป็นแผ่น (sheet) และต้องแปลงเป็นกิโลกรัม
            if (receivedUnitText.toLowerCase().includes('sheet') || receivedUnitText.includes('แผ่น')) {
                conversionRate = weightPerSheetActual;
                
                document.getElementById(`conversion_${index}`).value = conversionRate.toFixed(6);
                
                // แสดงข้อมูลการแปลง
                const sheetsPerKg = (1 / weightPerSheetActual).toFixed(2);
                document.getElementById(`conversion_info_${index}`).innerHTML = 
                    `<span style="color: green;">1 แผ่น = ${weightPerSheetActual.toFixed(6)} kg<br>1 กิโล = ${sheetsPerKg} แผ่น</span>`;
                
                calculateConversion(index);
                
                alert(`คำนวณอัตราแปลงแล้ว:\n• 1 แผ่น = ${weightPerSheetActual.toFixed(6)} kg\n• 1 กิโลกรัม = ${sheetsPerKg} แผ่น`);
            }
            // ถ้ารับเป็นกิโลกรัม และต้องแปลงเป็นแผ่น
            else if (receivedUnitText.toLowerCase().includes('kg') || receivedUnitText.includes('กิโลกรัม')) {
                conversionRate = 1 / weightPerSheetActual;
                
                document.getElementById(`conversion_${index}`).value = conversionRate.toFixed(6);
                
                // แสดงข้อมูลการแปลง
                const sheetsPerKg = conversionRate.toFixed(2);
                document.getElementById(`conversion_info_${index}`).innerHTML = 
                    `<span style="color: blue;">1 กิโล = ${sheetsPerKg} แผ่น<br>1 แผ่น = ${weightPerSheetActual.toFixed(6)} kg</span>`;
                
                calculateConversion(index);
                
                alert(`คำนวณอัตราแปลงแล้ว:\n• 1 กิโลกรัม = ${sheetsPerKg} แผ่น\n• 1 แผ่น = ${weightPerSheetActual.toFixed(6)} kg`);
            }
        }

        // เพิ่ม event listener สำหรับการเปลี่ยนแปลงค่า
        document.addEventListener('DOMContentLoaded', function() {
            // คำนวณทุกครั้งที่โหลดหน้า
            <?php foreach ($po_items as $index => $item): ?>
                calculateConversion(<?= $index ?>);
            <?php endforeach; ?>
        });
    </script>
</body>
</html>