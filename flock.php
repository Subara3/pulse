<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('flock');

$pageName = 'flock';
$pageTitle = "FLOCK - PULSE";
$pageDesc = json_decode('"\u5206\u96e2\u3001\u6574\u5217\u3001\u7d50\u5408\u306e\u30eb\u30fc\u30eb\u3067\u7fa4\u308c\u304c\u751f\u307e\u308c\u308bBoids\u306eCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/flock.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">BOIDS <b id="h-count">180</b><br>SPEED <b id="h-speed">3.6</b><br>FORCE <b id="h-force">ATTRACT</b><br><span class="sub">SPREAD</span> <b id="h-spread">0.72</b></div>
<div class="mark">FLOCK</div>
<?php $interactionHints=[['type'=>'drag','label'=>'field'],['type'=>'click','label'=>'pulse']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="attract">ATTRACT</button><button class="control-btn" data-mode="repel">REPEL</button><button class="control-btn" data-mode="observe">OBSERVE</button></div><div class="slider-row"><span class="slider-label">BOIDS</span><input type="range" id="sl-count" min="40" max="360" value="180"><span class="slider-value" id="sv-count">180</span></div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="10" max="80" value="36"><span class="slider-value" id="sv-speed">3.6</span></div><div class="btn-row"><button class="control-btn active" id="btn-trails">TRAILS</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,count=180,maxSpeed=3.6,mode='attract',trails=true,boids=[],mouse={x:0,y:0,on:false},spread=.72;
var hc=document.getElementById('h-count'),hs=document.getElementById('h-speed'),hf=document.getElementById('h-force'),hp=document.getElementById('h-spread'),sc=document.getElementById('sl-count'),ss=document.getElementById('sl-speed'),vc=document.getElementById('sv-count'),vs=document.getElementById('sv-speed');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}function make(){boids=[];for(var i=0;i<count;i++){var a=Math.random()*Math.PI*2;boids.push({x:Math.random()*W,y:Math.random()*H,vx:Math.cos(a)*maxSpeed*D,vy:Math.sin(a)*maxSpeed*D})}hud()}
function step(){for(var i=0;i<boids.length;i++){var b=boids[i],sx=0,sy=0,ax=0,ay=0,cx=0,cy=0,n=0;for(var j=0;j<boids.length;j++){if(i===j)continue;var o=boids[j],dx=o.x-b.x,dy=o.y-b.y,d=Math.hypot(dx,dy);if(d<70*D){n++;ax+=o.vx;ay+=o.vy;cx+=o.x;cy+=o.y;if(d<22*D){sx-=dx/(d||1);sy-=dy/(d||1)}}}if(n){ax=ax/n-b.vx;ay=ay/n-b.vy;cx=cx/n-b.x;cy=cy/n-b.y;b.vx+=sx*.05+ax*.025+cx*.0009*spread;b.vy+=sy*.05+ay*.025+cy*.0009*spread}if(mouse.on&&mode!=='observe'){var mdx=mouse.x-b.x,mdy=mouse.y-b.y,md=Math.hypot(mdx,mdy)||1,sgn=mode==='repel'?-1:1;b.vx+=sgn*mdx/md*.08*D;b.vy+=sgn*mdy/md*.08*D}var sp=Math.hypot(b.vx,b.vy)||1,lim=maxSpeed*D;if(sp>lim){b.vx=b.vx/sp*lim;b.vy=b.vy/sp*lim}b.x=(b.x+b.vx+W)%W;b.y=(b.y+b.vy+H)%H}}
function draw(){step();x.fillStyle=trails?'rgba(10,10,15,.18)':'#0a0a0f';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';for(var i=0;i<boids.length;i++){var b=boids[i],a=Math.atan2(b.vy,b.vx);x.save();x.translate(b.x,b.y);x.rotate(a);x.fillStyle=i%2?'rgba(123,44,191,.58)':'rgba(0,245,212,.68)';x.beginPath();x.moveTo(10*D,0);x.lineTo(-7*D,4*D);x.lineTo(-4*D,0);x.lineTo(-7*D,-4*D);x.closePath();x.fill();x.restore()}x.globalCompositeOperation='source-over';requestAnimationFrame(draw)}function hud(){hc.textContent=count;hs.textContent=maxSpeed.toFixed(1);hf.textContent=mode.toUpperCase();hp.textContent=spread.toFixed(2);vc.textContent=count;vs.textContent=maxSpeed.toFixed(1)}c.addEventListener('pointerdown',function(e){mouse={x:e.clientX*D,y:e.clientY*D,on:true};if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){mouse={x:e.clientX*D,y:e.clientY*D,on:e.buttons>0};if(e.buttons&&window.SFX)SFX.play('move',140)});c.addEventListener('pointerup',function(){mouse.on=false});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});hud();if(window.SFX)SFX.play('toggle')}});sc.oninput=function(){count=+sc.value;make()};ss.oninput=function(){maxSpeed=+ss.value/10;hud()};document.getElementById('btn-trails').onclick=function(e){trails=!trails;e.currentTarget.classList.toggle('active',trails);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){make();if(window.SFX)SFX.play('success')};addEventListener('resize',function(){resize();make()});resize();make();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
