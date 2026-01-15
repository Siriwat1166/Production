<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบรับสินค้า - แปลงหน่วย</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h1 class="text-3xl font-bold text-gray-800 text-center mb-2">ระบบรับสินค้า</h1>
            <p class="text-gray-600 text-center">แปลงหน่วยการรับที่แตกต่างจากหน่วยการสั่งซื้อ</p>
        </div>

        <!-- ค้นหา PO -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                <span class="bg-indigo-100 text-indigo-600 rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">🔍</span>
                ค้นหา Purchase Order (PO)
            </h2>
            
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-2">เลข PO</label>
                    <input type="text" id="poNumber" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="เช่น PO-2024-001">
                </div>
                <div class="flex items-end">
                    <button onclick="searchPO()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                        ค้นหา PO
                    </button>
                </div>
            </div>
            
            <!-- แสดงสถานะ PO -->
            <div id="poStatus" class="mt-4 p-3 rounded-lg bg-gray-50 border border-gray-200" style="display: none;">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-600">สถานะ PO:</span>
                    <span id="poStatusText" class="text-sm font-semibold"></span>
                </div>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-8">
            <!-- ข้อมูลการสั่งซื้อ -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                    <span class="bg-blue-100 text-blue-600 rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">1</span>
                    ข้อมูลการสั่งซื้อ
                </h2>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">รหัสสินค้า</label>
                        <input type="text" id="productCode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="เช่น P-A4-80, P-978x623-400" onchange="loadProductSpecs()">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อสินค้า</label>
                        <input type="text" id="productName" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-gray-50" placeholder="เช่น กระดาษ A4" readonly>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนที่สั่ง</label>
                            <input type="number" id="orderQuantity" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-gray-50" placeholder="1000" readonly>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">หน่วยที่สั่ง</label>
                            <select id="orderUnit" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-gray-50" disabled>
                                <option value="kg">กิโลกรัม (kg)</option>
                                <option value="ton">ตัน (ton)</option>
                                <option value="gram">กรัม (g)</option>
                                <option value="piece">ชิ้น</option>
                                <option value="box">กล่อง</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">รับแล้ว</label>
                            <input type="number" id="receivedQuantity" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50" readonly>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">คงเหลือ</label>
                            <input type="number" id="remainingQuantity" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-yellow-50 font-semibold text-orange-600" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- การตั้งค่าอัตราแปลง -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                    <span class="bg-green-100 text-green-600 rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">2</span>
                    ข้อมูลสำหรับคำนวณ
                </h2>

                    <!-- ขนาดกระดาษ -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-700 mb-3">ขนาดกระดาษ (มิลลิเมตร)</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">กว้าง (mm)</label>
                                <input type="number" id="paperWidth" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" placeholder="210" value="210" onchange="calculateConversion()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">ยาว (mm)</label>
                                <input type="number" id="paperLength" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" placeholder="297" value="297" onchange="calculateConversion()">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">GSM (กรัม/ตารางเมตร)</label>
                            <input type="number" id="paperGSM" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" placeholder="80" value="80" onchange="calculateConversion()">
                        </div>
                        
                        <!-- ตัวอย่างขนาดกระดาษ -->
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" onclick="setPaperSize(210, 297, 'A4')" class="text-xs bg-white border border-gray-300 rounded px-2 py-1 hover:bg-gray-50">A4 (210×297)</button>
                            <button type="button" onclick="setPaperSize(297, 420, 'A3')" class="text-xs bg-white border border-gray-300 rounded px-2 py-1 hover:bg-gray-50">A3 (297×420)</button>
                            <button type="button" onclick="setPaperSize(148, 210, 'A5')" class="text-xs bg-white border border-gray-300 rounded px-2 py-1 hover:bg-gray-50">A5 (148×210)</button>
                        </div>
                    </div>
                    
                    <!-- แสดงอัตราการแปลง -->
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-green-800 mb-2">ผลการคำนวณ:</h3>
                        <div class="text-center space-y-1">
                            <div><span id="weightPerSheet" class="text-sm font-medium text-green-700">น้ำหนักต่อแผ่น: - g/sht</span></div>
                            <div><span id="conversionDisplay" class="text-lg font-bold text-green-700">1 กิโลกรัม = - แผ่น</span></div>
                        </div>
                        <div class="text-center mt-2">
                            <span id="conversionDetail" class="text-sm text-green-600">กรุณากรอกข้อมูลขนาดกระดาษ</span>
                        </div>
                    </div>
                    
                    <!-- ช่องจำนวนที่ต้องการรับ -->
                    <div class="border-t pt-4 mt-4">
                        <h3 class="text-sm font-semibold text-gray-700 mb-3">จำนวนที่ต้องการรับในครั้งนี้:</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนที่ต้องการรับ</label>
                                <input type="number" id="wantToReceive" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-purple-50" placeholder="เช่น 5" onchange="calculateFromWantedAmount()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">หน่วยที่ต้องการรับ</label>
                                <select id="wantToReceiveUnit" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-purple-50" onchange="calculateFromWantedAmount()">
                                    <option value="kg">กิโลกรัม (kg)</option>
                                    <option value="sheet" selected>แผ่น (sheet)</option>
                                    <option value="ton">ตัน (ton)</option>
                                    <option value="gram">กรัม (g)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ผลการคำนวณ -->
        <div class="bg-white rounded-xl shadow-lg p-6 mt-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                <span class="bg-purple-100 text-purple-600 rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">3</span>
                ผลการคำนวณ
            </h2>
            
            <div class="grid md:grid-cols-3 gap-6 mb-6">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <h3 class="text-sm font-medium text-blue-600 mb-2">จำนวนคงเหลือ</h3>
                    <p class="text-2xl font-bold text-blue-800" id="displayOrder">- -</p>
                </div>
                
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <h3 class="text-sm font-medium text-green-600 mb-2">ควรรับได้ (ที่เหลือ)</h3>
                    <p class="text-2xl font-bold text-green-800" id="displayCalculated">- -</p>
                </div>
                
                <div class="bg-orange-50 rounded-lg p-4 text-center">
                    <h3 class="text-sm font-medium text-orange-600 mb-2">จำนวนที่รับจริง</h3>
                    <input type="number" id="actualReceived" class="w-full text-center text-2xl font-bold text-orange-800 bg-transparent border-2 border-orange-200 rounded-lg py-2 focus:ring-2 focus:ring-orange-500 focus:border-transparent" placeholder="0">
                </div>
            </div>

            <!-- แสดงผลการคำนวณที่เหลือ -->
            <div class="bg-gradient-to-r from-yellow-50 to-amber-50 border border-yellow-200 rounded-lg p-6" id="remainingCalculation" style="display: none;">
                <h3 class="text-lg font-semibold text-yellow-800 mb-4 text-center">📊 ผลการคำนวณที่เหลือทั้งหมด</h3>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <!-- คงเหลือในหน่วยกิโลกรัม -->
                    <div class="bg-white rounded-lg p-4 border border-yellow-200">
                        <h4 class="text-sm font-semibold text-yellow-700 mb-3 text-center">📦 จำนวนคงเหลือ (กิโลกรัม)</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">สั่งซื้อทั้งหมด:</span>
                                <span class="font-semibold text-blue-600" id="remainingOrderTotal">- kg</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">รับแล้ว:</span>
                                <span class="font-semibold text-green-600" id="remainingReceived">- kg</span>
                            </div>
                            <div class="border-t pt-2 flex justify-between">
                                <span class="text-gray-800 font-semibold">คงเหลือ:</span>
                                <span class="font-bold text-orange-600 text-lg" id="remainingKg">- kg</span>
                            </div>
                        </div>
                    </div>

                    <!-- คงเหลือในหน่วยแผ่น -->
                    <div class="bg-white rounded-lg p-4 border border-yellow-200">
                        <h4 class="text-sm font-semibold text-yellow-700 mb-3 text-center">📄 จำนวนคงเหลือ (แผ่น)</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">ควรรับทั้งหมด:</span>
                                <span class="font-semibold text-blue-600" id="remainingSheetsTotal">- แผ่น</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">รับแล้ว (คำนวณ):</span>
                                <span class="font-semibold text-green-600" id="remainingSheetsReceived">- แผ่น</span>
                            </div>
                            <div class="border-t pt-2 flex justify-between">
                                <span class="text-gray-800 font-semibold">คงเหลือ:</span>
                                <span class="font-bold text-orange-600 text-lg" id="remainingSheets">- แผ่น</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- สรุปการคำนวณ -->
                <div class="mt-4 bg-white rounded-lg p-4 border border-yellow-200">
                    <h4 class="text-sm font-semibold text-yellow-700 mb-3 text-center">💡 สรุปการคำนวณ</h4>
                    <div class="grid md:grid-cols-3 gap-4 text-center">
                        <div class="bg-blue-50 rounded-lg p-3">
                            <div class="text-xs text-blue-600 mb-1">อัตราการแปลง</div>
                            <div class="font-semibold text-blue-800" id="summaryConversionRate">- แผ่น/kg</div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-3">
                            <div class="text-xs text-green-600 mb-1">น้ำหนักต่อแผ่น</div>
                            <div class="font-semibold text-green-800" id="summaryWeightPerSheet">- g/sht</div>
                        </div>
                        <div class="bg-purple-50 rounded-lg p-3">
                            <div class="text-xs text-purple-600 mb-1">เปอร์เซ็นต์ที่รับแล้ว</div>
                            <div class="font-semibold text-purple-800" id="summaryPercentReceived">- %</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            
            <!-- สถานะการรับ -->
            <div class="mt-6 p-4 rounded-lg" id="statusDisplay" style="display: none;">
                <div class="flex items-center justify-center">
                    <span class="text-lg font-semibold" id="statusText"></span>
                    <span class="ml-2 text-2xl" id="statusIcon"></span>
                </div>
                <p class="text-center mt-2 text-sm" id="statusDetail"></p>
            </div>
        </div>

        <!-- ปุ่มดำเนินการ -->
        <div class="flex justify-center space-x-4 mt-8">
            <button onclick="calculateReceive()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-8 py-3 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                คำนวณจำนวนที่ควรรับ
            </button>
            <button onclick="checkStatus()" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-8 py-3 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                ตรวจสอบสถานะ
            </button>
            <button onclick="clearForm()" class="bg-gray-500 hover:bg-gray-600 text-white font-semibold px-8 py-3 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                ล้างข้อมูล
            </button>
        </div>
    </div>

    <script>
        // ฐานข้อมูลรหัสสินค้า (จำลองฐานข้อมูล)
        const productDatabase = {
            'P-A4-80': {
                name: 'กระดาษ A4 80 แกรม',
                width: 210,
                length: 297,
                gsm: 80
            },
            'P-A3-100': {
                name: 'กระดาษ A3 100 แกรม',
                width: 297,
                length: 420,
                gsm: 100
            },
            'P-A5-70': {
                name: 'กระดาษ A5 70 แกรม',
                width: 148,
                length: 210,
                gsm: 70
            },
            'P-978x623-400': {
                name: 'กระดาษ 978×623 mm, GSM 400',
                width: 978,
                length: 623,
                gsm: 400
            },
            'P-B4-90': {
                name: 'กระดาษ B4 90 แกรม',
                width: 250,
                length: 353,
                gsm: 90
            },
            'P-CUSTOM-120': {
                name: 'กระดาษ Custom 120 แกรม',
                width: 500,
                length: 700,
                gsm: 120
            }
        };

        // ข้อมูล PO ตัวอย่าง (จำลองฐานข้อมูล)
        const poDatabase = {
            'PO-2024-001': {
                productCode: 'P-A4-80',
                orderQuantity: 1000,
                orderUnit: 'kg',
                receivedQuantity: 250,
                status: 'active'
            },
            'PO-2024-002': {
                productCode: 'P-A3-100',
                orderQuantity: 1000,
                orderUnit: 'kg',
                receivedQuantity: 300,
                status: 'active'
            },
            'PO-2024-003': {
                productCode: 'P-A5-70',
                orderQuantity: 1000,
                orderUnit: 'kg',
                receivedQuantity: 150,
                status: 'active'
            },
            'PO-2024-978': {
                productCode: 'P-978x623-400',
                orderQuantity: 1000,
                orderUnit: 'kg',
                receivedQuantity: 200,
                status: 'active'
            },
            'PO-2024-B4': {
                productCode: 'P-B4-90',
                orderQuantity: 500,
                orderUnit: 'kg',
                receivedQuantity: 100,
                status: 'active'
            }
        };

        // ฟังก์ชันโหลดข้อมูลสินค้าจากรหัสสินค้า
        function loadProductSpecs() {
            const productCode = document.getElementById('productCode').value.trim().toUpperCase();
            
            if (!productCode) {
                // ล้างข้อมูลเมื่อไม่มีรหัสสินค้า
                document.getElementById('productName').value = '';
                document.getElementById('paperWidth').value = '210';
                document.getElementById('paperLength').value = '297';
                document.getElementById('paperGSM').value = '80';
                calculateConversion();
                return;
            }

            const productData = productDatabase[productCode];
            
            if (productData) {
                // พบรหัสสินค้า - โหลดข้อมูลอัตโนมัติ
                document.getElementById('productName').value = productData.name;
                document.getElementById('paperWidth').value = productData.width;
                document.getElementById('paperLength').value = productData.length;
                document.getElementById('paperGSM').value = productData.gsm;
                
                // คำนวณอัตราการแปลงใหม่
                calculateConversion();
                
                // แสดงข้อความยืนยัน
                showProductLoadedMessage(productData);
            } else {
                // ไม่พบรหัสสินค้า
                document.getElementById('productName').value = 'ไม่พบรหัสสินค้านี้ในระบบ';
                showProductNotFoundMessage(productCode);
            }
        }

        // ฟังก์ชันแสดงข้อความยืนยันการโหลดสินค้า
        function showProductLoadedMessage(productData) {
            // สร้าง element แสดงข้อความชั่วคราว
            const messageDiv = document.createElement('div');
            messageDiv.className = 'mt-2 p-2 bg-green-50 border border-green-200 rounded text-sm text-green-700';
            messageDiv.innerHTML = `✅ โหลดข้อมูลสินค้าสำเร็จ: ${productData.width}×${productData.length} mm, ${productData.gsm} GSM`;
            
            const productCodeInput = document.getElementById('productCode');
            productCodeInput.parentNode.appendChild(messageDiv);
            
            // ลบข้อความหลัง 3 วินาที
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.parentNode.removeChild(messageDiv);
                }
            }, 3000);
        }

        // ฟังก์ชันแสดงข้อความเมื่อไม่พบสินค้า
        function showProductNotFoundMessage(productCode) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'mt-2 p-2 bg-red-50 border border-red-200 rounded text-sm text-red-700';
            messageDiv.innerHTML = `❌ ไม่พบรหัสสินค้า "${productCode}" ในระบบ`;
            
            const productCodeInput = document.getElementById('productCode');
            productCodeInput.parentNode.appendChild(messageDiv);
            
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.parentNode.removeChild(messageDiv);
                }
            }, 3000);
        }

        // ฟังก์ชันค้นหา PO
        function searchPO() {
            const poNumber = document.getElementById('poNumber').value.trim();
            
            if (!poNumber) {
                alert('กรุณากรอกเลข PO');
                return;
            }

            const poData = poDatabase[poNumber];
            const poStatus = document.getElementById('poStatus');
            const poStatusText = document.getElementById('poStatusText');

            if (poData) {
                // แสดงข้อมูล PO
                document.getElementById('productCode').value = poData.productCode;
                document.getElementById('orderQuantity').value = poData.orderQuantity;
                document.getElementById('orderUnit').value = poData.orderUnit;
                document.getElementById('receivedQuantity').value = poData.receivedQuantity;
                
                // คำนวณจำนวนคงเหลือ
                const remaining = poData.orderQuantity - poData.receivedQuantity;
                document.getElementById('remainingQuantity').value = remaining;

                // โหลดข้อมูลสินค้าจากรหัสสินค้า
                loadProductSpecs();

                // แสดงสถานะ PO
                poStatus.style.display = 'block';
                if (poData.status === 'completed') {
                    poStatus.className = 'mt-4 p-3 rounded-lg bg-green-50 border border-green-200';
                    poStatusText.textContent = 'รับครบแล้ว';
                    poStatusText.className = 'text-sm font-semibold text-green-600';
                } else if (remaining <= 0) {
                    poStatus.className = 'mt-4 p-3 rounded-lg bg-green-50 border border-green-200';
                    poStatusText.textContent = 'รับครบแล้ว';
                    poStatusText.className = 'text-sm font-semibold text-green-600';
                } else {
                    poStatus.className = 'mt-4 p-3 rounded-lg bg-blue-50 border border-blue-200';
                    poStatusText.textContent = `รอรับสินค้า (เหลือ ${remaining} ${getUnitText(poData.orderUnit)})`;
                    poStatusText.className = 'text-sm font-semibold text-blue-600';
                }

                // คำนวณอัตราการแปลงและแสดงผล
                calculateConversion();
                calculateReceive();
            } else {
                // ไม่พบ PO
                poStatus.style.display = 'block';
                poStatus.className = 'mt-4 p-3 rounded-lg bg-red-50 border border-red-200';
                poStatusText.textContent = 'ไม่พบเลข PO นี้ในระบบ';
                poStatusText.className = 'text-sm font-semibold text-red-600';
                
                // ล้างข้อมูล
                clearForm();
            }
        }

        // ฟังก์ชันคำนวณอัตราการแปลงจากสูตร
        function calculateConversion() {
            const width = parseFloat(document.getElementById('paperWidth').value);
            const length = parseFloat(document.getElementById('paperLength').value);
            const gsm = parseFloat(document.getElementById('paperGSM').value);

            if (!width || !length || !gsm) {
                document.getElementById('weightPerSheet').textContent = 'น้ำหนักต่อแผ่น: - g/sht';
                document.getElementById('conversionDisplay').textContent = '1 กิโลกรัม = - แผ่น';
                document.getElementById('conversionDetail').textContent = 'กรุณากรอกข้อมูลขนาดกระดาษ';
                return;
            }

            // สูตรใหม่:
            // 1. หาน้ำหนักต่อแผ่น (g/sht) = (กว้าง mm × ยาว mm × GSM) ÷ 1,000,000
            const weightPerSheet = (width * length * gsm) / 1000000;
            
            // 2. คำนวณจำนวนแผ่นจากน้ำหนักรวม (กิโลกรัม) = (น้ำหนักรวม kg × 1,000) ÷ g/sht
            // สำหรับ 1 กิโลกรัม
            const sheetsPerKg = (1 * 1000) / weightPerSheet;
            
            // แสดงผล
            document.getElementById('weightPerSheet').textContent = `น้ำหนักต่อแผ่น: ${weightPerSheet.toFixed(4)} g/sht`;
            document.getElementById('conversionDisplay').textContent = `1 กิโลกรัม = ${Math.round(sheetsPerKg).toLocaleString()} แผ่น`;
            document.getElementById('conversionDetail').textContent = `${width}×${length} mm, ${gsm} GSM`;
            
            // เก็บค่าอัตราการแปลงไว้ใช้
            window.conversionRate = sheetsPerKg;
            window.weightPerSheet = weightPerSheet;
        }

        // ฟังก์ชันตั้งค่าขนาดกระดาษ
        function setPaperSize(width, length, sizeName) {
            document.getElementById('paperWidth').value = width;
            document.getElementById('paperLength').value = length;
            calculateConversion();
        }

        // ฟังก์ชันคำนวณจำนวนที่ควรรับ
        function calculateReceive() {
            const productName = document.getElementById('productName').value;
            const orderQuantity = parseFloat(document.getElementById('orderQuantity').value);
            const remainingQuantity = parseFloat(document.getElementById('remainingQuantity').value);
            const orderUnit = document.getElementById('orderUnit').value;

            if (!productName || !orderQuantity || !window.conversionRate) {
                if (!window.conversionRate) {
                    alert('กรุณากรอกข้อมูลขนาดกระดาษให้ครบถ้วน');
                } else {
                    alert('กรุณากรอกข้อมูลให้ครบถ้วน');
                }
                return;
            }

            // คำนวณจำนวนที่ควรรับ (แผ่น) - ใช้จำนวนที่เหลือ
            let shouldReceiveRemaining = remainingQuantity * window.conversionRate;
            
            // แสดงผล - แสดงจำนวนที่เหลือ
            document.getElementById('displayOrder').textContent = `${remainingQuantity.toLocaleString()} ${getUnitText(orderUnit)}`;
            document.getElementById('displayCalculated').textContent = `${Math.round(shouldReceiveRemaining).toLocaleString()} แผ่น`;
            
            // เก็บค่าไว้ใช้ในการตรวจสอบสถานะ
            window.shouldReceiveAmount = Math.round(shouldReceiveRemaining);
            
            // แสดงผลการคำนวณที่เหลือ
            showRemainingCalculation();
        }

        // ฟังก์ชันแสดงผลการคำนวณที่เหลือทั้งหมด
        function showRemainingCalculation() {
            const orderQuantity = parseFloat(document.getElementById('orderQuantity').value);
            const receivedQuantity = parseFloat(document.getElementById('receivedQuantity').value);
            const remainingQuantity = parseFloat(document.getElementById('remainingQuantity').value);

            if (!orderQuantity || !window.conversionRate) {
                return;
            }

            // คำนวณค่าต่างๆ
            const totalShouldReceiveSheets = orderQuantity * window.conversionRate;
            const receivedSheets = receivedQuantity * window.conversionRate;
            const remainingSheets = remainingQuantity * window.conversionRate;
            const percentReceived = (receivedQuantity / orderQuantity) * 100;

            // แสดงผลในส่วนคงเหลือ (กิโลกรัม)
            document.getElementById('remainingOrderTotal').textContent = `${orderQuantity.toLocaleString()} kg`;
            document.getElementById('remainingReceived').textContent = `${receivedQuantity.toLocaleString()} kg`;
            document.getElementById('remainingKg').textContent = `${remainingQuantity.toLocaleString()} kg`;

            // แสดงผลในส่วนคงเหลือ (แผ่น)
            document.getElementById('remainingSheetsTotal').textContent = `${Math.round(totalShouldReceiveSheets).toLocaleString()} แผ่น`;
            document.getElementById('remainingSheetsReceived').textContent = `${Math.round(receivedSheets).toLocaleString()} แผ่น`;
            document.getElementById('remainingSheets').textContent = `${Math.round(remainingSheets).toLocaleString()} แผ่น`;

            // แสดงผลสรุปการคำนวณ
            document.getElementById('summaryConversionRate').textContent = `${Math.round(window.conversionRate).toLocaleString()} แผ่น/kg`;
            document.getElementById('summaryWeightPerSheet').textContent = `${window.weightPerSheet.toFixed(4)} g/sht`;
            document.getElementById('summaryPercentReceived').textContent = `${percentReceived.toFixed(1)}%`;

            // แสดงส่วนการคำนวณที่เหลือ
            document.getElementById('remainingCalculation').style.display = 'block';
        }

        // ฟังก์ชันตรวจสอบสถานะการรับ
        function checkStatus() {
            const actualReceived = parseFloat(document.getElementById('actualReceived').value);
            const shouldReceive = window.shouldReceiveAmount;

            if (!actualReceived || !shouldReceive) {
                alert('กรุณาคำนวณจำนวนที่ควรรับก่อน และกรอกจำนวนที่รับจริง');
                return;
            }

            const statusDisplay = document.getElementById('statusDisplay');
            const statusText = document.getElementById('statusText');
            const statusIcon = document.getElementById('statusIcon');
            const statusDetail = document.getElementById('statusDetail');

            const difference = actualReceived - shouldReceive;
            const percentDiff = (Math.abs(difference) / shouldReceive) * 100;

            statusDisplay.style.display = 'block';

            if (Math.abs(difference) < 0.01) {
                // รับครบถ้วน
                statusDisplay.className = 'mt-6 p-4 rounded-lg bg-green-100 border border-green-300';
                statusText.textContent = 'รับสินค้าครบถ้วน';
                statusText.className = 'text-lg font-semibold text-green-800';
                statusIcon.textContent = '✅';
                statusDetail.textContent = 'จำนวนที่รับตรงกับที่คำนวณไว้';
                statusDetail.className = 'text-center mt-2 text-sm text-green-700';
            } else if (difference > 0) {
                // รับเกิน
                statusDisplay.className = 'mt-6 p-4 rounded-lg bg-yellow-100 border border-yellow-300';
                statusText.textContent = 'รับสินค้าเกิน';
                statusText.className = 'text-lg font-semibold text-yellow-800';
                statusIcon.textContent = '⚠️';
                statusDetail.textContent = `เกินกว่าที่ควรรับ ${difference.toLocaleString()} หน่วย (${percentDiff.toFixed(2)}%)`;
                statusDetail.className = 'text-center mt-2 text-sm text-yellow-700';
            } else {
                // รับขาด
                statusDisplay.className = 'mt-6 p-4 rounded-lg bg-red-100 border border-red-300';
                statusText.textContent = 'รับสินค้าขาด';
                statusText.className = 'text-lg font-semibold text-red-800';
                statusIcon.textContent = '❌';
                statusDetail.textContent = `ขาดจากที่ควรรับ ${Math.abs(difference).toLocaleString()} หน่วย (${percentDiff.toFixed(2)}%)`;
                statusDetail.className = 'text-center mt-2 text-sm text-red-700';
            }
        }

        // ฟังก์ชันล้างข้อมูล
        function clearForm() {
            document.getElementById('poNumber').value = '';
            document.getElementById('productCode').value = '';
            document.getElementById('productName').value = '';
            document.getElementById('orderQuantity').value = '';
            document.getElementById('receivedQuantity').value = '';
            document.getElementById('remainingQuantity').value = '';
            document.getElementById('actualReceived').value = '';
            document.getElementById('wantToReceive').value = '';
            document.getElementById('displayOrder').textContent = '- -';
            document.getElementById('displayCalculated').textContent = '- -';
            document.getElementById('statusDisplay').style.display = 'none';
            document.getElementById('poStatus').style.display = 'none';
            document.getElementById('remainingCalculation').style.display = 'none';
            
            // รีเซ็ตข้อมูลกระดาษ
            document.getElementById('paperWidth').value = '210';
            document.getElementById('paperLength').value = '297';
            document.getElementById('paperGSM').value = '80';
            document.getElementById('weightPerSheet').textContent = 'น้ำหนักต่อแผ่น: - g/sht';
            document.getElementById('conversionDisplay').textContent = '1 กิโลกรัม = - แผ่น';
            document.getElementById('conversionDetail').textContent = 'กรุณากรอกข้อมูลขนาดกระดาษ';
            
            window.shouldReceiveAmount = null;
            window.conversionRate = null;
        }

        // ฟังก์ชันแปลงชื่อหน่วย
        function getUnitText(unit) {
            const units = {
                'kg': 'กิโลกรัม',
                'ton': 'ตัน',
                'gram': 'กรัม',
                'piece': 'ชิ้น',
                'box': 'กล่อง',
                'sheet': 'แผ่น',
                'roll': 'ม้วน',
                'pack': 'แพ็ค'
            };
            return units[unit] || unit;
        }

        // ฟังก์ชันคำนวณจากจำนวนที่ต้องการรับ
        function calculateFromWantedAmount() {
            const wantToReceive = parseFloat(document.getElementById('wantToReceive').value);
            const wantToReceiveUnit = document.getElementById('wantToReceiveUnit').value;

            if (!wantToReceive || !window.conversionRate) {
                return;
            }

            let calculatedSheets = 0;
            let calculatedKg = 0;
            
            // ตรวจสอบหน่วยที่ต้องการรับ
            if (wantToReceiveUnit === 'sheet') {
                // ต้องการรับเป็นแผ่น - แปลงกลับเป็นกิโลกรัม
                calculatedKg = wantToReceive / window.conversionRate;
                calculatedSheets = wantToReceive;
            } else if (wantToReceiveUnit === 'kg') {
                // ต้องการรับเป็นกิโลกรัม - แปลงเป็นแผ่น
                calculatedKg = wantToReceive;
                calculatedSheets = wantToReceive * window.conversionRate;
            }
            
            // แสดงผล
            document.getElementById('displayOrder').textContent = `${calculatedKg.toFixed(2)} กิโลกรัม`;
            document.getElementById('displayCalculated').textContent = `${Math.round(calculatedSheets).toLocaleString()} แผ่น`;
            
            // เก็บค่าไว้ใช้ในการตรวจสอบสถานะ
            window.shouldReceiveAmount = Math.round(calculatedSheets);
        }

        // ตั้งค่าเริ่มต้น
        document.addEventListener('DOMContentLoaded', function() {
            // เพิ่มตัวอย่าง PO ที่สามารถทดสอบได้
            const examplePOs = document.createElement('div');
            examplePOs.className = 'mt-2 text-xs text-gray-500';
            examplePOs.innerHTML = 'ตัวอย่าง PO ที่ทดสอบได้: PO-2024-001, PO-2024-002, PO-2024-003, <strong>PO-2024-978</strong>, PO-2024-B4';
            document.getElementById('poNumber').parentNode.appendChild(examplePOs);
            
            // เพิ่มตัวอย่างรหัสสินค้า
            const exampleProducts = document.createElement('div');
            exampleProducts.className = 'mt-2 text-xs text-gray-500';
            exampleProducts.innerHTML = 'ตัวอย่างรหัสสินค้า: <strong>P-A4-80</strong>, P-A3-100, P-A5-70, <strong>P-978x623-400</strong>, P-B4-90, P-CUSTOM-120';
            document.getElementById('productCode').parentNode.appendChild(exampleProducts);
            
            // คำนวณอัตราการแปลงเริ่มต้น
            calculateConversion();
        });
    </script>
<script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'96e6bb38a06d2dd4',t:'MTc1NTA3MjU3Ny4wMDAwMDA='};var a=document.createElement('script');a.nonce='';a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script></body>
</html>
