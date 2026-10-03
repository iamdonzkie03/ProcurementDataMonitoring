<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']); require_once __DIR__.'/../app/layout.php'; $embedded=!empty($embedded); $pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    checkCsrf();
    try{
        $action=$_POST['action']??'add';
        if($action==='toggle'){
            $id=(int)$_POST['id'];
            $st=$pdo->prepare('UPDATE units_of_measure SET status=IF(status="Active","Inactive","Active") WHERE id=?');
            $st->execute([$id]);
            flash('success','Unit status updated.');
        }elseif($action==='delete'){
            $id=(int)$_POST['id'];
            if($id<=0) throw new RuntimeException('Invalid unit of measurement.');
            $st=$pdo->prepare('DELETE FROM units_of_measure WHERE id=?');
            $st->execute([$id]);
            flash('success','Unit of measurement deleted.');
        }else{
            $id=(int)($_POST['id']??0);
            $name=trim($_POST['name']??'');
            if($name==='') throw new RuntimeException('Enter a unit of measurement.');
            if($id>0){
                $st=$pdo->prepare('UPDATE units_of_measure SET name=? WHERE id=?');
                $st->execute([$name,$id]);
                flash('success','Unit of measurement updated.');
            }else{
                $st=$pdo->prepare('INSERT INTO units_of_measure(name) VALUES(?)');
                $st->execute([$name]);
                flash('success','Unit of measurement added.');
            }
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    header('Location:'.($embedded ? 'settings.php?tab=uom' : 'units.php'));exit;
}
$editing=null;
if(isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT id,name,status FROM units_of_measure WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editing=$st->fetch() ?: null;
}
$rows=$pdo->query('SELECT * FROM units_of_measure ORDER BY status DESC,name')->fetchAll();
if(!$embedded) pageStart('Units of Measurement');
?>
<div class="panel">
<div class="toolbar"><div><h2>Units of Measurement</h2><p class="hint-text">Manage the units available in Purchase Request item dropdowns.</p></div></div>
<form method="post" class="form-grid" style="margin-bottom:18px">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?=e((string)($editing['id']??0))?>">
<div class="field"><label>Unit Name</label><input class="input" name="name" value="<?=e($editing['name']??'')?>" placeholder="e.g. Unit, Piece, Lot, Vial" required></div>
<div class="field" style="display:flex;align-items:end;gap:8px"><button class="btn"><?= $editing ? 'Save Changes' : '+ Add Unit' ?></button><?php if($editing): ?><a class="btn secondary" href="<?=e($embedded?'settings.php?tab=uom':'units.php')?>">Cancel</a><?php endif; ?></div>
</form>
<div class="table-wrap"><table class="table"><tr><th>Unit</th><th>Status</th><th>Action</th></tr><?php foreach($rows as $r):?><tr><td><b><?=e($r['name'])?></b></td><td><span class="badge"><?=e($r['status'])?></span></td><td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn secondary"><?= $r['status']==='Active'?'Deactivate':'Activate'?></button></form>
<a class="btn secondary" href="<?=e(($embedded?'settings.php?tab=uom':'units.php').'?edit='.(int)$r['id'])?>">Edit</a>
<form method="post" style="display:inline" onsubmit="return confirm('Delete this Unit of Measurement?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn danger" type="submit">Delete</button></form>
</td></tr><?php endforeach;?></table></div>
</div>
<?php if(!$embedded) pageEnd();