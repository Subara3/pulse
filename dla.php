<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('dla');

$pageName = 'dla';
$pageTitle = "DLA - PULSE";
$pageDesc = json_decode('"\u62e1\u6563\u5f8b\u901f\u51dd\u96c6\u306e\u7d50\u6676\u6210\u9577\u3092\u3001\u7c92\u5b50\u306e\u30e9\u30f3\u30c0\u30e0\u30a6\u30a9\u30fc\u30af\u3067\u63cf\u304fCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/dla.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">CLUSTER <b id="h-cluster">1</b><br>WALKERS <b id="h-walkers">0</b><br>SPEED <b id="h-speed">24</b><br><span class="sub">MODE</span> <b id="h-mode">RADIAL</b></div>
<div class="mark">DLA</div>
<?php $interactionHints=[['type'=>'click','label'=>'seed'],['type'=>'drag','label'=>'feed']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="radial">RADIAL</button><button class="control-btn" data-mode="branch">BRANCH</button><button class="control-btn" data-mode="snow">SNOW</button></div><div class="slider-row"><span class="slider-label">WALKERS</span><input type="range" id="sl-walkers" min="20" max="180" value="90"><span class="slider-value" id="sv-walkers">90</span></div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="4" max="60" value="24"><span class="slider-value" id="sv-speed">24</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,mode='radial',walkTarget=90,speed=24,run=true,grid=new Set(),pts=[],walkers=[],cell=4;
var hc=document.getElementById('h-cluster'),hw=document.getElementById('h-walkers'),hs=document.getElementById('h-speed'),hm=document.getElementById('h-mode'),sw=document.getElementById('sl-walkers'),ss=document.getElementById('sl-speed'),vw=document.getElementById('sv-walkers'),vs=document.getElementById('sv-speed');
function key(i,j){return i+','+j}function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';reset()}function add(i,j){grid.add(key(i,j));pts.push([i,j])}function reset(){grid=new Set();pts=[];walkers=[];add(Math.floor(W/(cell*D)/2),Math.floor(H/(cell*D)/2));spawn();hud()}function spawnOne(){var cols=Math.floor(W/(cell*D)),rows=Math.floor(H/(cell*D)),a=Math.random()*Math.PI*2,r=Math.min(cols,rows)*.42;return {i:Math.floor(cols/2+Math.cos(a)*r),j:Math.floor(rows/2+Math.sin(a)*r)}}function spawn(){while(walkers.length<walkTarget)walkers.push(spawnOne())}function near(i,j){return grid.has(key(i+1,j))||grid.has(key(i-1,j))||grid.has(key(i,j+1))||grid.has(key(i,j-1))}
function step(){if(!run)return;spawn();var cols=Math.floor(W/(cell*D)),rows=Math.floor(H/(cell*D));for(var s=0;s<speed;s++)for(var n=walkers.length-1;n>=0;n--){var w=walkers[n],d=Math.floor(Math.random()*4);if(mode==='branch'&&Math.random()<.35){var dx=w.i-cols/2,dy=w.j-rows/2;if(Math.abs(dx)>Math.abs(dy))w.i+=Math.sign(dx);else w.j+=Math.sign(dy)}else if(mode==='snow'&&Math.random()<.35){d=Math.floor((Math.atan2(w.j-rows/2,w.i-cols/2)/(Math.PI/3)+6))%6}if(d===0)w.i++;else if(d===1)w.i--;else if(d===2)w.j++;else w.j--;if(w.i<1||w.j<1||w.i>=cols-1||w.j>=rows-1){walkers[n]=spawnOne();continue}if(near(w.i,w.j)){add(w.i,w.j);walkers[n]=spawnOne();if(window.SFX&&pts.length%25===0)SFX.play('tick',120)}}}
function draw(){step();x.fillStyle='rgba(10,10,15,.24)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var s=cell*D;for(var i=0;i<pts.length;i++){var p=pts[i],q=i/Math.max(1,pts.length);x.fillStyle=i%2?'rgba(123,44,191,'+(.28+q*.38)+')':'rgba(0,245,212,'+(.28+q*.42)+')';x.fillRect(p[0]*s,p[1]*s,s+1,s+1)}x.fillStyle='rgba(255,255,255,.22)';for(var j=0;j<walkers.length;j++){var w=walkers[j];x.fillRect(w.i*s,w.j*s,s,s)}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}function hud(){hc.textContent=pts.length;hw.textContent=walkers.length;hs.textContent=speed;hm.textContent=mode.toUpperCase();vw.textContent=walkTarget;vs.textContent=speed}function seed(e){add(Math.floor(e.clientX*D/(cell*D)),Math.floor(e.clientY*D/(cell*D)));if(window.SFX)SFX.play('ripple',100)}c.addEventListener('pointerdown',seed);c.addEventListener('pointermove',function(e){if(e.buttons){seed(e);if(window.SFX)SFX.play('move',140)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});hud();if(window.SFX)SFX.play('toggle')}});sw.oninput=function(){walkTarget=+sw.value;hud()};ss.oninput=function(){speed=+ss.value;hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){reset();if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
