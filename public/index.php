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
$prDashboardCount=0;$prDashboardAmount=0.0;$recentPrDashboardRows=[];
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
    LIMIT 10");
  $recentPrDashboardRows=$st->fetchAll();
}catch(Throwable $e){}

// 4. Purchase Orders - all divisions/departments/areas/units
$poDashboardCount=0;$poDashboardAmount=0.0;$recentPoDashboardRows=[];
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
    LIMIT 10");
  $recentPoDashboardRows=$st->fetchAll();
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
$ppmpBudgetYearOptions=[];
try{$ppmpBudgetYearOptions=array_map('intval',$pdo->query("SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN));}catch(Throwable $e){}
$currentCalendarYear=(int)date('Y');
if(!in_array($currentCalendarYear,$ppmpBudgetYearOptions,true))$ppmpBudgetYearOptions[]=$currentCalendarYear;
rsort($ppmpBudgetYearOptions);
$ppmpEndUserYear=(int)($_GET['ppmp_budget_year']??$currentCalendarYear);
if(!in_array($ppmpEndUserYear,$ppmpBudgetYearOptions,true))$ppmpEndUserYear=$currentCalendarYear;
$ppmpEndUserTotals=[];
try{
  $endUserSql="SELECT a.name AS end_user, COALESCE(SUM(p.quantity*p.unit_price),0) AS total_amount
    FROM ppmp_items p JOIN areas a ON a.id=p.area_id
    WHERE p.fiscal_year=?";
  $endUserArgs=[$ppmpEndUserYear];
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
.dashboard-pr-po-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:stretch;width:100%;margin-top:24px}
.dashboard-pr-po-card{min-width:0;width:100%;height:100%;box-sizing:border-box}
.dashboard-pr-po-card .table-wrap{max-width:100%;overflow-x:auto}
@media(max-width:760px){.dashboard-pr-po-grid{grid-template-columns:1fr}}
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
.dashboard-enduser-chart-layout{display:flex;flex-direction:column;gap:18px}
.dashboard-enduser-chart-visual{display:block;width:100%;overflow-x:auto;overflow-y:hidden;padding:4px 0 10px}
#ppmpEndUserPieChart{display:block;height:390px;max-width:none}
.dashboard-enduser-legend{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:9px;max-height:280px;overflow:auto;padding:2px 4px}
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

.procurement-process-workflow{width:100%;max-width:none;margin:24px 0 0;box-sizing:border-box;overflow:hidden}
.procurement-process-workflow .workflow-intro{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.procurement-process-workflow .workflow-intro p{margin:6px 0 0;color:#64748b;font-size:13px;line-height:1.6}
.procurement-process-workflow .workflow-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.procurement-process-workflow .workflow-replay{cursor:pointer}
.procurement-process-workflow .workflow-step.is-active{z-index:1;border-color:#7896c4;box-shadow:0 0 0 3px rgba(120,150,196,.18),0 12px 28px rgba(59,86,130,.13);animation:workflowStepFocus .8s ease both}
.procurement-process-workflow .workflow-step.is-active .workflow-step-number{background:#526f9e;color:#fff;box-shadow:0 0 0 5px rgba(82,111,158,.13)}
.procurement-process-workflow .workflow-step.is-complete{border-color:#cfe2d4}
.procurement-process-workflow .workflow-step.is-complete .workflow-step-number{background:#e6f4e9;color:#34734a}
@keyframes workflowStepFocus{0%{transform:scale(.985)}45%{transform:scale(1.015)}100%{transform:scale(1)}}

.procurement-process-workflow .workflow-phase{margin-top:20px;padding:18px;border:1px solid #e8edf3;border-radius:14px;background:linear-gradient(145deg,#fff,#fafbfd)}
.procurement-process-workflow .workflow-phase-heading{display:flex;align-items:center;gap:10px;margin:0 0 14px;color:#344054;font-size:14px;font-weight:800}
.procurement-process-workflow .workflow-phase-number{display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px;width:30px;height:30px;border-radius:9px;background:#e9eef7;color:#4f6589;font-size:12px}
.procurement-process-workflow .workflow-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:stretch}
.procurement-process-workflow .workflow-step{position:relative;min-width:0;padding:15px 15px 14px;border:1px solid #e4e9f0;border-radius:12px;background:#fff;box-shadow:0 2px 7px rgba(15,23,42,.035);animation:workflowStepEnter .55s both;animation-delay:calc(var(--step-index,0)*45ms);transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
.procurement-process-workflow .workflow-step:hover{transform:translateY(-3px);border-color:#cbd5e1;box-shadow:0 8px 18px rgba(15,23,42,.07)}
.procurement-process-workflow .workflow-step-top{display:flex;align-items:center;gap:9px;margin-bottom:8px}
.procurement-process-workflow .workflow-step-number{display:inline-flex;align-items:center;justify-content:center;flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:#edf2f8;color:#526783;font-size:12px;font-weight:800}
.procurement-process-workflow .workflow-step strong{font-size:12px;line-height:1.45;color:#344054}
.procurement-process-workflow .workflow-step p{margin:0;color:#596579;font-size:12px;line-height:1.65;overflow-wrap:anywhere}
.procurement-process-workflow .workflow-outcome{display:block;margin-top:9px;padding:7px 9px;border-radius:8px;font-size:11px;font-weight:700;line-height:1.5}
.procurement-process-workflow .workflow-outcome.approved{background:#edf8f0;color:#34734a}
.procurement-process-workflow .workflow-outcome.disapproved{background:#fff1f0;color:#a3423b}
.procurement-process-workflow .workflow-outcome.info{background:#eef4ff;color:#49699c}
.procurement-process-workflow .workflow-connector{display:flex;justify-content:center;align-items:center;height:24px;color:#9aa8bb;font-size:20px;animation:workflowArrowPulse 1.8s ease-in-out infinite}
.procurement-process-workflow .workflow-finish{margin-top:18px;padding:14px 16px;border-radius:12px;background:#edf8f0;border:1px solid #cfe8d6;color:#34734a;font-size:12px;line-height:1.65}
@keyframes workflowStepEnter{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes workflowArrowPulse{0%,100%{opacity:.45;transform:translateY(-2px)}50%{opacity:1;transform:translateY(2px)}}
@media(max-width:1000px){.procurement-process-workflow .workflow-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.procurement-process-workflow .workflow-phase{padding:12px}.procurement-process-workflow .workflow-steps{grid-template-columns:1fr}.procurement-process-workflow .workflow-step{animation-delay:0ms}}
@media(prefers-reduced-motion:reduce){.procurement-process-workflow .workflow-step,.procurement-process-workflow .workflow-connector{animation:none;transition:none}.procurement-process-workflow .workflow-step:hover{transform:none}}

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
    <div><h2 style="margin:0">PPMP Budget by End-user</h2><div class="dashboard-enduser-chart-total">Fiscal Year <?= (int)$ppmpEndUserYear ?> · Total planned amount: <b>₱<?=number_format($ppmpEndUserChartTotal,2)?></b></div></div>
    <form method="get" style="display:flex;align-items:center;gap:8px">
      <?php if(isset($_GET['year'])): ?><input type="hidden" name="year" value="<?= (int)$year ?>"><?php endif; ?>
      <?php if(isset($_GET['ppmp_year'])): ?><input type="hidden" name="ppmp_year" value="<?= (int)$recentPpmpYear ?>"><?php endif; ?>
      <?php if(isset($_GET['ppmp_page'])): ?><input type="hidden" name="ppmp_page" value="<?= (int)$recentPage ?>"><?php endif; ?>
      <label for="ppmp_budget_year" style="font-size:12px;font-weight:700;color:#64748b">Year</label>
      <select id="ppmp_budget_year" name="ppmp_budget_year" class="input" style="width:auto;min-width:100px;padding:7px 9px" onchange="this.form.submit()">
        <?php foreach($ppmpBudgetYearOptions as $optionYear): ?><option value="<?=$optionYear?>" <?=$ppmpEndUserYear===$optionYear?'selected':''?>><?=$optionYear?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if($ppmpEndUserTotals): ?>
  <div class="dashboard-enduser-chart-layout">
    <div class="dashboard-enduser-chart-visual"><canvas id="ppmpEndUserBarChart" width="900" height="390" role="img" aria-label="Vertical bar chart showing each end-user's share of the PPMP planned budget for fiscal year <?= (int)$ppmpEndUserYear ?>. Bars show percentage of total budget."></canvas></div>
    <div class="dashboard-enduser-legend" id="ppmpEndUserBarLegend"><?php foreach($ppmpEndUserTotals as $i=>$row): $pct=$ppmpEndUserChartTotal>0?((float)$row['total_amount']/$ppmpEndUserChartTotal*100):0; ?><div class="dashboard-enduser-legend-row"><span class="dashboard-enduser-legend-swatch" data-chart-index="<?=$i?>"></span><div class="dashboard-enduser-legend-name"><?=e($row['end_user'])?><span class="dashboard-enduser-legend-percent"><?=number_format($pct,1)?>% of total</span></div><div class="dashboard-enduser-legend-value">₱<?=number_format((float)$row['total_amount'],2)?></div></div><?php endforeach; ?></div>
  </div>
  <?php else: ?><p class="empty">No PPMP budget amounts are available for FY <?= (int)$ppmpEndUserYear ?>.</p><?php endif; ?>
</div>
<div class="dashboard-pr-po-grid">
<div class="panel dashboard-pr-po-card"><h2>Purchase Requests</h2><h3 style="margin:14px 0 8px;font-size:14px">Recent Purchase Requests</h3><div class="table-wrap"><table class="table"><tr><th>PR No.</th><th>Area/Unit</th><th>Division/Department</th><th>Status</th><th>Total Amount</th><th>Created</th></tr><?php foreach($recentPrDashboardRows as $r):?><tr><td><?=e($r['pr_no'])?></td><td><?=e($r['area']??'')?></td><td><?=e($r['division']??'')?></td><td><?=e($r['status']??'')?></td><td>₱<?=number_format((float)$r['total_amount'],2)?></td><td><?=!empty($r['created_at'])?e(date('M d, Y h:i A',strtotime($r['created_at']))):'—'?></td></tr><?php endforeach;?><?php if(!$recentPrDashboardRows):?><tr><td colspan="6" class="empty">No Purchase Requests have been created.</td></tr><?php endif;?></table></div><h3 style="margin:18px 0 8px;font-size:14px">Cancelled Purchase Requests</h3><div class="table-wrap"><table class="table"><tr><th>PR No.</th><th>Area/Unit</th><th>Division/Department</th><th>Reason</th></tr><?php foreach($cancelledPrRows as $r):?><tr><td><?=e($r['pr_no'])?></td><td><?=e($r['area']??'')?></td><td><?=e($r['division']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?><?php if(!$cancelledPrRows):?><tr><td colspan="4" class="empty">No cancelled Purchase Requests.</td></tr><?php endif;?></table></div></div>
<div class="panel dashboard-pr-po-card"><h2>Purchase Orders</h2><h3 style="margin:14px 0 8px;font-size:14px">Recent Purchase Orders</h3><div class="table-wrap"><table class="table"><tr><th>PO No.</th><th>Supplier</th><th>Area/Unit</th><th>Division/Department</th><th>Status</th><th>Total Amount</th><th>Created</th></tr><?php foreach($recentPoDashboardRows as $r):?><tr><td><?=e($r['po_no'])?></td><td><?=e($r['supplier']??'')?></td><td><?=e($r['area']??'')?></td><td><?=e($r['division']??'')?></td><td><?=e($r['status']??'')?></td><td>₱<?=number_format((float)$r['total_amount'],2)?></td><td><?=!empty($r['created_at'])?e(date('M d, Y h:i A',strtotime($r['created_at']))):'—'?></td></tr><?php endforeach;?><?php if(!$recentPoDashboardRows):?><tr><td colspan="7" class="empty">No Purchase Orders have been created.</td></tr><?php endif;?></table></div><h3 style="margin:18px 0 8px;font-size:14px">Cancelled Purchase Orders</h3><div class="table-wrap"><table class="table"><tr><th>PO No.</th><th>Supplier</th><th>Area/Unit</th><th>Division/Department</th><th>Reason</th></tr><?php foreach($cancelledPoRows as $r):?><tr><td><?=e($r['po_no'])?></td><td><?=e($r['supplier']??'')?></td><td><?=e($r['area']??'')?></td><td><?=e($r['division']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?><?php if(!$cancelledPoRows):?><tr><td colspan="5" class="empty">No cancelled Purchase Orders.</td></tr><?php endif;?></table></div></div>
</div><div class="panel procurement-process-workflow">
  <div class="workflow-intro">
    <div><h2 style="margin:0">Procurement Process Workflow</h2><p>Follow the process from PPMP preparation through bidding, award, and contract issuance.</p></div>
    <div class="workflow-actions"><button type="button" class="btn workflow-replay" id="replayProcurementWorkflow">Replay Animation</button><a class="btn" href="app.php?year=<?=$year?>">View Consolidated APP</a></div>
  </div>
  <div class="workflow-phase">
    <h3 class="workflow-phase-heading"><span class="workflow-phase-number">01</span> PPMP Preparation, Review &amp; Budget Approval</h3>
    <div class="workflow-steps">
      <article class="workflow-step" style="--step-index:1"><div class="workflow-step-top"><span class="workflow-step-number">1</span><strong>PPMP Preparation</strong></div><p>The End-user creates a PPMP and submits it to the Division Head for review.</p></article>
      <article class="workflow-step" style="--step-index:2"><div class="workflow-step-top"><span class="workflow-step-number">2</span><strong>Division Head Review</strong></div><p>The Division Head reviews the PPMP and may approve or disapprove it.</p><span class="workflow-outcome info">Decision: Approve or disapprove</span></article>
      <article class="workflow-step" style="--step-index:3"><div class="workflow-step-top"><span class="workflow-step-number">3</span><strong>Division Head Disapproves</strong></div><p>If disapproved, the PPMP is returned to the End-user.</p><span class="workflow-outcome disapproved">Outcome: Marked “Disapproved”</span></article>
      <article class="workflow-step" style="--step-index:4"><div class="workflow-step-top"><span class="workflow-step-number">4</span><strong>Budget Section Review</strong></div><p>If the Division Head approves, the PPMP is forwarded to the Budget Section for approval and issuance of budget allocation. The Budget Officer may approve or disapprove it.</p></article>
      <article class="workflow-step" style="--step-index:5"><div class="workflow-step-top"><span class="workflow-step-number">5</span><strong>Budget Officer Disapproves</strong></div><p>If disapproved by the Budget Officer, the PPMP is returned to the End-user.</p><span class="workflow-outcome disapproved">Outcome: Marked “Disapproved”</span></article>
      <article class="workflow-step" style="--step-index:6"><div class="workflow-step-top"><span class="workflow-step-number">6</span><strong>Budget Approval</strong></div><p>If approved by the Budget Officer, the PPMP is returned to the End-user marked “Approved” and automatically included in the Annual Procurement Plan (APP).</p><span class="workflow-outcome approved">Outcome: Approved PPMP added to APP</span></article>
    </div>
  </div>
  <div class="workflow-connector" aria-hidden="true">↓</div>
  <div class="workflow-phase">
    <h3 class="workflow-phase-heading"><span class="workflow-phase-number">02</span> Purchase Request &amp; HoPE Approval</h3>
    <div class="workflow-steps">
      <article class="workflow-step" style="--step-index:7"><div class="workflow-step-top"><span class="workflow-step-number">7</span><strong>Create Purchase Request</strong></div><p>The End-user creates a PR. Only items approved and listed in the APP are available for selection in a Purchase Request.</p></article>
      <article class="workflow-step" style="--step-index:8"><div class="workflow-step-top"><span class="workflow-step-number">8</span><strong>CAF &amp; HoPE Routing</strong></div><p>The PR is forwarded to the Budget Office for issuance of the Certificate of Availability of Funds (CAF), then to the Head of the Procuring Entity (HoPE) for approval.</p></article>
      <article class="workflow-step" style="--step-index:9"><div class="workflow-step-top"><span class="workflow-step-number">9</span><strong>HoPE Reviews PR</strong></div><p>The HoPE may approve or disapprove the Purchase Request.</p><span class="workflow-outcome info">Decision: Approve or disapprove</span></article>
      <article class="workflow-step" style="--step-index:10"><div class="workflow-step-top"><span class="workflow-step-number">10</span><strong>PR Disapproved</strong></div><p>If the HoPE disapproves the PR, it is returned to the End-user.</p><span class="workflow-outcome disapproved">Outcome: Returned to End-user</span></article>
      <article class="workflow-step" style="--step-index:11"><div class="workflow-step-top"><span class="workflow-step-number">11</span><strong>PR Approved &amp; BAC Review</strong></div><p>If approved, the PR is returned to the End-user and routed through the Procurement Section to the BAC for evaluation and verification of documentary requirements before posting the bid opportunity, if applicable.</p><span class="workflow-outcome approved">Next: Bid opportunity, if applicable</span></article>
    </div>
  </div>
  <div class="workflow-connector" aria-hidden="true">↓</div>
  <div class="workflow-phase">
    <h3 class="workflow-phase-heading"><span class="workflow-phase-number">03</span> Bid Evaluation &amp; Recommendation of Award</h3>
    <div class="workflow-steps">
      <article class="workflow-step" style="--step-index:12"><div class="workflow-step-top"><span class="workflow-step-number">12</span><strong>End-user Bid Evaluation</strong></div><p>Bid results are forwarded to the End-user to evaluate the completeness and compliance of the bidder’s submission.</p></article>
      <article class="workflow-step" style="--step-index:13"><div class="workflow-step-top"><span class="workflow-step-number">13</span><strong>Non-compliant Bid</strong></div><p>If the bidder is found non-compliant, the bid opportunity is marked as Failed.</p><span class="workflow-outcome disapproved">Outcome: Failed</span></article>
      <article class="workflow-step" style="--step-index:14"><div class="workflow-step-top"><span class="workflow-step-number">14</span><strong>Compliant Bid</strong></div><p>If the bidder is found compliant, the bid opportunity is marked as Awarded.</p><span class="workflow-outcome approved">Outcome: Awarded</span></article>
      <article class="workflow-step" style="--step-index:15"><div class="workflow-step-top"><span class="workflow-step-number">15</span><strong>BAC Re-evaluation</strong></div><p>The BAC, through the Procurement Section, re-evaluates bidders’ compliance requirements before recommending an award.</p></article>
      <article class="workflow-step" style="--step-index:16"><div class="workflow-step-top"><span class="workflow-step-number">16</span><strong>Resolution Recommending Award</strong></div><p>BAC members issue a resolution recommending the award for approval by the HoPE.</p></article>
    </div>
  </div>
  <div class="workflow-connector" aria-hidden="true">↓</div>
  <div class="workflow-phase">
    <h3 class="workflow-phase-heading"><span class="workflow-phase-number">04</span> HoPE Decision, Contract &amp; Closeout</h3>
    <div class="workflow-steps">
      <article class="workflow-step" style="--step-index:17"><div class="workflow-step-top"><span class="workflow-step-number">17</span><strong>Recommendation Disapproved</strong></div><p>If the HoPE disapproves the recommendation, it is returned to the End-user.</p><span class="workflow-outcome disapproved">Outcome: Returned to End-user</span></article>
      <article class="workflow-step" style="--step-index:18"><div class="workflow-step-top"><span class="workflow-step-number">18</span><strong>Prepare Contract &amp; NTP</strong></div><p>If the HoPE approves the recommendation, the Procurement Section prepares the Purchase Order/Contract and Notice to Proceed (NTP) for HoPE approval.</p></article>
      <article class="workflow-step" style="--step-index:19"><div class="workflow-step-top"><span class="workflow-step-number">19</span><strong>Contract Disapproved</strong></div><p>If the HoPE disapproves the Purchase Order/Contract, the entire project is considered cancelled.</p><span class="workflow-outcome disapproved">Outcome: Project cancelled</span></article>
      <article class="workflow-step" style="--step-index:20"><div class="workflow-step-top"><span class="workflow-step-number">20</span><strong>Contract Approved &amp; Issued</strong></div><p>If approved, copies of the Purchase Order/Contract are emailed or forwarded to the winning bidder, who may begin obligations under the contract terms.</p><span class="workflow-outcome approved">Outcome: Winning bidder may proceed</span></article>
      <article class="workflow-step" style="--step-index:21"><div class="workflow-step-top"><span class="workflow-step-number">21</span><strong>Recordkeeping</strong></div><p>The Procurement Section keeps the received approved copies of the Purchase Order/Contract and its NTP.</p></article>
    </div>
    <div class="workflow-finish"><strong>Process completion:</strong> Approved contract documents and the Notice to Proceed are issued to the winning bidder, and the Procurement Section retains the approved copies for its records.</div>
  </div>
</div>
<script>
(function(){
  const root=document.querySelector('.procurement-process-workflow');
  if(!root)return;
  const steps=Array.from(root.querySelectorAll('.workflow-step'));
  const replay=root.querySelector('#replayProcurementWorkflow');
  if(!steps.length)return;
  const reducedMotion=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let timer=null;
  function play(){
    if(timer)window.clearInterval(timer);
    steps.forEach(step=>step.classList.remove('is-active','is-complete'));
    let index=0;
    if(reducedMotion){steps.forEach(step=>step.classList.add('is-complete'));return;}
    function advance(){
      steps.forEach((step,i)=>{
        step.classList.toggle('is-active',i===index);
        if(i<index)step.classList.add('is-complete');
      });
      index++;
      if(index>=steps.length){
        window.clearInterval(timer);
        timer=null;
        steps.forEach(step=>step.classList.remove('is-active'));
        steps.forEach(step=>step.classList.add('is-complete'));
      }
    }
    advance();
    timer=window.setInterval(advance,850);
  }
  if(replay)replay.addEventListener('click',play);
  play();
})();
</script>
<script>const ppmpEndUserData = <?=json_encode(array_map(static fn($row)=>['name'=>(string)$row['end_user'],'amount'=>(float)$row['total_amount']],$ppmpEndUserTotals),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
(function(){
  const canvas=document.getElementById('ppmpEndUserBarChart');
  if(!canvas || !ppmpEndUserData.length) return;
  const ctx=canvas.getContext('2d');
  const colors=['#647ba5','#64866d','#8170a8','#b78361','#a66f87','#5f8d84','#a58b4d','#5f879a','#9a7560','#718096','#7c8f6b','#987fa8'];
  const total=ppmpEndUserData.reduce((sum,item)=>sum+item.amount,0);
  document.querySelectorAll('.dashboard-enduser-legend-swatch').forEach((el,i)=>{el.style.backgroundColor=colors[i%colors.length];});
  function draw(){
    const dpr=window.devicePixelRatio||1;
    const width=Math.max(600,ppmpEndUserData.length*100+80);
    const height=390;
    canvas.width=width*dpr;canvas.height=height*dpr;canvas.style.width=width+'px';canvas.style.height=height+'px';
    ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,width,height);
    const left=58,right=24,top=34,bottom=125;
    const plotW=width-left-right,plotH=height-top-bottom;
    ctx.font='11px Arial, sans-serif';ctx.textAlign='right';ctx.textBaseline='middle';
    for(let tick=0;tick<=100;tick+=20){
      const y=top+plotH-(tick/100)*plotH;
      ctx.beginPath();ctx.moveTo(left,y);ctx.lineTo(width-right,y);ctx.strokeStyle='#e8edf2';ctx.lineWidth=1;ctx.stroke();
      ctx.fillStyle='#64748b';ctx.fillText(tick+'%',left-10,y);
    }
    const slot=plotW/ppmpEndUserData.length;
    const barW=Math.max(12,Math.min(48,slot*.58));
    ppmpEndUserData.forEach((item,i)=>{
      const pct=total>0?(item.amount/total*100):0;
      const x=left+slot*i+(slot-barW)/2;
      const barH=plotH*(pct/100);
      const y=top+plotH-barH;
      ctx.fillStyle=colors[i%colors.length];
      ctx.beginPath();
      const radius=Math.min(5,barW/2,barH/2);
      ctx.moveTo(x,top+plotH);ctx.lineTo(x,y+radius);ctx.quadraticCurveTo(x,y,x+radius,y);
      ctx.lineTo(x+barW-radius,y);ctx.quadraticCurveTo(x+barW,y,x+barW,y+radius);
      ctx.lineTo(x+barW,top+plotH);ctx.closePath();ctx.fill();
      ctx.fillStyle='#172033';ctx.font='700 11px Arial, sans-serif';ctx.textAlign='center';ctx.textBaseline='bottom';
      ctx.fillText(pct.toFixed(1)+'%',x+barW/2,Math.max(13,y-5));
      ctx.save();ctx.translate(x+barW/2,top+plotH+13);ctx.rotate(-Math.PI/4);
      ctx.textAlign='right';ctx.textBaseline='middle';ctx.fillStyle='#475569';ctx.font='11px Arial, sans-serif';
      let label=String(item.name||'End-user');
      while(label.length>8 && ctx.measureText(label).width>145) label=label.slice(0,-2);
      if(label!==item.name) label=label.replace(/\.*$/,'')+'…';
      ctx.fillText(label,0,0,145);ctx.restore();
    });
  }
  draw();
  if(window.ResizeObserver){new ResizeObserver(draw).observe(canvas.parentElement);}
  else window.addEventListener('resize',draw);
})();
window.addEventListener("load",function(){var saved=sessionStorage.getItem("dashboardRecentPpmpScrollY");if(saved!==null){sessionStorage.removeItem("dashboardRecentPpmpScrollY");window.scrollTo(0,Number(saved)||0);}});</script><?php pageEnd();