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
  $cols=$pdo->query("SHOW COLUMNS FROM area_personnel LIKE 'position_designation'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE area_personnel ADD COLUMN position_designation VARCHAR(150) NULL AFTER name");
  $cols=$pdo->query("SHOW COLUMNS FROM area_personnel LIKE 'electronic_signature'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE area_personnel ADD COLUMN electronic_signature VARCHAR(255) NULL AFTER position_designation");
}catch(PDOException $e){ /* Migration can also be applied manually. */ }

function saveElectronicSignatureData(string $data): string{
  if($data==='') throw new RuntimeException('No electronic signature was selected.');
  if(!preg_match('/^data:(image\\/(?:png|jpeg));base64,(.+)$/s',$data,$m)) throw new RuntimeException('Electronic signature must be a PNG or JPG image.');
  $binary=base64_decode($m[2],true);
  if($binary===false || $binary==='') throw new RuntimeException('The electronic signature data could not be decoded.');
  if(strlen($binary)>2*1024*1024) throw new RuntimeException('Electronic signature must not exceed 2 MB.');
  $imageInfo=@getimagesizefromstring($binary);
  if($imageInfo===false) throw new RuntimeException('Electronic signature must be a valid PNG or JPG image.');
  $actualMime=(string)($imageInfo['mime']??'');
  $allowed=['image/png'=>'png','image/jpeg'=>'jpg'];
  if(!isset($allowed[$actualMime]) || $actualMime!==$m[1]) throw new RuntimeException('Electronic signature must be a valid PNG or JPG image.');
  $dir=__DIR__.'/uploads/signatures';
  if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Unable to create signature upload folder.');
  $filename='signature_'.date('YmdHis').'_' . bin2hex(random_bytes(5)).'.'.$allowed[$actualMime];
  $destination=$dir.'/'.$filename;
  if(file_put_contents($destination,$binary,LOCK_EX)===false) throw new RuntimeException('Unable to save the electronic signature. Check that the signature upload folder is writable.');
  return 'uploads/signatures/'.$filename;
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

  if($action==='delete'){
    if($id<=0){ flash('error','Invalid Area/Unit.'); }
    else {
      try{
        $st=$pdo->prepare('DELETE FROM areas WHERE id=?');
        $st->execute([$id]);
        flash($st->rowCount() ? 'success' : 'error',$st->rowCount() ? 'Area/Unit deleted.' : 'Area/Unit not found.');
      }catch(PDOException $e){
        flash('error','This Area/Unit cannot be deleted because it is already used by existing PPMP or Purchase Request records.');
      }
    }
    header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
  }

  if($action==='save_division' || $action==='update_division'){
    $divisionId=(int)($_POST['division_id']??0);
    $divisionName=trim($_POST['division_name']??'');
    $head=trim($_POST['division_head']??'');
    $headPosition=trim($_POST['head_position_designation']??'');
    $signaturePath=null;
  
    if($divisionId<=0 || $divisionName==='' || $head===''){
      flash('error','Division/Department name and Division/Department Head are required.');
      header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
    }

    if($signatureData!==''){
      try{
        $signaturePath=saveElectronicSignatureData($signatureData);
      }catch(RuntimeException $e){
        flash('error',$e->getMessage());
        header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
      }
    }

    try{
      $oldSt=$pdo->prepare('SELECT electronic_signature FROM divisions WHERE id=?');
      $oldSt->execute([$divisionId]);
      $oldSignaturePath=(string)($oldSt->fetchColumn()??'');

      if($signaturePath!==null){
        $st=$pdo->prepare('UPDATE divisions SET name=?,division_head=?,head_position_designation=?,electronic_signature=? WHERE id=?');
        $st->execute([$divisionName,$head,$headPosition,$signaturePath,$divisionId]);
      }else{
        $st=$pdo->prepare('UPDATE divisions SET name=?,division_head=?,head_position_designation=? WHERE id=?');
        $st->execute([$divisionName,$head,$headPosition,$divisionId]);
      }

      if($signaturePath!==null && $oldSignaturePath!=='' && $oldSignaturePath!==$signaturePath){
        $oldFile=__DIR__.'/'.$oldSignaturePath;
        if(is_file($oldFile)) @unlink($oldFile);
      }
      flash('success','Division/Department updated.');
    }catch(PDOException $e){
      if($signaturePath!==null){
        $newFile=__DIR__.'/'.$signaturePath;
        if(is_file($newFile)) @unlink($newFile);
      }
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
      if($data!=='') $signaturePathsByIndex[(int)$idx]=saveElectronicSignatureData($data);
    }
  }catch(RuntimeException $e){
    foreach($signaturePathsByIndex as $savedPath){
      $savedFile=__DIR__.'/'.$savedPath;
      if(is_file($savedFile)) @unlink($savedFile);
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
        $oldFile=__DIR__.'/'.$oldSignaturePath;
        if(is_file($oldFile)) @unlink($oldFile);
      }
    }

    flash('success',$successMessage);
  }catch(PDOException $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    foreach($signaturePathsByIndex as $savedPath){
      $newFile=__DIR__.'/'.$savedPath;
      if(is_file($newFile)) @unlink($newFile);
    }
    flash('error','Unable to save the Area/Unit and its names. The Area/Unit name/code may already exist, or the selected Division/Department or name data is invalid.');
  }
  header('Location:'.($embedded ? 'settings.php?tab=area-unit' : 'areas.php')); exit;
}

$divisions=$pdo->query('SELECT id,name,division_head,head_position_designation,electronic_signature FROM divisions ORDER BY name')->fetchAll();
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
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="<?= $divisionEditing ? 'update_division' : 'save_division' ?>">
        <input type="hidden" name="electronic_signature_data" value="">
        <?php if($divisionEditing): ?><input type="hidden" name="division_id" value="<?=e($divisionEditing['id'])?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field"><label>Division/Department Name</label><input class="input" name="division_name" required placeholder="e.g. Medical Service" value="<?=e($divisionEditing['name']??'')?>"></div>
          <div class="field"><label>Division/Department Head</label><input class="input" name="division_head" required placeholder="e.g. Juan Dela Cruz" value="<?=e($divisionEditing['division_head']??'')?>"></div>
          <div class="field"><label>Position/Designation</label><input class="input" name="head_position_designation" placeholder="e.g. Medical Center Chief / Division Chief" value="<?=e($divisionEditing['head_position_designation']??'')?>"></div>
          <div class="field full"><label>Electronic Signature</label><input class="input" type="file" name="electronic_signature" accept="image/png,image/jpeg"><small class="muted">Upload PNG or JPG signature image, maximum 2 MB.</small><?php if(!empty($divisionEditing['electronic_signature'])): ?><div class="signature-preview"><img src="<?=e($divisionEditing['electronic_signature'])?>" alt="Division/Department electronic signature"></div><?php endif; ?></div>
        </div>
        <div class="actions"><button class="btn" type="submit"><?= $divisionEditing ? 'Save Division/Department' : '+ Add Division/Department' ?></button><?php if($divisionEditing): ?><a class="btn secondary" href="areas.php">Cancel</a><?php endif; ?></div>
      </form>
    </div>
    <div class="management-inner-list">
      <div class="management-list-content">
        <h2>Division/Department List</h2>
        <div class="table-wrap"><table class="table">
          <tr><th>Division/Department</th><th>Division/Department Head</th><th>Area/Unit Count</th><th>Actions</th></tr>
          <?php foreach($divisions as $d): $cnt=0; foreach($rows as $r){if((int)$r['division_id']===(int)$d['id'])$cnt++;} ?>
          <tr><td><?=e($d['name'])?></td><td><div><?=e($d['division_head'])?></div><?php if(trim((string)($d['head_position_designation']??''))!==''): ?><small class="muted"><?=e($d['head_position_designation'])?></small><?php endif; ?></td><td><?=e($cnt)?></td><td><a class="btn secondary" href="areas.php?edit_division=<?=e($d['id'])?>">Edit</a></td></tr>
          <?php endforeach; ?>
          <?php if(!$divisions): ?><tr><td colspan="4">No Division/Department records found.</td></tr><?php endif; ?>
        </table></div>
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
        <div class="actions"><button class="btn" type="submit"><?= $editing ? 'Save Changes' : '+ Add Area/Unit' ?></button><?php if($editing): ?><a class="btn secondary" href="areas.php">Cancel</a><?php endif; ?></div>
      </form>
    </div>
    <div class="management-inner-list">
      <div class="management-list-content area-unit-list-panel">
        <h2>Area/Unit List</h2>
        <div class="table-wrap"><table class="table">
          <tr><th>Division/Department</th><th>Division/Department Head</th><th>Area/Unit</th><th>Code</th><th>Names</th><th>Created</th><th>Actions</th></tr>
          <?php foreach($rows as $r): ?><?php $areaPeople=array_values(array_filter($people,fn($p)=>(int)$p['area_id']===(int)$r['id'])); ?>
          <tr><td><?=e($r['division_name'])?></td><td><?=e($r['division_head'])?></td><td><?=e($r['name'])?></td><td><?=e($r['code']??'')?></td><td><?php if($areaPeople): ?><ul style="margin:0;padding-left:18px"><?php foreach($areaPeople as $p): ?><li><?=e($p['name'])?><?php if(!empty($p['position_designation'])): ?> — <span class="muted"><?=e($p['position_designation'])?></span><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><span class="muted">No names yet</span><?php endif; ?></td><td><?=e($r['created_at'])?></td><td><div class="actions"><a class="btn secondary master-action" href="areas.php?edit=<?=e($r['id'])?>">Edit</a><form method="post" class="master-action-form" onsubmit="return confirm('Delete this Area/Unit? This can only be deleted if it is not used by existing records.');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn danger master-action" type="submit">Delete</button></form></div></td></tr>
          <?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7">No Area/Unit records found.</td></tr><?php endif; ?>
        </table></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
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
<style>
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
.signature-preview{margin-top:8px;padding:8px;border:1px solid #ddd;background:#fff;display:inline-block}
.signature-preview img{display:block;max-width:240px;max-height:90px;object-fit:contain}
</style>
<?php if(!$embedded) pageEnd(); ?>