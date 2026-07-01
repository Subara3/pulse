<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lorenz');

$pageName = 'lorenz';
$pageTitle = 'LORENZ - PULSE';
$pageDesc = 'ローレンツ方程式の複数軌道とポアンカレ断面を描くCanvasカオス可視化。';
$pageCanonical = 'https://pulse.subara3.com/lorenz.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.section-mark{position:fixed;right:1rem;bottom:1rem;z-index:55;width:72px;height:72px;border:1px solid rgba(123,44,191,.35);border-radius:50%;box-shadow:0 0 28px rgba(123,44,191,.18);pointer-events:none}
.section-mark:after{content:"";position:absolute;left:50%;top:50%;width:7px;height:7px;border-radius:50%;background:#00f5d4;box-shadow:0 0 18px rgba(0,245,212,.8);transform:translate(-50%,-50%)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.section-mark{right:.75rem;bottom:4.5rem;width:52px;height:52px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  RHO <b id="h-rho">28.0</b><br>
  ORBITS <b id="h-orbits">9</b><br>
  TRAIL <b id="h-trail">1800</b><br>
  <span class="sub">VIEW</span> <b id="h-view">FLOW</b>
</div>
<div class="section-mark"></div>
<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'rotate'],
  ['type' => 'click', 'label' => 'seed'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row"><button class="control-btn active" data-view="flow">FLOW</button><button class="control-btn" data-view="section">SECTION</button><button class="control-btn" data-view="ribbon">RIBBON</button></div></div>
  <div><div class="slider-row"><span class="slider-label">RHO</span><input type="range" id="sl-rho" min="180" max="420" value="280"><span class="slider-value" id="sv-rho">28.0</span></div></div>
  <div><div class="slider-row"><span class="slider-label">ORBITS</span><input type="range" id="sl-orbits" min="3" max="15" value="9"><span class="slider-value" id="sv-orbits">9</span></div></div>
  <div><div class="slider-row"><span class="slider-label">TRAIL</span><input type="range" id="sl-trail" min="500" max="4200" value="1800" step="100"><span class="slider-value" id="sv-trail">1800</span></div></div>
  <div><div class="btn-row"><button class="control-btn" id="btn-seed">SEED</button><button class="control-btn active" id="btn-spin">SPIN</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var canvas=document.getElementById('canvas'),ctx=canvas.getContext('2d');
var W=0,H=0,DPR=1,rho=28,orbitCount=9,trail=1800,view='flow',spin=true,yaw=.25,pitch=.55,drag=false,lx=0,ly=0,orbits=[];
var sigma=10,beta=8/3,dt=.006;
var hRho=document.getElementById('h-rho'),hOrbits=document.getElementById('h-orbits'),hTrail=document.getElementById('h-trail'),hView=document.getElementById('h-view');
var slRho=document.getElementById('sl-rho'),slOrbits=document.getElementById('sl-orbits'),slTrail=document.getElementById('sl-trail'),svRho=document.getElementById('sv-rho'),svOrbits=document.getElementById('sv-orbits'),svTrail=document.getElementById('sv-trail'),btnSeed=document.getElementById('btn-seed'),btnSpin=document.getElementById('btn-spin');
function resize(){DPR=Math.min(2,devicePixelRatio||1);W=canvas.width=Math.floor(innerWidth*DPR);H=canvas.height=Math.floor(innerHeight*DPR);canvas.style.width=innerWidth+'px';canvas.style.height=innerHeight+'px';}
function seed(){orbits=[];for(var i=0;i<orbitCount;i++){var a=i/orbitCount*Math.PI*2;orbits.push({x:.12+Math.cos(a)*.018,y:Math.sin(a)*.018,z:.02,pts:[],section:[],phase:i/orbitCount});}if(typeof SFX!=='undefined')SFX.play('spawn',100);}
function step(o){var dx=sigma*(o.y-o.x),dy=o.x*(rho-o.z)-o.y,dz=o.x*o.y-beta*o.z;o.x+=dx*dt;o.y+=dy*dt;o.z+=dz*dt;if(o.pts.length&&o.pts[o.pts.length-1].x<0&&o.x>=0&&o.y>0){o.section.push({y:o.y,z:o.z});if(o.section.length>420)o.section.shift();}o.pts.push({x:o.x,y:o.y,z:o.z});if(o.pts.length>trail)o.pts.shift();}
function project(p){var cy=Math.cos(yaw),sy=Math.sin(yaw),cp=Math.cos(pitch),sp=Math.sin(pitch);var x=p.x*cy-p.y*sy;var y=p.x*sy+p.y*cy;var z=p.z;var yy=y*cp-z*sp;var zz=y*sp+z*cp;var sc=Math.min(W,H)*.017;return{x:W/2+x*sc,y:H*.58-zz*sc+yy*sc*.25,d:zz};}
function drawGrid(){ctx.strokeStyle='rgba(0,245,212,.045)';ctx.lineWidth=1*DPR;for(var i=0;i<12;i++){var x=i*W/12;ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,H);ctx.stroke();}for(var j=0;j<8;j++){var y=j*H/8;ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(W,y);ctx.stroke();}}
function drawFlow(){for(var o=0;o<orbits.length;o++){var arr=orbits[o].pts;if(arr.length<2)continue;ctx.beginPath();for(var i=1;i<arr.length;i++){var a=project(arr[i-1]),b=project(arr[i]);var q=i/arr.length;ctx.strokeStyle=o%2?'rgba(123,44,191,'+(.05+q*.55)+')':'rgba(0,245,212,'+(.06+q*.62)+')';ctx.lineWidth=(.7+q*1.7)*DPR;ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();}var c=project(arr[arr.length-1]);ctx.fillStyle=o%2?'rgba(123,44,191,.9)':'rgba(0,245,212,.9)';ctx.beginPath();ctx.arc(c.x,c.y,3.5*DPR,0,Math.PI*2);ctx.fill();}}
function drawSection(){ctx.save();ctx.translate(W/2,H/2);ctx.strokeStyle='rgba(123,44,191,.25)';ctx.strokeRect(-W*.28,-H*.28,W*.56,H*.56);ctx.globalCompositeOperation='lighter';for(var o=0;o<orbits.length;o++){var sec=orbits[o].section;for(var i=0;i<sec.length;i++){var p=sec[i],x=(p.y/30-.5)*W*.72,y=(p.z/52-.5)*H*.75,a=i/sec.length;ctx.fillStyle=o%2?'rgba(123,44,191,'+(.08+a*.55)+')':'rgba(0,245,212,'+(.08+a*.62)+')';ctx.fillRect(x,y,2*DPR,2*DPR);}}ctx.restore();}
function drawRibbon(){ctx.globalCompositeOperation='lighter';for(var o=0;o<orbits.length;o++){var arr=orbits[o].pts;if(arr.length<4)continue;ctx.beginPath();for(var i=0;i<arr.length;i+=3){var p=project(arr[i]);if(i===0)ctx.moveTo(p.x,p.y);else ctx.lineTo(p.x,p.y);}ctx.strokeStyle=o%2?'rgba(123,44,191,.18)':'rgba(0,245,212,.2)';ctx.lineWidth=5*DPR;ctx.stroke();}ctx.globalCompositeOperation='source-over';drawFlow();}
function draw(){for(var s=0;s<7;s++)for(var i=0;i<orbits.length;i++)step(orbits[i]);if(spin&&!drag)yaw+=.002;ctx.fillStyle='rgba(10,10,15,.18)';ctx.fillRect(0,0,W,H);drawGrid();ctx.globalCompositeOperation='lighter';if(view==='section')drawSection();else if(view==='ribbon')drawRibbon();else drawFlow();ctx.globalCompositeOperation='source-over';requestAnimationFrame(draw);}
function updateHud(){hRho.textContent=rho.toFixed(1);hOrbits.textContent=orbitCount;hTrail.textContent=trail;hView.textContent=view.toUpperCase();svRho.textContent=rho.toFixed(1);svOrbits.textContent=orbitCount;svTrail.textContent=trail;}
canvas.addEventListener('pointerdown',function(e){drag=true;lx=e.clientX;ly=e.clientY;if(typeof SFX!=='undefined')SFX.play('ripple',120);});
canvas.addEventListener('pointermove',function(e){if(!drag&&!e.buttons)return;yaw+=(e.clientX-lx)*.006;pitch=Math.max(-.95,Math.min(.95,pitch+(e.clientY-ly)*.005));lx=e.clientX;ly=e.clientY;});window.addEventListener('pointerup',function(){drag=false;});
document.querySelectorAll('[data-view]').forEach(function(b){b.addEventListener('click',function(){view=b.dataset.view;document.querySelectorAll('[data-view]').forEach(function(x){x.classList.toggle('active',x===b);});updateHud();if(typeof SFX!=='undefined')SFX.play('toggle');});});
slRho.addEventListener('input',function(){rho=parseInt(slRho.value,10)/10;updateHud();});
slOrbits.addEventListener('input',function(){orbitCount=parseInt(slOrbits.value,10);seed();updateHud();});
slTrail.addEventListener('input',function(){trail=parseInt(slTrail.value,10);for(var i=0;i<orbits.length;i++)orbits[i].pts=orbits[i].pts.slice(-trail);updateHud();});
btnSeed.addEventListener('click',function(){seed();});btnSpin.addEventListener('click',function(){spin=!spin;btnSpin.classList.toggle('active',spin);if(typeof SFX!=='undefined')SFX.play('toggle');});
window.addEventListener('resize',resize);resize();seed();updateHud();ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
