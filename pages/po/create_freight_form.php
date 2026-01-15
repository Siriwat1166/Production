<?php
// pages/po/create_freight_form.php - ฟอร์ม Freight PO (โหลดผ่าน AJAX)
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
    
    // ดึง Material POs สำหรับเชื่อมโยง
    $stmt = $conn->prepare("
        SELECT ph.po_id, ph.po_number, ph.supplier_id, ph.total_amount, ph.po_date, s.supplier_name
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        WHERE ph.is_material_po = 1 AND ph.status IN ('Draft', 'Approved', 'Partial') 
        ORDER BY ph.po_date DESC, ph.po_number DESC
    ");
    $stmt->execute();
    $material_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $suppliers = [];
    $material_pos = [];
}
?>

<!-- Freight PO Form -->
<style>
    .freight-type-selector {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .freight-type-option {
        padding: 15px;
        border: 2px solid #d1e7ff;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
        background: white;
    }

    .freight-type-option:hover {
        border-color: #3498db;
        box-shadow: 0 4px 8px rgba(52, 152, 219, 0.2);
    }

    .freight-type-option.selected {
        border-color: #2ecc71;
        background: #d5f4e6;
    }
    
    .freight-type-option h6 {
        margin-bottom: 0.5rem;
        color: #2c3e50;
    }
    
    .freight-type-option small {
        color: #6c757d;
    }
</style>

<form method="POST" action="process_freight.php" id="freightPOForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="po_type" value="freight">
    
    <!-- Header Card -->
    <div class="card">
        <div class="card-header" style="background: linear-gradient(135deg, #3498db 0%, #5dade2 100%);">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">
                    <i class="fas fa-truck me-2"></i>สร้าง Freight PO
                </h4>
                <button type="button" class="btn btn-light btn-sm" onclick="resetForm()">
                    <i class="fas fa-arrow-left me-1"></i>เปลี่ยนประเภท
                </button>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">
                <i class="fas fa-info-circle me-2"></i>
                กรอกข้อมูลค่าขนส่งและค่าใช้จ่ายเพิ่มเติม สามารถเชื่อมโยงกับ Material PO ได้
            </p>
        </div>
    </div>
    
    <!-- ข้อมูลพื้นฐาน -->
    <div class="card">
        <div class="card-header" style="background: linear-gradient(135deg, #3498db 0%, #5dade2 100%);">
            <h5 class="mb-0">
                <i class="fas fa-clipboard-list me-2"></i>ข้อมูลพื้นฐาน
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="po_number_freight" class="form-label">
                        เลขที่ PO <span class="required">*</span>
                    </label>
                    <input type="text" class="form-control" id="po_number_freight" name="po_number" 
                           placeholder="กรอกเลขที่ PO เช่น FRT-2024-001" required
                           style="font-family: 'Courier New', monospace; font-weight: bold;">
                    <small class="text-muted">ตัวอย่าง: FRT-2024-001, SHIP-202412-001</small>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="po_date_freight" class="form-label">
                        วันที่ <span class="required">*</span>
                    </label>
                    <input type="date" class="form-control" id="po_date_freight" name="po_date" 
                           value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="supplier_id_freight" class="form-label">
                        Supplier (ผู้ให้บริการขนส่ง) <span class="required">*</span>
                    </label>
                    <select class="form-select" id="supplier_id_freight" name="supplier_id" required>
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
                    <label for="linked_po_id" class="form-label">
                        เลือก Material PO ที่ต้องการเชื่อมโยง
                    </label>
                    <select class="form-select" id="linked_po_id" name="linked_po_id">
                        <option value="">-- ไม่เชื่อมโยงกับ PO ใด (สร้างแยก) --</option>
                        <?php foreach ($material_pos as $po): ?>
                        <option value="<?= htmlspecialchars($po['po_id']) ?>">
                            <?= htmlspecialchars($po['po_number']) ?> - 
                            <?= htmlspecialchars($po['supplier_name']) ?> 
                            (<?= number_format($po['total_amount'], 2) ?> บาท) - 
                            <?= date('d/m/Y', strtotime($po['po_date'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">
                        <i class="fas fa-link me-1"></i>
                        เลือก Material PO ที่ต้องการเพิ่มค่าขนส่ง (ถ้ามี)
                    </small>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="notes_freight" class="form-label">หมายเหตุ</label>
                    <textarea class="form-control" id="notes_freight" name="notes" rows="2" 
                              placeholder="หมายเหตุเพิ่มเติม"></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- รายละเอียดค่าขนส่ง -->
    <div class="card">
        <div class="card-header" style="background: linear-gradient(135deg, #3498db 0%, #5dade2 100%);">
            <h5 class="mb-0">
                <i class="fas fa-money-bill-wave me-2"></i>รายละเอียดค่าใช้จ่าย
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-lightbulb me-2"></i>
                <strong>คำแนะนำ:</strong> เลือกประเภทค่าใช้จ่ายด้านล่าง แล้วกรอกรายละเอียดและจำนวนเงิน
            </div>
            
            <h6 class="mb-3">เลือกประเภทค่าใช้จ่าย</h6>
            <div class="freight-type-selector">
                <div class="freight-type-option" data-freight-type="shipping">
                    <h6>🚢 ค่าขนส่ง</h6>
                    <small>ค่าขนส่งทางเรือ บก อากาศ</small>
                </div>
                <div class="freight-type-option" data-freight-type="customs">
                    <h6>🏛️ ค่าภาษีศุลกากร</h6>
                    <small>ภาษีนำเข้า ค่าธรรมเนียม</small>
                </div>
                <div class="freight-type-option" data-freight-type="insurance">
                    <h6>🛡️ ค่าประกันภัย</h6>
                    <small>ประกันสินค้า ประกันขนส่ง</small>
                </div>
                <div class="freight-type-option" data-freight-type="handling">
                    <h6>📦 ค่าดำเนินการ</h6>
                    <small>ค่าจัดการ ค่าบริการ</small>
                </div>
                <div class="freight-type-option" data-freight-type="other">
                    <h6>📋 อื่นๆ</h6>
                    <small>ค่าใช้จ่ายอื่นๆ</small>
                </div>
            </div>
            
            <table class="items-table" id="freight-items-table">
                <thead>
                    <tr>
                        <th width="20%">ประเภทค่าใช้จ่าย</th>
                        <th width="30%">รายละเอียด</th>
                        <th width="15%">จำนวนเงิน</th>
                        <th width="30%">หมายเหตุ</th>
                        <th width="5%">ลบ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <select name="freight_type[]" class="form-select freight-type-select" required>
                                <option value="">-- เลือกประเภท --</option>
                                <option value="shipping">🚢 ค่าขนส่ง</option>
                                <option value="customs">🏛️ ค่าภาษีศุลกากร</option>
                                <option value="insurance">🛡️ ค่าประกันภัย</option>
                                <option value="handling">📦 ค่าดำเนินการ</option>
                                <option value="other">📋 อื่นๆ</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="freight_description[]" required 
                                   placeholder="ระบุรายละเอียด" class="form-control">
                        </td>
                        <td>
                            <input type="number" name="freight_amount[]" step="0.01" required 
                                   placeholder="0.00" class="form-control freight-amount-input">
                        </td>
                        <td>
                            <input type="text" name="freight_notes[]" 
                                   placeholder="หมายเหตุ" class="form-control">
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
                <button type="button" class="btn btn-primary" onclick="addFreightRow()">
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
                                <span>ยอดรวมค่าขนส่ง:</span>
                                <span id="freight-total" class="fw-bold">0.00 บาท</span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold fs-5">รวมทั้งหมด:</span>
                                <span id="freight-grand-total" class="fw-bold text-primary fs-5">0.00 บาท</span>
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
                        <i class="fas fa-save me-2"></i>สร้าง Freight PO
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Initialize form when loaded
function initializePOForm() {
    console.log('Initializing Freight PO Form...');
    
    // Initialize Select2
    $('#supplier_id_freight, #linked_po_id').select2({
        theme: 'bootstrap-5',
        placeholder: 'เลือกรายการ',
        allowClear: true,
        width: '100%'
    });
    
    // Add event listeners to existing rows
    addFreightRowListeners(document.querySelector('#freight-items-table tbody tr'));
    
    // Freight type selector
    document.querySelectorAll('.freight-type-option').forEach(option => {
        option.addEventListener('click', function() {
            const freightType = this.dataset.freightType;
            addFreightRowWithType(freightType);
            
            // Visual feedback
            this.classList.add('selected');
            setTimeout(() => {
                this.classList.remove('selected');
            }, 300);
        });
    });
}

// Add Freight Row
function addFreightRow() {
    const table = document.getElementById('freight-items-table').getElementsByTagName('tbody')[0];
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
    addFreightRowListeners(newRow);
}

// Add Freight Row with specific type
function addFreightRowWithType(freightType) {
    const table = document.getElementById('freight-items-table').getElementsByTagName('tbody')[0];
    const newRow = table.rows[0].cloneNode(true);
    
    // Reset values
    newRow.querySelectorAll('input').forEach(field => {
        field.value = '';
    });
    
    // Set freight type
    const selectField = newRow.querySelector('.freight-type-select');
    selectField.value = freightType;
    
    table.appendChild(newRow);
    addFreightRowListeners(newRow);
    
    showAlert(`เพิ่มรายการ ${selectField.options[selectField.selectedIndex].text} แล้ว`, 'success');
}

// Remove Row
function removeRow(button) {
    const row = button.closest('tr');
    const table = row.closest('tbody');
    
    if (table.rows.length > 1) {
        row.remove();
        calculateFreightTotal();
    } else {
        showAlert('ต้องมีรายการอย่างน้อย 1 รายการ', 'warning');
    }
}

// Add event listeners to freight row
function addFreightRowListeners(row) {
    const amountInput = row.querySelector('.freight-amount-input');
    
    if (amountInput) {
        amountInput.addEventListener('input', calculateFreightTotal);
    }
}

// Calculate freight total
function calculateFreightTotal() {
    let total = 0;
    document.querySelectorAll('.freight-amount-input').forEach(field => {
        total += parseFloat(field.value) || 0;
    });
    
    document.getElementById('freight-total').textContent = total.toFixed(2) + ' บาท';
    document.getElementById('freight-grand-total').textContent = total.toFixed(2) + ' บาท';
}

// Form submit
document.getElementById('freightPOForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate
    if (!this.checkValidity()) {
        e.stopPropagation();
        this.classList.add('was-validated');
        showAlert('กรุณากรอกข้อมูลให้ครบถ้วน', 'danger');
        return;
    }
    
    // Submit form
    showAlert('กำลังสร้าง Freight PO...', 'info');
    this.submit();
});
</script>