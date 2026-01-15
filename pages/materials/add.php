<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มข้อมูล Material - Production System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #8B4513;
            --secondary-color: #FF8C00;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --accent-color: #A0522D;
        }
        
        body {
            background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--primary-color);
        }
        
        .main-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(139, 69, 19, 0.15);
            margin: 20px;
            max-width: none;
            width: calc(100% - 40px);
            overflow: hidden;
            border: 1px solid rgba(139, 69, 19, 0.1);
        }
        
        .container-fluid {
            max-width: 100%;
            padding: 0 20px;
        }
        
        /* Full width layout */
        @media (min-width: 768px) {
            .main-container {
                margin: 20px 20px;
            }
        }
        
        @media (min-width: 1200px) {
            .main-container {
                margin: 20px 30px;
            }
        }
        
        .header-section {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .ssp-preview {
            background: rgba(255, 140, 0, 0.1);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            border: 2px dashed rgba(255, 140, 0, 0.3);
        }
        
        .ssp-preview h4 {
            color: #FFD700;
        }
        
        .form-section {
            background: rgba(255, 140, 0, 0.05);
            border-left: 4px solid var(--secondary-color);
            padding: 25px;
            margin: 20px;
            border-radius: 10px;
            border: 1px solid rgba(255, 140, 0, 0.1);
        }
        
        .form-section h5 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            padding: 12px 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.25);
            background: white;
        }
        
        .btn-custom {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
        }
        
        .btn-success-custom {
            background: linear-gradient(135deg, var(--success-color), #047857);
            color: white;
        }
        
        .btn-secondary-custom {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }
        
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139, 69, 19, 0.3);
        }
        
        .calculation-card {
            background: rgba(5, 150, 105, 0.1);
            border: 2px solid var(--success-color);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .preview-code {
            font-family: 'Courier New', monospace;
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-align: center;
            letter-spacing: 2px;
            background: rgba(255, 140, 0, 0.2);
            padding: 10px;
            border-radius: 8px;
        }
        
        .auto-name-hint {
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--secondary-color);
            border-radius: 8px;
            padding: 10px;
            font-size: 0.9em;
            color: var(--primary-color);
        }
        
        .required-field {
            color: var(--danger-color);
        }
        
        .form-floating label {
            color: var(--accent-color);
        }
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 0 20px;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        
        .step::after {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: rgba(139, 69, 19, 0.3);
            z-index: 1;
        }
        
        .step:last-child::after {
            display: none;
        }
        
        .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(139, 69, 19, 0.3);
            color: var(--primary-color);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .step.active .step-circle {
            background: var(--primary-color);
            color: white;
        }
        
        .step.completed .step-circle {
            background: var(--success-color);
            color: white;
        }
        
        .hidden-section {
            display: none;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .alert-warning {
            background: rgba(217, 119, 6, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--warning-color);
        }
        
        .alert-info {
            background: rgba(255, 140, 0, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--secondary-color);
        }
        
        .alert-primary {
            background: rgba(139, 69, 19, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--primary-color);
        }
        
        .alert-secondary {
            background: rgba(160, 82, 45, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--accent-color);
        }
        
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 20px 40px rgba(139, 69, 19, 0.2);
        }
        
        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        
        .bg-light {
            background-color: rgba(139, 69, 19, 0.05) !important;
        }
        
        .text-muted {
            color: var(--accent-color) !important;
        }
        
        .text-primary {
            color: var(--primary-color) !important;
        }
        
        .text-success {
            color: var(--success-color) !important;
        }
        
        /* Navbar สำหรับการนำทาง */
        .top-navbar {
            background: rgba(139, 69, 19, 0.9);
            padding: 15px 0;
            margin-bottom: 0;
        }
        
        .nav-link {
            color: white !important;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            color: #FFD700 !important;
            transform: translateY(-2px);
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: var(--secondary-color);
        }
        
        .breadcrumb-item.active {
            color: var(--accent-color);
        }
        .form-floating .select2-container .select2-selection--single {
  height: calc(3.5rem + 2px);             /* เท่ากับ form-control ใน form-floating */
  border: 1px solid var(--bs-border-color);
  border-radius: .375rem;                  /* เท่า .form-control */
  display: flex; align-items: center;      /* จัดแนวตรงกลาง */
  padding: 0 .75rem;                       /* เว้นด้านข้างเหมือน form-control */
}

/* จัดข้อความให้กึ่งกลางแนวตั้ง */
.form-floating 
  .select2-container--default 
  .select2-selection--single 
  .select2-selection__rendered {
  line-height: 1.25;
  padding-left: 0;                         /* เพราะเราใส่ padding ที่ selection แล้ว */
  width: 100%;
}

/* ปรับลูกศรให้สูงเท่ากันและชิดขวาพอดี */
.form-floating 
  .select2-container--default 
  .select2-selection--single 
  .select2-selection__arrow {
  height: calc(3.5rem + 2px);
  right: .75rem;
}

/* แก้ label ของ form-floating ให้ลอยถูกต้อง (ต้องมี option แรกว่าง) */
.form-floating select[aria-hidden="true"] + label,
.form-floating .select2-selection--single + label { 
  opacity: .65;
}
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg top-navbar">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="../dashboard.php">
                <i class="fas fa-arrow-left me-2"></i>กลับสู่ Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text text-white">
                    <i class="fas fa-user me-1"></i>เพิ่มข้อมูลวัสดุใหม่
                </span>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <div class="container-fluid mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">หน้าหลัก</a></li>
                <li class="breadcrumb-item"><a href="list.php">รายการวัสดุ</a></li>
                <li class="breadcrumb-item active">เพิ่มวัสดุใหม่</li>
            </ol>
        </nav>
    </div>

    <div class="container-fluid">
        <div class="main-container">
            <!-- Header Section -->
            <div class="header-section">
                <h2><i class="fas fa-plus-circle me-3"></i>เพิ่มข้อมูล Material</h2>
                <p>ระบบจัดการ SSP Code อัตโนมัติ และข้อมูลวัสดุครบถ้วน</p>
                
                <div class="ssp-preview">
                    <h4><i class="fas fa-barcode me-2"></i>ตัวอย่าง SSP Code ที่จะสร้าง</h4>
                    <div class="preview-code" id="sspPreview">-- เลือกข้อมูลเพื่อดูตัวอย่าง --</div>
                    <small>รูปแบบ: Type(1) + Group(3) + Supplier(3) + RunNumber(5) - รหัสจริงจะสร้างเมื่อบันทึก</small>
                </div>
            </div>

            <!-- Progress Steps -->
            <div class="progress-steps mt-4">
                <div class="step active" id="step1">
                    <div class="step-circle">1</div>
                    <small>ข้อมูลหลัก</small>
                </div>
                <div class="step" id="step2">
                    <div class="step-circle">2</div>
                    <small>รายละเอียด</small>
                </div>
                <div class="step" id="step3">
                    <div class="step-circle">3</div>
                    <small>ยืนยัน</small>
                </div>
            </div>

            <form id="materialForm" method="POST" action="save_material.php">
                <!-- ส่วนข้อมูลหลัก -->
                <div class="form-section">
                    <h5><i class="fas fa-info-circle me-2"></i>ข้อมูลหลัก</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="materialType" name="material_type_id" required>
                                    <option value="">เลือกประเภท</option>
                                    <!-- จะถูกโหลดจาก AJAX -->
                                </select>
                                <label for="materialType">ประเภทวัสดุ <span class="required-field">*</span></label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="materialGroup" name="group_id" required>
                                    <option value="">เลือกกลุ่ม</option>
                                    <!-- จะถูกโหลดจาก AJAX -->
                                </select>
                                <label for="materialGroup">กลุ่มวัสดุ <span class="required-field">*</span></label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="supplier" name="supplier_id" required>
                                    <option value="">เลือกผู้ขาย</option>
                                    <!-- จะถูกโหลดจาก AJAX -->
                                </select>
                                <label for="supplier">ผู้ขาย <span class="required-field">*</span></label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="unit" name="unit_id" required>
                                    <option value="">เลือกหน่วย</option>
                                    <!-- จะถูกโหลดจาก AJAX -->
                                </select>
                                <label for="unit">หน่วยหลัก <span class="required-field">*</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="materialName" name="name" required>
                                <label for="materialName">ชื่อวัสดุ <span class="required-field">*</span></label>
                            </div>
                            <div class="auto-name-hint" id="autoNameHint" style="display: none;">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>สำหรับกระดาษ:</strong> ระบบจะสร้างชื่ออัตโนมัติจาก ประเภทกระดาษ + แบรนด์ + GSM + ขนาด
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="materialName2" name="name2">
                                <label for="materialName2">ชื่อวัสดุ (ภาษาอังกฤษ)</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ส่วนรายละเอียดเฉพาะ - กระดาษ -->
                <div class="form-section hidden-section" id="paperboardSection">
                    <h5><i class="fas fa-scroll me-2"></i>รายละเอียดเฉพาะ - Paperboard/กระดาษ</h5>
                    
                    <!-- ข้อมูลพื้นฐาน -->
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>ข้อมูลพื้นฐานกระดาษ</strong>
                    </div>
                    
                    <!-- ประเภทกระดาษ -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="typeTh" name="type_paperboard_th" placeholder="เช่น กระดาษคาร์ตัน">
                                <label for="typeTh">ประเภทกระดาษ (ไทย) <span class="text-danger">*</span></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="typeEn" name="type_paperboard_en" placeholder="e.g. Duplex Board">
                                <label for="typeEn">ประเภทกระดาษ (อังกฤษ)</label>
                            </div>
                        </div>
                    </div>

                    <!-- แบรนด์ และ คุณสมบัติ -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="brand" name="brand" placeholder="เช่น MOORIM">
                                <label for="brand">แบรนด์ 1</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="brand2" name="brand2" placeholder="แบรนด์สำรอง">
                                <label for="brand2">แบรนด์ 2</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="number" class="form-control" id="gsm" name="gsm" placeholder="300">
                                <label for="gsm">GSM <span class="text-danger">*</span></label>
                            </div>
                            <small class="text-muted">น้ำหนักกระดาษ (กรัมต่อตารางเมตร)</small>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="number" step="0.01" class="form-control" id="caliper" name="caliper" placeholder="0.45">
                                <label for="caliper">Caliper</label>
                            </div>
                            <small class="text-muted">ความหนากระดาษ (มม.)</small>
                        </div>
                    </div>

                    <!-- ขนาดกระดาษ -->
                    <div class="alert alert-info">
                        <i class="fas fa-ruler me-2"></i>
                        <strong>ขนาดกระดาษ</strong> - กรอกเป็น มม. หรือ นิ้ว (ระบบจะแปลงให้อัตโนมัติ)
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="number" step="0.01" class="form-control" id="widthMm" name="w_mm" placeholder="840">
                                <label for="widthMm">ความกว้าง (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="number" step="0.01" class="form-control" id="lengthMm" name="l_mm" placeholder="900">
                                <label for="lengthMm">ความยาว (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="number" step="0.01" class="form-control bg-light" id="widthInch" name="w_inch" readonly>
                                <label for="widthInch">ความกว้าง (นิ้ว)</label>
                            </div>
                            <small class="text-muted">คำนวณอัตโนมัติ</small>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="number" step="0.01" class="form-control bg-light" id="lengthInch" name="l_inch" readonly>
                                <label for="lengthInch">ความยาว (นิ้ว)</label>
                            </div>
                            <small class="text-muted">คำนวณอัตโนมัติ</small>
                        </div>
                    </div>

                    <!-- การเคลือบและการรับรอง -->
                    <div class="alert alert-secondary">
                        <i class="fas fa-layer-group me-2"></i>
                        <strong>การเคลือบและการรับรอง</strong> (ถ้ามี)
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="laminated1" name="laminated1" placeholder="เช่น BOPP Matt">
                                <label for="laminated1">Laminated 1</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="laminated2" name="laminated2" placeholder="เช่น UV Coating">
                                <label for="laminated2">Laminated 2</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="certificated" name="certificated" placeholder="เช่น FSC, ISO">
                                <label for="certificated">การรับรอง</label>
                            </div>
                        </div>
                    </div>

                    <!-- น้ำหนักคำนวณอัตโนมัติ -->
                    <div class="alert alert-success">
                        <i class="fas fa-calculator me-2"></i>
                        <strong>น้ำหนักต่อแผ่น</strong> - คำนวณอัตโนมัติจาก: (กว้าง × ยาว × GSM) ÷ 1,000,000
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="calculation-display p-3 rounded" style="background: rgba(5, 150, 105, 0.1); border: 2px solid rgba(5, 150, 105, 0.3);">
                                <div id="weightCalculation" class="text-success fw-bold fs-5">
                                    <i class="fas fa-info-circle me-2"></i>กรอกข้อมูลขนาดและ GSM เพื่อคำนวณน้ำหนัก
                                </div>
                                <small class="text-muted d-block mt-2">
                                    ตัวอย่าง: (840 × 900 × 300) ÷ 1,000,000 = <strong>0.2268 กก./แผ่น</strong>
                                </small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="number" step="0.0001" class="form-control bg-light fs-5 fw-bold text-success" 
                                       id="weightKgPerSheet" name="weight_kg_per_sheet" readonly>
                                <label for="weightKgPerSheet"><i class="fas fa-weight me-2"></i>น้ำหนักต่อแผ่น (กก.)</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ส่วนรายละเอียดเฉพาะ - หมึก -->
                <div class="form-section hidden-section" id="inkSection">
                    <h5><i class="fas fa-paint-brush me-2"></i>รายละเอียดเฉพาะ - Ink/หมึก</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="inkType" name="ink_type">
                                <label for="inkType">ประเภทหมึก</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="color" name="color">
                                <label for="color">สี</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="inkGroup" name="ink_group">
                                <label for="inkGroup">กลุ่มหมึก</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="side" name="side">
                                <label for="side">ด้าน (Side)</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>ข้อมูลกระดาษที่ใช้พิมพ์</strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="brandPaperboard" name="brand_paperboard">
                                <label for="brandPaperboard">แบรนด์กระดาษ</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="typePaperboard" name="type_paperboard">
                                <label for="typePaperboard">ประเภทกระดาษ</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" class="form-control" id="gsmPaperboard" name="gsm_paperboard">
                                <label for="gsmPaperboard">GSM กระดาษ</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="laminated1Ink" name="laminated1">
                                <label for="laminated1Ink">Laminated 1</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="laminated2Ink" name="laminated2">
                                <label for="laminated2Ink">Laminated 2</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="coating1" name="coating1">
                                <label for="coating1">Coating 1</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="coating2" name="coating2">
                                <label for="coating2">Coating 2</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ส่วนรายละเอียดเฉพาะ - Adhesive -->
                <div class="form-section hidden-section" id="adhesiveSection">
                    <h5><i class="fas fa-droplet me-2"></i>รายละเอียดเฉพาะ - Adhesive/กาว</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="adhesiveType" name="adhesive_type">
                                <label for="adhesiveType">ประเภทกาว</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="applyOn" name="apply_on">
                                <label for="applyOn">ใช้กับวัสดุ</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="application" name="application">
                                <label for="application">การใช้งาน</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ส่วนรายละเอียดเฉพาะ - Coating -->
                <div class="form-section hidden-section" id="coatingSection">
                    <h5><i class="fas fa-spray-can me-2"></i>รายละเอียดเฉพาะ - Coating/เคลือบผิว</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="coatingBased" name="coating_based">
                                <label for="coatingBased">ฐานเคลือบ (Based)</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="coatingType" name="coating_type">
                                <label for="coatingType">ประเภท</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="effect" name="effect">
                                <label for="effect">เอฟเฟกต์</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="thickness" name="thickness">
                                <label for="thickness">ความหนา</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ส่วนรายละเอียดเฉพาะ - Film -->
                <div class="form-section hidden-section" id="filmSection">
                    <h5><i class="fas fa-file-invoice me-2"></i>รายละเอียดเฉพาะ - Film/ฟิล์ม</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="filmType" name="film_type">
                                <label for="filmType">ประเภทฟิล์ม</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="filmCode" name="film_code">
                                <label for="filmCode">รหัสฟิล์ม</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="filmEffect" name="film_effect">
                                <label for="filmEffect">เอฟเฟกต์</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="filmThickness" name="film_thickness">
                                <label for="filmThickness">ความหนา</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ส่วนรายละเอียดเฉพาะ - Foil -->
                <div class="form-section hidden-section" id="foilSection">
                    <h5><i class="fas fa-certificate me-2"></i>รายละเอียดเฉพาะ - Foil/ฟอยล์</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="foilCode" name="foil_code">
                                <label for="foilCode">รหัสฟอยล์</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="foilColor" name="foil_color">
                                <label for="foilColor">สี</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="foilEffect" name="foil_effect">
                                <label for="foilEffect">เอฟเฟกต์</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-ruler me-2"></i>
                        <strong>ขนาดฟอยล์</strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="foilWidthMm" name="foil_w_mm">
                                <label for="foilWidthMm">ความกว้าง (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="foilLengthM" name="foil_l_m">
                                <label for="foilLengthM">ความยาว (เมตร)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="foilM2" name="foil_m2" readonly>
                                <label for="foilM2">พื้นที่ (ตร.ม.)</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ส่วนรายละเอียดเฉพาะ - Corrugated Box -->
                <div class="form-section hidden-section" id="corrugatedSection">
                    <h5><i class="fas fa-box me-2"></i>รายละเอียดเฉพาะ - Corrugated Box/กล่องลูกฟูก</h5>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="caseNumber" name="case_number">
                                <label for="caseNumber">Case Number</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-expand me-2"></i>
                        <strong>ขนาดภายนอก (Outer Dimensions)</strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="wOuterMm" name="w_outer_mm">
                                <label for="wOuterMm">กว้าง (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="lOuterMm" name="l_outer_mm">
                                <label for="lOuterMm">ยาว (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="hOuterMm" name="h_outer_mm">
                                <label for="hOuterMm">สูง (มม.)</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-compress me-2"></i>
                        <strong>ขนาดภายใน (Inner Dimensions)</strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="wInnerMm" name="w_inner_mm">
                                <label for="wInnerMm">กว้าง (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="lInnerMm" name="l_inner_mm">
                                <label for="lInnerMm">ยาว (มม.)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.01" class="form-control" id="hInnerMm" name="h_inner_mm">
                                <label for="hInnerMm">สูง (มม.)</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-secondary">
                        <i class="fas fa-layer-group me-2"></i>
                        <strong>โครงสร้างลูกฟูก</strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="typeFlute" name="type_flute">
                                <label for="typeFlute">ประเภทฟลูต</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="number" class="form-control" id="layer" name="layer">
                                <label for="layer">จำนวนชั้น</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="number" step="0.0001" class="form-control" id="weightKgPerBox" name="weight_kg_per_box">
                                <label for="weightKgPerBox">น้ำหนัก (กก./กล่อง)</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="logo" name="logo">
                                <label for="logo">โลโก้</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="liner1" name="liner">
                                <label for="liner1">Liner 1</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="flute1" name="flute">
                                <label for="flute1">Flute 1</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="liner2" name="liner2">
                                <label for="liner2">Liner 2</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="flute2" name="flute2">
                                <label for="flute2">Flute 2</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="liner3" name="liner3">
                                <label for="liner3">Liner 3</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ปุ่มควบคุม -->
                <div class="form-section">
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary-custom btn-custom" onclick="window.history.back()">
                            <i class="fas fa-arrow-left me-2"></i>ยกเลิก
                        </button>
                        <div>
                            <button type="button" class="btn btn-primary-custom btn-custom me-2" id="previewBtn">
                                <i class="fas fa-eye me-2"></i>ดูตัวอย่าง
                            </button>
                            <button type="submit" class="btn btn-success-custom btn-custom">
                                <i class="fas fa-save me-2"></i>บันทึกข้อมูล
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal สำหรับดูตัวอย่าง -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>ตัวอย่างข้อมูลที่จะบันทึก</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="previewContent">
                    <!-- เนื้อหาตัวอย่างจะถูกใส่ที่นี่ -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-success" onclick="$('#materialForm').submit()">
                        <i class="fas fa-save me-2"></i>ยืนยันและบันทึก
                    </button>
                </div>
            </div>
        </div>
    </div>

<!-- ต้องโหลด jQuery ก่อน -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.0/jquery.min.js"></script>
<!-- แล้วค่อยโหลด Bootstrap -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// =========================
// ค่าคงที่และตัวแปรใช้งานร่วม
// =========================
const PAPERBOARD_GROUPS = ['501','551','801','802','803','804'];
const ALLOWED_UNITS_PAPERBOARD = [
  // ไทย
  'แผ่น','ห่อ','รีม','ม้วน','กิโล','กิโลกรัม','ตัน',
  // เผื่อชื่ออังกฤษ/สัญลักษณ์
  'sheet','pack','ream','roll','kg','kilogram','ton','metric ton','t'
];
// ✅ กลุ่มหมึก (ใส่ทั้ง 553 และ 556 เผื่อโค้ดเดิมมีใช้ต่างกัน)
const INK_GROUPS = ['553','556'];
const ALLOWED_UNITS_INK = [
  'กระป๋อง','can',
  'กิโล','กิโลกรัม','kg','kilogram'
];
let ALL_UNITS = []; // เก็บทุกหน่วยที่โหลดจาก API

// =========================
// โหลดข้อมูลพื้นฐาน (ประเภท/กลุ่ม/ผู้ขาย/หน่วย)
// =========================
function loadMaterialTypes() {
  $.ajax({
    url: '../../api/get_material_types.php',
    method: 'GET',
    dataType: 'json',
    success: function(data) {
      let options = '<option value="">เลือกประเภท</option>';
      data.forEach(item => {
        const displayText = `${item.type_name} (${item.type_code})`;
        options += `<option value="${item.material_type_id}" data-code="${item.type_code}">${displayText}</option>`;
      });
      $('#materialType').html(options);
    },
    error: function(xhr, status, error) { console.error('AJAX Error:', error); }
  });
}

function loadGroups() {
  $.ajax({
    url: '../../api/get_groups.php',
    method: 'GET',
    dataType: 'json',
    success: function(data) {
      let options = '<option value="">เลือกกลุ่ม</option>';
      data.forEach(item => {
        const displayText = `${item.name} (${item.id})`;
        options += `<option value="${item.id}" data-code="${item.id}">${displayText}</option>`;
      });
      $('#materialGroup').html(options);
    },
    error: function(xhr, status, error) { console.error('Error loading groups:', error); }
  });
}

function loadSuppliers() {
  $.ajax({
    url: '../../api/get_suppliers.php',
    method: 'GET',
    dataType: 'json',
    success: function(data) {
      let options = '<option value=""></option>'; // ให้เป็นค่าว่างเพื่อใช้ placeholder
      data.forEach(item => {
        // ใส่ data-code ไว้ให้ค้นหาด้วย
        const text = `${item.supplier_name} (${item.supplier_code})`;
        options += `<option value="${item.supplier_id}" data-code="${item.supplier_code}">${text}</option>`;
      });
      const $sel = $('#supplier');

      // ถ้าเคย init select2 แล้ว ให้ทำลายก่อน
      if ($sel.data('select2')) { $sel.select2('destroy'); }

      $sel.html(options);

      initSupplierSelect2(); // ✅ เรียกให้เป็น select2 ที่ค้นหาได้
    },
    error: function(xhr, status, error) {
      console.error('Error loading suppliers:', error);
    }
  });
}
function initSupplierSelect2() {
  const normalize = s => (s || '').toString().toLowerCase().trim();

  // custom matcher: ค้นหาจากข้อความที่เห็น + data-code
  function supplierMatcher(params, data) {
    if ($.trim(params.term) === '') return data; // ไม่พิมพ์ -> แสดงทั้งหมด
    if (typeof data.text === 'undefined') return null;

    const term = normalize(params.term);
    const text = normalize(data.text);

    // ดึง data-code จาก option element
    let code = '';
    if (data.element && data.element.dataset && data.element.dataset.code) {
      code = normalize(data.element.dataset.code);
    }

    // match ชื่อหรือโค้ด
    if (text.indexOf(term) > -1 || code.indexOf(term) > -1) {
      return data;
    }
    return null;
  }

  $('#supplier').select2({
    placeholder: 'เลือกหรือค้นหาผู้ขาย...',
    allowClear: true,
    width: '100%',
    matcher: supplierMatcher,
    // ถ้าอยู่ใน modal อาจต้องกำหนด dropdownParent เช่น:
    // dropdownParent: $('#yourModalId')
  });
}


// ✅ โหลดหน่วยครั้งเดียว เก็บไว้ใน ALL_UNITS แล้วกรองตามกลุ่ม
function loadUnits() {
  $.ajax({
    url: '../../api/get_units.php',
    method: 'GET',
    dataType: 'json',
    success: function(data) {
      ALL_UNITS = Array.isArray(data) ? data : [];
      filterUnitsByGroup();        // กรองตามกลุ่มปัจจุบัน
      setDefaultUnitIfPaperboard();// ตั้งค่าเริ่มต้นถ้าเป็นกลุ่มกระดาษ
    },
    error: function(xhr, status, error) {
      console.error('Error loading units:', error);
      $('#unit').html('<option value="">เลือกหน่วย</option>');
    }
  });
}

// กรองหน่วยสำหรับกลุ่มกระดาษ
function filterUnitsByGroup() {
  const groupId = $('#materialGroup').val();
  let unitsToRender = [];

  if (PAPERBOARD_GROUPS.includes(groupId)) {
    // กระดาษ: แสดงเฉพาะหน่วยที่อนุญาต
    unitsToRender = ALL_UNITS.filter(u => {
      const name   = String(u.unit_name   || '').trim().toLowerCase();
      const symbol = String(u.unit_symbol || '').trim().toLowerCase();
      return ALLOWED_UNITS_PAPERBOARD.some(a => {
        a = a.toLowerCase();
        return name === a || symbol === a;
      });
    });
  } else if (INK_GROUPS.includes(groupId)) {
    // ✅ หมึก: เฉพาะ กระป๋อง / กิโล(กรัม)
    unitsToRender = ALL_UNITS.filter(u => {
      const name   = String(u.unit_name   || '').trim().toLowerCase();
      const symbol = String(u.unit_symbol || '').trim().toLowerCase();
      return ALLOWED_UNITS_INK.some(a => {
        a = a.toLowerCase();
        return name === a || symbol === a;
      });
    });
  } else {
    // กลุ่มอื่น แสดงทั้งหมด
    unitsToRender = ALL_UNITS.slice();
  }

  let options = '<option value="">เลือกหน่วย</option>';
  unitsToRender.forEach(item => {
    const displayText = item.unit_symbol
      ? `${item.unit_name} (${item.unit_symbol})`
      : item.unit_name;
    options += `<option value="${item.unit_id}">${displayText}</option>`;
  });
  $('#unit').html(options);
}


// ตั้งหน่วยเริ่มต้นให้กลุ่มกระดาษ (ถ้ามี “แผ่น” ให้เลือกอัตโนมัติ)
function setDefaultUnitIfInk() {
  const groupId = $('#materialGroup').val();
  if (!INK_GROUPS.includes(groupId)) return;
  const sel = $('#unit');
  const preferred = ['กระป๋อง','can','กิโล','kg']; // ลำดับความชอบ
  for (const p of preferred) {
    const opt = sel.find('option').filter(function(){
      return $(this).text().toLowerCase().includes(p.toLowerCase());
    }).first();
    if (opt.length) { sel.val(opt.val()); break; }
  }
}

// =========================
// SSP Code Preview
// =========================
// ค้นหาฟังก์ชัน updateSSPPreview (ประมาณบรรทัด 1100-1180)
function updateSSPPreview() {
  const typeId = $('#materialType').val();
  const groupId = $('#materialGroup').val();
  const supplierId = $('#supplier').val();

  if (typeId && groupId && supplierId) {
    // เรียก API เพื่อ generate SSP Code preview
    $.ajax({
      url: '../../api/generate_ssp_code.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        material_type_id: typeId,
        group_id: groupId,
        supplier_id: supplierId
      }),
      success: function(response) {
        if (response.success) {
          $('#sspPreview').text(response.ssp_code);
        }
      },
      error: function() {
        $('#sspPreview').text('-----');
      }
    });
  } else {
    $('#sspPreview').text('-----');
  }
}

// =========================
/** แสดง/ซ่อนฟอร์มตามกลุ่ม */
// =========================
function toggleSpecificSections() {
  const groupId = $('#materialGroup').val();

  $('.hidden-section').hide();
  $('#autoNameHint').hide();

  if (PAPERBOARD_GROUPS.includes(groupId)) {
    $('#paperboardSection').show();
    $('#autoNameHint').show();
    $('#materialName').prop('readonly', true).addClass('bg-light');
  } else if (groupId === '553') {
    $('#inkSection').show();
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else if (groupId === '557') {
    $('#coatingSection').show();
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else if (groupId === '556') {
    $('#adhesiveSection').show();
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else if (groupId === '714') {
    $('#filmSection').show();
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else if (groupId === '260') {
    $('#corrugatedSection').show();
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else if (groupId === '555') {
    $('#foilSection').show();
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else if (groupId === '262') {
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  } else {
    $('#materialName').prop('readonly', false).removeClass('bg-light');
  }
}

// =========================
/** ตั้งชื่ออัตโนมัติสำหรับกระดาษ */
// =========================
function updatePaperboardName() {
  const groupId = $('#materialGroup').val();
  if (!PAPERBOARD_GROUPS.includes(groupId)) return;

  const typeTh     = $('#typeTh').val()      || '';
  const brand      = $('#brand').val()       || '';
  const gsm        = $('#gsm').val()         || '';
  const widthInch  = $('#widthInch').val()   || '';
  const lengthInch = $('#lengthInch').val()  || '';

  if (typeTh && brand && gsm && widthInch && lengthInch) {
    const autoName = `${typeTh} ${brand} ${gsm}g ${widthInch}×${lengthInch}นิ้ว`;
    $('#materialName').val(autoName);
  }
}

// =========================
// แปลงขนาด mm <-> inch
// =========================
function convertToInches() {
  const widthMm  = parseFloat($('#widthMm').val());
  const lengthMm = parseFloat($('#lengthMm').val());

  $('#widthInch').val( widthMm  > 0 ? (widthMm  / 25.4).toFixed(2) : '' );
  $('#lengthInch').val(lengthMm > 0 ? (lengthMm / 25.4).toFixed(2) : '' );
}

function convertToMm() {
  const widthInch  = parseFloat($('#widthInch').val());
  const lengthInch = parseFloat($('#lengthInch').val());

  if (widthInch  > 0 && !$('#widthMm').is(':focus'))  $('#widthMm').val((widthInch  * 25.4).toFixed(2));
  if (lengthInch > 0 && !$('#lengthMm').is(':focus')) $('#lengthMm').val((lengthInch * 25.4).toFixed(2));
}

// =========================
// คำนวณต่าง ๆ
// =========================
function calculateWeight() {
  const width  = parseFloat($('#widthMm').val())  || 0;
  const length = parseFloat($('#lengthMm').val()) || 0;
  const gsm    = parseFloat($('#gsm').val())      || 0;

  if (width > 0 && length > 0 && gsm > 0) {
    const weight = (width * length * gsm) / 1000000;
    $('#weightKgPerSheet').val(weight.toFixed(4));
    $('#weightCalculation').text(`${width} × ${length} × ${gsm} ÷ 1,000,000 = ${weight.toFixed(4)} กก./แผ่น`);
  } else {
    $('#weightKgPerSheet').val('');
    $('#weightCalculation').text('');
  }
}

function calculateFoilArea() {
  const widthMm = parseFloat($('#foilWidthMm').val()) || 0;
  const lengthM = parseFloat($('#foilLengthM').val()) || 0;

  if (widthMm > 0 && lengthM > 0) {
    const widthM = widthMm / 1000;
    const area = widthM * lengthM;
    $('#foilM2').val(area.toFixed(4));
  } else {
    $('#foilM2').val('');
  }
}

// =========================
// พรีวิว
// =========================
function showPreview() {
  const groupId = $('#materialGroup').val();
  let previewHtml = '<div class="row">';

  // ข้อมูลหลัก
  previewHtml += '<div class="col-12"><h6 class="text-primary">ข้อมูลหลัก</h6>';
  previewHtml += `<p><strong>SSP Code:</strong> ${$('#sspPreview').text()}</p>`;
  previewHtml += `<p><strong>ชื่อวัสดุ:</strong> ${$('#materialName').val()}</p>`;
  previewHtml += `<p><strong>ประเภท:</strong> ${$('#materialType option:selected').text()}</p>`;
  previewHtml += `<p><strong>กลุ่ม:</strong> ${$('#materialGroup option:selected').text()}</p>`;
  previewHtml += `<p><strong>ผู้ขาย:</strong> ${$('#supplier option:selected').text()}</p>`;
  previewHtml += `<p><strong>หน่วย:</strong> ${$('#unit option:selected').text()}</p>`;
  previewHtml += '</div>';

  // Paperboard
  if (PAPERBOARD_GROUPS.includes(groupId) && $('#paperboardSection').is(':visible')) {
    previewHtml += '<div class="col-12 mt-3"><h6 class="text-success">รายละเอียดกระดาษ</h6>';
    previewHtml += `<p><strong>แบรนด์ 1:</strong> ${$('#brand').val()}</p>`;
    previewHtml += `<p><strong>แบรนด์ 2:</strong> ${$('#brand2').val()}</p>`;
    previewHtml += `<p><strong>GSM:</strong> ${$('#gsm').val()}</p>`;
    previewHtml += `<p><strong>Caliper:</strong> ${$('#caliper').val()}</p>`;
    previewHtml += `<p><strong>ขนาด:</strong> ${$('#widthMm').val()} × ${$('#lengthMm').val()} มม. (${$('#widthInch').val()} × ${$('#lengthInch').val()} นิ้ว)</p>`;
    previewHtml += `<p><strong>น้ำหนัก:</strong> ${$('#weightKgPerSheet').val()} กก./แผ่น</p>`;
    if ($('#laminated1').val() || $('#laminated2').val()) {
      previewHtml += `<p><strong>Laminated:</strong> ${$('#laminated1').val()} / ${$('#laminated2').val()}</p>`;
    }
    if ($('#certificated').val()) {
      previewHtml += `<p><strong>การรับรอง:</strong> ${$('#certificated').val()}</p>`;
    }
    previewHtml += '</div>';
  }

  // หมึก / กาว / เคลือบ / ฟิล์ม / ฟอยล์ / ลูกฟูก (ยึดตามโค้ดเดิมของคุณ)
  // ... (คงโค้ดเดิมของ showPreview สำหรับแต่ละกลุ่ม) ...

  previewHtml += '</div>';
  $('#previewContent').html(previewHtml);
  $('#previewModal').modal('show');
}

// =========================
// Submit: สร้าง SSP แล้วค่อย submit form
// =========================
$('#materialForm').on('submit', function(e) {
  e.preventDefault();

  if (!$('#materialType').val() || !$('#materialGroup').val() || !$('#supplier').val() || !$('#materialName').val()) {
    alert('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
    return false;
  }

  $.ajax({
    url: '../../api/generate_ssp_code.php',
    method: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({
      material_type_id: $('#materialType').val(),
      group_id: $('#materialGroup').val(),
      supplier_id: $('#supplier').val()
    }),
    success: function(response) {
      if (response.success) {
        if (!$('#materialForm').find('input[name="ssp_code"]').length) {
          $('#materialForm').append(`<input type="hidden" name="ssp_code" value="${response.ssp_code}">`);
        } else {
          $('#materialForm').find('input[name="ssp_code"]').val(response.ssp_code);
        }
        $('#materialForm')[0].submit();
      }
    },
    error: function(xhr, status, error) {
      console.error('Error generating SSP Code:', error);
      alert('เกิดข้อผิดพลาดในการสร้าง SSP Code');
    }
  });
});

// =========================
// เริ่มทำงานเมื่อโหลดหน้า
// =========================
$(document).ready(function() {
  // โหลด dropdown
  loadMaterialTypes();
  loadGroups();
  loadSuppliers();
  loadUnits(); // จะเรียก filterUnitsByGroup() ต่อให้

  // อัปเดต SSP Preview
  $('#materialType, #materialGroup, #supplier').on('change', updateSSPPreview);

  // เปลี่ยนกลุ่ม → แสดง/ซ่อนฟอร์ม + กรองหน่วย + default หน่วย + อัปเดตชื่อ
$('#materialGroup').on('change', function() {
  toggleSpecificSections();
  filterUnitsByGroup();
  setDefaultUnitIfPaperboard();
  setDefaultUnitIfInk();        // ✅ เพิ่มบรรทัดนี้
  updatePaperboardName();
});

  // Paperboard: ตั้งชื่ออัตโนมัติจากข้อมูลที่กรอก
  $('#typeTh, #brand, #gsm').on('input change', updatePaperboardName);

  // mm → inch แล้วตั้งชื่อ, และ inch → mm แล้วตั้งชื่อ
  $('#widthMm, #lengthMm').on('input change', function() {
    convertToInches();
    updatePaperboardName();
  });
  $('#widthInch, #lengthInch').on('input change', function() {
    convertToMm();
    updatePaperboardName();
  });

  // คำนวณน้ำหนัก / พื้นที่ฟอยล์
  $('#widthMm, #lengthMm, #gsm').on('input', calculateWeight);
  $('#foilWidthMm, #foilLengthM').on('input', calculateFoilArea);

  // พรีวิว
  $('#previewBtn').on('click', showPreview);

  // เรียกครั้งแรกตอนหน้าโหลด
  toggleSpecificSections();
  convertToInches();
  updatePaperboardName();
  updateSSPPreview();
});
</script>

</body>
</html>