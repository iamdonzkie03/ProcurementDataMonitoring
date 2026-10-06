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
        }elseif($action==='save_bulk'){
            $names=(array)($_POST['names']??[]);
            $validNames=[];
            foreach($names as $rawName){
                $name=trim((string)$rawName);
                if($name==='') continue;
                if(mb_strlen($name,'UTF-8')>100) throw new RuntimeException('Unit of measurement must not exceed 100 characters.');
                $validNames[]=$name;
            }
            if(!$validNames) throw new RuntimeException('Enter at least one unit of measurement.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('INSERT INTO units_of_measure(name) VALUES(?)');
            $added=0;
            foreach($validNames as $name){
                $check=$pdo->prepare('SELECT COUNT(*) FROM units_of_measure WHERE LOWER(TRIM(name))=LOWER(TRIM(?))');
                $check->execute([$name]);
                if((int)$check->fetchColumn()>0) throw new RuntimeException('Duplicate Unit of Measurement: '.$name);
                $st->execute([$name]);
                $added++;
            }
            $pdo->commit();
            flash('success',$added.' Unit of Measurement(s) added.');
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
<div style="margin-bottom:18px;padding:14px 16px;border:1px solid #dbe3ea;border-radius:6px;background:#f8fafc">
<div style="font-weight:600;margin-bottom:5px">Add Multiple Units of Measurement</div>
<div class="muted" style="margin-bottom:10px">Enter one or more Units of Measurement, then click <strong>Save Units</strong>.</div>
<form method="post" id="uomBulkForm">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<input type="hidden" name="action" value="save_bulk">
<div id="uomRows">
<div class="uom-entry-row" style="display:flex;gap:8px;align-items:end;margin-bottom:8px">
<div class="field" style="flex:1;margin:0"><label>Unit Name *</label><input class="input" name="names[]" maxlength="100" placeholder="e.g. Unit, Piece, Lot, Vial" required></div>
<button class="btn secondary uom-remove" type="button" style="display:none">Remove</button>
</div>
</div>
<div style="display:flex;gap:8px;align-items:center;margin-top:8px">
<button class="btn secondary" type="button" id="addUomRow">Add Row</button>
<button class="btn" type="submit" style="background:#198754;color:#fff;border-color:#198754">Save Units</button>
</div>
</form>
</div>
<form method="post" class="form-grid" style="margin-bottom:18px">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?=e((string)($editing['id']??0))?>">
<div class="field"><label>Unit Name</label><input class="input" name="name" value="<?=e($editing['name']??'')?>" placeholder="e.g. Unit, Piece, Lot, Vial" required></div>
<div class="field" style="display:flex;align-items:end;gap:8px"><button class="btn"><?= $editing ? 'Save Changes' : '+ Add Unit' ?></button><?php if($editing): ?><a class="btn secondary" href="<?=e($embedded?'settings.php?tab=uom':'units.php')?>">Cancel</a><?php endif; ?></div>
</form>
<div class="table-wrap"><table class="table"><tr><th>Unit</th><th>Status</th><th>Action</th></tr><?php foreach($rows as $r):?><tr><td><b><?=e($r['name'])?></b></td><td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="status-toggle <?=$r['status']==='Active'?'status-active':'status-inactive'?>" type="submit" title="Click to change status"><?=e($r['status'])?></button></form></td><td>
<a class="btn secondary master-action" href="<?=e(($embedded?'settings.php?tab=uom':'units.php').'?edit='.(int)$r['id'])?>">Edit</a>
<form method="post" class="master-action-form" onsubmit="return confirm('Delete this Unit of Measurement?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn danger master-action" type="submit">Delete</button></form>
</td></tr><?php endforeach;?></table></div>
</div>
<style>
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
.status-toggle{border:0!important;color:#fff!important;border-radius:999px;padding:5px 12px;font:inherit;font-weight:700;cursor:pointer;transition:none!important;box-shadow:none!important;transform:none!important}
.status-toggle.status-active{background:#198754!important}
.status-toggle.status-inactive{background:#dc3545!important}
.status-toggle:hover,.status-toggle:focus,.status-toggle:active{color:#fff!important;box-shadow:none!important;transform:none!important;outline:none!important}
</style>
<?php if(!$embedded) pageEnd();