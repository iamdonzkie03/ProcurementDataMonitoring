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
          <label>Category</label>
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
            <label>Category</label>
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