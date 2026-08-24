<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('stereo');

$pageName = 'stereo';
$pageTitle = 'STEREO - PULSE';
$pageDesc = '立体射影。球面上の格子・航路・多面体が平面の円として映し出され、回転とともに変形する等角写像の可視化。';
$pageCanonical = 'https://pulse.subara3.com/stereo.php';
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
  <div>MODE <span id="hud-mode">GRID</span></div>
  <div>CIRCLES <span id="hud-count">0</span></div>
</div>
<div id="info-label"><span>STEREO</span></div>
<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'rotate'],
  ['type' => 'click', 'label' => 'circle'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row" id="mode-btns">
    <button class="ctrl-btn active" data-val="0">GRID</button>
    <button class="ctrl-btn" data-val="1">GREAT</button>
    <button class="ctrl-btn" data-val="2">LOXO</button>
    <button class="ctrl-btn" data-val="3">ICOSA</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">DENSITY</label>
    <input type="range" id="sl-dens" min="4" max="24" value="12">
    <span class="slider-value" id="sv-dens">12</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-spd" min="0" max="100" value="30">
    <span class="slider-value" id="sv-spd">30</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">ZOOM</label>
    <input type="range" id="sl-zoom" min="20" max="300" value="100">
    <span class="slider-value" id="sv-zoom">1.0</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;
var MODES=['GRID','GREAT','LOXO','ICOSA'];
var modeIdx=0;
var rotA=0.3,rotB=0;
var autoRot=true,autoTimer=null;
var spd=0.003;
var dens=12;
var zoom=1;
var userGC=[];
var particles=[];
var MAX_P=120;
var curveCache=null,cacheKey='';
var cosA,sinA,cosB,sinB;
var drag=false,lx=0,ly=0,raDrag=0,rbDrag=0;
var fc=0;

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
}

function updateTrig(){
  cosA=Math.cos(rotA);sinA=Math.sin(rotA);
  cosB=Math.cos(rotB);sinB=Math.sin(rotB);
}

function bScale(){return Math.min(W,H)*0.22*zoom;}

function genGrid(){
  var curves=[],n=dens;
  for(var i=1;i<n;i++){
    var phi=(i/n)*Math.PI;
    var r=Math.sin(phi),zz=Math.cos(phi);
    var pts=[];
    for(var j=0;j<=72;j++){
      var t=(j/72)*Math.PI*2;
      pts.push(r*Math.cos(t),r*Math.sin(t),zz);
    }
    curves.push({pts:pts,hue:172+(i/n)*22});
  }
  for(var i=0;i<n;i++){
    var theta=(i/n)*Math.PI;
    var pts=[];
    for(var j=0;j<=72;j++){
      var phi2=(j/72)*Math.PI;
      var r=Math.sin(phi2),z=Math.cos(phi2);
      pts.push(r*Math.cos(theta),r*Math.sin(theta),z);
    }
    curves.push({pts:pts,hue:178});
  }
  return curves;
}

function gcFromNormal(nx,ny,nz,hue){
  var ux,uy,uz;
  if(Math.abs(nz)<0.9){ux=-ny;uy=nx;uz=0;}
  else{ux=0;uy=-nz;uz=ny;}
  var len=Math.sqrt(ux*ux+uy*uy+uz*uz);
  if(len<1e-10)return{pts:[],hue:hue||180};
  ux/=len;uy/=len;uz/=len;
  var vx=ny*uz-nz*uy,vy=nz*ux-nx*uz,vz=nx*uy-ny*ux;
  var pts=[];
  for(var j=0;j<=72;j++){
    var t=(j/72)*Math.PI*2;
    var ct=Math.cos(t),st=Math.sin(t);
    pts.push(ux*ct+vx*st,uy*ct+vy*st,uz*ct+vz*st);
  }
  return{pts:pts,hue:hue||180};
}

function genGreat(){
  var curves=[],n=dens;
  for(var i=0;i<n;i++){
    var a1=(i/n)*Math.PI;
    var a2=i*2.399;
    var nx=Math.sin(a1)*Math.cos(a2);
    var ny=Math.sin(a1)*Math.sin(a2);
    var nz=Math.cos(a1);
    curves.push(gcFromNormal(nx,ny,nz,170+(i/n)*25));
  }
  return curves;
}

function genLoxo(){
  var curves=[];
  var n=Math.max(3,Math.floor(dens*0.6));
  var alpha=1.15;
  var cotAl=1/Math.tan(alpha);
  for(var i=0;i<n;i++){
    var lam0=(i/n)*Math.PI*2;
    var pts=[];
    for(var j=0;j<=400;j++){
      var lat=-1.48+(j/400)*2.96;
      var arg=Math.PI/4+lat/2;
      if(arg<=0.02||arg>=Math.PI-0.02)continue;
      var mp=Math.tan(arg);
      if(mp<=0)continue;
      var lon=lam0+cotAl*Math.log(mp);
      var clat=Math.cos(lat);
      pts.push(clat*Math.cos(lon),clat*Math.sin(lon),Math.sin(lat));
    }
    curves.push({pts:pts,hue:175+(i/n)*20});
  }
  return curves;
}

function genIcosa(){
  var PHI=(1+Math.sqrt(5))/2;
  var a=1/Math.sqrt(1+PHI*PHI),b=PHI*a;
  var V=[[-a,b,0],[a,b,0],[-a,-b,0],[a,-b,0],
    [0,-a,b],[0,a,b],[0,-a,-b],[0,a,-b],
    [b,0,-a],[b,0,a],[-b,0,-a],[-b,0,a]];
  var E=[[0,11],[0,5],[0,1],[0,7],[0,10],
    [1,5],[5,11],[11,10],[10,7],[7,1],
    [3,9],[3,4],[3,2],[3,6],[3,8],
    [4,9],[2,4],[6,2],[8,6],[9,8],
    [1,9],[5,4],[11,2],[10,6],[7,8],
    [4,5],[2,11],[6,10],[8,7],[9,1]];
  var curves=[];
  for(var ei=0;ei<E.length;ei++){
    var vi=V[E[ei][0]],vj=V[E[ei][1]];
    var dot=vi[0]*vj[0]+vi[1]*vj[1]+vi[2]*vj[2];
    var omega=Math.acos(Math.max(-1,Math.min(1,dot)));
    var so=Math.sin(omega);
    var pts=[];
    for(var k=0;k<=40;k++){
      var t=k/40;
      if(omega<0.001){pts.push(vi[0],vi[1],vi[2]);}
      else{
        var s1=Math.sin((1-t)*omega)/so;
        var s2=Math.sin(t*omega)/so;
        pts.push(s1*vi[0]+s2*vj[0],s1*vi[1]+s2*vj[1],s1*vi[2]+s2*vj[2]);
      }
    }
    curves.push({pts:pts,hue:177+Math.sin(ei*0.7)*8});
  }
  return curves;
}

function getCurves(){
  var key=modeIdx+'-'+dens;
  if(key!==cacheKey){
    switch(modeIdx){
      case 0:curveCache=genGrid();break;
      case 1:curveCache=genGreat();break;
      case 2:curveCache=genLoxo();break;
      case 3:curveCache=genIcosa();break;
    }
    cacheKey=key;
  }
  var all=curveCache.slice();
  for(var i=0;i<userGC.length;i++){
    var g=userGC[i];
    all.push(gcFromNormal(g[0],g[1],g[2],275));
  }
  return all;
}

function drawCurve(pts,hue,alpha,width){
  var s=bScale();
  var n=pts.length/3;
  if(n<2)return;
  X.strokeStyle='hsla('+hue+',70%,55%,'+alpha+')';
  X.lineWidth=width;
  X.beginPath();
  var penDown=false,psx=0,psy=0;
  var maxJ2=Math.min(W,H);maxJ2*=maxJ2*0.3;
  for(var i=0;i<n;i++){
    var ox=pts[i*3],oy=pts[i*3+1],oz=pts[i*3+2];
    var x1=ox*cosB+oz*sinB;
    var z1=-ox*sinB+oz*cosB;
    var y2=oy*cosA-z1*sinA;
    var z2=oy*sinA+z1*cosA;
    if(z2>0.97){penDown=false;continue;}
    var inv=1/(1-z2);
    var px=x1*inv,py=y2*inv;
    if(px*px+py*py>64){penDown=false;continue;}
    var sx=W*0.5+px*s,sy=H*0.5-py*s;
    if(!penDown){X.moveTo(sx,sy);penDown=true;}
    else{
      var dx=sx-psx,dy=sy-psy;
      if(dx*dx+dy*dy>maxJ2){X.moveTo(sx,sy);}
      else{X.lineTo(sx,sy);}
    }
    psx=sx;psy=sy;
  }
  X.stroke();
}

function updateParts(curves){
  if(curves.length>0&&particles.length<MAX_P&&fc%3===0){
    var ci=Math.floor(Math.random()*curves.length);
    var c=curves[ci];
    if(c.pts.length>6){
      particles.push({ci:ci,t:Math.random(),sp:0.002+Math.random()*0.003});
    }
  }
  for(var i=particles.length-1;i>=0;i--){
    particles[i].t+=particles[i].sp;
    if(particles[i].t>=1||particles[i].ci>=curves.length){
      particles.splice(i,1);
    }
  }
}

function drawParts(curves){
  var s=bScale();
  for(var i=0;i<particles.length;i++){
    var p=particles[i];
    if(p.ci>=curves.length)continue;
    var pts=curves[p.ci].pts;
    var n=pts.length/3;
    var idx=Math.floor(p.t*(n-1));
    if(idx<0||idx>=n)continue;
    var ox=pts[idx*3],oy=pts[idx*3+1],oz=pts[idx*3+2];
    var x1=ox*cosB+oz*sinB;
    var z1=-ox*sinB+oz*cosB;
    var y2=oy*cosA-z1*sinA;
    var z2=oy*sinA+z1*cosA;
    if(z2>0.97)continue;
    var inv=1/(1-z2);
    var px=x1*inv,py=y2*inv;
    if(px*px+py*py>64)continue;
    var sx=W*0.5+px*s,sy=H*0.5-py*s;
    var fade=1-Math.abs(p.t*2-1);
    var isUser=curves[p.ci].hue>250;
    X.globalAlpha=0.7*fade;
    X.fillStyle=isUser?'#7b2cbf':'#00f5d4';
    X.beginPath();
    X.arc(sx,sy,2.5,0,Math.PI*2);
    X.fill();
  }
  X.globalAlpha=1;
}

function screenToSphere(sx,sy){
  var s=bScale();
  var px=(sx-W*0.5)/s;
  var py=-(sy-H*0.5)/s;
  var r2=px*px+py*py;
  var d=1+r2;
  var rx=2*px/d,ry=2*py/d,rz=(r2-1)/d;
  var iy=ry*cosA+rz*sinA;
  var iz=-ry*sinA+rz*cosA;
  var ix=rx*cosB-iz*sinB;
  var fz=rx*sinB+iz*cosB;
  return[ix,iy,fz];
}

var hudMode=document.getElementById('hud-mode');
var hudCount=document.getElementById('hud-count');
function updateHUD(){
  if(hudMode)hudMode.textContent=MODES[modeIdx];
  if(hudCount)hudCount.textContent=userGC.length;
}

function frame(){
  fc++;
  if(autoRot){
    rotB+=spd;
    rotA+=spd*0.37;
  }
  updateTrig();

  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  var curves=getCurves();

  var eqR=bScale();
  X.strokeStyle='rgba(0,245,212,0.04)';
  X.lineWidth=1;
  X.beginPath();
  X.arc(W*0.5,H*0.5,eqR,0,Math.PI*2);
  X.stroke();

  X.globalCompositeOperation='lighter';
  for(var i=0;i<curves.length;i++){
    drawCurve(curves[i].pts,curves[i].hue,0.05,5);
  }
  for(var i=0;i<curves.length;i++){
    drawCurve(curves[i].pts,curves[i].hue,0.45,1.2);
  }
  X.globalCompositeOperation='source-over';

  updateParts(curves);
  drawParts(curves);

  requestAnimationFrame(frame);
}

function pDown(x,y,isRight){
  if(isRight){
    if(userGC.length>0){
      userGC.length=0;
      if(window.SFX)SFX.play('pop');
      updateHUD();
    }
    return;
  }
  drag=true;lx=x;ly=y;
  raDrag=rotA;rbDrag=rotB;
  autoRot=false;
  if(autoTimer){clearTimeout(autoTimer);autoTimer=null;}
}
function pMove(x,y){
  if(!drag)return;
  rotB=rbDrag+(x-lx)*0.005;
  rotA=raDrag-(y-ly)*0.005;
}
function pUp(x,y){
  if(!drag)return;
  var dx=x-lx,dy=y-ly;
  if(dx*dx+dy*dy<16){
    updateTrig();
    var sp=screenToSphere(x,y);
    var len=Math.sqrt(sp[0]*sp[0]+sp[1]*sp[1]+sp[2]*sp[2]);
    if(len>0.01){
      userGC.push([sp[0]/len,sp[1]/len,sp[2]/len]);
      if(window.SFX)SFX.play('spawn');
      updateHUD();
    }
  }else{
    if(window.SFX)SFX.play('move',100);
  }
  drag=false;
  autoTimer=setTimeout(function(){autoRot=true;},3000);
}

C.addEventListener('mousedown',function(e){e.preventDefault();pDown(e.clientX,e.clientY,e.button===2);});
C.addEventListener('mousemove',function(e){pMove(e.clientX,e.clientY);});
C.addEventListener('mouseup',function(e){pUp(e.clientX,e.clientY);});
window.addEventListener('mouseup',function(){if(drag){drag=false;autoTimer=setTimeout(function(){autoRot=true;},3000);}});
C.addEventListener('contextmenu',function(e){e.preventDefault();});

C.addEventListener('wheel',function(e){
  e.preventDefault();
  zoom*=e.deltaY>0?0.92:1.08;
  zoom=Math.max(0.2,Math.min(4,zoom));
  var zs=document.getElementById('sl-zoom');
  if(zs)zs.value=Math.round(zoom*100);
  var zv=document.getElementById('sv-zoom');
  if(zv)zv.textContent=zoom.toFixed(1);
},{passive:false});

var pinchDist=0;
C.addEventListener('touchstart',function(e){
  e.preventDefault();
  if(e.touches.length===2){
    var dx=e.touches[0].clientX-e.touches[1].clientX;
    var dy=e.touches[0].clientY-e.touches[1].clientY;
    pinchDist=Math.sqrt(dx*dx+dy*dy);
  }else if(e.touches.length===1){
    pDown(e.touches[0].clientX,e.touches[0].clientY,false);
  }
},{passive:false});
C.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(e.touches.length===2){
    var dx=e.touches[0].clientX-e.touches[1].clientX;
    var dy=e.touches[0].clientY-e.touches[1].clientY;
    var d=Math.sqrt(dx*dx+dy*dy);
    if(pinchDist>0){
      zoom*=d/pinchDist;
      zoom=Math.max(0.2,Math.min(4,zoom));
      var zs=document.getElementById('sl-zoom');
      if(zs)zs.value=Math.round(zoom*100);
      var zv=document.getElementById('sv-zoom');
      if(zv)zv.textContent=zoom.toFixed(1);
    }
    pinchDist=d;
  }else if(e.touches.length===1){
    pMove(e.touches[0].clientX,e.touches[0].clientY);
  }
},{passive:false});
C.addEventListener('touchend',function(e){
  pinchDist=0;
  if(e.changedTouches.length&&e.touches.length===0){
    pUp(e.changedTouches[0].clientX,e.changedTouches[0].clientY);
  }
});

var modeBtns=document.querySelectorAll('#mode-btns .ctrl-btn');
modeBtns.forEach(function(btn){
  btn.addEventListener('click',function(){
    modeIdx=parseInt(this.dataset.val);
    modeBtns.forEach(function(b){b.classList.remove('active');});
    this.classList.add('active');
    cacheKey='';
    particles.length=0;
    if(window.SFX)SFX.play('toggle');
    updateHUD();
  });
});

var slDens=document.getElementById('sl-dens');
var svDens=document.getElementById('sv-dens');
if(slDens)slDens.addEventListener('input',function(){
  dens=parseInt(this.value);
  if(svDens)svDens.textContent=dens;
  cacheKey='';particles.length=0;
});

var slSpd=document.getElementById('sl-spd');
var svSpd=document.getElementById('sv-spd');
if(slSpd)slSpd.addEventListener('input',function(){
  var v=parseInt(this.value);
  spd=v*0.0001;
  if(svSpd)svSpd.textContent=v;
});

var slZoom=document.getElementById('sl-zoom');
var svZoom=document.getElementById('sv-zoom');
if(slZoom)slZoom.addEventListener('input',function(){
  zoom=parseInt(this.value)/100;
  if(svZoom)svZoom.textContent=zoom.toFixed(1);
});

var btnReset=document.getElementById('btn-reset');
if(btnReset)btnReset.addEventListener('click',function(){
  userGC.length=0;
  rotA=0.3;rotB=0;
  zoom=1;autoRot=true;
  particles.length=0;
  if(slZoom){slZoom.value=100;}
  if(svZoom){svZoom.textContent='1.0';}
  if(slDens){slDens.value=12;}
  if(svDens){svDens.textContent='12';}
  dens=12;cacheKey='';
  if(slSpd){slSpd.value=30;}
  if(svSpd){svSpd.textContent='30';}
  spd=0.003;
  if(window.SFX)SFX.play('click');
  updateHUD();
});

resize();
window.addEventListener('resize',resize);
updateHUD();
frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
