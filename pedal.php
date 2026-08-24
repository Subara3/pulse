<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pedal');

$pageName = 'pedal';
$pageTitle = 'PEDAL - PULSE';
$pageDesc = 'ペダル曲線。基底曲線の接線へ垂線を下ろした足の軌跡が、微分幾何学の曲線変換を描き出す。';
$pageCanonical = 'https://pulse.subara3.com/pedal.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div><span id="hud-curve">CIRCLE</span></div>
</div>
<div id="info-label"><span>PEDAL CURVES</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">BASE CURVE</label></div>
  <div class="btn-row" id="curve-btns">
    <button class="ctrl-btn active" data-c="0">CIRCLE</button>
    <button class="ctrl-btn" data-c="1">ELLIPSE</button>
    <button class="ctrl-btn" data-c="2">ASTROID</button>
    <button class="ctrl-btn" data-c="3">CARDIOID</button>
    <button class="ctrl-btn" data-c="4">ROSE</button>
  </div>
  <div class="slider-row"><label class="slider-label">PRESETS</label></div>
  <div class="btn-row" id="preset-btns">
    <button class="preset-btn" data-p="0" title="Circle + pedal on circumference">&#8594; CARDIOID</button>
    <button class="preset-btn" data-p="1" title="Circle + pedal outside">&#8594; LIMA&#199;ON</button>
    <button class="preset-btn" data-p="2" title="Ellipse + pedal at focus">&#8594; CIRCLE</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="10" value="5">
    <span class="slider-value" id="sv-speed">5</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr,cx,cy,S=1;
var PI=Math.PI,PI2=PI*2;
var TEAL=[0,245,212],PURP=[123,44,191];
var N=720;

var curveIdx=0,px=0,py=0;
var animT=0,sweepDone=false,speed=1;
var dragging=false,dragOX=0,dragOY=0;
var touchID=null,tapX=0,tapY=0,wasTap=false,didDrag=false;
var lastTime=0;

function rgba(c,a){return'rgba('+c[0]+','+c[1]+','+c[2]+','+a+')';}

function resize(){
  var oldS=S;
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
  cx=W/2;cy=H/2;
  S=Math.max(Math.min(W,H)*0.22,60);
  if(oldS>1){var r=S/oldS;px*=r;py*=r;}
}

var curves=[
  {name:'CIRCLE',
   pos:function(t){return[S*Math.cos(t),S*Math.sin(t)];},
   vel:function(t){return[-S*Math.sin(t),S*Math.cos(t)];},
   defP:function(){return[S,0];}},
  {name:'ELLIPSE',
   pos:function(t){return[S*1.4*Math.cos(t),S*0.85*Math.sin(t)];},
   vel:function(t){return[-S*1.4*Math.sin(t),S*0.85*Math.cos(t)];},
   defP:function(){var a=S*1.4,b=S*0.85;return[Math.sqrt(a*a-b*b),0];}},
  {name:'ASTROID',
   pos:function(t){var c=Math.cos(t),s=Math.sin(t);return[S*c*c*c,S*s*s*s];},
   vel:function(t){var c=Math.cos(t),s=Math.sin(t);return[-3*S*c*c*s,3*S*s*s*c];},
   defP:function(){return[S*0.45,S*0.45];}},
  {name:'CARDIOID',
   pos:function(t){var r=S*0.48*(1+Math.cos(t));return[r*Math.cos(t),r*Math.sin(t)];},
   vel:function(t){var a=S*0.48;return[a*(-Math.sin(t)-Math.sin(2*t)),a*(Math.cos(t)+Math.cos(2*t))];},
   defP:function(){return[S*0.5,0];}},
  {name:'ROSE',
   pos:function(t){var r=S*Math.cos(3*t);return[r*Math.cos(t),r*Math.sin(t)];},
   vel:function(t){var r=S*Math.cos(3*t),rp=-3*S*Math.sin(3*t);
     return[rp*Math.cos(t)-r*Math.sin(t),rp*Math.sin(t)+r*Math.cos(t)];},
   defP:function(){return[S*0.55,0];}}
];

function pedalFoot(cv,t){
  var p=cv.pos(t),v=cv.vel(t);
  var dx=p[0]-px,dy=p[1]-py;
  var dot=dx*v[0]+dy*v[1];
  var len2=v[0]*v[0]+v[1]*v[1];
  if(len2<1e-10)return null;
  var s=dot/len2;
  return[p[0]-s*v[0],p[1]-s*v[1]];
}

function computePedal(){
  var cv=curves[curveIdx],pts=[];
  for(var i=0;i<N;i++){
    pts.push(pedalFoot(cv,i/N*PI2));
  }
  return pts;
}

function drawOrigin(){
  var sz=6;
  X.strokeStyle='rgba(255,255,255,0.06)';
  X.lineWidth=1;
  X.beginPath();
  X.moveTo(cx-sz,cy);X.lineTo(cx+sz,cy);
  X.moveTo(cx,cy-sz);X.lineTo(cx,cy+sz);
  X.stroke();
}

function drawBase(){
  var cv=curves[curveIdx];
  X.beginPath();
  for(var i=0;i<=N;i++){
    var p=cv.pos(i/N*PI2);
    if(i===0)X.moveTo(cx+p[0],cy+p[1]);
    else X.lineTo(cx+p[0],cy+p[1]);
  }
  X.strokeStyle=rgba(TEAL,0.1);
  X.lineWidth=1.2;
  X.stroke();
}

function drawPedalPath(pts,count,gap2){
  var started=false;
  X.beginPath();
  for(var i=0;i<count;i++){
    if(!pts[i]){started=false;continue;}
    var fx=cx+pts[i][0],fy=cy+pts[i][1];
    if(!started){X.moveTo(fx,fy);started=true;continue;}
    var prev=pts[i-1];
    if(!prev){X.moveTo(fx,fy);continue;}
    var ddx=pts[i][0]-prev[0],ddy=pts[i][1]-prev[1];
    if(ddx*ddx+ddy*ddy>gap2)X.moveTo(fx,fy);
    else X.lineTo(fx,fy);
  }
  if(count>=N&&pts[0]&&pts[N-1]){
    var ddx=pts[0][0]-pts[N-1][0],ddy=pts[0][1]-pts[N-1][1];
    if(ddx*ddx+ddy*ddy<gap2)X.lineTo(cx+pts[0][0],cy+pts[0][1]);
  }
  X.stroke();
}

function drawPedal(pts,count){
  if(count<2)return;
  var gap2=S*S*0.25;
  var layers=[[8,0.04],[4,0.12],[1.8,0.6]];
  for(var L=0;L<3;L++){
    X.lineWidth=layers[L][0];
    X.strokeStyle=rgba(TEAL,layers[L][1]);
    drawPedalPath(pts,count,gap2);
  }
}

function drawConstruction(t){
  var cv=curves[curveIdx];
  var p=cv.pos(t),v=cv.vel(t);
  var len2=v[0]*v[0]+v[1]*v[1];
  if(len2<1e-10)return;
  var len=Math.sqrt(len2);
  var dx=p[0]-px,dy=p[1]-py;
  var s=(dx*v[0]+dy*v[1])/len2;
  var fx=p[0]-s*v[0],fy=p[1]-s*v[1];
  var ux=v[0]/len,uy=v[1]/len;
  var ext=S*2.5;

  X.beginPath();
  X.moveTo(cx+p[0]-ext*ux,cy+p[1]-ext*uy);
  X.lineTo(cx+p[0]+ext*ux,cy+p[1]+ext*uy);
  X.strokeStyle=rgba(PURP,0.28);
  X.lineWidth=1;
  X.stroke();

  X.beginPath();
  X.moveTo(cx+px,cy+py);
  X.lineTo(cx+fx,cy+fy);
  X.strokeStyle=rgba(TEAL,0.22);
  X.lineWidth=1;
  X.setLineDash([4,4]);
  X.stroke();
  X.setLineDash([]);

  X.beginPath();
  X.arc(cx+p[0],cy+p[1],3.5,0,PI2);
  X.fillStyle=rgba(PURP,0.8);
  X.fill();

  X.beginPath();
  X.arc(cx+fx,cy+fy,3.5,0,PI2);
  X.fillStyle=rgba(TEAL,0.9);
  X.fill();
}

function drawPedalPt(){
  X.beginPath();
  X.arc(cx+px,cy+py,10,0,PI2);
  X.fillStyle=rgba(TEAL,0.08);
  X.fill();

  X.beginPath();
  X.arc(cx+px,cy+py,5.5,0,PI2);
  X.fillStyle='rgba(255,255,255,0.9)';
  X.fill();

  X.beginPath();
  X.arc(cx+px,cy+py,2.5,0,PI2);
  X.fillStyle='#00f5d4';
  X.fill();
}

function drawLeading(pts,count){
  if(sweepDone||count<1||count>=N)return;
  var pt=pts[count-1];
  if(!pt)return;
  X.beginPath();
  X.arc(cx+pt[0],cy+pt[1],4,0,PI2);
  X.shadowColor='#00f5d4';
  X.shadowBlur=12;
  X.fillStyle=rgba(TEAL,0.9);
  X.fill();
  X.shadowBlur=0;
}

function frame(now){
  if(!lastTime)lastTime=now;
  var dt=Math.min((now-lastTime)/1000,0.05);
  lastTime=now;

  animT+=dt*speed*1.5;
  if(animT>PI2){animT-=PI2;sweepDone=true;}

  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  drawOrigin();
  drawBase();

  var pts=computePedal();
  var count=(sweepDone||dragging)?N:Math.floor(animT/PI2*N);
  drawPedal(pts,count);
  drawLeading(pts,count);
  drawConstruction(animT);
  drawPedalPt();

  requestAnimationFrame(frame);
}

function wx(sx){return sx-cx;}
function wy(sy){return sy-cy;}
function nearPedal(sx,sy){var dx=sx-(cx+px),dy=sy-(cy+py);return dx*dx+dy*dy<625;}

function setCurve(idx){
  curveIdx=idx;
  var d=curves[idx].defP();
  px=d[0];py=d[1];
  animT=0;sweepDone=false;
  updateUI();
  if(window.SFX)SFX.play('toggle');
}

function resetPedal(){
  var d=curves[curveIdx].defP();
  px=d[0];py=d[1];
  animT=0;sweepDone=false;
  if(window.SFX)SFX.play('click');
}

function applyPreset(idx){
  if(idx===0){curveIdx=0;px=S;py=0;}
  else if(idx===1){curveIdx=0;px=S*1.5;py=0;}
  else if(idx===2){curveIdx=1;var a=S*1.4,b=S*0.85;px=Math.sqrt(a*a-b*b);py=0;}
  animT=0;sweepDone=false;
  updateUI();
  if(window.SFX)SFX.play('toggle');
}

function updateUI(){
  document.getElementById('hud-curve').textContent=curves[curveIdx].name;
  var btns=document.querySelectorAll('#curve-btns .ctrl-btn');
  for(var i=0;i<btns.length;i++)btns[i].classList.toggle('active',i===curveIdx);
}

C.addEventListener('mousedown',function(e){
  if(e.button===2)return;
  if(nearPedal(e.clientX,e.clientY)){
    dragging=true;didDrag=false;
    dragOX=px-wx(e.clientX);dragOY=py-wy(e.clientY);
    C.style.cursor='grabbing';
    sweepDone=true;
  }
});

window.addEventListener('mousemove',function(e){
  if(dragging){
    px=wx(e.clientX)+dragOX;
    py=wy(e.clientY)+dragOY;
    didDrag=true;
  }else{
    C.style.cursor=nearPedal(e.clientX,e.clientY)?'grab':'crosshair';
  }
});

window.addEventListener('mouseup',function(){
  if(dragging){
    dragging=false;
    C.style.cursor='crosshair';
    if(window.SFX)SFX.play('move');
  }
});

C.addEventListener('click',function(e){
  if(didDrag){didDrag=false;return;}
  if(e.button!==0)return;
  if(!nearPedal(e.clientX,e.clientY)){
    px=wx(e.clientX);py=wy(e.clientY);
    animT=0;sweepDone=false;
    if(window.SFX)SFX.play('spawn');
  }
});

C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  resetPedal();
});

C.addEventListener('touchstart',function(e){
  if(touchID!==null)return;
  var t=e.touches[0];
  touchID=t.identifier;
  tapX=t.clientX;tapY=t.clientY;wasTap=true;
  if(nearPedal(t.clientX,t.clientY)){
    e.preventDefault();
    dragging=true;
    dragOX=px-wx(t.clientX);dragOY=py-wy(t.clientY);
    sweepDone=true;wasTap=false;
  }
},{passive:false});

window.addEventListener('touchmove',function(e){
  if(touchID===null)return;
  for(var i=0;i<e.touches.length;i++){
    if(e.touches[i].identifier===touchID){
      var t=e.touches[i];
      if(dragging){
        e.preventDefault();
        px=wx(t.clientX)+dragOX;py=wy(t.clientY)+dragOY;
      }
      if(Math.abs(t.clientX-tapX)+Math.abs(t.clientY-tapY)>10)wasTap=false;
      break;
    }
  }
},{passive:false});

window.addEventListener('touchend',function(e){
  for(var i=0;i<e.changedTouches.length;i++){
    if(e.changedTouches[i].identifier===touchID){
      if(dragging){
        dragging=false;
        if(window.SFX)SFX.play('move');
      }else if(wasTap){
        px=wx(tapX);py=wy(tapY);
        animT=0;sweepDone=false;
        if(window.SFX)SFX.play('spawn');
      }
      touchID=null;
      break;
    }
  }
});

var curveBtns=document.querySelectorAll('#curve-btns .ctrl-btn');
for(var i=0;i<curveBtns.length;i++){
  (function(idx){curveBtns[idx].addEventListener('click',function(){setCurve(idx);});})(i);
}
var presetBtns=document.querySelectorAll('#preset-btns .preset-btn');
for(var i=0;i<presetBtns.length;i++){
  (function(idx){presetBtns[idx].addEventListener('click',function(){applyPreset(idx);});})(i);
}

document.getElementById('sl-speed').addEventListener('input',function(){
  speed=parseFloat(this.value)/5;
  document.getElementById('sv-speed').textContent=this.value;
});

document.getElementById('btn-reset').addEventListener('click',resetPedal);

resize();
window.addEventListener('resize',resize);
var d=curves[0].defP();
px=d[0];py=d[1];
updateUI();
requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
