    </div>
    <div class="actions"><button class="btn" type="submit">Save PPMP Items</button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php
$h=($allPpmpRows[0]??$rows[0]??[]);
$ppmpNo=$h['ppmp_no']??'';
$person=$h['requested_by']??'';
$preparedPos=$h['prepared_position']??'End-User or Implementing Unit';
$preparedSignature='';
if($person!=='' && !empty($h['area_id'])){
  $stPreparedSignature=$pdo->prepare('SELECT electronic_signature FROM area_personnel WHERE area_id=? AND name=? LIMIT 1');
  $stPreparedSignature->execute([(int)$h['area_id'],$person]);
  $preparedSignature=trim((string)($stPreparedSignature->fetchColumn()??''));
}
// Resolve Submitted By directly from the Division/Department that owns
// the PPMP's Area/Unit. The Area/Unit's parent Division is authoritative;
// do not require ppmp_supervisor_enabled just to display the Division Head
// signature on the PPMP Form.
$submitted='';
$submittedPos='';
$submittedSignature='';
if(!empty($h['area_id'])){
  $stSubmittedDivision=$pdo->prepare('SELECT d.division_head,d.head_position_designation,d.electronic_signature
    FROM areas a JOIN divisions d ON d.id=a.division_id WHERE a.id=? LIMIT 1');
  $stSubmittedDivision->execute([(int)$h['area_id']]);
  $submittedDivision=$stSubmittedDivision->fetch();
  if($submittedDivision){
    $submitted=trim((string)($submittedDivision['division_head']??''));
    $submittedPos=trim((string)($submittedDivision['head_position_designation']??''));
    $submittedSignature=trim((string)($submittedDivision['electronic_signature']??''));
  }
}

// Convert stored signature files into data URLs for the print document. This
// avoids browser path/base-directory problems and guarantees the saved
// Division Head signature is embedded directly in the printable PPMP.
function ppmpSignatureSrc($storedPath): string{
  $storedPath=trim((string)$storedPath);
  if($storedPath==='') return '';
  if(stripos($storedPath,'data:image/')===0) return $storedPath;
  $relative=ltrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$storedPath),DIRECTORY_SEPARATOR);
  $fullPath=__DIR__.DIRECTORY_SEPARATOR.$relative;
  if(!is_file($fullPath) || !is_readable($fullPath)) return '';
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($fullPath);
  if(!in_array($mime,['image/png','image/jpeg'],true)) return '';
  $binary=file_get_contents($fullPath);
  if($binary===false || $binary==='') return '';
  return 'data:'.$mime.';base64,'.base64_encode($binary);
}
$submittedSignature=ppmpSignatureSrc($submittedSignature);
// If the PPMP was created by the Division/Department Head, also use the
// Division Head signature for Prepared By when no Area/Unit personnel
// signature is configured for that person.
if($preparedSignature==='' && $submitted!=='' && strcasecmp(trim((string)$person),$submitted)===0){
  $preparedSignature=$submittedSignature;
}
// Budget signatory comes from the Area/Unit master list.
// IMPORTANT: do not inspect the selected PPMP Area/Unit personnel and do not
// look for the word "Budget" in a person's name/position. Instead, locate the
// Area/Unit record whose own Area/Unit name identifies it as Budget, then pull
// the person(s) attached to that Area/Unit.
$budgetName='';
$budgetPos='';
$budgetAreaId=0;

// Match Budget, Budget Unit, Budget Section, Budget Office, etc.