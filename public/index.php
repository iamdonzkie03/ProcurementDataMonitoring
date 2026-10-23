<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']); require_once __DIR__.'/../app/layout.php';
$year=(int)($_GET['year']??date('Y'));$pdo=db();
$contextDivisionId=currentLoginDivisionId();$contextAreaId=currentLoginAreaId();$isSupervisor=currentUserIsPpmpSupervisor();
$pendingPpmpCount=0;

// Dashboard transaction summaries are intentionally system-wide.
try{
  $cols=$pdo->query("SHOW COLUMNS FROM purchase_requests LIKE 'cancellation_reason'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN cancellation_reason TEXT NULL AFTER status");
  $cols=$pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'cancellation_reason'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN cancellation_reason TEXT NULL AFTER status");
}catch(Throwable $e){}

// 1. Masterlist of Items
$masterlistCount=0;
try{
  $masterlistCount=(int)$pdo->query("SELECT COUNT(*) FROM ppmp_masterlist")->fetchColumn();
}catch(Throwable $e){}

// Dashboard mini-panel metrics
$currentAppTotal=0.0;
try{
  $st=$pdo->prepare("SELECT COALESCE(SUM(quantity*unit_price),0) FROM ppmp_items WHERE fiscal_year=?");
  $st->execute([(int)date('Y')]);
  $currentAppTotal=(float)$st->fetchColumn();
}catch(Throwable $e){}
$supplierCount=0;$recentSupplierDashboard=null;
try{
  $supplierCount=(int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
  $recentSupplierDashboard=$pdo->query("SELECT supplier_company_name,updated_at FROM suppliers ORDER BY updated_at DESC,id DESC LIMIT 1")->fetch() ?: null;
}catch(Throwable $e){}
$totalUsers=0;$recentUserDashboard=null;
try{
  ensureUserActivitySchema($pdo);
  $totalUsers=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  $recentUserDashboard=$pdo->query("SELECT full_name,last_activity_at FROM users WHERE last_activity_at IS NOT NULL ORDER BY last_activity_at DESC,id DESC LIMIT 1")->fetch() ?: null;
}catch(Throwable $e){}

// 2. Divisions/Departments and all Areas/Units
$divisionCount=0;$areaUnitCount=0;
try{
  $divisionCount=(int)$pdo->query("SELECT COUNT(*) FROM divisions")->fetchColumn();
  $areaUnitCount=(int)$pdo->query("SELECT COUNT(*) FROM areas")->fetchColumn();
}catch(Throwable $e){}

// 3. Purchase Requests - all divisions/departments/areas/units
$prDashboardCount=0;$prDashboardAmount=0.0;$recentPrDashboard=null;
try{
  $st=$pdo->query("SELECT COUNT(*) FROM purchase_requests");
  $prDashboardCount=(int)$st->fetchColumn();
  $st=$pdo->query("SELECT COALESCE(SUM(i.quantity*i.unit_price),0)
    FROM purchase_requests r
    LEFT JOIN purchase_request_items i ON i.pr_id=r.id");
  $prDashboardAmount=(float)$st->fetchColumn();

  $st=$pdo->query("SELECT r.id,r.pr_no,r.created_at,r.status,r.purpose,
      a.name area,d.name division,
      COALESCE(SUM(i.quantity*i.unit_price),0) total_amount
    FROM purchase_requests r
    LEFT JOIN areas a ON a.id=r.area_id
    LEFT JOIN divisions d ON d.id=a.division_id
    LEFT JOIN purchase_request_items i ON i.pr_id=r.id
    GROUP BY r.id
    ORDER BY r.created_at DESC,r.id DESC
    LIMIT 1");
  $recentPrDashboard=$st->fetch() ?: null;
}catch(Throwable $e){}

// 4. Purchase Orders - all divisions/departments/areas/units
$poDashboardCount=0;$poDashboardAmount=0.0;$recentPoDashboard=null;
try{
  $st=$pdo->query("SELECT COUNT(*) FROM purchase_orders");
  $poDashboardCount=(int)$st->fetchColumn();
  $st=$pdo->query("SELECT COALESCE(SUM(i.quantity*i.unit_price),0)
    FROM purchase_orders o
    LEFT JOIN purchase_order_items i ON i.po_id=o.id");
  $poDashboardAmount=(float)$st->fetchColumn();

  $st=$pdo->query("SELECT o.id,o.po_no,o.created_at,o.po_date,o.status,o.supplier,
      r.pr_no,a.name area,d.name division,
      COALESCE(SUM(i.quantity*i.unit_price),0) total_amount
    FROM purchase_orders o
    LEFT JOIN purchase_requests r ON r.id=o.pr_id
    LEFT JOIN areas a ON a.id=r.area_id
    LEFT JOIN divisions d ON d.id=a.division_id
    LEFT JOIN purchase_order_items i ON i.po_id=o.id
    GROUP BY o.id
    ORDER BY o.created_at DESC,o.id DESC
    LIMIT 1");
  $recentPoDashboard=$st->fetch() ?: null;
}catch(Throwable $e){}

// 5. Cancelled Purchase Requests and Purchase Orders
$cancelledPrCount=0;$cancelledPoCount=0;$cancelledPrRows=[];$cancelledPoRows=[];
try{
  $st=$pdo->query("SELECT r.pr_no,r.created_at,a.name area,d.name division,
      COALESCE(NULLIF(TRIM(r.cancellation_reason),''),NULLIF(TRIM(r.purpose),''),'No reason recorded') reason
    FROM purchase_requests r
    LEFT JOIN areas a ON a.id=r.area_id
    LEFT JOIN divisions d ON d.id=a.division_id
    WHERE r.status='Cancelled'
    ORDER BY r.created_at DESC,r.id DESC
    LIMIT 10");
  $cancelledPrRows=$st->fetchAll();
  $cancelledPrCount=(int)$pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status='Cancelled'")->fetchColumn();

  $st=$pdo->query("SELECT o.po_no,o.created_at,o.supplier,a.name area,d.name division,
      COALESCE(NULLIF(TRIM(o.cancellation_reason),''),NULLIF(TRIM(o.remarks),''),'No reason recorded') reason
    FROM purchase_orders o
    LEFT JOIN purchase_requests r ON r.id=o.pr_id
    LEFT JOIN areas a ON a.id=r.area_id
    LEFT JOIN divisions d ON d.id=a.division_id
    WHERE o.status='Cancelled'
    ORDER BY o.created_at DESC,o.id DESC
    LIMIT 10");
  $cancelledPoRows=$st->fetchAll();
  $cancelledPoCount=(int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='Cancelled'")->fetchColumn();
}catch(Throwable $e){}
if($isSupervisor && $contextDivisionId>0){$pendingSt=$pdo->prepare("SELECT COUNT(*) FROM ppmp_reviews r JOIN areas a ON a.id=r.area_id WHERE r.status='Pending for Review' AND a.division_id=?");$pendingSt->execute([$contextDivisionId]);$pendingPpmpCount=(int)$pendingSt->fetchColumn();}
$scopeSql='';$scopeArgs=[];
if($isSupervisor && $contextDivisionId>0){$scopeSql=' JOIN areas sa ON sa.id=p.area_id WHERE p.fiscal_year=? AND sa.division_id=?';$scopeArgs=[$year,$contextDivisionId];}
elseif($contextAreaId>0){$scopeSql=' WHERE p.fiscal_year=? AND p.area_id=?';$scopeArgs=[$year,$contextAreaId];}
else{$scopeSql=' WHERE p.fiscal_year=?';$scopeArgs=[$year];}
$ppmpSt=$pdo->prepare("SELECT COUNT(*) FROM ppmp_items p".$scopeSql);$ppmpSt->execute($scopeArgs);$ppmp=(int)$ppmpSt->fetchColumn();
$areaSt=$pdo->prepare("SELECT COUNT(DISTINCT p.area_id) FROM ppmp_items p".$scopeSql);$areaSt->execute($scopeArgs);$areas=(int)$areaSt->fetchColumn();
$qtySt=$pdo->prepare("SELECT COALESCE(SUM(p.quantity),0) FROM ppmp_items p".$scopeSql);$qtySt->execute($scopeArgs);$qty=(float)$qtySt->fetchColumn();
$abcSt=$pdo->prepare("SELECT COALESCE(SUM(p.quantity*p.unit_price),0) FROM ppmp_items p".$scopeSql);$abcSt->execute($scopeArgs);$abc=(float)$abcSt->fetchColumn();
$ppmpEndUserTotals=[];
try{
  $endUserSql="SELECT a.name AS end_user, COALESCE(SUM(p.quantity*p.unit_price),0) AS total_amount
    FROM ppmp_items p JOIN areas a ON a.id=p.area_id
    WHERE p.fiscal_year=?";
  $endUserArgs=[$year];
  if($isSupervisor && $contextDivisionId>0){$endUserSql.=" AND a.division_id=?";$endUserArgs[]=$contextDivisionId;}
  elseif($contextAreaId>0){$endUserSql.=" AND p.area_id=?";$endUserArgs[]=$contextAreaId;}
  $endUserSql.=" GROUP BY a.id,a.name HAVING SUM(p.quantity*p.unit_price)>0 ORDER BY total_amount DESC,a.name ASC";
  $endUserSt=$pdo->prepare($endUserSql);$endUserSt->execute($endUserArgs);$ppmpEndUserTotals=$endUserSt->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){}
$ppmpEndUserChartTotal=array_sum(array_map(static fn($row)=>(float)$row['total_amount'],$ppmpEndUserTotals));

$prWhere=$isSupervisor&&$contextDivisionId>0?' AND a.division_id=?':($contextAreaId>0?' AND r.area_id=?':'');
$prArgs=$isSupervisor&&$contextDivisionId>0?[$year,$contextDivisionId]:($contextAreaId>0?[$year,$contextAreaId]:[$year]);
$prCountSt=$pdo->prepare("SELECT COUNT(*) FROM purchase_requests r JOIN areas a ON a.id=r.area_id WHERE r.fiscal_year=? AND r.status<>'Cancelled'".$prWhere);$prCountSt->execute($prArgs);$prCount=(int)$prCountSt->fetchColumn();
$poCountSt=$pdo->prepare("SELECT COUNT(*) FROM purchase_orders o JOIN purchase_requests r ON r.id=o.pr_id JOIN areas a ON a.id=r.area_id WHERE r.fiscal_year=? AND o.status<>'Cancelled'".$prWhere);$poCountSt->execute($prArgs);$poCount=(int)$poCountSt->fetchColumn();
$activeUsers=0;
try{
  ensureUserActivitySchema($pdo);
  $activeUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='Active' AND last_activity_at IS NOT NULL AND last_activity_at >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
}catch(Throwable $e){ $activeUsers=0; }
$recentYearOptions=[];
try{
  $recentYearOptions=array_map('intval',$pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
}catch(Throwable $e){}
$currentCalendarYear=(int)date('Y');
if(!in_array($currentCalendarYear,$recentYearOptions,true))$recentYearOptions[]=$currentCalendarYear;
rsort($recentYearOptions);
$recentPpmpYear=(int)($_GET['ppmp_year']??$currentCalendarYear);
if(!in_array($recentPpmpYear,$recentYearOptions,true))$recentPpmpYear=$currentCalendarYear;
$recentSql=$isSupervisor&&$contextDivisionId>0?' WHERE p.fiscal_year=? AND a.division_id=? AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)':($contextAreaId>0?' WHERE p.fiscal_year=? AND p.area_id=? AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)':' WHERE p.fiscal_year=? AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
$recentArgs=$isSupervisor&&$contextDivisionId>0?[$recentPpmpYear,$contextDivisionId]:($contextAreaId>0?[$recentPpmpYear,$contextAreaId]:[$recentPpmpYear]);
$recent=$pdo->prepare('SELECT p.*,a.name area,c.name category FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id'.$recentSql.' ORDER BY p.created_at DESC');$recent->execute($recentArgs);$recentAllRows=$recent->fetchAll();$recentPageSize=10;$recentTotalRows=count($recentAllRows);$recentTotalPages=max(1,(int)ceil($recentTotalRows/$recentPageSize));$recentPage=max(1,min($recentTotalPages,(int)($_GET['ppmp_page']??1)));$recentRows=array_slice($recentAllRows,($recentPage-1)*$recentPageSize,$recentPageSize);pageStart('Dashboard');
?><style>
.dashboard-mini-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.dashboard-mini-card{position:relative;isolation:isolate;display:block;min-width:0;min-height:148px;overflow:hidden;border:1px solid #e5e7eb;border-radius:15px;padding:20px 20px 18px;background:linear-gradient(145deg,#fff 0%,#fbfaf7 100%);box-shadow:0 3px 10px rgba(15,23,42,.035),0 12px 28px rgba(15,23,42,.045);transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease}
.dashboard-mini-card:before{content:"";position:absolute;z-index:-1;top:0;left:0;right:0;height:4px;background:#9ca3af}
.dashboard-mini-card:after{content:"";position:absolute;z-index:-1;width:112px;height:112px;right:-48px;bottom:-54px;border-radius:50%;background:currentColor;opacity:.035;pointer-events:none}
.dashboard-mini-card:hover{transform:translateY(-3px);box-shadow:0 8px 18px rgba(15,23,42,.07),0 18px 36px rgba(15,23,42,.06);border-color:#d1d5db}
.dashboard-mini-card .label{font-size:12px;font-weight:700;letter-spacing:.045em;text-transform:uppercase;color:#64748b;margin-bottom:12px}
.dashboard-mini-card .metric{font-size:24px;font-weight:800;letter-spacing:-.035em;line-height:1.2;margin:5px 0 9px;color:#172033;overflow-wrap:anywhere}
.dashboard-mini-card .hint{font-size:11px;line-height:1.6;color:#64748b;overflow-wrap:anywhere}
.dashboard-mini-card .hint b{color:#344054;font-weight:700}
.dashboard-pastel-blue{background:linear-gradient(145deg,#fff 0%,#f7f9fd 100%);color:#647ba5}
.dashboard-pastel-blue:before{background:#647ba5}
.dashboard-pastel-green{background:linear-gradient(145deg,#fff 0%,#f7faf7 100%);color:#64866d}
.dashboard-pastel-green:before{background:#64866d}
.dashboard-pastel-lavender{background:linear-gradient(145deg,#fff 0%,#faf8fd 100%);color:#8170a8}
.dashboard-pastel-lavender:before{background:#8170a8}
.dashboard-pastel-peach{background:linear-gradient(145deg,#fff 0%,#fdf8f4 100%);color:#b78361}
.dashboard-pastel-peach:before{background:#b78361}
.dashboard-pastel-pink{background:linear-gradient(145deg,#fff 0%,#fdf8fa 100%);color:#a66f87}
.dashboard-pastel-pink:before{background:#a66f87}
.dashboard-pastel-mint{background:linear-gradient(145deg,#fff 0%,#f6faf9 100%);color:#5f8d84}
.dashboard-pastel-mint:before{background:#5f8d84}
.dashboard-pastel-yellow{background:linear-gradient(145deg,#fff 0%,#fcfaf3 100%);color:#a58b4d}
.dashboard-pastel-yellow:before{background:#a58b4d}
.dashboard-pastel-sky{background:linear-gradient(145deg,#fff 0%,#f5f9fb 100%);color:#5f879a}
.dashboard-pastel-sky:before{background:#5f879a}
.dashboard-enduser-chart{margin-top:18px}
.dashboard-enduser-chart-layout{display:grid;grid-template-columns:minmax(220px,.9fr) minmax(260px,1.1fr);align-items:center;gap:24px}
.dashboard-enduser-chart-visual{display:flex;align-items:center;justify-content:center;min-height:260px}
#ppmpEndUserPieChart{display:block;width:100%;max-width:320px;height:auto;aspect-ratio:1}
.dashboard-enduser-legend{display:flex;flex-direction:column;gap:9px;max-height:320px;overflow:auto;padding:2px 4px}
.dashboard-enduser-legend-row{display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:10px;padding:9px 10px;border:1px solid #edf0f3;border-radius:9px;background:#fff}
.dashboard-enduser-legend-swatch{width:10px;height:10px;border-radius:3px}
.dashboard-enduser-legend-name{font-size:12px;font-weight:600;color:#475569;overflow-wrap:anywhere}
.dashboard-enduser-legend-value{font-size:12px;font-weight:700;color:#172033;text-align:right;white-space:nowrap}
.dashboard-enduser-legend-percent{display:block;font-size:10px;font-weight:500;color:#94a3b8;margin-top:2px}
.dashboard-enduser-chart-total{font-size:12px;color:#64748b;margin-top:5px}
@media(max-width:760px){.dashboard-enduser-chart-layout{grid-template-columns:1fr;gap:12px}.dashboard-enduser-chart-visual{min-height:unset}.dashboard-enduser-legend{max-height:none}}
.dashboard-mini-card[href]{cursor:pointer}
.dashboard-mini-card[href]:focus-visible{outline:3px solid #94a3b8;outline-offset:3px}
@media(prefers-reduced-motion:reduce){.dashboard-mini-card{transition:none}.dashboard-mini-card:hover{transform:none}}
@media(max-width:1050px){.dashboard-mini-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.dashboard-mini-cards{grid-template-columns:1fr}.dashboard-mini-card{min-height:unset}}
</style>
<div class="dashboard-mini-cards">
  <div class="dashboard-mini-card dashboard-pastel-blue">
    <div class="label">Current APP Total · FY <?= (int)date('Y') ?></div>
    <div class="metric">₱<?=number_format($currentAppTotal,2)?></div>
    <div class="hint">Total planned budget for the current calendar year</div>
  </div>
  <div class="dashboard-mini-card dashboard-pastel-pink">
    <div class="label">Supplier Registry</div>
    <div class="metric"><?=number_format($supplierCount)?></div>
    <div class="hint"><?php if($recentSupplierDashboard): ?><b>Last updated:</b> <?=e($recentSupplierDashboard['supplier_company_name']??'')?> · <?=!empty($recentSupplierDashboard['updated_at'])?e(date('M d, Y',strtotime($recentSupplierDashboard['updated_at']))):'Date unavailable'?><?php else: ?>No suppliers registered yet<?php endif; ?></div>
  </div>
  <div class="dashboard-mini-card dashboard-pastel-mint">
    <div class="label">Users</div>
    <div class="metric"><?=number_format($totalUsers)?></div>
    <div class="hint"><?php if($recentUserDashboard): ?><b>Recent user logged in:</b> <?=e($recentUserDashboard['full_name']??'')?><?php if(!empty($recentUserDashboard['last_activity_at'])): ?> · <?=e(date('M d, Y h:i A',strtotime($recentUserDashboard['last_activity_at'])))?><?php endif; ?><?php else: ?>No recent user activity recorded<?php endif; ?></div>
  </div>
  <div class="dashboard-mini-card dashboard-pastel-yellow">
    <div class="label">Masterlist of Items</div>
    <div class="metric"><?=number_format($masterlistCount)?></div>
    <div class="hint">Total items available in the procurement masterlist</div>
  </div>
  <a class="dashboard-mini-card dashboard-pastel-sky" href="role_functions_guide.php" style="display:block;color:inherit;text-decoration:none" aria-label="Open Role and Functions Access Matrix guide">
    <div class="label">Guides</div>
    <div class="metric">Role &amp; Functions</div>
    <div class="hint">Open the Role and Functions Access Matrix <span aria-hidden="true">→</span></div>
  </a>
</div>
<div class="panel dashboard-enduser-chart">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <div><h2 style="margin:0">PPMP Budget by End-user</h2><div class="dashboard-enduser-chart-total">Fiscal Year <?= (int)$year ?> · Total planned amount: <b>₱<?=number_format($ppmpEndUserChartTotal,2)?></b></div></div>
  </div>
  <?php if($ppmpEndUserTotals): ?>
  <div class="dashboard-enduser-chart-layout">
    <div class="dashboard-enduser-chart-visual"><canvas id="ppmpEndUserPieChart" width="320" height="320" role="img" aria-label="Pie chart showing PPMP planned amounts by end-user for fiscal year <?= (int)$year ?>"></canvas></div>
    <div class="dashboard-enduser-legend" id="ppmpEndUserPieLegend"><?php foreach($ppmpEndUserTotals as $i=>$row): $pct=$ppmpEndUserChartTotal>0?((float)$row['total_amount']/$ppmpEndUserChartTotal*100):0; ?><div class="dashboard-enduser-legend-row"><span class="dashboard-enduser-legend-swatch" data-chart-index="<?=$i?>"></span><div class="dashboard-enduser-legend-name"><?=e($row['end_user'])?><span class="dashboard-enduser-legend-percent"><?=number_format($pct,1)?>% of total</span></div><div class="dashboard-enduser-legend-value">₱<?=number_format((float)$row['total_amount'],2)?></div></div><?php endforeach; ?></div>
  </div>
  <?php else: ?><p class="empty">No PPMP budget amounts are available for FY <?= (int)$year ?>.</p><?php endif; ?>
</div>
<div class="grid">
<div class="panel"><h2>Recent Purchase Request</h2><?php if($recentPrDashboard):?><p><b><?=e($recentPrDashboard['pr_no'])?></b></p><p><b>Area/Unit:</b> <?=e($recentPrDashboard['area']??'')?></p><p><b>Division/Department:</b> <?=e($recentPrDashboard['division']??'')?></p><p><b>Total Amount:</b> ₱<?=number_format((float)$recentPrDashboard['total_amount'],2)?></p><p><b>Created:</b> <?=e(date('M d, Y h:i A',strtotime($recentPrDashboard['created_at'])))?></p><?php else:?><p class="empty">No Purchase Request has been created.</p><?php endif;?></div>
<div class="panel"><h2>Recent Purchase Order</h2><?php if($recentPoDashboard):?><p><b><?=e($recentPoDashboard['po_no'])?></b></p><p><b>Supplier:</b> <?=e($recentPoDashboard['supplier']??'')?></p><p><b>Area/Unit:</b> <?=e($recentPoDashboard['area']??'')?></p><p><b>Division/Department:</b> <?=e($recentPoDashboard['division']??'')?></p><p><b>Total Amount:</b> ₱<?=number_format((float)$recentPoDashboard['total_amount'],2)?></p><p><b>Created:</b> <?=e(date('M d, Y h:i A',strtotime($recentPoDashboard['created_at'])))?></p><?php else:?><p class="empty">No Purchase Order has been created.</p><?php endif;?></div>
</div>
<div class="grid" style="margin-top:16px">
<div class="panel"><h2>Cancelled Purchase Requests</h2><div class="table-wrap"><table class="table"><tr><th>PR No.</th><th>Area/Unit</th><th>Division/Department</th><th>Reason</th></tr><?php foreach($cancelledPrRows as $r):?><tr><td><?=e($r['pr_no'])?></td><td><?=e($r['area']??'')?></td><td><?=e($r['division']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?><?php if(!$cancelledPrRows):?><tr><td colspan="4" class="empty">No cancelled Purchase Requests.</td></tr><?php endif;?></table></div></div>
<div class="panel"><h2>Cancelled Purchase Orders</h2><div class="table-wrap"><table class="table"><tr><th>PO No.</th><th>Supplier</th><th>Area/Unit</th><th>Reason</th></tr><?php foreach($cancelledPoRows as $r):?><tr><td><?=e($r['po_no'])?></td><td><?=e($r['supplier']??'')?></td><td><?=e($r['area']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?><?php if(!$cancelledPoRows):?><tr><td colspan="4" class="empty">No cancelled Purchase Orders.</td></tr><?php endif;?></table></div></div>
</div><div class="grid"><div class="panel" id="recent-ppmp-entries"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px"><div><h2 style="margin:0">Recent PPMP Entries</h2><small style="color:#64748b">Entries created in the last 7 days</small></div><form method="get" style="display:flex;align-items:center;gap:8px" onsubmit="sessionStorage.setItem('dashboardRecentPpmpScrollY',String(window.scrollY))"><label for="ppmp_year_filter" style="font-size:12px;font-weight:700">Year</label><select id="ppmp_year_filter" name="ppmp_year" class="input" style="width:auto;min-width:100px;padding:7px 9px" onchange="this.form.requestSubmit()"><?php foreach($recentYearOptions as $optionYear):?><option value="<?=$optionYear?>" <?=$recentPpmpYear===$optionYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></form></div><div class="table-wrap"><table class="table"><tr><th>Item</th><th>Area/Unit</th><th>Category</th><th>Qty</th><th>ABC</th></tr><?php foreach($recentRows as $r):?><tr><td><?=e($r['item_name'])?></td><td><?=e($r['area'])?></td><td><?=e($r['category'])?></td><td><?=number_format($r['quantity'],2).' '.e($r['unit'])?></td><td>₱<?=number_format($r['quantity']*$r['unit_price'],2)?></td></tr><?php endforeach;?><?php if(!$recentRows):?><tr><td colspan="5" class="empty">No PPMP entries created in the last 7 days for FY <?=$recentPpmpYear?>.</td></tr><?php endif;?></table></div><?php if($recentTotalRows>0):?><div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:#64748b"><span>Showing <?=number_format(($recentPage-1)*$recentPageSize+1)?>–<?=number_format(min($recentPage*$recentPageSize,$recentTotalRows))?> of <?=number_format($recentTotalRows)?> entries</span><div style="display:flex;align-items:center;gap:6px"><?php $recentPageUrl=function(int $page)use($recentPpmpYear):string{$params=$_GET;$params['ppmp_year']=$recentPpmpYear;$params['ppmp_page']=$page;return '?'.http_build_query($params).'#recent-ppmp-entries';};?><a class="btn" style="padding:5px 9px;font-size:12px;<?= $recentPage<=1?'opacity:.45;pointer-events:none;':'' ?>" href="<?=e($recentPageUrl($recentPage-1))?>">Previous</a><span style="padding:0 5px">Page <?=$recentPage?> of <?=$recentTotalPages?></span><a class="btn" style="padding:5px 9px;font-size:12px;<?= $recentPage>=$recentTotalPages?'opacity:.45;pointer-events:none;':'' ?>" href="<?=e($recentPageUrl($recentPage+1))?>">Next</a></div></div><?php endif;?></div><div class="panel"><h2>Procurement Workflow</h2><p>1. Area/Unit prepares its PPMP.</p><p>2. Equivalent PPMP items are consolidated into the APP.</p><p>3. Authorized users create PRs from available PPMP quantities.</p><p>4. PR quantities automatically reduce remaining planned quantities.</p><p>5. Submitted/approved PRs can be converted into POs with supplier details.</p><a class="btn" href="app.php?year=<?=$year?>">View Consolidated APP</a></div></div><script>const ppmpEndUserData = <?=json_encode(array_map(static fn($row)=>['name'=>(string)$row['end_user'],'amount'=>(float)$row['total_amount']],$ppmpEndUserTotals),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
(function(){
  const canvas=document.getElementById('ppmpEndUserPieChart');
  if(!canvas || !ppmpEndUserData.length) return;
  const ctx=canvas.getContext('2d');
  const colors=['#647ba5','#64866d','#8170a8','#b78361','#a66f87','#5f8d84','#a58b4d','#5f879a','#9a7560','#718096','#7c8f6b','#987fa8'];
  const total=ppmpEndUserData.reduce((sum,item)=>sum+item.amount,0);
  document.querySelectorAll('.dashboard-enduser-legend-swatch').forEach((el,i)=>{el.style.backgroundColor=colors[i%colors.length];});
  function draw(){
    const dpr=window.devicePixelRatio||1;
    const width=Math.max(220,Math.min(canvas.parentElement.clientWidth||320,320));
    canvas.width=width*dpr;canvas.height=width*dpr;canvas.style.width=width+'px';canvas.style.height=width+'px';
    ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,width,width);
    const cx=width/2,cy=width/2,r=width*.44;
    let angle=-Math.PI/2;
    ppmpEndUserData.forEach((item,i)=>{
      const slice=total>0?(item.amount/total)*Math.PI*2:0;
      ctx.beginPath();ctx.moveTo(cx,cy);ctx.arc(cx,cy,r,angle,angle+slice);ctx.closePath();
      ctx.fillStyle=colors[i%colors.length];ctx.fill();ctx.strokeStyle='#fff';ctx.lineWidth=2;ctx.stroke();angle+=slice;
    });
  }
  draw();
  if(window.ResizeObserver){new ResizeObserver(draw).observe(canvas.parentElement);}
  else window.addEventListener('resize',draw);
})();
window.addEventListener("load",function(){var saved=sessionStorage.getItem("dashboardRecentPpmpScrollY");if(saved!==null){sessionStorage.removeItem("dashboardRecentPpmpScrollY");window.scrollTo(0,Number(saved)||0);}});</script><?php pageEnd();