// Global variables
let currentPOData = {};
let currentItems = [];

// Set current date on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded, initializing...');
    
    const currentDateElement = document.getElementById('currentDate');
    if (currentDateElement) {
        currentDateElement.textContent = new Date().toLocaleDateString('th-TH');
    }
    
    // Initialize debug functions
    initializeDebugFunctions();
    
    // Close modal when clicking outside
    const receiptModal = document.getElementById('receiptModal');
    if (receiptModal) {
        receiptModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeReceiptModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('receiptModal');
            if (modal && modal.style.display === 'block') {
                closeReceiptModal();
            }
        }
    });

    // Add invoice validation event listener
    setTimeout(() => {
        const invoiceInput = document.getElementById('invoiceNumber');
        if (invoiceInput) {
            invoiceInput.addEventListener('blur', validateInvoiceNumber);
        }
    }, 1000);
});

// Enhanced modal opening with conversion support
function openReceiptModalWithConversion(poNumber) {
    console.log('Opening receipt modal for PO:', poNumber);
    showLoading();
    
    callApi('get_po_data_with_conversions', { po_number: poNumber })
        .then(data => {
            hideLoading();
            
            if (!data || !data.po_data || !data.items) {
                throw new Error('ข้อมูล PO ไม่ครบถ้วน');
            }
            
            currentPOData = data.po_data;
            currentItems = data.items;
            
            // Populate modal with data
            updateModalContent(data.po_data);
            
            // Load items with conversion support
            loadItemsWithConversion(data.items);
            
            // Show modal
            document.getElementById('receiptModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Initialize summary
            updateSummaryWithConversion();
        })
        .catch(error => {
            hideLoading();
            console.error('Error loading PO data:', error);
            alert(error.message);
        });
}

// Update modal content
function updateModalContent(data) {
    const elements = {
        modalPONumber: document.getElementById('modalPONumber'),
        receiptPONumber: document.getElementById('receiptPONumber'),
        supplierName: document.getElementById('supplierName'),
        poDate: document.getElementById('poDate'),
        totalItems: document.getElementById('totalItems'),
        totalAmount: document.getElementById('totalAmount'),
        approvedBy: document.getElementById('approvedBy')
    };

    try {
        if (elements.modalPONumber && data.po_number) {
            elements.modalPONumber.textContent = data.po_number;
        }
        if (elements.receiptPONumber && data.po_number) {
            elements.receiptPONumber.textContent = data.po_number;
        }
        if (elements.supplierName) {
            elements.supplierName.textContent = data.supplier_name || 'ไม่ระบุ';
        }
        if (elements.poDate && data.po_date) {
            elements.poDate.textContent = formatDate(data.po_date);
        }
        if (elements.totalItems && currentItems && Array.isArray(currentItems)) {
            elements.totalItems.textContent = currentItems.length;
        }
        if (elements.totalAmount && data.total_amount) {
            elements.totalAmount.textContent = numberFormat(data.total_amount || 0);
        }
        if (elements.approvedBy) {
            elements.approvedBy.textContent = data.approved_by_name || 'ยังไม่อนุมัติ';
        }
        
        // Set current date
        const receiptDateElement = document.getElementById('receiptDate');
        if (receiptDateElement) {
            receiptDateElement.value = new Date().toISOString().split('T')[0];
        }
    } catch (error) {
        console.error('Error updating modal content:', error);
    }
}

function toggleLotSection(itemIndex) {
    const lotSection = document.getElementById(`lot-section-${itemIndex}`);
    if (lotSection) {
        const isVisible = lotSection.style.display !== 'none';
        lotSection.style.display = isVisible ? 'none' : 'block';
        
        // Update button icon
        const button = event.target.closest('button');
        if (button) {
            const icon = button.querySelector('i');
            if (icon) {
                icon.className = isVisible ? 'fas fa-barcode' : 'fas fa-times';
            }
        }
    }
}

function loadWarehouses() {
    callApi('get_warehouses', {})
        .then(data => {
            if (data && data.success && Array.isArray(data.warehouses)) {
                const warehouseSelect = document.getElementById('warehouseSelect');
                if (warehouseSelect) {
                    warehouseSelect.innerHTML = '<option value="">เลือกคลังสินค้า</option>';
                    
                    data.warehouses.forEach(warehouse => {
                        if (warehouse && warehouse.warehouse_id) {
                            const option = document.createElement('option');
                            option.value = warehouse.warehouse_id;
                            option.textContent = `${warehouse.warehouse_name_th || warehouse.warehouse_name} (${warehouse.warehouse_code})`;
                            warehouseSelect.appendChild(option);
                        }
                    });
                    
                    // Set default warehouse if only one available
                    if (data.warehouses.length === 1) {
                        warehouseSelect.value = data.warehouses[0].warehouse_id;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error loading warehouses:', error);
        });
}

function loadReceivingStatuses() {
    callApi('get_receiving_statuses', {})
        .then(data => {
            if (data && data.success && Array.isArray(data.statuses)) {
                // Store statuses for later use
                window.receivingStatuses = data.statuses;
            }
        })
        .catch(error => {
            console.error('Error loading receiving statuses:', error);
        });
}

function validateInvoiceNumber() {
    const invoiceInput = document.getElementById('invoiceNumber');
    if (!invoiceInput) return;
    
    const invoiceNumber = invoiceInput.value;
    
    if (!invoiceNumber || !currentPOData || !currentPOData.supplier_id) {
        return;
    }
    
    callApi('validate_invoice_number', {
        invoice_number: invoiceNumber,
        supplier_id: currentPOData.supplier_id
    })
    .then(data => {
        if (data && data.success) {
            if (data.exists) {
                showWarning('เลขที่ใบแจ้งหนี้นี้มีอยู่แล้วในระบบ');
            }
        }
    })
    .catch(error => {
        console.error('Error validating invoice:', error);
    });
}

function showWarning(message) {
    // Create or update warning message
    let warningDiv = document.getElementById('invoice-warning');
    if (!warningDiv) {
        warningDiv = document.createElement('div');
        warningDiv.id = 'invoice-warning';
        warningDiv.className = 'alert alert-warning mt-2';
        
        const invoiceInput = document.getElementById('invoiceNumber');
        if (invoiceInput && invoiceInput.parentNode) {
            invoiceInput.parentNode.appendChild(warningDiv);
        }
    }
    
    warningDiv.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i>${message}`;
    warningDiv.style.display = 'block';
    
    // Hide after 5 seconds
    setTimeout(() => {
        if (warningDiv) {
            warningDiv.style.display = 'none';
        }
    }, 5000);
}

// Enhanced loadItems function with unit conversion support
// ส่วนสร้าง HTML สำหรับแต่ละรายการสินค้า - เพิ่มฟิลด์ Pallet ในตำแหน่งหลัก
function loadItemsWithConversion(items) {
    const itemsList = document.getElementById('itemsList');
    if (!itemsList) {
        console.error('itemsList element not found');
        return;
    }
    
    if (!Array.isArray(items)) {
        console.error('Items is not an array:', items);
        itemsList.innerHTML = '<div class="alert alert-warning">ไม่พบข้อมูลรายการสินค้า</div>';
        return;
    }
    
    itemsList.innerHTML = '';
    
    items.forEach((item, index) => {
        if (!item) {
            console.warn('Item is null or undefined at index:', index);
            return;
        }
        
        const itemCard = document.createElement('div');
        itemCard.className = 'item-card';
        
        // สร้าง receiving unit options
        let receivingUnitOptions = '';
        if (item.receiving_units && Array.isArray(item.receiving_units) && item.receiving_units.length > 0) {
            item.receiving_units.forEach(unit => {
                if (unit) {
                    const selected = unit.unit_id == item.stock_unit_id ? 'selected' : '';
                    receivingUnitOptions += `
                        <option value="${unit.unit_id || ''}" 
                                data-conversion="${unit.conversion_factor || 1}"
                                data-conversion-factor="${unit.conversion_factor || 1}" 
                                data-symbol="${unit.unit_symbol || unit.code || ''}"
                                ${selected}>
                            ${unit.unit_name_th || unit.name || 'หน่วย'} (${unit.unit_symbol || unit.code || ''})
                        </option>
                    `;
                }
            });
        } else {
            receivingUnitOptions = `
                <option value="${item.purchase_unit_id || ''}" 
                        data-conversion="1"
                        data-conversion-factor="1"
                        data-symbol="${item.purchase_unit_symbol || ''}">
                    ${item.purchase_unit_name || 'หน่วย'}
                </option>
            `;
        }

        // ข้อมูลกระดาษ
        let paperboardInfo = '';
        if (item.paperboard_info && 
            item.paperboard_info.W_mm && 
            item.paperboard_info.L_mm && 
            item.paperboard_info.gsm) {
            paperboardInfo = `
                <div class="paperboard-info mt-2">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        ขนาด: ${item.paperboard_info.W_mm}×${item.paperboard_info.L_mm} มม. |
                        GSM: ${item.paperboard_info.gsm} |
                        น้ำหนัก/แผ่น: ${item.paperboard_info.Weight_kg_per_sheet || 0} กก. |
                        ${item.paperboard_info.sheets_per_kg || 0} แผ่น/กก.
                    </small>
                </div>
            `;
        }
        
    
// ===== ✅ Location Section แบบใหม่ - รองรับหลายพื้นที่ =====
const locationSection = `
    <!-- แสดงตารางโดยตรง ไม่มีส่วน toggle -->
    <div class="multiple-locations-section mt-3" id="multiple_locations_${index}">
        <div class="alert alert-info py-2 mb-2">
            <i class="fas fa-info-circle me-1"></i>
            <strong>กรอกข้อมูลแต่ละพื้นที่</strong> - แต่ละแถว = 1 พื้นที่ + 1 Lot
        </div>
        
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th width="20%">พื้นที่ <span class="text-danger">*</span></th>
                        <th width="15%">เลข Lot <span class="text-danger">*</span></th>
                        <th width="10%">จำนวน <span class="text-danger">*</span></th>
                        <th width="8%">Pallet</th>
                        <th width="12%">วันผลิต</th>
                        <th width="12%">วันหมดอายุ</th>
                        <th width="10%">สภาพ</th>
                        <th width="13%">หมายเหตุ</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody id="location_rows_${index}">
                    <!-- JavaScript จะเพิ่มแถวตรงนี้ -->
                </tbody>
                <tfoot class="table-secondary">
                    <tr>
                        <td colspan="2">
                            <button type="button" class="btn btn-sm btn-success" 
                                    onclick="addLocationRow(${index})">
                                <i class="fas fa-plus me-1"></i>เพิ่มพื้นที่
                            </button>
                        </td>
                        <td class="text-end fw-bold">รวม:</td>
                        <td>
                            <span id="total_qty_${index}" class="badge bg-primary fs-6">0</span>
                        </td>
                        <td colspan="5">
                            <small class="text-muted" id="footer_text_${index}">
                                ต้องรับ: <strong>${numberFormat(item.quantity || 0)}</strong> 
                                ${item.purchase_unit_name || 'หน่วย'}
                            </small>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
`;
        
        itemCard.innerHTML = `
    <div class="unit-conversion-indicator">
        <i class="fas fa-exchange-alt"></i> แปลงหน่วย
    </div>
    
    <div class="row align-items-center">
        <div class="col-md-1">
            <div class="item-image">
                <i class="fas fa-box"></i>
            </div>
        </div>
        <div class="col-md-3">
            <h6 class="fw-bold mb-1">${item.product_name || item.item_description || 'ไม่ระบุชื่อสินค้า'}</h6>
            <small class="text-muted">รหัส: ${item.product_code || item.SSP_Code || 'N/A'}</small>
            ${paperboardInfo}
        </div>
        <div class="col-md-2 text-center">
            <label class="form-label fw-bold">สั่งซื้อ</label>
            <div class="fw-bold fs-6 text-primary">
                ${numberFormat(item.quantity || 0)} ${item.purchase_unit_name || 'หน่วย'}
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">จำนวนที่รับ</label>
            <div class="input-group">
                <input type="number" class="form-control quantity-input text-center" 
                min="0" step="0.001" value="0" 
                data-ordered="${item.quantity || 0}" 
                data-item-index="${index}"
                data-product-id="${item.product_id || ''}"
                data-purchase-unit="${item.purchase_unit_id || ''}"
                onchange="updateQuantityWithConversion(this)"
                oninput="updateQuantityWithConversion(this)">
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">หน่วยรับ</label>
            <select class="form-select receiving-unit-select" 
                    data-item-index="${index}"
                    onchange="updateReceivingUnit(this)">
                ${receivingUnitOptions}
            </select>
        </div>
        <div class="col-md-1 text-center">
            <label class="form-label fw-bold">สถานะ</label>
            <div class="status-display" id="status-${index}">
                <span class="badge bg-secondary">ยังไม่รับ</span>
            </div>
        </div>
        <div class="col-md-1 text-center">
            <button type="button" class="btn btn-outline-primary btn-sm" 
                    onclick="fillMaxQuantityConverted(this)" title="เติมเต็มจำนวน">
                <i class="fas fa-fill-drip"></i>
            </button>
        </div>
    </div>
    
    <div class="row mt-2">
        <div class="col-md-12">
            <div class="conversion-info" id="conversion-info-${index}" style="display: none;">
                <div class="alert alert-info py-2">
                    <small>
                        <i class="fas fa-exchange-alt me-1"></i>
                        <span class="conversion-text"></span>
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    ${locationSection}
`;
        
        itemsList.appendChild(itemCard);
    });
    
    // เรียก load warehouses หลังจากสร้าง items เสร็จ
    loadWarehouses();
    loadReceivingStatuses();
}

// API call function with enhanced error handling
async function callApi(action, data) {
    console.log('Calling API:', action);
    
    try {
        const requestBody = new URLSearchParams({
            ...data,
            action: action
        });
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: requestBody
        });

        const responseText = await response.text();
        console.log('Response received, length:', responseText.length);
        
        if (!responseText || responseText.trim() === '') {
            throw new Error('ไม่ได้รับข้อมูลจากเซิร์ฟเวอร์');
        }

        if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
            console.error('Received HTML instead of JSON');
            throw new Error('เซิร์ฟเวอร์ส่ง HTML กลับมาแทน JSON - อาจมี PHP Error');
        }

        let responseData;
        try {
            responseData = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Raw response:', responseText.substring(0, 500));
            throw new Error('เซิร์ฟเวอร์ส่งข้อมูลที่ไม่ใช่ JSON กลับมา');
        }

        if (!responseData || typeof responseData !== 'object') {
            throw new Error('รูปแบบข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง');
        }

        if (!responseData.hasOwnProperty('success')) {
            throw new Error('ข้อมูลจากเซิร์ฟเวอร์ไม่มี success property');
        }

        if (!responseData.success) {
            throw new Error(responseData.message || 'การดำเนินการไม่สำเร็จ');
        }

        return responseData;

    } catch (error) {
        console.error('API Error:', error.message);
        
        if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
            throw new Error('ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้');
        }
        
        throw error;
    }
}

// Enhanced submit receipt with conversion - แก้ไข async/await
let isSubmitting = false; // เพิ่มตัวแปร global เพื่อป้องกันการ submit ซ้ำ

async function submitReceiptEnhanced() {
    console.log('Starting enhanced receipt submission with validation...');
    
    // ป้องกันการ submit หลายครั้ง
    if (isSubmitting) {
        console.log('กำลังประมวลผลอยู่... กรุณารอสักครู่');
        alert('กำลังประมวลผลอยู่ กรุณารอสักครู่');
        return;
    }
    
    isSubmitting = true;
    
    // ปิดปุ่ม submit และแสดงสถานะ
    const submitBtn = document.querySelector('button[onclick="submitReceiptEnhanced()"]');
    const originalBtnText = submitBtn?.innerHTML;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังบันทึก...';
    }
    
    try {
        // 1. ตรวจสอบข้อมูลพื้นฐาน
        const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
        let hasReceived = false;
        
        inputs.forEach(input => {
            if (parseFloat(input.value) > 0) {
                hasReceived = true;
            }
        });
        
        if (!hasReceived) {
            alert('กรุณาระบุจำนวนสินค้าที่รับเข้าอย่างน้อย 1 รายการ');
            return;
        }
        
        // 2. Validate ข้อมูลทางเซิร์ฟเวอร์
        console.log('Validating data...');
        const validation = await validateReceiptData();
        
        if (!validation.valid) {
            const errorMessage = 'พบข้อผิดพลาด:\n' + validation.errors.join('\n');
            alert(errorMessage);
            
            // แสดง debug info เพื่อช่วยแก้ไข
            await debugReceiptData();
            return;
        }
        
        // 3. ยืนยันการบันทึก
        const formData = collectEnhancedFormData();
        const itemsData = JSON.parse(formData.items_data);
        
        // ตรวจสอบ duplicate po_item_id ใน client-side
        const poItemIds = itemsData.map(item => item.po_item_id);
        const uniquePoItemIds = [...new Set(poItemIds)];
        
        if (poItemIds.length !== uniquePoItemIds.length) {
            console.error('Duplicate po_item_id found:', poItemIds);
            alert('พบข้อมูลซ้ำในรายการ กรุณาตรวจสอบอีกครั้ง');
            return;
        }
        
        let confirmMessage = 'ยืนยันการบันทึกรับเข้าสินค้าหรือไม่?\n\nเมื่อยืนยันแล้วจะไม่สามารถแก้ไขได้';
        
        // แสดงสรุปข้อมูล
        let summaryInfo = '\n\nสรุปข้อมูลที่จะบันทึก:';
        summaryInfo += `\nคลังสินค้า: ${document.getElementById('warehouseSelect')?.selectedOptions[0]?.text || 'ไม่ระบุ'}`;
        summaryInfo += `\nจำนวนรายการ: ${itemsData.length}`;
        
        const itemsWithLocation = itemsData.filter(item => item.location_id);
        const itemsWithLot = itemsData.filter(item => item.supplier_lot_number);
        
        if (itemsWithLocation.length > 0) {
            summaryInfo += `\nมีตำแหน่งเก็บ: ${itemsWithLocation.length} รายการ`;
        }
        
        if (itemsWithLot.length > 0) {
            summaryInfo += `\nมี Lot Number: ${itemsWithLot.length} รายการ`;
        }
        
        confirmMessage += summaryInfo;
        
        if (!confirm(confirmMessage)) {
            return;
        }

        // 4. ส่งข้อมูล
        showLoading();
        console.log('Sending data to server...');
        
        const result = await callApi('save_receipt_enhanced', formData);
        
        if (result && result.success) {
            let successMessage = `บันทึกการรับเข้าสำเร็จ!\n\nหมายเลข GR: ${result.gr_number || 'ไม่ระบุ'}`;
            
            if (result.total_amount) {
                successMessage += `\nมูลค่ารวม: ${numberFormat(result.total_amount)} บาท`;
            }
            
            if (result.processed_items) {
                successMessage += `\nรายการที่ประมวลผล: ${result.processed_items}`;
            }
            
            // แสดงข้อมูลที่บันทึกแล้ว
            let recordedData = '';
            if (itemsWithLocation.length > 0) {
                recordedData += `\n✓ บันทึกตำแหน่งเก็บ: ${itemsWithLocation.length} รายการ`;
            }
            if (itemsWithLot.length > 0) {
                recordedData += `\n✓ บันทึก Lot Number: ${itemsWithLot.length} รายการ`;
            }
            recordedData += '\n✓ สร้าง Stock Movement แล้ว';
            recordedData += '\n✓ อัปเดต Inventory แล้ว';
            
            successMessage += recordedData;
            
            alert(successMessage);
            
            // ป้องกันการกดปุ่มซ้ำหลังจากสำเร็จ
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>บันทึกเสร็จสิ้น';
                submitBtn.disabled = true;
            }
            
            // Reload หลังจาก delay เล็กน้อย
            setTimeout(() => {
                location.reload();
            }, 1000);
            
        } else {
            throw new Error(result?.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');
        }
        
    } catch (error) {
        console.error('Enhanced Submit Error:', error);
        alert('เกิดข้อผิดพลาด: ' + error.message);
        
        // แสดง debug info เมื่อเกิดข้อผิดพลาด
        await debugReceiptData();
    } finally {
        hideLoading();
        
        // คืนค่าปุ่มและสถานะ
        isSubmitting = false;
        
        if (submitBtn && originalBtnText) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    }
    
}

// เพิ่มปุ่ม debug ใน UI (เฉพาะ development)
function addDebugButton() {
    const isDevelopment = window.location.hostname === 'localhost' || 
                         window.location.hostname === '127.0.0.1' ||
                         window.location.search.includes('debug=1');
    
    if (isDevelopment) {
        const modalFooter = document.querySelector('.modal-body-custom .d-flex');
        if (modalFooter) {
            const debugBtn = document.createElement('button');
            debugBtn.type = 'button';
            debugBtn.className = 'btn btn-outline-warning btn-sm me-2';
            debugBtn.innerHTML = '<i class="fas fa-bug me-1"></i>Debug';
            debugBtn.onclick = debugReceiptData;
            
            modalFooter.insertBefore(debugBtn, modalFooter.firstChild);
        }
    }
}

// เพิ่มฟังก์ชันตรวจสอบข้อมูลอัตโนมัติ
function autoValidateForm() {
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    let hasReceivedItems = false;
    
    inputs.forEach(input => {
        if (parseFloat(input.value) > 0) {
            hasReceivedItems = true;
            
            const itemIndex = input.dataset.itemIndex;
            // ✅ ตรวจสอบว่าใช้โหมดไหน
const multipleSection = document.getElementById(`multiple_locations_${itemIndex}`);
let locationData = {};

if (multipleSection && multipleSection.style.display !== 'none') {
    // โหมดหลายพื้นที่
    const locations = [];
    const rows = document.querySelectorAll(`#location_rows_${itemIndex} tr.location-row`);
    
    rows.forEach((row, rowIdx) => {
        const qty = parseFloat(row.querySelector(`[name="qty_${itemIndex}_${rowIdx}"]`)?.value);
        if (qty && qty > 0) {
            locations.push({
                location_id: row.querySelector(`[name="location_${itemIndex}_${rowIdx}"]`)?.value,
                supplier_lot_number: row.querySelector(`[name="lot_${itemIndex}_${rowIdx}"]`)?.value,
                received_quantity: qty,
                quantity_pallet: parseInt(row.querySelector(`[name="pallet_${itemIndex}_${rowIdx}"]`)?.value) || 0,
                manufacturing_date: row.querySelector(`[name="mfg_${itemIndex}_${rowIdx}"]`)?.value || null,
                supplier_expiry_date: row.querySelector(`[name="exp_${itemIndex}_${rowIdx}"]`)?.value || null,
                received_condition: row.querySelector(`[name="condition_${itemIndex}_${rowIdx}"]`)?.value || 'good',
                damage_notes: row.querySelector(`[name="notes_${itemIndex}_${rowIdx}"]`)?.value || null
            });
        }
    });
    
    if (locations.length > 0) {
        itemData.locations = locations;
        // ไม่ต้องเพิ่ม single location fields
    } else {
        console.warn(`Item ${itemIndex}: Multiple location mode but no locations added`);
        return; // skip item
    }
    
} else {
    // โหมดเดี่ยว (เหมือนเดิม)
    const locationSelect = document.querySelector(`.location-select[data-item-index="${itemIndex}"]`);
    itemData.location_id = locationSelect ? locationSelect.value : null;
    itemData.supplier_lot_number = supplierLotInput ? supplierLotInput.value : null;
    itemData.supplier_batch_code = supplierLotInput ? supplierLotInput.value : null;
    itemData.manufacturing_date = manufacturingDateInput ? manufacturingDateInput.value : null;
    itemData.supplier_expiry_date = expiryDateInput ? expiryDateInput.value : null;
    itemData.received_condition = conditionSelect ? conditionSelect.value : 'good';
    itemData.damage_notes = damageNotesInput ? damageNotesInput.value : null;
    itemData.quantity_pallet = quantityPalletInput ? parseFloat(quantityPalletInput.value) || null : null;
}
            const lotInput = document.querySelector(`.supplier-lot-input[data-item-index="${itemIndex}"]`);
            
            // เพิ่ม/ลบ class validation
            if (!locationSelect || !locationSelect.value) {
                input.classList.add('validation-warning');
            } else {
                input.classList.remove('validation-warning');
            }
        }
    });
    
    // เปิด/ปิดปุ่มบันทึกตามสถานะ
    const submitBtn = document.querySelector('button[onclick="submitReceiptEnhanced()"]');
    if (submitBtn) {
        submitBtn.disabled = !hasReceivedItems;
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // เพิ่มปุ่ม debug
    setTimeout(addDebugButton, 1000);
    
    // Auto validation เมื่อมีการเปลี่ยนแปลงข้อมูล
    document.addEventListener('input', function(event) {
        if (event.target.matches('#receiptModal input[type="number"]')) {
            setTimeout(autoValidateForm, 100);
        }
    });
    
    document.addEventListener('change', function(event) {
        if (event.target.matches('.location-select, .supplier-lot-input')) {
            setTimeout(autoValidateForm, 100);
        }
    });
});

// เพิ่มใน global scope
window.debugReceiptData = debugReceiptData;
window.validateReceiptData = validateReceiptData;
window.autoValidateForm = autoValidateForm;

console.log('Debug and validation functions loaded');
console.log('Available functions: debugReceiptData(), validateReceiptData(), autoValidateForm()');

// เพิ่ม function submitReceiptWithConversion เพื่อ backward compatibility
async function submitReceiptWithConversion() {
    return await submitReceiptEnhanced();
}

function collectEnhancedFormData() {
    try {
        const itemsData = [];
        const quantityInputs = document.querySelectorAll('#receiptModal .quantity-input');
        
        console.log('Found inputs:', quantityInputs.length);
        
        quantityInputs.forEach(input => {
            const itemIndex = parseInt(input.dataset.itemIndex);
            const item = currentItems[itemIndex];
            const receivedQuantity = parseFloat(input.value) || 0;
            
            console.log(`Item ${itemIndex}:`, {receivedQuantity, item});
            
            if (receivedQuantity > 0 && item) {
                // สร้าง itemData object
                const itemData = {
                    po_item_id: item.po_item_id,
                    product_id: item.product_id,
                    ordered_quantity: item.quantity,
                    received_quantity: receivedQuantity,
                    receiving_unit_id: document.querySelector(`select.receiving-unit-select[data-item-index="${itemIndex}"]`)?.value,
                    purchase_unit_id: item.purchase_unit_id,
                    stock_unit_id: item.stock_unit_id,
                    unit_price: item.unit_price || 0
                };
                
                // ตรวจสอบว่าใช้โหมดหลายพื้นที่หรือไม่
                const multipleSection = document.getElementById(`multiple_locations_${itemIndex}`);
                
                if (multipleSection && multipleSection.style.display !== 'none') {
                    // โหมดหลายพื้นที่
                    const locations = [];
                    const rows = document.querySelectorAll(`#location_rows_${itemIndex} tr.location-row`);
                    
                    console.log(`Rows for item ${itemIndex}:`, rows.length);
                    
                    rows.forEach((row, rowIdx) => {
                        const qty = parseFloat(row.querySelector(`[name="qty_${itemIndex}_${rowIdx}"]`)?.value);
                        const locationId = row.querySelector(`[name="location_${itemIndex}_${rowIdx}"]`)?.value;
                        const lotNumber = row.querySelector(`[name="lot_${itemIndex}_${rowIdx}"]`)?.value;
                        
                        console.log(`Row ${rowIdx}:`, {qty, locationId, lotNumber});
                        
                        if (qty && qty > 0 && locationId && lotNumber) {
                            locations.push({
                                location_id: locationId,
                                supplier_lot_number: lotNumber,
                                received_quantity: qty,
                                quantity_pallet: parseInt(row.querySelector(`[name="pallet_${itemIndex}_${rowIdx}"]`)?.value) || 0,
                                manufacturing_date: row.querySelector(`[name="mfg_${itemIndex}_${rowIdx}"]`)?.value || null,
                                supplier_expiry_date: row.querySelector(`[name="exp_${itemIndex}_${rowIdx}"]`)?.value || null,
                                received_condition: row.querySelector(`[name="condition_${itemIndex}_${rowIdx}"]`)?.value || 'good',
                                damage_notes: row.querySelector(`[name="notes_${itemIndex}_${rowIdx}"]`)?.value || null
                            });
                        }
                    });
                    
                    console.log('Locations collected:', locations);
                    
                    if (locations.length > 0) {
                        itemData.locations = locations;
                        itemsData.push(itemData); // ✅ เพิ่มใน array
                    } else {
                        console.warn(`Item ${itemIndex}: โหมดหลายพื้นที่แต่ไม่มี locations ที่ valid`);
                    }
                    
                } else {
                    // โหมดเดียว - ต้องมี location_id และ lot_number
                    const locationSelect = document.querySelector(`.location-select[data-item-index="${itemIndex}"]`);
                    const lotInput = document.querySelector(`.supplier-lot-input[data-item-index="${itemIndex}"]`);
                    
                    if (locationSelect?.value && lotInput?.value) {
                        itemData.location_id = locationSelect.value;
                        itemData.supplier_lot_number = lotInput.value;
                        itemData.supplier_batch_code = lotInput.value;
                        
                        // เพิ่มฟิลด์อื่นๆ
                        const mfgInput = document.querySelector(`[data-item-index="${itemIndex}"].manufacturing-date-input`);
                        const expInput = document.querySelector(`[data-item-index="${itemIndex}"].expiry-date-input`);
                        const conditionSelect = document.querySelector(`[data-item-index="${itemIndex}"].condition-select`);
                        const palletInput = document.querySelector(`[data-item-index="${itemIndex}"].pallet-input`);
                        
                        itemData.manufacturing_date = mfgInput?.value || null;
                        itemData.supplier_expiry_date = expInput?.value || null;
                        itemData.received_condition = conditionSelect?.value || 'good';
                        itemData.quantity_pallet = palletInput ? parseInt(palletInput.value) || 0 : 0;
                        itemData.damage_notes = null;
                        
                        itemsData.push(itemData); // ✅ เพิ่มใน array
                    } else {
                        console.warn(`Item ${itemIndex}: ขาด location_id หรือ lot_number`);
                        alert(`กรุณาระบุพื้นที่เก็บและเลข Lot สำหรับรายการที่ ${itemIndex + 1}`);
                    }
                }
            }
        });
        
        console.log('Final itemsData:', itemsData);
        
        if (itemsData.length === 0) {
            throw new Error('ไม่มีรายการสินค้าที่รับเข้าหรือข้อมูลไม่ครบถ้วน');
        }
        
        // ตรวจสอบ warehouse
        const warehouseId = document.getElementById('warehouseSelect')?.value;
        if (!warehouseId) {
            throw new Error('กรุณาเลือกคลังสินค้า');
        }
        
        return {
            po_data: JSON.stringify(currentPOData),
            items_data: JSON.stringify(itemsData),
            warehouse_id: warehouseId,
            general_notes: document.getElementById('generalNotes')?.value || ''
        };
        
    } catch (error) {
        console.error('collectEnhancedFormData error:', error);
        throw error;
    }
}

function validateFormData() {
    const errors = [];
    
    // ตรวจสอบ warehouse
    const warehouseSelect = document.getElementById('warehouseSelect');
    if (!warehouseSelect || !warehouseSelect.value) {
        errors.push('กรุณาเลือกคลังสินค้า');
    }
    
    // ตรวจสอบ location สำหรับรายการที่มีจำนวน
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    let missingLocations = [];
    
    inputs.forEach(input => {
        const receivedQuantity = parseFloat(input.value) || 0;
        if (receivedQuantity > 0) {
            const itemIndex = input.dataset.itemIndex;
            const locationSelect = document.querySelector(`.location-select[data-item-index="${itemIndex}"]`);
            
            if (!locationSelect || !locationSelect.value) {
                const item = currentItems[itemIndex];
                missingLocations.push(item?.product_name || `รายการที่ ${parseInt(itemIndex) + 1}`);
            }
        }
    });
    
    if (missingLocations.length > 0) {
        errors.push(`รายการต่อไปนี้ยังไม่ได้เลือกตำแหน่งที่เก็บ: ${missingLocations.join(', ')}`);
    }
    
    return errors;
}
// เพิ่ม function สำหรับ preview receipt
function previewReceipt() {
    try {
        const formData = collectEnhancedFormData();
        const poData = JSON.parse(formData.po_data);
        const itemsData = JSON.parse(formData.items_data);
        
        let previewHtml = `
            <div class="receipt-preview">
                <h5>ตัวอย่างใบรับสินค้า</h5>
                <p><strong>PO:</strong> ${poData.po_number}</p>
                <p><strong>คลังสินค้า:</strong> ${document.getElementById('warehouseSelect')?.selectedOptions[0]?.text || 'ไม่ระบุ'}</p>
                <p><strong>วันที่รับเข้า:</strong> ${document.getElementById('receiptDate')?.value || 'วันนี้'}</p>
                <h6>รายการสินค้า:</h6>
                <ul>
        `;
        
        itemsData.forEach(item => {
            previewHtml += `<li>${item.receiving_unit_name}: ${item.received_quantity} ${item.receiving_unit_name}</li>`;
        });
        
        previewHtml += `
                </ul>
                <p><strong>หมายเหตุ:</strong> ${formData.general_notes || 'ไม่มี'}</p>
            </div>
        `;
        
        // แสดงใน modal หรือ alert
        alert('ตัวอย่างข้อมูลถูกแสดงใน console (F12)');
        console.log('Receipt Preview:', previewHtml);
        
    } catch (error) {
        console.error('Preview error:', error);
        alert('ไม่สามารถสร้างตัวอย่างได้: ' + error.message);
    }
}

// Updated function to handle unit conversion when quantity changes
function updateQuantityWithConversion(input) {
    if (!input) return;
    
    const itemIndex = input.dataset.itemIndex;
    const productId = input.dataset.productId;
    const purchaseUnitId = input.dataset.purchaseUnit;
    const receivedQuantity = parseFloat(input.value) || 0;
    
    const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
    const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
    const statusElement = document.getElementById(`status-${itemIndex}`);
    const itemCard = input.closest('.item-card');
    const orderedQuantity = parseFloat(input.dataset.ordered) || 0;
    
    if (!receivingUnitSelect || !conversionInfo || !statusElement) {
        console.warn('Required elements not found for item:', itemIndex);
        return;
    }
    
    const receivingUnitId = receivingUnitSelect.value;
    
    if (productId && receivingUnitId && receivingUnitId !== purchaseUnitId) {
        callApi('calculate_conversion', {
            product_id: productId,
            from_unit: receivingUnitId,
            to_unit: purchaseUnitId,
            quantity: receivedQuantity
        })
        .then(data => {
            if (data && data.success) {
                const convertedToPurchaseUnit = data.converted_quantity || 0;
                calculateAndShowConversion(productId, purchaseUnitId, receivingUnitId, receivedQuantity, itemIndex);
                updateItemStatus(statusElement, convertedToPurchaseUnit, orderedQuantity);
                
                if (itemCard) {
                    itemCard.classList.add('has-conversion');
                }
            } else {
                updateItemStatus(statusElement, receivedQuantity, orderedQuantity);
            }
        })
        .catch(error => {
            console.error('Error calculating conversion:', error);
            updateItemStatus(statusElement, receivedQuantity, orderedQuantity);
        });
    } else {
        if (conversionInfo) {
            conversionInfo.style.display = 'none';
        }
        if (itemCard) {
            itemCard.classList.remove('has-conversion');
        }
        updateItemStatus(statusElement, receivedQuantity, orderedQuantity);
    }
    
    updateSummaryWithConversion();
}

// Function to update receiving unit
// เพิ่มในฟังก์ชัน updateReceivingUnit
function updateReceivingUnit(select) {
    if (!select) return;
    
    const itemIndex = select.dataset.itemIndex;
    const quantityInput = document.querySelector(`input[data-item-index="${itemIndex}"]`);
    const item = currentItems[itemIndex];
    
    if (!quantityInput || !item) {
        console.warn('Required elements not found for receiving unit update:', itemIndex);
        return;
    }
    
    if (item && item.product_id) {
        const orderedQuantity = parseFloat(item.quantity) || 0;
        const purchaseUnitId = item.purchase_unit_id;
        const receivingUnitId = select.value;
        
        if (receivingUnitId && receivingUnitId !== purchaseUnitId) {
            calculateConversionAndSetQuantity(
                item.product_id, 
                purchaseUnitId, 
                receivingUnitId, 
                orderedQuantity, 
                itemIndex
            );
            
            // ⭐ อัปเดตตารางด้านล่าง
            updateTableFooterUnit(itemIndex, receivingUnitId, orderedQuantity, item.product_id, purchaseUnitId);
        } else {
            quantityInput.value = orderedQuantity;
            updateQuantityWithConversion(quantityInput);
            
            // รีเซ็ตกลับเป็นหน่วยเดิม
            const footerText = document.querySelector(`#multiple_locations_${itemIndex} tfoot small`);
            if (footerText) {
                footerText.innerHTML = `ต้องรับ: <strong>${numberFormat(orderedQuantity)}</strong> ${item.purchase_unit_name || 'หน่วย'}`;
            }
        }
    }
}

// ⭐ ฟังก์ชันใหม่: อัปเดตตัวเลขในตาราง
function updateTableFooterUnit(itemIndex, toUnitId, quantity, productId, fromUnitId) {
    callApi('calculate_conversion', {
        product_id: productId,
        from_unit: fromUnitId,
        to_unit: toUnitId,
        quantity: quantity
    })
    .then(data => {
        if (data && data.success) {
            const convertedQty = data.converted_quantity || 0;
            
            const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const unitName = receivingUnitSelect ? 
                receivingUnitSelect.options[receivingUnitSelect.selectedIndex].text : 'หน่วย';
            
            // อัปเดต footer text โดยตรง
            const footerText = document.getElementById(`footer_text_${itemIndex}`);
            if (footerText) {
                footerText.innerHTML = `ต้องรับ: <strong>${numberFormat(Math.round(convertedQty))}</strong> ${unitName}`;
            }
        }
    })
    .catch(error => {
        console.error('Error updating table footer:', error);
    });
}

// Calculate conversion and set quantity automatically
function calculateConversionAndSetQuantity(productId, fromUnitId, toUnitId, quantity, itemIndex) {
    callApi('calculate_conversion', {
        product_id: productId,
        from_unit: fromUnitId,
        to_unit: toUnitId,
        quantity: quantity
    })
    .then(data => {
        const quantityInput = document.querySelector(`input[data-item-index="${itemIndex}"]`);
        
        if (!quantityInput) {
            console.warn('Quantity input not found for index:', itemIndex);
            return;
        }
        
        if (data && data.success) {
            const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const selectedOption = receivingUnitSelect ? receivingUnitSelect.options[receivingUnitSelect.selectedIndex] : null;
            const unitSymbol = selectedOption ? selectedOption.dataset.symbol : '';
            
            let finalQuantity = data.converted_quantity || 0;
            
            if (unitSymbol === 'SHEET' || unitSymbol === 'PCS' || 
                (selectedOption && (selectedOption.text.includes('แผ่น') || selectedOption.text.includes('Sheets')))) {
                finalQuantity = Math.round(data.converted_quantity);
            }
            
            quantityInput.value = finalQuantity;
            updateQuantityWithConversion(quantityInput);
            showAutoConversionInfo(itemIndex, quantity, data.converted_quantity, finalQuantity, fromUnitId, toUnitId);
        } else {
            quantityInput.value = quantity;
            updateQuantityWithConversion(quantityInput);
        }
    })
    .catch(error => {
        console.error('Error calculating conversion:', error);
        const quantityInput = document.querySelector(`input[data-item-index="${itemIndex}"]`);
        if (quantityInput) {
            quantityInput.value = quantity;
            updateQuantityWithConversion(quantityInput);
        }
    });
}

// Show auto conversion information
function showAutoConversionInfo(itemIndex, originalQuantity, exactConversion, finalQuantity, fromUnitId, toUnitId) {
    const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
    const item = currentItems[itemIndex];
    
    if (!conversionInfo || !item) {
        return;
    }
    
    const fromUnitText = item.purchase_unit_name || 'หน่วยเดิม';
    const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
    const toUnitText = receivingUnitSelect ? 
        receivingUnitSelect.options[receivingUnitSelect.selectedIndex].text : 'หน่วยใหม่';
    
    let conversionText = `
        <strong>การแปลงอัตโนมัติ:</strong><br>
        สั่งซื้อ: ${numberFormat(originalQuantity)} ${fromUnitText}<br>
    `;
    
    if (exactConversion !== finalQuantity) {
        conversionText += `
            คำนวดได้: ${numberFormat(exactConversion)} ${toUnitText}<br>
            รับเข้า: ${numberFormat(finalQuantity)} ${toUnitText} <span class="badge bg-info">ปัดเศษ</span>
        `;
    } else {
        conversionText += `รับเข้า: ${numberFormat(finalQuantity)} ${toUnitText}`;
    }
    
    const conversionTextElement = conversionInfo.querySelector('.conversion-text');
    if (conversionTextElement) {
        conversionTextElement.innerHTML = conversionText;
        conversionInfo.style.display = 'block';
        conversionInfo.classList.add('auto-calculated');
        
        const itemCard = document.querySelector(`input[data-item-index="${itemIndex}"]`)?.closest('.item-card');
        if (itemCard) {
            itemCard.classList.add('has-conversion');
        }
    }
}

// Calculate and display conversion information
function calculateAndShowConversion(productId, fromUnitId, toUnitId, quantity, itemIndex) {
    if (quantity === 0) {
        const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
        if (conversionInfo) {
            conversionInfo.style.display = 'none';
        }
        return;
    }
    
    callApi('calculate_conversion', {
        product_id: productId,
        from_unit: fromUnitId,
        to_unit: toUnitId,
        quantity: quantity
    })
    .then(data => {
        const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
        
        if (!conversionInfo) return;
        
        if (data && data.success) {
            const fromUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const fromUnitText = fromUnitSelect ? fromUnitSelect.options[fromUnitSelect.selectedIndex].text : 'หน่วย';
            const purchaseUnitText = currentItems[itemIndex]?.purchase_unit_name || 'หน่วย';
            
            const conversionTextElement = conversionInfo.querySelector('.conversion-text');
            if (conversionTextElement) {
                conversionTextElement.innerHTML = `
                    ${numberFormat(quantity)} ${fromUnitText} 
                    = ${numberFormat(data.converted_quantity)} ${purchaseUnitText}
                    (อัตราแปลง: 1:${data.conversion_factor || 1})
                `;
                conversionInfo.style.display = 'block';
                conversionInfo.classList.remove('auto-calculated');
            }
        } else {
            conversionInfo.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error calculating conversion:', error);
        const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
        if (conversionInfo) {
            conversionInfo.style.display = 'none';
        }
    });
}

// Fill max quantity with conversion
function fillMaxQuantityConverted(button) {
    if (!button) return;
    
    const row = button.closest('.item-card');
    if (!row) return;
    
    const quantityInput = row.querySelector('input[type="number"]');
    if (!quantityInput) return;
    
    const receivingUnitSelect = row.querySelector('select.receiving-unit-select');
    
    if (receivingUnitSelect) {
        updateReceivingUnit(receivingUnitSelect);
    }
}

// Updated summary calculation with unit conversion
function updateSummaryWithConversion() {
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    let totalOrdered = 0;
    let totalReceived = 0;
    
    inputs.forEach(input => {
        const itemIndex = input.dataset.itemIndex;
        const orderedQuantity = parseFloat(input.dataset.ordered) || 0;
        const receivedQuantity = parseFloat(input.value) || 0;
        
        const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
        if (receivingUnitSelect) {
            const selectedOption = receivingUnitSelect.options[receivingUnitSelect.selectedIndex];
            const conversionFactor = parseFloat(selectedOption?.dataset.conversionFactor) || 1;
            
            totalOrdered += orderedQuantity;
            totalReceived += (receivedQuantity * conversionFactor);
        } else {
            totalOrdered += orderedQuantity;
            totalReceived += receivedQuantity;
        }
    });
    
    const totalOrderedElement = document.getElementById('totalOrdered');
    const totalReceivedElement = document.getElementById('totalReceived');
    const percentageElement = document.getElementById('percentage');
    
    if (totalOrderedElement) {
        totalOrderedElement.textContent = numberFormat(totalOrdered);
    }
    if (totalReceivedElement) {
        totalReceivedElement.textContent = numberFormat(totalReceived);
    }
    
    let percentage = 0;
    if (totalOrdered > 0) {
        const ratio = totalReceived / totalOrdered;
        const actualPercentage = ratio * 100;
        
        if (Math.abs(1 - ratio) <= 0.03) {
            percentage = 100;
        } else {
            percentage = Math.min(Math.round(actualPercentage), 100);
        }
    }
    
    if (percentageElement) {
        percentageElement.textContent = percentage + '%';
    }
}

// Update item status based on quantities
function updateItemStatus(statusElement, receivedQuantity, orderedQuantity) {
    if (!statusElement) return;
    
    const tolerance = orderedQuantity * 0.03;
    const lowerBound = orderedQuantity - tolerance;
    const upperBound = orderedQuantity + tolerance;
    
    if (receivedQuantity === 0) {
        statusElement.innerHTML = '<span class="badge bg-secondary">ยังไม่รับ</span>';
    } else if (receivedQuantity < lowerBound) {
        statusElement.innerHTML = '<span class="badge bg-warning">รับบางส่วน</span>';
    } else if (receivedQuantity >= lowerBound && receivedQuantity <= upperBound) {
        statusElement.innerHTML = '<span class="badge bg-success">รับครบ</span>';
    } else {
        statusElement.innerHTML = '<span class="badge bg-info">เกินจำนวน</span>';
    }
}

// Enhanced form data collection with unit conversion
function collectFormDataWithConversion() {
    try {
        if (!currentPOData || typeof currentPOData !== 'object') {
            throw new Error('ข้อมูล PO ไม่ถูกต้อง');
        }

        const itemsData = [];
        const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
        
        inputs.forEach(input => {
            const itemIndex = parseInt(input.dataset.itemIndex);
            
            if (isNaN(itemIndex) || !currentItems[itemIndex]) {
                console.warn(`Invalid item index: ${itemIndex}`);
                return;
            }
            
            const item = currentItems[itemIndex];
            const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const notesInput = document.querySelector(`input.item-notes[data-item-index="${itemIndex}"]`);
            
            const receivingUnitId = receivingUnitSelect ? receivingUnitSelect.value : item.purchase_unit_id;
            const receivedQuantity = parseFloat(input.value) || 0;
            
            if (receivedQuantity > 0) {
                itemsData.push({
                    po_item_id: item.po_item_id,
                    product_id: item.product_id,
                    ordered_quantity: item.quantity,
                    received_quantity: receivedQuantity,
                    receiving_unit_id: receivingUnitId,
                    unit_price: item.unit_price,
                    purchase_unit_id: item.purchase_unit_id,
                    stock_unit_id: item.stock_unit_id,
                    conversion_factor: item.conversion_factor,
                    receiving_unit_name: receivingUnitSelect ? 
                        receivingUnitSelect.options[receivingUnitSelect.selectedIndex].text : '',
                    stock_unit_name: item.stock_unit_name,
                    notes: notesInput ? notesInput.value : ''
                });
            }
        });
        
        if (itemsData.length === 0) {
            throw new Error('ไม่มีรายการสินค้าที่รับเข้า');
        }
        
        return {
            po_data: JSON.stringify(currentPOData),
            items_data: JSON.stringify(itemsData),
            general_notes: document.getElementById('generalNotes')?.value || ''
        };
        
    } catch (error) {
        console.error('Error collecting form data:', error);
        throw new Error(`เกิดข้อผิดพลาดในการรวบรวมข้อมูล: ${error.message}`);
    }
}

// Modal control functions
function closeReceiptModal() {
    const modal = document.getElementById('receiptModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        resetReceiptForm();
    }
}

function resetReceiptForm() {
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    inputs.forEach(input => {
        input.value = 0;
        updateQuantityWithConversion(input);
    });
    
    const textInputs = document.querySelectorAll('#receiptModal input[type="text"]');
    textInputs.forEach(input => input.value = '');
    
    const dateInputs = document.querySelectorAll('#receiptModal input[type="date"]');
    dateInputs.forEach(input => input.value = '');
    
    const selects = document.querySelectorAll('#receiptModal select');
    selects.forEach(select => {
        if (select.id !== 'warehouseSelect') { // Keep warehouse selection
            select.selectedIndex = 0;
        }
    });
    
    const generalNotes = document.getElementById('generalNotes');
    if (generalNotes) {
        generalNotes.value = '';
    }
    
    // Hide all lot sections
    const lotSections = document.querySelectorAll('.lot-tracking-section');
    lotSections.forEach(section => {
        section.style.display = 'none';
    });
}

async function saveDraft() {
    if (confirm('ต้องการบันทึกข้อมูลเป็นร่างหรือไม่?')) {
        showLoading();
        
        try {
            const formData = collectEnhancedFormData();
            
            const response = await callApi('save_draft', formData);
            hideLoading();
            
            if (response && response.success) {
                alert('บันทึกข้อมูลร่างเรียบร้อยแล้ว');
            } else {
                alert('เกิดข้อผิดพลาด: ' + (response?.message || 'ไม่สามารถบันทึกได้'));
            }
        } catch (error) {
            hideLoading();
            console.error('Draft save error:', error);
            alert('เกิดข้อผิดพลาด: ' + error.message);
        }
    }
}

function viewPODetails(poNumber) {
    window.location.href = `po-details.php?po=${poNumber}`;
}

// Utility functions
function showLoading() {
    const loadingElement = document.getElementById('loadingOverlay');
    if (loadingElement) {
        loadingElement.style.display = 'block';
    }
}

function hideLoading() {
    const loadingElement = document.getElementById('loadingOverlay');
    if (loadingElement) {
        loadingElement.style.display = 'none';
    }
}

function formatDate(dateString) {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('th-TH');
    } catch (error) {
        console.error('Date formatting error:', error);
        return dateString;
    }
}

function numberFormat(number) {
    try {
        return new Intl.NumberFormat('th-TH').format(number || 0);
    } catch (error) {
        console.error('Number formatting error:', error);
        return String(number || 0);
    }
}

// Debug functions
function initializeDebugFunctions() {
    window.debugAPI = async function() {
        console.log('Testing API...');
        try {
            const result = await callApi('test_connection', {});
            console.log('API test result:', result);
            return true;
        } catch (error) {
            console.error('API test failed:', error.message);
            return false;
        }
    };

    window.debugServer = async function() {
        console.log('Testing server connection...');
        try {
            const response = await fetch(window.location.href, {
                method: 'GET',
                cache: 'no-cache'
            });
            console.log('Server reachable:', response.status, response.statusText);
            return true;
        } catch (error) {
            console.error('Server connection test failed:', error);
            return false;
        }
    };

    window.debugForm = function() {
        try {
            const formData = collectEnhancedFormData();
            console.log('Current form data:', formData);
            
            const poData = JSON.parse(formData.po_data);
            const itemsData = JSON.parse(formData.items_data);
            
            console.log('PO Data:', poData);
            console.log('Items Data:', itemsData);
            
            return { formData, poData, itemsData };
        } catch (error) {
            console.error('Form data error:', error);
            return null;
        }
    };

    window.debugInfo = function() {
        console.log('Debug Information:');
        console.log('- Current URL:', window.location.href);
        console.log('- Current PO Data:', currentPOData);
        console.log('- Current Items:', currentItems);
        console.log('- User Agent:', navigator.userAgent);
        console.log('- Timestamp:', new Date().toISOString());
    };

    console.log('Debug functions available:');
    console.log('- debugAPI() - ทดสอบ API');
    console.log('- debugServer() - ทดสอบการเชื่อมต่อเซิร์ฟเวอร์');
    console.log('- debugForm() - ตรวจสอบข้อมูลฟอร์ม');
    console.log('- debugInfo() - แสดงข้อมูล debug');
}
// Load locations when warehouse changes
function loadWarehouseLocations(warehouseId) {
    if (!warehouseId) {
        // Clear all location selects
        document.querySelectorAll('.location-select').forEach(select => {
            select.innerHTML = '<option value="">เลือกตำแหน่งที่เก็บ</option>';
            select.disabled = true;
        });
        return;
    }
    
    callApi('get_warehouse_locations', { warehouse_id: warehouseId })
        .then(data => {
            if (data && data.success && Array.isArray(data.locations)) {
                updateLocationSelects(data.locations);
            } else {
                console.warn('No locations found for warehouse:', warehouseId);
                updateLocationSelects([]);
            }
        })
        .catch(error => {
            console.error('Error loading warehouse locations:', error);
            updateLocationSelects([]);
        });
}
// เพิ่ม function loadLocationsByWarehouse
function loadLocationsByWarehouse(warehouseId) {
    if (!warehouseId) {
        // Clear all location selects
        document.querySelectorAll('.location-select').forEach(select => {
            select.innerHTML = '<option value="">เลือกคลังสินค้าก่อน</option>';
            select.disabled = true;
        });
        return;
    }
    
    callApi('get_warehouse_locations', { warehouse_id: warehouseId })
        .then(data => {
            if (data && data.success && Array.isArray(data.locations)) {
                updateLocationSelects(data.locations);
            } else {
                console.warn('No locations found for warehouse:', warehouseId);
                updateLocationSelects([]);
            }
        })
        .catch(error => {
            console.error('Error loading warehouse locations:', error);
            updateLocationSelects([]);
        });
}
// Update all location select elements
function updateLocationSelects(locations) {
    const locationSelects = document.querySelectorAll('.location-select');
    
    locationSelects.forEach(select => {
        const currentValue = select.value;
        select.innerHTML = '<option value="">เลือกตำแหน่งที่เก็บ</option>';
        
        if (locations.length > 0) {
            // Group by zone
            const locationsByZone = {};
            locations.forEach(location => {
                const zone = location.zone_name || 'ไม่ระบุโซน';
                if (!locationsByZone[zone]) {
                    locationsByZone[zone] = [];
                }
                locationsByZone[zone].push(location);
            });
            
            // Add options grouped by zone
            Object.keys(locationsByZone).sort().forEach(zone => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = zone;
                
                locationsByZone[zone].forEach(location => {
                    const option = document.createElement('option');
                    option.value = location.location_id;
                    option.textContent = `${location.area_id} - ${location.location_name_th}`;
                    optgroup.appendChild(option);
                });
                
                select.appendChild(optgroup);
            });
            
            select.disabled = false;
            
            // Restore previous value if still valid
            if (currentValue) {
                select.value = currentValue;
            }
        } else {
            select.disabled = true;
        }
    });
}

// Get location data for item
function getLocationDataForItem(itemIndex) {
    const locationSelect = document.querySelector(`.location-select[data-item-index="${itemIndex}"]`);
    
    if (!locationSelect || !locationSelect.value) {
        return null;
    }
    
    return {
        location_id: locationSelect.value
    };
}

// Event listener for warehouse change
document.addEventListener('change', function(event) {
    if (event.target && event.target.id === 'warehouseSelect') {
        const warehouseId = event.target.value;
        loadWarehouseLocations(warehouseId);
    }
});

async function debugReceiptData() {
    try {
        const formData = collectEnhancedFormData();
        
        console.log('=== Debug Receipt Data ===');
        console.log('Form Data:', formData);
        
        // เรียก API debug
        const result = await callApi('debug_receipt_data', formData);
        
        if (result && result.success) {
            console.log('Server Debug Info:', result.debug_info);
            
            // แสดงผลใน UI
            displayDebugInfo(result.debug_info);
            
            return result.debug_info;
        } else {
            console.error('Debug failed:', result);
            return null;
        }
        
    } catch (error) {
        console.error('Debug error:', error);
        return null;
    }
}

// ฟังก์ชันสำหรับ validate ข้อมูลก่อนส่ง
async function validateReceiptData() {
    try {
        const formData = collectEnhancedFormData();
        
        // เรียก API validate
        const result = await callApi('validate_receipt_data', formData);
        
        if (result) {
            if (result.success) {
                console.log('✅ Validation passed');
                return { valid: true, errors: [] };
            } else {
                console.warn('❌ Validation failed:', result.errors);
                return { valid: false, errors: result.errors || [] };
            }
        }
        
        return { valid: false, errors: ['ไม่สามารถตรวจสอบข้อมูลได้'] };
        
    } catch (error) {
        console.error('Validation error:', error);
        return { valid: false, errors: [error.message] };
    }
}

// แสดงผล debug info ใน UI
function displayDebugInfo(debugInfo) {
    // สร้าง debug panel หากยังไม่มี
    let debugPanel = document.getElementById('debug-panel');
    if (!debugPanel) {
        debugPanel = document.createElement('div');
        debugPanel.id = 'debug-panel';
        debugPanel.className = 'debug-panel';
        document.body.appendChild(debugPanel);
    }
    
    const html = `
        <div class="debug-content">
            <h6><i class="fas fa-bug me-2"></i>Debug Information</h6>
            <div class="debug-item">
                <strong>เวลา:</strong> ${debugInfo.timestamp}
            </div>
            <div class="debug-item">
                <strong>จำนวนรายการ:</strong> ${debugInfo.items_count || 0}
            </div>
            <div class="debug-item">
                <strong>รายการที่รับ:</strong> ${debugInfo.received_items_count || 0}
            </div>
            <div class="debug-item">
                <strong>มี Location:</strong> ${debugInfo.items_with_location || 0}
            </div>
            <div class="debug-item">
                <strong>มี Lot:</strong> ${debugInfo.items_with_lot || 0}
            </div>
            ${debugInfo.validation_errors && debugInfo.validation_errors.length > 0 ? `
                <div class="debug-item text-danger">
                    <strong>ข้อผิดพลาด:</strong>
                    <ul class="mb-0">
                        ${debugInfo.validation_errors.map(error => `<li>${error}</li>`).join('')}
                    </ul>
                </div>
            ` : ''}
            <div class="debug-actions mt-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="closeDebugPanel()">
                    ปิด
                </button>
                <button class="btn btn-sm btn-outline-info" onclick="copyDebugInfo()">
                    คัดลอก
                </button>
            </div>
        </div>
    `;
    
    debugPanel.innerHTML = html;
    debugPanel.style.display = 'block';
    
    // เก็บข้อมูลสำหรับ copy
    window.currentDebugInfo = debugInfo;
}

// ปิด debug panel
function closeDebugPanel() {
    const debugPanel = document.getElementById('debug-panel');
    if (debugPanel) {
        debugPanel.style.display = 'none';
    }
}

// คัดลอกข้อมูล debug
function copyDebugInfo() {
    if (window.currentDebugInfo) {
        const text = JSON.stringify(window.currentDebugInfo, null, 2);
        navigator.clipboard.writeText(text).then(() => {
            alert('คัดลอกข้อมูล debug แล้ว');
        }).catch(err => {
            console.error('ไม่สามารถคัดลอกได้:', err);
            
            // fallback สำหรับ browser เก่า
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            alert('คัดลอกข้อมูล debug แล้ว');
        });
    }
}

// ========== ✅ ฟังก์ชันจัดการหลาย Location ==========

/**
 * สลับระหว่างโหมดเดี่ยวกับหลายพื้นที่
 */
function toggleMultipleLocations(itemIndex) {
    const singleSection = document.getElementById(`single_location_section_${itemIndex}`);
    const multipleSection = document.getElementById(`multiple_locations_${itemIndex}`);
    const toggleText = document.getElementById(`toggle_text_${itemIndex}`);
    
    if (!singleSection || !multipleSection || !toggleText) {
        console.error('Required elements not found for item:', itemIndex);
        return;
    }
    
    if (multipleSection.style.display === 'none') {
        // เปิดโหมดหลายพื้นที่
        singleSection.style.display = 'none';
        multipleSection.style.display = 'block';
        toggleText.innerHTML = '<i class="fas fa-undo me-1"></i>กลับไปใช้พื้นที่เดียว';
        
        // เพิ่มแถวแรกถ้ายังไม่มี
        const tbody = document.getElementById(`location_rows_${itemIndex}`);
        if (tbody && tbody.children.length === 0) {
            addLocationRow(itemIndex);
        }
    } else {
        // กลับไปโหมดเดี่ยว
        if (confirm('ต้องการกลับไปใช้พื้นที่เดียวหรือไม่? ข้อมูลในตารางจะถูกลบ')) {
            singleSection.style.display = 'flex';
            multipleSection.style.display = 'none';
            toggleText.innerHTML = '<i class="fas fa-layer-group me-1"></i>แบ่งไปหลายพื้นที่';
            
            // ล้างแถวทั้งหมด
            const tbody = document.getElementById(`location_rows_${itemIndex}`);
            if (tbody) {
                tbody.innerHTML = '';
            }
            updateTotalQuantity(itemIndex);
        }
    }
}

/**
 * เพิ่มแถวพื้นที่ใหม่
 */
function addLocationRow(itemIndex) {
    const tbody = document.getElementById(`location_rows_${itemIndex}`);
    if (!tbody) {
        console.error('tbody not found for item:', itemIndex);
        return;
    }
    
    const rowIndex = tbody.children.length;
    const warehouseId = document.getElementById('warehouseSelect')?.value;
    
    const row = document.createElement('tr');
    row.className = 'location-row';
    row.dataset.item = itemIndex;
    row.dataset.row = rowIndex;
    
    row.innerHTML = `
        <td>
            <input type="text" 
                   class="form-control form-control-sm location-search-input" 
                   list="locations_${itemIndex}_${rowIndex}"
                   name="location_search_${itemIndex}_${rowIndex}"
                   placeholder="พิมพ์ค้นหา..."
                   onchange="selectLocationFromSearch(this, ${itemIndex}, ${rowIndex})"
                   required>
            <datalist id="locations_${itemIndex}_${rowIndex}">
                <option value="">กำลังโหลด...</option>
            </datalist>
            <input type="hidden" 
                   name="location_${itemIndex}_${rowIndex}"
                   data-item-index="${itemIndex}"
                   data-row-index="${rowIndex}">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" 
                   name="lot_${itemIndex}_${rowIndex}" 
                   placeholder="LOT-12345" required>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm qty-multi-input text-end" 
                   name="qty_${itemIndex}_${rowIndex}" 
                   placeholder="0" required min="0" step="0.001"
                   data-item-index="${itemIndex}"
                   onchange="updateTotalQuantity(${itemIndex})"
                   oninput="updateTotalQuantity(${itemIndex})">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-end" 
                   name="pallet_${itemIndex}_${rowIndex}" 
                   placeholder="0" min="0" step="1">
        </td>
        <td>
            <input type="date" class="form-control form-control-sm" 
                   name="mfg_${itemIndex}_${rowIndex}">
        </td>
        <td>
            <input type="date" class="form-control form-control-sm" 
                   name="exp_${itemIndex}_${rowIndex}">
        </td>
        <td>
            <select class="form-select form-select-sm" 
                    name="condition_${itemIndex}_${rowIndex}">
                <option value="good">ปกติ</option>
                <option value="damaged">เสียหาย</option>
                <option value="expired">หมดอายุ</option>
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" 
                   name="notes_${itemIndex}_${rowIndex}" 
                   placeholder="หมายเหตุ">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" 
                    onclick="removeLocationRow(this, ${itemIndex})" 
                    title="ลบแถว">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);
    
    if (warehouseId) {
        loadLocationsForDatalist(itemIndex, rowIndex, warehouseId);
    }
}

function loadLocationsForDatalist(itemIndex, rowIndex, warehouseId) {
    if (!warehouseId) return;
    
    callApi('get_warehouse_locations', { warehouse_id: warehouseId })
        .then(data => {
            if (data && data.success && Array.isArray(data.locations)) {
                const datalist = document.getElementById(`locations_${itemIndex}_${rowIndex}`);
                if (datalist) {
                    datalist.innerHTML = '';
                    
                    // เก็บข้อมูลไว้ใน global สำหรับการค้นหา
                    if (!window.locationData) window.locationData = {};
                    window.locationData[`${itemIndex}_${rowIndex}`] = {};
                    
                    data.locations.forEach(loc => {
                        const displayText = `${loc.location_code} - ${loc.location_name_th || ''} (Zone ${loc.zone || ''})`;
                        
                        const option = document.createElement('option');
                        option.value = displayText;
                        option.dataset.locationId = loc.location_id;
                        datalist.appendChild(option);
                        
                        // เก็บ mapping
                        window.locationData[`${itemIndex}_${rowIndex}`][displayText] = loc.location_id;
                    });
                }
            }
        })
        .catch(error => {
            console.error('Error loading locations:', error);
        });
}

function selectLocationFromSearch(input, itemIndex, rowIndex) {
    const selectedText = input.value;
    const hiddenInput = document.querySelector(`input[name="location_${itemIndex}_${rowIndex}"]`);
    
    if (window.locationData && window.locationData[`${itemIndex}_${rowIndex}`]) {
        const locationId = window.locationData[`${itemIndex}_${rowIndex}`][selectedText];
        if (locationId && hiddenInput) {
            hiddenInput.value = locationId;
        }
    }
}
/**
 * ลบแถวพื้นที่
 */
function removeLocationRow(btn, itemIndex) {
    const row = btn.closest('tr');
    if (row) {
        if (confirm('ต้องการลบแถวนี้หรือไม่?')) {
            row.remove();
            updateTotalQuantity(itemIndex);
        }
    }
}

/**
 * อัปเดตจำนวนรวมในตาราง
 */
function updateTotalQuantity(itemIndex) {
    const rows = document.querySelectorAll(`#location_rows_${itemIndex} .qty-multi-input`);
    let total = 0;
    
    rows.forEach(input => {
        const value = parseFloat(input.value) || 0;
        total += value;
    });
    
    const badge = document.getElementById(`total_qty_${itemIndex}`);
    const item = currentItems[itemIndex];
    
    if (!badge || !item) return;
    
    badge.textContent = numberFormat(total);
    
    // ดึงข้อมูลหน่วยที่เลือก
    const receivingUnitSelect = document.querySelector(`select.receiving-unit-select[data-item-index="${itemIndex}"]`);
    const orderedQuantity = parseFloat(item.quantity) || 0;
    
    if (!receivingUnitSelect) {
        updateBadgeColor(badge, total, orderedQuantity);
        return;
    }
    
    const receivingUnitId = receivingUnitSelect.value;
    const purchaseUnitId = item.purchase_unit_id;
    
    // ถ้าหน่วยเดียวกัน เปรียบเทียบตรงๆ
    if (receivingUnitId === purchaseUnitId) {
        updateBadgeColor(badge, total, orderedQuantity);
        return;
    }
    
    // ถ้าต่างหน่วย ต้องแปลง
    callApi('calculate_conversion', {
        product_id: item.product_id,
        from_unit: purchaseUnitId,
        to_unit: receivingUnitId,
        quantity: orderedQuantity
    })
    .then(data => {
        if (data && data.success) {
            const targetQuantity = Math.round(data.converted_quantity || 0);
            updateBadgeColor(badge, total, targetQuantity);
        } else {
            updateBadgeColor(badge, total, orderedQuantity);
        }
    })
    .catch(error => {
        console.error('Error in updateTotalQuantity:', error);
        updateBadgeColor(badge, total, orderedQuantity);
    });
}

// ฟังก์ชันช่วยอัปเดตสี
function updateBadgeColor(badge, total, targetQuantity) {
    const tolerance = targetQuantity * 0.03;
    
    badge.className = 'badge fs-6 ';
    if (total === 0) {
        badge.className += 'bg-secondary';
    } else if (total < targetQuantity - tolerance) {
        badge.className += 'bg-warning';
    } else if (total >= targetQuantity - tolerance && total <= targetQuantity + tolerance) {
        badge.className += 'bg-success';
    } else {
        badge.className += 'bg-danger';
    }
}

/**
 * โหลด locations สำหรับแถวเฉพาะ
 */
function loadLocationsForRow(itemIndex, rowIndex, warehouseId) {
    if (!warehouseId) return;
    
    callApi('get_warehouse_locations', { warehouse_id: warehouseId })
        .then(data => {
            if (data && data.success && Array.isArray(data.locations)) {
                const select = document.querySelector(`[name="location_${itemIndex}_${rowIndex}"]`);
                if (select) {
                    let options = '<option value="">เลือกพื้นที่</option>';
                    
                    // Group by zone
                    const byZone = {};
                    data.locations.forEach(loc => {
                        const zone = loc.zone || 'อื่นๆ';
                        if (!byZone[zone]) byZone[zone] = [];
                        byZone[zone].push(loc);
                    });
                    
                    Object.keys(byZone).sort().forEach(zone => {
                        options += `<optgroup label="Zone ${zone}">`;
                        byZone[zone].forEach(loc => {
                            options += `<option value="${loc.location_id}">${loc.location_code} - ${loc.location_name_th || ''}</option>`;
                        });
                        options += '</optgroup>';
                    });
                    
                    select.innerHTML = options;
                }
            }
        })
        .catch(error => {
            console.error('Error loading locations for row:', error);
        });
}

// เพิ่มในส่วน event listener ของ warehouseSelect
document.addEventListener('change', function(event) {
    if (event.target && event.target.id === 'warehouseSelect') {
        const warehouseId = event.target.value;
        
        // โหลดสำหรับ single location
        loadWarehouseLocations(warehouseId);
        
        // โหลดสำหรับแถวที่มีอยู่แล้วในตาราง
        document.querySelectorAll('.location-multi-input').forEach(select => {
            const itemIndex = select.dataset.itemIndex;
            const rowIndex = select.dataset.rowIndex;
            if (itemIndex !== undefined && rowIndex !== undefined) {
                loadLocationsForRow(itemIndex, rowIndex, warehouseId);
            }
        });
    }
});

console.log('✅ Multiple location functions loaded');
// ========== โหมดการรับเข้า (PO Mode vs Direct Mode) ==========

/**
 * สลับระหว่างโหมดรับเข้าจาก PO กับโหมดรับเข้าโดยตรง
 */
function switchReceiptMode(mode) {
    const poSection = document.getElementById('poSelectionSection');
    const directSection = document.getElementById('directReceiptSection');
    const btnPO = document.getElementById('btnPOMode');
    const btnDirect = document.getElementById('btnDirectMode');
    
    if (!poSection || !directSection || !btnPO || !btnDirect) {
        console.error('Required mode elements not found');
        return;
    }
    
    if (mode === 'po') {
        // แสดงโหมด PO
        poSection.style.display = 'block';
        directSection.style.display = 'none';
        
        btnPO.classList.add('active');
        btnPO.classList.remove('btn-outline-primary');
        btnPO.classList.add('btn-primary');
        
        btnDirect.classList.remove('active');
        btnDirect.classList.remove('btn-success');
        btnDirect.classList.add('btn-outline-success');
        
    } else if (mode === 'direct') {
        // แสดงโหมด Direct
        poSection.style.display = 'none';
        directSection.style.display = 'block';
        
        btnDirect.classList.add('active');
        btnDirect.classList.remove('btn-outline-success');
        btnDirect.classList.add('btn-success');
        
        btnPO.classList.remove('active');
        btnPO.classList.remove('btn-primary');
        btnPO.classList.add('btn-outline-primary');
        
        // เซ็ตวันที่รับเข้าเป็นวันนี้
        const directDateInput = document.getElementById('directReceiptDate');
        if (directDateInput) {
            directDateInput.value = new Date().toISOString().split('T')[0];
        }
        
        // Generate เลขที่เอกสาร Direct Receipt
        generateDirectReceiptNumber();
    }
}

/**
 * สร้างเลขที่เอกสารรับเข้าโดยตรง (ไม่มี PO)
 */
function generateDirectReceiptNumber() {
    const directReceiptInput = document.getElementById('directReceiptNumber');
    if (directReceiptInput) {
        const timestamp = new Date().getTime();
        const randomNum = Math.floor(Math.random() * 1000);
        const receiptNumber = `DR-${timestamp}-${randomNum}`;
        directReceiptInput.value = receiptNumber;
    }
}

/**
 * โหลดรายการ Suppliers สำหรับ Direct Mode
 */
function loadSuppliersForDirect() {
    callApi('get_suppliers', {})
        .then(data => {
            if (data && data.success && Array.isArray(data.suppliers)) {
                const select = document.getElementById('directSupplierSelect');
                if (select) {
                    select.innerHTML = '<option value="">เลือกซัพพลายเออร์</option>';
                    data.suppliers.forEach(supplier => {
                        const option = document.createElement('option');
                        option.value = supplier.supplier_id;
                        option.textContent = supplier.supplier_name;
                        select.appendChild(option);
                    });
                }
            }
        })
        .catch(error => {
            console.error('Error loading suppliers:', error);
        });
}

// Event Listeners สำหรับปุ่มสลับโหมด
document.addEventListener('DOMContentLoaded', function() {
    const btnPO = document.getElementById('btnPOMode');
    const btnDirect = document.getElementById('btnDirectMode');
    
    if (btnPO) {
        btnPO.addEventListener('click', function() {
            switchReceiptMode('po');
        });
    }
    
    if (btnDirect) {
        btnDirect.addEventListener('click', function() {
            switchReceiptMode('direct');
            loadSuppliersForDirect(); // โหลดรายการ Suppliers
        });
    }
    
    // ตั้งค่าเริ่มต้นเป็นโหมด PO
    switchReceiptMode('po');
});

console.log('✅ Receipt mode switching functions loaded');