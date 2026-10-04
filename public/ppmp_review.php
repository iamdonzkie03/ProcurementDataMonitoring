<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS ppmp_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiscal_year YEAR NOT NULL,
    area_id INT UNSIGNED NOT NULL,
    ppmp_no VARCHAR(80) NOT NULL,
    status ENUM('Draft','Pending for Review','Pending for Approval','Approved','Declined') NOT NULL DEFAULT 'Draft',
    submitted_by INT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    supervisor_reviewed_by INT UNSIGNED NULL,
    supervisor_reviewed_at DATETIME NULL,
    budget_reviewed_by INT UNSIGNED NULL,
    budget_reviewed_at DATETIME NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ppmp_review_area FOREIGN KEY(area_id) REFERENCES areas(id),
    CONSTRAINT fk_ppmp_review_submitter FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ppmp_review_supervisor FOREIGN KEY(supervisor_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ppmp_review_budget FOREIGN KEY(budget_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_ppmp_review (fiscal_year, area_id, ppmp_no),
    INDEX idx_ppmp_review_status (status),
    INDEX idx_ppmp_review_area (area_id)
  ) ENGINE=InnoDB");
}catch(PDOException $e){}

$user=currentUser();
$userName=trim((string)($user['full_name']??''));

function isSupervisorForArea(PDO $pdo,int $areaId,string $userName): bool{
  if(!currentUserIsPpmpSupervisor()) return false;
  $contextDivision=currentLoginDivisionId();
  $st=$pdo->prepare("SELECT COUNT(*) FROM areas a JOIN divisions d ON d.id=a.division_id
    WHERE a.id=? AND d.id=? AND d.ppmp_supervisor_enabled=1 AND d.division_head=?");
  $st->execute([$areaId,$contextDivision,$userName]);
  return (int)$st->fetchColumn()>0;
}

function isBudgetOfficerForArea(PDO $pdo,int $areaId,string $userName): bool{
  $st=$pdo->prepare("SELECT COUNT(*) FROM area_personnel ap JOIN areas a ON a.id=ap.area_id
    WHERE ap.area_id=? AND ap.name=?
      AND LOWER(COALESCE(ap.position_designation,'')) LIKE '%budget officer%'
      AND LOWER(a.name) LIKE '%budget%'");
  $st->execute([$areaId,$userName]);
  return (int)$st->fetchColumn()>0;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $action=$_POST['action']??'';
  $reviewId=(int)($_POST['review_id']??0);
  $st=$pdo->prepare("SELECT * FROM ppmp_reviews WHERE id=? LIMIT 1");
  $st->execute([$reviewId]); $review=$st->fetch();
  if(!$review){flash('error','PPMP review record not found.');header('Location:ppmp_review.php');exit;}

  if($action==='submit_for_approval'){
    if(!isSupervisorForArea($pdo,(int)$review['area_id'],$userName)){
      http_response_code(403);exit('403 - You are not the assigned Supervisor/Authorized Person for this Area/Unit.');
    }
    if($review['status']!=='Pending for Review'){
      flash('error','This PPMP is no longer pending for Supervisor review.');
    }else{
      $pdo->prepare("UPDATE ppmp_reviews SET status='Pending for Approval',supervisor_reviewed_by=?,supervisor_reviewed_at=NOW() WHERE id=?")->execute([(int)$user['id'],$reviewId]);
      flash('success','PPMP submitted to the Budget Officer for approval.');
    }
    header('Location:ppmp_review.php?review_id='.$reviewId);exit;
  }

  if($action==='approve' || $action==='decline'){
    if(!isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName)){
      http_response_code(403);exit('403 - You are not the assigned Budget Officer for this Budget Area/Unit.');
    }
    if($review['status']!=='Pending for Approval'){
      flash('error','This PPMP is no longer pending for Budget Officer approval.');
    }else{
      $remarks=trim($_POST['remarks']??'');
      if($action==='decline' && $remarks===''){
        flash('error','Please provide the reason for declining the PPMP.');
      }else{
        $status=$action==='approve'?'Approved':'Declined';
        $pdo->prepare("UPDATE ppmp_reviews SET status=?,budget_reviewed_by=?,budget_reviewed_at=NOW(),remarks=? WHERE id=?")->execute([$status,(int)$user['id'],$remarks,$reviewId]);
        flash('success',$action==='approve'?'PPMP approved.':'PPMP declined and returned to the PPMP user.');
      }
    }
    header('Location:ppmp_review.php?review_id='.$reviewId);exit;
  }
}

$supervisorQueue=[];
$budgetQueue=[];
$stQueue=$pdo->prepare("SELECT r.*,a.name area,d.name division,u.full_name submitted_by_name,
  COUNT(p.id) item_count,COALESCE(SUM(p.quantity*p.unit_price),0) total_abc
  FROM ppmp_reviews r
  JOIN areas a ON a.id=r.area_id
  JOIN divisions d ON d.id=a.division_id
  LEFT JOIN users u ON u.id=r.submitted_by
  LEFT JOIN ppmp_items p ON p.fiscal_year=r.fiscal_year AND p.area_id=r.area_id AND p.ppmp_no=r.ppmp_no
  WHERE r.status IN ('Pending for Review','Pending for Approval')
    AND (d.id=? OR ?=1)
  GROUP BY r.id ORDER BY r.updated_at DESC");
$stQueue->execute([currentLoginDivisionId(),hasRole(['Administrator'])?1:0]);
$stQueue=$stQueue->fetchAll();
foreach($stQueue as $item){
  $area=(int)$item['area_id'];
  if($item['status']==='Pending for Review' && isSupervisorForArea($pdo,$area,$userName))$supervisorQueue[]=$item;
  if($item['status']==='Pending for Approval' && isBudgetOfficerForArea($pdo,$area,$userName))$budgetQueue[]=$item;
}

$reviewId=(int)($_GET['review_id']??0);
$review=null;$items=[];
if($reviewId>0){
  $st=$pdo->prepare("SELECT r.*,a.name area,d.name division,u.full_name submitted_by_name
    FROM ppmp_reviews r JOIN areas a ON a.id=r.area_id JOIN divisions d ON d.id=a.division_id
    LEFT JOIN users u ON u.id=r.submitted_by WHERE r.id=? LIMIT 1");
  $st->execute([$reviewId]);$review=$st->fetch();
  if($review){
    $allowed=isSupervisorForArea($pdo,(int)$review['area_id'],$userName) || isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName) || hasRole(['Administrator']);
    if(!$allowed){http_response_code(403);exit('403 - This PPMP is not assigned to you for review.');}
    $st=$pdo->prepare("SELECT p.*,c.name category FROM ppmp_items p JOIN categories c ON c.id=p.category_id WHERE p.fiscal_year=? AND p.area_id=? AND p.ppmp_no=? ORDER BY p.id");
    $st->execute([(int)$review['fiscal_year'],(int)$review['area_id'],$review['ppmp_no']]);$items=$st->fetchAll();
  }
}

pageStart('PPMP Review');
?>
<style>
.ppmp-review-status{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}
.ppmp-review-status.pending-review{background:#fff3cd;color:#856404}.ppmp-review-status.pending-approval{background:#cff4fc;color:#055160}
.ppmp-review-status.approved{background:#d1e7dd;color:#0f5132}.ppmp-review-status.declined{background:#f8d7da;color:#842029}
.ppmp-review-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.ppmp-review-summary .card{padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
@media(max-width:900px){.ppmp-review-summary{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.ppmp-review-summary{grid-template-columns:1fr}}
.ppmp-review-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}
.ppmp-approve{background:#d9f7df!important;color:#166534!important;border:1px solid #b7e4c0!important}
.ppmp-decline{background:#f8d7da!important;color:#842029!important;border:1px solid #f1b0b7!important}
</style>

<div class="grid">
  <div class="panel">
    <h2>PPMP Review Queue</h2>
    <p class="muted">PPMP lists assigned to your Supervisor/Authorized Person or Budget Officer role.</p>

    <?php if($supervisorQueue): ?>
      <h3>Pending for Review</h3>
      <div class="table-wrap"><table class="table">
        <tr><th>PPMP No.</th><th>Fiscal Year</th><th>Area/Unit</th><th>Items</th><th>Total ABC</th><th>Submitted By</th><th>Status</th><th>Action</th></tr>
        <?php foreach($supervisorQueue as $r): ?><tr>
          <td><?=e($r['ppmp_no'])?></td><td><?=e((string)$r['fiscal_year'])?></td><td><?=e($r['area'])?></td><td><?=e((string)$r['item_count'])?></td>
          <td>₱<?=number_format((float)$r['total_abc'],2)?></td><td><?=e($r['submitted_by_name']??'')?></td>
          <td><span class="ppmp-review-status pending-review">Pending for Review</span></td>
          <td><a class="btn secondary" href="ppmp_review.php?review_id=<?=$r['id']?>">View PPMP</a></td>
        </tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <?php if($budgetQueue): ?>
      <h3>Pending for Approval</h3>
      <div class="table-wrap"><table class="table">
        <tr><th>PPMP No.</th><th>Fiscal Year</th><th>Area/Unit</th><th>Items</th><th>Total ABC</th><th>Submitted By</th><th>Status</th><th>Action</th></tr>
        <?php foreach($budgetQueue as $r): ?><tr>
          <td><?=e($r['ppmp_no'])?></td><td><?=e((string)$r['fiscal_year'])?></td><td><?=e($r['area'])?></td><td><?=e((string)$r['item_count'])?></td>
          <td>₱<?=number_format((float)$r['total_abc'],2)?></td><td><?=e($r['submitted_by_name']??'')?></td>
          <td><span class="ppmp-review-status pending-approval">Pending for Approval</span></td>
          <td><a class="btn secondary" href="ppmp_review.php?review_id=<?=$r['id']?>">View PPMP</a></td>
        </tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <?php if(!$supervisorQueue && !$budgetQueue): ?>
      <div class="empty">No PPMP is currently waiting for your review or approval.</div>
    <?php endif; ?>
  </div>

  <?php if($review): ?>
  <div class="panel">
    <h2>PPMP <?=e($review['ppmp_no'])?></h2>
    <div class="ppmp-review-summary">
      <div class="card"><small>Fiscal Year</small><br><b><?=e((string)$review['fiscal_year'])?></b></div>
      <div class="card"><small>Division</small><br><b><?=e($review['division'])?></b></div>
      <div class="card"><small>Area/Unit</small><br><b><?=e($review['area'])?></b></div>
      <div class="card"><small>Status</small><br><b><?=e($review['status'])?></b></div>
    </div>
    <p><b>Submitted by:</b> <?=e($review['submitted_by_name']??'')?></p>
    <div class="table-wrap"><table class="table">
      <tr><th>Item</th><th>Category</th><th>Description</th><th>Qty / Unit</th><th>Unit Cost</th><th>Total Budget</th><th>Mode</th></tr>
      <?php $grand=0; foreach($items as $r): $line=(float)$r['quantity']*(float)$r['unit_price'];$grand+=$line; ?>
      <tr><td><b><?=e($r['item_name'])?></b></td><td><?=e($r['category'])?></td><td><?=e($r['description'])?></td><td><?=number_format((float)$r['quantity'],2).' '.e($r['unit'])?></td><td>₱<?=number_format((float)$r['unit_price'],2)?></td><td>₱<?=number_format($line,2)?></td><td><?=e($r['procurement_mode'])?></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="5" style="text-align:right"><b>GRAND TOTAL</b></td><td><b>₱<?=number_format($grand,2)?></b></td><td></td></tr>
    </table></div>

    <?php if($review['status']==='Pending for Review' && isSupervisorForArea($pdo,(int)$review['area_id'],$userName)): ?>
      <div class="ppmp-review-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="submit_for_approval"><input type="hidden" name="review_id" value="<?=$review['id']?>"><button class="btn ppmp-approve" type="submit" onclick="return confirm('Submit this entire PPMP list to the Budget Officer for approval?');">Submit for Approval</button></form>
      </div>
    <?php endif; ?>

    <?php if($review['status']==='Pending for Approval' && isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName)): ?>
      <div class="ppmp-review-actions">
        <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;width:100%;">
          <input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="review_id" value="<?=$review['id']?>">
          <textarea class="input" name="remarks" rows="2" placeholder="Reason for decline (required only when declining)" style="flex:1;min-width:260px;"></textarea>
          <button class="btn ppmp-approve" name="action" value="approve" type="submit" onclick="return confirm('Approve this PPMP?');">Approve</button>
          <button class="btn ppmp-decline" name="action" value="decline" type="submit" onclick="return confirm('Decline this PPMP and return it to the PPMP user?');">Decline</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if($review['status']==='Declined' && !empty($review['remarks'])): ?>
      <div class="alert error"><b>Budget Officer Remarks:</b> <?=nl2br(e($review['remarks']))?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
