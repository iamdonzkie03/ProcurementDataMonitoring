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
  $pdo->exec("CREATE TABLE IF NOT EXISTS ppmp_review_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    ppmp_item_id BIGINT UNSIGNED NOT NULL,
    status ENUM('Pending for Review','Approved','Declined','Pending for Approval','Budget Approved','Budget Declined') NOT NULL DEFAULT 'Pending for Review',
    supervisor_remarks TEXT NULL,
    supervisor_reviewed_by INT UNSIGNED NULL,
    supervisor_reviewed_at DATETIME NULL,
    budget_remarks TEXT NULL,
    budget_reviewed_by INT UNSIGNED NULL,
    budget_reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pri_review FOREIGN KEY(review_id) REFERENCES ppmp_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_pri_item FOREIGN KEY(ppmp_item_id) REFERENCES ppmp_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_pri_supervisor FOREIGN KEY(supervisor_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pri_budget FOREIGN KEY(budget_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_pri_review_item (review_id, ppmp_item_id),
    INDEX idx_pri_review_status (status)
  ) ENGINE=InnoDB");
}catch(PDOException $e){}

$user=currentUser();
$userName=trim((string)($user['full_name']??''));

// For PPMP supervision, the Division Head assignment is authoritative.
// Resolve the supervisor's division directly from divisions.division_head,
// rather than relying on users.division_id being populated correctly.
$supervisorDivisionId=0;
if($userName!==''){
  $stSupervisorDivision=$pdo->prepare("SELECT id FROM divisions
    WHERE ppmp_supervisor_enabled=1
      AND LOWER(TRIM(division_head))=LOWER(TRIM(?))
    ORDER BY id LIMIT 1");
  $stSupervisorDivision->execute([$userName]);
  $supervisorDivisionId=(int)$stSupervisorDivision->fetchColumn();
}
$ppmpSupervisor=($supervisorDivisionId>0);

function isSupervisorForArea(PDO $pdo,int $areaId,string $userName): bool{
  global $supervisorDivisionId;
  if($supervisorDivisionId<=0 || $userName==='') return false;
  // A Division/Department Head supervises every Area/Unit belonging to the
  // Division for which that person is configured as Division Head.
  $st=$pdo->prepare("SELECT COUNT(*) FROM areas a
    WHERE a.id=? AND a.division_id=?");
  $st->execute([$areaId,$supervisorDivisionId]);
  return (int)$st->fetchColumn()>0;
}
function isBudgetOfficerForArea(PDO $pdo,int $areaId,string $userName): bool{
  // Identify the Budget Officer by the person's name listed in Area/Unit
  // under the Budget Office. The position/designation text is not required.
  // The assigned Budget Officer may approve PPMPs from every Area/Unit.
  if($userName==='') return false;
  $st=$pdo->prepare("SELECT COUNT(*) FROM area_personnel ap JOIN areas a ON a.id=ap.area_id
    WHERE LOWER(TRIM(ap.name))=LOWER(TRIM(?))
      AND LOWER(a.name) LIKE '%budget%'");
  $st->execute([$userName]);
  return (int)$st->fetchColumn()>0;
}
function refreshReviewStatus(PDO $pdo,int $reviewId): void{
  $st=$pdo->prepare("SELECT
    SUM(status='Pending for Review') pending_count,
    SUM(status='Approved') approved_count,
    SUM(status='Pending for Approval') pfa_count,
    SUM(status='Budget Approved') budget_approved_count,
    SUM(status='Declined') declined_count,
    COUNT(*) total_count
    FROM ppmp_review_items WHERE review_id=?");
  $st->execute([$reviewId]);$x=$st->fetch()?:[];
  $status='Declined';
  if((int)($x['pending_count']??0)>0) $status='Pending for Review';
  elseif((int)($x['pfa_count']??0)>0) $status='Pending for Approval';
  elseif((int)($x['approved_count']??0)>0 || (int)($x['budget_approved_count']??0)>0) $status='Approved';
  elseif((int)($x['declined_count']??0)>0) $status='Declined';
  $pdo->prepare('UPDATE ppmp_reviews SET status=? WHERE id=?')->execute([$status,$reviewId]);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $action=$_POST['action']??'';
  $reviewId=(int)($_POST['review_id']??0);
  $st=$pdo->prepare("SELECT * FROM ppmp_reviews WHERE id=? LIMIT 1");$st->execute([$reviewId]);$review=$st->fetch();
  if(!$review){flash('error','PPMP review record not found.');header('Location:ppmp_review.php');exit;}
  $areaId=(int)$review['area_id'];

  if($action==='review_items'){
    if(!isSupervisorForArea($pdo,$areaId,$userName)){http_response_code(403);exit('403 - You are not the assigned Supervisor/Authorized Person for this Area/Unit.');}
    // Decisions are item-level: keep reviewing any remaining Pending for Review
    // items even when other items in this PPMP have advanced to later statuses.
    $pendingCheck=$pdo->prepare("SELECT COUNT(*) FROM ppmp_review_items WHERE review_id=? AND status='Pending for Review'");
    $pendingCheck->execute([$reviewId]);
    if((int)$pendingCheck->fetchColumn()===0){flash('error','There are no PPMP items pending for Supervisor review.');header('Location:ppmp_review.php?review_id='.$reviewId);exit;}
    $statuses=$_POST['item_status']??[];$remarks=$_POST['item_remarks']??[];
    if(!is_array($statuses))$statuses=[];
    $pdo->beginTransaction();
    try{
      $get=$pdo->prepare('SELECT id,status FROM ppmp_review_items WHERE id=? AND review_id=? LIMIT 1');
      $up=$pdo->prepare("UPDATE ppmp_review_items SET status=?,supervisor_remarks=?,supervisor_reviewed_by=?,supervisor_reviewed_at=NOW() WHERE id=? AND review_id=?");
      $approvedAny=false;
      foreach($statuses as $itemReviewId=>$status){
        $itemReviewId=(int)$itemReviewId;
        if(!in_array($status,['Approved','Declined'],true))continue;
        $get->execute([$itemReviewId,$reviewId]);$row=$get->fetch();
        if(!$row || $row['status']!=='Pending for Review')continue;
        $remark=trim((string)($remarks[$itemReviewId]??''));
        if($status==='Declined' && $remark===''){throw new RuntimeException('A decline reason is required for every item marked Declined.');}
        $nextStatus=$status==='Approved'?'Pending for Approval':'Declined';
        $up->execute([$nextStatus,$status==='Declined'?$remark:null,(int)$user['id'],$itemReviewId,$reviewId]);
        if($status==='Approved')$approvedAny=true;
      }
      if($approvedAny){
        $pdo->prepare("UPDATE ppmp_reviews SET supervisor_reviewed_by=?,supervisor_reviewed_at=NOW() WHERE id=?")
          ->execute([(int)$user['id'],$reviewId]);
      }
      $pdo->commit();refreshReviewStatus($pdo,$reviewId);flash('success','PPMP item review decisions have been saved.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    header('Location:ppmp_review.php?review_id='.$reviewId);exit;
  }

  if($action==='submit_for_approval'){
    if(!isSupervisorForArea($pdo,$areaId,$userName)){http_response_code(403);exit('403 - You are not the assigned Supervisor/Authorized Person for this Area/Unit.');}
    $counts=$pdo->prepare("SELECT SUM(status='Pending for Review') pending_count,SUM(status='Approved') approved_count,SUM(status='Declined') declined_count,COUNT(*) total_count FROM ppmp_review_items WHERE review_id=?");
    $counts->execute([$reviewId]);$x=$counts->fetch()?:[];
    if((int)($x['pending_count']??0)>0){flash('error','Review every PPMP item before submitting for approval.');}
    elseif((int)($x['approved_count']??0)<=0){flash('error','At least one PPMP item must be approved before submission for approval.');}
    else{
      $pdo->beginTransaction();
      try{
        $pdo->prepare("UPDATE ppmp_review_items SET status='Pending for Approval' WHERE review_id=? AND status='Approved'")->execute([$reviewId]);
        $pdo->prepare("UPDATE ppmp_reviews SET status='Pending for Approval',supervisor_reviewed_by=?,supervisor_reviewed_at=NOW() WHERE id=?")->execute([(int)$user['id'],$reviewId]);
        $pdo->commit();flash('success','Approved PPMP items have been submitted to the Budget Officer for approval.');
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','Unable to submit the approved PPMP items: '.$e->getMessage());}
    }
    header('Location:ppmp_review.php?review_id='.$reviewId);exit;
  }

  if($action==='budget_decision'){
    if(!isBudgetOfficerForArea($pdo,$areaId,$userName)){http_response_code(403);exit('403 - You are not the assigned Budget Officer for this Area/Unit.');}
    $itemStatuses=$_POST['budget_status']??[];$remarks=$_POST['budget_remarks']??[];
    if(!is_array($itemStatuses))$itemStatuses=[];
    $pdo->beginTransaction();
    try{
      $up=$pdo->prepare("UPDATE ppmp_review_items SET status=?,budget_remarks=?,budget_reviewed_by=?,budget_reviewed_at=NOW() WHERE id=? AND review_id=? AND status='Pending for Approval'");
      foreach($itemStatuses as $itemReviewId=>$status){
        if(!in_array($status,['Approved','Declined','Budget Approved','Budget Declined'],true))continue;
        $remark=trim((string)($remarks[$itemReviewId]??''));
        $declined=in_array($status,['Declined','Budget Declined'],true);
        if($declined && $remark==='')throw new RuntimeException('A decline reason is required for every item declined by the Budget Officer.');
        $finalStatus=$declined?'Declined':'Approved';
        $up->execute([$finalStatus,$declined?$remark:null,(int)$user['id'],(int)$itemReviewId,$reviewId]);
      }
      if($up->rowCount()>0){$pdo->prepare('UPDATE ppmp_reviews SET budget_reviewed_by=?,budget_reviewed_at=NOW() WHERE id=?')->execute([(int)$user['id'],$reviewId]);}
      $pdo->commit();refreshReviewStatus($pdo,$reviewId);flash('success','Budget Officer item decisions have been saved.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    header('Location:ppmp_review.php?review_id='.$reviewId);exit;
  }
}

$supervisorQueue=[];$budgetQueue=[];
$stQueue=$pdo->prepare("SELECT r.*,a.name area,d.name division,u.full_name submitted_by_name,
  COUNT(DISTINCT p.id) item_count,
  COUNT(DISTINCT CASE WHEN pri_pending.status='Pending for Review' THEN pri_pending.id END) pending_review_count,
  COUNT(DISTINCT CASE WHEN pri_pending.status='Approved' THEN pri_pending.id END) approved_count,
  COUNT(DISTINCT CASE WHEN pri_pending.status='Pending for Approval' THEN pri_pending.id END) pending_approval_count,
  COALESCE(SUM(CASE WHEN p.total_budget IS NULL OR p.total_budget=0 THEN p.quantity*p.unit_price ELSE p.total_budget END),0) total_abc
  FROM ppmp_reviews r
  JOIN areas a ON a.id=r.area_id
  JOIN divisions d ON d.id=a.division_id
  LEFT JOIN users u ON u.id=r.submitted_by
  LEFT JOIN ppmp_items p ON p.fiscal_year=r.fiscal_year AND p.area_id=r.area_id AND p.ppmp_no=r.ppmp_no
  LEFT JOIN ppmp_review_items pri_pending ON pri_pending.review_id=r.id AND pri_pending.ppmp_item_id=p.id
  WHERE d.id=?
    AND EXISTS (
      SELECT 1 FROM ppmp_review_items pri
      WHERE pri.review_id=r.id AND pri.status='Pending for Review'
    )
  GROUP BY r.id ORDER BY r.updated_at DESC");
$stQueue->execute([$supervisorDivisionId]);
foreach($stQueue->fetchAll() as $item){
  // Division Heads see every PPMP in their division that still has at least
  // one item pending supervisor review, regardless of the parent review status.
  if($ppmpSupervisor && (int)($item['pending_review_count']??0)>0 && isSupervisorForArea($pdo,(int)$item['area_id'],$userName)){
    $supervisorQueue[]=$item;
  }
}

// List any PPMP containing items waiting for the Budget Officer, even when
// other items in the same PPMP still await Supervisor review.
if(isBudgetOfficerForArea($pdo,0,$userName) || hasRole(['Administrator'])){
  $stBudgetQueue=$pdo->query("SELECT r.*,a.name area,d.name division,u.full_name submitted_by_name,
    COUNT(DISTINCT p.id) item_count
    FROM ppmp_reviews r
    JOIN areas a ON a.id=r.area_id
    JOIN divisions d ON d.id=a.division_id
    LEFT JOIN users u ON u.id=r.submitted_by
    LEFT JOIN ppmp_items p ON p.fiscal_year=r.fiscal_year AND p.area_id=r.area_id AND p.ppmp_no=r.ppmp_no
    WHERE EXISTS (SELECT 1 FROM ppmp_review_items pri WHERE pri.review_id=r.id AND pri.status='Pending for Approval')
    GROUP BY r.id ORDER BY r.updated_at DESC");
  $budgetQueue=$stBudgetQueue->fetchAll();
}

$reviewId=(int)($_GET['review_id']??0);$review=null;$items=[];
if($reviewId>0){
  $st=$pdo->prepare("SELECT r.*,a.name area,d.name division,u.full_name submitted_by_name FROM ppmp_reviews r
    JOIN areas a ON a.id=r.area_id JOIN divisions d ON d.id=a.division_id LEFT JOIN users u ON u.id=r.submitted_by WHERE r.id=? LIMIT 1");
  $st->execute([$reviewId]);$review=$st->fetch();
  if($review){
    $allowed=isSupervisorForArea($pdo,(int)$review['area_id'],$userName)||isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName)||hasRole(['Administrator']);
    if(!$allowed){http_response_code(403);exit('403 - This PPMP is not assigned to you for review.');}
    // Ensure the review snapshot contains every actual PPMP item for this PPMP.
    // Older review records may have missing ppmp_review_items rows, so synchronize them
    // from ppmp_items before loading the review screen.
    $stItems=$pdo->prepare('SELECT id FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no=? ORDER BY id');
    $stItems->execute([(int)$review['fiscal_year'],(int)$review['area_id'],(string)$review['ppmp_no']]);
    $insMissing=$pdo->prepare("INSERT IGNORE INTO ppmp_review_items(review_id,ppmp_item_id,status) VALUES(?,?, 'Pending for Review')");
    foreach($stItems->fetchAll(PDO::FETCH_COLUMN) as $ppmpItemId){
      $insMissing->execute([$reviewId,(int)$ppmpItemId]);
    }

    $st=$pdo->prepare("SELECT pri.*,p.item_name,p.description,p.quantity,p.unit,p.unit_price,p.procurement_mode,p.total_budget,c.name category
      FROM ppmp_review_items pri
      JOIN ppmp_items p ON p.id=pri.ppmp_item_id
      LEFT JOIN categories c ON c.id=p.category_id
      WHERE pri.review_id=? AND p.fiscal_year=? AND p.area_id=? AND p.ppmp_no=?
      ORDER BY p.id");
    $st->execute([$reviewId,(int)$review['fiscal_year'],(int)$review['area_id'],(string)$review['ppmp_no']]);$items=$st->fetchAll();
  }
}
$isBudgetOfficerPage=isBudgetOfficerForArea($pdo,0,$userName);
$pageTitle=$isBudgetOfficerPage?'PPMP for Approval':'PPMP for Review';
pageStart($pageTitle);
?>
<style>
.ppmp-review-status{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.pending{background:#fff3cd;color:#856404}.pfa{background:#cff4fc;color:#055160}.approved{background:#d1e7dd;color:#0f5132}.declined{background:#f8d7da;color:#842029}
.review-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:18px}.review-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.decision-select{min-width:140px}.decline-remark{display:none;margin-top:6px;min-width:220px}.item-review-row.is-declined .decline-remark{display:block}.item-review-row td{vertical-align:top}
.area-card{padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;margin-bottom:10px}.area-card a{text-decoration:none}.status-counts{display:flex;gap:5px;flex-wrap:wrap;min-width:230px}.status-counts .ppmp-review-status{white-space:nowrap;font-size:11px;padding:4px 7px}
</style>
<div class="review-grid">
<div class="panel">
<h2><?=e($pageTitle)?></h2>
<p class="muted"><?= $isBudgetOfficerPage ? 'Review PPMP items awaiting Budget Officer approval and record an Approved or Declined decision.' : 'Select an Area/Unit to review its submitted PPMP items independently.' ?></p>
<?php if($supervisorQueue):?>
<h3>Areas/Units Pending Supervisor Review</h3>
<div class="table-wrap"><table class="table"><tr><th>Division</th><th>Area/Unit</th><th>PPMP No.</th><th>Fiscal Year</th><th>Items</th><th>Submitted By</th><th>Status</th><th>Action</th></tr>
<?php foreach($supervisorQueue as $r):?><tr><td><?=e($r['division'])?></td><td><?=e($r['area'])?></td><td><?=e($r['ppmp_no'])?></td><td><?=e((string)$r['fiscal_year'])?></td><td><?=e((string)$r['item_count'])?></td><td><?=e($r['submitted_by_name']??'')?></td><td><div class="status-counts"><span class="ppmp-review-status approved">Approved: <?= (int)($r['approved_count']??0) ?></span><span class="ppmp-review-status pending">Pending for Review: <?= (int)($r['pending_review_count']??0) ?></span><span class="ppmp-review-status pfa">Pending for Approval: <?= (int)($r['pending_approval_count']??0) ?></span></div></td><td><a class="btn secondary" href="ppmp_review.php?review_id=<?=$r['id']?>">Review PPMP</a></td></tr><?php endforeach;?>
</table></div>
<?php endif;?>
<?php if($budgetQueue):?>
<h3>Areas/Units Pending Budget Approval</h3>
<div class="table-wrap"><table class="table"><tr><th>Division</th><th>Area/Unit</th><th>PPMP No.</th><th>Fiscal Year</th><th>Items</th><th>Submitted By</th><th>Status</th><th>Action</th></tr>
<?php foreach($budgetQueue as $r):?><tr><td><?=e($r['division'])?></td><td><?=e($r['area'])?></td><td><?=e($r['ppmp_no'])?></td><td><?=e((string)$r['fiscal_year'])?></td><td><?=e((string)$r['item_count'])?></td><td><?=e($r['submitted_by_name']??'')?></td><td><span class="ppmp-review-status pfa">Pending for Approval</span></td><td><a class="btn secondary" href="ppmp_review.php?review_id=<?=$r['id']?>">Review Pending Items</a></td></tr><?php endforeach;?>
</table></div>
<?php endif;?>
<?php if(!$supervisorQueue&&!$budgetQueue):?><div class="empty">No PPMP requests are currently waiting for your action.</div><?php endif;?>
</div>
<?php if($review):?>
<div class="panel">
<h2><?=e($review['area'])?> — PPMP <?=e($review['ppmp_no'])?></h2>
<p><b>Division:</b> <?=e($review['division'])?> &nbsp; <b>Fiscal Year:</b> <?=e((string)$review['fiscal_year'])?> &nbsp; <b>Submitted By:</b> <?=e($review['submitted_by_name']??'')?></p>
<?php $itemStatusCounts=['Approved'=>0,'Pending for Review'=>0,'Pending for Approval'=>0]; foreach($items as $countItem){if(isset($itemStatusCounts[$countItem['status']]))$itemStatusCounts[$countItem['status']]++;} ?>
<div class="status-counts" style="margin:10px 0 14px"><span class="ppmp-review-status approved">Approved: <?=$itemStatusCounts['Approved']?></span><span class="ppmp-review-status pending">Pending for Review: <?=$itemStatusCounts['Pending for Review']?></span><span class="ppmp-review-status pfa">Pending for Approval: <?=$itemStatusCounts['Pending for Approval']?></span></div>
<form method="post" id="supervisorReviewForm">
<input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName)?'budget_decision':'review_items'?>"><input type="hidden" name="review_id" value="<?=$reviewId?>">
<div class="review-actions"><button type="button" class="btn secondary" id="approveSelected">Approve Selected</button><button type="button" class="btn secondary" id="declineSelected">Decline Selected</button><span class="muted" id="selectedCount">0 selected</span></div>
<div class="table-wrap"><table class="table">
<tr><th><input type="checkbox" id="selectAllItems" aria-label="Select all items"></th><th>PPMP Item</th><th>Category</th><th>Qty / Unit</th><th>Unit Cost</th><th>Total</th><th>Review Decision</th></tr>
<?php foreach($items as $r):?>
<tr class="item-review-row" data-item="<?=$r['id']?>">
<td><input type="checkbox" class="item-check" aria-label="Select PPMP item" <?=$r['status']!=='Pending for Review'?'disabled':''?>></td>
<td><b><?=e($r['item_name'])?></b><br><small><?=e($r['description'])?></small></td><td><?=e($r['category'])?></td><td><?=number_format((float)$r['quantity'],2).' '.e($r['unit'])?></td><td>₱<?=number_format((float)$r['unit_price'],2)?></td><td>₱<?=number_format((float)($r['total_budget']??((float)$r['quantity']*(float)$r['unit_price'])),2)?></td>
<td>
<?php if(isSupervisorForArea($pdo,(int)$review['area_id'],$userName) && $r['status']==='Pending for Review'):?>
<select class="select decision-select" name="item_status[<?=$r['id']?>]"><option value="">Select</option><option value="Approved">Approved</option><option value="Declined">Declined</option></select>
<textarea class="input decline-remark" name="item_remarks[<?=$r['id']?>]" rows="2" placeholder="Reason for decline"><?=e($r['supervisor_remarks']??'')?></textarea>
<?php elseif(isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName) && $r['status']==='Pending for Approval'):?>
<select class="select decision-select" name="budget_status[<?=$r['id']?>]"><option value="">Select</option><option value="Approved">Approved</option><option value="Declined">Declined</option></select>
<textarea class="input decline-remark" name="budget_remarks[<?=$r['id']?>]" rows="2" placeholder="Reason for decline"></textarea>
<?php else:?>
<span class="ppmp-review-status <?=in_array($r['status'],['Approved','Budget Approved'],true)?'approved':($r['status']==='Declined'||$r['status']==='Budget Declined'?'declined':'pending')?>"><?=e($r['status'])?></span>
<?php if(!empty($r['supervisor_remarks'])):?><br><small><b>Supervisor:</b> <?=nl2br(e($r['supervisor_remarks']))?></small><?php endif;?>
<?php if(!empty($r['budget_remarks'])):?><br><small><b>Budget:</b> <?=nl2br(e($r['budget_remarks']))?></small><?php endif;?>
<?php endif;?>
</td></tr>
<?php endforeach;?>
</table></div>
<?php if(isSupervisorForArea($pdo,(int)$review['area_id'],$userName) && array_filter($items,fn($it)=>$it['status']==='Pending for Review')):?>
<div class="review-actions"><button class="btn" type="submit">Save Selected Decisions</button></div>
</form>
<?php $approved=0;$pending=0;foreach($items as $it){if($it['status']==='Approved')$approved++;if($it['status']==='Pending for Review')$pending++;}?>
<?php if($approved>0 && $pending===0):?><form method="post" class="review-actions"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="submit_for_approval"><input type="hidden" name="review_id" value="<?=$reviewId?>"><button class="btn" type="submit" onclick="return confirm('Submit all approved items to the Budget Officer?');">Submit Approved Items for Approval</button></form><?php endif;?>
<?php elseif(isBudgetOfficerForArea($pdo,(int)$review['area_id'],$userName) && array_filter($items,fn($it)=>$it['status']==='Pending for Approval')):?>
<div class="review-actions"><button class="btn" type="submit">Save Budget Decisions</button></div>
</form>
<?php else:?></form><?php endif;?>
</div>
<?php endif;?>
</div>
<script>
(function(){
 const rows=Array.from(document.querySelectorAll('.item-review-row'));
 const selectAll=document.getElementById('selectAllItems'),count=document.getElementById('selectedCount');
 const approve=document.getElementById('approveSelected'),decline=document.getElementById('declineSelected');
 function syncRow(row){
   const select=row.querySelector('.decision-select'),remark=row.querySelector('.decline-remark');if(!select||!remark)return;
   const declined=select.value==='Declined'||select.value==='Budget Declined';
   row.classList.toggle('is-declined',declined);remark.required=declined;
 }
 function syncCount(){
   const selected=rows.filter(r=>r.querySelector('.item-check')?.checked).length;
   if(count)count.textContent=selected+' selected';
   if(selectAll)selectAll.checked=rows.length>0&&selected===rows.length;
 }
 rows.forEach(function(row){
   const select=row.querySelector('.decision-select'),check=row.querySelector('.item-check');
   if(select){select.addEventListener('change',function(){syncRow(row);});syncRow(row);}
   if(check)check.addEventListener('change',syncCount);
 });
 if(selectAll)selectAll.addEventListener('change',function(){rows.forEach(r=>{const c=r.querySelector('.item-check');if(c)c.checked=selectAll.checked;});syncCount();});
 function applySelected(decision){
   const selected=rows.filter(r=>r.querySelector('.item-check')?.checked);
   if(!selected.length){alert('Select at least one PPMP item first.');return;}
   selected.forEach(function(row){const s=row.querySelector('.decision-select');if(s){const opt=Array.from(s.options).find(o=>o.value===decision || (decision==='Approved'&&o.value==='Budget Approved') || (decision==='Declined'&&o.value==='Budget Declined'));if(opt){s.value=opt.value;s.dispatchEvent(new Event('change'));}}});
   syncCount();
 }
 if(approve)approve.addEventListener('click',function(){applySelected('Approved');});
 if(decline)decline.addEventListener('click',function(){applySelected('Declined');});
 syncCount();
})();
</script>
<?php pageEnd(); ?>