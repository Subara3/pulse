<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('eigen');

$pageName = 'eigen';
$pageTitle = 'EIGEN - PULSE';
$pageDesc = '2x2行列が平面を変形する様子を、基底ベクトル・単位円・固有方向・流れとして観察するCanvas可視化。';
$pageCanonical = 'https://pulse.subara3.com/eigen.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
.matrix-readout{position:fixed;right:1rem;bottom:1.2rem;z-index:55;color:rgba(255,255,255,.35);font-family:"JetBrains Mono",monospace;font-size:.58rem;line-height:1.7;text-align:right;pointer-events:none}
.matrix-readout b{color:#7b2cbf}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.matrix-readout{right:.75rem;bottom:4.5rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  MODE <b id="h-mode">FLOW</b><br>
  DET <b id="h-det">0.00</b><br>
  TRACE <b id="h-trace">0.00</b><br>
  <span class="sub">TYPE</span> <b id="h-type">REAL</b>
</div>
<div class="matrix-readout">
  A00 <b id="m-a">1.18</b> A01 <b id="m-b">0.42</b><br>
  A10 <b id="m-c">-0.32</b> A11 <b id="m-d">0.76</b><br>
  L1 <b id="m-l1">0.00</b> L2 <b id="m-l2">0.00</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed trace'],
  ['type' => 'drag', 'label' => 'move basis'],
  ['type' => 'right', 'label' => 'clear traces'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row"><button class="control-btn active" data-mode="flow">FLOW</button><button class="control-btn" data-mode="circle">CIRCLE</button><button class="control-btn" data-mode="grid">GRID</button></div></div>
  <div><div class="slider-row"><span class="slider-label">TIME</span><input type="range" id="sl-time" min="12" max="80" value="38" step="2"><span class="slider-value" id="sv-time">38</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SCALE</span><input type="range" id="sl-scale" min="70" max="150" value="100" step="5"><span class="slider-value" id="sv-scale">1.00</span></div></div>
  <div><div class="btn-row"><button class="control-btn active" id="btn-auto">AUTO</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W=0,H=0,D=1,t=0,mode='flow',auto=true,drag=-1,timeScale=.38,viewScale=1;
var A={a:1.18,b:.42,c:-.32,d:.76};
var traces=[],particles=[];
var hudMode=document.getElementById('h-mode'),hudDet=document.getElementById('h-det'),hudTrace=document.getElementById('h-trace'),hudType=document.getElementById('h-type');
var ma=document.getElementById('m-a'),mb=document.getElementById('m-b'),mc=document.getElementById('m-c'),md=document.getElementById('m-d'),ml1=document.getElementById('m-l1'),ml2=document.getElementById('m-l2');
var slTime=document.getElementById('sl-time'),slScale=document.getElementById('sl-scale'),svTime=document.getElementById('sv-time'),svScale=document.getElementById('sv-scale');
function resize(){D=Math.min(2,devicePixelRatio||1);W=C.width=Math.floor(innerWidth*D);H=C.height=Math.floor(innerHeight*D);C.style.width=innerWidth+'px';C.style.height=innerHeight+'px';seedParticles()}
function center(){return{x:W*.5,y:H*.52,s:Math.min(W,H)*.22*viewScale}}
function tx(p){var g=center();return{x:g.x+p.x*g.s,y:g.y-p.y*g.s}}
function inv(x,y){var g=center();return{x:(x-g.x)/g.s,y:-(y-g.y)/g.s}}
function mul(p){return{x:A.a*p.x+A.b*p.y,y:A.c*p.x+A.d*p.y}}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function eig(){
  var tr=A.a+A.d,det=A.a*A.d-A.b*A.c,disc=tr*tr-4*det;
  if(disc<0)return{tr:tr,det:det,disc:disc,real:false,l1:tr/2,l2:tr/2,vecs:[]};
  var r=Math.sqrt(disc),l1=(tr+r)/2,l2=(tr-r)/2;
  return{tr:tr,det:det,disc:disc,real:true,l1:l1,l2:l2,vecs:[eigvec(l1),eigvec(l2)]};
}
function eigvec(l){
  var x,y;
  if(Math.abs(A.b)>Math.abs(A.c)){x=A.b;y=l-A.a}else{x=l-A.d;y=A.c}
  var m=Math.hypot(x,y)||1;return{x:x/m,y:y/m,l:l};
}
function seedParticles(){
  particles=[];
  for(var i=0;i<90;i++){var a=i*.61803398875*Math.PI*2,r=.16+((i*37)%100)/100*1.18;particles.push({x:Math.cos(a)*r,y:Math.sin(a)*r,age:Math.random()*80})}
}
function reset(){A={a:1.18,b:.42,c:-.32,d:.76};traces=[];seedParticles()}
function spawn(p){var tr={pts:[{x:p.x,y:p.y}],life:1};traces.push(tr);if(traces.length>28)traces.shift()}
function update(){
  t+=.016;
  if(auto){A.a+=Math.sin(t*.37)*.0009;A.d+=Math.cos(t*.31)*.0008;A.b+=Math.sin(t*.23)*.0007;A.c+=Math.cos(t*.29)*.0007}
  var E=eig();
  hudMode.textContent=mode.toUpperCase();hudDet.textContent=E.det.toFixed(2);hudTrace.textContent=E.tr.toFixed(2);hudType.textContent=E.real?'REAL':'COMPLEX';
  ma.textContent=A.a.toFixed(2);mb.textContent=A.b.toFixed(2);mc.textContent=A.c.toFixed(2);md.textContent=A.d.toFixed(2);ml1.textContent=E.l1.toFixed(2);ml2.textContent=E.l2.toFixed(2);
  for(var i=0;i<particles.length;i++){
    var p=particles[i],v=mul(p);p.x+=v.x*.003*timeScale;p.y+=v.y*.003*timeScale;p.age++;
    if(Math.hypot(p.x,p.y)>1.55||p.age>260){var a=(i+t)*2.399,r=.18+((i*19)%100)/100*.9;p.x=Math.cos(a)*r;p.y=Math.sin(a)*r;p.age=0}
  }
  for(var j=traces.length-1;j>=0;j--){
    var q=traces[j],last=q.pts[q.pts.length-1],v2=mul(last);
    q.pts.push({x:last.x+v2.x*.011*timeScale,y:last.y+v2.y*.011*timeScale});
    if(q.pts.length>150)q.pts.shift();
    q.life-=.0025;if(q.life<=0)traces.splice(j,1);
  }
  return E;
}
function drawArrow(from,to,col,w){
  var a=tx(from),b=tx(to),ang=Math.atan2(b.y-a.y,b.x-a.x);
  X.strokeStyle=col;X.fillStyle=col;X.lineWidth=w*D;X.beginPath();X.moveTo(a.x,a.y);X.lineTo(b.x,b.y);X.stroke();
  X.beginPath();X.moveTo(b.x,b.y);X.lineTo(b.x-Math.cos(ang-.45)*10*D,b.y-Math.sin(ang-.45)*10*D);X.lineTo(b.x-Math.cos(ang+.45)*10*D,b.y-Math.sin(ang+.45)*10*D);X.closePath();X.fill();
}
function path(arr,col,w){
  if(!arr.length)return;X.strokeStyle=col;X.lineWidth=w*D;X.beginPath();
  for(var i=0;i<arr.length;i++){var p=tx(arr[i]);if(i)X.lineTo(p.x,p.y);else X.moveTo(p.x,p.y)}
  X.stroke();
}
function drawGrid(){
  for(var i=-8;i<=8;i++){
    var pts1=[],pts2=[];
    for(var k=-80;k<=80;k++){var u=k/40;pts1.push(mul({x:u,y:i/4}));pts2.push(mul({x:i/4,y:u}))}
    path(pts1,'rgba(0,245,212,'+(i===0?.36:.11)+')',1);
    path(pts2,'rgba(123,44,191,'+(i===0?.38:.12)+')',1);
  }
}
function drawCircle(){
  var raw=[],out=[];
  for(var i=0;i<=240;i++){var a=i/240*Math.PI*2,p={x:Math.cos(a),y:Math.sin(a)};raw.push(p);out.push(mul(p))}
  path(raw,'rgba(255,255,255,.16)',1.1);path(out,'rgba(0,245,212,.76)',2.4);
}
function drawEigen(E){
  if(!E.real)return;
  for(var i=0;i<E.vecs.length;i++){
    var v=E.vecs[i],col=i?'rgba(123,44,191,.64)':'rgba(0,245,212,.68)';
    path([{x:-v.x*1.7,y:-v.y*1.7},{x:v.x*1.7,y:v.y*1.7}],col,1.8);
    drawArrow({x:0,y:0},{x:v.x*v.l,y:v.y*v.l},col,1.4);
  }
}
function drawParticles(){
  X.globalCompositeOperation='lighter';
  for(var i=0;i<particles.length;i++){
    var p=tx(particles[i]),q=particles[i].age/260;
    X.fillStyle='rgba(0,245,212,'+(.18+.45*(1-q))+')';
    X.beginPath();X.arc(p.x,p.y,(1.4+2*(1-q))*D,0,Math.PI*2);X.fill();
  }
  X.globalCompositeOperation='source-over';
}
function drawTraces(){
  X.globalCompositeOperation='lighter';
  for(var i=0;i<traces.length;i++)path(traces[i].pts,'rgba(123,44,191,'+(.2+.42*traces[i].life)+')',1.6);
  X.globalCompositeOperation='source-over';
}
function drawHandles(){
  var e1=mul({x:1,y:0}),e2=mul({x:0,y:1});
  drawArrow({x:0,y:0},e1,'rgba(0,245,212,.9)',2.6);
  drawArrow({x:0,y:0},e2,'rgba(123,44,191,.9)',2.6);
  [e1,e2].forEach(function(p,i){var s=tx(p);X.fillStyle=i?'rgba(123,44,191,.95)':'rgba(0,245,212,.95)';X.beginPath();X.arc(s.x,s.y,8*D,0,Math.PI*2);X.fill();X.strokeStyle='rgba(255,255,255,.68)';X.lineWidth=1*D;X.stroke()});
}
function draw(){
  var E=update();
  X.fillStyle='rgba(10,10,15,.38)';X.fillRect(0,0,W,H);
  var g=center();
  X.strokeStyle='rgba(255,255,255,.06)';X.lineWidth=1*D;X.beginPath();X.arc(g.x,g.y,g.s,0,Math.PI*2);X.stroke();
  if(mode==='grid'||mode==='flow')drawGrid();
  drawCircle();
  drawEigen(E);
  if(mode==='flow'){drawParticles();drawTraces()}
  drawHandles();
  requestAnimationFrame(draw);
}
function nearestHandle(x,y){
  var e1=tx(mul({x:1,y:0})),e2=tx(mul({x:0,y:1})),r=22*D*D;
  var d1=(e1.x-x)*(e1.x-x)+(e1.y-y)*(e1.y-y),d2=(e2.x-x)*(e2.x-x)+(e2.y-y)*(e2.y-y);
  if(d1<r&&d1<=d2)return 0;if(d2<r)return 1;return -1;
}
C.addEventListener('pointerdown',function(e){
  var x=e.clientX*D,y=e.clientY*D;
  if(e.button===2){traces=[];if(typeof SFX!=='undefined')SFX.play('pop');return}
  drag=nearestHandle(x,y);
  if(drag<0){spawn(inv(x,y));if(typeof SFX!=='undefined')SFX.play('spawn')}else{auto=false;document.getElementById('btn-auto').classList.remove('active');if(typeof SFX!=='undefined')SFX.play('click')}
  C.setPointerCapture(e.pointerId);
});
C.addEventListener('pointermove',function(e){
  if(drag<0)return;
  var p=inv(e.clientX*D,e.clientY*D),m=1.65;p.x=clamp(p.x,-m,m);p.y=clamp(p.y,-m,m);
  if(drag===0){A.a=p.x;A.c=p.y}else{A.b=p.x;A.d=p.y}
  if(typeof SFX!=='undefined')SFX.play('move',80);
});
addEventListener('pointerup',function(e){drag=-1;try{C.releasePointerCapture(e.pointerId)}catch(err){}});
C.addEventListener('contextmenu',function(e){e.preventDefault()});
document.querySelectorAll('[data-mode]').forEach(function(b){b.addEventListener('click',function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(x){x.classList.toggle('active',x===b)});if(typeof SFX!=='undefined')SFX.play('toggle')})});
slTime.addEventListener('input',function(){timeScale=+this.value/100;svTime.textContent=this.value;if(typeof SFX!=='undefined')SFX.play('tick',80)});
slScale.addEventListener('input',function(){viewScale=+this.value/100;svScale.textContent=viewScale.toFixed(2);if(typeof SFX!=='undefined')SFX.play('tick',80)});
document.getElementById('btn-auto').addEventListener('click',function(){auto=!auto;this.classList.toggle('active',auto);if(typeof SFX!=='undefined')SFX.play('toggle')});
document.getElementById('btn-reset').addEventListener('click',function(){reset();if(typeof SFX!=='undefined')SFX.play('click')});
addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
