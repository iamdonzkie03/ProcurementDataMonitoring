<?php
require_once __DIR__.'/../config/config.php';
requireLogin(); 
require_once __DIR__.'/../app/layout.php';$pdo=db();$currentYear=(int)date('Y');$availableYears=array_map('intval',$pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
if(!in_array($currentYear,$availableYears,true))$availableYears[]=$currentYear;rsort($availableYears);$year=(int)($_GET['year']??$currentYear);
	if(!in_array($year,$availableYears,true))$year=$currentYear;$q=trim($_GET['q']??'');
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_app_edits'])){
 if(!$canEditApp){http_response_code(403);exit('403 - Access denied');}
 checkCsrf();
 $year=(int)($_POST['year']??$currentYear);
 if(!in_array($year,$availableYears,true))$year=$currentYear;
 $save=$pdo->prepare("INSERT INTO app_consolidated_edits(fiscal_year,row_key,project_title,bid_evaluation_criteria,procurement_strategy,updated_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE project_title=VALUES(project_title),bid_evaluation_criteria=VALUES(bid_evaluation_criteria),procurement_strategy=VALUES(procurement_strategy),updated_by=VALUES(updated_by)");
 $u=currentUser();
 foreach((array)($_POST['app_rows']??[]) as $key=>$v){
  if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key)||!is_array($v))continue;
  $save->execute([$year,$key,mb_substr(trim((string)($v['title']??'')),0,500),trim((string)($v['criteria']??'')),trim((string)($v['strategy']??'')),isset($u['id'])?(int)$u['id']:null]);
 }
 flash('success','Consolidated APP edits saved successfully.');
 header('Location: app.php?year='.$year.($q!==''?'&q='.rawurlencode($q):'').'#consolidated-app-table');exit;
}
		$sql='SELECT src.fiscal_year, src.item_key, MIN(src.item_name) item_name, src.unit, src.category_id, src.category, src.unit_price, SUM(src.area_qty) total_qty, SUM(src.area_abc) total_abc, GROUP_CONCAT(CONCAT(src.area_name, " (", FORMAT(src.area_qty, 2), ")") ORDER BY src.area_name SEPARATOR ", ") areas FROM (SELECT p.fiscal_year, TRIM(LOWER(p.item_name)) item_key, MIN(p.item_name) item_name, p.unit, c.id category_id, c.name category, p.unit_price, p.area_id, a.name area_name, SUM(p.quantity) area_qty, SUM(p.quantity*p.unit_price) area_abc FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=?';
		$args=[$year];
				if($q!==''){$sql.=' AND (p.item_name LIKE ? OR c.name LIKE ? OR a.name LIKE ?)';
					$args=[...$args,"%$q%","%$q%","%$q%"];}$sql.=' GROUP BY p.fiscal_year, TRIM(LOWER(p.item_name)), p.unit, c.id, c.name, p.unit_price, p.area_id, a.name) src GROUP BY src.fiscal_year, src.item_key, src.unit, src.category_id, src.category, src.unit_price ORDER BY item_name, src.unit_price';
					$st=$pdo->prepare($sql);
					$st->execute($args);
					$rows=$st->fetchAll();
$editStmt=$pdo->prepare('SELECT row_key,project_title,bid_evaluation_criteria,procurement_strategy FROM app_consolidated_edits WHERE fiscal_year=?');
$editStmt->execute([$year]);
$savedEdits=[];
foreach($editStmt->fetchAll() as $savedRow){$savedEdits[$savedRow['row_key']]=$savedRow;}

					$grandQty=0;
					$grandAbc=0;
					foreach($rows as $r){
						$grandQty+=(float)$r['total_qty'];$grandAbc+=(float)$r['total_abc'];}pageStart('Consolidated Annual Procurement Plan');
?>
<div class="panel consolidated-app-panel">
	<style>
		.consolidated-app-panel .app-panel-header{display:grid;grid-template-columns:1fr minmax(320px,2fr) 1fr;align-items:start;gap:12px;margin-bottom:16px}
		.consolidated-app-panel .app-controls-left{display:flex;justify-content:flex-start;align-items:center;gap:8px;flex-wrap:wrap}
		.consolidated-app-panel .app-controls-left form{display:flex;align-items:center;gap:8px;margin:0}
		.consolidated-app-panel .app-action-button{display:inline-flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important;width:120px!important;min-width:120px!important;max-width:120px!important;height:38px!important;min-height:38px!important;max-height:38px!important;flex:0 0 120px!important;margin:0!important;padding:0 8px!important;border:0!important;font-family:inherit!important;font-size:13px!important;font-weight:600!important;line-height:18px!important;text-align:center!important;white-space:nowrap!important;vertical-align:middle!important}
		.consolidated-app-panel .app-summary-center{display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:8px;padding-top:2px}
		.consolidated-app-panel .app-summary-center .badge{display:inline-flex;align-items:center;justify-content:center}
		.consolidated-app-panel .app-budget-total{font-size:16px;font-weight:700;line-height:1.35}
		.consolidated-app-panel .app-controls-right{display:flex;justify-content:flex-end;align-items:flex-start}
		@media(max-width:900px){.consolidated-app-panel .app-panel-header{grid-template-columns:1fr;gap:10px}.consolidated-app-panel .app-summary-center{grid-row:2}.consolidated-app-panel .app-controls-right{justify-content:flex-start}}
	</style>
	<div class="app-panel-header">
		<div class="app-controls-left">
			<form>
				<select class="input" name="year" aria-label="Calendar Year"><?php foreach($availableYears as $availableYear):?><option value="<?=$availableYear?>" <?=$year===$availableYear?'selected':''?>><?=$availableYear?></option><?php endforeach;?></select>
				<button class="btn app-action-button" type="submit">View APP</button>
			</form>
		</div>
		<div class="app-summary-center">
			<span class="badge"><?=count($rows)?> consolidated lines</span>
			<div class="app-budget-total">Total Approved Budget for the Contract (ABC): ₱<?=number_format($grandAbc,2)?></div>
		</div>
		<div class="app-controls-right">
			<a class="btn app-action-button" href="print_app.php?year=<?=$year?><?= $q!=='' ? '&amp;q='.rawurlencode($q) : '' ?>" target="_blank" rel="noopener">🖨 Print APP</a>
		</div>
	</div>
		<p style="font-size:12px;color:#6b7280">Equivalent items are consolidated using normalized item name + unit + category + unit price. The Contributing Areas/Units column shows each Area/Unit with its requested quantity; consolidated quantity and total ABC are summed.</p>
  <p style="font-size:12px;color:#6b7280">Edit only Project Title, Criteria for Bid Evaluation, and Procurement Strategy or Tools. All other fields are read-only. Editing is available to Administrators and Editors.</p>
  <?php foreach(flashes() as $f): ?><div class="alert <?=e($f['type'])?>"><?=e($f['message'])?></div><?php endforeach; ?>
  <div class="table-wrap" id="consolidated-app-table">
  <form method="post">
  <input type="hidden" name="csrf" value="<?=e(csrf())?>">
  <input type="hidden" name="year" value="<?=$year?>">
  <input type="hidden" name="q" value="<?=e($q)?>">
  <table class="table app-edit-table">
  <thead><tr><th>#</th><th>Project Title</th><th>End-User or Implementing Unit</th><th>General Description of the Project</th><th>Mode of Procurement</th><th>To be covered by an Early Procurement Activity? (Yes/No)</th><th>Criteria for Bid Evaluation (including Sustainability and Domestic Preference)</th><th>Start of Procurement Activity</th><th>End of Procurement Activity</th><th>Source of Fund</th><th>Estimated Budget / Approved Budget for the Contract (Php)</th><th>PROCUREMENT STRATEGY OR TOOLS</th><th>REMARKS<br>(Other relevant description of the procurement project, if applicable)</th></tr></thead><tbody>
  <?php foreach($rows as $i=>$r): $rowKey=hash('sha256',json_encode([$year,strtolower(trim((string)$r['item_name'])),(string)$r['unit'],(string)$r['category_id'],number_format((float)$r['unit_price'],4,'.','')],JSON_UNESCAPED_UNICODE)); $saved=$savedEdits[$rowKey]??null; $title=$saved!==null?(string)$saved['project_title']:(string)$r['item_name']; $criteria=$saved!==null?(string)($saved['bid_evaluation_criteria']??''):''; $strategy=$saved!==null?(string)($saved['procurement_strategy']??''):''; ?>
  <tr><td><?=($i+1)?></td>
  <td><?php if($canEditApp): ?><input class="app-edit-input" type="text" maxlength="500" name="app_rows[<?=e($rowKey)?>][title]" value="<?=e($title)?>"><?php else: ?><?=e($title)?><?php endif; ?></td>
  <td><?=e($r['areas'])?></td>
  <td><?=e(trim((string)($r['description']??''))."\nConsolidated quantity: ".number_format((float)$r['total_qty'],2)." ".trim((string)$r['unit']))?></td>
  <td><?=e($r['procurement_mode']??'')?></td><td><?=e($r['epa']??'')?></td>
  <td><?php if($canEditApp): ?><textarea class="app-edit-input" rows="2" name="app_rows[<?=e($rowKey)?>][criteria]"><?=e($criteria)?></textarea><?php else: ?><?=e($criteria)?><?php endif; ?></td>
  <td><?=e(!empty($r['start_procurement'])?date('m/Y',strtotime($r['start_procurement'])):'')?></td><td><?=e(!empty($r['end_procurement'])?date('m/Y',strtotime($r['end_procurement'])):'')?></td>
  <td><?=e($r['source_of_funds']??'')?></td><td style="text-align:right;white-space:nowrap">₱<?=number_format((float)$r['total_abc'],2)?></td>
  <td><?php if($canEditApp): ?><textarea class="app-edit-input" rows="2" name="app_rows[<?=e($rowKey)?>][strategy]"><?=e($strategy)?></textarea><?php else: ?><?=e($strategy)?><?php endif; ?></td><td><?=e($r['remarks']??'')?></td></tr>
  <?php endforeach; if(!$rows): ?><tr><td colspan="13" class="empty">No PPMP data for FY <?=$year?>.</td></tr><?php endif; ?>
  </tbody><tfoot><tr><th colspan="10" style="text-align:right">Total Approved Budget for the Contract (ABC)</th><th style="text-align:right">₱<?=number_format($grandAbc,2)?></th><th colspan="2"></th></tr></tfoot></table>
  <?php if($canEditApp&&$rows): ?><div style="padding-top:14px"><button class="btn btn-primary" type="submit" name="save_app_edits" value="1">Save APP Changes</button></div><?php endif; ?>
  </form></div>
  <style>.consolidated-app-panel .app-edit-table{min-width:1900px;table-layout:fixed}.consolidated-app-panel .app-edit-table th{background:#eaf0f8;vertical-align:middle;min-width:120px;white-space:normal;line-height:1.35}.consolidated-app-panel .app-edit-table th:nth-child(1){min-width:45px;width:45px}.consolidated-app-panel .app-edit-table th:nth-child(2){min-width:220px;width:220px}.consolidated-app-panel .app-edit-table th:nth-child(3){min-width:200px;width:200px}.consolidated-app-panel .app-edit-table th:nth-child(4){min-width:230px;width:230px}.consolidated-app-panel .app-edit-table th:nth-child(7),.consolidated-app-panel .app-edit-table th:nth-child(12){min-width:260px;width:260px}.consolidated-app-panel .app-edit-table td{vertical-align:top;white-space:pre-wrap;overflow-wrap:anywhere}.consolidated-app-panel .app-edit-input{width:100%;min-width:180px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:5px;padding:7px;font:inherit;background:#fff}.consolidated-app-panel textarea.app-edit-input{min-width:230px;resize:vertical}</style>
	</div>
</div><?php pageEnd();
