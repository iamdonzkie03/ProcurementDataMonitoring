<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

$currentFiscalYear=(int)date('Y');
$entryFiscalYears=range($currentFiscalYear,$currentFiscalYear+3);
$existingFiscalYears=array_map('intval',$pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
$searchFiscalYears=array_values(array_unique(array_merge($existingFiscalYears,$entryFiscalYears)));
rsort($searchFiscalYears);
$year=(int)($_GET['year']??$currentFiscalYear);
if(!in_array($year,$searchFiscalYears,true)) $year=$currentFiscalYear;
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
  $requestedBy=trim($_POST['requested_by']??'');
  $preparedPosition='';
  if($requestedBy!==''){
    $stRequested=$pdo->prepare('SELECT position_designation FROM area_personnel WHERE area_id=? AND name=? LIMIT 1');
    $stRequested->execute([$areaId,$requestedBy]);
    $requestedPerson=$stRequested->fetch();
    if(!$requestedPerson){
      flash('error','Requested By must be selected from the personnel assigned to the selected End-User / Implementing Unit.');
      header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit;
    }
    $preparedPosition=trim($requestedPerson['position_designation']??'');
  }

  if(!in_array($year,$entryFiscalYears,true)){
    flash('error','Fiscal Year must be between '.$currentFiscalYear.' and '.($currentFiscalYear+3).'.');
    header('Location:ppmp.php?year='.$currentFiscalYear.'&area_id='.$areaId); exit;
  }

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
  $totalBudget=$qty*$unitPrice;
  $supportingDocuments=trim($_POST['existing_supporting_documents']??'');
  $existingDocs=[];
  if($supportingDocuments!==''){ $decoded=json_decode($supportingDocuments,true); if(is_array($decoded)) $existingDocs=$decoded; }
  if(!empty($_FILES['supporting_documents']['name']) && is_array($_FILES['supporting_documents']['name'])){
    $uploadDir=__DIR__.'/uploads/ppmp';
    if(!is_dir($uploadDir)) @mkdir($uploadDir,0775,true);
    foreach($_FILES['supporting_documents']['name'] as $i=>$originalName){
      if($_FILES['supporting_documents']['error'][$i]===UPLOAD_ERR_NO_FILE) continue;
      if($_FILES['supporting_documents']['error'][$i]!==UPLOAD_ERR_OK){ flash('error','One or more supporting documents could not be uploaded.'); header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit; }
      if((int)$_FILES['supporting_documents']['size'][$i]>10*1024*1024){ flash('error','Each supporting PDF must not exceed 10 MB.'); header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit; }
      $ext=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));
      $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['supporting_documents']['tmp_name'][$i]);
      if($ext!=='pdf' || $mime!=='application/pdf'){ flash('error','Attached Supporting Documents must be PDF files only.'); header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit; }
      $safeName='ppmp_'.date('YmdHis').'_'.bin2hex(random_bytes(5)).'.pdf';
      if(!move_uploaded_file($_FILES['supporting_documents']['tmp_name'][$i],$uploadDir.'/'.$safeName)){ flash('error','Unable to save one or more supporting PDF files.'); header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit; }
      $existingDocs[]=['name'=>basename($originalName),'path'=>'uploads/ppmp/'.$safeName,'uploaded_at'=>date('Y-m-d H:i:s')];
    }
  }
  $supportingDocuments=$existingDocs ? json_encode($existingDocs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : '';
  if($action==='add'){
    $stSeries=$pdo->prepare("SELECT MAX(CASE WHEN ppmp_no REGEXP CONCAT('^PPMP-',?, '-[0-9]{4}
  $values=[
    $year,$ppmpNo,$areaId,(int)$_POST['category_id'],trim($_POST['item_name']),
    trim($_POST['description']??''),trim($_POST['procurement_type']??''),$qty,trim($_POST['unit']),
    trim($_POST['procurement_mode']??''),trim($_POST['preprocurement_conference']??''),
    trim($_POST['start_procurement']??''),trim($_POST['end_procurement']??''),trim($_POST['delivery_period']??''),
    trim($_POST['source_of_funds']??''),$unitPrice,$supportingDocuments,
    $requestedBy,trim($_POST['prepared_by']??''),$preparedPosition,
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
$areas=$pdo->query('SELECT a.*,d.name division_name,d.division_head authorized_person FROM areas a JOIN divisions d ON d.id=a.division_id ORDER BY d.name,a.name')->fetchAll();
$cats=$pdo->query("SELECT * FROM categories WHERE status='Active' ORDER BY name")->fetchAll();
$classifications=$pdo->query("SELECT name FROM classifications WHERE status='Active' ORDER BY name")->fetchAll();
$procurementMethods=$pdo->query("SELECT procurement_method,details FROM procurement_methods WHERE status='Active' ORDER BY procurement_method")->fetchAll();
$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();
$personnelByArea=[];
$stPersonnel=$pdo->query('SELECT id,area_id,name,position_designation FROM area_personnel ORDER BY area_id,name');
foreach($stPersonnel->fetchAll() as $person){ $personnelByArea[(int)$person['area_id']][]=$person; }

$where=' WHERE p.fiscal_year=?'; $args=[$year];
if($areaId>0){$where.=' AND p.area_id=?';$args[]=$areaId;}
if($q!==''){$where.=' AND (p.item_name LIKE ? OR p.description LIKE ? OR a.name LIKE ? OR c.name LIKE ?)';$args=[...$args,"%$q%","%$q%","%$q%","%$q%"];}
$sql='SELECT p.*,a.name area,d.name division_name,d.division_head authorized_person,c.name category FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN divisions d ON d.id=a.division_id JOIN categories c ON c.id=p.category_id'.$where.' ORDER BY p.id';
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
      <select class="select" name="year" aria-label="Search Fiscal Year">
        <?php foreach($searchFiscalYears as $searchYear): ?>
          <option value="<?=$searchYear?>" <?=$year===$searchYear?'selected':''?>><?=$searchYear?></option>
        <?php endforeach; ?>
      </select>
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
  <p class="muted">Complete the four sections below. Requested By personnel are based on the selected End-User / Implementing Unit.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>"><input type="hidden" name="existing_supporting_documents" value="<?=e($editing['supporting_documents']??'')?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e($editing['id'])?>"><?php endif; ?>

    <div class="ppmp-section">
      <h3>1. PPMP Identification and Requesting Personnel</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Fiscal Year *</label>
          <select class="select" name="fiscal_year" required>
            <?php foreach($entryFiscalYears as $entryYear): ?>
              <option value="<?=$entryYear?>" <?=((int)($editing['fiscal_year']??$year)===$entryYear)?'selected':''?>><?=$entryYear?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>PPMP No.</label><input class="input" name="ppmp_no" id="ppmp_no" readonly value="<?=e($editing['ppmp_no']??'')?>" placeholder="Auto-generated"></div>
        <div class="field"><label>End-User / Implementing Unit *</label>
          <select class="select" name="area_id" id="ppmp_area" required>
            <option value="">Select</option>
            <?php foreach($areas as $a):?>
              <option value="<?=$a['id']?>" data-person="<?=e($a['authorized_person']??'')?>" <?=((int)($editing['area_id']??0)===(int)$a['id'])?'selected':''?>><?=e($a['name'])?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="field"><label>Supervisor / Authorized Person *</label><input class="input" id="ppmp_person" name="prepared_by" value="<?=e($editing['prepared_by']??'')?>" readonly required></div>
        <div class="field"><label>Requested By</label>
          <select class="select" name="requested_by" id="ppmp_requested_by" data-current="<?=e($editing['requested_by']??'')?>">
            <option value="">Select</option>
            <?php foreach($personnelByArea as $personAreaId=>$people): foreach($people as $person): ?>
              <option value="<?=e($person['name'])?>" data-area-id="<?=$personAreaId?>" data-position="<?=e($person['position_designation']??'')?>"><?=e($person['name'])?></option>
            <?php endforeach; endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Prepared Position / Designation</label><input class="input" id="ppmp_prepared_position" name="prepared_position" value="<?=e($editing['prepared_position']??'')?>" readonly></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>2. Procurement Project Details</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Category *</label>
          <select class="select" name="category_id" required><option value="">Select</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>" <?=((int)($editing['category_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Type of Project / Classification *</label>
          <select class="select" name="procurement_type" required><option value="">Select</option><?php foreach($classifications as $c):?><option value="<?=e($c['name'])?>" <?=((string)($editing['procurement_type']??'')===(string)$c['name'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Item Name *</label><input class="input" name="item_name" required value="<?=e($editing['item_name']??'')?>"></div>
        <div class="field full"><label>General Description / Technical Specifications *</label><textarea class="input" name="description" rows="4" required><?=e($editing['description']??'')?></textarea></div>
        <div class="field"><label>Quantity *</label><input class="input" type="number" step="0.0001" min="0" name="quantity" id="ppmp_quantity" required value="<?=e($editing['quantity']??'')?>"></div>
        <div class="field"><label>Unit or Measurement / Size *</label><select class="select" name="unit" required><option value="">Select</option><?php foreach($units as $u):?><option value="<?=e($u['name'])?>" <?=((string)($editing['unit']??'')===(string)$u['name'])?'selected':''?>><?=e($u['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Unit Cost (PhP) *</label><input class="input" type="number" step="0.01" min="0" name="unit_price" id="ppmp_unit_price" required value="<?=e($editing['unit_price']??'')?>"></div>
        <div class="field"><label>Total Budget</label><input class="input" type="number" step="0.01" id="ppmp_total_budget" value="<?=e(((float)($editing['quantity']??0)*(float)($editing['unit_price']??0)) ?: '')?>" readonly></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>3. Procurement Method and Timeline</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Recommended Mode of Procurement *</label>
          <select class="select" name="procurement_mode" required><option value="">Select</option><?php foreach($procurementMethods as $method):?><option value="<?=e($method['procurement_method'])?>" <?=((string)($editing['procurement_mode']??'')===(string)$method['procurement_method'])?'selected':''?>><?=e($method['procurement_method'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Pre-Procurement Conference *</label><select class="select" name="preprocurement_conference" required><option value="">Select</option><option value="Yes" <?=($editing['preprocurement_conference']??'')==='Yes'?'selected':''?>>Yes</option><option value="No" <?=($editing['preprocurement_conference']??'')==='No'?'selected':''?>>No</option><option value="N/A" <?=($editing['preprocurement_conference']??'')==='N/A'?'selected':''?>>N/A</option></select></div>
        <div class="field"><label>Start of Procurement Activity *</label><input class="input" type="date" name="start_procurement" required value="<?=e($editing['start_procurement']??'')?>"></div>
        <div class="field"><label>End of Procurement Activity *</label><input class="input" type="date" name="end_procurement" required value="<?=e($editing['end_procurement']??'')?>"></div>
        <div class="field"><label>Expected Delivery / Implementation Period *</label><input class="input" type="date" name="delivery_period" required value="<?=e($editing['delivery_period']??'')?>"></div>
        <div class="field"><label>Source of Funds *</label><input class="input" name="source_of_funds" required value="<?=e($editing['source_of_funds']??'')?>"></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>4. Supporting Documents and Remarks</h3>
      <div class="ppmp-input-grid">
        <div class="field full">
          <label>Attached Supporting Documents (PDF only)</label>
          <input class="input" type="file" name="supporting_documents[]" accept="application/pdf,.pdf" multiple>
          <small class="muted">You may select multiple PDF files. Maximum 10 MB per file.</small>
          <?php
          $existingDocs=[];
          if(!empty($editing['supporting_documents'])){
            $decoded=json_decode($editing['supporting_documents'],true);
            if(is_array($decoded)) $existingDocs=$decoded;
          }
          ?>
          <?php if($existingDocs): ?><div class="ppmp-existing-files"><strong>Existing files:</strong><ul><?php foreach($existingDocs as $doc): ?><li><a href="<?=e($doc['path']??'#')?>" target="_blank"><?=e($doc['name']??'PDF document')?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
        </div>
        <div class="field full"><label>Remarks</label><textarea class="input" name="remarks" rows="3"><?=e($editing['remarks']??'')?></textarea></div>
      </div>
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
const requested=document.getElementById('ppmp_requested_by'), preparedPosition=document.getElementById('ppmp_prepared_position');
if(area){
  function syncSupervisor(){const o=area.options[area.selectedIndex]; if(person) person.value=o?(o.dataset.person||''):'';}
  function syncPreparedPosition(){if(!requested||!preparedPosition)return; const o=requested.options[requested.selectedIndex]; preparedPosition.value=(o&&!o.disabled)?(o.dataset.position||''):'';}
  function syncRequested(){
    if(!requested)return;
    const areaId=area.value; let current=requested.dataset.current||'';
    Array.from(requested.options).forEach(function(o,index){
      if(index===0){o.hidden=false;o.disabled=false;return;}
      const show=o.dataset.areaId===areaId; o.hidden=!show; o.disabled=!show;
    });
    requested.value=current; if(!requested.value) requested.value=''; syncPreparedPosition();
  }
  area.addEventListener('change',function(){requested.dataset.current='';syncSupervisor();syncRequested();});
  if(requested)requested.addEventListener('change',syncPreparedPosition);
  syncSupervisor(); syncRequested();
}
})();
</script>
<?php pageEnd();) THEN CAST(RIGHT(ppmp_no,4) AS UNSIGNED) ELSE 0 END) FROM ppmp_items WHERE fiscal_year=?");
    $stSeries->execute([$year,$year]);
    $nextSeries=((int)$stSeries->fetchColumn())+1;
    $ppmpNo='PPMP-'.$year.'-'.str_pad((string)$nextSeries,4,'0',STR_PAD_LEFT);
  }else{
    $ppmpNo=trim($_POST['ppmp_no']??($editing['ppmp_no']??''));
  }
  $values=[
    $year,trim($_POST['ppmp_no']??''),$areaId,(int)$_POST['category_id'],trim($_POST['item_name']),
    trim($_POST['description']??''),trim($_POST['procurement_type']??''),$qty,trim($_POST['unit']),
    trim($_POST['procurement_mode']??''),trim($_POST['preprocurement_conference']??''),
    trim($_POST['start_procurement']??''),trim($_POST['end_procurement']??''),trim($_POST['delivery_period']??''),
    trim($_POST['source_of_funds']??''),$unitPrice,trim($_POST['supporting_documents']??''),
    $requestedBy,trim($_POST['prepared_by']??''),$preparedPosition,
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
$areas=$pdo->query('SELECT a.*,d.name division_name,d.division_head authorized_person FROM areas a JOIN divisions d ON d.id=a.division_id ORDER BY d.name,a.name')->fetchAll();
$cats=$pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();
$personnelByArea=[];
$stPersonnel=$pdo->query('SELECT id,area_id,name,position_designation FROM area_personnel ORDER BY area_id,name');
foreach($stPersonnel->fetchAll() as $person){ $personnelByArea[(int)$person['area_id']][]=$person; }

$where=' WHERE p.fiscal_year=?'; $args=[$year];
if($areaId>0){$where.=' AND p.area_id=?';$args[]=$areaId;}
if($q!==''){$where.=' AND (p.item_name LIKE ? OR p.description LIKE ? OR a.name LIKE ? OR c.name LIKE ?)';$args=[...$args,"%$q%","%$q%","%$q%","%$q%"];}
$sql='SELECT p.*,a.name area,d.name division_name,d.division_head authorized_person,c.name category FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN divisions d ON d.id=a.division_id JOIN categories c ON c.id=p.category_id'.$where.' ORDER BY p.id';
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
      <select class="select" name="year" aria-label="Search Fiscal Year">
        <?php foreach($searchFiscalYears as $searchYear): ?>
          <option value="<?=$searchYear?>" <?=$year===$searchYear?'selected':''?>><?=$searchYear?></option>
        <?php endforeach; ?>
      </select>
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
  <p class="muted">Complete the four sections below. Requested By personnel are based on the selected End-User / Implementing Unit.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e($editing['id'])?>"><?php endif; ?>

    <div class="ppmp-section">
      <h3>1. PPMP Identification and Requesting Personnel</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Fiscal Year *</label>
          <select class="select" name="fiscal_year" required>
            <?php foreach($entryFiscalYears as $entryYear): ?>
              <option value="<?=$entryYear?>" <?=((int)($editing['fiscal_year']??$year)===$entryYear)?'selected':''?>><?=$entryYear?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>PPMP No.</label><input class="input" name="ppmp_no" id="ppmp_no" readonly value="<?=e($editing['ppmp_no']??'')?>" placeholder="Auto-generated"></div>
        <div class="field"><label>End-User / Implementing Unit *</label>
          <select class="select" name="area_id" id="ppmp_area" required>
            <option value="">Select</option>
            <?php foreach($areas as $a):?>
              <option value="<?=$a['id']?>" data-person="<?=e($a['authorized_person']??'')?>" <?=((int)($editing['area_id']??0)===(int)$a['id'])?'selected':''?>><?=e($a['name'])?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="field"><label>Supervisor / Authorized Person *</label><input class="input" id="ppmp_person" name="prepared_by" value="<?=e($editing['prepared_by']??'')?>" readonly required></div>
        <div class="field"><label>Requested By</label>
          <select class="select" name="requested_by" id="ppmp_requested_by" data-current="<?=e($editing['requested_by']??'')?>">
            <option value="">Select</option>
            <?php foreach($personnelByArea as $personAreaId=>$people): foreach($people as $person): ?>
              <option value="<?=e($person['name'])?>" data-area-id="<?=$personAreaId?>" data-position="<?=e($person['position_designation']??'')?>"><?=e($person['name'])?></option>
            <?php endforeach; endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Prepared Position / Designation</label><input class="input" id="ppmp_prepared_position" name="prepared_position" value="<?=e($editing['prepared_position']??'')?>" readonly></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>2. Procurement Project Details</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Category *</label>
          <select class="select" name="category_id" required><option value="">Select</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>" <?=((int)($editing['category_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Type of Project / Classification *</label>
          <select class="select" name="procurement_type" required><option value="">Select</option><?php foreach($classifications as $c):?><option value="<?=e($c['name'])?>" <?=((string)($editing['procurement_type']??'')===(string)$c['name'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Item Name *</label><input class="input" name="item_name" required value="<?=e($editing['item_name']??'')?>"></div>
        <div class="field full"><label>General Description / Technical Specifications *</label><textarea class="input" name="description" rows="4" required><?=e($editing['description']??'')?></textarea></div>
        <div class="field"><label>Quantity *</label><input class="input" type="number" step="0.0001" min="0" name="quantity" id="ppmp_quantity" required value="<?=e($editing['quantity']??'')?>"></div>
        <div class="field"><label>Unit or Measurement / Size *</label><select class="select" name="unit" required><option value="">Select</option><?php foreach($units as $u):?><option value="<?=e($u['name'])?>" <?=((string)($editing['unit']??'')===(string)$u['name'])?'selected':''?>><?=e($u['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Unit Cost (PhP) *</label><input class="input" type="number" step="0.01" min="0" name="unit_price" id="ppmp_unit_price" required value="<?=e($editing['unit_price']??'')?>"></div>
        <div class="field"><label>Total Budget</label><input class="input" type="number" step="0.01" id="ppmp_total_budget" value="<?=e(((float)($editing['quantity']??0)*(float)($editing['unit_price']??0)) ?: '')?>" readonly></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>3. Procurement Method and Timeline</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Recommended Mode of Procurement *</label>
          <select class="select" name="procurement_mode" required><option value="">Select</option><?php foreach($procurementMethods as $method):?><option value="<?=e($method['procurement_method'])?>" <?=((string)($editing['procurement_mode']??'')===(string)$method['procurement_method'])?'selected':''?>><?=e($method['procurement_method'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Pre-Procurement Conference *</label><select class="select" name="preprocurement_conference" required><option value="">Select</option><option value="Yes" <?=($editing['preprocurement_conference']??'')==='Yes'?'selected':''?>>Yes</option><option value="No" <?=($editing['preprocurement_conference']??'')==='No'?'selected':''?>>No</option><option value="N/A" <?=($editing['preprocurement_conference']??'')==='N/A'?'selected':''?>>N/A</option></select></div>
        <div class="field"><label>Start of Procurement Activity *</label><input class="input" type="date" name="start_procurement" required value="<?=e($editing['start_procurement']??'')?>"></div>
        <div class="field"><label>End of Procurement Activity *</label><input class="input" type="date" name="end_procurement" required value="<?=e($editing['end_procurement']??'')?>"></div>
        <div class="field"><label>Expected Delivery / Implementation Period *</label><input class="input" type="date" name="delivery_period" required value="<?=e($editing['delivery_period']??'')?>"></div>
        <div class="field"><label>Source of Funds *</label><input class="input" name="source_of_funds" required value="<?=e($editing['source_of_funds']??'')?>"></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>4. Supporting Documents and Remarks</h3>
      <div class="ppmp-input-grid">
        <div class="field full">
          <label>Attached Supporting Documents (PDF only)</label>
          <input class="input" type="file" name="supporting_documents[]" accept="application/pdf,.pdf" multiple>
          <small class="muted">You may select multiple PDF files. Maximum 10 MB per file.</small>
          <?php
          $existingDocs=[];
          if(!empty($editing['supporting_documents'])){
            $decoded=json_decode($editing['supporting_documents'],true);
            if(is_array($decoded)) $existingDocs=$decoded;
          }
          ?>
          <?php if($existingDocs): ?><div class="ppmp-existing-files"><strong>Existing files:</strong><ul><?php foreach($existingDocs as $doc): ?><li><a href="<?=e($doc['path']??'#')?>" target="_blank"><?=e($doc['name']??'PDF document')?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
        </div>
        <div class="field full"><label>Remarks</label><textarea class="input" name="remarks" rows="3"><?=e($editing['remarks']??'')?></textarea></div>
      </div>
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
const requested=document.getElementById('ppmp_requested_by'), preparedPosition=document.getElementById('ppmp_prepared_position');
if(area){
  function syncSupervisor(){const o=area.options[area.selectedIndex]; if(person) person.value=o?(o.dataset.person||''):'';}
  function syncPreparedPosition(){if(!requested||!preparedPosition)return; const o=requested.options[requested.selectedIndex]; preparedPosition.value=(o&&!o.disabled)?(o.dataset.position||''):'';}
  function syncRequested(){
    if(!requested)return;
    const areaId=area.value; let current=requested.dataset.current||'';
    Array.from(requested.options).forEach(function(o,index){
      if(index===0){o.hidden=false;o.disabled=false;return;}
      const show=o.dataset.areaId===areaId; o.hidden=!show; o.disabled=!show;
    });
    requested.value=current; if(!requested.value) requested.value=''; syncPreparedPosition();
  }
  area.addEventListener('change',function(){requested.dataset.current='';syncSupervisor();syncRequested();});
  if(requested)requested.addEventListener('change',syncPreparedPosition);
  syncSupervisor(); syncRequested();
}
})();
</script>
<?php pageEnd();