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
  assigned_member VARCHAR(180) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bac_member_type (member_type),
  INDEX idx_bac_member_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $pdo->exec("ALTER TABLE bac_committee_members ADD COLUMN assigned_member VARCHAR(180) NULL AFTER code_tag"); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)!==1060) throw $e; }
$pdo->exec("ALTER TABLE bac_committee_members MODIFY COLUMN member_type ENUM('BAC','TWG','SECRETARIAT') NOT NULL");
$pdo->exec("CREATE TABLE IF NOT EXISTS bac_twg_teams (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 team_name VARCHAR(180) NOT NULL UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $pdo->exec("ALTER TABLE bac_twg_teams ADD COLUMN bac_code_tag VARCHAR(100) NULL AFTER team_name"); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)!==1060) throw $e; }
try { $pdo->exec("ALTER TABLE bac_committee_members ADD COLUMN bac_team VARCHAR(100) NULL AFTER code_tag"); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)!==1060) throw $e; }
$bacTeamOptions=$pdo->query("SELECT DISTINCT code_tag FROM bac_committee_members WHERE member_type='BAC' AND code_tag<>'' ORDER BY code_tag")->fetchAll(PDO::FETCH_COLUMN);
$twgTeamNames=$pdo->query("SELECT team_name FROM bac_twg_teams ORDER BY team_name")->fetchAll(PDO::FETCH_COLUMN);

$tab=$_GET['tab']??'hierarchy';
if(!in_array($tab,['hierarchy','bac-members','technical-working-group','bac-secretariat'],true)) $tab='hierarchy';

if($_SERVER['REQUEST_METHOD']==='POST'){
  requireRole(['Administrator','Editor']);
  checkCsrf();
  $action=(string)($_POST['action']??'add');
  $postedType=(string)($_POST['member_type']??'BAC');
  $type=in_array($postedType,['BAC','TWG','SECRETARIAT'],true)?$postedType:'BAC';
  $returnTab=$type==='TWG'?'technical-working-group':($type==='SECRETARIAT'?'bac-secretariat':'bac-members');
  if($type==='TWG'&&$action==='create_twg_team'){
    $teamName=trim((string)($_POST['team_name']??''));
    $bacCodeTag=trim((string)($_POST['bac_code_tag']??''));
    if($teamName===''){flash('error','Enter a Team Name.');}
    elseif($bacCodeTag===''||!in_array($bacCodeTag,$bacTeamOptions,true)){flash('error','Select a valid BAC Team for this TWG Team.');}
    else{
      try{$teamStmt=$pdo->prepare('INSERT INTO bac_twg_teams(team_name,bac_code_tag) VALUES(?,?)');$teamStmt->execute([$teamName,$bacCodeTag]);flash('success','TWG Team created and assigned to '.$bacCodeTag.'.');}
      catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)flash('error','That TWG Team Name already exists.');else throw $e;}
    }
    header('Location:bac.php?tab=technical-working-group');exit;
  }
  if($type==='TWG'&&$action==='set_twg_role'){
    $id=(int)($_POST['id']??0); $role=trim((string)($_POST['twg_role']??''));
    $validRoles=['TWG Chairman','TWG Vice-Chairman','TWG Member'];
    $personStmt=$pdo->prepare("SELECT id,name,code_tag FROM bac_committee_members WHERE id=? AND member_type='TWG' LIMIT 1");
    $personStmt->execute([$id]); $person=$personStmt->fetch(PDO::FETCH_ASSOC);
    if(!$person||!in_array($role,$validRoles,true)){flash('error','Select a valid TWG member and role.');}
    else{
      $roleCheck=$pdo->prepare("SELECT COUNT(*) FROM bac_committee_members WHERE member_type='TWG' AND code_tag=? AND designation=? AND id<>?");
      $roleCheck->execute([$person['code_tag'],$role,$id]);
      if($role!=='TWG Member'&&(int)$roleCheck->fetchColumn()>0){flash('error',$role.' is already assigned to this TWG Team.');}
      else{$updateRole=$pdo->prepare("UPDATE bac_committee_members SET designation=? WHERE id=? AND member_type='TWG'");$updateRole->execute([$role,$id]);flash('success',$person['name'].' is now assigned as '.$role.'.');}
    }
    header('Location:bac.php?tab=technical-working-group');exit;
  }
  if($type==='TWG'&&$action==='edit_twg_team'){
    $teamId=(int)($_POST['team_id']??0);
    $teamName=trim((string)($_POST['team_name']??''));
    $bacCodeTag=trim((string)($_POST['bac_code_tag']??''));
    $oldStmt=$pdo->prepare('SELECT id,team_name,bac_code_tag FROM bac_twg_teams WHERE id=? LIMIT 1');
    $oldStmt->execute([$teamId]);
    $oldTeam=$oldStmt->fetch(PDO::FETCH_ASSOC);
    if(!$oldTeam){flash('error','TWG Team could not be found.');}
    elseif($teamName===''){flash('error','Enter a Team Name.');}
    elseif($bacCodeTag===''||!in_array($bacCodeTag,$bacTeamOptions,true)){flash('error','Select a valid BAC Team for this TWG Team.');}
    else{
      try{
        $pdo->beginTransaction();
        $teamStmt=$pdo->prepare('UPDATE bac_twg_teams SET team_name=?,bac_code_tag=? WHERE id=?');
        $teamStmt->execute([$teamName,$bacCodeTag,$teamId]);
        $memberStmt=$pdo->prepare("UPDATE bac_committee_members SET code_tag=?,bac_team=? WHERE member_type='TWG' AND code_tag=?");
        $memberStmt->execute([$teamName,$bacCodeTag,$oldTeam['team_name']]);
        $pdo->commit();
        flash('success','TWG Team updated.');
      }catch(PDOException $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if((int)($e->errorInfo[1]??0)===1062)flash('error','That TWG Team Name already exists.');
        else throw $e;
      }
    }
    header('Location:bac.php?tab=technical-working-group');exit;
  }
  if($type==='SECRETARIAT'&&$action==='add_secretariat_member'){
    $name=trim((string)($_POST['name']??''));
    $areaUnit=trim((string)($_POST['area_unit']??''));
    if($name===''||$areaUnit===''){flash('error','Enter the Secretariat member name and Area/Unit.');}
    else{
      $dupe=$pdo->prepare("SELECT COUNT(*) FROM bac_committee_members WHERE member_type='SECRETARIAT' AND LOWER(TRIM(name))=LOWER(TRIM(?))");
      $dupe->execute([$name]);
      if((int)$dupe->fetchColumn()>0){flash('error','This person is already listed in the BAC Secretariat.');}
      else{
        $ins=$pdo->prepare("INSERT INTO bac_committee_members(member_type,name,designation,area_unit,code_tag) VALUES('SECRETARIAT',?,'Secretariat Member',?,'BAC Secretariat')");
        $ins->execute([$name,$areaUnit]);flash('success','BAC Secretariat member added.');
      }
    }
    header('Location:bac.php?tab=bac-secretariat');exit;
  }
  if($type==='SECRETARIAT'&&$action==='set_secretariat_head'){
    $id=(int)($_POST['id']??0);
    $check=$pdo->prepare("SELECT id,name FROM bac_committee_members WHERE id=? AND member_type='SECRETARIAT' LIMIT 1");
    $check->execute([$id]);$target=$check->fetch(PDO::FETCH_ASSOC);
    if(!$target){flash('error','Select a valid Secretariat member.');}
    else{
      $pdo->beginTransaction();
      try{
        $pdo->prepare("UPDATE bac_committee_members SET designation='Secretariat Member' WHERE member_type='SECRETARIAT'")->execute();
        $pdo->prepare("UPDATE bac_committee_members SET designation='Head BAC Secretariat' WHERE id=? AND member_type='SECRETARIAT'")->execute([$id]);
        $pdo->commit();flash('success',$target['name'].' is assigned as Head BAC Secretariat.');
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    header('Location:bac.php?tab=bac-secretariat');exit;
  }
  if($action==='delete_member'){
    $id=(int)($_POST['id']??0);
    if($type==='BAC'){
      // Deleting an original member also removes any Ad-Hoc member assigned to that person.
      $personStmt=$pdo->prepare("SELECT name,designation,code_tag FROM bac_committee_members WHERE id=? AND member_type='BAC' LIMIT 1");
      $personStmt->execute([$id]);
      $person=$personStmt->fetch(PDO::FETCH_ASSOC);
      if(!$person){
        flash('error','Member could not be found.');
      }else{
        $adhocForRole=['BAC Chairman'=>'Ad-Hoc Chairman','BAC Vice-Chairman'=>'Ad-Hoc Vice-Chairman','BAC Member'=>'Ad-Hoc Member'];
        $deletedAdHocs=0;
        if(isset($adhocForRole[$person['designation']])){
          $adhocStmt=$pdo->prepare("DELETE FROM bac_committee_members WHERE member_type='BAC' AND code_tag=? AND designation=? AND LOWER(TRIM(assigned_member))=LOWER(TRIM(?))");
          $adhocStmt->execute([$person['code_tag'],$adhocForRole[$person['designation']],$person['name']]);
          $deletedAdHocs=$adhocStmt->rowCount();
        }
        $st=$pdo->prepare('DELETE FROM bac_committee_members WHERE id=? AND member_type=?');
        $st->execute([$id,$type]);
        flash('success','Original BAC member deleted.'.($deletedAdHocs?' '.$deletedAdHocs.' assigned Ad-Hoc member(s) deleted as well.':''));
      }
    }else{
      $st=$pdo->prepare('DELETE FROM bac_committee_members WHERE id=? AND member_type=?');
      $st->execute([$id,$type]);
      flash($st->rowCount()?'success':'error',$st->rowCount()?'Member deleted.':'Member could not be found.');
    }
    header('Location:bac.php?tab='.$returnTab);exit;
  }
  if($action==='delete_twg_teams'&&$type==='TWG'){
    $teamIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['team_ids']??[])),static fn($id)=>$id>0)));
    if(!$teamIds){
      flash('error','Select at least one TWG Team to delete.');
    }else{
      $marks=implode(',',array_fill(0,count($teamIds),'?'));
      $pdo->beginTransaction();
      try{
        $teamStmt=$pdo->prepare("SELECT id,team_name FROM bac_twg_teams WHERE id IN ($marks)");
        $teamStmt->execute($teamIds);
        $selectedTeams=$teamStmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$selectedTeams){
          $pdo->rollBack();
          flash('error','The selected TWG Team(s) could not be found.');
        }else{
          $teamNames=array_column($selectedTeams,'team_name');
          $memberMarks=implode(',',array_fill(0,count($teamNames),'?'));
          $deleteMembers=$pdo->prepare("DELETE FROM bac_committee_members WHERE member_type='TWG' AND code_tag IN ($memberMarks)");
          $deleteMembers->execute($teamNames);
          $deleteTeams=$pdo->prepare("DELETE FROM bac_twg_teams WHERE id IN ($marks)");
          $deleteTeams->execute(array_column($selectedTeams,'id'));
          $deletedTeamCount=$deleteTeams->rowCount();
          $deletedMemberCount=$deleteMembers->rowCount();
          $pdo->commit();
          flash('success',$deletedTeamCount.' TWG Team(s) and '.$deletedMemberCount.' member(s) deleted.');
        }
      }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
      }
    }
    header('Location:bac.php?tab=technical-working-group');exit;
  }
  if($action==='delete_team'&&$type==='BAC'){
    $tag=trim((string)($_POST['code_tag']??''));
    if(!in_array($tag,['BAC A','BAC B','BAC C','BAC D','BAC E'],true)){
      flash('error','Select a valid BAC team to delete.');
    }else{
      $st=$pdo->prepare("DELETE FROM bac_committee_members WHERE member_type='BAC' AND code_tag=?");
      $st->execute([$tag]);
      flash('success',$st->rowCount()?'BAC Team '.$tag.' and its '.$st->rowCount().' member(s) deleted.':'BAC Team '.$tag.' deleted.');
    }
    header('Location:bac.php?tab=bac-members');exit;
  }
  if($action==='delete_selected'){
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected_ids']??[])),static fn($id)=>$id>0)));
    if(!$ids){flash('error','Select at least one member to delete.');}
    else{
      $marks=implode(',',array_fill(0,count($ids),'?'));
      $st=$pdo->prepare("DELETE FROM bac_committee_members WHERE member_type=? AND id IN ($marks)");
      $st->execute(array_merge([$type],$ids));
      flash('success',$st->rowCount().' member(s) deleted.');
    }
    header('Location:bac.php?tab='.$returnTab);exit;
  }
  if($action==='update_member'){
    $id=(int)($_POST['id']??0);
    $name=trim((string)($_POST['name']??''));
    $designation=trim((string)($_POST['designation']??''));
    $expertise=trim((string)($_POST['area_expertise']??''));
    $areaUnit=trim((string)($_POST['area_unit']??''));
    $codeTag=trim((string)($_POST['code_tag']??''));
    $bacTeam=trim((string)($_POST['bac_team']??''));
    $assigned=trim((string)($_POST['assigned_member']??''));
    if($id<1||$name===''||$designation===''||$areaUnit===''||$codeTag===''||($type==='TWG'&&($expertise===''||$bacTeam===''||!in_array($codeTag,$twgTeamNames??[],true)||!in_array($bacTeam,$bacTeamOptions,true)))){
      flash('error','Complete all required fields.');
    }elseif($type==='BAC'&&preg_match('/\bprocurement\b/i',$areaUnit)){
      flash('error','Personnel from the Procurement Area/Unit cannot be assigned to a BAC designation.');
    }elseif($type==='BAC'&&!in_array($designation,['BAC Chairman','BAC Vice-Chairman','BAC Member','Ad-Hoc Chairman','Ad-Hoc Vice-Chairman','Ad-Hoc Member'],true)){
      flash('error','Select a valid BAC designation.');
    }elseif($type==='BAC'&&in_array($designation,['Ad-Hoc Chairman','Ad-Hoc Vice-Chairman','Ad-Hoc Member'],true)&&$assigned===''){
      flash('error','Select the BAC Member assigned to this Ad-Hoc Member.');
    }else{
      if($type==='BAC'){
        $normalizeUpdateTag=static function($value){return mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$value)));};
        $rosterStmt=$pdo->prepare("SELECT id,name,designation,code_tag FROM bac_committee_members WHERE member_type='BAC' AND id<>?");
        $rosterStmt->execute([$id]);
        $rosterForUpdate=$rosterStmt->fetchAll(PDO::FETCH_ASSOC);
        $rosterForUpdate[]=['id'=>$id,'name'=>$name,'designation'=>$designation,'code_tag'=>$codeTag];
        $counts=[];
        foreach($rosterForUpdate as $person){
          $tagKey=$normalizeUpdateTag($person['code_tag']??'');
          if($tagKey==='') continue;
          if(!isset($counts[$tagKey])) $counts[$tagKey]=['label'=>trim((string)$person['code_tag']),'total'=>0,'chairman'=>0,'vice'=>0,'members'=>0];
          $counts[$tagKey]['total']++;
          $role=mb_strtolower(trim((string)($person['designation']??'')));
          if($role==='bac chairman') $counts[$tagKey]['chairman']++;
          elseif($role==='bac vice-chairman') $counts[$tagKey]['vice']++;
          elseif($role==='bac member') $counts[$tagKey]['members']++;
        }
        $countErrors=[];
        foreach($counts as $data){
          if($data['total']>7) $countErrors[]='BAC Code/Tag "'.$data['label'].'" cannot have more than 7 total members.';
          if($data['members']>5) $countErrors[]='BAC Code/Tag "'.$data['label'].'" cannot have more than 5 BAC Members.';
        }
        if($countErrors){
          flash('error',implode(' ',$countErrors).' Member was not updated.');
          header('Location:bac.php?tab='.$returnTab);exit;
        }
      }
      $st=$pdo->prepare('UPDATE bac_committee_members SET name=?,designation=?,area_expertise=?,area_unit=?,code_tag=?,bac_team=?,assigned_member=? WHERE id=? AND member_type=?');
      $st->execute([$name,$designation,$type==='TWG'?$expertise:null,$areaUnit,$codeTag,$type==='TWG'?$bacTeam:null,$type==='BAC'&&in_array($designation,['Ad-Hoc Chairman','Ad-Hoc Vice-Chairman','Ad-Hoc Member'],true)?$assigned:null,$id,$type]);
      flash('success','Member updated.');
    }
    header('Location:bac.php?tab='.$returnTab);exit;
  }
  $returnTab=$type==='TWG'?'technical-working-group':'bac-members';
  $names=(array)($_POST['name']??[]);
  $designations=(array)($_POST['designation']??[]);
  $expertises=(array)($_POST['area_expertise']??[]);
  $areaUnits=(array)($_POST['area_unit']??[]);
  $codeTags=(array)($_POST['code_tag']??[]);
  $bacTeams=(array)($_POST['bac_team']??[]);
  $assignedMembers=(array)($_POST['assigned_member']??[]);
  $count=max(count($names),count($designations),count($expertises),count($areaUnits),count($codeTags),count($bacTeams),count($assignedMembers));
  $entries=[];
  for($i=0;$i<$count;$i++){
    $name=trim((string)($names[$i]??''));
    $designation=trim((string)($designations[$i]??''));
    $expertise=trim((string)($expertises[$i]??''));
    $areaUnit=trim((string)($areaUnits[$i]??''));
    $codeTag=trim((string)($codeTags[$i]??''));
    $bacTeam=trim((string)($bacTeams[$i]??''));
    $assignedMember=trim((string)($assignedMembers[$i]??''));
    if($name===''&&$designation===''&&$expertise===''&&$areaUnit===''&&$codeTag===''&&$bacTeam===''&&$assignedMember==='') continue;
    if($type==='TWG'&&isset($existingBacNameKeys[mb_strtolower(preg_replace('/\\s+/u',' ',trim($name)))])){flash('error','A person who is already a BAC member cannot be assigned to a TWG. No members were saved.');header('Location:bac.php?tab='.$returnTab);exit;}
     if($name===''||$designation===''||$areaUnit===''||$codeTag===''||($type==='TWG'&&($bacTeam===''||!in_array($bacTeam,$bacTeamOptions,true)||!in_array($codeTag,$twgTeamNames??[],true)))){
      flash('error','Complete every required field in each member row. No members were saved.');
      header('Location:bac.php?tab='.$returnTab);exit;
    }
    if($type==='BAC'&&preg_match('/\\bprocurement\\b/i',$areaUnit)){flash('error','Personnel from the Procurement Area/Unit cannot be added to any BAC designation, including Ad-Hoc roles. No members were saved.');header('Location:bac.php?tab='.$returnTab);exit;}
    if($type==='BAC'&&in_array(mb_strtolower($designation),['ad-hoc chairman','ad-hoc vice-chairman','ad-hoc member'],true)&&$assignedMember===''){flash('error','Select an assigned BAC Chairman, BAC Vice-Chairman, or BAC Member for each Ad-Hoc role. No members were saved.');header('Location:bac.php?tab='.$returnTab);exit;}
    if(($type!=='BAC'||!in_array(mb_strtolower($designation),['ad-hoc chairman','ad-hoc vice-chairman','ad-hoc member'],true))&&$assignedMember!==''){flash('error','Only Ad-Hoc roles may have an Assigned BAC Member. No members were saved.');header('Location:bac.php?tab='.$returnTab);exit;}
    $entries[]=[$type,$name,$designation,$type==='TWG'?$expertise:null,$areaUnit,$codeTag,$type==='TWG'?$bacTeam:null,$assignedMember!==''?$assignedMember:null];
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
    $existingStmt=$pdo->prepare('SELECT name,designation,code_tag,area_unit FROM bac_committee_members WHERE member_type=\'BAC\'');
    $existingStmt->execute();
    $existing=$existingStmt->fetchAll(PDO::FETCH_ASSOC); foreach($existing as &$existingPerson){$existingPerson['_new_entry']=false;} unset($existingPerson);
    $all=array_merge($existing,$type==='BAC'?array_map(static function($entry){return ['name'=>$entry[1],'designation'=>$entry[2],'code_tag'=>$entry[5],'assigned_member'=>$entry[6]??null,'area_unit'=>$entry[4],'_new_entry'=>true];},$entries):[]);
    $errors=[];
    $seenNames=[];
    $seenRoles=[];
    $bacMemberNames=[];
    foreach($all as $person){ if(mb_strtolower(trim((string)($person['designation']??'')))==='bac member')$bacMemberNames[$normalizeTag($person['code_tag']??'')][$normalizeName($person['name']??'')]=trim((string)($person['name']??'')); }
    foreach($all as $person){
      $tag=$normalizeTag($person['code_tag']??'');
      $name=$normalizeName($person['name']??'');
      $designation=mb_strtolower(trim((string)($person['designation']??'')));
      if($tag===''||$name==='')continue;
      if(!in_array($tag,$validTags,true))$errors[]='Invalid BAC Code/Tag "'.trim((string)$person['code_tag']).'". Choose BAC A, BAC B, BAC C, BAC D, or BAC E.';
      if(!in_array($designation,$allowedDesignations,true))$errors[]='Invalid BAC designation for '.$person['name'].'.';
      if(preg_match('/\\bprocurement\\b/i',(string)($person['area_unit']??'')))$errors[]=$person['name'].' belongs to the Procurement Area/Unit and cannot be assigned to a BAC designation.';
      if(in_array($designation,['bac chairman','bac vice-chairman','ad-hoc chairman','ad-hoc vice-chairman'],true)){
        $roleKey=$tag.'|'.$designation;
        if(isset($seenRoles[$roleKey]))$errors[]='BAC code/tag "'.trim((string)$person['code_tag']).'" may have only one '.trim((string)$person['designation']).'.';
        else $seenRoles[$roleKey]=true;
      }
      if(!empty($person['_new_entry'])&&in_array($designation,['ad-hoc chairman','ad-hoc vice-chairman','ad-hoc member'],true)){$assigned=$normalizeName($person['assigned_member']??'');$requiredRole=['ad-hoc chairman'=>'bac chairman','ad-hoc vice-chairman'=>'bac vice-chairman','ad-hoc member'=>'bac member'][$designation];$eligible=false;foreach($all as $candidate){if($normalizeTag($candidate['code_tag']??'')===$tag&&mb_strtolower(trim((string)($candidate['designation']??'')))===$requiredRole&&$normalizeName($candidate['name']??'')===$assigned){$eligible=true;break;}}if($assigned===''||!$eligible)$errors[]=$person['designation'].' '.$person['name'].' must be assigned to a '.$requiredRole.' under the same Code/Tag.';}
      // A named BAC person may belong to only one code/tag, even if repeated in the current batch.
      if(isset($seenNames[$name])&&$seenNames[$name]!==$tag)$errors[]=$person['name'].' is already assigned to a different BAC code/tag and cannot be added again.';
      else $seenNames[$name]=$tag;
    }
    // Allow a BAC team to be built one member at a time. Enforce upper limits
    // during each save, but do not require the Chairman, Vice-Chairman, or five
    // total members to exist before an individual can be added.
    $membersByTag=[];
    foreach($all as $person){
      $tag=$normalizeTag($person['code_tag']??'');
      if($tag==='') continue;
      if(!isset($membersByTag[$tag])) $membersByTag[$tag]=['label'=>trim((string)($person['code_tag']??'')),'total'=>0,'chairman'=>0,'vice'=>0,'members'=>0];
      $membersByTag[$tag]['total']++;
      $role=mb_strtolower(trim((string)($person['designation']??'')));
      if($role==='bac chairman') $membersByTag[$tag]['chairman']++;
      elseif($role==='bac vice-chairman') $membersByTag[$tag]['vice']++;
      elseif($role==='bac member') $membersByTag[$tag]['members']++;
    }
    foreach($membersByTag as $tagData){
      $tagLabel=$tagData['label'];
      if($tagData['total']>7) $errors[]='BAC Code/Tag "'.$tagLabel.'" cannot have more than 7 total members.';
      if($tagData['members']>5) $errors[]='BAC Code/Tag "'.$tagLabel.'" cannot have more than 5 BAC Members.';
    }
    if($errors){
      flash('error',implode(' ',$errors).' No members were saved.');
    }else{
      $st=$pdo->prepare('INSERT INTO bac_committee_members(member_type,name,designation,area_expertise,area_unit,code_tag,bac_team,assigned_member) VALUES(?,?,?,?,?,?,?,?)');
      $pdo->beginTransaction();
      try{foreach($entries as $entry)$st->execute($entry);$pdo->commit();flash('success',count($entries)===1?'Member added.':count($entries).' members added.');}
      catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
  }
  header('Location:bac.php?tab='.$returnTab);exit;
}

$type=$tab==='technical-working-group'?'TWG':($tab==='bac-secretariat'?'SECRETARIAT':'BAC');
$st=$pdo->prepare('SELECT id,name,designation,area_expertise,area_unit,code_tag,bac_team,assigned_member FROM bac_committee_members WHERE member_type=? ORDER BY name,id');
$st->execute([$type]);
$members=$st->fetchAll();
$canManage=hasRole(['Administrator','Editor']);

// Build the BAC name list from configured Division/Department supervisors and Area/Unit Heads.
$bacEligiblePeople=$pdo->query("SELECT d.division_head AS name,d.name AS area_unit,'Division/Department Supervisor' AS source_role FROM divisions d WHERE TRIM(COALESCE(d.division_head,''))<>'' AND LOWER(d.name) NOT LIKE '%procurement%' UNION SELECT ap.name AS name,a.name AS area_unit,'Area/Unit Personnel' AS source_role FROM area_personnel ap JOIN areas a ON a.id=ap.area_id WHERE TRIM(COALESCE(ap.name,''))<>'' AND LOWER(a.name) NOT LIKE '%procurement%' ORDER BY name,area_unit")->fetchAll(PDO::FETCH_ASSOC);
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
// People already registered as BAC members are not eligible for TWG assignment.
$existingBacNames=$pdo->query("SELECT name FROM bac_committee_members WHERE member_type='BAC'")->fetchAll(PDO::FETCH_COLUMN);
$existingBacNameKeys=array_fill_keys(array_map(static fn($name)=>mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$name))),$existingBacNames),true);
$bacPeopleOptions=array_values(array_filter($bacPeopleOptions,static fn($person)=>!isset($existingBacNameKeys[mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$person['name'])))])));
$bacRoster=$pdo->query("SELECT name,designation,code_tag,assigned_member FROM bac_committee_members WHERE member_type='BAC' ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
$twgTeams=$pdo->query("SELECT id,team_name,bac_code_tag FROM bac_twg_teams ORDER BY team_name")->fetchAll(PDO::FETCH_ASSOC);
$twgTeamNames=array_values(array_map(static fn($team)=>$team['team_name'],$twgTeams));

pageStart('Bids and Awards Committee');
?>
<div class="panel bac-page-panel">
  <div class="bac-page-heading">
    <div class="bac-page-icon" aria-hidden="true">⚖</div>
    <div>
      <h2>Bids and Awards Committee</h2>
      <p>Manage BAC Members and the Technical Working Group. Add BAC roles one at a time; each Code/Tag allows up to 7 total records and a maximum of 5 regular BAC Members. Add one Chairman and one Vice-Chairman as needed.</p>
    </div>
  </div>
  <nav class="bac-tabs" aria-label="Bids and Awards Committee sections">
    <a class="btn <?=$tab==='hierarchy'?'':'secondary'?>" href="bac.php?tab=hierarchy" <?=$tab==='hierarchy'?'aria-current="page"':''?>>Hierarchy</a>
    <a class="btn <?=$tab==='bac-members'?'':'secondary'?>" href="bac.php?tab=bac-members" <?=$tab==='bac-members'?'aria-current="page"':''?>>BAC Members</a>
    <a class="btn <?=$tab==='bac-secretariat'?'':'secondary'?>" href="bac.php?tab=bac-secretariat" <?=$tab==='bac-secretariat'?'aria-current="page"':''?>>BAC Secretariat</a>
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
  <?php elseif($tab==='bac-secretariat'): ?>
  <?php
    $secretariatRows=$pdo->query("SELECT id,name,designation,area_unit FROM bac_committee_members WHERE member_type='SECRETARIAT' ORDER BY CASE WHEN designation='Head BAC Secretariat' THEN 0 ELSE 1 END,name,id")->fetchAll(PDO::FETCH_ASSOC);
    $secretariatHeadCount=count(array_filter($secretariatRows,static fn($row)=>$row['designation']==='Head BAC Secretariat'));
  ?>
  <section class="bac-tab-content">
    <div class="bac-list-toolbar"><h3>BAC Secretariat Team</h3><?php if($canManage): ?><button class="btn" type="button" id="showAddSecretariatMember">Add BAC Secretariat Member</button><?php endif; ?></div>
    <?php if($canManage): ?>
    <form method="post" id="addSecretariatMemberForm" class="bac-member-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="SECRETARIAT">
      <input type="hidden" name="action" value="add_secretariat_member">
      <div class="bac-edit-grid">
        <label>Name*<input class="input" name="name" maxlength="180" required placeholder="Enter Secretariat member name"></label>
        <label>Area/Unit*<input class="input" name="area_unit" maxlength="180" required placeholder="Enter Area/Unit"></label>
      </div>
      <div class="bac-form-actions"><button class="btn" type="submit">Save Member</button><button class="btn secondary" type="button" id="cancelAddSecretariatMember">Cancel</button></div>
    </form>
    <?php endif; ?>
    <?php if($canManage&&$secretariatRows): ?>
    <form method="post" id="deleteSecretariatMembersForm" onsubmit="if(!confirm('Delete the selected BAC Secretariat member(s)? This cannot be undone.'))return false;return Array.from(this.querySelectorAll('input[name=&quot;selected_ids[]&quot;]')).some(function(box){return box.checked;})||(alert('Select at least one member to delete.'),false);">
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="SECRETARIAT">
      <input type="hidden" name="action" value="delete_selected">
      <div class="bac-bulk-actions"><label><input type="checkbox" id="selectAllSecretariatMembers"> Select All</label><button class="btn danger" type="submit">Delete Selected Members</button></div>
      <div class="table-wrap"><table class="table bac-members-table"><thead><tr><th>Select</th><th>Name</th><th>Designation</th><th>Area/Unit</th><?php if($canManage): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
      <?php foreach($secretariatRows as $secretariatRow): ?>
        <tr><td><input type="checkbox" name="selected_ids[]" value="<?=(int)$secretariatRow['id']?>" aria-label="Select <?=e($secretariatRow['name'])?>"></td><td><strong><?=e($secretariatRow['name'])?></strong></td><td><?=e($secretariatRow['designation'])?></td><td><?=e($secretariatRow['area_unit'])?></td><td><?php if($secretariatRow['designation']!=='Head BAC Secretariat'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="SECRETARIAT"><input type="hidden" name="action" value="set_secretariat_head"><input type="hidden" name="id" value="<?=(int)$secretariatRow['id']?>"><button class="btn secondary" type="submit">Assign as Head</button></form><?php else: ?><span class="bac-role-empty">Current Head</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </form>
    <?php elseif($secretariatRows): ?>
      <div class="table-wrap"><table class="table bac-members-table"><thead><tr><th>Name</th><th>Designation</th><th>Area/Unit</th></tr></thead><tbody><?php foreach($secretariatRows as $secretariatRow): ?><tr><td><?=e($secretariatRow['name'])?></td><td><?=e($secretariatRow['designation'])?></td><td><?=e($secretariatRow['area_unit'])?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?><p class="bac-role-empty">No BAC Secretariat members have been added yet.</p><?php endif; ?>
  </section>
  <?php else: ?>
  <section class="bac-tab-content">
    <div class="bac-list-toolbar">
      <h3><?=$tab==='bac-members'?'BAC Members':'Technical Working Group'?></h3>
      <?php if($canManage&&$type==='TWG'): ?><button class="btn" type="button" id="showAddTwgTeam">Add TWG Team</button><?php endif; ?>
      <?php if($canManage&&$type==='BAC'):
        $usedTeamTags=[];
        foreach($members as $existingTeamMember){$usedTeamTags[]=mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$existingTeamMember['code_tag'])));}
        $availableTeamTags=array_values(array_filter(['BAC A','BAC B','BAC C','BAC D','BAC E'],static function($candidate)use($usedTeamTags){return !in_array(mb_strtolower($candidate),$usedTeamTags,true);}));
        $requestedTeam=trim((string)($_GET['add_team']??''));
        $validRequestedTeam=in_array($requestedTeam,['BAC A','BAC B','BAC C','BAC D','BAC E'],true)&&!in_array(mb_strtolower($requestedTeam),$usedTeamTags,true);
        if($validRequestedTeam){$availableTeamTags=array_values(array_filter($availableTeamTags,static fn($candidate)=>$candidate!==$requestedTeam));}
        $nextTeam=$availableTeamTags[0]??'';
        // The Code/Tag dropdown must offer only teams that exist or the one currently being added.
        $teamTagsForDropdown=[];
        foreach(['BAC A','BAC B','BAC C','BAC D','BAC E'] as $knownTag){
          if(in_array(mb_strtolower($knownTag),$usedTeamTags,true)||($validRequestedTeam&&$knownTag===$requestedTeam)) $teamTagsForDropdown[]=$knownTag;
        }
      ?>
        <?php if($nextTeam!==''): ?><a class="btn" href="bac.php?tab=bac-members&amp;add_team=<?=rawurlencode($nextTeam)?>">Add Team</a><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if($canManage&&$type==='TWG'): ?>
    <form method="post" id="addTwgTeamForm" class="bac-member-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="TWG">
      <input type="hidden" name="action" value="create_twg_team">
      <div class="bac-edit-grid"><label>Team Name*<input class="input" name="team_name" maxlength="180" required placeholder="Enter TWG Team Name"></label><label>BAC Team*<select class="input" name="bac_code_tag" required><option value="">Select BAC Team</option><?php foreach($bacTeamOptions as $bacTeamOption): ?><option value="<?=e($bacTeamOption)?>"><?=e($bacTeamOption)?></option><?php endforeach; ?></select></label></div><?php if(!$bacTeamOptions): ?><p class="bac-role-empty">Create a BAC Team and add at least one BAC member before creating a TWG Team.</p><?php endif; ?>
      <div class="bac-form-actions"><button class="btn" type="submit" <?=!$bacTeamOptions?'disabled':''?>>Save Team</button><button class="btn secondary" type="button" id="cancelAddTwgTeam">Cancel</button></div>
    </form>
    <?php endif; ?>
    <?php if($canManage&&$type==='TWG'): ?>
    <form method="post" id="editTwgTeamForm" class="bac-edit-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="TWG">
      <input type="hidden" name="action" value="edit_twg_team">
      <input type="hidden" name="team_id" id="editTwgTeamId">
      <div class="bac-edit-grid"><label>Team Name*<input class="input" name="team_name" id="editTwgTeamName" maxlength="180" required></label><label>BAC Team*<select class="input" name="bac_code_tag" id="editTwgTeamBacTeam" required><option value="">Select BAC Team</option><?php foreach($bacTeamOptions as $bacTeamOption): ?><option value="<?=e($bacTeamOption)?>"><?=e($bacTeamOption)?></option><?php endforeach; ?></select></label></div>
      <div class="bac-form-actions"><button class="btn" type="submit">Save Team Changes</button><button class="btn secondary" type="button" id="cancelEditTwgTeam">Cancel</button></div>
    </form>
    <?php endif; ?>
    <?php if($canManage): ?>
    <form method="post" id="addMemberForm" class="bac-member-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="<?=e($type)?>">
      <div class="table-wrap bac-entry-table-wrap"><table class="table bac-entry-table"><thead><tr><th>Name*</th><th><?=$type==='BAC'?'BAC Designation':'TWG Designation'?>*</th><th>Area/Unit*</th><?php if($type==='BAC'): ?><th>Assigned BAC Member</th><?php endif; ?><th><?=$type==='BAC'?'Code/Tag*':'TWG Name*'?></th><?php if($type==='TWG'): ?><th>BAC Team*</th><?php endif; ?></tr></thead><tbody id="bacEntryRows"><tr class="bac-entry-row"><td><div class="bac-person-autocomplete"><input class="input bac-person-name" type="text" name="name[]" maxlength="180" placeholder="Search name..." autocomplete="off" aria-label="Name" required><div class="bac-person-suggestions" role="listbox"></div></div></td><td><?php if($type==='BAC'): ?><select class="input" name="designation[]" aria-label="BAC Designation" required><option value="">Select BAC Designation</option><option value="BAC Chairman">BAC Chairman</option><option value="BAC Vice-Chairman">BAC Vice-Chairman</option><option value="BAC Member">BAC Member</option><option value="Ad-Hoc Chairman">Ad-Hoc Chairman</option><option value="Ad-Hoc Vice-Chairman">Ad-Hoc Vice-Chairman</option><option value="Ad-Hoc Member">Ad-Hoc Member</option></select><?php else: ?><input class="input bac-locked-field" type="text" name="designation[]" value="TWG Member" maxlength="180" placeholder="TWG Designation" aria-label="TWG Designation" readonly required><?php endif; ?></td><td><input class="input bac-area-unit-input bac-locked-field" type="text" name="area_unit[]" maxlength="180" placeholder="Auto-filled from selected name" aria-label="Area/Unit" readonly required></td><?php if($type==='BAC'): ?><td><select class="input bac-assigned-member" name="assigned_member[]" aria-label="Assigned BAC Member" disabled><option value="">Only required for Ad-Hoc Member</option></select></td><?php endif; ?><td><?php if($type==='BAC'): ?><select class="input" name="code_tag[]" aria-label="BAC Code/Tag" required><option value="">Select BAC Code/Tag</option><?php foreach(($teamTagsForDropdown??[]) as $bacTag): ?><option value="<?=e($bacTag)?>"><?=e($bacTag)?></option><?php endforeach; ?></select><?php else: ?><select class="input bac-locked-field" name="code_tag[]" aria-label="TWG Name" required disabled><option value="">Select TWG Name</option><?php foreach($twgTeams as $twgTeamOption): ?><option value="<?=e($twgTeamOption['team_name'])?>"><?=e($twgTeamOption['team_name'])?></option><?php endforeach; ?></select><input type="hidden" name="code_tag[]" value=""><?php endif; ?></td><?php if($type==='TWG'): ?><td><select class="input bac-locked-field" name="bac_team_display[]" aria-label="Assigned BAC Team" disabled><option value="">Assigned BAC Team</option><?php foreach($bacTeamOptions as $bacTeamOption): ?><option value="<?=e($bacTeamOption)?>"><?=e($bacTeamOption)?></option><?php endforeach; ?></select><input type="hidden" name="bac_team[]" value=""></td><?php endif; ?></tr></tbody></table></div>
      <div class="bac-form-actions"><button class="btn" type="submit" id="saveMemberItems">Save Member</button><button class="btn secondary" type="button" id="cancelAddMember">Cancel</button></div>
    </form>
    <?php endif; ?>
    <?php if($canManage): ?>
    <form method="post" id="editMemberForm" class="bac-edit-form" hidden>
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="member_type" value="<?=e($type)?>">
      <input type="hidden" name="action" value="update_member">
      <input type="hidden" name="id" id="editMemberId">
      <div class="bac-edit-grid">
        <label>Name<input class="input" name="name" id="editMemberName" required></label>
        <label><?=$type==='BAC'?'BAC Designation':'TWG Designation'?><?php if($type==='BAC'): ?>
          <select class="input" name="designation" id="editMemberDesignation" required><option>BAC Chairman</option><option>BAC Vice-Chairman</option><option>BAC Member</option><option>Ad-Hoc Chairman</option><option>Ad-Hoc Vice-Chairman</option><option>Ad-Hoc Member</option></select>
        <?php else: ?><input class="input" name="designation" id="editMemberDesignation" required><?php endif; ?></label>
        <?php if($type==='TWG'): ?><label>Area of Expertise<input class="input" name="area_expertise" id="editMemberExpertise" required></label><?php endif; ?>
        <label>Area/Unit<input class="input" name="area_unit" id="editMemberAreaUnit" required readonly></label>
        <label><?=$type==='BAC'?'Code/Tag':'TWG Name'?><?php if($type==='BAC'): ?>
          <select class="input" name="code_tag" id="editMemberCodeTag" required><?php foreach(($teamTagsForDropdown??[]) as $bacTag): ?><option value="<?=e($bacTag)?>"><?=e($bacTag)?></option><?php endforeach; ?></select>
        <?php else: ?><select class="input" name="code_tag" id="editMemberCodeTag" required><option value="">Select TWG Name</option><?php foreach($twgTeams as $twgTeamOption): ?><option value="<?=e($twgTeamOption['team_name'])?>"><?=e($twgTeamOption['team_name'])?></option><?php endforeach; ?></select><?php endif; ?></label><?php if($type==='TWG'): ?><label>BAC Team<select class="input" name="bac_team" id="editMemberBacTeam" required><option value="">Select BAC Team</option><?php foreach($bacTeamOptions as $bacTeamOption): ?><option value="<?=e($bacTeamOption)?>"><?=e($bacTeamOption)?></option><?php endforeach; ?></select></label><?php endif; ?>
        <?php if($type==='BAC'): ?><label>Assigned BAC Member<input class="input" name="assigned_member" id="editMemberAssigned"></label><?php endif; ?>
      </div>
      <div class="bac-form-actions"><button class="btn" type="submit">Save Changes</button><button class="btn secondary" type="button" id="cancelEditMember">Cancel</button></div>
    </form>
    <?php endif; ?>
    <?php if($type==='TWG'): ?>
      <?php if($canManage&&$twgTeams): ?>
      <form method="post" id="deleteTwgTeamsForm" onsubmit="return confirm('Delete the selected TWG Team(s) and all members inside them? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="member_type" value="TWG">
        <input type="hidden" name="action" value="delete_twg_teams">
        <div class="bac-form-actions" style="justify-content:space-between;margin:0 0 12px">
          <label style="display:flex;align-items:center;gap:8px;font-weight:600"><input type="checkbox" id="selectAllTwgTeams"> Select All TWG Teams</label>
          <button class="btn danger" type="submit">Delete Selected TWG Teams</button>
        </div>
        <div class="bac-twg-team-grid">
        <?php foreach($twgTeams as $twgTeam): $twgTeamName=$twgTeam['team_name']; $twgColorMap=['BAC A'=>['border'=>'#60a5fa','body'=>'#eff6ff','header'=>'#1d4ed8'],'BAC B'=>['border'=>'#4ade80','body'=>'#f0fdf4','header'=>'#15803d'],'BAC C'=>['border'=>'#fbbf24','body'=>'#fffbeb','header'=>'#b45309'],'BAC D'=>['border'=>'#a78bfa','body'=>'#f5f3ff','header'=>'#6d28d9'],'BAC E'=>['border'=>'#f472b6','body'=>'#fdf2f8','header'=>'#be185d']]; $twgColor=$twgColorMap[trim((string)($twgTeam['bac_code_tag']??''))]??['border'=>'#cbd5e1','body'=>'#f8fafc','header'=>'#334155']; ?>
          <article class="bac-code-box bac-twg-team-box" style="border:2px solid <?=$twgColor['border']?> !important;background:<?=$twgColor['body']?> !important;">
            <header class="bac-code-box-header" style="background:<?=$twgColor['header']?> !important;"><label style="display:flex;align-items:flex-start;gap:8px"><input type="checkbox" name="team_ids[]" value="<?= (int)$twgTeam['id'] ?>" aria-label="Select <?=e($twgTeamName)?> for deletion"><span><h4 style="font-size:12px"><?=e($twgTeamName)?></h4><p style="font-size:10px">BAC Team: <?=e($twgTeam['bac_code_tag']??'Not assigned')?></p><p style="font-size:10px">Members: <?=count(array_filter($members,static fn($m)=>trim((string)$m['code_tag'])===trim((string)$twgTeamName)))?></p></span></label><button type="button" class="btn secondary edit-twg-team-btn" data-team="<?=e(json_encode($twgTeam,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit Team</button></header>
          <?php $teamMembersForRoles=array_values(array_filter($members,static fn($m)=>trim((string)$m['code_tag'])===trim((string)$twgTeamName))); $hasTwgChairman=(bool)array_filter($teamMembersForRoles,static fn($m)=>trim((string)$m['designation'])==='TWG Chairman'); $hasTwgVice=(bool)array_filter($teamMembersForRoles,static fn($m)=>trim((string)$m['designation'])==='TWG Vice-Chairman'); ?>
           <div class="bac-twg-team-actions">
            <?php if(!$hasTwgChairman): ?><button type="button" class="btn twg-add-role" data-team="<?=e($twgTeamName)?>" data-bac-team="<?=e($twgTeam['bac_code_tag']??'')?>" data-role="TWG Chairman">Add TWG Chairman</button><?php endif; ?>
            <?php if(!$hasTwgVice): ?><button type="button" class="btn twg-add-role" data-team="<?=e($twgTeamName)?>" data-bac-team="<?=e($twgTeam['bac_code_tag']??'')?>" data-role="TWG Vice-Chairman">Add TWG Vice-Chairman</button><?php endif; ?>
            <button type="button" class="btn twg-add-role" data-team="<?=e($twgTeamName)?>" data-bac-team="<?=e($twgTeam['bac_code_tag']??'')?>" data-role="TWG Member">Add TWG Members</button>
          </div>
          <div class="bac-twg-team-members">
            <?php $teamMembers=array_values(array_filter($members,static fn($m)=>trim((string)$m['code_tag'])===trim((string)$twgTeamName))); ?>
            <?php if(!$teamMembers): ?><div class="bac-role-empty">No TWG members added yet</div><?php else: ?>
              <?php foreach($teamMembers as $teamMember): ?><div class="bac-person-box"><div class="bac-person-box-details"><strong><?=e($teamMember['name'])?></strong><span><?=e($teamMember['designation'])?> · <?=e($teamMember['area_unit'])?></span></div><?php if($canManage): ?><div class="bac-person-actions"><?php if($teamMember['designation']!=='TWG Chairman'&&!$hasTwgChairman): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="TWG"><input type="hidden" name="action" value="set_twg_role"><input type="hidden" name="id" value="<?=(int)$teamMember['id']?>"><input type="hidden" name="twg_role" value="TWG Chairman"><button class="btn secondary" type="submit">Make Chairman</button></form><?php endif; ?><?php if($teamMember['designation']!=='TWG Vice-Chairman'&&!$hasTwgVice): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="TWG"><input type="hidden" name="action" value="set_twg_role"><input type="hidden" name="id" value="<?=(int)$teamMember['id']?>"><input type="hidden" name="twg_role" value="TWG Vice-Chairman"><button class="btn secondary" type="submit">Make Vice-Chairman</button></form><?php endif; ?><?php if(in_array($teamMember['designation'],['TWG Chairman','TWG Vice-Chairman'],true)): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="TWG"><input type="hidden" name="action" value="set_twg_role"><input type="hidden" name="id" value="<?=(int)$teamMember['id']?>"><input type="hidden" name="twg_role" value="TWG Member"><button class="btn secondary" type="submit">Set as Member</button></form><?php endif; ?><button class="btn secondary bac-card-edit edit-member-btn" type="button" data-member="<?=e(json_encode($teamMember,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit</button></div><?php endif; ?></div><?php endforeach; ?>
            <?php endif; ?>
          </div>
          </article>
        <?php endforeach; ?>
        </div>
      </form>
      <?php elseif($twgTeams): ?>
        <div class="bac-twg-team-grid">
        <?php foreach($twgTeams as $twgTeam): $twgTeamName=$twgTeam['team_name']; $twgColorMap=['BAC A'=>['border'=>'#60a5fa','body'=>'#eff6ff','header'=>'#1d4ed8'],'BAC B'=>['border'=>'#4ade80','body'=>'#f0fdf4','header'=>'#15803d'],'BAC C'=>['border'=>'#fbbf24','body'=>'#fffbeb','header'=>'#b45309'],'BAC D'=>['border'=>'#a78bfa','body'=>'#f5f3ff','header'=>'#6d28d9'],'BAC E'=>['border'=>'#f472b6','body'=>'#fdf2f8','header'=>'#be185d']]; $twgColor=$twgColorMap[trim((string)($twgTeam['bac_code_tag']??''))]??['border'=>'#cbd5e1','body'=>'#f8fafc','header'=>'#334155']; ?>
          <article class="bac-code-box bac-twg-team-box">
            <header class="bac-code-box-header" style="background:<?=$twgColor['header']?> !important;"><div><h4 style="font-size:12px"><?=e($twgTeamName)?></h4><p style="font-size:10px">BAC Team: <?=e($twgTeam['bac_code_tag']??'Not assigned')?></p><p style="font-size:10px">Members: <?=count(array_filter($members,static fn($m)=>trim((string)$m['code_tag'])===trim((string)$twgTeamName)))?></p></div><?php if($canManage): ?><button type="button" class="btn secondary edit-twg-team-btn" data-team="<?=e(json_encode($twgTeam,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit Team</button><?php endif; ?></header>
            <div class="bac-twg-team-actions">
              <?php if($canManage): ?>
              <?php if(!$hasTwgChairman): ?><button type="button" class="btn twg-add-role" data-team="<?=e($twgTeamName)?>" data-bac-team="<?=e($twgTeam['bac_code_tag']??'')?>" data-role="TWG Chairman">Add TWG Chairman</button><?php endif; ?>
              <?php if(!$hasTwgVice): ?><button type="button" class="btn twg-add-role" data-team="<?=e($twgTeamName)?>" data-bac-team="<?=e($twgTeam['bac_code_tag']??'')?>" data-role="TWG Vice-Chairman">Add TWG Vice-Chairman</button><?php endif; ?>
              <button type="button" class="btn twg-add-role" data-team="<?=e($twgTeamName)?>" data-bac-team="<?=e($twgTeam['bac_code_tag']??'')?>" data-role="TWG Member">Add TWG Members</button>
              <?php endif; ?>
            </div>
            <div class="bac-twg-team-members">
              <?php $teamMembers=array_values(array_filter($members,static fn($m)=>trim((string)$m['code_tag'])===trim((string)$twgTeamName))); ?>
              <?php if(!$teamMembers): ?><div class="bac-role-empty">No TWG members added yet</div><?php else: ?>
                <?php foreach($teamMembers as $teamMember): ?><div class="bac-person-box"><div class="bac-person-box-details"><strong><?=e($teamMember['name'])?></strong><span><?=e($teamMember['designation'])?> · <?=e($teamMember['area_unit'])?></span></div><?php if($canManage): ?><div class="bac-person-actions"><?php if($teamMember['designation']!=='TWG Chairman'&&!$hasTwgChairman): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="TWG"><input type="hidden" name="action" value="set_twg_role"><input type="hidden" name="id" value="<?=(int)$teamMember['id']?>"><input type="hidden" name="twg_role" value="TWG Chairman"><button class="btn secondary" type="submit">Make Chairman</button></form><?php endif; ?><?php if($teamMember['designation']!=='TWG Vice-Chairman'&&!$hasTwgVice): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="TWG"><input type="hidden" name="action" value="set_twg_role"><input type="hidden" name="id" value="<?=(int)$teamMember['id']?>"><input type="hidden" name="twg_role" value="TWG Vice-Chairman"><button class="btn secondary" type="submit">Make Vice-Chairman</button></form><?php endif; ?><?php if(in_array($teamMember['designation'],['TWG Chairman','TWG Vice-Chairman'],true)): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="TWG"><input type="hidden" name="action" value="set_twg_role"><input type="hidden" name="id" value="<?=(int)$teamMember['id']?>"><input type="hidden" name="twg_role" value="TWG Member"><button class="btn secondary" type="submit">Set as Member</button></form><?php endif; ?><button class="btn secondary bac-card-edit edit-member-btn" type="button" data-member="<?=e(json_encode($teamMember,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit</button></div><?php endif; ?></div><?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="bac-role-empty">No TWG Teams have been created yet.</p>
      <?php endif; ?>
    <?php endif; ?>
    <div class="bac-roster-boxes">
      <?php if($type==='BAC'):
        $membersByCode=[];
        foreach($members as $member){$tagKey=mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$member['code_tag'])));if(!isset($membersByCode[$tagKey]))$membersByCode[$tagKey]=['label'=>$member['code_tag'],'members'=>[]];$membersByCode[$tagKey]['members'][]=$member;}
        $tagOrder=['BAC A','BAC B','BAC C','BAC D','BAC E'];
        $requestedTeam=trim((string)($_GET['add_team']??''));
        foreach($tagOrder as $tagLabel):
          $tagKey=mb_strtolower($tagLabel);
          $group=$membersByCode[$tagKey]??['label'=>$tagLabel,'members'=>[]];
          $groupMembers=$group['members'];$total=count($groupMembers);
          // Keep unused teams hidden until the user chooses Add Team for that Code/Tag.
          if($total===0&&$requestedTeam!==$tagLabel) continue;
          $roles=['BAC Chairman'=>[],'BAC Vice-Chairman'=>[],'BAC Member'=>[]];
          foreach($groupMembers as $gm){if(isset($roles[$gm['designation']]))$roles[$gm['designation']][]=$gm;}
          // Roster readiness is based on original BAC members only; Ad-Hoc members
          // count toward the seven-person capacity, but not toward the minimum of five.
          $originalCount=count($roles['BAC Chairman'])+count($roles['BAC Vice-Chairman'])+count($roles['BAC Member']);
          $adHocRoles=['BAC Chairman'=>'Ad-Hoc Chairman','BAC Vice-Chairman'=>'Ad-Hoc Vice-Chairman','BAC Member'=>'Ad-Hoc Member'];
      ?>
      <?php $teamColors=['BAC A'=>['class'=>'bac-team-a','border'=>'#60a5fa','body'=>'#eff6ff','header'=>'#1d4ed8'],'BAC B'=>['class'=>'bac-team-b','border'=>'#4ade80','body'=>'#f0fdf4','header'=>'#15803d'],'BAC C'=>['class'=>'bac-team-c','border'=>'#fbbf24','body'=>'#fffbeb','header'=>'#b45309'],'BAC D'=>['class'=>'bac-team-d','border'=>'#a78bfa','body'=>'#f5f3ff','header'=>'#6d28d9'],'BAC E'=>['class'=>'bac-team-e','border'=>'#f472b6','body'=>'#fdf2f8','header'=>'#be185d']]; $teamColor=$teamColors[$tagLabel]??$teamColors['BAC A']; ?>
      <article class="bac-code-box <?=$teamColor['class']?>" style="border:2px solid <?=$teamColor['border']?> !important;background:<?=$teamColor['body']?> !important;">
        <header class="bac-code-box-header" style="background:<?=$teamColor['header']?> !important;">
          <div><span class="bac-code-eyebrow">BAC COMMITTEE</span><h4><?=e($tagLabel)?></h4><p><?=$total?> of 7 total members</p></div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="bac-code-box-status <?=$originalCount>=5&&$total<=7?'is-valid':'is-incomplete'?>"><?=$originalCount<5?'Needs '.(5-$originalCount).' more':($total<=7?'Roster size '.$total.'/7':'Exceeds limit')?></div>
            <?php if($canManage): ?><form method="post" onsubmit="return confirm('Delete <?=e($tagLabel)?> and all members assigned to this team? This cannot be undone.');" style="margin:0"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="BAC"><input type="hidden" name="action" value="delete_team"><input type="hidden" name="code_tag" value="<?=e($tagLabel)?>"><button class="btn danger" type="submit">Delete Team</button></form><?php endif; ?>
          </div>
        </header>
        <div class="bac-code-box-grid">
          <?php foreach($roles as $roleName=>$roleMembers): ?>
          <section class="bac-role-box">
            <div class="bac-role-box-title"><strong><?=e($roleName)?></strong><span><?=count($roleMembers)?></span></div>
            <?php if(!$roleMembers): ?><div class="bac-role-empty">No original BAC member assigned</div><?php endif; ?>
            <?php foreach($roleMembers as $person):
              $assignedAdHocs=array_values(array_filter($groupMembers,static function($candidate)use($person,$adHocRoles,$roleName){return $candidate['designation']===($adHocRoles[$roleName]??'')&&mb_strtolower(trim((string)($candidate['assigned_member']??'')))===mb_strtolower(trim((string)$person['name']));}));
            ?>
            <div class="bac-original-member-box">
              <div class="bac-person-box">
                <div class="bac-person-box-details"><strong><?=e($person['name'])?></strong><span><?=e($person['area_unit'])?></span></div>
                <?php if($canManage): ?><button class="btn secondary bac-card-edit edit-member-btn" type="button" data-member="<?=e(json_encode($person,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit</button><form method="post" onsubmit="return confirm('Delete <?=e(addslashes($person['name']))?>?');" style="display:inline;margin:0"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="BAC"><input type="hidden" name="action" value="delete_member"><input type="hidden" name="id" value="<?=(int)$person['id']?>"><button class="btn danger bac-card-edit" type="submit">Delete</button></form><?php endif; ?>
              </div>
              <div class="bac-assigned-adhocs">
                <div class="bac-assigned-adhocs-heading">Assigned Ad-Hoc Members <span><?=count($assignedAdHocs)?></span></div>
                <?php if(!$assignedAdHocs): ?><div class="bac-role-empty">No Ad-Hoc assigned</div><?php endif; ?>
                <?php foreach($assignedAdHocs as $adhoc): ?>
                <div class="bac-adhoc-person">
                  <div class="bac-person-box-details"><strong><?=e($adhoc['name'])?></strong><span><?=e($adhoc['area_unit'])?></span></div>
                  <?php if($canManage): ?><button class="btn secondary bac-card-edit edit-member-btn" type="button" data-member="<?=e(json_encode($adhoc,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit</button><form method="post" onsubmit="return confirm('Delete <?=e(addslashes($adhoc['name']))?>?');" style="display:inline;margin:0"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="member_type" value="BAC"><input type="hidden" name="action" value="delete_member"><input type="hidden" name="id" value="<?=(int)$adhoc['id']?>"><button class="btn danger bac-card-edit" type="submit">Delete</button></form><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if($canManage&&$total<7&&count($assignedAdHocs)===0): ?><button class="bac-add-adhoc" type="button" data-code-tag="<?=e($tagLabel)?>" data-designation="<?=e($adHocRoles[$roleName])?>" data-assigned-name="<?=e($person['name'])?>" data-area-unit="<?=e($person['area_unit']??'')?>">+ Add <?=e($adHocRoles[$roleName])?></button><?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if($canManage&&$total<7&&(($roleName==='BAC Member'&&count($roleMembers)<5)||($roleName!=='BAC Member'&&count($roleMembers)===0))): ?><button class="bac-add-original" type="button" data-code-tag="<?=e($tagLabel)?>" data-designation="<?=e($roleName)?>">+ Add <?=e($roleName)?></button><?php endif; ?>
          </section>
          <?php endforeach; ?>
        </div>
        <footer class="bac-code-box-footer"><span><?=count($roles['BAC Chairman'])?> Chairman · <?=count($roles['BAC Vice-Chairman'])?> Vice-Chairman · <?=count($roles['BAC Member'])?> BAC Members</span>
          <?php if($canManage&&$originalCount<5): ?><span class="bac-roster-guidance">Add original BAC members until this roster has at least 5 original members.</span><?php endif; ?>
        </footer>
      </article>
      <?php endforeach; ?>
      <?php elseif($type!=='TWG'): ?>
      <div class="table-wrap bac-members-table-wrap"><table class="table bac-members-table">
        <thead><tr><th>Name</th><th><?=$type==='BAC'?'BAC Designation':'TWG Designation'?></th><?php if($type==='TWG'): ?><th>Area of Expertise</th><?php endif; ?><th>Area/Unit</th><th><?=$type==='BAC'?'Code/Tag':'TWG Name'?></th><?php if($type==='TWG'): ?><th>BAC Team</th><?php endif; ?><?php if($canManage): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
        <?php foreach($members as $member): ?><tr><td><?=e($member['name'])?></td><td><?=e($member['designation'])?></td><?php if($type==='TWG'): ?><td><?=e($member['area_expertise']??'')?></td><?php endif; ?><td><?=e($member['area_unit'])?></td><td><?=e($member['code_tag'])?></td><?php if($type==='TWG'): ?><td><?=e($member['bac_team']??'')?></td><?php endif; ?><?php if($canManage): ?><td><button class="btn secondary edit-member-btn" type="button" data-member="<?=e(json_encode($member,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>">Edit</button></td><?php endif; ?></tr><?php endforeach; ?>
        <?php if(!$members): ?><tr><td colspan="<?=($canManage?5:4)?>" class="empty">No members have been added yet.</td></tr><?php endif; ?>
        </tbody></table></div>
      <?php endif; ?>
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
 .bac-entry-table .bac-area-unit-input{background:#e9ecef!important;color:#6b7280;cursor:not-allowed;opacity:1}.bac-entry-table .bac-locked-field{background:#e9ecef!important;color:#6b7280!important;cursor:not-allowed;opacity:1;pointer-events:none}
.bac-person-autocomplete{position:relative;width:100%;min-width:0}
.bac-person-suggestions{position:fixed;left:0;top:0;width:300px;max-width:min(420px,calc(100vw - 32px));z-index:100000;background:#fff;border:1px solid #94a3b8;border-radius:6px;box-shadow:0 10px 28px rgba(15,23,42,.24);max-height:260px;overflow-y:auto;display:none}
.bac-person-suggestion{display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-bottom:1px solid #e5e7eb;background:#fff;text-align:left;cursor:pointer;font-size:12px;white-space:normal;overflow-wrap:anywhere}
.bac-person-suggestion:hover,.bac-person-suggestion:focus{background:#eef5ff}
.bac-person-suggestion small{display:block;color:#6b7280;margin-top:3px;font-size:11px}

.bac-entry-table .bac-entry-action{white-space:nowrap}.bac-entry-table .bac-row-remove{padding:7px 10px;font-size:12px;background:#dc3545;color:#fff;border-color:#dc3545}
.bac-form-actions{display:flex;gap:8px;margin-top:16px}
.bac-members-table-wrap{width:100%;overflow-x:auto}
.bac-members-table{width:100%;border-collapse:collapse}
.bac-twg-team-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}.bac-twg-team-box .bac-code-box-header h4{font-size:12px!important}.bac-twg-team-actions{display:flex;flex-direction:column;gap:8px;padding:14px}.bac-twg-team-actions .btn{width:100%;white-space:normal}.bac-twg-team-members{padding:0 14px 12px}.bac-twg-team-box .bac-code-box-header{min-height:88px}.bac-roster-boxes{display:grid;grid-template-columns:1fr;gap:18px}
.bac-original-member-box{border:1px solid #dbeafe;background:#f8fbff;border-radius:9px;padding:9px;margin-top:10px}
.bac-assigned-adhocs{margin:7px 0 0 13px;padding:9px 0 0 11px;border-left:2px solid #bfdbfe}
.bac-assigned-adhocs-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#475569;font-weight:700}
.bac-assigned-adhocs-heading span{background:#dbeafe;color:#1e40af;border-radius:12px;padding:2px 7px}
.bac-adhoc-person{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #eaf0f8}
.bac-add-original{display:block;width:100%;margin-top:10px;padding:10px;border:1px dashed #94a3b8;border-radius:7px;background:#f8fafc;color:#334155;font-size:11px;font-weight:700;cursor:pointer}
.bac-add-original:hover{background:#eff6ff;border-color:#3b82f6;color:#1d4ed8}
.bac-roster-guidance{font-size:11px;color:#92400e}

.bac-code-box{border:1px solid #dbe3ec;border-radius:14px;background:#f8fafc;overflow:hidden;box-shadow:0 4px 14px rgba(16,42,67,.05)}
.bac-code-box.bac-team-a{border-color:#93c5fd;background:#eff6ff}.bac-code-box.bac-team-a>.bac-code-box-header{background:#1d4ed8}
.bac-code-box.bac-team-b{border-color:#86efac;background:#f0fdf4}.bac-code-box.bac-team-b>.bac-code-box-header{background:#15803d}
.bac-code-box.bac-team-c{border-color:#fcd34d;background:#fffbeb}.bac-code-box.bac-team-c>.bac-code-box-header{background:#b45309}
.bac-code-box.bac-team-d{border-color:#c4b5fd;background:#f5f3ff}.bac-code-box.bac-team-d>.bac-code-box-header{background:#6d28d9}
.bac-code-box.bac-team-e{border-color:#f9a8d4;background:#fdf2f8}.bac-code-box.bac-team-e>.bac-code-box-header{background:#be185d}
.bac-code-box-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;background:#102a43;color:#fff}
.bac-code-box-header h4{margin:3px 0;font-size:19px;color:#fff}
.bac-code-box-header p{margin:0;color:#dbeafe;font-size:12px}
.bac-code-eyebrow{font-size:10px;letter-spacing:.12em;font-weight:700;color:#bfdbfe}
.bac-code-box-status{padding:7px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;background:#dcfce7;color:#166534}
.bac-code-box-status.is-incomplete{background:#fef3c7;color:#92400e}
.bac-code-box-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px}
.bac-role-box{min-width:0;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:12px}
.bac-role-box-title{display:flex;justify-content:space-between;align-items:center;gap:8px;padding-bottom:10px;border-bottom:1px solid #edf2f7;color:#334155;font-size:12px}
.bac-role-box-title span{display:grid;place-items:center;min-width:24px;height:24px;padding:0 5px;border-radius:20px;background:#eaf1f8;color:#102a43;font-weight:700;box-sizing:border-box}
.bac-person-box{display:flex;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid #f1f5f9}
.bac-person-box:last-of-type{border-bottom:0}
.bac-person-box input[type=checkbox]{flex:0 0 auto}
.bac-person-box-details{display:flex;flex-direction:column;gap:3px;min-width:0;flex:1}
.bac-person-box-details strong{font-size:12px;color:#102a43;overflow-wrap:anywhere}
.bac-person-box-details span,.bac-person-box-details small{font-size:11px;color:#64748b;overflow-wrap:anywhere}
.bac-card-edit{font-size:11px;padding:5px 8px;flex:0 0 auto}
.bac-role-empty{padding:12px 0;color:#94a3b8;font-size:11px;font-style:italic}
.bac-add-adhoc{display:inline-flex;align-items:center;justify-content:center;margin-top:8px;width:100%;padding:8px 9px;border:1px dashed #94a3b8;border-radius:7px;background:#f8fafc;color:#334155;font-size:11px;font-weight:700;cursor:pointer}
.bac-add-adhoc:hover{background:#eff6ff;border-color:#3b82f6;color:#1d4ed8}
.bac-code-box-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;border-top:1px solid #e2e8f0;background:#fff;color:#64748b;font-size:11px}
.bac-code-box-footer .btn{font-size:11px;padding:7px 10px}
.bac-roster-empty{padding:36px 20px;text-align:center;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b}
.bac-roster-empty strong{color:#102a43}
.bac-roster-empty p{font-size:12px}
@media(max-width:1000px){.bac-code-box-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bac-twg-team-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.bac-twg-team-grid{grid-template-columns:1fr}.bac-code-box-grid{grid-template-columns:1fr;padding:10px}.bac-code-box-header{padding:14px}.bac-code-box-footer{align-items:flex-start;flex-direction:column}.bac-code-box-status{white-space:normal}}

.bac-person-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:5px}.bac-person-actions form{margin:0}.bac-person-actions .btn{font-size:10px;padding:5px 7px}
 .bac-members-table th,.bac-members-table td{padding:11px 12px;text-align:left;border-bottom:1px solid #e5e7eb}
.bac-members-table th{background:#f8fafc;color:#334155;font-size:13px;white-space:nowrap}
.bac-members-table td{font-size:13px}.bac-bulk-actions{display:flex;align-items:center;gap:12px;margin:14px 0}.bac-bulk-actions span{font-size:12px;color:#64748b}.bac-edit-form{margin:14px 0 20px;padding:16px;border:1px solid #dbe3ec;border-radius:10px;background:#f8fafc}.bac-edit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.bac-edit-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:600}.bac-edit-form[hidden]{display:none}
.bac-page-heading p{margin:0;color:#6b7280;font-size:13px;line-height:1.6}
@media(max-width:700px){.content:has(.bac-page-panel){padding:14px!important;min-height:calc(100vh - 150px)}.bac-page-panel{min-height:calc(100vh - 190px);padding:17px}.bac-page-heading h2{font-size:18px}.bac-page-icon{width:44px;height:44px;flex-basis:44px}.bac-member-fields{grid-template-columns:1fr}.bac-list-toolbar{align-items:flex-start;flex-wrap:wrap}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const showSecretariatMember=document.getElementById('showAddSecretariatMember');
  const addSecretariatMemberForm=document.getElementById('addSecretariatMemberForm');
  const cancelSecretariatMember=document.getElementById('cancelAddSecretariatMember');
  if(showSecretariatMember&&addSecretariatMemberForm)showSecretariatMember.addEventListener('click',function(){addSecretariatMemberForm.hidden=false;const nameInput=addSecretariatMemberForm.querySelector('[name="name"]');if(nameInput)nameInput.focus();});
  if(cancelSecretariatMember&&addSecretariatMemberForm)cancelSecretariatMember.addEventListener('click',function(){addSecretariatMemberForm.reset();addSecretariatMemberForm.hidden=true;});
  const selectAllSecretariatMembers=document.getElementById('selectAllSecretariatMembers');
  const secretariatMemberCheckboxes=Array.from(document.querySelectorAll('#deleteSecretariatMembersForm input[name="selected_ids[]"]'));
  if(selectAllSecretariatMembers){selectAllSecretariatMembers.addEventListener('change',function(){secretariatMemberCheckboxes.forEach(function(box){box.checked=selectAllSecretariatMembers.checked;});});secretariatMemberCheckboxes.forEach(function(box){box.addEventListener('change',function(){selectAllSecretariatMembers.checked=secretariatMemberCheckboxes.length>0&&secretariatMemberCheckboxes.every(function(box){return box.checked;});});});}

  const twgTeamDeleteForm=document.getElementById('deleteTwgTeamsForm');
  if(twgTeamDeleteForm){
    const selectAllTwgTeams=document.getElementById('selectAllTwgTeams');
    const twgTeamCheckboxes=Array.from(twgTeamDeleteForm.querySelectorAll('input[name="team_ids[]"]'));
    if(selectAllTwgTeams){
      selectAllTwgTeams.addEventListener('change',function(){twgTeamCheckboxes.forEach(function(box){box.checked=selectAllTwgTeams.checked;});});
      twgTeamCheckboxes.forEach(function(box){box.addEventListener('change',function(){selectAllTwgTeams.checked=twgTeamCheckboxes.length>0&&twgTeamCheckboxes.every(function(item){return item.checked;});});});
    }
    twgTeamDeleteForm.addEventListener('submit',function(event){
      if(!twgTeamCheckboxes.some(function(box){return box.checked;})){event.preventDefault();alert('Select at least one TWG Team to delete.');}
    });
  }
  const form=document.getElementById('addMemberForm');
  const showTwgTeam=document.getElementById('showAddTwgTeam');
  const addTwgTeamForm=document.getElementById('addTwgTeamForm');
  const cancelTwgTeam=document.getElementById('cancelAddTwgTeam');
  if(showTwgTeam&&addTwgTeamForm)showTwgTeam.addEventListener('click',function(){addTwgTeamForm.hidden=false;const input=addTwgTeamForm.querySelector('[name="team_name"]');if(input)input.focus();});
  if(cancelTwgTeam&&addTwgTeamForm)cancelTwgTeam.addEventListener('click',function(){addTwgTeamForm.reset();addTwgTeamForm.hidden=true;});
  document.querySelectorAll('.twg-add-role').forEach(function(button){button.addEventListener('click',function(){
    if(!form||!rows)return;
    form.hidden=false;
    const row=rows.querySelector('.bac-entry-row');if(!row)return;
    const designation=row.querySelector('[name="designation[]"]');
    const tag=row.querySelector('select[name="code_tag[]"]');
     const hiddenTag=row.querySelector('input[type="hidden"][name="code_tag[]"]');
    const area=row.querySelector('[name="area_unit[]"]');
    const expertise=row.querySelector('[name="area_expertise[]"]');
    const bacTeamDisplay=row.querySelector('[name="bac_team_display[]"]');
    const bacTeamHidden=row.querySelector('input[type="hidden"][name="bac_team[]"]');
    if(designation)designation.value=button.dataset.role||'TWG Member';
    if(tag)tag.value=button.dataset.team||'';
     if(hiddenTag)hiddenTag.value=button.dataset.team||'';
    if(bacTeamDisplay)bacTeamDisplay.value=button.dataset.bacTeam||'';
    if(bacTeamHidden)bacTeamHidden.value=button.dataset.bacTeam||'';
    if(area)area.value='';
    if(expertise)expertise.value='';
    form.scrollIntoView({behavior:'smooth',block:'start'});
    const name=row.querySelector('.bac-person-name');if(name)name.focus();
  });});
  const show=document.getElementById('showAddMember');
  const cancel=document.getElementById('cancelAddMember');
  const rows=document.getElementById('bacEntryRows');
  const save=document.getElementById('saveMemberItems');
  function updateSaveLabel(){
    if(save&&rows)save.textContent=rows.querySelectorAll('.bac-entry-row').length>1?'Save Members':'Save Member';
  }
  const bulkSelectAll=document.getElementById('selectAllMembers');
  if(bulkSelectAll)bulkSelectAll.addEventListener('change',function(){document.querySelectorAll('.member-select').forEach(function(box){box.checked=bulkSelectAll.checked;});});
  const editTwgTeamForm=document.getElementById('editTwgTeamForm');
  document.querySelectorAll('.edit-twg-team-btn').forEach(function(button){
    button.addEventListener('click',function(){
      if(!editTwgTeamForm)return;
      let team={};try{team=JSON.parse(button.dataset.team||'{}');}catch(e){}
      document.getElementById('editTwgTeamId').value=team.id||'';
      document.getElementById('editTwgTeamName').value=team.team_name||'';
      document.getElementById('editTwgTeamBacTeam').value=team.bac_code_tag||'';
      editTwgTeamForm.hidden=false;
      editTwgTeamForm.scrollIntoView({behavior:'smooth',block:'nearest'});
      document.getElementById('editTwgTeamName').focus();
    });
  });
  const cancelEditTwgTeam=document.getElementById('cancelEditTwgTeam');
  if(cancelEditTwgTeam)cancelEditTwgTeam.addEventListener('click',function(){editTwgTeamForm.reset();editTwgTeamForm.hidden=true;});
  const editForm=document.getElementById('editMemberForm');
  document.querySelectorAll('.edit-member-btn').forEach(function(button){
    button.addEventListener('click',function(){
      const m=JSON.parse(button.dataset.member||'{}');
      if(!editForm)return;
      const fields={id:'id',name:'name',designation:'designation',area_expertise:'expertise',area_unit:'areaUnit',code_tag:'codeTag',assigned_member:'assigned'};
      const bacTeamEl=document.getElementById('editMemberBacTeam');if(bacTeamEl)bacTeamEl.value=m.bac_team||'';
      Object.keys(fields).forEach(function(key){const el=document.getElementById('editMember'+fields[key].charAt(0).toUpperCase()+fields[key].slice(1));if(el)el.value=m[key]||'';});
      editForm.hidden=false;
      editForm.scrollIntoView({behavior:'smooth',block:'nearest'});
    });
  });
  const cancelEdit=document.getElementById('cancelEditMember');
  if(cancelEdit)cancelEdit.addEventListener('click',function(){editForm.hidden=true;});
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
  const savedBacRoster=<?=json_encode($bacRoster,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
  function normalizeBacName(value){return String(value||'').trim().replace(/\\s+/g,' ').toLocaleLowerCase();}
  function refreshAssignedMembers(){
    if(!rows)return;
    rows.querySelectorAll('.bac-entry-row').forEach(function(row){
      const designation=row.querySelector('select[name="designation[]"]');
      const tag=row.querySelector('select[name="code_tag[]"]');
      const assigned=row.querySelector('.bac-assigned-member');
      if(!designation||!tag||!assigned)return;
      const isAdHoc=['Ad-Hoc Member','Ad-Hoc Vice-Chairman','Ad-Hoc Chairman'].includes(designation.value);
      const selected=assigned.value;
      assigned.innerHTML='';
      const placeholder=document.createElement('option');
      placeholder.value='';
      placeholder.textContent=isAdHoc?'Select available BAC personnel':'Only required for Ad-Hoc Member';
      assigned.appendChild(placeholder);
      const occupied=new Set();
      // Exclude saved Ad-Hoc members and assignees, but preserve the current row's selected assignee.
      savedBacRoster.forEach(function(person){
        if(['Ad-Hoc Chairman','Ad-Hoc Vice-Chairman','Ad-Hoc Member'].includes(person.designation))occupied.add(normalizeBacName(person.name));
        if(person.assigned_member)occupied.add(normalizeBacName(person.assigned_member));
      });
      rows.querySelectorAll('.bac-entry-row').forEach(function(memberRow){
        const role=memberRow.querySelector('select[name="designation[]"]');
        const nameInput=memberRow.querySelector('.bac-person-name');
        const assignedInput=memberRow.querySelector('.bac-assigned-member');
        const name=nameInput?nameInput.value.trim():'';
        if(memberRow!==row&&role&&name&&['Ad-Hoc Chairman','Ad-Hoc Vice-Chairman','Ad-Hoc Member'].includes(role.value))occupied.add(normalizeBacName(name));
        if(memberRow!==row&&assignedInput&&assignedInput.value)occupied.add(normalizeBacName(assignedInput.value));
      });
      const requiredAssigneeDesignation={
        'Ad-Hoc Chairman':'BAC Chairman',
        'Ad-Hoc Vice-Chairman':'BAC Vice-Chairman',
        'Ad-Hoc Member':'BAC Member'
      }[designation.value]||'';
      const selectedTag=String(tag.value||'').trim().replace(/\\s+/g,' ').toLocaleLowerCase();
      const available=savedBacRoster.filter(function(person){
        const name=String(person.name||'').trim();
        const personTag=String(person.code_tag||'').trim().replace(/\\s+/g,' ').toLocaleLowerCase();
        return name&&personTag===selectedTag&&person.designation===requiredAssigneeDesignation&&(!occupied.has(normalizeBacName(name))||normalizeBacName(name)===normalizeBacName(selected));
      });
      available.forEach(function(person){
        const option=document.createElement('option');
        option.value=person.name;
        option.textContent=person.name+' — '+person.designation+' ('+person.code_tag+')';
        option.dataset.codeTag=person.code_tag||'';
        assigned.appendChild(option);
      });
      assigned.disabled=!isAdHoc;
      assigned.required=isAdHoc;
      if(isAdHoc&&Array.from(assigned.options).some(function(option){return option.value===selected;}))assigned.value=selected;
      else assigned.value='';
      // Set Code/Tag only after restoring the selected assignee, using that roster member's stored tag.
      if(isAdHoc){
        // Keep the select enabled so its value is submitted with the form.
        // Any manual change is overwritten by refreshAssignedMembers() using the selected member's stored tag.
        tag.disabled=false;
        tag.style.pointerEvents='none';
        tag.tabIndex=-1;
        const chosen=assigned.options[assigned.selectedIndex];
        tag.value=chosen&&chosen.value?(chosen.dataset.codeTag||''):'';
      }else{
        tag.disabled=false;
        tag.style.pointerEvents='';
        tag.tabIndex=0;
      }
    });
  }
  if(rows){
    rows.addEventListener('change',function(event){
      if(event.target.matches('select[name="designation[]"],select[name="code_tag[]"],select[name="assigned_member[]"]')){
        if(event.target.matches('select[name="assigned_member[]"]')){
          const row=event.target.closest('.bac-entry-row');
          const tag=row&&row.querySelector('select[name="code_tag[]"]');
          const chosen=event.target.options[event.target.selectedIndex];
          if(tag&&chosen&&chosen.dataset.codeTag)tag.value=chosen.dataset.codeTag;
        }
        refreshAssignedMembers();
      }
    });
    rows.addEventListener('input',function(event){if(event.target.classList.contains('bac-person-name'))refreshAssignedMembers();});
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
    refreshAssignedMembers();
    const input=row.querySelector('input');if(input)input.focus();
  }
  document.querySelectorAll('.bac-add-adhoc,.bac-add-original').forEach(function(button){
    button.addEventListener('click',function(){
      if(!form||!rows)return;
      form.hidden=false;
      const row=rows.querySelector('.bac-entry-row');
      if(!row)return;
      const designation=row.querySelector('select[name="designation[]"]');
      const tag=row.querySelector('select[name="code_tag[]"]');
      const name=row.querySelector('.bac-person-name');
      const area=row.querySelector('.bac-area-unit-input');
      const assigned=row.querySelector('.bac-assigned-member');
      if(name)name.value='';
      if(area)area.value='';
      [designation,tag,assigned,area].forEach(function(field){if(field){field.classList.remove('bac-locked-field');field.style.pointerEvents='';field.tabIndex=0;}});
      const isAdHoc=button.classList.contains('bac-add-adhoc');
      const targetTag=button.dataset.codeTag||'BAC A';
      if(designation)designation.value=button.dataset.designation||'BAC Member';
      if(tag){
        // Ensure the selected team's option exists even when the dropdown was built
        // from a filtered list of currently available teams.
        let tagOption=Array.from(tag.options).find(function(option){return option.value===targetTag;});
        if(!tagOption){tagOption=document.createElement('option');tagOption.value=targetTag;tagOption.textContent=targetTag;tag.appendChild(tagOption);}
        tag.value=targetTag;
      }
      refreshAssignedMembers();
      if(isAdHoc){
        if(area)area.value=button.dataset.areaUnit||'';
        if(assigned&&button.dataset.assignedName){
          const target=button.dataset.assignedName;
          const option=Array.from(assigned.options).find(function(o){return o.value===target;});
          if(option){assigned.value=target;assigned.disabled=false;}
        }
        // Re-apply the tag after refreshing dependent controls, and keep it selected.
        if(tag){tag.value=targetTag;tag.dispatchEvent(new Event('change',{bubbles:true}));}
        [designation,area,assigned,tag].forEach(function(field){if(field){field.classList.add('bac-locked-field');field.style.pointerEvents='none';field.tabIndex=-1;}});
      }
      form.scrollIntoView({behavior:'smooth',block:'start'});
      if(name)name.focus();
    });
  });
  if(cancel&&form)cancel.addEventListener('click',function(){
    form.reset();
    if(rows){
      const entryRows=Array.from(rows.querySelectorAll('.bac-entry-row'));
      entryRows.slice(1).forEach(function(row){row.remove();});
      const firstRow=rows.querySelector('.bac-entry-row');
      if(firstRow){
        firstRow.querySelectorAll('input').forEach(function(input){input.value='';});
        firstRow.querySelectorAll('select').forEach(function(select){select.selectedIndex=0;});
        firstRow.querySelectorAll('.bac-person-suggestions').forEach(function(list){list.innerHTML='';list.style.display='none';});
      }
    }
    form.hidden=true;
    if(show)show.hidden=false;
    updateSaveLabel();
    refreshAssignedMembers();
  });
  updateSaveLabel();
  refreshAssignedMembers();
});
</script>
<?php pageEnd(); ?>
