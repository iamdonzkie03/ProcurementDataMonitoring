<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';

$pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS bac_committee_members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_type ENUM('BAC','TWG') NOT NULL,
  name VARCHAR(180) NOT NULL,
  designation VARCHAR(180) NOT NULL,
  area_expertise VARCHAR(180) NULL,
  area_unit VARCHAR(180) NOT NULL,
  code_tag VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bac_member_type (member_type),
  INDEX idx_bac_member_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$tab=$_GET['tab']??'bac-members';
if(!in_array($tab,['bac-members','technical-working-group'],true)) $tab='bac-members';

if($_SERVER['REQUEST_METHOD']==='POST'){
  requireRole(['Administrator','Editor']);
  checkCsrf();
  $type=($_POST['member_type']??'')==='TWG'?'TWG':'BAC';
  $name=trim((string)($_POST['name']??''));
  $designation=trim((string)($_POST['designation']??''));
  $expertise=trim((string)($_POST['area_expertise']??''));
  $areaUnit=trim((string)($_POST['area_unit']??''));
  $codeTag=trim((string)($_POST['code_tag']??''));
  $returnTab=$type==='TWG'?'technical-working-group':'bac-members';
  if($name===''||$designation===''||$areaUnit===''||$codeTag===''||($type==='TWG'&&$expertise==='')){
    flash('error','Please complete all required member fields.');
  }else{
    $st=$pdo->prepare('INSERT INTO bac_committee_members(member_type,name,designation,area_expertise,area_unit,code_tag) VALUES(?,?,?,?,?,?)');
    $st->execute([$type,$name,$designation,$type==='TWG'?$expertise:null,$areaUnit,$codeTag]);
    flash('success',$type==='TWG'?'Technical Working Group member added.':'BAC member added.');
  }
  header('Location:bac.php?tab='.$returnTab);exit;
}

$type=$tab==='technical-working-group'?'TWG':'BAC';
$st=$pdo->prepare('SELECT id,name,designation,area_expertise,area_unit,code_tag FROM bac_committee_members WHERE member_type=? ORDER BY name,id');
$st->execute([$type]);
$members=$st->fetchAll();
$canManage=hasRole(['Administrator','Editor']);

pageStart('Bids and Awards Committee');
?>
<div class="panel bac-page-panel">
  <div class="bac-page-heading">
    <div class="bac-page-icon" aria-hidden="true">⚖</div>
    <div>
      <h2>Bids and Awards Committee</h2>
      <p>Manage BAC Members and the Technical Working Group.</p>
    </div>
  </div>
  <nav class="bac-tabs" aria-label="Bids and Awards Committee sections">
    <a class="btn <?=$tab==='bac-members'?'':'secondary'?>" href="bac.php?tab=bac-members" <?=$tab==='bac-members'?'aria-current="page"':''?>>BAC Members</a>
    <a class="btn <?=$tab==='technical-working-group'?'':'secondary'?>" href="bac.php?tab=technical-working-group" <?=$tab==='technical-working-group'?'aria-current="page"':''?>>Technical Working Group</a>
  </nav>
  <section class="bac-tab-content">
    <div class="bac-list-toolbar">
      <h3><?=$tab==='bac-members'?'BAC Members':'Technical Working Group'?></h3>
      <?php if($canManage): ?><button class="btn" type="button" id="showAddMember">+ Add Member</button><?php endif; ?>
    </div>
    <?php if($canManage): ?>
    <form method="post" id="addMemberForm" class="bac-member-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="<?=e($type)?>">
      <div class="bac-member-fields">
        <div class="field"><label for="memberName">Name*</label><input class="input" id="memberName" name="name" maxlength="180" required></div>
        <div class="field"><label for="memberDesignation"><?=$type==='BAC'?'BAC Designation':'TWG Designation'?>*</label><input class="input" id="memberDesignation" name="designation" maxlength="180" required></div>
        <?php if($type==='TWG'): ?><div class="field"><label for="memberExpertise">Area of Expertise*</label><input class="input" id="memberExpertise" name="area_expertise" maxlength="180" required></div><?php endif; ?>
        <div class="field"><label for="memberAreaUnit">Area/Unit*</label><input class="input" id="memberAreaUnit" name="area_unit" maxlength="180" required></div>
        <div class="field"><label for="memberCodeTag">Code/Tag*</label><input class="input" id="memberCodeTag" name="code_tag" maxlength="100" required></div>
      </div>
      <div class="bac-form-actions"><button class="btn" type="submit">Save Member</button><button class="btn secondary" type="button" id="cancelAddMember">Cancel</button></div>
    </form>
    <?php endif; ?>
    <div class="table-wrap bac-members-table-wrap">
      <table class="table bac-members-table">
        <thead><tr><th>Name</th><th><?=$type==='BAC'?'BAC Designation':'TWG Designation'?></th><?php if($type==='TWG'): ?><th>Area of Expertise</th><?php endif; ?><th>Area/Unit</th><th>Code/Tag</th></tr></thead>
        <tbody>
        <?php foreach($members as $member): ?>
          <tr><td><?=e($member['name'])?></td><td><?=e($member['designation'])?></td><?php if($type==='TWG'): ?><td><?=e($member['area_expertise']??'')?></td><?php endif; ?><td><?=e($member['area_unit'])?></td><td><?=e($member['code_tag'])?></td></tr>
        <?php endforeach; ?>
        <?php if(!$members): ?><tr><td colspan="<?=$type==='BAC'?4:5?>" class="empty">No members have been added yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<style>
.content:has(.bac-page-panel){max-width:none!important;width:100%;padding:20px 28px 28px!important;min-height:calc(100vh - 170px);display:flex;flex-direction:column}
.bac-page-panel{width:100%;max-width:none;flex:1;min-height:calc(100vh - 220px);margin:0;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px;box-shadow:0 8px 24px rgba(16,42,67,.08);display:flex;flex-direction:column}
.bac-page-heading{display:flex;align-items:center;gap:16px;padding-bottom:20px;border-bottom:1px solid #e5e7eb}
.bac-page-icon{width:54px;height:54px;flex:0 0 54px;display:grid;place-items:center;border-radius:12px;background:#102a43;color:#fff;font-size:27px}
.bac-page-heading h2{margin:0 0 5px;color:#102a43;font-size:22px}
.bac-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:22px;padding-bottom:14px;border-bottom:1px solid #e5e7eb}
.bac-tabs .btn{font-size:13px;text-decoration:none}
.bac-tabs .btn.secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
.bac-tab-content{flex:1;min-height:180px;padding-top:20px}
.bac-tab-content h3{margin:0;color:#102a43;font-size:17px}
.bac-list-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
.bac-list-toolbar .btn,.bac-form-actions .btn{white-space:nowrap}
.bac-member-form{padding:18px;margin-bottom:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px}
.bac-member-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.bac-member-fields .field{margin:0}
.bac-member-fields label{display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:#374151}
.bac-member-fields .input{width:100%;box-sizing:border-box}
.bac-form-actions{display:flex;gap:8px;margin-top:16px}
.bac-members-table-wrap{width:100%;overflow-x:auto}
.bac-members-table{width:100%;border-collapse:collapse}
.bac-members-table th,.bac-members-table td{padding:11px 12px;text-align:left;border-bottom:1px solid #e5e7eb}
.bac-members-table th{background:#f8fafc;color:#334155;font-size:13px;white-space:nowrap}
.bac-members-table td{font-size:13px}
.bac-page-heading p{margin:0;color:#6b7280;font-size:13px;line-height:1.6}
@media(max-width:700px){.content:has(.bac-page-panel){padding:14px!important;min-height:calc(100vh - 150px)}.bac-page-panel{min-height:calc(100vh - 190px);padding:17px}.bac-page-heading h2{font-size:18px}.bac-page-icon{width:44px;height:44px;flex-basis:44px}.bac-member-fields{grid-template-columns:1fr}.bac-list-toolbar{align-items:flex-start;flex-wrap:wrap}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const form=document.getElementById('addMemberForm');
  const show=document.getElementById('showAddMember');
  const cancel=document.getElementById('cancelAddMember');
  if(show&&form)show.addEventListener('click',function(){form.hidden=false;show.hidden=true;const first=form.querySelector('input[name="name"]');if(first)first.focus();});
  if(cancel&&form&&show)cancel.addEventListener('click',function(){form.reset();form.hidden=true;show.hidden=false;});
});
</script>
<?php pageEnd(); ?>
