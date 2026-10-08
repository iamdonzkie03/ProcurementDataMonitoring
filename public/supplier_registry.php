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
function supplierUpload(string $field,string $uploadDir,string $uploadWeb,?string $oldPath=null): ?string {
  if(empty($_FILES[$field]) || ($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return $oldPath;
  if(($_FILES[$field]['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new RuntimeException('The uploaded file could not be processed.');
  if((int)($_FILES[$field]['size']??0)>8*1024*1024) throw new RuntimeException('Each supplier certificate/permit file must not exceed 8 MB.');
  $original=(string)($_FILES[$field]['name']??'');
  $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
  $allowed=['pdf','jpg','jpeg','png'];
  if(!in_array($ext,$allowed,true)) throw new RuntimeException('Only PDF, JPG, JPEG and PNG files are allowed.');
  $safe=preg_replace('/[^a-zA-Z0-9_-]/','-',pathinfo($original,PATHINFO_FILENAME));
  $name=$safe.'-'.bin2hex(random_bytes(8)).'.'.$ext;
  $target=rtrim($uploadDir,'/\\').DIRECTORY_SEPARATOR.$name;
  if(!move_uploaded_file($_FILES[$field]['tmp_name'],$target)) throw new RuntimeException('Unable to save the uploaded file.');
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
      $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
      flash('success','Supplier deleted successfully.');
    } elseif($action==='save'){
      $id=(int)($_POST['id']??0);
      $name=trim((string)($_POST['supplier_company_name']??''));
      if($name==='') throw new RuntimeException('Supplier/Company Name is required.');
      $address=trim((string)($_POST['address']??''));$owner=trim((string)($_POST['owner']??''));
      $rep=trim((string)($_POST['authorized_representative']??''));$business=trim((string)($_POST['business_type']??''));
      $reg=in_array($_POST['registration_type']??'', ['SEC','DTI','CDA'], true)?$_POST['registration_type']:null;
      $phDate=supplierDate($_POST['philgeps_valid_until']??null);$bpDate=supplierDate($_POST['business_permit_valid_until']??null);$taxDate=supplierDate($_POST['tax_clearance_valid_until']??null);
      $old=null;if($id){$q=$pdo->prepare("SELECT * FROM suppliers WHERE id=?");$q->execute([$id]);$old=$q->fetch();if(!$old)throw new RuntimeException('Supplier record was not found.');}
      $ph=supplierUpload('philgeps_certificate',$uploadDir,$uploadWeb,$old['philgeps_certificate_path']??null);
      $bp=supplierUpload('business_permit',$uploadDir,$uploadWeb,$old['business_permit_path']??null);
      $tax=supplierUpload('tax_clearance_certificate',$uploadDir,$uploadWeb,$old['tax_clearance_certificate_path']??null);
      if($id){
        $st=$pdo->prepare("UPDATE suppliers SET supplier_company_name=?,address=?,owner=?,authorized_representative=?,business_type=?,philgeps_certificate_path=?,philgeps_valid_until=?,business_permit_path=?,business_permit_valid_until=?,tax_clearance_certificate_path=?,tax_clearance_valid_until=?,registration_type=?,updated_by=? WHERE id=?");
        $st->execute([$name,$address,$owner,$rep,$business,$ph,$phDate,$bp,$bpDate,$tax,$taxDate,$reg,(int)currentUser()['id'],$id]);
        flash('success','Supplier information updated successfully.');
      } else {
        $st=$pdo->prepare("INSERT INTO suppliers(supplier_company_name,address,owner,authorized_representative,business_type,philgeps_certificate_path,philgeps_valid_until,business_permit_path,business_permit_valid_until,tax_clearance_certificate_path,tax_clearance_valid_until,registration_type,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$name,$address,$owner,$rep,$business,$ph,$phDate,$bp,$bpDate,$tax,$taxDate,$reg,(int)currentUser()['id'],(int)currentUser()['id']]);
        flash('success','Supplier added successfully.');
      }
      header('Location:supplier_registry.php');exit;
    }
  } catch(Throwable $e) { flash('error',$e->getMessage()); }
}

$search=trim((string)($_GET['search']??''));
$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0){$st=$pdo->prepare("SELECT * FROM suppliers WHERE id=?");$st->execute([$editId]);$editing=$st->fetch()?:null;}
if(!$editing)$editing=['id'=>0,'supplier_company_name'=>'','address'=>'','owner'=>'','authorized_representative'=>'','business_type'=>'','philgeps_certificate_path'=>null,'philgeps_valid_until'=>null,'business_permit_path'=>null,'business_permit_valid_until'=>null,'tax_clearance_certificate_path'=>null,'tax_clearance_valid_until'=>null,'registration_type'=>''];
$list=$pdo->prepare("SELECT * FROM suppliers WHERE supplier_company_name LIKE ? OR owner LIKE ? OR authorized_representative LIKE ? OR business_type LIKE ? ORDER BY supplier_company_name");
$like='%'.$search.'%';$list->execute([$like,$like,$like,$like]);$rows=$list->fetchAll();
pageStart('Supplier Registry');
?>
<div class="supplier-registry-page">
  <div class="panel supplier-content-panel">
  <div class="supplier-toolbar">
    <div>
      <h2 style="margin:0">Supplier Registry</h2>
      <p class="hint-text">Maintain the official registry of suppliers and their accreditation documents.</p>
    </div>
    <form class="supplier-search" method="get">
      <input class="input" type="search" name="search" value="<?=e($search)?>" placeholder="Search supplier, owner, representative...">
      <button class="btn secondary" type="submit">Search</button>
      <?php if($search!==''): ?><a class="btn secondary" href="supplier_registry.php">Clear</a><?php endif; ?>
      <?php if($isEditor): ?><a class="btn" href="supplier_registry.php">+ Add Supplier</a><?php endif; ?>
    </form>
  </div>

  <?php if($isEditor): ?>
  <form method="post" enctype="multipart/form-data" class="supplier-form">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=e((string)$editing['id'])?>">
    <div class="supplier-panel">
      <div class="supplier-panel-title"><span>1</span><div><b>Supplier Information</b><small>Basic company and representative details</small></div></div>
      <div class="supplier-fields">
        <div class="field supplier-wide"><label>Supplier/Company Name <span>*</span></label><input class="input" name="supplier_company_name" required value="<?=e($editing['supplier_company_name'])?>"></div>
        <div class="field supplier-wide"><label>Address</label><textarea class="input supplier-textarea" name="address" rows="3"><?=e($editing['address'])?></textarea></div>
        <div class="field"><label>Owner</label><input class="input" name="owner" value="<?=e($editing['owner'])?>"></div>
        <div class="field"><label>Authorized Representative</label><input class="input" name="authorized_representative" value="<?=e($editing['authorized_representative'])?>"></div>
        <div class="field"><label>Business Type</label><input class="input" name="business_type" value="<?=e($editing['business_type'])?>" placeholder="e.g. Sole Proprietorship, Corporation"></div>
      </div>
    </div>

    <div class="supplier-panel">
      <div class="supplier-panel-title"><span>2</span><div><b>Registration &amp; Compliance Documents</b><small>Upload certificates/permits and record their validity</small></div></div>
      <div class="supplier-document-grid">
        <div class="supplier-document-card">
          <div class="field"><label>PhilGEPS Membership Certificate</label><input type="file" name="philgeps_certificate" accept=".pdf,.jpg,.jpeg,.png"></div>
          <?php if(!empty($editing['philgeps_certificate_path'])): ?><div class="supplier-file"><a href="<?=e($editing['philgeps_certificate_path'])?>" target="_blank">View current certificate</a></div><?php endif; ?>
          <div class="field"><label>Valid Until</label><input class="input" type="date" name="philgeps_valid_until" value="<?=e($editing['philgeps_valid_until']??'')?>"></div>
        </div>
        <div class="supplier-document-card">
          <div class="field"><label>Mayor's/Business Permit</label><input type="file" name="business_permit" accept=".pdf,.jpg,.jpeg,.png"></div>
          <?php if(!empty($editing['business_permit_path'])): ?><div class="supplier-file"><a href="<?=e($editing['business_permit_path'])?>" target="_blank">View current permit</a></div><?php endif; ?>
          <div class="field"><label>Valid Until</label><input class="input" type="date" name="business_permit_valid_until" value="<?=e($editing['business_permit_valid_until']??'')?>"></div>
        </div>
        <div class="supplier-document-card">
          <div class="field"><label>Tax Clearance Certificate</label><input type="file" name="tax_clearance_certificate" accept=".pdf,.jpg,.jpeg,.png"></div>
          <?php if(!empty($editing['tax_clearance_certificate_path'])): ?><div class="supplier-file"><a href="<?=e($editing['tax_clearance_certificate_path'])?>" target="_blank">View current certificate</a></div><?php endif; ?>
          <div class="field"><label>Valid Until</label><input class="input" type="date" name="tax_clearance_valid_until" value="<?=e($editing['tax_clearance_valid_until']??'')?>"></div>
        </div>
      </div>
      <div class="field supplier-registration"><label>Registration Type</label><select class="select" name="registration_type"><option value="">Select Registration Type</option><option value="SEC" <?=$editing['registration_type']==='SEC'?'selected':''?>>SEC — Security Exchange Commission</option><option value="DTI" <?=$editing['registration_type']==='DTI'?'selected':''?>>DTI — Department of Trade and Industry</option><option value="CDA" <?=$editing['registration_type']==='CDA'?'selected':''?>>CDA — Cooperative Development Authority</option></select></div>
      <div class="supplier-form-actions"><a class="btn secondary" href="supplier_registry.php">Clear</a><button class="btn" type="submit"><?=$editing['id']?'Update Supplier':'Save Supplier'?></button></div>
    </div>
  </form>
  <?php endif; ?>

  <div class="supplier-list-panel">
    <div class="toolbar"><div><h2 style="margin:0">Registered Suppliers</h2><p class="hint-text"><?=number_format(count($rows))?> supplier<?=count($rows)===1?'':'s'?> found</p></div></div>
    <div class="table-wrap"><table class="table supplier-table">
      <tr><th>#</th><th>Supplier/Company Name</th><th>Address</th><th>Owner</th><th>Authorized Representative</th><th>Business Type</th><th>Registration</th><th>Documents</th><?php if($isEditor): ?><th>Action</th><?php endif; ?></tr>
      <?php $i=1;foreach($rows as $r): ?>
      <tr>
        <td><?=$i++?></td><td><b><?=e($r['supplier_company_name'])?></b></td><td><?=nl2br(e($r['address']))?></td><td><?=e($r['owner'])?></td><td><?=e($r['authorized_representative'])?></td><td><?=e($r['business_type'])?></td><td><?=e($r['registration_type']?:'—')?></td>
        <td class="supplier-doc-links"><?php if($r['philgeps_certificate_path']): ?><a href="<?=e($r['philgeps_certificate_path'])?>" target="_blank">PhilGEPS</a> <small><?=e($r['philgeps_valid_until']?:'No date')?></small><br><?php endif;?><?php if($r['business_permit_path']): ?><a href="<?=e($r['business_permit_path'])?>" target="_blank">Permit</a> <small><?=e($r['business_permit_valid_until']?:'No date')?></small><br><?php endif;?><?php if($r['tax_clearance_certificate_path']): ?><a href="<?=e($r['tax_clearance_certificate_path'])?>" target="_blank">Tax Clearance</a> <small><?=e($r['tax_clearance_valid_until']?:'No date')?></small><?php endif;?><?php if(!$r['philgeps_certificate_path']&&!$r['business_permit_path']&&!$r['tax_clearance_certificate_path']): ?>—<?php endif;?></td>
        <?php if($isEditor): ?><td><div class="supplier-actions"><a class="btn secondary" href="supplier_registry.php?edit=<?=(int)$r['id']?>">Edit</a><form method="post" onsubmit="return confirm('Delete this supplier and its uploaded documents?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn danger" type="submit">Delete</button></form></div></td><?php endif; ?>
      </tr>
      <?php endforeach; if(!$rows): ?><tr><td colspan="<?=$isEditor?9:8?>" class="empty">No suppliers found.</td></tr><?php endif; ?>
    </table></div>
  </div>
  </div>
</div>
<?php pageEnd(); ?>