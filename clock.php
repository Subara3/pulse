<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('clock');

$pageName = 'clock';
$pageTitle = 'CLOCK - PULSE';
$pageDesc = '粒子が7セグメントの時刻へ流れ込む、Canvas粒子時計。';
$pageCanonical = 'https://pulse.subara3.com/clock.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.scanline{position:fixed;left:0;right:0;top:50%;z-index:55;height:1px;background:linear-gradient(90deg,transparent,rgba(0,245,212,.42),transparent);box-shadow:0 0 18px rgba(0,245,212,.28);pointer-events:none;animation:scan 6s linear infinite}
@keyframes scan{0%{transform:translateY(-48vh)}100%{transform:translateY(48vh)}}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.scanline{display:none}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="hud">
  FIELD <b id="h-mode">DIGITS</b><br>
  PARTICLES <b id="h-count">0</b><br>
  FORCE <b id="h-force">0.72</b><br>
  <span class="sub">TIME</span> <b id="h-time">00:00:00</b>
</div>
<div class="scanline"></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'burst'],
  ['type' => 'drag', 'label' => 'stir'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row">
    <button class="control-btn active" data-mode="digits">DIGITS</button>
    <button class="control-btn" data-mode="orbit">ORBIT</button>
    <button class="control-btn" data-mode="rain">RAIN</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">FORCE</span><input type="range" id="sl-force" min="30" max="120" value="72"><span class="slider-value" id="sv-force">0.72</span></div></div>
  <div><div class="slider-row"><span class="slider-label">TRAIL</span><input type="range" id="sl-trail" min="4" max="28" value="12"><span class="slider-value" id="sv-trail">12</span></div></div>
  <div><div class="btn-row"><button class="control-btn" id="btn-shuffle">SHUFFLE</button><button class="control-btn active" id="btn-tick">TICK</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var canvas=document.getElementById('canvas'),ctx=canvas.getContext('2d');
var W=0,H=0,DPR=1,particles=[],targets=[],mode='digits',force=.72,trail=12,tickSound=true,lastTime='',lastPointer=null;
var hMode=document.getElementById('h-mode'),hCount=document.getElementById('h-count'),hForce=document.getElementById('h-force'),hTime=document.getElementById('h-time');
var slForce=document.getElementById('sl-force'),slTrail=document.getElementById('sl-trail'),svForce=document.getElementById('sv-force'),svTrail=document.getElementById('sv-trail'),btnShuffle=document.getElementById('btn-shuffle'),btnTick=document.getElementById('btn-tick');
var SEG={0:[1,1,1,1,1,1,0],1:[0,1,1,0,0,0,0],2:[1,1,0,1,1,0,1],3:[1,1,1,1,0,0,1],4:[0,1,1,0,0,1,1],5:[1,0,1,1,0,1,1],6:[1,0,1,1,1,1,1],7:[1,1,1,0,0,0,0],8:[1,1,1,1,1,1,1],9:[1,1,1,1,0,1,1]};
function resize(){DPR=Math.min(2,window.devicePixelRatio||1);W=canvas.width=Math.floor(innerWidth*DPR);H=canvas.height=Math.floor(innerHeight*DPR);canvas.style.width=innerWidth+'px';canvas.style.height=innerHeight+'px';buildTargets(true);ensureParticles();}
function addLine(a,x1,y1,x2,y2,n){if(!a)return;for(var i=0;i<n;i++){var t=i/(n-1||1);targets.push({x:x1+(x2-x1)*t+(Math.random()-.5)*2*DPR,y:y1+(y2-y1)*t+(Math.random()-.5)*2*DPR});}}
function digitTargets(ch,x,y,w,h){if(ch===':'){for(var i=0;i<20;i++){targets.push({x:x+w*.5+(Math.random()-.5)*w*.18,y:y+h*.34+(Math.random()-.5)*h*.1});targets.push({x:x+w*.5+(Math.random()-.5)*w*.18,y:y+h*.66+(Math.random()-.5)*h*.1});}return;}var s=SEG[ch]||SEG[0],n=Math.max(8,Math.floor(w/6/DPR));addLine(s[0],x+w*.15,y,x+w*.85,y,n);addLine(s[1],x+w,y+h*.08,x+w,y+h*.45,n);addLine(s[2],x+w,y+h*.55,x+w,y+h*.92,n);addLine(s[3],x+w*.15,y+h,x+w*.85,y+h,n);addLine(s[4],x,y+h*.55,x,y+h*.92,n);addLine(s[5],x,y+h*.08,x,y+h*.45,n);addLine(s[6],x+w*.15,y+h*.5,x+w*.85,y+h*.5,n);}
function timeString(){var d=new Date();return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')+':'+String(d.getSeconds()).padStart(2,'0');}
function buildTargets(forceBuild){var ts=timeString();if(!forceBuild&&mode==='digits'&&ts===lastTime)return;lastTime=ts;targets=[];if(mode==='digits'){var chars=ts.split(''),dw=Math.min(W*.105,84*DPR),gap=dw*.18,total=dw*6+gap*7,cx=(W-total)/2,cy=H*.46-dw*.8;for(var i=0;i<chars.length;i++){var ch=chars[i],w=ch===':'?dw*.42:dw;digitTargets(ch,cx,cy,w,dw*1.72);cx+=w+gap;}}else if(mode==='orbit'){var cx2=W/2,cy2=H/2;for(var r=0;r<6;r++){var rr=Math.min(W,H)*(.1+r*.048);for(var k=0;k<90;k++){var a=k/90*Math.PI*2+r*.4;targets.push({x:cx2+Math.cos(a)*rr,y:cy2+Math.sin(a)*rr*.56});}}}else{for(var q=0;q<520;q++){targets.push({x:(q*97%Math.max(1,W)),y:((q*53+Date.now()*.03)%Math.max(1,H))});}}hTime.textContent=ts;if(tickSound&&typeof SFX!=='undefined')SFX.play('tick',900);}
function ensureParticles(){var want=Math.min(1700,Math.max(760,targets.length*2));while(particles.length<want)particles.push({x:Math.random()*W,y:Math.random()*H,vx:0,vy:0,t:Math.floor(Math.random()*Math.max(1,targets.length))});particles.length=want;hCount.textContent=particles.length;}
function updateHud(){hMode.textContent=mode.toUpperCase();hForce.textContent=force.toFixed(2);svForce.textContent=force.toFixed(2);svTrail.textContent=trail;}
function burst(x,y,power){for(var i=0;i<particles.length;i++){var p=particles[i],dx=p.x-x*DPR,dy=p.y-y*DPR,d=Math.sqrt(dx*dx+dy*dy)||1;if(d<180*DPR){var f=(1-d/(180*DPR))*power;p.vx+=dx/d*f;p.vy+=dy/d*f;}}}
function draw(){buildTargets(false);ensureParticles();ctx.fillStyle='rgba(10,10,15,'+(trail/100)+')';ctx.fillRect(0,0,W,H);ctx.globalCompositeOperation='lighter';for(var i=0;i<particles.length;i++){var p=particles[i],t=targets[p.t%targets.length]||{x:W/2,y:H/2};if(Math.random()<.002)p.t=Math.floor(Math.random()*targets.length);var dx=t.x-p.x,dy=t.y-p.y;p.vx+=dx*.004*force;p.vy+=dy*.004*force;p.vx*=.9;p.vy*=.9;p.x+=p.vx;p.y+=p.vy;var sp=Math.min(1,Math.sqrt(p.vx*p.vx+p.vy*p.vy)/18);ctx.fillStyle=i%5===0?'rgba(123,44,191,'+(.22+sp*.45)+')':'rgba(0,245,212,'+(.18+sp*.55)+')';ctx.beginPath();ctx.arc(p.x,p.y,(1.2+sp*2.2)*DPR,0,Math.PI*2);ctx.fill();}ctx.globalCompositeOperation='source-over';requestAnimationFrame(draw);}
canvas.addEventListener('pointerdown',function(e){lastPointer={x:e.clientX,y:e.clientY};burst(e.clientX,e.clientY,11);if(typeof SFX!=='undefined')SFX.play('ripple',120);});
canvas.addEventListener('pointermove',function(e){if(!(e.buttons||e.pressure))return;if(lastPointer){burst(e.clientX,e.clientY,4);}lastPointer={x:e.clientX,y:e.clientY};});
window.addEventListener('pointerup',function(){lastPointer=null;});
document.querySelectorAll('[data-mode]').forEach(function(b){b.addEventListener('click',function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(x){x.classList.toggle('active',x===b);});buildTargets(true);updateHud();if(typeof SFX!=='undefined')SFX.play('toggle');});});
slForce.addEventListener('input',function(){force=parseInt(slForce.value,10)/100;updateHud();if(typeof SFX!=='undefined')SFX.play('move',90);});
slTrail.addEventListener('input',function(){trail=parseInt(slTrail.value,10);updateHud();});
btnShuffle.addEventListener('click',function(){for(var i=0;i<particles.length;i++){particles[i].x=Math.random()*W;particles[i].y=Math.random()*H;}if(typeof SFX!=='undefined')SFX.play('success');});
btnTick.addEventListener('click',function(){tickSound=!tickSound;btnTick.classList.toggle('active',tickSound);if(typeof SFX!=='undefined')SFX.play('toggle');});
window.addEventListener('resize',resize);resize();updateHud();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
