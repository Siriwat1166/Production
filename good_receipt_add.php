<?php
// production/inventory/goods_receipt_add.php
// โครงสร้าง:
// production/
//   ├── config/
//   │   ├── config.php      <- ไฟล์ที่ต้องการ
//   │   └── database.php    <- ไฟล์ที่ต้องการ
//   └── inventory/
//       └── goods_receipt_add.php  <- ไฟล์นี้

// ใช้ relative path ที่ถูกต้อง
require_once '../config/config.php';
require_once '../config/database.php';

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูล Warehouses
$query_warehouses = "SELECT warehouse_id, warehouse_code, warehouse_name, warehouse_name_th 
                     FROM Warehouses WHERE is_active = 1 ORDER BY warehouse_code";
$stmt_warehouses = $db->query($query_warehouses);
$warehouses = $stmt_warehouses->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูล Suppliers
$query_suppliers = "SELECT supplier_id, supplier_code, supplier_name 
                    FROM Suppliers WHERE is_active = 1 ORDER BY supplier_code";
$stmt_suppliers = $db->query($query_suppliers);
$suppliers = $stmt_suppliers->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูล Units
$query_units = "SELECT unit_id, unit_code, unit_name, unit_name_th 
                FROM Units WHERE is_active = 1 ORDER BY unit_code";
$stmt_units = $db->query($query_units);
$units = $stmt_units->fetchAll(PDO::FETCH_ASSOC);

// สร้างเลข GR Number อัตโนมัติ
$gr_number = 'GR' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

$page_title = "รับเข้าคลังสินค้า (Goods Receipt)";
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .receipt-type-card {
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #dee2e6;
        }
        
        .receipt-type-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transform: translateY(-2px);
        }
        
        .receipt-type-card.selected {
            border-color: #667eea;
            background-color: #f8f9ff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .items-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .unit-conversion-info {
            background-color: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .calculation-highlight {
            background-color: #fff3cd;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
        }
        
        .btn-add-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .remove-item-btn {
            cursor: pointer;
            color: #dc3545;
        }
        
        .remove-item-btn:hover {
            color: #bb2d3b;
        }
    </style>
</head>
<body class="bg-light">
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1"><i class="bi bi-box-arrow-in-down"></i> <?php echo $page_title; ?></h2>
                        <p class="text-muted mb-0">บันทึกการรับเข้าสินค้าเข้าคลัง</p>
                    </div>
                    <a href="goods_receipt_list.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> กลับ
                    </a>
                </div>
            </div>
        </div>

        <form id="goodsReceiptForm" method="POST">
            <!-- เลือกประเภทการรับเข้า -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-1-circle"></i> เลือกประเภทการรับเข้า</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="receipt-type-card card h-100 p-3" data-type="WITH_PO">
                                <div class="text-center">
                                    <i class="bi bi-file-earmark-text" style="font-size: 3rem; color: #667eea;"></i>
                                    <h5 class="mt-3">รับตาม PO (With PO)</h5>
                                    <p class="text-muted mb-0">รับสินค้าที่มีใบสั่งซื้อ (Purchase Order)</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="receipt-type-card card h-100 p-3" data-type="WITHOUT_PO">
                                <div class="text-center">
                                    <i class="bi bi-receipt" style="font-size: 3rem; color: #764ba2;"></i>
                                    <h5 class="mt-3">รับไม่มี PO (Direct Receipt)</h5>
                                    <p class="text-muted mb-0">รับสินค้าโดยตรงโดยไม่มีใบสั่งซื้อ</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="receipt_type" id="receipt_type" required>
                </div>
            </div>

            <!-- ข้อมูลหลัก -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-2-circle"></i> ข้อมูลการรับเข้า</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">เลขที่รับเข้า <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="gr_number" id="gr_number" 
                                   value="<?php echo $gr_number; ?>" readonly>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">วันที่รับเข้า <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="receipt_date" id="receipt_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">คลังสินค้า <span class="text-danger">*</span></label>
                            <select class="form-select" name="warehouse_id" id="warehouse_id" required>
                                <option value="">-- เลือกคลัง --</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?php echo $warehouse['warehouse_id']; ?>">
                                        <?php echo $warehouse['warehouse_code'] . ' - ' . 
                                                  ($warehouse['warehouse_name_th'] ?: $warehouse['warehouse_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Aisle</label>
                            <select class="form-select" name="default_location_id" id="default_location_id">
                                <option value="">-- เลือก Aisle --</option>
                            </select>
                            <small class="text-muted">เลือกคลังก่อน</small>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">ผู้รับเข้า <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="received_by_name" 
                                   value="<?php echo $_SESSION['full_name'] ?? 'User'; ?>" readonly>
                            <input type="hidden" name="received_by" value="<?php echo $_SESSION['user_id']; ?>">
                        </div>

                        <!-- สำหรับรับตาม PO -->
                        <div class="col-md-4 po-section d-none">
                            <label class="form-label">เลขที่ PO <span class="text-danger">*</span></label>
                            <select class="form-select select2" name="po_id" id="po_id">
                                <option value="">-- เลือก PO --</option>
                            </select>
                        </div>

                        <!-- สำหรับรับไม่มี PO -->
                        <div class="col-md-4 direct-section d-none">
                            <label class="form-label">ซัพพลายเออร์</label>
                            <select class="form-select select2" name="supplier_id" id="supplier_id">
                                <option value="">-- เลือกซัพพลายเออร์ --</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['supplier_id']; ?>">
                                        <?php echo $supplier['supplier_code'] . ' - ' . $supplier['supplier_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 direct-section d-none">
                            <label class="form-label">เหตุผลการรับเข้า</label>
                            <input type="text" class="form-control" name="receipt_reason" id="receipt_reason"
                                   placeholder="เช่น รับคืนจากลูกค้า, รับสินค้าตัวอย่าง">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">เลขที่ใบแจ้งหนี้</label>
                            <input type="text" class="form-control" name="invoice_number" id="invoice_number">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" id="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- รายการสินค้า -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-3-circle"></i> รายการสินค้า</h5>
                    <button type="button" class="btn btn-add-item btn-sm text-white" id="addItemBtn">
                        <i class="bi bi-plus-circle"></i> เพิ่มรายการ
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table items-table table-hover" id="itemsTable">
                            <thead>
                                <tr>
                                    <th width="3%">#</th>
                                    <th width="16%">สินค้า</th>
                                    <th width="9%">Lot Number</th>
                                    <th width="7%">จำนวนสั่งซื้อ</th>
                                    <th width="8%">หน่วยรับเข้า</th>
                                    <th width="8%">จำนวนรับ</th>
                                    <th width="8%">จำนวนเข้าสต็อก</th>
                                    <th width="8%">หน่วยสต็อก</th>
                                    <th width="9%">Location (Aisle)</th>
                                    <th width="7%">ราคา/หน่วย</th>
                                    <th width="5%">
                                        <i class="bi bi-gear"></i>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                                <!-- รายการจะถูกเพิ่มด้วย JavaScript -->
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end"><strong>รวมทั้งหมด:</strong></td>
                                    <td><strong id="totalQuantity">0.00</strong></td>
                                    <td colspan="3"></td>
                                    <td><strong id="totalAmount">0.00</strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ปุ่มบันทึก -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-0 text-muted">
                                <i class="bi bi-info-circle"></i> 
                                กรุณาตรวจสอบข้อมูลให้ครบถ้วนก่อนบันทึก
                            </p>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary me-2" onclick="window.location.href='goods_receipt_list.php'">
                                <i class="bi bi-x-circle"></i> ยกเลิก
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> บันทึกการรับเข้า
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    $(document).ready(function() {
        let itemCounter = 0;
        
        // Initialize Select2
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
        
        // เมื่อเลือกคลัง ให้โหลด Locations
        $('#warehouse_id').change(function() {
            const warehouseId = $(this).val();
            loadLocations(warehouseId);
        });
        
        // โหลด Locations ตามคลัง
        function loadLocations(warehouseId) {
            const locationSelect = $('#default_location_id');
            
            if (!warehouseId) {
                locationSelect.html('<option value="">-- เลือก Aisle --</option>');
                locationSelect.prop('disabled', true);
                return;
            }
            
            $.ajax({
                url: '../api/get_warehouse_locations.php',
                method: 'GET',
                data: { warehouse_id: warehouseId },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- เลือก Aisle --</option>';
                        response.data.forEach(function(location) {
                            options += `<option value="${location.aisle}">${location.aisle}</option>`;
                        });
                        locationSelect.html(options);
                        locationSelect.prop('disabled', false);
                        
                        // อัพเดท location ในแต่ละแถว
                        updateItemLocations(response.data);
                    }
                },
                error: function() {
                    console.error('ไม่สามารถโหลด Locations ได้');
                    locationSelect.html('<option value="">-- ไม่พบ Aisle --</option>');
                    locationSelect.prop('disabled', true);
                }
            });
        }
        
        // อัพเดท Location dropdown ในแต่ละแถวสินค้า
        function updateItemLocations(locations) {
            let options = '<option value="">-- เลือก Aisle --</option>';
            locations.forEach(function(location) {
                options += `<option value="${location.aisle}">${location.aisle}</option>`;
            });
            
            $('.location-select').html(options);
        }
        
        // เลือกประเภทการรับเข้า
        $('.receipt-type-card').click(function() {
            $('.receipt-type-card').removeClass('selected');
            $(this).addClass('selected');
            
            const receiptType = $(this).data('type');
            $('#receipt_type').val(receiptType);
            
            if (receiptType === 'WITH_PO') {
                $('.po-section').removeClass('d-none');
                $('.direct-section').addClass('d-none');
                $('#po_id').prop('required', true);
                loadPOList();
            } else {
                $('.po-section').addClass('d-none');
                $('.direct-section').removeClass('d-none');
                $('#po_id').prop('required', false);
            }
        });
        
        // โหลดรายการ PO
        function loadPOList() {
            $.ajax({
                url: '../api/get_po_list.php',
                method: 'GET',
                data: { status: 'approved' },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- เลือก PO --</option>';
                        response.data.forEach(function(po) {
                            options += `<option value="${po.po_id}">${po.po_number} - ${po.supplier_name}</option>`;
                        });
                        $('#po_id').html(options);
                    }
                }
            });
        }
        
        // เมื่อเลือก PO
        $('#po_id').change(function() {
            const poId = $(this).val();
            if (poId) {
                loadPOItems(poId);
            }
        });
        
        // โหลดรายการสินค้าจาก PO
        function loadPOItems(poId) {
            $.ajax({
                url: '../api/get_po_items.php',
                method: 'GET',
                data: { po_id: poId },
                success: function(response) {
                    if (response.success) {
                        $('#itemsTableBody').empty();
                        itemCounter = 0;
                        
                        response.data.forEach(function(item) {
                            addItemRow(item);
                        });
                    }
                },
                error: function() {
                    alert('ไม่สามารถโหลดรายการสินค้าได้');
                }
            });
        }
        
        // เพิ่มแถวรายการสินค้า
        $('#addItemBtn').click(function() {
            addItemRow();
        });
        
        function addItemRow(itemData = null) {
            itemCounter++;
            
            // กำหนดหน่วยสต็อกเริ่มต้น
            let defaultStockUnitId = '';
            if (itemData && itemData.stock_unit_id) {
                defaultStockUnitId = itemData.stock_unit_id;
            }
            
            const row = `
                <tr data-row-id="${itemCounter}">
                    <td class="text-center">${itemCounter}</td>
                    <td>
                        <select class="form-select form-select-sm product-select" 
                                name="items[${itemCounter}][product_id]" required>
                            <option value="">-- เลือกสินค้า --</option>
                            ${itemData ? `<option value="${itemData.product_id}" selected>
                                ${itemData.product_name}</option>` : ''}
                        </select>
                        <div class="unit-conversion-info d-none" id="conversion-info-${itemCounter}"></div>
                        <input type="hidden" name="items[${itemCounter}][po_item_id]" value="${itemData?.po_item_id || ''}">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                               name="items[${itemCounter}][supplier_lot_number]"
                               placeholder="Lot Number">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end" 
                               name="items[${itemCounter}][quantity_ordered]" 
                               value="${itemData?.remaining_quantity || 0}" 
                               step="0.01" readonly>
                    </td>
                    <td>
                        <select class="form-select form-select-sm received-unit-select" 
                                name="items[${itemCounter}][received_unit_id]" 
                                data-row="${itemCounter}" required>
                            <option value="">-- หน่วย --</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo $unit['unit_id']; ?>"
                                    ${itemData && itemData.purchase_unit_id == <?php echo $unit['unit_id']; ?> ? 'selected' : ''}>
                                    <?php echo $unit['unit_name_th'] ?: $unit['unit_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end quantity-received" 
                               name="items[${itemCounter}][quantity_received]" 
                               data-row="${itemCounter}"
                               step="0.01" min="0" required>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end stock-quantity" 
                               name="items[${itemCounter}][stock_quantity]" 
                               step="0.01" readonly>
                    </td>
                    <td>
                        <select class="form-select form-select-sm stock-unit-select" 
                                name="items[${itemCounter}][stock_unit_id]" 
                                data-row="${itemCounter}" required>
                            <option value="">-- หน่วย --</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo $unit['unit_id']; ?>"
                                    ${defaultStockUnitId && defaultStockUnitId == <?php echo $unit['unit_id']; ?> ? 'selected' : ''}>
                                    <?php echo $unit['unit_name_th'] ?: $unit['unit_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm location-select" 
                                name="items[${itemCounter}][location_id]">
                            <option value="">-- Aisle --</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end unit-cost" 
                               name="items[${itemCounter}][unit_cost]" 
                               value="${itemData?.unit_price || 0}" 
                               step="0.01" min="0">
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary btn-split-item" 
                                    data-row="${itemCounter}" title="แยกรายการ">
                                <i class="bi bi-scissors"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger remove-item-btn-button" 
                                    data-row="${itemCounter}" title="ลบ">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            
            $('#itemsTableBody').append(row);
            
            // โหลด Locations ถ้ามีการเลือกคลังแล้ว
            const warehouseId = $('#warehouse_id').val();
            if (warehouseId) {
                loadLocationForRow(itemCounter, warehouseId);
            }
            
            // Initialize product select
            if (!itemData) {
                loadProducts(itemCounter);
            } else {
                // ถ้ามีข้อมูลจาก PO
                if (itemData.material_type_id == 1 && itemData.paperboard_data) {
                    // แสดงข้อมูลกระดาษ
                    showConversionInfo(itemCounter, itemData.paperboard_data);
                    $(`tr[data-row-id="${itemCounter}"]`).data('weight-per-sheet', itemData.paperboard_data.Weight_kg_per_sheet);
                }
            }
        }
        
        // โหลด Location สำหรับแถวใหม่
        function loadLocationForRow(rowId, warehouseId, selectedLocationId = null) {
            $.ajax({
                url: '../api/get_warehouse_locations.php',
                method: 'GET',
                data: { warehouse_id: warehouseId },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- Aisle --</option>';
                        response.data.forEach(function(location) {
                            const selected = (selectedLocationId && location.aisle == selectedLocationId) ? 'selected' : '';
                            options += `<option value="${location.aisle}" ${selected}>${location.aisle}</option>`;
                        });
                        $(`tr[data-row-id="${rowId}"] .location-select`).html(options);
                    }
                }
            });
        }
        
        // โหลดรายการสินค้า
        function loadProducts(rowId) {
            $.ajax({
                url: '../api/get_products.php',
                method: 'GET',
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- เลือกสินค้า --</option>';
                        response.data.forEach(function(product) {
                            options += `<option value="${product.id}" 
                                        data-material-type="${product.material_type_id}">
                                        ${product.SSP_Code} - ${product.Name}</option>`;
                        });
                        $(`tr[data-row-id="${rowId}"] .product-select`).html(options);
                    }
                },
                error: function() {
                    console.error('ไม่สามารถโหลดรายการสินค้าได้');
                }
            });
        }
        
        // เมื่อเลือกสินค้า
        $(document).on('change', '.product-select', function() {
            const productId = $(this).val();
            const rowId = $(this).closest('tr').data('row-id');
            
            if (productId) {
                loadProductDetails(productId, rowId);
            }
        });
        
        // โหลดรายละเอียดสินค้า
        function loadProductDetails(productId, rowId) {
            $.ajax({
                url: '../api/get_product_details.php',
                method: 'GET',
                data: { product_id: productId },
                success: function(response) {
                    if (response.success) {
                        const product = response.data;
                        const row = $(`tr[data-row-id="${rowId}"]`);
                        
                        // ตั้งค่าหน่วยสต็อกเริ่มต้นจากข้อมูลสินค้า
                        if (product.Unit_id) {
                            row.find('.stock-unit-select').val(product.Unit_id);
                        }
                        
                        // ถ้าเป็นกระดาษ (material_type_id = 1)
                        if (product.material_type_id == 1 && product.paperboard_data) {
                            const paperData = product.paperboard_data;
                            const calculations = response.specific_data.calculations;
                            
                            // เก็บข้อมูลการคำนวณ
                            row.data('weight-per-sheet', calculations.weight_per_sheet_kg);
                            row.data('sheets-per-kg', calculations.sheets_per_kg);
                            row.data('paper-data', paperData);
                            row.data('calculations', calculations);
                            
                            // แสดงข้อมูลการคำนวณ
                            showDetailedConversionInfo(rowId, paperData, calculations);
                            
                            // หาหน่วย "แผ่น" และตั้งเป็นหน่วยสต็อก
                            const stockUnitSelect = row.find('.stock-unit-select');
                            stockUnitSelect.find('option').each(function() {
                                const unitText = $(this).text().toLowerCase();
                                if (unitText.includes('แผ่น') || unitText.includes('sheet')) {
                                    stockUnitSelect.val($(this).val());
                                    return false;
                                }
                            });
                        }
                    }
                },
                error: function() {
                    console.error('ไม่สามารถโหลดรายละเอียดสินค้าได้');
                }
            });
        }
        
        // แสดงข้อมูลการคำนวณแบบละเอียด
        function showDetailedConversionInfo(rowId, paperData, calculations) {
            const info = `
                <div class="mt-2 p-2 bg-light border rounded">
                    <strong><i class="bi bi-info-circle text-primary"></i> ข้อมูลกระดาษ:</strong><br>
                    <div class="row g-1 mt-1">
                        <div class="col-6">
                            <small>• ขนาด: ${paperData.W_mm} × ${paperData.L_mm} mm</small>
                        </div>
                        <div class="col-6">
                            <small>• พื้นที่: ${calculations.area_m2.toFixed(6)} m²</small>
                        </div>
                        <div class="col-6">
                            <small>• GSM: ${paperData.gsm}</small>
                        </div>
                        <div class="col-6">
                            <small>• น้ำหนัก/แผ่น: ${calculations.weight_per_sheet_kg.toFixed(6)} kg</small>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="text-primary">
                        <strong><i class="bi bi-calculator"></i> สูตรการคำนวณ:</strong><br>
                        <small>
                            • <strong>กิโล → แผ่น:</strong> แผ่น = กิโล × ${calculations.sheets_per_kg.toFixed(2)}<br>
                            • <strong>แผ่น → กิโล:</strong> กิโล = แผ่น × ${calculations.weight_per_sheet_kg.toFixed(6)}
                        </small>
                    </div>
                    <div class="mt-2 text-center">
                        <span class="calculation-highlight">
                            <i class="bi bi-arrow-left-right"></i> รอการคำนวณ...
                        </span>
                    </div>
                </div>
            `;
            
            $(`#conversion-info-${rowId}`).html(info).removeClass('d-none');
        }
        
        // แสดงข้อมูลการคำนวณสำหรับกระดาษ
        function showConversionInfo(rowId, paperData) {
            // คำนวณข้อมูลเพิ่มเติม
            const w_m = paperData.W_mm / 1000;
            const l_m = paperData.L_mm / 1000;
            const area_m2 = w_m * l_m;
            const weight_per_sheet = (area_m2 * (paperData.gsm / 1000));
            const sheets_per_kg = 1 / weight_per_sheet;
            const conversion_factor = 5.60; // ตัวอย่าง
            
            const info = `
                <div class="mt-2">
                    <strong>ข้อมูลกระดาษ:</strong><br>
                    <small>
                        • ขนาด: ${paperData.W_mm} × ${paperData.L_mm} mm (${area_m2.toFixed(3)} m²)<br>
                        • GSM: ${paperData.gsm}<br>
                        • น้ำหนัก/แผ่น: ${weight_per_sheet.toFixed(6)} kg<br>
                        • จำนวนแผ่น/kg: ${sheets_per_kg.toFixed(2)} แผ่น
                    </small><br>
                    <span class="text-primary">
                        <i class="bi bi-calculator"></i> 
                        <strong>กิโล → แผ่น:</strong> จำนวนแผ่น = กิโล × ${sheets_per_kg.toFixed(2)}
                    </span><br>
                    <span class="text-info">
                        <i class="bi bi-calculator"></i> 
                        <strong>แผ่น → กิโล:</strong> จำนวนกิโล = แผ่น × ${weight_per_sheet.toFixed(6)}
                    </span>
                </div>
            `;
            
            $(`#conversion-info-${rowId}`).html(info).removeClass('d-none');
        }
        
        // คำนวณเมื่อใส่จำนวนรับ
        $(document).on('input', '.quantity-received', function() {
            const row = $(this).closest('tr');
            calculateStockQuantity(row);
        });
        
        // คำนวณเมื่อเปลี่ยนหน่วยรับเข้า
        $(document).on('change', '.received-unit-select', function() {
            const row = $(this).closest('tr');
            calculateStockQuantity(row);
        });
        
        // คำนวณเมื่อเปลี่ยนหน่วยสต็อก
        $(document).on('change', '.stock-unit-select', function() {
            const row = $(this).closest('tr');
            calculateStockQuantity(row);
        });
        
        // ฟังก์ชันคำนวณจำนวนสต็อก
        function calculateStockQuantity(row) {
            const rowId = row.data('row-id');
            const receivedQty = parseFloat(row.find('.quantity-received').val()) || 0;
            const weightPerSheet = row.data('weight-per-sheet');
            const receivedUnitId = row.find('.received-unit-select').val();
            const stockUnitId = row.find('.stock-unit-select').val();
            
            // ตรวจสอบชื่อหน่วย
            const receivedUnitText = row.find('.received-unit-select option:selected').text().toLowerCase();
            const stockUnitText = row.find('.stock-unit-select option:selected').text().toLowerCase();
            
            const isReceivedKg = receivedUnitId == getKgUnitId() || receivedUnitText.includes('กิโลกรัม') || receivedUnitText.includes('kg') || receivedUnitText.includes('กก');
            const isReceivedSheets = receivedUnitText.includes('แผ่น') || receivedUnitText.includes('sheet');
            const isStockSheets = stockUnitText.includes('แผ่น') || stockUnitText.includes('sheet');
            const isStockKg = stockUnitId == getKgUnitId() || stockUnitText.includes('กิโลกรัม') || stockUnitText.includes('kg') || stockUnitText.includes('กก');
            
            // ถ้าเป็นกระดาษและมีข้อมูลน้ำหนักต่อแผ่น
            if (weightPerSheet && weightPerSheet > 0) {
                
                // กรณีที่ 1: รับเป็น kg → สต็อกเป็นแผ่น
                if (isReceivedKg && isStockSheets) {
                    // สูตร: จำนวนแผ่น = กิโล / น้ำหนักต่อแผ่น
                    const sheets = receivedQty / weightPerSheet;
                    row.find('.stock-quantity').val(sheets.toFixed(2));
                    
                    const sheetsPerKg = 1 / weightPerSheet;
                    updateConversionInfo(row, 
                        `${receivedQty.toFixed(2)} kg ÷ ${weightPerSheet.toFixed(6)} = ${sheets.toFixed(2)} แผ่น<br>` +
                        `<small class="text-muted">(1 kg = ${sheetsPerKg.toFixed(2)} แผ่น)</small>`
                    );
                }
                // กรณีที่ 2: รับเป็นแผ่น → สต็อกเป็น kg
                else if (isReceivedSheets && isStockKg) {
                    // สูตร: จำนวนกิโล = แผ่น × น้ำหนักต่อแผ่น
                    const kg = receivedQty * weightPerSheet;
                    row.find('.stock-quantity').val(kg.toFixed(2));
                    
                    updateConversionInfo(row, 
                        `${receivedQty.toFixed(2)} แผ่น × ${weightPerSheet.toFixed(6)} = ${kg.toFixed(2)} kg`
                    );
                }
                // กรณีที่ 3: รับเป็นแผ่น → สต็อกเป็นแผ่น (เท่ากัน)
                else if (isReceivedSheets && isStockSheets) {
                    row.find('.stock-quantity').val(receivedQty.toFixed(2));
                    
                    updateConversionInfo(row, 
                        `${receivedQty.toFixed(2)} แผ่น = ${receivedQty.toFixed(2)} แผ่น`
                    );
                }
                // กรณีที่ 4: รับเป็น kg → สต็อกเป็น kg (เท่ากัน)
                else if (isReceivedKg && isStockKg) {
                    row.find('.stock-quantity').val(receivedQty.toFixed(2));
                    
                    updateConversionInfo(row, 
                        `${receivedQty.toFixed(2)} kg = ${receivedQty.toFixed(2)} kg`
                    );
                }
                else {
                    // หน่วยอื่นๆ ให้เท่ากัน
                    row.find('.stock-quantity').val(receivedQty.toFixed(2));
                }
            } else {
                // ถ้าไม่ใช่กระดาษหรือไม่มีข้อมูล ให้เท่ากัน
                row.find('.stock-quantity').val(receivedQty.toFixed(2));
            }
            
            calculateTotal();
        }
        
        // ฟังก์ชันอัพเดทข้อมูลการคำนวณ
        // ฟังก์ชันอัพเดทข้อมูลการคำนวณ
function updateConversionInfo(row, calculationText) {
    const conversionInfo = row.find('.unit-conversion-info');
    if (conversionInfo.length > 0 && !conversionInfo.hasClass('d-none')) {
        // หา div ที่แสดงผลการคำนวณและอัพเดท
        const calcDiv = conversionInfo.find('.text-center');
        if (calcDiv.length > 0) {
            calcDiv.html(`
                <span class="calculation-highlight">
                    <i class="bi bi-check-circle text-success"></i> 
                    ${calculationText}
                </span>
            `);
        } else {
            // ถ้าไม่มี div เดิม ให้แทนที่ส่วน text-primary
            const currentInfo = conversionInfo.html();
            const newInfo = currentInfo.replace(
                /<span class="text-primary">[\s\S]*?<\/span>/,
                `<span class="text-primary">
                    <i class="bi bi-calculator"></i> 
                    <span class="calculation-highlight">
                        ${calculationText}
                    </span>
                </span>`
            );
            conversionInfo.html(newInfo);
        }
    }
}
        
        // Helper function - ได้ unit_id ของ kg
        function getKgUnitId() {
            // ต้องดึงจาก database หรือกำหนดไว้ล่วงหน้า
            return 2; // สมมติว่า kg มี unit_id = 2
        }
        
        // ลบรายการ
        $(document).on('click', '.remove-item-btn, .remove-item-btn-button', function() {
            const rowId = $(this).data('row');
            
            if (confirm('คุณต้องการลบรายการนี้ใช่หรือไม่?')) {
                $(`tr[data-row-id="${rowId}"]`).remove();
                calculateTotal();
                reorderRows();
            }
        });
        
        // แยกรายการ (Split Item)
        $(document).on('click', '.btn-split-item', function() {
            const rowId = $(this).data('row');
            
            Swal.fire({
                title: 'แยกรายการสินค้า',
                html: `
                    <div class="text-start">
                        <p class="mb-3">ระบบจะคัดลอกรายการนี้เป็นรายการใหม่</p>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            คุณสามารถแก้ไขจำนวน, Lot Number และ Location ของแต่ละรายการได้
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'แยกรายการ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0d6efd'
            }).then((result) => {
                if (result.isConfirmed) {
                    duplicateItemRow(rowId);
                }
            });
        });
        
        // ฟังก์ชันคัดลอกรายการ
        function duplicateItemRow(sourceRowId) {
            const sourceRow = $(`tr[data-row-id="${sourceRowId}"]`);
            
            // ดึงข้อมูลจากแถวต้นฉบับ
            const productId = sourceRow.find('.product-select').val();
            const productName = sourceRow.find('.product-select option:selected').text();
            const poItemId = sourceRow.find('input[name*="[po_item_id]"]').val();
            const quantityOrdered = parseFloat(sourceRow.find('input[name*="[quantity_ordered]"]').val()) || 0;
            const currentQuantity = parseFloat(sourceRow.find('.quantity-received').val()) || 0;
            const receivedUnitId = sourceRow.find('.received-unit-select').val();
            const receivedUnitText = sourceRow.find('.received-unit-select option:selected').text();
            const stockUnitId = sourceRow.find('.stock-unit-select').val();
            const unitCost = sourceRow.find('.unit-cost').val();
            const weightPerSheet = sourceRow.data('weight-per-sheet');
            const locationId = sourceRow.find('.location-select').val();
            
            // สร้าง options สำหรับ stock unit
            let stockUnitOptions = '';
            <?php foreach ($units as $unit): ?>
                stockUnitOptions += `<option value="<?php echo $unit['unit_id']; ?>" ${stockUnitId == <?php echo $unit['unit_id']; ?> ? 'selected' : ''}>
                    <?php echo $unit['unit_name_th'] ?: $unit['unit_name']; ?>
                </option>`;
            <?php endforeach; ?>
            
            // สร้างแถวใหม่
            itemCounter++;
            
            const newRow = `
                <tr data-row-id="${itemCounter}" class="table-warning" data-weight-per-sheet="${weightPerSheet || ''}">
                    <td class="text-center">${itemCounter}</td>
                    <td>
                        <select class="form-select form-select-sm product-select" 
                                name="items[${itemCounter}][product_id]" required disabled>
                            <option value="${productId}" selected>${productName}</option>
                        </select>
                        <input type="hidden" name="items[${itemCounter}][product_id]" value="${productId}">
                        <input type="hidden" name="items[${itemCounter}][po_item_id]" value="${poItemId}">
                        <div class="unit-conversion-info d-none" id="conversion-info-${itemCounter}"></div>
                        <small class="text-warning"><i class="bi bi-arrow-return-right"></i> แยกจากรายการที่ ${sourceRowId}</small>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm bg-warning bg-opacity-25" 
                               name="items[${itemCounter}][supplier_lot_number]"
                               placeholder="Lot Number (แยก)">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end" 
                               name="items[${itemCounter}][quantity_ordered]" 
                               value="${quantityOrdered}" 
                               step="0.01" readonly>
                    </td>
                    <td>
                        <select class="form-select form-select-sm received-unit-select" 
                                name="items[${itemCounter}][received_unit_id]" 
                                data-row="${itemCounter}" required disabled>
                            <option value="${receivedUnitId}" selected>${receivedUnitText}</option>
                        </select>
                        <input type="hidden" name="items[${itemCounter}][received_unit_id]" value="${receivedUnitId}">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end quantity-received bg-warning bg-opacity-25" 
                               name="items[${itemCounter}][quantity_received]" 
                               data-row="${itemCounter}"
                               value="${currentQuantity.toFixed(2)}"
                               step="0.01" min="0" required>
                        <small class="text-muted">แก้ไขได้</small>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end stock-quantity" 
                               name="items[${itemCounter}][stock_quantity]" 
                               step="0.01" readonly>
                    </td>
                    <td>
                        <select class="form-select form-select-sm stock-unit-select bg-warning bg-opacity-25" 
                                name="items[${itemCounter}][stock_unit_id]" 
                                data-row="${itemCounter}" required>
                            ${stockUnitOptions}
                        </select>
                        <small class="text-muted">เปลี่ยนได้</small>
                    </td>
                    <td>
                        <select class="form-select form-select-sm location-select bg-warning bg-opacity-25" 
                                name="items[${itemCounter}][location_id]">
                            <option value="">-- Aisle (แยก) --</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end unit-cost" 
                               name="items[${itemCounter}][unit_cost]" 
                               value="${unitCost}" 
                               step="0.01" min="0" readonly>
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary btn-split-item" 
                                    data-row="${itemCounter}" title="แยกรายการ">
                                <i class="bi bi-scissors"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger remove-item-btn-button" 
                                    data-row="${itemCounter}" title="ลบ">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            
            // แทรกแถวใหม่ถัดจากแถวเดิม
            sourceRow.after(newRow);
            
            // โหลด Locations สำหรับแถวใหม่
            const warehouseId = $('#warehouse_id').val();
            if (warehouseId) {
                loadLocationForRow(itemCounter, warehouseId, locationId);
            }
            
            // คำนวณจำนวนสต็อกสำหรับแถวใหม่
            const newRowElement = $(`tr[data-row-id="${itemCounter}"]`);
            newRowElement.find('.quantity-received').trigger('input');
            
            // แสดงข้อมูลกระดาษถ้ามี
            if (weightPerSheet) {
                const conversionInfo = sourceRow.find('.unit-conversion-info').html();
                newRowElement.find('.unit-conversion-info').html(conversionInfo).removeClass('d-none');
            }
            
            calculateTotal();
            reorderRows();
            
            Swal.fire({
                icon: 'success',
                title: 'แยกรายการสำเร็จ',
                html: 'สร้างรายการใหม่แล้ว<br><small class="text-muted">แก้ไขจำนวน, หน่วยสต็อก, Lot และ Location ได้</small>',
                showConfirmButton: false,
                timer: 1500
            });
        }
        
        // จัดลำดับแถวใหม่
        function reorderRows() {
            let counter = 1;
            $('#itemsTableBody tr').each(function() {
                $(this).find('td:first').text(counter);
                counter++;
            });
        }
        
        // คำนวณยอดรวม
        function calculateTotal() {
            let totalQty = 0;
            let totalAmount = 0;
            
            $('#itemsTableBody tr').each(function() {
                const qty = parseFloat($(this).find('.quantity-received').val()) || 0;
                const cost = parseFloat($(this).find('.unit-cost').val()) || 0;
                
                totalQty += qty;
                totalAmount += (qty * cost);
            });
            
            $('#totalQuantity').text(totalQty.toFixed(2));
            $('#totalAmount').text(totalAmount.toLocaleString('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }));
        }
        
        // Submit form
        $('#goodsReceiptForm').submit(function(e) {
            e.preventDefault();
            
            // ตรวจสอบว่ามีรายการสินค้าหรือไม่
            if ($('#itemsTableBody tr').length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'ไม่มีรายการสินค้า',
                    text: 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ'
                });
                return false;
            }
            
            // ตรวจสอบว่าเลือกประเภทการรับเข้าหรือไม่
            if (!$('#receipt_type').val()) {
                Swal.fire({
                    icon: 'warning',
                    title: 'กรุณาเลือกประเภทการรับเข้า',
                    text: 'เลือก "รับตาม PO" หรือ "รับไม่มี PO"'
                });
                return false;
            }
            
            Swal.fire({
                title: 'ยืนยันการบันทึก',
                text: 'คุณต้องการบันทึกการรับเข้าสินค้านี้ใช่หรือไม่?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = $(this).serialize();
                    
                    $.ajax({
                        url: '../api/save_goods_receipt.php',
                        method: 'POST',
                        data: formData,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'บันทึกสำเร็จ',
                                    text: 'บันทึกการรับเข้าสินค้าเรียบร้อยแล้ว',
                                    showConfirmButton: false,
                                    timer: 1500
                                }).then(() => {
                                    window.location.href = 'goods_receipt_list.php';
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาด',
                                    text: response.message || 'ไม่สามารถบันทึกข้อมูลได้'
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้'
                            });
                            console.error(error);
                        }
                    });
                }
            });
        });
        
        // คำนวณยอดรวมเมื่อเปลี่ยนราคา
        $(document).on('input', '.unit-cost', function() {
            calculateTotal();
        });
    });
    </script>
</body>
</html>