<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fractal');

$pageName = 'fractal';
$pageTitle = 'FRACTAL - PULSE';
$pageDesc = 'マンデルブロ集合を拡大していく。式を z^d・絶対値つき（バーニングシップ）・sin・exp に切り替えると、同じ手順から違う海岸線が出てくる。気に入った点はジュリア集合に裏返せる。';
$pageCanonical = 'https://pulse.subara3.com/fractal.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:#b58cff}
.formula{position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:20;display:flex;gap:.35rem;padding:.3rem;background:rgba(10,10,15,.55);border:1px solid rgba(0,245,212,.14);border-radius:999px;backdrop-filter:blur(12px)}
.formula button{font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;color:rgba(255,255,255,.6);background:none;border:0;border-radius:999px;padding:.55rem .8rem;cursor:pointer;transition:color .2s ease-out,background .2s ease-out}
.formula button:hover{color:#fff}
.formula button.active{color:#0a0a0f;background:#00f5d4}
.eq{position:fixed;left:1rem;bottom:1rem;z-index:20;font:12px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.1em;color:rgba(255,255,255,.42)}
.eq b{color:#00f5d4;font-weight:600}
.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}
.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
input[type=range]{width:100%;accent-color:#00f5d4}
.slider-row.off{opacity:.35;pointer-events:none}
@media(max-width:760px){.hud{top:6.2rem;right:.75rem;font-size:10px;padding:.5rem .6rem}.formula{top:3.3rem;gap:.1rem;padding:.2rem}.formula button{font-size:10px;padding:.5rem .55rem;letter-spacing:.06em}.eq{font-size:10px;left:.75rem;bottom:5.2rem}.slider-row{grid-template-columns:62px 1fr 44px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="formula" id="formula">
  <button data-f="mandel" class="active">MANDEL</button><button data-f="multi">z^d</button><button data-f="ship">SHIP</button><button data-f="sin">SIN</button><button data-f="exp">EXP</button>
</div>
<div class="hud"><span class="sub" id="h-kind">SET</span><br>ZOOM <b id="h-zoom">1</b><br>ITER <b id="h-iter">96</b><br><span id="h-extra">d <b id="h-d">2.00</b></span></div>
<div class="eq" id="eq">z → <b>z² + c</b></div>
<?php $interactionHints=[['type'=>'click','label'=>'zoom in'],['type'=>'drag','label'=>'pan'],['type'=>'right','label'=>'zoom out']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row"><button class="control-btn active" id="btn-drift">DRIFT</button><button class="control-btn" id="btn-spot">NEXT SPOT</button><button class="control-btn" id="btn-julia">JULIA</button></div>
  <div class="slider-row off" id="row-d"><span class="slider-label">POWER d</span><input type="range" id="sl-d" min="200" max="800" value="200"><span class="slider-value" id="sv-d">2.00</span></div>
  <div class="btn-row"><button class="control-btn" id="btn-morph">MORPH d</button></div>
  <div class="slider-row"><span class="slider-label">ITER</span><input type="range" id="sl-iter" min="48" max="400" value="96"><span class="slider-value" id="sv-iter">96</span></div>
  <div class="btn-row"><button class="control-btn active" data-palette="pulse">PULSE</button><button class="control-btn" data-palette="veil">VEIL</button><button class="control-btn" data-palette="ice">ICE</button></div>
  <div class="btn-row"><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

/* ---- formulas and their sightseeing spots (world coords, view width, power) ---- */
var F={
  mandel:{eq:'z² + c',bail:256,home:[-0.6,0,3.4],spots:[[-0.7436439,0.1318259,0.012],[-1.7686,0.0012,0.035],[0.2822,0.0097,0.016],[-0.1011,0.9563,0.012]]},
  multi:{eq:'z^d + c',bail:256,home:[0,0,3.2],spots:[[0,0,3.2,3],[-0.62,0.31,0.5,4],[0.3,0.62,0.4,5.5]]},
  ship:{eq:'(|Re z| + i|Im z|)² + c',bail:256,home:[-0.45,-0.5,3.6],flip:true,spots:[[-1.7616,-0.0282,0.06],[-1.941,-0.0015,0.02],[-1.625,-0.0045,0.04]]},
  sin:{eq:'c · sin z',bail:2500,home:[0,0,8],spots:[[1.0,0.35,0.35],[2.2,0.6,1.4],[0,1.25,1.6]]},
  exp:{eq:'c · exp z',bail:2500,home:[0.2,0,4.5],spots:[[1.2,0,1.5],[0.37,0,0.4],[0.62,0.2,0.5]]}
};

var c=document.getElementById('canvas'),ctx=c.getContext('2d'),W=0,H=0,img=null,dat=null;
var form='mandel',julia=false,jR=-0.7,jI=0.27,power=2;
var cx=-0.6,cy=0,vw=3.4,baseIter=96,pal='pulse';
var drift=true,morph=false,spotIdx=0,target=null,t=0,rest=0;
var BLOCKS=[8,4,2,1],curB=0,curY=0,rendering=false,animating=false;
var drag=null,pinch0=0,pinchVW=0,lastTap=0;

var $=function(id){return document.getElementById(id)};
var hK=$('h-kind'),hZ=$('h-zoom'),hI=$('h-iter'),hD=$('h-d'),hX=$('h-extra'),eq=$('eq');
var slD=$('sl-d'),svD=$('sv-d'),slI=$('sl-iter'),svI=$('sv-iter'),rowD=$('row-d');

/* ---- palettes (teal/purple only) ---- */
var PL=1024,pR={},pG={},pB={};
function brandHue(t){t=t<0?0:t>1?1:t;var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);return 172+e*118}
function hsl(h,s,l){s/=100;l/=100;var a=s*Math.min(l,1-l);function f(n){var k=(n+h/30)%12;return l-a*Math.max(-1,Math.min(k-3,9-k,1))}return[f(0)*255|0,f(8)*255|0,f(4)*255|0]}
function build(name,fn){var r=new Uint8Array(PL),g=new Uint8Array(PL),b=new Uint8Array(PL);for(var i=0;i<PL;i++){var q=fn(i/PL);r[i]=q[0];g[i]=q[1];b[i]=q[2]}pR[name]=r;pG[name]=g;pB[name]=b}
(function(){
  var A=[[2,14,20],[0,120,100],[0,245,212],[0,80,100],[60,20,100],[123,44,191],[181,140,255],[40,8,65],[2,14,20]],n=A.length-1;
  build('pulse',function(t){var p=t*n,i=Math.floor(p),f=p-i;f=f*f*(3-2*f);var a=A[i],b=A[Math.min(i+1,n)];return[a[0]+(b[0]-a[0])*f,a[1]+(b[1]-a[1])*f,a[2]+(b[2]-a[2])*f]});
  build('veil',function(t){var w=.5-.5*Math.cos(t*Math.PI*2);return hsl(brandHue(w),62+30*w,5+52*Math.sqrt(w))});
  build('ice',function(t){var w=.5-.5*Math.cos(t*Math.PI*2);return hsl(178+10*w,70,4+58*w)});
})();

function iterNow(){var z=Math.max(1,3.4/vw);return Math.min(1200,Math.round(baseIter+36*Math.log2(z)))}

/* ---- per-pixel escape time ---- */
function escape(x0,y0,mi){
  var zr,zi,cr,ci,n=0,r2=0,b=F[form].bail;
  if(julia){zr=x0;zi=y0;cr=jR;ci=jI}
  else if(form==='sin'||form==='exp'){zr=x0;zi=y0;cr=x0;ci=y0}
  else{zr=0;zi=0;cr=x0;ci=y0}
  if(form==='mandel'){
    for(;n<mi;n++){var a=zr*zr,bb=zi*zi;r2=a+bb;if(r2>b)break;zi=2*zr*zi+ci;zr=a-bb+cr}
  }else if(form==='ship'){
    for(;n<mi;n++){var a2=zr*zr,b2=zi*zi;r2=a2+b2;if(r2>b)break;zi=2*Math.abs(zr*zi)+ci;zr=a2-b2+cr}
  }else if(form==='multi'){
    var d=power;
    for(;n<mi;n++){r2=zr*zr+zi*zi;if(r2>b)break;var rn=Math.pow(r2,d/2),th=Math.atan2(zi,zr)*d;zr=rn*Math.cos(th)+cr;zi=rn*Math.sin(th)+ci}
  }else if(form==='sin'){
    for(;n<mi;n++){var sr=Math.sin(zr)*Math.cosh(zi),si=Math.cos(zr)*Math.sinh(zi);var tr=cr*sr-ci*si;zi=cr*si+ci*sr;zr=tr;r2=zr*zr+zi*zi;if(!(r2<=b))break}
  }else{
    for(;n<mi;n++){var ea=Math.exp(zr),er=ea*Math.cos(zi),ei=ea*Math.sin(zi);var tr2=cr*er-ci*ei;zi=cr*ei+ci*er;zr=tr2;r2=zr*zr+zi*zi;if(!(r2<=b))break}
  }
  if(n>=mi)return -1;
  if(r2!==r2||r2===Infinity)r2=1e8;
  var lg=form==='multi'?Math.log(Math.max(power,1.01)):0.6931;
  var mu=n+1-Math.log(Math.max(Math.log(r2)*0.5,1e-9))/lg;
  return mu<0?0:mu;
}

function paintRows(bs,y0,y1,skipPrev){
  var px2=vw/W,flip=F[form].flip&&!julia?-1:1,mi=iterNow(),r=pR[pal],g=pG[pal],b=pB[pal],spd=form==='sin'||form==='exp'?90:64;
  for(var py=y0;py<y1;py+=bs){
    for(var px=0;px<W;px+=bs){
      if(skipPrev&&py%skipPrev===0&&px%skipPrev===0)continue;
      var mu=escape(cx+(px-W*.5)*px2,cy+flip*(py-H*.5)*px2,mi),R,G,B;
      if(mu<0){R=8;G=7;B=14}else{var k=((Math.sqrt(mu)*spd)|0)%PL;R=r[k];G=g[k];B=b[k]}
      var yl=Math.min(py+bs,H),xl=Math.min(px+bs,W);
      for(var sy=py;sy<yl;sy++){var o=(sy*W+px)*4;for(var sx=px;sx<xl;sx++){dat[o]=R;dat[o+1]=G;dat[o+2]=B;dat[o+3]=255;o+=4}}
    }
  }
}

function startRender(){curB=0;curY=0;rendering=true}
function renderStep(){
  if(animating){paintRows(W*H>900000?6:4,0,H,0);ctx.putImageData(img,0,0);curB=1;curY=0;rendering=true;return}
  if(!rendering)return;
  var t0=performance.now();
  while(rendering&&performance.now()-t0<14){
    var bs=BLOCKS[curB],prev=curB>0?BLOCKS[curB-1]:0,rows=bs*4;
    paintRows(bs,curY,Math.min(curY+rows,H),prev);
    curY+=rows;
    if(curY>=H){curB++;curY=0;if(curB>=BLOCKS.length)rendering=false}
  }
  ctx.putImageData(img,0,0);
}

function hud(){
  var z=3.4/vw;
  hK.textContent=(julia?'JULIA':'SET')+' · '+form.toUpperCase();
  hZ.textContent=z>=1e4?z.toExponential(1):z>=10?z.toFixed(0):z.toFixed(2);
  hI.textContent=iterNow();
  hX.style.display=form==='multi'?'':'none';
  hD.textContent=power.toFixed(2);svD.textContent=power.toFixed(2);svI.textContent=baseIter;
  var e=F[form].eq;if(form==='multi')e='z^'+power.toFixed(2)+' + c';
  eq.innerHTML='z → <b>'+e+'</b>'+(julia?'  c = '+jR.toFixed(3)+(jI<0?' − ':' + ')+Math.abs(jI).toFixed(3)+'i':'');
}

/* ---- navigation ---- */
function flyTo(s){target={x:s[0],y:s[1],w:s[2],d:s[3]}}
function setForm(f,silent){
  form=f;julia=false;$('btn-julia').classList.remove('active');
  document.querySelectorAll('#formula button').forEach(function(b){b.classList.toggle('active',b.dataset.f===f)});
  var h=F[f].home;cx=h[0];cy=h[1];vw=h[2];target=null;spotIdx=0;
  if(f!=='multi'){power=2;morph=false;$('btn-morph').classList.remove('active')}
  else if(!morph){morph=true;$('btn-morph').classList.add('active')}
  rowD.classList.toggle('off',f!=='multi');slD.value=Math.round(power*100);
  if(drift&&f!=='multi')flyTo(F[f].spots[0]);
  startRender();hud();if(!silent&&window.SFX)SFX.play('toggle');
}
function nextSpot(){
  var s=F[form].spots;spotIdx=(spotIdx+1)%s.length;julia=false;$('btn-julia').classList.remove('active');
  var h=F[form].home;cx=h[0];cy=h[1];vw=h[2];flyTo(s[spotIdx]);
  if(s[spotIdx][3]){morph=false;$('btn-morph').classList.remove('active')}
  if(window.SFX)SFX.play('success');
}
function world(mx,my){var p=vw/W,fl=F[form].flip&&!julia?-1:1;return{x:cx+(mx-W*.5)*p,y:cy+fl*(my-H*.5)*p}}
function zoomAt(mx,my,f){var w=world(mx,my),fl=F[form].flip&&!julia?-1:1;vw*=f;var p=vw/W;cx=w.x-(mx-W*.5)*p;cy=w.y-fl*(my-H*.5)*p;target=null;startRender();hud()}
function stopAuto(){if(drift){drift=false;$('btn-drift').classList.remove('active')}target=null}

function resize(){W=c.width=innerWidth;H=c.height=innerHeight;img=ctx.createImageData(W,H);dat=img.data;startRender()}

/* ---- pointer ---- */
c.addEventListener('contextmenu',function(e){e.preventDefault()});
c.addEventListener('pointerdown',function(e){
  if(e.pointerType==='touch'&&drag)return;
  if(e.button===2){stopAuto();zoomAt(e.clientX,e.clientY,2);if(window.SFX)SFX.play('pop');return}
  drag={x:e.clientX,y:e.clientY,cx:cx,cy:cy,moved:false,id:e.pointerId};c.setPointerCapture(e.pointerId);
});
c.addEventListener('pointermove',function(e){
  if(!drag||e.pointerId!==drag.id||pinch0)return;
  var dx=e.clientX-drag.x,dy=e.clientY-drag.y;
  if(!drag.moved&&dx*dx+dy*dy<36)return;
  if(!drag.moved){drag.moved=true;stopAuto()}
  var p=vw/W,fl=F[form].flip&&!julia?-1:1;cx=drag.cx-dx*p;cy=drag.cy-fl*dy*p;startRender();hud();
  if(window.SFX)SFX.play('move',160);
});
c.addEventListener('pointerup',function(e){
  if(!drag||e.pointerId!==drag.id)return;
  if(!drag.moved&&!pinch0){stopAuto();zoomAt(e.clientX,e.clientY,.5);if(window.SFX)SFX.play('spawn')}
  drag=null;
});
c.addEventListener('pointercancel',function(){drag=null});
c.addEventListener('wheel',function(e){e.preventDefault();stopAuto();zoomAt(e.clientX,e.clientY,e.deltaY<0?.8:1.25);if(window.SFX)SFX.play('tick',110)},{passive:false});
c.addEventListener('touchstart',function(e){if(e.touches.length===2){var a=e.touches[0],b=e.touches[1];pinch0=Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);pinchVW=vw;drag=null;stopAuto()}},{passive:true});
c.addEventListener('touchmove',function(e){if(e.touches.length===2&&pinch0){e.preventDefault();var a=e.touches[0],b=e.touches[1];vw=pinchVW*pinch0/Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);startRender();hud()}},{passive:false});
c.addEventListener('touchend',function(e){if(e.touches.length<2)setTimeout(function(){pinch0=0},60)},{passive:true});

/* ---- controls ---- */
document.querySelectorAll('#formula button').forEach(function(b){b.onclick=function(){setForm(b.dataset.f)}});
$('btn-drift').onclick=function(e){drift=!drift;e.currentTarget.classList.toggle('active',drift);if(drift&&!target&&!julia)flyTo(F[form].spots[spotIdx]);if(window.SFX)SFX.play('toggle')};
$('btn-spot').onclick=nextSpot;
$('btn-julia').onclick=function(e){
  julia=!julia;e.currentTarget.classList.toggle('active',julia);target=null;
  if(julia){jR=cx;jI=cy;cx=0;cy=0;vw=form==='sin'||form==='exp'?6:3.6}else{var h=F[form].home;cx=jR;cy=jI;vw=Math.min(h[2],.5)}
  startRender();hud();if(window.SFX)SFX.play('toggle');
};
$('btn-morph').onclick=function(e){if(form!=='multi')setForm('multi',true);morph=!morph;e.currentTarget.classList.toggle('active',morph);if(window.SFX)SFX.play('toggle')};
slD.oninput=function(){morph=false;$('btn-morph').classList.remove('active');power=+slD.value/100;startRender();hud();if(window.SFX)SFX.play('move',90)};
slI.oninput=function(){baseIter=+slI.value;startRender();hud()};
document.querySelectorAll('[data-palette]').forEach(function(b){b.onclick=function(){pal=b.dataset.palette;document.querySelectorAll('[data-palette]').forEach(function(q){q.classList.toggle('active',q===b)});startRender();if(window.SFX)SFX.play('toggle')}});
$('btn-reset').onclick=function(){baseIter=96;slI.value=96;setForm(form,true);if(window.SFX)SFX.play('success')};

/* ---- loop ---- */
function tick(){
  t+=1/60;animating=false;
  if(morph&&form==='multi'){power=5-3*Math.cos(t*.22);slD.value=Math.round(power*100);animating=true}
  if(target){
    var k=target.k||.035;cx+=(target.x-cx)*k;cy+=(target.y-cy)*k;
    var lw=Math.log(vw),lt=Math.log(target.w);vw=Math.exp(lw+(lt-lw)*k*.8);
    if(target.d){power+=(target.d-power)*k;slD.value=Math.round(power*100)}
    animating=true;
    if(Math.abs(lw-lt)<.02&&Math.hypot(target.x-cx,target.y-cy)<vw*.01){target=null}
  }else if(drift&&!julia&&!rendering&&!drag){
    rest+=1/60;
    if(rest>1.4){rest=0;if(vw<3e-12)setForm(form,true);else target={x:cx,y:cy,w:vw*.45,k:.09}}
  }
  if(animating)hud();
  renderStep();
  requestAnimationFrame(tick);
}
addEventListener('resize',resize);resize();setForm('mandel',true);tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
