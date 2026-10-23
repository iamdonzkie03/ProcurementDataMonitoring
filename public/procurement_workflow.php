<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-workflow-panel{max-width:1600px;margin:0 auto;border:1px solid #d9e4f0;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 12px 32px rgba(21,49,80,.08)}
.proc-workflow-panel .panel-heading{padding:22px 28px;background:linear-gradient(115deg,#102c50,#23578d);color:#fff}
.proc-workflow-panel .panel-heading h2{margin:0;color:#fff;font-size:clamp(22px,3vw,30px);font-weight:800}
</style>
<section class="proc-workflow-panel">
  <header class="panel-heading">
    <h2>Procurement Process Flowchart</h2>
  </header>
</section>
<?php pageEnd(); ?>
