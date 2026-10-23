<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-tree{max-width:1600px;margin:0 auto;color:#344760;font-family:inherit}
.proc-tree .flowchart-canvas{padding:30px 28px 36px;border:1px solid #e0e6ee;border-radius:18px;background:linear-gradient(145deg,#fff 0%,#fbfcfe 100%);box-shadow:0 12px 36px rgba(25,45,75,.08);overflow:hidden}
.proc-tree .tree-intro{text-align:center;margin:0 auto 28px;max-width:900px}
.proc-tree .tree-intro h2{margin:0 0 9px;font-size:25px;letter-spacing:-.45px;font-weight:750;color:#203c5b}
.proc-tree .tree-intro p{margin:0;color:#738198;font-size:14px;line-height:1.65}
.proc-tree .flow-node{position:relative;box-sizing:border-box;width:min(100%,520px);min-height:70px;margin:0 auto;padding:14px 20px;border:1px solid #cad5e3;border-radius:10px;background:#fff;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;line-height:1.5;color:#6a788d;box-shadow:0 4px 12px rgba(33,54,82,.045)}
.proc-tree .flow-node strong{font-size:14px;color:#314d70;line-height:1.4;font-weight:700}
.proc-tree .flow-node span{display:block;margin-top:6px;font-size:12px;line-height:1.55}
.proc-tree .flow-node.start,.proc-tree .flow-node.end{border-color:#9fc9ad;background:linear-gradient(135deg,#f4fbf6,#fff);border-radius:14px;min-height:70px}
.proc-tree .flow-node.start strong,.proc-tree .flow-node.end strong{color:#276b43}
.proc-tree .flow-node.return{border-color:#e2b9b1;background:#fff7f5;color:#a4564b}
.proc-tree .flow-node.return strong{color:#a04439}
.proc-tree .flow-node.process{border-radius:5px}
.proc-tree .decision{width:min(100%,430px);min-height:124px;margin:0 auto;display:flex;align-items:center;justify-content:center;position:relative;padding:24px 58px;box-sizing:border-box;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);background:#b68b3e;text-align:center;filter:drop-shadow(0 4px 5px rgba(82,64,30,.08))}
.proc-tree .decision:before{content:"";position:absolute;inset:2px;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);background:#fffaf0}
.proc-tree .decision strong{position:relative;z-index:1;display:block;max-width:205px;padding:4px 8px;color:#72531d;font-size:13px;line-height:1.45;font-weight:750}
.proc-tree .down{height:30px;width:2px;background:#a9b8cb;margin:0 auto;position:relative}
.proc-tree .down:after{content:"";position:absolute;bottom:0;left:-4px;width:8px;height:8px;border-right:2px solid #a9b8cb;border-bottom:2px solid #a9b8cb;transform:rotate(45deg)}
.proc-tree .branch-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:28px;align-items:start;max-width:1100px;margin:0 auto;position:relative}
.proc-tree .branch-grid:before{content:"";position:absolute;top:0;left:25%;right:25%;height:2px;background:#a9b8cb}
.proc-tree .branch-col{min-width:0;text-align:center;position:relative;padding-top:17px}
.proc-tree .branch-col:before{content:"";position:absolute;top:0;left:50%;height:17px;border-left:2px solid #a9b8cb}
.proc-tree .branch-label{display:inline-block;position:relative;z-index:2;margin:-9px auto 9px;padding:4px 11px;border-radius:20px;background:#f4f7fb;font-size:10px;letter-spacing:.08em;color:#5b6c83;font-weight:800}
.proc-tree .branch-col .down{height:26px}
.proc-tree .branch-col .flow-node{width:100%;max-width:490px}
.proc-tree .subflow{margin-top:0}
.proc-tree .sequence-gap{height:26px}
.proc-tree .yes-label{background:#eaf6ee;color:#267247}
.proc-tree .no-label{background:#fff0ed;color:#a7473c}
.proc-tree .return-link{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:5px;min-height:65px;padding:3px 0 8px;color:#a04439;text-decoration:none;font-size:12px;font-weight:750;text-align:center}
.proc-tree .return-link .return-arrow{font-size:28px;line-height:1;color:#c16d60}
.proc-tree .return-link span:last-child{display:block;padding:7px 14px;border:1px solid #e7c2bb;border-radius:9px;background:#fff6f3}
.proc-tree .return-hub{width:min(100%,430px);margin:0 auto 22px;padding:14px 18px;border:1px solid #dca69d;border-radius:12px;background:linear-gradient(135deg,#fff3f0,#fff);text-align:center;box-shadow:0 5px 15px rgba(136,67,57,.07)}
.proc-tree .return-hub strong{display:block;color:#a04439;font-size:14px}
.proc-tree .return-hub span{display:block;margin-top:4px;color:#956b65;font-size:12px}
.proc-tree .return-hub:before{content:"RETURN PATH";display:block;margin-bottom:6px;font-size:9px;letter-spacing:.16em;font-weight:800;color:#b66a5f}
@media(max-width:760px){
 .proc-tree .flowchart-canvas{padding:20px 11px}
 .proc-tree .tree-intro h2{font-size:21px}
 .proc-tree .branch-grid{grid-template-columns:1fr;gap:20px}
 .proc-tree .branch-grid:before{display:none}
 .proc-tree .branch-col:before{height:17px}
 .proc-tree .decision{min-height:108px;padding:20px 49px}
 .proc-tree .flow-node{padding:12px}
}
@media print{
 .proc-tree .flowchart-canvas{box-shadow:none;border:0;padding:8px;overflow:visible}
 .proc-tree .flow-node,.proc-tree .decision,.proc-tree .return-hub{break-inside:avoid;print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
<div class="proc-tree">
  <div class="flowchart-canvas">
    <div class="tree-intro">
      <h2>Procurement Process Decision Tree</h2>
      <p>Trace the standard procurement path and follow each Yes/No decision to understand approvals, returns, failed bids, and contract completion.</p>
    </div>
    <div class="return-hub" id="return-to-end-user"><strong>Return to End-user</strong><span>Correct or revise the submission, then resubmit it for review.</span></div>

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
            <a class="return-link" href="#return-to-end-user" aria-label="Return to End-user"><span class="return-arrow">↑</span><span>Return to End-user</span></a>
          </div>
        </div>
      </div>
      <div class="branch-col">
        <div class="branch-label no-label">NO</div>
        <a class="return-link" href="#return-to-end-user" aria-label="Return to End-user"><span class="return-arrow">↑</span><span>Return to End-user</span></a>
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
        <a class="return-link" href="#return-to-end-user" aria-label="Return to End-user"><span class="return-arrow">↑</span><span>Return to End-user</span></a>
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
        <a class="return-link" href="#return-to-end-user" aria-label="Return to End-user"><span class="return-arrow">↑</span><span>Return to End-user</span></a>
      </div>
    </div>
  </div>
</div>
<?php pageEnd(); ?>
