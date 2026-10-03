<?php require_once __DIR__.'/../config/config.php';
function pageStart(string $title): void { $u=currentUser(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | Procurement Data Monitoring</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="app"><aside class="sidebar"><div class="brand"><span class="brand-mark">P</span><div><b>Procurement</b><small>Data Monitoring</small></div></div><nav class="sidebar-nav" aria-label="Primary navigation">
<a href="index.php">▦ <span>Dashboard</span></a>
<div class="sidebar-divider"></div>
<?php if(hasRole(['Administrator','Editor','Viewer'])): ?>
<a href="app.php">▥ <span>Annual Procurement Plan</span></a>
<a href="ppmp.php">▤ <span>Project Procurement Management Plan</span></a>
<a href="pr.php">▰ <span>Purchase Requests</span></a>
<a href="po.php">▱ <span>Purchase Orders</span></a>
<a href="reports.php">◫ <span>Reports</span></a>
<?php else: ?>
<a href="app.php">▥ <span>Annual Procurement Plan</span></a>
<?php endif; ?>
<div class="sidebar-divider"></div>
<?php
$settingsOpen = in_array(basename($_SERVER['PHP_SELF'] ?? ''), ['settings.php','areas.php','units.php'], true);
if(hasRole(['Administrator','Editor'])):
?>
<div class="sidebar-group <?= $settingsOpen ? 'is-open' : '' ?>">
  <button type="button" class="sidebar-group-title" data-sidebar-accordion aria-expanded="<?= $settingsOpen ? 'true' : 'false' ?>" aria-controls="settings-submenu">
    <span class="sidebar-group-label">⚙ <span>Settings</span></span>
    <span class="sidebar-chevron" aria-hidden="true">▾</span>
  </button>
  <div id="settings-submenu" class="sidebar-submenu" style="<?= $settingsOpen ? 'max-height:500px;' : 'max-height:0;' ?>">
    <a href="settings.php?tab=procurement-method">↳ <span>Procurement Method</span></a>
    <a href="settings.php?tab=classification">↳ <span>Classification</span></a>
    <a href="settings.php?tab=category">↳ <span>Category</span></a>
    <a href="areas.php">↳ <span>Area/Unit Management</span></a>
    <a href="units.php">↳ <span>UOM</span></a>
  </div>
</div>
<?php endif; ?>
<div class="sidebar-divider"></div>
<?php if(hasRole(['Administrator'])): ?><a href="users.php">♙ <span>User Management</span></a><?php endif; ?>
</nav><div class="side-foot"><small>Fiscal year</small><strong><?=date('Y')?></strong></div></aside>
<main class="main"><header class="topbar"><button class="menu-btn" onclick="document.body.classList.toggle('collapsed')">☰</button><div><h1><?=e($title)?></h1><p>Procurement planning and monitoring</p></div><div class="profile"><span class="avatar"><?=strtoupper(substr($u['full_name']??'G',0,1))?></span><div><b><?=e($u['full_name']??'Guest')?></b><small><?=e($u['role']??'Guest')?></small></div><a href="logout.php">Logout</a></div></header><section class="content"><?php foreach(flashes() as $f): ?><div class="alert <?=$f['type']?>"><?=e($f['message'])?></div><?php endforeach; ?>
<?php }
function pageEnd(): void { ?></section></main></div><script src="assets/app.js"></script><script>
document.addEventListener("DOMContentLoaded",function(){
  document.querySelectorAll("[data-sidebar-accordion]").forEach(function(button){
    button.addEventListener("click",function(){
      var group=button.closest(".sidebar-group");
      var submenu=document.getElementById(button.getAttribute("aria-controls"));
      if(!group||!submenu)return;
      var open=group.classList.toggle("is-open");
      button.setAttribute("aria-expanded",open?"true":"false");
      submenu.style.maxHeight=open?"500px":"0";
    });
  });
});
</script></body></html><?php }
