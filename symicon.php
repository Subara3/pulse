<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('symicon');

$pageName = 'symicon';
$pageTitle = 'SYMICON - PULSE';
$pageDesc = '対称カオスアイコン。カオス的反復写像にn回回転対称性を課すと、混沌から結晶のような構造が浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/symicon.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  Z<sub><span class="v" id="hud-n">5</span></sub> SYMMETRY<br>
  <span class="v" id="hud-pts">0</span> PTS<br>
  <span class="v" id="hud-name">PENTA</span>
</div>

<div id="info">
  <span class="v">Symmetric Chaos Icon</span><br>
  equivariant map &middot; Z<sub>n</sub> rotation &middot; density plot
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'random'],
  ['type' => 'right', 'label' => 'clear'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">N-FOLD</span>
      <input type="range" id="sl-nfold" min="3" max="12" value="5" step="1">
      <span class="slider-value" id="sv-nfold">5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="100" value="50">
      <span class="slider-value" id="sv-speed">50</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-random">RANDOM</button>
      <button class="control-btn" id="btn-auto">AUTO</button>
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
  <div class="preset-group" id="preset-btns">
    <button class="preset-btn active" data-idx="0">PENTA</button>
    <button class="preset-btn" data-idx="1">TRI</button>
    <button class="preset-btn" data-idx="2">HEX</button>
    <button class="preset-btn" data-idx="3">QUAD</button>
    <button class="preset-btn" data-idx="4">HEPTA</button>
    <button class="preset-btn" data-idx="5">OCTA</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,hist,imgData,pix;
var sc=2.0,zx=0.1,zy=0.01;
var total=0,maxD=0;
var autoOn=false,autoT=0,autoI=300;

var n=5,lam=-1.5,alp=1,gam=0,bet=0.3,omg=0;
var curNm='PENTA';

var PR=[
  {n:5,l:-1.5,a:1,g:0,b:0.3,w:0,nm:'PENTA'},
  {n:3,l:1.56,a:-1,g:0.1,b:-0.82,w:0,nm:'TRI'},
  {n:6,l:-1.86,a:2,g:0,b:0.86,w:0,nm:'HEX'},
  {n:4,l:-1.8,a:1,g:0.1,b:0.5,w:0.05,nm:'QUAD'},
  {n:7,l:-1.7,a:1,g:0,b:0.15,w:0.05,nm:'HEPTA'},
  {n:8,l:-1.5,a:1,g:0,b:0.1,w:0,nm:'OCTA'}
];

var LUT=new Uint8Array(768);
(function(){
  for(var i=0;i<256;i++){
    var t=i/255,r,g,b;
    if(t<0.2){var u=t/0.2;r=(10+113*u)|0;g=(10+34*u)|0;b=(15+176*u)|0;}
    else if(t<0.55){var u=(t-0.2)/0.35;r=(123-123*u)|0;g=(44+201*u)|0;b=(191+21*u)|0;}
    else{var u=(t-0.55)/0.45;r=(200*u)|0;g=(245+10*u)|0;b=(212+43*u)|0;}
    LUT[i*3]=r;LUT[i*3+1]=g;LUT[i*3+2]=b;
  }
})();

var hudN=document.getElementById('hud-n');
var hudP=document.getElementById('hud-pts');
var hudNm=document.getElementById('hud-name');
var slN=document.getElementById('sl-nfold');
var svN=document.getElementById('sv-nfold');
var slS=document.getElementById('sl-speed');
var svS=document.getElementById('sv-speed');

function resize(){W=C.width=window.innerWidth;H=C.height=window.innerHeight;clr();}

function clr(){
  hist=new Uint32Array(W*H);
  imgData=X.createImageData(W,H);
  pix=imgData.data;
  for(var i=0,len=W*H*4;i<len;i+=4){pix[i]=10;pix[i+1]=10;pix[i+2]=15;pix[i+3]=255;}
  total=0;maxD=0;zx=0.1;zy=0.01;
  for(var i=0;i<500;i++)iter();
}

function iter(){
  var r2=zx*zx+zy*zy;
  if(r2>100||isNaN(r2)){zx=(Math.random()-0.5)*0.2;zy=(Math.random()-0.5)*0.2;return false;}
  var th=Math.atan2(zy,zx),r=Math.sqrt(r2);
  var s=lam+alp*r2+gam*Math.cos(n*th);
  var rn1=n>1?Math.pow(r,n-1):1,a1=(n-1)*th;
  var nx=s*zx-omg*zy+bet*rn1*Math.cos(a1);
  var ny=s*zy+omg*zx-bet*rn1*Math.sin(a1);
  zx=nx;zy=ny;return true;
}

function batch(cnt){
  var hw=W*0.5,hh=H*0.5,sz=Math.min(hw,hh)/sc;
  for(var i=0;i<cnt;i++){
    if(!iter())continue;
    var px=(hw+zx*sz)|0,py=(hh-zy*sz)|0;
    if(px>=0&&px<W&&py>=0&&py<H){
      var idx=py*W+px;hist[idx]++;
      if(hist[idx]>maxD)maxD=hist[idx];
      total++;
    }
  }
}

function render(){
  if(maxD===0)return;
  var invLm=255/Math.log(maxD+1);
  for(var i=0,len=W*H;i<len;i++){
    var v=hist[i],p=i<<2;
    if(v===0){pix[p]=10;pix[p+1]=10;pix[p+2]=15;}
    else{var idx=Math.log(v+1)*invLm|0;if(idx>255)idx=255;var li=idx*3;pix[p]=LUT[li];pix[p+1]=LUT[li+1];pix[p+2]=LUT[li+2];}
  }
  X.putImageData(imgData,0,0);
}

function fmtK(v){return v<1e3?v+'':v<1e6?(v/1e3).toFixed(1)+'K':(v/1e6).toFixed(1)+'M';}

function testP(tl,ta,tg,tb,tw,tn){
  var tx=0.1,ty=0.01,dc=0;
  var mnx=1e9,mxx=-1e9,mny=1e9,mxy=-1e9;
  for(var i=0;i<10000;i++){
    var r2=tx*tx+ty*ty;
    if(r2>100||isNaN(r2)){dc++;if(dc>10)return null;tx=(Math.random()-0.5)*0.2;ty=(Math.random()-0.5)*0.2;continue;}
    var th=Math.atan2(ty,tx),r=Math.sqrt(r2);
    var s=tl+ta*r2+tg*Math.cos(tn*th);
    var rn1=tn>1?Math.pow(r,tn-1):1,a1=(tn-1)*th;
    var ntx=s*tx-tw*ty+tb*rn1*Math.cos(a1);
    var nty=s*ty+tw*tx-tb*rn1*Math.sin(a1);
    tx=ntx;ty=nty;
    if(i>500){if(tx<mnx)mnx=tx;if(tx>mxx)mxx=tx;if(ty<mny)mny=ty;if(ty>mxy)mxy=ty;}
  }
  var sx=mxx-mnx,sy=mxy-mny;
  if(sx<0.1||sy<0.1||sx>20||sy>20)return null;
  return Math.max(sx,sy)*0.7;
}

function applyP(idx){
  var p=PR[idx];
  var s=testP(p.l,p.a,p.g,p.b,p.w,p.n);
  if(!s){n=p.n;slN.value=n;svN.textContent=n;hudN.textContent=n;rndz();return;}
  n=p.n;lam=p.l;alp=p.a;gam=p.g;bet=p.b;omg=p.w;sc=s;curNm=p.nm;
  slN.value=n;svN.textContent=n;hudN.textContent=n;hudNm.textContent=curNm;
  clr();batch(200000);
  if(typeof SFX!=='undefined')SFX.play('toggle');
  var bs=document.querySelectorAll('#preset-btns .preset-btn');
  bs.forEach(function(b){b.classList.remove('active');});
  if(bs[idx])bs[idx].classList.add('active');
}

function rndz(){
  var tn=n;
  for(var att=0;att<300;att++){
    var tl,ta;
    if(att%2===0){tl=-(1+Math.random()*2);ta=0.5+Math.random()*2;}
    else{tl=0.5+Math.random()*2;ta=-(0.5+Math.random()*2);}
    var r0=Math.sqrt(Math.abs(tl/ta));
    var mb=Math.pow(Math.max(r0,0.3),2-tn);
    var tb=(Math.random()-0.5)*2*mb*2;
    var tg=(Math.random()-0.5)*0.8;
    var tw=(Math.random()-0.5)*0.3;
    var s=testP(tl,ta,tg,tb,tw,tn);
    if(s){
      lam=tl;alp=ta;gam=tg;bet=tb;omg=tw;sc=s;curNm='RND';
      hudNm.textContent=curNm;
      clr();batch(200000);
      if(typeof SFX!=='undefined')SFX.play('spawn');
      var bs=document.querySelectorAll('#preset-btns .preset-btn');
      bs.forEach(function(b){b.classList.remove('active');});
      return;
    }
  }
}

function loop(){
  var spd=Math.max(5000,50000*(+slS.value/50));
  batch(spd);render();
  hudP.textContent=fmtK(total);
  if(autoOn){autoT++;if(autoT>=autoI){autoT=0;rndz();}}
  requestAnimationFrame(loop);
}

window.addEventListener('resize',resize);

var mMov=false,mSX=0,mSY=0;
C.addEventListener('mousedown',function(e){
  if(e.button===2)return;mMov=false;mSX=e.clientX;mSY=e.clientY;
  function onM(ev){if(Math.abs(ev.clientX-mSX)+Math.abs(ev.clientY-mSY)>8)mMov=true;}
  function onU(){document.removeEventListener('mousemove',onM);document.removeEventListener('mouseup',onU);if(!mMov)rndz();}
  document.addEventListener('mousemove',onM);document.addEventListener('mouseup',onU);
});
C.addEventListener('contextmenu',function(e){e.preventDefault();clr();batch(200000);if(typeof SFX!=='undefined')SFX.play('click');});
C.addEventListener('wheel',function(e){e.preventDefault();sc*=e.deltaY>0?1.15:0.87;if(sc<0.2)sc=0.2;if(sc>30)sc=30;clr();batch(200000);},{passive:false});

var tSX=0,tSY=0,tMv=false;
C.addEventListener('touchstart',function(e){if(e.touches.length===1){tSX=e.touches[0].clientX;tSY=e.touches[0].clientY;tMv=false;}},{passive:true});
C.addEventListener('touchmove',function(e){if(e.touches.length===1&&Math.abs(e.touches[0].clientX-tSX)+Math.abs(e.touches[0].clientY-tSY)>12)tMv=true;},{passive:true});
C.addEventListener('touchend',function(e){if(!tMv&&e.changedTouches.length>0)rndz();tMv=false;});

slN.addEventListener('input',function(){n=+this.value;svN.textContent=n;hudN.textContent=n;rndz();});
slS.addEventListener('input',function(){svS.textContent=this.value;});

document.getElementById('btn-random').addEventListener('click',function(){rndz();});
document.getElementById('btn-auto').addEventListener('click',function(){
  autoOn=!autoOn;autoT=0;this.classList.toggle('active',autoOn);
  if(typeof SFX!=='undefined')SFX.play('toggle');
});
document.getElementById('btn-clear').addEventListener('click',function(){
  clr();batch(200000);if(typeof SFX!=='undefined')SFX.play('click');
});

document.querySelectorAll('#preset-btns .preset-btn').forEach(function(btn){
  btn.addEventListener('click',function(){applyP(+this.dataset.idx);});
});

resize();applyP(0);loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
