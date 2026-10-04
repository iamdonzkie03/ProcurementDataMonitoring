<?php
require_once __DIR__.'/../config/config.php';
if(isLoggedIn()){header('Location:index.php');exit;}
$pdo=db();
$error='';
$divisions=$pdo->query('SELECT id,name FROM divisions ORDER BY name')->fetchAll();
$areas=$pdo->query('SELECT a.id,a.name,a.division_id FROM areas a ORDER BY a.division_id,a.name')->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $username=trim($_POST['username']??'');
  $divisionId=(int)($_POST['division_id']??0);
  $areaId=(int)($_POST['area_id']??0);

  $stArea=$pdo->prepare('SELECT a.id,a.name,a.division_id,d.name division_name,d.division_head,d.ppmp_supervisor_enabled FROM areas a JOIN divisions d ON d.id=a.division_id WHERE a.id=? AND a.division_id=? LIMIT 1');
  $stArea->execute([$areaId,$divisionId]);
  $selectedArea=$stArea->fetch();

  if(!$selectedArea){
    $error='Please select a valid Area/Unit and its matching Division/Department.';
  }else{
    $st=$pdo->prepare('SELECT * FROM users WHERE username=? AND status="Active" LIMIT 1');
    $st->execute([$username]);$u=$st->fetch();

    if($u && password_verify($_POST['password']??'',$u['password_hash'])){
      $_SESSION['user']=$u;
      $_SESSION['login_context']=[
        'division_id'=>(int)$selectedArea['division_id'],
        'division_name'=>(string)$selectedArea['division_name'],
        'area_id'=>(int)$selectedArea['id'],
        'area_name'=>(string)$selectedArea['name'],
        'is_ppmp_supervisor'=>((int)$selectedArea['ppmp_supervisor_enabled']===1 && strcasecmp(trim((string)$selectedArea['division_head']),trim((string)$u['full_name']))===0)
      ];
      header('Location:index.php');exit;
    }
    $error='Invalid username or password.';
  }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login | Procurement Data Monitoring</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login-page">
<form class="login-card" method="post">
<span class="badge">PROCUREMENT DATA MONITORING</span><h1>Sign in</h1><p>Access the procurement planning and monitoring portal.</p>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<div class="field"><label>Division/Department</label>
<select class="select" name="division_id" id="loginDivision" required><option value="">Select Division/Department</option>
<?php foreach($divisions as $d):?><option value="<?=$d['id']?>" <?=((int)($_POST['division_id']??0)===(int)$d['id'])?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Area/Unit</label>
<select class="select" name="area_id" id="loginArea" required><option value="">Select Area/Unit</option>
<?php foreach($areas as $a):?><option value="<?=$a['id']?>" data-division="<?=$a['division_id']?>" <?=((int)($_POST['area_id']??0)===(int)$a['id'])?'selected':''?>><?=e($a['name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Username</label><input class="input" name="username" required autofocus value="<?=e($_POST['username']??'')?>"></div>
<div class="field"><label>Password</label><input class="input" type="password" name="password" required></div>
<button class="btn" type="submit">Sign In</button>
<p style="font-size:11px">Default setup account: admin / Admin@123</p>
</form>
<script>
(function(){
 const division=document.getElementById('loginDivision'),area=document.getElementById('loginArea');
 function filterAreas(){
   const id=division.value;
   Array.from(area.options).forEach(function(opt,i){
     if(i===0){opt.hidden=false;return;}
     opt.hidden=!!id && opt.dataset.division!==id;
   });
   if(area.selectedOptions[0] && area.selectedOptions[0].hidden) area.value='';
 }
 division.addEventListener('change',filterAreas); filterAreas();
})();
</script>
</body></html>