<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('xymodel');

$pageName = 'xymodel';
$pageTitle = 'XY MODEL - PULSE';
$pageDesc = 'XYモデル。格子上の連続スピンが温度に従い配向する。低温では渦欠陥が対を組み、BKT転移で解離する。';
$pageCanonical = 'https://pulse.subara3.com/xymodel.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .bp{color:#7b2cbf;font-weight:700}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  T <b id="h-temp">0.80</b><br>
  VORTEX <b id="h-vp">0</b> <span class="bp" id="h-vm">0</span><br>
  E <b id="h-e">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'vortex'],
  ['type' => 'drag', 'label' => 'align'],
  ['type' => 'right', 'label' => 'antivortex'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="slider-row"><span class="slider-label">TEMP</span><input type="range" id="sl-temp" min="1" max="300" value="80"><span class="slider-value" id="sv-temp">0.80</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="20" value="5"><span class="slider-value" id="sv-speed">5</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-vortex">VORTICES</button>
    <button class="control-btn" id="btn-view">ARROWS</button>
  </div></div>
  <div class="preset-group">
    <button class="preset-btn" data-preset="ordered">ORDERED</button>
    <button class="preset-btn" data-preset="random">RANDOM</button>
    <button class="preset-btn" data-preset="pair">VORTEX PAIR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,gw,gh;
var grid;
var TWO_PI=2*Math.PI,PI=Math.PI;
var T=0.80,sweeps=5,showV=true,showArrows=false;
var oc,ox;

var CT=new Uint8Array(1024*3);
(function(){
  for(var i=0;i<1024;i++){
    var a=i/1024*TWO_PI;
    var h=165+65*(1-Math.cos(a));
    var c=(1-Math.abs(2*0.52-1))*0.85;
    var x2=c*(1-Math.abs((h/60)%2-1));
    var m=0.52-c/2;
    var r,g,b;
    if(h<60){r=c;g=x2;b=0;}
    else if(h<120){r=x2;g=c;b=0;}
    else if(h<180){r=0;g=c;b=x2;}
    else if(h<240){r=0;g=x2;b=c;}
    else if(h<300){r=x2;g=0;b=c;}
    else{r=c;g=0;b=x2;}
    CT[i*3]=(r+m)*255|0;
    CT[i*3+1]=(g+m)*255|0;
    CT[i*3+2]=(b+m)*255|0;
  }
})();

var hTemp=document.getElementById('h-temp');
var hVp=document.getElementById('h-vp');
var hVm=document.getElementById('h-vm');
var hE=document.getElementById('h-e');

function setup(){
  W=C.width=innerWidth;H=C.height=innerHeight;
  var minD=Math.min(W,H);
  var cs=Math.max(3,Math.floor(minD/180));
  gw=Math.floor(W/cs);gh=Math.floor(H/cs);
  oc=document.createElement('canvas');
  oc.width=gw;oc.height=gh;
  ox=oc.getContext('2d');
}

function initOrdered(){
  grid=new Float64Array(gw*gh);
  for(var i=0;i<grid.length;i++) grid[i]=0;
}
function initRandom(){
  grid=new Float64Array(gw*gh);
  for(var i=0;i<grid.length;i++) grid[i]=Math.random()*TWO_PI;
}
function initVortexPair(){
  grid=new Float64Array(gw*gh);
  var cx1=Math.floor(gw*0.4),cy1=Math.floor(gh*0.5);
  var cx2=Math.floor(gw*0.6),cy2=Math.floor(gh*0.5);
  for(var j=0;j<gh;j++){
    for(var i=0;i<gw;i++){
      var a1=Math.atan2(j-cy1,i-cx1);
      var a2=Math.atan2(j-cy2,i-cx2);
      grid[j*gw+i]=a1-a2;
    }
  }
}

function wrapA(d){
  while(d>PI)d-=TWO_PI;
  while(d<-PI)d+=TWO_PI;
  return d;
}

function sweep(){
  var N=gw*gh;
  for(var k=0;k<N;k++){
    var i=(Math.random()*gw)|0;
    var j=(Math.random()*gh)|0;
    var idx=j*gw+i;
    var th=grid[idx];
    var nth=th+(Math.random()-0.5)*PI;
    var dE=0;
    var jm=(j-1+gh)%gh,jp=(j+1)%gh,im=(i-1+gw)%gw,ip=(i+1)%gw;
    var n0=grid[jm*gw+i],n1=grid[jp*gw+i],n2=grid[j*gw+im],n3=grid[j*gw+ip];
    dE+=Math.cos(th-n0)-Math.cos(nth-n0);
    dE+=Math.cos(th-n1)-Math.cos(nth-n1);
    dE+=Math.cos(th-n2)-Math.cos(nth-n2);
    dE+=Math.cos(th-n3)-Math.cos(nth-n3);
    if(dE<=0||Math.random()<Math.exp(-dE/T)){
      grid[idx]=nth;
    }
  }
}

function energy(){
  var e=0,n=0;
  for(var j=0;j<gh;j++){
    for(var i=0;i<gw;i++){
      var th=grid[j*gw+i];
      var ip=(i+1)%gw,jp=(j+1)%gh;
      e-=Math.cos(th-grid[j*gw+ip]);
      e-=Math.cos(th-grid[jp*gw+i]);
      n+=2;
    }
  }
  return e/n;
}

var vortices=[];
function detectVortices(){
  vortices.length=0;
  for(var j=0;j<gh;j++){
    for(var i=0;i<gw;i++){
      var ip=(i+1)%gw,jp=(j+1)%gh;
      var t00=grid[j*gw+i];
      var t10=grid[j*gw+ip];
      var t11=grid[jp*gw+ip];
      var t01=grid[jp*gw+i];
      var w=wrapA(t10-t00)+wrapA(t11-t10)+wrapA(t01-t11)+wrapA(t00-t01);
      if(w>PI) vortices.push(i+0.5,j+0.5,1);
      else if(w<-PI) vortices.push(i+0.5,j+0.5,-1);
    }
  }
}

function render(){
  var img=ox.createImageData(gw,gh);
  var d=img.data;
  for(var k=0,len=gw*gh;k<len;k++){
    var th=grid[k];
    th=((th%TWO_PI)+TWO_PI)%TWO_PI;
    var ci=(th/TWO_PI*1024)|0;
    if(ci>1023)ci=1023;
    var p=k*4;
    d[p]=CT[ci*3];d[p+1]=CT[ci*3+1];d[p+2]=CT[ci*3+2];d[p+3]=255;
  }
  ox.putImageData(img,0,0);
  X.imageSmoothingEnabled=true;
  X.drawImage(oc,0,0,W,H);

  if(showArrows){
    var sx=W/gw,sy=H/gh;
    var step=Math.max(1,Math.floor(Math.min(gw,gh)/40));
    X.strokeStyle='rgba(255,255,255,0.5)';
    X.lineWidth=1;
    var al=sx*step*0.35;
    for(var j=0;j<gh;j+=step){
      for(var i=0;i<gw;i+=step){
        var th=grid[j*gw+i];
        var px=(i+0.5)*sx,py=(j+0.5)*sy;
        var dx=Math.cos(th)*al,dy=Math.sin(th)*al;
        X.beginPath();X.moveTo(px-dx,py-dy);X.lineTo(px+dx,py+dy);X.stroke();
      }
    }
  }

  if(showV){
    var sx2=W/gw,sy2=H/gh;
    var r=Math.max(2,Math.min(sx2,sy2)*0.3);
    for(var vi=0;vi<vortices.length;vi+=3){
      var vx=vortices[vi]*sx2,vy=vortices[vi+1]*sy2,vc=vortices[vi+2];
      X.beginPath();X.arc(vx,vy,r,0,TWO_PI);
      X.fillStyle=vc>0?'rgba(255,255,255,0.85)':'rgba(123,44,191,0.9)';
      X.fill();
    }
  }
}

var frameC=0;
function draw(){
  for(var s=0;s<sweeps;s++) sweep();
  if(frameC%3===0) detectVortices();
  render();
  if(frameC%10===0){
    var vp=0,vm=0;
    for(var vi=0;vi<vortices.length;vi+=3){
      if(vortices[vi+2]>0)vp++;else vm++;
    }
    hTemp.textContent=T.toFixed(2);
    hVp.textContent='+'+vp;
    hVm.textContent='-'+vm;
    hE.textContent=energy().toFixed(3);
  }
  frameC++;
  requestAnimationFrame(draw);
}

function imposeVortex(gi,gj,charge){
  var rad=Math.max(4,Math.floor(Math.min(gw,gh)*0.04));
  for(var dj=-rad;dj<=rad;dj++){
    for(var di=-rad;di<=rad;di++){
      var d2=di*di+dj*dj;
      if(d2>rad*rad||d2<1)continue;
      var ti=(gi+di+gw)%gw,tj=(gj+dj+gh)%gh;
      grid[tj*gw+ti]=charge*Math.atan2(dj,di);
    }
  }
}

function alignSpins(gi,gj){
  var rad=Math.max(3,Math.floor(Math.min(gw,gh)*0.03));
  for(var dj=-rad;dj<=rad;dj++){
    for(var di=-rad;di<=rad;di++){
      if(di*di+dj*dj>rad*rad)continue;
      var ti=(gi+di+gw)%gw,tj=(gj+dj+gh)%gh;
      grid[tj*gw+ti]=Math.atan2(dj,di);
    }
  }
}

var dragging=false,dragBtn=0;
function toGrid(ex,ey){
  return[(ex/W*gw)|0,(ey/H*gh)|0];
}
C.addEventListener('mousedown',function(e){
  e.preventDefault();
  dragging=true;dragBtn=e.button;
  var g=toGrid(e.clientX,e.clientY);
  if(e.button===2){
    imposeVortex(g[0],g[1],-1);
    SFX.play('pop');
  }else{
    imposeVortex(g[0],g[1],1);
    SFX.play('spawn');
  }
});
C.addEventListener('mousemove',function(e){
  if(!dragging)return;
  var g=toGrid(e.clientX,e.clientY);
  alignSpins(g[0],g[1]);
});
C.addEventListener('mouseup',function(){dragging=false;});
C.addEventListener('contextmenu',function(e){e.preventDefault();});

var tId=null;
C.addEventListener('touchstart',function(e){
  e.preventDefault();
  var t=e.touches[0];
  var g=toGrid(t.clientX,t.clientY);
  dragging=true;
  tId=setTimeout(function(){
    imposeVortex(g[0],g[1],-1);
    SFX.play('pop');
    tId='long';
  },400);
  imposeVortex(g[0],g[1],1);
  SFX.play('spawn');
});
C.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(tId&&tId!=='long'){clearTimeout(tId);tId=null;}
  var t=e.touches[0];
  var g=toGrid(t.clientX,t.clientY);
  alignSpins(g[0],g[1]);
});
C.addEventListener('touchend',function(){
  dragging=false;
  if(tId&&tId!=='long')clearTimeout(tId);
  tId=null;
});

var slTemp=document.getElementById('sl-temp'),svTemp=document.getElementById('sv-temp');
var slSpeed=document.getElementById('sl-speed'),svSpeed=document.getElementById('sv-speed');
slTemp.addEventListener('input',function(){
  T=this.value/100;
  svTemp.textContent=T.toFixed(2);
  SFX.play('toggle',80);
});
slSpeed.addEventListener('input',function(){
  sweeps=+this.value;
  svSpeed.textContent=sweeps;
  SFX.play('toggle',80);
});

document.getElementById('btn-vortex').addEventListener('click',function(){
  showV=!showV;
  this.textContent=showV?'VORTICES':'NO VORTEX';
  SFX.play('toggle');
});
document.getElementById('btn-view').addEventListener('click',function(){
  showArrows=!showArrows;
  this.textContent=showArrows?'COLOR':'ARROWS';
  SFX.play('toggle');
});

var presets=document.querySelectorAll('.preset-btn');
for(var i=0;i<presets.length;i++){
  presets[i].addEventListener('click',function(){
    var p=this.getAttribute('data-preset');
    if(p==='ordered')initOrdered();
    else if(p==='random')initRandom();
    else if(p==='pair')initVortexPair();
    SFX.play('click');
  });
}

window.addEventListener('resize',function(){
  setup();
  initVortexPair();
});

setup();
initVortexPair();
draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
