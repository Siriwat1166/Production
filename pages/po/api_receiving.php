<?php
// api_receiving.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

header('Content-Type: application/json; charset=UTF-8');

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin','editor']);

/* ====== ตั้งค่าคงที่ "ตาราง" ======
   ปรับให้ตรงกับฐานข้อมูลจริงของคุณ (สคีมา/ชื่อตาราง) */
const T_PO_HEADER = 'Production.dbo.PO_Header';
const T_PO_ITEMS  = 'Production.dbo.PO_Items';
const T_MASTER    = 'Production.dbo.Master_Products_ID';
const T_PROD_UOM  = 'Production.dbo.PRODUCT_UOM_CONVERSIONS';
const T_DYN_UOM   = 'Production.dbo.Dynamic_Unit_Conversions';

/* ====== ตั้งค่าคงที่ "คอลัมน์" ของแต่ละตาราง ====== */
/* PO Header */
const COL_PO_ID        = 'PO_ID';
const COL_PO_NUMBER    = 'po_number';
const COL_SUPPLIER_ID  = 'Supplier_ID';
const COL_PO_DATE      = 'PO_Date';

/* PO Items */
const COL_ITEMS_PO_ID_FK = 'PO_ID';
const COL_PRODUCT_ID      = 'Product_ID';
const COL_QTY             = 'Order_Qty';
const COL_UNIT_ID         = 'Unit_ID';
const COL_PRICE           = 'Unit_Price';

/* Master Products */
const COL_MASTER_ID       = 'ID';
const COL_MASTER_CODE     = 'SSP_Code';
const COL_MASTER_NAME     = 'Name';
const COL_MASTER_UNIT_ID  = 'Unit_id';

try {
  $pdo = new PDO(
    "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME,
    DB_USERNAME, DB_PASSWORD,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]
  );

  $action = $_POST['action'] ?? $_GET['action'] ?? '';

  /* -------- 1) PO + Items -------- */
  if ($action === 'get_po_data_with_conversions') {
    $poNumber = trim($_POST['po_number'] ?? '');
    if ($poNumber === '') {
      echo json_encode(['success'=>false,'message'=>'PO number required']); exit;
    }

    // Header
    $sqlHdr = "SELECT TOP 1 "
            . COL_PO_ID       . " AS po_id, "
            . COL_PO_NUMBER   . " AS po_number, "
            . COL_SUPPLIER_ID . " AS supplier_id, "
            . COL_PO_DATE     . " AS created_date
            FROM " . T_PO_HEADER . "
            WHERE " . COL_PO_NUMBER . " = :p";

    $stmt = $pdo->prepare($sqlHdr);
    $stmt->execute(['p'=>$poNumber]);
    $po = $stmt->fetch();

    if (!$po) { echo json_encode(['success'=>false, 'message'=>'ไม่พบเลข PO นี้']); exit; }

    // Items join master
    $sqlItems = "SELECT 
                   poi." . COL_ITEMS_PO_ID_FK . "       AS po_id,
                   poi." . COL_PRODUCT_ID      . "       AS product_id,
                   poi." . COL_QTY             . "       AS qty,
                   poi." . COL_UNIT_ID         . "       AS unit_id,
                   poi." . COL_PRICE           . "       AS price,
                   m."   . COL_MASTER_CODE     . "       AS SSP_Code,
                   m."   . COL_MASTER_NAME     . "       AS Name,
                   m."   . COL_MASTER_UNIT_ID  . "       AS default_unit_id
                 FROM " . T_PO_ITEMS . " poi
                 JOIN " . T_MASTER  . " m
                   ON m." . COL_MASTER_ID . " = poi." . COL_PRODUCT_ID . "
                 WHERE poi." . COL_ITEMS_PO_ID_FK . " = :pid";

    $stmt2 = $pdo->prepare($sqlItems);
    $stmt2->execute(['pid'=>$po['po_id']]);
    $items = $stmt2->fetchAll();

    echo json_encode(['success'=>true, 'po_data'=>['po'=>$po, 'items'=>$items]]); exit;
  }

  /* -------- 2) แปลงหน่วย -------- */
  if ($action === 'calculate_conversion') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $fromUnit  = (int)($_POST['from_unit_id'] ?? 0);
    $toUnit    = (int)($_POST['to_unit_id'] ?? 0);
    $qty       = (float)($_POST['qty'] ?? 0);
    if (!$productId || !$fromUnit || !$toUnit || !$qty) {
      echo json_encode(['success'=>false, 'message'=>'Invalid params']); exit;
    }

    // PRODUCT_UOM_CONVERSIONS
    $stmt = $pdo->prepare("SELECT TOP 1 conversion_factor 
                           FROM " . T_PROD_UOM . "
                           WHERE product_id=:pid AND from_unit_id=:fu AND to_unit_id=:tu");
    $stmt->execute(['pid'=>$productId,'fu'=>$fromUnit,'tu'=>$toUnit]);
    $row = $stmt->fetch();
    if ($row) {
      echo json_encode(['success'=>true,'converted_qty'=>$qty*(float)$row['conversion_factor']]); exit;
    }

    // Dynamic_Unit_Conversions
    $stmt = $pdo->prepare("SELECT TOP 1 factor 
                           FROM " . T_DYN_UOM . "
                           WHERE product_id=:pid AND from_unit_id=:fu AND to_unit_id=:tu
                           ORDER BY created_date DESC");
    $stmt->execute(['pid'=>$productId,'fu'=>$fromUnit,'tu'=>$toUnit]);
    $row = $stmt->fetch();
    if ($row) {
      echo json_encode(['success'=>true,'converted_qty'=>$qty*(float)$row['factor']]); exit;
    }

    echo json_encode(['success'=>false, 'message'=>'ไม่พบอัตราแปลงหน่วยของสินค้านี้']); exit;
  }

  /* -------- 3) ค้นหาสินค้าด้วย SSP Code -------- */
  if ($action === 'search_product_by_code') {
    $code = trim($_GET['code'] ?? $_POST['code'] ?? '');
    if ($code === '') { echo json_encode(['found'=>false]); exit; }

    $sql = "SELECT TOP 1
              " . COL_MASTER_ID      . " AS product_id,
              " . COL_MASTER_NAME    . " AS product_name,
              " . COL_MASTER_UNIT_ID . " AS default_unit_id,
              " . COL_MASTER_CODE    . " AS product_code,
              supplier_id
            FROM " . T_MASTER . "
            WHERE is_active=1 AND " . COL_MASTER_CODE . " = :code
            ORDER BY " . COL_MASTER_ID . " DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['code'=>$code]);
    $row = $stmt->fetch();

    if (!$row) { echo json_encode(['found'=>false]); exit; }

    $supplierName = null;
    if (!empty($row['supplier_id'])) {
      $st = $pdo->prepare("SELECT TOP 1 supplier_name FROM Production.dbo.Suppliers WHERE id=:sid");
      $st->execute(['sid'=>$row['supplier_id']]);
      $s = $st->fetch();
      $supplierName = $s['supplier_name'] ?? null;
    }
    echo json_encode(['found'=>true,'data'=>$row,'supplier_name'=>$supplierName]); exit;
  }

  echo json_encode(['success'=>false,'message'=>'unknown action']); exit;

} catch (Exception $e) {
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
