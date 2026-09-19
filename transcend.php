<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('transcend');

$pageName = 'transcend';
$pageTitle = 'TRANSCEND - PULSE';
$pageDesc = '超越反復フラクタル。sin(z)とexp(z)の反復が多項式を超え、無限に連なる自己相似構造を紡ぐ。';
$pageCanonical = 'https://pulse.subara3.com/transcend.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px);min-width:140px}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
.slider-row{display:grid;grid-template-columns:56px 1fr 44px;gap:.5rem;align-items:center}
.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:#00f5d4;text-align:right}
input[type=range]{width:100%;accent-color:#00f5d4}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
<span class="sub" id="h-func">SIN</span> · <span id="h-mode">PARAM</span><br>
<span id="h-zoom">&times;1.00</span>
<span id="h-julia" style="display:none"><br>c = <b id="h-cr">0</b> <b id="h-ci">+0i</b></span>
</div>
<div class="mark">TRANSCEND</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'julia set'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'scroll', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
<div class="btn-row">
<button class="control-btn active" id="btn-sin">SIN</button>
<button class="control-btn" id="btn-exp">EXP</button>
</div>
<div class="btn-row">
<button class="control-btn active" id="btn-param">PARAM</button>
<button class="control-btn" id="btn-julia">JULIA</button>
<button class="control-btn" id="btn-reset">RESET</button>
</div>
<div class="slider-row">
<label>ITER</label>
<input type="range" id="sl-iter" min="32" max="256" value="80" step="16">
<span class="slider-value" id="sv-iter">80</span>
</div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var cv=document.getElementById('canvas'),ctx=cv.getContext('2d'),W=0,H=0;
var func='sin',mode='param',cx=0,cy=0,vw=8,mi=80;
var jR=1.0,jI=0.5;
var BLOCKS=[8,4,2],curB=0,curY=0,rendering=false;
var img,dat,drag=null;
var _dc=document.createElement('canvas'),_dctx=_dc.getContext('2d');

var PL=1024,pR=new Uint8Array(PL),pG=new Uint8Array(PL),pB=new Uint8Array(PL);
(function(){
  var a=[[2,14,20],[0,80,70],[0,245,212],[0,170,145],[50,15,90],[123,44,191],[181,140,255],[40,8,60],[2,14,20]];
  var n=a.length-1;
  for(var i=0;i<PL;i++){
    var t=i/PL,p=t*n,idx=p|0,f=p-idx;f=f*f*(3-2*f);
    var c0=a[idx],c1=a[Math.min(idx+1,n)];
    pR[i]=(c0[0]+(c1[0]-c0[0])*f)|0;
    pG[i]=(c0[1]+(c1[1]-c0[1])*f)|0;
    pB[i]=(c0[2]+(c1[2]-c0[2])*f)|0;
  }
})();

var hF=document.getElementById('h-func'),hM=document.getElementById('h-mode');
var hZ=document.getElementById('h-zoom'),hJ=document.getElementById('h-julia');
var hCR=document.getElementById('h-cr'),hCI=document.getElementById('h-ci');
var sI=document.getElementById('sl-iter'),vI=document.getElementById('sv-iter');

function hud(){
  hF.textContent=func.toUpperCase();
  hM.textContent=mode.toUpperCase();
  var z=8/vw;
  hZ.textContent='×'+(z>=100?z.toFixed(0):z>=10?z.toFixed(1):z.toFixed(2));
  if(mode==='julia'){
    hJ.style.display='';
    hCR.textContent=jR.toFixed(3);
    hCI.textContent=(jI>=0?' +':' ')+jI.toFixed(3)+'i';
  }else hJ.style.display='none';
}

function resize(){W=cv.width=innerWidth;H=cv.height=innerHeight;startRender();}

function startRender(){
  img=ctx.createImageData(W,H);dat=img.data;
  curB=0;curY=0;rendering=true;
}

function renderChunk(){
  if(!rendering||curB>=BLOCKS.length){rendering=false;return;}
  var t0=performance.now(),bs=BLOCKS[curB],pxSz=vw/W;
  var isS=func==='sin',isP=mode==='param',prev=curB>0?BLOCKS[curB-1]:0;

  while(curY<H){
    for(var px=0;px<W;px+=bs){
      if(prev&&(curY%prev===0)&&(px%prev===0))continue;
      var x0=cx+(px-W*0.5)*pxSz,y0=cy+(curY-H*0.5)*pxSz;
      var zr,zi,cr,ci;
      if(isP){cr=x0;ci=y0;zr=x0;zi=y0;}
      else{zr=x0;zi=y0;cr=jR;ci=jI;}

      var n=0,r2;
      for(;n<mi;n++){
        var tr;
        if(isS){
          var sa=Math.sin(zr),ca=Math.cos(zr),ch=Math.cosh(zi),sh=Math.sinh(zi);
          var sr=sa*ch,si=ca*sh;
          tr=cr*sr-ci*si;zi=cr*si+ci*sr;
        }else{
          var ea=Math.exp(zr),er=ea*Math.cos(zi),ei=ea*Math.sin(zi);
          tr=cr*er-ci*ei;zi=cr*ei+ci*er;
        }
        zr=tr;r2=zr*zr+zi*zi;
        if(!(r2<=2500))break;
      }

      var R,G,B;
      if(n>=mi){R=8;G=7;B=14;}
      else{
        if(r2!==r2)r2=1e4;
        var ln=Math.log(r2)*0.5;
        var mu=n+1-Math.log(Math.max(ln,1))*1.4427;
        if(mu<0)mu=0;
        var idx=((mu*30)|0)%PL;
        R=pR[idx];G=pG[idx];B=pB[idx];
      }
      var yLim=Math.min(curY+bs,H),xLim=Math.min(px+bs,W);
      for(var sy=curY;sy<yLim;sy++){
        var row=sy*W;
        for(var sx=px;sx<xLim;sx++){
          var k=(row+sx)*4;
          dat[k]=R;dat[k+1]=G;dat[k+2]=B;dat[k+3]=255;
        }
      }
    }
    curY+=bs;
    if(performance.now()-t0>12)break;
  }
  if(curY>=H){curB++;curY=0;}
  ctx.putImageData(img,0,0);
  hud();
}

function world(mx,my){var s=vw/W;return{x:cx+(mx-W*0.5)*s,y:cy+(my-H*0.5)*s};}

cv.addEventListener('pointerdown',function(e){
  if(e.button===2)return;
  drag={x:e.clientX,y:e.clientY,cx:cx,cy:cy,moved:false};
  cv.setPointerCapture(e.pointerId);
  _dc.width=W;_dc.height=H;_dctx.drawImage(cv,0,0);
});
cv.addEventListener('pointermove',function(e){
  if(!drag)return;
  var dx=e.clientX-drag.x,dy=e.clientY-drag.y;
  if(Math.abs(dx)>3||Math.abs(dy)>3)drag.moved=true;
  if(drag.moved){
    rendering=false;
    var s=vw/W;cx=drag.cx-dx*s;cy=drag.cy-dy*s;
    ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
    ctx.drawImage(_dc,Math.round(dx),Math.round(dy));
    hud();
    if(window.SFX)SFX.play('move',200);
  }
});
cv.addEventListener('pointerup',function(e){
  if(!drag)return;
  if(!drag.moved){
    var w=world(e.clientX,e.clientY);
    if(mode==='param'){jR=w.x;jI=w.y;setMode('julia');}
    else setMode('param');
    if(window.SFX)SFX.play('toggle');
  }else startRender();
  drag=null;
});
cv.addEventListener('wheel',function(e){
  e.preventDefault();
  var f=e.deltaY<0?0.8:1.25,w=world(e.clientX,e.clientY);
  cx=w.x+(cx-w.x)*f;cy=w.y+(cy-w.y)*f;vw*=f;
  startRender();
  if(window.SFX)SFX.play('tick',120);
},{passive:false});
cv.addEventListener('contextmenu',function(e){e.preventDefault();});

var pinch0=0,pinchVW=0;
cv.addEventListener('touchstart',function(e){
  if(e.touches.length===2){
    var t=e.touches;
    pinch0=Math.hypot(t[0].clientX-t[1].clientX,t[0].clientY-t[1].clientY);
    pinchVW=vw;drag=null;rendering=false;
    e.preventDefault();
  }
},{passive:false});
cv.addEventListener('touchmove',function(e){
  if(e.touches.length===2&&pinch0>0){
    var t=e.touches;
    var d=Math.hypot(t[0].clientX-t[1].clientX,t[0].clientY-t[1].clientY);
    vw=pinchVW*pinch0/Math.max(d,1);
    hud();e.preventDefault();
  }
},{passive:false});
cv.addEventListener('touchend',function(e){
  if(pinch0>0&&e.touches.length<2){pinch0=0;startRender();if(window.SFX)SFX.play('tick');}
});

function setMode(m){
  mode=m;
  document.getElementById('btn-param').classList.toggle('active',m==='param');
  document.getElementById('btn-julia').classList.toggle('active',m==='julia');
  startRender();
}
function setFunc(f){
  func=f;
  document.getElementById('btn-sin').classList.toggle('active',f==='sin');
  document.getElementById('btn-exp').classList.toggle('active',f==='exp');
  if(mode==='param'){cx=f==='exp'?-0.3:0;cy=0;vw=f==='exp'?3:8;}
  startRender();
}

document.getElementById('btn-sin').onclick=function(){setFunc('sin');if(window.SFX)SFX.play('toggle');};
document.getElementById('btn-exp').onclick=function(){setFunc('exp');if(window.SFX)SFX.play('toggle');};
document.getElementById('btn-param').onclick=function(){setMode('param');if(window.SFX)SFX.play('toggle');};
document.getElementById('btn-julia').onclick=function(){setMode('julia');if(window.SFX)SFX.play('toggle');};
document.getElementById('btn-reset').onclick=function(){
  cx=func==='exp'?-0.3:0;cy=0;vw=func==='exp'?3:8;
  if(mode==='julia')setMode('param');else startRender();
  if(window.SFX)SFX.play('click');
};
sI.oninput=function(){mi=+sI.value;vI.textContent=mi;startRender();};

addEventListener('resize',resize);resize();
(function loop(){if(rendering)renderChunk();requestAnimationFrame(loop);})();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
