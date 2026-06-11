<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hull');

$pageName = 'hull';
$pageTitle = 'HULL - PULSE';
$pageDesc = '凸包アルゴリズムの可視化。Graham Scan・Jarvis March・QuickHullが点群の外殻をステップごとに探す。';
$pageCanonical = 'https://pulse.subara3.com/hull.php';

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
  <span class="v" id="hud-algo">GRAHAM</span><br>
  <span class="v" id="hud-pts">0</span> POINTS<br>
  HULL <span class="v" id="hud-hull">0</span><br>
  STEP <span class="v" id="hud-step">&mdash;</span>
</div>

<div id="info">
  <span class="v">Convex Hull</span><br>
  computational geometry &middot; O(n log n)
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="20" value="8">
      <span class="slider-value" id="sv-speed">8</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-scatter">SCATTER</button>
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
  <div class="preset-group" id="preset-btns">
    <button class="preset-btn active" data-algo="graham">GRAHAM</button>
    <button class="preset-btn" data-algo="jarvis">JARVIS</button>
    <button class="preset-btn" data-algo="quick">QUICKHULL</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,pts=[],algo='graham',speed=8;
var steps=[],si=0,anim=false,sdl=0;
var idle=false,idleH=[],pauseT=0,fc=0;

var hA=document.getElementById('hud-algo');
var hP=document.getElementById('hud-pts');
var hH=document.getElementById('hud-hull');
var hS=document.getElementById('hud-step');

function resize(){W=C.width=window.innerWidth;H=C.height=window.innerHeight;}

function cross(O,A,B){return(A.x-O.x)*(B.y-O.y)-(A.y-O.y)*(B.x-O.x);}
function d2(a,b){var dx=a.x-b.x,dy=a.y-b.y;return dx*dx+dy*dy;}

function scatter(n){
  pts=[];var m=Math.min(W,H)*0.1;
  for(var i=0;i<n;i++)pts.push({x:m+Math.random()*(W-2*m),y:m+Math.random()*(H-2*m),vx:(Math.random()-0.5)*0.5,vy:(Math.random()-0.5)*0.5});
  runA();
}

function sE(s){var e=[];for(var i=0;i<s.length-1;i++)e.push([s[i],s[i+1]]);return e;}

function fastH(){
  var p=pts,n=p.length;if(n<3)return n===2?[[p[0],p[1]]]:[];
  var pi=0;
  for(var i=1;i<n;i++)if(p[i].y>p[pi].y||(p[i].y===p[pi].y&&p[i].x<p[pi].x))pi=i;
  var pv=p[pi],sr=[];
  for(var i=0;i<n;i++)if(i!==pi)sr.push(p[i]);
  sr.sort(function(a,b){var aa=Math.atan2(pv.y-a.y,a.x-pv.x),ab=Math.atan2(pv.y-b.y,b.x-pv.x);return Math.abs(aa-ab)<1e-10?d2(pv,a)-d2(pv,b):aa-ab;});
  sr.unshift(pv);
  var st=[sr[0],sr[1]];
  for(var i=2;i<sr.length;i++){while(st.length>1&&cross(st[st.length-2],st[st.length-1],sr[i])>=0)st.pop();st.push(sr[i]);}
  var e=[];for(var i=0;i<st.length;i++)e.push([st[i],st[(i+1)%st.length]]);
  return e;
}

function bGraham(){
  var s=[],p=pts,n=p.length;if(n<3){s.push({h:[],t:null,a:null});return s;}
  var pi=0;
  for(var i=1;i<n;i++)if(p[i].y>p[pi].y||(p[i].y===p[pi].y&&p[i].x<p[pi].x))pi=i;
  var pv=p[pi];
  s.push({h:[],t:null,a:pv,l:'PIVOT'});
  var sr=[];for(var i=0;i<n;i++)if(i!==pi)sr.push(p[i]);
  sr.sort(function(a,b){var aa=Math.atan2(pv.y-a.y,a.x-pv.x),ab=Math.atan2(pv.y-b.y,b.x-pv.x);return Math.abs(aa-ab)<1e-10?d2(pv,a)-d2(pv,b):aa-ab;});
  sr.unshift(pv);
  var st=[sr[0],sr[1]];
  s.push({h:sE(st),t:null,a:sr[1],l:'INIT'});
  for(var i=2;i<sr.length;i++){
    var q=sr[i];
    s.push({h:sE(st),t:[st[st.length-1],q],a:q,l:'TEST'});
    while(st.length>1&&cross(st[st.length-2],st[st.length-1],q)>=0){
      var rm=st.pop();
      s.push({h:sE(st),t:[st[st.length-1],q],a:q,r:rm,l:'POP'});
    }
    st.push(q);
    s.push({h:sE(st),t:null,a:q,l:'PUSH'});
  }
  var fe=sE(st);fe.push([st[st.length-1],st[0]]);
  s.push({h:fe,t:null,a:null,l:'DONE'});
  return s;
}

function bJarvis(){
  var s=[],p=pts,n=p.length;if(n<3){s.push({h:[],t:null,a:null});return s;}
  var s0=0;
  for(var i=1;i<n;i++)if(p[i].x<p[s0].x||(p[i].x===p[s0].x&&p[i].y>p[s0].y))s0=i;
  s.push({h:[],t:null,a:p[s0],l:'START'});
  var cur=s0,edges=[],sf=0;
  do{
    var nxt=(cur===0)?1:0;
    var ev=Math.max(1,Math.floor(n/8));
    for(var i=0;i<n;i++){
      if(i===cur)continue;
      var c=cross(p[cur],p[nxt],p[i]);
      if(c<0||(c===0&&d2(p[cur],p[i])>d2(p[cur],p[nxt])))nxt=i;
      if(i%ev===0)s.push({h:edges.slice(),t:[p[cur],p[i]],a:p[nxt],l:'SCAN'});
    }
    edges.push([p[cur],p[nxt]]);
    s.push({h:edges.slice(),t:null,a:p[nxt],l:'WRAP'});
    cur=nxt;sf++;
  }while(cur!==s0&&sf<n+1);
  s.push({h:edges,t:null,a:null,l:'DONE'});
  return s;
}

function bQuick(){
  var s=[],p=pts,n=p.length;if(n<3){s.push({h:[],t:null,a:null});return s;}
  var mi=0,ma=0;
  for(var i=1;i<n;i++){if(p[i].x<p[mi].x)mi=i;if(p[i].x>p[ma].x)ma=i;}
  var A=p[mi],B=p[ma],hPts=[A,B];
  function hE(){
    if(hPts.length<2)return[];
    var cx=0,cy=0;for(var i=0;i<hPts.length;i++){cx+=hPts[i].x;cy+=hPts[i].y;}
    cx/=hPts.length;cy/=hPts.length;
    var sr=hPts.slice().sort(function(a,b){return Math.atan2(a.y-cy,a.x-cx)-Math.atan2(b.y-cy,b.x-cx);});
    var e=[];for(var i=0;i<sr.length;i++)e.push([sr[i],sr[(i+1)%sr.length]]);
    return e;
  }
  s.push({h:[[A,B]],t:null,a:null,l:'DIVIDE'});
  function fH(sub,P,Q){
    if(!sub.length)return;
    var md=0,fi=-1;
    for(var i=0;i<sub.length;i++){var d=Math.abs(cross(P,Q,sub[i]));if(d>md){md=d;fi=i;}}
    if(fi<0)return;
    var F=sub[fi];hPts.push(F);
    s.push({h:hE(),t:[P,Q],a:F,l:'SPLIT'});
    var l1=[],l2=[];
    for(var i=0;i<sub.length;i++){if(i===fi)continue;if(cross(P,F,sub[i])<0)l1.push(sub[i]);if(cross(F,Q,sub[i])<0)l2.push(sub[i]);}
    fH(l1,P,F);fH(l2,F,Q);
  }
  var up=[],lo=[];
  for(var i=0;i<n;i++){if(i===mi||i===ma)continue;var c=cross(A,B,p[i]);if(c<0)up.push(p[i]);else if(c>0)lo.push(p[i]);}
  fH(up,A,B);fH(lo,B,A);
  s.push({h:hE(),t:null,a:null,l:'DONE'});
  return s;
}

function runA(){
  if(pts.length<1)return;
  switch(algo){case'graham':steps=bGraham();break;case'jarvis':steps=bJarvis();break;case'quick':steps=bQuick();break;}
  si=0;sdl=0;anim=true;idle=false;pauseT=0;updH();
}

function goIdle(){anim=false;idle=true;idleH=fastH();updH();}

function updH(){
  hA.textContent=algo==='quick'?'QUICKHULL':algo.toUpperCase();
  hP.textContent=pts.length;
  if(anim&&steps.length){var f=steps[Math.min(si,steps.length-1)];hH.textContent=f.h?f.h.length:0;hS.textContent=(si+1)+'/'+steps.length;}
  else{hH.textContent=idleH.length;hS.textContent='—';}
}

function cF(){return steps.length?steps[Math.min(si,steps.length-1)]:{h:[],t:null,a:null};}

function render(ts){
  X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);
  X.strokeStyle='rgba(0,245,212,0.025)';X.lineWidth=1;
  for(var gx=60;gx<W;gx+=60){X.beginPath();X.moveTo(gx,0);X.lineTo(gx,H);X.stroke();}
  for(var gy=60;gy<H;gy+=60){X.beginPath();X.moveTo(0,gy);X.lineTo(W,gy);X.stroke();}
  if(anim||pauseT>0){var f=cF();drawE(f.h,ts,false);drawT(f.t);drawP(f.a,f.r);}
  else{drawE(idleH,ts,idle);drawT(null);drawP(null,null);}
}

function drawE(edges,ts,pulse){
  if(!edges||!edges.length)return;
  var al=pulse?0.5+0.2*Math.sin(ts*0.003):0.7;
  var lw=pulse?2+0.5*Math.sin(ts*0.004):2;
  for(var i=0;i<edges.length;i++){
    var e=edges[i];
    X.beginPath();X.moveTo(e[0].x,e[0].y);X.lineTo(e[1].x,e[1].y);
    X.strokeStyle='rgba(0,245,212,'+(al*0.15)+')';X.lineWidth=lw+6;X.stroke();
    X.beginPath();X.moveTo(e[0].x,e[0].y);X.lineTo(e[1].x,e[1].y);
    X.strokeStyle='rgba(0,245,212,'+al+')';X.lineWidth=lw;X.stroke();
  }
}

function drawT(t){
  if(!t)return;
  X.setLineDash([8,5]);X.beginPath();X.moveTo(t[0].x,t[0].y);X.lineTo(t[1].x,t[1].y);
  X.strokeStyle='rgba(123,44,191,0.55)';X.lineWidth=1.5;X.stroke();X.setLineDash([]);
}

function drawP(act,rem){
  for(var i=0;i<pts.length;i++){
    var p=pts[i];
    var isA=act&&Math.abs(p.x-act.x)<2&&Math.abs(p.y-act.y)<2;
    var isR=rem&&Math.abs(p.x-rem.x)<2&&Math.abs(p.y-rem.y)<2;
    if(isA){
      X.beginPath();X.arc(p.x,p.y,14,0,6.283);X.fillStyle='rgba(0,245,212,0.06)';X.fill();
      X.beginPath();X.arc(p.x,p.y,8,0,6.283);X.fillStyle='rgba(0,245,212,0.15)';X.fill();
      X.beginPath();X.arc(p.x,p.y,4.5,0,6.283);X.fillStyle='#00f5d4';X.fill();
    }else if(isR){
      X.beginPath();X.arc(p.x,p.y,5,0,6.283);X.fillStyle='rgba(123,44,191,0.7)';X.fill();
    }else{
      X.beginPath();X.arc(p.x,p.y,3,0,6.283);X.fillStyle='rgba(200,200,200,0.2)';X.fill();
      X.beginPath();X.arc(p.x,p.y,2,0,6.283);X.fillStyle='rgba(0,245,212,0.45)';X.fill();
    }
  }
}

function drift(){
  var m=50;
  for(var i=0;i<pts.length;i++){
    var p=pts[i];
    if(p.vx===undefined){p.vx=(Math.random()-0.5)*0.4;p.vy=(Math.random()-0.5)*0.4;}
    p.vx+=(Math.random()-0.5)*0.03;p.vy+=(Math.random()-0.5)*0.03;
    var sp=Math.sqrt(p.vx*p.vx+p.vy*p.vy);
    if(sp>0.5){p.vx*=0.5/sp;p.vy*=0.5/sp;}
    p.x+=p.vx;p.y+=p.vy;
    if(p.x<m){p.x=m;p.vx=Math.abs(p.vx);}
    if(p.x>W-m){p.x=W-m;p.vx=-Math.abs(p.vx);}
    if(p.y<m){p.y=m;p.vy=Math.abs(p.vy);}
    if(p.y>H-m){p.y=H-m;p.vy=-Math.abs(p.vy);}
  }
  idleH=fastH();
}

function loop(ts){
  if(anim){
    sdl++;var iv=Math.max(1,20-speed*2);
    if(sdl>=iv){sdl=0;si++;
      if(si>=steps.length){si=steps.length-1;anim=false;pauseT=90;}
      updH();
    }
  }else if(pauseT>0){pauseT--;if(pauseT===0)goIdle();}
  else if(idle){fc++;if(fc%2===0)drift();if(fc%30===0)updH();}
  render(ts||0);
  requestAnimationFrame(loop);
}

function near(x,y,th){var bi=-1,bd=th*th;for(var i=0;i<pts.length;i++){var dx=pts[i].x-x,dy=pts[i].y-y,dd=dx*dx+dy*dy;if(dd<bd){bd=dd;bi=i;}}return bi;}

var drg=false,dI=-1,dM=false;
C.addEventListener('mousedown',function(e){
  e.preventDefault();var mx=e.clientX,my=e.clientY;
  if(e.button===2){var ni=near(mx,my,25);if(ni>=0){pts.splice(ni,1);if(typeof SFX!=='undefined')SFX.play('pop');runA();}return;}
  var ni=near(mx,my,18);
  if(ni>=0){drg=true;dI=ni;dM=false;}
  else{pts.push({x:mx,y:my,vx:0,vy:0});if(typeof SFX!=='undefined')SFX.play('spawn');runA();}
});
C.addEventListener('mousemove',function(e){if(!drg||dI<0||dI>=pts.length)return;dM=true;pts[dI].x=e.clientX;pts[dI].y=e.clientY;});
document.addEventListener('mouseup',function(){if(drg&&dM){if(typeof SFX!=='undefined')SFX.play('move');runA();}drg=false;});
C.addEventListener('contextmenu',function(e){e.preventDefault();});

var tT=null,tL=false,tP=-1,tD=false,tmx=0,tmy=0;
C.addEventListener('touchstart',function(e){
  if(e.touches.length!==1)return;var t=e.touches[0];tmx=t.clientX;tmy=t.clientY;tD=false;tL=false;
  tP=near(tmx,tmy,25);
  tT=setTimeout(function(){tL=true;if(tP>=0&&tP<pts.length){pts.splice(tP,1);if(typeof SFX!=='undefined')SFX.play('pop');runA();}},500);
},{passive:true});
C.addEventListener('touchmove',function(e){
  if(e.touches.length!==1)return;if(tT){clearTimeout(tT);tT=null;}tD=true;
  if(tP>=0&&tP<pts.length&&!tL){var t=e.touches[0];pts[tP].x=t.clientX;pts[tP].y=t.clientY;}
},{passive:true});
C.addEventListener('touchend',function(){
  if(tT){clearTimeout(tT);tT=null;}if(tL){tP=-1;return;}
  if(tD&&tP>=0){if(typeof SFX!=='undefined')SFX.play('move');runA();}
  else if(!tD&&tP<0){pts.push({x:tmx,y:tmy,vx:0,vy:0});if(typeof SFX!=='undefined')SFX.play('spawn');runA();}
  tP=-1;tD=false;
});

var slS=document.getElementById('sl-speed'),svS=document.getElementById('sv-speed');
slS.addEventListener('input',function(){speed=+this.value;svS.textContent=this.value;});
document.getElementById('btn-scatter').addEventListener('click',function(){scatter(25+Math.floor(Math.random()*25));if(typeof SFX!=='undefined')SFX.play('click');});
document.getElementById('btn-clear').addEventListener('click',function(){pts=[];steps=[];si=0;anim=false;idle=false;pauseT=0;idleH=[];updH();if(typeof SFX!=='undefined')SFX.play('click');});
document.querySelectorAll('#preset-btns .preset-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    algo=this.dataset.algo;
    document.querySelectorAll('#preset-btns .preset-btn').forEach(function(b){b.classList.remove('active');});
    this.classList.add('active');
    if(typeof SFX!=='undefined')SFX.play('toggle');
    if(pts.length>=3)runA();else updH();
  });
});

window.addEventListener('resize',resize);
resize();
scatter(35);
loop(0);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
