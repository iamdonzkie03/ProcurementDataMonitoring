<?php
require_once __DIR__.'/../config/config.php';
requireLogin(); 
require_once __DIR__.'/../app/layout.php';$pdo=db();
$canEditApp=hasRole(['Administrator','Editor']);
$pdo->exec("CREATE TABLE IF NOT EXISTS app_consolidated_edits (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, fiscal_year SMALLINT NOT NULL, row_key CHAR(64) NOT NULL, project_title VARCHAR(500) NOT NULL DEFAULT '', bid_evaluation_criteria TEXT NULL, procurement_strategy TEXT NULL, unit_settings LONGTEXT NULL, updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_app_edit_year_row (fiscal_year,row_key), INDEX idx_app_edit_year (fiscal_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$unitSettingsColumn=$pdo->query("SHOW COLUMNS FROM app_consolidated_edits LIKE 'unit_settings'")->fetch();
if(!$unitSettingsColumn){$pdo->exec("ALTER TABLE app_consolidated_edits ADD COLUMN unit_settings LONGTEXT NULL");}
if(!function_exists('formatAppProcurementDate')){function formatAppProcurementDate($value){$value=trim((string)$value);if($value==='')return '';if(preg_match('/^(\d{1,2})\/(\d{4})$/',$value,$m)){return date('F Y',mktime(0,0,0,(int)$m[1],1,(int)$m[2]));}$timestamp=strtotime($value);return $timestamp!==false?date('F Y',$timestamp):$value;}}
$currentYear=(int)date('Y');$availableYears=array_map('intval',$pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
if(!in_array($currentYear,$availableYears,true))$availableYears[]=$currentYear;rsort($availableYears);$year=(int)($_GET['year']??$currentYear);
	if(!in_array($year,$availableYears,true))$year=$currentYear;$q=trim((string)($_GET['q']??$_POST['q']??''));
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_app_edits'])){
 if(!$canEditApp){http_response_code(403);exit('403 - Access denied');}
 checkCsrf();
 $year=(int)($_POST['year']??$currentYear);
 if(!in_array($year,$availableYears,true))$year=$currentYear;
 $save=$pdo->prepare("INSERT INTO app_consolidated_edits(fiscal_year,row_key,project_title,bid_evaluation_criteria,procurement_strategy,unit_settings,updated_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE project_title=VALUES(project_title),bid_evaluation_criteria=VALUES(bid_evaluation_criteria),procurement_strategy=VALUES(procurement_strategy),unit_settings=VALUES(unit_settings),updated_by=VALUES(updated_by)");
 $u=currentUser();
 foreach((array)($_POST['app_rows']??[]) as $key=>$v){
  if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key)||!is_array($v))continue;
  $unitSettings=[]; foreach((array)($v['units']??[]) as $unitId=>$unitValue){if(!ctype_digit((string)$unitId)||!is_array($unitValue))continue; $unitSettings[(string)$unitId]=['mode'=>(string)($unitValue['mode']??''),'epa'=>(string)($unitValue['epa']??''),'start'=>(string)($unitValue['start']??''),'end'=>(string)($unitValue['end']??''),'funds'=>(string)($unitValue['funds']??''),'strategy'=>(string)($unitValue['strategy']??''),'remarks'=>(string)($unitValue['remarks']??''),'select_mode'=>(string)($unitValue['select_mode']??'')==='1','select_epa'=>(string)($unitValue['select_epa']??'')==='1','select_start'=>(string)($unitValue['select_start']??'')==='1','select_end'=>(string)($unitValue['select_end']??'')==='1'];} $save->execute([$year,$key,mb_substr(trim((string)($v['title']??'')),0,500),trim((string)($v['criteria']??'')),trim((string)($v['strategy']??'')),json_encode($unitSettings,JSON_UNESCAPED_UNICODE),isset($u['id'])?(int)$u['id']:null]);
 }
 flash('success','Consolidated APP edits saved successfully.');
 $postedQ=trim((string)($_POST['q']??$q));header('Location: app.php?year='.$year.($postedQ!==''?'&q='.rawurlencode($postedQ):'').'#consolidated-app-table');exit;
}
		$sql='SELECT src.fiscal_year, src.item_key, MIN(src.item_name) item_name, src.unit, src.category_id, src.category, src.unit_price, SUM(src.area_qty) total_qty, SUM(src.area_abc) total_abc, MIN(src.description) description, MIN(src.procurement_mode) procurement_mode, MIN(src.epa) epa, MIN(src.start_procurement) start_procurement, MIN(src.end_procurement) end_procurement, MIN(src.source_of_funds) source_of_funds, MIN(src.remarks) remarks, GROUP_CONCAT(CONCAT(src.area_name, " (", FORMAT(src.area_qty, 2), ")") ORDER BY src.area_name SEPARATOR ", ") areas FROM (SELECT p.fiscal_year, TRIM(LOWER(p.item_name)) item_key, MIN(p.item_name) item_name, p.unit, c.id category_id, c.name category, p.unit_price, p.area_id, a.name area_name, SUM(p.quantity) area_qty, SUM(p.quantity*p.unit_price) area_abc, MIN(p.description) description, MIN(p.procurement_mode) procurement_mode, MIN(p.preprocurement_conference) epa, MIN(p.start_procurement) start_procurement, MIN(p.end_procurement) end_procurement, MIN(p.source_of_funds) source_of_funds, MIN(p.remarks) remarks FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=?';
		$args=[$year];
				if($q!==''){$sql.=' AND (p.item_name LIKE ? OR c.name LIKE ? OR a.name LIKE ?)';
					$args=[...$args,"%$q%","%$q%","%$q%"];}$sql.=' GROUP BY p.fiscal_year, TRIM(LOWER(p.item_name)), p.unit, c.id, c.name, p.unit_price, p.area_id, a.name) src GROUP BY src.fiscal_year, src.item_key, src.unit, src.category_id, src.category, src.unit_price ORDER BY item_name, src.unit_price';
					$st=$pdo->prepare($sql);
					$st->execute($args);
					$rows=$st->fetchAll();
$unitStmt=$pdo->prepare('SELECT p.fiscal_year,TRIM(LOWER(p.item_name)) item_key,p.unit,c.id category_id,p.unit_price,p.area_id,a.name area_name,MIN(p.procurement_mode) procurement_mode,MIN(p.preprocurement_conference) epa,MIN(p.start_procurement) start_procurement,MIN(p.end_procurement) end_procurement,MIN(p.source_of_funds) source_of_funds,MIN(p.remarks) remarks FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=? GROUP BY p.fiscal_year,TRIM(LOWER(p.item_name)),p.unit,c.id,p.unit_price,p.area_id,a.name');
$unitStmt->execute([$year]);$unitRows=$unitStmt->fetchAll();$unitsByRow=[];
foreach($unitRows as $ur){$rk=hash('sha256',json_encode([$year,(string)$ur['item_key'],(string)$ur['unit'],(string)$ur['category_id'],number_format((float)$ur['unit_price'],4,'.','')],JSON_UNESCAPED_UNICODE));$unitsByRow[$rk][]=$ur;}
$editStmt=$pdo->prepare('SELECT row_key,project_title,bid_evaluation_criteria,procurement_strategy,unit_settings FROM app_consolidated_edits WHERE fiscal_year=?');
$editStmt->execute([$year]);
$savedEdits=[];
foreach($editStmt->fetchAll() as $savedRow){$savedRow['unit_settings']=json_decode((string)($savedRow['unit_settings']??''),true)?:[];$savedEdits[$savedRow['row_key']]=$savedRow;}

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
  <p style="font-size:12px;color:#6b7280">Edit only Project Title, Criteria for Bid Evaluation, and Procurement Strategy or Tools. All other fields are read-only. Mode, EPA, and dates are listed per end-user with selection checkboxes. Procurement Strategy is selected per end-user. Editing is available to Administrators and Editors.</p>
  <?php foreach(flashes() as $f): ?><div class="alert <?=e($f['type'])?>"><?=e($f['message'])?></div><?php endforeach; ?>
  <div class="table-wrap" id="consolidated-app-table">
  <form method="post">
  <input type="hidden" name="csrf" value="<?=e(csrf())?>">
  <input type="hidden" name="year" value="<?=$year?>">
  <input type="hidden" name="q" value="<?=e($q)?>">
  <table class="table app-edit-table">
  <thead><tr><th>#</th><th>Project Title</th><th>End-User or Implementing Unit</th><th>General Description of the Project</th><th>Mode of Procurement</th><th>To be covered by an Early Procurement Activity? (Yes/No)</th><th>Criteria for Bid Evaluation (including Sustainability and Domestic Preference)</th><th>Start of Procurement Activity</th><th>End of Procurement Activity</th><th>Source of Fund</th><th>Estimated Budget / Approved Budget for the Contract (Php)</th><th>PROCUREMENT STRATEGY OR TOOLS</th><th>REMARKS<br>(Other relevant description of the procurement project, if applicable)</th></tr></thead><tbody>
  <?php foreach($rows as $i=>$r): $rowKey=hash('sha256',json_encode([$year,strtolower(trim((string)$r['item_name'])),(string)$r['unit'],(string)$r['category_id'],number_format((float)$r['unit_price'],4,'.','')],JSON_UNESCAPED_UNICODE)); $saved=$savedEdits[$rowKey]??null; $title=$saved!==null?(string)$saved['project_title']:''; $criteria=$saved!==null?(string)($saved['bid_evaluation_criteria']??''):''; $strategy=$saved!==null?(string)($saved['procurement_strategy']??''):''; ?>
  <tr><td><?=($i+1)?></td>
  <td><?php if($canEditApp): ?><input class="app-edit-input" type="text" maxlength="500" name="app_rows[<?=e($rowKey)?>][title]" value="<?=e($title)?>"><?php else: ?><?=e($title)?><?php endif; ?></td>
  <td><?=e($r['areas'])?></td>
  <td><strong><?=e(trim((string)$r['item_name']))?></strong><?php if(!empty($r['description'])): ?><br><?=nl2br(e(trim((string)$r['description'])))?><?php endif; ?></td>
  <td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><label><input type="checkbox" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][select_mode]" value="1" <?=!empty($us['select_mode'])?'checked':''?>> Select</label><div><?=e($us['mode']??$ur['procurement_mode']??'')?></div><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][mode]" value="<?=e($us['mode']??$ur['procurement_mode']??'')?>"><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][epa]" value="<?=e($us['epa']??$ur['epa']??'')?>"><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][start]" value="<?=e($us['start']??(!empty($ur['start_procurement'])?formatAppProcurementDate($ur['start_procurement']):''))?>"><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][end]" value="<?=e($us['end']??(!empty($ur['end_procurement'])?formatAppProcurementDate($ur['end_procurement']):''))?>"><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][funds]" value="<?=e($us['funds']??$ur['source_of_funds']??'')?>"><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][remarks]" value="<?=e($us['remarks']??$ur['remarks']??'')?>"></div><?php endforeach; ?></td><td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; $epaValue=(string)($us['epa']??$ur['epa']??''); ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><?php if($canEditApp): ?><div class="app-epa-radio-group"><label><input type="radio" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][epa]" value="Yes" <?=strcasecmp($epaValue,'Yes')===0?'checked':''?>> Yes</label><label><input type="radio" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][epa]" value="No" <?=strcasecmp($epaValue,'No')===0?'checked':''?>> No</label></div><?php else: ?><?=e($epaValue)?><?php endif; ?><input type="hidden" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][select_epa]" value="1"></div><?php endforeach; ?></td>
  <td><?php if($canEditApp): ?><select class="app-edit-input" name="app_rows[<?=e($rowKey)?>][criteria]"><option value="">Select criteria</option><?php foreach(['LCRB/SCRB','MARB','MEARB','HRRB/SHRB'] as $criterion): ?><option value="<?=e($criterion)?>" <?=$criteria===$criterion?'selected':''?>><?=e($criterion)?></option><?php endforeach; ?></select><?php else: ?><?=e($criteria)?><?php endif; ?></td>
  <td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><label><input type="checkbox" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][select_start]" value="1" <?=!empty($us['select_start'])?'checked':''?>> Select</label><div><?=e(!empty($us['start'])?$us['start']:(!empty($ur['start_procurement'])?date('m/Y',strtotime($ur['start_procurement'])):''))?></div></div><?php endforeach; ?></td><td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><label><input type="checkbox" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][select_end]" value="1" <?=!empty($us['select_end'])?'checked':''?>> Select</label><div><?=e(!empty($us['end'])?$us['end']:(!empty($ur['end_procurement'])?date('m/Y',strtotime($ur['end_procurement'])):''))?></div></div><?php endforeach; ?></td>
  <td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><div><?=e($us['funds']??$ur['source_of_funds']??'')?></div></div><?php endforeach; ?></td><td style="text-align:right;white-space:nowrap">₱<?=number_format((float)$r['total_abc'],2)?></td>
  <td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><?php if($canEditApp): ?><select class="app-edit-input" name="app_rows[<?=e($rowKey)?>][units][<?=$ur['area_id']?>][strategy]"><option value="">Select strategy</option><?php foreach(['LCA / LCCA','Subcontracting','Multi-Year Contract','Design and Build Scheme','Procurement Agent','Framework Agreement','Pooled Procurement','Renewal or Regular & Recurring Services','Warehousing & Inventory Services','None or N/A'] as $opt): ?><option value="<?=e($opt)?>" <?=($us['strategy']??'')===$opt?'selected':''?>><?=e($opt)?></option><?php endforeach; ?></select><?php else: ?><?=e($us['strategy']??'')?><?php endif; ?></div><?php endforeach; ?></td><td><?php foreach(($unitsByRow[$rowKey]??[]) as $ur): $us=$saved['unit_settings'][(string)$ur['area_id']]??[]; ?><div class="app-unit-detail"><strong><?=e($ur['area_name'])?></strong><div><?=e($us['remarks']??$ur['remarks']??'')?></div></div><?php endforeach; ?></td></tr>
  <?php endforeach; if(!$rows): ?><tr><td colspan="13" class="empty">No PPMP data for FY <?=$year?>.</td></tr><?php endif; ?>
  </tbody><tfoot><tr><th colspan="10" style="text-align:right">Total Approved Budget for the Contract (ABC)</th><th style="text-align:right">₱<?=number_format($grandAbc,2)?></th><th colspan="2"></th></tr></tfoot></table>
  <?php if($canEditApp&&$rows): ?><div style="padding-top:14px"><button class="btn btn-primary" type="submit" name="save_app_edits" value="1">Save APP Changes</button></div><?php endif; ?>
  </form></div>
  <style>.consolidated-app-panel .app-edit-table{min-width:1900px;table-layout:fixed}.consolidated-app-panel .app-edit-table th{background:#eaf0f8;vertical-align:middle;min-width:120px;white-space:normal;line-height:1.35}.consolidated-app-panel .app-edit-table th:nth-child(1){min-width:45px;width:45px}.consolidated-app-panel .app-edit-table th:nth-child(2){min-width:220px;width:220px}.consolidated-app-panel .app-edit-table th:nth-child(3){min-width:200px;width:200px}.consolidated-app-panel .app-edit-table th:nth-child(4){min-width:230px;width:230px}.consolidated-app-panel .app-edit-table th:nth-child(7),.consolidated-app-panel .app-edit-table th:nth-child(12){min-width:260px;width:260px}.consolidated-app-panel .app-edit-table td{vertical-align:top;white-space:pre-wrap;overflow-wrap:anywhere}.consolidated-app-panel .app-unit-detail{padding:6px 0;border-bottom:1px solid #dbe2ea;display:flex;flex-direction:column;gap:4px}.consolidated-app-panel .app-unit-detail:last-child{border-bottom:0}.consolidated-app-panel .app-unit-detail label{font-size:12px;display:flex;align-items:center;gap:5px}.consolidated-app-panel .app-epa-radio-group{display:flex;align-items:center;gap:14px;padding:4px 0}.consolidated-app-panel .app-epa-radio-group label{display:inline-flex;align-items:center;gap:5px;font-size:13px;cursor:pointer}.consolidated-app-panel .app-epa-radio-group input[type=radio]{width:auto;min-width:0;margin:0}.consolidated-app-panel .app-edit-input{width:100%;min-width:180px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:5px;padding:7px;font:inherit;background:#fff}.consolidated-app-panel textarea.app-edit-input{min-width:230px;resize:vertical}</style>
	</div>
</div><?php pageEnd();
