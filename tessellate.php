<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tessellate');

$pageName = 'tessellate';
$pageTitle = 'TESSELLATE - PULSE';
$pageDesc = '四角の辺をつまんで曲げると、同じ変形が対辺にも伝わり、自分だけのタイルが平面を埋める。';
$pageCanonical = 'https://pulse.subara3.com/tessellate.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.5);font:12px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #hud .val{color:rgba(0,245,212,.85)}
  canvas{cursor:grab}
  canvas.dragging{cursor:grabbing}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MODE <span class="val" id="hud-mode">FREE</span></div>
  <div>TILES <span class="val" id="hud-tiles">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'reshape'],
  ['type' => 'click', 'label' => 'mode'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">EDGE LINK</label></div>
  <div class="btn-row" id="mode-btns">
    <button class="ctrl-btn active" data-m="free">FREE</button>
    <button class="ctrl-btn" data-m="linked">LINKED</button>
  </div>
  <div class="slider-row"><label class="slider-label">TOOLS</label></div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-shuffle">SHUFFLE</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;

var K=5,SG=18,MR=0.32;
var eV=[0,0,0,0,0],eH=[0,0,0,0,0];
var linked=false,autoAnim=true,t0=0;
var gx,gy,nc,nr,ci,cj,S;
var drag=null;

var twFrom=null,twTo=null,twT0=0,twDur=500,twActive=false;

function resize(){
  dpr=Math.min(window.devicePixelRatio||1,2);
  W=window.innerWidth; H=window.innerHeight;
  C.width=W*dpr; C.height=H*dpr;
  C.style.width=W+'px'; C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
  S=Math.max(50,Math.min(140,Math.floor(Math.min(W,H)/7)));
  nc=Math.ceil(W/S)+4; nr=Math.ceil(H/S)+4;
  gx=Math.floor((W-nc*S)/2); gy=Math.floor((H-nr*S)/2);
  ci=Math.floor(nc/2); cj=Math.floor(nr/2);
  document.getElementById('hud-tiles').textContent=nc*nr;
}

function cr(a,b,d,e,t){
  var t2=t*t,t3=t2*t;
  return .5*((2*b)+(-a+d)*t+(2*a-5*b+4*d-e)*t2+(-a+3*b-3*d+e)*t3);
}

function samp(off){
  var pts=[0];
  for(var i=0;i<K;i++) pts.push(off[i]);
  pts.push(0);
  var n=pts.length,out=new Array(SG+1);
  for(var i=0;i<=SG;i++){
    var u=i/SG*(n-1),lo=Math.min(Math.floor(u),n-2),f=u-lo;
    out[i]=cr(pts[Math.max(0,lo-1)],pts[lo],pts[Math.min(n-1,lo+1)],pts[Math.min(n-1,lo+2)],f);
  }
  return out;
}

function buildPoly(vS,hS){
  var p=[];
  for(var i=0;i<=SG;i++) p.push(i/SG*S,hS[i]);
  for(var i=1;i<=SG;i++) p.push(S+vS[i],i/SG*S);
  for(var i=SG-1;i>=0;i--) p.push(i/SG*S,S+hS[i]);
  for(var i=SG-1;i>=1;i--) p.push(vS[i],i/SG*S);
  return p;
}

function drawTile(ox,oy,p,fill,stk){
  X.beginPath();
  X.moveTo(ox+p[0],oy+p[1]);
  for(var i=2;i<p.length;i+=2) X.lineTo(ox+p[i],oy+p[i+1]);
  X.closePath();
  X.fillStyle=fill; X.fill();
  X.strokeStyle=stk; X.lineWidth=.6; X.stroke();
}

var FA='rgba(0,200,175,.06)',FB='rgba(100,40,160,.06)';
var SA='rgba(0,245,212,.14)',SB='rgba(123,44,191,.14)';

function draw(){
  X.fillStyle='#0a0a0f'; X.fillRect(0,0,W,H);
  var vS=samp(eV),hS=samp(eH),p=buildPoly(vS,hS);
  var m=S*MR+2;

  for(var j=0;j<nr;j++){
    for(var i=0;i<nc;i++){
      var ox=gx+i*S,oy=gy+j*S;
      if(ox+S+m<0||ox-m>W||oy+S+m<0||oy-m>H) continue;
      var e=(i+j)&1;
      drawTile(ox,oy,p,e?FB:FA,e?SB:SA);
    }
  }

  var ex=gx+ci*S,ey=gy+cj*S;
  X.beginPath(); X.moveTo(ex+p[0],ey+p[1]);
  for(var i=2;i<p.length;i+=2) X.lineTo(ex+p[i],ey+p[i+1]);
  X.closePath();
  X.strokeStyle='rgba(0,245,212,.45)'; X.lineWidth=1.8; X.stroke();
  X.fillStyle='rgba(0,245,212,.03)'; X.fill();

  var hr=6;
  for(var i=0;i<K;i++){
    var t=(i+1)/(K+1);
    var px=ex+S+eV[i],py=ey+t*S;
    X.beginPath(); X.arc(px,py,hr,0,Math.PI*2);
    X.fillStyle=(drag&&drag.tp==='v'&&drag.idx===i)?'#fff':'rgba(0,245,212,.85)';
    X.fill();
    X.strokeStyle='rgba(0,245,212,.3)'; X.lineWidth=1; X.stroke();
  }

  if(!linked){
    for(var i=0;i<K;i++){
      var t=(i+1)/(K+1);
      var px=ex+t*S,py=ey+S+eH[i];
      X.beginPath(); X.arc(px,py,hr,0,Math.PI*2);
      X.fillStyle=(drag&&drag.tp==='h'&&drag.idx===i)?'#fff':'rgba(123,44,191,.85)';
      X.fill();
      X.strokeStyle='rgba(123,44,191,.3)'; X.lineWidth=1; X.stroke();
    }
  }

  if(!linked){
    X.save(); X.globalAlpha=.25; X.setLineDash([3,4]);
    for(var i=0;i<K;i++){
      var t=(i+1)/(K+1);
      X.beginPath();
      X.moveTo(ex+S+eV[i],ey+t*S);
      X.lineTo(ex+eV[i],ey+t*S);
      X.strokeStyle='rgba(0,245,212,.4)'; X.lineWidth=.8; X.stroke();
    }
    for(var i=0;i<K;i++){
      var t=(i+1)/(K+1);
      X.beginPath();
      X.moveTo(ex+t*S,ey+S+eH[i]);
      X.lineTo(ex+t*S,ey+eH[i]);
      X.strokeStyle='rgba(123,44,191,.4)'; X.lineWidth=.8; X.stroke();
    }
    X.restore();
  }
}

function hitTest(mx,my){
  var ex=gx+ci*S,ey=gy+cj*S,r=20;
  for(var i=0;i<K;i++){
    var t=(i+1)/(K+1);
    var dx=mx-(ex+S+eV[i]),dy=my-(ey+t*S);
    if(dx*dx+dy*dy<r*r) return {tp:'v',idx:i};
  }
  if(!linked){
    for(var i=0;i<K;i++){
      var t=(i+1)/(K+1);
      var dx=mx-(ex+t*S),dy=my-(ey+S+eH[i]);
      if(dx*dx+dy*dy<r*r) return {tp:'h',idx:i};
    }
  }
  return null;
}

function clamp(v,lo,hi){return v<lo?lo:v>hi?hi:v;}

function onDown(x,y){
  var hit=hitTest(x,y);
  if(hit){
    if(autoAnim){autoAnim=false; SFX.play('toggle');}
    twActive=false;
    drag=hit;
    C.classList.add('dragging');
    SFX.play('spawn');
  }
}

function onMove(x,y){
  if(!drag) return;
  var ex=gx+ci*S,ey=gy+cj*S,mx=S*MR;
  if(drag.tp==='v'){
    eV[drag.idx]=clamp(x-(ex+S),-mx,mx);
    if(linked) eH[drag.idx]=eV[drag.idx];
  } else {
    eH[drag.idx]=clamp(y-(ey+S),-mx,mx);
  }
  SFX.play('move',80);
}

function onUp(){
  if(drag){drag=null; C.classList.remove('dragging');}
}

function setMode(m){
  linked=(m==='linked');
  if(linked) for(var i=0;i<K;i++) eH[i]=eV[i];
  var btns=document.querySelectorAll('#mode-btns .ctrl-btn');
  btns.forEach(function(b){b.classList.toggle('active',b.dataset.m===m);});
  document.getElementById('hud-mode').textContent=m.toUpperCase();
  SFX.play('toggle');
}

function startTween(tv,th){
  twFrom={v:eV.slice(),h:eH.slice()};
  twTo={v:tv,h:th};
  twT0=performance.now();
  twActive=true;
}

function updateTween(){
  if(!twActive) return;
  var t=Math.min((performance.now()-twT0)/twDur,1);
  var s=t*t*(3-2*t);
  for(var i=0;i<K;i++){
    eV[i]=twFrom.v[i]+(twTo.v[i]-twFrom.v[i])*s;
    eH[i]=twFrom.h[i]+(twTo.h[i]-twFrom.h[i])*s;
  }
  if(t>=1) twActive=false;
}

function doShuffle(){
  autoAnim=false;
  var mx=S*MR*.8,nv=[],nh=[];
  for(var i=0;i<K;i++){
    nv.push((Math.random()*2-1)*mx);
    nh.push(linked?nv[i]:(Math.random()*2-1)*mx);
  }
  startTween(nv,nh);
  SFX.play('click');
}

function doReset(){
  autoAnim=false;
  startTween([0,0,0,0,0],[0,0,0,0,0]);
  SFX.play('click');
}

function autoAnimate(t){
  var amp=S*MR*.55;
  for(var i=0;i<K;i++){
    var phase=i*1.4+0.3;
    eV[i]=Math.sin(t*.0007+phase)*amp*(.45+.45*Math.sin(t*.00025+i*.7));
    eH[i]=linked?eV[i]:Math.cos(t*.0005+i*1.7+2)*amp*(.45+.45*Math.cos(t*.0003+i*.9+1));
  }
}

function loop(now){
  if(!t0) t0=now;
  if(autoAnim) autoAnimate(now-t0);
  updateTween();
  draw();
  requestAnimationFrame(loop);
}

C.addEventListener('mousedown',function(e){onDown(e.clientX,e.clientY);});
window.addEventListener('mousemove',function(e){if(drag) onMove(e.clientX,e.clientY);});
window.addEventListener('mouseup',onUp);

C.addEventListener('touchstart',function(e){
  e.preventDefault(); onDown(e.touches[0].clientX,e.touches[0].clientY);
},{passive:false});
C.addEventListener('touchmove',function(e){
  e.preventDefault(); onMove(e.touches[0].clientX,e.touches[0].clientY);
},{passive:false});
C.addEventListener('touchend',function(e){e.preventDefault();onUp();},{passive:false});

C.addEventListener('contextmenu',function(e){e.preventDefault();});

document.querySelectorAll('#mode-btns .ctrl-btn').forEach(function(b){
  b.addEventListener('click',function(){setMode(b.dataset.m);});
});
document.getElementById('btn-shuffle').addEventListener('click',doShuffle);
document.getElementById('btn-reset').addEventListener('click',doReset);

resize();
window.addEventListener('resize',resize);
requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
