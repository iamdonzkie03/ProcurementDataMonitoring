<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer']); require_once __DIR__.'/../app/layout.php'; $pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    requireRole(['Administrator','Editor']); checkCsrf();
    try{
        $pdo->beginTransaction();
        $action=$_POST['action']??'create';

        if($action==='update'){
            $prId=(int)$_POST['pr_id'];
            $st=$pdo->prepare('SELECT * FROM purchase_requests WHERE id=? AND status="Draft"');
            $st->execute([$prId]); $pr=$st->fetch();
            if(!$pr) throw new RuntimeException('Only Draft Purchase Requests can be edited.');

            $year=(int)$_POST['fiscal_year'];
            $area=(int)$_POST['area_id'];
            $items=$_POST['items']??[];
            if(!$items) throw new RuntimeException('Select at least one PPMP item.');

            $up=$pdo->prepare('UPDATE purchase_requests SET fiscal_year=?,area_id=?,purpose=?,requested_by=?,status=? WHERE id=? AND status="Draft"');
            $up->execute([$year,$area,trim($_POST['purpose']),trim($_POST['requested_by']),$_POST['status']??'Draft',$prId]);

            $old=$pdo->prepare('SELECT ppmp_item_id,quantity FROM purchase_request_items WHERE pr_id=?');
            $old->execute([$prId]); $oldItems=[];
            foreach($old as $oi){$oldItems[(int)$oi['ppmp_item_id']=(float)$oi['quantity'];}

            $pdo->prepare('DELETE FROM purchase_request_items WHERE pr_id=?')->execute([$prId]);

            $ins=$pdo->prepare('INSERT INTO purchase_request_items(pr_id,ppmp_item_id,quantity,unit_price) VALUES(?,?,?,?)');
            foreach($items as $ppmpId=>$qty){
                $qty=(float)$qty; if($qty<=0) continue;
                $x=$pdo->prepare('SELECT p.*,COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled" AND r.id<>?),0) used FROM ppmp_items p WHERE p.id=? AND p.fiscal_year=?');
                $x->execute([$prId,(int)$ppmpId,$year]); $p=$x->fetch();
                if(!$p) throw new RuntimeException('Invalid PPMP item selected.');
                $remaining=(float)$p['quantity']-(float)$p['used'];
                if($qty>$remaining+0.000001) throw new RuntimeException('Requested quantity exceeds remaining PPMP quantity for '.$p['item_name'].'.');
                $ins->execute([$prId,(int)$ppmpId,$qty,(float)$p['unit_price']]);
            }

            if((int)$pdo->query('SELECT COUNT(*) FROM purchase_request_items WHERE pr_id='.$prId)->fetchColumn()<1) throw new RuntimeException('Enter a quantity greater than zero.');
            $pdo->commit(); flash('success','Purchase Request '.$pr['pr_no'].' was updated.');
            header('Location:pr.php'); exit;
        }

        $year=(int)$_POST['fiscal_year']; $area=(int)$_POST['area_id']; $items=$_POST['items']??[];
        if(!$items) throw new RuntimeException('Select at least one PPMP item.');
        $prNo='PR-'.$year.'-'.date('mdHis').'-'.random_int(10,99);
        $st=$pdo->prepare('INSERT INTO purchase_requests(pr_no,fiscal_year,area_id,purpose,status,requested_by,created_by) VALUES(?,?,?,?,?,?,?)');
        $st->execute([$prNo,$year,$area,trim($_POST['purpose']),$_POST['status']??'Draft',trim($_POST['requested_by']),currentUser()['id']]);
        $prId=(int)$pdo->lastInsertId();
        foreach($items as $ppmpId=>$qty){
            $qty=(float)$qty; if($qty<=0) continue;
            $x=$pdo->prepare('SELECT p.*,COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled"),0) used FROM ppmp_items p WHERE p.id=? AND p.fiscal_year=?');
            $x->execute([(int)$ppmpId,$year]); $p=$x->fetch();
            if(!$p) throw new RuntimeException('Invalid PPMP item selected.');
            $remaining=(float)$p['quantity']-(float)$p['used'];
            if($qty>$remaining+0.000001) throw new RuntimeException('Requested quantity exceeds remaining PPMP quantity for '.$p['item_name'].'.');
            $ins=$pdo->prepare('INSERT INTO purchase_request_items(pr_id,ppmp_item_id,quantity,unit_price) VALUES(?,?,?,?)');
            $ins->execute([$prId,(int)$ppmpId,$qty,(float)$p['unit_price']]);
        }
        if((int)$pdo->query('SELECT COUNT(*) FROM purchase_request_items WHERE pr_id='.$prId)->fetchColumn()<1) throw new RuntimeException('Enter a quantity greater than zero.');
        $pdo->commit(); flash('success','Purchase Request '.$prNo.' created.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    header('Location:pr.php'); exit;
}

$editId=(int)($_GET['edit']??0); $editPr=null; $editItems=[];
if($editId>0){
    requireRole(['Administrator','Editor']);
    $st=$pdo->prepare('SELECT * FROM purchase_requests WHERE id=? AND status="Draft"'); $st->execute([$editId]); $editPr=$st->fetch();
    if(!$editPr){flash('error','Only Draft Purchase Requests can be edited.');header('Location:pr.php');exit;}
    $st=$pdo->prepare('SELECT ppmp_item_id,quantity FROM purchase_request_items WHERE pr_id=?');$st->execute([$editId]);
    foreach($st as $i)$editItems[(int)$i['ppmp_item_id']=(float)$i['quantity'];
}

$year=(int)($_GET['year']??($editPr['fiscal_year']??date('Y')));$q=trim($_GET['q']??'');
$areas=$pdo->query('SELECT * FROM areas ORDER BY name')->fetchAll();
$items=$pdo->prepare('SELECT p.*,a.name area,c.name category,p.quantity-COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled" AND r.id<>?),0) remaining FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=? ORDER BY a.name,p.item_name');
$items->execute([$editId,$year]);$ppmp=$items->fetchAll();
$prs=$pdo->prepare('SELECT r.*,a.name area,(SELECT COUNT(*) FROM purchase_request_items i WHERE i.pr_id=r.id) AS line_count,(SELECT COALESCE(SUM(i.quantity*i.unit_price),0) FROM purchase_request_items i WHERE i.pr_id=r.id) abc FROM purchase_requests r JOIN areas a ON a.id=r.area_id WHERE r.fiscal_year=? AND (r.pr_no LIKE ? OR a.name LIKE ?) ORDER BY r.created_at DESC');
$prs->execute([$year,"%$q%","%$q%"]);$rows=$prs->fetchAll();pageStart('Purchase Requests');
?>
<div class="panel"><div class="toolbar"><form><input class="input" name="q" placeholder="Search PR number or area" value="<?=e($q)?>"><input type="hidden" name="year" value="<?=$year?>"></form><?php if(hasRole(['Administrator','Editor'])):?><button class="btn" onclick="document.getElementById('prForm').scrollIntoView();return false">+ Create Purchase Request</button><?php endif;?></div>
<div class="table-wrap"><table class="table"><tr><th>PR No.</th><th>Area/Unit</th><th>Purpose</th><th>Lines</th><th>ABC</th><th>Status</th><th>Date</th><th>Action</th></tr>
<?php foreach($rows as $r):?><tr><td><b><?=e($r['pr_no'])?></b></td><td><?=e($r['area'])?></td><td><?=e($r['purpose'])?></td><td><?=$r['line_count']?></td><td>₱<?=number_format($r['abc'],2)?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?=date('M d, Y',strtotime($r['created_at']))?></td><td><?php if($r['status']==='Draft' && hasRole(['Administrator','Editor'])):?><a class="btn" href="pr.php?edit=<?=$r['id']?>">Edit Draft</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="8" class="empty">No purchase requests found.</td></tr><?php endif;?></table></div></div>

<?php if(hasRole(['Administrator','Editor'])):?><div class="panel" id="prForm" style="margin-top:18px"><h2><?=$editPr?'Edit Draft Purchase Request '.$editPr['pr_no']:'Create Purchase Request from PPMP'?></h2><p class="hint-text">Only Draft Purchase Requests can be reopened and edited. Available quantity is automatically recalculated.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$editPr?'update':'create'?>"><?php if($editPr):?><input type="hidden" name="pr_id" value="<?=$editPr['id']?>"><?php endif;?>
<div class="form-grid"><div class="field"><label>Fiscal Year</label><input class="input" type="number" name="fiscal_year" value="<?=e((string)$year)?>" required></div><div class="field"><label>Area/Unit</label><select class="select" name="area_id" required><option value="">Select</option><?php foreach($areas as $a):?><option value="<?=$a['id']?>" <?=((int)($editPr['area_id']??0)===(int)$a['id']?'selected':'')?>><?=e($a['name'])?></option><?php endforeach;?></select></div><div class="field full"><label>Purpose</label><textarea class="input" name="purpose" rows="2" required><?=e($editPr['purpose']??'')?></textarea></div><div class="field"><label>Requested By</label><input class="input" name="requested_by" value="<?=e($editPr['requested_by']??'')?>"></div><div class="field"><label>Status</label><select class="select" name="status"><option <?=($editPr['status']??'Draft')==='Draft'?'selected':''?>>Draft</option><option <?=($editPr['status']??'Draft')==='Submitted'?'selected':''?>>Submitted</option></select></div></div>
<div class="table-wrap" style="margin-top:16px"><table class="table"><tr><th>Select</th><th>PPMP Item</th><th>Area</th><th>Remaining</th><th>Unit</th><th>Unit Price</th><th>PR Qty</th></tr><?php foreach($ppmp as $p):$selected=array_key_exists((int)$p['id'],$editItems);$max=(float)$p['remaining']+($selected?(float)$editItems[(int)$p['id']]:0);?><tr><td><input type="checkbox" <?= $selected?'checked':''?> onclick="this.closest('tr').querySelector('[name^=items]').disabled=!this.checked"></td><td><?=e($p['item_name'])?><br><small><?=e($p['category'])?></small></td><td><?=e($p['area'])?></td><td><?=number_format($max,2)?></td><td><?=e($p['unit'])?></td><td>₱<?=number_format($p['unit_price'],2)?></td><td><input class="input" style="min-width:100px" type="number" step="0.0001" min="0" max="<?=$max?>" name="items[<?=$p['id']?>]" value="<?=e((string)($editItems[(int)$p['id']]??0))?>" <?= $selected?'':'disabled'?>></td></tr><?php endforeach;?></table></div><div class="actions"><button class="btn"><?=$editPr?'Save Draft / Submit PR':'Create Purchase Request'?></button><?php if($editPr):?><a class="btn" href="pr.php">Cancel</a><?php endif;?></div></form></div><?php endif;?><?php pageEnd();