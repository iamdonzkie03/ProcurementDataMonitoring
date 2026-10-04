<?php
require_once __DIR__.'/../config/config.php';
if(isLoggedIn()){header('Location:'.(currentUserIsPpmpSupervisor()?'ppmp_review.php':'index.php'));exit;}
$pdo=db(); ensureUserAccessSchema($pdo);
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $username=trim($_POST['username']??'');
  $st=$pdo->prepare('SELECT * FROM users WHERE username=? AND status="Active" LIMIT 1');
  $st->execute([$username]);$u=$st->fetch();
  if($u && password_verify($_POST['password']??'',$u['password_hash'])){
    $_SESSION['user']=$u;
    $_SESSION['login_context']=buildLoginContext($pdo,$u);
    header('Location:'.(currentUserIsPpmpSupervisor()?'ppmp_review.php':'index.php'));exit;
  }
  $error='Invalid username or password.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login | Procurement Data Monitoring</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login-page">
<form class="login-card" method="post">
<span class="badge">PROCUREMENT DATA MONITORING</span><h1>Sign in</h1><p>Access is controlled by the System Administrator. Your Division/Department and Area/Unit are assigned to your account.</p>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<div class="field"><label>Username</label><input class="input" name="username" required autofocus value="<?=e($_POST['username']??'')?>"></div>
<div class="field"><label>Password</label><input class="input" type="password" name="password" required></div>
<button class="btn" type="submit">Sign In</button>
</form>
</body></html>