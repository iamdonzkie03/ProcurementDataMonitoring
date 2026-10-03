<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

$year=(int)($_GET['year']??date('Y'));
$areaId=(int)($_GET['area_id']??0);
$q=trim($_GET['q']??'');
$print=isset($_GET['print']) && $_GET['print']=='1';
$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0 && !$print){
  $stEdit=$pdo->prepare('SELECT * FROM ppmp_items WHERE id=?'); $stEdit->execute([$editId]); $editing=$stEdit->fetch();
  if(!$editing){ flash('error','PPMP item not found.'); header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit; }
  $year=(int)$editing['fiscal_year']; $areaId=(int)$editing['area_id'];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  requireRole(['Administrator','Editor']); checkCsrf();
  $action=$_POST['action']??'add';
  $id=(int)($_POST['id']??0);
  $year=(int)($_POST['fiscal_year']??0);
  $areaId=(int)($_POST['area_id']??0);

  if($action==='delete'){
    if($id<=0){ flash('error','Invalid PPMP item.'); }
    else{
      $stDel=$pdo->prepare('DELETE FROM ppmp_items WHERE id=?');
      $stDel->execute([$id]);
      flash($stDel->rowCount() ? 'success' : 'error',$stDel->rowCount() ? 'PPMP item deleted.' : 'PPMP item not found.');
    }
    header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit;
  }

  $qty=max(0,(float)($_POST['quantity']??0)); $unitPrice=max(0,(float)($_POST['unit_price']??0));
  $values=[
    $year,trim($_POST['ppmp_no']??''),$areaId,(int)$_POST['category_id'],trim($_POST['item_name']),
    trim($_POST['description']??''),trim($_POST['procurement_type']??''),$qty,trim($_POST['unit']),
    trim($_POST['procurement_mode']??''),trim($_POST['preprocurement_conference']??''),
    trim($_POST['start_procurement']??''),trim($_POST['end_procurement']??''),trim($_POST['delivery_period']??''),
    trim($_POST['source_of_funds']??''),$unitPrice,trim($_POST['supporting_documents']??''),
    trim($_POST['requested_by']??''),trim($_POST['prepared_by']??''),trim($_POST['prepared_position']??''),
    trim($_POST['submitted_by']??''),trim($_POST['submitted_position']??''),trim($_POST['budget_approved_by']??''),
    trim($_POST['budget_position']??''),($_POST['prepared_date']??'')?:null,($_POST['submitted_date']??'')?:null,
    ($_POST['budget_date']??'')?:null,trim($_POST['remarks']??'')
  ];
  if($action==='edit' && $id>0){
    $st=$pdo->prepare('UPDATE ppmp_items SET fiscal_year=?,ppmp_no=?,area_id=?,category_id=?,item_name=?,description=?,procurement_type=?,quantity=?,unit=?,procurement_mode=?,preprocurement_conference=?,start_procurement=?,end_procurement=?,delivery_period=?,source_of_funds=?,unit_price=?,supporting_documents=?,requested_by=?,prepared_by=?,prepared_position=?,submitted_by=?,submitted_position=?,budget_approved_by=?,budget_position=?,prepared_date=?,submitted_date=?,budget_date=?,remarks=? WHERE id=?');
    $st->execute([...$values,$id]); flash('success','PPMP item updated.');
  }else{
    $st=$pdo->prepare('INSERT INTO ppmp_items
      (fiscal_year,ppmp_no,area_id,category_id,item_name,description,procurement_type,quantity,unit,procurement_mode,
       preprocurement_conference,start_procurement,end_procurement,delivery_period,source_of_funds,unit_price,
       supporting_documents,requested_by,prepared_by,prepared_position,submitted_by,submitted_position,
       budget_approved_by,budget_position,prepared_date,submitted_date,budget_date,remarks,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([...$values,currentUser()['id']]); flash('success','PPMP item saved.');
  }
  header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit;
}
$areas=$pdo->query('SELECT * FROM areas ORDER BY name')->fetchAll();
$cats=$pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();

$where=' WHERE p.fiscal_year=?'; $args=[$year];
if($areaId>0){$where.=' AND p.area_id=?';$args[]=$areaId;}
if($q!==''){$where.=' AND (p.item_name LIKE ? OR p.description LIKE ? OR a.name LIKE ? OR c.name LIKE ?)';$args=[...$args,"%$q%","%$q%","%$q%","%$q%"];}
$sql='SELECT p.*,a.name area,a.authorized_person,c.name category FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id'.$where.' ORDER BY p.id';
$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll();

$selectedArea=null;
foreach($areas as $a){if((int)$a['id']===$areaId){$selectedArea=$a;break;}}
if($print && !$selectedArea && $rows){$areaId=(int)$rows[0]['area_id'];foreach($areas as $a){if((int)$a['id']===$areaId){$selectedArea=$a;break;}}}
if($print && !$selectedArea){flash('error','Select an Area/Unit before printing the PPMP form.');header('Location:ppmp.php?year='.$year);exit;}
if($print && $rows){
  $header=$rows[0];
  $selectedArea=$selectedArea ?: ['name'=>$header['area'],'authorized_person'=>$header['authorized_person']];
}
pageStart('Project Procurement Management Plan');
?>
<?php if(!$print): ?>
<div class="panel ppmp-toolbar">
  <div class="toolbar">
    <form class="ppmp-filter">
      <input class="input" type="number" name="year" value="<?=$year?>" min="2020" max="2100" aria-label="Fiscal Year">
      <select class="select" name="area_id"><option value="0">All Areas/Units</option><?php foreach($areas as $a):?><option value="<?=$a['id']?>" <?=$areaId===$a['id']?'selected':''?>><?=e($a['name'])?></option><?php endforeach;?></select>
      <input class="input" name="q" placeholder="Search item, area or description" value="<?=e($q)?>">
      <button class="btn" type="submit">View</button>
    </form>
    <?php if(hasRole(['Administrator','Editor'])):?><a class="btn" href="#ppmpForm">+ Add PPMP Item</a><?php endif;?>
    <?php if($areaId>0):?><a class="btn secondary" target="_blank" href="ppmp.php?print=1&year=<?=$year?>&area_id=<?=$areaId?>">Print PPMP Form</a><?php endif;?>
  </div>
</div>

<div class="ppmp-entry panel" id="ppmpForm">
  <h2><?= $editing ? 'Edit PPMP Item' : 'Project Procurement Management Plan — Data Entry' ?></h2>
  <p class="muted">The fields below correspond to the official PPMP Form. Saved entries are rendered in the print layout below.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e($editing['id'])?>"><?php endif; ?>
    <div class="ppmp-input-grid">
      <div class="field"><label>Fiscal Year *</label><input class="input" type="number" name="fiscal_year" value="<?=e($editing['fiscal_year']??$year)?>" required></div>
      <div class="field"><label>PPMP No. *</label><input class="input" name="ppmp_no" required placeholder="e.g. PPMP-2027-001" value="<?=e($editing['ppmp_no']??'')?>"></div>
      <div class="field"><label>End-User / Implementing Unit *</label><select class="select" name="area_id" id="ppmp_area" required><option value="">Select</option><?php foreach($areas as $a):?><option value="<?=$a['id']?>" data-person="<?=e($a['authorized_person']??'')?>" <?=((int)($editing['area_id']??0)===(int)$a['id'])?'selected':''><?=e($a['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label>Supervisor / Authorized Person *</label><input class="input" id="ppmp_person" name="prepared_by" value="<?=e($editing['prepared_by']??'')?>" readonly required></div>
      <div class="field"><label>Category *</label><select class="select" name="category_id" required><option value="">Select</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>" <?=((int)($editing['category_id']??0)===(int)$c['id'])?'selected':''><?=e($c['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label>Type of Project *</label><select class="select" name="procurement_type" required><option value="">Select</option><option <?=($editing['procurement_type']??'')==='Goods'?'selected':''>Goods</option><option <?=($editing['procurement_type']??'')==='Infrastructure'?'selected':''>Infrastructure</option><option <?=($editing['procurement_type']??'')==='Consulting Services'?'selected':''>Consulting Services</option><option <?=($editing['procurement_type']??'')==='General Support Services'?'selected':''>General Support Services</option></select></div>
      <div class="field full"><label>General Description and Objective of the Project to be Procured *</label><textarea class="input" name="description" rows="3" required><?=e($editing['description']??'')?></textarea></div>
      <div class="field"><label>Item / Project Name *</label><input class="input" name="item_name" required value="<?=e($editing['item_name']??'')?>"></div>
      <div class="field"><label>Quantity *</label><input class="input" type="number" step="0.0001" min="0" name="quantity" required value="<?=e($editing['quantity']??'')?>"></div>
      <div class="field"><label>Unit or Measurement / Size *</label><select class="select" name="unit" required><option value="">Select</option><?php foreach($units as $u):?><option value="<?=e($u['name'])?>" <?=((string)($editing['unit']??'')===(string)$u['name'])?'selected':''><?=e($u['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label>Recommended Mode of Procurement *</label><input class="input" name="procurement_mode" required placeholder="e.g. Competitive Bidding, SVP" value="<?=e($editing['procurement_mode']??'')?>"></div>
      <div class="field"><label>Pre-Procurement Conference *</label><select class="select" name="preprocurement_conference" required><option value="">Select</option><option <?=($editing['preprocurement_conference']??'')==='Yes'?'selected':''>Yes</option><option <?=($editing['preprocurement_conference']??'')==='No'?'selected':''>No</option><option <?=($editing['preprocurement_conference']??'')==='N/A'?'selected':''>N/A</option></select></div>
      <div class="field"><label>Start of Procurement Activity (MM/YYYY) *</label><input class="input" name="start_procurement" required placeholder="MM/YYYY" value="<?=e($editing['start_procurement']??'')?>"></div>
      <div class="field"><label>End of Procurement Activity (MM/YYYY) *</label><input class="input" name="end_procurement" required placeholder="MM/YYYY" value="<?=e($editing['end_procurement']??'')?>"></div>
      <div class="field"><label>Expected Delivery / Implementation Period *</label><input class="input" name="delivery_period" required placeholder="MM/YYYY or As Needed" value="<?=e($editing['delivery_period']??'')?>"></div>
      <div class="field"><label>Source of Funds *</label><input class="input" name="source_of_funds" required placeholder="GAA, Trust Fund, etc." value="<?=e($editing['source_of_funds']??'')?>"></div>
      <div class="field"><label>Unit Cost (PhP) *</label><input class="input" type="number" step="0.01" min="0" name="unit_price" required value="<?=e($editing['unit_price']??'')?>"></div>
      <div class="field"><label>Attached Supporting Documents *</label><input class="input" name="supporting_documents" required placeholder="Technical Specifications / SOW / TOR / Market Scoping" value="<?=e($editing['supporting_documents']??'')?>"></div>
      <div class="field"><label>Requested By</label><input class="input" name="requested_by" value="<?=e($editing['requested_by']??'')?>"></div>
      <div class="field"><label>Prepared Position / Designation</label><input class="input" name="prepared_position" value="<?=e($editing['prepared_position']??'End-User or Implementing Unit')?>"></div>
      <div class="field"><label>Submitted By</label><input class="input" name="submitted_by" value="<?=e($editing['submitted_by']??'')?>"></div>
      <div class="field"><label>Submitted Position / Designation</label><input class="input" name="submitted_position" value="<?=e($editing['submitted_position']??'Division/Department/Section Unit')?>"></div>
      <div class="field"><label>Within the Budget Allocation — Name</label><input class="input" name="budget_approved_by" value="<?=e($editing['budget_approved_by']??'')?>"></div>
      <div class="field"><label>Budget Position</label><input class="input" name="budget_position" value="<?=e($editing['budget_position']??'Budget Section')?>"></div>
      <div class="field"><label>Prepared Date</label><input class="input" type="date" name="prepared_date" value="<?=e($editing['prepared_date']??'')?>"></div>
      <div class="field"><label>Submitted Date</label><input class="input" type="date" name="submitted_date" value="<?=e($editing['submitted_date']??'')?>"></div>
      <div class="field"><label>Budget Date</label><input class="input" type="date" name="budget_date" value="<?=e($editing['budget_date']??'')?>"></div>
      <div class="field full"><label>Remarks</label><textarea class="input" name="remarks" rows="2"><?=e($editing['remarks']??'')?></textarea></div>
    </div>
    <div class="actions"><button class="btn" type="submit"><?= $editing ? 'Save Changes' : 'Save PPMP Item' ?></button><?php if($editing): ?><a class="btn secondary" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>">Cancel</a><?php endif; ?></div>
  </form>
</div>
<div class="panel ppmp-records">
  <h2>Saved PPMP Items — FY <?=$year?><?= $selectedArea?' / '.e($selectedArea['name']):'' ?></h2>
  <div class="table-wrap"><table class="table"><tr><th>Item</th><th>Type</th><th>Qty / Unit</th><th>Mode</th><th>Unit Cost</th><th>Budget</th><th>Actions</th></tr>
  <?php foreach($rows as $r):?><tr><td><b><?=e($r['item_name'])?></b><br><small><?=e($r['description'])?></small></td><td><?=e($r['procurement_type'])?></td><td><?=number_format($r['quantity'],2).' '.e($r['unit'])?></td><td><?=e($r['procurement_mode'])?></td><td>₱<?=number_format($r['unit_price'],2)?></td><td>₱<?=number_format($r['quantity']*$r['unit_price'],2)?></td><td><div class="actions ppmp-row-actions"><a class="btn secondary" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>&edit=<?=$r['id']?>">Edit</a><form method="post" style="display:inline" onsubmit="return confirm('Delete this PPMP item? This action cannot be undone.');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="fiscal_year" value="<?=e($year)?>"><input type="hidden" name="area_id" value="<?=e($areaId)?>"><button class="btn danger" type="submit">Delete</button></form></div></td></tr><?php endforeach;?></table></div>
</div>
<?php else: ?>
<?php
$h=$rows[0]??[];
$ppmpNo=$h['ppmp_no']??'';
$person=$h['prepared_by']??($selectedArea['authorized_person']??'');
$preparedPos=$h['prepared_position']??'End-User or Implementing Unit';
$submitted=$h['submitted_by']??'';
$submittedPos=$h['submitted_position']??'Division/Department/Section Unit';
$budgetName=$h['budget_approved_by']??'';
$budgetPos=$h['budget_position']??'Budget Section';
?>
<div class="ppmp-print-sheet">
  <div class="ppmp-head">
    <div class="ppmp-brand">
      <div class="ppmp-brand-mark">ZCMC</div>
      <div><div>Republic of the Philippines</div><div>Department of Health</div><strong>ZAMBOANGA CITY MEDICAL CENTER</strong><small>Dr. D. Evangelista St., Sta. Catalina, Zamboanga City 7000</small></div>
    </div>
    <div class="ppmp-control"><div>Form No.: ZCMC-F-PROC-01</div><div>Revision No.: 1</div><div>Effectivity Date: February 11, 2026</div></div>
  </div>
  <div class="ppmp-title">PROJECT PROCUREMENT MANAGEMENT PLAN (PPMP) NO. <span class="line"><?=e($ppmpNo)?></span></div>
  <div class="ppmp-classification"><span>☐ INDICATIVE</span><span>☐ FINAL</span></div>
  <div class="ppmp-meta"><div><b>Fiscal Year :</b> <?=e($year)?></div><div><b>End-User or Implementing Unit:</b> <?=e($selectedArea['name'])?></div></div>

  <table class="ppmp-official-table">
    <thead>
      <tr><th colspan="6">PROCUREMENT PROJECT DETAILS</th><th colspan="3">PROJECTED TIMELINE (MM/YYYY)</th><th colspan="3">FUNDING DETAILS</th><th>ATTACHED SUPPORTING<br>DOCUMENTS</th><th>REMARKS</th></tr>
      <tr>
        <th>General Description and Objective<br>of the Project to be Procured</th>
        <th>Type of the Project to be Procured<br>(whether Goods, Infrastructure and Consulting Services)</th>
        <th colspan="2">Quantity and Size of the Project to be Procured</th>
        <th>Recommended Mode of Procurement</th>
        <th>Pre-Procurement Conference, if applicable (Yes/No)</th>
        <th>Start of Procurement Activity</th>
        <th>End of Procurement Activity</th>
        <th>Expected Delivery / Implementation Period</th>
        <th>Source of Funds</th>
        <th>Unit Cost</th>
        <th>Estimated Budget / Authorized Budgetary Allocation (PhP)</th>
        <th></th><th></th>
      </tr>
      <tr class="subhead"><th>Column 1</th><th>Column 2</th><th>Quantity</th><th>Unit or Measurement/Size</th><th>Column 4</th><th>Column 5</th><th>Column 6</th><th>Column 7</th><th>Column 8</th><th>Column 9</th><th>Column 9A</th><th>Column 10</th><th>Column 11</th><th>Column 12</th></tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr class="data-row">
        <td><?=nl2br(e($r['description']))?></td><td><?=e($r['procurement_type'])?></td><td><?=rtrim(rtrim(number_format($r['quantity'],4,'.',''), '0'),'.')?></td><td><?=e($r['unit'])?></td><td><?=e($r['procurement_mode'])?></td><td><?=e($r['preprocurement_conference'])?></td><td><?=e($r['start_procurement'])?></td><td><?=e($r['end_procurement'])?></td><td><?=e($r['delivery_period'])?></td><td><?=e($r['source_of_funds'])?></td><td>₱<?=number_format($r['unit_price'],2)?></td><td>₱<?=number_format($r['quantity']*$r['unit_price'],2)?></td><td><?=nl2br(e($r['supporting_documents']))?></td><td><?=nl2br(e($r['remarks']))?></td>
      </tr>
      <?php endforeach; ?>
      <?php for($i=count($rows);$i<3;$i++): ?><tr class="data-row blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </tbody>
  </table>

  <div class="ppmp-note"><b>Important Note:</b> The Market Scoping Form and its proof of documentation and activities shall be attached to this PPMP prior to approval. Failure to provide both the Market Scoping Form and proof of documentation shall result to deferment or rejection of this PPMP.</div>

  <div class="ppmp-signatures">
    <div><b>Prepared by:</b><div class="signature-line"><?=e($person)?></div><div>Signature over Printed Name</div><div><?=e($preparedPos)?></div><div><i>End-User or Implementing Unit</i></div><div>Date : <?=e($h['prepared_date']??'')?></div></div>
    <div><b>Submitted by:</b><div class="signature-line"><?=e($submitted)?></div><div>Signature over Printed Name</div><div><?=e($submittedPos)?></div><div><i>Division/Department/Section Unit</i></div><div>Date : <?=e($h['submitted_date']??'')?></div></div>
    <div><b>within the budget allocation:</b><div class="signature-line"><?=e($budgetName)?></div><div>Signature over Printed Name</div><div>Supervising Administrative Officer</div><div><i><?=e($budgetPos)?></i></div><div>Date : <?=e($h['budget_date']??'')?></div></div>
  </div>
</div>
<div class="ppmp-print-actions"><button class="btn" onclick="window.print()">Print</button><a class="btn secondary" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>">Back</a></div>
<?php endif; ?>
<script>
(function(){
 const area=document.getElementById('ppmp_area'), person=document.getElementById('ppmp_person');
 if(area&&person){
   function sync(){const o=area.options[area.selectedIndex];person.value=o?(o.dataset.person||''):'';}
   area.addEventListener('change',sync); sync();
 }
})();
</script>
<?php pageEnd();