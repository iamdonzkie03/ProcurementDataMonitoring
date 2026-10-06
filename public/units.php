<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']); require_once __DIR__.'/../app/layout.php'; $embedded=!empty($embedded); $pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    checkCsrf();
    try{
        $action=$_POST['action']??'add';
        if($action==='toggle'){
            $id=(int)$_POST['id'];
            $st=$pdo->prepare('UPDATE units_of_measure SET status=IF(status="Active","Inactive","Active") WHERE id=?');
            $st->execute([$id]);
            flash('success','Unit status updated.');
        }elseif($action==='delete'){
            $id=(int)$_POST['id'];
            if($id<=0) throw new RuntimeException('Invalid unit of measurement.');
            $st=$pdo->prepare('DELETE FROM units_of_measure WHERE id=?');
            $st->execute([$id]);
            flash('success','Unit of measurement deleted.');
        }elseif($action==='save_bulk'){
            $names=(array)($_POST['names']??[]);
            $validNames=[];
            foreach($names as $rawName){
                $name=trim((string)$rawName);
                if($name==='') continue;
                if(mb_strlen($name,'UTF-8')>100) throw new RuntimeException('Unit of measurement must not exceed 100 characters.');
                $validNames[]=$name;
            }
            if(!$validNames) throw new RuntimeException('Enter at least one unit of measurement.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('INSERT INTO units_of_measure(name) VALUES(?)');
            $added=0;
            foreach($validNames as $name){
                $check=$pdo->prepare('SELECT COUNT(*) FROM units_of_measure WHERE LOWER(TRIM(name))=LOWER(TRIM(?))');
                $check->execute([$name]);
                if((int)$check->fetchColumn()>0) throw new RuntimeException('Duplicate Unit of Measurement: '.$name);
                $st->execute([$name]);
                $added++;
            }
            $pdo->commit();
            flash('success',$added.' Unit of Measurement(s) added.');
        }else{
            $id=(int)($_POST['id']??0);
            $name=trim($_POST['name']??'');
            if($name==='') throw new RuntimeException('Enter a unit of measurement.');
            if($id>0){
                $st=$pdo->prepare('UPDATE units_of_measure SET name=? WHERE id=?');
                $st->execute([$name,$id]);
                flash('success','Unit of measurement updated.');
            }else{
                $st=$pdo->prepare('INSERT INTO units_of_measure(name) VALUES(?)');
                $st->execute([$name]);
                flash('success','Unit of measurement added.');
            }
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    header('Location:'.($embedded ? 'settings.php?tab=uom' : 'units.php'));exit;
}
$editing=null;
if(isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT id,name,status FROM units_of_measure WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editing=$st->fetch() ?: null;
}
$rows=$pdo->query('SELECT * FROM units_of_measure ORDER BY status DESC,name')->fetchAll();
if(!$embedded) pageStart('Units of Measurement');
?>
<div class="panel">
<div class="toolbar uom-page-header">
  <div class="uom-page-title"><h2>Units of Measurement</h2><p class="hint-text">Manage the units available in Purchase Request item dropdowns.</p></div>
  <div class="uom-search uom-header-search" id="uomSearchBox">
    <input class="input" type="text" id="uomSearchInput" autocomplete="off" placeholder="Search Unit..." aria-label="Search Unit of Measurement">
    <div class="uom-search-suggestions" id="uomSearchSuggestions" role="listbox"></div>
  </div>
</div>
<form method="post" id="uomForm" style="margin-bottom:18px">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<input type="hidden" name="action" value="<?= $editing ? 'save' : 'save_bulk' ?>">
<input type="hidden" name="id" value="<?=e((string)($editing['id']??0))?>">
<?php if($editing): ?>
<div class="uom-edit-row"><div class="field uom-edit-field"><label>Unit Name</label><input class="input" name="name" value="<?=e($editing['name']??'')?>" placeholder="e.g. Unit, Piece, Lot, Vial" required></div><div class="uom-edit-actions"><button class="btn" type="submit">Save Changes</button><a class="btn secondary" href="<?=e($embedded?'settings.php?tab=uom':'units.php')?>">Cancel</a></div></div>
<?php else: ?>
<div id="uomRows">
<div class="uom-entry-row" style="display:flex;gap:8px;align-items:end;margin-bottom:8px">
<div class="field" style="flex:1;margin:0"><label>Unit Name *</label><input class="input" name="names[]" maxlength="100" placeholder="e.g. Unit, Piece, Lot, Vial" required></div>
<div class="uom-row-action"><button class="btn secondary" type="button" id="addUomRow">Add Row</button></div>
</div>
</div>
<div style="margin-top:10px">
<button class="btn" type="submit" id="saveUomItems" style="background:#198754;color:#fff;border-color:#198754">Save Unit</button>
</div>
<?php endif; ?>
</form>
<div class="table-wrap"><table class="table" id="uomTable"><tr><th>Unit</th><th>Action</th></tr><?php foreach($rows as $r):?><tr data-uom-id="<?=e((string)$r['id'])?>" data-uom-name="<?=e($r['name'])?>"><td><b><?=e($r['name'])?></b></td><td>
<a class="btn secondary master-action" href="<?=e($embedded ? 'settings.php?tab=uom&edit='.(int)$r['id'] : 'units.php?edit='.(int)$r['id'])?>">Edit</a>
<form method="post" class="master-action-form" onsubmit="return confirm('Delete this Unit of Measurement?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn danger master-action" type="submit">Delete</button></form>
</td></tr><?php endforeach;?></table></div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const rows=document.getElementById('uomRows');
  const add=document.getElementById('addUomRow');
  const save=document.getElementById('saveUomItems');
  if(!rows)return;
  function updateSaveLabel(){
    if(!save)return;
    const count=rows.querySelectorAll('.uom-entry-row').length;
    save.textContent=count>=2?'Save Units':'Save Unit';
  }
  if(add){
    add.addEventListener('click',function(){
      const first=rows.querySelector('.uom-entry-row');
      if(!first)return;
      const row=first.cloneNode(true);
      row.querySelector('input').value='';
      const action=row.querySelector('.uom-row-action');
      if(action){
        action.innerHTML='<button class="btn danger uom-remove" type="button">Remove</button>';
        action.querySelector('.uom-remove').addEventListener('click',function(){
          row.remove();
          updateSaveLabel();
        });
      }
      rows.appendChild(row);
      updateSaveLabel();
    });
  }
  updateSaveLabel();
});
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const input=document.getElementById('uomSearchInput');
  const suggestions=document.getElementById('uomSearchSuggestions');
  const table=document.getElementById('uomTable');
  if(!input||!suggestions||!table)return;

  const tableRows=Array.from(table.querySelectorAll('tr[data-uom-id]'));

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
      row.classList.remove('uom-search-selected');
    });
  }

  function filterRows(term,selectedName){
    const value=String(term||'').trim().toLowerCase();
    let visible=0;
    tableRows.forEach(function(row){
      const name=String(row.dataset.uomName||'');
      const match=selectedName
        ? name.toLowerCase()===selectedName.toLowerCase()
        : (!value || name.toLowerCase().includes(value));
      row.style.display=match?'':'none';
      row.classList.toggle('uom-search-selected',!!selectedName && match);
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
      .map(function(row){return {row:row,name:String(row.dataset.uomName||'')};})
      .filter(function(item){return item.name.toLowerCase().includes(term);})
      .slice(0,10);

    if(!matches.length){
      suggestions.innerHTML='<div class="uom-search-empty">No matching unit found.</div>';
      suggestions.style.display='block';
      filterRows(term,null);
      return;
    }

    matches.forEach(function(item){
      const button=document.createElement('button');
      button.type='button';
      button.className='uom-search-suggestion';
      button.setAttribute('role','option');
      button.dataset.uomId=item.row.dataset.uomId||'';
      button.dataset.uomName=item.name;
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
    const button=e.target.closest('.uom-search-suggestion');
    if(!button)return;
    const name=button.dataset.uomName||'';
    input.value=name;
    closeSuggestions();
    filterRows(name,name);
    const selected=tableRows.find(function(row){
      return String(row.dataset.uomName||'').toLowerCase()===name.toLowerCase();
    });
    if(selected){
      selected.scrollIntoView({behavior:'smooth',block:'center'});
    }
  });

  document.addEventListener('click',function(e){
    const box=document.getElementById('uomSearchBox');
    if(box&&!box.contains(e.target))closeSuggestions();
  });
});
</script>
<style>
.uom-edit-row{display:flex;align-items:flex-end;gap:8px;width:100%}
.uom-edit-field{flex:1;margin:0}
.uom-edit-actions{display:flex;align-items:center;gap:8px;white-space:nowrap}
.master-action{width:82px;min-width:82px;height:36px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.master-action-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}

<style>
.uom-page-header{display:flex;align-items:center;justify-content:space-between;gap:18px}
.uom-page-title{min-width:0}
.uom-page-title h2{margin:0}
.uom-page-title .hint-text{margin:4px 0 0}
.uom-search{position:relative}
.uom-header-search{width:200px;min-width:200px;margin:0 0 0 auto}
.uom-search .input{width:200px;box-sizing:border-box}
.uom-search-suggestions{
  position:absolute;left:0;right:0;top:100%;z-index:1000;
  background:#fff;border:1px solid #cfd6df;border-radius:4px;
  box-shadow:0 4px 12px rgba(0,0,0,.12);
  max-height:240px;overflow-y:auto;display:none
}
.uom-search-suggestion{
  display:block;width:100%;padding:9px 12px;border:0;
  background:#fff;text-align:left;cursor:pointer;font-size:14px
}
.uom-search-suggestion:hover,.uom-search-suggestion:focus{background:#eef5ff}
.uom-search-empty{padding:9px 12px;color:#6b7280;font-size:13px}
#uomTable tr.uom-search-selected td{background:#eef5ff}
</style>
</style>
<?php if(!$embedded) pageEnd();