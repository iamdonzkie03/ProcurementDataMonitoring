<style>.ppmp-document-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,.8fr) auto;gap:10px;align-items:center;margin-bottom:10px}@media(max-width:899px){.ppmp-document-row{grid-template-columns:1fr}}</style><?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();
try{
  $col=$pdo->query("SHOW COLUMNS FROM ppmp_items LIKE 'saved_at'")->fetch();
  if(!$col) $pdo->exec("ALTER TABLE ppmp_items ADD COLUMN saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_at");
  $col=$pdo->query("SHOW COLUMNS FROM ppmp_items LIKE 'total_budget'")->fetch();
  if(!$col) $pdo->exec("ALTER TABLE ppmp_items ADD COLUMN total_budget DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER unit_price");
  // The old schema enforced one row per End-User/Fiscal Year. PPMP now
  // allows multiple item records under one PPMP number, so remove that legacy
  // unique index automatically for existing installations.
  $idx=$pdo->query("SHOW INDEX FROM ppmp_items WHERE Key_name='uq_ppmp_fiscal_year_area'")->fetch();
  if($idx) $pdo->exec("ALTER TABLE ppmp_items DROP INDEX uq_ppmp_fiscal_year_area");
}catch(PDOException $e){}

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

function ppmpSaveFormError(string $message,int $year,int $areaId,int $id=0): void{
  $_SESSION['ppmp_form_old']=$_POST;
  $_SESSION['ppmp_form_edit_id']=$id;
  flash('error',$message);
  header('Location:ppmp.php?year='.$year.'&area_id='.$areaId.($id>0?'&edit='.$id:'').'#ppmpForm');
  exit;
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
      ppmpSaveFormError('Requested By must be selected from the personnel assigned to the selected End-User / Implementing Unit.',$year,$areaId,$id);
    }
    $preparedPosition=trim($requestedPerson['position_designation']??'');
  }

  if(!in_array($year,$entryFiscalYears,true)){
    ppmpSaveFormError('Fiscal Year must be between '.$currentFiscalYear.' and '.($currentFiscalYear+3).'.',$currentFiscalYear,$areaId,$id);
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

  $qty=max(0,(float)str_replace(',','',$_POST['quantity']??0));
  $unitPrice=max(0,(float)str_replace(',','',$_POST['unit_price']??0));
  $totalBudget=$qty*$unitPrice;
  $supportingDocuments=trim($_POST['existing_supporting_documents']??'');
  $existingDocs=[];
  if($supportingDocuments!==''){ $decoded=json_decode($supportingDocuments,true); if(is_array($decoded)) $existingDocs=$decoded; }
  if(!empty($_FILES['supporting_documents']['name']) && is_array($_FILES['supporting_documents']['name'])){
    $uploadDir=__DIR__.'/uploads/ppmp';
    if(!is_dir($uploadDir)) @mkdir($uploadDir,0775,true);
    foreach($_FILES['supporting_documents']['name'] as $i=>$originalName){
      if($_FILES['supporting_documents']['error'][$i]===UPLOAD_ERR_NO_FILE) continue;
      if($_FILES['supporting_documents']['error'][$i]!==UPLOAD_ERR_OK){ ppmpSaveFormError('One or more supporting documents could not be uploaded.',$year,$areaId,$id); }
      if((int)$_FILES['supporting_documents']['size'][$i]>20*1024*1024){ ppmpSaveFormError('Each supporting PDF must not exceed 20 MB.',$year,$areaId,$id); }
      $ext=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));
      $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['supporting_documents']['tmp_name'][$i]);
      if($ext!=='pdf' || $mime!=='application/pdf'){ ppmpSaveFormError('Attached Supporting Documents must be PDF files only.',$year,$areaId,$id); }
      $documentName=trim((string)(($_POST['supporting_document_names']??[])[$i]??''));
      if($documentName===''){ ppmpSaveFormError('Please provide a Name for every Attached Supporting Document.',$year,$areaId,$id); }
      $safeName='ppmp_'.date('YmdHis').'_'.$i.'_'.bin2hex(random_bytes(5)).'.pdf';
      if(!move_uploaded_file($_FILES['supporting_documents']['tmp_name'][$i],$uploadDir.'/'.$safeName)){ ppmpSaveFormError('Unable to save one or more supporting PDF files.',$year,$areaId,$id); }
      $existingDocs[]=['name'=>$documentName,'original_name'=>basename($originalName),'path'=>'uploads/ppmp/'.$safeName,'uploaded_at'=>date('Y-m-d H:i:s')];
    }
  }
  $supportingDocuments=$existingDocs ? json_encode($existingDocs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : '';
  // One PPMP header/number is maintained per End-User / Implementing Unit and Fiscal Year.
  // Multiple item records may be added, edited, or deleted under that same PPMP.
  // When adding an item, reuse the existing PPMP number for the selected End-User/Fiscal Year.
  // A new PPMP number is generated only when that End-User/Fiscal Year has no PPMP yet.
  $stExistingPpmp=$pdo->prepare('SELECT ppmp_no FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no IS NOT NULL AND ppmp_no<>"" ORDER BY id LIMIT 1');
  $stExistingPpmp->execute([$year,$areaId]);
  $existingPpmpNo=trim((string)$stExistingPpmp->fetchColumn());

  if($action==='add'){
    if($existingPpmpNo!==''){
      $ppmpNo=$existingPpmpNo;
    }else{
      $stSeries=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(ppmp_no,'-',-1) AS UNSIGNED)) FROM ppmp_items WHERE fiscal_year=? AND ppmp_no LIKE CONCAT('PPMP-',?,'-%')");
      $stSeries->execute([$year,$year]);
      $nextSeries=((int)$stSeries->fetchColumn())+1;
      $ppmpNo='PPMP-'.$year.'-'.str_pad((string)$nextSeries,4,'0',STR_PAD_LEFT);
    }
  }else{
    // Keep the existing PPMP number when editing an item. If the item is realigned
    // to another End-User/Fiscal Year that already has a PPMP, use that PPMP number.
    $ppmpNo=$existingPpmpNo!=='' ? $existingPpmpNo : trim($_POST['ppmp_no']??($editing['ppmp_no']??''));
  }
  $values=[
    $year,$ppmpNo,$areaId,(int)$_POST['category_id'],trim($_POST['item_name']),
    trim($_POST['description']??''),trim($_POST['procurement_type']??''),$qty,trim($_POST['unit']),
    trim($_POST['procurement_mode']??''),trim($_POST['preprocurement_conference']??''),
    trim($_POST['start_procurement']??''),trim($_POST['end_procurement']??''),trim($_POST['delivery_period']??''),
    trim($_POST['source_of_funds']??''),$unitPrice,$totalBudget,$supportingDocuments,
    $requestedBy,trim($_POST['prepared_by']??''),$preparedPosition,
    '', '', '', '', null, null, null, trim($_POST['remarks']??'')
  ];
  if($action==='edit' && $id>0){
    $st=$pdo->prepare('UPDATE ppmp_items SET fiscal_year=?,ppmp_no=?,area_id=?,category_id=?,item_name=?,description=?,procurement_type=?,quantity=?,unit=?,procurement_mode=?,preprocurement_conference=?,start_procurement=?,end_procurement=?,delivery_period=?,source_of_funds=?,unit_price=?,total_budget=?,supporting_documents=?,requested_by=?,prepared_by=?,prepared_position=?,submitted_by=?,submitted_position=?,budget_approved_by=?,budget_position=?,prepared_date=?,submitted_date=?,budget_date=?,remarks=? WHERE id=?');
    $st->execute([...$values,$id]); flash('success','PPMP item updated.');
  }else{
    $st=$pdo->prepare('INSERT INTO ppmp_items
      (fiscal_year,ppmp_no,area_id,category_id,item_name,description,procurement_type,quantity,unit,procurement_mode,
       preprocurement_conference,start_procurement,end_procurement,delivery_period,source_of_funds,unit_price,total_budget,
       supporting_documents,requested_by,prepared_by,prepared_position,submitted_by,submitted_position,
       budget_approved_by,budget_position,prepared_date,submitted_date,budget_date,remarks,created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([...$values,currentUser()['id']]); flash('success','PPMP item saved.');
  }
  header('Location:ppmp.php?year='.$year.'&area_id='.$areaId); exit;
}
$formOld=$_SESSION['ppmp_form_old']??null;
$formOldEditId=(int)($_SESSION['ppmp_form_edit_id']??0);
unset($_SESSION['ppmp_form_old'],$_SESSION['ppmp_form_edit_id']);
$formState=$editing?:($formOld??[]);
$formIsEditing=$editing!==null || ($formOld!==null && (($formOld['action']??'')==='edit'));
$areas=$pdo->query('SELECT a.*,d.name division_name,d.division_head authorized_person,d.head_position_designation authorized_position FROM areas a JOIN divisions d ON d.id=a.division_id ORDER BY d.name,a.name')->fetchAll();
$cats=$pdo->query("SELECT * FROM categories WHERE status='Active' ORDER BY name")->fetchAll();
$classifications=$pdo->query("SELECT name FROM classifications WHERE status='Active' ORDER BY name")->fetchAll();
$procurementMethods=$pdo->query("SELECT procurement_method,details FROM procurement_methods WHERE status='Active' ORDER BY procurement_method")->fetchAll();
$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();
$nextPpmpNo='';
$ppmpNextByYear=[];
$stNextAll=$pdo->query('SELECT fiscal_year,ppmp_no FROM ppmp_items WHERE ppmp_no IS NOT NULL AND ppmp_no<>\'\' ORDER BY fiscal_year,ppmp_no');
foreach($stNextAll->fetchAll() as $seriesRow){
  $fy=(int)$seriesRow['fiscal_year'];
  $prefix='PPMP-'.$fy.'-';
  $number=(int)substr((string)$seriesRow['ppmp_no'],strlen($prefix));
  if(strpos((string)$seriesRow['ppmp_no'],$prefix)===0 && $number>0){
    $current=$ppmpNextByYear[$fy]??0;
    if($number>$current) $ppmpNextByYear[$fy]=$number;
  }
}
foreach($entryFiscalYears as $entryYear){
  $nextSeries=(int)($ppmpNextByYear[$entryYear]??0)+1;
  $ppmpNextByYear[$entryYear]='PPMP-'.$entryYear.'-'.str_pad((string)$nextSeries,4,'0',STR_PAD_LEFT);
}
$existingPpmpByYearArea=[];
$stExistingMap=$pdo->query('SELECT fiscal_year,area_id,MIN(ppmp_no) AS ppmp_no FROM ppmp_items WHERE ppmp_no IS NOT NULL AND ppmp_no<>\'\' GROUP BY fiscal_year,area_id');
foreach($stExistingMap->fetchAll() as $mapRow){
  $existingPpmpByYearArea[(int)$mapRow['fiscal_year'].':'.(int)$mapRow['area_id']]=(string)$mapRow['ppmp_no'];
}
if(!$editing){
  $existingForSelectedArea=$existingPpmpByYearArea[$year.':'.$areaId]??'';
  $nextPpmpNo=$existingForSelectedArea!=='' ? $existingForSelectedArea : ($ppmpNextByYear[$year]??('PPMP-'.$year.'-0001'));
}
$personnelByArea=[];
$stPersonnel=$pdo->query('SELECT id,area_id,name,position_designation,electronic_signature FROM area_personnel ORDER BY area_id,name');
foreach($stPersonnel->fetchAll() as $person){ $personnelByArea[(int)$person['area_id']][]=$person; }

$where=' WHERE p.fiscal_year=?'; $args=[$year];
if($areaId>0){$where.=' AND p.area_id=?';$args[]=$areaId;}
if($q!==''){$where.=' AND (p.item_name LIKE ? OR p.description LIKE ? OR a.name LIKE ? OR c.name LIKE ?)';$args=[...$args,"%$q%","%$q%","%$q%","%$q%"];}
$sql='SELECT p.*,a.name area,d.name division_name,d.division_head authorized_person,d.head_position_designation authorized_position,c.name category FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN divisions d ON d.id=a.division_id JOIN categories c ON c.id=p.category_id'.$where.' ORDER BY p.id';
$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll();

$selectedArea=null;
foreach($areas as $a){if((int)$a['id']===$areaId){$selectedArea=$a;break;}}
if($print && !$selectedArea && $rows){$areaId=(int)$rows[0]['area_id'];foreach($areas as $a){if((int)$a['id']===$areaId){$selectedArea=$a;break;}}}
if($print && !$selectedArea){flash('error','Select an Area/Unit before printing the PPMP form.');header('Location:ppmp.php?year='.$year);exit;}
if($print && $rows){
  $header=$rows[0];
  $selectedArea=$selectedArea ?: ['name'=>$header['area'],'authorized_person'=>$header['authorized_person'],'authorized_position'=>$header['authorized_position']??''];
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

<div class="panel ppmp-records">
  <h2>Saved PPMP Items — FY <?=$year?><?= $selectedArea?' / '.e($selectedArea['name']):'' ?></h2>
  <div class="table-wrap"><table class="table"><tr><th>PPMP No.</th><th>Item</th><th>Type</th><th>Qty / Unit</th><th>Mode</th><th>Unit Cost</th><th>Total Budget</th><th>Saved</th><th>Actions</th></tr>
  <?php foreach($rows as $r):?><tr><td><?=e($r['ppmp_no'])?></td><td><b><?=e($r['item_name'])?></b><br><small><?=e($r['description'])?></small></td><td><?=e($r['procurement_type'])?></td><td><?=number_format($r['quantity'],2).' '.e($r['unit'])?></td><td><?=e($r['procurement_mode'])?></td><td>₱<?=number_format($r['unit_price'],2)?></td><td>₱<?=number_format($r['quantity']*$r['unit_price'],2)?></td><td><?=!empty($r['saved_at'])?e(date('F j, Y g:i A',strtotime($r['saved_at']))):e(date('F j, Y g:i A',strtotime($r['created_at'])))?></td><td class="ppmp-actions-cell"><div class="ppmp-row-actions"><a class="btn secondary ppmp-action-btn" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>&edit=<?=$r['id']?>">Edit</a><form method="post" class="ppmp-delete-form" onsubmit="return confirm('Delete this PPMP item? This action cannot be undone.');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="fiscal_year" value="<?=e($year)?>"><input type="hidden" name="area_id" value="<?=e($areaId)?>"><button class="btn danger ppmp-action-btn" type="submit">Delete</button></form></div></td></tr><?php endforeach;?></table></div>
</div>
<div class="ppmp-entry panel" id="ppmpForm">
  <h2><?= $formIsEditing ? 'Edit PPMP Item' : 'Project Procurement Management Plan — Data Entry' ?></h2>
  <p class="muted">Complete the four sections below. Requested By personnel are based on the selected End-User / Implementing Unit.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $formIsEditing ? 'edit' : 'add' ?>"><input type="hidden" name="existing_supporting_documents" value="<?=e($formState['supporting_documents']??'')?>">
    <?php if($formIsEditing): ?><input type="hidden" name="id" value="<?=e($formState['id']??$formOldEditId)?>"><?php endif; ?>

    <div class="ppmp-section">
      <h3>1. PPMP Identification and Requesting Personnel</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Fiscal Year *</label>
          <select class="select" name="fiscal_year" id="ppmp_fiscal_year" required>
            <?php foreach($entryFiscalYears as $entryYear): ?>
              <option value="<?=$entryYear?>" data-ppmp-no="<?=e($existingPpmpByYearArea[$entryYear.':'.(int)($formState['area_id']??$areaId)]??($ppmpNextByYear[$entryYear]??('PPMP-'.$entryYear.'-0001')))?>" <?=((int)($formState['fiscal_year']??$year)===$entryYear)?'selected':''?>><?=$entryYear?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>PPMP No.</label><input class="input" name="ppmp_no" id="ppmp_no" readonly data-locked="<?=$formIsEditing ? '1' : '0'?>" value="<?=e($formState['ppmp_no']??$nextPpmpNo)?>" placeholder="Auto-generated"></div>
        <div class="field"><label>End-User / Implementing Unit *</label>
          <select class="select" name="area_id" id="ppmp_area" required>
            <option value="">Select</option>
            <?php foreach($areas as $a):?>
              <option value="<?=$a['id']?>" data-person="<?=e($a['authorized_person']??'')?>" <?php foreach($entryFiscalYears as $mapYear): ?>data-ppmp-<?=$mapYear?>="<?=e($existingPpmpByYearArea[$mapYear.':'.(int)$a['id']]??($ppmpNextByYear[$mapYear]??('PPMP-'.$mapYear.'-0001')))?>" <?php endforeach; ?> <?=((int)($formState['area_id']??0)===(int)$a['id'])?'selected':''?>><?=e($a['name'])?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="field"><label>Supervisor / Authorized Person *</label><input class="input" id="ppmp_person" name="prepared_by" value="<?=e($formState['prepared_by']??'')?>" readonly required></div>
        <div class="field"><label>Requested By</label>
          <select class="select" name="requested_by" id="ppmp_requested_by" data-current="<?=e($formState['requested_by']??'')?>">
            <option value="">Select</option>
            <?php foreach($personnelByArea as $personAreaId=>$people): foreach($people as $person): ?>
              <option value="<?=e($person['name'])?>" data-area-id="<?=$personAreaId?>" data-position="<?=e($person['position_designation']??'')?>"><?=e($person['name'])?></option>
            <?php endforeach; endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Prepared Position / Designation</label><input class="input" id="ppmp_prepared_position" name="prepared_position" value="<?=e($formState['prepared_position']??'')?>" readonly></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>2. Procurement Project Details</h3>
      <div class="ppmp-project-fields">
        <div class="field"><label>Category *</label>
          <select class="select" name="category_id" required><option value="">Select</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>" <?=((int)($formState['category_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Type of Project / Classification *</label>
          <select class="select" name="procurement_type" required><option value="">Select</option><?php foreach($classifications as $c):?><option value="<?=e($c['name'])?>" <?=((string)($formState['procurement_type']??'')===(string)$c['name'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Item Name *</label><input class="input" name="item_name" required value="<?=e($formState['item_name']??'')?>"></div>
      </div>

      <div class="ppmp-input-grid">
        <div class="field full"><label>General Description / Technical Specifications *</label><textarea class="input" name="description" rows="4" required><?=e($formState['description']??'')?></textarea></div>
        <div class="field ppmp-quantity-field"><label>Quantity *</label><input class="input ppmp-money-input" type="text" inputmode="decimal" name="quantity" id="ppmp_quantity" required value="<?=e(!empty($formState['quantity']) ? number_format((float)$formState['quantity'],2,'.',',') : '')?>"></div>
        <div class="field ppmp-unit-field"><label>Unit or Measurement / Size *</label><select class="select" name="unit" required><option value="">Select</option><?php foreach($units as $u):?><option value="<?=e($u['name'])?>" <?=((string)($formState['unit']??'')===(string)$u['name'])?'selected':''?>><?=e($u['name'])?></option><?php endforeach;?></select></div>
        <div class="field ppmp-unit-cost-field"><label>Unit Cost (PhP) *</label><input class="input ppmp-money-input" type="text" inputmode="decimal" name="unit_price" id="ppmp_unit_price" required value="<?=e(!empty($formState['unit_price']) ? number_format((float)$formState['unit_price'],2,'.',',') : '')?>"></div>
        <div class="field ppmp-total-field"><label>Total Budget</label><input class="input ppmp-total-budget" type="text" id="ppmp_total_budget" value="<?=e(((float)($formState['quantity']??0)*(float)($formState['unit_price']??0)) ? number_format((float)$formState['quantity']*(float)$formState['unit_price'],2,'.',',') : '')?>" readonly></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>3. Procurement Method and Timeline</h3>
      <div class="ppmp-input-grid">
        <div class="field"><label>Recommended Mode of Procurement *</label>
          <select class="select" name="procurement_mode" required><option value="">Select</option><?php foreach($procurementMethods as $method):?><option value="<?=e($method['procurement_method'])?>" <?=((string)($formState['procurement_mode']??'')===(string)$method['procurement_method'])?'selected':''?>><?=e($method['procurement_method'])?></option><?php endforeach;?></select>
        </div>
        <div class="field"><label>Pre-Procurement Conference *</label><select class="select" name="preprocurement_conference" required><option value="">Select</option><option value="Yes" <?=($formState['preprocurement_conference']??'')==='Yes'?'selected':''?>>Yes</option><option value="No" <?=($formState['preprocurement_conference']??'')==='No'?'selected':''?>>No</option><option value="N/A" <?=($formState['preprocurement_conference']??'')==='N/A'?'selected':''?>>N/A</option></select></div>
        <?php $startDate=!empty($formState['start_procurement'])?date('F d, Y',strtotime($formState['start_procurement'])):''; $endDate=!empty($formState['end_procurement'])?date('F d, Y',strtotime($formState['end_procurement'])):''; $deliveryDate=!empty($formState['delivery_period'])?date('F d, Y',strtotime($formState['delivery_period'])):''; ?>
        <div class="field"><label>Start of Procurement Activity *</label><div class="ppmp-date-picker"><input class="input ppmp-long-date" type="text" name="start_procurement_display" data-date-target="start_procurement" placeholder="October 03, 2026" value="<?=e($startDate)?>" autocomplete="off" required><input type="date" class="ppmp-date-native" id="start_procurement_picker" aria-hidden="true" tabindex="-1"><input type="hidden" name="start_procurement" id="start_procurement"></div></div>
        <div class="field"><label>End of Procurement Activity *</label><div class="ppmp-date-picker"><input class="input ppmp-long-date" type="text" name="end_procurement_display" data-date-target="end_procurement" placeholder="October 03, 2026" value="<?=e($endDate)?>" autocomplete="off" required><input type="date" class="ppmp-date-native" id="end_procurement_picker" aria-hidden="true" tabindex="-1"><input type="hidden" name="end_procurement" id="end_procurement"></div></div>
        <div class="field"><label>Expected Delivery / Implementation Period *</label><div class="ppmp-date-picker"><input class="input ppmp-long-date" type="text" name="delivery_period_display" data-date-target="delivery_period" placeholder="October 03, 2026" value="<?=e($deliveryDate)?>" autocomplete="off" required><input type="date" class="ppmp-date-native" id="delivery_period_picker" aria-hidden="true" tabindex="-1"><input type="hidden" name="delivery_period" id="delivery_period"></div></div>
        <div class="field"><label>Source of Funds *</label><input class="input" name="source_of_funds" required value="<?=e($formState['source_of_funds']??'')?>"></div>
      </div>
    </div>

    <div class="ppmp-section">
      <h3>4. Supporting Documents and Remarks</h3>
      <div class="ppmp-input-grid">
        <div class="field full">
          <label>Attached Supporting Documents (PDF only)</label>
          <div id="ppmp-supporting-documents"><div class="ppmp-document-row"><input class="input" type="file" name="supporting_documents[]" accept="application/pdf,.pdf"><input class="input" type="text" name="supporting_document_names[]" placeholder="Name of document"><button class="btn secondary ppmp-remove-document" type="button">Remove</button></div></div>
          <button class="btn secondary" type="button" id="ppmp-add-document">+ Add Another Document</button>
          <small class="muted">Multiple PDF files are allowed. Each file must not exceed 20 MB. Give each file a descriptive Name.</small>
          <?php
          $existingDocs=[];
          if(!empty($formState['supporting_documents'])){
            $decoded=json_decode($formState['supporting_documents'],true);
            if(is_array($decoded)) $existingDocs=$decoded;
          }
          ?>
          <?php if($existingDocs): ?><div class="ppmp-existing-files"><strong>Existing files:</strong><ul><?php foreach($existingDocs as $doc): ?><li><a href="<?=e($doc['path']??'#')?>" target="_blank"><?=e($doc['name']??'PDF document')?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
        </div>
        <div class="field full"><label>Remarks</label><textarea class="input" name="remarks" rows="3"><?=e($formState['remarks']??'')?></textarea></div>
      </div>
    </div>

    <div class="actions"><button class="btn" type="submit"><?= $formIsEditing ? 'Save Changes' : 'Save PPMP Item' ?></button><?php if($formIsEditing): ?><a class="btn secondary" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>">Cancel</a><?php endif; ?></div>
  </form>
</div>
<?php endif; ?>
<?php
$h=$rows[0]??[];
$ppmpNo=$h['ppmp_no']??'';
$person=$h['requested_by']??'';
$preparedPos=$h['prepared_position']??'End-User or Implementing Unit';
$preparedSignature='';
if($person!=='' && !empty($h['area_id'])){
  $stPreparedSignature=$pdo->prepare('SELECT electronic_signature FROM area_personnel WHERE area_id=? AND name=? LIMIT 1');
  $stPreparedSignature->execute([(int)$h['area_id'],$person]);
  $preparedSignature=trim((string)($stPreparedSignature->fetchColumn()??''));
}
$submitted=$selectedArea['authorized_person']??($h['authorized_person']??'');
$submittedPos=$selectedArea['authorized_position']??($h['authorized_position']??'');
// Budget signatory comes from the Area/Unit master list.
// IMPORTANT: do not inspect the selected PPMP Area/Unit personnel and do not
// look for the word "Budget" in a person's name/position. Instead, locate the
// Area/Unit record whose own Area/Unit name identifies it as Budget, then pull
// the person(s) attached to that Area/Unit.
$budgetName='';
$budgetPos='';
$budgetAreaId=0;

// Match Budget, Budget Unit, Budget Section, Budget Office, etc.
// The Area/Unit name is the source of truth for the Budget signatory.
$stBudgetArea=$pdo->query("SELECT id,name FROM areas
  WHERE LOWER(name) REGEXP '(^|[[:space:]-])(budget)([[:space:]-]|$)'
     OR LOWER(name) LIKE 'budget %'
     OR LOWER(name) LIKE '% budget'
  ORDER BY
    CASE
      WHEN LOWER(name)='budget' THEN 0
      WHEN LOWER(name) LIKE 'budget %' THEN 1
      WHEN LOWER(name) LIKE '%budget%' THEN 2
      ELSE 3
    END, name
  LIMIT 1")->fetch();

if($stBudgetArea){
  $budgetAreaId=(int)$stBudgetArea['id'];
  $stBudgetPeople=$pdo->prepare('SELECT name,position_designation
    FROM area_personnel
    WHERE area_id=?
    ORDER BY id');
  $stBudgetPeople->execute([$budgetAreaId]);
  $budgetPeople=$stBudgetPeople->fetchAll();

  // Use the first configured person under the Budget Area/Unit.
  // Their stored Position/Designation is printed as-is.
  if($budgetPeople){
    $budgetName=trim((string)($budgetPeople[0]['name']??''));
    $budgetPos=trim((string)($budgetPeople[0]['position_designation']??''));
  }
}
function ppmpPrintDate($value): string{
  $value=trim((string)$value);
  if($value==='') return '';
  $timestamp=strtotime($value);
  return $timestamp ? date('F d, Y',$timestamp) : $value;
}
?>
<?php if($print): ?>
<div class="ppmp-print-sheet paper-long" id="ppmpPrintSheet">
  <div class="ppmp-head">
    <div class="ppmp-brand">
      <div class="ppmp-brand-mark">ZCMC</div>
      <div><div>Republic of the Philippines</div><div>Department of Health</div><strong>ZAMBOANGA CITY MEDICAL CENTER</strong><small>Dr. D. Evangelista St., Sta. Catalina, Zamboanga City 7000</small></div>
    </div>
    <div class="ppmp-control"><div>Form No.: ZCMC-F-PROC-01</div><div>Revision No.: 1</div><div>Effectivity Date: February 11, 2026</div></div>
  </div>

  <div class="ppmp-title">PROJECT PROCUREMENT MANAGEMENT PLAN (PPMP) NO. <span class="line"><?=e($ppmpNo)?></span></div>
  <div class="ppmp-classification"><span>☐ INDICATIVE</span><span>☐ FINAL</span></div>
  <div class="ppmp-meta">
    <div><b>Fiscal Year :</b> <?=e($year)?></div>
    <div><b>End-User or Implementing Unit:</b> <?=e($selectedArea['name'])?></div>
  </div>

  <?php
  $ppmpExplanations=[
    'Refers to the type of procurement—whether for Goods (e.g., supplies, materials, ICT equipment, medicines); Infrastructure Projects (e.g., roads, buildings, site development or land improvement, public utilities such as water systems or flood control); Consulting Services (e.g., feasibility studies, advisory and management consulting, training and capacity building); or General Support Services (e.g., security, janitorial, transportation and logistics, training and event management). It also describes the objective of the project.',
    'Refers to quantity / size of the contract (whether by lot, item or package). If items are too many to use this column, a separate attachment may be included)\n\nItem refers to the smallest unit or individual good/service being procured.\nLot refers to a group or related items bundled together in one bidding.\nPackage is a collection of one or more lots grouped under a single procurement project.\n\nIt is sufficient to indicate that this information is reflected in the Technical Specifications, Scope of Work (SOW), or Terms of Reference (TOR) (as applicable) when the latter is attached to this PPMP.',
    'Indicate applicable procurement mode under RA No. 12009 recommended by the End-User.',
    'Indicate the projected month (MM/YYYY) of the Pre-procurement Conference.',
    'Indicate the projected month (MM/YYYY) of the start of procurement activity which will depend on the applicable mode of procurement used by the Procuring Entity.',
    'Indicate the projected month (MM/YYYY) of issuance of Notice of Award or Purchase Order, as the case may be, based on the prescribed procurement timelines.',
    'This refers to the target start date (MM/YYYY) when the delivery of goods, implementation of infrastructure projects, or provision of consulting services is expected to begin, indicating when the project is needed. The End-User may indicate "as needed" if the project is on a per-need basis.',
    'The fund source for the payment of the project to be procured may include, but shall not be limited to GAA, Corporate Operating Budget, Internally Generated Funds, General or other sources of funds for LGUs, Special Purpose Funds or Trust Funds, and Foreign Assisted Projects (FAPs).',
    '',
    'Use Estimated Budget when the GAA or other appropriate fund source has not yet been passed or approved and Authorized Budgetary Allocation when the GAA or other appropriate fund source has been passed or approved. These amounts are determined after market scoping.',
    '',
    'This column refers to additional details regarding the project, such as basis of changes from previous PPMP, contract package details, procurement strategies, and recommended award criterion.'
  ];
  $printRows=$rows;
  if(count($printRows)<2) $printRows=array_pad($printRows,2,null);
  $grandTotal=0;
  foreach($rows as $gr){$grandTotal+=(float)$gr['quantity']*(float)$gr['unit_price'];}
  ?>

  <table class="ppmp-official-table ppmp-excel-template">
    <colgroup>
      <col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"><col class="c6"><col class="c7"><col class="c8"><col class="c9"><col class="c10"><col class="c11"><col class="c12"><col class="c13"><col class="c14">
    </colgroup>
    <thead>
      <tr class="group-head">
        <th colspan="6">PROCUREMENT PROJECT DETAILS</th>
        <th colspan="3">PROJECTED TIMELINE (MM/YYYY)</th>
        <th colspan="3">FUNDING DETAILS</th>
        <th rowspan="3">ATTACHED SUPPORTING DOCUMENTS</th>
        <th rowspan="3">REMARKS</th>
      </tr>
      <tr class="field-head">
        <th rowspan="2">General Description and Objective<br>of the Project to be Procured</th>
        <th rowspan="2">Type of the Project to be Procured<br>(whether Goods, Infrastructure and Consulting Services)</th>
        <th colspan="2">Quantity and Size of the Project to be Procured</th>
        <th rowspan="2">Recommended Mode of Procurement</th>
        <th rowspan="2">Pre-Procurement Conference, if applicable (Yes/No)</th>
        <th rowspan="2">Start of Procurement Activity</th>
        <th rowspan="2">End of Procurement Activity</th>
        <th rowspan="2">Expected Delivery / Implementation Period</th>
        <th rowspan="2">Source of Funds</th>
        <th rowspan="2">Unit Cost</th>
        <th rowspan="2">Estimated Budget / Authorized Budgetary Allocation (PhP)</th>
      </tr>
      <tr class="quantity-head">
        <th>Quantity</th>
        <th>Unit or Measurement/Size</th>
      </tr>
      <tr class="column-head">
        <th>Column 1</th><th>Column 2</th><th colspan="2">Column 3</th><th>Column 4</th><th>Column 5</th><th>Column 6</th><th>Column 7</th><th>Column 8</th><th>Column 9</th><th>Column 9A</th><th>Column 10</th><th>Column 11</th><th>Column 12</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr class="data-row template-data-row">
        <td><?=e(trim((string)$r['item_name']).', '.trim((string)$r['description']))?></td>
        <td><?=e($r['procurement_type'])?></td>
        <td><?=e(rtrim(rtrim(number_format((float)$r['quantity'],4,'.',''), '0'),'.'))?></td>
        <td><?=e($r['unit'])?></td>
        <td><?=e($r['procurement_mode'])?></td>
        <td><?=e($r['preprocurement_conference'])?></td>
        <td><?=e(ppmpPrintDate($r['start_procurement']))?></td>
        <td><?=e(ppmpPrintDate($r['end_procurement']))?></td>
        <td><?=e(ppmpPrintDate($r['delivery_period']))?></td>
        <td><?=e($r['source_of_funds'])?></td>
        <td>₱<?=number_format((float)$r['unit_price'],2)?></td>
        <td>₱<?=number_format((float)$r['quantity']*(float)$r['unit_price'],2)?></td>
        <td><?php
          $printDocs=[];
          if(!empty($r['supporting_documents'])){
            $decodedDocs=json_decode((string)$r['supporting_documents'],true);
            if(is_array($decodedDocs)){
              foreach($decodedDocs as $doc){
                if(is_array($doc)){
                  $docName=trim((string)($doc['name']??''));
                  if($docName!=='') $printDocs[]=$docName;
                }
              }
            }
          }
          echo $printDocs ? implode('<br>',array_map('e',$printDocs)) : '';
        ?></td>
        <td><?=nl2br(e($r['remarks']))?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="grand-total-row">
        <td colspan="10"></td>
        <td><b>GRAND TOTAL</b></td>
        <td><b>₱<?=number_format($grandTotal,2)?></b></td>
        <td></td><td></td>
      </tr>
    </tbody>
  </table>

  <div class="ppmp-note"><b>Important Note:</b> The Market Scoping Form and its proof of documentation and activities shall be attached to this PPMP prior to approval. Failure to provide both the Market Scoping Form and proof of documentation shall result to deferment or rejection of this PPMP.</div>

  <div class="ppmp-signatures ppmp-signature-template">
    <div class="ppmp-signature-box">
      <b>Prepared by:</b>
      <div class="signature-line ppmp-prepared-signature"><?php if($preparedSignature!==''): ?><img src="<?=e($preparedSignature)?>" alt="Prepared By electronic signature"><?php endif; ?><span><?=e($person)?></span></div>
      <div>Signature over Printed Name</div>
      <div><?=e($preparedPos)?></div>
      <div><i>End-User or Implementing Unit</i></div>
      <div>Date : <?=e(ppmpPrintDate($h['updated_at']??$h['created_at']??date('Y-m-d'))) ?></div>
    </div>
    <div class="ppmp-signature-box">
      <b>Submitted by:</b>
      <div class="signature-line"><?=e($submitted)?></div>
      <div>Signature over Printed Name</div>
      <div><?=e($submittedPos)?></div>
      <div><i>Division/Department/Section Unit</i></div>
      <div>Date : ______________________________</div>
    </div>
    <div class="ppmp-signature-box ppmp-budget-signature">
      <b>within the budget allocation:</b>
      <div class="signature-line"><?=e($budgetName)?></div>
      <div>Signature over Printed Name</div>
      <div><?=e($budgetPos)?></div>
      <div><i>Budget Section</i></div>
      <div>Date : ______________________________</div>
    </div>
  </div>
</div>
<div class="ppmp-print-actions">
  <button class="btn" onclick="printPpmp('long')">Print 8.5 × 13</button>
  <button class="btn secondary" onclick="printPpmp('a4')">Print A4</button>
  <a class="btn secondary" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>">Back</a>
</div>
<?php endif; ?>
<script>
function printPpmp(paper){
  const sheet=document.getElementById('ppmpPrintSheet');
  if(sheet){sheet.classList.remove('paper-a4','paper-long');sheet.classList.add(paper==='a4'?'paper-a4':'paper-long');}
  window.print();
}
(function(){
 const fiscalYear=document.getElementById('ppmp_fiscal_year'), ppmpNo=document.getElementById('ppmp_no');
 if(fiscalYear&&ppmpNo&&ppmpNo.dataset.locked!=='1'){
   function syncPpmpNumber(){
      const fy=fiscalYear.value, ao=document.getElementById('ppmp_area'), areaOpt=ao&&ao.options[ao.selectedIndex];
      const mapped=areaOpt&&fy ? areaOpt.getAttribute('data-ppmp-'+fy) : '';
      const o=fiscalYear.options[fiscalYear.selectedIndex];
      ppmpNo.value=mapped || (o?(o.getAttribute('data-ppmp-no')||''):'');
    }
   fiscalYear.addEventListener('change',syncPpmpNumber);
   syncPpmpNumber();
 }
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
 const quantity=document.getElementById('ppmp_quantity'), unitPrice=document.getElementById('ppmp_unit_price'), totalBudget=document.getElementById('ppmp_total_budget');
 const supportingDocuments=document.getElementById('ppmp-supporting-documents'), addDocument=document.getElementById('ppmp-add-document');
 function syncDocumentRows(){if(!supportingDocuments)return; supportingDocuments.querySelectorAll('.ppmp-document-row').forEach(function(row,index){const remove=row.querySelector('.ppmp-remove-document');if(remove)remove.style.display=index===0?'none':'inline-flex';});}
 function addDocumentRow(){if(!supportingDocuments)return;const row=document.createElement('div');row.className='ppmp-document-row';row.innerHTML='<input class="input" type="file" name="supporting_documents[]" accept="application/pdf,.pdf"><input class="input" type="text" name="supporting_document_names[]" placeholder="Name of document"><button class="btn secondary ppmp-remove-document" type="button">Remove</button>';supportingDocuments.appendChild(row);syncDocumentRows();}
 if(addDocument)addDocument.addEventListener('click',addDocumentRow);
 if(supportingDocuments)supportingDocuments.addEventListener('click',function(e){if(e.target.classList.contains('ppmp-remove-document')){const row=e.target.closest('.ppmp-document-row');if(row)row.remove();syncDocumentRows();}});
 syncDocumentRows();
 function moneyNumber(value){return parseFloat(String(value||'').replace(/,/g,''))||0;}
 function formatMoney(value){return Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function syncTotalBudget(){if(!quantity||!unitPrice||!totalBudget)return; const q=moneyNumber(quantity.value), p=moneyNumber(unitPrice.value); totalBudget.value=formatMoney(q*p);}
 function liveFormatMoney(field){
   if(!field)return;
   const raw=String(field.value||'').replace(/[^0-9.]/g,'');
   if(raw===''){field.value='';syncTotalBudget();return;}
   const parts=raw.split('.');
   let integer=(parts[0]||'0').replace(/^0+(?=\d)/,'');
   integer=integer.replace(/\B(?=(\d{3})+(?!\d))/g,',');
   if(parts.length>1){
     field.value=integer+'.'+(parts.slice(1).join('').slice(0,2));
   }else{
     field.value=integer;
   }
   syncTotalBudget();
 }
 function finalizeMoney(field){
   if(!field||field.value==='')return;
   field.value=formatMoney(moneyNumber(field.value));
   syncTotalBudget();
 }
 [quantity,unitPrice].forEach(function(field){
   if(!field)return;
   field.addEventListener('input',function(){liveFormatMoney(this);});
   field.addEventListener('blur',function(){finalizeMoney(this);});
   field.addEventListener('focus',function(){
     if(this.value==='0.00')this.select();
   });
 });
 if(quantity&&unitPrice){
   // Initialize Edit PPMP Item exactly like Add PPMP Item: format existing
   // Quantity and Unit Cost immediately and recalculate Total Budget.
   if(quantity.value!=='') liveFormatMoney(quantity);
   if(unitPrice.value!=='') liveFormatMoney(unitPrice);
   syncTotalBudget();
 }

})();
</script>

<style>
.ppmp-date-picker{position:relative}
.ppmp-date-picker .ppmp-date-native{position:absolute;inset:0;width:100%;height:100%;opacity:0;pointer-events:none}
.ppmp-long-date{font-variant-numeric:tabular-nums;cursor:pointer}
.ppmp-entry .ppmp-quantity-field,
.ppmp-entry .ppmp-unit-field,
.ppmp-entry .ppmp-unit-cost-field,
.ppmp-entry .ppmp-total-field{
  min-width:0
}
.ppmp-entry .ppmp-project-fields{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1.4fr);
  gap:16px;
  align-items:end
}
.ppmp-entry .ppmp-project-fields .field{min-width:0}
.ppmp-entry .ppmp-quantity-field{grid-column:span 3}
.ppmp-entry .ppmp-unit-field{grid-column:span 6}
.ppmp-entry .ppmp-unit-cost-field{grid-column:span 4}
.ppmp-entry .ppmp-total-field{grid-column:span 7}
.ppmp-entry .ppmp-quantity-field input{
  text-align:left!important
}
.ppmp-entry .ppmp-total-budget{
  font-size:25px!important
}
@media (min-width: 900px){
  .ppmp-entry .ppmp-input-grid:has(.ppmp-quantity-field){
    grid-template-columns:repeat(20,minmax(0,1fr))
  }
  .ppmp-entry .ppmp-quantity-field{grid-column:span 3}
  .ppmp-entry .ppmp-unit-field{grid-column:span 6}
  .ppmp-entry .ppmp-unit-cost-field{grid-column:span 4}
  .ppmp-entry .ppmp-total-field{grid-column:span 7}
}
@media (max-width: 899px){
  .ppmp-entry .ppmp-project-fields{grid-template-columns:1fr}
  .ppmp-entry .ppmp-quantity-field,
  .ppmp-entry .ppmp-unit-field,
  .ppmp-entry .ppmp-unit-cost-field,
  .ppmp-entry .ppmp-total-field{grid-column:1/-1}
}
</style>
<script>
(function(){
  function displayDate(iso){
    if(!iso)return '';
    const d=new Date(iso+'T00:00:00');
    return d.toLocaleDateString('en-US',{month:'long',day:'2-digit',year:'numeric'});
  }
  function isoDate(display){
    const m=String(display||'').trim().match(/^(January|February|March|April|May|June|July|August|September|October|November|December)\\s+(\\d{1,2}),\\s+(\\d{4})$/i);
    if(!m)return '';
    const months={january:1,february:2,march:3,april:4,may:5,june:6,july:7,august:8,september:9,october:10,november:11,december:12};
    return m[3]+'-'+String(months[m[1].toLowerCase()]).padStart(2,'0')+'-'+String(m[2]).padStart(2,'0');
  }
  document.querySelectorAll('.ppmp-date-picker').forEach(function(box){
    const display=box.querySelector('.ppmp-long-date'), picker=box.querySelector('.ppmp-date-native'), hidden=box.querySelector('input[type="hidden"]');
    if(!display||!picker||!hidden)return;
    const existing=isoDate(display.value);
    if(existing){picker.value=existing;hidden.value=existing;}
    function openPicker(){
      try{
        if(typeof picker.showPicker==='function') picker.showPicker();
        else {picker.style.pointerEvents='auto';picker.click();picker.style.pointerEvents='none';}
      }catch(e){picker.style.pointerEvents='auto';picker.click();picker.style.pointerEvents='none';}
    }
    display.addEventListener('click',openPicker);
    display.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();openPicker();}});
    picker.addEventListener('change',function(){hidden.value=this.value;display.value=displayDate(this.value);});
    display.addEventListener('input',function(){
      const iso=isoDate(this.value);
      hidden.value=iso;
      if(iso)picker.value=iso;
    });
    display.addEventListener('blur',function(){
      const iso=isoDate(this.value);
      if(iso)this.value=displayDate(iso);
    });
  });
})();
</script>
