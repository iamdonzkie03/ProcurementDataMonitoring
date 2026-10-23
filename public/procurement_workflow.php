<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-tree{max-width:1180px;margin:0 auto;color:#29425f;font-family:inherit}
.proc-tree .flowchart-canvas{padding:34px 24px 48px;border:1px solid #d6e0ed;border-radius:10px;background:#fff;box-shadow:0 6px 22px rgba(32,68,120,.07);overflow:hidden}
.proc-tree .tree-intro{text-align:center;margin:0 auto 30px;max-width:900px}
.proc-tree .tree-intro h2{margin:0 0 8px;font-size:25px;font-weight:750;color:#203c5b}
.proc-tree .tree-intro p{margin:0;color:#738198;font-size:14px;line-height:1.6}
.proc-tree .excel-flow{--rail-x:calc(50% + 350px);position:relative;max-width:820px;margin:0 auto;padding:0 50px 0 0}
.proc-tree .excel-flow:after{content:"";position:absolute;z-index:0;top:150px;bottom:70px;right:16px;width:1.5px;background:#4472c4}
.proc-tree .flow-node{position:relative;z-index:1;box-sizing:border-box;width: min(100%,520px);min-height:58px;margin:0 auto;padding:10px 16px;border:1.5px solid #315f9f;border-radius:13px;background:#c3d9ee;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;line-height:1.4;color:#60748c}
.proc-tree .flow-node strong{font-size:14px;color:#21683b;line-height:1.4;font-weight:750}
.proc-tree .flow-node span{display:block;margin-top:5px;font-size:12px;line-height:1.45;color:#60748c}
.proc-tree .flow-node.process{border-radius:0;background:#fff000;border-color:#315f9f;box-shadow:none}
.proc-tree .flow-node.process strong{color:#0047b8}
.proc-tree .flow-node.final{border-radius:13px;background:#c3d9ee}
.proc-tree .flow-node.final strong{color:#21683b}
.proc-tree .decision-row{position:relative;z-index:1;width:100%;min-height:145px;display:flex;align-items:center;justify-content:center}
.proc-tree .decision{width:min(100%,430px);height:100px;display:flex;align-items:center;justify-content:center;position:relative;padding:20px 60px;box-sizing:border-box;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);background:#315f9f;text-align:center}
.proc-tree .decision:before{content:"";position:absolute;inset:1.5px;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);background:#fff1c9}
.proc-tree .decision strong{position:relative;z-index:1;display:block;max-width:190px;color:#76531b;font-size:13px;line-height:1.4;font-weight:750;text-align:center}
.proc-tree .connector{position:relative;z-index:1;width:1.5px;height:28px;margin:0 auto;background:#4472c4}
.proc-tree .connector:after{content:"";position:absolute;bottom:0;left:-4px;width:8px;height:8px;border-right:2px solid #4472c4;border-bottom:2px solid #4472c4;transform:rotate(45deg)}
.proc-tree .decision-row .connector{position:absolute;top:0;left:50%;height:22px}
.proc-tree .decision-row .decision{margin-top:22px}
.proc-tree .yes-tag,.proc-tree .no-tag{position:absolute;z-index:2;display:flex;align-items:center;justify-content:center;width:54px;height:34px;box-sizing:border-box;border:1.5px solid #315f9f;border-radius:50%;font-size:11px;font-weight:500;color:#111}
.proc-tree .yes-tag{left:calc(50% + 48px);bottom:1px;background:#70b64a}
.proc-tree .no-tag{left:calc(50% + 185px);top:42px;background:#ff1717;color:#fff}
.proc-tree .no-tag:before{content:"";position:absolute;right:100%;top:50%;width:24px;border-top:1.5px solid #4472c4}
.proc-tree .no-tag:after{content:"";position:absolute;left:50%;top:100%;height:calc(var(--rail-length, 100px));border-left:1.5px solid #4472c4}
.proc-tree .branch-note{font-size:12px;text-align:center;color:#61748e;margin:4px auto 0}
.proc-tree .spacer{height:18px}
.proc-tree .return-hub{width:min(100%,430px);margin:0 auto 28px;padding:12px 16px;border:1.5px solid #315f9f;border-radius:13px;background:#c3d9ee;text-align:center}
.proc-tree .return-hub strong{display:block;color:#174e91;font-size:14px}
.proc-tree .return-hub span{display:block;margin-top:4px;color:#60748c;font-size:12px}
.proc-tree .return-hub:before{content:"RETURN PATH";display:block;margin-bottom:5px;font-size:9px;letter-spacing:.16em;font-weight:800;color:#315f9f}
@media(max-width:760px){
 .proc-tree .flowchart-canvas{padding:20px 10px}
 .proc-tree .tree-intro h2{font-size:21px}
 .proc-tree .excel-flow{padding-right:0}
 .proc-tree .excel-flow:after{right:2px;opacity:.55}
 .proc-tree .decision{padding:17px 45px;height:95px}
 .proc-tree .no-tag{left:auto;right:-2px;top:43px}
 .proc-tree .no-tag:before{width:10px}
 .proc-tree .yes-tag{left:calc(50% + 38px)}
 .proc-tree .flow-node{padding:10px}
}
@media print{
 .proc-tree .flowchart-canvas{box-shadow:none;border:0;padding:8px;overflow:visible}
 .proc-tree .flow-node,.proc-tree .decision,.proc-tree .return-hub{break-inside:avoid;print-color-adjust:exact;-webkit-print-color-adjust:exact}
 .proc-tree .excel-flow:after{print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
<div class="proc-tree">
 <div class="flowchart-canvas">
  <div class="tree-intro">
   <h2>Procurement Process Flowchart</h2>
   <p>Follow the same top-to-bottom process sequence, decision shapes, Yes/No markers, and connecting lines shown in the Excel flowchart.</p>
  </div>
  <div class="return-hub" id="return-to-end-user"><strong>Return to End-user</strong><span>Revise and resubmit when a decision is No.</span></div>
  <div class="excel-flow">
   <div class="flow-node"><strong>End-user prepares PPMP</strong><span>Create the PPMP and submit it to the Division Head.</span></div>
   <div class="connector"></div>
   <div class="decision-row"><div class="connector"></div><div class="decision"><strong>Division Head approves PPMP?</strong></div><span class="yes-tag">Yes</span><span class="no-tag">No</span></div>
   <div class="flow-node process"><strong>Budget Section review</strong><span>Review the PPMP and issue budget allocation decision.</span></div>
   <div class="connector"></div>
   <div class="decision-row"><div class="connector"></div><div class="decision"><strong>Budget Officer approves?</strong></div><span class="yes-tag">Yes</span><span class="no-tag">No</span></div>
   <div class="flow-node process"><strong>PPMP approved</strong><span>Return to End-user and include approved items in the APP.</span></div>
   <div class="connector"></div>
   <div class="flow-node final"><strong>Approved PPMP items included in the APP</strong></div>
   <div class="connector"></div>
   <div class="flow-node"><strong>Create Purchase Request (PR)</strong><span>Select approved APP items; route to the Budget Office for CAF issuance.</span></div>
   <div class="connector"></div>
   <div class="flow-node process"><strong>Budget Office issues CAF</strong><span>Certificate of Availability of Funds; forward PR to the Head of the Procuring Entity (HoPE).</span></div>
   <div class="connector"></div>
   <div class="decision-row"><div class="connector"></div><div class="decision"><strong>Does HoPE approve the PR?</strong></div><span class="yes-tag">Yes</span><span class="no-tag">No</span></div>
   <div class="flow-node process"><strong>Procurement Section &amp; BAC review</strong><span>Verify documentary requirements and prepare the bid opportunity, if applicable.</span></div>
   <div class="connector"></div>
   <div class="flow-node final"><strong>Proceed to bid evaluation</strong><span>Continue to the next decision stage.</span></div>
   <div class="connector"></div>
   <div class="flow-node final"><strong>BAC recommendation submitted to HoPE</strong></div>
   <div class="connector"></div>
   <div class="flow-node process"><strong>HoPE reviews recommendation of award</strong><span>Decide whether to approve or disapprove the BAC recommendation.</span></div>
   <div class="connector"></div>
   <div class="decision-row"><div class="connector"></div><div class="decision"><strong>Does HoPE approve the recommendation?</strong></div><span class="yes-tag">Yes</span><span class="no-tag">No</span></div>
   <div class="flow-node process"><strong>Prepare PO / Contract &amp; NTP</strong><span>Procurement Section prepares the Purchase Order/Contract and Notice to Proceed for HoPE approval.</span></div>
   <div class="connector"></div>
   <div class="decision-row"><div class="connector"></div><div class="decision"><strong>Does HoPE approve the recommendation?</strong></div><span class="yes-tag">Yes</span><span class="no-tag">No</span></div>
   <div class="flow-node process"><strong>Issue approved contract</strong><span>Send approved copies to the winning bidder; obligations may begin under the contract terms.</span></div>
   <div class="connector"></div>
   <div class="flow-node final"><strong>Recordkeeping</strong><span>Procurement Section retains approved PO/Contract and NTP copies.</span></div>
  </div>
 </div>
</div>
<?php pageEnd(); ?>
