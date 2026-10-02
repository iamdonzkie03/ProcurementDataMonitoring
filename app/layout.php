<?php require_once __DIR__.'/../config/config.php';
function pageStart(string $title): void { $u=currentUser(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | Procurement Data Monitoring</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="app"><aside class="sidebar"><div class="brand"><span class="brand-mark">P</span><div><b>Procurement</b><small>Data Monitoring</small></div></div><nav>
<a href="index.php">▦ <span>Dashboard</span></a>
<?php if(hasRole(['Administrator','Editor','Viewer'])): ?><a href="ppmp.php">▤ <span>PPMP</span></a><a href="app.php">▥ <span>Consolidated APP</span></a><a href="pr.php">▰ <span>Purchase Requests</span></a><a href="po.php">▱ <span>Purchase Orders</span></a><a href="reports.php">◫ <span>Reports</span></a><?php else: ?><a href="app.php">▥ <span>Consolidated APP</span></a><?php endif; ?>
<?php if(hasRole(['Administrator','Editor'])): ?><a href="units.php">⚖ <span>Units of Measurement</span></a><?php endif; ?><?php if(hasRole(['Administrator'])): ?><a href="users.php">♙ <span>User Management</span></a><?php endif; ?>
</nav><div class="side-foot"><small>Fiscal year</small><strong><?=date('Y')?></strong></div></aside>
<main class="main"><header class="topbar"><button class="menu-btn" onclick="document.body.classList.toggle('collapsed')">☰</button><div><h1><?=e($title)?></h1><p>Procurement planning and monitoring</p></div><div class="profile"><span class="avatar"><?=strtoupper(substr($u['full_name']??'G',0,1))?></span><div><b><?=e($u['full_name']??'Guest')?></b><small><?=e($u['role']??'Guest')?></small></div><a href="logout.php">Logout</a></div></header><section class="content"><?php foreach(flashes() as $f): ?><div class="alert <?=$f['type']?>"><?=e($f['message'])?></div><?php endforeach; ?>
<?php }
function pageEnd(): void { ?></section></main></div><script src="assets/app.js"></script></body></html><?php }
