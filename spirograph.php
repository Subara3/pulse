<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('spirograph');

$pageName = 'spirograph';
$pageTitle = "SPIROGRAPH - PULSE";
$pageDesc = json_decode('"\u56de\u8ee2\u3059\u308b\u5186\u3068\u30da\u30f3\u306e\u4f4d\u7f6e\u304c\u63cf\u304f\u30b9\u30d4\u30ed\u30b0\u30e9\u30d5\u66f2\u7dda\u306eCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/spirograph.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">RATIO <b id="h-ratio">5:3</b><br>OFFSET <b id="h-offset">0.62</b><br>POINTS <b id="h-points">0</b><br><span class="sub">FORM</span> <b id="h-mode">HYPO</b></div>
<div class="mark">SPIROGRAPH</div>
<?php $interactionHints=[['type'=>'drag','label'=>'offset'],['type'=>'click','label'=>'pulse']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="hypo">HYPO</button><button class="control-btn" data-mode="epi">EPI</button><button class="control-btn" data-mode="rose">ROSE</button></div><div class="slider-row"><span class="slider-label">RING</span><input type="range" id="sl-a" min="3" max="12" value="5"><span class="slider-value" id="sv-a">5</span></div><div class="slider-row"><span class="slider-label">GEAR</span><input type="range" id="sl-b" min="1" max="9" value="3"><span class="slider-value" id="sv-b">3</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">DRAW</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,A=5,B=3,off=.62,mode='hypo',run=true,t=0,pts=[];
var hr=document.getElementById('h-ratio'),ho=document.getElementById('h-offset'),hp=document.getElementById('h-points'),hm=document.getElementById('h-mode'),sa=document.getElementById('sl-a'),sb=document.getElementById('sl-b'),va=document.getElementById('sv-a'),vb=document.getElementById('sv-b');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';pts=[]}function point(u){var R=Math.min(W,H)*.22,r=R*B/A,d=R*off;if(mode==='epi'){return [W/2+(R+r)*Math.cos(u)-d*Math.cos((R+r)/r*u),H/2+(R+r)*Math.sin(u)-d*Math.sin((R+r)/r*u)]}if(mode==='rose'){return [W/2+Math.cos(A*u)*Math.cos(B*u)*R*2.3*off,H/2+Math.cos(A*u)*Math.sin(B*u)*R*2.3*off]}return [W/2+(R-r)*Math.cos(u)+d*Math.cos((R-r)/r*u),H/2+(R-r)*Math.sin(u)-d*Math.sin((R-r)/r*u)]}
function draw(){if(run){for(var k=0;k<5;k++){pts.push(point(t));t+=.025}while(pts.length>2400)pts.shift()}x.fillStyle='rgba(10,10,15,.18)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';x.beginPath();for(var i=0;i<pts.length;i++){if(i)x.lineTo(pts[i][0],pts[i][1]);else x.moveTo(pts[i][0],pts[i][1])}x.strokeStyle='rgba(0,245,212,.58)';x.lineWidth=1.1*D;x.stroke();for(var j=0;j<pts.length;j+=24){x.fillStyle=j%48?'rgba(123,44,191,.25)':'rgba(0,245,212,.32)';x.beginPath();x.arc(pts[j][0],pts[j][1],2.2*D,0,Math.PI*2);x.fill()}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}function hud(){hr.textContent=A+':'+B;ho.textContent=off.toFixed(2);hp.textContent=pts.length;hm.textContent=mode.toUpperCase();va.textContent=A;vb.textContent=B}c.addEventListener('pointerdown',function(){pts=[];if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons){off=Math.max(.15,Math.min(1.4,off+(e.movementX||0)*.002));pts=[];if(window.SFX)SFX.play('move',140)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;pts=[];document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});sa.oninput=function(){A=+sa.value;pts=[];hud()};sb.oninput=function(){B=+sb.value;pts=[];hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){A=5;B=3;off=.62;sa.value=5;sb.value=3;pts=[];t=0;if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
