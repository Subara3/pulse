<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('string');

$pageName = 'string';
$pageTitle = "STRING ART - PULSE";
$pageDesc = json_decode('"\u5186\u5468\u4e0a\u306e\u70b9\u3092\u6570\u5f0f\u3067\u7d50\u3073\u3001\u7cf8\u306e\u5bc6\u5ea6\u304c\u66f2\u7dda\u3092\u63cf\u304fCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/string.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">POINTS <b id="h-points">160</b><br>STEP <b id="h-step">67</b><br>LINES <b id="h-lines">0</b><br><span class="sub">FORM</span> <b id="h-mode">ROSE</b></div>
<div class="mark">STRING ART</div>
<?php $interactionHints=[['type'=>'drag','label'=>'step'],['type'=>'click','label'=>'burst']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="rose">ROSE</button><button class="control-btn" data-mode="cardioid">CARDIOID</button><button class="control-btn" data-mode="weave">WEAVE</button></div><div class="slider-row"><span class="slider-label">POINTS</span><input type="range" id="sl-points" min="48" max="260" value="160"><span class="slider-value" id="sv-points">160</span></div><div class="slider-row"><span class="slider-label">STEP</span><input type="range" id="sl-step" min="2" max="140" value="67"><span class="slider-value" id="sv-step">67</span></div><div class="btn-row"><button class="control-btn active" id="btn-draw">DRAW</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,points=160,step=67,mode='rose',drawOn=true,phase=0,burst=0;
var hp=document.getElementById('h-points'),hs=document.getElementById('h-step'),hl=document.getElementById('h-lines'),hm=document.getElementById('h-mode'),sp=document.getElementById('sl-points'),ss=document.getElementById('sl-step'),vp=document.getElementById('sv-points'),vs=document.getElementById('sv-step');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}
function pt(i,r,cx,cy){var a=i/points*Math.PI*2+phase;return [cx+Math.cos(a)*r,cy+Math.sin(a)*r]}
function draw(){if(drawOn)phase+=.002;x.fillStyle='rgba(10,10,15,.2)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var cx=W/2,cy=H/2,r=Math.min(W,H)*(.34+burst*.05),lines=Math.min(points*2,420);for(var i=0;i<lines;i++){var j=mode==='cardioid'?i*2:mode==='weave'?i*step+Math.sin(i*.07+phase)*points*.1:i*step;var a=pt(i%points,r,cx,cy),b=pt(((j%points)+points)%points,r,cx,cy),q=i/lines;x.strokeStyle=i%2?'rgba(123,44,191,'+(.08+q*.32)+')':'rgba(0,245,212,'+(.10+q*.38)+')';x.lineWidth=(.6+q*.8)*D;x.beginPath();x.moveTo(a[0],a[1]);x.lineTo(b[0],b[1]);x.stroke()}if(burst>0)burst*=.94;x.globalCompositeOperation='source-over';hud(lines);requestAnimationFrame(draw)}
function hud(lines){hp.textContent=points;hs.textContent=step;hl.textContent=lines||0;hm.textContent=mode.toUpperCase();vp.textContent=points;vs.textContent=step}
c.addEventListener('pointerdown',function(){burst=1;if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons){step=Math.max(2,Math.min(140,step+(e.movementX||0)*.1));ss.value=Math.round(step);hud();if(window.SFX)SFX.play('move',130)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});sp.oninput=function(){points=+sp.value;hud()};ss.oninput=function(){step=+ss.value;hud()};document.getElementById('btn-draw').onclick=function(e){drawOn=!drawOn;e.currentTarget.classList.toggle('active',drawOn);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){points=160;step=67;sp.value=160;ss.value=67;phase=0;if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
