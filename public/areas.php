<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0){
  $st=$pdo->prepare('SELECT id,name,code,authorized_person FROM areas WHERE id=?');
  $st->execute([$editId]);
  $editing=$st->fetch();
  if(!$editing){ flash('error','Area/Unit not found.'); header('Location:areas.php'); exit; }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $action=$_POST['action']??'add';
  $id=(int)($_POST['id']??0);
  $name=trim($_POST['name']??'');
  $code=trim($_POST['code']??'') ?: null;
  $authorized=trim($_POST['authorized_person']??'') ?: null;

  if($action==='delete'){
    if($id<=0){ flash('error','Invalid Area/Unit.'); }
    else {
      try{
        $st=$pdo->prepare('DELETE FROM areas WHERE id=?');
        $st->execute([$id]);
        flash($st->rowCount() ? 'success' : 'error',$st->rowCount() ? 'Area/Unit deleted.' : 'Area/Unit not found.');
      }catch(PDOException $e){
        flash('error','This Area/Unit cannot be deleted because it is already used by existing PPMP records.');
      }
    }
    header('Location:areas.php'); exit;
  }

  if($name===''){ flash('error','Area/Unit name is required.'); header('Location:areas.php'.($action==='edit'&&$id?'?edit='.$id:'')); exit; }
  if($authorized===null){ flash('error','Name of Supervisor/Authorized Person is required.'); header('Location:areas.php'.($action==='edit'&&$id?'?edit='.$id:'')); exit; }

  try{
    if($action==='edit' && $id>0){
      $st=$pdo->prepare('UPDATE areas SET name=?,code=?,authorized_person=? WHERE id=?');
      $st->execute([$name,$code,$authorized,$id]);
      flash('success','Area/Unit updated.');
    }else{
      $st=$pdo->prepare('INSERT INTO areas(name,code,authorized_person) VALUES(?,?,?)');
      $st->execute([$name,$code,$authorized]);
      flash('success','Area/Unit added.');
    }
  }catch(PDOException $e){
    flash('error','Area/Unit name or code already exists.');
  }
  header('Location:areas.php'); exit;
}

$rows=$pdo->query('SELECT id,name,code,authorized_person,created_at FROM areas ORDER BY name')->fetchAll();
pageStart('Area/Unit Management');
?>
<div class="panel">
  <div class="toolbar"><div><h2><?= $editing ? 'Edit Area/Unit' : 'Area/Unit' ?></h2><p><?= $editing ? 'Update the Area/Unit and its Supervisor/Authorized Person.' : 'Add the offices, departments or units that can be selected when creating a PPMP.' ?></p></div></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e($editing['id'])?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label>Area/Unit Name</label><input class="input" name="name" required placeholder="e.g. Medical Service" value="<?=e($editing['name']??'')?>"></div>
      <div class="field"><label>Code <small>(optional)</small></label><input class="input" name="code" placeholder="e.g. MED" value="<?=e($editing['code']??'')?>"></div>
      <div class="field"><label>Name of Supervisor/Authorized Person</label><input class="input" name="authorized_person" required placeholder="e.g. Juan Dela Cruz" value="<?=e($editing['authorized_person']??'')?>"></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $editing ? 'Save Changes' : '+ Add Area/Unit' ?></button>
      <?php if($editing): ?><a class="btn secondary" href="areas.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<div class="panel" style="margin-top:18px">
  <h2>Area/Unit List</h2>
  <div class="table-wrap"><table class="table">
    <tr><th>Area/Unit</th><th>Code</th><th>Supervisor/Authorized Person</th><th>Created</th><th>Actions</th></tr>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?=e($r['name'])?></td>
        <td><?=e($r['code']??'')?></td>
        <td><?=e($r['authorized_person']??'')?></td>
        <td><?=e($r['created_at'])?></td>
        <td>
          <div class="actions">
            <a class="btn secondary" href="areas.php?edit=<?=e($r['id'])?>">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this Area/Unit? This can only be deleted if it is not used by existing PPMP records.');">
              <input type="hidden" name="csrf" value="<?=e(csrf())?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=e($r['id'])?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="5">No Area/Unit records found.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php pageEnd();