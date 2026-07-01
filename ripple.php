<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ripple');

$pageName = 'ripple';
$pageTitle = "RIPPLE - PULSE";
$pageDesc = json_decode('"\u30af\u30ea\u30c3\u30af\u3068\u30c9\u30e9\u30c3\u30b0\u3067\u6ce2\u7d0b\u5834\u3092\u91cd\u306d\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/ripple.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">MODE <b id="h-mode">WATER</b><br>RIPPLES <b id="h-ripples">0</b><br>DAMP <b id="h-damp">0.96</b><br><span class="sub">ENERGY</span> <b id="h-energy">0.00</b></div>
<div class="mark">RIPPLE</div>
<?php $interactionHints=[['type'=>'click','label'=>'drop'],['type'=>'drag','label'=>'wake']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="water">WATER</button><button class="control-btn" data-mode="glass">GLASS</button><button class="control-btn" data-mode="field">FIELD</button></div><div class="slider-row"><span class="slider-label">DAMP</span><input type="range" id="sl-damp" min="88" max="99" value="96"><span class="slider-value" id="sv-damp">0.96</span></div><div class="slider-row"><span class="slider-label">SIZE</span><input type="range" id="sl-size" min="40" max="180" value="95"><span class="slider-value" id="sv-size">95</span></div><div class="btn-row"><button class="control-btn active" id="btn-flow">FLOW</button><button class="control-btn" id="btn-clear">CLEAR</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,mode='water',damp=.96,size=95,flow=true,t=0,rips=[];
var hm=document.getElementById('h-mode'),hr=document.getElementById('h-ripples'),hd=document.getElementById('h-damp'),he=document.getElementById('h-energy'),sd=document.getElementById('sl-damp'),ss=document.getElementById('sl-size'),vd=document.getElementById('sv-damp'),vs=document.getElementById('sv-size');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}function drop(x0,y0,p){rips.push({x:x0*D,y:y0*D,r:4*D,a:p||1,w:size*D});if(window.SFX)SFX.play('ripple',100)}
function draw(){t+=.016;if(flow&&Math.sin(t*1.7)>.995)drop(innerWidth*(.5+.35*Math.sin(t*.7)),innerHeight*(.5+.3*Math.cos(t*.5)),.55);x.fillStyle='rgba(10,10,15,.20)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var e=0;for(var i=rips.length-1;i>=0;i--){var r=rips[i];r.r+=2.5*D;r.a*=damp;e+=r.a;if(r.a<.015){rips.splice(i,1);continue}for(var k=0;k<3;k++){var q=k/3,alpha=r.a*(.45-q*.12);x.strokeStyle=mode==='glass'?'rgba(0,245,212,'+alpha+')':mode==='field'?'rgba(123,44,191,'+alpha+')':(k%2?'rgba(123,44,191,'+alpha+')':'rgba(0,245,212,'+alpha+')');x.lineWidth=(1.2+k*.8)*D;x.beginPath();x.arc(r.x,r.y,r.r+q*r.w,0,Math.PI*2);x.stroke()}}x.globalCompositeOperation='source-over';hud(e);requestAnimationFrame(draw)}function hud(e){hm.textContent=mode.toUpperCase();hr.textContent=rips.length;hd.textContent=damp.toFixed(2);he.textContent=e.toFixed(2);vd.textContent=damp.toFixed(2);vs.textContent=size}c.addEventListener('pointerdown',function(e){drop(e.clientX,e.clientY,1)});c.addEventListener('pointermove',function(e){if(e.buttons){drop(e.clientX,e.clientY,.45);if(window.SFX)SFX.play('move',130)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});sd.oninput=function(){damp=+sd.value/100;hud(0)};ss.oninput=function(){size=+ss.value;hud(0)};document.getElementById('btn-flow').onclick=function(e){flow=!flow;e.currentTarget.classList.toggle('active',flow);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-clear').onclick=function(){rips=[];if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();drop(innerWidth*.5,innerHeight*.5,1);draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
