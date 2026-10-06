<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';

$tab=$_GET['tab']??'procurement-method';
$allowed=['procurement-method','classification','category','area-unit','uom'];
if(!in_array($tab,$allowed,true)) $tab='procurement-method';

$pdo=db();



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
  <?php $embedded=true; include __DIR__.'/procurement_methods.php'; ?>
<?php elseif($tab==='classification'): ?>
  <?php $embedded=true; include __DIR__.'/classification.php'; ?>
<?php elseif($tab==='category'): ?>
  <?php $embedded=true; include __DIR__.'/categories.php'; ?>
<?php elseif($tab==='area-unit'): ?>
  <?php $embedded=true; include __DIR__.'/areas.php'; ?>
<?php else: ?>
  <?php $embedded=true; include __DIR__.'/units.php'; ?>
<?php endif; ?>
</div>
<style>
.status-toggle{border:0!important;color:#fff!important;border-radius:999px;padding:5px 12px;font:inherit;font-weight:700;cursor:pointer;transition:none!important;box-shadow:none!important;transform:none!important}
.status-toggle.status-active{background:#198754!important}
.status-toggle.status-inactive{background:#dc3545!important}
.status-toggle:hover,.status-toggle:focus,.status-toggle:active{color:#fff!important;box-shadow:none!important;transform:none!important;outline:none!important}
</style>

<?php pageEnd();
