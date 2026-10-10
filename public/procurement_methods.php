<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);

try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS procurement_methods (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, procurement_method VARCHAR(255) NOT NULL, details TEXT NULL, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
}catch(PDOException $e){}
require_once __DIR__.'/../app/layout.php';
$embedded=!empty($embedded);
$pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    checkCsrf();
    try{
        $action=$_POST['action']??'';
        if($action==='bulk_delete'){
            $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected_ids']??[])),static fn($id)=>$id>0)));
            if(!$ids) throw new RuntimeException('Select at least one Procurement Method to delete.');
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $st=$pdo->prepare("DELETE FROM procurement_methods WHERE id IN ($placeholders)");
            $st->execute($ids);
            $deleted=$st->rowCount();
            if($deleted<1) throw new RuntimeException('No matching Procurement Methods were found.');
            flash('success',$deleted===1?'Procurement Method deleted.':$deleted.' Procurement Methods deleted.');
        }elseif($action==='save_bulk'){
            $names=array_map('trim',(array)($_POST['names']??[]));
            $validNames=[];
            foreach($names as $name){
                if($name==='') continue;
                if(mb_strlen($name,'UTF-8')>255) throw new RuntimeException('Procurement Method must not exceed 255 characters.');
                $validNames[]=$name;
            }
            if(!$validNames) throw new RuntimeException('Enter at least one Procurement Method.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('INSERT INTO procurement_methods(procurement_method) VALUES(?)');
            $added=0;
            foreach($validNames as $name){
                $check=$pdo->prepare('SELECT COUNT(*) FROM procurement_methods WHERE LOWER(TRIM(procurement_method))=LOWER(TRIM(?))');
                $check->execute([$name]);
                if((int)$check->fetchColumn()>0) throw new RuntimeException('Duplicate Procurement Method: '.$name);
                $st->execute([$name]);
                $added++;
            }
            $pdo->commit();
            flash('success',$added===1?'Procurement Method added.':$added.' Procurement Methods added.');
        }elseif($action==='save'){
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['procurement_method']??''));
            if($name==='') throw new RuntimeException('Procurement Method is required.');
            if(mb_strlen($name,'UTF-8')>255) throw new RuntimeException('Procurement Method must not exceed 120 characters.');
            $st=$pdo->prepare('UPDATE procurement_methods SET procurement_method=? WHERE id=?');
            $st->execute([$name,$id]);
            flash('success','Procurement Method updated.');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error',$e->getMessage());
    }
    header('Location:'.($embedded?'settings.php?tab=procurement-method':'procurement_methods.php'));exit;
}

$editing=null;
if(isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT id,procurement_method FROM procurement_methods WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editing=$st->fetch() ?: null;
}
$rows=$pdo->query('SELECT id,procurement_method FROM procurement_methods ORDER BY procurement_method')->fetchAll();
if(!$embedded) pageStart('Procurement Method');
?>
<div class="panel">
  <div class="toolbar procurement_method-page-header">
    <div class="procurement_method-page-title">
      <h2>Procurement Method</h2>
      <p class="muted">Manage procurement methods.</p>
    </div>
    <div class="procurement_method-search" id="procurement_methodSearchBox">
      <input class="input" type="text" id="procurement_methodSearchInput" autocomplete="off" placeholder="Search Method..." aria-label="Search Method">
      <div class="procurement_method-search-suggestions" id="procurement_methodSearchSuggestions" role="listbox"></div>
    </div>
  </div>

  <form method="post" id="procurement_methodManagementForm" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?=$editing?'save':'save_bulk'?>">
    <input type="hidden" name="id" value="<?=e((string)($editing['id']??0))?>">
    <?php if($editing): ?>
      <div class="procurement_method-form-row">
        <div class="field procurement-method-name-field">
          <label>Method Name*</label>
          <input class="input procurement-method-name-input" name="procurement_method" value="<?=e($editing['procurement_method']??'')?>" maxlength="255" required>
        </div>
        <div class="procurement_method-form-actions">
          <button class="btn" type="submit">Save Changes</button>
          <a class="btn secondary" href="<?=e($embedded?'settings.php?tab=procurement-method':'procurement_methods.php')?>">Cancel</a>
        </div>
      </div>
    <?php else: ?>
      <div id="procurement_methodRows">
        <div class="procurement_method-entry-row">
          <div class="field procurement_method-name-field">
            <label>Method Name*</label>
            <input class="input procurement_method-name-input" name="names[]" maxlength="255" required>
          </div>
          <div class="procurement_method-row-action"><button class="btn secondary procurement_method-add-row" type="button" id="addProcurementMethodRow">Add Row</button></div>
        </div>
      </div>
      <div class="procurement_method-save-actions">
        <button class="btn" type="submit" id="saveProcurementMethodItems" style="background:#198754;color:#fff;border-color:#198754">Save Method</button>
      </div>
    <?php endif; ?>
  </form>

  <div class="table-wrap" style="margin-top:22px">
  <form method="post" id="procurementMethodBulkDeleteForm" onsubmit="return confirmBulkProcurementMethodDelete();">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="bulk_delete">
    <div class="procurement_method-pagination" id="procurement_methodPagination" aria-label="Procurement Method pagination"><div class="procurement_method-page-size"><label for="procurement_methodPageSize">Show</label><select class="input" id="procurement_methodPageSize" aria-label="Records per page"><option value="10">10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select><span><label for="procurement_methodPageSize">records</label></span></div><div class="procurement_method-pagination-info" id="procurement_methodPaginationInfo"></div><div class="procurement_method-pagination-buttons" id="procurement_methodPaginationButtons"></div></div>
    <div class="procurement-method-selection-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px">
      <label style="display:flex;align-items:center;gap:7px;margin:0;font-size:13px"><input type="checkbox" id="selectAllProcurementMethods"> Select All</label>
      <button class="btn danger" type="submit" id="deleteSelectedProcurementMethods" disabled>Delete Selected</button>
    </div>
    <table class="table" id="procurement_methodTable">
      <thead><tr><th style="width:42px">Select</th><th>Procurement Method</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr data-procurement_method-id="<?=e((string)$r['id'])?>" data-procurement_method-name="<?=e($r['procurement_method'])?>">
          <td><input type="checkbox" class="procurement-method-select" name="selected_ids[]" value="<?=(int)$r['id']?>" aria-label="Select <?=e($r['procurement_method'])?>"></td>
          <td><b><?=e($r['procurement_method'])?></b></td>
          <td><a class="btn secondary master-action" href="<?=e($embedded?'settings.php?tab=procurement-method&edit='.(int)$r['id']:'procurement_methods.php?edit='.(int)$r['id'])?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="3" class="empty">No Procurement Methods have been added yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </form>
  </div>
</div>

<script>
function confirmBulkProcurementMethodDelete(){
  const selected=Array.from(document.querySelectorAll('.procurement-method-select:checked'));
  if(!selected.length){alert('Select at least one Procurement Method to delete.');return false;}
  const count=selected.length;
  return confirm(count===1?'Delete the selected Procurement Method?':'Delete all '+count+' selected Procurement Methods?');
}
document.addEventListener('DOMContentLoaded',function(){
  const selectAll=document.getElementById('selectAllProcurementMethods');
  const deleteButton=document.getElementById('deleteSelectedProcurementMethods');
  const checks=Array.from(document.querySelectorAll('.procurement-method-select'));
  function updateBulkSelection(){
    const selected=checks.filter(function(box){return box.checked;}).length;
    if(deleteButton)deleteButton.disabled=selected===0;
    if(selectAll){selectAll.checked=checks.length>0&&selected===checks.length;selectAll.indeterminate=selected>0&&selected<checks.length;}
  }
  if(selectAll)selectAll.addEventListener('change',function(){checks.forEach(function(box){box.checked=selectAll.checked;});updateBulkSelection();});
  checks.forEach(function(box){box.addEventListener('change',updateBulkSelection);});
  updateBulkSelection();
});
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const rows=document.getElementById('procurement_methodRows');
  const add=document.getElementById('addProcurementMethodRow');
  const save=document.getElementById('saveProcurementMethodItems');
  if(!rows)return;
  function updateSaveLabel(){
    if(!save)return;
    const count=rows.querySelectorAll('.procurement_method-entry-row').length;
    save.textContent=count>=2?'Save Methods':'Save Method';
  }
  if(add){
    add.addEventListener('click',function(){
      const first=rows.querySelector('.procurement_method-entry-row');
      if(!first)return;
      const row=first.cloneNode(true);
      row.querySelector('input').value='';
      const action=row.querySelector('.procurement_method-row-action');
      if(action){
        action.innerHTML='<button class="btn danger procurement_method-row-remove" type="button">Remove</button>';
        action.querySelector('.procurement_method-row-remove').addEventListener('click',function(){
          row.remove();
          updateSaveLabel();
        });
      }
      rows.appendChild(row);
      updateSaveLabel();
      const input=row.querySelector('.procurement_method-name-input');
      if(input)input.focus();
    });
  }
  updateSaveLabel();
});
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const input=document.getElementById('procurement_methodSearchInput');
  const suggestions=document.getElementById('procurement_methodSearchSuggestions');
  const table=document.getElementById('procurement_methodTable');
  const pageSizeSelect=document.getElementById('procurement_methodPageSize');
  const paginationInfo=document.getElementById('procurement_methodPaginationInfo');
  const paginationButtons=document.getElementById('procurement_methodPaginationButtons');
  if(!input||!suggestions||!table||!pageSizeSelect||!paginationInfo||!paginationButtons)return;
  const tableRows=Array.from(table.querySelectorAll('tr[data-procurement_method-id]'));
  let filteredRows=tableRows.slice(),currentPage=1,pageSize=Number(pageSizeSelect.value)||10;
  function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
  function closeSuggestions(){suggestions.innerHTML='';suggestions.style.display='none';}
  function getName(row){return String(row.dataset.procurement_methodName||'');}
  function renderPagination(){
    const total=filteredRows.length,totalPages=Math.max(1,Math.ceil(total/pageSize));
    if(currentPage>totalPages)currentPage=totalPages;
    tableRows.forEach(function(row){row.style.display='none';row.classList.remove('procurement_method-search-selected');});
    const start=(currentPage-1)*pageSize;
    filteredRows.slice(start,start+pageSize).forEach(function(row){row.style.display='';});
    paginationInfo.textContent=total?'Showing '+(start+1)+'-'+Math.min(start+pageSize,total)+' of '+total+' records':'0 records';
    paginationButtons.innerHTML='';
    function addButton(label,page,disabled,active){
      const b=document.createElement('button');b.type='button';b.className='btn secondary procurement_method-page-button'+(active?' active':'');
      b.textContent=label;b.disabled=!!disabled;b.addEventListener('click',function(){currentPage=page;renderPagination();});
      paginationButtons.appendChild(b);
    }
    addButton('Previous',currentPage-1,currentPage===1,false);
    const max=7;let first=Math.max(1,currentPage-3),last=Math.min(totalPages,first+max-1);first=Math.max(1,last-max+1);
    for(let page=first;page<=last;page++)addButton(String(page),page,false,page===currentPage);
    addButton('Next',currentPage+1,currentPage===totalPages,false);
  }
  function applyFilter(term,selected,reset){
    const value=String(term||'').trim().toLowerCase();
    filteredRows=tableRows.filter(function(row){
      const name=getName(row).toLowerCase();
      return selected?name===selected.toLowerCase():(!value||name.includes(value));
    });
    if(reset)currentPage=1;
    renderPagination();
    if(selected)filteredRows.forEach(function(row){row.classList.add('procurement_method-search-selected');});
  }
  function showSuggestions(){
    const term=String(input.value||'').trim().toLowerCase();suggestions.innerHTML='';
    if(!term){closeSuggestions();applyFilter('',null,true);return;}
    const matches=tableRows.filter(function(row){return getName(row).toLowerCase().includes(term);}).slice(0,10);
    if(!matches.length){
      suggestions.innerHTML='<div class="procurement_method-search-empty">No matching method found.</div>';
      suggestions.style.display='block';applyFilter(term,null,true);return;
    }
    matches.forEach(function(row){
      const button=document.createElement('button');button.type='button';button.className='procurement_method-search-suggestion';
      button.setAttribute('role','option');button.dataset.name=getName(row);button.innerHTML=escapeHtml(getName(row));suggestions.appendChild(button);
    });
    suggestions.style.display='block';applyFilter(term,null,true);
  }
  input.addEventListener('input',showSuggestions);
  input.addEventListener('focus',function(){if(input.value.trim())showSuggestions();});
  input.addEventListener('keydown',function(e){if(e.key==='Escape'){input.value='';closeSuggestions();applyFilter('',null,true);}});
  suggestions.addEventListener('click',function(e){
    const button=e.target.closest('.procurement_method-search-suggestion');if(!button)return;
    const name=button.dataset.name||'';input.value=name;closeSuggestions();applyFilter(name,name,true);
  });
  pageSizeSelect.addEventListener('change',function(){pageSize=Number(pageSizeSelect.value)||10;currentPage=1;renderPagination();});
  document.addEventListener('click',function(e){const box=document.getElementById('procurement_methodSearchBox');if(box&&!box.contains(e.target))closeSuggestions();});
  renderPagination();
});
</script>
<style>
.procurement_method-page-header{display:flex;align-items:center;justify-content:space-between;gap:18px}
.procurement_method-page-title{min-width:0}
.procurement_method-page-title h2{margin:0}
.procurement_method-page-title .muted{margin:4px 0 0}
.procurement_method-search{position:relative;width:200px;min-width:200px;margin:0 0 0 auto}
.procurement_method-search .input{width:200px;box-sizing:border-box}
.procurement_method-search-suggestions{position:absolute;left:0;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #cfd6df;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;display:none}
.procurement_method-search-suggestion{display:block;width:100%;padding:9px 12px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:14px}
.procurement_method-search-suggestion:hover,.procurement_method-search-suggestion:focus{background:#eef5ff}
.procurement_method-search-empty{padding:9px 12px;color:#6b7280;font-size:13px}
#procurement_methodTable tr.procurement_method-search-selected td{background:#eef5ff}
.procurement_method-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;width:100%;clear:both;position:static;float:none;margin:0 0 10px;padding:0;box-sizing:border-box}
.procurement_method-pagination-info{flex:0 0 auto;text-align:right;font-size:13px;color:#6b7280}
.procurement_method-page-size{display:flex;align-items:center;gap:6px;font-size:14px;color:#4b5563}
.procurement_method-page-size .input{width:78px;min-width:78px;height:36px}
.procurement_method-pagination-buttons{display:flex;align-items:center;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.procurement_method-page-button{min-width:38px;height:36px;padding:0 10px;background:#cfe8ff;color:#1f5f85;border-color:#b7d9f5}
.procurement_method-page-button:hover:not(:disabled):not(.active){background:#b9dcfa;color:#174a69}
.procurement_method-page-button.active{font-weight:700;pointer-events:none;background:#0d6efd;color:#fff;border-color:#0d6efd}
.procurement_method-pagination-buttons .procurement_method-page-button:first-child,.procurement_method-pagination-buttons .procurement_method-page-button:last-child{background:#495057;color:#fff;border-color:#495057}
.procurement_method-pagination-buttons .procurement_method-page-button:first-child:hover:not(:disabled),.procurement_method-pagination-buttons .procurement_method-page-button:last-child:hover:not(:disabled){background:#343a40;color:#fff;border-color:#343a40}
.procurement_method-pagination-buttons .procurement_method-page-button:disabled{opacity:.7;cursor:not-allowed}
@media(max-width:700px){.procurement_method-pagination{align-items:flex-start;justify-content:flex-end;flex-wrap:wrap}.procurement_method-pagination-info{order:3;flex-basis:100%;text-align:right}}
.procurement_method-entry-row{display:flex;gap:8px;align-items:end;margin-bottom:8px}
.procurement_method-name-field{flex:1;margin:0}
.procurement_method-row-action{height:36px;display:flex;align-items:center}
.procurement_method-add-row,.procurement_method-row-remove{height:36px;white-space:nowrap}
.procurement_method-save-actions{margin-top:10px;display:flex;justify-content:flex-start;text-align:left}
.procurement_method-save-actions .btn{margin-left:0}
.procurement_method-form-row{display:flex;align-items:flex-end;gap:12px;width:100%;box-sizing:border-box}
.procurement_method-form-row .procurement-method-name-field{flex:1 1 auto;min-width:0;margin:0}
.procurement_method-form-row .procurement-method-name-input{width:100%;box-sizing:border-box}
.procurement_method-form-actions{display:flex;gap:8px;align-items:center;flex:0 0 auto;white-space:nowrap}
.procurement_method-form-actions .btn{white-space:nowrap}
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
</style>
<?php if(!$embedded) pageEnd();
