<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';

$sections = [
    'Dashboard' => [
        ['View Dashboard', '✓', '✓', '✓', '✓'],
        ['View summary cards and statistics', '✓', '✓', '✓', '◐'],
    ],
    'Annual Procurement Plan (APP)' => [
        ['View APP', '✓', '✓', '✓', '✓'],
        ['Create / edit / delete APP data', '✓', '◐', '—', '—'],
        ['Print / export APP', '✓', '✓', '✓', '◐'],
    ],
    'Project Procurement Management Plan (PPMP)' => [
        ['View PPMP records', '✓', '✓', '✓', '—'],
        ['Create PPMP', '✓', '✓', '—', '—'],
        ['Edit / delete PPMP entries', '✓', '✓', '—', '—'],
        ['Submit PPMP for review', '✓', '✓', '—', '—'],
        ['Review / approve / decline PPMP', '◐', '◐', '—', '—'],
        ['View review remarks and status', '✓', '✓', '✓', '—'],
    ],
    'Purchase Requests (PR)' => [
        ['View PR records', '✓', '✓', '✓', '—'],
        ['Create / edit PR', '✓', '✓', '—', '—'],
        ['Delete / cancel PR', '✓', '◐', '—', '—'],
        ['Print PR', '✓', '✓', '✓', '—'],
    ],
    'Purchase Orders (PO)' => [
        ['View PO records', '✓', '✓', '✓', '—'],
        ['Create / edit PO', '✓', '✓', '—', '—'],
        ['Delete / cancel PO', '✓', '◐', '—', '—'],
        ['Print PO', '✓', '✓', '✓', '—'],
    ],
    'Supplier Registry' => [
        ['View / search registered suppliers', '✓', '✓', '✓', '—'],
        ['Add supplier records', '✓', '✓', '—', '—'],
        ['Edit supplier records', '✓', '✓', '—', '—'],
        ['Delete supplier records and uploaded files', '✓', '✓', '—', '—'],
    ],
    'Masterlist and Settings' => [
        ['Manage PPMP Masterlist', '✓', '✓', '—', '—'],
        ['Manage UOM, Category and Classification', '✓', '◐', '—', '—'],
        ['Manage Procurement Methods and Area/Unit settings', '✓', '◐', '—', '—'],
    ],
    'User Management and Security' => [
        ['Create user accounts', '✓', '✓', '—', '—'],
        ['Edit / deactivate user accounts', '✓', '◐', '—', '—'],
        ['Assign roles and permissions', '✓', '◐', '—', '—'],
        ['Change login page background / design', '✓', '—', '—', '—'],
        ['Change own password', '✓', '✓', '✓', '◐'],
        ['Forgot password / password reset', '✓', '✓', '✓', '◐'],
    ],
];
pageStart('Role and Functions Access Matrix');
?>
<style>
.role-guide-intro{color:#64748b;margin:0 0 16px;line-height:1.6}
.role-guide-legend{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px;font-size:12px}
.role-guide-legend span{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border:1px solid #e2e8f0;border-radius:8px;background:#fff}
.role-guide-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px}
.role-guide-table{width:100%;border-collapse:collapse;min-width:720px;font-size:13px}
.role-guide-table th,.role-guide-table td{padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:middle}
.role-guide-table thead th{background:#f1f5f9;color:#334155;font-weight:700;white-space:nowrap}
.role-guide-table .role-section td{background:#f8fafc;color:#334155;font-weight:700;border-top:1px solid #cbd5e1}
.role-guide-table .role-check{text-align:center;font-size:16px;font-weight:700;width:92px}
.role-guide-table tr:last-child td{border-bottom:0}
.role-guide-note{font-size:12px;line-height:1.65;color:#64748b;margin:16px 0 0}
.role-guide-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
@media(max-width:700px){.role-guide-table{font-size:12px}.role-guide-table th,.role-guide-table td{padding:8px}}
</style>
<div class="panel">
  <div class="role-guide-actions">
    <div>
      <h2 style="margin:0 0 6px">Role and Functions Access Matrix</h2>
      <p class="role-guide-intro">Use this guide to understand which modules and actions are intended for each account role.</p>
    </div>
    <a class="btn" href="index.php">← Back to Dashboard</a>
  </div>
  <div class="role-guide-legend" aria-label="Access legend">
    <span><strong>✓</strong> Allowed</span>
    <span><strong>◐</strong> Limited / subject to authorization</span>
    <span><strong>—</strong> Not allowed</span>
  </div>
  <div class="role-guide-table-wrap">
    <table class="role-guide-table">
      <thead>
        <tr>
          <th scope="col">Module / Function</th>
          <th scope="col" class="role-check">Administrator</th>
          <th scope="col" class="role-check">Editor</th>
          <th scope="col" class="role-check">Viewer</th>
          <th scope="col" class="role-check">Guest</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sections as $section => $rows): ?>
        <tr class="role-section"><td colspan="5"><?=e($section)?></td></tr>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?=e($row[0])?></td>
            <?php for ($i = 1; $i <= 4; $i++): ?>
              <td class="role-check" aria-label="<?=e($row[$i])?>"><?=e($row[$i])?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="role-guide-note"><strong>Important:</strong> This matrix documents the intended access policy and should be used as a user guide, not as proof that every permission is currently enforced in the application. Limited access depends on assigned permissions and workflow status. PPMP review, approval and decline actions must be authorized by the user's assigned workflow role (such as supervisor or budget officer), not simply by being an Administrator or Editor. Users should only be able to perform actions that are also validated by the server.</p>
</div>
<?php pageEnd(); ?>
