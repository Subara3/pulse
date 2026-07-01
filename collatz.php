<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('collatz');

$pageName = 'collatz';
$pageTitle = "COLLATZ - PULSE";
$pageDesc = json_decode('"\u30b3\u30e9\u30c3\u30c4\u4e88\u60f3\u306e\u6570\u5217\u3092\u3001\u5206\u5c90\u3059\u308b\u8ecc\u8de1\u3068\u5bc6\u5ea6\u3068\u3057\u3066\u63cf\u304fCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/collatz.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">MODE <b id="h-mode">ORBIT</b><br>SEEDS <b id="h-seeds">160</b><br>STEPS <b id="h-steps">120</b><br><span class="sub">FOCUS</span> <b id="h-focus">27</b></div>
<div class="mark">COLLATZ</div>
<?php $interactionHints=[['type'=>'click','label'=>'seed'],['type'=>'drag','label'=>'bend']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="orbit">ORBIT</button><button class="control-btn" data-mode="fan">FAN</button><button class="control-btn" data-mode="rain">RAIN</button></div><div class="slider-row"><span class="slider-label">SEEDS</span><input type="range" id="sl-seeds" min="40" max="320" value="160"><span class="slider-value" id="sv-seeds">160</span></div><div class="slider-row"><span class="slider-label">STEPS</span><input type="range" id="sl-steps" min="40" max="220" value="120"><span class="slider-value" id="sv-steps">120</span></div><div class="btn-row"><button class="control-btn active" id="btn-flow">FLOW</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,mode='orbit',seeds=160,steps=120,focus=27,flow=true,turn=.42,t=0;
var hm=document.getElementById('h-mode'),hs=document.getElementById('h-seeds'),hp=document.getElementById('h-steps'),hf=document.getElementById('h-focus'),ss=document.getElementById('sl-seeds'),st=document.getElementById('sl-steps'),vs=document.getElementById('sv-seeds'),vt=document.getElementById('sv-steps');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}
function seq(n){var a=[];for(var i=0;i<steps&&n>1;i++){a.push(n);n=n%2?3*n+1:n/2}a.push(n);return a}
function drawOne(n,k){var a=seq(n),cx=W/2,cy=H*.54,ang=-Math.PI/2+(k/seeds-.5)*.8,len=Math.min(W,H)*.009,px=cx,py=cy;x.beginPath();x.moveTo(px,py);for(var i=0;i<a.length;i++){var odd=a[i]%2;var bend=(odd?turn:-turn)*(mode==='fan'?1.4:1);ang+=bend;if(mode==='rain'){px=(k/seeds)*W+Math.sin(i*.2+t+n)*20*D;py=H*.12+i*len*2.3}else{px+=Math.cos(ang)*len*(1+i*.006);py+=Math.sin(ang)*len*(1+i*.006)}x.lineTo(px,py)}var q=Math.min(1,a.length/steps);x.strokeStyle=k%2?'rgba(123,44,191,'+(.08+q*.35)+')':'rgba(0,245,212,'+(.10+q*.42)+')';x.lineWidth=(.7+q*.8)*D;x.stroke()}
function draw(){if(flow){focus+=.18;t+=.016}x.fillStyle='rgba(10,10,15,.18)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';for(var i=0;i<seeds;i++){drawOne(Math.max(2,Math.floor(focus+i+1)),i)}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}
function hud(){hm.textContent=mode.toUpperCase();hs.textContent=seeds;hp.textContent=steps;hf.textContent=Math.floor(focus);vs.textContent=seeds;vt.textContent=steps}
c.addEventListener('pointerdown',function(e){focus=Math.max(2,Math.floor(e.clientX/innerWidth*999));if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons){turn=Math.max(.08,Math.min(.9,turn+(e.movementX||0)*.002));if(window.SFX)SFX.play('move',130)}});
document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});ss.oninput=function(){seeds=+ss.value;hud()};st.oninput=function(){steps=+st.value;hud()};document.getElementById('btn-flow').onclick=function(e){flow=!flow;e.currentTarget.classList.toggle('active',flow);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){focus=27;turn=.42;if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
