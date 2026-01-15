<?php
// pages/po/create_material_form.php - ฟอร์ม Material PO (โหลดผ่าน AJAX)
require_once "../../config/config.php";
require_once "../../config/database.php";

// เชื่อมต่อฐานข้อมูล
try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // ดึงข้อมูล Suppliers
    $stmt = $conn->prepare("SELECT supplier_id, supplier_code, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูล Products
    $stmt = $conn->prepare("
        SELECT p.id, p.SSP_Code, p.Name, p.Name2, u.unit_name, u.unit_symbol,
               g.name as group_name, mt.type_name as material_type_name,
               p.supplier_id
        FROM Master_Products_ID p 
        LEFT JOIN Units u ON p.Unit_id = u.unit_id 
        LEFT JOIN Groups g ON p.group_id = g.id
        LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
        WHERE p.is_active = 1 AND p.status = 1 
        ORDER BY p.SSP_Code, p.Name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูล Units
    $stmt = $conn->prepare("SELECT unit_id, unit_code, unit_name, unit_name_th, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name");
    $stmt->execute();
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $suppliers = [];
    $products = [];
    $units = [];
}
?>

<!-- Material PO Form -->
<style>
    .card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        margin-bottom: 2rem;
    }
    
    .card-header {
        background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        color: white;
        border-radius: 15px 15px 0 0 !important;
        padding: 1.25rem;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .items-table th {
        background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
        color: white;
        padding: 15px;
        text-align: left;
        font-weight: 600;
    }
    
    .items-table td {
        padding: 12px 15px;
        border-bottom: 1px solid #e9ecef;
    }
    
    .items-table tr:hover {
        background: #f8f9fa;
    }
    
    .items-table input,
    .items-table select {
        width: 100%;
        border: 1px solid #ddd;
        padding: 8px;
        border-radius: 4px;
    }
    
    .calculated-field {
        background-color: #f8f9fa !important;
    }
    
    .required {
        color: #dc3545;
    }
</style>

<form method="POST" action="process_material.php" id="materialPOForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="po_type" value="material">
    
    <!-- Header Card -->
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">
                    <i class="fas fa-boxes-stacked me-2"></i>สร้าง Material PO
                </h4>
                <button type="button" class="btn btn-light btn-sm" onclick="resetForm()">
                    <i class="fas fa-arrow-left me-1"></i>เปลี่ยนประเภท
                </button>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">
                <i class="fas fa-info-circle me-2"></i>
                กรอกข้อมูลการสั่งซื้อวัตถุดิบ ระบบจะคำนวณยอดรวมอัตโนมัติ
            </p>
        </div>
    </div>
    
    <!-- ข้อมูลพื้นฐาน -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-clipboard-list me-2"></i>ข้อมูลพื้นฐาน
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="po_number" class="form-label">
                        เลขที่ PO <span class="required">*</span>
                    </label>
                    <input type="text" class="form-control" id="po_number" name="po_number" 
                           placeholder="กรอกเลขที่ PO เช่น MAT-2024-001" required
                           style="font-family: 'Courier New', monospace; font-weight: bold;">
                    <small class="text-muted">ตัวอย่าง: MAT-2024-001, PO-202412-001</small>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="po_date" class="form-label">
                        วันที่ <span class="required">*</span>
                    </label>
                    <input type="date" class="form-control" id="po_date" name="po_date" 
                           value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="supplier_id" class="form-label">
                        Supplier <span class="required">*</span>
                    </label>
                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                        <option value="">เลือก Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= htmlspecialchars($supplier['supplier_id']) ?>">
                            <?= htmlspecialchars($supplier['supplier_name']) ?> 
                            (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="notes" class="form-label">หมายเหตุ</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" 
                              placeholder="หมายเหตุเพิ่มเติม"></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- รายละเอียดสินค้า -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-box me-2"></i>รายละเอียดสินค้า
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-lightbulb me-2"></i>
                <strong>คำแนะนำ:</strong> เลือกสินค้าจาก Master Products กรอกจำนวนและราคา ระบบจะคำนวณยอดรวมอัตโนมัติ
            </div>
            
            <table class="items-table" id="material-items-table">
                <thead>
                    <tr>
                        <th width="30%">รหัสสินค้า</th>
                        <th width="12%">จำนวน</th>
                        <th width="15%">หน่วย</th>
                        <th width="12%">ราคา/หน่วย</th>
                        <th width="12%">รวม</th>
                        <th width="14%">หมายเหตุ</th>
                        <th width="5%">ลบ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <select name="product_id[]" class="form-select product-select" required>
                                <option value="">-- เลือกสินค้า --</option>
                                <?php foreach ($products as $product): ?>
                                <option value="<?= htmlspecialchars($product['id']) ?>" 
                                        data-ssp="<?= htmlspecialchars($product['SSP_Code']) ?>"
                                        data-name="<?= htmlspecialchars($product['Name']) ?>"
                                        data-supplier-id="<?= htmlspecialchars($product['supplier_id'] ?? '') ?>">
                                    <?= htmlspecialchars($product['SSP_Code']) ?> - 
                                    <?= htmlspecialchars($product['Name']) ?>
                                    <?php if (!empty($product['Name2'])): ?>
                                        | <?= htmlspecialchars($product['Name2']) ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="quantity[]" step="0.01" required 
                                   placeholder="0.00" class="form-control quantity-input">
                        </td>
                        <td>
                            <select name="purchase_unit_id[]" class="form-select" required>
                                <option value="">-- หน่วย --</option>
                                <?php foreach ($units as $unit): ?>
                                <option value="<?= htmlspecialchars($unit['unit_id']) ?>">
                                    <?= htmlspecialchars($unit['unit_name']) ?>
                                    <?php if (!empty($unit['unit_symbol'])): ?>
                                        (<?= htmlspecialchars($unit['unit_symbol']) ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="unit_price[]" step="0.01" required 
                                   placeholder="0.00" class="form-control price-input">
                        </td>
                        <td>
                            <input type="text" name="total_price[]" readonly 
                                   class="form-control calculated-field total-price">
                        </td>
                        <td>
                            <input type="text" name="notes_item[]" placeholder="หมายเหตุ" 
                                   class="form-control">
                        </td>
                        <td>
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div class="mt-3">
                <button type="button" class="btn btn-primary" onclick="addMaterialRow()">
                    <i class="fas fa-plus me-2"></i>เพิ่มรายการ
                </button>
            </div>
            
            <!-- สรุปยอดรวม -->
            <div class="row mt-4">
                <div class="col-md-6 ms-auto">
                    <div class="card">
                        <div class="card-header bg-light text-dark">
                            <h6 class="mb-0">สรุปยอดรวม</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span>ยอดรวมสินค้า:</span>
                                <span id="material-total" class="fw-bold">0.00 บาท</span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold fs-5">รวมทั้งหมด:</span>
                                <span id="material-grand-total" class="fw-bold text-primary fs-5">0.00 บาท</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ปุ่มดำเนินการ -->
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-times me-2"></i>ยกเลิก
                </button>
                <div>
                    <button type="reset" class="btn btn-warning me-2">
                        <i class="fas fa-redo me-2"></i>รีเซ็ตฟอร์ม
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>สร้าง Material PO
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Initialize form when loaded
function initializePOForm() {
    console.log('Initializing Material PO Form...');
    
    // Initialize Select2 for suppliers
    $('#supplier_id').select2({
        theme: 'bootstrap-5',
        placeholder: 'พิมพ์ค้นหาหรือเลือก Supplier',
        allowClear: true,
        width: '100%'
    });
    
    // Add event listeners to existing rows
    addMaterialRowListeners(document.querySelector('#material-items-table tbody tr'));
    
    // Supplier filter
    $('#supplier_id').on('change', function() {
        const selectedSupplierId = this.value;
        updateProductsBySupplier(selectedSupplierId);
    });
}

// Add Material Row
function addMaterialRow() {
    const table = document.getElementById('material-items-table').getElementsByTagName('tbody')[0];
    const newRow = table.rows[0].cloneNode(true);
    
    // Reset values
    newRow.querySelectorAll('input, select').forEach(field => {
        if (field.type === 'text' || field.type === 'number') {
            field.value = '';
        } else if (field.tagName === 'SELECT') {
            field.selectedIndex = 0;
        }
    });
    
    table.appendChild(newRow);
    addMaterialRowListeners(newRow);
}

// Remove Row
function removeRow(button) {
    const row = button.closest('tr');
    const table = row.closest('tbody');
    
    if (table.rows.length > 1) {
        row.remove();
        calculateMaterialTotal();
    } else {
        showAlert('ต้องมีรายการอย่างน้อย 1 รายการ', 'warning');
    }
}

// Add event listeners to material row
function addMaterialRowListeners(row) {
    const quantityInput = row.querySelector('.quantity-input');
    const priceInput = row.querySelector('.price-input');
    
    if (quantityInput) {
        quantityInput.addEventListener('input', calculateRowTotal);
    }
    if (priceInput) {
        priceInput.addEventListener('input', calculateRowTotal);
    }
}

// Calculate row total
function calculateRowTotal(event) {
    const row = event.target.closest('tr');
    const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const totalField = row.querySelector('.total-price');
    
    const total = quantity * price;
    totalField.value = total.toFixed(2);
    
    calculateMaterialTotal();
}

// Calculate material total
function calculateMaterialTotal() {
    let total = 0;
    document.querySelectorAll('.total-price').forEach(field => {
        total += parseFloat(field.value) || 0;
    });
    
    document.getElementById('material-total').textContent = total.toFixed(2) + ' บาท';
    document.getElementById('material-grand-total').textContent = total.toFixed(2) + ' บาท';
}

// Update products by supplier
function updateProductsBySupplier(selectedSupplierId) {
    const productSelects = document.querySelectorAll('select[name="product_id[]"]');
    
    productSelects.forEach(select => {
        const options = select.querySelectorAll('option');
        
        options.forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
            } else {
                const productSupplierId = option.dataset.supplierId;
                
                if (!selectedSupplierId || productSupplierId === selectedSupplierId) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            }
        });
        
        // Reset if selected option is hidden
        if (select.value) {
            const selectedOption = select.querySelector(`option[value="${select.value}"]`);
            if (selectedOption && selectedOption.style.display === 'none') {
                select.value = '';
            }
        }
    });
    
    if (selectedSupplierId) {
        showAlert('กรองแสดงเฉพาะสินค้าของ Supplier ที่เลือกแล้ว', 'info');
    }
}

// Form submit
document.getElementById('materialPOForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate
    if (!this.checkValidity()) {
        e.stopPropagation();
        this.classList.add('was-validated');
        showAlert('กรุณากรอกข้อมูลให้ครบถ้วน', 'danger');
        return;
    }
    
    // Submit form
    showAlert('กำลังสร้าง Material PO...', 'info');
    this.submit();
});
</script>