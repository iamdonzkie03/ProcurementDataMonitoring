<?php
declare(strict_types=1);
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor']);
require_once __DIR__.'/../app/layout.php';

$pdo=db();
$action=$_POST['action']??'';
$editId=(int)($_GET['edit']??0);

$editing=null;

/**
 * A duplicate is an existing row with the same item name, specifications,
 * unit of measurement, and unit cost. Text comparison ignores case and
 * surrounding whitespace; costs are compared at the database's 2-decimal precision.
 */
function ppmpMasterlistDuplicateExists(PDO $pdo, string $itemName, string $specifications, string $uom, float $unitCost, int $excludeId=0): bool {
  $sql = "SELECT id FROM ppmp_masterlist
          WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))
            AND LOWER(TRIM(COALESCE(technical_specifications,''))) = LOWER(TRIM(?))
            AND LOWER(TRIM(unit_of_measurement)) = LOWER(TRIM(?))
            AND unit_cost = ?";
  $params = [$itemName, $specifications, $uom, number_format($unitCost, 2, '.', '')];
  if($excludeId > 0){
    $sql .= " AND id <> ?";
    $params[] = $excludeId;
  }
  $sql .= " LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (bool)$st->fetchColumn();
}

function ppmpMasterlistDuplicateKey(string $itemName, string $specifications, string $uom, float $unitCost): string {
  return mb_strtolower(trim($itemName),'UTF-8')."\x1F".
         mb_strtolower(trim($specifications),'UTF-8')."\x1F".
         mb_strtolower(trim($uom),'UTF-8')."\x1F".
         number_format($unitCost, 2, '.', '');
}

function ppmpMasterlistReadXlsx(string $filePath): array {
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive is required to import Excel files.');
  if(!function_exists('simplexml_load_string')) throw new RuntimeException('PHP SimpleXML is required to import Excel files.');

  $zip=new ZipArchive();
  if($zip->open($filePath)!==true) throw new RuntimeException('The uploaded file is not a valid .xlsx workbook.');

  $workbookXml=$zip->getFromName('xl/workbook.xml');
  $relsXml=$zip->getFromName('xl/_rels/workbook.xml.rels');
  if($workbookXml===false || $relsXml===false){
    $zip->close();
    throw new RuntimeException('The uploaded Excel workbook is missing required workbook data.');
  }

  $workbook=@simplexml_load_string($workbookXml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
  $rels=@simplexml_load_string($relsXml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
  if($workbook===false || $rels===false){
    $zip->close();
    throw new RuntimeException('The uploaded Excel workbook could not be read.');
  }

  $ns=$workbook->getDocNamespaces(true);
  $mainNs=$ns['']??'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  $workbook->registerXPathNamespace('x',$mainNs);
  $sheetNodes=$workbook->xpath('//x:sheets/x:sheet');
  if(!$sheetNodes || !isset($sheetNodes[0])){
    $zip->close();
    throw new RuntimeException('The Excel workbook does not contain a worksheet.');
  }

  $sheetRelId=(string)$sheetNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
  $relsNs=$rels->getDocNamespaces(true);
  $rels->registerXPathNamespace('r','http://schemas.openxmlformats.org/package/2006/relationships');
  $relNodes=$rels->xpath('//r:Relationship');
  $target='';
  foreach($relNodes as $rel){
    if((string)$rel['Id']===$sheetRelId){
      $target=(string)$rel['Target'];
      break;
    }
  }
  if($target===''){
    $zip->close();
    throw new RuntimeException('The first Excel worksheet could not be located.');
  }
  $target=ltrim($target,'/');
  if(str_starts_with($target,'xl/')) $sheetPath=$target;
  else $sheetPath='xl/'.$target;

  $sheetXml=$zip->getFromName($sheetPath);
  if($sheetXml===false){
    $zip->close();
    throw new RuntimeException('The first Excel worksheet could not be read.');
  }

  $sharedStrings=[];
  $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
  if($sharedXml!==false){
    $shared=@simplexml_load_string($sharedXml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if($shared!==false){
      $sharedNs=$shared->getDocNamespaces(true);
      $shared->registerXPathNamespace('x',$sharedNs['']??$mainNs);
      foreach(($shared->xpath('//x:si')?:[]) as $si){
        $texts=$si->xpath('.//*[local-name()="t"]');
        $value='';
        foreach(($texts?:[]) as $t) $value.=(string)$t;
        $sharedStrings[]=$value;
      }
    }
  }

  $sheet=@simplexml_load_string($sheetXml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
  if($sheet===false){
    $zip->close();
    throw new RuntimeException('The first Excel worksheet could not be parsed.');
  }
  $sheetNs=$sheet->getDocNamespaces(true);
  $sheet->registerXPathNamespace('x',$sheetNs['']??$mainNs);
  $rows=$sheet->xpath('//x:sheetData/x:row')?:[];

  $result=[];
  foreach($rows as $row){
    $values=[];
    foreach(($row->xpath('./*[local-name()="c"]')?:[]) as $cell){
      $ref=(string)$cell['r'];
      if($ref==='' || !preg_match('/^([A-Z]+)\d+$/i',$ref,$m)) continue;
      $letters=strtoupper($m[1]);
      $col=0;
      for($j=0,$len=strlen($letters);$j<$len;$j++) $col=$col*26+(ord($letters[$j])-64);
      $col--;
      $type=(string)$cell['t'];
      $value='';
      if($type==='inlineStr'){
        $texts=$cell->xpath('./*[local-name()="is"]//*[local-name()="t"]');
        foreach(($texts?:[]) as $t) $value.=(string)$t;
      }else{
        $v=$cell->xpath('./*[local-name()="v"]');
        $value=isset($v[0])?(string)$v[0]:'';
        if($type==='s' && $value!=='' && isset($sharedStrings[(int)$value])) $value=$sharedStrings[(int)$value];
        if($type==='b') $value=$value==='1'?'TRUE':'FALSE';
      }
      $values[$col]=$value;
    }
    if($values) $result[]=$values;
  }
  $zip->close();

  if(!$result) throw new RuntimeException('The Excel workbook contains no data.');
  return $result;
}

try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS ppmp_masterlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    technical_specifications TEXT NULL,
    unit_of_measurement VARCHAR(100) NOT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ppmp_masterlist_item (item_name),
    INDEX idx_ppmp_masterlist_uom (unit_of_measurement)
  ) ENGINE=InnoDB");
  $cols=$pdo->query("SHOW COLUMNS FROM ppmp_masterlist LIKE 'technical_specifications'")->fetch();
  if(!$cols) $pdo->exec("ALTER TABLE ppmp_masterlist ADD COLUMN technical_specifications TEXT NULL AFTER item_name");
}catch(PDOException $e){
  flash('error','Unable to initialize the PPMP Masterlist table.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  checkCsrf();
  $id=(int)($_POST['id']??0);
  $itemNames=(array)($_POST['item_name']??[]);
  $specifications=(array)($_POST['technical_specifications']??[]);
  $uoms=(array)($_POST['unit_of_measurement']??[]);
  $unitCosts=(array)($_POST['unit_cost']??[]);
  $itemName=trim((string)($itemNames[0]??''));
  $technicalSpecifications=trim((string)($specifications[0]??''));
  $uom=trim((string)($uoms[0]??''));
  $unitCostRaw=str_replace(',','',trim((string)($unitCosts[0]??'')));
  $unitCost=is_numeric($unitCostRaw)?(float)$unitCostRaw:-1;
  $userId=(int)(currentUser()['id']??0);


  if($action==='import_excel'){
    $upload=$_FILES['masterlist_excel']??null;
    if(!$upload || !isset($upload['error']) || $upload['error']!==UPLOAD_ERR_OK){
      flash('error','Please select a valid Excel (.xlsx) file to upload.');
      header('Location:ppmp_masterlist.php'); exit;
    }
    if(($upload['size']??0)>10*1024*1024){
      flash('error','The Excel file is too large. Maximum file size is 10 MB.');
      header('Location:ppmp_masterlist.php'); exit;
    }
    $originalName=(string)($upload['name']??'');
    if(strtolower(pathinfo($originalName,PATHINFO_EXTENSION))!=='xlsx'){
      flash('error','Only Excel .xlsx files are accepted.');
      header('Location:ppmp_masterlist.php'); exit;
    }

    try{
      $rowsFromExcel=ppmpMasterlistReadXlsx((string)$upload['tmp_name']);
      $expectedHeaders=['item name','technical specifications','unit of measurement','unit cost'];

      // Excel cells may have sparse column indexes, invisible whitespace/BOM characters,
      // or extra formatted-but-empty columns. Normalize the header before validating it.
      $headerRow=$rowsFromExcel[0]??[];
      ksort($headerRow);
      $headerRow=array_values($headerRow);
      $normalizedHeader=[];
      foreach($headerRow as $cellValue){
        $cellValue=(string)$cellValue;
        $cellValue=preg_replace('/^[\\s\\x{FEFF}\\x{00A0}]+|[\\s\\x{FEFF}\\x{00A0}]+$/u','',$cellValue);
        $normalizedHeader[]=mb_strtolower($cellValue,'UTF-8');
      }
      while($normalizedHeader && end($normalizedHeader)==='') array_pop($normalizedHeader);

      if($normalizedHeader!==$expectedHeaders){
        throw new RuntimeException('The Excel header row must contain these four columns in this order: Item Name, Technical Specifications, Unit of Measurement, Unit Cost. Please remove any non-empty extra columns and ensure the headings are on the first row.');
      }

      $activeUoms=[];
      $uomCheck=$pdo->query("SELECT name FROM units_of_measure WHERE status='Active'");
      foreach($uomCheck->fetchAll(PDO::FETCH_COLUMN) as $name) $activeUoms[mb_strtolower(trim((string)$name),'UTF-8')]=true;

      $importRows=[];
      foreach(array_slice($rowsFromExcel,1) as $excelIndex=>$row){
        $excelRow=$excelIndex+2;
        $row=array_pad($row,4,'');
        $itemName=trim((string)($row[0]??''));
        $spec=trim((string)($row[1]??''));
        $uom=trim((string)($row[2]??''));
        $costRaw=str_replace([',','₱',' '],'',trim((string)($row[3]??'')));

        if($itemName==='' && $spec==='' && $uom==='' && $costRaw==='') continue;
        if($itemName==='' || $uom==='' || $costRaw===''){
          throw new RuntimeException("Excel row {$excelRow}: Item Name, Unit of Measurement, and Unit Cost are required.");
        }
        if(!is_numeric($costRaw) || (float)$costRaw<0){
          throw new RuntimeException("Excel row {$excelRow}: Unit Cost must be a valid non-negative number.");
        }
        if(!isset($activeUoms[mb_strtolower($uom,'UTF-8')])){
          throw new RuntimeException("Excel row {$excelRow}: Unit of Measurement \"{$uom}\" is not an active UOM in the system.");
        }
        if(mb_strlen($itemName,'UTF-8')>255) throw new RuntimeException("Excel row {$excelRow}: Item Name exceeds 255 characters.");
        if(mb_strlen($spec,'UTF-8')>5000) throw new RuntimeException("Excel row {$excelRow}: Technical Specifications exceeds 5000 characters.");
        $importRows[]=[$itemName,$spec,$uom,(float)$costRaw];
      }

      if(!$importRows) throw new RuntimeException('The Excel workbook contains no masterlist items to import.');

      $seenRows=[];
      foreach($importRows as $i=>$row){
        $excelRow=$i+2;
        $key=ppmpMasterlistDuplicateKey($row[0],$row[1],$row[2],$row[3]);
        if(isset($seenRows[$key])){
          throw new RuntimeException("Excel row {$excelRow}: this item duplicates another row in the uploaded file. No items were imported.");
        }
        $seenRows[$key]=true;
        if(ppmpMasterlistDuplicateExists($pdo,$row[0],$row[1],$row[2],$row[3])){
          throw new RuntimeException("Excel row {$excelRow}: this item already exists in the PPMP Masterlist. No items were imported.");
        }
      }

      $pdo->beginTransaction();
      $st=$pdo->prepare('INSERT INTO ppmp_masterlist (item_name,technical_specifications,unit_of_measurement,unit_cost,created_by,updated_by) VALUES (?,?,?,?,?,?)');
      foreach($importRows as $row) $st->execute([$row[0],$row[1],$row[2],$row[3],$userId,$userId]);
      $pdo->commit();
      flash('success',count($importRows).' PPMP Masterlist item(s) imported successfully.');
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      flash('error',$e->getMessage());
    }
    header('Location:ppmp_masterlist.php'); exit;
  }

  if($action==='delete'){
    if($id<=0){
      flash('error','Invalid PPMP Masterlist item.');
    }else{
      try{
        $st=$pdo->prepare('DELETE FROM ppmp_masterlist WHERE id=?');
        $st->execute([$id]);
        flash($st->rowCount()?'success':'error',$st->rowCount()?'Masterlist item deleted.':'Masterlist item not found.');
      }catch(PDOException $e){
        flash('error','Unable to delete the masterlist item.');
      }
    }
    header('Location:ppmp_masterlist.php'); exit;
  }

  if($action==='save' && !$editId && count($itemNames)>1){
    $validRows=[];
    foreach($itemNames as $i=>$name){
      $name=trim((string)$name); $spec=trim((string)($specifications[$i]??'')); $measure=trim((string)($uoms[$i]??'')); $costRaw=str_replace(',','',trim((string)($unitCosts[$i]??'')));
      if($name==='' && $measure==='' && $costRaw==='') continue;
      $cost=is_numeric($costRaw)?(float)$costRaw:-1;
      if($name==='' || $measure==='' || $costRaw==='' || $cost<0 || !is_numeric($costRaw)){
        flash('error','Each masterlist row must have Item Name, Unit of Measurement, and a valid Unit Cost.');
        header('Location:ppmp_masterlist.php'); exit;
      }
      $validRows[]=[$name,$spec,$measure,$cost];
    }
    if(!$validRows){ flash('error','Add at least one masterlist item.'); header('Location:ppmp_masterlist.php'); exit; }
    $seenRows=[];
    foreach($validRows as $i=>$row){
      $key=ppmpMasterlistDuplicateKey($row[0],$row[1],$row[2],$row[3]);
      if(isset($seenRows[$key])){
        flash('error','Manual row '.($i+1).' duplicates another row in this submission. No items were saved.');
        header('Location:ppmp_masterlist.php'); exit;
      }
      $seenRows[$key]=true;
      if(ppmpMasterlistDuplicateExists($pdo,$row[0],$row[1],$row[2],$row[3])){
        flash('error','Manual row '.($i+1).' already exists in the PPMP Masterlist. No items were saved.');
        header('Location:ppmp_masterlist.php'); exit;
      }
    }
    try{
      $pdo->beginTransaction();
      $st=$pdo->prepare('INSERT INTO ppmp_masterlist (item_name,technical_specifications,unit_of_measurement,unit_cost,created_by,updated_by) VALUES (?,?,?,?,?,?)');
      foreach($validRows as $row) $st->execute([$row[0],$row[1],$row[2],$row[3],$userId,$userId]);
      $pdo->commit(); flash('success',count($validRows).' PPMP Masterlist item(s) added.');
    }catch(PDOException $e){ if($pdo->inTransaction())$pdo->rollBack(); flash('error','Unable to save the PPMP Masterlist items.'); }
    header('Location:ppmp_masterlist.php'); exit;
  }

  if($itemName==='' || $uom==='' || $unitCostRaw==='' || !is_numeric($unitCostRaw) || $unitCost<0){
    flash('error','Item Name, Unit of Measurement, and a valid Unit Cost are required.');
    header('Location:ppmp_masterlist.php'.($id>0?'?edit='.$id:'')); exit;
  }

  try{
    if(ppmpMasterlistDuplicateExists($pdo,$itemName,$technicalSpecifications,$uom,$unitCost,($action==='update' && $id>0)?$id:0)){
      flash('error','This item already exists in the PPMP Masterlist with the same Item Name, Technical Specifications, Unit of Measurement, and Unit Cost. No changes were saved.');
      header('Location:ppmp_masterlist.php'.(($action==='update' && $id>0)?'?edit='.$id:''));
      exit;
    }
    if($action==='update' && $id>0){
      $st=$pdo->prepare('UPDATE ppmp_masterlist SET item_name=?,technical_specifications=?,unit_of_measurement=?,unit_cost=?,updated_by=? WHERE id=?');
      $st->execute([$itemName,$technicalSpecifications,$uom,$unitCost,$userId,$id]);
      flash('success','PPMP Masterlist item updated.');
    }else{
      $st=$pdo->prepare('INSERT INTO ppmp_masterlist (item_name,technical_specifications,unit_of_measurement,unit_cost,created_by,updated_by) VALUES (?,?,?,?,?,?)');
      $st->execute([$itemName,$technicalSpecifications,$uom,$unitCost,$userId,$userId]);
      flash('success','PPMP Masterlist item added.');
    }
  }catch(PDOException $e){
    flash('error','Unable to save the PPMP Masterlist item.');
  }
  header('Location:ppmp_masterlist.php'); exit;
}

if($editId>0){
  $st=$pdo->prepare('SELECT * FROM ppmp_masterlist WHERE id=? LIMIT 1');
  $st->execute([$editId]);
  $editing=$st->fetch();
  if(!$editing){
    flash('error','PPMP Masterlist item not found.');
    header('Location:ppmp_masterlist.php'); exit;
  }
}

$uomRows=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name ASC")->fetchAll();
$st=$pdo->query('SELECT id,item_name,technical_specifications,unit_of_measurement,unit_cost,created_at,updated_at FROM ppmp_masterlist ORDER BY item_name ASC,id ASC');
$masterlist=$st->fetchAll();

pageStart('PPMP Masterlist');
?>

<style>
/* Keep a consistent visible gap between every top-level panel on this page. */
body .panel{
  margin-bottom:24px !important;
}
body .panel:last-of-type{
  margin-bottom:0 !important;
}
.masterlist-row{margin-bottom:10px}
.masterlist-row + .masterlist-row{padding-top:10px;border-top:1px solid #eee}
.masterlist-remove-row{white-space:nowrap}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){

(function(){

  const button=document.getElementById('addMasterlistRow');
  const saveButton=document.getElementById('saveMasterlistItems');
  const container=document.getElementById('masterlistRows');
  if(!container)return;

  // Identical number-formatting behavior to the PPMP Form Quantity/Unit Cost fields.
  function formatNumberInput(field,finalize){
    if(!field)return;
    let raw=String(field.value||'').replace(/,/g,'').replace(/[^0-9.]/g,'');
    if(raw===''){field.value='';return;}
    const parts=raw.split('.');
    let integer=(parts[0]||'0').replace(/^0+(?=\d)/,'');
    integer=integer.replace(/\B(?=(\d{3})+(?!\d))/g,',');
    let value=integer;
    if(parts.length>1)value+='.'+(parts.slice(1).join('').slice(0,2));
    if(finalize && value.indexOf('.')<0)value+='.00';
    else if(finalize && value.indexOf('.')>=0)value=(parseFloat(value.replace(/,/g,''))||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    field.value=value;
  }

  function isUnitCostField(target){
    return target && target.classList && target.classList.contains('masterlist-unit-cost');
  }

  container.addEventListener('input',function(e){
    if(isUnitCostField(e.target)) formatNumberInput(e.target,false);
  });

  container.addEventListener('blur',function(e){
    if(isUnitCostField(e.target)) formatNumberInput(e.target,true);
  },true);

  container.addEventListener('keydown',function(e){
    if(!isUnitCostField(e.target))return;
    if(['e','E','+','-'].includes(e.key))e.preventDefault();
  });

  container.addEventListener('paste',function(e){
    if(!isUnitCostField(e.target))return;
    setTimeout(function(){formatNumberInput(e.target,false);},0);
  });

  function updateSaveButton(){
    if(!saveButton)return;
    const count=container.querySelectorAll('.masterlist-row').length;
    saveButton.textContent=count>=2?'Save Items':'Save Item';
  }

  function addMasterlistRow(){
    const first=container.querySelector('.masterlist-row');
    if(!first)return;
    const row=first.cloneNode(true);
    row.querySelectorAll('input,textarea').forEach(function(input){input.value='';});
    const uomSelect=row.querySelector('select[name="unit_of_measurement[]"]');
    if(uomSelect)uomSelect.selectedIndex=0;
    const action=row.querySelector('.masterlist-submit');
    if(action){
      action.innerHTML='<button class="btn danger masterlist-remove-row" type="button">Remove</button>';
      action.querySelector('button').addEventListener('click',function(){row.remove();updateSaveButton();});
    }
    container.appendChild(row);
    updateSaveButton();
  }

  if(button){
    button.addEventListener('click',addMasterlistRow);
  }

  updateSaveButton();
})();

});
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const input=document.getElementById('masterlistSearchInput');
  const suggestions=document.getElementById('masterlistSearchSuggestions');
  const box=document.getElementById('masterlistSearchBox');
  const table=document.getElementById('masterlistTable');
  const pageSizeSelect=document.getElementById('masterlistPageSize');
  const paginationInfo=document.getElementById('masterlistPaginationInfo');
  const paginationButtons=document.getElementById('masterlistPaginationButtons');
  if(!input||!suggestions||!table||!pageSizeSelect||!paginationInfo||!paginationButtons)return;
  const tableRows=Array.from(table.querySelectorAll('tr[data-masterlist-id]'));
  const emptyRow=table.querySelector('.masterlist-empty-row');
  let filteredRows=tableRows.slice(),currentPage=1,pageSize=Number(pageSizeSelect.value)||10;
  function closeSuggestions(){suggestions.innerHTML='';suggestions.style.display='none';}
  function getName(row){return String(row.dataset.masterlistName||'');}
  function renderPagination(){
    const total=filteredRows.length,totalPages=Math.max(1,Math.ceil(total/pageSize));
    if(currentPage>totalPages)currentPage=totalPages;
    tableRows.forEach(function(row){row.style.display='none';row.classList.remove('masterlist-search-selected');});
    const start=(currentPage-1)*pageSize;
    filteredRows.slice(start,start+pageSize).forEach(function(row){row.style.display='';});
    if(emptyRow)emptyRow.style.display=total?'none':'';
    paginationInfo.textContent=total?'Showing '+(start+1)+'-'+Math.min(start+pageSize,total)+' of '+total+' records':'0 records';
    paginationButtons.innerHTML='';
    function addButton(label,page,disabled,active){
      const b=document.createElement('button');b.type='button';b.className='btn secondary masterlist-page-button'+(active?' active':'');
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
      const text=row.textContent.toLowerCase();
      return selected?getName(row).toLowerCase()===selected.toLowerCase():(!value||text.includes(value));
    });
    if(reset)currentPage=1;
    renderPagination();
    if(selected)filteredRows.forEach(function(row){row.classList.add('masterlist-search-selected');});
  }
  function showSuggestions(){
    const term=String(input.value||'').trim().toLowerCase();suggestions.innerHTML='';
    if(!term){closeSuggestions();applyFilter('',null,true);return;}
    const matches=tableRows.filter(function(row){return getName(row).toLowerCase().includes(term);}).slice(0,10);
    if(!matches.length){
      const empty=document.createElement('div');empty.className='masterlist-search-empty';empty.textContent='No matching item found.';suggestions.appendChild(empty);
      suggestions.style.display='block';applyFilter(term,null,true);return;
    }
    matches.forEach(function(row){
      const button=document.createElement('button');button.type='button';button.className='masterlist-search-suggestion';
      button.setAttribute('role','option');button.dataset.name=getName(row);button.textContent=getName(row);suggestions.appendChild(button);
    });
    suggestions.style.display='block';applyFilter(term,null,true);
  }
  input.addEventListener('input',showSuggestions);
  input.addEventListener('focus',function(){if(input.value.trim())showSuggestions();});
  input.addEventListener('keydown',function(e){if(e.key==='Escape'){input.value='';closeSuggestions();applyFilter('',null,true);}});
  suggestions.addEventListener('click',function(e){
    const button=e.target.closest('.masterlist-search-suggestion');if(!button)return;
    const name=button.dataset.name||'';input.value=name;closeSuggestions();applyFilter(name,name,true);
  });
  pageSizeSelect.addEventListener('change',function(){pageSize=Number(pageSizeSelect.value)||10;currentPage=1;renderPagination();});
  document.addEventListener('click',function(e){if(box&&!box.contains(e.target))closeSuggestions();});
  renderPagination();
});
</script>
<style>
.masterlist-toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.masterlist-list-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.masterlist-list-header h2{margin:0}
.masterlist-search{position:relative;width:200px;min-width:200px;margin:0 0 0 auto}
.masterlist-search .input{width:200px;box-sizing:border-box}
.masterlist-search-suggestions{position:absolute;left:0;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #cfd6df;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;display:none}
.masterlist-search-suggestion{display:block;width:100%;padding:9px 12px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:14px}
.masterlist-search-suggestion:hover,.masterlist-search-suggestion:focus{background:#eef5ff}
.masterlist-search-empty{padding:9px 12px;color:#6b7280;font-size:13px}
#masterlistTable tr.masterlist-search-selected td{background:#eef5ff}
.masterlist-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;width:100%;clear:both;position:static;float:none;margin:0 0 10px;padding:0;box-sizing:border-box}
.masterlist-pagination-info{flex:0 0 auto;text-align:right;font-size:13px;color:#6b7280}
.masterlist-page-size{display:flex;align-items:center;gap:6px;font-size:14px;color:#4b5563}
.masterlist-page-size .input{width:78px;min-width:78px;height:36px}
.masterlist-pagination-buttons{display:flex;align-items:center;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.masterlist-page-button{min-width:38px;height:36px;padding:0 10px;background:#cfe8ff;color:#1f5f85;border-color:#b7d9f5}
.masterlist-page-button:hover:not(:disabled):not(.active){background:#b9dcfa;color:#174a69}
.masterlist-page-button.active{font-weight:700;pointer-events:none;background:#0d6efd;color:#fff;border-color:#0d6efd}
.masterlist-pagination-buttons .masterlist-page-button:first-child,.masterlist-pagination-buttons .masterlist-page-button:last-child{background:#495057;color:#fff;border-color:#495057}
.masterlist-pagination-buttons .masterlist-page-button:disabled{opacity:.7;cursor:not-allowed}
@media(max-width:700px){.masterlist-pagination{align-items:flex-start;justify-content:flex-end;flex-wrap:wrap}.masterlist-pagination-info{order:3;flex-basis:100%;text-align:right}}
.masterlist-toolbar h2{margin:0}
.masterlist-form-grid{display:grid;grid-template-columns:minmax(0,20fr) minmax(0,45fr) minmax(0,15fr) minmax(0,20fr) auto;gap:16px;align-items:start}
.masterlist-form-grid .field{margin:0}
.masterlist-form-grid .masterlist-field{width:100%}
.masterlist-form-grid .masterlist-field .input{width:100%}
.masterlist-form-grid .masterlist-submit{display:flex;align-items:flex-start;justify-content:center;height:auto;min-width:105px;padding-top:0;margin-top:22px}
.masterlist-form-grid .masterlist-submit .btn{white-space:nowrap}
.masterlist-actions{display:flex;gap:6px;align-items:center;white-space:nowrap}
.masterlist-actions form{margin:0}
.masterlist-table th,.masterlist-table td{vertical-align:middle}
.masterlist-cost{text-align:right;white-space:nowrap}
@media(max-width:1100px){.masterlist-form-grid{grid-template-columns:minmax(0,20fr) minmax(0,45fr) minmax(0,15fr) minmax(0,20fr) auto;gap:10px}.masterlist-form-grid .masterlist-submit{min-width:90px;padding-top:22px}}


@media(max-width:600px){.masterlist-form-grid{grid-template-columns:1fr}}
</style>

<div class="panel">
  <div class="masterlist-toolbar">
    <div>
      <h2>PPMP Masterlist</h2>
      <p class="muted" style="margin:5px 0 0">Maintain the standard items, units of measurement, and unit costs available for PPMP preparation.</p>
    </div>
    <a class="btn secondary" href="ppmp.php">Back to Project Procurement Management Plan</a>
  </div>
</div>

<div class="panel">
  <h2><?= $editing ? 'Edit Masterlist Item' : 'Add Masterlist Item' ?></h2>

  <div style="margin-bottom:18px;padding:14px 16px;border:1px solid #dbe3ea;border-radius:6px;background:#f8fafc">
    <div style="font-weight:600;margin-bottom:5px">Option 1: Upload Excel File</div>
    <div class="muted" style="margin-bottom:10px">Upload an <strong>.xlsx</strong> file with exactly these columns, in this exact order: <strong>Item Name</strong>, <strong>Technical Specifications</strong>, <strong>Unit of Measurement</strong>, <strong>Unit Cost</strong>.</div>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?=e(csrf())?>">
      <input type="hidden" name="action" value="import_excel">
      <input class="input" type="file" name="masterlist_excel" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="max-width:420px">
      <button class="btn secondary" type="submit">Upload Excel</button>
    </form>
  </div>
  <div style="font-weight:600;margin-bottom:8px">Option 2: Add Manually</div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'save' ?>">
    <?php if($editing): ?><input type="hidden" name="id" value="<?=e((string)$editing['id'])?>"><?php endif; ?>
    <div id="masterlistRows">
      <div class="masterlist-row masterlist-form-grid">
        <div class="field masterlist-field"><label>Item Name *</label><input class="input" type="text" name="item_name[]" required maxlength="255" value="<?=e($editing['item_name']??'')?>" placeholder="Enter item name"></div>
        <div class="field masterlist-field"><label>Technical Specifications</label><input class="input" type="text" name="technical_specifications[]" maxlength="5000" value="<?=e($editing['technical_specifications']??'')?>" placeholder="Enter technical specifications"></div>
        <div class="field masterlist-field"><label>Unit of Measurement *</label><select class="input" name="unit_of_measurement[]" required><option value="">Select Unit</option><?php foreach($uomRows as $uomRow): ?><option value="<?=e($uomRow['name'])?>" <?=($editing['unit_of_measurement']??'')===$uomRow['name']?'selected':''?>><?=e($uomRow['name'])?></option><?php endforeach; ?></select></div>
        <div class="field masterlist-field"><label>Unit Cost *</label><input class="input masterlist-unit-cost" type="text" name="unit_cost[]" required inputmode="decimal" autocomplete="off" maxlength="21" value="<?= $editing ? e(number_format((float)$editing['unit_cost'],2,'.',',')) : '' ?>" placeholder="0.00"></div>
        <div class="masterlist-submit">
<?php if($editing): ?>
  <button class="btn" type="submit">Update Item</button>
  <a class="btn secondary" href="ppmp_masterlist.php">Cancel</a>
<?php else: ?>
  <button class="btn" type="button" id="addMasterlistRow">Add Row</button>
<?php endif; ?>
</div>
      </div>
    </div>
    <?php if(!$editing): ?>
    <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
      <button class="btn" style="background:#198754;color:#fff;border-color:#198754" type="submit" id="saveMasterlistItems">Save Item</button>
    </div>
    <?php endif; ?>
  </form>
</div>

<div class="panel">
  <div class="masterlist-list-header">
    <h2>Masterlist Items</h2>
    <div class="masterlist-search" id="masterlistSearchBox">
      <input class="input" type="text" id="masterlistSearchInput" autocomplete="off" placeholder="Search Masterlist..." aria-label="Search Masterlist Items">
      <div class="masterlist-search-suggestions" id="masterlistSearchSuggestions" role="listbox"></div>
    </div>
  </div>
  <div class="table-wrap" style="margin-top:16px">
    <div class="masterlist-pagination" id="masterlistPagination" aria-label="Masterlist pagination">
      <div class="masterlist-page-size"><label for="masterlistPageSize">Show</label><select class="input" id="masterlistPageSize" aria-label="Records per page"><option value="10">10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select><span>records</span></div>
      <div class="masterlist-pagination-info" id="masterlistPaginationInfo"></div>
      <div class="masterlist-pagination-buttons" id="masterlistPaginationButtons"></div>
    </div>
    <table class="table masterlist-table" id="masterlistTable">
      <thead><tr><th>#</th><th>Item Name</th><th>Technical Specifications</th><th>Unit of Measurement</th><th class="masterlist-cost">Unit Cost</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($masterlist as $i=>$row): ?>
        <tr data-masterlist-id="<?=e((string)$row['id'])?>" data-masterlist-name="<?=e($row['item_name'])?>">
          <td><?=e((string)($i+1))?></td>
          <td><?=e($row['item_name'])?></td>
          <td><?=nl2br(e($row['technical_specifications']??''))?></td>
          <td><?=e($row['unit_of_measurement'])?></td>
          <td class="masterlist-cost">₱<?=number_format((float)$row['unit_cost'],2)?></td>
          <td>
            <div class="masterlist-actions">
              <a class="btn secondary" href="ppmp_masterlist.php?edit=<?=e((string)$row['id'])?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete this PPMP Masterlist item?');">
                <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=e((string)$row['id'])?>">
                <button class="btn danger" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr class="masterlist-empty-row" <?= $masterlist ? 'style="display:none"' : '' ?>><td colspan="6" class="empty">No PPMP Masterlist items match your search.</td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php pageEnd(); ?>
