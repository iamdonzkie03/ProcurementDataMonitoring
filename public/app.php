<?php
require_once __DIR__.'/../config/config.php';
requireLogin(); 
require_once __DIR__.'/../app/layout.php';$pdo=db();$currentYear=(int)date('Y');$availableYears=array_map('intval',$pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
if(!in_array($currentYear,$availableYears,true))$availableYears[]=$currentYear;rsort($availableYears);$year=(int)($_GET['year']??$currentYear);
	if(!in_array($year,$availableYears,true))$year=$currentYear;$q=trim($_GET['q']??'');
		$sql='SELECT src.fiscal_year, src.item_key, MIN(src.item_name) item_name, src.unit, src.category_id, src.category, src.unit_price, SUM(src.area_qty) total_qty, SUM(src.area_abc) total_abc, GROUP_CONCAT(CONCAT(src.area_name, " (", FORMAT(src.area_qty, 2), ")") ORDER BY src.area_name SEPARATOR ", ") areas FROM (SELECT p.fiscal_year, TRIM(LOWER(p.item_name)) item_key, MIN(p.item_name) item_name, p.unit, c.id category_id, c.name category, p.unit_price, p.area_id, a.name area_name, SUM(p.quantity) area_qty, SUM(p.quantity*p.unit_price) area_abc FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=?';
		$args=[$year];
				if($q!==''){$sql.=' AND (p.item_name LIKE ? OR c.name LIKE ? OR a.name LIKE ?)';
					$args=[...$args,"%$q%","%$q%","%$q%"];}$sql.=' GROUP BY p.fiscal_year, TRIM(LOWER(p.item_name)), p.unit, c.id, c.name, p.unit_price, p.area_id, a.name) src GROUP BY src.fiscal_year, src.item_key, src.unit, src.category_id, src.category, src.unit_price ORDER BY item_name, src.unit_price';
					$st=$pdo->prepare($sql);
					$st->execute($args);
					$rows=$st->fetchAll();
					$grandQty=0;
					$grandAbc=0;
					foreach($rows as $r){
						$grandQty+=(float)$r['total_qty'];$grandAbc+=(float)$r['total_abc'];}pageStart('Consolidated Annual Procurement Plan');
?>
<div class="consolidated-app-panel">
	<style>
		.consolidated-app-panel .app-panel-header{display:grid;grid-template-columns:1fr minmax(320px,2fr) 1fr;align-items:start;gap:12px;margin-bottom:16px}
		.consolidated-app-panel .app-controls-left{display:flex;justify-content:flex-start;align-items:center;gap:8px;flex-wrap:wrap}
		.consolidated-app-panel .app-controls-left form{display:flex;align-items:center;gap:8px;margin:0}
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
				<button class="btn" type="submit">View APP</button>
			</form>
		</div>
		<div class="app-summary-center">
			<span class="badge"><?=count($rows)?> consolidated lines</span>
			<div class="app-budget-total">Total Approved Budget for the Contract (ABC): ₱<?=number_format($grandAbc,2)?></div>
		</div>
		<div class="app-controls-right">
			<a class="btn" href="print_app.php?year=<?=$year?><?= $q!=='' ? '&amp;q='.rawurlencode($q) : '' ?>" target="_blank" rel="noopener">🖨 Print APP</a>
		</div>
	</div>
		<p style="font-size:12px;color:#6b7280">Equivalent items are consolidated using normalized item name + unit + category + unit price. The Contributing Areas/Units column shows each Area/Unit with its requested quantity; consolidated quantity and total ABC are summed.</p>
		<div class="table-wrap">
			<table class="table">
				<tr><th>Item</th>
					<th>Category</th><th>Unit</th>
					<th>Consolidated Qty</th>
					<th>Unit Price</th>
					<th>Total ABC</th>
					<th>Contributing Areas/Units</th>
				</tr>
				<?php foreach($rows as $r):?>
				<tr>
					<td><b><?=e($r['item_name'])?></b></td>
					<td><?=e($r['category'])?></td><td><?=e($r['unit'])?></td>
					<td><?=number_format($r['total_qty'],2)?></td>
					<td>₱<?=number_format($r['unit_price'],2)?></td>
					<td><b>₱<?=number_format($r['total_abc'],2)?></b></td>
					<td><?=e($r['areas'])?></td>
				</tr><?php endforeach;?><?php if(!$rows):?>
				<tr><td colspan="7" class="empty">No PPMP data for FY <?=$year?>.</td></tr><?php endif;?>
			</table>
	</div>
</div><?php pageEnd();
