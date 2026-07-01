<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lissajous');

$pageName = 'lissajous';
$pageTitle = "LISSAJOUS - PULSE";
$pageDesc = json_decode('"\u4e8c\u3064\u306e\u632f\u52d5\u306e\u4f4d\u76f8\u3068\u5468\u6ce2\u6570\u304c\u63cf\u304f\u30ea\u30b5\u30b8\u30e5\u30fc\u66f2\u7dda\u306eCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/lissajous.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">A:B <b id="h-ratio">3:2</b><br>PHASE <b id="h-phase">0.70</b><br>TRAIL <b id="h-trail">420</b><br><span class="sub">STYLE</span> <b id="h-mode">TRACE</b></div>
<div class="mark">LISSAJOUS</div>
<?php $interactionHints=[['type'=>'drag','label'=>'phase'],['type'=>'click','label'=>'pulse']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="trace">TRACE</button><button class="control-btn" data-mode="mesh">MESH</button><button class="control-btn" data-mode="orbit">ORBIT</button></div><div class="slider-row"><span class="slider-label">FREQ A</span><input type="range" id="sl-a" min="1" max="9" value="3"><span class="slider-value" id="sv-a">3</span></div><div class="slider-row"><span class="slider-label">FREQ B</span><input type="range" id="sl-b" min="1" max="9" value="2"><span class="slider-value" id="sv-b">2</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,a=3,b=2,phase=.7,mode='trace',run=true,t=0,pulse=0;
var hr=document.getElementById('h-ratio'),hp=document.getElementById('h-phase'),ht=document.getElementById('h-trail'),hm=document.getElementById('h-mode'),sa=document.getElementById('sl-a'),sb=document.getElementById('sl-b'),va=document.getElementById('sv-a'),vb=document.getElementById('sv-b');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}
function pos(u,r){return [W/2+Math.sin(a*u+phase)*r,H/2+Math.sin(b*u)*r]}
function draw(){if(run)t+=.012;x.fillStyle='rgba(10,10,15,.2)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var r=Math.min(W,H)*(.34+pulse*.04),n=mode==='mesh'?160:520,prev=null;for(var i=0;i<n;i++){var u=t+i/n*Math.PI*2,p=pos(u,r),q=i/n;if(mode==='orbit'){x.fillStyle=i%2?'rgba(123,44,191,.18)':'rgba(0,245,212,.20)';x.beginPath();x.arc(p[0],p[1],(2+q*5)*D,0,Math.PI*2);x.fill()}else{if(prev){x.strokeStyle=i%2?'rgba(123,44,191,'+(.12+q*.32)+')':'rgba(0,245,212,'+(.14+q*.36)+')';x.lineWidth=(mode==='mesh'?1.0:.8)*D;x.beginPath();x.moveTo(prev[0],prev[1]);x.lineTo(p[0],p[1]);x.stroke()}if(mode==='mesh'&&i%8===0){var p2=pos(t+(i*3%n)/n*Math.PI*2,r);x.strokeStyle='rgba(123,44,191,.13)';x.beginPath();x.moveTo(p[0],p[1]);x.lineTo(p2[0],p2[1]);x.stroke()}prev=p}}if(pulse>0)pulse*=.94;x.globalCompositeOperation='source-over';hud(n);requestAnimationFrame(draw)}
function hud(n){hr.textContent=a+':'+b;hp.textContent=phase.toFixed(2);ht.textContent=n||0;hm.textContent=mode.toUpperCase();va.textContent=a;vb.textContent=b}
c.addEventListener('pointerdown',function(){pulse=1;if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons){phase+=((e.movementX||0)+(e.movementY||0))*.006;if(window.SFX)SFX.play('move',130)}});document.querySelectorAll('[data-mode]').forEach(function(btn){btn.onclick=function(){mode=btn.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===btn)});if(window.SFX)SFX.play('toggle')}});sa.oninput=function(){a=+sa.value;hud()};sb.oninput=function(){b=+sb.value;hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){a=3;b=2;phase=.7;sa.value=3;sb.value=2;if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
