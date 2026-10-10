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
.dashboard-mini-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.dashboard-mini-card{min-width:0;min-height:142px;border:1px solid rgba(60,70,90,.10);border-radius:14px;padding:17px 18px;box-shadow:0 5px 16px rgba(31,41,55,.045)}
.dashboard-mini-card .label{font-size:12px;font-weight:700;color:#334155;margin-bottom:9px}
.dashboard-mini-card .metric{font-size:24px;font-weight:800;line-height:1.2;margin:5px 0 8px;color:#1f2937;overflow-wrap:anywhere}
.dashboard-mini-card .hint{font-size:11px;line-height:1.5;color:#475569;overflow-wrap:anywhere}
.dashboard-mini-card .hint b{color:#334155}
.dashboard-pastel-blue{background:#eaf3ff}
.dashboard-pastel-green{background:#eaf7ee}
.dashboard-pastel-lavender{background:#f1edff}
.dashboard-pastel-peach{background:#fff0e6}
.dashboard-pastel-pink{background:#fcecf3}
.dashboard-pastel-mint{background:#e5f7f3}
.dashboard-pastel-yellow{background:#fff8dc}
.dashboard-pastel-sky{background:#e8f7fc}
@media(max-width:1050px){.dashboard-mini-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.dashboard-mini-cards{grid-template-columns:1fr}.dashboard-mini-card{min-height:unset}}
</style>
<div class="dashboard-mini-cards">
  <div class="dashboard-mini-card dashboard-pastel-blue">
    <div class="label">Current APP Total · FY <?= (int)date('Y') ?></div>
    <div class="metric">₱<?=number_format($currentAppTotal,2)?></div>
    <div class="hint">Total planned budget for the current calendar year</div>
  </div>
  <div class="dashboard-mini-card dashboard-pastel-green">
    <div class="label">Divisions / Departments</div>
    <div class="metric"><?=number_format($divisionCount)?></div>
    <div class="hint"><?=number_format($areaUnitCount)?> Areas / Units under them</div>
  </div>
  <div class="dashboard-mini-card dashboard-pastel-lavender">
    <div class="label">Purchase Requests</div>
    <div class="metric"><?=number_format($prDashboardCount)?></div>
    <div class="hint"><?php if($recentPrDashboard): ?><b>Last created:</b> <?=e($recentPrDashboard['pr_no']??'')?> · <?=e(date('M d, Y',strtotime($recentPrDashboard['created_at'])))?><?php else: ?>No Purchase Requests created yet<?php endif; ?></div>
  </div>
  <div class="dashboard-mini-card dashboard-pastel-peach">
    <div class="label">Purchase Orders</div>
    <div class="metric"><?=number_format($poDashboardCount)?></div>
    <div class="hint"><?php if($recentPoDashboard): ?><b>Last created:</b> <?=e($recentPoDashboard['po_no']??'')?> · <?=e(date('M d, Y',strtotime($recentPoDashboard['created_at'])))?><?php else: ?>No Purchase Orders created yet<?php endif; ?></div>
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
  <div class="dashboard-mini-card dashboard-pastel-sky">
    <div class="label">Manuals</div>
    <div class="metric">Guides</div>
    <div class="hint">Procurement planning, purchasing, and system workflow references</div>
  </div>
</div>
<div class="grid">
<div class="panel"><h2>Recent Purchase Request</h2><?php if($recentPrDashboard):?><p><b><?=e($recentPrDashboard['pr_no'])?></b></p><p><b>Area/Unit:</b> <?=e($recentPrDashboard['area']??'')?></p><p><b>Division/Department:</b> <?=e($recentPrDashboard['division']??'')?></p><p><b>Total Amount:</b> ₱<?=number_format((float)$recentPrDashboard['total_amount'],2)?></p><p><b>Created:</b> <?=e(date('M d, Y h:i A',strtotime($recentPrDashboard['created_at'])))?></p><?php else:?><p class="empty">No Purchase Request has been created.</p><?php endif;?></div>
<div class="panel"><h2>Recent Purchase Order</h2><?php if($recentPoDashboard):?><p><b><?=e($recentPoDashboard['po_no'])?></b></p><p><b>Supplier:</b> <?=e($recentPoDashboard['supplier']??'')?></p><p><b>Area/Unit:</b> <?=e($recentPoDashboard['area']??'')?></p><p><b>Division/Department:</b> <?=e($recentPoDashboard['division']??'')?></p><p><b>Total Amount:</b> ₱<?=number_format((float)$recentPoDashboard['total_amount'],2)?></p><p><b>Created:</b> <?=e(date('M d, Y h:i A',strtotime($recentPoDashboard['created_at'])))?></p><?php else:?><p class="empty">No Purchase Order has been created.</p><?php endif;?></div>
</div>
<div class="grid" style="margin-top:16px">
<div class="panel"><h2>Cancelled Purchase Requests</h2><div class="table-wrap"><table class="table"><tr><th>PR No.</th><th>Area/Unit</th><th>Division/Department</th><th>Reason</th></tr><?php foreach($cancelledPrRows as $r):?><tr><td><?=e($r['pr_no'])?></td><td><?=e($r['area']??'')?></td><td><?=e($r['division']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?><?php if(!$cancelledPrRows):?><tr><td colspan="4" class="empty">No cancelled Purchase Requests.</td></tr><?php endif;?></table></div></div>
<div class="panel"><h2>Cancelled Purchase Orders</h2><div class="table-wrap"><table class="table"><tr><th>PO No.</th><th>Supplier</th><th>Area/Unit</th><th>Reason</th></tr><?php foreach($cancelledPoRows as $r):?><tr><td><?=e($r['po_no'])?></td><td><?=e($r['supplier']??'')?></td><td><?=e($r['area']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?><?php if(!$cancelledPoRows):?><tr><td colspan="4" class="empty">No cancelled Purchase Orders.</td></tr><?php endif;?></table></div></div>
</div><div class="grid"><div class="panel" id="recent-ppmp-entries"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px"><div><h2 style="margin:0">Recent PPMP Entries</h2><small style="color:#64748b">Entries created in the last 7 days</small></div><form method="get" style="display:flex;align-items:center;gap:8px" onsubmit="sessionStorage.setItem('dashboardRecentPpmpScrollY',String(window.scrollY))"><label for="ppmp_year_filter" style="font-size:12px;font-weight:700">Year</label><select id="ppmp_year_filter" name="ppmp_year" class="input" style="width:auto;min-width:100px;padding:7px 9px" onchange="this.form.requestSubmit()"><?php foreach($recentYearOptions as $optionYear):?><option value="<?=$optionYear?>" <?=$recentPpmpYear===$optionYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></form></div><div class="table-wrap"><table class="table"><tr><th>Item</th><th>Area/Unit</th><th>Category</th><th>Qty</th><th>ABC</th></tr><?php foreach($recentRows as $r):?><tr><td><?=e($r['item_name'])?></td><td><?=e($r['area'])?></td><td><?=e($r['category'])?></td><td><?=number_format($r['quantity'],2).' '.e($r['unit'])?></td><td>₱<?=number_format($r['quantity']*$r['unit_price'],2)?></td></tr><?php endforeach;?><?php if(!$recentRows):?><tr><td colspan="5" class="empty">No PPMP entries created in the last 7 days for FY <?=$recentPpmpYear?>.</td></tr><?php endif;?></table></div><?php if($recentTotalRows>0):?><div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:#64748b"><span>Showing <?=number_format(($recentPage-1)*$recentPageSize+1)?>–<?=number_format(min($recentPage*$recentPageSize,$recentTotalRows))?> of <?=number_format($recentTotalRows)?> entries</span><div style="display:flex;align-items:center;gap:6px"><?php $recentPageUrl=function(int $page)use($recentPpmpYear):string{$params=$_GET;$params['ppmp_year']=$recentPpmpYear;$params['ppmp_page']=$page;return '?'.http_build_query($params).'#recent-ppmp-entries';};?><a class="btn" style="padding:5px 9px;font-size:12px;<?= $recentPage<=1?'opacity:.45;pointer-events:none;':'' ?>" href="<?=e($recentPageUrl($recentPage-1))?>">Previous</a><span style="padding:0 5px">Page <?=$recentPage?> of <?=$recentTotalPages?></span><a class="btn" style="padding:5px 9px;font-size:12px;<?= $recentPage>=$recentTotalPages?'opacity:.45;pointer-events:none;':'' ?>" href="<?=e($recentPageUrl($recentPage+1))?>">Next</a></div></div><?php endif;?></div></div><div class="panel"><h2>Procurement Workflow</h2><p>1. Area/Unit prepares its PPMP.</p><p>2. Equivalent PPMP items are consolidated into the APP.</p><p>3. Authorized users create PRs from available PPMP quantities.</p><p>4. PR quantities automatically reduce remaining planned quantities.</p><p>5. Submitted/approved PRs can be converted into POs with supplier details.</p><a class="btn" href="app.php?year=<?=$year?>">View Consolidated APP</a></div></div><script>window.addEventListener("load",function(){var saved=sessionStorage.getItem("dashboardRecentPpmpScrollY");if(saved!==null){sessionStorage.removeItem("dashboardRecentPpmpScrollY");window.scrollTo(0,Number(saved)||0);}});</script><?php pageEnd();