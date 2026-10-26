<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();
$canManage=hasRole(['Administrator','Editor']);
$uploadRoot=__DIR__.'/uploads';
$uploadWeb='uploads';
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS procurement_process_pdfs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    process_title VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    relative_path VARCHAR(700) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_process_title (process_title),
    INDEX idx_process_uploaded_at (uploaded_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Throwable $e) {}
function processPdfFolderName(string $title): string {
  $title=trim($title);
  $title=preg_replace('~[\\\\/:*?"<>|]+~u','-', $title);
  $title=preg_replace('~[\\x00-\\x1F]+~u','', $title);
  $title=preg_replace('~\\s+~u',' ', $title);
  $title=trim((string)$title," .-\t\n\r\0\x0B");
  if($title==='') throw new RuntimeException('Enter a valid process title.');
  if(function_exists('mb_substr')) $title=mb_substr($title,0,150,'UTF-8'); else $title=substr($title,0,150);
  return $title;
}
function processPdfRemove(string $relativePath,string $uploadRoot): bool {
  $relative=str_replace(chr(92),'/',ltrim($relativePath,'/'));
  if(!preg_match('~^uploads/[^/]+/[^/]+\\.pdf$~i',$relative)) return false;
  $base=realpath(__DIR__);
  if($base===false) return false;
  $target=$base.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
  $parent=realpath(dirname($target));
  if($parent===false || !str_starts_with($parent,$base.DIRECTORY_SEPARATOR)) return false;
  if(is_file($target) && !@unlink($target)) return false;
  if(is_dir($parent)){
    $items=@scandir($parent);
    if(is_array($items) && count($items)===2) @rmdir($parent);
  }
  return true;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  if(!$canManage){http_response_code(403);exit('403 - Access denied');}
  $action=(string)($_POST['action']??'');
  if($action==='upload_process_pdfs'){
    $title=trim((string)($_POST['process_title']??''));
    try {
      if($title==='') throw new RuntimeException('Process title is required.');
      if(strlen($title)>255) throw new RuntimeException('Process title must not exceed 255 characters.');
      if(empty($_FILES['process_pdfs']['name']) || !is_array($_FILES['process_pdfs']['name'])) throw new RuntimeException('Choose at least one PDF file to upload.');
      $folder=processPdfFolderName($title);
      $targetDir=$uploadRoot.DIRECTORY_SEPARATOR.$folder;
      if(!is_dir($targetDir) && !@mkdir($targetDir,0775,true) && !is_dir($targetDir)) throw new RuntimeException('Could not create the process folder under public/uploads. Check folder permissions.');
      $count=0;$errors=[];$total=count($_FILES['process_pdfs']['name']);
      for($i=0;$i<$total;$i++){
        $err=(int)($_FILES['process_pdfs']['error'][$i]??UPLOAD_ERR_NO_FILE);
        if($err===UPLOAD_ERR_NO_FILE) continue;
        $original=(string)($_FILES['process_pdfs']['name'][$i]??'');
        if($err!==UPLOAD_ERR_OK){$errors[]=$original.': upload failed.';continue;}
        $tmp=(string)($_FILES['process_pdfs']['tmp_name'][$i]??'');
        $size=(int)($_FILES['process_pdfs']['size'][$i]??0);
        if($size<=0 || $size>25*1024*1024){$errors[]=$original.': file must be between 1 byte and 25 MB.';continue;}
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        $mime='';if(function_exists('finfo_open')){$fi=finfo_open(FILEINFO_MIME_TYPE);if($fi){$mime=(string)finfo_file($fi,$tmp);finfo_close($fi);}}
        if($ext!=='pdf' || ($mime!=='' && !in_array($mime,['application/pdf','application/x-pdf','application/octet-stream'],true))){$errors[]=$original.': only PDF files are accepted.';continue;}
        $handle=@fopen($tmp,'rb');$signature=$handle?fread($handle,5):'';if($handle)fclose($handle);
        if($signature!=='%PDF-'){$errors[]=$original.': file content is not a valid PDF.';continue;}
        $baseName=pathinfo($original,PATHINFO_FILENAME);
        $safeName=preg_replace('~[^a-zA-Z0-9_-]+~','-', $baseName);
        $safeName=trim((string)$safeName,'-_');if($safeName==='')$safeName='process-document';
        $stored=$safeName.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.pdf';
        $target=$targetDir.DIRECTORY_SEPARATOR.$stored;
        if(!move_uploaded_file($tmp,$target)){$errors[]=$original.': could not save file.';continue;}
        try {
          $relative=$uploadWeb.'/'.$folder.'/'.$stored;
          $st=$pdo->prepare('INSERT INTO procurement_process_pdfs(process_title,original_filename,stored_filename,relative_path,file_size,uploaded_by) VALUES(?,?,?,?,?,?)');
          $st->execute([$title,substr($original,0,255),$stored,$relative,$size,(int)(currentUser()['id']??0) ?: null]);
          $count++;
        } catch(Throwable $e){@unlink($target);$errors[]=$original.': could not save file details.';}
      }
      if($count>0){$message=$count.' PDF file(s) uploaded to public/uploads/'.$folder.'/.';if($errors)$message.=' Some files were skipped: '.implode(' ',$errors);flash($errors?'error':'success',$message);}
      else {if(is_dir($targetDir)){$items=@scandir($targetDir);if(is_array($items)&&count($items)===2)@rmdir($targetDir);}throw new RuntimeException($errors?implode(' ',$errors):'Choose at least one PDF file to upload.');}
    } catch(Throwable $e){flash('error',$e->getMessage());}
    header('Location:procurement_workflow.php');exit;
  }
  if($action==='bulk_delete_process_pdfs'){
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['pdf_ids']??[])),static fn($id)=>$id>0)));
    if(!$ids){flash('error','Select at least one PDF file to delete.');header('Location:procurement_workflow.php');exit;}
    try {
      $marks=implode(',',array_fill(0,count($ids),'?'));
      $st=$pdo->prepare("SELECT id,relative_path FROM procurement_process_pdfs WHERE id IN ($marks)");
      $st->execute($ids);$selected=$st->fetchAll(PDO::FETCH_ASSOC);
      if(!$selected) throw new RuntimeException('No matching PDF files were found.');
      $delete=$pdo->prepare("DELETE FROM procurement_process_pdfs WHERE id IN ($marks)");$delete->execute(array_column($selected,'id'));
      $failed=[];foreach($selected as $item){if(!processPdfRemove((string)$item['relative_path'],$uploadRoot))$failed[]=basename((string)$item['relative_path']);}
      if($failed)flash('error',count($selected).' database record(s) deleted. Some files could not be removed from storage: '.implode(', ',$failed).'. Check folder permissions.');
      else flash('success',count($selected).' PDF file(s) deleted.');
    } catch(Throwable $e){flash('error','Unable to delete selected PDF files. '.$e->getMessage());}
    header('Location:procurement_workflow.php');exit;
  }
}
$rows=[];
try {$rows=$pdo->query('SELECT id,process_title,original_filename,relative_path,file_size,uploaded_at FROM procurement_process_pdfs ORDER BY uploaded_at DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);} catch(Throwable $e) {}
pageStart('Procurement Process Workflow');
?>
<style>
.process-pdf-page{max-width:1600px;margin:auto}.process-pdf-page *{box-sizing:border-box}.process-pdf-panel{background:#fff;border:1px solid #d9e4f0;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(21,49,80,.07);margin-bottom:18px}.process-pdf-heading{padding:22px 28px;background:linear-gradient(115deg,#102c50,#23578d);color:#fff}.process-pdf-heading h2{margin:0;color:#fff;font-size:clamp(22px,3vw,29px);font-weight:800}.process-pdf-heading p{margin:7px 0 0;color:#d9e8f7;font-size:13px}.process-pdf-body{padding:20px}.process-pdf-page .field label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#334b64}.process-pdf-page .input{width:100%;min-height:40px}.process-pdf-upload-grid{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.4fr);gap:14px;align-items:end}.process-pdf-upload-grid .field{margin:0}.process-pdf-help{font-size:12px;color:#687e93;line-height:1.5;margin-top:6px}.process-pdf-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.process-pdf-actions .btn{cursor:pointer}.process-pdf-list-top{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:18px 20px;border-bottom:1px solid #e2e9f1}.process-pdf-list-top h3{margin:0;font-size:18px;color:#203b57}.process-pdf-list-top p{margin:5px 0 0;font-size:12px;color:#6b7f92}.process-pdf-search{width:min(100%,270px)}.process-pdf-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid #e5ebf2;font-size:12px;color:#5b6f83}.process-pdf-page-size{display:flex;align-items:center;gap:7px}.process-pdf-page-size select{width:78px;min-height:32px}.process-pdf-page-buttons{display:flex;align-items:center;gap:5px}.process-pdf-page-buttons button{min-width:31px;padding:6px 9px}.process-pdf-page-buttons button.active{background:#20588e;color:#fff;border-color:#20588e}.process-pdf-table-wrap{overflow-x:auto}.process-pdf-table{width:100%;border-collapse:collapse;min-width:740px}.process-pdf-table th,.process-pdf-table td{padding:12px 14px;text-align:left;border-bottom:1px solid #e8edf3;font-size:13px;vertical-align:middle}.process-pdf-table th{background:#f6f9fc;color:#3e5872;font-size:12px;white-space:nowrap}.process-pdf-table tr:hover td{background:#fbfdff}.process-pdf-check{text-align:center!important;width:46px}.process-pdf-check input{width:15px;height:15px;cursor:pointer}.process-pdf-title{font-weight:700;color:#263f59}.process-pdf-file{display:inline-flex;align-items:center;gap:7px;color:#20588e;text-decoration:none;font-weight:600}.process-pdf-file:hover{text-decoration:underline}.process-pdf-meta{font-size:11px;color:#74869a;margin-top:4px}.process-pdf-bulk{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid #e5ebf2}.process-pdf-empty{padding:28px!important;text-align:center!important;color:#6b7f92}.process-pdf-footer{padding:11px 16px;color:#6b7f92;font-size:12px;background:#fbfcfe}.process-pdf-page .btn-danger{background:#c83d4b!important;color:#fff!important;border-color:#b72d3b!important}.process-pdf-page .btn-danger:hover{background:#aa2634!important}@media(max-width:700px){.process-pdf-body{padding:14px}.process-pdf-heading{padding:18px}.process-pdf-upload-grid{grid-template-columns:1fr}.process-pdf-list-top{padding:15px}.process-pdf-pagination{align-items:flex-start}.process-pdf-search{width:100%}}
</style>
<div class="process-pdf-page">
<section class="process-pdf-panel">
<header class="process-pdf-heading"><h2>Procurement Process Flowchart</h2><p>Upload and organize procurement process PDFs by process title. Each title has its own folder.</p></header>
<?php if($canManage): ?>
<div class="process-pdf-body">
<form method="post" enctype="multipart/form-data" id="processPdfUploadForm">
<input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="upload_process_pdfs">
<div class="process-pdf-upload-grid">
<div class="field"><label for="processTitle">Process Title <span aria-hidden="true">*</span></label><input class="input" id="processTitle" name="process_title" type="text" maxlength="255" placeholder="e.g., Procurement Planning Process" required><div class="process-pdf-help">This title names the folder created under public/uploads.</div></div>
<div class="field"><label for="processPdfs">PDF File(s) <span aria-hidden="true">*</span></label><input class="input" id="processPdfs" name="process_pdfs[]" type="file" accept="application/pdf,.pdf" multiple required><div class="process-pdf-help">Select multiple PDFs for the same process title. Maximum 25 MB per file.</div></div>
</div><div class="process-pdf-actions"><button type="submit" class="btn" id="processPdfUploadButton">Upload PDF File(s)</button><button type="reset" class="btn secondary">Clear</button></div>
</form>
</div>
<?php endif; ?>
</section>
<section class="process-pdf-panel">
<div class="process-pdf-list-top"><div><h3>Uploaded Procurement Processes</h3><p>Search, filter, open, and delete multiple PDF files.</p></div><div class="process-pdf-search"><input class="input" type="search" id="processPdfSearch" placeholder="Filter by process title or file..." aria-label="Filter PDF files"></div></div>
<div class="process-pdf-pagination"><div class="process-pdf-page-size"><label for="processPdfPageSize">Show</label><select class="input" id="processPdfPageSize"><option value="10">10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select><span>records</span></div><div id="processPdfPageInfo">Showing 0 records</div><div class="process-pdf-page-buttons" id="processPdfPageButtons"></div></div>
<form method="post" id="processPdfBulkForm" onsubmit="return confirmProcessPdfDelete()">
<input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="bulk_delete_process_pdfs">
<div class="process-pdf-bulk"><label><input type="checkbox" id="processPdfSelectAll"> Select all files on this page</label><?php if($canManage): ?><button type="submit" class="btn btn-danger" id="processPdfDeleteSelected">Delete Selected</button><?php endif; ?></div>
<div class="process-pdf-table-wrap"><table class="process-pdf-table" id="processPdfTable"><thead><tr><th class="process-pdf-check"><span class="sr-only">Select</span></th><th>Process Title</th><th>PDF File</th><th>File Size</th><th>Date Uploaded</th></tr></thead><tbody>
<?php foreach($rows as $row): $folder=basename(dirname((string)$row['relative_path']));$url=(string)$row['relative_path']; ?>
<tr data-title="<?=e(strtolower((string)$row['process_title']))?>" data-file="<?=e(strtolower((string)$row['original_filename']))?>">
<td class="process-pdf-check"><?php if($canManage): ?><input type="checkbox" class="process-pdf-row-check" name="pdf_ids[]" value="<?=(int)$row['id']?>" aria-label="Select <?=e((string)$row['original_filename'])?>"><?php else: ?><span>—</span><?php endif; ?></td>
<td><div class="process-pdf-title"><?=e((string)$row['process_title'])?></div><div class="process-pdf-meta">Folder: public/uploads/<?=e($folder)?>/</div></td>
<td><a class="process-pdf-file" href="<?=e($url)?>" target="_blank" rel="noopener"><span aria-hidden="true">📄</span><?=e((string)$row['original_filename'])?></a></td>
<td><?=number_format(((int)$row['file_size'])/1024,1)?> KB</td><td><?=e(date('M d, Y h:i A',strtotime((string)$row['uploaded_at'])))?></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr class="process-pdf-empty-row"><td class="process-pdf-empty" colspan="5">No procurement process PDFs have been uploaded yet.</td></tr><?php endif; ?>
</tbody></table></div>
</form>
<div class="process-pdf-footer" id="processPdfSummary"><?=count($rows)?> PDF file(s) total.</div>
</section>
</div>
<script>
(function(){
var table=document.getElementById('processPdfTable'),body=table&&table.tBodies[0];if(!body)return;
var rows=Array.prototype.slice.call(body.querySelectorAll('tr[data-title]')),search=document.getElementById('processPdfSearch'),size=document.getElementById('processPdfPageSize'),info=document.getElementById('processPdfPageInfo'),buttons=document.getElementById('processPdfPageButtons'),all=document.getElementById('processPdfSelectAll'),summary=document.getElementById('processPdfSummary'),page=1;
function visibleRows(){var q=(search.value||'').toLocaleLowerCase().trim();return rows.filter(function(r){return (r.dataset.title+' '+r.dataset.file).indexOf(q)!==-1})}
function render(){var matches=visibleRows(),per=parseInt(size.value,10)||10,pages=Math.max(1,Math.ceil(matches.length/per));page=Math.min(page,pages);rows.forEach(function(r){r.style.display='none'});matches.forEach(function(r,i){r.style.display=(i>=(page-1)*per&&i<page*per)?'':'none'});info.textContent=matches.length?'Showing '+((page-1)*per+1)+'–'+Math.min(page*per,matches.length)+' of '+matches.length:'Showing 0 records';summary.textContent=matches.length+' matching PDF file(s) · '+rows.length+' total';buttons.replaceChildren();function btn(label,target,disabled,active){var b=document.createElement('button');b.type='button';b.textContent=label;b.disabled=disabled;if(active)b.classList.add('active');b.addEventListener('click',function(){page=target;render()});buttons.appendChild(b)}btn('‹',Math.max(1,page-1),page===1,false);var start=Math.max(1,page-2),end=Math.min(pages,start+4);start=Math.max(1,end-4);for(var p=start;p<=end;p++)btn(String(p),p,false,p===page);btn('›',Math.min(pages,page+1),page===pages,false);if(all)all.checked=false}
if(search)search.addEventListener('input',function(){page=1;render()});size.addEventListener('change',function(){page=1;render()});
if(all)all.addEventListener('change',function(){visibleRows().forEach(function(r,i){if(i>=(page-1)*(parseInt(size.value,10)||10)&&i<page*(parseInt(size.value,10)||10)){var c=r.querySelector('.process-pdf-row-check');if(c)c.checked=all.checked}})});
body.addEventListener('change',function(ev){if(ev.target.classList.contains('process-pdf-row-check')&&!ev.target.checked&&all)all.checked=false});
window.confirmProcessPdfDelete=function(){var n=document.querySelectorAll('.process-pdf-row-check:checked').length;if(!n){alert('Select at least one PDF file first.');return false}return confirm('Delete '+n+' selected PDF file(s) and remove them from their process folders? This cannot be undone.')};
var form=document.getElementById('processPdfUploadForm');if(form)form.addEventListener('submit',function(){var b=document.getElementById('processPdfUploadButton');if(b){b.disabled=true;b.textContent='Uploading…'}});
render();
})();
</script>
<?php pageEnd(); ?>
