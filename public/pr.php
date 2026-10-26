<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer']); require_once __DIR__.'/../app/layout.php'; $pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    requireRole(['Administrator','Editor']); checkCsrf();
    try{
        $pdo->beginTransaction();
        $action=$_POST['action']??'create';

        if($action==='update'){
            $prId=(int)$_POST['pr_id'];
            $st=$pdo->prepare('SELECT * FROM purchase_requests WHERE id=? AND status="Draft"');
            $st->execute([$prId]); $pr=$st->fetch();
            if(!$pr) throw new RuntimeException('Only Draft Purchase Requests can be edited.');

            $year=(int)$_POST['fiscal_year'];
            $area=(int)$_POST['area_id'];
            $items=$_POST['items']??[]; $itemQuantities=$_POST['qty_display']??[];
            if(!$items) throw new RuntimeException('Select at least one PPMP item.');

            $up=$pdo->prepare('UPDATE purchase_requests SET fiscal_year=?,area_id=?,purpose=?,requested_by=?,status=? WHERE id=? AND status="Draft"');
            $up->execute([$year,$area,trim($_POST['purpose']),trim($_POST['requested_by']),$_POST['status']??'Draft',$prId]);

            
            $pdo->prepare('DELETE FROM purchase_request_items WHERE pr_id=?')->execute([$prId]);

            $ins=$pdo->prepare('INSERT INTO purchase_request_items(pr_id,ppmp_item_id,quantity,unit_price,unit_id) VALUES(?,?,?,?,?)');
            foreach($items as $ppmpId=>$qty){
                $qty=(float)($itemQuantities[$ppmpId]??0); if($qty<=0) continue;
                $x=$pdo->prepare('SELECT p.*,COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled" AND r.id<>?),0) used FROM ppmp_items p WHERE p.id=? AND p.fiscal_year=? AND p.area_id=? AND EXISTS (SELECT 1 FROM ppmp_reviews rv WHERE rv.fiscal_year=p.fiscal_year AND rv.area_id=p.area_id AND rv.ppmp_no=p.ppmp_no AND rv.status="Approved")');
                $x->execute([$prId,(int)$ppmpId,$year,$area]); $p=$x->fetch();
                if(!$p) throw new RuntimeException('Invalid PPMP item selected.');
                $remaining=(float)$p['quantity']-(float)$p['used'];
                if($qty>$remaining+0.000001) throw new RuntimeException('Requested quantity exceeds remaining PPMP quantity for '.$p['item_name'].'.');
                $unitId=(int)($_POST['units'][(int)$ppmpId]??0);if(!$unitId)throw new RuntimeException('Select a unit of measurement for '.$p['item_name'].'.');$ins->execute([$prId,(int)$ppmpId,$qty,(float)$p['unit_price'],$unitId]);
            }

            if((int)$pdo->query('SELECT COUNT(*) FROM purchase_request_items WHERE pr_id='.$prId)->fetchColumn()<1) throw new RuntimeException('Enter a quantity greater than zero.');
            $pdo->commit(); flash('success','Purchase Request '.$pr['pr_no'].' was updated.');
            header('Location:pr.php'); exit;
        }

        $year=(int)$_POST['fiscal_year']; $area=(int)$_POST['area_id']; $items=$_POST['items']??[]; $itemQuantities=$_POST['qty_display']??[];
        if(!$items) throw new RuntimeException('Select at least one PPMP item.');
        $monthPrefix=date('y-m');
        $seriesStmt=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(pr_no,'-',-1) AS UNSIGNED)) FROM purchase_requests WHERE pr_no LIKE CONCAT(?, '-%')");
        $seriesStmt->execute([$monthPrefix]);
        $prNo=$monthPrefix.'-'.str_pad((string)(((int)$seriesStmt->fetchColumn())+1),4,'0',STR_PAD_LEFT);
        $st=$pdo->prepare('INSERT INTO purchase_requests(pr_no,fiscal_year,area_id,purpose,status,requested_by,created_by) VALUES(?,?,?,?,?,?,?)');
        $st->execute([$prNo,$year,$area,trim($_POST['purpose']),$_POST['status']??'Draft',trim($_POST['requested_by']),currentUser()['id']]);
        $prId=(int)$pdo->lastInsertId();
        foreach($items as $ppmpId=>$qty){
            $qty=(float)($itemQuantities[$ppmpId]??0); if($qty<=0) continue;
            $x=$pdo->prepare('SELECT p.*,COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled"),0) used FROM ppmp_items p WHERE p.id=? AND p.fiscal_year=? AND p.area_id=? AND EXISTS (SELECT 1 FROM ppmp_reviews rv WHERE rv.fiscal_year=p.fiscal_year AND rv.area_id=p.area_id AND rv.ppmp_no=p.ppmp_no AND rv.status="Approved")');
            $x->execute([(int)$ppmpId,$year,$area]); $p=$x->fetch();
            if(!$p) throw new RuntimeException('Invalid PPMP item selected.');
            $remaining=(float)$p['quantity']-(float)$p['used'];
            if($qty>$remaining+0.000001) throw new RuntimeException('Requested quantity exceeds remaining PPMP quantity for '.$p['item_name'].'.');
            $unitId=(int)($_POST['units'][(int)$ppmpId]??0);if(!$unitId)throw new RuntimeException('Select a unit of measurement for '.$p['item_name'].'.');$ins=$pdo->prepare('INSERT INTO purchase_request_items(pr_id,ppmp_item_id,quantity,unit_price,unit_id) VALUES(?,?,?,?,?)');
            $ins->execute([$prId,(int)$ppmpId,$qty,(float)$p['unit_price'],$unitId]);
        }
        if((int)$pdo->query('SELECT COUNT(*) FROM purchase_request_items WHERE pr_id='.$prId)->fetchColumn()<1) throw new RuntimeException('Enter a quantity greater than zero.');
        $pdo->commit(); flash('success','Purchase Request '.$prNo.' created.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    header('Location:pr.php'); exit;
}

$editId=(int)($_GET['edit']??0); $editPr=null; $editItems=[];$editUnits=[];
if($editId>0){
    requireRole(['Administrator','Editor']);
    $st=$pdo->prepare('SELECT * FROM purchase_requests WHERE id=? AND status="Draft"'); $st->execute([$editId]); $editPr=$st->fetch();
    if(!$editPr){flash('error','Only Draft Purchase Requests can be edited.');header('Location:pr.php');exit;}
    $st=$pdo->prepare('SELECT ppmp_item_id,quantity,unit_id FROM purchase_request_items WHERE pr_id=?');$st->execute([$editId]);
    foreach($st as $i){$editItems[(int)$i['ppmp_item_id']] = (float)$i['quantity'];$editUnits[(int)$i['ppmp_item_id']] = (int)($i['unit_id']??0);}
}

$year=(int)($_GET['year']??($editPr['fiscal_year']??date('Y')));$q=trim($_GET['q']??'');
$areas=$pdo->query('SELECT * FROM areas ORDER BY name')->fetchAll();$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();
$items=$pdo->prepare('SELECT p.*,a.name area,c.name category,u.name unit_name,p.quantity-COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled" AND r.id<>?),0) remaining FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id LEFT JOIN units_of_measure u ON LOWER(TRIM(u.name))=LOWER(TRIM(p.unit)) WHERE p.fiscal_year=? AND EXISTS (SELECT 1 FROM ppmp_reviews rv WHERE rv.fiscal_year=p.fiscal_year AND rv.area_id=p.area_id AND rv.ppmp_no=p.ppmp_no AND rv.status="Approved") ORDER BY a.name,p.item_name');
$items->execute([$editId,$year]);$ppmp=$items->fetchAll();
$supervisors=$pdo->query("SELECT id,full_name,area_id FROM users WHERE status='Active' AND area_id IS NOT NULL ORDER BY full_name")->fetchAll();
$prs=$pdo->prepare('SELECT r.*,a.name area,(SELECT COUNT(*) FROM purchase_request_items i WHERE i.pr_id=r.id) AS line_count,(SELECT COALESCE(SUM(i.quantity*i.unit_price),0) FROM purchase_request_items i WHERE i.pr_id=r.id) abc FROM purchase_requests r JOIN areas a ON a.id=r.area_id WHERE r.fiscal_year=? AND (r.pr_no LIKE ? OR a.name LIKE ?) ORDER BY r.created_at DESC');
$prs->execute([$year,"%$q%","%$q%"]);$allRows=$prs->fetchAll();
$statuses=['Pending','Received','Returned','Cancelled','Completed'];
$activeStatus=$_GET['status']??'Pending';
if(!in_array($activeStatus,$statuses,true))$activeStatus='Pending';
$rows=array_values(array_filter($allRows,function($r)use($activeStatus){
  $status=strtolower(trim((string)$r['status']));
  if($activeStatus==='Pending')return in_array($status,['pending','draft','submitted','for review','for approval'],true);
  return $status===strtolower($activeStatus);
}));
pageStart('Purchase Requests');
?>
<div class="panel pr-list-panel">
  <div class="pr-list-header">
    <div><h2>Purchase Requests</h2><p class="hint-text">View and manage Purchase Requests by status.</p></div>
    <form class="pr-search-form" method="get"><input class="input" name="q" placeholder="Search PR number or area" value="<?=e($q)?>"><input type="hidden" name="year" value="<?=$year?>"><input type="hidden" name="status" value="<?=e($activeStatus)?>"></form>
  </div>
  <nav class="pr-status-tabs" aria-label="Purchase Request status tabs">
    <?php foreach($statuses as $status):$count=count(array_filter($allRows,function($r)use($status){$v=strtolower(trim((string)$r['status']));return $status==='Pending'?in_array($v,['pending','draft','submitted','for review','for approval'],true):$v===strtolower($status);}));?>
      <a class="pr-status-tab <?=$activeStatus===$status?'active':''?>" href="pr.php?<?=http_build_query(['year'=>$year,'q'=>$q,'status'=>$status])?>"><?=e($status)?><span><?=$count?></span></a>
    <?php endforeach;?>
  </nav>
  <div class="table-wrap"><table class="table"><thead><tr><th>PR No.</th><th>Area/Unit</th><th>Purpose</th><th>Lines</th><th>ABC</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>
  <?php foreach($rows as $r):?><tr><td><b><?=e($r['pr_no'])?></b></td><td><?=e($r['area'])?></td><td><?=e($r['purpose'])?></td><td><?=$r['line_count']?></td><td>₱<?=number_format($r['abc'],2)?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?=date('M d, Y',strtotime($r['created_at']))?></td><td><?php if($r['status']==='Draft' && hasRole(['Administrator','Editor'])):?><a class="btn" href="pr.php?edit=<?=$r['id']?>">Edit Draft</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="8" class="empty">No purchase requests in this tab.</td></tr><?php endif;?>
  </tbody></table></div>
  <?php if(hasRole(['Administrator','Editor'])): ?><div class="pr-create-actions"><button class="btn" type="button" id="showPrForm">Create PR</button></div><?php endif;?>
</div>

<?php if(hasRole(['Administrator','Editor'])):?><div class="panel" id="prForm" style="margin-top:18px" <?=$editPr?'':'hidden'?>><h2><?=$editPr?'Edit Draft Purchase Request '.$editPr['pr_no']:'Create Purchase Request from PPMP'?></h2><p class="hint-text">Only Draft Purchase Requests can be reopened and edited. Available quantity is automatically recalculated.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$editPr?'update':'create'?>"><?php if($editPr):?><input type="hidden" name="pr_id" value="<?=$editPr['id']?>"><?php endif;?>
<div class="form-grid pr-selection-row"><div class="field"><label>Select Year</label><select class="select" id="prYearSelect" name="fiscal_year" required><?php for($fy=(int)date("Y")-3;$fy<=(int)date("Y")+3;$fy++):?><option value="<?=$fy?>" <?=$year===$fy?"selected":""?>><?=$fy?></option><?php endfor;?></select></div><input type="hidden" name="status" value="Draft"><div class="field"><label>Select Area/Unit</label><select class="select" name="area_id" id="prArea" required><option value="">Select Area/Unit</option><?php foreach($areas as $a):?><option value="<?=$a['id']?>" <?=((int)($editPr['area_id']??0)===(int)$a['id']?'selected':'')?>><?=e($a['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Supervisor Assigned</label><select class="select pr-supervisor-readonly" name="requested_by" id="prSupervisor" required readonly disabled><option value="">Select Supervisor</option><?php foreach($supervisors as $sv):?><option value="<?=e($sv['full_name'])?>" data-area="<?=$sv['area_id']?>" <?=($editPr && ($editPr['requested_by']??'')===$sv['full_name']?'selected':'')?>><?=e($sv['full_name'])?></option><?php endforeach;?></select></div><div class="field full"><label>Purpose</label><textarea class="input" name="purpose" rows="2" required><?=e($editPr['purpose']??'')?></textarea></div></div>
<div class="actions" style="margin-top:16px"><button class="btn" type="button" id="viewPpmpItems">View PPMP Items</button><span class="hint-text" id="ppmpViewHint">Select an Area/Unit and Supervisor Assigned, then click View PPMP Items.</span></div><div class="table-wrap" style="margin-top:12px" id="prItemsWrap" hidden><table class="table" id="prItemsTable"><thead><tr><th>Stock No/Item No.</th><th>Select Approved PPMP Item</th><th>Unit</th><th>Item Description</th><th>Quantity</th><th>Unit Cost</th><th>Total Cost</th></tr></thead><tbody><?php foreach($ppmp as $p):$selected=array_key_exists((int)$p['id'],$editItems);$max=(float)$p['remaining']+($selected?(float)$editItems[(int)$p['id']]:0);?><tr class="pr-item-row" data-area="<?=$p['area_id']?>" data-remaining="<?=$max?>" data-unit="<?=e($p['unit_name']??$p['unit'])?>" data-description="<?=e($p['item_name'])?>" data-cost="<?=$p['unit_price']?>" data-unit-id="<?=(int)($unitsByName[$p['unit']]??0)?>"><td class="pr-stock-no"></td><td><label class="pr-item-select"><input type="checkbox" class="pr-item-check" name="items[<?=$p['id']?>]" value="<?=e((string)($editItems[(int)$p['id']]??$max))?>" <?=$selected?'checked':''?>><span><?=e($p['item_name'])?> <small>(<?=e($p['ppmp_no']??'PPMP')?> · <?=e($p['area'])?>)</small></span></label></td><td class="pr-unit"><?=e($p['unit_name']??$p['unit'])?></td><td><?=e($p['item_name'])?></td><td><input class="input pr-qty" type="number" min="0.0001" max="<?=$max?>" step="0.0001" name="qty_display[<?=$p['id']?>]" value="<?=$selected?e((string)$editItems[(int)$p['id']]):e((string)$max)?>" <?=$selected?'':'disabled'?>></td><td class="pr-cost"><?=number_format((float)$p['unit_price'],2)?></td><td class="pr-total"><?=number_format(($selected?(float)$editItems[(int)$p['id']]:0)*(float)$p['unit_price'],2)?><input type="hidden" name="units[<?=$p['id']?>]" value="<?php foreach($units as $u){if(strcasecmp($u['name'],(string)($p['unit_name']??$p['unit']))===0){echo (int)$u['id'];break;}}?>" <?=$selected?'':'disabled'?>></td></tr><?php endforeach;?><?php if(!$ppmp):?><tr><td colspan="7" class="empty">No approved PPMP items are available for this fiscal year.</td></tr><?php endif;?></tbody></table></div><div class="actions"><button class="btn"><?=$editPr?'Save Draft / Submit PR':'Create Purchase Request'?></button><?php if($editPr):?><a class="btn" href="pr.php">Cancel</a><?php else:?><button class="btn secondary" type="button" id="hidePrForm">Cancel</button><?php endif;?></div></form></div><?php endif;?><?php pageEnd(); ?>
<style>
.pr-list-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.pr-list-header h2{margin:0}.pr-list-header .hint-text{margin:5px 0 0}
.pr-search-form{width:250px;max-width:100%;margin:0}
.pr-status-tabs{display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid #dce5ef;margin-bottom:16px}
.pr-status-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 15px;margin-bottom:-1px;border:1px solid transparent;border-bottom:3px solid transparent;border-radius:8px 8px 0 0;text-decoration:none;color:#475569;font-weight:700}
.pr-status-tab span{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;padding:0 6px;border-radius:999px;background:#eef2f7;color:#475569;font-size:12px}
.pr-status-tab.active{color:#164e80;background:#edf6ff;border-color:#d9e9f8;border-bottom-color:#0d6efd}
.pr-status-tab.active span{background:#0d6efd;color:#fff}
.pr-create-actions{display:flex;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid #edf1f5}
#prForm[hidden]{display:none!important}
.pr-item-row[hidden]{display:none!important}
.pr-item-select{display:flex;align-items:flex-start;gap:8px;font-weight:500;min-width:220px}
.pr-item-select small{display:block;color:#718096;font-size:11px}
.pr-item-row td{vertical-align:middle}
.pr-qty{min-width:90px;width:100px}
#prItemsTable input[type=checkbox]{width:16px;height:16px}
\n#hidePrForm[hidden]{display:none!important}
.pr-selection-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:start}.pr-supervisor-readonly{background:#e9ecef!important;color:#6c757d!important;cursor:not-allowed;opacity:1!important}@media(max-width:650px){.pr-selection-row{grid-template-columns:1fr}.pr-list-header{align-items:stretch;flex-direction:column}.pr-search-form{width:100%}.pr-status-tabs{gap:2px}.pr-status-tab{padding:9px 8px;font-size:13px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const button=document.getElementById('showPrForm');
  const cancelButton=document.getElementById('hidePrForm');
  const form=document.getElementById('prForm');
  const area=document.getElementById('prArea');
  const yearSelect=document.getElementById('prYearSelect');
  const supervisor=document.getElementById('prSupervisor');
  const viewItemsButton=document.getElementById('viewPpmpItems');
  const itemsWrap=document.getElementById('prItemsWrap');
  const viewHint=document.getElementById('ppmpViewHint');
  const supervisorName=<?=json_encode((string)($editPr['requested_by']??''),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
  let itemsViewed=<?= $editPr ? 'true' : 'false' ?>;
  if(button&&form){
    button.addEventListener('click',function(){form.hidden=false;button.hidden=true;form.scrollIntoView({behavior:'smooth',block:'start'});});
    if(cancelButton)cancelButton.addEventListener('click',function(){form.hidden=true;button.hidden=false;});
    if(!form.hidden)button.hidden=true;
  }
  function filterRows(){
    if(!area)return;
    if(!itemsViewed&&itemsWrap)itemsWrap.hidden=true;
    const selectedArea=area.value;
    if(supervisor){
      Array.from(supervisor.options).forEach(function(o){if(o.value)o.hidden=!!selectedArea&&o.dataset.area!==selectedArea;});
      const match=Array.from(supervisor.options).find(function(o){return o.value&&o.dataset.area===selectedArea;});
      supervisor.value=match?match.value:'';
      if(supervisorName&&selectedArea&&supervisor.dataset.initialArea===selectedArea){const existing=Array.from(supervisor.options).find(function(o){return o.value===supervisorName&&o.dataset.area===selectedArea;});if(existing)supervisor.value=existing.value;}
    }
    document.querySelectorAll('.pr-item-row').forEach(function(row){
      const visible=itemsViewed&&!!selectedArea&&!!(supervisor&&supervisor.value)&&row.dataset.area===selectedArea;
      row.hidden=!visible;
      const check=row.querySelector('.pr-item-check'),qty=row.querySelector('.pr-qty'),unit=row.querySelector('input[type=hidden][name^="units"]');
      if(!visible){if(check)check.checked=false;if(qty)qty.disabled=true;if(unit)unit.disabled=true;if(check)check.disabled=true;}
      else {if(check)check.disabled=false;}
      updateRow(row);
    });
    renumber();
  }
  function updateRow(row){
    const check=row.querySelector('.pr-item-check'),qty=row.querySelector('.pr-qty'),total=row.querySelector('.pr-total');
    if(qty){qty.disabled=!check.checked||row.hidden; if(!check.checked)qty.value='';}
    const hiddenUnit=row.querySelector('input[type=hidden][name^="units"]');if(hiddenUnit)hiddenUnit.disabled=!check.checked||row.hidden;
    if(check)check.value=qty&&qty.value?qty.value:'0';
    if(total){const cost=parseFloat(row.dataset.cost||'0'),quantity=parseFloat(qty&&qty.value||'0');total.textContent=(cost*quantity).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
  }
  function renumber(){let n=1;document.querySelectorAll('.pr-item-row').forEach(function(row){if(!row.hidden&&row.querySelector('.pr-item-check').checked){row.querySelector('.pr-stock-no').textContent=String(n++).padStart(4,'0');}else row.querySelector('.pr-stock-no').textContent='';});}
  document.querySelectorAll('.pr-item-row').forEach(function(row){
    const check=row.querySelector('.pr-item-check'),qty=row.querySelector('.pr-qty');
    check.addEventListener('change',function(){if(qty){qty.disabled=!check.checked||row.hidden;if(check.checked&&!qty.value)qty.value=row.dataset.remaining||'0';}updateRow(row);renumber();});
    if(qty)qty.addEventListener('input',function(){updateRow(row);renumber();});
  });
  if(yearSelect)yearSelect.addEventListener('change',function(){const url=new URL(window.location.href);url.searchParams.set('year',yearSelect.value);if(url.searchParams.has('edit'))url.searchParams.delete('edit');window.location.href=url.toString();});
  if(area)area.addEventListener('change',function(){itemsViewed=false;if(itemsWrap)itemsWrap.hidden=true;if(viewHint)viewHint.textContent='Click View PPMP Items to load the approved items for this Area/Unit.';filterRows();});
  if(supervisor)supervisor.addEventListener('change',function(){itemsViewed=false;if(itemsWrap)itemsWrap.hidden=true;if(viewHint)viewHint.textContent='Click View PPMP Items to load the approved items for this supervisor and Area/Unit.';filterRows();});
  if(viewItemsButton)viewItemsButton.addEventListener('click',function(){
    if(!area||!area.value){alert('Please select an Area/Unit first.');if(area)area.focus();return;}
    if(!supervisor||!supervisor.value){alert('Please select a Supervisor Assigned first.');if(supervisor)supervisor.focus();return;}
    const selectedSupervisor=supervisor.selectedOptions[0];
    if(!selectedSupervisor||selectedSupervisor.dataset.area!==area.value){alert('The selected supervisor must belong to the selected Area/Unit.');return;}
    itemsViewed=true;if(itemsWrap)itemsWrap.hidden=false;
    if(viewHint)viewHint.textContent='Select the approved PPMP items you need. Selected items will automatically fill the table rows below.';
    filterRows();if(itemsWrap)itemsWrap.scrollIntoView({behavior:'smooth',block:'start'});
  });
  if(supervisor)supervisor.disabled=true;
  if(area)filterRows();
  if(itemsViewed&&itemsWrap)itemsWrap.hidden=false;
});
</script>
