<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';

$tab=$_GET['tab']??'procurement-method';
$allowed=['procurement-method','classification','category','area-unit','uom'];
if(!in_array($tab,$allowed,true)) $tab='procurement-method';

$pdo=db();
$categories=$pdo->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
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
  <p class="muted">Procurement methods are currently entered in the PPMP Recommended Mode of Procurement field. A dedicated master list can be added here when the approved list is finalized.</p>
  <div class="empty">No procurement method master list has been configured yet.</div>
<?php elseif($tab==='classification'): ?>
  <h2>Classification</h2>
  <p class="muted">This area is reserved for the procurement classification master list.</p>
  <div class="empty">No classification master list has been configured yet.</div>
<?php elseif($tab==='category'): ?>
  <h2>Category</h2>
  <div class="table-wrap"><table class="table"><tr><th>ID</th><th>Category</th></tr>
  <?php foreach($categories as $c): ?><tr><td><?=e($c['id'])?></td><td><?=e($c['name'])?></td></tr><?php endforeach; ?>
  </table></div>
<?php elseif($tab==='area-unit'): ?>
  <?php $embedded=true; include __DIR__.'/areas.php'; ?>
<?php else: ?>
  <?php $embedded=true; include __DIR__.'/units.php'; ?>
<?php endif; ?>
</div>
<?php pageEnd();