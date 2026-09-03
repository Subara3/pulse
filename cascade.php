<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cascade');

$pageName = 'cascade';
$pageTitle = 'CASCADE - PULSE';
$pageDesc = 'ネットワーク上のカスケード故障。1つのノードが倒れると、負荷が隣へ流れ連鎖的に崩壊が広がる。';
$pageCanonical = 'https://pulse.subara3.com/cascade.php';
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
<div class="hud"><span class="dim">CASCADE</span><br>ALIVE <b id="h-alive">60/60</b><br>WAVE <b id="h-wave">0</b><br>α <b id="h-alpha">0.20</b><br><span class="dim" id="h-mode">SINGLE</span></div>
<div class="mark">CASCADE</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'fail'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btn-single">SINGLE</button>
    <button class="control-btn" id="btn-random">RANDOM</button>
    <button class="control-btn" id="btn-attack">ATTACK</button>
  </div>
  <div class="slider-row">
    <span class="slider-label">α</span>
    <input type="range" id="sl-tol" min="0" max="100" value="20">
    <span class="slider-value" id="sv-tol">0.20</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">NODES</span>
    <input type="range" id="sl-n" min="30" max="120" value="60">
    <span class="slider-value" id="sv-n">60</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
    <button class="control-btn" id="btn-regen">REGENERATE</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d');
var W,H,cx,cy;

var TEAL=[0,245,212],PURP=[123,44,191];
function rgba(c,a){return 'rgba('+((c[0]|0)+','+((c[1]|0)+','+((c[2]|0)+','+a+')')));}
function mix(a,b,t){return[a[0]+(b[0]-a[0])*t,a[1]+(b[1]-a[1])*t,a[2]+(b[2]-a[2])*t];}
function brandHue(t){t=t<0?0:t>1?1:t;var e=t<.5?.5*Math.pow(2*t,3.2):1-.5*Math.pow(2*(1-t),3.2);return 172+e*118;}
function hsl(h,s,l){h%=360;if(h<0)h+=360;s/=100;l/=100;var c=(1-Math.abs(2*l-1))*s,x=c*(1-Math.abs(h/60%2-1)),m=l-c/2,r,g,b;if(h<60){r=c;g=x;b=0}else if(h<120){r=x;g=c;b=0}else if(h<180){r=0;g=c;b=x}else if(h<240){r=0;g=x;b=c}else if(h<300){r=x;g=0;b=c}else{r=c;g=0;b=x}return[(r+m)*255|0,(g+m)*255|0,(b+m)*255|0];}

var nodes=[],edges=[];
var mode=0,MODES=['SINGLE','RANDOM','ATTACK'];
var tolerance=0.20,nodeCount=60;
var cascWave=[],cascActive=false,cascSize=0,aliveCount=0;
var hover=-1,drag=-1,dragOx=0,dragOy=0;
var settled=false,temp=1;
var autoTick=0,resetTick=-1;
var lastWaveT=0;
var mx=-1,my=-1;

function resize(){
  W=c.width=window.innerWidth;H=c.height=window.innerHeight;
  cx=W/2;cy=H/2;
}

function genNetwork(){
  nodes=[];edges=[];cascWave=[];cascActive=false;cascSize=0;
  settled=false;temp=1;resetTick=-1;autoTick=0;
  var m0=3,m=2;
  var i,j,n;
  for(i=0;i<m0;i++){
    var a=2*Math.PI*i/m0;
    nodes.push({x:cx+Math.cos(a)*60,y:cy+Math.sin(a)*60,vx:0,vy:0,
      deg:0,load:0,cap:0,alive:true,failT:-1,adj:[]});
  }
  for(i=0;i<m0;i++)for(j=i+1;j<m0;j++){
    edges.push({a:i,b:j,pulse:0});
    nodes[i].deg++;nodes[j].deg++;
    nodes[i].adj.push({n:j,e:edges.length-1});
    nodes[j].adj.push({n:i,e:edges.length-1});
  }
  for(n=m0;n<nodeCount;n++){
    var ang=2*Math.PI*n/nodeCount;
    var rad=Math.min(W,H)*0.28;
    nodes.push({x:cx+Math.cos(ang)*rad+((Math.random()-.5)*40),
      y:cy+Math.sin(ang)*rad+((Math.random()-.5)*40),vx:0,vy:0,
      deg:0,load:0,cap:0,alive:true,failT:-1,adj:[]});
    var totalDeg=0;
    for(i=0;i<n;i++)totalDeg+=nodes[i].deg||1;
    var tgts=[],att=0;
    while(tgts.length<m&&att<2000){
      var rnd=Math.random()*totalDeg,sum=0;
      for(i=0;i<n;i++){sum+=(nodes[i].deg||1);if(sum>=rnd){if(tgts.indexOf(i)===-1)tgts.push(i);break;}}
      att++;
    }
    for(i=0;i<tgts.length;i++){
      var ei=edges.length;
      edges.push({a:n,b:tgts[i],pulse:0});
      nodes[n].deg++;nodes[tgts[i]].deg++;
      nodes[n].adj.push({n:tgts[i],e:ei});
      nodes[tgts[i]].adj.push({n:n,e:ei});
    }
  }
  calcLoads();
  aliveCount=nodeCount;
  for(i=0;i<300;i++)layoutStep();
  settled=true;
  SFX.play('spawn');
  updHud();
}

function calcLoads(){
  for(var i=0;i<nodes.length;i++){
    nodes[i].load=nodes[i].deg;
    nodes[i].cap=nodes[i].deg*(1+tolerance);
  }
}

function layoutStep(){
  var k=Math.sqrt(W*H/nodes.length)*0.35;
  var k2=k*k,N=nodes.length,i,j;
  for(i=0;i<N;i++){
    if(!nodes[i].alive&&nodes[i].failT>0)continue;
    nodes[i].vx=0;nodes[i].vy=0;
  }
  for(i=0;i<N;i++){
    if(!nodes[i].alive&&nodes[i].failT>0)continue;
    for(j=i+1;j<N;j++){
      if(!nodes[j].alive&&nodes[j].failT>0)continue;
      var dx=nodes[i].x-nodes[j].x,dy=nodes[i].y-nodes[j].y;
      var d2=dx*dx+dy*dy;if(d2<1)d2=1;
      var d=Math.sqrt(d2),f=k2/d;
      var fx=dx/d*f,fy=dy/d*f;
      nodes[i].vx+=fx;nodes[i].vy+=fy;
      nodes[j].vx-=fx;nodes[j].vy-=fy;
    }
  }
  for(i=0;i<edges.length;i++){
    var a=nodes[edges[i].a],b=nodes[edges[i].b];
    if((!a.alive&&a.failT>0)||(!b.alive&&b.failT>0))continue;
    var dx=a.x-b.x,dy=a.y-b.y;
    var d=Math.sqrt(dx*dx+dy*dy);if(d<1)d=1;
    var f=d*d/k;
    var fx=dx/d*f,fy=dy/d*f;
    a.vx-=fx;a.vy-=fy;b.vx+=fx;b.vy+=fy;
  }
  for(i=0;i<N;i++){
    if(!nodes[i].alive&&nodes[i].failT>0)continue;
    nodes[i].vx-=(nodes[i].x-cx)*0.008;
    nodes[i].vy-=(nodes[i].y-cy)*0.008;
  }
  var mg=40;
  for(i=0;i<N;i++){
    if(!nodes[i].alive&&nodes[i].failT>0)continue;
    if(i===drag)continue;
    var v=Math.sqrt(nodes[i].vx*nodes[i].vx+nodes[i].vy*nodes[i].vy);
    var lim=temp*k;
    if(v>lim){nodes[i].vx=nodes[i].vx/v*lim;nodes[i].vy=nodes[i].vy/v*lim;}
    nodes[i].x+=nodes[i].vx*0.12;
    nodes[i].y+=nodes[i].vy*0.12;
    if(nodes[i].x<mg)nodes[i].x=mg;if(nodes[i].x>W-mg)nodes[i].x=W-mg;
    if(nodes[i].y<mg)nodes[i].y=mg;if(nodes[i].y>H-mg)nodes[i].y=H-mg;
  }
  temp*=0.985;if(temp<0.005)temp=0.005;
}

function failNode(idx){
  if(idx<0||idx>=nodes.length||!nodes[idx].alive)return;
  var n=nodes[idx];
  n.alive=false;n.failT=performance.now();
  cascSize++;aliveCount--;
  SFX.play('pop',60);
  var adjs=n.adj,totalD=0,i;
  for(i=0;i<adjs.length;i++){
    if(nodes[adjs[i].n].alive)totalD+=nodes[adjs[i].n].deg;
  }
  if(totalD>0){
    for(i=0;i<adjs.length;i++){
      var nb=nodes[adjs[i].n];
      if(!nb.alive)continue;
      var share=n.load*(nb.deg/totalD);
      nb.load+=share;
      edges[adjs[i].e].pulse=1;
      if(nb.load>nb.cap&&cascWave.indexOf(adjs[i].n)===-1)cascWave.push(adjs[i].n);
    }
  }
  updHud();
}

function procCascade(now){
  if(cascWave.length>0&&now-lastWaveT>180){
    lastWaveT=now;
    var batch=cascWave.slice();cascWave=[];
    for(var i=0;i<batch.length;i++){
      if(nodes[batch[i]].alive&&nodes[batch[i]].load>nodes[batch[i]].cap)failNode(batch[i]);
    }
    cascActive=true;
  }
  if(cascWave.length===0&&cascActive){
    cascActive=false;
    if(cascSize>1)SFX.play('complete');
    if(mode===0)resetTick=180;
  }
}

function resetAlive(){
  for(var i=0;i<nodes.length;i++){
    nodes[i].alive=true;nodes[i].failT=-1;
  }
  calcLoads();cascSize=0;aliveCount=nodeCount;cascWave=[];cascActive=false;
  autoTick=0;resetTick=-1;
  updHud();
}

function getNode(px,py){
  for(var i=nodes.length-1;i>=0;i--){
    var n=nodes[i];if(!n.alive)continue;
    var dx=n.x-px,dy=n.y-py,r=4+n.deg*1.1+6;
    if(dx*dx+dy*dy<r*r)return i;
  }
  return -1;
}

function updHud(){
  document.getElementById('h-alive').textContent=aliveCount+'/'+nodeCount;
  document.getElementById('h-wave').textContent=cascSize;
  document.getElementById('h-alpha').textContent=tolerance.toFixed(2);
  document.getElementById('h-mode').textContent=MODES[mode];
}

function draw(now){
  ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
  var i,e,a,b,n;

  for(i=0;i<edges.length;i++){
    e=edges[i];a=nodes[e.a];b=nodes[e.b];
    if(e.pulse>0)e.pulse*=0.93;if(e.pulse<0.005)e.pulse=0;
    var ba=a.alive,bb=b.alive;
    var al;
    if(ba&&bb){al=0.12+e.pulse*0.6;}
    else if(!ba&&!bb){al=0.025;}
    else{al=0.06+e.pulse*0.4;}
    if(e.pulse>0.05){
      ctx.strokeStyle=rgba(PURP,al+0.1);ctx.lineWidth=1.5+e.pulse*2;
    }else if(ba&&bb){
      ctx.strokeStyle=rgba(TEAL,al);ctx.lineWidth=0.8;
    }else{
      ctx.strokeStyle='rgba(60,60,80,'+al+')';ctx.lineWidth=0.4;
    }
    ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();
  }

  for(i=0;i<nodes.length;i++){
    n=nodes[i];
    var r=4+n.deg*1.1;

    if(!n.alive&&n.failT>0){
      var el=now-n.failT;
      if(el<400){
        var ft=el/400;
        var fr=r*(1+(1-ft)*2.5);
        ctx.beginPath();ctx.arc(n.x,n.y,fr,0,Math.PI*2);
        ctx.fillStyle='rgba(255,255,255,'+(1-ft)*0.7+')';ctx.fill();
        var gr=ctx.createRadialGradient(n.x,n.y,fr*0.3,n.x,n.y,fr*2);
        gr.addColorStop(0,rgba(PURP,(1-ft)*0.4));gr.addColorStop(1,'rgba(123,44,191,0)');
        ctx.beginPath();ctx.arc(n.x,n.y,fr*2,0,Math.PI*2);ctx.fillStyle=gr;ctx.fill();
      }else{
        ctx.beginPath();ctx.arc(n.x,n.y,r*0.5,0,Math.PI*2);
        ctx.fillStyle='rgba(35,35,50,0.4)';ctx.fill();
      }
      continue;
    }
    if(!n.alive)continue;

    var stress=n.cap>0?n.load/n.cap:0;
    var st=Math.max(0,Math.min(1,(stress-0.3)/0.7));
    var h=brandHue(st);
    var col=hsl(h,75,48+(1-st)*12);
    var breathe=1+Math.sin(now*0.0015+i*0.7)*0.08;
    var dr=r*breathe;

    var grd=ctx.createRadialGradient(n.x,n.y,dr*0.3,n.x,n.y,dr*2.8);
    grd.addColorStop(0,rgba(col,0.25+st*0.1));grd.addColorStop(1,rgba(col,0));
    ctx.beginPath();ctx.arc(n.x,n.y,dr*2.8,0,Math.PI*2);ctx.fillStyle=grd;ctx.fill();

    ctx.beginPath();ctx.arc(n.x,n.y,dr,0,Math.PI*2);
    ctx.fillStyle=rgba(col,0.85);ctx.fill();
    ctx.beginPath();ctx.arc(n.x,n.y,dr*0.35,0,Math.PI*2);
    ctx.fillStyle='rgba(255,255,255,0.25)';ctx.fill();

    if(i===hover){
      ctx.beginPath();ctx.arc(n.x,n.y,dr+4,0,Math.PI*2);
      ctx.strokeStyle=rgba(TEAL,0.5);ctx.lineWidth=1.5;ctx.stroke();
    }
  }

  if(mode===2&&!cascActive&&aliveCount>nodeCount*0.2){
    var maxD=-1,tgt=-1;
    for(i=0;i<nodes.length;i++){if(nodes[i].alive&&nodes[i].deg>maxD){maxD=nodes[i].deg;tgt=i;}}
    if(tgt>=0){
      n=nodes[tgt];
      var pr=6+n.deg*1.1+8+Math.sin(now*0.006)*4;
      ctx.beginPath();ctx.arc(n.x,n.y,pr,0,Math.PI*2);
      ctx.strokeStyle=rgba(PURP,0.4+Math.sin(now*0.008)*0.2);
      ctx.lineWidth=1.5;ctx.setLineDash([4,4]);ctx.stroke();ctx.setLineDash([]);
    }
  }
}

function loop(){
  var now=performance.now();
  if(!settled)for(var i=0;i<3;i++)layoutStep();
  else{temp=0.005;layoutStep();}

  procCascade(now);

  if(resetTick>0){resetTick--;if(resetTick===0)resetAlive();}

  if(mode===1&&!cascActive&&resetTick<0){
    autoTick++;
    if(autoTick>100){
      autoTick=0;
      if(aliveCount>nodeCount*0.25){
        var alv=[];
        for(var i=0;i<nodes.length;i++)if(nodes[i].alive)alv.push(i);
        if(alv.length>0){cascSize=0;failNode(alv[Math.random()*alv.length|0]);lastWaveT=now;}
      }else{resetTick=120;}
    }
  }

  if(mode===2&&!cascActive&&resetTick<0){
    autoTick++;
    if(autoTick>80){
      autoTick=0;
      if(aliveCount>nodeCount*0.15){
        var maxD=-1,tgt=-1;
        for(var i=0;i<nodes.length;i++)if(nodes[i].alive&&nodes[i].deg>maxD){maxD=nodes[i].deg;tgt=i;}
        if(tgt>=0){cascSize=0;failNode(tgt);lastWaveT=now;}
      }else{resetTick=120;}
    }
  }

  draw(now);
  requestAnimationFrame(loop);
}

var pointerDown=false,didDrag=false,downT=0;
function pDown(px,py,isRight){
  if(isRight){SFX.play('click');resetAlive();return;}
  mx=px;my=py;
  var idx=getNode(px,py);
  pointerDown=true;didDrag=false;downT=performance.now();
  if(idx>=0){drag=idx;dragOx=nodes[idx].x-px;dragOy=nodes[idx].y-py;}
}
function pMove(px,py){
  mx=px;my=py;
  if(pointerDown&&drag>=0){
    nodes[drag].x=px+dragOx;nodes[drag].y=py+dragOy;
    didDrag=true;
    SFX.play('move',120);
    return;
  }
  hover=getNode(px,py);
}
function pUp(px,py){
  if(pointerDown&&!didDrag&&drag>=0&&mode===0&&!cascActive){
    cascSize=0;failNode(drag);lastWaveT=performance.now();
    SFX.play('click');
  }else if(pointerDown&&!didDrag){
    var idx=getNode(px,py);
    if(idx>=0&&mode===0&&!cascActive){
      cascSize=0;failNode(idx);lastWaveT=performance.now();
      SFX.play('click');
    }
  }
  pointerDown=false;drag=-1;didDrag=false;
}

c.addEventListener('mousedown',function(e){e.preventDefault();pDown(e.clientX,e.clientY,e.button===2);});
c.addEventListener('mousemove',function(e){pMove(e.clientX,e.clientY);});
c.addEventListener('mouseup',function(e){pUp(e.clientX,e.clientY);});
c.addEventListener('contextmenu',function(e){e.preventDefault();});
c.addEventListener('touchstart',function(e){e.preventDefault();var t=e.touches[0];pDown(t.clientX,t.clientY,false);},{passive:false});
c.addEventListener('touchmove',function(e){e.preventDefault();var t=e.touches[0];pMove(t.clientX,t.clientY);},{passive:false});
c.addEventListener('touchend',function(e){e.preventDefault();pUp(mx,my);},{passive:false});

var btnS=document.getElementById('btn-single'),btnR=document.getElementById('btn-random'),btnA=document.getElementById('btn-attack');
function setMode(m){
  mode=m;autoTick=0;resetTick=-1;
  btnS.classList.toggle('active',m===0);
  btnR.classList.toggle('active',m===1);
  btnA.classList.toggle('active',m===2);
  if(!cascActive)resetAlive();
  SFX.play('toggle');updHud();
}
btnS.addEventListener('click',function(){setMode(0);});
btnR.addEventListener('click',function(){setMode(1);});
btnA.addEventListener('click',function(){setMode(2);});

var slTol=document.getElementById('sl-tol'),svTol=document.getElementById('sv-tol');
slTol.addEventListener('input',function(){tolerance=this.value/100;svTol.textContent=tolerance.toFixed(2);calcLoads();updHud();SFX.play('tick',80);});

var slN=document.getElementById('sl-n'),svN=document.getElementById('sv-n');
slN.addEventListener('input',function(){nodeCount=parseInt(this.value);svN.textContent=nodeCount;SFX.play('tick',80);});
slN.addEventListener('change',function(){genNetwork();});

document.getElementById('btn-reset').addEventListener('click',function(){SFX.play('click');resetAlive();});
document.getElementById('btn-regen').addEventListener('click',function(){SFX.play('click');genNetwork();});

window.addEventListener('resize',function(){resize();genNetwork();});
resize();genNetwork();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
