<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tame');

$pageName = 'tame';
$pageTitle = 'TAME - PULSE';
$pageDesc = 'カオス制御。ロジスティック写像のカオス軌道を微小な摂動で不安定周期軌道に安定化する。OGY法の可視化。';
$pageCanonical = 'https://pulse.subara3.com/tame.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">TAME</span><br>r <b id="h-r">3.90</b><br>CTRL <b id="h-ctrl">OFF</b><br>TARGET <b id="h-tgt">P1</b><br>|δr| <b id="h-dr">0.000</b></div>
<div class="mark">TAME</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'control'],
  ['type' => 'drag', 'label' => 'parameter'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">r</span>
    <input type="range" id="sl-r" min="350" max="399" value="390">
    <span class="slider-value" id="sv-r">3.90</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">ε MAX</span>
    <input type="range" id="sl-eps" min="1" max="35" value="20">
    <span class="slider-value" id="sv-eps">0.20</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sl-spd" min="1" max="12" value="4">
    <span class="slider-value" id="sv-spd">4</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-ctrl">CONTROL</button>
    <button class="control-btn active" id="btn-p1">P1</button>
    <button class="control-btn" id="btn-p2">P2</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d');
var W,H;
var r=3.9,eps=0.20,ctrl=false,tgtP=1,spd=4;
var x=0.2+Math.random()*0.6;
var hist=[],cob=[],lastDr=0,lockCt=0,lk=false;
var CL,CT,CW,CH,TL,TT,TW,TH;
var needDen=true,denCv=null;
var sparks=[];

function layout(){
  W=c.width=window.innerWidth;H=c.height=window.innerHeight;
  var p=Math.min(W,H)*0.05;
  var mx=Math.min(W-p*2,H*0.55);
  CW=CH=Math.max(100,mx);CL=(W-CW)/2;CT=p;
  TL=CL;TW=CW;TT=CT+CH+p*0.6;
  TH=Math.max(50,H-TT-p*1.2);
  needDen=true;
}

function buildDen(){
  var sz=Math.min(300,Math.round(CW));
  if(sz<20)return;
  var dc=document.createElement('canvas');dc.width=dc.height=sz;
  var dx=dc.getContext('2d'),im=dx.createImageData(sz,sz),d=im.data;
  var ct=new Uint16Array(sz*sz),mx=0,xi=0.2;
  for(var i=0;i<500;i++)xi=r*xi*(1-xi);
  for(var i=0;i<25000;i++){
    var xn=r*xi*(1-xi);
    var px=(xi*(sz-1))|0,py=((1-xn)*(sz-1))|0;
    if(px>=0&&px<sz&&py>=0&&py<sz){var k=py*sz+px;ct[k]++;if(ct[k]>mx)mx=ct[k];}
    xi=xn;
  }
  if(mx<1)mx=1;var lm=Math.log(1+mx);
  for(var i=0;i<sz*sz;i++){
    if(ct[i]>0){
      var v=Math.pow(Math.log(1+ct[i])/lm,0.5);
      d[i*4]=0;d[i*4+1]=(v*245)|0;d[i*4+2]=(v*212)|0;d[i*4+3]=(v*20)|0;
    }
  }
  dx.putImageData(im,0,0);denCv=dc;needDen=false;
}

function fp1(){return r>1?1-1/r:0;}
function fp2(){
  var d=(r+1)*(r-3);if(d<0)return null;
  var s=Math.sqrt(d);return[(r+1+s)/(2*r),(r+1-s)/(2*r)];
}

function step(){
  var dr=0,den=x*(1-x);
  if(ctrl&&Math.abs(den)>1e-12){
    if(tgtP===1){
      dr=fp1()/den-r;
    }else{
      var pts=fp2();
      if(pts){
        var tgt=Math.abs(x-pts[0])<Math.abs(x-pts[1])?pts[1]:pts[0];
        dr=tgt/den-r;
      }
    }
    if(Math.abs(dr)>eps)dr=0;
  }
  lastDr=dr;
  var xn=(r+dr)*x*(1-x);
  if(xn<0)xn=0;if(xn>1)xn=1;
  cob.push({x0:x,y0:x,x1:x,y1:xn,dr:dr});
  cob.push({x0:x,y0:xn,x1:xn,y1:xn,dr:dr});
  if(cob.length>500)cob.splice(0,2);
  x=xn;
  hist.push({v:x,dr:dr});
  if(hist.length>300)hist.shift();

  if(ctrl){
    var tgt=fp1();
    if(tgtP===2){var pts=fp2();if(pts)tgt=Math.abs(x-pts[0])<Math.abs(x-pts[1])?pts[0]:pts[1];}
    if(Math.abs(x-tgt)<0.005){
      lockCt++;
      if(lockCt===30&&!lk){
        lk=true;SFX.play('success');
        var sx=mX(x),sy=mY(x);
        for(var j=0;j<12;j++){var a=Math.random()*6.2832;
          sparks.push({x:sx,y:sy,vx:Math.cos(a)*(1+Math.random()*2),vy:Math.sin(a)*(1+Math.random()*2),l:1,dc:0.015+Math.random()*0.02});}
      }
    }else{lockCt=Math.max(0,lockCt-3);}
  }else{lockCt=0;lk=false;}
}

function mX(v){return CL+v*CW;}
function mY(v){return CT+(1-v)*CH;}

function draw(){
  ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);

  if(denCv)ctx.drawImage(denCv,CL,CT,CW,CH);

  ctx.strokeStyle='rgba(255,255,255,0.05)';ctx.lineWidth=1;ctx.strokeRect(CL,CT,CW,CH);

  ctx.beginPath();ctx.moveTo(mX(0),mY(0));ctx.lineTo(mX(1),mY(1));
  ctx.strokeStyle='rgba(255,255,255,0.1)';ctx.lineWidth=1;ctx.stroke();

  ctx.beginPath();
  for(var i=0;i<=80;i++){var t=i/80,y=r*t*(1-t);i?ctx.lineTo(mX(t),mY(y)):ctx.moveTo(mX(t),mY(y));}
  ctx.strokeStyle='rgba(255,255,255,0.35)';ctx.lineWidth=1.5;ctx.stroke();

  if(ctrl&&Math.abs(lastDr)>0.003){
    var rp=r+lastDr;ctx.beginPath();
    for(var i=0;i<=60;i++){var t=i/60,y=rp*t*(1-t);i?ctx.lineTo(mX(t),mY(y)):ctx.moveTo(mX(t),mY(y));}
    ctx.strokeStyle='rgba(181,140,255,0.2)';ctx.lineWidth=1;ctx.stroke();
  }

  var xs=fp1(),pts=fp2();
  var is1=ctrl&&tgtP===1;
  ctx.beginPath();ctx.arc(mX(xs),mY(xs),is1?6:4,0,6.2832);
  ctx.strokeStyle=is1?'rgba(181,140,255,0.7)':'rgba(181,140,255,0.2)';ctx.lineWidth=1.5;ctx.stroke();
  if(is1&&lk){
    var g=ctx.createRadialGradient(mX(xs),mY(xs),3,mX(xs),mY(xs),22);
    g.addColorStop(0,'rgba(181,140,255,0.12)');g.addColorStop(1,'rgba(0,0,0,0)');
    ctx.beginPath();ctx.arc(mX(xs),mY(xs),22,0,6.2832);ctx.fillStyle=g;ctx.fill();
  }

  if(pts){
    var is2=ctrl&&tgtP===2;
    for(var i=0;i<2;i++){
      ctx.beginPath();ctx.arc(mX(pts[i]),mY(pts[i]),is2?5:3,0,6.2832);
      ctx.strokeStyle=is2?'rgba(181,140,255,0.7)':'rgba(181,140,255,0.15)';ctx.lineWidth=1.5;ctx.stroke();
      if(is2&&lk){
        var g=ctx.createRadialGradient(mX(pts[i]),mY(pts[i]),2,mX(pts[i]),mY(pts[i]),16);
        g.addColorStop(0,'rgba(181,140,255,0.1)');g.addColorStop(1,'rgba(0,0,0,0)');
        ctx.beginPath();ctx.arc(mX(pts[i]),mY(pts[i]),16,0,6.2832);ctx.fillStyle=g;ctx.fill();
      }
    }
  }

  var n=cob.length;
  for(var i=0;i<n;i++){
    var p=cob[i],al=0.04+(i/n)*0.5;
    ctx.strokeStyle=Math.abs(p.dr)>0.003?'rgba(181,140,255,'+al+')':'rgba(0,245,212,'+al+')';
    ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(mX(p.x0),mY(p.y0));ctx.lineTo(mX(p.x1),mY(p.y1));ctx.stroke();
  }

  var px=mX(x),py=mY(x);
  var col=ctrl?'181,140,255':'0,245,212';
  var gr=ctx.createRadialGradient(px,py,2,px,py,18);
  gr.addColorStop(0,'rgba('+col+',0.3)');gr.addColorStop(1,'rgba('+col+',0)');
  ctx.beginPath();ctx.arc(px,py,18,0,6.2832);ctx.fillStyle=gr;ctx.fill();
  ctx.beginPath();ctx.arc(px,py,3.5,0,6.2832);
  ctx.fillStyle=ctrl?'#b58cff':'#00f5d4';ctx.fill();

  for(var i=sparks.length-1;i>=0;i--){
    var s=sparks[i];s.x+=s.vx;s.y+=s.vy;s.l-=s.dc;
    if(s.l<=0){sparks.splice(i,1);continue;}
    ctx.beginPath();ctx.arc(s.x,s.y,1.5*s.l,0,6.2832);
    ctx.fillStyle='rgba(181,140,255,'+(s.l*0.5)+')';ctx.fill();
  }

  ctx.strokeStyle='rgba(255,255,255,0.04)';ctx.lineWidth=1;ctx.strokeRect(TL,TT,TW,TH);

  if(ctrl){
    ctx.save();ctx.setLineDash([3,5]);ctx.lineWidth=1;ctx.strokeStyle='rgba(181,140,255,0.12)';
    if(tgtP===1){
      var fy=TT+(1-xs)*TH;ctx.beginPath();ctx.moveTo(TL,fy);ctx.lineTo(TL+TW,fy);ctx.stroke();
    }else if(pts){
      for(var i=0;i<2;i++){var fy=TT+(1-pts[i])*TH;ctx.beginPath();ctx.moveTo(TL,fy);ctx.lineTo(TL+TW,fy);ctx.stroke();}
    }
    ctx.restore();
  }

  var hl=hist.length;
  if(hl>1){
    ctx.beginPath();
    for(var i=0;i<hl;i++){var hx=TL+(i/299)*TW,hy=TT+(1-hist[i].v)*TH;i?ctx.lineTo(hx,hy):ctx.moveTo(hx,hy);}
    ctx.strokeStyle='rgba(0,245,212,0.35)';ctx.lineWidth=1;ctx.stroke();
    for(var i=0;i<hl;i++){
      if(Math.abs(hist[i].dr)>0.003){
        var hx=TL+(i/299)*TW,hy=TT+(1-hist[i].v)*TH;
        ctx.fillStyle='rgba(181,140,255,0.4)';ctx.fillRect(hx-1,hy-1,2,2);
      }
    }
  }

  ctx.font='10px ui-monospace,monospace';ctx.fillStyle='rgba(255,255,255,0.15)';
  ctx.textAlign='right';ctx.textBaseline='middle';
  ctx.fillText('1',CL-5,mY(1));ctx.fillText('0',CL-5,mY(0));
  ctx.textAlign='center';ctx.textBaseline='top';
  ctx.fillText('0',mX(0),mY(0)+4);ctx.fillText('1',mX(1),mY(0)+4);
}

function updateHUD(){
  document.getElementById('h-r').textContent=r.toFixed(2);
  var hc=document.getElementById('h-ctrl');
  hc.textContent=ctrl?(lk?'LOCKED':'ON'):'OFF';
  hc.style.color=ctrl?(lk?'#00f5d4':'#b58cff'):'rgba(255,255,255,.55)';
  document.getElementById('h-tgt').textContent='P'+tgtP;
  document.getElementById('h-dr').textContent=Math.abs(lastDr).toFixed(3);
}

function loop(){
  for(var i=0;i<spd;i++)step();
  if(needDen)buildDen();
  draw();updateHUD();
  requestAnimationFrame(loop);
}

function resetAll(){
  x=0.1+Math.random()*0.8;hist=[];cob=[];lockCt=0;lk=false;sparks=[];needDen=true;
}

var ds=null,hd=false;
c.addEventListener('mousedown',function(e){
  if(e.button===2){resetAll();SFX.play('click');return;}
  ds={x:e.clientX};hd=false;
});
c.addEventListener('mousemove',function(e){
  if(!ds)return;var dx=e.clientX-ds.x;
  if(Math.abs(dx)>5){hd=true;r=Math.max(3.5,Math.min(3.99,r+dx*0.001));ds.x=e.clientX;
    var sl=document.getElementById('sl-r');if(sl)sl.value=Math.round(r*100);
    var sv=document.getElementById('sv-r');if(sv)sv.textContent=r.toFixed(2);
    needDen=true;SFX.play('move',100);
  }
});
window.addEventListener('mouseup',function(){
  if(ds&&!hd){ctrl=!ctrl;document.getElementById('btn-ctrl').classList.toggle('active',ctrl);
    if(!ctrl){lk=false;lockCt=0;}else cob=[];SFX.play('toggle');}
  ds=null;
});
c.addEventListener('contextmenu',function(e){e.preventDefault();});

var tst=null,tdr=false;
c.addEventListener('touchstart',function(e){e.preventDefault();tst={x:e.touches[0].clientX};tdr=false;},{passive:false});
c.addEventListener('touchmove',function(e){e.preventDefault();if(!tst||!e.touches.length)return;
  var dx=e.touches[0].clientX-tst.x;
  if(Math.abs(dx)>8){tdr=true;r=Math.max(3.5,Math.min(3.99,r+dx*0.001));tst.x=e.touches[0].clientX;
    var sl=document.getElementById('sl-r');if(sl)sl.value=Math.round(r*100);
    var sv=document.getElementById('sv-r');if(sv)sv.textContent=r.toFixed(2);
    needDen=true;SFX.play('move',100);
  }
},{passive:false});
c.addEventListener('touchend',function(e){e.preventDefault();
  if(tst&&!tdr){ctrl=!ctrl;document.getElementById('btn-ctrl').classList.toggle('active',ctrl);
    if(!ctrl){lk=false;lockCt=0;}else cob=[];SFX.play('toggle');}
  tst=null;
},{passive:false});

var slR=document.getElementById('sl-r'),svR=document.getElementById('sv-r');
if(slR)slR.addEventListener('input',function(){r=parseInt(this.value)/100;if(svR)svR.textContent=r.toFixed(2);needDen=true;SFX.play('tick',80);});
var slE=document.getElementById('sl-eps'),svE=document.getElementById('sv-eps');
if(slE)slE.addEventListener('input',function(){eps=parseInt(this.value)/100;if(svE)svE.textContent=eps.toFixed(2);SFX.play('tick',80);});
var slS=document.getElementById('sl-spd'),svS=document.getElementById('sv-spd');
if(slS)slS.addEventListener('input',function(){spd=parseInt(this.value);if(svS)svS.textContent=spd;SFX.play('tick',80);});

document.getElementById('btn-ctrl').addEventListener('click',function(){
  ctrl=!ctrl;this.classList.toggle('active',ctrl);
  if(!ctrl){lk=false;lockCt=0;}else cob=[];SFX.play('toggle');
});
document.getElementById('btn-p1').addEventListener('click',function(){
  tgtP=1;this.classList.add('active');document.getElementById('btn-p2').classList.remove('active');
  lockCt=0;lk=false;SFX.play('toggle');
});
document.getElementById('btn-p2').addEventListener('click',function(){
  tgtP=2;this.classList.add('active');document.getElementById('btn-p1').classList.remove('active');
  lockCt=0;lk=false;SFX.play('toggle');
});
document.getElementById('btn-reset').addEventListener('click',function(){resetAll();SFX.play('click');});

window.addEventListener('resize',layout);
layout();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
