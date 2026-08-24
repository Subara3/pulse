<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rips');

$pageName = 'rips';
$pageTitle = 'RIPS COMPLEX - PULSE';
$pageDesc = 'リップス複体。距離パラメータを上げると点群が繋がり、単体複体が成長する。位相的特徴が現れては消える。';
$pageCanonical = 'https://pulse.subara3.com/rips.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  r = <span class="v" id="hud-r">0</span><br>
  &beta;<sub>0</sub> <span class="v" id="hud-b0">0</span><br>
  V <span class="v" id="hud-v">0</span> &middot; E <span class="v" id="hud-e">0</span> &middot; T <span class="v" id="hud-t">0</span>
</div>

<div id="info">
  <span class="v">Vietoris&ndash;Rips Complex</span><br>
  &sigma; &isin; VR(X, r) &hArr; diam(&sigma;) &le; r
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add point'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">RADIUS</span>
      <input type="range" id="sl-r" min="0" max="1000" value="300">
      <span class="slider-value" id="sv-r">0</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">POINTS</span>
      <input type="range" id="sl-n" min="5" max="100" value="40">
      <span class="slider-value" id="sv-n">40</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-auto">AUTO</button>
      <button class="control-btn active" id="btn-tri">TRIANGLES</button>
      <button class="control-btn" id="btn-regen">REGENERATE</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d'),W,H;
var pts=[],n=40;
var r=0,rMax=1,autoOn=true,showTri=true;
var dragIdx=-1,dragging=false;
var PI2=Math.PI*2;
var parent=[],rank=[];
var edgeCount=0,triCount=0,b0=0;
var edgeAge={},triAge={};
var time=0;

function resize(){
  var d=devicePixelRatio||1;
  W=innerWidth;H=innerHeight;
  C.width=W*d;C.height=H*d;
  C.style.width=W+'px';C.style.height=H+'px';
  ctx.setTransform(d,0,0,d,0,0);
  rMax=Math.sqrt(W*W+H*H);
  updateSlider();
}

function scatter(count){
  pts=[];edgeAge={};triAge={};
  var mx=W*.1,my=H*.1;
  for(var i=0;i<count;i++){
    pts.push({x:mx+Math.random()*(W-2*mx),y:my+Math.random()*(H-2*my)});
  }
}

function dist(a,b){
  var dx=a.x-b.x,dy=a.y-b.y;
  return Math.sqrt(dx*dx+dy*dy);
}

function ufInit(){
  parent=[];rank=[];
  for(var i=0;i<pts.length;i++){parent[i]=i;rank[i]=0;}
}

function ufFind(x){
  while(parent[x]!==x){parent[x]=parent[parent[x]];x=parent[x];}
  return x;
}

function ufUnion(a,b){
  a=ufFind(a);b=ufFind(b);
  if(a===b)return;
  if(rank[a]<rank[b]){var t=a;a=b;b=t;}
  parent[b]=a;
  if(rank[a]===rank[b])rank[a]++;
}

function compute(){
  var N=pts.length;
  ufInit();
  edgeCount=0;triCount=0;
  var r2=r*r;
  var adj=[];
  for(var i=0;i<N;i++)adj[i]=[];

  for(var i=0;i<N;i++){
    for(var j=i+1;j<N;j++){
      var dx=pts[i].x-pts[j].x,dy=pts[i].y-pts[j].y;
      if(dx*dx+dy*dy<=r2){
        adj[i].push(j);adj[j].push(i);
        edgeCount++;
        ufUnion(i,j);
        var ek=i+'_'+j;
        if(!edgeAge[ek])edgeAge[ek]=time;
      }
    }
  }

  if(showTri){
    for(var i=0;i<N;i++){
      for(var ai=0;ai<adj[i].length;ai++){
        var j=adj[i][ai];
        if(j<=i)continue;
        for(var bi=ai+1;bi<adj[i].length;bi++){
          var k=adj[i][bi];
          if(k<=j)continue;
          var dx=pts[j].x-pts[k].x,dy=pts[j].y-pts[k].y;
          if(dx*dx+dy*dy<=r2){
            triCount++;
            var tk=i+'_'+j+'_'+k;
            if(!triAge[tk])triAge[tk]=time;
          }
        }
      }
    }
  }

  var roots={};
  for(var i=0;i<N;i++)roots[ufFind(i)]=1;
  b0=0;for(var k in roots)b0++;
}

function draw(){
  ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
  var N=pts.length;
  var r2=r*r;

  if(showTri){
    for(var i=0;i<N;i++){
      for(var j=i+1;j<N;j++){
        var dx1=pts[i].x-pts[j].x,dy1=pts[i].y-pts[j].y;
        if(dx1*dx1+dy1*dy1>r2)continue;
        for(var k=j+1;k<N;k++){
          var dx2=pts[i].x-pts[k].x,dy2=pts[i].y-pts[k].y;
          if(dx2*dx2+dy2*dy2>r2)continue;
          var dx3=pts[j].x-pts[k].x,dy3=pts[j].y-pts[k].y;
          if(dx3*dx3+dy3*dy3>r2)continue;
          var tk=i+'_'+j+'_'+k;
          var age=Math.min(1,(time-(triAge[tk]||time))*0.05+0.3);
          ctx.beginPath();
          ctx.moveTo(pts[i].x,pts[i].y);
          ctx.lineTo(pts[j].x,pts[j].y);
          ctx.lineTo(pts[k].x,pts[k].y);
          ctx.closePath();
          ctx.fillStyle='rgba(123,44,191,'+(.12*age).toFixed(3)+')';
          ctx.fill();
        }
      }
    }
  }

  for(var i=0;i<N;i++){
    for(var j=i+1;j<N;j++){
      var dx=pts[i].x-pts[j].x,dy=pts[i].y-pts[j].y;
      if(dx*dx+dy*dy<=r2){
        var ek=i+'_'+j;
        var age=Math.min(1,(time-(edgeAge[ek]||time))*0.05+0.4);
        ctx.beginPath();
        ctx.moveTo(pts[i].x,pts[i].y);
        ctx.lineTo(pts[j].x,pts[j].y);
        ctx.strokeStyle='rgba(0,245,212,'+(.35*age).toFixed(3)+')';
        ctx.lineWidth=1.2;
        ctx.stroke();
      }
    }
  }

  ctx.shadowColor='rgba(0,245,212,.4)';ctx.shadowBlur=8;
  for(var i=0;i<N;i++){
    var p=pts[i],pulse=.6+.4*Math.sin(time*0.06+i*1.7);
    ctx.beginPath();ctx.arc(p.x,p.y,3.5,0,PI2);
    ctx.fillStyle='rgba(255,255,255,'+(.5+.3*pulse).toFixed(2)+')';
    ctx.fill();
  }
  ctx.shadowColor='transparent';ctx.shadowBlur=0;

  for(var i=0;i<N;i++){
    var p=pts[i];
    ctx.beginPath();ctx.arc(p.x,p.y,r,0,PI2);
    ctx.strokeStyle='rgba(0,245,212,.04)';ctx.lineWidth=.5;
    ctx.stroke();
  }
}

function hud(){
  document.getElementById('hud-r').textContent=r.toFixed(0);
  document.getElementById('hud-b0').textContent=b0;
  document.getElementById('hud-v').textContent=pts.length;
  document.getElementById('hud-e').textContent=edgeCount;
  document.getElementById('hud-t').textContent=triCount;
}

function updateSlider(){
  var sl=document.getElementById('sl-r');
  sl.max=Math.round(rMax*.5);
  r=+sl.value;
  document.getElementById('sv-r').textContent=r.toFixed(0);
}

function frame(){
  time++;
  if(autoOn){
    var sl=document.getElementById('sl-r');
    var v=+sl.value+.4;
    if(v>+sl.max)v=0;
    sl.value=v;
    r=v;
    document.getElementById('sv-r').textContent=r.toFixed(0);
  }
  compute();draw();hud();
  requestAnimationFrame(frame);
}

function findNearest(x,y){
  var best=-1,bd=900;
  for(var i=0;i<pts.length;i++){
    var dx=pts[i].x-x,dy=pts[i].y-y,dd=dx*dx+dy*dy;
    if(dd<bd){bd=dd;best=i;}
  }
  return best;
}

C.addEventListener('mousedown',function(e){
  if(e.button===2)return;
  var x=e.clientX,y=e.clientY;
  var idx=findNearest(x,y);
  if(idx>=0&&Math.hypot(pts[idx].x-x,pts[idx].y-y)<20){
    dragIdx=idx;dragging=true;
  }else{
    pts.push({x:x,y:y});
    SFX.play('spawn');
    document.getElementById('sl-n').value=pts.length;
    document.getElementById('sv-n').textContent=pts.length;
  }
});
C.addEventListener('mousemove',function(e){
  if(dragging&&dragIdx>=0){
    pts[dragIdx].x=e.clientX;pts[dragIdx].y=e.clientY;
    SFX.play('move',100);
  }
});
C.addEventListener('mouseup',function(){dragging=false;dragIdx=-1;});
C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  var idx=findNearest(e.clientX,e.clientY);
  if(idx>=0&&Math.hypot(pts[idx].x-e.clientX,pts[idx].y-e.clientY)<25){
    pts.splice(idx,1);edgeAge={};triAge={};
    SFX.play('pop');
    document.getElementById('sl-n').value=pts.length;
    document.getElementById('sv-n').textContent=pts.length;
  }
});

var touchTimer=null;
C.addEventListener('touchstart',function(e){
  e.preventDefault();
  var t=e.touches[0],x=t.clientX,y=t.clientY;
  var idx=findNearest(x,y);
  if(idx>=0&&Math.hypot(pts[idx].x-x,pts[idx].y-y)<30){
    dragIdx=idx;dragging=true;
    touchTimer=setTimeout(function(){
      if(dragging&&dragIdx>=0){
        pts.splice(dragIdx,1);edgeAge={};triAge={};
        dragging=false;dragIdx=-1;
        SFX.play('pop');
        document.getElementById('sl-n').value=pts.length;
        document.getElementById('sv-n').textContent=pts.length;
      }
    },500);
  }else{
    pts.push({x:x,y:y});
    SFX.play('spawn');
    document.getElementById('sl-n').value=pts.length;
    document.getElementById('sv-n').textContent=pts.length;
  }
},{passive:false});
C.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
  if(dragging&&dragIdx>=0){
    var t=e.touches[0];
    pts[dragIdx].x=t.clientX;pts[dragIdx].y=t.clientY;
  }
},{passive:false});
C.addEventListener('touchend',function(){
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
  dragging=false;dragIdx=-1;
});

document.getElementById('sl-r').addEventListener('input',function(){
  r=+this.value;
  document.getElementById('sv-r').textContent=r.toFixed(0);
  autoOn=false;
  document.getElementById('btn-auto').classList.remove('active');
});

document.getElementById('sl-n').addEventListener('input',function(){
  var v=+this.value;
  document.getElementById('sv-n').textContent=v;
  scatter(v);n=v;
  SFX.play('click');
});

document.getElementById('btn-auto').addEventListener('click',function(){
  autoOn=!autoOn;this.classList.toggle('active',autoOn);
  SFX.play('toggle');
});
document.getElementById('btn-tri').addEventListener('click',function(){
  showTri=!showTri;this.classList.toggle('active',showTri);
  SFX.play('toggle');
});
document.getElementById('btn-regen').addEventListener('click',function(){
  n=+document.getElementById('sl-n').value;
  scatter(n);edgeAge={};triAge={};
  SFX.play('click');
});

resize();scatter(n);
var sl=document.getElementById('sl-r');
sl.value=Math.round(rMax*.15);
r=+sl.value;
document.getElementById('sv-r').textContent=r.toFixed(0);
addEventListener('resize',function(){resize();scatter(n);});
frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
