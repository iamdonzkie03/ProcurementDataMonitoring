\n<style>.ppmp-entry-table .ppmp-masterlist-locked{background:#e5e7eb!important;color:#6b7280!important;border:0!important;outline:0!important;box-shadow:none!important;cursor:not-allowed}.ppmp-entry-table textarea.ppmp-masterlist-locked{resize:none;border:0!important;outline:0!important;box-shadow:none!important}\n.ppmp-status-badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:#eef2f7;color:#374151}.ppmp-status-pending-for-review{background:#fff3cd;color:#856404}.ppmp-status-pending-for-approval{background:#cff4fc;color:#055160}.ppmp-status-approved{background:#d1e7dd;color:#0f5132}.ppmp-status-declined{background:#f8d7da;color:#842029}.ppmp-status-draft{background:#e9ecef;color:#495057}.ppmp-submit-review{background:#d9f7df!important;color:#166534!important;border:1px solid #b7e4c0!important;}\n.ppmp-submit-review:hover,.ppmp-submit-review:focus,.ppmp-submit-review:active{background:#d9f7df!important;color:#166534!important;}\n.ppmp-entry-table-wrap{overflow-x:auto}
.ppmp-entry-table{min-width:2400px}.ppmp-entry-table th,.ppmp-entry-table td{vertical-align:top;padding:7px}.ppmp-entry-table th{white-space:nowrap}.ppmp-entry-table .input,.ppmp-entry-table .select{min-width:120px}.ppmp-entry-table textarea{min-width:180px;resize:vertical}.ppmp-entry-table .ppmp-row-date{min-width:135px}.ppmp-entry-table .ppmp-row-qty,.ppmp-entry-table .ppmp-row-unit-price,.ppmp-entry-table .ppmp-row-total{min-width:110px}.ppmp-row-documents{min-width:280px}
.ppmp-row-documents .ppmp-document-row{display:grid;grid-template-columns:1fr 1fr auto;gap:5px;margin-bottom:5px;align-items:center}
.ppmp-row-documents .input{min-width:0}
.ppmp-row-documents .ppmp-add-document{white-space:nowrap}
.ppmp-entry-table .ppmp-row-number{font-weight:700;text-align:center}.ppmp-item-autocomplete{position:relative;min-width:240px}.ppmp-item-suggestions{position:absolute;left:0;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #cfd6df;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;display:none}.ppmp-item-suggestion{display:block;width:100%;padding:8px 10px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:13px}.ppmp-item-suggestion:hover,.ppmp-item-suggestion:focus{background:#eef5ff}.ppmp-item-suggestion small{display:block;color:#6b7280;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ppmp-entry-table .ppmp-total-budget{border:0;background:transparent;font-weight:700;font-size:12px!important}.ppmp-entry-table .ppmp-remove-row{white-space:nowrap}

.ppmp-entry-table .ppmp-masterlist-locked{background:#e5e7eb!important;color:#6b7280!important;border:0!important;outline:0!important;box-shadow:none!important;cursor:not-allowed}
.ppmp-entry-table textarea.ppmp-masterlist-locked{resize:none;border:0!important;outline:0!important;box-shadow:none!important}
.ppmp-entry-table .ppmp-total-budget{background:#e5e7eb!important;color:#4b5563!important;border:0!important;outline:0!important;box-shadow:none!important;font-weight:800!important}

.ppmp-entry-table .ppmp-item-name{min-width:0}
.ppmp-entry-table .ppmp-item-description{min-width:0;width:100%;box-sizing:border-box}
.ppmp-entry-table .ppmp-row-unit-price,.ppmp-entry-table .ppmp-total-budget{text-align:right!important}
.ppmp-entry-table .ppmp-total-budget{font-weight:700!important}

.ppmp-entry-table th:nth-child(4),.ppmp-entry-table td:nth-child(4){width:16%;min-width:220px}
.ppmp-entry-table th:nth-child(5),.ppmp-entry-table td:nth-child(5){width:22%;min-width:300px}
</style>\n<style>.ppmp-document-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,.8fr) auto;gap:10px;align-items:center;margin-bottom:10px}@media(max-width:899px){.ppmp-document-row{grid-template-columns:1fr}}</style><?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();
$currentUser=currentUser();
$currentUserId=(int)($currentUser['id']??0);
$currentUserName=trim((string)($currentUser['full_name']??''));
$isPpmpSupervisor=currentUserIsPpmpSupervisor();
$isDivisionHeadPpmpOwner=false;
if($isPpmpSupervisor && $currentUserId>0 && currentLoginDivisionId()>0 && $currentUserName!==''){
  $stDivisionHead=$pdo->prepare('SELECT COUNT(*) FROM divisions WHERE id=? AND ppmp_supervisor_enabled=1 AND division_head=?');
  $stDivisionHead->execute([currentLoginDivisionId(),$currentUserName]);
  $isDivisionHeadPpmpOwner=(int)$stDivisionHead->fetchColumn()>0;
}
$canManagePpmp=true;
try{
  $col=$pdo->query("SHOW COLUMNS FROM ppmp_items LIKE 'saved_at'")->fetch();
  if(!$col) $pdo->exec("ALTER TABLE ppmp_items ADD COLUMN saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_at");
  $col=$pdo->query("SHOW COLUMNS FROM ppmp_items LIKE 'total_budget'")->fetch();
  if(!$col) $pdo->exec("ALTER TABLE ppmp_items ADD COLUMN total_budget DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER unit_price");
  // The old schema enforced one row per End-User/Fiscal Year. PPMP now
  // allows multiple item records under one PPMP number, so remove that legacy
  // unique index automatically for existing installations.
  $idx=$pdo->query("SHOW INDEX FROM ppmp_items WHERE Key_name='uq_ppmp_fiscal_year_area'")->fetch();
  if($idx) $pdo->exec("ALTER TABLE ppmp_items DROP INDEX uq_ppmp_fiscal_year_area");
}catch(PDOException $e){}

try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS ppmp_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiscal_year YEAR NOT NULL,
    area_id INT UNSIGNED NOT NULL,
    ppmp_no VARCHAR(80) NOT NULL,
    status ENUM('Draft','Pending for Review','Pending for Approval','Approved','Declined') NOT NULL DEFAULT 'Draft',
    submitted_by INT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    supervisor_reviewed_by INT UNSIGNED NULL,
    supervisor_reviewed_at DATETIME NULL,
    budget_reviewed_by INT UNSIGNED NULL,
    budget_reviewed_at DATETIME NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ppmp_review_area FOREIGN KEY(area_id) REFERENCES areas(id),
    CONSTRAINT fk_ppmp_review_submitter FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ppmp_review_supervisor FOREIGN KEY(supervisor_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ppmp_review_budget FOREIGN KEY(budget_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_ppmp_review (fiscal_year, area_id, ppmp_no),
    INDEX idx_ppmp_review_status (status),
    INDEX idx_ppmp_review_area (area_id)
  ) ENGINE=InnoDB");
}catch(PDOException $e){}

$ppmpMasterlistRows=[];
try{
  $ppmpMasterlistRows=$pdo->query("SELECT id,item_name,technical_specifications,unit_cost FROM ppmp_masterlist WHERE TRIM(item_name)<>'' ORDER BY item_name ASC,id ASC")->fetchAll();
}catch(PDOException $e){ $ppmpMasterlistRows=[]; }

$currentFiscalYear=(int)date('Y');
$entryFiscalYears=range($currentFiscalYear,$currentFiscalYear+3);
$existingFiscalYears=$isPpmpSupervisor
  ? array_map('intval',$pdo->query('SELECT DISTINCT p.fiscal_year FROM ppmp_items p JOIN areas a ON a.id=p.area_id WHERE p.fiscal_year IS NOT NULL AND a.division_id='.(int)currentLoginDivisionId().' ORDER BY p.fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN))
  : array_map('intval',$pdo->query('SELECT DISTINCT fiscal_year FROM ppmp_items WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
$searchFiscalYears=array_values(array_unique(array_merge($existingFiscalYears,$entryFiscalYears)));
rsort($searchFiscalYears);
$year=(int)($_GET['year']??(($isPpmpSupervisor && $searchFiscalYears) ? $searchFiscalYears[0] : $currentFiscalYear));
if($searchFiscalYears && !in_array($year,$searchFiscalYears,true)) $year=$searchFiscalYears[0];
elseif(!$searchFiscalYears && $isPpmpSupervisor) $year=$currentFiscalYear;
$areaId=(int)($_GET['area_id']??0);
$divisionId=(int)($_GET['division_id']??0);
$areaHeadName=trim((string)($_GET['area_head']??''));
$q=trim($_GET['q']??'');
$print=isset($_GET['print']) && $_GET['print']=='1';
$editId=(int)($_GET['edit']??0);
$editing=null;
if($editId>0 && !$print){
  $stEdit=$pdo->prepare('SELECT p.*,a.division_id FROM ppmp_items p JOIN areas a ON a.id=p.area_id WHERE p.id=?'); $stEdit->execute([$editId]); $editing=$stEdit->fetch();
  if(!$editing){ flash('error','PPMP item not found.'); header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit; }
  if($isPpmpSupervisor && ((int)$editing['created_by']!==$currentUserId || (int)$editing['division_id']!==currentLoginDivisionId())){ http_response_code(403); exit('403 - Supervisors may only edit PPMP items they created.'); }
  $year=(int)$editing['fiscal_year']; $areaId=(int)$editing['area_id'];
}

function ppmpSaveFormError(string $message,int $year,int $areaId,int $id=0): void{
  $_SESSION['ppmp_form_old']=$_POST;
  $_SESSION['ppmp_form_edit_id']=$id;
  flash('error',$message);
  header('Location:ppmp.php?year='.$year.'&area_id='.$areaId.($id>0?'&edit='.$id:'').'#ppmpForm');
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  requireRole(['Administrator','Editor']); checkCsrf();
  $action=$_POST['action']??'add';
  $id=(int)($_POST['id']??0);
  $year=(int)($_POST['fiscal_year']??0);
  $areaId=(int)($_POST['area_id']??0);
  $divisionId=(int)($_POST['division_id']??0);
  if($divisionId<=0 && $areaId>0){$stPostDivision=$pdo->prepare('SELECT division_id FROM areas WHERE id=? LIMIT 1');$stPostDivision->execute([$areaId]);$divisionId=(int)$stPostDivision->fetchColumn();}
  $areaHeadName=trim((string)($_POST['area_head']??''));
  $q=trim((string)($_POST['q']??''));
  $requestedBy='';

  if($action==='add_bulk'){
    $items=$_POST['items']??[];
    if(!is_array($items)||!$items) ppmpSaveFormError('Add at least one PPMP item row.',$year,$areaId);
    if(!in_array($year,$entryFiscalYears,true)) ppmpSaveFormError('Fiscal Year is outside the permitted range.',$year,$areaId);
    $preparedBy=trim($_POST['prepared_by']??''); $requestedBy=''; $preparedPosition='';
    $submittedByName='';
    $submittedPosition='';

    // A Division/Department Head creating their own PPMP must always save the
    // PPMP against the dedicated Division/Department Area/Unit record and use
    // the Division/Department Head as the End-User/Area Head. Do not rely on
    // the browser's selected area/head values because those can be stale or
    // altered before the POST reaches the server.
    if($isDivisionHeadPpmpOwner && currentLoginDivisionId()>0){
      $stOwnDivision=$pdo->prepare('SELECT id,name,division_head,head_position_designation
        FROM divisions WHERE id=? AND ppmp_supervisor_enabled=1
          AND LOWER(TRIM(division_head))=LOWER(TRIM(?)) LIMIT 1');
      $stOwnDivision->execute([currentLoginDivisionId(),$currentUserName]);
      $ownDivision=$stOwnDivision->fetch();
      if(!$ownDivision){
        ppmpSaveFormError('Unable to resolve your Division/Department Head assignment.',$year,$areaId);
      }

      $stOwnArea=$pdo->prepare('SELECT id FROM areas WHERE division_id=? AND name=? LIMIT 1');
      $stOwnArea->execute([currentLoginDivisionId(),$ownDivision['name']]);
      $ownDivisionAreaId=(int)$stOwnArea->fetchColumn();

      if($ownDivisionAreaId<=0){
        try{
          $stCreateOwnArea=$pdo->prepare('INSERT INTO areas (division_id,name,code) VALUES (?,?,?)');
          $stCreateOwnArea->execute([currentLoginDivisionId(),$ownDivision['name'],'DIV-'.currentLoginDivisionId()]);
          $ownDivisionAreaId=(int)$pdo->lastInsertId();
        }catch(PDOException $e){
          $stOwnArea->execute([currentLoginDivisionId(),$ownDivision['name']]);
          $ownDivisionAreaId=(int)$stOwnArea->fetchColumn();
        }
      }

      if($ownDivisionAreaId<=0){
        ppmpSaveFormError('Unable to create or locate the Division/Department Area/Unit record.',$year,$areaId);
      }

      $areaId=$ownDivisionAreaId;
      $requestedBy=trim((string)$ownDivision['division_head']);
      $preparedBy=$requestedBy;
      $preparedPosition=trim((string)($ownDivision['head_position_designation']??''));
      $submittedByName=$requestedBy;
      $submittedPosition=$preparedPosition;
    }elseif($requestedBy!==''){
      $stRequested=$pdo->prepare('SELECT position_designation FROM area_personnel WHERE area_id=? AND name=? LIMIT 1'); $stRequested->execute([$areaId,$requestedBy]); $requestedPerson=$stRequested->fetch();
      if(!$requestedPerson) ppmpSaveFormError('Requested By must be selected from personnel assigned to the selected Area/Unit.',$year,$areaId);
      $preparedPosition=trim($requestedPerson['position_designation']??'');
    }
    $stExisting=$pdo->prepare('SELECT ppmp_no FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no IS NOT NULL AND ppmp_no<>"" ORDER BY id LIMIT 1'); $stExisting->execute([$year,$areaId]); $bulkPpmpNo=trim((string)$stExisting->fetchColumn());
    if($bulkPpmpNo===''){
      $stSeries=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(ppmp_no,'-',-1) AS UNSIGNED)) FROM ppmp_items WHERE fiscal_year=? AND ppmp_no LIKE CONCAT('PPMP-',?,'-%')"); $stSeries->execute([$year,$year]); $bulkPpmpNo='PPMP-'.$year.'-'.str_pad((string)(((int)$stSeries->fetchColumn())+1),4,'0',STR_PAD_LEFT);
    }
    // Additional PPMP item rows may be added from the same workspace even when
    // the existing PPMP has already reached Pending for Approval or Approved.
    // Newly saved rows are placed in Pending for Review and the PPMP review queue
    // is reopened so the Supervisor can review the new rows.
    $uploadDir=__DIR__.'/uploads/ppmp';
    if(!is_dir($uploadDir)) @mkdir($uploadDir,0775,true);
    $insert=$pdo->prepare('INSERT INTO ppmp_items (fiscal_year,ppmp_no,area_id,category_id,item_name,description,procurement_type,quantity,unit,procurement_mode,preprocurement_conference,start_procurement,end_procurement,delivery_period,source_of_funds,unit_price,total_budget,supporting_documents,requested_by,prepared_by,prepared_position,submitted_by,submitted_position,budget_approved_by,budget_position,prepared_date,submitted_date,budget_date,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $saved=0; $pdo->beginTransaction();
    try{
      foreach($items as $itemIndex=>$item){
        if(!is_array($item)) continue;
        $categoryId=(int)($item['category_id']??0);
        $itemName=trim((string)($item['item_name']??''));
        $description=trim((string)($item['description']??''));
        $procurementType=trim((string)($item['procurement_type']??''));
        $qty=max(0,(float)str_replace(',','',(string)($item['quantity']??0)));
        $unit=trim((string)($item['unit']??''));
        $unitPrice=max(0,(float)str_replace(',','',(string)($item['unit_price']??0)));
        $procurementMode=trim((string)($item['procurement_mode']??''));
        $preprocurement=trim((string)($item['preprocurement_conference']??''));
        $startProcurement=trim((string)($item['start_procurement']??''));
        $endProcurement=trim((string)($item['end_procurement']??''));
        $deliveryPeriod=trim((string)($item['delivery_period']??''));
        $sourceOfFunds=trim((string)($item['source_of_funds']??''));
        $remarks=trim((string)($item['remarks']??''));
        if($itemName===''||$description===''||$categoryId<=0){
          throw new RuntimeException('Every PPMP row must have Category, Item Name, and Technical Specifications.');
        }
        $rowDocs=array();
        if(isset($_FILES['items']['name'])){
          $fileNames=$_FILES['items']['name'];
          $fileTypes=$_FILES['items']['type'];
          $fileTmp=$_FILES['items']['tmp_name'];
          $fileErrors=$_FILES['items']['error'];
          $fileSizes=$_FILES['items']['size'];
          $itemKey=(int)$itemIndex;
          if(isset($fileNames[$itemKey]['supporting_documents']) && is_array($fileNames[$itemKey]['supporting_documents'])){
            $documentNamesForRow=$item['supporting_document_names']??array();
            foreach($fileNames[$itemKey]['supporting_documents'] as $docIndex=>$originalName){
              if(($fileErrors[$itemKey]['supporting_documents'][$docIndex]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
              if(($fileErrors[$itemKey]['supporting_documents'][$docIndex]??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new RuntimeException('One or more supporting documents for item row '.($itemKey+1).' could not be uploaded.');
              if((int)($fileSizes[$itemKey]['supporting_documents'][$docIndex]??0)>20*1024*1024) throw new RuntimeException('Each supporting PDF must not exceed 20 MB.');
              $tmpFile=$fileTmp[$itemKey]['supporting_documents'][$docIndex]??'';
              $ext=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));
              $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmpFile);
              if($ext!=='pdf'||$mime!=='application/pdf') throw new RuntimeException('Supporting Documents must be PDF files only.');
              $documentName=trim((string)($documentNamesForRow[$docIndex]??''));
              if($documentName==='') throw new RuntimeException('Please provide a name for every supporting PDF in item row '.($itemKey+1).'.');
              $safeName='ppmp_'.date('YmdHis').'_'.$itemKey.'_'.$docIndex.'_'.bin2hex(random_bytes(5)).'.pdf';
              if(!move_uploaded_file($tmpFile,$uploadDir.'/'.$safeName)) throw new RuntimeException('Unable to save a supporting PDF for item row '.($itemKey+1).'.');
              $rowDocs[]=array('name'=>$documentName,'original_name'=>basename($originalName),'path'=>'uploads/ppmp/'.$safeName,'uploaded_at'=>date('Y-m-d H:i:s'));
            }
          }
        }
        $docsJson=$rowDocs?json_encode($rowDocs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):'';
        $createdBy=(int)(currentUser()['id']??0);
        $insertValues=array(
          $year,$bulkPpmpNo,$areaId,$categoryId,$itemName,$description,$procurementType,$qty,$unit,$procurementMode,
          $preprocurement,$startProcurement,$endProcurement,$deliveryPeriod,$sourceOfFunds,$unitPrice,$qty*$unitPrice,
          $docsJson,$requestedBy,$preparedBy,$preparedPosition,$submittedByName,$submittedPosition,'','',null,null,null,$remarks,$createdBy
        );
        $insert->execute($insertValues);
        $saved++;
      }
      if($saved===0) throw new RuntimeException('No valid PPMP item rows were submitted.');

      // Saving a new PPMP automatically places it in the Supervisor review queue.
      $stReviewSave=$pdo->prepare("SELECT id,status FROM ppmp_reviews WHERE fiscal_year=? AND area_id=? AND ppmp_no=? LIMIT 1");
      $stReviewSave->execute([$year,$areaId,$bulkPpmpNo]);
      $existingReview=$stReviewSave->fetch();

      if($existingReview){
        // Adding rows reopens the PPMP review queue. Existing item-level
        // decisions are preserved; only the PPMP header workflow is reopened
        // so the newly added rows can be reviewed.
        $stReviewUpdate=$pdo->prepare("UPDATE ppmp_reviews
          SET status='Pending for Review',
              submitted_by=?,
              submitted_at=CURRENT_TIMESTAMP,
              supervisor_reviewed_by=NULL,
              supervisor_reviewed_at=NULL,
              budget_reviewed_by=NULL,
              budget_reviewed_at=NULL,
              remarks=NULL,
              updated_at=CURRENT_TIMESTAMP
          WHERE id=?");
        $stReviewUpdate->execute([(int)(currentUser()['id']??0), (int)$existingReview['id']]);
        $reviewId=(int)$existingReview['id'];
      }else{
        $stReviewInsert=$pdo->prepare("INSERT INTO ppmp_reviews
          (fiscal_year,area_id,ppmp_no,status,submitted_by,submitted_at)
          VALUES (?,?,?,'Pending for Review',?,CURRENT_TIMESTAMP)");
        $stReviewInsert->execute([$year,$areaId,$bulkPpmpNo,(int)(currentUser()['id']??0)]);
        $reviewId=(int)$pdo->lastInsertId();
      }

      // Ensure every saved PPMP item has a corresponding Supervisor review row.
      $stSavedItems=$pdo->prepare("SELECT id FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no=? ORDER BY id");
      $stSavedItems->execute([$year,$areaId,$bulkPpmpNo]);
      $stReviewItem=$pdo->prepare("SELECT id FROM ppmp_review_items WHERE review_id=? AND ppmp_item_id=? LIMIT 1");
      $stInsertReviewItem=$pdo->prepare("INSERT INTO ppmp_review_items
        (review_id,ppmp_item_id,status)
        VALUES (?,?,'Pending for Review')");
      foreach($stSavedItems->fetchAll(PDO::FETCH_COLUMN) as $savedItemId){
        $stReviewItem->execute([$reviewId,(int)$savedItemId]);
        if(!$stReviewItem->fetchColumn()){
          $stInsertReviewItem->execute([$reviewId,(int)$savedItemId]);
        }
      }

      $pdo->commit();
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); ppmpSaveFormError('Unable to save the PPMP items: '.$e->getMessage(),$year,$areaId); }
    $stSavedDivision=$pdo->prepare('SELECT division_id FROM areas WHERE id=? LIMIT 1');
    $stSavedDivision->execute([$areaId]);
    $savedDivisionId=(int)$stSavedDivision->fetchColumn();
    flash('success',$saved.' PPMP item(s) saved under '.$bulkPpmpNo.'. All saved items are now Pending for Review.');
    header('Location:ppmp.php?year='.$year.'&division_id='.$savedDivisionId.'&area_id='.$areaId);
    exit;
  }

  if($action==='submit_for_review'){
    if($isPpmpSupervisor){
      http_response_code(403); exit('403 - A Supervisor may manage only their own PPMP and may not submit it as a subordinate PPMP for Supervisor review.');
    }
    if($areaId<=0 || $year<=0){
      flash('error','Select a Fiscal Year and a specific Area/Unit before submitting the PPMP for review.');
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }
    $stItems=$pdo->prepare('SELECT ppmp_no, COUNT(*) item_count FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no IS NOT NULL AND ppmp_no<>"" GROUP BY ppmp_no ORDER BY ppmp_no LIMIT 1');
    $stItems->execute([$year,$areaId]); $ppmpSet=$stItems->fetch();
    if(!$ppmpSet){
      flash('error','There are no saved PPMP items to submit for review.');
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }
    $ppmpNo=trim((string)$ppmpSet['ppmp_no']);

    $stReview=$pdo->prepare('SELECT * FROM ppmp_reviews WHERE fiscal_year=? AND area_id=? AND ppmp_no=? LIMIT 1');
    $stReview->execute([$year,$areaId,$ppmpNo]); $review=$stReview->fetch();
    if($review && in_array($review['status'],['Pending for Review','Pending for Approval','Approved'],true)){
      flash('error','This PPMP is already '.$review['status'].'.');
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }

    // The Division/Department Head is the PPMP Supervisor/Authorized Person only
    // when the Division/Department is explicitly marked Yes in Area/Unit Management.
    $stTarget=$pdo->prepare("SELECT d.division_head name,d.head_position_designation position_designation,d.ppmp_supervisor_enabled
      FROM areas a JOIN divisions d ON d.id=a.division_id WHERE a.id=? LIMIT 1");
    $stTarget->execute([$areaId]); $target=$stTarget->fetch();
    if(!$target || (int)$target['ppmp_supervisor_enabled']!==1){
      flash('error','The selected Division/Department has not assigned its Head as the Supervisor/Authorized Person for PPMP review.');
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }
    if(!$target || trim((string)$target['name'])===''){
      flash('error','No Supervisor or Authorized Person is assigned to this Area/Unit.');
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }
    $stUser=$pdo->prepare('SELECT id FROM users WHERE full_name=? AND status="Active" LIMIT 1');
    $stUser->execute([trim($target['name'])]); $targetUserId=(int)$stUser->fetchColumn();
    if($targetUserId<=0){
      flash('error','The Supervisor/Authorized Person ('.trim($target['name']).') does not have an active User account for PPMP review.');
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }

    $pdo->beginTransaction();
    try{
      if($review){
        $reviewId=(int)$review['id'];
        $st=$pdo->prepare("UPDATE ppmp_reviews SET status='Pending for Review',submitted_by=?,submitted_at=NOW(),supervisor_reviewed_by=NULL,supervisor_reviewed_at=NULL,budget_reviewed_by=NULL,budget_reviewed_at=NULL,remarks=NULL WHERE id=?");
        $st->execute([currentUser()['id'],$reviewId]);
        $pdo->prepare('DELETE FROM ppmp_review_items WHERE review_id=?')->execute([$reviewId]);
      }else{
        $st=$pdo->prepare("INSERT INTO ppmp_reviews(fiscal_year,area_id,ppmp_no,status,submitted_by,submitted_at) VALUES(?,?,?,'Pending for Review',?,NOW())");
        $st->execute([$year,$areaId,$ppmpNo,currentUser()['id']]);
        $reviewId=(int)$pdo->lastInsertId();
      }
      $stItem=$pdo->prepare('SELECT id FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no=? ORDER BY id');
      $stItem->execute([$year,$areaId,$ppmpNo]);
      $ins=$pdo->prepare("INSERT INTO ppmp_review_items(review_id,ppmp_item_id,status) VALUES(?,?, 'Pending for Review')");
      foreach($stItem->fetchAll(PDO::FETCH_COLUMN) as $ppmpItemId){$ins->execute([$reviewId,(int)$ppmpItemId]);}
      $pdo->commit();
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      flash('error','Unable to submit the PPMP for review: '.$e->getMessage());
      header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
    }
    flash('success','The entire '.$ppmpNo.' PPMP list has been submitted to '.$target['name'].' for review.');
    header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
  }

  if($isPpmpSupervisor && in_array($action,['add','edit','delete'],true)){
    if($action==='edit' && $id<=0){ http_response_code(403); exit('403 - Invalid PPMP item.'); }
    // A Division/Department Head who is the assigned PPMP Supervisor may create
    // their first own PPMP before any item exists. Existing supervisors remain
    // limited to PPMP items they personally created within their own Division.
    if($action==='add' && $isDivisionHeadPpmpOwner){
      $stOwnDivision=$pdo->prepare('SELECT COUNT(*) FROM areas WHERE id=? AND division_id=?');
      $stOwnDivision->execute([$areaId,currentLoginDivisionId()]);
      if((int)$stOwnDivision->fetchColumn()===0){ http_response_code(403); exit('403 - Your own PPMP must belong to an Area/Unit under your Division/Department.'); }
    }else{
      $stOwn=$pdo->prepare("SELECT COUNT(*) FROM ppmp_items p JOIN areas a ON a.id=p.area_id WHERE p.created_by=? AND p.fiscal_year=? AND p.area_id=? AND a.division_id=?" );
      $stOwn->execute([$currentUserId,$year,$areaId,currentLoginDivisionId()]);
      if((int)$stOwn->fetchColumn()===0){
        http_response_code(403); exit('403 - Supervisors may only add, edit, or delete items from a PPMP they created within their own Division/Department.');
      }
    }
    if($action==='edit'){
      $stItemOwner=$pdo->prepare('SELECT created_by,area_id FROM ppmp_items WHERE id=? LIMIT 1'); $stItemOwner->execute([$id]); $ownerRow=$stItemOwner->fetch();
      if(!$ownerRow || (int)$ownerRow['created_by']!==$currentUserId || (int)$ownerRow['area_id']!==$areaId){ http_response_code(403); exit('403 - You may only modify PPMP items you created.'); }
    }
  }



  if(!in_array($year,$entryFiscalYears,true)){
    ppmpSaveFormError('Fiscal Year must be between '.$currentFiscalYear.' and '.($currentFiscalYear+3).'.',$currentFiscalYear,$areaId,$id);
  }

  $stWorkflowLock=$pdo->prepare('SELECT status FROM ppmp_reviews WHERE fiscal_year=? AND area_id=? ORDER BY id DESC LIMIT 1');
  $stWorkflowLock->execute([$year,$areaId]);
  $workflowStatus=(string)$stWorkflowLock->fetchColumn();

  // PPMP actions are controlled at the item level. An item that is already
  // Pending for Approval may be edited or deleted independently. If it is edited,
  // only that item is returned to Pending for Review so the Supervisor can review
  // the revised item again. Declined items may be deleted, but cannot be edited.
  $declinedItemEdit=false;
  $pendingApprovalItemEdit=false;
  $itemReviewStatus='';
  $itemReviewId=0;
  if(in_array($action,['edit','delete'],true) && $id>0){
    $stItemReview=$pdo->prepare("SELECT pri.id,pri.status,pr.id review_id
      FROM ppmp_review_items pri
      JOIN ppmp_reviews pr ON pr.id=pri.review_id
      WHERE pri.ppmp_item_id=? AND pr.fiscal_year=? AND pr.area_id=?
      ORDER BY pr.id DESC LIMIT 1");
    $stItemReview->execute([$id,$year,$areaId]);
    $itemReview=$stItemReview->fetch();
    if($itemReview){
      $itemReviewId=(int)$itemReview['id'];
      $itemReviewStatus=(string)$itemReview['status'];
      $declinedItemEdit=in_array($itemReviewStatus,['Declined','Budget Declined'],true);
      $pendingApprovalItemEdit=$itemReviewStatus==='Pending for Approval';
    }
  }

  // Editing is permitted for Draft and Pending for Approval items.
  // Deleting is permitted for Draft, Pending for Approval, and Declined items.
  // Other workflow states remain locked.
  $itemWorkflowException=
    $declinedItemEdit ||
    ($pendingApprovalItemEdit && in_array($action,['edit','delete'],true));

  if($workflowStatus!=='' && !in_array($workflowStatus,['Draft','Declined'],true)
     && in_array($action,['add','edit','delete'],true) && !$itemWorkflowException){
    ppmpSaveFormError('This PPMP is '.$workflowStatus.' and can no longer be changed until the review workflow is completed.',$year,$areaId,$id);
  }

  // A Declined item is intentionally delete-only.
  if($action==='edit' && $declinedItemEdit){
    ppmpSaveFormError('A Declined PPMP item can only be deleted. Please delete it and create a new item if necessary.',$year,$areaId,$id);
  }

  if($action==='delete'){
    if($id<=0){ flash('error','Invalid PPMP item.'); }
    else{
      $stDel=$pdo->prepare('DELETE FROM ppmp_items WHERE id=?');
      $stDel->execute([$id]);
      flash($stDel->rowCount() ? 'success' : 'error',$stDel->rowCount() ? 'PPMP item deleted.' : 'PPMP item not found.');
    }
    header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
  }

  $qty=max(0,(float)str_replace(',','',$_POST['quantity']??0));
  $unitPrice=max(0,(float)str_replace(',','',$_POST['unit_price']??0));
  $totalBudget=$qty*$unitPrice;
  $supportingDocuments=trim($_POST['existing_supporting_documents']??'');
  $existingDocs=[];
  if($supportingDocuments!==''){ $decoded=json_decode($supportingDocuments,true); if(is_array($decoded)) $existingDocs=$decoded; }
  if(!empty($_FILES['supporting_documents']['name']) && is_array($_FILES['supporting_documents']['name'])){
    $uploadDir=__DIR__.'/uploads/ppmp';
    if(!is_dir($uploadDir)) @mkdir($uploadDir,0775,true);
    foreach($_FILES['supporting_documents']['name'] as $i=>$originalName){
      if($_FILES['supporting_documents']['error'][$i]===UPLOAD_ERR_NO_FILE) continue;
      if($_FILES['supporting_documents']['error'][$i]!==UPLOAD_ERR_OK){ ppmpSaveFormError('One or more supporting documents could not be uploaded.',$year,$areaId,$id); }
      if((int)$_FILES['supporting_documents']['size'][$i]>20*1024*1024){ ppmpSaveFormError('Each supporting PDF must not exceed 20 MB.',$year,$areaId,$id); }
      $ext=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));
      $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['supporting_documents']['tmp_name'][$i]);
      if($ext!=='pdf' || $mime!=='application/pdf'){ ppmpSaveFormError('Attached Supporting Documents must be PDF files only.',$year,$areaId,$id); }
      $documentName=trim((string)(($_POST['supporting_document_names']??[])[$i]??''));
      if($documentName===''){ ppmpSaveFormError('Please provide a Name for every Attached Supporting Document.',$year,$areaId,$id); }
      $safeName='ppmp_'.date('YmdHis').'_'.$i.'_'.bin2hex(random_bytes(5)).'.pdf';
      if(!move_uploaded_file($_FILES['supporting_documents']['tmp_name'][$i],$uploadDir.'/'.$safeName)){ ppmpSaveFormError('Unable to save one or more supporting PDF files.',$year,$areaId,$id); }
      $existingDocs[]=['name'=>$documentName,'original_name'=>basename($originalName),'path'=>'uploads/ppmp/'.$safeName,'uploaded_at'=>date('Y-m-d H:i:s')];
    }
  }
  $supportingDocuments=$existingDocs ? json_encode($existingDocs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : '';
  // One PPMP header/number is maintained per End-User / Implementing Unit and Fiscal Year.
  // Multiple item records may be added, edited, or deleted under that same PPMP.
  // When adding an item, reuse the existing PPMP number for the selected End-User/Fiscal Year.
  // A new PPMP number is generated only when that End-User/Fiscal Year has no PPMP yet.
  $stExistingPpmp=$pdo->prepare('SELECT ppmp_no FROM ppmp_items WHERE fiscal_year=? AND area_id=? AND ppmp_no IS NOT NULL AND ppmp_no<>"" ORDER BY id LIMIT 1');
  $stExistingPpmp->execute([$year,$areaId]);
  $existingPpmpNo=trim((string)$stExistingPpmp->fetchColumn());

  if($action==='add'){
    if($existingPpmpNo!==''){
      $ppmpNo=$existingPpmpNo;
    }else{
      $stSeries=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(ppmp_no,'-',-1) AS UNSIGNED)) FROM ppmp_items WHERE fiscal_year=? AND ppmp_no LIKE CONCAT('PPMP-',?,'-%')");
      $stSeries->execute([$year,$year]);
      $nextSeries=((int)$stSeries->fetchColumn())+1;
      $ppmpNo='PPMP-'.$year.'-'.str_pad((string)$nextSeries,4,'0',STR_PAD_LEFT);
    }
  }else{
    // Keep the existing PPMP number when editing an item. If the item is realigned
    // to another End-User/Fiscal Year that already has a PPMP, use that PPMP number.
    $ppmpNo=$existingPpmpNo!=='' ? $existingPpmpNo : trim($_POST['ppmp_no']??($editing['ppmp_no']??''));
  }
  // Normalize timeline dates server-side so editing a PPMP cannot lose already-saved
  // dates if the browser-side hidden-date synchronization does not run.
  $normalizePpmpDate=function($value){
    $value=trim((string)$value);
    if($value==='') return '';
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)) return $value;
    $timestamp=strtotime($value);
    return $timestamp!==false ? date('Y-m-d',$timestamp) : $value;
  };
  $startProcurement=$normalizePpmpDate($_POST['start_procurement']??'');
  if($startProcurement==='') $startProcurement=$normalizePpmpDate($_POST['start_procurement_display']??'');
  $endProcurement=$normalizePpmpDate($_POST['end_procurement']??'');
  if($endProcurement==='') $endProcurement=$normalizePpmpDate($_POST['end_procurement_display']??'');
  $deliveryPeriod=$normalizePpmpDate($_POST['delivery_period']??'');
  if($deliveryPeriod==='') $deliveryPeriod=$normalizePpmpDate($_POST['delivery_period_display']??'');

  $values=[
    $year,$ppmpNo,$areaId,(int)$_POST['category_id'],trim($_POST['item_name']),
    trim($_POST['description']??''),trim($_POST['procurement_type']??''),$qty,trim($_POST['unit']),
    trim($_POST['procurement_mode']??''),trim($_POST['preprocurement_conference']??''),
    $startProcurement,$endProcurement,$deliveryPeriod,
    trim($_POST['source_of_funds']??''),$unitPrice,$totalBudget,$supportingDocuments,
    $requestedBy,trim($_POST['prepared_by']??''),$preparedPosition,
    '', '', '', '', null, null, null, trim($_POST['remarks']??'')
  ];
  if($action==='edit' && $id>0){
    $st=$pdo->prepare('UPDATE ppmp_items SET fiscal_year=?,ppmp_no=?,area_id=?,category_id=?,item_name=?,description=?,procurement_type=?,quantity=?,unit=?,procurement_mode=?,preprocurement_conference=?,start_procurement=?,end_procurement=?,delivery_period=?,source_of_funds=?,unit_price=?,total_budget=?,supporting_documents=?,requested_by=?,prepared_by=?,prepared_position=?,submitted_by=?,submitted_position=?,budget_approved_by=?,budget_position=?,prepared_date=?,submitted_date=?,budget_date=?,remarks=? WHERE id=?');
    $st->execute([...$values,$id]);

    // If an item was already Pending for Approval, editing it invalidates the
    // previous Supervisor/Budget decisions for that item only. Return it to the
    // Supervisor's review queue while leaving other PPMP items untouched.
    if($itemReviewStatus==='Pending for Approval' && $itemReviewId>0){
      $stResetReviewItem=$pdo->prepare("UPDATE ppmp_review_items
        SET status='Pending for Review',
            supervisor_remarks=NULL,
            supervisor_reviewed_by=NULL,
            supervisor_reviewed_at=NULL,
            budget_remarks=NULL,
            budget_reviewed_by=NULL,
            budget_reviewed_at=NULL
        WHERE id=?");
      $stResetReviewItem->execute([$itemReviewId]);

      $stResetReview=$pdo->prepare("UPDATE ppmp_reviews SET status='Pending for Review',updated_at=CURRENT_TIMESTAMP WHERE id=?");
      $stResetReview->execute([$itemReview['review_id']]);
      flash('success','PPMP item updated and resubmitted to the Supervisor for review.');
    }else{
      flash('success','PPMP item updated.');
    }
  }else{
    $st=$pdo->prepare('INSERT INTO ppmp_items
      (fiscal_year,ppmp_no,area_id,category_id,item_name,description,procurement_type,quantity,unit,procurement_mode,
       preprocurement_conference,start_procurement,end_procurement,delivery_period,source_of_funds,unit_price,total_budget,
       supporting_documents,requested_by,prepared_by,prepared_position,submitted_by,submitted_position,
       budget_approved_by,budget_position,prepared_date,submitted_date,budget_date,remarks,created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([...$values,currentUser()['id']]); flash('success','PPMP item saved.');
  }
  header('Location:ppmp.php?year='.$year.'&division_id='.(int)$divisionId.'&area_id='.$areaId.'&area_head='.urlencode($areaHeadName).'&q='.urlencode($q).'#savedPpmpItems'); exit;
}
$formOld=$_SESSION['ppmp_form_old']??null;
$formOldEditId=(int)($_SESSION['ppmp_form_edit_id']??0);
unset($_SESSION['ppmp_form_old'],$_SESSION['ppmp_form_edit_id']);
$formState=$editing?:($formOld??[]);
$formIsEditing=$editing!==null || ($formOld!==null && (($formOld['action']??'')==='edit'));
$areas=$pdo->query('SELECT a.*,d.name division_name,d.division_head authorized_person,d.head_position_designation authorized_position,d.electronic_signature authorized_signature,d.ppmp_supervisor_enabled FROM areas a JOIN divisions d ON d.id=a.division_id ORDER BY d.name,a.name')->fetchAll();
$divisionHeadAreaId=0;
$divisionHeadRecord=null;
if($isDivisionHeadPpmpOwner && currentLoginDivisionId()>0){
  $stDivision=$pdo->prepare('SELECT id,name,division_head,head_position_designation FROM divisions WHERE id=? LIMIT 1');
  $stDivision->execute([currentLoginDivisionId()]);
  $divisionHeadRecord=$stDivision->fetch();
  if($divisionHeadRecord){
    $stDivisionArea=$pdo->prepare('SELECT id FROM areas WHERE division_id=? AND name=? LIMIT 1');
    $stDivisionArea->execute([currentLoginDivisionId(),$divisionHeadRecord['name']]);
    $divisionHeadAreaId=(int)$stDivisionArea->fetchColumn();
    if($divisionHeadAreaId<=0){
      try{
        $stCreateDivisionArea=$pdo->prepare('INSERT INTO areas (division_id,name,code) VALUES (?,?,?)');
        $stCreateDivisionArea->execute([currentLoginDivisionId(),$divisionHeadRecord['name'],'DIV-'.currentLoginDivisionId()]);
        $divisionHeadAreaId=(int)$pdo->lastInsertId();
      }catch(PDOException $e){
        $stDivisionArea->execute([currentLoginDivisionId(),$divisionHeadRecord['name']]);
        $divisionHeadAreaId=(int)$stDivisionArea->fetchColumn();
      }
    }
    if($divisionHeadAreaId>0){
      $divisionId=currentLoginDivisionId();
      $areaId=$divisionHeadAreaId;
      $areaHeadName=trim((string)$divisionHeadRecord['division_head']);
    }
  }
}
$supervisorOwnAreaIds=[];
$supervisorOwnFiscalYears=[];
$supervisorOwnPpmpExists=false;
if($isPpmpSupervisor && $currentUserId>0 && currentLoginDivisionId()>0){
  if($isDivisionHeadPpmpOwner && $divisionHeadAreaId>0){
    $areas=array_values(array_filter($areas,fn($a)=>(int)$a['id']===$divisionHeadAreaId));
  }
  $stOwnAreas=$pdo->prepare('SELECT DISTINCT p.area_id FROM ppmp_items p JOIN areas a ON a.id=p.area_id WHERE p.created_by=? AND a.division_id=? ORDER BY p.area_id');
  $stOwnAreas->execute([$currentUserId,currentLoginDivisionId()]);
  $supervisorOwnAreaIds=array_map('intval',$stOwnAreas->fetchAll(PDO::FETCH_COLUMN));
  $stOwnYears=$pdo->prepare('SELECT DISTINCT p.fiscal_year FROM ppmp_items p JOIN areas a ON a.id=p.area_id WHERE p.created_by=? AND a.division_id=? ORDER BY p.fiscal_year DESC');
  $stOwnYears->execute([$currentUserId,currentLoginDivisionId()]);
  $supervisorOwnFiscalYears=array_map('intval',$stOwnYears->fetchAll(PDO::FETCH_COLUMN));
  $supervisorOwnPpmpExists=!empty($supervisorOwnAreaIds);
  if($isPpmpSupervisor){
    $areas=array_values(array_filter($areas,fn($a)=>(int)$a['division_id']===currentLoginDivisionId()));
  }
  $canManagePpmp=$supervisorOwnPpmpExists || $isDivisionHeadPpmpOwner;
  if($isPpmpSupervisor && $divisionId<=0) $divisionId=currentLoginDivisionId();
  if($areaId>0 && $isPpmpSupervisor && !in_array($areaId,array_map('intval',array_column($areas,'id')),true)) $areaId=0;
}
$cats=$pdo->query("SELECT * FROM categories WHERE status='Active' ORDER BY name")->fetchAll();
$classifications=$pdo->query("SELECT name FROM classifications WHERE status='Active' ORDER BY name")->fetchAll();
$procurementMethods=$pdo->query("SELECT procurement_method,details FROM procurement_methods WHERE status='Active' ORDER BY procurement_method")->fetchAll();
$units=$pdo->query("SELECT id,name FROM units_of_measure WHERE status='Active' ORDER BY name")->fetchAll();
$nextPpmpNo='';
$ppmpNextByYear=[];
$stNextAll=$pdo->query('SELECT fiscal_year,ppmp_no FROM ppmp_items WHERE ppmp_no IS NOT NULL AND ppmp_no<>\'\' ORDER BY fiscal_year,ppmp_no');
foreach($stNextAll->fetchAll() as $seriesRow){
  $fy=(int)$seriesRow['fiscal_year'];
  $prefix='PPMP-'.$fy.'-';
  $number=(int)substr((string)$seriesRow['ppmp_no'],strlen($prefix));
  if(strpos((string)$seriesRow['ppmp_no'],$prefix)===0 && $number>0){
    $current=$ppmpNextByYear[$fy]??0;
    if($number>$current) $ppmpNextByYear[$fy]=$number;
  }
}
foreach($entryFiscalYears as $entryYear){
  $nextSeries=(int)($ppmpNextByYear[$entryYear]??0)+1;
  $ppmpNextByYear[$entryYear]='PPMP-'.$entryYear.'-'.str_pad((string)$nextSeries,4,'0',STR_PAD_LEFT);
}
$existingPpmpByYearArea=[];
$stExistingMap=$pdo->query('SELECT fiscal_year,area_id,MIN(ppmp_no) AS ppmp_no FROM ppmp_items WHERE ppmp_no IS NOT NULL AND ppmp_no<>\'\' GROUP BY fiscal_year,area_id');
foreach($stExistingMap->fetchAll() as $mapRow){
  $existingPpmpByYearArea[(int)$mapRow['fiscal_year'].':'.(int)$mapRow['area_id']]=(string)$mapRow['ppmp_no'];
}
if(!$editing){
  $existingForSelectedArea=$existingPpmpByYearArea[$year.':'.$areaId]??'';
  $nextPpmpNo=$existingForSelectedArea!=='' ? $existingForSelectedArea : ($ppmpNextByYear[$year]??('PPMP-'.$year.'-0001'));
}
$divisions=$pdo->query('SELECT id,name,division_head,head_position_designation,ppmp_supervisor_enabled FROM divisions ORDER BY name')->fetchAll();
$personnelByArea=[];
$stPersonnel=$pdo->query('SELECT id,area_id,name,position_designation,electronic_signature FROM area_personnel ORDER BY area_id,name');
foreach($stPersonnel->fetchAll() as $person){ $personnelByArea[(int)$person['area_id']][]=$person; }

$where=' WHERE p.fiscal_year=?'; $args=[$year];
if($isPpmpSupervisor){ $where.=' AND a.division_id=?'; $args[]=currentLoginDivisionId(); }
if($areaId>0){$where.=' AND p.area_id=?';$args[]=$areaId;}
if($q!==''){$where.=' AND (p.item_name LIKE ? OR p.description LIKE ? OR a.name LIKE ? OR c.name LIKE ?)';$args=[...$args,"%$q%","%$q%","%$q%","%$q%"];}
$sql='SELECT p.*,a.name area,d.name division_name,d.division_head authorized_person,d.head_position_designation authorized_position,c.name category,COALESCE(pri.status,pr.status,\'Draft\') review_status,COALESCE(pri.supervisor_remarks,pri.budget_remarks,pr.remarks,\'\') review_remarks FROM ppmp_items p JOIN areas a ON a.id=p.area_id JOIN divisions d ON d.id=a.division_id JOIN categories c ON c.id=p.category_id LEFT JOIN ppmp_reviews pr ON pr.fiscal_year=p.fiscal_year AND pr.area_id=p.area_id AND pr.ppmp_no=p.ppmp_no LEFT JOIN ppmp_review_items pri ON pri.review_id=pr.id AND pri.ppmp_item_id=p.id'.$where.' ORDER BY p.id';
$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
// Keep all items available for page metadata, but declined items are excluded
// from the official printed PPMP Form.
$allPpmpRows=$rows;

// Calculate the ABC totals by the current item-level review status so the
// Saved PPMP Items workspace shows the value of items at each review stage.
$ppmpStatusAbc=array(
  'Pending for Review'=>0.0,
  'Pending for Approval'=>0.0,
  'Declined'=>0.0,
  'Approved'=>0.0
);
foreach($rows as $ppmpStatusRow){
  $ppmpStatus=(string)($ppmpStatusRow['review_status']??'');
  if(array_key_exists($ppmpStatus,$ppmpStatusAbc)){
    $ppmpStatusAbc[$ppmpStatus]+=(float)(($ppmpStatusRow['total_budget']??0) ?: ((float)$ppmpStatusRow['quantity']*(float)$ppmpStatusRow['unit_price']));
  }
}
if($print){
  $rows=array_values(array_filter($rows,function($r){
    return !in_array((string)($r['review_status']??''),['Declined','Budget Declined'],true);
  }));
}

$selectedArea=null;
foreach($areas as $a){if((int)$a['id']===$areaId){$selectedArea=$a;break;}}
if($print && !$selectedArea && $rows){$areaId=(int)$rows[0]['area_id'];foreach($areas as $a){if((int)$a['id']===$areaId){$selectedArea=$a;break;}}}
if($print && !$selectedArea){flash('error','Select an Area/Unit before printing the PPMP form.');header('Location:ppmp.php?year='.$year);exit;}
if($print && $rows){
  $header=($allPpmpRows[0]??$rows[0]??null);
  $selectedArea=$selectedArea ?: ['name'=>$header['area'],'authorized_person'=>$header['authorized_person'],'authorized_position'=>$header['authorized_position']??''];
}
$supervisorPending=[];
if(!$print && $isPpmpSupervisor && currentLoginDivisionId()>0){
  // Build the Division Head review queue from every PPMP in the current
  // division that has at least one item still pending supervisor review.
  // This deliberately does not use the currently selected Area/Unit filter.
  $stSupervisorQueue=$pdo->prepare("SELECT r.id,r.fiscal_year,r.ppmp_no,r.status,r.submitted_at,a.name area,d.name division,u.full_name submitted_by_name,
    COUNT(DISTINCT p.id) item_count,
    COALESCE(SUM(CASE WHEN p.total_budget IS NULL OR p.total_budget=0 THEN p.quantity*p.unit_price ELSE p.total_budget END),0) total_abc
    FROM ppmp_reviews r
    JOIN areas a ON a.id=r.area_id
    JOIN divisions d ON d.id=a.division_id
    LEFT JOIN users u ON u.id=r.submitted_by
    LEFT JOIN ppmp_items p ON p.fiscal_year=r.fiscal_year AND p.area_id=r.area_id AND p.ppmp_no=r.ppmp_no
    WHERE d.id=?
      AND r.status='Pending for Review'
      AND EXISTS (SELECT 1 FROM ppmp_review_items pri_pending WHERE pri_pending.review_id=r.id AND pri_pending.status='Pending for Review')
    GROUP BY r.id,r.fiscal_year,r.ppmp_no,r.status,r.submitted_at,a.name,d.name,u.full_name,r.updated_at
    ORDER BY r.updated_at DESC");
  $stSupervisorQueue->execute([currentLoginDivisionId()]);$supervisorPending=$stSupervisorQueue->fetchAll();
}
pageStart('Project Procurement Management Plan');
?>
<?php if(!$print && $isPpmpSupervisor): ?>
<div class="panel" style="margin-bottom:16px"><h2>PPMPs Pending for Review</h2><p class="muted">Review PPMP submissions from all Areas/Units under your Division/Department.</p>
<?php if($supervisorPending): ?><div class="table-wrap"><table class="table"><tr><th>Division/Department</th><th>Area/Unit</th><th>PPMP No.</th><th>Fiscal Year</th><th>Items</th><th>Submitted By</th><th>Status</th><th>Action</th></tr>
<?php foreach($supervisorPending as $r): ?><tr><td><?=e($r['division'])?></td><td><?=e($r['area'])?></td><td><?=e($r['ppmp_no'])?></td><td><?=e((string)$r['fiscal_year'])?></td><td><?=e((string)$r['item_count'])?></td><td><?=e($r['submitted_by_name']??'')?></td><td><span class="ppmp-status-badge ppmp-status-pending-for-review">Pending for Review</span></td><td><a class="btn secondary ppmp-action-btn" href="ppmp_review.php?review_id=<?=$r['id']?>">Review PPMP</a></td></tr><?php endforeach; ?></table></div>
<?php else: ?><div class="empty">No Areas/Units have submitted a PPMP for review.</div><?php endif; ?></div>
<?php endif; ?>
<?php if(!$print && $isPpmpSupervisor && !$canManagePpmp): ?>
<div class="panel" style="margin-bottom:16px"><h2>PPMP Workspace</h2><div class="empty">You have not created your own PPMP yet. Only PPMPs submitted by Areas/Units in your Division/Department that are <b>Pending for Review</b> are available above. You may not add or modify another Area/Unit's PPMP.</div></div>
<?php endif; ?>
<?php if(!$print && (!$isPpmpSupervisor || $canManagePpmp)): ?>
<div class="panel ppmp-toolbar">
  <div class="ppmp-selection-header">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <h2 style="margin-top:0;margin-bottom:0">Project Procurement Management Plan</h2>
      <?php if(hasRole(['Administrator','Editor'])): ?>
        <a class="btn" style="background:#198754;color:#fff;border-color:#198754" href="ppmp_masterlist.php">Create PPMP Masterlist</a>
      <?php endif; ?>
    </div>
    <p class="muted">Select the Calendar Year, Division/Department, Area/Unit, and Area/Unit Head, then click <b>View</b> to display the Saved PPMP Items and Data Entry sections.</p>
    <form class="ppmp-selection-form" method="get" id="ppmpSelectionForm">
      <div class="ppmp-selection-grid">
        <div class="field">
          <label>Calendar Year *</label>
          <select class="select" name="year" id="ppmp_year" required>
            <option value="">Select Calendar Year</option>
            <?php foreach($searchFiscalYears as $searchYear): ?>
              <option value="<?=$searchYear?>" <?=$year===$searchYear?'selected':''?>><?=$searchYear?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Division / Department *</label>
          <select class="select" name="division_id" id="ppmp_division" required>
            <option value="">Select Division / Department</option>
            <?php foreach($divisions as $d): ?>
              <option value="<?=$d['id']?>" data-head="<?=e($d['division_head']??'')?>" data-position="<?=e($d['head_position_designation']??'')?>" <?=$divisionId===(int)$d['id']?'selected':''?>><?=e($d['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Division / Department Head</label>
          <input class="input" type="text" id="ppmp_division_head" value="<?= $isDivisionHeadPpmpOwner ? e($currentUserName) : '' ?>" readonly placeholder="Automatically shown">
        </div>
        <div class="field">
          <label>Area / Unit *</label>
          <select class="select" name="area_id" id="ppmp_area_select" required <?=$divisionId>0?'':'disabled'?>>
            <option value="">Select Area / Unit</option>
            <?php foreach($areas as $a): ?>
              <option value="<?=$a['id']?>" data-division-id="<?=$a['division_id']?>" <?=$areaId===(int)$a['id']?'selected':''?>><?=e($a['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Supervisor / Area / Unit Head *</label>
          <select class="select" name="area_head" id="ppmp_area_head" required <?=$areaId>0?'':'disabled'?>>
            <option value="">Select Supervisor / Head</option>
            <?php if($isDivisionHeadPpmpOwner && $divisionHeadAreaId>0): ?>
              <option value="<?=e($currentUserName)?>" data-area-id="<?=$divisionHeadAreaId?>" data-position="<?=e($divisionHeadRecord['head_position_designation']??'')?>" selected><?=e($currentUserName)?></option>
              <?php else: ?><?php foreach($personnelByArea as $personAreaId=>$people): foreach($people as $person): ?>
              <option value="<?=e($person['name'])?>" data-area-id="<?=$personAreaId?>" data-position="<?=e($person['position_designation']??'')?>" <?=($areaHeadName!=='' && $areaHeadName===$person['name'] && $areaId===(int)$personAreaId)?'selected':''?>><?=e($person['name'])?></option>
            <?php endforeach; endforeach; ?>
              <?php endif; ?>
          </select>
        </div>
        <div class="field">
          <label>Area / Unit Head Position / Designation</label>
          <input class="input" type="text" id="ppmp_area_head_position" value="<?= $isDivisionHeadPpmpOwner ? e($divisionHeadRecord['head_position_designation']??'') : '' ?>" readonly placeholder="Automatically shown">
        </div>
      </div>
      <div class="actions" style="margin-top:16px">
        <button class="btn" type="submit">View</button>
      </div>
    </form>
  </div>
</div>

<?php if($divisionId>0 && $areaId>0): ?>
<div class="panel ppmp-toolbar">
  <div class="toolbar">
    <!-- PPMP toolbar: Add PPMP Item intentionally removed. -->
    <button class="btn secondary ppmp-toolbar-action" type="button" onclick="window.open('ppmp.php?print=1&year=<?=$year?>&area_id=<?=$areaId?>','_blank','noopener')"><span class="ppmp-toolbar-label">Print PPMP Form</span></button>
    <?php if(!$isPpmpSupervisor && $rows && in_array(($rows[0]['review_status']??'Draft'),['Draft','Declined'],true)):?><form method="post" style="display:inline-block;margin:0;"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="submit_for_review"><input type="hidden" name="fiscal_year" value="<?=e($year)?>"><input type="hidden" name="area_id" value="<?=e($areaId)?>"><button class="btn ppmp-toolbar-action ppmp-submit-review" type="submit" onclick="return confirm('Submit the entire PPMP list for Supervisor/Authorized Person review?');"><span class="ppmp-toolbar-label">Submit for Review</span></button></form><?php endif;?>
  </div>
</div>

<div class="panel ppmp-records" id="savedPpmpItems">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:16px">
    <h2 style="margin:0">Saved PPMP Items — FY <?=$year?><?= $selectedArea?' / '.e($selectedArea['name']):'' ?></h2>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:13px">
      <span style="background:#d9f0ff;color:#1f5f85;padding:6px 10px;border-radius:6px"><b>Pending for Review:</b> ₱<?=number_format($ppmpStatusAbc['Pending for Review'],2)?></span>
      <span style="background:#e0e0e0;color:#555;padding:6px 10px;border-radius:6px"><b>Pending for Approval:</b> ₱<?=number_format($ppmpStatusAbc['Pending for Approval'],2)?></span>
      <span style="background:#d9f2df;color:#26733a;padding:6px 10px;border-radius:6px"><b>Approved:</b> ₱<?=number_format($ppmpStatusAbc['Approved'],2)?></span>
      <span style="background:#f8d7da;color:#a1262f;padding:6px 10px;border-radius:6px"><b>Declined:</b> ₱<?=number_format($ppmpStatusAbc['Declined'],2)?></span>
    </div>
  </div>
  <div class="table-wrap"><table class="table"><tr><th>PPMP No.</th><th>Area/Unit</th><th>Item</th><th>Type</th><th>Qty / Unit</th><th>Mode</th><th>Unit Cost</th><th>Total Budget</th><th>Saved</th><th>Status</th><th>Actions</th></tr>
  <?php foreach($rows as $r):?><tr><td><?=e($r['ppmp_no'])?></td><td><?=e($r['area'])?></td><td><b><?=e($r['item_name'])?></b><br><small><?=e($r['description'])?></small></td><td><?=e($r['procurement_type'])?></td><td><?=number_format($r['quantity'],2).' '.e($r['unit'])?></td><td><?=e($r['procurement_mode'])?></td><td>₱<?=number_format($r['unit_price'],2)?></td><td>₱<?=number_format($r['quantity']*$r['unit_price'],2)?></td><td><?=!empty($r['saved_at'])?e(date('F j, Y g:i A',strtotime($r['saved_at']))):e(date('F j, Y g:i A',strtotime($r['created_at'])))?></td><td><span class="ppmp-status-badge ppmp-status-<?=e(strtolower(str_replace(' ','-',(string)$r['review_status'])))?>"><?=e($r['review_status'])?></span><?php if($r['review_status']==='Declined' && !empty($r['review_remarks'])):?><br><small><?=e($r['review_remarks'])?></small><?php endif;?></td><td class="ppmp-actions-cell">
<?php if(hasRole(['Administrator','Editor']) && (!$isPpmpSupervisor || (int)$r['created_by']===$currentUserId) && in_array($r['review_status'],['Draft','Pending for Review','Pending for Approval','Declined'],true)):?>
  <div class="ppmp-row-actions">
    <?php if(in_array($r['review_status'],['Draft','Pending for Review','Pending for Approval'],true)):?>
      <a class="btn secondary ppmp-action-btn" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>&edit=<?=$r['id']?>">Edit</a>
    <?php endif;?>
    <?php if(in_array($r['review_status'],['Draft','Pending for Review','Pending for Approval','Declined'],true)):?>
      <form method="post" class="ppmp-delete-form" onsubmit="return confirm('Delete this PPMP item? This action cannot be undone.');">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?=e($r['id'])?>">
        <input type="hidden" name="fiscal_year" value="<?=e($year)?>">
        <input type="hidden" name="area_id" value="<?=e($areaId)?>">
        <button class="btn danger ppmp-action-btn" type="submit">Delete</button>
      </form>
    <?php endif;?>
  </div>
<?php endif;?>
</td></tr><?php endforeach;?></table></div>
</div>
<?php $ppmpFormOpen=(!$isPpmpSupervisor || $canManagePpmp) && $divisionId>0 && $areaId>0; ?>
<?php if(!$isPpmpSupervisor || $canManagePpmp): ?>
<div class="ppmp-entry panel" id="ppmpForm" style="<?= $ppmpFormOpen ? '' : 'display:none;' ?>">
  <h2>Project Procurement Management Plan — Data Entry</h2>
  <p class="muted">Enter multiple PPMP items as individual rows. The Fiscal Year, Division, Area/Unit, and Supervisor information are taken from the selection workflow above.</p>
  <form method="post" enctype="multipart/form-data" id="ppmpBulkForm">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="action" value="<?=$editing?'edit':'add_bulk'?>">
    <input type="hidden" name="id" value="<?=$editing?(int)$editing['id']:0?>">
    <input type="hidden" name="division_id" value="<?=e($divisionId)?>">
    <?php if($editing): ?><input type="hidden" name="existing_supporting_documents" value="<?=e($editing['supporting_documents']??'')?>"><?php endif; ?>
    <input type="hidden" name="fiscal_year" value="<?=e($year)?>">
    <input type="hidden" name="area_id" value="<?=e($areaId)?>">
    <input type="hidden" name="ppmp_no" value="<?=e($nextPpmpNo)?>">
    <input type="hidden" name="prepared_by" id="ppmp_person" value="<?=e($formState['prepared_by']??($selectedArea['authorized_person']??''))?>">
    <div class="ppmp-section">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <h3 style="margin:0"><?= $editing ? 'Edit PPMP Item' : 'PPMP Items' ?></h3>
        <?php if(!$editing): ?><button class="btn secondary" type="button" id="ppmpAddRow">+ Add Row</button><?php endif; ?>
      </div>
            <div class="table-wrap ppmp-entry-table-wrap">
        <table class="table ppmp-entry-table" id="ppmpEntryTable">
          <thead><tr><th>#</th><th>Category *</th><th>Classification *</th><th>Item Name *</th><th>Technical Specifications *</th><th>Quantity *</th><th>Unit *</th><th>Unit Cost *</th><th>Total Budget</th><th>Procurement Mode *</th><th>Pre-Procurement *</th><th>Start *</th><th>End *</th><th>Delivery *</th><th>Source of Funds *</th><th>Supporting Documents</th><th>Remarks</th><th>Action</th></tr></thead>
          <tbody id="ppmpEntryBody">
            <tr class="ppmp-entry-row">
              <td class="ppmp-row-number">1</td>
              <td><select class="select" name="<?=$editing?'category_id':'items[0][category_id]'?>" required><option value="">Select</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>" <?=((int)($formState['category_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></td>
              <td><select class="select" name="<?=$editing?'procurement_type':'items[0][procurement_type]'?>" required><option value="">Select</option><?php foreach($classifications as $c):?><option value="<?=e($c['name'])?>" <?=((string)($formState['procurement_type']??'')===(string)$c['name'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></td>
              <td><div class="ppmp-item-autocomplete"><input class="input ppmp-item-name" name="<?=$editing?'item_name':'items[0][item_name]'?>" required autocomplete="off" value="<?=e($formState['item_name']??'')?>"><div class="ppmp-item-suggestions" role="listbox"></div></div></td>
              <td><textarea class="input ppmp-item-description ppmp-masterlist-locked" name="<?=$editing?'description':'items[0][description]'?>" rows="2" readonly required><?=e($formState['description']??'')?></textarea></td>
              <td><input class="input ppmp-row-qty" name="<?=$editing?'quantity':'items[0][quantity]'?>" inputmode="decimal" required value="<?=isset($formState['quantity'])?e(number_format((float)$formState['quantity'],2,'.','')):''?>"></td>
              <td><select class="select" name="<?=$editing?'unit':'items[0][unit]'?>" required><option value="">Select</option><?php foreach($units as $u):?><option value="<?=e($u['name'])?>" <?=((string)($formState['unit']??'')===(string)$u['name'])?'selected':''?>><?=e($u['name'])?></option><?php endforeach;?></select></td>
              <td><input class="input ppmp-row-unit-price ppmp-masterlist-locked" name="<?=$editing?'unit_price':'items[0][unit_price]'?>" inputmode="decimal" readonly required value="<?=isset($formState['unit_price'])?e(number_format((float)$formState['unit_price'],2,'.',',')):''?>"></td>
              <td><input class="input ppmp-row-total ppmp-total-budget" readonly value="<?=isset($formState['total_budget'])?e(number_format((float)$formState['total_budget'],2,'.',',')):''?>"></td>
              <td><select class="select" name="<?=$editing?'procurement_mode':'items[0][procurement_mode]'?>" required><option value="">Select</option><?php foreach($procurementMethods as $method):?><option value="<?=e($method['procurement_method'])?>" <?=((string)($formState['procurement_mode']??'')===(string)$method['procurement_method'])?'selected':''?>><?=e($method['procurement_method'])?></option><?php endforeach;?></select></td>
              <td><select class="select" name="<?=$editing?'preprocurement_conference':'items[0][preprocurement_conference]'?>" required><option value="">Select</option><option <?=((string)($formState['preprocurement_conference']??'')==='Yes')?'selected':''?>>Yes</option><option <?=((string)($formState['preprocurement_conference']??'')==='No')?'selected':''?>>No</option><option <?=in_array((string)($formState['preprocurement_conference']??''),['N/A','NA','Not Applicable'],true)?'selected':''?>>N/A</option></select></td>
              <td><input class="input ppmp-row-date" type="date" name="<?=$editing?'start_procurement':'items[0][start_procurement]'?>" required value="<?=e($formState['start_procurement']??'')?>"></td>
              <td><input class="input ppmp-row-date" type="date" name="<?=$editing?'end_procurement':'items[0][end_procurement]'?>" required value="<?=e($formState['end_procurement']??'')?>"></td>
              <td><input class="input ppmp-row-date" type="date" name="<?=$editing?'delivery_period':'items[0][delivery_period]'?>" required value="<?=e($formState['delivery_period']??'')?>"></td>
              <td><input class="input" name="<?=$editing?'source_of_funds':'items[0][source_of_funds]'?>" required value="<?=e($formState['source_of_funds']??'')?>"></td>
              <td>
                <div class="ppmp-row-documents">
                  <div class="ppmp-document-row">
                    <input class="input" type="file" name="items[0][supporting_documents][]" accept="application/pdf,.pdf">
                    <input class="input" type="text" name="items[0][supporting_document_names][]" placeholder="Document name">
                    <button class="btn secondary ppmp-remove-document" type="button">Remove</button>
                  </div>
                  <button class="btn secondary ppmp-add-document" type="button">+ Add PDF</button>
                </div>
              </td>
              <td><textarea class="input" name="<?=$editing?'remarks':'items[0][remarks]'?>" rows="2"><?=e($formState['remarks']??'')?></textarea></td>
              <td><?php if($editing): ?><a class="btn secondary" href="ppmp.php?year=<?=$year?>&division_id=<?=$divisionId?>&area_id=<?=$areaId?>#savedPpmpItems">Cancel</a><?php else: ?><button class="btn danger ppmp-remove-row" type="button">Remove</button><?php endif; ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="ppmp-table-total" style="text-align:right;margin-top:12px;font-weight:700">Total PPMP Budget: ₱<span id="ppmpGrandTotal">0.00</span></div>
    </div>
    <div class="actions"><button class="btn" type="submit"><?=$editing?'Update PPMP Item':'Save PPMP Items'?></button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php
$h=($allPpmpRows[0]??$rows[0]??[]);
$ppmpNo=$h['ppmp_no']??'';
$person=$h['requested_by']??'';
$preparedPos=$h['prepared_position']??'End-User or Implementing Unit';
$preparedSignature='';
if($person!=='' && !empty($h['area_id'])){
  $stPreparedSignature=$pdo->prepare('SELECT electronic_signature FROM area_personnel WHERE area_id=? AND name=? LIMIT 1');
  $stPreparedSignature->execute([(int)$h['area_id'],$person]);
  $preparedSignature=trim((string)($stPreparedSignature->fetchColumn()??''));
}
// Resolve the Submitted By signatory directly from the Division/Department
// attached to the PPMP. This is authoritative for the Division Head and does
// not depend on whether the selected Area/Unit row carried the signature value.
$submitted='';
$submittedPos='';
$submittedSignature='';
if(!empty($h['area_id'])){
  $stSubmittedDivision=$pdo->prepare('SELECT d.division_head,d.head_position_designation,d.electronic_signature,d.ppmp_supervisor_enabled
    FROM areas a JOIN divisions d ON d.id=a.division_id WHERE a.id=? LIMIT 1');
  $stSubmittedDivision->execute([(int)$h['area_id']]);
  $submittedDivision=$stSubmittedDivision->fetch();
  if($submittedDivision && (int)$submittedDivision['ppmp_supervisor_enabled']===1){
    $submitted=trim((string)($submittedDivision['division_head']??''));
    $submittedPos=trim((string)($submittedDivision['head_position_designation']??''));
    $submittedSignature=trim((string)($submittedDivision['electronic_signature']??''));
  }
}
// If the PPMP was created by the Division/Department Head, also use the
// Division Head signature for Prepared By when no Area/Unit personnel
// signature is configured for that person.
if($preparedSignature==='' && $submitted!=='' && strcasecmp(trim((string)$person),$submitted)===0){
  $preparedSignature=$submittedSignature;
}
// Budget signatory comes from the Area/Unit master list.
// IMPORTANT: do not inspect the selected PPMP Area/Unit personnel and do not
// look for the word "Budget" in a person's name/position. Instead, locate the
// Area/Unit record whose own Area/Unit name identifies it as Budget, then pull
// the person(s) attached to that Area/Unit.
$budgetName='';
$budgetPos='';
$budgetAreaId=0;

// Match Budget, Budget Unit, Budget Section, Budget Office, etc.
// The Area/Unit name is the source of truth for the Budget signatory.
$stBudgetArea=$pdo->query("SELECT id,name FROM areas
  WHERE LOWER(name) REGEXP '(^|[[:space:]-])(budget)([[:space:]-]|$)'
     OR LOWER(name) LIKE 'budget %'
     OR LOWER(name) LIKE '% budget'
  ORDER BY
    CASE
      WHEN LOWER(name)='budget' THEN 0
      WHEN LOWER(name) LIKE 'budget %' THEN 1
      WHEN LOWER(name) LIKE '%budget%' THEN 2
      ELSE 3
    END, name
  LIMIT 1")->fetch();

if($stBudgetArea){
  $budgetAreaId=(int)$stBudgetArea['id'];
  $stBudgetPeople=$pdo->prepare('SELECT name,position_designation
    FROM area_personnel
    WHERE area_id=?
    ORDER BY id');
  $stBudgetPeople->execute([$budgetAreaId]);
  $budgetPeople=$stBudgetPeople->fetchAll();

  // Use the first configured person under the Budget Area/Unit.
  // Their stored Position/Designation is printed as-is.
  if($budgetPeople){
    $budgetName=trim((string)($budgetPeople[0]['name']??''));
    $budgetPos=trim((string)($budgetPeople[0]['position_designation']??''));
  }
}
function ppmpPrintDate($value): string{
  $value=trim((string)$value);
  if($value==='') return '';
  $timestamp=strtotime($value);
  return $timestamp ? date('F d, Y',$timestamp) : $value;
}
?>
<?php if($print): ?>
<div class="ppmp-print-sheet paper-long" id="ppmpPrintSheet">
  <div class="ppmp-head">
    <div class="ppmp-brand">
      <div class="ppmp-brand-mark">ZCMC</div>
      <div><div>Republic of the Philippines</div><div>Department of Health</div><strong>ZAMBOANGA CITY MEDICAL CENTER</strong><small>Dr. D. Evangelista St., Sta. Catalina, Zamboanga City 7000</small></div>
    </div>
    <div class="ppmp-control"><div>Form No.: ZCMC-F-PROC-01</div><div>Revision No.: 1</div><div>Effectivity Date: February 11, 2026</div></div>
  </div>

  <div class="ppmp-title">PROJECT PROCUREMENT MANAGEMENT PLAN (PPMP) NO. <span class="line"><?=e($ppmpNo)?></span></div>
  <div class="ppmp-classification"><span>☐ INDICATIVE</span><span>☐ FINAL</span></div>
  <div class="ppmp-meta">
    <div><b>Fiscal Year :</b> <?=e($year)?></div>
    <div><b>End-User or Implementing Unit:</b> <?=e($selectedArea['name'])?></div>
  </div>

  <?php
  $ppmpExplanations=[
    'Refers to the type of procurement—whether for Goods (e.g., supplies, materials, ICT equipment, medicines); Infrastructure Projects (e.g., roads, buildings, site development or land improvement, public utilities such as water systems or flood control); Consulting Services (e.g., feasibility studies, advisory and management consulting, training and capacity building); or General Support Services (e.g., security, janitorial, transportation and logistics, training and event management). It also describes the objective of the project.',
    'Refers to quantity / size of the contract (whether by lot, item or package). If items are too many to use this column, a separate attachment may be included)\n\nItem refers to the smallest unit or individual good/service being procured.\nLot refers to a group or related items bundled together in one bidding.\nPackage is a collection of one or more lots grouped under a single procurement project.\n\nIt is sufficient to indicate that this information is reflected in the Technical Specifications, Scope of Work (SOW), or Terms of Reference (TOR) (as applicable) when the latter is attached to this PPMP.',
    'Indicate applicable procurement mode under RA No. 12009 recommended by the End-User.',
    'Indicate the projected month (MM/YYYY) of the Pre-procurement Conference.',
    'Indicate the projected month (MM/YYYY) of the start of procurement activity which will depend on the applicable mode of procurement used by the Procuring Entity.',
    'Indicate the projected month (MM/YYYY) of issuance of Notice of Award or Purchase Order, as the case may be, based on the prescribed procurement timelines.',
    'This refers to the target start date (MM/YYYY) when the delivery of goods, implementation of infrastructure projects, or provision of consulting services is expected to begin, indicating when the project is needed. The End-User may indicate "as needed" if the project is on a per-need basis.',
    'The fund source for the payment of the project to be procured may include, but shall not be limited to GAA, Corporate Operating Budget, Internally Generated Funds, General or other sources of funds for LGUs, Special Purpose Funds or Trust Funds, and Foreign Assisted Projects (FAPs).',
    '',
    'Use Estimated Budget when the GAA or other appropriate fund source has not yet been passed or approved and Authorized Budgetary Allocation when the GAA or other appropriate fund source has been passed or approved. These amounts are determined after market scoping.',
    '',
    'This column refers to additional details regarding the project, such as basis of changes from previous PPMP, contract package details, procurement strategies, and recommended award criterion.'
  ];
  $printRows=$rows;
  if(count($printRows)<2) $printRows=array_pad($printRows,2,null);  $grandTotal=0;
  foreach($rows as $gr){$grandTotal+=(float)$gr['quantity']*(float)$gr['unit_price'];}
  ?>

  <table class="ppmp-official-table ppmp-excel-template">
    <colgroup>
      <col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"><col class="c6"><col class="c7"><col class="c8"><col class="c9"><col class="c10"><col class="c11"><col class="c12"><col class="c13"><col class="c14">
    </colgroup>
    <thead>
      <tr class="group-head">
        <th colspan="6">PROCUREMENT PROJECT DETAILS</th>
        <th colspan="3">PROJECTED TIMELINE (MM/YYYY)</th>
        <th colspan="3">FUNDING DETAILS</th>
        <th rowspan="3">ATTACHED SUPPORTING DOCUMENTS</th>
        <th rowspan="3">REMARKS</th>
      </tr>
      <tr class="field-head">
        <th rowspan="2">General Description and Objective<br>of the Project to be Procured</th>
        <th rowspan="2">Type of the Project to be Procured<br>(whether Goods, Infrastructure and Consulting Services)</th>
        <th colspan="2">Quantity and Size of the Project to be Procured</th>
        <th rowspan="2">Recommended Mode of Procurement</th>
        <th rowspan="2">Pre-Procurement Conference, if applicable (Yes/No)</th>
        <th rowspan="2">Start of Procurement Activity</th>
        <th rowspan="2">End of Procurement Activity</th>
        <th rowspan="2">Expected Delivery / Implementation Period</th>
        <th rowspan="2">Source of Funds</th>
        <th rowspan="2">Unit Cost</th>
        <th rowspan="2">Estimated Budget / Authorized Budgetary Allocation (PhP)</th>
      </tr>
      <tr class="quantity-head">
        <th>Quantity</th>
        <th>Unit or Measurement/Size</th>
      </tr>
      <tr class="column-head">
        <th>Column 1</th><th>Column 2</th><th colspan="2">Column 3</th><th>Column 4</th><th>Column 5</th><th>Column 6</th><th>Column 7</th><th>Column 8</th><th>Column 9</th><th>Column 9A</th><th>Column 10</th><th>Column 11</th><th>Column 12</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr class="data-row template-data-row">
        <td><?=e(trim((string)$r['item_name']).', '.trim((string)$r['description']))?></td>
        <td><?=e($r['procurement_type'])?></td>
        <td><?=e(rtrim(rtrim(number_format((float)$r['quantity'],4,'.',''), '0'),'.'))?></td>
        <td><?=e($r['unit'])?></td>
        <td><?=e($r['procurement_mode'])?></td>
        <td><?=e($r['preprocurement_conference'])?></td>
        <td><?=e(ppmpPrintDate($r['start_procurement']))?></td>
        <td><?=e(ppmpPrintDate($r['end_procurement']))?></td>
        <td><?=e(ppmpPrintDate($r['delivery_period']))?></td>
        <td><?=e($r['source_of_funds'])?></td>
        <td>₱<?=number_format((float)$r['unit_price'],2)?></td>
        <td>₱<?=number_format((float)$r['quantity']*(float)$r['unit_price'],2)?></td>
        <td><?php
          $printDocs=[];
          if(!empty($r['supporting_documents'])){
            $decodedDocs=json_decode((string)$r['supporting_documents'],true);
            if(is_array($decodedDocs)){
              foreach($decodedDocs as $doc){
                if(is_array($doc)){
                  $docName=trim((string)($doc['name']??''));
                  if($docName!=='') $printDocs[]=$docName;
                }
              }
            }
          }
          echo $printDocs ? implode('<br>',array_map('e',$printDocs)) : '';
        ?></td>
        <td><?=nl2br(e($r['remarks']))?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="grand-total-row">
        <td colspan="10"></td>
        <td><b>GRAND TOTAL</b></td>
        <td><b>₱<?=number_format($grandTotal,2)?></b></td>
        <td></td><td></td>
      </tr>
    </tbody>
  </table>

  <div class="ppmp-note"><b>Important Note:</b> The Market Scoping Form and its proof of documentation and activities shall be attached to this PPMP prior to approval. Failure to provide both the Market Scoping Form and proof of documentation shall result to deferment or rejection of this PPMP.</div>

  
<style>
/* PPMP signature blocks: Signature, Name, Line, Caption, Position, Unit/Section, Date. */
.ppmp-signature-template{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:18px;
  align-items:start;
  width:100%;
}
.ppmp-signature-template .ppmp-signature-box{
  min-width:0;
  text-align:center;
  display:grid;
  grid-template-rows:22px 50px 20px 8px 22px 22px 22px;
  align-items:start;
}
.ppmp-signature-template .ppmp-signature-box > b{
  display:block;
  height:22px;
  line-height:22px;
}
.ppmp-signature-template .signature-line{
  height:50px;
  min-height:50px;
  position:relative;
  display:block;
  text-align:center;
  margin:0;
  border:0 !important;
  border-bottom:0 !important;
}
.ppmp-signature-template .signature-line img{
  position:absolute;
  top:0;
  left:50%;
  transform:translateX(-50%);
  width:110px !important;
  height:50px !important;
  min-width:110px !important;
  max-width:110px !important;
  min-height:50px !important;
  max-height:50px !important;
  object-fit:contain;
  object-position:center;
  display:block;
  margin:0;
}
.ppmp-signature-template .signature-name{
  height:20px;
  min-height:20px;
  line-height:20px;
  text-align:center;
  font-weight:600;
  overflow:hidden;
  white-space:nowrap;
  text-overflow:ellipsis;
}
.ppmp-signature-template .signature-underline{
  height:8px;
  line-height:0;
  border:0;
  border-top:1px solid #222;
  margin:0 18px;
}
.ppmp-signature-template .signature-caption,
.ppmp-signature-template .signature-meta{
  height:22px;
  line-height:22px;
  margin:0;
  text-align:center;
  overflow:hidden;
  white-space:nowrap;
  text-overflow:ellipsis;
}
@media print{
  .ppmp-signature-template{
    grid-template-columns:repeat(3,1fr);
    gap:12px;
  }
  .ppmp-signature-template .ppmp-signature-box{
    grid-template-rows:22px 50px 20px 8px 22px 22px 22px;
  }
}
</style>
<div class="ppmp-signatures ppmp-signature-template">
    <div class="ppmp-signature-box">
      <b>Prepared by:</b>
      <div class="signature-line ppmp-prepared-signature"><?php if($preparedSignature!==''): ?><img src="<?=e($preparedSignature)?>" alt="Prepared By electronic signature"><?php endif; ?></div>
      <div class="signature-name"><?=e($person)?></div>
      <div class="signature-underline"></div>
      <div class="signature-caption">Signature over Printed Name</div>
      <div class="signature-meta"><?=e($preparedPos)?></div>
      <div class="signature-meta"><i>End-User or Implementing Unit</i></div>
      <div class="signature-meta">Date : <?=e(ppmpPrintDate($h['updated_at']??$h['created_at']??date('Y-m-d'))) ?></div>
    </div>
    <div class="ppmp-signature-box">
      <b>Submitted by:</b>
      <div class="signature-line ppmp-submitted-signature"><?php if($submittedSignature!==''): ?><img src="<?=e($submittedSignature)?>" alt="Submitted By electronic signature"><?php endif; ?></div>
      <div class="signature-name"><?=e($submitted)?></div>
      <div class="signature-underline"></div>
      <div class="signature-caption">Signature over Printed Name</div>
      <div class="signature-meta"><?=e($submittedPos)?></div>
      <div class="signature-meta"><i>Division/Department/Section Unit</i></div>
      <div class="signature-meta">Date : ______________________________</div>
    </div>
    <div class="ppmp-signature-box ppmp-budget-signature">
      <b>within the budget allocation:</b>
      <div class="signature-line"></div>
      <div class="signature-name"><?=e($budgetName)?></div>
      <div class="signature-underline"></div>
      <div class="signature-caption">Signature over Printed Name</div>
      <div class="signature-meta"><?=e($budgetPos)?></div>
      <div class="signature-meta"><i>Budget Section</i></div>
      <div class="signature-meta">Date : ______________________________</div>
    </div>
  </div>
</div>
</div>
<div class="ppmp-print-actions">
  <button class="btn" onclick="printPpmp('long')">Print 8.5 × 13</button>
  <button class="btn secondary" onclick="printPpmp('a4')">Print A4</button>
  <a class="btn secondary" href="ppmp.php?year=<?=$year?>&area_id=<?=$areaId?>">Back</a>
</div>
<?php endif; ?>
<script>
(function(){
  const masterlist=<?= json_encode($ppmpMasterlistRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  function closeSuggestions(box){const list=box&&box.querySelector('.ppmp-item-suggestions');if(list){list.innerHTML='';list.style.display='none';}}
  function showSuggestions(input){
    const box=input.closest('.ppmp-item-autocomplete'), list=box&&box.querySelector('.ppmp-item-suggestions');
    if(!box||!list)return;
    const term=String(input.value||'').trim().toLowerCase();
    list.innerHTML='';
    if(!term){closeSuggestions(box);return;}
    const matches=masterlist.filter(function(item){return String(item.item_name||'').toLowerCase().includes(term);}).slice(0,20);
    if(!matches.length){closeSuggestions(box);return;}
    matches.forEach(function(item){
      const button=document.createElement('button');
      button.type='button';button.className='ppmp-item-suggestion';button.setAttribute('role','option');
      button.dataset.itemId=item.id;
      button.dataset.itemName=item.item_name||'';
      button.dataset.specifications=item.technical_specifications||'';
      button.dataset.unitCost=item.unit_cost??'';
      button.innerHTML='<strong>'+escapeHtml(item.item_name||'')+'</strong>'+(item.technical_specifications?'<small>'+escapeHtml(item.technical_specifications)+'</small>':'');
      list.appendChild(button);
    });
    list.style.display='block';
  }
  function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
  function bindItemAutocomplete(row){
    if(!row)return;
    const input=row.querySelector('.ppmp-item-name'), box=input&&input.closest('.ppmp-item-autocomplete');
    if(!input||!box||input.dataset.autocompleteBound==='1')return;
    input.dataset.autocompleteBound='1';
    input.addEventListener('input',function(){showSuggestions(this);});
    input.addEventListener('focus',function(){if(this.value.trim())showSuggestions(this);});
    input.addEventListener('keydown',function(e){
      const list=box.querySelector('.ppmp-item-suggestions');
      if(e.key==='Escape'){closeSuggestions(box);return;}
      if(e.key==='ArrowDown'&&list&&list.style.display==='block'){
        e.preventDefault();const first=list.querySelector('.ppmp-item-suggestion');if(first)first.focus();
      }
    });
    box.addEventListener('click',function(e){
      const button=e.target.closest('.ppmp-item-suggestion');if(!button)return;
      input.value=button.dataset.itemName||'';
      const description=row.querySelector('.ppmp-item-description');
      if(description)description.value=button.dataset.specifications||'';
      const unitPrice=row.querySelector('.ppmp-row-unit-price');
      if(unitPrice){
        const rawCost=String(button.dataset.unitCost??'').replace(/,/g,'').trim();
        const cost=parseFloat(rawCost);
        if(Number.isFinite(cost)){
          unitPrice.value=cost.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
          unitPrice.dispatchEvent(new Event('input',{bubbles:true}));
          unitPrice.dispatchEvent(new Event('blur',{bubbles:true}));
        }
      }
      closeSuggestions(box);input.focus();
    });
  }
  document.querySelectorAll('#ppmpEntryBody .ppmp-entry-row').forEach(bindItemAutocomplete);
  document.addEventListener('click',function(e){
    document.querySelectorAll('.ppmp-item-autocomplete').forEach(function(box){if(!box.contains(e.target))closeSuggestions(box);});
  });
  window.ppmpBindItemAutocomplete=bindItemAutocomplete;
})();
</script>
<script>
function printPpmp(paper){
  const sheet=document.getElementById('ppmpPrintSheet');
  if(sheet){sheet.classList.remove('paper-a4','paper-long');sheet.classList.add(paper==='a4'?'paper-a4':'paper-long');}
  window.print();
}
(function(){
 const fiscalYear=document.getElementById('ppmp_fiscal_year'), ppmpNo=document.getElementById('ppmp_no');
 if(fiscalYear&&ppmpNo&&ppmpNo.dataset.locked!=='1'){
   function syncPpmpNumber(){
      const fy=fiscalYear.value, ao=document.getElementById('ppmp_area'), areaOpt=ao&&ao.options[ao.selectedIndex];
      const mapped=areaOpt&&fy ? areaOpt.getAttribute('data-ppmp-'+fy) : '';
      const o=fiscalYear.options[fiscalYear.selectedIndex];
      ppmpNo.value=mapped || (o?(o.getAttribute('data-ppmp-no')||''):'');
    }
   fiscalYear.addEventListener('change',syncPpmpNumber);
   syncPpmpNumber();
 }
 const selectionDivision=document.getElementById('ppmp_division');
 const selectionArea=document.getElementById('ppmp_area_select');
 const selectionDivisionHead=document.getElementById('ppmp_division_head');
 const selectionAreaHead=document.getElementById('ppmp_area_head');
 const selectionAreaHeadPosition=document.getElementById('ppmp_area_head_position');
 function syncSelectionFields(){
   if(!selectionDivision)return;
   const divOpt=selectionDivision.options[selectionDivision.selectedIndex];
   if(selectionDivisionHead)selectionDivisionHead.value=divOpt?((divOpt.dataset.head||'')):''; 
   const divId=selectionDivision.value||'';
   if(selectionArea){
     Array.from(selectionArea.options).forEach(function(o,i){
       if(i===0){o.hidden=false;o.disabled=false;return;}
       const show=o.dataset.divisionId===divId;
       o.hidden=!show;o.disabled=!show;
     });
     if(!Array.from(selectionArea.options).some(function(o){return !o.disabled&&o.value===selectionArea.value;}))selectionArea.value='';
     selectionArea.disabled=!divId;
   }
   if(selectionAreaHead){
     const areaId=selectionArea?selectionArea.value:'';
     Array.from(selectionAreaHead.options).forEach(function(o,i){
       if(i===0){o.hidden=false;o.disabled=false;return;}
       const show=o.dataset.areaId===areaId;
       o.hidden=!show;o.disabled=!show;
     });
     if(!Array.from(selectionAreaHead.options).some(function(o){return !o.disabled&&o.value===selectionAreaHead.value;}))selectionAreaHead.value='';
     selectionAreaHead.disabled=!areaId;
     syncSelectionHeadPosition();
   }
 }
 function syncSelectionHeadPosition(){
   if(!selectionAreaHeadPosition||!selectionAreaHead)return;
   const o=selectionAreaHead.options[selectionAreaHead.selectedIndex];
   selectionAreaHeadPosition.value=(o&&!o.disabled)?(o.dataset.position||''):'';
 }
 if(selectionDivision){selectionDivision.addEventListener('change',function(){if(selectionArea)selectionArea.value='';if(selectionAreaHead)selectionAreaHead.value='';syncSelectionFields();});}
 <?php if($isDivisionHeadPpmpOwner): ?>
 if(selectionDivision){selectionDivision.value='<?= (int)$divisionId ?>';selectionDivision.disabled=true;selectionDivision.insertAdjacentHTML('afterend','<input type="hidden" name="division_id" value="<?= (int)$divisionId ?>">');}
 if(selectionArea){selectionArea.value='<?= (int)$areaId ?>';selectionArea.disabled=true;selectionArea.insertAdjacentHTML('afterend','<input type="hidden" name="area_id" value="<?= (int)$areaId ?>">');}
 if(selectionAreaHead){selectionAreaHead.value='<?=e($currentUserName)?>';selectionAreaHead.disabled=true;selectionAreaHead.insertAdjacentHTML('afterend','<input type="hidden" name="area_head" value="<?=e($currentUserName)?>">');}
 <?php endif; ?>
 if(selectionArea){selectionArea.addEventListener('change',function(){if(selectionAreaHead)selectionAreaHead.value='';syncSelectionFields();});}
 if(selectionAreaHead)selectionAreaHead.addEventListener('change',syncSelectionHeadPosition);
 syncSelectionFields();
 syncSelectionHeadPosition();

 const area=document.getElementById('ppmp_area'), person=document.getElementById('ppmp_person');
function syncSupervisor(){const o=area.options[area.selectedIndex]; if(person) person.value=o?(o.dataset.person||''):'';}
if(area){ area.addEventListener('change',syncSupervisor); syncSupervisor(); }
 const quantity=document.getElementById('ppmp_quantity'), unitPrice=document.getElementById('ppmp_unit_price'), totalBudget=document.getElementById('ppmp_total_budget');
 function syncRowDocuments(row,index){
   if(!row)return;
   row.querySelectorAll('.ppmp-document-row').forEach(function(docRow,docIndex){
     const remove=docRow.querySelector('.ppmp-remove-document');
     if(remove)remove.style.display=docIndex===0?'none':'inline-flex';
     const file=docRow.querySelector('input[type="file"]');
     const name=docRow.querySelector('input[type="text"]');
     if(file)file.name='items['+index+'][supporting_documents][]';
     if(name)name.name='items['+index+'][supporting_document_names][]';
   });
 }
 function syncAllRowDocuments(){
   document.querySelectorAll('#ppmpEntryBody .ppmp-entry-row').forEach(function(row,index){syncRowDocuments(row,index);});
 }
 document.addEventListener('click',function(e){
   if(e.target.classList.contains('ppmp-add-document')){
     const wrap=e.target.closest('.ppmp-row-documents');
     if(!wrap)return;
     const first=wrap.querySelector('.ppmp-document-row');
     if(!first)return;
     const row=first.cloneNode(true);
     row.querySelectorAll('input').forEach(function(input){input.value='';});
     wrap.insertBefore(row,e.target);
     syncAllRowDocuments();
   }
   if(e.target.classList.contains('ppmp-remove-document')){
     const row=e.target.closest('.ppmp-document-row');
     const wrap=e.target.closest('.ppmp-row-documents');
     if(row&&wrap){
       const rows=wrap.querySelectorAll('.ppmp-document-row');
       if(rows.length>1)row.remove();
       else row.querySelectorAll('input').forEach(function(input){input.value='';});
       syncAllRowDocuments();
     }
   }
 });
 function moneyNumber(value){return parseFloat(String(value||'').replace(/,/g,''))||0;}
 function formatMoney(value){return Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function syncTotalBudget(){if(!quantity||!unitPrice||!totalBudget)return; const q=moneyNumber(quantity.value), p=moneyNumber(unitPrice.value); totalBudget.value=formatMoney(q*p);}
 function liveFormatMoney(field){
   if(!field)return;
   const raw=String(field.value||'').replace(/[^0-9.]/g,'');
   if(raw===''){field.value='';syncTotalBudget();return;}
   const parts=raw.split('.');
   let integer=(parts[0]||'0').replace(/^0+(?=\d)/,'');
   integer=integer.replace(/\B(?=(\d{3})+(?!\d))/g,',');
   if(parts.length>1){
     field.value=integer+'.'+(parts.slice(1).join('').slice(0,2));
   }else{
     field.value=integer;
   }
   syncTotalBudget();
 }
 function finalizeMoney(field){
   if(!field||field.value==='')return;
   field.value=formatMoney(moneyNumber(field.value));
   syncTotalBudget();
 }
 [quantity,unitPrice].forEach(function(field){
   if(!field)return;
   field.addEventListener('input',function(){liveFormatMoney(this);});
   field.addEventListener('blur',function(){finalizeMoney(this);});
   field.addEventListener('focus',function(){
     if(this.value==='0.00')this.select();
   });
 });
 if(quantity&&unitPrice){
   // Initialize Edit PPMP Item exactly like Add PPMP Item: format existing
   // Quantity and Unit Cost immediately and recalculate Total Budget.
   if(quantity.value!=='') liveFormatMoney(quantity);
   if(unitPrice.value!=='') liveFormatMoney(unitPrice);
   syncTotalBudget();
 }

})();
</script>

<style>
.ppmp-selection-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:end}
.ppmp-selection-grid .field{min-width:0}
.ppmp-selection-form .actions{justify-content:flex-start}
@media(max-width:899px){.ppmp-selection-grid{grid-template-columns:1fr}}
.ppmp-date-picker{position:relative}
.ppmp-date-picker .ppmp-date-native{position:absolute;inset:0;width:100%;height:100%;opacity:0;pointer-events:none}
.ppmp-long-date{font-variant-numeric:tabular-nums;cursor:pointer}
.ppmp-entry .ppmp-quantity-field,
.ppmp-entry .ppmp-unit-field,
.ppmp-entry .ppmp-unit-cost-field,
.ppmp-entry .ppmp-total-field{
  min-width:0
}
.ppmp-entry .ppmp-project-fields{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1.4fr);
  gap:16px;
  align-items:end
}
.ppmp-entry .ppmp-project-fields .field{min-width:0}
.ppmp-entry .ppmp-quantity-field{grid-column:span 3}
.ppmp-entry .ppmp-unit-field{grid-column:span 6}
.ppmp-entry .ppmp-unit-cost-field{grid-column:span 4}
.ppmp-entry .ppmp-total-field{grid-column:span 7}
.ppmp-entry .ppmp-quantity-field input{  text-align:left!important
}
.ppmp-entry .ppmp-total-budget{
  font-size:inherit!important;
  font-weight:inherit!important;
}
@media (min-width: 900px){
  .ppmp-entry .ppmp-input-grid:has(.ppmp-quantity-field){
    grid-template-columns:repeat(20,minmax(0,1fr))
  }
  .ppmp-entry .ppmp-quantity-field{grid-column:span 3}
  .ppmp-entry .ppmp-unit-field{grid-column:span 6}
  .ppmp-entry .ppmp-unit-cost-field{grid-column:span 4}
  .ppmp-entry .ppmp-total-field{grid-column:span 7}
}
@media (max-width: 899px){
  .ppmp-entry .ppmp-project-fields{grid-template-columns:1fr}
  .ppmp-entry .ppmp-quantity-field,
  .ppmp-entry .ppmp-unit-field,
  .ppmp-entry .ppmp-unit-cost-field,
  .ppmp-entry .ppmp-total-field{grid-column:1/-1}
}
</style>
<script>
(function(){
  function displayDate(iso){
    if(!iso)return '';
    const d=new Date(iso+'T00:00:00');
    return d.toLocaleDateString('en-US',{month:'long',day:'2-digit',year:'numeric'});
  }
  function isoDate(display){
    const m=String(display||'').trim().match(/^(January|February|March|April|May|June|July|August|September|October|November|December)\\s+(\\d{1,2}),\\s+(\\d{4})$/i);
    if(!m)return '';
    const months={january:1,february:2,march:3,april:4,may:5,june:6,july:7,august:8,september:9,october:10,november:11,december:12};
    return m[3]+'-'+String(months[m[1].toLowerCase()]).padStart(2,'0')+'-'+String(m[2]).padStart(2,'0');
  }
  document.querySelectorAll('.ppmp-date-picker').forEach(function(box){
    const display=box.querySelector('.ppmp-long-date'), picker=box.querySelector('.ppmp-date-native'), hidden=box.querySelector('input[type="hidden"]');
    if(!display||!picker||!hidden)return;
    const existing=isoDate(display.value);
    if(existing){picker.value=existing;hidden.value=existing;}
    function openPicker(){
      try{
        if(typeof picker.showPicker==='function') picker.showPicker();
        else {picker.style.pointerEvents='auto';picker.click();picker.style.pointerEvents='none';}
      }catch(e){picker.style.pointerEvents='auto';picker.click();picker.style.pointerEvents='none';}
    }
    display.addEventListener('click',openPicker);
    display.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();openPicker();}});
    picker.addEventListener('change',function(){hidden.value=this.value;display.value=displayDate(this.value);});
    display.addEventListener('input',function(){
      const iso=isoDate(this.value);
      hidden.value=iso;
      if(iso)picker.value=iso;
    });
    display.addEventListener('blur',function(){
      const iso=isoDate(this.value);
      if(iso)this.value=displayDate(iso);
    });
  });
})();
</script><script>
(function(){
 const body=document.getElementById('ppmpEntryBody'),add=document.getElementById('ppmpAddRow'),grand=document.getElementById('ppmpGrandTotal'); if(!body||!add)return;
 function renumber(){[...body.querySelectorAll('.ppmp-entry-row')].forEach((row,i)=>{row.querySelector('.ppmp-row-number').textContent=i+1;row.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/items\[\d+\]/,'items['+i+']'));if(window.ppmpBindItemAutocomplete)window.ppmpBindItemAutocomplete(row);});}
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
 function calc(){let g=0;body.querySelectorAll('.ppmp-entry-row').forEach(row=>{let q=parseFloat((row.querySelector('.ppmp-row-qty')?.value||'').replace(/,/g,''))||0,p=parseFloat((row.querySelector('.ppmp-row-unit-price')?.value||'').replace(/,/g,''))||0,t=q*p;g+=t;row.querySelector('.ppmp-row-total').value=t?t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):'';});if(grand)grand.textContent=g.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function bind(row){
   row.querySelectorAll('.ppmp-row-qty,.ppmp-row-unit-price').forEach(x=>{
     x.addEventListener('input',function(){formatNumberInput(this,false);calc();});
     x.addEventListener('blur',function(){formatNumberInput(this,true);calc();});
   });
   row.querySelector('.ppmp-remove-row')?.addEventListener('click',()=>{if(body.querySelectorAll('.ppmp-entry-row').length===1){row.querySelectorAll('input,textarea,select').forEach(x=>{if(x.type!=='hidden')x.value=''});calc();return;}row.remove();renumber();calc();});
 }
 add.addEventListener('click',()=>{let row=body.querySelector('.ppmp-entry-row').cloneNode(true);row.querySelectorAll('input,textarea').forEach(x=>x.value='');row.querySelectorAll('select').forEach(x=>x.selectedIndex=0);body.appendChild(row);renumber();bind(row);calc();});
 bind(body.querySelector('.ppmp-entry-row'));renumber();calc();
})();
/* Remove any legacy PPMP add-item control injected into the toolbar.
   This affects only the toolbar control and never the Data Entry "+ Add Row" control. */
(function(){
  function removeLegacyToolbarControl(root){
    const scope=root||document;
    scope.querySelectorAll('.ppmp-toolbar button, .ppmp-toolbar a, #addPpmpItemBtn').forEach(function(el){
      const label=String(el.textContent||'').replace(/\s+/g,' ').trim().toLowerCase();
      const id=String(el.id||'').toLowerCase();
      if(id==='addppmpitembtn' || label==='+ add ppmp item' || label==='add ppmp item'){
        el.remove();
      }
    });
  }
  function startLegacyToolbarGuard(){
    removeLegacyToolbarControl(document);
    if(window.MutationObserver){
      const observer=new MutationObserver(function(mutations){
        mutations.forEach(function(mutation){
          mutation.addedNodes.forEach(function(node){
            if(node.nodeType===1) removeLegacyToolbarControl(node);
          });
        });
      });
      observer.observe(document.body,{childList:true,subtree:true});
    }
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',startLegacyToolbarGuard);
  }else{
    startLegacyToolbarGuard();
  }
})();

</script>

