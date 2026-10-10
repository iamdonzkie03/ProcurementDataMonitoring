<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();

try {
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_company_name VARCHAR(255) NOT NULL,
  address TEXT NULL,
  owner VARCHAR(150) NULL,
  authorized_representative VARCHAR(150) NULL,
  business_type VARCHAR(120) NULL,
  philgeps_certificate_path VARCHAR(500) NULL,
  philgeps_valid_until DATE NULL,
  business_permit_path VARCHAR(500) NULL,
  business_permit_valid_until DATE NULL,
  tax_clearance_certificate_path VARCHAR(500) NULL,
  tax_clearance_valid_until DATE NULL,
  pcab_license_path VARCHAR(500) NULL,
  pcab_license_valid_until DATE NULL,
  registration_type ENUM('SEC','DTI','CDA') NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_supplier_name (supplier_company_name),
  INDEX idx_supplier_registration (registration_type),
  CONSTRAINT fk_supplier_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_supplier_updated_by FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB
SQL);
} catch(Throwable $e) {}

// Keep duplicate prevention in application logic (including existing databases).
// The exact identity is Supplier/Company Name + Address + Owner.
try {
  $indexRows=$pdo->query("SHOW INDEX FROM suppliers")->fetchAll(PDO::FETCH_ASSOC);
  $uniqueIndexes=[];
  foreach($indexRows as $indexRow){
    $keyName=(string)($indexRow['Key_name']??'');
    if($keyName==='' || strtoupper($keyName)==='PRIMARY' || (int)($indexRow['Non_unique']??1)!==0) continue;
    $uniqueIndexes[$keyName][(int)($indexRow['Seq_in_index']??1)]=(string)($indexRow['Column_name']??'');
  }
  $identityColumns=['supplier_company_name','address','owner'];
  foreach($uniqueIndexes as $keyName=>$columnsByOrder){
    $columns=array_values($columnsByOrder);
    if($columns && count(array_diff($columns,$identityColumns))===0){
      $quotedName=chr(96).str_replace(chr(96),chr(96).chr(96),$keyName).chr(96);
      $pdo->exec("ALTER TABLE suppliers DROP INDEX $quotedName");
    }
  }
} catch(Throwable $e) {}

try {
  $pdo->exec("ALTER TABLE suppliers ADD COLUMN pcab_license_path VARCHAR(500) NULL AFTER tax_clearance_valid_until");
} catch(Throwable $e) {}
try {
  $pdo->exec("ALTER TABLE suppliers ADD COLUMN pcab_license_valid_until DATE NULL AFTER pcab_license_path");
} catch(Throwable $e) {}

$isEditor=hasRole(['Administrator','Editor']);
$uploadDir=__DIR__.'/uploads/suppliers';
$uploadWeb='uploads/suppliers';
if(!is_dir($uploadDir)) @mkdir($uploadDir,0775,true);

function supplierDate(?string $v): ?string {
  $v=trim((string)$v);
  if($v==='') return null;
  $d=DateTime::createFromFormat('Y-m-d',$v);
  return ($d && $d->format('Y-m-d')===$v) ? $v : null;
}
function supplierDisplayDate(?string $v): string {
  $v=trim((string)$v);
  if($v==='') return 'No validity date';
  $d=DateTime::createFromFormat('Y-m-d',$v);
  return ($d && $d->format('Y-m-d')===$v) ? $d->format('F d, Y') : $v;
}
function supplierFolderPart(string $value): string {
  // Replace only characters that are invalid in Windows directory names.
  $value=trim($value);
  $value=preg_replace('~[\\\\/:*?"<>|]+~u','-',$value);
  $value=preg_replace('~\\s+~u',' ',$value);
  $value=trim((string)$value, " .- \t\n\r\0\x0B");
  return function_exists('mb_substr') ? mb_substr($value,0,100,'UTF-8') : substr($value,0,100);
}
function supplierFolderName(string $companyName,string $address,string $owner): string {
  $parts=[
    supplierFolderPart($companyName),
    supplierFolderPart($address),
    supplierFolderPart($owner)
  ];
  if($parts[0]==='') throw new RuntimeException('Enter a Supplier/Company Name before uploading documents.');
  return implode(' - ',$parts);
}
function supplierUpload(string $field,string $uploadDir,string $uploadWeb,string $companyName,string $address,string $owner,?string $oldPath=null,int $index=0): ?string {
  if(empty($_FILES[$field]) || !isset($_FILES[$field]['error'][$index]) || $_FILES[$field]['error'][$index]===UPLOAD_ERR_NO_FILE) return $oldPath;
  if($_FILES[$field]['error'][$index]!==UPLOAD_ERR_OK) throw new RuntimeException('The uploaded file could not be processed.');
  if((int)($_FILES[$field]['size'][$index]??0)>8*1024*1024) throw new RuntimeException('Each supplier certificate/permit file must not exceed 8 MB.');
  $original=(string)($_FILES[$field]['name'][$index]??'');
  $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
  if(!in_array($ext,['pdf'],true)) throw new RuntimeException('Only PDF files are allowed.');
  $safe=preg_replace('/[^a-zA-Z0-9_-]/','-',pathinfo($original,PATHINFO_FILENAME));
  $name=$safe.'-'.bin2hex(random_bytes(8)).'.'.$ext;
  $folder=supplierFolderName($companyName,$address,$owner);
  $companyDir=rtrim($uploadDir,'/\\').DIRECTORY_SEPARATOR.$folder;
  if(!is_dir($companyDir) && !@mkdir($companyDir,0775,true) && !is_dir($companyDir)) throw new RuntimeException('Unable to create the supplier folder: '.$folder);
  $target=$companyDir.DIRECTORY_SEPARATOR.$name;
  if(!move_uploaded_file($_FILES[$field]['tmp_name'][$index],$target)) throw new RuntimeException('Unable to save the uploaded file.');
  if($oldPath && $oldPath!==$uploadWeb.'/'.$folder.'/'.$name) supplierRemoveFile($oldPath,$uploadDir);
  return $uploadWeb.'/'.$folder.'/'.$name;
}
function supplierRemoveFile(?string $path,string $uploadDir): bool {
  $path=trim((string)$path);
  if($path==='') return true;

  // Accept the required public/uploads/suppliers/<company>/ layout and legacy public/suppliers/<company>/ paths.
  $relative=ltrim(str_replace('\\\\','/',$path),'/');
  if(!(str_starts_with($relative,'uploads/suppliers/') || str_starts_with($relative,'suppliers/'))) return false;
  $publicRoot=realpath(__DIR__);
  if($publicRoot===false) return false;
  $file=$publicRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
  $parent=realpath(dirname($file));
  if($parent===false || !str_starts_with($parent,$publicRoot.DIRECTORY_SEPARATOR)) return false;
  if(!is_file($file)) return true;
  if(!@unlink($file)) return false;

  // Remove the supplier subfolder when it is empty.
  if(str_starts_with($relative,'uploads/suppliers/') || str_starts_with($relative,'suppliers/')){
    $supplierDir=dirname($file);
    if(is_dir($supplierDir)){
      $items=@scandir($supplierDir);
      if(is_array($items) && count($items)===2) @rmdir($supplierDir);
    }
  }
  return true;
}

if($_SERVER['REQUEST_METHOD']==='POST' && $isEditor){
  checkCsrf();
  $action=(string)($_POST['action']??'');
  try {
    if($action==='bulk_delete'){
      $ids=array_values(array_unique(array_filter(array_map('intval', (array)($_POST['supplier_ids']??[])), static fn($id)=>$id>0)));
      if(!$ids) throw new RuntimeException('Select at least one supplier record to delete.');
      $placeholders=implode(',',array_fill(0,count($ids),'?'));
      $st=$pdo->prepare("SELECT * FROM suppliers WHERE id IN ($placeholders)");
      $st->execute($ids);
      $selected=$st->fetchAll();
      if(!$selected) throw new RuntimeException('No matching supplier records were found.');
      $deleteIds=[];
      $filesToRemove=[];
      foreach($selected as $old){
        $deleteIds[]=(int)$old['id'];
        foreach(['philgeps_certificate_path','business_permit_path','tax_clearance_certificate_path','pcab_license_path'] as $fileColumn){
          if(!empty($old[$fileColumn])) $filesToRemove[]=$old[$fileColumn];
        }
      }

      // Remove database records first. Only after the delete succeeds, remove every
      // associated PDF from the local public/uploads/suppliers/<company>/ folder.
      $deletePlaceholders=implode(',',array_fill(0,count($deleteIds),'?'));
      $pdo->prepare("DELETE FROM suppliers WHERE id IN ($deletePlaceholders)")->execute($deleteIds);

      $fileDeleteFailures=[];
      foreach(array_unique($filesToRemove) as $filePath){
        if(!supplierRemoveFile($filePath,$uploadDir)) $fileDeleteFailures[]=basename((string)$filePath);
      }
      if($fileDeleteFailures){
        flash('error','Supplier record(s) deleted, but these attached file(s) could not be removed from the local folder: '.implode(', ',$fileDeleteFailures).'. Please check folder permissions.');
      } else {
        flash('success',count($deleteIds).' supplier record(s) and all attached files were deleted successfully.');
      }
      header('Location:supplier_registry.php');exit;
    } elseif($action==='save'){
      $ids=$_POST['supplier_id']??[];$names=$_POST['supplier_company_name']??[];$addresses=$_POST['address']??[];$owners=$_POST['owner']??[];$reps=$_POST['authorized_representative']??[];$businesses=[];$regs=$_POST['registration_type']??[];$phDates=$_POST['philgeps_valid_until']??[];$bpDates=$_POST['business_permit_valid_until']??[];$taxDates=$_POST['tax_clearance_valid_until']??[];$pcabDates=$_POST['pcab_license_valid_until']??[];
      $count=max(count($names),count($ids));
      for($i=0;$i<$count;$i++){
        $id=(int)($ids[$i]??0);$name=trim((string)($names[$i]??''));if($name==='')continue;
        $address=trim((string)($addresses[$i]??''));$owner=trim((string)($owners[$i]??''));$rep=trim((string)($reps[$i]??''));$business=trim((string)($businesses[$i]??''));$reg=in_array($regs[$i]??'', ['SEC','DTI','CDA'], true)?$regs[$i]:null;
        $phDate=supplierDate($phDates[$i]??null);$bpDate=supplierDate($bpDates[$i]??null);$taxDate=supplierDate($taxDates[$i]??null);$pcabDate=supplierDate($pcabDates[$i]??null);$old=null;
        if($id){$q=$pdo->prepare("SELECT * FROM suppliers WHERE id=?");$q->execute([$id]);$old=$q->fetch();if(!$old)continue;}

        // Reject a duplicate identity if another record already has the same
        // company name, address, and owner. Exclude this row itself while editing.
        $duplicateSql = "SELECT id FROM suppliers
          WHERE LOWER(TRIM(supplier_company_name)) = LOWER(TRIM(?))
            AND LOWER(TRIM(COALESCE(address,''))) = LOWER(TRIM(?))
            AND LOWER(TRIM(COALESCE(owner,''))) = LOWER(TRIM(?))";
        $duplicateParams = [$name,$address,$owner];
        if($id>0){$duplicateSql .= " AND id <> ?";$duplicateParams[]=$id;}
        $duplicateSql .= " LIMIT 1";
        $duplicateCheck=$pdo->prepare($duplicateSql);
        $duplicateCheck->execute($duplicateParams);
        if($duplicateCheck->fetchColumn()){
          throw new RuntimeException('A supplier/company with the same Supplier/Company Name, Address, and Owner already exists. Please check the Registered Suppliers list.');
        }

        $ph=supplierUpload('philgeps_certificate',$uploadDir,$uploadWeb,$name,$address,$owner,$old['philgeps_certificate_path']??null,$i);
        $bp=supplierUpload('business_permit',$uploadDir,$uploadWeb,$name,$address,$owner,$old['business_permit_path']??null,$i);
        $tax=supplierUpload('tax_clearance_certificate',$uploadDir,$uploadWeb,$name,$address,$owner,$old['tax_clearance_certificate_path']??null,$i);
        $pcab=supplierUpload('pcab_license',$uploadDir,$uploadWeb,$name,$address,$owner,$old['pcab_license_path']??null,$i);
        if($id){
          $st=$pdo->prepare("UPDATE suppliers SET supplier_company_name=?,address=?,owner=?,authorized_representative=?,business_type=?,philgeps_certificate_path=?,philgeps_valid_until=?,business_permit_path=?,business_permit_valid_until=?,tax_clearance_certificate_path=?,tax_clearance_valid_until=?,pcab_license_path=?,pcab_license_valid_until=?,registration_type=?,updated_by=? WHERE id=?");
          $st->execute([$name,$address,$owner,$rep,$business,$ph,$phDate,$bp,$bpDate,$tax,$taxDate,$pcab,$pcabDate,$reg,(int)currentUser()['id'],$id]);
        } else {
          $st=$pdo->prepare("INSERT INTO suppliers(supplier_company_name,address,owner,authorized_representative,business_type,philgeps_certificate_path,philgeps_valid_until,business_permit_path,business_permit_valid_until,tax_clearance_certificate_path,tax_clearance_valid_until,pcab_license_path,pcab_license_valid_until,registration_type,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
          $st->execute([$name,$address,$owner,$rep,$business,$ph,$phDate,$bp,$bpDate,$tax,$taxDate,$pcab,$pcabDate,$reg,(int)currentUser()['id'],(int)currentUser()['id']]);
        }
      }
      flash('success','Supplier information saved successfully.');header('Location:supplier_registry.php');exit;
    }
  } catch(Throwable $e) { flash('error',$e->getMessage()); }
}

$search=trim((string)($_GET['search']??''));
$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0){$st=$pdo->prepare("SELECT * FROM suppliers WHERE id=?");$st->execute([$editId]);$editing=$st->fetch()?:null;}
if(!$editing)$editing=['id'=>0,'supplier_company_name'=>'','address'=>'','owner'=>'','authorized_representative'=>'','business_type'=>'','philgeps_certificate_path'=>null,'philgeps_valid_until'=>null,'business_permit_path'=>null,'business_permit_valid_until'=>null,'tax_clearance_certificate_path'=>null,'tax_clearance_valid_until'=>null,'pcab_license_path'=>null,'pcab_license_valid_until'=>null,'registration_type'=>''];
$list=$pdo->prepare("SELECT * FROM suppliers WHERE supplier_company_name LIKE ? OR owner LIKE ? OR authorized_representative LIKE ? OR business_type LIKE ? ORDER BY supplier_company_name");
$like='%'.$search.'%';$list->execute([$like,$like,$like,$like]);$rows=$list->fetchAll();
pageStart('Supplier Registry');
?>
<style>
.supplier-list-panel{width:100%;max-width:100%;min-width:0;box-sizing:border-box;overflow:hidden}
.supplier-list-section,.supplier-table-wrap{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
.supplier-table-wrap{overflow-x:hidden}
.supplier-table{width:100%!important;max-width:100%!important;table-layout:fixed;border-collapse:collapse}
.supplier-table th,.supplier-table td{box-sizing:border-box;padding:8px 7px;white-space:normal;overflow-wrap:anywhere;word-break:normal;vertical-align:top}
.supplier-table.supplier-table-editor{width:100%!important;min-width:0!important;max-width:100%!important;table-layout:fixed!important}
.supplier-table.supplier-table-editor col.col-select{width:20px!important}
.supplier-table.supplier-table-editor col.col-number{width:10px!important}
.supplier-table.supplier-table-editor col.col-company{width:100px!important}
.supplier-table.supplier-table-editor col.col-permits{width:150px!important}
.supplier-table.supplier-table-editor col.col-actions{width:20px!important}
.supplier-table.supplier-table-editor th:nth-child(1),.supplier-table.supplier-table-editor td:nth-child(1){width:20px!important;min-width:20px!important;max-width:20px!important;box-sizing:border-box;padding-left:2px!important;padding-right:2px!important}
.supplier-table.supplier-table-editor th:nth-child(2),.supplier-table.supplier-table-editor td:nth-child(2){width:10px!important;min-width:10px!important;max-width:10px!important}
.supplier-table.supplier-table-editor th:nth-child(3),.supplier-table.supplier-table-editor td:nth-child(3){width:100px!important;min-width:100px!important;max-width:100px!important}
.supplier-table.supplier-table-editor th:nth-child(4),.supplier-table.supplier-table-editor td:nth-child(4){width:150px!important;min-width:150px!important;max-width:150px!important}
.supplier-table.supplier-table-editor th:nth-child(5),.supplier-table.supplier-table-editor td:nth-child(5){width:20px!important;min-width:20px!important;max-width:20px!important}
.supplier-table.supplier-table-editor th{font-size:12px;line-height:1.25;white-space:normal;overflow-wrap:normal}
.supplier-table-viewer th:nth-child(1),.supplier-table-viewer td:nth-child(1){width:5%}
.supplier-table-viewer th:nth-child(2),.supplier-table-viewer td:nth-child(2){width:35%}
.supplier-table-viewer th:nth-child(3),.supplier-table-viewer td:nth-child(3){width:60%}
.supplier-table .supplier-company-details,.supplier-table .supplier-doc-links{min-width:0;overflow-wrap:anywhere}
.supplier-table .supplier-doc-links>div{margin-bottom:6px;line-height:1.4}
.supplier-table .supplier-doc-links small{white-space:normal}
.supplier-table .supplier-actions{display:flex;flex-wrap:wrap;gap:4px}
.supplier-form-scroll{display:block!important;width:100%!important;max-width:100%!important;overflow-x:auto!important;overflow-y:visible!important}
.supplier-entry-table{border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important;width:1788px!important;min-width:1788px!important;max-width:none!important;margin:0!important}
.supplier-entry-table .supplier-entry-row{display:table-row!important;width:auto!important}
.supplier-entry-table .supplier-entry-row>.supplier-field{display:table-cell!important;float:none!important;position:static!important;vertical-align:top!important;width:auto!important;min-width:0!important;max-width:none!important;}
.supplier-entry-table .supplier-entry-row>.supplier-row-select-field{width:60px!important;min-width:60px!important;max-width:60px!important;}
.supplier-entry-table .supplier-entry-row>.supplier-company-field,.supplier-entry-table .supplier-entry-row>.supplier-address-field,.supplier-entry-table .supplier-entry-row>.supplier-owner-field,.supplier-entry-table .supplier-entry-row>.supplier-authorized-representative-field{width:200px!important;min-width:200px!important;max-width:200px!important;}
.supplier-entry-table .supplier-entry-row>.supplier-document-field{width:230px!important;min-width:230px!important;max-width:230px!important;}
.supplier-entry-table .supplier-field{display:table-cell!important;vertical-align:top!important;border:1px solid #cbd5e1!important;border-radius:4px!important;padding:6px!important;box-sizing:border-box!important;background:#fff!important;white-space:normal!important;}
.supplier-entry-table .supplier-row-select-field{width:60px!important;min-width:60px!important;max-width:60px!important;padding:6px 4px!important}
.supplier-entry-table .supplier-row-select-field label{display:block!important;margin:0 0 5px!important;white-space:nowrap!important}
.supplier-entry-table .supplier-row-select-field input[type=checkbox]{display:block!important;width:15px!important;height:15px!important;margin:0!important}
.supplier-entry-table .supplier-company-field,.supplier-entry-table .supplier-address-field,.supplier-entry-table .supplier-owner-field,.supplier-entry-table .supplier-authorized-representative-field{width:200px!important;min-width:200px!important;max-width:200px!important}
.supplier-entry-table .supplier-document-field{width:230px!important;min-width:230px!important;max-width:230px!important}
.supplier-entry-table .supplier-field label{display:block!important;margin-bottom:5px!important}
.supplier-entry-table input:not([type=checkbox]):not([type=file]),.supplier-entry-table select{max-width:100%!important;box-sizing:border-box!important}
.supplier-entry-table input[type=file]{display:block!important;width:100%!important;max-width:100%!important;font-size:12px!important}
.supplier-entry-table .supplier-date-picker,.supplier-entry-table .supplier-registration-under-tax{max-width:100%!important}
.supplier-bulk-actions{min-width:0}
@media(max-width:760px){
 .supplier-table-wrap{overflow-x:auto}
 .supplier-table{min-width:680px!important}
}
</style>
<div class="supplier-registry-page" style="display:flex!important;flex-direction:column!important;align-items:stretch!important;row-gap:24px!important;">
  <div class="panel supplier-content-panel">
    <div class="supplier-toolbar supplier-registry-heading-row">
      <div class="supplier-heading-block">
        <h2 style="margin:0">Supplier Registry</h2>
        <p class="hint-text">Maintain the official registry of suppliers and their accreditation documents.</p>
        <div class="supplier-table-select-all-toolbar" style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:12px">
          <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" id="selectAllSupplierEntryRows"> Select All</label>
        </div>
      </div>
      <div class="supplier-header-search" id="supplierSearchBox">
        <input class="input" type="search" id="supplierSearchInput" name="search" value="<?=e($search)?>" placeholder="Search Supplier..." autocomplete="off" aria-label="Search Supplier">
        <div class="supplier-search-suggestions" id="supplierSearchSuggestions"></div>
      </div>
    </div>

    <?php if($isEditor): ?>
    <form method="post" enctype="multipart/form-data" class="supplier-form supplier-row-form" id="supplierForm">
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="action" value="save">
      <div class="supplier-form-scroll">
        <input type="hidden" name="supplier_id[]" value="<?=e((string)$editing['id'])?>">
        <table class="supplier-entry-table">
          <colgroup><col style="width:60px"><col style="width:280px"><col style="width:280px"><col style="width:280px"><col style="width:280px"><col style="width:280px"><col style="width:280px"><col style="width:280px"><col style="width:280px"></colgroup>
          <tbody>
          <tr class="supplier-entry-row">
          <td class="supplier-field supplier-row-select-field"><label>Select</label><input type="checkbox" class="supplier-entry-select" aria-label="Select supplier entry row to remove" style="display:block!important;position:static!important;float:none!important;justify-self:start!important;width:15px!important;min-width:15px!important;max-width:15px!important;height:15px!important;margin:0!important;padding:0!important;transform:none!important;"></td>
          <td class="supplier-field supplier-company-field"><label>Supplier/Company</label><input class="input" name="supplier_company_name[]" value="<?=e($editing['supplier_company_name'])?>" placeholder="Supplier/Company Name" required></td>
          <td class="supplier-field supplier-address-field"><label>Address</label><input class="input" type="text" name="address[]" value="<?=e($editing['address'])?>" placeholder="Address"></td>
          <td class="supplier-field supplier-owner-field"><label>Owner</label><input class="input" name="owner[]" value="<?=e($editing['owner'])?>" placeholder="Owner"></td>
          <td class="supplier-field supplier-authorized-representative-field"><label>Authorized Representative</label><input class="input" name="authorized_representative[]" value="<?=e($editing['authorized_representative'])?>" placeholder="Authorized Representative"></td>
          <td class="supplier-field supplier-document-field"><label>PhilGEPS Membership Certificate</label><input type="file" name="philgeps_certificate[]" accept=".pdf"><?php if(!empty($editing['philgeps_certificate_path'])): ?><div class="supplier-current-upload">Current file: <a href="<?=e($editing['philgeps_certificate_path'])?>" target="_blank" rel="noopener"><?=e(basename($editing['philgeps_certificate_path']))?></a></div><?php endif; ?><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['philgeps_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="philgeps_valid_until[]" value="<?=e($editing['philgeps_valid_until']??'')?>"></div></div></td>
          <td class="supplier-field supplier-document-field"><label>Business/Mayor's Permit</label><input type="file" name="business_permit[]" accept=".pdf"><?php if(!empty($editing['business_permit_path'])): ?><div class="supplier-current-upload">Current file: <a href="<?=e($editing['business_permit_path'])?>" target="_blank" rel="noopener"><?=e(basename($editing['business_permit_path']))?></a></div><?php endif; ?><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['business_permit_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="business_permit_valid_until[]" value="<?=e($editing['business_permit_valid_until']??'')?>"></div></div></td>
          <td class="supplier-field supplier-document-field"><label>Tax Clearance Certificate</label><input type="file" name="tax_clearance_certificate[]" accept=".pdf"><?php if(!empty($editing['tax_clearance_certificate_path'])): ?><div class="supplier-current-upload">Current file: <a href="<?=e($editing['tax_clearance_certificate_path'])?>" target="_blank" rel="noopener"><?=e(basename($editing['tax_clearance_certificate_path']))?></a></div><?php endif; ?><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['tax_clearance_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="tax_clearance_valid_until[]" value="<?=e($editing['tax_clearance_valid_until']??'')?>"></div></div><div class="supplier-registration-under-tax"><span>Registration Type</span><select class="select" name="registration_type[]" style="font-size:12px!important;"><option value="">Select</option><option value="SEC" <?=$editing['registration_type']==='SEC'?'selected':''?>>SEC</option><option value="DTI" <?=$editing['registration_type']==='DTI'?'selected':''?>>DTI</option><option value="CDA" <?=$editing['registration_type']==='CDA'?'selected':''?>>CDA</option></select></div></td>
          <td class="supplier-field supplier-document-field"><label>PCAB License</label><input type="file" name="pcab_license[]" accept=".pdf"><?php if(!empty($editing['pcab_license_path'])): ?><div class="supplier-current-upload">Current file: <a href="<?=e($editing['pcab_license_path'])?>" target="_blank" rel="noopener"><?=e(basename($editing['pcab_license_path']))?></a></div><?php endif; ?><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['pcab_license_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="pcab_license_valid_until[]" value="<?=e($editing['pcab_license_valid_until']??'')?>"></div></div>
          </td>
          </tr>
          </tbody>
        </table>
      </div>
      <div class="supplier-form-actions">
        <button type="button" class="btn secondary" id="addSupplierRow">+ Add Row</button>
        <button type="button" class="btn danger" id="removeSelectedSupplierRows">Remove Selected</button>
        <button class="btn" type="submit">Save Supplier</button>
      </div>
    </form>
    <?php endif; ?>

  </div>

  <div class="panel supplier-content-panel supplier-list-panel">
    <div class="supplier-list-section">
      <div class="supplier-list-header">
        <div>
          <h2 style="margin:0;font-size:17px!important;line-height:1.3!important;font-weight:700!important">Registered Suppliers</h2>
          <p class="hint-text" style="font-size:11px!important;line-height:1.35!important;margin:5px 0 0!important"><?=number_format(count($rows))?> supplier<?=count($rows)===1?'':'s'?> found</p>
        </div>
      </div>
      <?php if($isEditor): ?>
      <form method="post" id="bulkSupplierDeleteForm" onsubmit="return confirm('Delete all selected supplier records and their uploaded permits/licenses? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="bulk_delete">
        <div class="supplier-bulk-actions" style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin:12px 0;font-size:12px;width:100%;box-sizing:border-box">
          <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" id="selectAllSuppliers"> Select All</label>
          <button class="btn danger" type="submit" id="deleteSelectedSuppliers" style="margin-left:auto;flex:0 0 auto">Delete Selected</button>
        </div>
      <?php endif; ?>
      <div class="table-wrap supplier-table-wrap">
        <table class="table supplier-table <?=$isEditor?'supplier-table-editor':'supplier-table-viewer'?>" id="supplierTable">
          <colgroup><?php if($isEditor): ?><col class="col-select"><col class="col-number"><col class="col-company"><col class="col-permits"><col class="col-actions"><?php else: ?><col style="width:5%"><col style="width:35%"><col style="width:60%"><?php endif; ?></colgroup>
          <thead><tr><?php if($isEditor): ?><th><span class="sr-only">Select</span></th><?php endif; ?><th>#</th><th>Supplier/Company Name</th><th>Permits and Licenses</th><?php if($isEditor): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php $i=1;foreach($rows as $r): ?>
          <tr>
            <?php if($isEditor): ?><td><input type="checkbox" class="supplier-select-checkbox" name="supplier_ids[]" value="<?=(int)$r['id']?>" aria-label="Select <?=e($r['supplier_company_name'])?> for deletion"></td><?php endif; ?>
            <td><?=$i++?></td>
            <td class="supplier-company-details">
              <div><strong><?=e($r['supplier_company_name'])?></strong></div>
              <div><strong>Address:</strong> <?=e($r['address']?:'—')?></div>
              <div><strong>Owner:</strong> <?=e($r['owner']?:'—')?></div>
              <div><strong>Authorized Representative:</strong> <?=e($r['authorized_representative']?:'—')?></div>
            </td>
            <td class="supplier-doc-links">
              <div><strong>PhilGEPS Membership Certificate:</strong> <?php if($r['philgeps_certificate_path']): ?><a href="<?=e($r['philgeps_certificate_path'])?>" target="_blank">View PDF</a><?php else: ?>—<?php endif; ?> <small>| Validity: <?=e(supplierDisplayDate($r['philgeps_valid_until']))?></small></div>
              <div><strong>Mayor's/Business Permit:</strong> <?php if($r['business_permit_path']): ?><a href="<?=e($r['business_permit_path'])?>" target="_blank">View PDF</a><?php else: ?>—<?php endif; ?> <small>| Validity: <?=e(supplierDisplayDate($r['business_permit_valid_until']))?></small></div>
              <div><strong>Tax Clearance:</strong> <?php if($r['tax_clearance_certificate_path']): ?><a href="<?=e($r['tax_clearance_certificate_path'])?>" target="_blank">View PDF</a><?php else: ?>—<?php endif; ?> <small>| Registration Type: <?=e($r['registration_type']?:'—')?> | Validity: <?=e(supplierDisplayDate($r['tax_clearance_valid_until']))?></small></div>
              <div><strong>PCAB License:</strong> <?php if($r['pcab_license_path']): ?><a href="<?=e($r['pcab_license_path'])?>" target="_blank">View PDF</a><?php else: ?>—<?php endif; ?> <small>| Validity: <?=e(supplierDisplayDate($r['pcab_license_valid_until']))?></small></div>
            </td>
            <?php if($isEditor): ?><td class="supplier-actions"><div class="supplier-actions"><a class="btn secondary" href="supplier_registry.php?edit=<?=(int)$r['id']?>">Edit</a></div></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php if(!$rows): ?><tr><td colspan="<?=$isEditor?5:3?>" class="empty">No suppliers found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($isEditor): ?></form><?php endif; ?>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const datePickerRoot=document.querySelector('.supplier-form-scroll');
 function formatSupplierDate(value){if(!value)return '';const d=new Date(value+'T00:00:00');return isNaN(d)?value:d.toLocaleDateString('en-US',{month:'long',day:'2-digit',year:'numeric'});}
 function syncDatePicker(p){const native=p.querySelector('.supplier-date-native'),display=p.querySelector('.supplier-date-display');if(native&&display){display.value=formatSupplierDate(native.value);display.style.setProperty('font-size','12px','important');display.style.setProperty('line-height','1.2','important');const openPicker=function(e){if(e){e.preventDefault();e.stopPropagation();}try{native.focus({preventScroll:true});if(typeof native.showPicker==='function')native.showPicker();else native.click();}catch(err){try{native.focus({preventScroll:true});}catch(ignore){}}};display.addEventListener('click',openPicker);p.addEventListener('click',function(e){if(e.target!==native)openPicker(e);});native.addEventListener('change',function(){display.value=formatSupplierDate(native.value);display.style.setProperty('font-size','12px','important');display.style.setProperty('line-height','1.2','important');});}}
 if(datePickerRoot){datePickerRoot.querySelectorAll('.supplier-date-picker').forEach(syncDatePicker);}
 const rows=document.querySelector('.supplier-form-scroll'),add=document.getElementById('addSupplierRow'),removeSelected=document.getElementById('removeSelectedSupplierRows'),selectAllEntryRows=document.getElementById('selectAllSupplierEntryRows');
 function syncEntrySelectAll(){if(!rows||!selectAllEntryRows)return;const checks=Array.from(rows.querySelectorAll('.supplier-entry-select'));selectAllEntryRows.checked=checks.length>0&&checks.every(cb=>cb.checked);selectAllEntryRows.indeterminate=checks.some(cb=>cb.checked)&&!checks.every(cb=>cb.checked);}
 if(selectAllEntryRows&&rows){selectAllEntryRows.addEventListener('change',function(){rows.querySelectorAll('.supplier-entry-select').forEach(cb=>cb.checked=selectAllEntryRows.checked);selectAllEntryRows.indeterminate=false;});rows.addEventListener('change',function(e){if(e.target.classList.contains('supplier-entry-select'))syncEntrySelectAll();});}
 if(add&&rows){add.addEventListener('click',function(){const template=rows.querySelector('.supplier-entry-row');if(!template)return;const r=template.cloneNode(true);r.querySelectorAll('input').forEach(i=>{if(i.type==='checkbox'){i.checked=false;}else if(i.type!=='hidden')i.value='';});r.querySelectorAll('textarea').forEach(t=>t.value='');r.querySelectorAll('select').forEach(s=>s.selectedIndex=0);r.querySelectorAll('input[type=file]').forEach(i=>i.value='');rows.appendChild(r);r.querySelectorAll('.supplier-date-picker').forEach(function(dp){const n=dp.querySelector('.supplier-date-native'),d=dp.querySelector('.supplier-date-display');if(n)n.value='';if(d)d.value='';syncDatePicker(dp);});syncEntrySelectAll();});
 if(removeSelected){removeSelected.addEventListener('click',function(){const all=Array.from(rows.querySelectorAll('.supplier-entry-row'));const selected=all.filter(r=>r.querySelector('.supplier-entry-select')?.checked);if(!selected.length){alert('Select one or more supplier entry rows to remove.');return;}if(selected.length===all.length){if(!confirm('All entry rows are selected. Clear the entry form and keep one blank row?'))return;const first=all[0];all.slice(1).forEach(r=>r.remove());first.querySelectorAll('input').forEach(i=>{if(i.type==='checkbox')i.checked=false;else if(i.type!=='hidden')i.value='';});first.querySelectorAll('select').forEach(s=>s.selectedIndex=0);first.querySelectorAll('.supplier-date-display').forEach(i=>i.value='');first.querySelectorAll('input[type=file]').forEach(i=>i.value='');}else{selected.forEach(r=>r.remove());}syncEntrySelectAll();});}
 }
 const selectAll=document.getElementById('selectAllSuppliers');
 if(selectAll){selectAll.addEventListener('change',function(){document.querySelectorAll('.supplier-select-checkbox').forEach(cb=>{const tr=cb.closest('tr');if(tr&&tr.style.display!=='none')cb.checked=selectAll.checked;});});}
 document.querySelectorAll('.supplier-select-checkbox').forEach(cb=>cb.addEventListener('change',function(){const all=Array.from(document.querySelectorAll('.supplier-select-checkbox'));if(selectAll)selectAll.checked=all.length>0&&all.every(x=>x.checked);}));
 const input=document.getElementById('supplierSearchInput'),box=document.getElementById('supplierSearchBox'),suggestions=document.getElementById('supplierSearchSuggestions'),table=document.getElementById('supplierTable');
 if(!input||!suggestions||!table)return;const tableRows=Array.from(table.querySelectorAll('tbody tr')).filter(r=>!r.classList.contains('empty'));
 function filter(term){term=String(term||'').trim().toLowerCase();tableRows.forEach(r=>r.style.display=!term||r.textContent.toLowerCase().includes(term)?'':'none');}
 function close(){suggestions.innerHTML='';suggestions.style.display='none';}
 input.addEventListener('input',function(){const term=input.value.trim().toLowerCase();suggestions.innerHTML='';if(!term){close();filter('');return;}const m=tableRows.filter(r=>r.textContent.toLowerCase().includes(term)).slice(0,10);m.forEach(r=>{const b=document.createElement('button');b.type='button';b.className='supplier-search-suggestion';b.textContent=(r.cells[document.querySelector('.supplier-select-checkbox')?2:1]?.querySelector('strong')?.textContent||r.cells[document.querySelector('.supplier-select-checkbox')?2:1]?.textContent||'').trim();b.onclick=()=>{input.value=b.textContent;close();filter(b.textContent)};suggestions.appendChild(b)});if(!m.length){const e=document.createElement('div');e.className='supplier-search-empty';e.textContent='No matching supplier found.';suggestions.appendChild(e)}suggestions.style.display='block';filter(term)});input.addEventListener('keydown',e=>{if(e.key==='Escape'){input.value='';close();filter('')}});document.addEventListener('click',e=>{if(box&&!box.contains(e.target))close()});
});
</script>
<?php pageEnd(); ?>
