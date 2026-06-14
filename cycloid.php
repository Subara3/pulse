<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cycloid');

$pageName = 'cycloid';
$pageTitle = 'CYCLOID - PULSE';
$pageDesc = 'サイクロイド曲線。円が転がるとき生まれる軌跡。最速降下線と等時曲線の物理を体験する。';
$pageCanonical = 'https://pulse.subara3.com/cycloid.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  MODE <span class="v" id="hud-mode">ROLL</span><br>
  <span id="hud-detail"></span>
</div>

<div id="info">
  <span class="v">Cycloid</span><br>
  x = R(&#952; &#8722; sin &#952;)<br>
  y = R(1 &#8722; cos &#952;)
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop / restart'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-roll">ROLL</button>
      <button class="control-btn" id="btn-tauto">TAUTO</button>
      <button class="control-btn" id="btn-race">RACE</button>
    </div>
  </div>
  <div class="slider-wrap" id="roll-controls">
    <div class="slider-row">
      <span class="slider-label">d/R</span>
      <input type="range" id="sl-dr" min="0" max="200" value="100">
      <span class="slider-value" id="sv-dr">1.00</span>
    </div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="10" max="200" value="80">
      <span class="slider-value" id="sv-speed">1.0</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d'),W,H;
var mode='roll',GR=600;
var T=[0,245,212],PU=[123,44,191];
function rgb(c,a){return'rgba('+c[0]+','+c[1]+','+c[2]+','+(a==null?1:a)+')';}

var $hm=document.getElementById('hud-mode');
var $hd=document.getElementById('hud-detail');
var $info=document.getElementById('info');
var $rc=document.getElementById('roll-controls');

var roll={a:0,R:60,d:1,spd:1,gy:0,trail:[],camX:0};
var tau={R:100,ox:0,oy:0,balls:[],timer:0,flash:0};
var rc={sx:0,sy:0,ex:0,ey:0,lines:[],balls:[],elapsed:0,running:false,wait:0,winner:-1};

function resize(){
  W=C.width=innerWidth;H=C.height=innerHeight;
  roll.R=Math.min(H*.11,W*.07);
  roll.gy=H*.72;
  roll.trail=[];
  tau.R=Math.min(H*.18,(W-60)/(2*Math.PI));
  tau.ox=W/2-Math.PI*tau.R;
  tau.oy=H*.22;
  rc.sx=W*.15;rc.sy=H*.18;rc.ex=W*.85;rc.ey=H*.75;
  if(mode==='race')initRace();
}

function buildTable(fn,N){
  var pts=[],al=[0];
  for(var i=0;i<=N;i++){
    var t=i/N;pts.push(fn(t));
    if(i>0){var dx=pts[i].x-pts[i-1].x,dy=pts[i].y-pts[i-1].y;
      al.push(al[i-1]+Math.sqrt(dx*dx+dy*dy));}
  }
  return{pts:pts,al:al,len:al[N]};
}
function interpTable(tb,s){
  s=Math.max(0,Math.min(s,tb.len));
  var lo=0,hi=tb.pts.length-1;
  while(lo<hi-1){var m=(lo+hi)>>1;if(tb.al[m]<=s)lo=m;else hi=m;}
  var seg=tb.al[hi]-tb.al[lo];
  if(seg<1e-9)return{x:tb.pts[lo].x,y:tb.pts[lo].y};
  var f=(s-tb.al[lo])/seg;
  return{x:tb.pts[lo].x+f*(tb.pts[hi].x-tb.pts[lo].x),
         y:tb.pts[lo].y+f*(tb.pts[hi].y-tb.pts[lo].y)};
}
function tangentAt(tb,s){
  s=Math.max(0,Math.min(s,tb.len));
  var lo=0,hi=tb.pts.length-1;
  while(lo<hi-1){var m=(lo+hi)>>1;if(tb.al[m]<=s)lo=m;else hi=m;}
  var dx=tb.pts[hi].x-tb.pts[lo].x,dy=tb.pts[hi].y-tb.pts[lo].y;
  var ds=Math.sqrt(dx*dx+dy*dy);
  if(ds<1e-9)return{dxds:1,dyds:0};
  return{dxds:dx/ds,dyds:dy/ds};
}

// ===== ROLL =====
function updateRoll(dt){
  roll.a+=roll.spd*dt;
  var R=roll.R,d=roll.d*R,a=roll.a;
  var wx=R*a-d*Math.sin(a);
  var wy=roll.gy-R+d*Math.cos(a);
  roll.trail.push({x:wx,y:wy});
  if(roll.trail.length>3000)roll.trail.splice(0,roll.trail.length-3000);
  roll.camX=R*a-W*.6;

  var typ=roll.d<0.98?'CURTATE':roll.d>1.02?'PROLATE':'CYCLOID';
  $hd.innerHTML='d/R <span class="v">'+roll.d.toFixed(2)+'</span> '+typ;
}
function drawRoll(){
  var R=roll.R,d=roll.d*R,a=roll.a,cam=roll.camX,gy=roll.gy;
  ctx.strokeStyle=rgb(T,.12);ctx.lineWidth=1;
  ctx.beginPath();ctx.moveTo(0,gy);ctx.lineTo(W,gy);ctx.stroke();
  var spacing=R*Math.PI*2,first=Math.floor(cam/spacing),last=Math.ceil((cam+W)/spacing);
  ctx.strokeStyle=rgb(T,.06);
  for(var i=first;i<=last;i++){
    var sx=i*spacing-cam;
    ctx.beginPath();ctx.moveTo(sx,gy-4);ctx.lineTo(sx,gy+4);ctx.stroke();
  }
  var tr=roll.trail,len=tr.length;
  if(len>1){
    ctx.lineWidth=2;ctx.lineCap='round';ctx.lineJoin='round';
    for(var i=1;i<len;i++){
      var alpha=Math.pow(i/len,2)*.8;
      ctx.strokeStyle=rgb(T,alpha);
      ctx.beginPath();
      ctx.moveTo(tr[i-1].x-cam,tr[i-1].y);
      ctx.lineTo(tr[i].x-cam,tr[i].y);
      ctx.stroke();
    }
  }
  var cx=R*a-cam,cy=gy-R;
  ctx.strokeStyle=rgb(T,.15);ctx.lineWidth=1;
  ctx.beginPath();ctx.arc(cx,cy,R,0,Math.PI*2);ctx.stroke();
  for(var i=0;i<8;i++){
    var sa=i*Math.PI/4-a;
    ctx.strokeStyle=rgb(T,.06);
    ctx.beginPath();ctx.moveTo(cx,cy);
    ctx.lineTo(cx+R*Math.sin(sa),cy+R*Math.cos(sa));ctx.stroke();
  }
  var tx=cx-d*Math.sin(a),ty=cy+d*Math.cos(a);
  ctx.strokeStyle=rgb(T,.25);ctx.lineWidth=1;
  ctx.beginPath();ctx.moveTo(cx,cy);ctx.lineTo(tx,ty);ctx.stroke();
  ctx.shadowBlur=12;ctx.shadowColor=rgb(T,1);
  ctx.fillStyle=rgb(T,1);
  ctx.beginPath();ctx.arc(tx,ty,4,0,Math.PI*2);ctx.fill();
  ctx.shadowBlur=0;
  ctx.fillStyle=rgb(T,.3);
  ctx.beginPath();ctx.arc(cx,cy,3,0,Math.PI*2);ctx.fill();
}

// ===== TAUTO =====
function spawnTautoBalls(count,manual){
  for(var i=0;i<count;i++){
    var side=Math.random()>.5?1:-1;
    var th=manual?manual:(side>0?Math.random()*.85*Math.PI:.85*Math.PI+Math.random()*.85*Math.PI+.15*Math.PI);
    if(!manual&&side<0)th=2*Math.PI-Math.random()*.85*Math.PI;
    th=Math.max(.15,Math.min(th,2*Math.PI-.15));
    var s0=4*tau.R*Math.cos(th/2);
    tau.balls.push({s0:s0,t:0,th:th,alpha:1});
  }
  if(typeof SFX!=='undefined')SFX.play('spawn');
}
function updateTauto(dt){
  var omega=Math.sqrt(GR/(4*tau.R));
  tau.timer+=dt;
  if(tau.timer>3.5){tau.timer=0;spawnTautoBalls(5+Math.floor(Math.random()*3));}
  tau.flash=Math.max(0,tau.flash-dt*3);
  for(var i=tau.balls.length-1;i>=0;i--){
    var b=tau.balls[i];
    b.t+=dt;
    var s=b.s0*Math.cos(omega*b.t);
    var prevTh=b.th;
    var ratio=Math.max(-1,Math.min(1,s/(4*tau.R)));
    b.th=2*Math.acos(ratio);
    if(Math.abs(prevTh-Math.PI)>.3&&Math.abs(b.th-Math.PI)<.15){
      tau.flash=1;
      if(typeof SFX!=='undefined')SFX.play('collide',80);
    }
    b.alpha=Math.max(0,1-b.t/10);
    if(b.t>10)tau.balls.splice(i,1);
  }
  var period=Math.PI*Math.sqrt(tau.R/GR);
  $hd.innerHTML='BALLS <span class="v">'+tau.balls.length+'</span> T=<span class="v">'+period.toFixed(2)+'s</span>';
}
function drawTauto(){
  var R=tau.R,ox=tau.ox,oy=tau.oy;
  ctx.strokeStyle=rgb(T,.15);ctx.lineWidth=2;
  ctx.beginPath();
  for(var i=0;i<=200;i++){
    var th=i/200*2*Math.PI;
    var x=ox+R*(th-Math.sin(th)),y=oy+R*(1-Math.cos(th));
    i===0?ctx.moveTo(x,y):ctx.lineTo(x,y);
  }
  ctx.stroke();
  var bottomX=ox+R*Math.PI,bottomY=oy+2*R;
  if(tau.flash>0){
    var fr=tau.flash;
    ctx.fillStyle=rgb(T,fr*.3);
    ctx.beginPath();ctx.arc(bottomX,bottomY,20+30*(1-fr),0,Math.PI*2);ctx.fill();
  }
  ctx.shadowBlur=10;ctx.shadowColor=rgb(T,.8);
  for(var i=0;i<tau.balls.length;i++){
    var b=tau.balls[i];
    var x=ox+R*(b.th-Math.sin(b.th)),y=oy+R*(1-Math.cos(b.th));
    var a=b.alpha;
    ctx.fillStyle=rgb(T,a);
    ctx.beginPath();ctx.arc(x,y,Math.max(3,5*a),0,Math.PI*2);ctx.fill();
  }
  ctx.shadowBlur=0;
  ctx.strokeStyle=rgb(T,.08);ctx.setLineDash([4,8]);
  ctx.beginPath();ctx.moveTo(ox,oy);ctx.lineTo(ox+R*2*Math.PI,oy);ctx.stroke();
  ctx.setLineDash([]);
  ctx.fillStyle=rgb(T,.3);ctx.font='10px "Orbitron",sans-serif';
  ctx.textAlign='center';
  ctx.fillText('ALL ARRIVE SIMULTANEOUSLY',bottomX,bottomY+25);
}

// ===== RACE =====
function solveThetaF(dxdy){
  var lo=.01,hi=2*Math.PI-.01;
  for(var i=0;i<60;i++){
    var m=(lo+hi)/2;
    var v=(m-Math.sin(m))/(1-Math.cos(m));
    if(v<dxdy)lo=m;else hi=m;
  }
  return(lo+hi)/2;
}
function initRace(){
  var dx=rc.ex-rc.sx,dy=rc.ey-rc.sy;
  var lineTb=buildTable(function(t){return{x:rc.sx+dx*t,y:rc.sy+dy*t};},200);
  var dropTb=buildTable(function(t){return{x:rc.sx+dx*t*t,y:rc.sy+dy*(2*t-t*t)};},200);
  var thF=solveThetaF(dx/dy);
  var cR=dy/(1-Math.cos(thF));
  var cyTb=buildTable(function(t){
    var th=t*thF;
    return{x:rc.sx+cR*(th-Math.sin(th)),y:rc.sy+cR*(1-Math.cos(th))};
  },300);
  rc.lines=[
    {tb:lineTb,color:[180,180,180],label:'LINE'},
    {tb:dropTb,color:PU,label:'DROP'},
    {tb:cyTb,color:T,label:'CYCLOID'}
  ];
  startRace();
}
function startRace(){
  rc.balls=[];
  for(var i=0;i<rc.lines.length;i++){
    rc.balls.push({s:0,v:0,pos:{x:rc.sx,y:rc.sy},finished:false,time:0});
  }
  rc.elapsed=0;rc.running=true;rc.wait=0;rc.winner=-1;
  if(typeof SFX!=='undefined')SFX.play('spawn');
}
function updateRace(dt){
  if(!rc.running){
    rc.wait+=dt;
    if(rc.wait>3.5){initRace();}
    return;
  }
  rc.elapsed+=dt;
  var allDone=true;
  for(var i=0;i<rc.balls.length;i++){
    var b=rc.balls[i],tb=rc.lines[i].tb;
    if(b.finished)continue;
    allDone=false;
    var tn=tangentAt(tb,b.s);
    var a=GR*tn.dyds;
    b.v+=a*dt;
    if(b.v<0)b.v=0;
    var maxV=Math.sqrt(Math.max(0,2*GR*(b.pos.y-rc.sy)));
    if(maxV>0&&b.v>maxV*1.05)b.v=maxV;
    b.s+=b.v*dt;
    if(b.s>=tb.len){
      b.s=tb.len;b.finished=true;b.time=rc.elapsed;
      if(rc.winner<0){rc.winner=i;if(typeof SFX!=='undefined')SFX.play('success');}
    }
    b.pos=interpTable(tb,b.s);
  }
  if(allDone)rc.running=false;
  var info='';
  for(var i=0;i<rc.lines.length;i++){
    var b=rc.balls[i],l=rc.lines[i];
    info+=l.label+' <span class="v">'+(b.finished?b.time.toFixed(2)+'s':rc.elapsed.toFixed(2)+'s')+'</span>';
    if(rc.winner===i)info+=' &#9733;';
    info+='<br>';
  }
  $hd.innerHTML=info;
}
function drawRace(){
  ctx.fillStyle=rgb(T,.15);
  ctx.beginPath();ctx.arc(rc.sx,rc.sy,6,0,Math.PI*2);ctx.fill();
  ctx.beginPath();ctx.arc(rc.ex,rc.ey,6,0,Math.PI*2);ctx.fill();
  ctx.fillStyle='rgba(255,255,255,.12)';ctx.font='10px "Orbitron",sans-serif';
  ctx.textAlign='center';
  ctx.fillText('START',rc.sx,rc.sy-12);
  ctx.fillText('END',rc.ex,rc.ey+22);
  for(var i=0;i<rc.lines.length;i++){
    var l=rc.lines[i],tb=l.tb,c=l.color;
    ctx.strokeStyle=rgb(c,.25);ctx.lineWidth=2;
    ctx.beginPath();
    for(var j=0;j<tb.pts.length;j++){
      j===0?ctx.moveTo(tb.pts[j].x,tb.pts[j].y):ctx.lineTo(tb.pts[j].x,tb.pts[j].y);
    }
    ctx.stroke();
    ctx.fillStyle=rgb(c,.2);ctx.font='9px "Orbitron",sans-serif';
    ctx.textAlign='left';
    var mid=tb.pts[Math.floor(tb.pts.length*.3)];
    ctx.fillText(l.label,mid.x+8,mid.y-8);
  }
  ctx.shadowBlur=10;
  for(var i=0;i<rc.balls.length;i++){
    var b=rc.balls[i],c=rc.lines[i].color;
    ctx.shadowColor=rgb(c,.8);ctx.fillStyle=rgb(c,1);
    var r=rc.winner===i&&b.finished?7:5;
    ctx.beginPath();ctx.arc(b.pos.x,b.pos.y,r,0,Math.PI*2);ctx.fill();
  }
  ctx.shadowBlur=0;
  if(rc.winner>=0&&!rc.running){
    var b=rc.balls[rc.winner];
    var pulse=Math.sin(rc.wait*6)*.3+.3;
    ctx.strokeStyle=rgb(rc.lines[rc.winner].color,pulse);ctx.lineWidth=2;
    ctx.beginPath();ctx.arc(b.pos.x,b.pos.y,12+rc.wait*3,0,Math.PI*2);ctx.stroke();
  }
}

// ===== MODE SWITCH =====
function setMode(m){
  mode=m;
  $hm.textContent=m.toUpperCase();
  document.getElementById('btn-roll').classList.toggle('active',m==='roll');
  document.getElementById('btn-tauto').classList.toggle('active',m==='tauto');
  document.getElementById('btn-race').classList.toggle('active',m==='race');
  $rc.style.display=m==='roll'?'':'none';
  if(m==='roll'){
    roll.trail=[];roll.a=0;
    $info.innerHTML='<span class="v">Cycloid</span><br>x = R(&#952; &#8722; sin &#952;)<br>y = R(1 &#8722; cos &#952;)';
  }else if(m==='tauto'){
    tau.balls=[];tau.timer=0;tau.flash=0;
    spawnTautoBalls(6);
    $info.innerHTML='<span class="v">Tautochrone</span><br>T = &#960;&#8730;(R/g)<br>Isochronous descent';
  }else{
    initRace();
    $info.innerHTML='<span class="v">Brachistochrone</span><br>Fastest descent curve<br>Johann Bernoulli, 1696';
  }
  $hd.innerHTML='';
  if(typeof SFX!=='undefined')SFX.play('toggle');
}

// ===== EVENTS =====
document.getElementById('btn-roll').onclick=function(){setMode('roll');};
document.getElementById('btn-tauto').onclick=function(){setMode('tauto');};
document.getElementById('btn-race').onclick=function(){setMode('race');};
document.getElementById('btn-reset').onclick=function(){
  setMode(mode);
  if(typeof SFX!=='undefined')SFX.play('click');
};
document.getElementById('sl-dr').oninput=function(){
  roll.d=this.value/100;
  document.getElementById('sv-dr').textContent=roll.d.toFixed(2);
  roll.trail=[];
};
document.getElementById('sl-speed').oninput=function(){
  roll.spd=this.value/80;
  document.getElementById('sv-speed').textContent=roll.spd.toFixed(1);
};

function handleClick(px,py){
  if(mode==='tauto'){
    var R=tau.R,ox=tau.ox,oy=tau.oy;
    var cy=Math.max(oy+.01,Math.min(py,oy+2*R-.01));
    var cosHalf=1-(cy-oy)/R;
    if(cosHalf>=-1&&cosHalf<=1){
      var th=Math.acos(cosHalf);
      var xLeft=ox+R*(th-Math.sin(th));
      var xRight=ox+R*((2*Math.PI-th)-Math.sin(2*Math.PI-th));
      var useRight=Math.abs(px-xRight)<Math.abs(px-xLeft);
      spawnTautoBalls(1,useRight?2*Math.PI-th:th);
    }
  }else if(mode==='race'){
    initRace();
  }else{
    roll.trail=[];
    if(typeof SFX!=='undefined')SFX.play('click');
  }
}

C.addEventListener('click',function(e){handleClick(e.clientX,e.clientY);});
C.addEventListener('touchstart',function(e){
  if(e.target===C){e.preventDefault();var t=e.touches[0];handleClick(t.clientX,t.clientY);}
},{passive:false});

// ===== MAIN LOOP =====
var lastT=0;
function loop(t){
  var dt=Math.min((t-lastT)/1000,.05);lastT=t;
  ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
  if(mode==='roll'){updateRoll(dt);drawRoll();}
  else if(mode==='tauto'){updateTauto(dt);drawTauto();}
  else{updateRace(dt);drawRace();}
  requestAnimationFrame(loop);
}

resize();
window.addEventListener('resize',resize);
requestAnimationFrame(function(t){lastT=t;requestAnimationFrame(loop);});
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
