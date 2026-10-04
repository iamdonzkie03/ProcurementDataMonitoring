<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator']);require_once __DIR__.'/../app/layout.php';$pdo=db(); ensureUserAccessSchema($pdo);
$divisions=$pdo->query('SELECT id,name,division_head,head_position_designation FROM divisions ORDER BY name')->fetchAll();
$areas=$pdo->query('SELECT id,name,division_id FROM areas ORDER BY name')->fetchAll();
$personnel=$pdo->query('SELECT ap.id,ap.name,ap.area_id,ap.position_designation,a.division_id,a.name area_name FROM area_personnel ap JOIN areas a ON a.id=ap.area_id ORDER BY a.name,ap.name')->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){checkCsrf();$action=$_POST['action']??'';if($action==='create'){$p=$_POST['password']??'';if(strlen($p)<8){flash('error','Password must be at least 8 characters.');}else{$divisionId=(int)($_POST['division_id']??0);
$personName=trim($_POST['full_name']??'');
$person=$pdo->prepare('SELECT ap.name,ap.area_id FROM area_personnel ap JOIN areas a ON a.id=ap.area_id WHERE a.division_id=? AND ap.name=? LIMIT 1');
$person->execute([$divisionId,$personName]);$personRow=$person->fetch();
$head=$pdo->prepare('SELECT division_head FROM divisions WHERE id=? AND division_head=? LIMIT 1');
$head->execute([$divisionId,$personName]);$isDivisionHead=(bool)$head->fetchColumn();
$canonicalName=$personRow['name']??($isDivisionHead?$personName:'');
$areaId=(int)($personRow['area_id']??0);
if($divisionId<=0 || $canonicalName===''){flash('error','Please select a valid Division/Department and Name / Full Name assigned to that Division.');}
else{
$st=$pdo->prepare('INSERT INTO users(username,full_name,email,password_hash,role,status,division_id,area_id) VALUES(?,?,?,?,?,?,?,?)');$st->execute([trim($_POST['username']),$canonicalName,trim($_POST['email']),password_hash($p,PASSWORD_DEFAULT),$_POST['role'],$_POST['status'],$divisionId,$areaId]);flash('success','User created.');}}}elseif($action==='toggle'){$pdo->prepare('UPDATE users SET status=IF(status="Active","Inactive","Active") WHERE id=?')->execute([(int)$_POST['id']]);flash('success','User status updated.');}header('Location:users.php');exit;}$rows=$pdo->query('SELECT u.id,u.username,u.full_name,u.email,u.role,u.status,u.created_at,d.name division_name,a.name area_name FROM users u LEFT JOIN divisions d ON d.id=u.division_id LEFT JOIN areas a ON a.id=u.area_id ORDER BY u.full_name')->fetchAll();pageStart('User Management');
?><div class="grid"><div class="panel"><h2>Users</h2><div class="table-wrap"><table class="table"><tr><th>Name</th><th>Username</th><th>Division / Area</th><th>Role</th><th>Status</th><th>Action</th></tr><?php foreach($rows as $r):?><tr><td><?=e($r['full_name'])?><br><small><?=e($r['email'])?></small></td><td><?=e($r['username'])?></td><td><?=e($r['division_name']??'Not assigned')?> / <?=e($r['area_name']??'Not assigned')?></td><td><?=e($r['role'])?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?php if($r['id']!==currentUser()['id']):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn secondary" type="submit">Toggle</button></form><?php endif;?></td></tr><?php endforeach;?></table></div></div><div class="panel"><h2>Create User</h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create">
<div class="field"><label>Division/Department</label><select class="select" name="division_id" id="userDivision" required><option value="">Select Division/Department</option><?php foreach($divisions as $d):?><option value="<?=$d['id']?>" data-head="<?=e($d['division_head']??'')?>" data-position="<?=e($d['head_position_designation']??'')?>"><?=e($d['name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Select Name / Full Name</label><select class="select" name="full_name" id="userName" required disabled><option value="">Select Name / Full Name</option><?php foreach($divisions as $d):?><option value="<?=e($d['division_head'])?>" data-division="<?=$d['id']?>" data-head="1" data-position="<?=e($d['head_position_designation']??'')?>"><?=e($d['division_head'])?> — Division/Department Head<?php if(($d['head_position_designation']??'')!==''):?> — <?=e($d['head_position_designation'])?><?php endif;?></option><?php endforeach;?><?php foreach($personnel as $p):?><option value="<?=e($p['name'])?>" data-division="<?=$p['division_id']?>" data-area="<?=$p['area_id']?>" data-position="<?=e($p['position_designation']??'')?>"><?=e($p['name'])?> — <?=e($p['area_name'])?><?php if(($p['position_designation']??'')!==''):?> — <?=e($p['position_designation'])?><?php endif;?></option><?php endforeach;?></select></div>
<div class="field"><label>Username</label><input class="input" name="username" required></div>
<div class="field"><label>Password</label><input class="input" type="password" name="password" minlength="8" required></div>
<div class="field"><label>Email</label><input class="input" type="email" name="email"></div>
<div class="field"><label>Role</label><select class="select" name="role"><option>Administrator</option><option>Editor</option><option>Viewer</option><option>Guest</option></select></div>
<div class="field"><label>Status</label><select class="select" name="status"><option>Active</option><option>Inactive</option></select></div>
<div class="actions"><button class="btn">Create User</button></div></form></div></div><script>
(function(){
 const d=document.getElementById('userDivision'),n=document.getElementById('userName');
 function filterNames(){const id=d.value;Array.from(n.options).forEach((o,i)=>{if(i)o.hidden=!id||o.dataset.division!==id;});if(!n.value||(n.selectedOptions[0]&&n.selectedOptions[0].hidden))n.value='';n.disabled=!id;}
 d.addEventListener('change',filterNames);filterNames();
})();
</script><?php pageEnd();
