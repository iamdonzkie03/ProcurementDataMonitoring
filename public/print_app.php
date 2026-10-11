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

/*
 * The print layout follows the headings and section structure in
 * APP Format.xlsx (ZCMC-F-PROC-02, adopted from the GPPB standard form).
 * Consolidated PPMP quantities are retained in the project description because
 * the prescribed APP template does not have a separate quantity/unit column.
 */
$sql = 'SELECT
          src.fiscal_year, src.item_key, MIN(src.item_name) item_name,
          src.unit, src.category_id, src.category, src.unit_price,
          SUM(src.area_qty) total_qty, SUM(src.area_abc) total_abc,
          GROUP_CONCAT(CONCAT(src.area_name, " (", FORMAT(src.area_qty, 2), " ", src.unit, ")") ORDER BY src.area_name SEPARATOR "; ") areas,
          MIN(src.description) description,
          MIN(src.procurement_mode) procurement_mode,
          MIN(src.epa) epa,
          MIN(src.start_procurement) start_procurement,
          MIN(src.end_procurement) end_procurement,
          MIN(src.source_of_funds) source_of_funds,
          MIN(src.remarks) remarks,
          MIN(src.procurement_type) procurement_type
        FROM (
          SELECT p.fiscal_year, TRIM(LOWER(p.item_name)) item_key,
            MIN(p.item_name) item_name, p.unit, c.id category_id, c.name category,
            p.unit_price, p.area_id, a.name area_name,
            SUM(p.quantity) area_qty, SUM(p.quantity*p.unit_price) area_abc,
            MIN(p.description) description, MIN(p.procurement_mode) procurement_mode,
            MIN(p.preprocurement_conference) epa, MIN(p.start_procurement) start_procurement,
            MIN(p.end_procurement) end_procurement, MIN(p.source_of_funds) source_of_funds,
            MIN(p.remarks) remarks, MIN(p.procurement_type) procurement_type
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

$sections = [
    'general' => ['title' => 'General Requirements', 'rows' => []],
    'misc' => ['title' => 'Miscellaneous Items (for Direct Acquisition only) Sec. 32.2 of RA No. 12009', 'rows' => []],
    'cse' => ['title' => 'Common-Use Supplies and Equipment (CSE) to be purchased from PS-DBM (kindly indicate the summary/total amounts only)', 'rows' => []],
];
$grandAbc = 0.0;
$epaTotal = 0.0;
$cseTotal = 0.0;
foreach ($rows as $row) {
    $grandAbc += (float)$row['total_abc'];
    if (strcasecmp(trim((string)$row['epa']), 'Yes') === 0) $epaTotal += (float)$row['total_abc'];
    $procurementModeKey = strtolower(trim((string)($row['procurement_mode'] ?? '')));
    $categoryKey = strtolower(trim((string)$row['category'].' '.(string)$row['procurement_type']));

    // Direct Acquisition in the Mode of Procurement always takes priority:
    // route the record to Miscellaneous Items, even if its category also matches CSE.
    if (strpos($procurementModeKey, 'direct acquisition') !== false) {
        $sections['misc']['rows'][] = $row;
    } elseif (strpos($categoryKey, 'miscellaneous') !== false || strpos($categoryKey, 'direct acquisition') !== false) {
        $sections['misc']['rows'][] = $row;
    } elseif (strpos($categoryKey, 'cse') !== false || strpos($categoryKey, 'common-use') !== false || strpos($categoryKey, 'common use') !== false) {
        $sections['cse']['rows'][] = $row;
        $cseTotal += (float)$row['total_abc'];
    } else {
        $sections['general']['rows'][] = $row;
    }
}
$nonEpaTotal = max(0, $grandAbc - $epaTotal);
$agencyName = defined('APP_AGENCY_NAME') ? APP_AGENCY_NAME : 'ZAMBOANGA CITY MEDICAL CENTER';
function appPrintEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function appPrintDate($value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return '';
    $time = strtotime($value);
    return $time ? date('m/Y', $time) : $value;
}
function appPrintProjectDescription(array $row): string {
    $parts = [];
    if (trim((string)($row['description'] ?? '')) !== '') $parts[] = trim((string)$row['description']);
    $parts[] = 'Consolidated quantity: '.number_format((float)$row['total_qty'], 2).' '.trim((string)$row['unit']);
    return implode("\n", $parts);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ANNUAL PROCUREMENT PLAN FOR FY <?=appPrintEscape($year)?></title>
<style>
@page{size:A3 landscape;margin:8mm 6mm 8mm}
*{box-sizing:border-box}
body{font-family:"Century Gothic",Arial,sans-serif;color:#111;margin:0;font-size:7pt;background:#fff}
.toolbar{display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin:0 0 14px}
.btn{display:inline-block;border:1px solid #475569;border-radius:3px;padding:7px 11px;background:#fff;color:#111;text-decoration:none;font-size:10pt;cursor:pointer}
.sheet{width:100%;margin:0 auto}
.form-meta{font-size:7pt;text-align:right;line-height:1.25;margin:0 0 5px;white-space:pre-line}
.heading{text-align:center;margin:0 0 8px}
.heading h1{font-family:"Century Gothic",Arial,sans-serif;font-size:15pt;font-weight:700;margin:0;letter-spacing:.1px}
.heading .agency{font-size:9pt;font-weight:700;margin-bottom:4px}
.heading .sub{font-size:8pt;margin-top:4px}
table{border-collapse:collapse;width:100%;table-layout:fixed;border:1.3px solid #222}
th,td{border:1px solid #222;padding:3px 3px;vertical-align:top;overflow-wrap:anywhere;white-space:pre-line}
.group-head th{background:#d9e2f3;color:#111;border:1px solid #222;font-weight:700}
.column-head th{background:#eaf0f8;color:#111;border:1px solid #222}
.column-number th{background:#f2f2f2;color:#111;border:1px solid #777}
.group-head th{font-size:8pt;text-align:center;vertical-align:middle;height:26px}
.column-head th{font-size:7pt;text-align:center;vertical-align:middle;height:88px;font-weight:700}
.column-number th{font-size:6pt;text-align:center;height:17px;font-weight:400}
td.number{text-align:center;vertical-align:middle}
td.amount{text-align:right;white-space:nowrap}
.section-row td{font-weight:700;background:#d9e2f3;padding:5px 4px;vertical-align:middle;border-top:1.4px solid #222;border-bottom:1.4px solid #222}
.section-row td:first-child{text-align:left}
.data-row td{background:#fff}
.data-row td{height:35px}
.summary-label{font-weight:700;background:#eaf0f8}
.summary-row td{height:24px;vertical-align:middle}
.summary-row .amount{font-weight:700;background:#f2f2f2}
.note{font-size:7pt;font-style:italic;margin:7px 0 0}
.signatures{display:flex;justify-content:space-between;gap:24px;margin-top:25px;page-break-inside:avoid}
.signature{width:32%;text-align:center;font-size:8pt}
.signature .line{border-top:1px solid #111;margin:24px 0 5px}
@media print{.toolbar{display:none}.sheet{width:100%}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
@media screen{body{padding:18px;background:#f1f5f9}.sheet{background:#fff;padding:18px;max-width:1800px;box-shadow:0 2px 10px #00000012}}
</style>
</head>
<body>
<div class="toolbar">
  <a class="btn" href="../APP%20Format.xlsx" download>Download APP Format.xlsx</a>
  <button class="btn" type="button" onclick="window.print()">Print APP</button>
  <button class="btn" type="button" onclick="window.close()">Close</button>
</div>
<div class="sheet">
  <div class="form-meta">Form No: ZCMC-F-PROC-02
Revision No: 0
Effectivity Date: January 12, 2026
Adopted from GPPB Standard Form</div>
  <header class="heading">
    <div class="agency"><?=appPrintEscape($agencyName)?></div>
    <h1>ANNUAL PROCUREMENT PLAN FOR FY <?=appPrintEscape($year)?></h1>
    <div class="sub">Consolidated from PPMP submissions · Date printed: <?=date('F d, Y')?></div>
  </header>
  <table>
    <colgroup>
      <col style="width:3%"><col style="width:10%"><col style="width:8%"><col style="width:5%"><col style="width:11%"><col style="width:7%"><col style="width:5%"><col style="width:10%"><col style="width:5%"><col style="width:5%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:11%">
    </colgroup>
    <thead>
      <tr class="group-head">
        <th rowspan="2" style="vertical-align:middle">#</th>
        <th colspan="7">PROCUREMENT PROJECT DETAILS</th>
        <th colspan="2">PROJECTED TIMELINE (MM/YYYY)</th>
        <th colspan="2">FUNDING DETAILS</th>
        <th>PROCUREMENT STRATEGY OR TOOLS</th>
        <th rowspan="2" style="vertical-align:middle">REMARKS<br>(Other relevant description of the procurement project, if applicable)</th>
      </tr>
      <tr class="column-head">
        <th>Project Title</th>
        <th>End-User or Implementing Unit</th>
        <th>Category</th>
        <th>General Description of the Project</th>
        <th>Mode of Procurement</th>
        <th>To be covered by an Early Procurement Activity? (Yes/No)</th>
        <th>Criteria for Bid Evaluation (including Sustainability and Domestic Preference)</th>
        <th>Start of Procurement Activity</th>
        <th>End of Procurement Activity</th>
        <th>Source of Fund</th>
        <th>Estimated Budget / Approved Budget for the Contract (Php)</th>
        <th>Column 11</th>
      </tr>
      <tr class="column-number">
        <th></th><th>Column 1</th><th>Column 2</th><th></th><th>Column 3</th><th>Column 4</th><th>Column 5</th><th>Column 6</th><th>Column 7</th><th>Column 8</th><th>Column 9</th><th>Column 10</th><th>Column 11</th><th>Column 12</th>
      </tr>
    </thead>
    <tbody>
    <?php $counter = 1; foreach ($sections as $sectionKey => $section): ?>
      <tr class="section-row"><td><?=appPrintEscape($section['title'])?></td><?php for($i=0;$i<13;$i++): ?><td></td><?php endfor; ?></tr>
      <?php if ($section['rows']): foreach ($section['rows'] as $row): ?>
        <tr class="data-row">
          <td class="number"><?=$counter++?></td>
          <td><?=appPrintEscape($row['item_name'])?></td>
          <td><?=appPrintEscape($row['areas'])?></td>
          <td><?=appPrintEscape($row['category'])?></td>
          <td><?=appPrintEscape(appPrintProjectDescription($row))?></td>
          <td><?=appPrintEscape($row['procurement_mode'])?></td>
          <td class="number"><?=appPrintEscape($row['epa'])?></td>
          <td></td>
          <td class="number"><?=appPrintEscape(appPrintDate($row['start_procurement']))?></td>
          <td class="number"><?=appPrintEscape(appPrintDate($row['end_procurement']))?></td>
          <td><?=appPrintEscape($row['source_of_funds'])?></td>
          <td class="amount"><?=number_format((float)$row['total_abc'],2)?></td>
          <td></td>
          <td><?=appPrintEscape($row['remarks'])?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr class="data-row"><td class="number"></td><?php for($i=0;$i<13;$i++): ?><td></td><?php endfor; ?></tr>
      <?php endif; ?>
    <?php endforeach; ?>
      <tr><td colspan="14" style="font-style:italic;border-top:0;border-bottom:0;background:#fff">Note: Insert additional rows as necessary.</td></tr>
      <tr class="summary-row"><td colspan="8" class="summary-label">Total Amount of Estimated Budget for EPA Projects:</td><td colspan="4" class="amount"><?=number_format($epaTotal,2)?></td><td colspan="2"></td></tr>
      <tr class="summary-row"><td colspan="8" class="summary-label">Total Amount of CSEs to be purchased from PS-DBM:</td><td colspan="4" class="amount"><?=number_format($cseTotal,2)?></td><td colspan="2"></td></tr>
      <tr class="summary-row"><td colspan="8" class="summary-label">Total Amount of Estimated Budget for Non-EPA Projects:</td><td colspan="4" class="amount"><?=number_format($nonEpaTotal,2)?></td><td colspan="2"></td></tr>
      <tr class="summary-row"><td colspan="8" class="summary-label">Total Amount of Estimated Budget:</td><td colspan="4" class="amount"><?=number_format($grandAbc,2)?></td><td colspan="2"></td></tr>
    </tbody>
  </table>
  <div class="signatures">
    <div class="signature"><div class="line"></div><strong>Prepared by</strong><br>Name / Position</div>
    <div class="signature"><div class="line"></div><strong>Reviewed by</strong><br>Name / Position</div>
    <div class="signature"><div class="line"></div><strong>Approved by</strong><br>Name / Position</div>
  </div>
</div>
</body>
</html>
