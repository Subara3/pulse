<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('biomorph');

$pageName = 'biomorph';
$pageTitle = 'BIOMORPH - PULSE';
$pageDesc = 'Pickoverバイオモーフ。複素関数の反復にバイオモーフィック判定を適用すると、微生物のような有機的フラクタルが現れる。';
$pageCanonical = 'https://pulse.subara3.com/biomorph.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  FUNC <b id="h-func">z&sup2;+c</b><br>
  MODE <b id="h-mode">FORM</b><br>
  <span id="h-c-wrap">c = <b id="h-c">&mdash;</b><br></span>
  ZOOM <b id="h-zoom">1.0&times;</b><br>
  <span id="h-prog" class="sub"></span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'pick c'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'right', 'label' => 'mode'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row">
    <button class="control-btn active" data-f="0">z&sup2;</button>
    <button class="control-btn" data-f="1">z&sup3;</button>
    <button class="control-btn" data-f="2">z&#x2074;</button>
    <button class="control-btn" data-f="3">SIN</button>
    <button class="control-btn" data-f="4">zSIN</button>
    <button class="control-btn" data-f="5">EXP</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">ITER</span><input type="range" id="sl-iter" min="20" max="200" value="80" step="10"><span class="slider-value" id="sv-iter">80</span></div></div>
  <div><div class="slider-row"><span class="slider-label">THRESHOLD</span><input type="range" id="sl-thresh" min="2" max="20" value="10" step="1"><span class="slider-value" id="sv-thresh">10</span></div></div>
  <div><div class="slider-row"><span class="slider-label">PALETTE</span><input type="range" id="sl-pal" min="0" max="3" value="0" step="1"><span class="slider-value" id="sv-pal">AQUA</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn active" id="btn-mode">FORM</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H, imgData;
var renderRow = 0, rendering = true, rpf = 4;

var cx = 0, cy = 0, scale = 2.0;
var func = 0, maxIt = 80, thresh = 10;
var mode = 1;
var fcr = 0, fci = 0.6;
var palI = 0;

var fN = ['z²+c','z³+c','z⁴+c','sin+c','z·sin+c','exp+c'];
var pN = ['AQUA','DEEP','NEON','IRIS'];
var dS = [2.0,2.0,1.8,4.0,3.0,3.0];
var dCr = [0,-0.4,-0.5,1.0,0.5,-0.5];
var dCi = [0.6,0.6,0.5,0.0,0.3,0.5];

var PA = [
  [[30,10,50],[0,80,110],[0,180,165],[0,245,212]],
  [[10,5,30],[15,30,90],[0,120,140],[0,200,185]],
  [[15,5,25],[60,15,110],[0,160,145],[0,245,212]],
  [[10,15,30],[0,140,130],[80,25,150],[150,60,200]]
];

function resize(){
  W=window.innerWidth; H=window.innerHeight;
  C.width=W; C.height=H;
  restart();
}

function restart(){
  imgData=X.createImageData(W,H);
  var d=imgData.data;
  for(var i=0;i<d.length;i+=4){d[i]=10;d[i+1]=10;d[i+2]=15;d[i+3]=255;}
  renderRow=0; rendering=true;
  updateHUD();
}

function lp(a,b,t){return a+(b-a)*t;}

function colAt(p,t){
  t=Math.max(0,Math.min(0.999,t));
  var n=p.length-1,i=(t*n)|0,f=t*n-i;
  return[(lp(p[i][0],p[i+1][0],f))|0,(lp(p[i][1],p[i+1][1],f))|0,(lp(p[i][2],p[i+1][2],f))|0];
}

function renderChunk(){
  if(!rendering)return;
  var t0=performance.now();
  var asp=W/H;
  var xmi=cx-scale*asp,xma=cx+scale*asp;
  var ymi=cy-scale,yma=cy+scale;
  var d=imgData.data,p=PA[palI];
  var end=Math.min(renderRow+rpf,H);
  var mi=maxIt,th=thresh;

  for(var py=renderRow;py<end;py++){
    for(var px=0;px<W;px++){
      var xx=xmi+(px/W)*(xma-xmi);
      var yy=ymi+(py/H)*(yma-ymi);
      var zr,zi,cr,ci;
      if(mode===0){cr=xx;ci=yy;zr=0;zi=0;}
      else{zr=xx;zi=yy;cr=fcr;ci=fci;}

      var it=0,nr,ni,t2,p2,sr,si,ex;
      for(;it<mi;it++){
        switch(func){
          case 0:nr=zr*zr-zi*zi+cr;ni=2*zr*zi+ci;break;
          case 1:nr=zr*zr*zr-3*zr*zi*zi+cr;ni=3*zr*zr*zi-zi*zi*zi+ci;break;
          case 2:t2=zr*zr-zi*zi;p2=2*zr*zi;nr=t2*t2-p2*p2+cr;ni=2*t2*p2+ci;break;
          case 3:nr=Math.sin(zr)*Math.cosh(zi)+cr;ni=Math.cos(zr)*Math.sinh(zi)+ci;break;
          case 4:sr=Math.sin(zr)*Math.cosh(zi);si=Math.cos(zr)*Math.sinh(zi);
                 nr=zr*sr-zi*si+cr;ni=zr*si+zi*sr+ci;break;
          case 5:ex=Math.exp(Math.min(zr,700));nr=ex*Math.cos(zi)+cr;ni=ex*Math.sin(zi)+ci;break;
        }
        zr=nr;zi=ni;
        if(!isFinite(zr)||!isFinite(zi)){zr=1e6;zi=1e6;break;}
        if(zr*zr+zi*zi>1e4)break;
      }

      var bio=Math.abs(zr)<th||Math.abs(zi)<th;
      var idx=(py*W+px)*4;
      if(bio){
        var tc=it/mi;
        var cc=colAt(p,tc);
        d[idx]=cc[0];d[idx+1]=cc[1];d[idx+2]=cc[2];
      }
    }
  }
  renderRow=end;
  X.putImageData(imgData,0,0);
  if(renderRow>=H){
    rendering=false;
    document.getElementById('h-prog').textContent='';
  }else{
    X.fillStyle='rgba(0,245,212,0.2)';
    X.fillRect(0,renderRow,W,2);
    document.getElementById('h-prog').textContent='SCAN '+((renderRow/H*100)|0)+'%';
  }
  var dt=performance.now()-t0;
  if(dt>14)rpf=Math.max(2,rpf-1);
  else if(dt<8)rpf=Math.min(16,rpf+1);
}

function updateHUD(){
  document.getElementById('h-func').textContent=fN[func];
  document.getElementById('h-mode').textContent=mode===0?'MAP':'FORM';
  var cw=document.getElementById('h-c-wrap');
  if(mode===1){
    var s1=fcr>=0?'':'−';
    var s2=fci>=0?' + ':' − ';
    document.getElementById('h-c').textContent=s1+Math.abs(fcr).toFixed(3)+s2+Math.abs(fci).toFixed(3)+'i';
    cw.style.display='';
  }else{cw.style.display='none';}
  document.getElementById('h-zoom').textContent=(dS[func]/scale).toFixed(1)+'×';
  document.getElementById('btn-mode').textContent=mode===0?'MAP':'FORM';
  document.getElementById('btn-mode').classList.toggle('active',mode===1);
}

function px2c(px,py){
  var asp=W/H;
  return[cx-scale*asp+(px/W)*2*scale*asp,cy-scale+(py/H)*2*scale];
}

var drag=false,lmx,lmy,dragM;
C.addEventListener('mousedown',function(e){
  if(e.button===0){drag=true;dragM=false;lmx=e.clientX;lmy=e.clientY;}
});
window.addEventListener('mousemove',function(e){
  if(!drag)return;
  var dx=e.clientX-lmx,dy=e.clientY-lmy;
  if(Math.abs(dx)+Math.abs(dy)>3)dragM=true;
  if(dragM){
    var asp=W/H;
    cx-=dx/W*2*scale*asp;
    cy-=dy/H*2*scale;
    lmx=e.clientX;lmy=e.clientY;
    restart();
  }
});
window.addEventListener('mouseup',function(e){
  if(!drag)return;
  drag=false;
  if(!dragM){
    var c=px2c(e.clientX,e.clientY);
    fcr=c[0];fci=c[1];
    if(mode===0){mode=1;cx=0;cy=0;scale=dS[func];}
    restart();
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }
});
C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  mode=1-mode;
  cx=0;cy=0;scale=dS[func];
  restart();
  if(typeof SFX!=='undefined')SFX.play('toggle');
});
C.addEventListener('wheel',function(e){
  e.preventDefault();
  var c=px2c(e.clientX,e.clientY);
  var f=e.deltaY>0?1.15:1/1.15;
  scale*=f;
  cx=c[0]+(cx-c[0])*f;
  cy=c[1]+(cy-c[1])*f;
  restart();
},{passive:false});

var tid=null,tsx,tsy,tmv;
var pD0=0,pS0,pCx0,pCy0;
C.addEventListener('touchstart',function(e){
  e.preventDefault();
  if(e.touches.length===1){
    tid=e.touches[0].identifier;
    tsx=e.touches[0].clientX;tsy=e.touches[0].clientY;
    lmx=tsx;lmy=tsy;tmv=false;
  }else if(e.touches.length===2){
    tid=null;
    var dx=e.touches[1].clientX-e.touches[0].clientX;
    var dy=e.touches[1].clientY-e.touches[0].clientY;
    pD0=Math.sqrt(dx*dx+dy*dy);
    pS0=scale;pCx0=cx;pCy0=cy;
  }
},{passive:false});
C.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(e.touches.length===1&&tid!==null){
    var t=e.touches[0];
    var dx=t.clientX-lmx,dy=t.clientY-lmy;
    if(Math.abs(t.clientX-tsx)+Math.abs(t.clientY-tsy)>10)tmv=true;
    if(tmv){
      var asp=W/H;
      cx-=dx/W*2*scale*asp;
      cy-=dy/H*2*scale;
      lmx=t.clientX;lmy=t.clientY;
      restart();
    }
  }else if(e.touches.length===2){
    var dx=e.touches[1].clientX-e.touches[0].clientX;
    var dy=e.touches[1].clientY-e.touches[0].clientY;
    var dist=Math.sqrt(dx*dx+dy*dy);
    if(pD0>0){scale=pS0*(pD0/dist);restart();}
  }
},{passive:false});
C.addEventListener('touchend',function(e){
  if(e.touches.length===0&&tid!==null&&!tmv){
    var c=px2c(tsx,tsy);
    fcr=c[0];fci=c[1];
    if(mode===0){mode=1;cx=0;cy=0;scale=dS[func];}
    restart();
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }
  tid=null;
},{passive:false});

var fBtns=document.querySelectorAll('[data-f]');
fBtns.forEach(function(b){
  b.addEventListener('click',function(){
    func=parseInt(this.dataset.f);
    fBtns.forEach(function(x){x.classList.remove('active');});
    this.classList.add('active');
    fcr=dCr[func];fci=dCi[func];
    cx=0;cy=0;scale=dS[func];
    restart();
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
});

var sI=document.getElementById('sl-iter'),vI=document.getElementById('sv-iter');
sI.addEventListener('input',function(){maxIt=parseInt(this.value);vI.textContent=maxIt;restart();});

var sT=document.getElementById('sl-thresh'),vT=document.getElementById('sv-thresh');
sT.addEventListener('input',function(){thresh=parseInt(this.value);vT.textContent=thresh;restart();});

var sP=document.getElementById('sl-pal'),vP=document.getElementById('sv-pal');
sP.addEventListener('input',function(){palI=parseInt(this.value);vP.textContent=pN[palI];restart();});

document.getElementById('btn-mode').addEventListener('click',function(){
  mode=1-mode;
  cx=0;cy=0;scale=dS[func];
  restart();
  if(typeof SFX!=='undefined')SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  cx=0;cy=0;scale=dS[func];
  fcr=dCr[func];fci=dCi[func];
  mode=1;restart();
  if(typeof SFX!=='undefined')SFX.play('click');
});

document.addEventListener('visibilitychange',function(){
  if(!document.hidden&&!rendering&&imgData)X.putImageData(imgData,0,0);
});

function loop(){
  if(rendering)renderChunk();
  requestAnimationFrame(loop);
}

window.addEventListener('resize',resize);
resize();
if(typeof SFX!=='undefined')SFX.play('spawn');
requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
