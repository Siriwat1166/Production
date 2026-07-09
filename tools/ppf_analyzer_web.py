#!/usr/bin/env python3
"""
PPF Ink Key Analyzer — Web App (v1.1)
=====================================
Web service สำหรับ S.SILPA: upload ไฟล์ .ppf → ดู ink key histogram ในเบราว์เซอร์

v1.1: เพิ่ม RunLength (PackBits) decoder — รองรับ PPF จาก ArtPro+ Export to CIP3
      ที่ใช้ Compression: RunLength

Installation:
  pip install flask numpy

Run:
  python ppf_analyzer_web.py

แล้วเปิดเบราว์เซอร์: http://localhost:5000

Deploy production (Windows):
  pip install waitress
  waitress-serve --listen=0.0.0.0:5000 ppf_analyzer_web:app
"""

import re
import base64
import io
import csv
from pathlib import Path

from flask import Flask, request, jsonify, Response, render_template_string
import numpy as np

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 500 * 1024 * 1024  # 500 MB

# ════════════════════════════════════════════════════════════════════
# Decoders
# ════════════════════════════════════════════════════════════════════

def decode_runlength(data: bytes, expected_size: int) -> bytes:
    """
    Decode CIP3 RunLength (PackBits-style) compression.
    CIP3 spec: byte n
      0 <= n <= 127  : copy next (n+1) bytes literally
      129 <= n <= 255: repeat next byte (257-n) times
      n == 128       : no-op / end
    """
    out = bytearray()
    i = 0
    L = len(data)
    while i < L and len(out) < expected_size:
        n = data[i]
        i += 1
        if n < 128:
            count = n + 1
            out.extend(data[i:i+count])
            i += count
        elif n > 128:
            if i >= L:
                break
            out.extend(bytes([data[i]]) * (257 - n))
            i += 1
        # n == 128: skip
    return bytes(out[:expected_size])


def try_decode_preview(raw: bytes, encoding: str, compression: str,
                       w: int, h: int, bpc: int) -> bytes | None:
    """แปลง raw preview data → uncompressed pixel bytes"""
    expected = w * h if bpc == 8 else (w * h + 7) // 8

    # Step 1: undo transfer encoding (ASCII85 / Hex / none)
    data = raw
    try:
        if encoding == 'ascii85':
            stripped = b''.join(raw.split())
            if stripped.startswith(b'<~'):
                stripped = stripped[2:]
            if stripped.endswith(b'~>'):
                stripped = stripped[:-2]
            data = base64.a85decode(stripped)
        elif encoding == 'hex':
            hx = b''.join(raw.split())
            if hx.startswith(b'<'):
                hx = hx[1:]
            if hx.endswith(b'>'):
                hx = hx[:-1]
            data = bytes.fromhex(hx.decode())
    except Exception:
        data = raw  # fall back to raw

    # Step 2: undo compression
    if compression in ('runlength', 'rle', 'packbits'):
        return decode_runlength(data, expected)
    elif compression in ('none', '', 'binary'):
        return data[:expected]
    else:
        # unknown compression — try runlength first, then raw
        decoded = decode_runlength(data, expected)
        if len(decoded) == expected:
            return decoded
        return data[:expected]


# ════════════════════════════════════════════════════════════════════
# PPF Parser
# ════════════════════════════════════════════════════════════════════

def parse_ppf(content: bytes) -> dict:
    """Parse CIP3 PPF file bytes → extract separations + metadata"""
    # --- Sheet size ---
    sheet_w_mm = sheet_h_mm = None
    m = re.search(rb'%CIP3AdmSheetSize:\s*([\d.]+)\s+([\d.]+)', content)
    if m:
        sheet_w_mm = float(m.group(1)) * 25.4 / 72
        sheet_h_mm = float(m.group(2)) * 25.4 / 72
    if sheet_w_mm is None:
        m = re.search(rb'%CIP3AdmPaperExtent:\s*([\d.]+)\s+([\d.]+)', content)
        if m:
            sheet_w_mm = float(m.group(1)) * 25.4 / 72
            sheet_h_mm = float(m.group(2)) * 25.4 / 72
    if sheet_w_mm is None:
        m = re.search(rb'%%BoundingBox:\s*([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)', content)
        if m:
            sheet_w_mm = (float(m.group(3)) - float(m.group(1))) * 25.4 / 72
            sheet_h_mm = (float(m.group(4)) - float(m.group(2))) * 25.4 / 72
    if sheet_w_mm is None:
        sheet_w_mm, sheet_h_mm = 690, 560

    # --- Job name ---
    m = re.search(rb'%CIP3AdmJobName:\s*\(([^)]*)\)', content)
    job_name = m.group(1).decode('latin-1', errors='replace') if m and m.group(1) else 'Untitled'

    # --- Separations ---
    separations = {}
    pattern = re.compile(
        rb'%CIP3BeginSeparation(?::\s*\(([^)]*)\))?(.*?)%CIP3EndSeparation',
        re.DOTALL
    )

    for idx, match in enumerate(pattern.finditer(content)):
        block = match.group(2)

        # ชื่อ separation: อาจอยู่หลัง BeginSeparation หรือใน AdmSeparationNames/PreviewColour
        name = None
        if match.group(1):
            name = match.group(1).decode('latin-1', errors='replace')
        if not name:
            nm = re.search(rb'%CIP3AdmSeparationNames?:\s*\(([^)]*)\)', block)
            if nm:
                name = nm.group(1).decode('latin-1', errors='replace')
        if not name:
            nm = re.search(rb'%CIP3PreviewColou?r:\s*\(([^)]*)\)', block)
            if nm:
                name = nm.group(1).decode('latin-1', errors='replace')
        if not name:
            name = f'Separation_{idx+1}'

        w_m = re.search(rb'%CIP3PreviewImageWidth:\s*(\d+)', block)
        h_m = re.search(rb'%CIP3PreviewImageHeight:\s*(\d+)', block)
        if not (w_m and h_m):
            continue
        w, h = int(w_m.group(1)), int(h_m.group(1))

        enc_m = re.search(rb'%CIP3PreviewImageEncoding:\s*(\w+)', block)
        comp_m = re.search(rb'%CIP3PreviewImageCompression:\s*(\w+)', block)
        bpc_m = re.search(rb'%CIP3PreviewImageBitsPerComp:\s*(\d+)', block)

        encoding = enc_m.group(1).decode().lower() if enc_m else 'binary'
        compression = comp_m.group(1).decode().lower() if comp_m else 'none'
        bpc = int(bpc_m.group(1)) if bpc_m else 8

        img_match = re.search(
            rb'%CIP3BeginPreviewImage(.*?)%CIP3EndPreviewImage',
            block, re.DOTALL
        )
        if not img_match:
            continue
        raw = img_match.group(1)
        # ตัด %CIP3PreviewImage* header lines ที่อาจปนใน block
        # เอาเฉพาะหลังบรรทัดสุดท้ายที่ขึ้นต้นด้วย %
        lines_removed = re.sub(rb'^%[^\n]*\n', b'', raw, flags=re.MULTILINE)
        raw_data = lines_removed.strip(b'\r\n')

        try:
            pixels = try_decode_preview(raw_data, encoding, compression, w, h, bpc)
            if pixels is None or len(pixels) == 0:
                continue

            if bpc == 8:
                if len(pixels) < w * h:
                    pixels = pixels + b'\x00' * (w * h - len(pixels))
                arr = np.frombuffer(pixels[:w*h], dtype=np.uint8).reshape(h, w)
            elif bpc == 1:
                bits = np.unpackbits(np.frombuffer(pixels, dtype=np.uint8))
                if len(bits) < w * h:
                    bits = np.pad(bits, (0, w*h - len(bits)))
                arr = (bits[:w*h].reshape(h, w) * 255).astype(np.uint8)
            else:
                continue

            # กัน name ซ้ำ
            base = name
            k = 2
            while name in separations:
                name = f'{base}_{k}'
                k += 1
            separations[name] = arr
        except Exception as e:
            print(f'  ⚠️  Skipped {name}: {e}')

    return {
        'job_name': job_name,
        'sheet_width_mm': sheet_w_mm,
        'sheet_height_mm': sheet_h_mm,
        'separations': separations,
    }


# ════════════════════════════════════════════════════════════════════
# Simulator (demo mode)
# ════════════════════════════════════════════════════════════════════

def simulate_demo():
    sheet_w_mm, sheet_h_mm = 690, 560
    px_per_mm = 4
    W, H = int(sheet_w_mm*px_per_mm), int(sheet_h_mm*px_per_mm)
    seps = {n: np.zeros((H, W), dtype=np.uint8)
            for n in ['Cyan', 'Magenta', 'Yellow', 'Black', 'PANTONE 186 C']}

    def fill(arr, x, y, w, h, d):
        x0, y0 = max(0, int(x*px_per_mm)), max(0, int(y*px_per_mm))
        x1, y1 = min(W, int((x+w)*px_per_mm)), min(H, int((y+h)*px_per_mm))
        if x0 < x1 and y0 < y1:
            arr[y0:y1, x0:x1] = np.maximum(arr[y0:y1, x0:x1], d)

    for sx, sy in [(16.5,16.5),(353,16.5),(16.5,281),(353,281)]:
        for off in range(0, 280, 30):
            fill(seps['PANTONE 186 C'], sx+off, sy+15, 18, 100, 200)
            fill(seps['PANTONE 186 C'], sx+off, sy+150, 18, 100, 200)
        fill(seps['PANTONE 186 C'], sx+50, sy+110, 60, 30, 150)
        fill(seps['Black'], sx+30, sy+80, 80, 50, 80)
        fill(seps['Black'], sx+70, sy+60, 50, 8, 220)
        fill(seps['Cyan'], sx+120, sy+170, 60, 60, 90)
        fill(seps['Magenta'], sx+180, sy+170, 60, 60, 75)
        fill(seps['Yellow'], sx+150, sy+200, 80, 40, 110)

    return {'job_name': 'DEMO (simulated)', 'sheet_width_mm': sheet_w_mm,
            'sheet_height_mm': sheet_h_mm, 'separations': seps}


# ════════════════════════════════════════════════════════════════════
# Zone calculation
# ════════════════════════════════════════════════════════════════════

def compute_zone_histograms(separations, sheet_w_mm, zone_w_mm=32.5):
    histograms = {}
    if not separations:
        return histograms
    sample = next(iter(separations.values()))
    img_w = sample.shape[1]
    n_zones = max(1, int(sheet_w_mm // zone_w_mm))
    px_per_zone = max(1, img_w // n_zones)
    for name, arr in separations.items():
        hist = []
        for i in range(n_zones):
            zone = arr[:, i*px_per_zone:(i+1)*px_per_zone]
            hist.append(float(zone.mean()/255*100) if zone.size else 0.0)
        histograms[name] = hist
    return histograms


# ════════════════════════════════════════════════════════════════════
# HTML UI
# ════════════════════════════════════════════════════════════════════

HTML = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PPF Ink Analyzer · S.SILPA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
  --bg:#0a0e17; --surface:#131927; --surface-2:#1a2236;
  --border:#232b41; --border-strong:#313a55;
  --text:#e7eaf0; --text-dim:#8a92a8; --text-faint:#565d75;
  --accent:#00d4ff; --warn:#fbbf24; --err:#ef4444; --ok:#34d399;
  --cyan:#00aeef; --magenta:#ec008c; --yellow:#ffe600; --black:#9098ad;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{font-family:'Geist',sans-serif;background:var(--bg);
  background-image:linear-gradient(rgba(35,43,65,.15) 1px,transparent 1px),
  linear-gradient(90deg,rgba(35,43,65,.15) 1px,transparent 1px);
  background-size:24px 24px;color:var(--text);min-height:100vh;font-size:14px;line-height:1.5}
.container{max-width:1400px;margin:0 auto;padding:32px 24px}
header{display:flex;justify-content:space-between;align-items:flex-start;
  margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--border)}
.brand{display:flex;align-items:center;gap:14px}
.brand-mark{width:36px;height:36px;background:linear-gradient(135deg,var(--cyan),var(--magenta) 50%,#ff7e3d);
  border-radius:8px;display:flex;align-items:center;justify-content:center;
  font-family:'Geist Mono',monospace;font-weight:700;font-size:16px;color:#0a0e17}
.brand-text{font-family:'Geist Mono',monospace;font-weight:600;letter-spacing:.05em;font-size:14px}
.brand-text .accent{color:var(--accent)}
.brand-sub{font-size:11px;color:var(--text-dim);margin-top:3px;font-family:'Geist Mono',monospace}
.meta{font-family:'Geist Mono',monospace;color:var(--text-faint);font-size:11px;text-align:right}
.status-pill{display:inline-block;padding:2px 8px;background:rgba(52,211,153,.1);
  color:var(--ok);border-radius:3px;margin-top:4px;border:1px solid rgba(52,211,153,.2);font-size:10px}
.ink-ruler{height:3px;margin-bottom:28px;border-radius:2px;opacity:.55;
  background:linear-gradient(90deg,var(--cyan) 0 23%,var(--magenta) 23% 46%,
  var(--yellow) 46% 69%,#4a5269 69% 84%,#c8102e 84% 100%)}
.dropzone{background:var(--surface);border:2px dashed var(--border-strong);border-radius:12px;
  padding:72px 40px;text-align:center;cursor:pointer;transition:all .2s;position:relative}
.dropzone:hover,.dropzone.dragover{border-color:var(--accent);background:var(--surface-2)}
.dropzone-icon{width:44px;height:44px;margin:0 auto 16px;color:var(--text-dim)}
.dropzone:hover .dropzone-icon{color:var(--accent)}
.dropzone-title{font-size:17px;font-weight:500;margin-bottom:6px}
.dropzone-sub{color:var(--text-dim);font-family:'Geist Mono',monospace;font-size:11px}
.controls{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-top:18px;
  padding-top:18px;border-top:1px solid var(--border)}
.control{display:flex;align-items:center;gap:8px;font-family:'Geist Mono',monospace;font-size:11px}
.control label{color:var(--text-faint);letter-spacing:.1em}
select{background:var(--surface-2);border:1px solid var(--border-strong);color:var(--text);
  padding:7px 12px;border-radius:6px;font-family:inherit;font-size:11px;cursor:pointer}
button{background:transparent;border:1px solid var(--border-strong);color:var(--text);
  padding:8px 16px;border-radius:6px;font-family:'Geist Mono',monospace;font-size:11px;
  font-weight:500;cursor:pointer;transition:all .15s}
button:hover{border-color:var(--accent);color:var(--accent)}
button.primary{background:var(--accent);border-color:var(--accent);color:#04111a;font-weight:600}
button.primary:hover{background:#00b8e0;color:#04111a}
.job-info{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--border);
  border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:24px}
.job-info-cell{background:var(--surface);padding:18px 22px}
.job-info-label{font-family:'Geist Mono',monospace;font-size:10px;color:var(--text-faint);
  text-transform:uppercase;letter-spacing:.12em;margin-bottom:8px}
.job-info-value{font-family:'Geist Mono',monospace;font-size:20px;font-weight:600;line-height:1.1}
.job-info-unit{font-size:12px;color:var(--text-faint);font-weight:400;margin-left:4px}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:10px;
  padding:24px;margin-bottom:20px}
.panel-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;
  padding-bottom:14px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:12px}
.panel-title{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.12em;
  font-family:'Geist Mono',monospace;display:flex;align-items:center;gap:8px}
.panel-title::before{content:'';width:3px;height:14px;background:var(--accent);border-radius:1px}
.legend{display:flex;gap:6px;flex-wrap:wrap}
.legend-item{display:flex;align-items:center;gap:8px;font-family:'Geist Mono',monospace;
  font-size:11px;cursor:pointer;padding:5px 10px;border-radius:4px;user-select:none;
  border:1px solid transparent}
.legend-item:hover{background:var(--surface-2);border-color:var(--border)}
.legend-item.disabled{opacity:.35;text-decoration:line-through}
.legend-swatch{width:11px;height:11px;border-radius:2px}
.chart-wrap{height:420px;position:relative}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-family:'Geist Mono',monospace;font-size:12px}
th{text-align:left;padding:10px 14px;color:var(--text-faint);font-weight:500;
  text-transform:uppercase;letter-spacing:.1em;font-size:10px;border-bottom:1px solid var(--border)}
td{padding:12px 14px;border-bottom:1px solid var(--border)}
tbody tr:hover{background:var(--surface-2)}
tbody tr:last-child td{border-bottom:none}
.sep-swatch{display:inline-block;width:10px;height:10px;border-radius:2px;
  margin-right:10px;vertical-align:middle}
.text-num{font-variant-numeric:tabular-nums}
.actions-bar{display:flex;gap:8px;margin-top:24px;flex-wrap:wrap}
.actions-bar .spacer{flex:1}
.loading{display:flex;align-items:center;justify-content:center;padding:80px;
  color:var(--text-dim);font-family:'Geist Mono',monospace;font-size:12px}
.spinner{width:18px;height:18px;border:2px solid var(--border-strong);
  border-top-color:var(--accent);border-radius:50%;margin-right:14px;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.error{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--err);
  padding:14px 18px;border-radius:8px;font-family:'Geist Mono',monospace;font-size:12px;margin-bottom:16px}
.hidden{display:none!important}
footer{margin-top:40px;padding-top:20px;border-top:1px solid var(--border);
  color:var(--text-faint);font-family:'Geist Mono',monospace;font-size:10px;text-align:center}
@media(max-width:768px){.container{padding:20px 14px}.job-info{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="container">
<header>
  <div class="brand">
    <div class="brand-mark">P</div>
    <div>
      <div class="brand-text">PPF.<span class="accent">INK_ANALYZER</span></div>
      <div class="brand-sub">Heidelberg ink key zone visualizer · v1.1 RunLength</div>
    </div>
  </div>
  <div class="meta">
    <div>S.SILPA · INTERNAL</div>
    <div class="status-pill" id="status-pill">READY</div>
  </div>
</header>
<div class="ink-ruler"></div>

<div id="upload-section">
  <div class="dropzone" id="dropzone">
    <svg class="dropzone-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
      <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
    </svg>
    <div class="dropzone-title">Drop .ppf file here</div>
    <div class="dropzone-sub">or click to browse · max 500 MB · supports RunLength/ASCII85/Hex/Binary</div>
    <input type="file" id="file-input" accept=".ppf,.PPF" style="display:none">
  </div>
  <div class="controls">
    <div class="control">
      <label>ZONE_WIDTH</label>
      <select id="zone-width">
        <option value="32.5">32.5 mm (SM102 / CD102 / XL75 / XL105)</option>
        <option value="30">30.0 mm (SM52 / SM74 / CD74)</option>
        <option value="35">35.0 mm (custom)</option>
        <option value="40">40.0 mm (custom)</option>
      </select>
    </div>
    <button id="demo-btn">USE_DEMO_DATA</button>
  </div>
</div>

<div id="loading" class="hidden loading"><div class="spinner"></div>ANALYZING PPF...</div>
<div id="error" class="hidden error"></div>

<div id="results" class="hidden">
  <div class="job-info">
    <div class="job-info-cell"><div class="job-info-label">JOB</div>
      <div class="job-info-value" id="job-name">—</div></div>
    <div class="job-info-cell"><div class="job-info-label">SHEET</div>
      <div class="job-info-value" id="sheet-size">—</div></div>
    <div class="job-info-cell"><div class="job-info-label">INK ZONES</div>
      <div class="job-info-value" id="zone-count">—</div></div>
    <div class="job-info-cell"><div class="job-info-label">SEPARATIONS</div>
      <div class="job-info-value" id="sep-count">—</div></div>
  </div>
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">INK COVERAGE PER ZONE</div>
      <div class="legend" id="legend"></div>
    </div>
    <div class="chart-wrap"><canvas id="chart"></canvas></div>
  </div>
  <div class="panel">
    <div class="panel-header"><div class="panel-title">ZONE SUMMARY</div></div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>SEPARATION</th><th style="text-align:right">AVG %</th>
          <th style="text-align:right">PEAK %</th><th style="text-align:right">PEAK ZONE</th>
          <th style="text-align:right">MIN %</th><th style="text-align:right">BALANCE</th>
        </tr></thead>
        <tbody id="summary-tbody"></tbody>
      </table>
    </div>
  </div>
  <div class="actions-bar">
    <button class="primary" id="csv-btn">⬇ EXPORT_CSV</button>
    <button id="json-btn">⬇ EXPORT_JSON</button>
    <div class="spacer"></div>
    <button id="reset-btn">↻ NEW_FILE</button>
  </div>
</div>

<footer>PPF.INK_ANALYZER · v1.1 · S.SILPA INTERNAL TOOL</footer>
</div>

<script>
const sepColors = {
  'Cyan':'#00aeef','cyan':'#00aeef','Magenta':'#ec008c','magenta':'#ec008c',
  'Yellow':'#ffe600','yellow':'#ffe600','Black':'#9098ad','black':'#9098ad','K':'#9098ad',
  'Orange':'#f97316','orange':'#f97316','Green':'#16a34a','green':'#16a34a',
  'Violet':'#8b5cf6','violet':'#8b5cf6','Blue':'#2563eb',
  'PANTONE 186 C':'#c8102e','PANTONE186C':'#c8102e',
};
function getColor(name){
  if(sepColors[name])return sepColors[name];
  if(/186/.test(name))return '#c8102e';
  if(/orange/i.test(name))return '#f97316';
  if(/green/i.test(name))return '#16a34a';
  if(/violet|purple/i.test(name))return '#8b5cf6';
  if(/blue/i.test(name))return '#2563eb';
  if(/red/i.test(name))return '#dc2626';
  if(/silver|pearl|silk|foil|matt/i.test(name))return '#a8a29e';
  const fb=['#a855f7','#f59e0b','#06b6d4','#84cc16','#f43f5e'];
  let h=0;for(let i=0;i<name.length;i++)h=(h*31+name.charCodeAt(i))|0;
  return fb[Math.abs(h)%fb.length];
}
const $=id=>document.getElementById(id);
const dropzone=$('dropzone'),fileInput=$('file-input'),statusPill=$('status-pill');
let currentData=null,chart=null,disabledSeps=new Set();

function setStatus(t,type='ok'){
  statusPill.textContent=t;
  const c={ok:['rgba(52,211,153,.1)','rgba(52,211,153,.2)','var(--ok)'],
    busy:['rgba(251,191,36,.1)','rgba(251,191,36,.2)','var(--warn)'],
    error:['rgba(239,68,68,.1)','rgba(239,68,68,.2)','var(--err)']}[type];
  statusPill.style.background=c[0];statusPill.style.borderColor=c[1];statusPill.style.color=c[2];
}
dropzone.addEventListener('click',()=>fileInput.click());
['dragover','dragenter'].forEach(ev=>dropzone.addEventListener(ev,e=>{
  e.preventDefault();dropzone.classList.add('dragover')}));
['dragleave','dragend'].forEach(ev=>dropzone.addEventListener(ev,()=>
  dropzone.classList.remove('dragover')));
dropzone.addEventListener('drop',e=>{
  e.preventDefault();dropzone.classList.remove('dragover');
  if(e.dataTransfer.files.length){fileInput.files=e.dataTransfer.files;upload()}});
fileInput.addEventListener('change',()=>upload());
$('demo-btn').addEventListener('click',()=>upload(true));

async function upload(demo=false){
  showLoading();
  const fd=new FormData();
  fd.append('zone_width',$('zone-width').value);
  if(demo)fd.append('demo','1');
  else{if(!fileInput.files.length){hideLoading();
    $('upload-section').classList.remove('hidden');return}
    fd.append('file',fileInput.files[0])}
  try{
    const res=await fetch('/api/analyze',{method:'POST',body:fd});
    const json=await res.json();
    if(json.error){showError(json.error);return}
    currentData=json;render(json);
  }catch(e){showError(e.message)}
}
function showLoading(){
  $('upload-section').classList.add('hidden');$('error').classList.add('hidden');
  $('results').classList.add('hidden');$('loading').classList.remove('hidden');
  setStatus('PROCESSING','busy');
}
function hideLoading(){$('loading').classList.add('hidden')}
function showError(msg){
  hideLoading();$('results').classList.add('hidden');
  $('upload-section').classList.remove('hidden');
  $('error').classList.remove('hidden');$('error').textContent='ERROR: '+msg;
  setStatus('ERROR','error');
}
window.addEventListener('load',()=>{
  if(typeof Chart==='undefined'){
    showError('Chart.js failed to load — check internet connection.');
  }
});
function render(data){
  hideLoading();$('upload-section').classList.add('hidden');
  $('results').classList.remove('hidden');
  $('job-name').textContent=data.job_name;
  $('sheet-size').innerHTML=`${Math.round(data.sheet_width_mm)} × ${Math.round(data.sheet_height_mm)}<span class="job-info-unit">mm</span>`;
  $('zone-count').textContent=data.n_zones;
  $('sep-count').textContent=Object.keys(data.histograms).length;
  disabledSeps.clear();renderLegend(data);renderChart(data);renderTable(data);
  setStatus('OK','ok');
}
function renderLegend(data){
  const legend=$('legend');legend.innerHTML='';
  for(const name of Object.keys(data.histograms)){
    const item=document.createElement('div');
    item.className='legend-item';
    item.innerHTML=`<span class="legend-swatch" style="background:${getColor(name)}"></span>${name}`;
    item.addEventListener('click',()=>{
      if(disabledSeps.has(name)){disabledSeps.delete(name);item.classList.remove('disabled')}
      else{disabledSeps.add(name);item.classList.add('disabled')}
      renderChart(currentData)});
    legend.appendChild(item);
  }
}
function renderChart(data){
  const ctx=$('chart').getContext('2d');
  if(chart)chart.destroy();
  const zones=Array.from({length:data.n_zones},(_,i)=>i+1);
  const datasets=Object.entries(data.histograms)
    .filter(([n])=>!disabledSeps.has(n))
    .map(([name,hist])=>({label:name,data:hist,
      backgroundColor:getColor(name),borderWidth:0,borderRadius:2,
      categoryPercentage:.85,barPercentage:.92}));
  chart=new Chart(ctx,{type:'bar',data:{labels:zones,datasets},
    options:{responsive:true,maintainAspectRatio:false,
      animation:{duration:400},
      plugins:{legend:{display:false},
        tooltip:{backgroundColor:'#0a0e17',borderColor:'#313a55',borderWidth:1,
          padding:12,cornerRadius:4,
          titleFont:{family:'Geist Mono',size:11,weight:600},
          bodyFont:{family:'Geist Mono',size:11},
          callbacks:{title:i=>`ZONE ${String(i[0].label).padStart(2,'0')}`,
            label:c=>`  ${c.dataset.label.padEnd(18)} ${c.parsed.y.toFixed(2)}%`}}},
      scales:{
        x:{grid:{color:'#1a2236',drawTicks:false},
          ticks:{color:'#8a92a8',font:{family:'Geist Mono',size:10}},
          title:{display:true,
            text:'INK KEY ZONE → ZONE WIDTH = '+data.zone_width_mm+' mm',
            color:'#565d75',font:{family:'Geist Mono',size:10,weight:600},
            padding:{top:12}}},
        y:{beginAtZero:true,grid:{color:'#1a2236',drawTicks:false},
          ticks:{color:'#8a92a8',font:{family:'Geist Mono',size:10},callback:v=>v+'%'},
          title:{display:true,text:'COVERAGE %',color:'#565d75',
            font:{family:'Geist Mono',size:10,weight:600},padding:{bottom:12}}}}}});
}
function renderTable(data){
  const tbody=$('summary-tbody');tbody.innerHTML='';
  for(const[sep,info]of Object.entries(data.summary)){
    const hist=data.histograms[sep];
    const mean=info.avg;
    const variance=hist.reduce((s,v)=>s+Math.pow(v-mean,2),0)/hist.length;
    const cv=mean>0.01?Math.sqrt(variance)/mean*100:0;
    const bt=mean<0.01?'—':`±${cv.toFixed(0)}%`;
    const bc=mean<0.01?'var(--text-faint)':cv>150?'var(--err)':cv>80?'var(--warn)':'var(--ok)';
    const minV=Math.min(...hist);
    const tr=document.createElement('tr');
    tr.innerHTML=`
      <td><span class="sep-swatch" style="background:${getColor(sep)}"></span>${sep}</td>
      <td style="text-align:right" class="text-num">${info.avg.toFixed(2)}</td>
      <td style="text-align:right" class="text-num">${info.peak.toFixed(2)}</td>
      <td style="text-align:right" class="text-num">#${String(info.peak_zone).padStart(2,'0')}</td>
      <td style="text-align:right" class="text-num">${minV.toFixed(2)}</td>
      <td style="text-align:right;color:${bc}" class="text-num">${bt}</td>`;
    tbody.appendChild(tr);
  }
}
$('csv-btn').addEventListener('click',async()=>{
  if(!currentData)return;
  const res=await fetch('/api/csv',{method:'POST',
    headers:{'Content-Type':'application/json'},body:JSON.stringify(currentData)});
  downloadBlob(await res.blob(),`zones_${safeName(currentData.job_name)}.csv`);
});
$('json-btn').addEventListener('click',()=>{
  if(!currentData)return;
  downloadBlob(new Blob([JSON.stringify(currentData,null,2)],
    {type:'application/json'}),`zones_${safeName(currentData.job_name)}.json`);
});
$('reset-btn').addEventListener('click',()=>{
  currentData=null;disabledSeps.clear();
  $('results').classList.add('hidden');$('upload-section').classList.remove('hidden');
  fileInput.value='';setStatus('READY','ok');
});
function downloadBlob(blob,fn){
  const url=URL.createObjectURL(blob);const a=document.createElement('a');
  a.href=url;a.download=fn;document.body.appendChild(a);a.click();a.remove();
  URL.revokeObjectURL(url);
}
function safeName(s){return(s||'job').replace(/[^a-zA-Z0-9_.-]/g,'_')}
</script>
</body>
</html>
"""


# ════════════════════════════════════════════════════════════════════
# Flask routes
# ════════════════════════════════════════════════════════════════════

@app.route('/')
def index():
    return render_template_string(HTML)


@app.route('/api/analyze', methods=['POST'])
def api_analyze():
    try:
        zone_w = float(request.form.get('zone_width', 32.5))
    except (TypeError, ValueError):
        return jsonify({'error': 'Invalid zone_width'}), 400

    if request.form.get('demo'):
        data = simulate_demo()
    elif 'file' in request.files:
        f = request.files['file']
        if not f or not f.filename:
            return jsonify({'error': 'No file provided'}), 400
        content = f.read()
        if len(content) < 100:
            return jsonify({'error': 'File too small to be valid PPF'}), 400
        data = parse_ppf(content)
        if data['job_name'] in ('Untitled', ''):
            data['job_name'] = Path(f.filename).stem
    else:
        return jsonify({'error': 'No file or demo flag'}), 400

    if not data['separations']:
        return jsonify({'error': (
            'No separations decoded from PPF. Possible causes: unsupported '
            'compression/encoding, or corrupted file. Open the .ppf in a text '
            'editor and check %CIP3PreviewImageEncoding / Compression lines, '
            'then report them for parser adjustment.')}), 400

    histograms = compute_zone_histograms(
        data['separations'], data['sheet_width_mm'], zone_w)

    summary = {}
    for name, hist in histograms.items():
        peak = max(hist) if hist else 0
        summary[name] = {
            'avg': sum(hist)/len(hist) if hist else 0,
            'peak': peak,
            'peak_zone': hist.index(peak)+1 if hist else 0,
            'total': sum(hist),
        }

    return jsonify({
        'job_name': data['job_name'],
        'sheet_width_mm': round(data['sheet_width_mm'], 1),
        'sheet_height_mm': round(data['sheet_height_mm'], 1),
        'zone_width_mm': zone_w,
        'n_zones': len(next(iter(histograms.values()))) if histograms else 0,
        'histograms': histograms,
        'summary': summary,
    })


@app.route('/api/csv', methods=['POST'])
def api_csv():
    data = request.json or {}
    output = io.StringIO()
    writer = csv.writer(output)
    writer.writerow(['job_name', 'separation', 'zone_no', 'coverage_pct',
                     'sheet_width_mm', 'zone_width_mm'])
    job = data.get('job_name', 'Untitled')
    sw = data.get('sheet_width_mm', '')
    zw = data.get('zone_width_mm', '')
    for sep, hist in (data.get('histograms') or {}).items():
        for i, v in enumerate(hist, 1):
            writer.writerow([job, sep, i, round(v, 3), sw, zw])
    return Response(output.getvalue(), mimetype='text/csv',
                    headers={'Content-Disposition': 'attachment; filename=zones.csv'})


@app.route('/health')
def health():
    return jsonify({'status': 'ok', 'service': 'ppf-ink-analyzer', 'version': '1.1'})


if __name__ == '__main__':
    print()
    print('╔════════════════════════════════════════════════════════╗')
    print('║  PPF.INK_ANALYZER v1.1 · S.SILPA Internal Tool         ║')
    print('║  + RunLength (PackBits) decoder for ArtPro+ PPF        ║')
    print('║                                                        ║')
    print('║  ► Open browser: http://localhost:5000                 ║')
    print('║  ► Drop a .ppf file or use demo data to start          ║')
    print('║  ► Ctrl+C to stop                                      ║')
    print('╚════════════════════════════════════════════════════════╝')
    print()
    app.run(host='0.0.0.0', port=5000, debug=False)
