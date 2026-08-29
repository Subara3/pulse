<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('resonance');

$pageName = 'resonance';
$pageTitle = 'RESONANCE - PULSE';
$pageDesc = '強制振動と共振。駆動周波数が固有振動数に一致するとき、振幅が劇的に増大する共振現象を体験する。';
$pageCanonical = 'https://pulse.subara3.com/resonance.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">RESONANCE</span><br>DRIVE <b id="h-freq">1.27</b> Hz<br>Q <b id="h-q">20.0</b><br>DAMP <b id="h-damp">0.20</b></div>
<div class="mark">RESONANCE</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'frequency'],
  ['type' => 'drag', 'label' => 'sweep'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">DAMPING</span>
    <input type="range" id="sl-damp" min="2" max="60" value="20">
    <span class="slider-value" id="sv-damp">0.20</span>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d');
var W,H,N;
var FMIN=2,FMAX=14,F0=60;
var gamma=0.20,driveFreq=8,time=0,DT=1/60;
var autoSweep=true,sweepDir=1,dragging=false;
var oscs=[],parts=[];

function init(){
  W=c.width=window.innerWidth;
  H=c.height=window.innerHeight;
  N=Math.max(60,Math.min(200,(W/8)|0));
  oscs=[];
  for(var i=0;i<N;i++){
    oscs.push({omega:FMIN+(i/(N-1))*(FMAX-FMIN),x:0,v:0,peak:0});
  }
  parts=[];
}

function step(){
  if(autoSweep){
    driveFreq+=sweepDir*(FMAX-FMIN)/900;
    if(driveFreq>=FMAX){driveFreq=FMAX;sweepDir=-1;}
    if(driveFreq<=FMIN){driveFreq=FMIN;sweepDir=1;}
  }
  time+=DT;
  var sub=4,sdt=DT/sub;
  for(var s=0;s<sub;s++){
    var t=time-DT+(s+1)*sdt;
    var f=F0*Math.sin(driveFreq*t);
    for(var i=0;i<N;i++){
      var o=oscs[i];
      var a=f-2*gamma*o.v-o.omega*o.omega*o.x;
      o.v+=a*sdt;
      o.x+=o.v*sdt;
    }
  }
  var maxP=1;
  for(var i=0;i<N;i++){
    var o=oscs[i],ax=Math.abs(o.x);
    o.peak=ax>o.peak?ax:o.peak*0.997;
    if(o.peak>maxP)maxP=o.peak;
  }
  for(var i=0;i<N;i++){
    var o=oscs[i],r=o.peak/maxP;
    if(r>0.6&&Math.random()<(r-0.6)*0.35&&parts.length<400){
      var sc=gScale(maxP);
      parts.push({
        x:gX(i),y:H/2+o.x*sc,
        vx:(Math.random()-0.5)*1.2,
        vy:(o.x>0?-1:1)*(0.5+Math.random()*1.5),
        life:1,decay:0.012+Math.random()*0.02,
        rgb:gRgb(i)
      });
    }
  }
  for(var i=parts.length-1;i>=0;i--){
    var p=parts[i];
    p.x+=p.vx;p.y+=p.vy;p.life-=p.decay;
    if(p.life<=0)parts.splice(i,1);
  }
}

function gScale(maxP){return maxP<0.1?1:Math.min(H*0.33/maxP,300);}

function gX(i){var m=W*0.04;return m+(i/(N-1))*(W-2*m);}

function fX(freq){var m=W*0.04;return m+((freq-FMIN)/(FMAX-FMIN))*(W-2*m);}

function xF(x){var m=W*0.04;var t=(x-m)/(W-2*m);return FMIN+Math.max(0,Math.min(1,t))*(FMAX-FMIN);}

function gRgb(i){
  var delta=oscs[i].omega-driveFreq;
  var t=0.5+delta/(8*gamma);
  t=Math.max(0,Math.min(1,t));
  t=t*t*(3-2*t);
  return[(t*123)|0,(245+t*(44-245))|0,(212+t*(191-212))|0];
}

function draw(){
  ctx.fillStyle='#0a0a0f';
  ctx.fillRect(0,0,W,H);
  var cy=H/2,maxP=1;
  for(var i=0;i<N;i++)if(oscs[i].peak>maxP)maxP=oscs[i].peak;
  var sc=gScale(maxP);

  ctx.beginPath();ctx.moveTo(0,cy);ctx.lineTo(W,cy);
  ctx.strokeStyle='rgba(255,255,255,0.03)';ctx.lineWidth=1;ctx.stroke();

  ctx.beginPath();ctx.moveTo(gX(0),cy);
  for(var i=0;i<N;i++)ctx.lineTo(gX(i),cy-oscs[i].peak*sc);
  ctx.lineTo(gX(N-1),cy);ctx.closePath();
  var g1=ctx.createLinearGradient(0,cy-maxP*sc,0,cy);
  g1.addColorStop(0,'rgba(0,245,212,0.09)');g1.addColorStop(1,'rgba(0,245,212,0)');
  ctx.fillStyle=g1;ctx.fill();

  ctx.beginPath();ctx.moveTo(gX(0),cy);
  for(var i=0;i<N;i++)ctx.lineTo(gX(i),cy+oscs[i].peak*sc);
  ctx.lineTo(gX(N-1),cy);ctx.closePath();
  var g2=ctx.createLinearGradient(0,cy,0,cy+maxP*sc);
  g2.addColorStop(0,'rgba(0,245,212,0)');g2.addColorStop(1,'rgba(0,245,212,0.09)');
  ctx.fillStyle=g2;ctx.fill();

  ctx.strokeStyle='rgba(0,245,212,0.15)';ctx.lineWidth=1;
  ctx.beginPath();
  for(var i=0;i<N;i++){var x=gX(i);i===0?ctx.moveTo(x,cy-oscs[i].peak*sc):ctx.lineTo(x,cy-oscs[i].peak*sc);}
  ctx.stroke();
  ctx.beginPath();
  for(var i=0;i<N;i++){var x=gX(i);i===0?ctx.moveTo(x,cy+oscs[i].peak*sc):ctx.lineTo(x,cy+oscs[i].peak*sc);}
  ctx.stroke();

  for(var i=0;i<N;i++){
    var o=oscs[i],px=gX(i),tipY=cy+o.x*sc;
    var amp=maxP>0?o.peak/maxP:0;
    var rgb=gRgb(i);
    var cs='rgba('+rgb[0]+','+rgb[1]+','+rgb[2]+',';

    ctx.beginPath();ctx.moveTo(px,cy);ctx.lineTo(px,tipY);
    ctx.strokeStyle=cs+(0.06+amp*0.35)+')';ctx.lineWidth=1;ctx.stroke();

    var r=1+amp*4;
    ctx.beginPath();ctx.arc(px,tipY,r,0,6.2832);
    ctx.fillStyle=cs+(0.2+amp*0.8)+')';ctx.fill();

    if(amp>0.4){
      var gr=r+amp*15;
      var grd=ctx.createRadialGradient(px,tipY,r*0.3,px,tipY,gr);
      grd.addColorStop(0,cs+(amp*0.2)+')');
      grd.addColorStop(1,cs+'0)');
      ctx.beginPath();ctx.arc(px,tipY,gr,0,6.2832);ctx.fillStyle=grd;ctx.fill();
    }
  }

  for(var i=0;i<parts.length;i++){
    var p=parts[i];
    ctx.beginPath();ctx.arc(p.x,p.y,1.2*p.life,0,6.2832);
    ctx.fillStyle='rgba('+p.rgb[0]+','+p.rgb[1]+','+p.rgb[2]+','+(p.life*0.5)+')';
    ctx.fill();
  }

  var dx=fX(driveFreq);
  ctx.save();
  ctx.beginPath();ctx.moveTo(dx,H*0.06);ctx.lineTo(dx,H*0.94);
  ctx.strokeStyle='rgba(123,44,191,0.3)';ctx.lineWidth=1.5;ctx.setLineDash([4,6]);ctx.stroke();
  ctx.restore();

  ctx.beginPath();
  ctx.moveTo(dx-5,H*0.04);ctx.lineTo(dx+5,H*0.04);ctx.lineTo(dx,H*0.06);ctx.closePath();
  ctx.fillStyle='rgba(181,140,255,0.5)';ctx.fill();
}

function updateHUD(){
  var hf=document.getElementById('h-freq');
  var hq=document.getElementById('h-q');
  var hd=document.getElementById('h-damp');
  if(hf)hf.textContent=(driveFreq/(2*Math.PI)).toFixed(2);
  if(hq)hq.textContent=(driveFreq/(2*gamma)).toFixed(1);
  if(hd)hd.textContent=gamma.toFixed(2);
}

function loop(){step();draw();updateHUD();requestAnimationFrame(loop);}

function resetAll(){
  for(var i=0;i<N;i++){oscs[i].x=0;oscs[i].v=0;oscs[i].peak=0;}
  parts=[];
}

c.addEventListener('mousedown',function(e){
  if(e.button===2){resetAll();SFX.play('click');return;}
  dragging=true;autoSweep=false;
  document.getElementById('btn-auto').classList.remove('active');
  driveFreq=xF(e.clientX);SFX.play('spawn');
});
c.addEventListener('mousemove',function(e){if(dragging){driveFreq=xF(e.clientX);SFX.play('move',120);}});
window.addEventListener('mouseup',function(){dragging=false;});
c.addEventListener('contextmenu',function(e){e.preventDefault();});

c.addEventListener('wheel',function(e){
  e.preventDefault();
  driveFreq+=-e.deltaY*0.02;
  driveFreq=Math.max(FMIN,Math.min(FMAX,driveFreq));
  autoSweep=false;
  document.getElementById('btn-auto').classList.remove('active');
},{passive:false});

c.addEventListener('touchstart',function(e){
  e.preventDefault();dragging=true;autoSweep=false;
  document.getElementById('btn-auto').classList.remove('active');
  driveFreq=xF(e.touches[0].clientX);SFX.play('spawn');
},{passive:false});
c.addEventListener('touchmove',function(e){
  e.preventDefault();if(dragging){driveFreq=xF(e.touches[0].clientX);SFX.play('move',120);}
},{passive:false});
c.addEventListener('touchend',function(){dragging=false;});

var slD=document.getElementById('sl-damp'),svD=document.getElementById('sv-damp');
if(slD)slD.addEventListener('input',function(){
  gamma=parseInt(this.value)/100;if(svD)svD.textContent=gamma.toFixed(2);
  SFX.play('tick',80);
});
var btnA=document.getElementById('btn-auto');
if(btnA)btnA.addEventListener('click',function(){
  autoSweep=!autoSweep;this.classList.toggle('active',autoSweep);
  if(autoSweep)sweepDir=1;
  SFX.play('toggle');
});
var btnR=document.getElementById('btn-reset');
if(btnR)btnR.addEventListener('click',function(){resetAll();SFX.play('click');});

window.addEventListener('resize',init);
init();
for(var w=0;w<360;w++)step();
parts=[];
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
