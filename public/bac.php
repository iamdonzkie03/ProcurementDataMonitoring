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

$tab=$_GET['tab']??'hierarchy';
if(!in_array($tab,['hierarchy','bac-members','technical-working-group'],true)) $tab='hierarchy';

if($_SERVER['REQUEST_METHOD']==='POST'){
  requireRole(['Administrator','Editor']);
  checkCsrf();
  $type=($_POST['member_type']??'')==='TWG'?'TWG':'BAC';
  $returnTab=$type==='TWG'?'technical-working-group':'bac-members';
  $names=(array)($_POST['name']??[]);
  $designations=(array)($_POST['designation']??[]);
  $expertises=(array)($_POST['area_expertise']??[]);
  $areaUnits=(array)($_POST['area_unit']??[]);
  $codeTags=(array)($_POST['code_tag']??[]);
  $count=max(count($names),count($designations),count($expertises),count($areaUnits),count($codeTags));
  $entries=[];
  for($i=0;$i<$count;$i++){
    $name=trim((string)($names[$i]??''));
    $designation=trim((string)($designations[$i]??''));
    $expertise=trim((string)($expertises[$i]??''));
    $areaUnit=trim((string)($areaUnits[$i]??''));
    $codeTag=trim((string)($codeTags[$i]??''));
    if($name===''&&$designation===''&&$expertise===''&&$areaUnit===''&&$codeTag==='') continue;
    if($name===''||$designation===''||$areaUnit===''||$codeTag===''||($type==='TWG'&&$expertise==='')){
      flash('error','Complete every required field in each member row. No members were saved.');
      header('Location:bac.php?tab='.$returnTab);exit;
    }
    $entries[]=[$type,$name,$designation,$type==='TWG'?$expertise:null,$areaUnit,$codeTag];
  }
  if(!$entries){flash('error','Enter at least one member before saving.');}
  else{
    // BAC code tags are compared case-insensitively with repeated spaces normalized.
    // A BAC code/tag can have only one Chairman and one Vice-Chairman, and a person
    // already assigned to one BAC code/tag cannot be assigned to another.
    $normalizeTag=static function($value){return mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$value)));};
    $normalizeName=static function($value){return mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$value)));};
    $validTags=['bac a','bac b','bac c','bac d','bac e'];
    $allowedDesignations=['bac chairman','bac vice-chairman','bac member','ad-hoc chairman','ad-hoc vice-chairman','ad-hoc member'];
    $existingStmt=$pdo->prepare('SELECT name,designation,code_tag FROM bac_committee_members WHERE member_type=\'BAC\'');
    $existingStmt->execute();
    $existing=$existingStmt->fetchAll(PDO::FETCH_ASSOC);
    $all=array_merge($existing,$type==='BAC'?array_map(static function($entry){return ['name'=>$entry[1],'designation'=>$entry[2],'code_tag'=>$entry[5]];},$entries):[]);
    $errors=[];
    $seenNames=[];
    $seenRoles=[];
    foreach($all as $person){
      $tag=$normalizeTag($person['code_tag']??'');
      $name=$normalizeName($person['name']??'');
      $designation=mb_strtolower(trim((string)($person['designation']??'')));
      if($tag===''||$name==='')continue;
      if(!in_array($tag,$validTags,true))$errors[]='Invalid BAC Code/Tag "'.trim((string)$person['code_tag']).'". Choose BAC A, BAC B, BAC C, BAC D, or BAC E.';
      if(!in_array($designation,$allowedDesignations,true))$errors[]='Invalid BAC designation for '.$person['name'].'.';
      if(in_array($designation,['bac chairman','bac vice-chairman','ad-hoc chairman','ad-hoc vice-chairman'],true)){
        $roleKey=$tag.'|'.$designation;
        if(isset($seenRoles[$roleKey]))$errors[]='BAC code/tag "'.trim((string)$person['code_tag']).'" may have only one '.trim((string)$person['designation']).'.';
        else $seenRoles[$roleKey]=true;
      }
      // A named BAC person may belong to only one code/tag, even if repeated in the current batch.
      if(isset($seenNames[$name])&&$seenNames[$name]!==$tag)$errors[]=$person['name'].' is already assigned to a different BAC code/tag and cannot be added again.';
      else $seenNames[$name]=$tag;
    }
    if($errors){
      flash('error',implode(' ',$errors).' No members were saved.');
    }else{
      $st=$pdo->prepare('INSERT INTO bac_committee_members(member_type,name,designation,area_expertise,area_unit,code_tag) VALUES(?,?,?,?,?,?)');
      $pdo->beginTransaction();
      try{foreach($entries as $entry)$st->execute($entry);$pdo->commit();flash('success',count($entries)===1?'Member added.':count($entries).' members added.');}
      catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
  }
  header('Location:bac.php?tab='.$returnTab);exit;
}

$type=$tab==='technical-working-group'?'TWG':'BAC';
$st=$pdo->prepare('SELECT id,name,designation,area_expertise,area_unit,code_tag FROM bac_committee_members WHERE member_type=? ORDER BY name,id');
$st->execute([$type]);
$members=$st->fetchAll();
$canManage=hasRole(['Administrator','Editor']);

// Build the BAC name list from configured Division/Department supervisors and Area/Unit Heads.
$bacEligiblePeople=$pdo->query("SELECT d.division_head AS name,d.name AS area_unit,'Division/Department Supervisor' AS source_role FROM divisions d WHERE TRIM(COALESCE(d.division_head,''))<>'' UNION SELECT ap.name AS name,a.name AS area_unit,'Area/Unit Personnel' AS source_role FROM area_personnel ap JOIN areas a ON a.id=ap.area_id WHERE TRIM(COALESCE(ap.name,''))<>'' ORDER BY name,area_unit")->fetchAll(PDO::FETCH_ASSOC);
$bacPeopleByName=[];
foreach($bacEligiblePeople as $person){
  $personName=trim((string)$person['name']);
  if($personName==='') continue;
  $key=mb_strtolower($personName);
  if(!isset($bacPeopleByName[$key])) $bacPeopleByName[$key]=['name'=>$personName,'area_units'=>[]];
  $unit=trim((string)$person['area_unit']);
  if($unit!==''&&!in_array($unit,$bacPeopleByName[$key]['area_units'],true)) $bacPeopleByName[$key]['area_units'][]=$unit;
}
$bacPeopleOptions=array_values($bacPeopleByName);

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
    <a class="btn <?=$tab==='hierarchy'?'':'secondary'?>" href="bac.php?tab=hierarchy" <?=$tab==='hierarchy'?'aria-current="page"':''?>>Hierarchy</a>
    <a class="btn <?=$tab==='bac-members'?'':'secondary'?>" href="bac.php?tab=bac-members" <?=$tab==='bac-members'?'aria-current="page"':''?>>BAC Members</a>
    <a class="btn <?=$tab==='technical-working-group'?'':'secondary'?>" href="bac.php?tab=technical-working-group" <?=$tab==='technical-working-group'?'aria-current="page"':''?>>Technical Working Group</a>
  </nav>
  <?php if($tab==='hierarchy'): ?>
  <section class="bac-hierarchy-content" aria-label="BAC organizational hierarchy">
    <h3>BAC Organizational Hierarchy</h3>
    <p class="bac-hierarchy-intro">Organizational structure of the Bids and Awards Committee and its Technical Working Group.</p>
    <div class="bac-hierarchy-chart">
      <div class="bac-hierarchy-node bac-hierarchy-top"><span class="bac-hierarchy-role">Head of the Procuring Entity</span><strong>HoPE</strong></div>
      <div class="bac-hierarchy-connector"></div>
      <div class="bac-hierarchy-branch bac-hierarchy-hope-branch">
        <div class="bac-hierarchy-bac-column">
          <div class="bac-hierarchy-node bac-hierarchy-group bac-hierarchy-bac">
            <span class="bac-hierarchy-role">Committee</span><strong>Bids and Awards Committee</strong>
            <div class="bac-hierarchy-subnodes">
              <div class="bac-hierarchy-subnode">BAC Chairperson</div>
              <div class="bac-hierarchy-subnode">BAC Vice-Chairperson</div>
              <div class="bac-hierarchy-subnode">BAC Members</div>
            </div>
          </div>
          <div class="bac-hierarchy-twg-branch">
            <div class="bac-hierarchy-node bac-hierarchy-twg"><span class="bac-hierarchy-role">Technical Support</span><strong>Technical Working Group (TWG)</strong><small>Supports the BAC with technical evaluation and related tasks</small></div>
          </div>
        </div>
        <div class="bac-hierarchy-node bac-hierarchy-group bac-hierarchy-secretariat">
          <span class="bac-hierarchy-role">Secretariat</span><strong>BAC Secretariat</strong>
          <div class="bac-hierarchy-subnodes">
            <div class="bac-hierarchy-subnode">BAC Secretariat Head</div>
            <div class="bac-hierarchy-subnode">Secretariat Members</div>
          </div>
        </div>
      </div>
      <p class="bac-hierarchy-note">Illustrative structure only. Confirm official appointments and applicable procurement rules when assigning positions.</p>
    </div>
  </section>
  <?php else: ?>
  <section class="bac-tab-content">
    <div class="bac-list-toolbar">
      <h3><?=$tab==='bac-members'?'BAC Members':'Technical Working Group'?></h3>
      <?php if($canManage): ?><button class="btn" type="button" id="showAddMember">+ Add Member</button><?php endif; ?>
    </div>
    <?php if($canManage): ?>
    <form method="post" id="addMemberForm" class="bac-member-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="<?=e($type)?>">
      <div class="table-wrap bac-entry-table-wrap"><table class="table bac-entry-table"><thead><tr><th>Name*</th><th><?=$type==='BAC'?'BAC Designation':'TWG Designation'?>*</th><?php if($type==='TWG'): ?><th>Area of Expertise*</th><?php endif; ?><th>Area/Unit*</th><th>Code/Tag*</th><th>Action</th></tr></thead><tbody id="bacEntryRows"><tr class="bac-entry-row"><td><div class="bac-person-autocomplete"><input class="input bac-person-name" type="text" name="name[]" maxlength="180" placeholder="Search name..." autocomplete="off" aria-label="Name" required><div class="bac-person-suggestions" role="listbox"></div></div></td><td><?php if($type==='BAC'): ?><select class="input" name="designation[]" aria-label="BAC Designation" required><option value="">Select BAC Designation</option><option value="BAC Chairman">BAC Chairman</option><option value="BAC Vice-Chairman">BAC Vice-Chairman</option><option value="BAC Member">BAC Member</option><option value="Ad-Hoc Chairman">Ad-Hoc Chairman</option><option value="Ad-Hoc Vice-Chairman">Ad-Hoc Vice-Chairman</option><option value="Ad-Hoc Member">Ad-Hoc Member</option></select><?php else: ?><input class="input" type="text" name="designation[]" maxlength="180" placeholder="TWG Designation" aria-label="TWG Designation" required><?php endif; ?></td><?php if($type==='TWG'): ?><td><input class="input" type="text" name="area_expertise[]" maxlength="180" placeholder="Area of Expertise" aria-label="Area of Expertise" required></td><?php endif; ?><td><input class="input bac-area-unit-input" type="text" name="area_unit[]" maxlength="180" placeholder="Auto-filled from selected name" aria-label="Area/Unit" readonly required></td><td><?php if($type==='BAC'): ?><select class="input" name="code_tag[]" aria-label="BAC Code/Tag" required><option value="">Select BAC Code/Tag</option><?php foreach(['BAC A','BAC B','BAC C','BAC D','BAC E'] as $bacTag): ?><option value="<?=e($bacTag)?>"><?=e($bacTag)?></option><?php endforeach; ?></select><?php else: ?><input class="input" type="text" name="code_tag[]" maxlength="100" placeholder="Code/Tag" aria-label="Code/Tag" required><?php endif; ?></td><td class="bac-entry-action"><button class="btn secondary" type="button" id="addMemberRow">Add Row</button></td></tr></tbody></table></div>
      <div class="bac-form-actions"><button class="btn" type="submit" id="saveMemberItems">Save Member</button><button class="btn secondary" type="button" id="cancelAddMember">Cancel</button></div>
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
  <?php endif; ?>
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
.bac-hierarchy-content{padding-top:22px;max-width:1100px;margin:0 auto;width:100%}
.bac-hierarchy-content h3{margin:0;color:#102a43;font-size:18px;text-align:center}
.bac-hierarchy-intro{text-align:center;color:#6b7280;font-size:13px;margin:8px 0 24px}
.bac-hierarchy-chart{display:flex;flex-direction:column;align-items:center;padding:10px 0 20px}
.bac-hierarchy-node{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 4px 12px rgba(16,42,67,.07);padding:17px 22px;min-width:220px;max-width:100%;box-sizing:border-box}
.bac-hierarchy-node strong{color:#102a43;font-size:15px}
.bac-hierarchy-node small{color:#64748b;font-size:12px;line-height:1.5}
.bac-hierarchy-role{font-size:10px;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:#64748b}
.bac-hierarchy-top{background:#f8fafc;border-color:#94a3b8}
.bac-hierarchy-chair{background:#102a43;border-color:#102a43;min-width:280px}
.bac-hierarchy-chair strong,.bac-hierarchy-chair .bac-hierarchy-role,.bac-hierarchy-chair small{color:#fff}
.bac-hierarchy-connector{width:2px;height:28px;background:#94a3b8}
.bac-hierarchy-branch{position:relative;display:flex;justify-content:center;gap:22px;width:100%;padding-top:20px}
.bac-hierarchy-branch:before{content:"";position:absolute;top:0;left:25%;right:25%;height:2px;background:#94a3b8}
.bac-hierarchy-branch .bac-hierarchy-node:before{content:"";position:absolute;top:-21px;width:2px;height:20px;background:#94a3b8}
.bac-hierarchy-branch .bac-hierarchy-node{position:relative;flex:1;max-width:340px}
.bac-hierarchy-hope-branch:before{left:25%;right:25%}
.bac-hierarchy-hope-branch .bac-hierarchy-node{max-width:440px;align-self:stretch}
.bac-hierarchy-bac-column{position:relative;display:flex;flex-direction:column;align-items:center;flex:1;max-width:440px;min-width:0}
.bac-hierarchy-hope-branch>.bac-hierarchy-bac-column{flex:1}
.bac-hierarchy-bac-column>.bac-hierarchy-bac{width:100%;max-width:440px!important}
.bac-hierarchy-twg-branch{position:relative;display:flex;justify-content:center;width:100%;padding-top:28px;margin-top:0}
.bac-hierarchy-twg-branch:before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:2px;height:28px;background:#94a3b8}
.bac-hierarchy-twg-branch .bac-hierarchy-twg{width:100%;max-width:440px}
.bac-hierarchy-secretariat{align-self:stretch;flex:1}
.bac-hierarchy-group{gap:12px}
.bac-hierarchy-subnodes{display:flex;flex-direction:column;gap:8px;width:100%;margin-top:4px}
.bac-hierarchy-subnode{border:1px solid #dbe3ec;background:#f8fafc;border-radius:7px;padding:10px 12px;color:#334155;font-size:13px;font-weight:600;width:100%;box-sizing:border-box}
.bac-hierarchy-bac{max-width:440px!important}
.bac-hierarchy-twg{margin-top:0;background:#eff6ff;border-color:#93c5fd;min-width:0;width:100%;padding:14px 12px}
.bac-hierarchy-twg strong{font-size:14px}

.bac-hierarchy-note{font-size:11px;color:#64748b;text-align:center;margin-top:22px}
@media(max-width:700px){.bac-hierarchy-bac-column{width:100%;max-width:360px}.bac-hierarchy-twg-branch{margin-top:0;padding-top:20px}.bac-hierarchy-twg-branch:before{left:50%;height:20px}.bac-hierarchy-branch{flex-direction:column;align-items:center;gap:22px;padding-top:0}.bac-hierarchy-branch:before,.bac-hierarchy-branch .bac-hierarchy-node:before{display:none}.bac-hierarchy-branch .bac-hierarchy-node{width:100%;max-width:360px}.bac-hierarchy-chair{min-width:0;width:100%;max-width:360px}.bac-hierarchy-node{min-width:0;width:100%;max-width:360px}}
.bac-tab-content h3{margin:0;color:#102a43;font-size:17px}
.bac-list-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
.bac-list-toolbar .btn,.bac-form-actions .btn{white-space:nowrap}
.bac-member-form{padding:18px;margin-bottom:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px}
.bac-entry-table-wrap{width:100%;overflow-x:auto}
.bac-entry-table{width:100%;min-width:<?=$type==='BAC'?'850px':'1050px'?>;table-layout:fixed}
.bac-entry-table th,.bac-entry-table td{padding:7px;vertical-align:top}
.bac-entry-table th{font-size:12px;white-space:nowrap}
.bac-entry-table .input{width:100%;min-width:0;box-sizing:border-box;font-size:12px;padding:8px}
.bac-entry-table .bac-area-unit-input{background:#e9ecef!important;color:#6b7280;cursor:not-allowed;opacity:1}
.bac-person-autocomplete{position:relative;width:100%;min-width:0}
.bac-person-suggestions{position:fixed;left:0;top:0;width:300px;max-width:min(420px,calc(100vw - 32px));z-index:100000;background:#fff;border:1px solid #94a3b8;border-radius:6px;box-shadow:0 10px 28px rgba(15,23,42,.24);max-height:260px;overflow-y:auto;display:none}
.bac-person-suggestion{display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-bottom:1px solid #e5e7eb;background:#fff;text-align:left;cursor:pointer;font-size:12px;white-space:normal;overflow-wrap:anywhere}
.bac-person-suggestion:hover,.bac-person-suggestion:focus{background:#eef5ff}
.bac-person-suggestion small{display:block;color:#6b7280;margin-top:3px;font-size:11px}

.bac-entry-table .bac-entry-action{white-space:nowrap}.bac-entry-table .bac-row-remove{padding:7px 10px;font-size:12px;background:#dc3545;color:#fff;border-color:#dc3545}
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
  const rows=document.getElementById('bacEntryRows');
  const addRow=document.getElementById('addMemberRow');
  const save=document.getElementById('saveMemberItems');
  function updateSaveLabel(){
    if(save&&rows)save.textContent=rows.querySelectorAll('.bac-entry-row').length>1?'Save Members':'Save Member';
  }
  const personOptions=<?=json_encode($bacPeopleOptions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
  function closePersonSuggestions(box){const list=box.querySelector('.bac-person-suggestions');if(list){list.innerHTML='';list.style.display='none';list.style.left='';list.style.top='';list.style.width='';}}
  function positionPersonSuggestions(input,list){
    const rect=input.getBoundingClientRect();
    const gap=5;
    const maxHeight=Math.min(260,window.innerHeight-24);
    list.style.width=Math.max(220,Math.min(360,rect.width))+'px';
    list.style.maxHeight=maxHeight+'px';
    list.style.left=Math.max(8,Math.min(rect.left,window.innerWidth-list.offsetWidth-8))+'px';
    list.style.top=Math.max(8,rect.top-Math.min(list.scrollHeight,maxHeight)-gap)+'px';
  }
  function choosePerson(row,person){
    const input=row.querySelector('.bac-person-name');
    const areaInput=row.querySelector('.bac-area-unit-input');
    if(input)input.value=person.name;
    if(areaInput)areaInput.value=(person.area_units||[]).join(', ');
    const box=row.querySelector('.bac-person-autocomplete');if(box)closePersonSuggestions(box);
  }
  function renderPersonSuggestions(input){
    const box=input.closest('.bac-person-autocomplete');
    const list=box&&box.querySelector('.bac-person-suggestions');
    const row=input.closest('.bac-entry-row');
    if(!list||!row)return;
    const term=input.value.trim().toLocaleLowerCase();
    const matches=personOptions.filter(function(person){return !term||person.name.toLocaleLowerCase().includes(term)||person.area_units.some(function(unit){return unit.toLocaleLowerCase().includes(term);});}).slice(0,30);
    list.innerHTML='';
    if(!matches.length){list.style.display='none';return;}
    matches.forEach(function(person){
      const button=document.createElement('button');button.type='button';button.className='bac-person-suggestion';button.setAttribute('role','option');
      const strong=document.createElement('strong');strong.textContent=person.name;button.appendChild(strong);
      const small=document.createElement('small');small.textContent=(person.area_units||[]).join(', ');button.appendChild(small);
      button.addEventListener('click',function(){choosePerson(row,person);});
      list.appendChild(button);
    });
    list.style.display='block';
    positionPersonSuggestions(input,list);
  }
  window.addEventListener('resize',function(){rows&&rows.querySelectorAll('.bac-person-name').forEach(function(input){const list=input.closest('.bac-person-autocomplete').querySelector('.bac-person-suggestions');if(list&&list.style.display==='block')positionPersonSuggestions(input,list);});});
  window.addEventListener('scroll',function(){rows&&rows.querySelectorAll('.bac-person-name').forEach(function(input){const list=input.closest('.bac-person-autocomplete').querySelector('.bac-person-suggestions');if(list&&list.style.display==='block')positionPersonSuggestions(input,list);});},true);
  if(rows){
    rows.addEventListener('input',function(event){
      if(event.target.classList.contains('bac-person-name'))renderPersonSuggestions(event.target);
    });
    rows.addEventListener('focusin',function(event){
      if(event.target.classList.contains('bac-person-name'))renderPersonSuggestions(event.target);
    });
    rows.addEventListener('keydown',function(event){
      if(event.target.classList.contains('bac-person-name')&&event.key==='Escape')closePersonSuggestions(event.target.closest('.bac-person-autocomplete'));
    });
    document.addEventListener('click',function(event){
      rows.querySelectorAll('.bac-person-autocomplete').forEach(function(box){if(!box.contains(event.target))closePersonSuggestions(box);});
    });
  }
  function addMemberRow(){
    if(!rows)return;
    const first=rows.querySelector('.bac-entry-row');
    if(!first)return;
    const row=first.cloneNode(true);
    row.querySelectorAll('input').forEach(function(input){input.value='';});
    row.querySelectorAll('select').forEach(function(select){select.selectedIndex=0;});
    const action=row.querySelector('.bac-entry-action');
    if(action){
      action.innerHTML='<button class="btn danger bac-row-remove" type="button">Remove</button>';
      action.querySelector('.bac-row-remove').addEventListener('click',function(){row.remove();updateSaveLabel();});
    }
    rows.appendChild(row);
    updateSaveLabel();
    const input=row.querySelector('input');if(input)input.focus();
  }
  if(show&&form)show.addEventListener('click',function(){form.hidden=false;show.hidden=true;const first=form.querySelector('input[name="name[]"]');if(first)first.focus();});
  if(addRow)addRow.addEventListener('click',addMemberRow);
  if(cancel&&form&&show)cancel.addEventListener('click',function(){form.reset();if(rows)rows.innerHTML='';form.hidden=true;show.hidden=false;});
  updateSaveLabel();
});
</script>
<?php pageEnd(); ?>
