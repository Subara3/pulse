<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('karman');

$pageName = 'karman';
$pageTitle = 'KÁRMÁN - PULSE';
$pageDesc = 'カルマン渦列。格子ボルツマン法で流体中の障害物が生む交互の渦を可視化する。';
$pageCanonical = 'https://pulse.subara3.com/karman.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  RE <span class="v" id="hud-re">120</span><br>
  STEP <span class="v" id="hud-step">0</span>
</div>

<div id="info">
  <span class="v">Kármán Vortex Street</span><br>
  Lattice Boltzmann &middot; D2Q9 BGK
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'obstacle'],
  ['type' => 'click', 'label' => 'dye'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="8" value="3" step="1">
      <span class="slider-value" id="sv-speed">3</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">RE</span>
      <input type="range" id="sl-re" min="30" max="200" value="120" step="5">
      <span class="slider-value" id="sv-re">120</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-mode">VORTICITY</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d');
var W,H;

var NX=300,NY=150;
var ex=[0,1,0,-1,0,1,-1,-1,1];
var ey=[0,0,1,0,-1,1,1,-1,-1];
var wt=[4/9,1/9,1/9,1/9,1/9,1/36,1/36,1/36,1/36];
var opp=[0,3,4,1,2,7,8,5,6];

var f=[],ft=[];
for(var k=0;k<9;k++){f[k]=new Float32Array(NX*NY);ft[k]=new Float32Array(NX*NY);}
var rh=new Float32Array(NX*NY);
var vx=new Float32Array(NX*NY);
var vy=new Float32Array(NX*NY);
var ob=new Uint8Array(NX*NY);

var u0=0.08,tau=0.548;
var ocx=Math.round(NX*0.15),ocy=Math.round(NY*0.5),orad=12;
var steps=0,spf=3;
var cmode=0,cmNames=['VORTICITY','VELOCITY','DENSITY'];

var oC=document.createElement('canvas');
oC.width=NX;oC.height=NY;
var oCtx=oC.getContext('2d');
var iD=oCtx.createImageData(NX,NY);
var px=iD.data;

var NT=600;
var tX=new Float32Array(NT),tY=new Float32Array(NT),tA=new Float32Array(NT);

function resize(){W=C.width=window.innerWidth;H=C.height=window.innerHeight;}
window.addEventListener('resize',resize);
resize();

function buildObs(){
  ob.fill(0);
  var r2=orad*orad;
  for(var y=0;y<NY;y++)for(var x=0;x<NX;x++){
    var dx=x-ocx,dy=y-ocy;
    if(dx*dx+dy*dy<=r2)ob[y*NX+x]=1;
  }
}

function setRe(re){
  var nu=u0*(2*orad)/re;
  tau=3*nu+0.5;
  if(tau<0.505)tau=0.505;
}

function eqF(i,r,ux,uy){
  var eu=ex[i]*ux+ey[i]*uy;
  return wt[i]*r*(1+3*eu+4.5*eu*eu-1.5*(ux*ux+uy*uy));
}

function initFlow(){
  for(var y=0;y<NY;y++)for(var x=0;x<NX;x++){
    var idx=y*NX+x;
    var vxi=u0,vyi=0.01*Math.sin(2*Math.PI*x/NX*3)*Math.sin(2*Math.PI*y/NY*2);
    rh[idx]=1;vx[idx]=vxi;vy[idx]=vyi;
    for(var i=0;i<9;i++)f[i][idx]=eqF(i,1,vxi,vyi);
  }
  steps=0;
  buildObs();
  initTracers();
}

function lbmStep(){
  var it=1/tau;
  for(var idx=0;idx<NX*NY;idx++){
    if(ob[idx]){
      rh[idx]=1;vx[idx]=0;vy[idx]=0;
      var t;
      t=f[1][idx];f[1][idx]=f[3][idx];f[3][idx]=t;
      t=f[2][idx];f[2][idx]=f[4][idx];f[4][idx]=t;
      t=f[5][idx];f[5][idx]=f[7][idx];f[7][idx]=t;
      t=f[6][idx];f[6][idx]=f[8][idx];f[8][idx]=t;
      continue;
    }
    var f0=f[0][idx],f1=f[1][idx],f2=f[2][idx],f3=f[3][idx],
        f4=f[4][idx],f5=f[5][idx],f6=f[6][idx],f7=f[7][idx],f8=f[8][idx];
    var r=f0+f1+f2+f3+f4+f5+f6+f7+f8;
    if(r<1e-6)r=1;
    var ux=(f1-f3+f5-f6-f7+f8)/r;
    var uy=(f2-f4+f5+f6-f7-f8)/r;
    rh[idx]=r;vx[idx]=ux;vy[idx]=uy;
    var usq=ux*ux+uy*uy;
    for(var i=0;i<9;i++){
      var eu=ex[i]*ux+ey[i]*uy;
      f[i][idx]-=it*(f[i][idx]-wt[i]*r*(1+3*eu+4.5*eu*eu-1.5*usq));
    }
  }
  for(var i=0;i<9;i++){
    var exi=ex[i],eyi=ey[i];
    for(var y=0;y<NY;y++){
      for(var x=0;x<NX;x++){
        var sx=x-exi,sy=y-eyi;
        if(sy<0)sy+=NY;else if(sy>=NY)sy-=NY;
        if(sx<0)sx=0;else if(sx>=NX)sx=NX-1;
        ft[i][y*NX+x]=f[i][sy*NX+sx];
      }
    }
  }
  for(var i=0;i<9;i++){var tmp=f[i];f[i]=ft[i];ft[i]=tmp;}
  for(var y=0;y<NY;y++){
    var idx=y*NX;
    var pv=0.008*Math.sin(2*Math.PI*y/NY*3+steps*0.04);
    for(var i=0;i<9;i++)f[i][idx]=eqF(i,1,u0,pv);
  }
  for(var y=0;y<NY;y++){
    var b=y*NX+NX-1,b1=y*NX+NX-2;
    for(var i=0;i<9;i++)f[i][b]=f[i][b1];
  }
  steps++;
  if(steps%500===0&&isNaN(rh[Math.floor(NX*NY/2)]))initFlow();
}

function initTracers(){for(var i=0;i<NT;i++)resetT(i);}
function resetT(i){tX[i]=Math.random()*8;tY[i]=Math.random()*NY;tA[i]=0;}
function updateTracers(){
  for(var i=0;i<NT;i++){
    var x=tX[i],y=tY[i];
    var ix=Math.floor(x),iy=Math.floor(y);
    if(ix<0||ix>=NX-1||iy<0||iy>=NY-1){resetT(i);continue;}
    if(tA[i]>1200){resetT(i);continue;}
    var fx=x-ix,fy=y-iy;
    var i00=iy*NX+ix,i10=i00+1,i01=i00+NX,i11=i01+1;
    var uvx=(1-fx)*(1-fy)*vx[i00]+fx*(1-fy)*vx[i10]+(1-fx)*fy*vx[i01]+fx*fy*vx[i11];
    var uvy=(1-fx)*(1-fy)*vy[i00]+fx*(1-fy)*vy[i10]+(1-fx)*fy*vy[i01]+fx*fy*vy[i11];
    tX[i]+=uvx;tY[i]+=uvy;tA[i]++;
    if(tX[i]<0||tX[i]>=NX||tY[i]<0||tY[i]>=NY){resetT(i);continue;}
    var dx=tX[i]-ocx,dy=tY[i]-ocy;
    if(dx*dx+dy*dy<(orad+1)*(orad+1))resetT(i);
  }
}
function injectDye(gx,gy){
  for(var i=0;i<40;i++){
    var idx=Math.floor(Math.random()*NT);
    tX[idx]=gx+(Math.random()-0.5)*6;
    tY[idx]=gy+(Math.random()-0.5)*6;
    tA[idx]=30;
  }
}

function render(){
  ctx.fillStyle='#0a0a0f';
  ctx.fillRect(0,0,W,H);
  if(cmode===0)renderVort();
  else if(cmode===1)renderVel();
  else renderDen();
  oCtx.putImageData(iD,0,0);
  ctx.imageSmoothingEnabled=true;
  ctx.drawImage(oC,0,0,W,H);
  drawObs();
  drawTracers();
}

function renderVort(){
  for(var y=1;y<NY-1;y++){
    for(var x=1;x<NX-1;x++){
      var idx=y*NX+x,p=idx*4;
      if(ob[idx]){px[p]=8;px[p+1]=8;px[p+2]=12;px[p+3]=255;continue;}
      var dvdx=(vy[idx+1]-vy[idx-1])*0.5;
      var dudy=(vx[idx+NX]-vx[idx-NX])*0.5;
      var w=dvdx-dudy;
      var v=w*3000;
      if(v>1)v=1;else if(v<-1)v=-1;
      if(v>=0){
        px[p]=(10*(1-v))|0;
        px[p+1]=(10+235*v)|0;
        px[p+2]=(15+197*v)|0;
      }else{
        var a=-v;
        px[p]=(10+113*a)|0;
        px[p+1]=(10+34*a)|0;
        px[p+2]=(15+176*a)|0;
      }
      px[p+3]=255;
    }
  }
  fillEdges();
}

function renderVel(){
  for(var y=0;y<NY;y++){
    for(var x=0;x<NX;x++){
      var idx=y*NX+x,p=idx*4;
      if(ob[idx]){px[p]=8;px[p+1]=8;px[p+2]=12;px[p+3]=255;continue;}
      var sp=Math.sqrt(vx[idx]*vx[idx]+vy[idx]*vy[idx]);
      var v=Math.min(1,sp/(u0*2));
      px[p]=(10*(1-v))|0;
      px[p+1]=(10+235*v)|0;
      px[p+2]=(15+197*v)|0;
      px[p+3]=255;
    }
  }
}

function renderDen(){
  for(var y=0;y<NY;y++){
    for(var x=0;x<NX;x++){
      var idx=y*NX+x,p=idx*4;
      if(ob[idx]){px[p]=8;px[p+1]=8;px[p+2]=12;px[p+3]=255;continue;}
      var d=(rh[idx]-1)*500;
      if(d>1)d=1;else if(d<-1)d=-1;
      if(d>=0){
        px[p]=(10*(1-d))|0;
        px[p+1]=(10+235*d)|0;
        px[p+2]=(15+197*d)|0;
      }else{
        var a=-d;
        px[p]=(10+113*a)|0;
        px[p+1]=(10+34*a)|0;
        px[p+2]=(15+176*a)|0;
      }
      px[p+3]=255;
    }
  }
}

function fillEdges(){
  for(var x=0;x<NX;x++){
    var p0=x*4,pN=((NY-1)*NX+x)*4;
    px[p0]=10;px[p0+1]=10;px[p0+2]=15;px[p0+3]=255;
    px[pN]=10;px[pN+1]=10;px[pN+2]=15;px[pN+3]=255;
  }
  for(var y=0;y<NY;y++){
    var p0=(y*NX)*4,pN=(y*NX+NX-1)*4;
    px[p0]=10;px[p0+1]=10;px[p0+2]=15;px[p0+3]=255;
    px[pN]=10;px[pN+1]=10;px[pN+2]=15;px[pN+3]=255;
  }
}

function drawObs(){
  var cx=ocx/NX*W,cy=ocy/NY*H;
  var rx=orad/NX*W,ry=orad/NY*H;
  ctx.save();
  ctx.beginPath();
  ctx.ellipse(cx,cy,rx*1.6,ry*1.6,0,0,Math.PI*2);
  var g=ctx.createRadialGradient(cx,cy,Math.min(rx,ry)*0.8,cx,cy,Math.max(rx,ry)*1.6);
  g.addColorStop(0,'rgba(0,245,212,0.06)');
  g.addColorStop(1,'rgba(0,245,212,0)');
  ctx.fillStyle=g;
  ctx.fill();
  ctx.beginPath();
  ctx.ellipse(cx,cy,rx,ry,0,0,Math.PI*2);
  ctx.fillStyle='#0a0a0f';
  ctx.fill();
  ctx.strokeStyle='rgba(0,245,212,0.35)';
  ctx.lineWidth=1.5;
  ctx.stroke();
  ctx.restore();
}

function drawTracers(){
  var sX=W/NX,sY=H/NY;
  ctx.save();
  for(var i=0;i<NT;i++){
    var a=Math.min(1,tA[i]/30)*0.65;
    if(a<0.05)continue;
    ctx.globalAlpha=a;
    ctx.fillStyle='#00f5d4';
    ctx.fillRect(tX[i]*sX-1,tY[i]*sY-1,2,2);
  }
  ctx.restore();
}

var dragging=false,dox=0,doy=0;
function s2g(sx,sy){return{x:sx/W*NX,y:sy/H*NY};}

function onDown(sx,sy){
  var g=s2g(sx,sy);
  var dx=g.x-ocx,dy=g.y-ocy;
  if(dx*dx+dy*dy<(orad+5)*(orad+5)){
    dragging=true;dox=ocx-g.x;doy=ocy-g.y;
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }else{
    injectDye(g.x,g.y);
    if(typeof SFX!=='undefined')SFX.play('ripple');
  }
}
function onMove(sx,sy){
  if(!dragging)return;
  var g=s2g(sx,sy);
  ocx=Math.max(orad+2,Math.min(NX-orad-2,g.x+dox));
  ocy=Math.max(orad+2,Math.min(NY-orad-2,g.y+doy));
  buildObs();
  if(typeof SFX!=='undefined')SFX.play('move',100);
}
function onUp(){dragging=false;}

C.addEventListener('mousedown',function(e){e.preventDefault();onDown(e.clientX,e.clientY);});
C.addEventListener('mousemove',function(e){onMove(e.clientX,e.clientY);});
C.addEventListener('mouseup',onUp);
C.addEventListener('mouseleave',onUp);
C.addEventListener('contextmenu',function(e){e.preventDefault();});
C.addEventListener('touchstart',function(e){e.preventDefault();var t=e.touches[0];onDown(t.clientX,t.clientY);},{passive:false});
C.addEventListener('touchmove',function(e){e.preventDefault();var t=e.touches[0];onMove(t.clientX,t.clientY);},{passive:false});
C.addEventListener('touchend',onUp);

var slS=document.getElementById('sl-speed'),svS=document.getElementById('sv-speed');
var slR=document.getElementById('sl-re'),svR=document.getElementById('sv-re');
var bM=document.getElementById('btn-mode'),bR=document.getElementById('btn-reset');
var hR=document.getElementById('hud-re'),hS=document.getElementById('hud-step');

slS.addEventListener('input',function(){spf=parseInt(this.value);svS.textContent=spf;});
slR.addEventListener('input',function(){
  var re=parseInt(this.value);svR.textContent=re;
  setRe(re);hR.textContent=re;
});
bM.addEventListener('click',function(){
  cmode=(cmode+1)%3;this.textContent=cmNames[cmode];
  if(typeof SFX!=='undefined')SFX.play('toggle');
});
bR.addEventListener('click',function(){
  initFlow();for(var i=0;i<100;i++)lbmStep();
  if(typeof SFX!=='undefined')SFX.play('click');
});

setRe(120);
initFlow();
for(var i=0;i<100;i++)lbmStep();

function frame(){
  for(var s=0;s<spf;s++)lbmStep();
  updateTracers();
  render();
  hS.textContent=steps;
  requestAnimationFrame(frame);
}
requestAnimationFrame(frame);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
