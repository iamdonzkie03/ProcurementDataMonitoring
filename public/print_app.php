<?php
require_once __DIR__.'/../config/config.php';
requireLogin();

$pdo = db();
$currentYear = (int)date('Y');
$availableYears = array_map('intval', $pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
if (!in_array($currentYear, $availableYears, true)) $availableYears[] = $currentYear;
rsort($availableYears);
$year = (int)($_GET['year'] ?? $currentYear);
if (!in_array($year, $availableYears, true)) $year = $currentYear;
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT src.fiscal_year, src.item_key, MIN(src.item_name) item_name, src.unit, src.category_id, src.category, src.unit_price, SUM(src.area_qty) total_qty, SUM(src.area_abc) total_abc, GROUP_CONCAT(CONCAT(src.area_name, " (", FORMAT(src.area_qty, 2), ")") ORDER BY src.area_name SEPARATOR ", ") areas
        FROM (
          SELECT p.fiscal_year, TRIM(LOWER(p.item_name)) item_key, MIN(p.item_name) item_name, p.unit, c.id category_id, c.name category, p.unit_price, p.area_id, a.name area_name, SUM(p.quantity) area_qty, SUM(p.quantity*p.unit_price) area_abc
          FROM ppmp_items p
          JOIN areas a ON a.id=p.area_id
          JOIN categories c ON c.id=p.category_id
          WHERE p.fiscal_year=?';
$args = [$year];
if ($q !== '') {
    $sql .= ' AND (p.item_name LIKE ? OR c.name LIKE ? OR a.name LIKE ?)';
    $args[] = "%{$q}%";
    $args[] = "%{$q}%";
    $args[] = "%{$q}%";
}
$sql .= ' GROUP BY p.fiscal_year, TRIM(LOWER(p.item_name)), p.unit, c.id, c.name, p.unit_price, p.area_id, a.name
        ) src
        GROUP BY src.fiscal_year, src.item_key, src.unit, src.category_id, src.category, src.unit_price
        ORDER BY item_name, src.unit_price';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$grandQty = 0.0;
$grandAbc = 0.0;
foreach ($rows as $row) {
    $grandQty += (float)$row['total_qty'];
    $grandAbc += (float)$row['total_abc'];
}
$agencyName = defined('APP_AGENCY_NAME') ? APP_AGENCY_NAME : '';
function appPrintEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Annual Procurement Plan FY <?=appPrintEscape($year)?></title>
<style>
@page{size:A4 landscape;margin:12mm}
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;color:#111827;margin:0;font-size:10pt}
.toolbar{display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin:0 0 16px}
.btn{display:inline-block;border:1px solid #64748b;border-radius:4px;padding:8px 12px;background:#fff;color:#111827;text-decoration:none;font-size:10pt;cursor:pointer}
.heading{text-align:center;margin:0 0 14px}
.heading h1{font-size:16pt;letter-spacing:.3px;margin:0 0 4px}
.heading h2{font-size:12pt;margin:0 0 4px;font-weight:600}
.heading p{margin:3px 0}
.meta{display:flex;justify-content:space-between;gap:16px;margin:12px 0 8px;font-size:9pt}
table{border-collapse:collapse;width:100%;table-layout:fixed}
th,td{border:1px solid #374151;padding:5px 6px;vertical-align:top;overflow-wrap:anywhere}
th{font-weight:700;text-align:center;background:#f3f4f6}
td.num{text-align:right;white-space:nowrap}
.total-row td{font-weight:700;background:#f9fafb}
.note{font-size:8pt;margin-top:8px;color:#374151}
.signatures{display:flex;justify-content:space-between;gap:30px;margin-top:30px;page-break-inside:avoid}
.signature{width:32%;text-align:center;font-size:9pt}
.signature .line{border-top:1px solid #111827;margin:28px 0 5px}
@media print{.toolbar{display:none}.heading{margin-top:0}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
@media screen{body{padding:22px;background:#f1f5f9}.sheet{background:white;padding:22px;max-width:1400px;margin:auto;box-shadow:0 2px 10px #00000012}}
</style>
</head>
<body>
<div class="toolbar">
  <a class="btn" href="../APP%20Format.xlsx" download>Download APP Format.xlsx</a>
  <button class="btn" type="button" onclick="window.print()">Print APP</button>
  <button class="btn" type="button" onclick="window.close()">Close</button>
</div>
<div class="sheet">
  <header class="heading">
    <?php if ($agencyName !== ''): ?><h2><?=appPrintEscape($agencyName)?></h2><?php endif; ?>
    <h1>ANNUAL PROCUREMENT PLAN (APP)</h1>
    <h2>FISCAL YEAR <?=appPrintEscape($year)?></h2>
  </header>
  <div class="meta">
    <span><strong>Consolidated Procurement Plan</strong></span>
    <span><strong>Date Printed:</strong> <?=date('F d, Y')?></span>
  </div>
  <table>
    <colgroup>
      <col style="width:23%"><col style="width:11%"><col style="width:7%"><col style="width:9%"><col style="width:11%"><col style="width:12%"><col style="width:27%">
    </colgroup>
    <thead><tr>
      <th>Procurement Item / Description</th>
      <th>Category</th>
      <th>Unit</th>
      <th>Consolidated Quantity</th>
      <th>Unit Price (₱)</th>
      <th>Total ABC (₱)</th>
      <th>Contributing Areas / Units</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?=appPrintEscape($row['item_name'])?></td>
        <td><?=appPrintEscape($row['category'])?></td>
        <td><?=appPrintEscape($row['unit'])?></td>
        <td class="num"><?=number_format((float)$row['total_qty'], 2)?></td>
        <td class="num"><?=number_format((float)$row['unit_price'], 2)?></td>
        <td class="num"><?=number_format((float)$row['total_abc'], 2)?></td>
        <td><?=appPrintEscape($row['areas'])?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center">No consolidated PPMP data found for Fiscal Year <?=appPrintEscape($year)?>.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr class="total-row">
      <td colspan="3">GRAND TOTAL</td>
      <td class="num"><?=number_format($grandQty, 2)?></td>
      <td></td>
      <td class="num"><?=number_format($grandAbc, 2)?></td>
      <td></td>
    </tr></tfoot>
  </table>
  <p class="note">Note: Equivalent items are consolidated using item name, unit, category and unit price. Items with different unit prices remain separate. The contributing areas/units column indicates each area's/unit's requested quantity.</p>
  <div class="signatures">
    <div class="signature"><div class="line"></div><strong>Prepared by</strong><br>Name / Position</div>
    <div class="signature"><div class="line"></div><strong>Reviewed by</strong><br>Name / Position</div>
    <div class="signature"><div class="line"></div><strong>Approved by</strong><br>Name / Position</div>
  </div>
</div>
</body>
</html>
