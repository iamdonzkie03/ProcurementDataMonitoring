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
function supplierUpload(string $field,string $uploadDir,string $uploadWeb,?string $oldPath=null,int $index=0): ?string {
  if(empty($_FILES[$field]) || !isset($_FILES[$field]['error'][$index]) || $_FILES[$field]['error'][$index]===UPLOAD_ERR_NO_FILE) return $oldPath;
  if($_FILES[$field]['error'][$index]!==UPLOAD_ERR_OK) throw new RuntimeException('The uploaded file could not be processed.');
  if((int)($_FILES[$field]['size'][$index]??0)>8*1024*1024) throw new RuntimeException('Each supplier certificate/permit file must not exceed 8 MB.');
  $original=(string)($_FILES[$field]['name'][$index]??'');
  $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
  if(!in_array($ext,['pdf'],true)) throw new RuntimeException('Only PDF files are allowed.');
  $safe=preg_replace('/[^a-zA-Z0-9_-]/','-',pathinfo($original,PATHINFO_FILENAME));
  $name=$safe.'-'.bin2hex(random_bytes(8)).'.'.$ext;
  $target=rtrim($uploadDir,'/\\').DIRECTORY_SEPARATOR.$name;
  if(!move_uploaded_file($_FILES[$field]['tmp_name'][$index],$target)) throw new RuntimeException('Unable to save the uploaded file.');
  if($oldPath){
    $oldFile=__DIR__.'/'.ltrim(str_replace(['uploads/','/'],'',$oldPath),'/');
    if(is_file($oldFile)) @unlink($oldFile);
  }
  return $uploadWeb.'/'.$name;
}
function supplierRemoveFile(?string $path): void {
  if(!$path) return;
  $file=__DIR__.'/'.ltrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$path),DIRECTORY_SEPARATOR);
  if(is_file($file)) @unlink($file);
}

if($_SERVER['REQUEST_METHOD']==='POST' && $isEditor){
  checkCsrf();
  $action=(string)($_POST['action']??'');
  try {
    if($action==='delete'){
      $id=(int)($_POST['id']??0);
      $st=$pdo->prepare("SELECT * FROM suppliers WHERE id=?");$st->execute([$id]);$old=$st->fetch();
      if(!$old) throw new RuntimeException('Supplier record was not found.');
      supplierRemoveFile($old['philgeps_certificate_path']??null);
      supplierRemoveFile($old['business_permit_path']??null);
      supplierRemoveFile($old['tax_clearance_certificate_path']??null);
      supplierRemoveFile($old['pcab_license_path']??null);
      $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
      flash('success','Supplier and all uploaded files were deleted successfully.');
    } elseif($action==='save'){
      $ids=$_POST['supplier_id']??[];$names=$_POST['supplier_company_name']??[];$addresses=$_POST['address']??[];$owners=$_POST['owner']??[];$reps=$_POST['authorized_representative']??[];$businesses=[];$regs=$_POST['registration_type']??[];$phDates=$_POST['philgeps_valid_until']??[];$bpDates=$_POST['business_permit_valid_until']??[];$taxDates=$_POST['tax_clearance_valid_until']??[];$pcabDates=$_POST['pcab_license_valid_until']??[];
      $count=max(count($names),count($ids));
      for($i=0;$i<$count;$i++){
        $id=(int)($ids[$i]??0);$name=trim((string)($names[$i]??''));if($name==='')continue;
        $address=trim((string)($addresses[$i]??''));$owner=trim((string)($owners[$i]??''));$rep=trim((string)($reps[$i]??''));$business=trim((string)($businesses[$i]??''));$reg=in_array($regs[$i]??'', ['SEC','DTI','CDA'], true)?$regs[$i]:null;
        $phDate=supplierDate($phDates[$i]??null);$bpDate=supplierDate($bpDates[$i]??null);$taxDate=supplierDate($taxDates[$i]??null);$pcabDate=supplierDate($pcabDates[$i]??null);$old=null;
        if($id){$q=$pdo->prepare("SELECT * FROM suppliers WHERE id=?");$q->execute([$id]);$old=$q->fetch();if(!$old)continue;}
        $ph=supplierUpload('philgeps_certificate',$uploadDir,$uploadWeb,$old['philgeps_certificate_path']??null,$i);
        $bp=supplierUpload('business_permit',$uploadDir,$uploadWeb,$old['business_permit_path']??null,$i);
        $tax=supplierUpload('tax_clearance_certificate',$uploadDir,$uploadWeb,$old['tax_clearance_certificate_path']??null,$i);
        $pcab=supplierUpload('pcab_license',$uploadDir,$uploadWeb,$old['pcab_license_path']??null,$i);
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
<div class="supplier-registry-page">
  <div class="panel supplier-content-panel">
    <div class="supplier-toolbar supplier-registry-heading-row">
      <div class="supplier-heading-block">
        <h2 style="margin:0">Supplier Registry</h2>
        <p class="hint-text">Maintain the official registry of suppliers and their accreditation documents.</p>
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
        <div class="supplier-entry-row">
          <input type="hidden" name="supplier_id[]" value="<?=e((string)$editing['id'])?>">
          <div class="supplier-field"><label>Supplier/Company</label><input class="input" name="supplier_company_name[]" value="<?=e($editing['supplier_company_name'])?>" placeholder="Supplier/Company Name" required></div>
          <div class="supplier-field supplier-address-field"><label>Address</label><input class="input" type="text" name="address[]" value="<?=e($editing['address'])?>" placeholder="Address"></div>
          <div class="supplier-field"><label>Owner</label><input class="input" name="owner[]" value="<?=e($editing['owner'])?>" placeholder="Owner"></div>
          <div class="supplier-field"><label>Authorized Representative</label><input class="input" name="authorized_representative[]" value="<?=e($editing['authorized_representative'])?>" placeholder="Authorized Representative"></div>
          <div class="supplier-field supplier-document-field"><label>PhilGEPS Membership Certificate</label><input type="file" name="philgeps_certificate[]" accept=".pdf"><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['philgeps_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="philgeps_valid_until[]" value="<?=e($editing['philgeps_valid_until']??'')?>"></div></div></div>
          <div class="supplier-field supplier-document-field"><label>Business/Mayor's Permit</label><input type="file" name="business_permit[]" accept=".pdf"><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['business_permit_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="business_permit_valid_until[]" value="<?=e($editing['business_permit_valid_until']??'')?>"></div></div></div>
          <div class="supplier-field supplier-document-field"><label>Tax Clearance Certificate</label><input type="file" name="tax_clearance_certificate[]" accept=".pdf"><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['tax_clearance_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="tax_clearance_valid_until[]" value="<?=e($editing['tax_clearance_valid_until']??'')?>"></div></div><div class="supplier-registration-under-tax"><span>Registration Type</span><select class="select" name="registration_type[]" style="font-size:12px!important;"><option value="">Select</option><option value="SEC" <?=$editing['registration_type']==='SEC'?'selected':''?>>SEC</option><option value="DTI" <?=$editing['registration_type']==='DTI'?'selected':''?>>DTI</option><option value="CDA" <?=$editing['registration_type']==='CDA'?'selected':''?>>CDA</option></select></div></div>
          <div class="supplier-field supplier-document-field"><label>PCAB License</label><input type="file" name="pcab_license[]" accept=".pdf"><div class="supplier-validity"><span>Validity Date</span><div class="supplier-date-picker"><input class="input supplier-date-display" type="text" value="<?=e($editing['pcab_license_valid_until']??'')?>" placeholder="Select date" readonly><input class="supplier-date-native" type="date" name="pcab_license_valid_until[]" value="<?=e($editing['pcab_license_valid_until']??'')?>"></div></div></div>
          <div class="supplier-field supplier-row-action"><label>Action</label><button type="button" class="btn danger supplier-remove-row">Remove</button></div>
        </div>
      </div>
      <div class="supplier-form-actions">
        <button type="button" class="btn secondary" id="addSupplierRow">+ Add Row</button>
        <button class="btn" type="submit">Save Supplier</button>
      </div>
    </form>
    <?php endif; ?>

    <div class="supplier-list-section">
      <div class="supplier-list-header">
        <div>
          <h2 style="margin:0">Registered Suppliers</h2>
          <p class="hint-text"><?=number_format(count($rows))?> supplier<?=count($rows)===1?'':'s'?> found</p>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table supplier-table" id="supplierTable">
          <thead><tr><th>#</th><th>Supplier/Company Name</th><th>Permits and Licenses</th><?php if($isEditor): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php $i=1;foreach($rows as $r): ?>
          <tr>
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
            <?php if($isEditor): ?><td class="supplier-actions"><div class="supplier-actions"><a class="btn secondary" href="supplier_registry.php?edit=<?=(int)$r['id']?>">Edit</a><form method="post" onsubmit="return confirm('Delete this supplier and all uploaded permits/licenses?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn danger" type="submit">Delete</button></form></div></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php if(!$rows): ?><tr><td colspan="<?=$isEditor?3:2?>" class="empty">No suppliers found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const datePickerRoot=document.querySelector('.supplier-form-scroll');
 function formatSupplierDate(value){if(!value)return '';const d=new Date(value+'T00:00:00');return isNaN(d)?value:d.toLocaleDateString('en-US',{month:'long',day:'2-digit',year:'numeric'});}
 function syncDatePicker(p){const native=p.querySelector('.supplier-date-native'),display=p.querySelector('.supplier-date-display');if(native&&display){display.value=formatSupplierDate(native.value);display.style.setProperty('font-size','12px','important');display.style.setProperty('line-height','1.2','important');const openPicker=function(e){if(e){e.preventDefault();e.stopPropagation();}try{native.focus({preventScroll:true});if(typeof native.showPicker==='function')native.showPicker();else native.click();}catch(err){try{native.focus({preventScroll:true});}catch(ignore){}}};display.addEventListener('click',openPicker);p.addEventListener('click',function(e){if(e.target!==native)openPicker(e);});native.addEventListener('change',function(){display.value=formatSupplierDate(native.value);display.style.setProperty('font-size','12px','important');display.style.setProperty('line-height','1.2','important');});}}
 if(datePickerRoot){datePickerRoot.querySelectorAll('.supplier-date-picker').forEach(syncDatePicker);}
 const rows=document.querySelector('.supplier-form-scroll'),add=document.getElementById('addSupplierRow');
 if(add&&rows){add.addEventListener('click',function(){const r=rows.querySelector('.supplier-entry-row').cloneNode(true);r.querySelectorAll('input').forEach(i=>{if(i.type!=='hidden')i.value='';});r.querySelectorAll('textarea').forEach(t=>t.value='');r.querySelectorAll('select').forEach(s=>s.selectedIndex=0);r.querySelectorAll('input[type=file]').forEach(i=>i.value='');rows.appendChild(r);const dp=r.querySelector('.supplier-date-picker');if(dp){const n=dp.querySelector('.supplier-date-native'),d=dp.querySelector('.supplier-date-display');if(n)n.value='';if(d)d.value='';syncDatePicker(dp);}});rows.addEventListener('click',function(e){if(e.target.classList.contains('supplier-remove-row')){const all=rows.querySelectorAll('.supplier-entry-row');if(all.length>1)e.target.closest('.supplier-entry-row').remove();else e.target.closest('.supplier-entry-row').querySelectorAll('input').forEach(i=>{if(i.type!=='hidden')i.value='';});}});}
 const input=document.getElementById('supplierSearchInput'),box=document.getElementById('supplierSearchBox'),suggestions=document.getElementById('supplierSearchSuggestions'),table=document.getElementById('supplierTable');
 if(!input||!suggestions||!table)return;const tableRows=Array.from(table.querySelectorAll('tbody tr')).filter(r=>!r.classList.contains('empty'));
 function filter(term){term=String(term||'').trim().toLowerCase();tableRows.forEach(r=>r.style.display=!term||r.textContent.toLowerCase().includes(term)?'':'none');}
 function close(){suggestions.innerHTML='';suggestions.style.display='none';}
 input.addEventListener('input',function(){const term=input.value.trim().toLowerCase();suggestions.innerHTML='';if(!term){close();filter('');return;}const m=tableRows.filter(r=>r.textContent.toLowerCase().includes(term)).slice(0,10);m.forEach(r=>{const b=document.createElement('button');b.type='button';b.className='supplier-search-suggestion';b.textContent=(r.cells[1]?.querySelector('strong')?.textContent||r.cells[1]?.textContent||'').trim();b.onclick=()=>{input.value=b.textContent;close();filter(b.textContent)};suggestions.appendChild(b)});if(!m.length){const e=document.createElement('div');e.className='supplier-search-empty';e.textContent='No matching supplier found.';suggestions.appendChild(e)}suggestions.style.display='block';filter(term)});input.addEventListener('keydown',e=>{if(e.key==='Escape'){input.value='';close();filter('')}});document.addEventListener('click',e=>{if(box&&!box.contains(e.target))close()});
});
</script>
<?php pageEnd(); ?>