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

            
            $pdo->prepare('DELETE FROM purchase_request_items WHERE pr_id=?')->execute([$prId]);

            $ins=$pdo->prepare('INSERT INTO purchase_request_items(pr_id,ppmp_item_id,quantity,unit_price,unit_id) VALUES(?,?,?,?,?)');
            foreach($items as $ppmpId=>$qty){
                $qty=(float)$qty; if($qty<=0) continue;
                $x=$pdo->prepare('SELECT p.*,COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled" AND r.id<>?),0) used FROM ppmp_items p WHERE p.id=? AND p.fiscal_year=?');
                $x->execute([$prId,(int)$ppmpId,$year]); $p=$x->fetch();
                if(!$p) throw new RuntimeException('Invalid PPMP item selected.');
                $remaining=(float)$p['quantity']-(float)$p['used'];
                if($qty>$remaining+0.000001) throw new RuntimeException('Requested quantity exceeds remaining PPMP quantity for '.$p['item_name'].'.');
                $unitId=(int)($_POST['units'][(int)$ppmpId]??0);if(!$unitId)throw new RuntimeException('Select a unit of measurement for '.$p['item_name'].'.');$ins->execute([$prId,(int)$ppmpId,$qty,(float)$p['unit_price'],$unitId]);
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
            $unitId=(int)($_POST['units'][(int)$ppmpId]??0);if(!$unitId)throw new RuntimeException('Select a unit of measurement for '.$p['item_name'].'.');$ins=$pdo->prepare('INSERT INTO purchase_request_items(pr_id,ppmp_item_id,quantity,unit_price,unit_id) VALUES(?,?,?,?,?)');
            $ins->execute([$prId,(int)$ppmpId,$qty,(float)$p['unit_price'],$unitId]);
        }
        if((int)$pdo->query('SELECT COUNT(*) FROM purchase_request_items WHERE pr_id='.$prId)->fetchColumn()<1) throw new RuntimeException('Enter a quantity greater than zero.');
        $pdo->commit(); flash('success','Purchase Request '.$prNo.' created.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    header('Location:pr.php'); exit;
}

$editId=(int)($_GET['edit']??0); $editPr=null; $editItems=[];$editUnits=[];
if($editId>0){
    requireRole(['Administrator','Editor']);
    $st=$pdo->prepare('SELECT * FROM purchase_requests WHERE id=? AND status="Draft"'); $st->execute([$editId]); $editPr=$st->fetch();
    if(!$editPr){flash('error','Only Draft Purchase Requests can be edited.');header('Location:pr.php');exit;}
    $st=$pdo->prepare('SELECT ppmp_item_id,quantity,unit_id FROM purchase_request_items WHERE pr_id=?');$st->execute([$editId]);
    foreach($st as $i){$editItems[(int)$i['ppmp_item_id']] = (float)$i['quantity'];$editUnits[(int)$i['ppmp_item_id']] = (int)($i['unit_id']??0);}
}

$year=(int)($_GET['year']??($editPr['fiscal_year']??date('Y')));$q=trim($_GET['q']??'');
$areas=$pdo->query('SELECT * FROM areas ORDER BY name')->fetchAll();$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();
$items=$pdo->prepare('SELECT p.*,a.name area,c.name category,p.quantity-COALESCE((SELECT SUM(i.quantity) FROM purchase_request_items i JOIN purchase_requests r ON r.id=i.pr_id WHERE i.ppmp_item_id=p.id AND r.status<>"Cancelled" AND r.id<>?),0) remaining FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=? ORDER BY a.name,p.item_name');
$items->execute([$editId,$year]);$ppmp=$items->fetchAll();
$prs=$pdo->prepare('SELECT r.*,a.name area,(SELECT COUNT(*) FROM purchase_request_items i WHERE i.pr_id=r.id) AS line_count,(SELECT COALESCE(SUM(i.quantity*i.unit_price),0) FROM purchase_request_items i WHERE i.pr_id=r.id) abc FROM purchase_requests r JOIN areas a ON a.id=r.area_id WHERE r.fiscal_year=? AND (r.pr_no LIKE ? OR a.name LIKE ?) ORDER BY r.created_at DESC');
$prs->execute([$year,"%$q%","%$q%"]);$allRows=$prs->fetchAll();
$statuses=['Pending','Received','Returned','Cancelled','Completed'];
$activeStatus=$_GET['status']??'Pending';
if(!in_array($activeStatus,$statuses,true))$activeStatus='Pending';
$rows=array_values(array_filter($allRows,function($r)use($activeStatus){
  $status=strtolower(trim((string)$r['status']));
  if($activeStatus==='Pending')return in_array($status,['pending','draft','submitted','for review','for approval'],true);
  return $status===strtolower($activeStatus);
}));
pageStart('Purchase Requests');
?>
<div class="panel pr-list-panel">
  <div class="pr-list-header">
    <div><h2>Purchase Requests</h2><p class="hint-text">View and manage Purchase Requests by status.</p></div>
    <form class="pr-search-form" method="get"><input class="input" name="q" placeholder="Search PR number or area" value="<?=e($q)?>"><input type="hidden" name="year" value="<?=$year?>"><input type="hidden" name="status" value="<?=e($activeStatus)?>"></form>
  </div>
  <nav class="pr-status-tabs" aria-label="Purchase Request status tabs">
    <?php foreach($statuses as $status):$count=count(array_filter($allRows,function($r)use($status){$v=strtolower(trim((string)$r['status']));return $status==='Pending'?in_array($v,['pending','draft','submitted','for review','for approval'],true):$v===strtolower($status);}));?>
      <a class="pr-status-tab <?=$activeStatus===$status?'active':''?>" href="pr.php?<?=http_build_query(['year'=>$year,'q'=>$q,'status'=>$status])?>"><?=e($status)?><span><?=$count?></span></a>
    <?php endforeach;?>
  </nav>
  <div class="table-wrap"><table class="table"><thead><tr><th>PR No.</th><th>Area/Unit</th><th>Purpose</th><th>Lines</th><th>ABC</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>
  <?php foreach($rows as $r):?><tr><td><b><?=e($r['pr_no'])?></b></td><td><?=e($r['area'])?></td><td><?=e($r['purpose'])?></td><td><?=$r['line_count']?></td><td>₱<?=number_format($r['abc'],2)?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?=date('M d, Y',strtotime($r['created_at']))?></td><td><?php if($r['status']==='Draft' && hasRole(['Administrator','Editor'])):?><a class="btn" href="pr.php?edit=<?=$r['id']?>">Edit Draft</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="8" class="empty">No purchase requests in this tab.</td></tr><?php endif;?>
  </tbody></table></div>
  <?php if(hasRole(['Administrator','Editor'])): ?><div class="pr-create-actions"><button class="btn" type="button" id="showPrForm">Create PR</button></div><?php endif;?>
</div>

<?php if(hasRole(['Administrator','Editor'])):?><div class="panel" id="prForm" style="margin-top:18px" <?=$editPr?'':'hidden'?>><h2><?=$editPr?'Edit Draft Purchase Request '.$editPr['pr_no']:'Create Purchase Request from PPMP'?></h2><p class="hint-text">Only Draft Purchase Requests can be reopened and edited. Available quantity is automatically recalculated.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$editPr?'update':'create'?>"><?php if($editPr):?><input type="hidden" name="pr_id" value="<?=$editPr['id']?>"><?php endif;?>
<div class="form-grid"><div class="field"><label>Fiscal Year</label><input class="input" type="number" name="fiscal_year" value="<?=e((string)$year)?>" required></div><div class="field"><label>Area/Unit</label><select class="select" name="area_id" required><option value="">Select</option><?php foreach($areas as $a):?><option value="<?=$a['id']?>" <?=((int)($editPr['area_id']??0)===(int)$a['id']?'selected':'')?>><?=e($a['name'])?></option><?php endforeach;?></select></div><div class="field full"><label>Purpose</label><textarea class="input" name="purpose" rows="2" required><?=e($editPr['purpose']??'')?></textarea></div><div class="field"><label>Requested By</label><input class="input" name="requested_by" value="<?=e($editPr['requested_by']??'')?>"></div><div class="field"><label>Status</label><select class="select" name="status"><option <?=($editPr['status']??'Draft')==='Draft'?'selected':''?>>Draft</option><option <?=($editPr['status']??'Draft')==='Submitted'?'selected':''?>>Submitted</option></select></div></div>
<div class="table-wrap" style="margin-top:16px"><table class="table"><tr><th>Select</th><th>PPMP Item</th><th>Area</th><th>Remaining</th><th>Unit</th><th>Unit Price</th><th>PR Qty</th></tr><?php foreach($ppmp as $p):$selected=array_key_exists((int)$p['id'],$editItems);$max=(float)$p['remaining']+($selected?(float)$editItems[(int)$p['id']]:0);?><tr><td><input type="checkbox" <?= $selected?'checked':''?> onclick="this.closest('tr').querySelectorAll('[name^=items],[name^=units]').forEach(function(x){x.disabled=!this.checked}.bind(this))"></td><td><?=e($p['item_name'])?><br><small><?=e($p['category'])?></small></td><td><?=e($p['area'])?></td><td><?=number_format($max,2)?></td><td><select class="select" name="units[<?=$p['id']?>]" required <?= $selected?'':'disabled'?>><option value="">Select unit</option><?php foreach($units as $u):?><option value="<?=$u['id']?>" <?=((int)($editUnits[(int)$p['id']]??0)===(int)$u['id']||(!$editPr && strcasecmp($p['unit'],$u['name'])===0)?'selected':'')?>><?=e($u['name'])?></option><?php endforeach;?></select></td><td>₱<?=number_format($p['unit_price'],2)?></td><td><input class="input" style="min-width:100px" type="number" step="0.0001" min="0" max="<?=$max?>" name="items[<?=$p['id']?>]" value="<?=e((string)($editItems[(int)$p['id']]??0))?>" <?= $selected?'':'disabled'?>></td></tr><?php endforeach;?></table></div><div class="actions"><button class="btn"><?=$editPr?'Save Draft / Submit PR':'Create Purchase Request'?></button><?php if($editPr):?><a class="btn" href="pr.php">Cancel</a><?php endif;?></div></form></div><?php endif;?><?php pageEnd(); ?>
<style>
.pr-list-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.pr-list-header h2{margin:0}.pr-list-header .hint-text{margin:5px 0 0}
.pr-search-form{width:250px;max-width:100%;margin:0}
.pr-status-tabs{display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid #dce5ef;margin-bottom:16px}
.pr-status-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 15px;margin-bottom:-1px;border:1px solid transparent;border-bottom:3px solid transparent;border-radius:8px 8px 0 0;text-decoration:none;color:#475569;font-weight:700}
.pr-status-tab span{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;padding:0 6px;border-radius:999px;background:#eef2f7;color:#475569;font-size:12px}
.pr-status-tab.active{color:#164e80;background:#edf6ff;border-color:#d9e9f8;border-bottom-color:#0d6efd}
.pr-status-tab.active span{background:#0d6efd;color:#fff}
.pr-create-actions{display:flex;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid #edf1f5}
#prForm[hidden]{display:none!important}
@media(max-width:650px){.pr-list-header{align-items:stretch;flex-direction:column}.pr-search-form{width:100%}.pr-status-tabs{gap:2px}.pr-status-tab{padding:9px 8px;font-size:13px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const button=document.getElementById('showPrForm');
  const form=document.getElementById('prForm');
  if(!button||!form)return;
  button.addEventListener('click',function(){
    form.hidden=false;
    form.scrollIntoView({behavior:'smooth',block:'start'});
  });
});
</script>
