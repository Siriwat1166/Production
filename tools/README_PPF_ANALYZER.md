# PPF Ink Key Analyzer

## 📖 Overview
Web-based tool สำหรับวิเคราะห์ไฟล์ PPF (Print Production Format) จาก Heidelberg Prinect / ArtPro+  
แสดง **ink key zone histogram** แบบ interactive พร้อม export CSV/JSON

### ฟีเจอร์หลัก
- ✅ รองรับ CIP3 PPF format (ASCII85, Hex, Binary, **RunLength/PackBits**)
- ✅ แสดงกราฟ ink coverage ตาม zone (32.5mm, 30mm, 35mm, 40mm)
- ✅ รองรับ multi-color separation (CMYK + spot colors)
- ✅ Export ข้อมูลเป็น CSV หรือ JSON
- ✅ Demo mode สำหรับทดสอบ
- ✅ Dark mode UI ที่สวยงาม

---

## 🚀 Installation

### Windows

1. **ติดตั้ง Python 3.8+** (ถ้ายังไม่มี)
   - ดาวน์โหลดจาก: https://www.python.org/downloads/
   - ✅ เลือก "Add Python to PATH" ตอนติดตั้ง

2. **เปิด Command Prompt** ที่โฟลเดอร์ `tools/`
   ```cmd
   cd C:\path\to\Production\tools
   ```

3. **รัน startup script**
   ```cmd
   start_ppf_analyzer.bat
   ```

   Script จะทำให้อัตโนมัติ:
   - สร้าง virtual environment
   - ติดตั้ง dependencies (flask, numpy, waitress)
   - รัน web server บน http://localhost:5000

### Linux / macOS

1. **ติดตั้ง Python 3.8+** (มักมีมาให้อยู่แล้ว)

2. **เปิด Terminal** ที่โฟลเดอร์ `tools/`
   ```bash
   cd /path/to/Production/tools
   ```

3. **รัน startup script**
   ```bash
   ./start_ppf_analyzer.sh
   ```

---

## 💻 การใช้งาน

### 1. เริ่มต้น
- รัน startup script ตามขั้นตอนด้านบน
- เปิดเบราว์เซอร์: **http://localhost:5000**

### 2. วิเคราะห์ไฟล์ PPF

#### วิธีที่ 1: Upload ไฟล์
- คลิก dropzone หรือลากไฟล์ `.ppf` มาวาง
- เลือก **Zone Width** (32.5mm สำหรับ SM102/CD102)
- กด **"ค้นหา"**

#### วิธีที่ 2: ทดสอบด้วย Demo
- กด **"USE_DEMO_DATA"** เพื่อดูตัวอย่าง

### 3. ดูผลลัพธ์
- **Histogram Graph**: แสดง coverage % ของแต่ละ separation แยกตาม zone
- **Summary Table**: สถิติเฉลี่ย, peak, balance
- **Export**: กด CSV หรือ JSON เพื่อดาวน์โหลดข้อมูล

---

## 🔧 Configuration

### เปลี่ยน Port (ถ้า 5000 ชนกับโปรแกรมอื่น)

แก้ไขไฟล์ `ppf_analyzer_web.py` บรรทัดสุดท้าย:
```python
app.run(host='0.0.0.0', port=5000, debug=False)  # เปลี่ยน 5000 เป็น port อื่น
```

### Deploy Production

สำหรับรัน 24/7 แบบ production-ready:

**Windows:**
```cmd
pip install waitress
waitress-serve --listen=0.0.0.0:5000 ppf_analyzer_web:app
```

**Linux (systemd service):**
```bash
sudo nano /etc/systemd/system/ppf-analyzer.service
```

```ini
[Unit]
Description=PPF Ink Key Analyzer
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/Production/tools
Environment="PATH=/path/to/Production/tools/venv/bin"
ExecStart=/path/to/Production/tools/venv/bin/python ppf_analyzer_web.py
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable ppf-analyzer
sudo systemctl start ppf-analyzer
```

---

## 📊 รองรับ Machine / Zone Width

| เครื่อง                  | Zone Width | รายละเอียด                |
|--------------------------|------------|---------------------------|
| SM102 / CD102 / XL105    | **32.5 mm**| Heidelberg standard       |
| SM52 / SM74 / CD74       | **30.0 mm**| Smaller format            |
| Custom                   | 35/40 mm   | สามารถปรับเองได้          |

---

## 🐛 Troubleshooting

### ❌ "Python not found"
- ติดตั้ง Python 3.8+ จาก https://www.python.org/
- เช็คว่าได้เลือก "Add to PATH" ตอนติดตั้ง

### ❌ "Port 5000 already in use"
- เปลี่ยน port ตามคำแนะนำใน Configuration ด้านบน
- หรือปิดโปรแกรมที่ใช้ port 5000 อยู่

### ❌ "No separations decoded from PPF"
- ไฟล์ PPF อาจเสีย หรือใช้ compression ที่ยังไม่รองรับ
- ส่งไฟล์ตัวอย่างมาให้ทีม dev วิเคราะห์

### ❌ "ImportError: numpy"
- ลบ venv และรันใหม่:
  ```bash
  rm -rf venv
  ./start_ppf_analyzer.sh
  ```

---

## 📁 โครงสร้างไฟล์

```
tools/
├── ppf_analyzer_web.py          # Main Flask application
├── requirements.txt             # Python dependencies
├── start_ppf_analyzer.bat       # Windows startup script
├── start_ppf_analyzer.sh        # Linux/macOS startup script
├── README_PPF_ANALYZER.md       # เอกสารนี้
└── venv/                        # Virtual environment (auto-created)
```

---

## 🔗 Integration กับระบบหลัก

เมนู **"PPF Ink Analyzer"** ถูกเพิ่มใน:
- `pages/dashboard.php` → โหมดคลังสินค้า
- Link: http://localhost:5000 (เปิดในแท็บใหม่)

**หมายเหตุ:** Flask app ต้องรันอยู่เพื่อให้เมนูใช้งานได้

---

## 📝 Notes

- ไฟล์ PPF ที่ใหญ่กว่า 500 MB จะถูกปฏิเสธ (ปรับได้ใน `app.config['MAX_CONTENT_LENGTH']`)
- รองรับ **RunLength (PackBits)** compression จาก ArtPro+ Export to CIP3
- Separation colors จะถูกเลือกอัตโนมัติ (CMYK + spot colors)

---

## 👨‍💻 Developer

- **Version:** 1.1 (RunLength support)
- **Technology:** Flask + NumPy + Chart.js
- **Company:** S.SILPA (Internal Tool)

---

## 📞 Support

พบปัญหาหรือต้องการคำแนะนำเพิ่มเติม ติดต่อ IT Support
