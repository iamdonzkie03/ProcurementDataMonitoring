<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0){
  $st=$pdo->prepare('SELECT a.*,d.name division_name FROM areas a LEFT JOIN divisions d ON d.id=a.division_id WHERE a.id=?');
  $st->execute([$editId]);
  $editing=$st->fetch();
  if(!$editing){ flash('error','Area/Unit not found.'); header('Location:areas.php'); exit; }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $action=$_POST['action']??'add';
  $id=(int)($_POST['id']??0);
  $divisionId=(int)($_POST['division_id']??0);
  $name=trim($_POST['name']??'');
  $code=trim($_POST['code']??'') ?: null;

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
    header('Location:areas.php'); exit;
  }

  if($action==='save_division'){
    $divisionName=trim($_POST['division_name']??'');
    $head=trim($_POST['division_head']??'');
    if($divisionName==='' || $head===''){
      flash('error','Division/Department name and Division/Department Head are required.');
    }else{
      try{
        $st=$pdo->prepare('INSERT INTO divisions(name,division_head) VALUES(?,?)');
        $st->execute([$divisionName,$head]);
        flash('success','Division/Department added with one designated Head.');
      }catch(PDOException $e){
        flash('error','The Division/Department name already exists.');
      }
    }
    header('Location:areas.php'); exit;
  }

  if($action==='update_division'){
    $divisionId=(int)($_POST['division_id']??0);
    $divisionName=trim($_POST['division_name']??'');
    $head=trim($_POST['division_head']??'');
    if($divisionId<=0 || $divisionName==='' || $head===''){
      flash('error','Division/Department name and Division/Department Head are required.');
    }else{
      try{
        $st=$pdo->prepare('UPDATE divisions SET name=?,division_head=? WHERE id=?');
        $st->execute([$divisionName,$head,$divisionId]);
        flash('success','Division/Department updated.');
      }catch(PDOException $e){
        flash('error','The Division/Department name already exists.');
      }
    }
    header('Location:areas.php'); exit;
  }

  if($action==='save_area_names'){
    $areaId=(int)($_POST['id']??0);
    $names=array_values(array_unique(array_filter(array_map('trim',$_POST['names']??[]),fn($v)=>$v!=='')));
    if($areaId<=0){
      flash('error','Invalid Area/Unit.');
    }else{
      try{
        $pdo->beginTransaction();
        $st=$pdo->prepare('DELETE FROM area_personnel WHERE area_id=?');
        $st->execute([$areaId]);
        $ins=$pdo->prepare('INSERT INTO area_personnel(area_id,name) VALUES(?,?)');
        foreach($names as $personName){ $ins->execute([$areaId,$personName]); }
        $pdo->commit();
        flash('success','Area/Unit names updated.');
      }catch(PDOException $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error','Unable to save the Area/Unit names. Please check for duplicate names.');
      }
    }
    header('Location:areas.php?edit='.$areaId); exit;
  }

  if($action==='delete_person'){
    $personId=(int)($_POST['person_id']??0);
    if($personId<=0){ flash('error','Invalid name record.'); }
    else{
      $st=$pdo->prepare('DELETE FROM area_personnel WHERE id=?');
      $st->execute([$personId]);
      flash($st->rowCount() ? 'success' : 'error',$st->rowCount() ? 'Name removed from the Area/Unit.' : 'Name record not found.');
    }
    header('Location:areas.php'); exit;
  }

  if($name==='' || $divisionId<=0){
    flash('error','Division/Department and Area/Unit name are required.');
    header('Location:areas.php'.($action==='edit'&&$id?'?edit='.$id:'')); exit;
  }

  $names=array_values(array_unique(array_filter(array_map('trim',$_POST['names']??[]),fn($v)=>$v!=='')));

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

    // Names are part of the same Area/Unit form and are saved together with it.
    $st=$pdo->prepare('DELETE FROM area_personnel WHERE area_id=?');
    $st->execute([$areaId]);

    if($names){
      $ins=$pdo->prepare('INSERT INTO area_personnel(area_id,name) VALUES(?,?)');
      foreach($names as $personName){
        $ins->execute([$areaId,$personName]);
      }
    }

    $pdo->commit();
    flash('success',$successMessage);
  }catch(PDOException $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    flash('error','Unable to save the Area/Unit and its names. The Area/Unit name/code may already exist, or the selected Division/Department or name data is invalid.');
  }
  header('Location:areas.php'); exit;
}

$divisions=$pdo->query('SELECT id,name,division_head FROM divisions ORDER BY name')->fetchAll();
$rows=$pdo->query('
  SELECT a.id,a.name,a.code,a.created_at,d.id division_id,d.name division_name,d.division_head
  FROM areas a
  JOIN divisions d ON d.id=a.division_id
  ORDER BY d.name,a.name
')->fetchAll();

$people=$pdo->query('
  SELECT ap.id,ap.area_id,ap.name,ap.created_at,a.name area_name,d.name division_name
  FROM area_personnel ap
  JOIN areas a ON a.id=ap.area_id
  JOIN divisions d ON d.id=a.division_id
  ORDER BY d.name,a.name,ap.name
')->fetchAll();

$divisionEditId=(int)($_GET['edit_division']??0);
$divisionEditing=null;
if($divisionEditId>0){
  $st=$pdo->prepare('SELECT id,name,division_head FROM divisions WHERE id=?');
  $st->execute([$divisionEditId]);
  $divisionEditing=$st->fetch();
}
pageStart('Area/Unit Management');
?>
<div class="management-columns">
  <div class="management-column">
    <div class="panel">
  <div class="toolbar"><div><h2><?= $divisionEditing ? 'Edit Division/Department' : 'Division/Department Management' ?></h2><p>Each Division/Department has exactly one designated Head. Multiple Area/Units may be assigned under the same Division/Department.</p></div></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $divisionEditing ? 'update_division' : 'save_division' ?>">
    <?php if($divisionEditing): ?><input type="hidden" name="division_id" value="<?=e($divisionEditing['id'])?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label>Division/Department Name</label><input class="input" name="division_name" required placeholder="e.g. Medical Service" value="<?=e($divisionEditing['name']??'')?>"></div>
      <div class="field"><label>Division/Department Head</label><input class="input" name="division_head" required placeholder="e.g. Juan Dela Cruz" value="<?=e($divisionEditing['division_head']??'')?>"></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $divisionEditing ? 'Save Division/Department' : '+ Add Division/Department' ?></button>
      <?php if($divisionEditing): ?><a class="btn secondary" href="areas.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>


    <div class="panel" style="margin-top:18px">
  <h2>Division/Department List</h2>
  <div class="table-wrap"><table class="table">
    <tr><th>Division/Department</th><th>Division/Department Head</th><th>Area/Unit Count</th><th>Actions</th></tr>
    <?php foreach($divisions as $d): $cnt=0; foreach($rows as $r){if((int)$r['division_id']===(int)$d['id'])$cnt++;} ?>
      <tr><td><?=e($d['name'])?></td><td><?=e($d['division_head'])?></td><td><?=e($cnt)?></td><td><a class="btn secondary" href="areas.php?edit_division=<?=e($d['id'])?>">Edit</a></td></tr>
    <?php endforeach; ?>
    <?php if(!$divisions): ?><tr><td colspan="4">No Division/Department records found.</td></tr><?php endif; ?>
  </table></div>
</div>
  </div>
  <div class="management-column">
    <div class="panel area-unit-add-panel">
  <h2><?= $editing ? 'Edit Area/Unit' : 'Add Area/Unit' ?></h2>
  <p>Area/Units inherit the Division/Department Head from their selected Division/Department and can contain multiple names.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e($editing['id'])?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label>Division/Department *</label><select class="select" name="division_id" required><option value="">Select Division/Department</option><?php foreach($divisions as $d): ?><option value="<?=e($d['id'])?>" <?=((int)($editing['division_id']??0)===(int)$d['id'])?'selected':''?>><?=e($d['name'])?> — Head: <?=e($d['division_head'])?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Area/Unit Name *</label><input class="input" name="name" required placeholder="e.g. Operating Room" value="<?=e($editing['name']??'')?>"></div>
      <div class="field"><label>Code <small>(optional)</small></label><input class="input" name="code" placeholder="e.g. OR" value="<?=e($editing['code']??'')?>"></div>
      <div class="field full">
        <label>Names Under This Area/Unit</label>
        <div id="area-names-list">
          <?php
          $editingPeople=[];
          if($editing){
            $stPeople=$pdo->prepare('SELECT id,name FROM area_personnel WHERE area_id=? ORDER BY name');
            $stPeople->execute([$editing['id']]);
            $editingPeople=$stPeople->fetchAll();
          }
          ?>
          <?php if($editingPeople): foreach($editingPeople as $person): ?>
            <div class="area-name-row" style="display:flex;gap:8px;margin-bottom:8px">
              <input class="input" name="names[]" value="<?=e($person['name'])?>" placeholder="e.g. Maria Santos">
              <button class="btn danger remove-area-name" type="button">Remove</button>
            </div>
          <?php endforeach; else: ?>
            <div class="area-name-row" style="display:flex;gap:8px;margin-bottom:8px">
              <input class="input" name="names[]" placeholder="e.g. Maria Santos">
              <button class="btn danger remove-area-name" type="button">Remove</button>
            </div>
          <?php endif; ?>
        </div>
        <button class="btn secondary" type="button" id="add-area-name">+ Add Another Name</button>
        <small class="muted">Add as many names as needed for this Area/Unit.</small>
      </div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $editing ? 'Save Changes' : '+ Add Area/Unit' ?></button>
      <?php if($editing): ?><a class="btn secondary" href="areas.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

</div>

    <div class="panel area-unit-list-panel">
      <h2>Area/Unit List</h2>
      <div class="table-wrap"><table class="table">
        <tr><th>Division/Department</th><th>Division/Department Head</th><th>Area/Unit</th><th>Code</th><th>Names</th><th>Created</th><th>Actions</th></tr>
        <?php foreach($rows as $r): ?>
          <?php $areaPeople=array_values(array_filter($people,fn($p)=>(int)$p['area_id']===(int)$r['id'])); ?>
          <tr>
            <td><?=e($r['division_name'])?></td>
            <td><?=e($r['division_head'])?></td>
            <td><?=e($r['name'])?></td>
            <td><?=e($r['code']??'')?></td>
            <td><?php if($areaPeople): ?><ul style="margin:0;padding-left:18px"><?php foreach($areaPeople as $p): ?><li><?=e($p['name'])?></li><?php endforeach; ?></ul><?php else: ?><span class="muted">No names yet</span><?php endif; ?></td>
            <td><?=e($r['created_at'])?></td>
            <td><div class="actions">
              <a class="btn secondary" href="areas.php?edit=<?=e($r['id'])?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this Area/Unit? This can only be deleted if it is not used by existing records.');">
                <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=e($r['id'])?>">
                <button class="btn danger" type="submit">Delete</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="7">No Area/Unit records found.</td></tr><?php endif; ?>
      </table></div>
    </div>
  </div>
</div>


;
<script>
(function(){
  const list=document.getElementById('area-names-list');
  const add=document.getElementById('add-area-name');
  if(!list||!add) return;
  add.addEventListener('click',function(){
    const row=document.createElement('div');
    row.className='area-name-row';
    row.style.cssText='display:flex;gap:8px;margin-bottom:8px';
    row.innerHTML='<input class="input" name="names[]" placeholder="e.g. Maria Santos"><button class="btn danger remove-area-name" type="button">Remove</button>';
    list.appendChild(row);
  });
  list.addEventListener('click',function(e){
    if(e.target.classList.contains('remove-area-name')){
      const rows=list.querySelectorAll('.area-name-row');
      if(rows.length>1) e.target.closest('.area-name-row').remove();
      else e.target.closest('.area-name-row').querySelector('input').value='';
    }
  });
})();
</script>
<?php pageEnd(); ?>