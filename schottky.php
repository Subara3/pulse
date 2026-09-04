<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('schottky');

$pageName = 'schottky';
$pageTitle = 'SCHOTTKY - PULSE';
$pageDesc = 'ショットキー群の円フラクタル。4つの円が定めるメビウス変換の反復が、入れ子の円パターンを無限に生む。';
$pageCanonical = 'https://pulse.subara3.com/schottky.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .dim{color:rgba(181,140,255,.7)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="dim">SCHOTTKY</span><br>DEPTH <b id="h-depth">7</b><br>CIRCLES <b id="h-count">0</b><br><span class="dim" id="h-mode">ORBIT</span></div>
<div class="mark">SCHOTTKY</div>
<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'scroll', 'label' => 'resize'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btn-orbit">ORBIT</button>
    <button class="control-btn" id="btn-free">FREE</button>
  </div>
  <div class="slider-row">
    <span class="slider-label">DEPTH</span>
    <input type="range" id="sl-depth" min="1" max="10" value="7">
    <span class="slider-value" id="sv-depth">7</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">RADIUS</span>
    <input type="range" id="sl-radius" min="20" max="90" value="55">
    <span class="slider-value" id="sv-radius">0.55</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPREAD</span>
    <input type="range" id="sl-spread" min="80" max="250" value="160">
    <span class="slider-value" id="sv-spread">1.60</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d');
var W,H;

var TEAL=[0,245,212],PURP=[123,44,191];
function rgba(col,a){return 'rgba('+col[0]+','+col[1]+','+col[2]+','+a+')';}
function mixC(a,b,t){return[a[0]+(b[0]-a[0])*t|0,a[1]+(b[1]-a[1])*t|0,a[2]+(b[2]-a[2])*t|0];}
function brandHue(t){t=t<0?0:t>1?1:t;var e=t<.5?.5*Math.pow(2*t,3.2):1-.5*Math.pow(2*(1-t),3.2);return 172+e*118;}
function hsl2rgb(h,s,l){h%=360;if(h<0)h+=360;s/=100;l/=100;var c=(1-Math.abs(2*l-1))*s,x=c*(1-Math.abs(h/60%2-1)),m=l-c/2,r,g,b;if(h<60){r=c;g=x;b=0}else if(h<120){r=x;g=c;b=0}else if(h<180){r=0;g=c;b=x}else if(h<240){r=0;g=x;b=c}else if(h<300){r=x;g=0;b=c}else{r=c;g=0;b=x}return[(r+m)*255|0,(g+m)*255|0,(b+m)*255|0];}

// Complex arithmetic: [re, im]
function cAdd(a,b){return[a[0]+b[0],a[1]+b[1]];}
function cSub(a,b){return[a[0]-b[0],a[1]-b[1]];}
function cMul(a,b){return[a[0]*b[0]-a[1]*b[1],a[0]*b[1]+a[1]*b[0]];}
function cConj(a){return[a[0],-a[1]];}
function cAbs2(a){return a[0]*a[0]+a[1]*a[1];}
function cAbs(a){return Math.sqrt(cAbs2(a));}
function cScale(a,s){return[a[0]*s,a[1]*s];}

// Mobius matrix composition: M = {a,b,c,d} each complex
function mCompose(M1,M2){
  return{
    a:cAdd(cMul(M1.a,M2.a),cMul(M1.b,M2.c)),
    b:cAdd(cMul(M1.a,M2.b),cMul(M1.b,M2.d)),
    c:cAdd(cMul(M1.c,M2.a),cMul(M1.d,M2.c)),
    d:cAdd(cMul(M1.c,M2.b),cMul(M1.d,M2.d))
  };
}

// Image of circle (cx,cy,r) under Mobius M
function mCircle(M,cx,cy,r){
  var p=[cx,cy];
  var cp_d=cAdd(cMul(M.c,p),M.d);
  var ap_b=cAdd(cMul(M.a,p),M.b);
  var cp_d2=cAbs2(cp_d);
  var c2=cAbs2(M.c);
  var r2=r*r;
  var denom=cp_d2-c2*r2;
  if(Math.abs(denom)<1e-12)return null;
  var det=cSub(cMul(M.a,M.d),cMul(M.b,M.c));
  var nr=cAbs(det)*r/Math.abs(denom);
  var num=cSub(cMul(ap_b,cConj(cp_d)),cScale(cMul(M.a,cConj(M.c)),r2));
  var nc=[num[0]/denom,num[1]/denom];
  return{cx:nc[0],cy:nc[1],r:nr};
}

// Circle state
var baseSpread=1.6,baseRadius=0.55;
var discs=[
  {cx:-baseSpread,cy:0,r:baseRadius},
  {cx:baseSpread,cy:0,r:baseRadius},
  {cx:0,cy:-baseSpread,r:baseRadius},
  {cx:0,cy:baseSpread,r:baseRadius}
];

var invOf=[1,0,3,2];
var genTgt=[1,0,3,2];
var gens=[];
var maxDepth=7;
var fracCircles=[];

var orbitMode=true,orbitAngle=0,orbitSpeed=0.0006;
var dragIdx=-1,dragOx=0,dragOy=0;
var viewScale=1;
var needRecompute=true;

function resetDiscs(){
  var s=baseSpread;
  discs[0]={cx:-s,cy:0,r:baseRadius};
  discs[1]={cx:s,cy:0,r:baseRadius};
  discs[2]={cx:0,cy:-s,r:baseRadius};
  discs[3]={cx:0,cy:s,r:baseRadius};
  orbitAngle=0;
  needRecompute=true;
}

function makeGen(si,ti){
  var cs=[discs[si].cx,discs[si].cy];
  var ct=[discs[ti].cx,discs[ti].cy];
  var rs=discs[si].r,rt=discs[ti].r;
  return{
    a:[ct[0],ct[1]],
    b:cSub([rs*rt,0],cMul(cs,ct)),
    c:[1,0],
    d:[-cs[0],-cs[1]]
  };
}

function computeFractal(){
  fracCircles=[];
  gens=[];
  for(var i=0;i<4;i++)gens.push(makeGen(i,genTgt[i]));
  var minR=1.5/viewScale;
  for(var g=0;g<4;g++){
    recurse(gens[g],g,1,minR);
  }
  needRecompute=false;
}

function recurse(mat,last,depth,minR){
  for(var g=0;g<4;g++){
    if(g===invOf[last])continue;
    var t=discs[genTgt[g]];
    var img=mCircle(mat,t.cx,t.cy,t.r);
    if(!img||img.r<minR)continue;
    fracCircles.push({cx:img.cx,cy:img.cy,r:img.r,depth:depth,pair:g<2?0:1});
    if(depth<maxDepth)recurse(mCompose(mat,gens[g]),g,depth+1,minR);
  }
}

function resize(){
  W=c.width=window.innerWidth;H=c.height=window.innerHeight;
  viewScale=Math.min(W,H)/5.5;
  needRecompute=true;
}

function w2s(x,y){return{x:x*viewScale+W/2,y:y*viewScale+H/2};}
function s2w(sx,sy){return{x:(sx-W/2)/viewScale,y:(sy-H/2)/viewScale};}

function draw(){
  ctx.fillStyle='#0a0a0f';
  ctx.fillRect(0,0,W,H);

  // Draw fractal circles (deepest first for layering)
  for(var i=fracCircles.length-1;i>=0;i--){
    var fc=fracCircles[i];
    var s=w2s(fc.cx,fc.cy);
    var sr=fc.r*viewScale;
    if(sr<0.3)continue;
    var alpha=Math.max(0.08,0.7-fc.depth*0.08);
    var lw=Math.max(0.4,1.8-fc.depth*0.15);
    var col=fc.pair===0?TEAL:PURP;
    if(sr>2){
      ctx.strokeStyle=rgba(col,alpha);
      ctx.lineWidth=lw;
      ctx.beginPath();
      ctx.arc(s.x,s.y,sr,0,Math.PI*2);
      ctx.stroke();
    }else{
      ctx.fillStyle=rgba(col,alpha);
      ctx.beginPath();
      ctx.arc(s.x,s.y,sr,0,Math.PI*2);
      ctx.fill();
    }
  }

  // Draw initial circles with glow
  for(var i=0;i<4;i++){
    var d=discs[i];
    var s=w2s(d.cx,d.cy);
    var sr=d.r*viewScale;
    var col=i<2?TEAL:PURP;

    ctx.save();
    ctx.shadowColor=rgba(col,0.35);
    ctx.shadowBlur=12;
    ctx.strokeStyle=rgba(col,0.85);
    ctx.lineWidth=2;
    ctx.beginPath();
    ctx.arc(s.x,s.y,sr,0,Math.PI*2);
    ctx.stroke();
    ctx.restore();

    if(dragIdx===i){
      ctx.fillStyle=rgba(col,0.08);
      ctx.beginPath();
      ctx.arc(s.x,s.y,sr,0,Math.PI*2);
      ctx.fill();
    }

    // Center dot
    ctx.fillStyle=rgba(col,0.5);
    ctx.beginPath();
    ctx.arc(s.x,s.y,3,0,Math.PI*2);
    ctx.fill();
  }

  // HUD
  document.getElementById('h-depth').textContent=maxDepth;
  document.getElementById('h-count').textContent=fracCircles.length+4;
  document.getElementById('h-mode').textContent=orbitMode?'ORBIT':'FREE';
}

function updateOrbit(){
  if(!orbitMode||dragIdx>=0)return;
  orbitAngle+=orbitSpeed;
  var s=baseSpread;
  var ca=Math.cos(orbitAngle),sa=Math.sin(orbitAngle);
  discs[0].cx=-s*ca;discs[0].cy=-s*sa;
  discs[1].cx=s*ca;discs[1].cy=s*sa;
  discs[2].cx=s*sa;discs[2].cy=-s*ca;
  discs[3].cx=-s*sa;discs[3].cy=s*ca;
  needRecompute=true;
}

function loop(){
  updateOrbit();
  if(needRecompute)computeFractal();
  draw();
  requestAnimationFrame(loop);
}

// Pointer interaction
function hitDisc(wx,wy){
  for(var i=0;i<4;i++){
    var dx=wx-discs[i].cx,dy=wy-discs[i].cy;
    if(dx*dx+dy*dy<discs[i].r*discs[i].r)return i;
  }
  return-1;
}

var pointerDown=false;
function pDown(px,py,isRight){
  if(isRight){SFX.play('click');resetDiscs();return;}
  var w=s2w(px,py);
  var idx=hitDisc(w.x,w.y);
  if(idx>=0){
    dragIdx=idx;dragOx=w.x-discs[idx].cx;dragOy=w.y-discs[idx].cy;
    pointerDown=true;
    SFX.play('spawn');
  }else{
    pointerDown=true;
  }
}

function pMove(px,py){
  if(!pointerDown||dragIdx<0)return;
  var w=s2w(px,py);
  discs[dragIdx].cx=w.x-dragOx;
  discs[dragIdx].cy=w.y-dragOy;
  needRecompute=true;
  SFX.play('move',120);
}

function pUp(){
  if(dragIdx>=0)SFX.play('pop');
  pointerDown=false;dragIdx=-1;
}

c.addEventListener('mousedown',function(e){e.preventDefault();pDown(e.clientX,e.clientY,e.button===2);});
c.addEventListener('mousemove',function(e){pMove(e.clientX,e.clientY);});
c.addEventListener('mouseup',function(){pUp();});
c.addEventListener('contextmenu',function(e){e.preventDefault();});

c.addEventListener('touchstart',function(e){e.preventDefault();var t=e.touches[0];pDown(t.clientX,t.clientY,false);},{passive:false});
c.addEventListener('touchmove',function(e){e.preventDefault();var t=e.touches[0];pMove(t.clientX,t.clientY);},{passive:false});
c.addEventListener('touchend',function(e){e.preventDefault();pUp();},{passive:false});

// Scroll to resize circles
c.addEventListener('wheel',function(e){
  e.preventDefault();
  var delta=e.deltaY>0?-0.02:0.02;
  baseRadius=Math.max(0.15,Math.min(0.95,baseRadius+delta));
  for(var i=0;i<4;i++)discs[i].r=baseRadius;
  document.getElementById('sl-radius').value=Math.round(baseRadius*100);
  document.getElementById('sv-radius').textContent=baseRadius.toFixed(2);
  needRecompute=true;
  SFX.play('tick',60);
},{passive:false});

// Controls
var btnOrbit=document.getElementById('btn-orbit');
var btnFree=document.getElementById('btn-free');
btnOrbit.addEventListener('click',function(){orbitMode=true;btnOrbit.classList.add('active');btnFree.classList.remove('active');SFX.play('toggle');resetDiscs();});
btnFree.addEventListener('click',function(){orbitMode=false;btnFree.classList.add('active');btnOrbit.classList.remove('active');SFX.play('toggle');});

var slDepth=document.getElementById('sl-depth'),svDepth=document.getElementById('sv-depth');
slDepth.addEventListener('input',function(){maxDepth=parseInt(this.value);svDepth.textContent=maxDepth;needRecompute=true;SFX.play('tick',80);});

var slRadius=document.getElementById('sl-radius'),svRadius=document.getElementById('sv-radius');
slRadius.addEventListener('input',function(){baseRadius=this.value/100;svRadius.textContent=baseRadius.toFixed(2);for(var i=0;i<4;i++)discs[i].r=baseRadius;needRecompute=true;SFX.play('tick',80);});

var slSpread=document.getElementById('sl-spread'),svSpread=document.getElementById('sv-spread');
slSpread.addEventListener('input',function(){baseSpread=this.value/100;svSpread.textContent=baseSpread.toFixed(2);if(orbitMode)resetDiscs();needRecompute=true;SFX.play('tick',80);});

document.getElementById('btn-reset').addEventListener('click',function(){
  SFX.play('click');
  baseSpread=1.6;baseRadius=0.55;maxDepth=7;
  slDepth.value=7;svDepth.textContent='7';
  slRadius.value=55;svRadius.textContent='0.55';
  slSpread.value=160;svSpread.textContent='1.60';
  resetDiscs();
});

window.addEventListener('resize',resize);
resize();
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
