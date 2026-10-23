<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-infographic{max-width:1600px;margin:0 auto;color:#18324f;font-family:inherit}
.proc-infographic .poster{overflow:hidden;border:1px solid #d9e4f0;border-radius:22px;background:radial-gradient(ellipse at top,#fff 0%,#f2f7fc 58%,#e8f1fa 100%);box-shadow:0 18px 48px rgba(21,49,80,.12)}
.proc-infographic .poster-head{padding:25px 30px;background:linear-gradient(115deg,#102c50,#23578d);color:#fff;display:flex;align-items:center;gap:18px}
.proc-infographic .head-icon{flex:0 0 58px;height:58px;border-radius:16px;display:grid;place-items:center;background:rgba(255,255,255,.13);font-size:30px}
.proc-infographic .poster-head h2{margin:0;font-size:clamp(22px,3vw,32px);font-weight:800;letter-spacing:-.5px;color:#fff}
.proc-infographic .poster-head p{margin:6px 0 0;color:#d8e8fa;font-size:14px;line-height:1.5}
.proc-infographic .legend{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;padding:14px 18px 2px;color:#536b85;font-size:12px}
.proc-infographic .legend span{display:inline-flex;align-items:center;gap:7px}
.proc-infographic .dot{width:10px;height:10px;border-radius:3px;display:inline-block}
.proc-infographic .svg-scroll{overflow-x:auto;padding:20px 18px 34px}
.proc-infographic svg{display:block;width:100%;height:auto;min-width:1000px;margin:0 auto}
.proc-infographic .connector{fill:none;stroke:#5282b3;stroke-width:1.35;stroke-linecap:round;stroke-linejoin:round;marker-end:url(#arr)}
.proc-infographic .return-path{fill:none;stroke:#d34e61;stroke-width:1.25;stroke-linecap:round;stroke-linejoin:round;marker-end:url(#arrRed);stroke-dasharray:5 6}
.proc-infographic .card{stroke-width:2.2;filter:url(#shadow)}
.proc-infographic .blue{fill:#e4f1ff;stroke:#5d91c5}
.proc-infographic .gold{fill:#fff3c9;stroke:#d1a63b}
.proc-infographic .green{fill:#e3f6e8;stroke:#5baf79}
.proc-infographic .red{fill:#ffeaed;stroke:#d75e70}
.proc-infographic .decision{fill:#fff3c9;stroke:#c69b31;stroke-width:2.5;filter:url(#shadow)}
.proc-infographic .node-title{font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;fill:#1d456f;text-anchor:middle}
.proc-infographic .node-desc{font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:400;fill:#4f6780;text-anchor:middle}
.proc-infographic .decision-text{font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:800;fill:#76591e;text-anchor:middle}
.proc-infographic .branch{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:800;letter-spacing:.7px;text-anchor:middle}
.proc-infographic .yes-pill{fill:#18763e;stroke:#fff;stroke-width:2.5}
.proc-infographic .no-pill{fill:#b92740;stroke:#fff;stroke-width:2.5}
.proc-infographic .pill-text{font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:800;fill:#fff;text-anchor:middle;dominant-baseline:middle;paint-order:stroke;stroke-width:0}
.proc-infographic .icon-disc{fill:#fff;stroke:#d4e4f4;stroke-width:1.5}
@media(max-width:760px){.proc-infographic .poster-head{padding:18px 16px;align-items:flex-start}.proc-infographic .head-icon{flex-basis:44px;height:44px;font-size:23px}.proc-infographic .svg-scroll{padding:8px 5px 18px}.proc-infographic svg{min-width:1000px}}
@media print{.proc-infographic .poster{box-shadow:none;border:0}.proc-infographic .svg-scroll{overflow:visible}.proc-infographic svg{min-width:0;width:100%;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
<div class="proc-infographic">
 <section class="poster">
  <header class="poster-head">
   <div class="head-icon" aria-hidden="true">☑</div>
   <div><h2>Procurement Process Flowchart</h2><p>Follow the procurement pathway, decision points, return routes, and final contract outcomes.</p></div>
  </header>
  <div class="legend">
   <span><i class="dot" style="background:#dcecff;border:1px solid #5d91c5"></i>Process</span>
   <span><i class="dot" style="background:#fff0bc;border:1px solid #c69b31"></i>Decision</span>
   <span><i class="dot" style="background:#dff4e5;border:1px solid #5baf79"></i>Approved / next step</span>
   <span><i class="dot" style="background:#ffe5e9;border:1px solid #d75e70"></i>No / return / cancellation</span>
  </div>
  <div class="svg-scroll">
   <svg viewBox="0 0 1600 2436" role="img" aria-label="Procurement process flowchart based on the supplied Excel diagram">
    <defs>
     <marker id="arr" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 z" fill="#5282b3"/></marker>
     <marker id="arrRed" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 z" fill="#d34e61"/></marker>
     <filter id="shadow" x="-15%" y="-15%" width="130%" height="145%"><feDropShadow dx="0" dy="5" stdDeviation="5" flood-color="#294969" flood-opacity=".15"/></filter>
    </defs>
    <!-- Uniform sizing: every process/outcome card is 520px wide; decision diamonds span the same 520px width. -->
    <g transform="translate(0,-94)">
    <!-- End-user is the first step at the top; NO routes reconnect to this card. -->
    <path class="connector" d="M600 94 V122"/>
    <rect class="card blue" x="340" y="122" width="520" height="76" rx="16"/>
    <circle class="icon-disc" cx="382" cy="160" r="22"/><text x="382" y="167" text-anchor="middle" font-size="21" fill="#245c91">1</text>
    <text class="node-title" x="610" y="154">End-user prepares PPMP</text>
    <text class="node-desc" x="610" y="178">Create the PPMP and submit it to the Division Head.</text>
    <path class="connector" d="M600 198 V226"/>
    <polygon class="decision" points="600,226 860,294 600,362 340,294"/>
    <text class="decision-text" x="600" y="288"><tspan x="600">Division Head approves PPMP?</tspan></text>
    <path class="connector" d="M600 362 V390"/>
    <rect class="yes-pill" x="568" y="365" width="64" height="22" rx="11"/><text class="pill-text" x="600" y="376">YES</text>
    <path class="return-path" d="M860 294 H915 V162 H860"/>
    <rect class="no-pill" x="879" y="283" width="58" height="22" rx="11"/><text class="pill-text" x="908" y="294">NO</text>
    <rect class="card red" x="990" y="230" width="520" height="76" rx="16"/>
    <text class="node-title" x="1250" y="261" style="fill:#a62f44">Division Head disapproves the PPMP.</text>
    <text class="node-desc" x="1250" y="284">Return the PPMP to the End-user.</text>
    <path class="return-path" d="M1250 230 V162 H860"/>

    <!-- Budget review -->
    <rect class="card gold" x="340" y="390" width="520" height="76" rx="16"/>
    <circle class="icon-disc" cx="382" cy="428" r="22"/><text x="382" y="435" text-anchor="middle" font-size="21" fill="#8c6a16">2</text>
    <text class="node-title" x="610" y="422">Budget Section review</text>
    <text class="node-desc" x="610" y="446">Review the PPMP and issue budget allocation decision.</text>
    <path class="connector" d="M600 466 V494"/>
    <polygon class="decision" points="600,494 860,562 600,630 340,562"/>
    <text class="decision-text" x="600" y="556">Budget Officer approves?</text>
    <path class="connector" d="M600 630 V658"/>
    <rect class="yes-pill" x="568" y="633" width="64" height="22" rx="11"/><text class="pill-text" x="600" y="644">YES</text>
    <path class="return-path" d="M860 562 H930 V162 H860"/>
    <rect class="no-pill" x="879" y="551" width="58" height="22" rx="11"/><text class="pill-text" x="908" y="562">NO</text>
    <rect class="card green" x="340" y="658" width="520" height="76" rx="16"/>
    <text class="node-title" x="600" y="689">PPMP approved</text>
    <text class="node-desc" x="600" y="713">Return to End-user and include approved items in the APP.</text>

    <!-- APP and PR preparation -->
    <path class="connector" d="M600 734 V762"/>
    <rect class="card blue" x="340" y="762" width="520" height="76" rx="16"/>
    <circle class="icon-disc" cx="382" cy="800" r="22"/><text x="382" y="807" text-anchor="middle" font-size="21" fill="#245c91">3</text>
    <text class="node-title" x="610" y="793">Approved PPMP items included in the APP</text>
    <text class="node-desc" x="610" y="817">Use the approved PPMP items for APP planning.</text>
    <path class="connector" d="M600 838 V866"/>
    <rect class="card blue" x="340" y="866" width="520" height="76" rx="16"/>
    <circle class="icon-disc" cx="382" cy="904" r="22"/><text x="382" y="911" text-anchor="middle" font-size="21" fill="#245c91">4</text>
    <text class="node-title" x="610" y="897">Create Purchase Request (PR)</text>
    <text class="node-desc" x="610" y="921">Select approved APP items; route to the Budget Office for CAF issuance.</text>
    <path class="connector" d="M600 942 V970"/>
    <rect class="card gold" x="340" y="970" width="520" height="92" rx="16"/>
    <circle class="icon-disc" cx="382" cy="1016" r="22"/><text x="382" y="1023" text-anchor="middle" font-size="21" fill="#8c6a16">5</text>
    <text class="node-title" x="610" y="1002">Budget Office issues CAF</text>
    <text class="node-desc" x="610" y="1026">Certificate of Availability of Funds; forward PR to the</text>
    <text class="node-desc" x="610" y="1046">Head of the Procuring Entity (HoPE).</text>

    <!-- HoPE PR decision -->
    <path class="connector" d="M600 1062 V1090"/>
    <polygon class="decision" points="600,1090 860,1158 600,1226 340,1158"/>
    <text class="decision-text" x="600" y="1152">Does HoPE approve the PR?</text>
    <path class="connector" d="M600 1226 V1254"/>
    <rect class="yes-pill" x="568" y="1229" width="64" height="22" rx="11"/><text class="pill-text" x="600" y="1240">YES</text>
    <path class="return-path" d="M860 1158 H950 V162 H860"/>
    <rect class="no-pill" x="879" y="1147" width="58" height="22" rx="11"/><text class="pill-text" x="908" y="1158">NO</text>

    <!-- Procurement and BAC -->
    <rect class="card gold" x="340" y="1254" width="520" height="92" rx="16"/>
    <circle class="icon-disc" cx="382" cy="1300" r="22"/><text x="382" y="1307" text-anchor="middle" font-size="21" fill="#8c6a16">6</text>
    <text class="node-title" x="610" y="1286">Procurement Section &amp; BAC review</text>
    <text class="node-desc" x="610" y="1310">Verify documentary requirements and prepare the bid</text>
    <text class="node-desc" x="610" y="1330">opportunity, if applicable.</text>
    <path class="connector" d="M600 1346 V1374"/>
    <rect class="card blue" x="340" y="1374" width="520" height="76" rx="16"/>
    <circle class="icon-disc" cx="382" cy="1412" r="22"/><text x="382" y="1419" text-anchor="middle" font-size="21" fill="#245c91">7</text>
    <text class="node-title" x="610" y="1405">Proceed to bid evaluation</text>
    <text class="node-desc" x="610" y="1429">Continue to the next decision stage.</text>
    <path class="connector" d="M600 1450 V1478"/>
    <rect class="card blue" x="340" y="1478" width="520" height="76" rx="16"/>
    <circle class="icon-disc" cx="382" cy="1516" r="22"/><text x="382" y="1523" text-anchor="middle" font-size="21" fill="#245c91">8</text>
    <text class="node-title" x="610" y="1509">BAC recommendation submitted to HoPE</text>
    <text class="node-desc" x="610" y="1533">Submit the recommendation for HoPE review.</text>
    <path class="connector" d="M600 1554 V1582"/>
    <rect class="card gold" x="340" y="1582" width="520" height="92" rx="16"/>
    <circle class="icon-disc" cx="382" cy="1628" r="22"/><text x="382" y="1635" text-anchor="middle" font-size="21" fill="#8c6a16">9</text>
    <text class="node-title" x="610" y="1614">HoPE reviews recommendation of award</text>
    <text class="node-desc" x="610" y="1638">Decide whether to approve or disapprove the</text>
    <text class="node-desc" x="610" y="1658">BAC recommendation.</text>

    <!-- Award recommendation decision -->
    <path class="connector" d="M600 1674 V1702"/>
    <polygon class="decision" points="600,1702 860,1770 600,1838 340,1770"/>
    <text class="decision-text" x="600" y="1764"><tspan x="600">Does HoPE approve</tspan><tspan x="600" dy="22">the recommendation?</tspan></text>
    <path class="connector" d="M600 1838 V1866"/>
    <rect class="yes-pill" x="568" y="1841" width="64" height="22" rx="11"/><text class="pill-text" x="600" y="1852">YES</text>
    <path class="return-path" d="M860 1770 H970 V162 H860"/>
    <rect class="no-pill" x="879" y="1759" width="58" height="22" rx="11"/><text class="pill-text" x="908" y="1770">NO</text>
    <rect class="card red" x="990" y="1732" width="520" height="76" rx="16"/>
    <text class="node-title" x="1250" y="1763" style="fill:#a62f44">Return to End-user</text>
    <text class="node-desc" x="1250" y="1786">Recommendation not approved.</text>
    <path class="return-path" d="M1250 1732 V162 H860"/>

    <!-- PO / contract and final decision -->
    <rect class="card gold" x="340" y="1866" width="520" height="92" rx="16"/>
    <circle class="icon-disc" cx="382" cy="1912" r="22"/><text x="382" y="1919" text-anchor="middle" font-size="21" fill="#8c6a16">10</text>
    <text class="node-title" x="610" y="1898">Prepare PO / Contract &amp; NTP</text>
    <text class="node-desc" x="610" y="1922">Procurement Section prepares the Purchase Order/Contract</text>
    <text class="node-desc" x="610" y="1942">and Notice to Proceed for HoPE approval.</text>
    <path class="connector" d="M600 1958 V1986"/>
    <polygon class="decision" points="600,1986 860,2054 600,2122 340,2054"/>
    <text class="decision-text" x="600" y="2048"><tspan x="600">Does HoPE approve</tspan><tspan x="600" dy="22">the recommendation?</tspan></text>
    <path class="connector" d="M600 2122 V2150"/>
    <rect class="yes-pill" x="568" y="2125" width="64" height="22" rx="11"/><text class="pill-text" x="600" y="2136">YES</text>
    <path class="return-path" d="M860 2054 H970 V162 H860"/>
    <rect class="no-pill" x="879" y="2043" width="58" height="22" rx="11"/><text class="pill-text" x="908" y="2054">NO</text>
    <rect class="card red" x="990" y="2016" width="520" height="76" rx="16"/>
    <text class="node-title" x="1250" y="2047" style="fill:#a62f44">Project cancelled</text>
    <text class="node-desc" x="1250" y="2070">Contract disapproval cancels the project.</text>
    <path class="return-path" d="M1510 2054 H1550 V162 H860"/>

    <rect class="card green" x="340" y="2150" width="520" height="92" rx="16"/>
    <circle class="icon-disc" cx="382" cy="2196" r="22"/><text x="382" y="2203" text-anchor="middle" font-size="21" fill="#287448">✓</text>
    <text class="node-title" x="610" y="2182">Issue approved contract</text>
    <text class="node-desc" x="610" y="2206">Send approved copies to the winning bidder; obligations may</text>
    <text class="node-desc" x="610" y="2226">begin under the contract terms.</text>
    <path class="connector" d="M600 2242 V2270"/>
    <rect class="card blue" x="340" y="2270" width="520" height="92" rx="16"/>
    <circle class="icon-disc" cx="382" cy="2316" r="22"/><text x="382" y="2323" text-anchor="middle" font-size="21" fill="#245c91">▣</text>
    <text class="node-title" x="610" y="2302">Recordkeeping</text>
    <text class="node-desc" x="610" y="2326">Procurement Section retains approved PO/Contract</text>
    <text class="node-desc" x="610" y="2346">and NTP copies.</text>
       </g>
   </svg>
  </div>
 </section>
</div>
<?php pageEnd(); ?>
