<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('catastrophe');

$pageName = 'catastrophe';
$pageTitle = 'CATASTROPHE - PULSE';
$pageDesc = 'カスプ・カタストロフ。制御パラメータが滑らかに変わるとき、平衡状態が突然跳ぶ。ヒステリシスが分岐の幾何学を描き出す。';
$pageCanonical = 'https://pulse.subara3.com/catastrophe.php';
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
<div class="hud"><span class="dim">CATASTROPHE</span><br>PARTICLES <b id="h-count">0</b><br>JUMPS <b id="h-jumps">0</b><br><span class="dim" id="h-mode">EXPLORE</span></div>
<div class="mark">CATASTROPHE</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'explore'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btn-explore">EXPLORE</button>
    <button class="control-btn" id="btn-loop">LOOP</button>
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

var TEAL=[0,245,212],PURP=[123,44,191],BG=[10,10,15];
function rgba(col,a){return 'rgba('+col[0]+','+col[1]+','+col[2]+','+a+')';}
function mixC(a,b,t){return[a[0]+(b[0]-a[0])*t,a[1]+(b[1]-a[1])*t,a[2]+(b[2]-a[2])*t];}

var A_MIN=-4,A_MAX=1.5,B_MIN=-4,B_MAX=4;
var BG_SCALE=4;
var TRAIL_MAX=200;

var particles=[];
var mode=0;
var jumpCount=0;
var time=0,prevT=0;
var mx=-1,my=-1;
var dragging=null;
var bgBuf=null;
var loopP=null;

function s2p(sx,sy){
  return{a:A_MAX-(sy/H)*(A_MAX-A_MIN),b:B_MIN+(sx/W)*(B_MAX-B_MIN)};
}
function p2s(a,b){
  return{x:((b-B_MIN)/(B_MAX-B_MIN))*W,y:((A_MAX-a)/(A_MAX-A_MIN))*H};
}

function cbrt(x){return x<0?-Math.pow(-x,1/3):Math.pow(x,1/3);}

function solveCubic(p,q){
  var disc=-4*p*p*p-27*q*q;
  if(disc<=0){
    var D=q*q/4+p*p*p/27;
    if(D<0)D=0;
    var sD=Math.sqrt(D);
    var u=cbrt(-q/2+sD);
    var v=cbrt(-q/2-sD);
    return[u+v];
  }else{
    var r=Math.sqrt(-p/3);
    var cosA=(-q)/(2*r*r*r);
    if(cosA>1)cosA=1;if(cosA<-1)cosA=-1;
    var th=Math.acos(cosA)/3;
    var m=2*r;
    var r0=m*Math.cos(th);
    var r1=m*Math.cos(th-2*Math.PI/3);
    var r2=m*Math.cos(th+2*Math.PI/3);
    var arr=[r0,r1,r2];
    arr.sort(function(a,b){return a-b;});
    return arr;
  }
}

function findMinima(p,q){
  var roots=solveCubic(p,q);
  var out=[];
  for(var i=0;i<roots.length;i++){
    if(3*roots[i]*roots[i]+p>0)out.push(roots[i]);
  }
  return out;
}

function disc(a,b){return -4*a*a*a-27*b*b;}

function potential(x,a,b){return x*x*x*x/4+a*x*x/2+b*x;}

function renderBg(){
  var bw=Math.ceil(W/BG_SCALE),bh=Math.ceil(H/BG_SCALE);
  bgBuf=document.createElement('canvas');
  bgBuf.width=bw;bgBuf.height=bh;
  var bctx=bgBuf.getContext('2d');
  var img=bctx.createImageData(bw,bh);
  var d=img.data;

  for(var py=0;py<bh;py++){
    for(var px=0;px<bw;px++){
      var pr=s2p(px*BG_SCALE,py*BG_SCALE);
      var a=pr.a,b=pr.b;
      var ds=disc(a,b);
      var idx=(py*bw+px)*4;
      if(ds>0){
        d[idx]=18;d[idx+1]=10;d[idx+2]=32;d[idx+3]=255;
      }else{
        var roots=solveCubic(a,b);
        var eq=roots[0];
        var t=(eq+2.5)/5;
        if(t<0)t=0;if(t>1)t=1;
        var col=mixC(PURP,TEAL,t);
        var dim=0.12;
        d[idx]=Math.round(col[0]*dim)+10;
        d[idx+1]=Math.round(col[1]*dim)+10;
        d[idx+2]=Math.round(col[2]*dim)+12;
        d[idx+3]=255;
      }
    }
  }
  bctx.putImageData(img,0,0);
}

function drawCusp(){
  ctx.save();
  ctx.shadowBlur=18;ctx.shadowColor='#00f5d4';
  ctx.strokeStyle='rgba(0,245,212,0.7)';ctx.lineWidth=2;
  ctx.beginPath();
  var steps=200;
  for(var i=0;i<=steps;i++){
    var a=A_MIN*(i/steps);
    var bv=2*Math.pow(-a,1.5)/Math.sqrt(27);
    var s=p2s(a,bv);
    if(i===0)ctx.moveTo(s.x,s.y);else ctx.lineTo(s.x,s.y);
  }
  ctx.stroke();
  ctx.beginPath();
  for(var i=0;i<=steps;i++){
    var a=A_MIN*(i/steps);
    var bv=-2*Math.pow(-a,1.5)/Math.sqrt(27);
    var s=p2s(a,bv);
    if(i===0)ctx.moveTo(s.x,s.y);else ctx.lineTo(s.x,s.y);
  }
  ctx.stroke();

  var cp=p2s(0,0);
  ctx.beginPath();
  ctx.arc(cp.x,cp.y,4,0,Math.PI*2);
  ctx.fillStyle='#00f5d4';
  ctx.fill();
  ctx.restore();
}

function drawPotential(pa,pb,pStates){
  var pw=Math.min(280,W*0.35)|0;
  var ph=110;
  var px0=W/2-pw/2;
  var py0=H-ph-60;
  if(py0<H*0.5)py0=H-ph-20;

  ctx.save();
  ctx.fillStyle='rgba(10,10,15,0.82)';
  ctx.strokeStyle='rgba(0,245,212,0.18)';
  ctx.lineWidth=1;
  ctx.beginPath();
  ctx.roundRect(px0-10,py0-10,pw+20,ph+20,6);
  ctx.fill();ctx.stroke();

  var xMin=-3,xMax=3;
  var vals=[];
  for(var i=0;i<=pw;i++){
    var x=xMin+(i/pw)*(xMax-xMin);
    vals.push(potential(x,pa,pb));
  }
  var vMin=Infinity,vMax=-Infinity;
  for(var i=0;i<vals.length;i++){
    if(vals[i]<vMin)vMin=vals[i];
    if(vals[i]>vMax)vMax=vals[i];
  }
  var vRange=vMax-vMin;
  if(vRange<0.1)vRange=0.1;
  var margin=vRange*0.15;
  vMin-=margin;vMax+=margin;
  vRange=vMax-vMin;

  ctx.strokeStyle='rgba(0,245,212,0.5)';
  ctx.lineWidth=1.5;
  ctx.beginPath();
  for(var i=0;i<=pw;i++){
    var sy=py0+ph-(vals[i]-vMin)/vRange*ph;
    if(i===0)ctx.moveTo(px0+i,sy);else ctx.lineTo(px0+i,sy);
  }
  ctx.stroke();

  var minima=findMinima(pa,pb);
  for(var j=0;j<minima.length;j++){
    var mx2=(minima[j]-xMin)/(xMax-xMin)*pw;
    var mv=potential(minima[j],pa,pb);
    var msy=py0+ph-(mv-vMin)/vRange*ph;
    var isCurrent=false;
    for(var k=0;k<pStates.length;k++){
      if(Math.abs(pStates[k]-minima[j])<0.3)isCurrent=true;
    }
    ctx.beginPath();
    ctx.arc(px0+mx2,msy,isCurrent?5:3,0,Math.PI*2);
    ctx.fillStyle=minima[j]>0?rgba(TEAL,0.9):rgba(PURP,0.9);
    ctx.fill();
    if(isCurrent){
      ctx.shadowBlur=10;
      ctx.shadowColor=minima[j]>0?'#00f5d4':'#b58cff';
      ctx.beginPath();ctx.arc(px0+mx2,msy,5,0,Math.PI*2);ctx.fill();
      ctx.shadowBlur=0;
    }
  }

  ctx.font='9px ui-monospace,monospace';
  ctx.fillStyle='rgba(255,255,255,0.3)';
  ctx.fillText('V(x)',px0,py0-2);
  ctx.restore();
}

function createOrbitP(ca,cb,ra,rb,omega,phase,state0){
  var a0=ca+ra*Math.sin(phase);
  var b0=cb+rb*Math.cos(phase);
  var minima=findMinima(a0,b0);
  var st=state0||0;
  if(minima.length>0){
    if(state0!==undefined){
      var best=0,bestD=Infinity;
      for(var i=0;i<minima.length;i++){
        var d=Math.abs(minima[i]-state0);
        if(d<bestD){bestD=d;best=i;}
      }
      st=minima[best];
    }else{
      st=minima[minima.length-1];
    }
  }
  return{
    orbit:{ca:ca,cb:cb,ra:ra,rb:rb,omega:omega,phase:phase},
    a:a0,b:b0,state:st,prevState:st,
    trail:[],jumpFlash:0,isAuto:true,age:0
  };
}

function createFreeP(a,b){
  var minima=findMinima(a,b);
  var st=minima.length>0?minima[minima.length>1?1:0]:0;
  return{
    orbit:null,a:a,b:b,state:st,prevState:st,
    trail:[],jumpFlash:0,isAuto:false,age:0
  };
}

function updateParticle(p,dt){
  p.age+=dt;
  if(p.orbit){
    var o=p.orbit;
    var t=time*o.omega+o.phase;
    p.a=o.ca+o.ra*Math.sin(t);
    p.b=o.cb+o.rb*Math.cos(t);
  }

  var minima=findMinima(p.a,p.b);
  if(minima.length===0)return;

  var best=0,bestD=Infinity;
  for(var i=0;i<minima.length;i++){
    var d=Math.abs(minima[i]-p.state);
    if(d<bestD){bestD=d;best=i;}
  }
  p.prevState=p.state;
  p.state=minima[best];

  if(Math.abs(p.state-p.prevState)>0.4){
    p.jumpFlash=1;
    jumpCount++;
    if(typeof SFX!=='undefined')SFX.play('collide');
    document.getElementById('h-jumps').textContent=jumpCount;
  }

  if(p.jumpFlash>0)p.jumpFlash=Math.max(0,p.jumpFlash-dt*2.5);

  var sp=p2s(p.a,p.b);
  p.trail.push({x:sp.x,y:sp.y,state:p.state,flash:p.jumpFlash});
  if(p.trail.length>TRAIL_MAX)p.trail.shift();
}

function drawParticle(p){
  var tr=p.trail;
  if(tr.length>1){
    for(var i=1;i<tr.length;i++){
      var alpha=(i/tr.length)*0.5;
      var col=tr[i].state>0?TEAL:PURP;
      if(tr[i].flash>0){
        col=[255,255,255];
        alpha=Math.min(1,alpha+tr[i].flash*0.5);
      }
      ctx.strokeStyle=rgba(col,alpha);
      ctx.lineWidth=tr[i].flash>0?2:1;
      ctx.beginPath();
      ctx.moveTo(tr[i-1].x,tr[i-1].y);
      ctx.lineTo(tr[i].x,tr[i].y);
      ctx.stroke();
    }
  }

  var sp=p2s(p.a,p.b);
  var col=p.state>0?TEAL:PURP;
  var r=p.isAuto?4:5;

  if(p.jumpFlash>0){
    ctx.save();
    ctx.shadowBlur=30*p.jumpFlash;
    ctx.shadowColor='#ffffff';
    ctx.beginPath();
    ctx.arc(sp.x,sp.y,r+10*p.jumpFlash,0,Math.PI*2);
    ctx.fillStyle=rgba([255,255,255],p.jumpFlash*0.5);
    ctx.fill();
    ctx.restore();
  }

  ctx.save();
  ctx.shadowBlur=12;
  ctx.shadowColor=p.state>0?'#00f5d4':'#b58cff';
  ctx.beginPath();
  ctx.arc(sp.x,sp.y,r,0,Math.PI*2);
  ctx.fillStyle=rgba(col,0.9);
  ctx.fill();
  ctx.restore();

  ctx.beginPath();
  ctx.arc(sp.x,sp.y,r*0.5,0,Math.PI*2);
  ctx.fillStyle='rgba(255,255,255,0.7)';
  ctx.fill();
}

function initAutoParticles(){
  particles=[];
  jumpCount=0;
  document.getElementById('h-jumps').textContent='0';

  var configs=[
    {ca:-1.5,cb:0,ra:1.8,rb:2.2,omega:0.35,phase:0,st:1.5},
    {ca:-1.5,cb:0,ra:1.8,rb:2.2,omega:0.35,phase:Math.PI,st:-1.5},
    {ca:-1.2,cb:0.3,ra:1.4,rb:1.8,omega:0.45,phase:0.8,st:1.2},
    {ca:-1.2,cb:-0.3,ra:1.4,rb:1.8,omega:0.45,phase:2.5,st:-1.2},
    {ca:-2,cb:0,ra:2.2,rb:2.8,omega:0.25,phase:1.2,st:1.8},
    {ca:-2,cb:0,ra:2.2,rb:2.8,omega:0.25,phase:4.4,st:-1.8},
    {ca:-1,cb:0,ra:1,rb:1.4,omega:0.55,phase:3,st:1},
    {ca:-1.8,cb:0.5,ra:1.6,rb:2,omega:0.3,phase:5,st:-1}
  ];
  for(var i=0;i<configs.length;i++){
    var cfg=configs[i];
    particles.push(createOrbitP(cfg.ca,cfg.cb,cfg.ra,cfg.rb,cfg.omega,cfg.phase,cfg.st));
  }
}

function initLoopParticle(){
  particles=[];
  jumpCount=0;
  document.getElementById('h-jumps').textContent='0';
  loopP=createOrbitP(-1.5,0,1.8,2.5,0.2,0,1.5);
  loopP.isAuto=true;
  particles.push(loopP);
}

function resize(){
  W=c.width=window.innerWidth;
  H=c.height=window.innerHeight;
  renderBg();
}

function findNearestP(sx,sy,maxDist){
  var best=-1,bestD=maxDist*maxDist;
  for(var i=0;i<particles.length;i++){
    var sp=p2s(particles[i].a,particles[i].b);
    var dx=sp.x-sx,dy=sp.y-sy;
    var d2=dx*dx+dy*dy;
    if(d2<bestD){bestD=d2;best=i;}
  }
  return best;
}

function draw(){
  ctx.clearRect(0,0,W,H);
  ctx.imageSmoothingEnabled=true;
  if(bgBuf)ctx.drawImage(bgBuf,0,0,W,H);
  drawCusp();

  for(var i=0;i<particles.length;i++){
    drawParticle(particles[i]);
  }

  if(mx>=0&&my>=0){
    var pr=s2p(mx,my);
    var states=[];
    for(var i=0;i<particles.length;i++){
      var sp=p2s(particles[i].a,particles[i].b);
      var dx=sp.x-mx,dy=sp.y-my;
      if(dx*dx+dy*dy<2500)states.push(particles[i].state);
    }
    drawPotential(pr.a,pr.b,states);

    ctx.save();
    ctx.strokeStyle='rgba(255,255,255,0.15)';
    ctx.setLineDash([4,6]);
    ctx.beginPath();
    ctx.moveTo(mx,0);ctx.lineTo(mx,H);
    ctx.moveTo(0,my);ctx.lineTo(W,my);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.restore();
  }

  document.getElementById('h-count').textContent=particles.length;
}

function animate(t){
  if(!prevT)prevT=t;
  var dt=(t-prevT)/1000;
  if(dt>0.1)dt=0.1;
  prevT=t;
  time+=dt;

  for(var i=0;i<particles.length;i++){
    updateParticle(particles[i],dt);
  }

  draw();
  requestAnimationFrame(animate);
}

function onDown(sx,sy,isRight){
  if(isRight){
    var idx=findNearestP(sx,sy,40);
    if(idx>=0){
      particles.splice(idx,1);
      if(typeof SFX!=='undefined')SFX.play('pop');
    }else{
      particles=particles.filter(function(p){return p.isAuto;});
      if(typeof SFX!=='undefined')SFX.play('click');
    }
    return;
  }

  var idx=findNearestP(sx,sy,30);
  if(idx>=0){
    dragging=particles[idx];
    dragging.orbit=null;
    dragging.isAuto=false;
  }else{
    if(particles.length<50){
      var pr=s2p(sx,sy);
      var p=createFreeP(pr.a,pr.b);
      particles.push(p);
      dragging=p;
      if(typeof SFX!=='undefined')SFX.play('spawn');
    }
  }
}

function onMove(sx,sy){
  mx=sx;my=sy;
  if(dragging){
    var pr=s2p(sx,sy);
    dragging.a=pr.a;
    dragging.b=pr.b;
  }
}

function onUp(){
  dragging=null;
}

c.addEventListener('mousedown',function(e){
  e.preventDefault();
  onDown(e.clientX,e.clientY,e.button===2);
});
c.addEventListener('mousemove',function(e){onMove(e.clientX,e.clientY);});
c.addEventListener('mouseup',function(){onUp();});
c.addEventListener('contextmenu',function(e){e.preventDefault();});
c.addEventListener('mouseleave',function(){mx=-1;my=-1;});

var touchId=null;
c.addEventListener('touchstart',function(e){
  e.preventDefault();
  var t=e.changedTouches[0];
  touchId=t.identifier;
  onDown(t.clientX,t.clientY,false);
  onMove(t.clientX,t.clientY);
},{passive:false});
c.addEventListener('touchmove',function(e){
  e.preventDefault();
  for(var i=0;i<e.changedTouches.length;i++){
    if(e.changedTouches[i].identifier===touchId){
      onMove(e.changedTouches[i].clientX,e.changedTouches[i].clientY);
      break;
    }
  }
},{passive:false});
c.addEventListener('touchend',function(e){
  for(var i=0;i<e.changedTouches.length;i++){
    if(e.changedTouches[i].identifier===touchId){
      onUp();touchId=null;break;
    }
  }
});

var btnExplore=document.getElementById('btn-explore');
var btnLoop=document.getElementById('btn-loop');
var btnReset=document.getElementById('btn-reset');

btnExplore.addEventListener('click',function(){
  mode=0;
  btnExplore.classList.add('active');
  btnLoop.classList.remove('active');
  document.getElementById('h-mode').textContent='EXPLORE';
  initAutoParticles();
  if(typeof SFX!=='undefined')SFX.play('toggle');
});

btnLoop.addEventListener('click',function(){
  mode=1;
  btnLoop.classList.add('active');
  btnExplore.classList.remove('active');
  document.getElementById('h-mode').textContent='LOOP';
  initLoopParticle();
  if(typeof SFX!=='undefined')SFX.play('toggle');
});

btnReset.addEventListener('click',function(){
  if(mode===0)initAutoParticles();
  else initLoopParticle();
  if(typeof SFX!=='undefined')SFX.play('click');
});

window.addEventListener('resize',resize);
resize();
initAutoParticles();
requestAnimationFrame(animate);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
