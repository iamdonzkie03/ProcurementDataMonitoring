<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';

pageStart('Bids and Awards Committee');
?>
<div class="panel bac-page-panel">
  <div class="bac-page-heading">
    <div class="bac-page-icon" aria-hidden="true">⚖</div>
    <div>
      <h2>Bids and Awards Committee</h2>
      <p>Committee workspace for procurement activities and related records.</p>
    </div>
  </div>
</div>
<style>
.content:has(.bac-page-panel){max-width:none!important;width:100%;padding:20px 28px 28px!important;min-height:calc(100vh - 170px);display:flex;flex-direction:column}
.bac-page-panel{width:100%;max-width:none;flex:1;min-height:calc(100vh - 220px);margin:0;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px;box-shadow:0 8px 24px rgba(16,42,67,.08);display:flex;flex-direction:column}
.bac-page-heading{display:flex;align-items:center;gap:16px;padding-bottom:20px;border-bottom:1px solid #e5e7eb}
.bac-page-icon{width:54px;height:54px;flex:0 0 54px;display:grid;place-items:center;border-radius:12px;background:#102a43;color:#fff;font-size:27px}
.bac-page-heading h2{margin:0 0 5px;color:#102a43;font-size:22px}
.bac-page-heading p,.bac-page-notice p{margin:0;color:#6b7280;font-size:13px;line-height:1.6}
.bac-page-notice{margin-top:22px;padding:18px 20px;background:#f4f7fb;border-left:4px solid #c49a42;border-radius:7px}
.bac-page-notice strong{display:block;margin-bottom:5px;color:#102a43}
.bac-page-links{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
@media(max-width:700px){.content:has(.bac-page-panel){padding:14px!important;min-height:calc(100vh - 150px)}.bac-page-panel{min-height:calc(100vh - 190px);padding:17px}.bac-page-heading h2{font-size:18px}.bac-page-icon{width:44px;height:44px;flex-basis:44px}}
</style>
<?php pageEnd(); ?>
