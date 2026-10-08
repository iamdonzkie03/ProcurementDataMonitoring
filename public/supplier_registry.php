<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer']);
require_once __DIR__.'/../app/layout.php';
$pdo=db();
$rows=$pdo->query("SELECT supplier,COUNT(*) po_count,MAX(po_date) last_po,COALESCE(SUM(x.total_amount),0) total_value FROM purchase_orders o LEFT JOIN (SELECT po_id,SUM(quantity*unit_price) total_amount FROM purchase_order_items GROUP BY po_id) x ON x.po_id=o.id WHERE TRIM(COALESCE(supplier,''))<>'' GROUP BY supplier ORDER BY supplier")->fetchAll();
pageStart('Supplier Registry');
?>
<div class="panel">
<div class="toolbar"><div><h2 style="margin:0">Supplier Registry</h2><p class="hint-text">Registered suppliers appearing in Purchase Orders.</p></div></div>
<div class="table-wrap"><table class="table"><tr><th>#</th><th>Supplier Name</th><th>Purchase Orders</th><th>Last PO Date</th><th>Total PO Value</th></tr>
<?php $i=1; foreach($rows as $r): ?><tr><td><?=$i++?></td><td><b><?=e($r['supplier'])?></b></td><td><?=number_format((int)$r['po_count'])?></td><td><?=e($r['last_po']??'—')?></td><td>₱<?=number_format((float)$r['total_value'],2)?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" class="empty">No suppliers are currently registered through Purchase Orders.</td></tr><?php endif; ?>
</table></div></div>
<?php pageEnd(); ?>