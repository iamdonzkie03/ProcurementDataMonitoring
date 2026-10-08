<?php require_once __DIR__.'/../config/config.php';
if(!function_exists('ensureUserActivitySchema')){
function ensureUserActivitySchema(PDO $pdo): void {
    try{
        $cols=$pdo->query("SHOW COLUMNS FROM users LIKE 'last_activity_at'")->fetch();
        if(!$cols) $pdo->exec("ALTER TABLE users ADD COLUMN last_activity_at DATETIME NULL AFTER status");
    }catch(Throwable $e){}
}
}
if(!function_exists('touchCurrentUserActivity')){
function touchCurrentUserActivity(): void {
    $u=function_exists('currentUser') ? currentUser() : ($_SESSION['user'] ?? null);
    if(!$u || empty($u['id'])) return;
    try{
        $pdo=db();
        ensureUserActivitySchema($pdo);
        $st=$pdo->prepare("UPDATE users SET last_activity_at=NOW() WHERE id=?");
        $st->execute([(int)$u['id']]);
    }catch(Throwable $e){}
}
}
function pageStart(string $title): void { $u=currentUser(); touchCurrentUserActivity(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | Procurement Data Monitoring</title><link rel="stylesheet" href="assets/style.css?v=20261004-ppmpmenu"><style id="app-typography-fix">.sidebar .sidebar-nav,.sidebar .sidebar-nav a,.sidebar .sidebar-nav summary,.sidebar .sidebar-nav span{font-size:12px !important;line-height:1.2 !important}.sidebar .sidebar-nav a[href*="ppmp_review.php"]{display:none !important}</style></head><body>
<div class="app"><header class="site-header"><div class="site-brand"><span class="brand-mark">P</span><div><b>Procurement Data Monitoring</b><small>Procurement Planning and Monitoring System</small></div></div><div class="site-user"><span class="avatar"><?=strtoupper(substr($u['full_name']??'G',0,1))?></span><div><b><?=e($u['full_name']??'Guest')?></b><small><?=e($u['role']??'Guest')?></small></div><a href="logout.php">Logout</a></div></header><aside class="sidebar"><div class="brand"><span class="brand-mark">P</span><div><b>Procurement</b><small>Data Monitoring</small></div></div><nav class="sidebar-nav" aria-label="Primary navigation">
<a href="index.php">▦ <span>Dashboard</span></a>
<div class="sidebar-divider"></div>
<?php if(hasRole(['Administrator','Editor','Viewer'])): ?>
<a href="app.php">▥ <span>Annual Procurement Plan</span></a>
<a href="ppmp.php">▤ <span>Project Procurement Management Plan</span></a>
<a href="pr.php">▰ <span>Purchase Requests</span></a>
<a href="po.php">▱ <span>Purchase Orders</span></a>
<a href="supplier_registry.php">◫ <span>Supplier Registry</span></a>

<?php else: ?>
<a href="app.php">▥ <span>Annual Procurement Plan</span></a>
<?php endif; ?>
<div class="sidebar-divider"></div>
<?php if(hasRole(['Administrator','Editor'])): ?>
<?php $settingsPages=['settings.php']; $currentPage=basename(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)); $settingsOpen=in_array($currentPage,$settingsPages,true); ?>
<details class="sidebar-group settings-menu"<?= $settingsOpen ? ' open' : '' ?>>
<summary class="sidebar-group-title"><span class="sidebar-group-label">⚙ <span>Settings</span></span><span class="sidebar-chevron" aria-hidden="true">▾</span></summary>
<div id="settings-submenu" class="sidebar-submenu">
<a class="<?=($currentPage==='settings.php' && ($tab??'')==='procurement-method')?'active':''?>" href="settings.php?tab=procurement-method">↳ <span>Procurement Method</span></a>
<a class="<?=($currentPage==='settings.php' && ($tab??'')==='classification')?'active':''?>" href="settings.php?tab=classification">↳ <span>Classification</span></a>
<a class="<?=($currentPage==='settings.php' && ($tab??'')==='category')?'active':''?>" href="settings.php?tab=category">↳ <span>Category</span></a>
<a class="<?=($currentPage==='settings.php' && ($tab??'')==='area-unit')?'active':''?>" href="settings.php?tab=area-unit">↳ <span>Area/Unit Management</span></a>
<a class="<?=($currentPage==='settings.php' && ($tab??'')==='uom')?'active':''?>" href="settings.php?tab=uom">↳ <span>UOM</span></a>
<?php if(hasRole(['Administrator'])): ?><a class="<?=($currentPage==='settings.php' && ($tab??'')==='login-background')?'active':''?>" href="settings.php?tab=login-background">↳ <span>Login Background</span></a><?php endif; ?>
</div></details>
<?php endif; ?>
<div class="sidebar-divider"></div>
<?php if(hasRole(['Administrator','Editor'])): ?><a href="users.php">♙ <span>User Management</span></a><?php endif; ?>
</nav><div class="side-foot"><small>Fiscal year</small><strong><?=date('Y')?></strong></div></aside>
<main class="main"><section class="page-heading"><div><h1><?=e($title)?></h1><p>Procurement planning and monitoring</p></div></section><section class="content"><?php foreach(flashes() as $f): ?><div class="alert <?=$f['type']?>"><?=e($f['message'])?></div><?php endforeach; ?>
<?php }
function pageEnd(): void { ?></section></main></div><script src="assets/app.js?v=20261004-ppmpmenu2?v=20261004-ppmpmenu"></script><script>(function(){if(window.__procurementHeartbeat)return;window.__procurementHeartbeat=true;const beat=function(){fetch('session_heartbeat.php',{method:'GET',credentials:'same-origin',cache:'no-store'}).catch(function(){});};beat();setInterval(beat,60000);})();</script></body></html><?php }
