<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);

try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS classifications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
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
            if($id<=0) throw new RuntimeException('Invalid Classification.');
            $st=$pdo->prepare('DELETE FROM classifications WHERE id=?');
            $st->execute([$id]);
            flash('success','Classification deleted.');
        }elseif($action==='save_bulk'){
            $names=array_map('trim',(array)($_POST['names']??[]));
            $validNames=[];
            foreach($names as $name){
                if($name==='') continue;
                if(mb_strlen($name,'UTF-8')>120) throw new RuntimeException('Classification must not exceed 120 characters.');
                $validNames[]=$name;
            }
            if(!$validNames) throw new RuntimeException('Enter at least one Classification.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('INSERT INTO classifications(name) VALUES(?)');
            $added=0;
            foreach($validNames as $name){
                $check=$pdo->prepare('SELECT COUNT(*) FROM classifications WHERE LOWER(TRIM(name))=LOWER(TRIM(?))');
                $check->execute([$name]);
                if((int)$check->fetchColumn()>0) throw new RuntimeException('Duplicate Classification: '.$name);
                $st->execute([$name]);
                $added++;
            }
            $pdo->commit();
            flash('success',$added===1?'Classification added.':$added.' Categories added.');
        }elseif($action==='save'){
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['name']??''));
            if($name==='') throw new RuntimeException('Classification is required.');
            if(mb_strlen($name,'UTF-8')>120) throw new RuntimeException('Classification must not exceed 120 characters.');
            $st=$pdo->prepare('UPDATE classifications SET name=? WHERE id=?');
            $st->execute([$name,$id]);
            flash('success','Classification updated.');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('error',$e->getMessage());
    }
    header('Location:'.($embedded?'settings.php?tab=classification':'classifications.php'));exit;
}

$editing=null;
if(isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT id,name FROM classifications WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editing=$st->fetch() ?: null;
}
$rows=$pdo->query('SELECT id,name FROM classifications ORDER BY name')->fetchAll();
if(!$embedded) pageStart('Classification');
?>
<div class="panel">
  <div class="toolbar classification-page-header">
    <div class="classification-page-title">
      <h2>Classification</h2>
      <p class="muted">Manage procurement classifications.</p>
    </div>
    <div class="classification-search" id="classificationSearchBox">
      <input class="input" type="text" id="classificationSearchInput" autocomplete="off" placeholder="Search Classification..." aria-label="Search Classification">
      <div class="classification-search-suggestions" id="classificationSearchSuggestions" role="listbox"></div>
    </div>
  </div>

  <form method="post" id="classificationManagementForm" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?=$editing?'save':'save_bulk'?>">
    <input type="hidden" name="id" value="<?=e((string)($editing['id']??0))?>">
    <?php if($editing): ?>
      <div class="classification-form-row">
        <div class="field classification-name-field">
          <label>Classification Name*</label>
          <input class="input classification-name-input" name="name" value="<?=e($editing['name']??'')?>" maxlength="120" required>
        </div>
        <div class="classification-form-actions">
          <button class="btn" type="submit">Save Changes</button>
          <a class="btn secondary" href="<?=e($embedded?'settings.php?tab=classification':'classifications.php')?>">Cancel</a>
        </div>
      </div>
    <?php else: ?>
      <div id="classificationRows">
        <div class="classification-entry-row">
          <div class="field classification-name-field">
            <label>Classification Name*</label>
            <input class="input classification-name-input" name="names[]" maxlength="120" required>
          </div>
          <div class="classification-row-action"><button class="btn secondary classification-add-row" type="button" id="addClassificationRow">Add Row</button></div>
        </div>
      </div>
      <div class="classification-save-actions">
        <button class="btn" type="submit" id="saveClassificationItems" style="background:#198754;color:#fff;border-color:#198754">Save Classification</button>
      </div>
    <?php endif; ?>
  </form>

  <div class="table-wrap" style="margin-top:22px">
    <table class="table" id="classificationTable">
      <thead><tr><th>Classification</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr data-classification-id="<?=e((string)$r['id'])?>" data-classification-name="<?=e($r['name'])?>">
          <td><b><?=e($r['name'])?></b></td>
          <td>
            <a class="btn secondary master-action" href="<?=e($embedded?'settings.php?tab=classification&edit='.(int)$r['id']:'classifications.php?edit='.(int)$r['id'])?>">Edit</a>
            <form method="post" class="master-action-form" onsubmit="return confirm('Delete this Classification?');">
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
  const rows=document.getElementById('classificationRows');
  const add=document.getElementById('addClassificationRow');
  const save=document.getElementById('saveClassificationItems');
  if(!rows)return;
  function updateSaveLabel(){
    if(!save)return;
    const count=rows.querySelectorAll('.classification-entry-row').length;
    save.textContent=count>=2?'Save Categories':'Save Classification';
  }
  if(add){
    add.addEventListener('click',function(){
      const first=rows.querySelector('.classification-entry-row');
      if(!first)return;
      const row=first.cloneNode(true);
      row.querySelector('input').value='';
      const action=row.querySelector('.classification-row-action');
      if(action){
        action.innerHTML='<button class="btn danger classification-row-remove" type="button">Remove</button>';
        action.querySelector('.classification-row-remove').addEventListener('click',function(){
          row.remove();
          updateSaveLabel();
        });
      }
      rows.appendChild(row);
      updateSaveLabel();
      const input=row.querySelector('.classification-name-input');
      if(input)input.focus();
    });
  }
  updateSaveLabel();
});
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const input=document.getElementById('classificationSearchInput');
  const suggestions=document.getElementById('classificationSearchSuggestions');
  const table=document.getElementById('classificationTable');
  if(!input||!suggestions||!table)return;

  const tableRows=Array.from(table.querySelectorAll('tr[data-classification-id]'));

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
      row.classList.remove('classification-search-selected');
    });
  }

  function filterRows(term,selectedName){
    const value=String(term||'').trim().toLowerCase();
    let visible=0;
    tableRows.forEach(function(row){
      const name=String(row.dataset.classificationName||'');
      const match=selectedName
        ? name.toLowerCase()===selectedName.toLowerCase()
        : (!value || name.toLowerCase().includes(value));
      row.style.display=match?'':'none';
      row.classList.toggle('classification-search-selected',!!selectedName && match);
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
      .map(function(row){return {row:row,name:String(row.dataset.classificationName||'')};})
      .filter(function(item){return item.name.toLowerCase().includes(term);})
      .slice(0,10);

    if(!matches.length){
      suggestions.innerHTML='<div class="classification-search-empty">No matching classification found.</div>';
      suggestions.style.display='block';
      filterRows(term,null);
      return;
    }

    matches.forEach(function(item){
      const button=document.createElement('button');
      button.type='button';
      button.className='classification-search-suggestion';
      button.setAttribute('role','option');
      button.dataset.classificationId=item.row.dataset.classificationId||'';
      button.dataset.classificationName=item.name;
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
    const button=e.target.closest('.classification-search-suggestion');
    if(!button)return;
    const name=button.dataset.classificationName||'';
    input.value=name;
    closeSuggestions();
    filterRows(name,name);
    const selected=tableRows.find(function(row){
      return String(row.dataset.classificationName||'').toLowerCase()===name.toLowerCase();
    });
    if(selected){
      selected.scrollIntoView({behavior:'smooth',block:'center'});
    }
  });

  document.addEventListener('click',function(e){
    const box=document.getElementById('classificationSearchBox');
    if(box&&!box.contains(e.target))closeSuggestions();
  });
});
</script>
<style>
.classification-page-header{display:flex;align-items:center;justify-content:space-between;gap:18px}
.classification-page-title{min-width:0}
.classification-page-title h2{margin:0}
.classification-page-title .muted{margin:4px 0 0}
.classification-search{position:relative;width:200px;min-width:200px;margin:0 0 0 auto}
.classification-search .input{width:200px;box-sizing:border-box}
.classification-search-suggestions{position:absolute;left:0;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #cfd6df;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;display:none}
.classification-search-suggestion{display:block;width:100%;padding:9px 12px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:14px}
.classification-search-suggestion:hover,.classification-search-suggestion:focus{background:#eef5ff}
.classification-search-empty{padding:9px 12px;color:#6b7280;font-size:13px}
#classificationTable tr.classification-search-selected td{background:#eef5ff}
.classification-entry-row{display:flex;gap:8px;align-items:end;margin-bottom:8px}
.classification-name-field{flex:1;margin:0}
.classification-row-action{height:36px;display:flex;align-items:center}
.classification-add-row,.classification-row-remove{height:36px;white-space:nowrap}
.classification-save-actions{margin-top:10px;display:flex;justify-content:flex-start;text-align:left}
.classification-save-actions .btn{margin-left:0}
.classification-form-row{display:flex;align-items:flex-end;gap:12px}
.classification-form-actions{display:flex;gap:8px;align-items:center}
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}
</style>
<?php if(!$embedded) pageEnd();