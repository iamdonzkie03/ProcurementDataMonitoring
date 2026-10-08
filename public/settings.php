<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';

$tab=$_GET['tab']??'procurement-method';
$allowed=['procurement-method','classification','category','area-unit','uom','login-background'];
if(!in_array($tab,$allowed,true)) $tab='procurement-method';

$pdo=db(); ensureAuthSchema($pdo);
if($tab==='login-background' && !hasRole(['Administrator'])){http_response_code(403);exit('403 - Only the System Administrator can change the login background.');}
if($_SERVER['REQUEST_METHOD']==='POST' && $tab==='login-background'){checkCsrf();setSetting('login_background',$_POST['login_background']??'philippine-blue');flash('success','Login background updated.');header('Location:settings.php?tab=login-background');exit;}



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
    <a class="btn <?=$tab==='uom'?'':'secondary'?>" href="settings.php?tab=uom">UOM</a><?php if(hasRole(['Administrator'])): ?><a class="btn <?=$tab==='login-background'?'':'secondary'?>" href="settings.php?tab=login-background">Login Background</a><?php endif; ?>
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
<?php elseif($tab==='uom'): ?>
  <?php $embedded=true; include __DIR__.'/units.php'; ?>
<?php else: ?>
  <h2>Login Background</h2><p class="muted">Only the System Administrator can change the login page visual theme.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><div class="field"><label>Background Design</label><select class="select" name="login_background"><option value="philippine-blue" <?=getSetting('login_background','philippine-blue')==='philippine-blue'?'selected':''?>>Philippine Blue — Procurement Dashboard</option><option value="midnight" <?=getSetting('login_background','')==='midnight'?'selected':''?>>Midnight Blue</option><option value="sky" <?=getSetting('login_background','')==='sky'?'selected':''?>>Clean Sky</option><option value="minimal" <?=getSetting('login_background','')==='minimal'?'selected':''?>>Minimal Light</option></select></div><div class="actions"><button class="btn" type="submit">Save Login Background</button></div></form>
<?php endif; ?>
</div>
<style>
.status-toggle{border:0!important;color:#fff!important;border-radius:999px;padding:5px 12px;font:inherit;font-weight:700;cursor:pointer;transition:none!important;box-shadow:none!important;transform:none!important}
.status-toggle.status-active{background:#198754!important}
.status-toggle.status-inactive{background:#dc3545!important}
.status-toggle:hover,.status-toggle:focus,.status-toggle:active{color:#fff!important;box-shadow:none!important;transform:none!important;outline:none!important}
</style>

<?php pageEnd();
