<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('diffract');

$pageName = 'diffract';
$pageTitle = 'DIFFRACT - PULSE';
$pageDesc = 'フラウンホーファー回折パターン。開口の形状をFFTで変換し、遠方場の光の干渉模様を描く。';
$pageCanonical = 'https://pulse.subara3.com/diffract.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  APERTURE <b id="h-shape">CIRCLE</b><br>
  ZOOM <b id="h-zoom">1.0</b>x<br>
  <span class="sub">ANGLE</span> <b id="h-angle">0°</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'shape'],
  ['type' => 'scroll', 'label' => 'zoom'],
  ['type' => 'drag', 'label' => 'rotate'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row" id="shape-btns">
    <button class="control-btn active" data-s="0">CIRCLE</button>
    <button class="control-btn" data-s="1">SQUARE</button>
    <button class="control-btn" data-s="2">SLIT</button>
    <button class="control-btn" data-s="3">DOUBLE</button>
    <button class="control-btn" data-s="4">TRI</button>
    <button class="control-btn" data-s="5">HEX</button>
    <button class="control-btn" data-s="6">RING</button>
    <button class="control-btn" data-s="7">GRID</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">SIZE A</span><input type="range" id="sl-a" min="2" max="60" value="20" step="1"><span class="slider-value" id="sv-a">20</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SIZE B</span><input type="range" id="sl-b" min="2" max="60" value="20" step="1"><span class="slider-value" id="sv-b">20</span></div></div>
  <div><div class="slider-row"><span class="slider-label">GAMMA</span><input type="range" id="sl-gamma" min="20" max="90" value="60" step="5"><span class="slider-value" id="sv-gamma">0.60</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-spin">SPIN</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,D;
var N=256,N2=N/2;
var shape=0,sizeA=20,sizeB=20,gamma=0.6;
var angle=0,autoSpin=true,zoom=1;
var dragging=false,dragX=0,dragY=0,dragA=0,dragMoved=false;

var NAMES=['CIRCLE','SQUARE','SLIT','DOUBLE','TRI','HEX','RING','GRID'];
var DEFS=[[20,20],[20,20],[3,20],[3,24],[20,20],[20,20],[24,12],[16,28]];

var hShape=document.getElementById('h-shape');
var hZoom=document.getElementById('h-zoom');
var hAngle=document.getElementById('h-angle');
var slA=document.getElementById('sl-a'),svA=document.getElementById('sv-a');
var slB=document.getElementById('sl-b'),svB=document.getElementById('sv-b');
var slG=document.getElementById('sl-gamma'),svG=document.getElementById('sv-gamma');

var oC=document.createElement('canvas');
oC.width=oC.height=N;
var oX=oC.getContext('2d');
var imgData=oX.createImageData(N,N);

var re=new Float64Array(N*N),im=new Float64Array(N*N);
var tmpR=new Float64Array(N),tmpI=new Float64Array(N);
var dirty=true;

var LUT=[];
(function(){
  var stops=[[0,10,10,15],[.1,40,15,70],[.22,90,30,140],[.35,123,44,191],[.48,30,85,130],[.6,0,155,135],[.72,0,205,175],[.84,0,245,212],[.94,160,250,235],[1,255,255,255]];
  for(var i=0;i<1024;i++){
    var t=i/1023,j=0;
    while(j<stops.length-2&&stops[j+1][0]<t)j++;
    var s0=stops[j],s1=stops[j+1];
    var f=(t-s0[0])/(s1[0]-s0[0]);
    LUT.push([
      Math.round(s0[1]+(s1[1]-s0[1])*f),
      Math.round(s0[2]+(s1[2]-s0[2])*f),
      Math.round(s0[3]+(s1[3]-s0[3])*f)
    ]);
  }
})();

function bitRev(n,bits){
  var r=0;for(var i=0;i<bits;i++){r=(r<<1)|(n&1);n>>=1;}return r;
}
var LOG2N=Math.log2(N)|0;
var revTable=new Uint16Array(N);
for(var i=0;i<N;i++)revTable[i]=bitRev(i,LOG2N);

function fft1d(xr,xi,off,stride,inv){
  for(var i=0;i<N;i++){tmpR[revTable[i]]=xr[off+i*stride];tmpI[revTable[i]]=xi[off+i*stride];}
  for(var s=1;s<=LOG2N;s++){
    var m=1<<s,mh=m>>1;
    var wR=Math.cos(2*Math.PI/m),wI=(inv?-1:1)*Math.sin(2*Math.PI/m);
    for(var k=0;k<N;k+=m){
      var uR=1,uI=0;
      for(var j=0;j<mh;j++){
        var tR=uR*tmpR[k+j+mh]-uI*tmpI[k+j+mh];
        var tI=uR*tmpI[k+j+mh]+uI*tmpR[k+j+mh];
        tmpR[k+j+mh]=tmpR[k+j]-tR;tmpI[k+j+mh]=tmpI[k+j]-tI;
        tmpR[k+j]+=tR;tmpI[k+j]+=tI;
        var nu=uR*wR-uI*wI;uI=uR*wI+uI*wR;uR=nu;
      }
    }
  }
  for(var i=0;i<N;i++){xr[off+i*stride]=tmpR[i];xi[off+i*stride]=tmpI[i];}
}

function fft2d(){
  for(var r=0;r<N;r++)fft1d(re,im,r*N,1,false);
  for(var c=0;c<N;c++)fft1d(re,im,c,N,false);
}

function buildAperture(){
  var a=sizeA,b=sizeB;
  for(var j=0;j<N;j++)for(var i=0;i<N;i++){
    var idx=j*N+i;
    var x=i-N2,y=j-N2;
    var v=0;
    switch(shape){
      case 0: if(x*x+y*y<=a*a)v=1;break;
      case 1: if(Math.abs(x)<=a&&Math.abs(y)<=b)v=1;break;
      case 2: if(Math.abs(x)<=a&&Math.abs(y)<=b)v=1;break;
      case 3:{
        var gap=Math.max(1,Math.round(b*0.4));
        if(Math.abs(y)<=b&&(Math.abs(x-gap)<=a||Math.abs(x+gap)<=a))v=1;
        break;
      }
      case 4:{
        var s3=0.866025;
        var inside=y>-a*0.6&&y<a*0.9;
        if(inside){
          var ty=y+a*0.2;
          var h=a*1.2;
          var frac=(ty+a*0.6)/h;
          var hw=a*s3*frac;
          if(Math.abs(x)<=hw&&frac>=0&&frac<=1)v=1;
        }
        break;
      }
      case 5:{
        var ax=Math.abs(x),ay=Math.abs(y);
        if(ax<=a*0.866025&&ay+ax*0.577350<=a)v=1;
        break;
      }
      case 6:{
        var r2=x*x+y*y;
        if(r2<=a*a&&r2>=(a-b)*(a-b))v=1;
        break;
      }
      case 7:{
        var gx=Math.round(a*0.5)||1,gy=Math.round(b*0.5)||1;
        var nx=3,ny=3;
        for(var gi=-nx;gi<=nx;gi++)for(var gj=-ny;gj<=ny;gj++){
          var cx=gi*(gx+a*0.3),cy=gj*(gy+b*0.3);
          if(Math.abs(x-cx)<=gx&&Math.abs(y-cy)<=gy)v=1;
        }
        break;
      }
    }
    var sign=((i+j)&1)?-1:1;
    re[idx]=v*sign;
    im[idx]=0;
  }
}

function computePattern(){
  buildAperture();
  fft2d();
  var maxV=0;
  for(var i=0;i<N*N;i++){
    var mag=re[i]*re[i]+im[i]*im[i];
    if(mag>maxV)maxV=mag;
  }
  var logMax=Math.log(1+maxV);
  var px=imgData.data;
  for(var i=0;i<N*N;i++){
    var mag=re[i]*re[i]+im[i]*im[i];
    var v=Math.log(1+mag)/logMax;
    v=Math.pow(v,gamma);
    var ci=Math.min(1023,Math.round(v*1023));
    var c=LUT[ci];
    var p=i*4;
    px[p]=c[0];px[p+1]=c[1];px[p+2]=c[2];px[p+3]=255;
  }
  oX.putImageData(imgData,0,0);
  dirty=false;
}

function resize(){
  D=Math.min(2,devicePixelRatio||1);
  W=C.width=Math.floor(innerWidth*D);H=C.height=Math.floor(innerHeight*D);
  C.style.width=innerWidth+'px';C.style.height=innerHeight+'px';
}

function draw(){
  requestAnimationFrame(draw);
  if(autoSpin)angle+=0.002;

  if(dirty)computePattern();

  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  var sc=Math.min(W,H)*zoom*(1+Math.sin(performance.now()*0.0004)*0.02);
  X.save();
  X.translate(W/2,H/2);
  X.rotate(angle);
  X.imageSmoothingEnabled=true;
  X.drawImage(oC,-sc/2,-sc/2,sc,sc);
  X.restore();

  var grd=X.createRadialGradient(W/2,H/2,sc*0.25,W/2,H/2,sc*0.7);
  grd.addColorStop(0,'rgba(10,10,15,0)');
  grd.addColorStop(1,'rgba(10,10,15,0.6)');
  X.fillStyle=grd;
  X.fillRect(0,0,W,H);

  hAngle.textContent=((angle*180/Math.PI)%360).toFixed(0)+'°';
  hZoom.textContent=zoom.toFixed(1);
}

function setShape(s){
  shape=s;
  sizeA=DEFS[s][0];sizeB=DEFS[s][1];
  slA.value=sizeA;svA.textContent=sizeA;
  slB.value=sizeB;svB.textContent=sizeB;
  hShape.textContent=NAMES[s];
  dirty=true;
  document.querySelectorAll('#shape-btns .control-btn').forEach(function(b,i){
    b.classList.toggle('active',i===s);
  });
  if(typeof SFX!=='undefined')SFX.play('toggle');
}

document.getElementById('shape-btns').addEventListener('click',function(e){
  var b=e.target.closest('[data-s]');
  if(b)setShape(parseInt(b.dataset.s));
});

slA.addEventListener('input',function(){sizeA=parseInt(this.value);svA.textContent=sizeA;dirty=true;});
slB.addEventListener('input',function(){sizeB=parseInt(this.value);svB.textContent=sizeB;dirty=true;});
slG.addEventListener('input',function(){gamma=parseInt(this.value)/100;svG.textContent=gamma.toFixed(2);dirty=true;});

var spinBtn=document.getElementById('btn-spin');
spinBtn.classList.add('active');
spinBtn.addEventListener('click',function(){
  autoSpin=!autoSpin;
  this.classList.toggle('active',autoSpin);
  if(typeof SFX!=='undefined')SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  angle=0;zoom=1;autoSpin=true;
  spinBtn.classList.add('active');
  setShape(0);
  gamma=0.6;slG.value=60;svG.textContent='0.60';
  dirty=true;
  if(typeof SFX!=='undefined')SFX.play('click');
});

C.addEventListener('pointerdown',function(e){
  if(e.button===2)return;
  dragging=true;dragX=e.clientX;dragY=e.clientY;dragA=angle;dragMoved=false;
  C.setPointerCapture(e.pointerId);
});
C.addEventListener('pointermove',function(e){
  if(!dragging)return;
  var dx=e.clientX-dragX,dy=e.clientY-dragY;
  if(dx*dx+dy*dy>25){
    dragMoved=true;
    angle=dragA+Math.atan2(dy,dx)*0.5;
    autoSpin=false;
    spinBtn.classList.remove('active');
  }
});
C.addEventListener('pointerup',function(e){
  if(!dragMoved&&dragging){
    shape=(shape+1)%NAMES.length;
    setShape(shape);
  }
  dragging=false;
});

C.addEventListener('wheel',function(e){
  e.preventDefault();
  zoom=Math.max(0.3,Math.min(5,zoom*(e.deltaY>0?0.9:1.1)));
  if(typeof SFX!=='undefined')SFX.play('tick',80);
},{passive:false});

C.addEventListener('contextmenu',function(e){e.preventDefault();});

var lastTouch=0;
C.addEventListener('touchstart',function(e){
  if(e.touches.length===2){
    lastTouch=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
  }
},{passive:true});
C.addEventListener('touchmove',function(e){
  if(e.touches.length===2){
    var d=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
    if(lastTouch>0)zoom=Math.max(0.3,Math.min(5,zoom*d/lastTouch));
    lastTouch=d;
  }
},{passive:true});

resize();
window.addEventListener('resize',function(){resize();});
computePattern();
draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
