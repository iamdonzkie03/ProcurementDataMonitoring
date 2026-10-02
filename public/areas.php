<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $name=trim($_POST['name']??'');
  if($name===''){ flash('error','Area/Unit name is required.'); header('Location:areas.php'); exit; }
  try{
    $st=$pdo->prepare('INSERT INTO areas(name,code) VALUES(?,?)');
    $st->execute([$name,trim($_POST['code']??'') ?: null]);
    flash('success','Area/Unit added.');
  }catch(PDOException $e){ flash('error','Area/Unit name or code already exists.'); }
  header('Location:areas.php'); exit;
}
$rows=$pdo->query('SELECT id,name,code,created_at FROM areas ORDER BY name')->fetchAll();
pageStart('Area/Unit Management');
?>
<div class="panel">
  <div class="toolbar"><div><h2>Area/Unit</h2><p>Add the offices, departments or units that can be selected when creating a PPMP.</p></div></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <div class="form-grid">
      <div class="field"><label>Area/Unit Name</label><input class="input" name="name" required placeholder="e.g. Medical Service"></div>
      <div class="field"><label>Code <small>(optional)</small></label><input class="input" name="code" placeholder="e.g. MED"></div>
    </div>
    <div class="actions"><button class="btn" type="submit">+ Add Area/Unit</button></div>
  </form>
</div>
<div class="panel" style="margin-top:18px">
  <h2>Area/Unit List</h2>
  <div class="table-wrap"><table class="table"><tr><th>Area/Unit</th><th>Code</th><th>Created</th></tr>
  <?php foreach($rows as $r): ?><tr><td><?=e($r['name'])?></td><td><?=e($r['code']??'')?></td><td><?=e($r['created_at'])?></td></tr><?php endforeach; ?>
  </table></div>
</div>
<?php pageEnd();