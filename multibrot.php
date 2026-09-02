<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('multibrot');

$pageName = 'multibrot';
$pageTitle = 'MULTIBROT - PULSE';
$pageDesc = 'マルチブロ集合。z^d+cの冪乗dを連続的に変化させると、マンデルブロ集合が(d-1)回対称へ変形していく。';
$pageCanonical = 'https://pulse.subara3.com/multibrot.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px);min-width:160px}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <span class="sub">MULTIBROT</span><br>
  d = <b id="h-pow">2.00</b><br>
  <span id="h-zoom">&times;1.0</span>
  <span id="h-julia" style="display:none"><br>c = <b id="h-cr">0</b> <b id="h-ci">+0i</b></span>
</div>
<div class="mark">MULTIBROT</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'zoom in'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'right', 'label' => 'zoom out'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btn-mandel">MANDELBROT</button>
    <button class="control-btn" id="btn-julia">JULIA</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
  <div class="slider-row">
    <label>POWER</label>
    <input type="range" id="sl-pow" min="200" max="800" value="200" step="1">
    <span class="slider-value" id="sl-pow-v">2.00</span>
  </div>
  <div class="slider-row">
    <label>ITER</label>
    <input type="range" id="sl-iter" min="32" max="512" value="128" step="16">
    <span class="slider-value" id="sl-iter-v">128</span>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var canvas=document.getElementById('canvas'),ctx=canvas.getContext('2d');
var W,H,img,dat;

var power=2.0,cx=0,cy=0,viewW=4.0,maxIter=128;
var mode=0,jR=-0.7,jI=0.27;

var BLOCKS=[8,4,2,1],curB=0,curY=0,rendering=false;

var PLEN=1024;
var pR=new Uint8Array(PLEN),pG=new Uint8Array(PLEN),pB=new Uint8Array(PLEN);

var dragging=false,dsx,dsy,dcx,dcy,dragBuf=null;
var lastTap=0,pinchD0=0,pinchVW=0;

var hPow=document.getElementById('h-pow');
var hZoom=document.getElementById('h-zoom');
var hJulia=document.getElementById('h-julia');
var hCR=document.getElementById('h-cr');
var hCI=document.getElementById('h-ci');
var slPow=document.getElementById('sl-pow');
var slPowV=document.getElementById('sl-pow-v');
var slIter=document.getElementById('sl-iter');
var slIterV=document.getElementById('sl-iter-v');

function buildPalette(){
  var a=[
    [2,14,20],[0,120,100],[0,245,212],[0,80,100],
    [60,20,100],[123,44,191],[181,140,255],[40,8,65],[2,14,20]
  ];
  var n=a.length-1;
  for(var i=0;i<PLEN;i++){
    var t=i/PLEN,pos=t*n,idx=Math.floor(pos),f=pos-idx;
    f=f*f*(3-2*f);
    var c0=a[idx],c1=a[Math.min(idx+1,n)];
    pR[i]=c0[0]+(c1[0]-c0[0])*f;
    pG[i]=c0[1]+(c1[1]-c0[1])*f;
    pB[i]=c0[2]+(c1[2]-c0[2])*f;
  }
}

function resize(){
  dragging=false;
  W=window.innerWidth;H=window.innerHeight;
  canvas.width=W;canvas.height=H;
  img=ctx.createImageData(W,H);dat=img.data;
  startRender();
}

function startRender(){curB=0;curY=0;rendering=true;}

function updateHUD(){
  hPow.textContent=power.toFixed(2);
  var z=4/viewW;
  hZoom.textContent='×'+(z>=100?z.toFixed(0):z>=10?z.toFixed(1):z.toFixed(2));
  if(mode===1){
    hJulia.style.display='';
    hCR.textContent=jR.toFixed(3);
    hCI.textContent=(jI>=0?'+':'')+jI.toFixed(3)+'i';
  }else{
    hJulia.style.display='none';
  }
}

function renderChunk(){
  if(!rendering||curB>=BLOCKS.length){rendering=false;return;}
  var bs=BLOCKS[curB],pxSz=viewW/W;
  var d=power,isD2=(d===2),mi=maxIter,logD=Math.log(d);
  var rowsPer=Math.max(1,Math.ceil(16000*bs/W));
  var endY=Math.min(curY+rowsPer*bs,H);

  for(var py=curY;py<endY;py+=bs){
    for(var px=0;px<W;px+=bs){
      var x0=cx+(px-W*0.5)*pxSz;
      var y0=cy+(py-H*0.5)*pxSz;
      var zx,zy,cr,ci;
      if(mode===0){zx=0;zy=0;cr=x0;ci=y0;}
      else{zx=x0;zy=y0;cr=jR;ci=jI;}

      var iter=0,r2;
      for(;iter<mi;iter++){
        r2=zx*zx+zy*zy;
        if(r2>256)break;
        if(isD2){
          var tx=zx*zx-zy*zy+cr;
          zy=2*zx*zy+ci;zx=tx;
        }else{
          var r=Math.sqrt(r2);
          var th=Math.atan2(zy,zx);
          var rn=Math.pow(r,d);
          zx=rn*Math.cos(d*th)+cr;
          zy=rn*Math.sin(d*th)+ci;
        }
      }

      var pr,pg,pb;
      if(iter>=mi){pr=10;pg=10;pb=15;}
      else{
        var sm=iter+1-Math.log(0.5*Math.log(r2))/logD;
        var t=((sm*0.025)%1+1)%1;
        var pi=(t*PLEN)|0;
        if(pi>=PLEN)pi=PLEN-1;
        pr=pR[pi];pg=pG[pi];pb=pB[pi];
      }

      var bw=Math.min(bs,W-px),bh=Math.min(bs,H-py);
      for(var dy=0;dy<bh;dy++){
        var off=((py+dy)*W+px)*4;
        for(var dx=0;dx<bw;dx++){
          dat[off]=pr;dat[off+1]=pg;dat[off+2]=pb;dat[off+3]=255;
          off+=4;
        }
      }
    }
  }
  ctx.putImageData(img,0,0);
  curY=endY;
  if(curY>=H){curB++;curY=0;}
}

function frame(){
  if(rendering&&!dragging)renderChunk();
  requestAnimationFrame(frame);
}

function captureDrag(){
  if(!dragBuf)dragBuf=document.createElement('canvas');
  dragBuf.width=W;dragBuf.height=H;
  dragBuf.getContext('2d').drawImage(canvas,0,0);
}

canvas.addEventListener('mousedown',function(e){
  if(e.button===2){
    e.preventDefault();
    var pxSz=viewW/W;
    cx+=(e.clientX-W*0.5)*pxSz*0.3;
    cy+=(e.clientY-H*0.5)*pxSz*0.3;
    viewW*=2;
    SFX.play('pop');
    startRender();updateHUD();
    return;
  }
  dragging=true;dsx=e.clientX;dsy=e.clientY;dcx=cx;dcy=cy;
  captureDrag();
});

canvas.addEventListener('mousemove',function(e){
  if(!dragging)return;
  ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
  ctx.drawImage(dragBuf,e.clientX-dsx,e.clientY-dsy);
});

window.addEventListener('mouseup',function(e){
  if(!dragging)return;
  dragging=false;
  var ddx=e.clientX-dsx,ddy=e.clientY-dsy;
  if(ddx*ddx+ddy*ddy<25){
    var pxSz=viewW/W;
    cx+=(dsx-W*0.5)*pxSz;cy+=(dsy-H*0.5)*pxSz;
    viewW*=0.5;
    SFX.play('spawn');
  }else{
    var pxSz=viewW/W;
    cx=dcx-ddx*pxSz;cy=dcy-ddy*pxSz;
  }
  startRender();updateHUD();
});

canvas.addEventListener('wheel',function(e){
  e.preventDefault();
  var f=e.deltaY>0?1.25:0.8;
  var pxSz=viewW/W;
  var px=cx+(e.clientX-W*0.5)*pxSz;
  var py=cy+(e.clientY-H*0.5)*pxSz;
  viewW*=f;
  cx=px-(e.clientX-W*0.5)*viewW/W;
  cy=py-(e.clientY-H*0.5)*viewW/W;
  SFX.play('tick',60);
  startRender();updateHUD();
},{passive:false});

canvas.addEventListener('contextmenu',function(e){e.preventDefault();});

canvas.addEventListener('touchstart',function(e){
  if(e.touches.length===1){
    dragging=true;
    dsx=e.touches[0].clientX;dsy=e.touches[0].clientY;
    dcx=cx;dcy=cy;
    captureDrag();
  }else if(e.touches.length===2){
    dragging=false;
    var t=e.touches;
    var ddx=t[0].clientX-t[1].clientX,ddy=t[0].clientY-t[1].clientY;
    pinchD0=Math.sqrt(ddx*ddx+ddy*ddy);pinchVW=viewW;
  }
},{passive:true});

canvas.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(e.touches.length===1&&dragging){
    ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
    ctx.drawImage(dragBuf,e.touches[0].clientX-dsx,e.touches[0].clientY-dsy);
  }else if(e.touches.length===2){
    var t=e.touches;
    var ddx=t[0].clientX-t[1].clientX,ddy=t[0].clientY-t[1].clientY;
    var dist=Math.sqrt(ddx*ddx+ddy*ddy);
    viewW=pinchVW*pinchD0/dist;
    startRender();updateHUD();
  }
},{passive:false});

canvas.addEventListener('touchend',function(e){
  if(e.touches.length===0&&dragging){
    dragging=false;
    if(e.changedTouches.length>0){
      var t=e.changedTouches[0];
      var ddx=t.clientX-dsx,ddy=t.clientY-dsy;
      if(ddx*ddx+ddy*ddy<100){
        var now=Date.now();
        if(now-lastTap<350){
          var pxSz=viewW/W;
          cx+=(dsx-W*0.5)*pxSz;cy+=(dsy-H*0.5)*pxSz;
          viewW*=0.5;
          SFX.play('spawn');
        }
        lastTap=now;
      }else{
        var pxSz=viewW/W;
        cx=dcx-ddx*pxSz;cy=dcy-ddy*pxSz;
      }
    }
    startRender();updateHUD();
  }
},{passive:true});

slPow.addEventListener('input',function(){
  power=parseInt(this.value)/100;
  slPowV.textContent=power.toFixed(2);
  SFX.play('move',80);
  startRender();updateHUD();
});

slIter.addEventListener('input',function(){
  maxIter=parseInt(this.value);
  slIterV.textContent=''+maxIter;
  startRender();
});

document.getElementById('btn-mandel').addEventListener('click',function(){
  if(mode===0)return;
  mode=0;
  this.classList.add('active');
  document.getElementById('btn-julia').classList.remove('active');
  SFX.play('toggle');
  startRender();updateHUD();
});

document.getElementById('btn-julia').addEventListener('click',function(){
  if(mode===1)return;
  mode=1;
  jR=cx;jI=cy;
  this.classList.add('active');
  document.getElementById('btn-mandel').classList.remove('active');
  cx=0;cy=0;viewW=4;
  SFX.play('toggle');
  startRender();updateHUD();
});

document.getElementById('btn-reset').addEventListener('click',function(){
  power=2;cx=0;cy=0;viewW=4;maxIter=128;mode=0;
  jR=-0.7;jI=0.27;
  slPow.value=200;slPowV.textContent='2.00';
  slIter.value=128;slIterV.textContent='128';
  document.getElementById('btn-mandel').classList.add('active');
  document.getElementById('btn-julia').classList.remove('active');
  SFX.play('click');
  startRender();updateHUD();
});

buildPalette();
window.addEventListener('resize',resize);
resize();
updateHUD();
requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
