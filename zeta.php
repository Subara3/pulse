<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('zeta');

$pageName = 'zeta';
$pageTitle = 'ZETA - PULSE';
$pageDesc = 'リーマンゼータ関数の領域彩色。非自明零点が臨界線Re(s)=1/2に並ぶ数論の深淵を覗き込む。';
$pageCanonical = 'https://pulse.subara3.com/zeta.php';
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
<div class="hud"><span class="sub">ZETA</span><br>s = <b id="h-re">0.50</b> + <b id="h-im">14.1</b>i<br>|ζ| = <b id="h-mod">0.00</b></div>
<div class="mark">ZETA</div>
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
    <button class="control-btn active" id="btn-contour">CONTOUR</button>
    <button class="control-btn" id="btn-zeros">ZEROS</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d');
var W,H,cx=0.5,cy=0,scale,img,dat;
var curPass=0,curRow=0,rendering=false;
var passes=[16,8,4,2];
var showContour=true,showZeros=false;
var mouseRe=0.5,mouseIm=14.13,mouseMod=0;
var dragging=false,dragSX,dragSY,dragCX,dragCY;
var time=0;
var ZEROS=[14.134725,21.022040,25.010858,30.424876,32.935062,37.586178,40.918719,43.327073,48.005151,49.773832,52.970321,56.446247,59.347044,60.831779,65.112544,67.079811,69.546401,72.067158,75.704691,77.144840];
var particles=[];
var NETA=20,etaW=new Array(NETA),etaLn=new Array(NETA);
(function(){
  var b=1,s=1;
  var dk=new Array(NETA+1);
  dk[0]=1;s=1;
  for(var j=1;j<=NETA;j++){
    b=b*(NETA-j+1)/j;
    s+=b;
    dk[j]=s;
  }
  var p2=Math.pow(2,NETA);
  for(var k=0;k<NETA;k++) etaW[k]=(p2-dk[k])/p2;
  for(var k=0;k<NETA;k++) etaLn[k]=Math.log(k+1);
})();

function brandHue(t){
  t=t<0?0:t>1?1:t;
  var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);
  return 172+e*118;
}
function hsl2rgb(h,s,l){
  h=((h%360)+360)%360;
  var c=(1-Math.abs(2*l-1))*s;
  var x=c*(1-Math.abs((h/60)%2-1));
  var m=l-c/2;
  var r,g,b;
  if(h<60){r=c;g=x;b=0;}
  else if(h<120){r=x;g=c;b=0;}
  else if(h<180){r=0;g=c;b=x;}
  else if(h<240){r=0;g=x;b=c;}
  else if(h<300){r=x;g=0;b=c;}
  else{r=c;g=0;b=x;}
  return[(r+m)*255|0,(g+m)*255|0,(b+m)*255|0];
}

function etaFunc(sr,si){
  var rr=0,ri=0;
  for(var k=0;k<NETA;k++){
    var er=Math.exp(-sr*etaLn[k]);
    var ang=-si*etaLn[k];
    var tr=er*Math.cos(ang),ti=er*Math.sin(ang);
    var sign=(k&1)?-1:1;
    rr+=sign*etaW[k]*tr;
    ri+=sign*etaW[k]*ti;
  }
  return[rr,ri];
}
function zetaRight(sr,si){
  var e=etaFunc(sr,si);
  var ln2=0.6931471805599453;
  var eR=(1-sr)*ln2,eI=-si*ln2;
  var p=Math.exp(eR);
  var dR=1-p*Math.cos(eI),dI=p*Math.sin(eI);
  var dd=dR*dR+dI*dI;
  if(dd<1e-20) return[1e8,0];
  return[(e[0]*dR+e[1]*dI)/dd,(e[1]*dR-e[0]*dI)/dd];
}

var LANCZOS_G=7,LANCZOS_P=[0.99999999999980993,676.5203681218851,-1259.1392167224028,771.32342877765313,-176.61502916214059,12.507343278686905,-0.13857109526572012,9.9843695780195716e-6,1.5056327351493116e-7];
function cgamma(re,im){
  if(re<0.5){
    var sr=Math.sin(Math.PI*re)*Math.cosh(Math.PI*im);
    var si=Math.cos(Math.PI*re)*Math.sinh(Math.PI*im);
    var g=cgamma(1-re,-im);
    var pr=sr*g[0]-si*g[1],pi=sr*g[1]+si*g[0];
    var dd=pr*pr+pi*pi;
    if(dd<1e-30) return[1e8,0];
    return[Math.PI*pr/dd,-Math.PI*pi/dd];
  }
  re-=1;
  var xr=LANCZOS_P[0],xi=0;
  for(var i=1;i<9;i++){
    var dr=re+i,di=im,dd=dr*dr+di*di;
    xr+=LANCZOS_P[i]*dr/dd;
    xi+=-LANCZOS_P[i]*di/dd;
  }
  var tr=re+LANCZOS_G+0.5,ti=im;
  var ltr=0.5*Math.log(tr*tr+ti*ti),lti=Math.atan2(ti,tr);
  var pR=(re+0.5)*ltr-im*lti,pI=(re+0.5)*lti+im*ltr;
  var eR=Math.exp(-tr),ecos=Math.cos(-ti),esin=Math.sin(-ti);
  var enR=eR*ecos,enI=eR*esin;
  var aR=Math.exp(pR),aC=aR*Math.cos(pI),aS=aR*Math.sin(pI);
  var bR=aC*enR-aS*enI,bI=aC*enI+aS*enR;
  var sq=2.5066282746310002;
  return[sq*(bR*xr-bI*xi),sq*(bR*xi+bI*xr)];
}

function zeta(sr,si){
  if(sr>=0.5) return zetaRight(sr,si);
  var z1=zetaRight(1-sr,-si);
  var ln2=0.6931471805599453,lnPi=1.1447298858494002;
  var aE=Math.exp(sr*ln2),aR=aE*Math.cos(si*ln2),aI=aE*Math.sin(si*ln2);
  var bE=Math.exp((sr-1)*lnPi),bR=bE*Math.cos(si*lnPi),bI=bE*Math.sin(si*lnPi);
  var cR=Math.sin(Math.PI*sr/2)*Math.cosh(Math.PI*si/2);
  var cI=Math.cos(Math.PI*sr/2)*Math.sinh(Math.PI*si/2);
  var g=cgamma(1-sr,-si);
  var r0=aR*bR-aI*bI,r1=aR*bI+aI*bR;
  var r2=r0*cR-r1*cI,r3=r0*cI+r1*cR;
  var r4=r2*g[0]-r3*g[1],r5=r2*g[1]+r3*g[0];
  return[r4*z1[0]-r5*z1[1],r4*z1[1]+r5*z1[0]];
}

function colorAt(sr,si){
  var z=zeta(sr,si);
  var mod=Math.sqrt(z[0]*z[0]+z[1]*z[1]);
  var phase=Math.atan2(z[1],z[0]);
  if(phase<0) phase+=6.283185307;
  var t=phase/6.283185307;
  var h=brandHue(t);
  var lm=Math.log(1+mod);
  var l=0.06+0.48*(1-1/(1+lm*0.35));
  if(showContour){
    var ring=Math.sin(lm*5)*0.5+0.5;
    l=l*(0.65+0.35*ring);
  }
  var s=0.72;
  return hsl2rgb(h,s,l);
}

function startRender(){
  curPass=0;curRow=0;rendering=true;
  img=ctx.createImageData(W,H);
  dat=img.data;
}

function renderChunk(){
  if(!rendering||curPass>=passes.length) {rendering=false;return;}
  var bs=passes[curPass];
  var gw=Math.ceil(W/bs),gh=Math.ceil(H/bs);
  var rPerF=Math.max(1,Math.floor(4000/gw));
  var endR=Math.min(curRow+rPerF,gh);
  for(var gy=curRow;gy<endR;gy++){
    for(var gx=0;gx<gw;gx++){
      var px=gx*bs+bs*0.5,py=gy*bs+bs*0.5;
      var sr=cx+(px-W*0.5)*scale;
      var si=cy+(H*0.5-py)*scale;
      var rgb=colorAt(sr,si);
      var x0=gx*bs,y0=gy*bs;
      var x1=Math.min(x0+bs,W),y1=Math.min(y0+bs,H);
      for(var py2=y0;py2<y1;py2++){
        var off=(py2*W+x0)*4;
        for(var px2=x0;px2<x1;px2++){
          dat[off]=rgb[0];dat[off+1]=rgb[1];dat[off+2]=rgb[2];dat[off+3]=255;
          off+=4;
        }
      }
    }
  }
  curRow=endR;
  if(curRow>=gh){curPass++;curRow=0;}
  ctx.putImageData(img,0,0);
}

function drawOverlays(){
  var critX=(0.5-cx)/scale+W*0.5;
  if(critX>0&&critX<W){
    ctx.save();
    ctx.setLineDash([6,8]);
    ctx.strokeStyle='rgba(181,140,255,0.25)';
    ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(critX,0);ctx.lineTo(critX,H);ctx.stroke();
    ctx.restore();
  }
  var reAxis=(cy)/scale+H*0.5;
  if(reAxis>0&&reAxis<H){
    ctx.save();
    ctx.strokeStyle='rgba(255,255,255,0.06)';
    ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(0,reAxis);ctx.lineTo(W,reAxis);ctx.stroke();
    ctx.restore();
  }
  if(showZeros){
    for(var i=0;i<ZEROS.length;i++){
      var zy=ZEROS[i];
      drawZeroMarker(0.5,zy,i);
      drawZeroMarker(0.5,-zy,i);
    }
  }
}

function drawZeroMarker(re,im,idx){
  var px=(re-cx)/scale+W*0.5;
  var py=H*0.5-(im-cy)/scale;
  if(px<-40||px>W+40||py<-40||py>H+40) return;
  var pulse=0.5+0.5*Math.sin(time*2+idx*0.7);
  var r=3+pulse*4;
  ctx.beginPath();ctx.arc(px,py,r,0,6.2832);
  ctx.strokeStyle='rgba(0,245,212,'+(0.3+pulse*0.5)+')';
  ctx.lineWidth=1.5;ctx.stroke();
  var gr=ctx.createRadialGradient(px,py,0,px,py,r+8);
  gr.addColorStop(0,'rgba(0,245,212,'+(0.15+pulse*0.1)+')');
  gr.addColorStop(1,'rgba(0,245,212,0)');
  ctx.beginPath();ctx.arc(px,py,r+8,0,6.2832);ctx.fillStyle=gr;ctx.fill();
}

function spawnParticle(){
  var re=cx+(Math.random()-0.5)*W*scale;
  var im=cy+(Math.random()-0.5)*H*scale;
  particles.push({re:re,im:im,life:1,decay:0.003+Math.random()*0.005,
    vr:(Math.random()-0.5)*scale*0.3,vi:(Math.random()-0.5)*scale*0.3});
}
function updateParticles(){
  for(var i=particles.length-1;i>=0;i--){
    var p=particles[i];
    p.re+=p.vr;p.im+=p.vi;
    p.vr*=0.99;p.vi*=0.99;
    p.life-=p.decay;
    if(p.life<=0) particles.splice(i,1);
  }
  while(particles.length<80) spawnParticle();
}
function drawParticles(){
  for(var i=0;i<particles.length;i++){
    var p=particles[i];
    var px=(p.re-cx)/scale+W*0.5;
    var py=H*0.5-(p.im-cy)/scale;
    if(px<0||px>W||py<0||py>H) continue;
    var a=p.life*0.3;
    ctx.beginPath();ctx.arc(px,py,1.2,0,6.2832);
    ctx.fillStyle='rgba(0,245,212,'+a+')';ctx.fill();
  }
}

function updateHUD(){
  var hr=document.getElementById('h-re'),hi=document.getElementById('h-im'),hm=document.getElementById('h-mod');
  if(hr) hr.textContent=mouseRe.toFixed(2);
  if(hi) hi.textContent=mouseIm.toFixed(1);
  if(hm) hm.textContent=mouseMod.toFixed(3);
}

function screenToComplex(sx,sy){
  return[cx+(sx-W*0.5)*scale,cy+(H*0.5-sy)*scale];
}

function zoomAt(sx,sy,factor){
  var s=screenToComplex(sx,sy);
  var ns=scale*factor;
  cx=s[0]-(sx-W*0.5)*ns;
  cy=s[1]-(H*0.5-sy)*ns;
  scale=ns;
  particles=[];
  startRender();
}

c.addEventListener('mousedown',function(e){
  if(e.button===2){zoomAt(e.clientX,e.clientY,2);SFX.play('click');return;}
  if(e.button===0){
    dragging=true;dragSX=e.clientX;dragSY=e.clientY;dragCX=cx;dragCY=cy;
  }
});
c.addEventListener('mousemove',function(e){
  var s=screenToComplex(e.clientX,e.clientY);
  mouseRe=s[0];mouseIm=s[1];
  var z=zeta(s[0],s[1]);
  mouseMod=Math.sqrt(z[0]*z[0]+z[1]*z[1]);
  if(dragging){
    cx=dragCX-(e.clientX-dragSX)*scale;
    cy=dragCY+(e.clientY-dragSY)*scale;
    startRender();
    SFX.play('move',150);
  }
});
window.addEventListener('mouseup',function(e){
  if(dragging&&Math.abs(e.clientX-dragSX)<4&&Math.abs(e.clientY-dragSY)<4){
    zoomAt(e.clientX,e.clientY,0.5);
    SFX.play('spawn');
  }
  dragging=false;
});
c.addEventListener('contextmenu',function(e){e.preventDefault();});
c.addEventListener('wheel',function(e){
  e.preventDefault();
  var f=e.deltaY>0?1.15:1/1.15;
  zoomAt(e.clientX,e.clientY,f);
  SFX.play('tick',80);
},{passive:false});

var touchDist=0,touchMid=[0,0],touchCX,touchCY,touchScale;
c.addEventListener('touchstart',function(e){
  e.preventDefault();
  if(e.touches.length===1){
    dragging=true;dragSX=e.touches[0].clientX;dragSY=e.touches[0].clientY;dragCX=cx;dragCY=cy;
  } else if(e.touches.length===2){
    dragging=false;
    var dx=e.touches[1].clientX-e.touches[0].clientX;
    var dy=e.touches[1].clientY-e.touches[0].clientY;
    touchDist=Math.sqrt(dx*dx+dy*dy);
    touchMid=[(e.touches[0].clientX+e.touches[1].clientX)/2,(e.touches[0].clientY+e.touches[1].clientY)/2];
    touchCX=cx;touchCY=cy;touchScale=scale;
  }
},{passive:false});
c.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(e.touches.length===1&&dragging){
    cx=dragCX-(e.touches[0].clientX-dragSX)*scale;
    cy=dragCY+(e.touches[0].clientY-dragSY)*scale;
    startRender();
    SFX.play('move',150);
  } else if(e.touches.length===2){
    var dx=e.touches[1].clientX-e.touches[0].clientX;
    var dy=e.touches[1].clientY-e.touches[0].clientY;
    var d=Math.sqrt(dx*dx+dy*dy);
    var f=touchDist/d;
    var mx=(e.touches[0].clientX+e.touches[1].clientX)/2;
    var my=(e.touches[0].clientY+e.touches[1].clientY)/2;
    scale=touchScale*f;
    cx=touchCX-(mx-touchMid[0])*scale+(mx-W*0.5)*(scale-touchScale);
    cy=touchCY+(my-touchMid[1])*scale-(H*0.5-my)*(scale-touchScale);
    startRender();
  }
},{passive:false});
c.addEventListener('touchend',function(e){
  if(e.touches.length===0){
    if(dragging&&Math.abs(e.changedTouches[0].clientX-dragSX)<8&&Math.abs(e.changedTouches[0].clientY-dragSY)<8){
      zoomAt(e.changedTouches[0].clientX,e.changedTouches[0].clientY,0.5);
      SFX.play('spawn');
    }
    dragging=false;
  }
});

document.getElementById('btn-contour').addEventListener('click',function(){
  showContour=!showContour;this.classList.toggle('active',showContour);
  startRender();SFX.play('toggle');
});
document.getElementById('btn-zeros').addEventListener('click',function(){
  showZeros=!showZeros;this.classList.toggle('active',showZeros);
  SFX.play('toggle');
});
document.getElementById('btn-reset').addEventListener('click',function(){
  cx=0.5;cy=0;scale=50/Math.min(W,H);particles=[];
  startRender();SFX.play('click');
});

function loop(){
  if(rendering) renderChunk();
  else{
    ctx.putImageData(img,0,0);
  }
  drawOverlays();
  updateParticles();
  drawParticles();
  updateHUD();
  time+=1/60;
  requestAnimationFrame(loop);
}

function init(){
  W=c.width=window.innerWidth;
  H=c.height=window.innerHeight;
  scale=50/Math.min(W,H);
  particles=[];
  startRender();
}

window.addEventListener('resize',init);
init();
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
