<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-tree{max-width:1500px;margin:0 auto}
.proc-tree .tree-intro{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.proc-tree .tree-intro p{margin:6px 0 0;color:#64748b;font-size:13px;line-height:1.7}
.proc-tree .tree-actions{display:flex;gap:8px;flex-wrap:wrap}
.proc-tree .tree-legend{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 20px;padding:12px 15px;background:#f8fafc;border:1px solid #e5eaf0;border-radius:10px;font-size:12px;color:#475569}
.proc-tree .legend-item{display:flex;align-items:center;gap:7px}
.proc-tree .legend-dot{width:10px;height:10px;border-radius:3px;display:inline-block}
.proc-tree .tree-phase{margin:0 0 22px;padding:22px;border:1px solid #e1e7ef;border-radius:14px;background:#fff;box-shadow:0 4px 15px rgba(15,23,42,.035);overflow:hidden}
.proc-tree .phase-title{display:flex;align-items:center;gap:11px;margin:0 0 22px;font-size:16px;color:#25364e}
.proc-tree .phase-no{display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;border-radius:10px;background:#e8eef7;color:#29466e;font-size:12px;font-weight:800}
.proc-tree .tree-node{position:relative;max-width:650px;margin:0 auto;padding:13px 17px;border:1px solid #cbd8e8;border-radius:10px;background:#f6f9fd;color:#24364e;text-align:center;line-height:1.5;box-shadow:0 2px 5px rgba(15,23,42,.035)}
.proc-tree .tree-node strong{display:block;font-size:13px}
.proc-tree .tree-node span{display:block;margin-top:4px;color:#64748b;font-size:12px}
.proc-tree .tree-node.start{background:#edf8f0;border-color:#b8dfc2}
.proc-tree .tree-node.end{background:#f0f7f2;border-color:#b9ddc2}
.proc-tree .tree-node.return{background:#fff4f1;border-color:#f0c5bb;color:#923f35}
.proc-tree .tree-node.info{background:#f2f6fc}
.proc-tree .tree-arrow{height:30px;display:flex;align-items:center;justify-content:center;color:#8291a5;font-size:23px;line-height:1}
.proc-tree .tree-arrow:before{content:"↓"}
.proc-tree .decision{max-width:530px;margin:0 auto;text-align:center}
.proc-tree .decision-shape{display:inline-flex;align-items:center;justify-content:center;min-height:74px;min-width:min(100%,290px);padding:12px 25px;border:2px solid #d9b76a;background:#fff8e8;color:#78561a;clip-path:polygon(8% 0,92% 0,100% 50%,92% 100%,8% 100%,0 50%);font-weight:800;font-size:13px;line-height:1.45}
.proc-tree .branch-label{display:inline-block;margin:0 auto;padding:5px 10px;border-radius:20px;font-size:11px;font-weight:800;letter-spacing:.03em}
.proc-tree .branch-label.yes{background:#e8f6ec;color:#267143}
.proc-tree .branch-label.no{background:#fff0ed;color:#a33d32}
.proc-tree .branch-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;margin-top:14px;position:relative}
.proc-tree .branch-columns:before{content:"";position:absolute;top:-15px;left:25%;right:25%;height:1px;background:#cbd5e1}
.proc-tree .branch{min-width:0;position:relative;text-align:center;padding-top:4px}
.proc-tree .branch:before{content:"";position:absolute;top:-15px;left:50%;height:15px;border-left:1px solid #cbd5e1}
.proc-tree .branch .tree-node{height:100%;box-sizing:border-box}
.proc-tree .branch .tree-arrow{height:25px}
.proc-tree .branch .tree-node+.tree-arrow{margin-top:0}
.proc-tree .merge-note{max-width:750px;margin:18px auto 0;padding:11px 14px;text-align:center;border:1px dashed #c5d0df;border-radius:9px;background:#fafcff;color:#526176;font-size:12px;line-height:1.6}
.proc-tree .tree-note{margin:8px 0 0;color:#64748b;font-size:11px;text-align:center}
@media(max-width:720px){.proc-tree .tree-phase{padding:15px}.proc-tree .branch-columns{grid-template-columns:1fr;gap:22px}.proc-tree .branch-columns:before{display:none}.proc-tree .branch:before{top:-15px;height:15px}.proc-tree .tree-node{padding:12px}.proc-tree .decision-shape{min-width:0;width:100%;box-sizing:border-box;clip-path:none;border-radius:10px;min-height:0}.proc-tree .phase-title{font-size:14px}.proc-tree .tree-legend{gap:8px}}
@media print{.proc-tree .tree-actions{display:none}.proc-tree .tree-phase{break-inside:avoid;box-shadow:none}.proc-tree .tree-node,.proc-tree .decision-shape{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
<div class="proc-tree">
  <div class="tree-intro">
    <div><h2 style="margin:0">Procurement Process Decision Tree</h2><p>Trace the standard procurement path and follow each Yes/No decision to understand approvals, returns, failed bids, and contract completion.</p></div>
    <div class="tree-actions"><a class="btn" href="index.php">← Dashboard</a><a class="btn btn-primary" href="app.php?year=<?= (int)date('Y') ?>">View Consolidated APP</a><button class="btn" type="button" onclick="window.print()">Print Decision Tree</button></div>
  </div>
  <div class="tree-legend">
    <span class="legend-item"><i class="legend-dot" style="background:#b8dfc2"></i> Start / completed</span>
    <span class="legend-item"><i class="legend-dot" style="background:#d9b76a"></i> Decision point</span>
    <span class="legend-item"><i class="legend-dot" style="background:#f0c5bb"></i> Return / disapproval / failure</span>
    <span class="legend-item"><i class="legend-dot" style="background:#cbd8e8"></i> Process step</span>
  </div>
  <section class="tree-phase">
    <h3 class="phase-title"><span class="phase-no">01</span> PPMP Preparation, Review &amp; Budget Approval</h3>
    <div class="tree-node start"><strong>End-user prepares PPMP</strong><span>Create the PPMP and submit it to the Division Head.</span></div>
    <div class="tree-arrow"></div>
    <div class="decision"><div class="decision-shape">Division Head approves PPMP?</div></div>
    <div class="branch-columns">
      <div class="branch"><span class="branch-label yes">YES</span><div class="tree-arrow"></div><div class="tree-node"><strong>Budget Section review</strong><span>Review the PPMP and issue budget allocation decision.</span></div><div class="tree-arrow"></div><div class="decision"><div class="decision-shape">Budget Officer approves?</div></div>
        <div class="branch-columns">
          <div class="branch"><span class="branch-label yes">YES</span><div class="tree-arrow"></div><div class="tree-node end"><strong>PPMP approved</strong><span>Return to End-user and include approved items in the APP.</span></div></div>
          <div class="branch"><span class="branch-label no">NO</span><div class="tree-arrow"></div><div class="tree-node return"><strong>Return to End-user</strong><span>PPMP is marked disapproved.</span></div></div>
        </div>
      </div>
      <div class="branch"><span class="branch-label no">NO</span><div class="tree-arrow"></div><div class="tree-node return"><strong>Return to End-user</strong><span>Division Head disapproves the PPMP.</span></div></div>
    </div>
    <p class="tree-note">Only approved PPMP items are available for inclusion in the APP and subsequent Purchase Requests.</p>
  </section>
  <section class="tree-phase">
    <h3 class="phase-title"><span class="phase-no">02</span> Purchase Request &amp; HoPE Approval</h3>
    <div class="tree-node start"><strong>Create Purchase Request (PR)</strong><span>Select approved APP items; route to the Budget Office for CAF issuance.</span></div>
    <div class="tree-arrow"></div><div class="tree-node"><strong>Budget Office issues CAF</strong><span>Certificate of Availability of Funds; forward PR to the Head of the Procuring Entity (HoPE).</span></div>
    <div class="tree-arrow"></div><div class="decision"><div class="decision-shape">Does HoPE approve the PR?</div></div>
    <div class="branch-columns">
      <div class="branch"><span class="branch-label yes">YES</span><div class="tree-arrow"></div><div class="tree-node"><strong>Procurement Section &amp; BAC review</strong><span>Verify documentary requirements and prepare the bid opportunity, if applicable.</span></div><div class="tree-arrow"></div><div class="tree-node end"><strong>Proceed to bid evaluation</strong><span>Continue to the next decision stage.</span></div></div>
      <div class="branch"><span class="branch-label no">NO</span><div class="tree-arrow"></div><div class="tree-node return"><strong>PR returned to End-user</strong><span>Process stops until the request is corrected and resubmitted, as applicable.</span></div></div>
    </div>
  </section>
  <section class="tree-phase">
    <h3 class="phase-title"><span class="phase-no">03</span> Bid Evaluation &amp; Recommendation of Award</h3>
    <div class="tree-node"><strong>End-user evaluates bid documents</strong><span>Assess completeness and compliance of the bidder’s submission.</span></div>
    <div class="tree-arrow"></div><div class="decision"><div class="decision-shape">Is the bid compliant?</div></div>
    <div class="branch-columns">
      <div class="branch"><span class="branch-label yes">YES</span><div class="tree-arrow"></div><div class="tree-node end"><strong>Bid marked Awarded</strong><span>Continue with BAC re-evaluation of compliance requirements.</span></div><div class="tree-arrow"></div><div class="tree-node"><strong>BAC recommends award</strong><span>BAC issues a resolution recommending award for HoPE decision.</span></div></div>
      <div class="branch"><span class="branch-label no">NO</span><div class="tree-arrow"></div><div class="tree-node return"><strong>Bid marked Failed</strong><span>Non-compliant bid opportunity is recorded as failed.</span></div></div>
    </div>
  </section>
  <section class="tree-phase">
    <h3 class="phase-title"><span class="phase-no">04</span> HoPE Decision, Contract &amp; Closeout</h3>
    <div class="tree-node"><strong>HoPE reviews recommendation of award</strong><span>Decide whether to approve or disapprove the BAC recommendation.</span></div>
    <div class="tree-arrow"></div><div class="decision"><div class="decision-shape">Does HoPE approve the recommendation?</div></div>
    <div class="branch-columns">
      <div class="branch"><span class="branch-label yes">YES</span><div class="tree-arrow"></div><div class="tree-node"><strong>Prepare PO / Contract &amp; NTP</strong><span>Procurement Section prepares the Purchase Order/Contract and Notice to Proceed for HoPE approval.</span></div><div class="tree-arrow"></div><div class="decision"><div class="decision-shape">Does HoPE approve the contract?</div></div>
        <div class="branch-columns">
          <div class="branch"><span class="branch-label yes">YES</span><div class="tree-arrow"></div><div class="tree-node end"><strong>Issue approved contract</strong><span>Send approved copies to the winning bidder; obligations may begin under the contract terms.</span></div><div class="tree-arrow"></div><div class="tree-node end"><strong>Recordkeeping</strong><span>Procurement Section retains approved PO/Contract and NTP copies.</span></div></div>
          <div class="branch"><span class="branch-label no">NO</span><div class="tree-arrow"></div><div class="tree-node return"><strong>Project cancelled</strong><span>Contract disapproval cancels the project.</span></div></div>
        </div>
      </div>
      <div class="branch"><span class="branch-label no">NO</span><div class="tree-arrow"></div><div class="tree-node return"><strong>Return to End-user</strong><span>HoPE disapproves the recommendation of award.</span></div></div>
    </div>
  </section>
  <div class="merge-note"><strong>Process completion:</strong> The approved Purchase Order/Contract and Notice to Proceed are issued to the winning bidder, and the Procurement Section retains the approved documents for its records.</div>
</div>
<?php pageEnd(); ?>
