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
        if($action==='delete'){
            $id=(int)($_POST['id']??0);
            if($id<=0) throw new RuntimeException('Invalid Procurement Method.');
            $st=$pdo->prepare('DELETE FROM procurement_methods WHERE id=?');
            $st->execute([$id]);
            flash('success','Procurement Method deleted.');
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
            $details=trim((string)($_POST['details']??''));
            if($name==='') throw new RuntimeException('Procurement Method is required.');
            if(mb_strlen($name,'UTF-8')>255) throw new RuntimeException('Procurement Method must not exceed 120 characters.');
            $st=$pdo->prepare('UPDATE procurement_methods SET procurement_method=?,details=? WHERE id=?');
            $st->execute([$name,$details!==''?$details:null,$id]);
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
    $st=$pdo->prepare('SELECT id,procurement_method,details FROM procurement_methods WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editing=$st->fetch() ?: null;
}
$rows=$pdo->query('SELECT id,procurement_method,details FROM procurement_methods ORDER BY procurement_method')->fetchAll();
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
        <div class="field procurement-method-details-field"><label>Details</label><input class="input" name="details" value="<?=e($editing['details']??'')?>"></div>
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
    <table class="table" id="procurement_methodTable">
      <thead><tr><th>Procurement Method</th><th>Details</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr data-procurement_method-id="<?=e((string)$r['id'])?>" data-procurement_method-name="<?=e($r['procurement_method'])?>">
          <td><b><?=e($r['procurement_method'])?></b></td>
          <td><?=nl2br(e($r['details']??''))?></td>
          <td>
            <a class="btn secondary master-action" href="<?=e($embedded?'settings.php?tab=procurement-method&edit='.(int)$r['id']:'procurement_methods.php?edit='.(int)$r['id'])?>">Edit</a>
            <form method="post" class="master-action-form" onsubmit="return confirm('Delete this Procurement Method?');">
              <input type="hidden" name="csrf" value="<?=e(csrf())?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <button class="btn danger master-action" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="3" class="empty">No Procurement Methods have been added yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

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
  if(!input||!suggestions||!table)return;

  const tableRows=Array.from(table.querySelectorAll('tr[data-procurement_method-id]'));

  function escapeHtml(value){
    return String(value??'').replace(/[&<>"']/g,function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }

  function closeSuggestions(){
    suggestions.innerHTML='';
    suggestions.style.display='none';
  }

  function showAllRows(){
    tableRows.forEach(function(row){
      row.style.display='';
      row.classList.remove('procurement_method-search-selected');
    });
  }

  function filterRows(term,selectedName){
    const value=String(term||'').trim().toLowerCase();
    let visible=0;
    tableRows.forEach(function(row){
      const name=String(row.dataset.procurement_methodName||'');
      const match=selectedName
        ? name.toLowerCase()===selectedName.toLowerCase()
        : (!value || name.toLowerCase().includes(value));
      row.style.display=match?'':'none';
      row.classList.toggle('procurement_method-search-selected',!!selectedName && match);
      if(match)visible++;
    });
    return visible;
  }

  function showSuggestions(){
    const term=String(input.value||'').trim().toLowerCase();
    suggestions.innerHTML='';
    if(!term){
      closeSuggestions();
      showAllRows();
      return;
    }

    const matches=tableRows
      .map(function(row){return {row:row,name:String(row.dataset.procurement_methodName||'')};})
      .filter(function(item){return item.name.toLowerCase().includes(term);})
      .slice(0,10);

    if(!matches.length){
      suggestions.innerHTML='<div class="procurement_method-search-empty">No matching method found.</div>';
      suggestions.style.display='block';
      filterRows(term,null);
      return;
    }

    matches.forEach(function(item){
      const button=document.createElement('button');
      button.type='button';
      button.className='procurement_method-search-suggestion';
      button.setAttribute('role','option');
      button.dataset.procurement_methodId=item.row.dataset.procurement_methodId||'';
      button.dataset.procurement_methodName=item.name;
      button.innerHTML=escapeHtml(item.name);
      suggestions.appendChild(button);
    });
    suggestions.style.display='block';
    filterRows(term,null);
  }

  input.addEventListener('input',showSuggestions);
  input.addEventListener('focus',function(){
    if(input.value.trim())showSuggestions();
  });
  input.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
      input.value='';
      closeSuggestions();
      showAllRows();
    }
  });

  suggestions.addEventListener('click',function(e){
    const button=e.target.closest('.procurement_method-search-suggestion');
    if(!button)return;
    const name=button.dataset.procurement_methodName||'';
    input.value=name;
    closeSuggestions();
    filterRows(name,name);
    const selected=tableRows.find(function(row){
      return String(row.dataset.procurement_methodName||'').toLowerCase()===name.toLowerCase();
    });
    if(selected){
      selected.scrollIntoView({behavior:'smooth',block:'center'});
    }
  });

  document.addEventListener('click',function(e){
    const box=document.getElementById('procurement_methodSearchBox');
    if(box&&!box.contains(e.target))closeSuggestions();
  });
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
.procurement_method-entry-row{display:flex;gap:8px;align-items:end;margin-bottom:8px}
.procurement_method-name-field{flex:1;margin:0}
.procurement_method-row-action{height:36px;display:flex;align-items:center}
.procurement_method-add-row,.procurement_method-row-remove{height:36px;white-space:nowrap}
.procurement_method-save-actions{margin-top:10px;display:flex;justify-content:flex-start;text-align:left}
.procurement_method-save-actions .btn{margin-left:0}
.procurement_method-form-row{display:flex;align-items:flex-end;gap:12px}
.procurement_method-form-actions{display:flex;gap:8px;align-items:center}
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
</style>
<?php if(!$embedded) pageEnd();