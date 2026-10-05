<?php
declare(strict_types=1);
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';

$pdo=db();
$action=$_POST['action']??'';
$editId=(int)($_GET['edit']??0);
$editing=null;

try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS ppmp_masterlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    unit_of_measurement VARCHAR(100) NOT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ppmp_masterlist_item (item_name),
    INDEX idx_ppmp_masterlist_uom (unit_of_measurement)
  ) ENGINE=InnoDB");
}catch(PDOException $e){
  flash('error','Unable to initialize the PPMP Masterlist table.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $id=(int)($_POST['id']??0);
  $itemName=trim((string)($_POST['item_name']??''));
  $uom=trim((string)($_POST['unit_of_measurement']??''));
  $unitCostRaw=trim((string)($_POST['unit_cost']??''));
  $unitCost=is_numeric($unitCostRaw)?(float)$unitCostRaw:-1;
  $userId=(int)(currentUser()['id']??0);

  if($action==='delete'){
    if($id<=0){
      flash('error','Invalid PPMP Masterlist item.');
    }else{
      try{
        $st=$pdo->prepare('DELETE FROM ppmp_masterlist WHERE id=?');
        $st->execute([$id]);
        flash($st->rowCount()?'success':'error',$st->rowCount()?'Masterlist item deleted.':'Masterlist item not found.');
      }catch(PDOException $e){
        flash('error','Unable to delete the masterlist item.');
      }
    }
    header('Location:ppmp_masterlist.php'); exit;
  }

  if($itemName==='' || $uom==='' || $unitCostRaw==='' || $unitCost<0){
    flash('error','Item Name, Unit of Measurement, and a valid Unit Cost are required.');
    header('Location:ppmp_masterlist.php'.($id>0?'?edit='.$id:'')); exit;
  }

  try{
    if($action==='update' && $id>0){
      $st=$pdo->prepare('UPDATE ppmp_masterlist SET item_name=?,unit_of_measurement=?,unit_cost=?,updated_by=? WHERE id=?');
      $st->execute([$itemName,$uom,$unitCost,$userId,$id]);
      flash('success','PPMP Masterlist item updated.');
    }else{
      $st=$pdo->prepare('INSERT INTO ppmp_masterlist (item_name,unit_of_measurement,unit_cost,created_by,updated_by) VALUES (?,?,?,?,?)');
      $st->execute([$itemName,$uom,$unitCost,$userId,$userId]);
      flash('success','PPMP Masterlist item added.');
    }
  }catch(PDOException $e){
    flash('error','Unable to save the PPMP Masterlist item.');
  }
  header('Location:ppmp_masterlist.php'); exit;
}

if($editId>0){
  $st=$pdo->prepare('SELECT * FROM ppmp_masterlist WHERE id=? LIMIT 1');
  $st->execute([$editId]);
  $editing=$st->fetch();
  if(!$editing){
    flash('error','PPMP Masterlist item not found.');
    header('Location:ppmp_masterlist.php'); exit;
  }
}

$st=$pdo->query('SELECT id,item_name,unit_of_measurement,unit_cost,created_at,updated_at FROM ppmp_masterlist ORDER BY item_name ASC,id ASC');
$masterlist=$st->fetchAll();

pageStart('PPMP Masterlist');
?>
<style>
.masterlist-toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.masterlist-toolbar h2{margin:0}
.masterlist-form-grid{display:grid;grid-template-columns:minmax(220px,1.5fr) minmax(180px,.8fr) minmax(160px,.7fr) auto;gap:12px;align-items:end}
.masterlist-form-grid .field{margin:0}
.masterlist-actions{display:flex;gap:6px;align-items:center;white-space:nowrap}
.masterlist-actions form{margin:0}
.masterlist-table th,.masterlist-table td{vertical-align:middle}
.masterlist-cost{text-align:right;white-space:nowrap}
@media(max-width:900px){.masterlist-form-grid{grid-template-columns:1fr 1fr}.masterlist-form-grid .masterlist-submit{grid-column:1/-1}}
@media(max-width:600px){.masterlist-form-grid{grid-template-columns:1fr}}
</style>

<div class="panel">
  <div class="masterlist-toolbar">
    <div>
      <h2>PPMP Masterlist</h2>
      <p class="muted" style="margin:5px 0 0">Maintain the standard items, units of measurement, and unit costs available for PPMP preparation.</p>
    </div>
    <a class="btn secondary" href="ppmp.php">Back to Project Procurement Management Plan</a>
  </div>
</div>

<div class="panel">
  <h2><?= $editing ? 'Edit Masterlist Item' : 'Add Masterlist Item' ?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'save' ?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e((string)$editing['id'])?>"><?php endif; ?>
    <div class="masterlist-form-grid">
      <div class="field">
        <label>Item Name *</label>
        <input class="input" type="text" name="item_name" required maxlength="255" value="<?=e($editing['item_name']??'')?>" placeholder="Enter item name">
      </div>
      <div class="field">
        <label>Unit of Measurement *</label>
        <input class="input" type="text" name="unit_of_measurement" required maxlength="100" value="<?=e($editing['unit_of_measurement']??'')?>" placeholder="e.g. pc, box, set">
      </div>
      <div class="field">
        <label>Unit Cost *</label>
        <input class="input" type="number" name="unit_cost" required min="0" step="0.01" inputmode="decimal" value="<?= $editing ? e(number_format((float)$editing['unit_cost'],2,'.','')) : '' ?>" placeholder="0.00">
      </div>
      <div class="masterlist-submit">
        <button class="btn" type="submit"><?= $editing ? 'Update Item' : 'Add Item' ?></button>
        <?php if($editing): ?><a class="btn secondary" href="ppmp_masterlist.php">Cancel</a><?php endif; ?>
      </div>
    </div>
  </form>
</div>

<div class="panel">
  <h2>Masterlist Items</h2>
  <?php if($masterlist): ?>
  <div class="table-wrap">
    <table class="table masterlist-table">
      <thead><tr><th>#</th><th>Item Name</th><th>Unit of Measurement</th><th class="masterlist-cost">Unit Cost</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($masterlist as $i=>$row): ?>
        <tr>
          <td><?=e((string)($i+1))?></td>
          <td><?=e($row['item_name'])?></td>
          <td><?=e($row['unit_of_measurement'])?></td>
          <td class="masterlist-cost">₱<?=number_format((float)$row['unit_cost'],2)?></td>
          <td>
            <div class="masterlist-actions">
              <a class="btn secondary" href="ppmp_masterlist.php?edit=<?=e((string)$row['id'])?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete this PPMP Masterlist item?');">
                <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=e((string)$row['id'])?>">
                <button class="btn danger" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty">No PPMP Masterlist items have been added yet.</div>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>