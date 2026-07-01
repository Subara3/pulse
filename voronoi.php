<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('voronoi');

$pageName = 'voronoi';
$pageTitle = "VORONOI - PULSE";
$pageDesc = json_decode('"\u6700\u3082\u8fd1\u3044\u70b9\u306b\u3088\u308b\u7a7a\u9593\u5206\u5272\u3092\u3001\u52d5\u304f\u7a2e\u70b9\u3068\u8ddd\u96e2\u5834\u3067\u63cf\u304fCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/voronoi.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">SEEDS <b id="h-seeds">24</b><br>RES <b id="h-res">5</b><br>COLOR <b id="h-color">PULSE</b><br><span class="sub">MODE</span> <b id="h-mode">CELL</b></div>
<div class="mark">VORONOI</div>
<?php $interactionHints=[['type'=>'click','label'=>'seed'],['type'=>'drag','label'=>'move']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="cell">CELL</button><button class="control-btn" data-mode="edge">EDGE</button><button class="control-btn" data-mode="field">FIELD</button></div><div class="slider-row"><span class="slider-label">SEEDS</span><input type="range" id="sl-seeds" min="6" max="80" value="24"><span class="slider-value" id="sv-seeds">24</span></div><div class="slider-row"><span class="slider-label">RES</span><input type="range" id="sl-res" min="3" max="10" value="5"><span class="slider-value" id="sv-res">5</span></div><div class="btn-row"><button class="control-btn active" id="btn-drift">DRIFT</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,seeds=[],seedCount=24,res=5,mode='cell',drift=true,drag=-1;
var hs=document.getElementById('h-seeds'),hr=document.getElementById('h-res'),hc=document.getElementById('h-color'),hm=document.getElementById('h-mode'),ss=document.getElementById('sl-seeds'),sr=document.getElementById('sl-res'),vs=document.getElementById('sv-seeds'),vr=document.getElementById('sv-res');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';make()}function make(){seeds=[];for(var i=0;i<seedCount;i++)seeds.push({x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.7*D,vy:(Math.random()-.5)*.7*D});hud()}function nearest(px,py){var a=0,b=1,da=1e9,db=1e9;for(var i=0;i<seeds.length;i++){var s=seeds[i],d=(s.x-px)*(s.x-px)+(s.y-py)*(s.y-py);if(d<da){db=da;b=a;da=d;a=i}else if(d<db){db=d;b=i}}return [a,b,Math.sqrt(da),Math.sqrt(db)]}function draw(){if(drift)for(var i=0;i<seeds.length;i++){var s=seeds[i];s.x+=s.vx;s.y+=s.vy;if(s.x<0||s.x>W)s.vx*=-1;if(s.y<0||s.y>H)s.vy*=-1}var cell=Math.max(3,res)*D;x.fillStyle='#0a0a0f';x.fillRect(0,0,W,H);for(var y=0;y<H;y+=cell)for(var px=0;px<W;px+=cell){var n=nearest(px,y),q=n[0]/Math.max(1,seeds.length-1),edge=Math.abs(n[3]-n[2]);if(mode==='edge')x.fillStyle=edge<4*D?'rgba(0,245,212,.72)':'rgba(10,10,15,.08)';else if(mode==='field')x.fillStyle='rgba('+Math.floor(35+88*q)+','+Math.floor(60+185*(1-q))+','+Math.floor(160+65*q)+',.55)';else x.fillStyle=n[0]%2?'rgba(123,44,191,.38)':'rgba(0,245,212,.34)';x.fillRect(px,y,cell+1,cell+1)}x.globalCompositeOperation='lighter';for(var j=0;j<seeds.length;j++){x.fillStyle=j%2?'rgba(123,44,191,.9)':'rgba(0,245,212,.9)';x.beginPath();x.arc(seeds[j].x,seeds[j].y,4*D,0,Math.PI*2);x.fill()}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}function hud(){hs.textContent=seeds.length;hr.textContent=res;hc.textContent='PULSE';hm.textContent=mode.toUpperCase();vs.textContent=seedCount;vr.textContent=res}c.addEventListener('pointerdown',function(e){var mx=e.clientX*D,my=e.clientY*D,best=-1,bd=22*D;for(var i=0;i<seeds.length;i++){var d=Math.hypot(seeds[i].x-mx,seeds[i].y-my);if(d<bd){bd=d;best=i}}if(best>=0)drag=best;else{seeds.push({x:mx,y:my,vx:0,vy:0});seedCount=seeds.length;ss.value=Math.min(80,seedCount)}if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons&&drag>=0){seeds[drag].x=e.clientX*D;seeds[drag].y=e.clientY*D;if(window.SFX)SFX.play('move',140)}});c.addEventListener('pointerup',function(){drag=-1});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});ss.oninput=function(){seedCount=+ss.value;make()};sr.oninput=function(){res=+sr.value;hud()};document.getElementById('btn-drift').onclick=function(e){drift=!drift;e.currentTarget.classList.toggle('active',drift);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){make();if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
