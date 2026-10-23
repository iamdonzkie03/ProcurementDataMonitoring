<?php
require_once __DIR__.'/../config/config.php';
requireRole(['Administrator','Editor','Viewer','Guest']);
require_once __DIR__.'/../app/layout.php';
pageStart('Procurement Process Workflow');
?>
<style>
.proc-tree{max-width:1450px;margin:0 auto;color:#263b53;font-family:inherit}
.proc-tree .flowchart-canvas{padding:28px;border:1px solid #dce5ef;border-radius:18px;background:linear-gradient(145deg,#fff 0%,#f7f9fc 100%);box-shadow:0 14px 38px rgba(27,51,82,.09)}
.proc-tree .tree-intro{text-align:center;margin:0 auto 22px;max-width:850px}
.proc-tree .tree-intro h2{font-size:26px;letter-spacing:-.4px;color:#203c5b;margin:0 0 8px;font-weight:780}
.proc-tree .tree-intro p{font-size:14px;line-height:1.65;color:#718096;margin:0}
.proc-tree .svg-scroll{width:100%;overflow-x:auto;padding:4px 0 10px}
.proc-tree .flow-svg{display:block;width:100%;height:auto;min-width:800px;margin:0 auto}
.proc-tree .flow-svg .connector{fill:none;stroke:#577ca9;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;marker-end:url(#arrow)}
.proc-tree .flow-svg .return-connector{fill:none;stroke:#bb6d63;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;marker-end:url(#return-arrow);stroke-dasharray:7 5}
.proc-tree .flow-svg .process{fill:#d8e7f6;stroke:#527ba9;stroke-width:2}
.proc-tree .flow-svg .process-yellow{fill:#fff5c7;stroke:#b99a3b;stroke-width:2}
.proc-tree .flow-svg .success{fill:#edf7ef;stroke:#6ca47e;stroke-width:2}
.proc-tree .flow-svg .decision{fill:#fff3ce;stroke:#b99a3b;stroke-width:2.5}
.proc-tree .flow-svg .return-box{fill:#fff1ee;stroke:#c47c72;stroke-width:2}
.proc-tree .flow-svg .label{font-family:Arial,Helvetica,sans-serif;font-size:17px;font-weight:700;fill:#294b73;text-anchor:middle}
.proc-tree .flow-svg .small{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:400;fill:#5d7189;text-anchor:middle}
.proc-tree .flow-svg .decision-text{font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;fill:#74571e;text-anchor:middle}
.proc-tree .flow-svg .return-text{font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;fill:#963f37;text-anchor:middle}
.proc-tree .flow-svg .branch{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:800;letter-spacing:1px;text-anchor:middle}
.proc-tree .flow-svg .yes{fill:#e0f2d9;stroke:#5c9a57;stroke-width:1.5}
.proc-tree .flow-svg .no{fill:#ffefed;stroke:#bd665d;stroke-width:1.5}
.proc-tree .flow-svg .yes-text{fill:#2c7139}
.proc-tree .flow-svg .no-text{fill:#a33f37}
@media(max-width:760px){.proc-tree .flowchart-canvas{padding:16px 10px}.proc-tree .tree-intro h2{font-size:21px}.proc-tree .flow-svg{min-width:780px}}
@media print{.proc-tree .flowchart-canvas{box-shadow:none;border:0;padding:0}.proc-tree .svg-scroll{overflow:visible}.proc-tree .flow-svg{min-width:0;width:100%;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
<div class="proc-tree">
 <div class="flowchart-canvas">
  <div class="tree-intro">
   <h2>Procurement Process Flowchart</h2>
   <p>Flowchart recreated from the supplied Excel file, preserving its process wording, decisions, outcomes, and branching paths.</p>
  </div>
  <div class="svg-scroll">
   <svg class="flow-svg" viewBox="0 0 1200 2370" role="img" aria-label="Procurement process flowchart matching the supplied Excel file">
    <defs>
     <marker id="arrow" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#577ca9"/></marker>
     <marker id="return-arrow" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#bb6d63"/></marker>
     <filter id="soft-shadow" x="-15%" y="-15%" width="130%" height="140%"><feDropShadow dx="0" dy="3" stdDeviation="3" flood-color="#304a69" flood-opacity=".10"/></filter>
    </defs>
    <!-- Return destination -->
    <rect class="return-box" x="435" y="24" width="330" height="66" rx="14" filter="url(#soft-shadow)"/>
    <text class="return-text" x="600" y="63">Return to End-user</text>

    <!-- Stage 1: PPMP review -->
    <rect class="success" x="390" y="130" width="420" height="78" rx="15" filter="url(#soft-shadow)"/>
    <text class="label" x="600" y="161"><tspan x="600">End-user prepares PPMP</tspan></text>
    <text class="small" x="600" y="185"><tspan x="600">Create the PPMP and submit it to the Division Head.</tspan></text>
    <path class="connector" d="M600 208 V250"/>
    <polygon class="decision" points="600,250 795,315 600,380 405,315" filter="url(#soft-shadow)"/>
    <text class="decision-text" x="600" y="309"><tspan x="600">Division Head approves</tspan><tspan x="600" dy="21">PPMP?</tspan></text>
    <rect class="yes" x="670" y="366" width="70" height="28" rx="14"/><text class="branch yes-text" x="705" y="385">YES</text>
    <rect class="no" x="816" y="301" width="62" height="28" rx="14"/><text class="branch no-text" x="847" y="320">NO</text>
    <path class="connector" d="M600 380 V420"/>
    <rect class="process-yellow" x="390" y="420" width="420" height="76" rx="4"/>
    <text class="label" x="600" y="450"><tspan x="600">Budget Section review</tspan></text>
    <text class="small" x="600" y="474"><tspan x="600">Review the PPMP and issue budget allocation decision.</tspan></text>
    <path class="connector" d="M600 496 V535"/>
    <polygon class="decision" points="600,535 785,595 600,655 415,595" filter="url(#soft-shadow)"/>
    <text class="decision-text" x="600" y="590"><tspan x="600">Budget Officer</tspan><tspan x="600" dy="21">approves?</tspan></text>
    <rect class="yes" x="670" y="640" width="70" height="28" rx="14"/><text class="branch yes-text" x="705" y="659">YES</text>
    <rect class="no" x="816" y="581" width="62" height="28" rx="14"/><text class="branch no-text" x="847" y="600">NO</text>
    <path class="connector" d="M600 655 V690"/>
    <rect class="success" x="390" y="690" width="420" height="78" rx="15" filter="url(#soft-shadow)"/>
    <text class="label" x="600" y="721"><tspan x="600">PPMP approved</tspan></text>
    <text class="small" x="600" y="745"><tspan x="600">Return to End-user and include approved items in the APP.</tspan></text>

    <!-- Exact return branches from the Excel decision paths -->
    <path class="return-connector" d="M878 315 H1015 V57 H765"/>
    <text class="branch no-text" x="1015" y="288" transform="rotate(-90 1015 288)">NO</text>
    <rect class="return-box" x="885" y="395" width="255" height="76" rx="9"/>
    <text class="return-text" x="1012" y="423"><tspan x="1012">Division Head</tspan><tspan x="1012" dy="21">disapproves the PPMP.</tspan></text>
    <path class="return-connector" d="M1012 395 V57 H765"/>
    <path class="return-connector" d="M878 595 H1080 V57 H765"/>
    <text class="branch no-text" x="1080" y="568" transform="rotate(-90 1080 568)">NO</text>

    <!-- Continue approved plan into APP / PR -->
    <path class="connector" d="M600 768 V815"/>
    <rect class="success" x="390" y="815" width="420" height="64" rx="14"/>
    <text class="label" x="600" y="842"><tspan x="600">Approved PPMP items included in the APP</tspan></text>
    <path class="connector" d="M600 879 V915"/>
    <rect class="process" x="390" y="915" width="420" height="76" rx="14"/>
    <text class="label" x="600" y="945"><tspan x="600">Create Purchase Request (PR)</tspan></text>
    <text class="small" x="600" y="969"><tspan x="600">Select approved APP items; route to the Budget Office for CAF issuance.</tspan></text>
    <path class="connector" d="M600 991 V1027"/>
    <rect class="process-yellow" x="390" y="1027" width="420" height="78" rx="4"/>
    <text class="label" x="600" y="1057"><tspan x="600">Budget Office issues CAF</tspan></text>
    <text class="small" x="600" y="1081"><tspan x="600">Certificate of Availability of Funds; forward PR to the</tspan><tspan x="600" dy="17">Head of the Procuring Entity (HoPE).</tspan></text>
    <path class="connector" d="M600 1105 V1140"/>
    <polygon class="decision" points="600,1140 795,1205 600,1270 405,1205" filter="url(#soft-shadow)"/>
    <text class="decision-text" x="600" y="1199"><tspan x="600">Does HoPE approve</tspan><tspan x="600" dy="21">the PR?</tspan></text>
    <rect class="yes" x="670" y="1255" width="70" height="28" rx="14"/><text class="branch yes-text" x="705" y="1274">YES</text>
    <rect class="no" x="816" y="1191" width="62" height="28" rx="14"/><text class="branch no-text" x="847" y="1210">NO</text>
    <path class="return-connector" d="M878 1205 H1148 V57 H765"/>
    <path class="connector" d="M600 1270 V1307"/>
    <rect class="process-yellow" x="390" y="1307" width="420" height="78" rx="4"/>
    <text class="label" x="600" y="1337"><tspan x="600">Procurement Section &amp; BAC review</tspan></text>
    <text class="small" x="600" y="1361"><tspan x="600">Verify documentary requirements and prepare the bid opportunity,</tspan><tspan x="600" dy="17">if applicable.</tspan></text>
    <path class="connector" d="M600 1385 V1420"/>
    <rect class="success" x="390" y="1420" width="420" height="72" rx="14"/>
    <text class="label" x="600" y="1450"><tspan x="600">Proceed to bid evaluation</tspan></text>
    <text class="small" x="600" y="1474"><tspan x="600">Continue to the next decision stage.</tspan></text>
    <path class="connector" d="M600 1492 V1527"/>
    <rect class="success" x="390" y="1527" width="420" height="62" rx="14"/>
    <text class="label" x="600" y="1563"><tspan x="600">BAC recommendation submitted to HoPE</tspan></text>
    <path class="connector" d="M600 1589 V1622"/>
    <rect class="process-yellow" x="390" y="1622" width="420" height="78" rx="4"/>
    <text class="label" x="600" y="1652"><tspan x="600">HoPE reviews recommendation of award</tspan></text>
    <text class="small" x="600" y="1676"><tspan x="600">Decide whether to approve or disapprove the BAC recommendation.</tspan></text>
    <path class="connector" d="M600 1700 V1734"/>
    <polygon class="decision" points="600,1734 795,1799 600,1864 405,1799" filter="url(#soft-shadow)"/>
    <text class="decision-text" x="600" y="1793"><tspan x="600">Does HoPE approve</tspan><tspan x="600" dy="21">the recommendation?</tspan></text>
    <rect class="yes" x="670" y="1849" width="70" height="28" rx="14"/><text class="branch yes-text" x="705" y="1868">YES</text>
    <rect class="no" x="816" y="1785" width="62" height="28" rx="14"/><text class="branch no-text" x="847" y="1804">NO</text>
    <path class="return-connector" d="M878 1799 H1170 V57 H765"/>
    <path class="connector" d="M600 1864 V1900"/>
    <rect class="process-yellow" x="390" y="1900" width="420" height="78" rx="4"/>
    <text class="label" x="600" y="1930"><tspan x="600">Prepare PO / Contract &amp; NTP</tspan></text>
    <text class="small" x="600" y="1954"><tspan x="600">Procurement Section prepares the Purchase Order/Contract and</tspan><tspan x="600" dy="17">Notice to Proceed for HoPE approval.</tspan></text>
    <path class="connector" d="M600 1978 V2012"/>
    <polygon class="decision" points="600,2012 795,2077 600,2142 405,2077" filter="url(#soft-shadow)"/>
    <text class="decision-text" x="600" y="2071"><tspan x="600">Does HoPE approve</tspan><tspan x="600" dy="21">the recommendation?</tspan></text>
    <rect class="yes" x="670" y="2127" width="70" height="28" rx="14"/><text class="branch yes-text" x="705" y="2146">YES</text>
    <rect class="no" x="816" y="2063" width="62" height="28" rx="14"/><text class="branch no-text" x="847" y="2082">NO</text>
    <path class="return-connector" d="M878 2077 H1188 V57 H765"/>
    <path class="connector" d="M600 2142 V2160"/>
    <rect class="success" x="390" y="2160" width="420" height="62" rx="14"/>
    <text class="label" x="600" y="2196"><tspan x="600">Issue approved contract</tspan></text>
    <path class="connector" d="M600 2222 V2250"/>
    <rect class="success" x="390" y="2250" width="420" height="68" rx="14"/>
    <text class="label" x="600" y="2279"><tspan x="600">Recordkeeping</tspan></text>
    <text class="small" x="600" y="2302"><tspan x="600">Procurement Section retains approved PO/Contract and NTP copies.</tspan></text>
    <path class="return-connector" d="M878 2077 H930 V2200"/>
    <rect class="return-box" x="850" y="2200" width="280" height="78" rx="9"/>
    <text class="return-text" x="990" y="2230"><tspan x="990">Project cancelled</tspan></text>
    <text class="small" x="990" y="2253"><tspan x="990">Contract disapproval cancels the project.</tspan></text>
   </svg>
  </div>
 </div>
</div>
<?php pageEnd(); ?>
