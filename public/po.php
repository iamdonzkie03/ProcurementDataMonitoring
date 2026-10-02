<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer']); require_once __DIR__.'/../app/layout.php'; $pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    requireRole(['Administrator','Editor']); checkCsrf();
    try{
        $pdo->beginTransaction();
        $action=$_POST['action']??'create';

        if($action==='update'){
            $poId=(int)$_POST['po_id'];
            $st=$pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND status="Draft"');$st->execute([$poId]);$po=$st->fetch();
            if(!$po) throw new RuntimeException('Only Draft Purchase Orders can be edited.');

            $status=$_POST['status']??'Draft';
            if(!in_array($status,['Draft','Issued'],true))$status='Draft';
            $up=$pdo->prepare('UPDATE purchase_orders SET supplier=?,po_date=?,status=?,remarks=? WHERE id=? AND status="Draft"');
            $up->execute([trim($_POST['supplier']),$_POST['po_date']?:null,$status,trim($_POST['remarks']),$poId]);
            $pdo->commit();flash('success','Purchase Order '.$po['po_no'].' was updated.');
            header('Location:po.php');exit;
        }

        $prId=(int)$_POST['pr_id'];
        $p=$pdo->prepare('SELECT * FROM purchase_requests WHERE id=? AND status IN ("Submitted","Approved")');$p->execute([$prId]);$pr=$p->fetch();
        if(!$pr)throw new RuntimeException('Only submitted or approved Purchase Requests can be converted to a PO.');
        $poNo='PO-'.date('Ymd-His').'-'.random_int(10,99);
        $st=$pdo->prepare('INSERT INTO purchase_orders(po_no,pr_id,supplier,po_date,status,remarks,created_by) VALUES(?,?,?,?,?,?,?)');
        $st->execute([$poNo,$prId,trim($_POST['supplier']),$_POST['po_date']?:null,$_POST['status']??'Draft',trim($_POST['remarks']),currentUser()['id']]);
        $poId=(int)$pdo->lastInsertId();
        $its=$pdo->prepare('SELECT * FROM purchase_request_items WHERE pr_id=?');$its->execute([$prId]);
        foreach($its as $i){$ins=$pdo->prepare('INSERT INTO purchase_order_items(po_id,pr_item_id,quantity,unit_price) VALUES(?,?,?,?)');$ins->execute([$poId,$i['id'],$i['quantity'],$i['unit_price']]);}
        $pdo->commit();flash('success','Purchase Order '.$poNo.' created.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    header('Location:po.php');exit;
}

$editId=(int)($_GET['edit']??0);$editPo=null;
if($editId>0){
    requireRole(['Administrator','Editor']);
    $st=$pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND status="Draft"');$st->execute([$editId]);$editPo=$st->fetch();
    if(!$editPo){flash('error','Only Draft Purchase Orders can be edited.');header('Location:po.php');exit;}
}

$prs=$pdo->query('SELECT r.id,r.pr_no,r.fiscal_year,a.name area,r.status,COALESCE(SUM(i.quantity*i.unit_price),0) abc FROM purchase_requests r JOIN areas a ON a.id=r.area_id LEFT JOIN purchase_request_items i ON i.pr_id=r.id WHERE r.status IN ("Submitted","Approved") GROUP BY r.id ORDER BY r.created_at DESC')->fetchAll();
$pos=$pdo->query('SELECT o.*,r.pr_no,a.name area,COALESCE(SUM(i.quantity*i.unit_price),0) abc FROM purchase_orders o JOIN purchase_requests r ON r.id=o.pr_id JOIN areas a ON a.id=r.area_id LEFT JOIN purchase_order_items i ON i.po_id=o.id GROUP BY o.id ORDER BY o.created_at DESC')->fetchAll();
pageStart('Purchase Orders');
?>
<div class="panel"><div class="toolbar"><?php if(hasRole(['Administrator','Editor'])):?><button class="btn" onclick="document.getElementById('poForm').scrollIntoView();return false">+ Create Purchase Order</button><?php endif;?></div>
<div class="table-wrap"><table class="table"><tr><th>PO No.</th><th>PR No.</th><th>Supplier</th><th>Area</th><th>ABC</th><th>Status</th><th>PO Date</th><th>Action</th></tr>
<?php foreach($pos as $r):?><tr><td><b><?=e($r['po_no'])?></b></td><td><?=e($r['pr_no'])?></td><td><?=e($r['supplier'])?></td><td><?=e($r['area'])?></td><td>₱<?=number_format($r['abc'],2)?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?=e($r['po_date'])?></td><td><?php if($r['status']==='Draft' && hasRole(['Administrator','Editor'])):?><a class="btn" href="po.php?edit=<?=$r['id']?>">Edit Draft</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$pos):?><tr><td colspan="8" class="empty">No purchase orders found.</td></tr><?php endif;?></table></div></div>

<?php if(hasRole(['Administrator','Editor'])):?><div class="panel" id="poForm" style="margin-top:18px"><h2><?=$editPo?'Edit Draft Purchase Order '.$editPo['po_no']:'Create Purchase Order from Purchase Request'?></h2><p class="hint-text">Only Draft Purchase Orders can be reopened and edited. Once Issued, the PO is no longer editable here.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$editPo?'update':'create'?>"><?php if($editPo):?><input type="hidden" name="po_id" value="<?=$editPo['id']?>"><?php endif;?>
<div class="form-grid"><?php if(!$editPo):?><div class="field full"><label>Purchase Request</label><select class="select" name="pr_id" required><option value="">Select submitted/approved PR</option><?php foreach($prs as $r):?><option value="<?=$r['id']?>"><?=e($r['pr_no'].' — '.$r['area'].' — ₱'.number_format($r['abc'],2))?></option><?php endforeach;?></select></div><?php else:?><div class="field full"><label>Purchase Request</label><input class="input" value="<?=e($editPo['pr_id'])?>" disabled></div><?php endif;?><div class="field"><label>Supplier</label><input class="input" name="supplier" value="<?=e($editPo['supplier']??'')?>" required></div><div class="field"><label>PO Date</label><input class="input" type="date" name="po_date" value="<?=e($editPo['po_date']??date('Y-m-d'))?>"></div><div class="field"><label>Status</label><select class="select" name="status"><option <?=($editPo['status']??'Draft')==='Draft'?'selected':''?>>Draft</option><option <?=($editPo['status']??'Draft')==='Issued'?'selected':''?>>Issued</option></select></div><div class="field full"><label>Remarks</label><textarea class="input" name="remarks" rows="2"><?=e($editPo['remarks']??'')?></textarea></div></div><div class="actions"><button class="btn"><?=$editPo?'Save Draft / Issue PO':'Create Purchase Order'?></button><?php if($editPo):?><a class="btn" href="po.php">Cancel</a><?php endif;?></div></form></div><?php endif;?><?php pageEnd();