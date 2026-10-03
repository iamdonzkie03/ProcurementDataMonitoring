<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';

$tab=$_GET['tab']??'procurement-method';
$allowed=['procurement-method','classification','category','area-unit','uom'];
if(!in_array($tab,$allowed,true)) $tab='procurement-method';

$pdo=db();

function ensureSettingsSchema(PDO $pdo): void {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS classifications (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL UNIQUE,
      status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $cols=$pdo->query("SHOW COLUMNS FROM categories LIKE 'status'")->fetch();
    if(!$cols) $pdo->exec("ALTER TABLE categories ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER name");
    $cols=$pdo->query("SHOW COLUMNS FROM procurement_methods LIKE 'status'")->fetch();
    if(!$cols) $pdo->exec("ALTER TABLE procurement_methods ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER details");
  } catch(PDOException $e) {
    // The page will still show the original database error if the DB user cannot alter/create tables.
  }
}
ensureSettingsSchema($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $action=$_POST['action']??'';
  if($action==='classification_toggle' || $action==='category_toggle'){
    $id=(int)($_POST['id']??0);
    $table=$action==='classification_toggle'?'classifications':'categories';
    if($id>0){
      $st=$pdo->prepare("UPDATE {$table} SET status=IF(status='Active','Inactive','Active') WHERE id=?");
      $st->execute([$id]);
      flash('success',($action==='classification_toggle'?'Classification':'Category').' status updated.');
    }
    header('Location:settings.php?tab='.($action==='classification_toggle'?'classification':'category')); exit;
  }
  if($action==='classification_save' || $action==='category_save'){
    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
    $table=$action==='classification_save'?'classifications':'categories'; $label=$action==='classification_save'?'Classification':'Category';
    if($name===''){ flash('error',$label.' is required.'); }
    else { try { if($id>0){$st=$pdo->prepare("UPDATE {$table} SET name=? WHERE id=?");$st->execute([$name,$id]);flash('success',$label.' updated.');}else{$st=$pdo->prepare("INSERT INTO {$table}(name) VALUES(?)");$st->execute([$name]);flash('success',$label.' added.');} } catch(PDOException $e){flash('error',$e->getCode()==='23000'?'That '.$label.' already exists.':'Unable to save the '.$label.'.');} }
    header('Location:settings.php?tab='.($action==='classification_save'?'classification':'category')); exit;
  }
  if($action==='classification_delete' || $action==='category_delete'){
    $id=(int)($_POST['id']??0); $table=$action==='classification_delete'?'classifications':'categories'; $label=$action==='classification_delete'?'Classification':'Category';
    if($id>0){try{$st=$pdo->prepare("DELETE FROM {$table} WHERE id=?");$st->execute([$id]);flash('success',$label.' deleted.');}catch(PDOException $e){flash('error','Unable to delete the '.$label.'. It may already be referenced by another record.');}}
    header('Location:settings.php?tab='.($action==='classification_delete'?'classification':'category')); exit;
  }
  if($action==='procurement_method_toggle'){
    $id=(int)($_POST['id']??0);
    if($id>0){
      $st=$pdo->prepare("UPDATE procurement_methods SET status=IF(status='Active','Inactive','Active') WHERE id=?");
      $st->execute([$id]);
      flash('success','Procurement Method status updated.');
    }
    header('Location:settings.php?tab=procurement-method'); exit;
  }
  if($action==='procurement_method_save'){
    $id=(int)($_POST['id']??0);
    $method=trim($_POST['procurement_method']??'');
    $details=trim($_POST['details']??'');
    if($method===''){
      flash('error','Procurement Method is required.');
    }else{
      try{
        if($id>0){
          $st=$pdo->prepare('UPDATE procurement_methods SET procurement_method=?,details=? WHERE id=?');
          $st->execute([$method,$details!==''?$details:null,$id]);
          flash('success','Procurement Method updated.');
        }else{
          $st=$pdo->prepare('INSERT INTO procurement_methods(procurement_method,details) VALUES(?,?)');
          $st->execute([$method,$details!==''?$details:null]);
          flash('success','Procurement Method added.');
        }
      }catch(PDOException $e){
        flash('error',$e->getCode()==='23000'?'That Procurement Method already exists.':'Unable to save the Procurement Method.');
      }
    }
    header('Location:settings.php?tab=procurement-method'); exit;
  }
  if($action==='procurement_method_delete'){
    $id=(int)($_POST['id']??0);
    if($id>0){
      try{
        $st=$pdo->prepare('DELETE FROM procurement_methods WHERE id=?');
        $st->execute([$id]);
        flash('success','Procurement Method deleted.');
      }catch(PDOException $e){
        flash('error','Unable to delete the Procurement Method. It may already be referenced by another record.');
      }
    }
    header('Location:settings.php?tab=procurement-method'); exit;
  }
}

$editingClassification=null; $editingCategory=null;
if($tab==='classification' && isset($_GET['edit'])){$st=$pdo->prepare('SELECT id,name,status FROM classifications WHERE id=?');$st->execute([(int)$_GET['edit']]);$editingClassification=$st->fetch()?:null;}
if($tab==='category' && isset($_GET['edit'])){$st=$pdo->prepare('SELECT id,name,status FROM categories WHERE id=?');$st->execute([(int)$_GET['edit']]);$editingCategory=$st->fetch()?:null;}
$classifications=$pdo->query('SELECT id,name,status FROM classifications ORDER BY name')->fetchAll();
$categories=$pdo->query('SELECT id,name,status FROM categories ORDER BY name')->fetchAll();
$editingMethod=null;
if($tab==='procurement-method' && isset($_GET['edit'])){
  $st=$pdo->prepare('SELECT id,procurement_method,details,status FROM procurement_methods WHERE id=?');
  $st->execute([(int)$_GET['edit']]);
  $editingMethod=$st->fetch() ?: null;
}
$procurementMethods=$pdo->query('SELECT id,procurement_method,details,status FROM procurement_methods ORDER BY procurement_method')->fetchAll();
pageStart('Settings');
?>
<div class="panel">
  <h2>Settings</h2>
  <p class="muted">Manage procurement master data, Area/Unit hierarchy, and Units of Measurement.</p>
  <div class="settings-tabs">
    <a class="btn <?=$tab==='procurement-method'?'':'secondary'?>" href="settings.php?tab=procurement-method">Procurement Method</a>
    <a class="btn <?=$tab==='classification'?'':'secondary'?>" href="settings.php?tab=classification">Classification</a>
    <a class="btn <?=$tab==='category'?'':'secondary'?>" href="settings.php?tab=category">Category</a>
    <a class="btn <?=$tab==='area-unit'?'':'secondary'?>" href="settings.php?tab=area-unit">Area/Unit Management</a>
    <a class="btn <?=$tab==='uom'?'':'secondary'?>" href="settings.php?tab=uom">UOM</a>
  </div>
</div>

<div class="panel settings-panel">
<?php if($tab==='procurement-method'): ?>
  <h2>Procurement Method</h2>
  <p class="muted">Add and maintain the procurement methods available for procurement planning and monitoring.</p>

  <form method="post" class="form-grid" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="procurement_method_save">
    <input type="hidden" name="id" value="<?=e((string)($editingMethod['id']??0))?>">
    <div class="field">
      <label>Procurement Method</label>
      <input class="input" name="procurement_method" value="<?=e($editingMethod['procurement_method']??'')?>" required placeholder="e.g. Public Bidding">
    </div>
    <div class="field full">
      <label>Details</label>
      <textarea class="input" name="details" rows="4" placeholder="Enter details or description of the procurement method"><?=e($editingMethod['details']??'')?></textarea>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $editingMethod ? 'Save Changes' : 'Add Procurement Method' ?></button>
      <?php if($editingMethod): ?><a class="btn secondary" href="settings.php?tab=procurement-method">Cancel</a><?php endif; ?>
    </div>
  </form>

  <div class="table-wrap" style="margin-top:22px">
    <table class="table">
      <thead><tr><th>Procurement Method</th><th>Details</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if($procurementMethods): foreach($procurementMethods as $pm): ?>
        <tr>
          <td><?=e($pm['procurement_method'])?></td>
          <td><?=nl2br(e($pm['details']??''))?></td>
          <td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="procurement_method_toggle"><input type="hidden" name="id" value="<?=(int)$pm['id']?>"><button class="status-toggle <?=$pm['status']==='Active'?'status-active':'status-inactive'?>" type="submit" title="Click to change status"><?=e($pm['status'])?></button></form></td>
          <td>
            <a class="btn secondary master-action" href="settings.php?tab=procurement-method&edit=<?=(int)$pm['id']?>">Edit</a>
            <form method="post" class="master-action-form" onsubmit="return confirm('Delete this Procurement Method?');">
              <input type="hidden" name="csrf" value="<?=e(csrf())?>">
              <input type="hidden" name="action" value="procurement_method_delete">
              <input type="hidden" name="id" value="<?=(int)$pm['id']?>">
              <button class="btn danger master-action" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="4" class="empty">No Procurement Methods have been added yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php elseif($tab==='classification'): ?>
  <h2>Classification</h2>
  <p class="muted">Add, edit, delete, activate, or deactivate procurement classifications.</p>
  <form method="post" class="form-grid" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="classification_save">
    <input type="hidden" name="id" value="<?=e((string)($editingClassification['id']??0))?>">
    <div class="field"><label>Classification</label><input class="input" name="name" value="<?=e($editingClassification['name']??'')?>" required></div>
    <div class="actions"><button class="btn" type="submit"><?=$editingClassification?'Save Changes':'Add Classification'?></button><?php if($editingClassification): ?><a class="btn secondary" href="settings.php?tab=classification">Cancel</a><?php endif; ?></div>
  </form>
  <div class="table-wrap" style="margin-top:22px"><table class="table"><thead><tr><th>Classification</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($classifications as $row): ?><tr><td><?=e($row['name'])?></td><td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="classification_toggle"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="status-toggle <?=$row['status']==='Active'?'status-active':'status-inactive'?>" type="submit" title="Click to change status"><?=e($row['status'])?></button></form></td><td>
  <a class="btn secondary master-action" href="settings.php?tab=classification&edit=<?=(int)$row['id']?>">Edit</a>
  <form method="post" class="master-action-form" onsubmit="return confirm('Delete this Classification?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="classification_delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn danger" type="submit">Delete</button></form>
  </td></tr><?php endforeach; ?><?php if(!$classifications): ?><tr><td colspan="3" class="empty">No Classifications have been added yet.</td></tr><?php endif; ?></tbody></table></div>
<?php elseif($tab==='category'): ?>
  <h2>Category</h2>
  <p class="muted">Add, edit, delete, activate, or deactivate procurement categories.</p>
  <form method="post" class="form-grid" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="category_save">
    <input type="hidden" name="id" value="<?=e((string)($editingCategory['id']??0))?>">
    <div class="field"><label>Category</label><input class="input" name="name" value="<?=e($editingCategory['name']??'')?>" required></div>
    <div class="actions"><button class="btn" type="submit"><?=$editingCategory?'Save Changes':'Add Category'?></button><?php if($editingCategory): ?><a class="btn secondary" href="settings.php?tab=category">Cancel</a><?php endif; ?></div>
  </form>
  <div class="table-wrap" style="margin-top:22px"><table class="table"><thead><tr><th>Category</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($categories as $row): ?><tr><td><?=e($row['name'])?></td><td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="category_toggle"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="status-toggle <?=$row['status']==='Active'?'status-active':'status-inactive'?>" type="submit" title="Click to change status"><?=e($row['status'])?></button></form></td><td>
  <a class="btn secondary master-action" href="settings.php?tab=category&edit=<?=(int)$row['id']?>">Edit</a>
  <form method="post" class="master-action-form" onsubmit="return confirm('Delete this Category?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="category_delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn danger" type="submit">Delete</button></form>
  </td></tr><?php endforeach; ?><?php if(!$categories): ?><tr><td colspan="3" class="empty">No Categories have been added yet.</td></tr><?php endif; ?></tbody></table></div>
<?php elseif($tab==='area-unit'): ?>
  <?php $embedded=true; include __DIR__.'/areas.php'; ?>
<?php else: ?>
  <?php $embedded=true; include __DIR__.'/units.php'; ?>
<?php endif; ?>
</div>
<style>
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
.status-toggle{border:0!important;color:#fff!important;border-radius:999px;padding:5px 12px;font:inherit;font-weight:700;cursor:pointer;transition:none!important;box-shadow:none!important;transform:none!important}
.status-toggle.status-active{background:#198754!important}
.status-toggle.status-inactive{background:#dc3545!important}
.status-toggle:hover,.status-toggle:focus,.status-toggle:active{color:#fff!important;box-shadow:none!important;transform:none!important;outline:none!important}
</style>
<?php pageEnd();