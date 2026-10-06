<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';
$embedded=!empty($embedded);
$pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    checkCsrf();
    try{
        $action=$_POST['action']??'';
        if($action==='delete'){
            $id=(int)($_POST['id']??0);
            if($id<=0) throw new RuntimeException('Invalid Category.');
            $st=$pdo->prepare('DELETE FROM categories WHERE id=?');
            $st->execute([$id]);
            flash('success','Category deleted.');
        }elseif($action==='save_bulk'){
            $names=array_map('trim',(array)($_POST['names']??[]));
            $validNames=[];
            foreach($names as $name){
                if($name==='') continue;
                if(mb_strlen($name,'UTF-8')>120) throw new RuntimeException('Category must not exceed 120 characters.');
                $validNames[]=$name;
            }
            if(!$validNames) throw new RuntimeException('Enter at least one Category.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('INSERT INTO categories(name) VALUES(?)');
            $added=0;
            foreach($validNames as $name){
                $check=$pdo->prepare('SELECT COUNT(*) FROM categories WHERE LOWER(TRIM(name))=LOWER(TRIM(?))');
                $check->execute([$name]);
                if((int)$check->fetchColumn()>0) throw new RuntimeException('Duplicate Category: '.$name);
                $st->execute([$name]);
                $added++;
            }
            $pdo->commit();
            flash('success',$added===1?'Category added.':$added.' Categories added.');
        }elseif($action==='save'){
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['name']??''));
            if($name==='') throw new RuntimeException('Category is required.');
            if(mb_strlen($name,'UTF-8')>120) throw new RuntimeException('Category must not exceed 120 characters.');
            $st=$pdo->prepare('UPDATE categories SET name=? WHERE id=?');
            $st->execute([$name,$id]);
            flash('success','Category updated.');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error',$e->getMessage());
    }
    header('Location:'.($embedded?'settings.php?tab=category':'categories.php'));exit;
}

$editing=null;
if(isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT id,name FROM categories WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editing=$st->fetch() ?: null;
}
$rows=$pdo->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
if(!$embedded) pageStart('Category');
?>
<div class="panel">
  <div class="toolbar category-page-header">
    <div class="category-page-title">
      <h2>Category</h2>
      <p class="muted">Manage procurement categories.</p>
    </div>
    <div class="category-search" id="categorySearchBox">
      <input class="input" type="text" id="categorySearchInput" autocomplete="off" placeholder="Search Category..." aria-label="Search Category">
      <div class="category-search-suggestions" id="categorySearchSuggestions" role="listbox"></div>
    </div>
  </div>

  <form method="post" id="categoryManagementForm" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?=$editing?'save':'save_bulk'?>">
    <input type="hidden" name="id" value="<?=e((string)($editing['id']??0))?>">
    <?php if($editing): ?>
      <div class="category-form-row">
        <div class="field category-name-field">
          <label>Category Name*</label>
          <input class="input category-name-input" name="name" value="<?=e($editing['name']??'')?>" maxlength="120" required>
        </div>
        <div class="category-form-actions">
          <button class="btn" type="submit">Save Changes</button>
          <a class="btn secondary" href="<?=e($embedded?'settings.php?tab=category':'categories.php')?>">Cancel</a>
        </div>
      </div>
    <?php else: ?>
      <div id="categoryRows">
        <div class="category-entry-row">
          <div class="field category-name-field">
            <label>Category Name*</label>
            <input class="input category-name-input" name="names[]" maxlength="120" required>
          </div>
          <div class="category-row-action"><button class="btn secondary category-add-row" type="button" id="addCategoryRow">Add Row</button></div>
        </div>
      </div>
      <div class="category-save-actions">
        <button class="btn" type="submit" id="saveCategoryItems" style="background:#198754;color:#fff;border-color:#198754">Save Category</button>
      </div>
    <?php endif; ?>
  </form>

  <div class="table-wrap" style="margin-top:22px">
  <div class="category-pagination" id="categoryPagination" aria-label="Category pagination"><div class="category-page-size"><label for="categoryPageSize">Show</label><select class="input" id="categoryPageSize" aria-label="Records per page"><option value="10">10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select><span><label for="categoryPageSize">records</label></span></div><div class="category-pagination-info" id="categoryPaginationInfo"></div><div class="category-pagination-buttons" id="categoryPaginationButtons"></div></div>
    <table class="table" id="categoryTable">
      <thead><tr><th>Category</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr data-category-id="<?=e((string)$r['id'])?>" data-category-name="<?=e($r['name'])?>">
          <td><b><?=e($r['name'])?></b></td>
          <td>
            <a class="btn secondary master-action" href="<?=e($embedded?'settings.php?tab=category&edit='.(int)$r['id']:'categories.php?edit='.(int)$r['id'])?>">Edit</a>
            <form method="post" class="master-action-form" onsubmit="return confirm('Delete this Category?');">
              <input type="hidden" name="csrf" value="<?=e(csrf())?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <button class="btn danger master-action" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="2" class="empty">No Categories have been added yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
  const rows=document.getElementById('categoryRows');
  const add=document.getElementById('addCategoryRow');
  const save=document.getElementById('saveCategoryItems');
  if(!rows)return;
  function updateSaveLabel(){
    if(!save)return;
    const count=rows.querySelectorAll('.category-entry-row').length;
    save.textContent=count>=2?'Save Categories':'Save Category';
  }
  if(add){
    add.addEventListener('click',function(){
      const first=rows.querySelector('.category-entry-row');
      if(!first)return;
      const row=first.cloneNode(true);
      row.querySelector('input').value='';
      const action=row.querySelector('.category-row-action');
      if(action){
        action.innerHTML='<button class="btn danger category-row-remove" type="button">Remove</button>';
        action.querySelector('.category-row-remove').addEventListener('click',function(){
          row.remove();
          updateSaveLabel();
        });
      }
      rows.appendChild(row);
      updateSaveLabel();
      const input=row.querySelector('.category-name-input');
      if(input)input.focus();
    });
  }
  updateSaveLabel();
});
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const input=document.getElementById('categorySearchInput');
  const suggestions=document.getElementById('categorySearchSuggestions');
  const table=document.getElementById('categoryTable');
  const pageSizeSelect=document.getElementById('categoryPageSize');
  const paginationInfo=document.getElementById('categoryPaginationInfo');
  const paginationButtons=document.getElementById('categoryPaginationButtons');
  if(!input||!suggestions||!table||!pageSizeSelect||!paginationInfo||!paginationButtons)return;
  const tableRows=Array.from(table.querySelectorAll('tr[data-category-id]'));
  let filteredRows=tableRows.slice(),currentPage=1,pageSize=Number(pageSizeSelect.value)||10;
  function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
  function closeSuggestions(){suggestions.innerHTML='';suggestions.style.display='none';}
  function getName(row){return String(row.dataset.categoryName||'');}
  function renderPagination(){
    const total=filteredRows.length,totalPages=Math.max(1,Math.ceil(total/pageSize));
    if(currentPage>totalPages)currentPage=totalPages;
    tableRows.forEach(function(row){row.style.display='none';row.classList.remove('category-search-selected');});
    const start=(currentPage-1)*pageSize;
    filteredRows.slice(start,start+pageSize).forEach(function(row){row.style.display='';});
    paginationInfo.textContent=total?'Showing '+(start+1)+'-'+Math.min(start+pageSize,total)+' of '+total+' records':'0 records';
    paginationButtons.innerHTML='';
    function addButton(label,page,disabled,active){
      const b=document.createElement('button');b.type='button';b.className='btn secondary category-page-button'+(active?' active':'');
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
    if(selected)filteredRows.forEach(function(row){row.classList.add('category-search-selected');});
  }
  function showSuggestions(){
    const term=String(input.value||'').trim().toLowerCase();suggestions.innerHTML='';
    if(!term){closeSuggestions();applyFilter('',null,true);return;}
    const matches=tableRows.filter(function(row){return getName(row).toLowerCase().includes(term);}).slice(0,10);
    if(!matches.length){
      suggestions.innerHTML='<div class="category-search-empty">No matching category found.</div>';
      suggestions.style.display='block';applyFilter(term,null,true);return;
    }
    matches.forEach(function(row){
      const button=document.createElement('button');button.type='button';button.className='category-search-suggestion';
      button.setAttribute('role','option');button.dataset.name=getName(row);button.innerHTML=escapeHtml(getName(row));suggestions.appendChild(button);
    });
    suggestions.style.display='block';applyFilter(term,null,true);
  }
  input.addEventListener('input',showSuggestions);
  input.addEventListener('focus',function(){if(input.value.trim())showSuggestions();});
  input.addEventListener('keydown',function(e){if(e.key==='Escape'){input.value='';closeSuggestions();applyFilter('',null,true);}});
  suggestions.addEventListener('click',function(e){
    const button=e.target.closest('.category-search-suggestion');if(!button)return;
    const name=button.dataset.name||'';input.value=name;closeSuggestions();applyFilter(name,name,true);
  });
  pageSizeSelect.addEventListener('change',function(){pageSize=Number(pageSizeSelect.value)||10;currentPage=1;renderPagination();});
  document.addEventListener('click',function(e){const box=document.getElementById('categorySearchBox');if(box&&!box.contains(e.target))closeSuggestions();});
  renderPagination();
});
</script>
<style>
.category-page-header{display:flex;align-items:center;justify-content:space-between;gap:18px}
.category-page-title{min-width:0}
.category-page-title h2{margin:0}
.category-page-title .muted{margin:4px 0 0}
.category-search{position:relative;width:200px;min-width:200px;margin:0 0 0 auto}
.category-search .input{width:200px;box-sizing:border-box}
.category-search-suggestions{position:absolute;left:0;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #cfd6df;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;display:none}
.category-search-suggestion{display:block;width:100%;padding:9px 12px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:14px}
.category-search-suggestion:hover,.category-search-suggestion:focus{background:#eef5ff}
.category-search-empty{padding:9px 12px;color:#6b7280;font-size:13px}
#categoryTable tr.category-search-selected td{background:#eef5ff}
.category-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;width:100%;clear:both;position:static;float:none;margin:0 0 10px;padding:0;box-sizing:border-box}
.category-pagination-info{flex:0 0 auto;text-align:right;font-size:13px;color:#6b7280}
.category-page-size{display:flex;align-items:center;gap:6px;font-size:14px;color:#4b5563}
.category-page-size .input{width:78px;min-width:78px;height:36px}
.category-pagination-buttons{display:flex;align-items:center;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.category-page-button{min-width:38px;height:36px;padding:0 10px;background:#cfe8ff;color:#1f5f85;border-color:#b7d9f5}
.category-page-button:hover:not(:disabled):not(.active){background:#b9dcfa;color:#174a69}
.category-page-button.active{font-weight:700;pointer-events:none;background:#0d6efd;color:#fff;border-color:#0d6efd}
.category-pagination-buttons .category-page-button:first-child,.category-pagination-buttons .category-page-button:last-child{background:#495057;color:#fff;border-color:#495057}
.category-pagination-buttons .category-page-button:first-child:hover:not(:disabled),.category-pagination-buttons .category-page-button:last-child:hover:not(:disabled){background:#343a40;color:#fff;border-color:#343a40}
.category-pagination-buttons .category-page-button:disabled{opacity:.7;cursor:not-allowed}
@media(max-width:700px){.category-pagination{align-items:flex-start;justify-content:flex-end;flex-wrap:wrap}.category-pagination-info{order:3;flex-basis:100%;text-align:right}}
.category-entry-row{display:flex;gap:8px;align-items:end;margin-bottom:8px}
.category-name-field{flex:1;margin:0}
.category-row-action{height:36px;display:flex;align-items:center}
.category-add-row,.category-row-remove{height:36px;white-space:nowrap}
.category-save-actions{margin-top:10px;display:flex;justify-content:flex-start;text-align:left}
.category-save-actions .btn{margin-left:0}
.category-form-row{display:flex;align-items:flex-end;gap:12px}
.category-form-actions{display:flex;gap:8px;align-items:center}
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
</style>
<?php if(!$embedded) pageEnd();
