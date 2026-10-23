<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-tree{max-width:1180px;margin:0 auto;color:#263b53;font-family:inherit}
.proc-tree .flowchart-canvas{padding:32px 26px 44px;border:1px solid #dbe4ef;border-radius:18px;background:linear-gradient(145deg,#fff,#f7f9fc);box-shadow:0 14px 38px rgba(27,51,82,.09);overflow:hidden}
.proc-tree .tree-intro{text-align:center;max-width:850px;margin:0 auto 26px}
.proc-tree .tree-intro h2{font-size:26px;letter-spacing:-.4px;color:#203c5b;margin:0 0 8px;font-weight:780}
.proc-tree .tree-intro p{font-size:14px;line-height:1.65;color:#718096;margin:0}
.proc-tree .return-hub{position:relative;z-index:2;max-width:420px;margin:0 auto 28px;padding:13px 18px;border:1px solid #d6a49b;border-radius:13px;background:linear-gradient(135deg,#fff2ef,#fff);text-align:center;box-shadow:0 5px 16px rgba(143,67,55,.08)}
.proc-tree .return-hub:before{content:"RETURN PATH";display:block;margin-bottom:5px;font-size:9px;letter-spacing:.17em;font-weight:800;color:#ad6157}
.proc-tree .return-hub strong{display:block;font-size:14px;color:#963e35}
.proc-tree .return-hub span{display:block;margin-top:4px;font-size:12px;color:#946e68}
.proc-tree .flow{position:relative;max-width:760px;margin:0 auto;padding:0 70px 0 0}
.proc-tree .node{position:relative;z-index:1;box-sizing:border-box;width:min(100%,520px);min-height:62px;margin:0 auto;padding:13px 18px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border:1.5px solid #5a83b7;border-radius:13px;background:#d8e7f6;box-shadow:0 4px 11px rgba(45,79,120,.06);line-height:1.45}
.proc-tree .node strong{font-size:14px;color:#28527f;font-weight:750}
.proc-tree .node span{font-size:12px;line-height:1.5;color:#61748a;margin-top:5px}
.proc-tree .node.process{border-radius:5px;border-color:#c3a33d;background:#fff6c9;box-shadow:0 3px 8px rgba(132,105,29,.06)}
.proc-tree .node.process strong{color:#765719}
.proc-tree .node.success{border-color:#78ae8b;background:#edf8f0}
.proc-tree .node.success strong{color:#276b43}
.proc-tree .connector{height:28px;width:2px;background:#6688b7;margin:0 auto;position:relative;z-index:1}
.proc-tree .connector:after{content:"";position:absolute;left:-4px;bottom:0;width:8px;height:8px;border-right:2px solid #6688b7;border-bottom:2px solid #6688b7;transform:rotate(45deg);background:transparent}
.proc-tree .decision-wrap{height:132px;position:relative;display:flex;justify-content:center;align-items:center;z-index:1}
.proc-tree .decision-wrap:before{content:"";position:absolute;top:0;left:50%;height:16px;width:2px;background:#6688b7}
.proc-tree .decision-wrap:after{content:"";position:absolute;bottom:0;left:50%;height:16px;width:2px;background:#6688b7}
.proc-tree .diamond{width:min(100%,380px);height:100px;display:flex;align-items:center;justify-content:center;position:relative;box-sizing:border-box;padding:20px 60px;background:#456b9e;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);filter:drop-shadow(0 3px 3px rgba(35,62,99,.12))}
.proc-tree .diamond:before{content:"";position:absolute;inset:1.5px;background:#fff4cf;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%)}
.proc-tree .diamond strong{position:relative;z-index:1;max-width:180px;color:#75551d;font-size:13px;line-height:1.4;text-align:center}
.proc-tree .decision-wrap .yes{position:absolute;z-index:2;bottom:-9px;left:calc(50% + 32px);padding:5px 11px;border:1px solid #477e4a;border-radius:20px;background:#e0f2d9;color:#2c6634;font-size:10px;font-weight:800;letter-spacing:.05em}
.proc-tree .decision-wrap .no{position:absolute;z-index:2;right:-60px;top:50%;transform:translateY(-50%);padding:6px 11px;border:1px solid #b85b54;border-radius:20px;background:#fff0ee;color:#a43c35;font-size:10px;font-weight:800;letter-spacing:.05em}
.proc-tree .decision-wrap .no:before{content:"";position:absolute;right:100%;top:50%;width:35px;border-top:2px solid #6688b7}
.proc-tree .return-rail{position:absolute;z-index:0;right:5px;top:180px;bottom:35px;width:2px;background:#c16f66}
.proc-tree .return-rail:before{content:"";position:absolute;top:-9px;left:-4px;width:8px;height:8px;border-left:2px solid #c16f66;border-top:2px solid #c16f66;transform:rotate(45deg)}
.proc-tree .return-rail:after{content:"NO → RETURN";position:absolute;right:-5px;top:48%;transform:translateX(50%) rotate(90deg);transform-origin:center;background:#fff7f5;color:#a64d44;border:1px solid #e5c1bc;border-radius:10px;padding:5px 8px;white-space:nowrap;font-size:9px;letter-spacing:.1em;font-weight:800}
.proc-tree .stage-note{max-width:520px;margin:0 auto;text-align:center;color:#728198;font-size:11px}
@media(max-width:760px){
 .proc-tree .flowchart-canvas{padding:20px 10px 30px}
 .proc-tree .tree-intro h2{font-size:21px}
 .proc-tree .flow{padding-right:34px}
 .proc-tree .return-rail{right:3px;opacity:.7}
 .proc-tree .decision-wrap .no{right:-34px;padding:5px 7px;font-size:9px}
 .proc-tree .decision-wrap .no:before{width:15px}
 .proc-tree .decision-wrap .yes{left:calc(50% + 20px)}
 .proc-tree .diamond{height:94px;padding:17px 46px}
 .proc-tree .node{padding:11px}
}
@media print{
 .proc-tree .flowchart-canvas{box-shadow:none;border:0;padding:8px;overflow:visible}
 .proc-tree .node,.proc-tree .diamond,.proc-tree .return-hub{break-inside:avoid;print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
<div class="proc-tree">
 <div class="flowchart-canvas">
  <div class="tree-intro">
   <h2>Procurement Process Flowchart</h2>
   <p>A clear, connected view of PPMP review, budget approval, procurement, award recommendation, contract approval, and recordkeeping.</p>
  </div>
  <div class="return-hub" id="return-to-end-user"><strong>Return to End-user</strong><span>Revise the submission and resubmit it for review.</span></div>
  <div class="flow">
   <div class="return-rail" aria-hidden="true"></div>
   <div class="node success"><strong>End-user prepares PPMP</strong><span>Prepare the PPMP and submit it to the Division Head.</span></div>
   <div class="connector"></div>
   <div class="decision-wrap"><div class="diamond"><strong>Division Head approves PPMP?</strong></div><span class="yes">YES</span><span class="no">NO</span></div>
   <div class="node process"><strong>Budget Section review</strong><span>Review the PPMP and issue the budget allocation decision.</span></div>
   <div class="connector"></div>
   <div class="decision-wrap"><div class="diamond"><strong>Budget Officer approves?</strong></div><span class="yes">YES</span><span class="no">NO</span></div>
   <div class="node success"><strong>PPMP approved</strong><span>Approved items are included in the APP.</span></div>
   <div class="connector"></div>
   <div class="node success"><strong>Approved PPMP items included in the APP</strong></div>
   <div class="connector"></div>
   <div class="node"><strong>Create Purchase Request (PR)</strong><span>Select approved APP items and route the PR to the Budget Office.</span></div>
   <div class="connector"></div>
   <div class="node process"><strong>Budget Office issues CAF</strong><span>Certificate of Availability of Funds; forward the PR to the Head of the Procuring Entity (HoPE).</span></div>
   <div class="connector"></div>
   <div class="decision-wrap"><div class="diamond"><strong>Does HoPE approve the PR?</strong></div><span class="yes">YES</span><span class="no">NO</span></div>
   <div class="node process"><strong>Procurement Section &amp; BAC review</strong><span>Check documentary requirements and prepare the procurement process.</span></div>
   <div class="connector"></div>
   <div class="node success"><strong>Proceed to bid evaluation</strong><span>Evaluate bids and complete the applicable review.</span></div>
   <div class="connector"></div>
   <div class="node success"><strong>BAC recommendation submitted to HoPE</strong></div>
   <div class="connector"></div>
   <div class="node process"><strong>HoPE reviews recommendation of award</strong><span>Review the BAC recommendation and decide whether to approve it.</span></div>
   <div class="connector"></div>
   <div class="decision-wrap"><div class="diamond"><strong>Does HoPE approve the recommendation?</strong></div><span class="yes">YES</span><span class="no">NO</span></div>
   <div class="node process"><strong>Prepare PO / Contract &amp; NTP</strong><span>Prepare the Purchase Order/Contract and Notice to Proceed for approval.</span></div>
   <div class="connector"></div>
   <div class="decision-wrap"><div class="diamond"><strong>Does HoPE approve the contract?</strong></div><span class="yes">YES</span><span class="no">NO</span></div>
   <div class="node success"><strong>Issue approved contract</strong><span>Send approved copies to the winning bidder.</span></div>
   <div class="connector"></div>
   <div class="node success"><strong>Recordkeeping</strong><span>Retain approved PO/Contract and NTP copies in the procurement records.</span></div>
   <div class="stage-note">NO branches return to the End-user via the highlighted return path.</div>
  </div>
 </div>
</div>
<?php pageEnd(); ?>
