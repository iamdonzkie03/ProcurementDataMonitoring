<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-tree{max-width:1600px;margin:0 auto;color:#274365;font-family:inherit}
.proc-tree .flowchart-canvas{padding:28px 24px 34px;border:1px solid #d6e0ee;border-radius:14px;background:#fff;box-shadow:0 5px 22px rgba(26,55,91,.07);overflow:hidden}
.proc-tree .tree-intro{text-align:center;margin:0 auto 26px;max-width:900px}
.proc-tree .tree-intro h2{margin:0 0 8px;font-size:23px;color:#22613b}
.proc-tree .tree-intro p{margin:0;color:#617995;font-size:14px;line-height:1.55}
.proc-tree .flow-node{position:relative;box-sizing:border-box;width:min(100%,520px);min-height:68px;margin:0 auto;padding:12px 18px;border:1.6px solid #2c5595;background:#fff;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;line-height:1.45;color:#536f91}
.proc-tree .flow-node strong{font-size:15px;color:#24446f;line-height:1.35}
.proc-tree .flow-node span{display:block;margin-top:7px;font-size:13px;line-height:1.4}
.proc-tree .flow-node.start,.proc-tree .flow-node.end{border-radius:15px;min-height:68px}
.proc-tree .flow-node.start strong,.proc-tree .flow-node.end strong{color:#267143}
.proc-tree .flow-node.return{color:#8e4137;border-color:#2c5595}
.proc-tree .flow-node.return strong{color:#a33f32}
.proc-tree .flow-node.process{border-radius:0}
.proc-tree .flow-node.compact{min-height:62px}
.proc-tree .decision{width:min(100%,430px);min-height:118px;margin:0 auto;display:flex;align-items:center;justify-content:center;position:relative;padding:20px 54px;box-sizing:border-box;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);background:#2d5595;color:#73531a;text-align:center}
.proc-tree .decision:before{content:"";position:absolute;inset:2px;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);background:#fff}
.proc-tree .decision strong{position:relative;z-index:1;display:block;max-width:210px;padding:2px 5px;background:#f5d98c;color:#6e511a;font-size:14px;line-height:1.35}
.proc-tree .down{height:32px;width:2px;background:#477bd0;margin:0 auto;position:relative}
.proc-tree .down:after{content:"";position:absolute;bottom:0;left:-4px;width:8px;height:8px;border-right:2px solid #477bd0;border-bottom:2px solid #477bd0;transform:rotate(45deg)}
.proc-tree .branch-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:26px;align-items:start;max-width:1100px;margin:0 auto;position:relative}
.proc-tree .branch-grid:before{content:"";position:absolute;top:0;left:25%;right:25%;height:2px;background:#477bd0}
.proc-tree .branch-col{min-width:0;text-align:center;position:relative;padding-top:16px}
.proc-tree .branch-col:before{content:"";position:absolute;top:0;left:50%;height:16px;border-left:2px solid #477bd0}
.proc-tree .branch-label{display:inline-block;position:relative;z-index:2;margin:-9px auto 8px;padding:3px 9px;background:#fff;font-size:13px;color:#111;font-weight:500}
.proc-tree .branch-col .down{height:28px}
.proc-tree .branch-col .flow-node{width:100%;max-width:490px}
.proc-tree .subflow{margin-top:0}
.proc-tree .route-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:26px;align-items:center;max-width:1120px;margin:0 auto;position:relative}
.proc-tree .route-row .flow-node{width:100%}
.proc-tree .route-row .route-arrow{position:relative;height:2px;background:#477bd0;align-self:center}
.proc-tree .route-row .route-arrow:after{content:"";position:absolute;right:0;top:-4px;width:8px;height:8px;border-top:2px solid #477bd0;border-right:2px solid #477bd0;transform:rotate(45deg)}
.proc-tree .sequence-gap{height:28px}
.proc-tree .return-connector{max-width:1080px;margin:8px auto 18px;text-align:right;color:#647b97;font-size:12px}
.proc-tree .return-connector span{display:inline-block;padding:5px 10px;border:1px dashed #8aa5cd;border-radius:7px;background:#f7faff}
.proc-tree .outcome-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:26px;align-items:start;max-width:1120px;margin:0 auto}
.proc-tree .outcome-grid .flow-node{width:100%}
.proc-tree .decision-stack{max-width:900px;margin:0 auto}
.proc-tree .decision-stack .decision{margin:0 auto}
.proc-tree .merge-note{margin:26px auto 0;max-width:700px;padding:12px 16px;border:1px solid #b7d8c0;border-radius:14px;background:#eff9f1;text-align:center;color:#2b6540;font-size:13px;line-height:1.5}
.proc-tree .yes-label{color:#267143;font-weight:700}
.proc-tree .no-label{color:#a33f32;font-weight:700}
@media(max-width:760px){
 .proc-tree .flowchart-canvas{padding:18px 10px}
 .proc-tree .tree-intro h2{font-size:20px}
 .proc-tree .branch-grid,.proc-tree .route-row,.proc-tree .outcome-grid{grid-template-columns:1fr;gap:22px}
 .proc-tree .branch-grid:before{display:none}
 .proc-tree .branch-col:before{height:16px}
 .proc-tree .decision{min-height:106px;padding:18px 48px}
 .proc-tree .flow-node{padding:12px}
 .proc-tree .route-row .route-arrow{height:26px;width:2px;justify-self:center;background:#477bd0}
 .proc-tree .route-row .route-arrow:after{right:-3px;top:auto;bottom:0;transform:rotate(135deg)}
}
@media print{
 .proc-tree .flowchart-canvas{box-shadow:none;border:0;padding:8px;overflow:visible}
 .proc-tree .flow-node,.proc-tree .decision,.proc-tree .merge-note{break-inside:avoid;print-color-adjust:exact;-webkit-print-color-adjust:exact}
 .proc-tree .down{background:#477bd0}
}
</style>
<div class="proc-tree">
  <div class="flowchart-canvas">
    <div class="tree-intro">
      <h2>Procurement Process Decision Tree</h2>
      <p>Trace the standard procurement path and follow each Yes/No decision to understand approvals, returns, failed bids, and contract completion.</p>
    </div>

    <div class="flow-node start"><strong>End-user prepares PPMP</strong><span>Create the PPMP and submit it to the Division</span></div>
    <div class="down"></div>
    <div class="decision"><strong>Division Head approves PPMP?</strong></div>
    <div class="branch-grid">
      <div class="branch-col">
        <div class="branch-label yes-label">YES</div>
        <div class="flow-node process"><strong>Budget Section review</strong><span>Review the PPMP and issue budget allocation decision.</span></div>
        <div class="down"></div>
        <div class="decision"><strong>Budget Officer approves?</strong></div>
        <div class="branch-grid subflow">
          <div class="branch-col">
            <div class="branch-label yes-label">YES</div>
            <div class="flow-node end"><strong>PPMP approved</strong><span>Return to End-user and include approved items in the APP.</span></div>
          </div>
          <div class="branch-col">
            <div class="branch-label no-label">NO</div>
            <div class="flow-node return"><strong>Return to End-user</strong><span>Division Head disapproves the PPMP.</span></div>
          </div>
        </div>
      </div>
      <div class="branch-col">
        <div class="branch-label no-label">NO</div>
        <div class="flow-node return"><strong>Return to End-user</strong><span>Division Head disapproves the PPMP.</span></div>
      </div>
    </div>

    <div class="sequence-gap"></div>
    <div class="down"></div>
    <div class="flow-node start"><strong>Approved PPMP items included in the APP</strong></div>
    <div class="down"></div>
    <div class="flow-node start"><strong>Create Purchase Request (PR)</strong><span>Select approved APP items; route to the Budget</span></div>
    <div class="down"></div>
    <div class="flow-node process"><strong>Budget Office issues CAF</strong><span>Certificate of Availability of Funds; forward PR to the Head of the Procuring Entity (HoPE).</span></div>
    <div class="down"></div>
    <div class="decision"><strong>Does HoPE approve the PR?</strong></div>
    <div class="branch-grid">
      <div class="branch-col">
        <div class="branch-label yes-label">YES</div>
        <div class="flow-node process"><strong>Procurement Section &amp; BAC review</strong><span>Verify documentary requirements and prepare the bid opportunity, if applicable.</span></div>
        <div class="down"></div>
        <div class="flow-node end"><strong>Proceed to bid evaluation</strong><span>Continue to the next decision stage.</span></div>
        <div class="down"></div>
        <div class="flow-node start"><strong>BAC recommendation submitted to HoPE</strong></div>
      </div>
      <div class="branch-col">
        <div class="branch-label no-label">NO</div>
        <div class="flow-node return"><strong>Return to End-user</strong><span>PR is returned for correction and resubmission.</span></div>
      </div>
    </div>

    <div class="sequence-gap"></div>
    <div class="down"></div>
    <div class="flow-node process"><strong>HoPE reviews recommendation of award</strong><span>Decide whether to approve or disapprove the BAC recommendation.</span></div>
    <div class="down"></div>
    <div class="decision"><strong>Does HoPE approve the recommendation?</strong></div>
    <div class="branch-grid">
      <div class="branch-col">
        <div class="branch-label yes-label">YES</div>
        <div class="flow-node process"><strong>Prepare PO / Contract &amp; NTP</strong><span>Procurement Section prepares the Purchase Order/Contract and Notice to Proceed for HoPE approval.</span></div>
        <div class="down"></div>
        <div class="decision"><strong>Does HoPE approve the recommendation?</strong></div>
        <div class="branch-grid subflow">
          <div class="branch-col">
            <div class="branch-label yes-label">YES</div>
            <div class="flow-node end"><strong>Issue approved contract</strong><span>Send approved copies to the winning bidder; obligations may begin under the contract terms.</span></div>
            <div class="down"></div>
            <div class="flow-node end"><strong>Recordkeeping</strong><span>Procurement Section retains approved PO/Contract and NTP copies.</span></div>
          </div>
          <div class="branch-col">
            <div class="branch-label no-label">NO</div>
            <div class="flow-node return"><strong>Project cancelled</strong><span>Contract disapproval cancels the project.</span></div>
          </div>
        </div>
      </div>
      <div class="branch-col">
        <div class="branch-label no-label">NO</div>
        <div class="flow-node return"><strong>Return to End-user</strong><span>HoPE disapproves the recommendation of award.</span></div>
      </div>
    </div>
  </div>
</div>
<?php pageEnd(); ?>
