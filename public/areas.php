<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';
$embedded=!empty($embedded);
$pdo=db();

try{
  $cols=$pdo->query("SHOW COLUMNS FROM divisions LIKE 'head_position_designation'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE divisions ADD COLUMN head_position_designation VARCHAR(150) NULL AFTER division_head");
  $cols=$pdo->query("SHOW COLUMNS FROM divisions LIKE 'electronic_signature'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE divisions ADD COLUMN electronic_signature VARCHAR(255) NULL AFTER head_position_designation");
  $cols=$pdo->query("SHOW COLUMNS FROM divisions LIKE 'ppmp_supervisor_enabled'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE divisions ADD COLUMN ppmp_supervisor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER electronic_signature");
  $cols=$pdo->query("SHOW COLUMNS FROM area_personnel LIKE 'position_designation'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE area_personnel ADD COLUMN position_designation VARCHAR(150) NULL AFTER name");
  $cols=$pdo->query("SHOW COLUMNS FROM area_personnel LIKE 'electronic_signature'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE area_personnel ADD COLUMN electronic_signature VARCHAR(255) NULL AFTER position_designation");
}catch(PDOException $e){ /* Migration can also be applied manually. */ }

function signatureSafeSegment(string $value): string{
  $value=trim($value);
  $value=str_replace(['\\\\','/',':','*','?','"','<','>','|'], '', $value);
  $value=preg_replace('/\\s+/u',' ',$value);
  return trim($value," .\\t\\n\\r\\0\\x0B") ?: 'Unnamed';
}

function deleteStoredSignature(?string $relativePath): void{
  $relativePath=trim((string)$relativePath);
  if($relativePath==='' || str_contains($relativePath,'..')) return;
  $base=realpath(__DIR__.'/uploads/signatures');
  $file=__DIR__.'/'.$relativePath;
  $parent=realpath(dirname($file));
  if($base!==false && $parent!==false && ($parent===$base || str_starts_with($parent,$base.DIRECTORY_SEPARATOR)) && is_file($file)) @unlink($file);
}

function saveElectronicSignatureData(string $data, array $folders, string $personName): string{
  if($data==='') throw new RuntimeException('No electronic signature was selected.');
  if(!preg_match('/^data:(image\\/(?:png|jpeg));base64,(.+)$/s',$data,$m)) throw new RuntimeException('Electronic signature must be a PNG or JPG image.');
  $binary=base64_decode($m[2],true);
  if($binary===false || $binary==='') throw new RuntimeException('The electronic signature data could not be decoded.');
  if(strlen($binary)>2*1024*1024) throw new RuntimeException('Electronic signature must not exceed 2 MB.');
  $imageInfo=@getimagesizefromstring($binary);
  if($imageInfo===false || !in_array((string)($imageInfo['mime']??''),['image/png','image/jpeg'],true) || (string)$imageInfo['mime']!==$m[1]) throw new RuntimeException('Electronic signature must be a valid PNG or JPG image.');
  if(!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) throw new RuntimeException('The PHP GD extension is required to save signatures in JPG format.');
  $image=@imagecreatefromstring($binary);
  if(!$image) throw new RuntimeException('The electronic signature image could not be processed.');
  $segments=array_map(static fn($part)=>signatureSafeSegment((string)$part),$folders);
  $dir=__DIR__.'/uploads/signatures';
  foreach($segments as $segment){
    $dir.='/'.$segment;
    if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)){
      imagedestroy($image);
      throw new RuntimeException('Unable to create the signature folder. Check folder permissions.');
    }
  }
  $filename=signatureSafeSegment($personName).'.jpg';
  $destination=$dir.'/'.$filename;
  $temp=$destination.'.'.bin2hex(random_bytes(4)).'.tmp';
  $saved=@imagejpeg($image,$temp,92);
  imagedestroy($image);
  if(!$saved || !is_file($temp)){
    if(is_file($temp)) @unlink($temp);
    throw new RuntimeException('Unable to save the JPG signature. Check that the signature upload folder is writable.');
  }
  if(!@rename($temp,$destination)){
    @unlink($temp);
    throw new RuntimeException('Unable to finalize the JPG signature file.');
  }
  return 'uploads/signatures/'.implode('/',$segments).'/'.$filename;
}

$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0){
  $st=$pdo->prepare('SELECT a.*,d.name division_name FROM areas a LEFT JOIN divisions d ON d.id=a.division_id WHERE a.id=?');
  $st->execute([$editId]);
  $editing=$st->fetch();
  if(!$editing){ flash('error','Area/Unit not found.'); header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit; }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $action=$_POST['action']??'add';
  $id=(int)($_POST['id']??0);
  $divisionId=(int)($_POST['division_id']??0);
  $name=trim($_POST['name']??'');
  $code=trim($_POST['code']??'') ?: null;
  $signatureData=trim((string)($_POST['electronic_signature_data']??''));

  if($action==='bulk_delete'){
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected_ids']??[])),static fn($selectedId)=>$selectedId>0)));
    if(!$ids){
      flash('error','Select at least one Area/Unit to delete.');
    }else{
      try{
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $sigSt=$pdo->prepare("SELECT ap.electronic_signature FROM area_personnel ap WHERE ap.area_id IN ($placeholders) AND COALESCE(ap.electronic_signature,'')<>''");
        $sigSt->execute($ids);
        $signaturePaths=$sigSt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $st=$pdo->prepare("DELETE FROM areas WHERE id IN ($placeholders)");
        $st->execute($ids);
        $deleted=$st->rowCount();
        $pdo->commit();
        foreach($signaturePaths as $signaturePath) deleteStoredSignature($signaturePath);
        flash($deleted ? 'success' : 'error',$deleted===1 ? 'Area/Unit deleted.' : ($deleted>1 ? $deleted.' Area/Units deleted.' : 'No matching Area/Unit records were found.'));
      }catch(PDOException $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error','One or more selected Area/Units cannot be deleted because they are already used by existing PPMP or Purchase Request records. No Area/Unit records were deleted if the database rejected the operation.');
      }
    }
    header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
  }

  if($action==='bulk_delete_divisions' || $action==='delete_division'){
    $divisionIds=$action==='delete_division' ? [$id] : array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected_division_ids']??[])),static fn($selectedId)=>$selectedId>0)));
    if(!$divisionIds){ flash('error','Select at least one Division/Department to delete.'); }
    else{
      try{
        $placeholders=implode(',',array_fill(0,count($divisionIds),'?'));
        $countSt=$pdo->prepare("SELECT COUNT(*) FROM areas WHERE division_id IN ($placeholders)");
        $countSt->execute($divisionIds);
        if((int)$countSt->fetchColumn()>0){
          flash('error','Selected Division/Department records cannot be deleted while they still contain Area/Unit records. Delete or reassign those Area/Units first. No Division/Department records were deleted.');
        }else{
          $sigSt=$pdo->prepare("SELECT electronic_signature FROM divisions WHERE id IN ($placeholders) AND COALESCE(electronic_signature,'')<>''");
          $sigSt->execute($divisionIds);
          $signaturePaths=$sigSt->fetchAll(PDO::FETCH_COLUMN);
          $pdo->beginTransaction();
          $st=$pdo->prepare("DELETE FROM divisions WHERE id IN ($placeholders)");
          $st->execute($divisionIds);
          $deleted=$st->rowCount();
          $pdo->commit();
          foreach($signaturePaths as $signaturePath) deleteStoredSignature($signaturePath);
          flash($deleted ? 'success' : 'error',$deleted===1 ? 'Division/Department deleted.' : ($deleted>1 ? $deleted.' Division/Department records deleted.' : 'No matching Division/Department records were found.'));
        }
      }catch(PDOException $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error','Selected Division/Department records cannot be deleted because they are already used by existing records.');
      }
    }
    header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
  }

  if($action==='save_division' || $action==='update_division'){
    $divisionId=(int)($_POST['division_id']??0);
    $divisionName=trim($_POST['division_name']??'');
    $head=trim($_POST['division_head']??'');
    $headPosition=trim($_POST['head_position_designation']??'');
    $supervisorEnabled=(int)($_POST['ppmp_supervisor_enabled']??0)===1 ? 1 : 0;
    $signaturePath=null;

    // New records do not have a division_id yet; updates must have one.
    if($divisionName==='' || $head==='' || ($action==='update_division' && $divisionId<=0)){
      flash('error','Division/Department name and Division/Department Head are required.');
      header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
    }

    if($signatureData!==''){
      try{
        $signaturePath=saveElectronicSignatureData($signatureData,[$divisionName],$head);
      }catch(RuntimeException $e){
        flash('error',$e->getMessage());
        header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
      }
    }

    try{
      if($action==='save_division'){
        if($signaturePath!==null){
          $st=$pdo->prepare('INSERT INTO divisions(name,division_head,head_position_designation,ppmp_supervisor_enabled,electronic_signature) VALUES(?,?,?,?,?)');
          $st->execute([$divisionName,$head,$headPosition,$supervisorEnabled,$signaturePath]);
        }else{
          $st=$pdo->prepare('INSERT INTO divisions(name,division_head,head_position_designation,ppmp_supervisor_enabled) VALUES(?,?,?,?)');
          $st->execute([$divisionName,$head,$headPosition,$supervisorEnabled]);
        }
        flash('success','Division/Department added.');
      }else{
        $oldSt=$pdo->prepare('SELECT electronic_signature FROM divisions WHERE id=?');
        $oldSt->execute([$divisionId]);
        $oldSignaturePath=(string)($oldSt->fetchColumn()??'');

        if($signaturePath!==null){
          $st=$pdo->prepare('UPDATE divisions SET name=?,division_head=?,head_position_designation=?,ppmp_supervisor_enabled=?,electronic_signature=? WHERE id=?');
          $st->execute([$divisionName,$head,$headPosition,$supervisorEnabled,$signaturePath,$divisionId]);
        }else{
          $st=$pdo->prepare('UPDATE divisions SET name=?,division_head=?,head_position_designation=?,ppmp_supervisor_enabled=? WHERE id=?');
          $st->execute([$divisionName,$head,$headPosition,$supervisorEnabled,$divisionId]);
        }

        if($signaturePath!==null && $oldSignaturePath!=='' && $oldSignaturePath!==$signaturePath) deleteStoredSignature($oldSignaturePath);
        flash('success','Division/Department updated.');
      }
    }catch(PDOException $e){
      if($signaturePath!==null) deleteStoredSignature($signaturePath);
      flash('error','Unable to save the Division/Department. The Division/Department name may already exist or the record is invalid.');
    }
    header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
  }

  if($action==='save_area_names'){
    $areaId=(int)($_POST['id']??0);
    $names=array_map('trim',$_POST['names']??[]);
    $positions=array_map('trim',$_POST['positions']??[]);
    $personnel=[];
    foreach($names as $i=>$personName){
      if($personName!=='') $personnel[]=[$personName,$positions[$i]??''];
    }
    $personnel=array_values(array_reduce($personnel,function($carry,$row){
      foreach($carry as $existing){ if(strcasecmp($existing[0],$row[0])===0) return $carry; }
      $carry[]=$row; return $carry;
    },[]));
    if($areaId<=0){
      flash('error','Invalid Area/Unit.');
    }else{
      try{
        $pdo->beginTransaction();
        $st=$pdo->prepare('DELETE FROM area_personnel WHERE area_id=?');
        $st->execute([$areaId]);
        $ins=$pdo->prepare('INSERT INTO area_personnel(area_id,name,position_designation) VALUES(?,?,?)');
        foreach($personnel as [$personName,$position]) $ins->execute([$areaId,$personName,$position]);
        $pdo->commit();
        flash('success','Area/Unit names updated.');
      }catch(PDOException $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error','Unable to save the Area/Unit names. Please check for duplicate names.');
      }
    }
    header('Location:'.($embedded ? 'settings.php?tab=area-unit&edit='.$areaId : 'areas.php?edit='.$areaId)); exit;
  }

  if($action==='delete_person'){
    $personId=(int)($_POST['person_id']??0);
    if($personId<=0) flash('error','Invalid name record.');
    else{
      $st=$pdo->prepare('DELETE FROM area_personnel WHERE id=?');
      $st->execute([$personId]);
      flash($st->rowCount() ? 'success' : 'error',$st->rowCount() ? 'Name removed from the Area/Unit.' : 'Name record not found.');
    }
    header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
  }

  if($name==='' || $divisionId<=0){
    flash('error','Division/Department and Area/Unit name are required.');
    header('Location:'.($embedded ? 'settings.php?tab=area-unit'.($action==='edit'&&$id?'&edit='.$id:'') : 'areas.php'.($action==='edit'&&$id?'?edit='.$id:''))); exit;
  }

  $signaturePathsByIndex=[];
  $signatureDataByIndex=$_POST['electronic_signature_data']??[];
  if(!is_array($signatureDataByIndex)) $signatureDataByIndex=[];
  try{
    foreach($signatureDataByIndex as $idx=>$data){
      $data=trim((string)$data);
      if($data!==''){
        $folderSt=$pdo->prepare('SELECT name FROM divisions WHERE id=?');
        $folderSt->execute([$divisionId]);
        $signatureDivisionName=(string)($folderSt->fetchColumn()?:'');
        $signaturePathsByIndex[(int)$idx]=saveElectronicSignatureData($data,[$signatureDivisionName,$name],(string)(($_POST['names'][$idx]??'')?:'Area Unit Head'));
      }
    }
  }catch(RuntimeException $e){
    foreach($signaturePathsByIndex as $savedPath){
      deleteStoredSignature($savedPath);
    }
    flash('error',$e->getMessage());
    header('Location:'.($embedded ? 'settings.php?tab=area-unit'.($action==='edit'&&$id?'&edit='.$id:'') : 'areas.php'.($action==='edit'&&$id?'?edit='.$id:'')));
    exit;
  }

  $names=array_map('trim',$_POST['names']??[]);
  $positions=array_map('trim',$_POST['positions']??[]);
  $personnel=[];
  foreach($names as $i=>$personName){
    if($personName!=='') $personnel[]=[
      'name'=>$personName,
      'position'=>$positions[$i]??'',
      'signature'=>$signaturePathsByIndex[$i]??null
    ];
  }
  $unique=[];
  foreach($personnel as $person){
    $duplicate=false;
    foreach($unique as $existing){if(strcasecmp($existing['name'],$person['name'])===0){$duplicate=true;break;}}
    if(!$duplicate)$unique[]=$person;
  }
  $personnel=$unique;

  try{
    $pdo->beginTransaction();

    if($action==='edit' && $id>0){
      $st=$pdo->prepare('UPDATE areas SET division_id=?,name=?,code=? WHERE id=?');
      $st->execute([$divisionId,$name,$code,$id]);
      $areaId=$id;
      $successMessage='Area/Unit updated.';
    }else{
      $st=$pdo->prepare('INSERT INTO areas(division_id,name,code) VALUES(?,?,?)');
      $st->execute([$divisionId,$name,$code]);
      $areaId=(int)$pdo->lastInsertId();
      $successMessage='Area/Unit added.';
    }

    $existingPeopleByName=[];
    $oldSignaturePaths=[];
    $oldSt=$pdo->prepare('SELECT name,electronic_signature FROM area_personnel WHERE area_id=?');
    $oldSt->execute([$areaId]);
    foreach($oldSt->fetchAll() as $oldPerson){
      $oldSignature=(string)($oldPerson['electronic_signature']??'');
      $existingPeopleByName[mb_strtolower(trim((string)$oldPerson['name']))]=$oldSignature;
      if($oldSignature!=='') $oldSignaturePaths[$oldSignature]=true;
    }

    $st=$pdo->prepare('DELETE FROM area_personnel WHERE area_id=?');
    $st->execute([$areaId]);

    if($personnel){
      $ins=$pdo->prepare('INSERT INTO area_personnel(area_id,name,position_designation,electronic_signature) VALUES(?,?,?,?)');
      foreach($personnel as $person){
        $key=mb_strtolower(trim($person['name']));
        $signature=$person['signature'];
        if($signature===null && isset($existingPeopleByName[$key])) $signature=$existingPeopleByName[$key] ?: null;
        $ins->execute([$areaId,$person['name'],$person['position'],$signature]);
      }
    }

    $pdo->commit();

    // Remove obsolete signature files only after the database save succeeds.
    // A signature is retained if the saved personnel record still references it.
    foreach(array_keys($oldSignaturePaths) as $oldSignaturePath){
      $stStillUsed=$pdo->prepare('SELECT COUNT(*) FROM area_personnel WHERE electronic_signature=?');
      $stStillUsed->execute([$oldSignaturePath]);
      if((int)$stStillUsed->fetchColumn()===0){
        deleteStoredSignature($oldSignaturePath);
      }
    }

    flash('success',$successMessage);
  }catch(PDOException $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    foreach($signaturePathsByIndex as $savedPath){
      deleteStoredSignature($savedPath);
    }
    flash('error','Unable to save the Area/Unit and its names. The Area/Unit name/code may already exist, or the selected Division/Department or name data is invalid.');
  }
  header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
}

$divisions=$pdo->query('SELECT id,name,division_head,head_position_designation,electronic_signature,ppmp_supervisor_enabled FROM divisions ORDER BY name')->fetchAll();
$rows=$pdo->query('
  SELECT a.id,a.name,a.code,a.created_at,d.id division_id,d.name division_name,d.division_head
  FROM areas a
  JOIN divisions d ON d.id=a.division_id
  ORDER BY d.name,a.name
')->fetchAll();

$people=$pdo->query('
  SELECT ap.id,ap.area_id,ap.name,ap.position_designation,ap.electronic_signature,ap.created_at,a.name area_name,d.name division_name
  FROM area_personnel ap
  JOIN areas a ON a.id=ap.area_id
  JOIN divisions d ON d.id=a.division_id
  ORDER BY d.name,a.name,ap.name
')->fetchAll();

$divisionEditId=(int)($_GET['edit_division']??0);
$divisionEditing=null;
if($divisionEditId>0){
  $st=$pdo->prepare('SELECT id,name,division_head,head_position_designation,electronic_signature FROM divisions WHERE id=?');
  $st->execute([$divisionEditId]);
  $divisionEditing=$st->fetch();
}
if(!$embedded) pageStart('Area/Unit Management');
?>
<div class="management-columns">
  <div class="management-column panel">
    <div class="management-section">
      <div class="toolbar"><div><h2><?= $divisionEditing ? 'Edit Division/Department' : 'Division/Department Management' ?></h2><p>Each Division/Department has exactly one designated Head. Multiple Area/Units may be assigned under the same Division/Department.</p></div></div>
      <form method="post" enctype="multipart/form-data" id="divisionManagementForm">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="<?= $divisionEditing ? 'update_division' : 'save_division' ?>">
        <input type="hidden" name="electronic_signature_data" value="">
        <?php if($divisionEditing): ?><input type="hidden" name="division_id" value="<?=e($divisionEditing['id'])?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field"><label>Division/Department Name</label><input class="input" name="division_name" required placeholder="e.g. Medical Service" value="<?=e($divisionEditing['name']??'')?>"></div>
          <div class="field"><label>Division/Department Head</label><input class="input" name="division_head" required placeholder="e.g. Juan Dela Cruz" value="<?=e($divisionEditing['division_head']??'')?>"></div>
          <div class="field"><label>Position/Designation</label><input class="input" name="head_position_designation" placeholder="e.g. Medical Center Chief / Division Chief" value="<?=e($divisionEditing['head_position_designation']??'')?>"></div>
          <div class="field full"><label>Assigned as Supervisor/Authorized Person for PPMP Review?</label>
            <div style="display:flex;gap:18px;align-items:center">
              <label><input type="radio" name="ppmp_supervisor_enabled" value="1" <?=((int)($divisionEditing['ppmp_supervisor_enabled']??0)===1)?'checked':''?>> Yes</label>
              <label><input type="radio" name="ppmp_supervisor_enabled" value="0" <?=((int)($divisionEditing['ppmp_supervisor_enabled']??0)!==1)?'checked':''?>> No</label>
            </div>
            <small class="muted">Yes assigns the Division/Department Head as the Supervisor/Authorized Person for PPMP review and the Submitted by signatory.</small>
          </div>
          <div class="field full"><label>Electronic Signature</label><input class="input" type="file" name="electronic_signature" accept="image/png,image/jpeg"><small class="muted">Upload PNG or JPG signature image, maximum 2 MB.</small><?php if(!empty($divisionEditing['electronic_signature'])): ?><div class="signature-preview"><img src="<?=e($divisionEditing['electronic_signature'])?>" alt="Division/Department electronic signature"></div><?php endif; ?></div>
        </div>
        <div class="actions"><button class="btn" type="submit"><?= $divisionEditing ? 'Save Division/Department' : '+ Add Division/Department' ?></button><?php if($divisionEditing): ?><a class="btn secondary" href="<?=e($embedded?'settings.php?tab=area-unit':'areas.php')?>">Cancel</a><?php endif; ?></div>
      </form>
    </div>
    <div class="management-inner-list">
      <div class="management-list-content">
        <div class="management-list-header">
          <h2>Division/Department List</h2>
          <div class="management-list-search" id="divisionSearchBox">
            <input class="input" type="text" id="divisionSearchInput" autocomplete="off" placeholder="Search Division..." aria-label="Search Division/Department">
            <div class="management-search-suggestions" id="divisionSearchSuggestions" role="listbox"></div>
          </div>
        </div>
        <form method="post" id="divisionBulkDeleteForm" onsubmit="return confirmBulkDivisionDelete();">
          <input type="hidden" name="csrf" value="<?=e(csrf())?>">
          <input type="hidden" name="action" value="bulk_delete_divisions">
        <div class="table-wrap"><table class="table" id="divisionTable">
          <tr><th style="width:34px">Select</th><th>Division/Department</th><th>Division/Department Head</th><th>PPMP Supervisor/Authorized Person</th><th>Actions</th></tr>
          <?php foreach($divisions as $d): ?>
          <tr data-management-search-id="<?=e((string)$d['id'])?>" data-management-search-name="<?=e($d['name'])?>" data-management-search-head="<?=e($d['division_head'])?>">
            <td><input type="checkbox" class="division-select" name="selected_division_ids[]" value="<?=(int)$d['id']?>" aria-label="Select <?=e($d['name'])?>"></td>
            <td><?=e($d['name'])?></td>
            <td>
              <div><?=e($d['division_head'])?></div>
              <?php if(trim((string)($d['head_position_designation']??''))!==''): ?><small class="muted"><?=e($d['head_position_designation'])?></small><?php endif; ?>
            </td>
            <td>
              <?php if((int)$d['ppmp_supervisor_enabled']===1): ?>
                <strong>Authorized (1)</strong><br><span class="muted"><?=e($d['division_head'])?></span>
              <?php else: ?>
                <span class="muted">Not Authorized</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <a class="btn secondary master-action" href="<?=e($embedded ? 'settings.php?tab=area-unit&edit_division='.(int)$d['id'] : 'areas.php?edit_division='.(int)$d['id'])?>">Edit</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$divisions): ?><tr><td colspan="5">No Division/Department records found.</td></tr><?php endif; ?>
        </table></div>
        <div class="area-selection-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 0 0">
          <label style="display:flex;align-items:center;gap:7px;margin:0;font-size:13px"><input type="checkbox" id="selectAllDivisions"> Select All</label>
          <button class="btn danger" type="submit" id="deleteSelectedDivisions" disabled>Delete Selected</button>
        </div>
        </form>
      </div>
    </div>
  </div>
  <div class="management-column panel">
    <div class="management-section area-unit-add-panel">
      <h2><?= $editing ? 'Edit Area/Unit' : 'Add Area/Unit' ?></h2>
      <p>Area/Units inherit the Division/Department Head from their selected Division/Department and can contain multiple names.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
        <input type="hidden" name="electronic_signature_data" value="">
        <?php if($editing): ?><input type="hidden" name="id" value="<?=e($editing['id'])?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field"><label>Division/Department *</label><select class="select" name="division_id" required><option value="">Select Division/Department</option><?php foreach($divisions as $d): ?><option value="<?=e($d['id'])?>" <?=((int)($editing['division_id']??0)===(int)$d['id'])?'selected':''?>><?=e($d['name'])?> — Head: <?=e($d['division_head'])?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Area/Unit Name *</label><input class="input" name="name" required placeholder="e.g. Operating Room" value="<?=e($editing['name']??'')?>"></div>
          <div class="field"><label>Code <small>(optional)</small></label><input class="input" name="code" placeholder="e.g. OR" value="<?=e($editing['code']??'')?>"></div>

          <div class="field full"><label>Names Under This Area/Unit</label><div id="area-names-list">
          <?php $editingPeople=[]; if($editing){$stPeople=$pdo->prepare('SELECT id,name,position_designation,electronic_signature FROM area_personnel WHERE area_id=? ORDER BY name');$stPeople->execute([$editing['id']]);$editingPeople=$stPeople->fetchAll();} ?>
          <?php if($editingPeople): foreach($editingPeople as $person): ?>
          <div class="area-name-row" style="display:grid;grid-template-columns:1fr 1fr 1.2fr auto;gap:8px;margin-bottom:8px;align-items:start">
            <input class="input" name="names[]" value="<?=e($person['name'])?>" placeholder="e.g. Maria Santos">
            <input class="input" name="positions[]" value="<?=e($person['position_designation']??'')?>" placeholder="e.g. Nurse / Administrative Officer">
            <div><input class="input" type="file" name="electronic_signature_file[]" accept="image/png,image/jpeg"><input type="hidden" name="electronic_signature_data[]" value=""><small class="muted">Electronic Signature (PNG/JPG, max 2 MB)</small><?php if(!empty($person['electronic_signature'])): ?><div class="signature-preview"><img src="<?=e($person['electronic_signature'])?>" alt="Electronic signature"></div><?php endif; ?></div>
            <button class="btn danger remove-area-name" type="button">Remove</button>
          </div>
          <?php endforeach; else: ?>
          <div class="area-name-row" style="display:grid;grid-template-columns:1fr 1fr 1.2fr auto;gap:8px;margin-bottom:8px;align-items:start">
            <input class="input" name="names[]" placeholder="e.g. Maria Santos">
            <input class="input" name="positions[]" placeholder="e.g. Nurse / Administrative Officer">
            <div><input class="input" type="file" name="electronic_signature_file[]" accept="image/png,image/jpeg"><input type="hidden" name="electronic_signature_data[]" value=""><small class="muted">Electronic Signature (PNG/JPG, max 2 MB)</small></div>
            <button class="btn danger remove-area-name" type="button">Remove</button>
          </div>
          <?php endif; ?>
          </div><button class="btn secondary" type="button" id="add-area-name">+ Add Another Name</button><small class="muted">Add as many names as needed for this Area/Unit.</small></div>
        </div>
        <div class="actions"><button class="btn" type="submit"><?= $editing ? 'Save Changes' : '+ Add Area/Unit' ?></button><?php if($editing): ?><a class="btn secondary" href="<?=e($embedded?'settings.php?tab=area-unit':'areas.php')?>">Cancel</a><?php endif; ?></div>
      </form>
    </div>
    <div class="management-inner-list">
      <div class="management-list-content area-unit-list-panel">
        <div class="management-list-header">
          <h2>Area/Unit List</h2>
          <div class="management-list-search" id="areaSearchBox">
            <input class="input" type="text" id="areaSearchInput" autocomplete="off" placeholder="Search Area/Unit..." aria-label="Search Area/Unit">
            <div class="management-search-suggestions" id="areaSearchSuggestions" role="listbox"></div>
          </div>
        </div>
        <form method="post" id="areaBulkDeleteForm" onsubmit="return confirmBulkAreaDelete();">
          <input type="hidden" name="csrf" value="<?=e(csrf())?>">
          <input type="hidden" name="action" value="bulk_delete">
        <div class="area-pagination" id="areaPagination" aria-label="Area/Unit pagination"><div class="area-page-size"><label for="areaPageSize">Show</label><select class="input" id="areaPageSize" aria-label="Records per page"><option value="10">10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select><span><label for="areaPageSize">records</label></span></div><div class="area-pagination-info" id="areaPaginationInfo"></div><div class="area-pagination-buttons" id="areaPaginationButtons"></div></div>
        <div class="area-selection-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 0 0">
          <label style="display:flex;align-items:center;gap:7px;margin:0;font-size:13px"><input type="checkbox" id="selectAllAreas"> Select All</label>
          <button class="btn danger" type="submit" id="deleteSelectedAreas" disabled>Delete Selected</button>
        </div>
        <div class="table-wrap"><table class="table" id="areaTable">
          <tr><th style="width:34px">Select</th><th>Division/Department</th><th>Division/Department Head</th><th>Area/Unit</th><th>Code</th><th>Names</th><th>Created</th><th>Actions</th></tr>
          <?php foreach($rows as $r): ?><?php $areaPeople=array_values(array_filter($people,fn($p)=>(int)$p['area_id']===(int)$r['id'])); ?>
          <tr data-management-search-id="<?=e((string)$r['id'])?>" data-management-search-name="<?=e($r['name'])?>" data-management-search-division="<?=e($r['division_name'])?>"><td><input type="checkbox" class="area-select" name="selected_ids[]" value="<?=(int)$r['id']?>" aria-label="Select <?=e($r['name'])?>"></td><td><?=e($r['division_name'])?></td><td><?=e($r['division_head'])?></td><td><?=e($r['name'])?></td><td><?=e($r['code']??'')?></td><td><?php if($areaPeople): ?><ul style="margin:0;padding-left:18px"><?php foreach($areaPeople as $p): ?><li><?=e($p['name'])?><?php if(!empty($p['position_designation'])): ?> — <span class="muted"><?=e($p['position_designation'])?></span><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><span class="muted">No names yet</span><?php endif; ?></td><td><?=e($r['created_at'])?></td><td><div class="actions"><a class="btn secondary master-action" href="<?=e($embedded ? 'settings.php?tab=area-unit&edit='.(int)$r['id'] : 'areas.php?edit='.(int)$r['id'])?>">Edit</a></div></td></tr>
          <?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="8">No Area/Unit records found.</td></tr><?php endif; ?>
        </table></div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function confirmBulkDivisionDelete(){
  const selected=Array.from(document.querySelectorAll('.division-select:checked'));
  if(!selected.length){alert('Select at least one Division/Department to delete.');return false;}
  return confirm(selected.length===1?'Delete the selected Division/Department? It can only be deleted if it has no Area/Unit records.':'Delete all '+selected.length+' selected Division/Department records? Each must have no Area/Unit records.');
}
function confirmBulkAreaDelete(){
  const selected=Array.from(document.querySelectorAll('.area-select:checked'));
  if(!selected.length){alert('Select at least one Area/Unit to delete.');return false;}
  return confirm(selected.length===1?'Delete the selected Area/Unit?':'Delete all '+selected.length+' selected Area/Units?');
}
document.addEventListener('DOMContentLoaded',function(){
  const selectAllDivisions=document.getElementById('selectAllDivisions');
  const deleteDivisions=document.getElementById('deleteSelectedDivisions');
  const divisionChecks=Array.from(document.querySelectorAll('.division-select'));
  function updateDivisionSelection(){
    const selected=divisionChecks.filter(function(box){return box.checked;}).length;
    if(deleteDivisions)deleteDivisions.disabled=selected===0;
    if(selectAllDivisions){selectAllDivisions.checked=divisionChecks.length>0&&selected===divisionChecks.length;selectAllDivisions.indeterminate=selected>0&&selected<divisionChecks.length;}
  }
  if(selectAllDivisions)selectAllDivisions.addEventListener('change',function(){divisionChecks.forEach(function(box){box.checked=selectAllDivisions.checked;});updateDivisionSelection();});
  divisionChecks.forEach(function(box){box.addEventListener('change',updateDivisionSelection);});
  updateDivisionSelection();

  const selectAll=document.getElementById('selectAllAreas');
  const deleteButton=document.getElementById('deleteSelectedAreas');
  const checks=Array.from(document.querySelectorAll('.area-select'));
  function updateBulkSelection(){
    const selected=checks.filter(function(box){return box.checked;}).length;
    if(deleteButton)deleteButton.disabled=selected===0;
    if(selectAll){selectAll.checked=checks.length>0&&selected===checks.length;selectAll.indeterminate=selected>0&&selected<checks.length;}
  }
  if(selectAll)selectAll.addEventListener('change',function(){checks.forEach(function(box){box.checked=selectAll.checked;});updateBulkSelection();});
  checks.forEach(function(box){box.addEventListener('change',updateBulkSelection);});
  updateBulkSelection();
});
</script>
<script>
(function(){
  // Convert the Division/Department signature upload to the hidden base64
  // field expected by save_division/update_division. This is separate from
  // the Area/Unit personnel signature handler below.
  const divisionForm=document.getElementById('divisionManagementForm');
  if(divisionForm){
    divisionForm.addEventListener('submit',function(event){
      const fileInput=divisionForm.querySelector('input[name="electronic_signature"]');
      const dataInput=divisionForm.querySelector('input[name="electronic_signature_data"]');
      const file=fileInput && fileInput.files ? fileInput.files[0] : null;
      if(!file || !dataInput || dataInput.value) return;
      if(file.size>2*1024*1024){event.preventDefault();alert('Electronic signature must not exceed 2 MB.');return;}
      if(file.type!=='image/png'&&file.type!=='image/jpeg'){event.preventDefault();alert('Electronic signature must be a PNG or JPG image.');return;}
      event.preventDefault();
      const reader=new FileReader();
      reader.onload=function(){dataInput.value=String(reader.result||'');fileInput.value='';divisionForm.submit();};
      reader.onerror=function(){alert('Unable to read the selected electronic signature file.');};
      reader.readAsDataURL(file);
    });
  }

  const form=document.querySelector('.area-unit-add-panel form');
  const list=document.getElementById('area-names-list');
  const add=document.getElementById('add-area-name');
  if(form){
    form.addEventListener('submit',function(event){
      const files=Array.from(form.querySelectorAll('input[name="electronic_signature_file[]"]'));
      const data=Array.from(form.querySelectorAll('input[name="electronic_signature_data[]"]'));
      const selected=files.map((input,i)=>({input:input,data:data[i],file:input.files&&input.files[0]})).filter(x=>x.file);
      if(!selected.length) return;
      event.preventDefault();
      let done=0, failed=false;
      selected.forEach(function(item){
        if(item.file.size>2*1024*1024){alert('Electronic signature must not exceed 2 MB.');failed=true;return;}
        if(item.file.type!=='image/png'&&item.file.type!=='image/jpeg'){alert('Electronic signature must be a PNG or JPG image.');failed=true;return;}
        const reader=new FileReader();
        reader.onload=function(){item.data.value=String(reader.result||'');done++;if(done===selected.length&&!failed){files.forEach(function(f){f.value='';});form.submit();}};
        reader.onerror=function(){failed=true;alert('Unable to read the selected electronic signature file.');};
        reader.readAsDataURL(item.file);
      });
    });
  }
  if(!list||!add)return;
  add.addEventListener('click',function(){
    const row=document.createElement('div');
    row.className='area-name-row';
    row.style.cssText='display:grid;grid-template-columns:1fr 1fr 1.2fr auto;gap:8px;margin-bottom:8px;align-items:start';
    row.innerHTML='<input class="input" name="names[]" placeholder="e.g. Maria Santos"><input class="input" name="positions[]" placeholder="e.g. Nurse / Administrative Officer"><div><input class="input" type="file" name="electronic_signature_file[]" accept="image/png,image/jpeg"><input type="hidden" name="electronic_signature_data[]" value=""><small class="muted">Electronic Signature (PNG/JPG, max 2 MB)</small></div><button class="btn danger remove-area-name" type="button">Remove</button>';
    list.appendChild(row);
  });
  list.addEventListener('click',function(e){
    if(e.target.classList.contains('remove-area-name')){
      const rows=list.querySelectorAll('.area-name-row');
      if(rows.length>1)e.target.closest('.area-name-row').remove();
      else e.target.closest('.area-name-row').querySelectorAll('input').forEach(function(input){input.value='';});
    }
  });
})();
</script>
<script>
(function(){
  function setupManagementSearch(cfg){
    const input=document.getElementById(cfg.input);
    const suggestions=document.getElementById(cfg.suggestions);
    const table=document.getElementById(cfg.table);
    const box=document.getElementById(cfg.box);
    const pageSizeSelect=document.getElementById(cfg.pageSize);
    const paginationInfo=document.getElementById(cfg.paginationInfo);
    const paginationButtons=document.getElementById(cfg.paginationButtons);
    if(!input||!suggestions||!table||!box)return;

    const rows=Array.from(table.querySelectorAll('tr[data-management-search-id]'));
    if(!pageSizeSelect||!paginationInfo||!paginationButtons){
      return;
    }

    let filteredRows=rows.slice();
    let currentPage=1;
    let pageSize=Number(pageSizeSelect.value)||10;

    function close(){suggestions.innerHTML='';suggestions.style.display='none';}

    function rowMatches(row,term,selected){
      const hay=[row.dataset.managementSearchName,row.dataset.managementSearchHead,row.dataset.managementSearchDivision].filter(Boolean).join(' ').toLowerCase();
      return selected ? row.dataset.managementSearchId===selected : (!term||hay.includes(term));
    }

    function renderPagination(){
      const total=filteredRows.length;
      const totalPages=Math.max(1,Math.ceil(total/pageSize));
      if(currentPage>totalPages)currentPage=totalPages;

      rows.forEach(r=>{
        r.style.display='none';
        r.classList.remove('management-search-selected');
      });

      const startIndex=(currentPage-1)*pageSize;
      filteredRows.slice(startIndex,startIndex+pageSize).forEach(r=>r.style.display='');

      if(!total){
        paginationInfo.textContent='0 records';
      }else{
        paginationInfo.textContent='Showing '+(startIndex+1)+'-'+Math.min(startIndex+pageSize,total)+' of '+total+' records';
      }

      paginationButtons.innerHTML='';
      function addButton(label,page,disabled,active){
        const b=document.createElement('button');
        b.type='button';
        b.className='btn secondary area-page-button'+(active?' active':'');
        b.textContent=label;
        b.disabled=!!disabled;
        b.addEventListener('click',function(){
          currentPage=page;
          renderPagination();
        });
        paginationButtons.appendChild(b);
      }

      addButton('Previous',currentPage-1,currentPage===1,false);
      const maxButtons=7;
      let first=Math.max(1,currentPage-3);
      let last=Math.min(totalPages,first+maxButtons-1);
      first=Math.max(1,last-maxButtons+1);
      for(let page=first;page<=last;page++)addButton(String(page),page,false,page===currentPage);
      addButton('Next',currentPage+1,currentPage===totalPages,false);
    }

    function filter(term,selected,resetPage){
      const value=String(term||'').trim().toLowerCase();
      filteredRows=rows.filter(r=>rowMatches(r,value,selected));
      if(resetPage)currentPage=1;
      renderPagination();
      if(selected){
        filteredRows.forEach(r=>r.classList.add('management-search-selected'));
      }
    }

    function render(){
      const term=input.value.trim().toLowerCase();
      suggestions.innerHTML='';
      if(!term){
        close();
        filter('',null,true);
        return;
      }

      const matches=rows.filter(r=>rowMatches(r,term,null)).slice(0,10);
      if(!matches.length){
        suggestions.innerHTML='<div class="management-search-empty">No matching record found.</div>';
        suggestions.style.display='block';
        filter(term,null,true);
        return;
      }

      matches.forEach(r=>{
        const b=document.createElement('button');
        b.type='button';
        b.className='management-search-suggestion';
        b.setAttribute('role','option');
        b.dataset.id=r.dataset.managementSearchId;
        b.textContent=r.dataset.managementSearchName||'';
        suggestions.appendChild(b);
      });
      suggestions.style.display='block';
      filter(term,null,true);
    }

    input.addEventListener('input',render);
    input.addEventListener('focus',()=>{if(input.value.trim())render();});
    input.addEventListener('keydown',e=>{
      if(e.key==='Escape'){
        input.value='';
        close();
        filter('',null,true);
      }
    });

    suggestions.addEventListener('click',e=>{
      const b=e.target.closest('.management-search-suggestion');
      if(!b)return;
      const row=rows.find(r=>r.dataset.managementSearchId===b.dataset.id);
      if(!row)return;
      input.value=row.dataset.managementSearchName||'';
      close();
      filter('',b.dataset.id,true);
      row.scrollIntoView({behavior:'smooth',block:'center'});
    });

    pageSizeSelect.addEventListener('change',function(){
      pageSize=Number(pageSizeSelect.value)||10;
      currentPage=1;
      renderPagination();
    });

    document.addEventListener('click',e=>{if(!box.contains(e.target))close();});
    renderPagination();
  }

  setupManagementSearch({
    box:'divisionSearchBox',
    input:'divisionSearchInput',
    suggestions:'divisionSearchSuggestions',
    table:'divisionTable'
  });

  setupManagementSearch({
    box:'areaSearchBox',
    input:'areaSearchInput',
    suggestions:'areaSearchSuggestions',
    table:'areaTable',
    pageSize:'areaPageSize',
    paginationInfo:'areaPaginationInfo',
    paginationButtons:'areaPaginationButtons'
  });
})();
</script>
</script>
<style>
.management-inner-list{border-top:1px solid #d9dee5;margin-top:18px;padding-top:14px;width:100%;box-sizing:border-box}
.management-list-content{width:100%;box-sizing:border-box}
.management-list-header{display:grid;grid-template-columns:minmax(0,1fr) 200px;align-items:center;column-gap:14px;width:100%;min-height:36px;margin:0 0 10px;padding:0;box-sizing:border-box}
.management-list-header h2{margin:0;min-width:0;line-height:36px;white-space:nowrap;align-self:center}
.management-list-search{position:relative;width:200px;min-width:200px;margin:0;justify-self:end;align-self:center}
.management-list-search .input{width:200px;box-sizing:border-box}
.management-search-suggestions{position:absolute;left:0;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #cfd6df;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;display:none}
.management-search-suggestion{display:block;width:100%;padding:9px 12px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:14px}
.management-search-suggestion:hover,.management-search-suggestion:focus{background:#eef5ff}
.management-search-empty{padding:9px 12px;color:#6b7280;font-size:13px}
.management-search-selected td{background:#eef5ff!important}
.area-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;width:100%;clear:both;position:static;float:none;margin:0 0 10px;padding:0;box-sizing:border-box}
.area-pagination .area-page-size{justify-self:auto}
.area-pagination .area-pagination-info{flex:0 0 auto;justify-self:auto;text-align:right;font-size:13px;color:#6b7280}
.area-pagination-buttons{display:flex;align-items:center;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.area-page-size{display:flex;align-items:center;gap:6px;font-size:14px;color:#4b5563}
.area-page-size .input{width:78px;min-width:78px;height:36px}
.area-page-button{min-width:38px;height:36px;padding:0 10px;background:#cfe8ff;color:#1f5f85;border-color:#b7d9f5}
.area-page-button:hover:not(:disabled):not(.active){background:#b9dcfa;color:#174a69}
.area-page-button.active{font-weight:700;pointer-events:none;background:#0d6efd;color:#fff;border-color:#0d6efd}
.area-pagination-buttons .area-page-button:first-child,
.area-pagination-buttons .area-page-button:last-child{background:#495057;color:#fff;border-color:#495057}
.area-pagination-buttons .area-page-button:first-child:hover:not(:disabled),
.area-pagination-buttons .area-page-button:last-child:hover:not(:disabled){background:#343a40;color:#fff;border-color:#343a40}
.area-pagination-buttons .area-page-button:disabled{opacity:.7;cursor:not-allowed}
@media(max-width:700px){
  .area-pagination{align-items:flex-start;justify-content:flex-end;flex-wrap:wrap}
  .area-pagination .area-pagination-info{order:3;flex-basis:100%;text-align:right}
}
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}

/* Keep the Division/Department List compact enough to fit its panel. */
.management-column:first-child .management-list-content .table-wrap{overflow-x:hidden}
.management-column:first-child .management-list-content .table{width:100%;table-layout:fixed;font-size:13px}
.management-column:first-child .management-list-content .table th,
.management-column:first-child .management-list-content .table td{padding:8px 6px;vertical-align:middle;overflow-wrap:anywhere}
.management-column:first-child .management-list-content .table th{text-align:center}
.management-column:first-child .management-list-content .table th:nth-child(1),
.management-column:first-child .management-list-content .table td:nth-child(1){width:5%;text-align:center}
.management-column:first-child .management-list-content .table th:nth-child(2),
.management-column:first-child .management-list-content .table td:nth-child(2){width:21%}
.management-column:first-child .management-list-content .table th:nth-child(3),
.management-column:first-child .management-list-content .table td:nth-child(3){width:23%}
.management-column:first-child .management-list-content .table th:nth-child(4),
.management-column:first-child .management-list-content .table td:nth-child(4){width:27%}
.management-column:first-child .management-list-content .table th:nth-child(5),
.management-column:first-child .management-list-content .table td:nth-child(5){width:24%}
.management-column:first-child .management-list-content .table .actions{display:flex;gap:5px;align-items:center;justify-content:flex-start;flex-wrap:nowrap;white-space:nowrap;width:max-content;max-width:100%}
.management-column:first-child .management-list-content .table .master-action{width:58px;min-width:58px;height:30px;padding:3px 4px;font-size:11px}
.management-column:first-child .management-list-content .table .master-action-form{margin:0;display:inline-flex;flex:0 0 auto}
/* Keep the Area/Unit List compact enough to fit the right-hand panel. */
.management-column:nth-child(2) .management-list-content .table-wrap{overflow-x:hidden}
.management-column:nth-child(2) .management-list-content .table{width:100%;table-layout:fixed;font-size:11.5px}
.management-column:nth-child(2) .management-list-content .table th,
.management-column:nth-child(2) .management-list-content .table td{padding:7px 4px;vertical-align:middle;overflow-wrap:anywhere;word-break:break-word}
.management-column:nth-child(2) .management-list-content .table th{text-align:center}
.management-column:nth-child(2) .management-list-content .table th:nth-child(1),
.management-column:nth-child(2) .management-list-content .table td:nth-child(1){width:4%;text-align:center}
.management-column:nth-child(2) .management-list-content .table th:nth-child(2),
.management-column:nth-child(2) .management-list-content .table td:nth-child(2){width:16%}
.management-column:nth-child(2) .management-list-content .table th:nth-child(3),
.management-column:nth-child(2) .management-list-content .table td:nth-child(3){width:14%}
.management-column:nth-child(2) .management-list-content .table th:nth-child(4),
.management-column:nth-child(2) .management-list-content .table td:nth-child(4){width:15%}
.management-column:nth-child(2) .management-list-content .table th:nth-child(5),
.management-column:nth-child(2) .management-list-content .table td:nth-child(5){width:6%}
.management-column:nth-child(2) .management-list-content .table th:nth-child(6),
.management-column:nth-child(2) .management-list-content .table td:nth-child(6){width:20%}
.management-column:nth-child(2) .management-list-content .table th:nth-child(7),
.management-column:nth-child(2) .management-list-content .table td:nth-child(7){width:10%}
.management-column:nth-child(2) .management-list-content .table th:nth-child(8),
.management-column:nth-child(2) .management-list-content .table td:nth-child(8){width:10%}
.management-column:nth-child(2) .management-list-content .table ul{padding-left:14px!important}
.management-column:nth-child(2) .management-list-content .table .actions{display:flex;gap:3px;align-items:center;justify-content:flex-start;flex-wrap:nowrap;white-space:nowrap;width:100%;max-width:100%}
.management-column:nth-child(2) .management-list-content .table .master-action{width:42px;min-width:42px;height:28px;padding:2px 3px;font-size:10px}
.management-column:nth-child(2) .management-list-content .table .master-action-form{margin:0;display:inline-flex;flex:0 0 auto}
.signature-preview{margin-top:8px;padding:8px;border:1px solid #ddd;background:#fff;display:inline-block}
.signature-preview img{display:block;max-width:240px;max-height:90px;object-fit:contain}
</style>
<?php if(!$embedded) pageEnd(); ?>
