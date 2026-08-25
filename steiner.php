<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('steiner');

$pageName = 'steiner';
$pageTitle = 'STEINER - PULSE';
$pageDesc = 'シュタイナー木。最短ネットワークが120°接合点を見つけ出す、組合せ最適化の可視化。';
$pageCanonical = 'https://pulse.subara3.com/steiner.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  TERMINALS <b id="h-terms">0</b><br>
  STEINER PTS <b id="h-steiner">0</b><br>
  TREE LENGTH <b id="h-len">0.00</b><br>
  <span class="sub">MST RATIO</span> <b id="h-ratio">1.00</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add point'],
  ['type' => 'drag', 'label' => 'move point'],
  ['type' => 'right', 'label' => 'remove point'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row"><button class="control-btn active" id="btn-optimize">OPTIMIZE</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="10" value="5" step="1"><span class="slider-value" id="sv-speed">5</span></div></div>
  <div><div class="slider-row"><span class="slider-label">POINTS</span><input type="range" id="sl-points" min="3" max="20" value="6" step="1"><span class="slider-value" id="sv-points">6</span></div></div>
  <div><div class="btn-row"><button class="control-btn" id="btn-random">RANDOM</button><button class="control-btn active" id="btn-show-mst">SHOW MST</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W=0,H=0,D=1;
var terminals=[],steinerPts=[],edges=[],mstEdges=[];
var dragging=-1,dragType='',showMst=true,optimizing=true,speed=5;
var animT=0;

var hudTerms=document.getElementById('h-terms'),hudSteiner=document.getElementById('h-steiner');
var hudLen=document.getElementById('h-len'),hudRatio=document.getElementById('h-ratio');
var slSpeed=document.getElementById('sl-speed'),svSpeed=document.getElementById('sv-speed');
var slPoints=document.getElementById('sl-points'),svPoints=document.getElementById('sv-points');

function resize(){
  D=Math.min(2,devicePixelRatio||1);
  W=C.width=Math.floor(innerWidth*D);H=C.height=Math.floor(innerHeight*D);
  C.style.width=innerWidth+'px';C.style.height=innerHeight+'px';
}

function dist(a,b){return Math.sqrt((a.x-b.x)*(a.x-b.x)+(a.y-b.y)*(a.y-b.y));}

function randomPoints(n){
  terminals=[];steinerPts=[];edges=[];mstEdges=[];
  var margin=0.15;
  for(var i=0;i<n;i++){
    terminals.push({x:margin+Math.random()*(1-2*margin),y:margin+Math.random()*(1-2*margin)});
  }
  computeMST();
  initSteiner();
}

function computeMST(){
  var n=terminals.length;if(n<2){mstEdges=[];return;}
  var inTree=new Array(n).fill(false);
  var minDist=new Array(n).fill(Infinity);
  var minEdge=new Array(n).fill(-1);
  mstEdges=[];
  inTree[0]=true;
  for(var i=1;i<n;i++){minDist[i]=dist(terminals[0],terminals[i]);minEdge[i]=0;}
  for(var iter=0;iter<n-1;iter++){
    var u=-1,best=Infinity;
    for(var i=0;i<n;i++){if(!inTree[i]&&minDist[i]<best){best=minDist[i];u=i;}}
    if(u===-1)break;
    inTree[u]=true;
    mstEdges.push([minEdge[u],u]);
    for(var i=0;i<n;i++){
      if(!inTree[i]){var d=dist(terminals[u],terminals[i]);if(d<minDist[i]){minDist[i]=d;minEdge[i]=u;}}
    }
  }
}

function initSteiner(){
  steinerPts=[];edges=[];
  if(terminals.length<3){
    if(terminals.length===2)edges=[[0,1,'t','t']];
    return;
  }
  var n=terminals.length;
  for(var i=0;i<n-2;i++){
    var cx=(terminals[i].x+terminals[i+1].x+terminals[(i+2)%n].x)/3;
    var cy=(terminals[i].y+terminals[i+1].y+terminals[(i+2)%n].y)/3;
    cx+=(.5-Math.random())*.02;cy+=(.5-Math.random())*.02;
    steinerPts.push({x:cx,y:cy});
  }
  rebuildEdges();
}

function rebuildEdges(){
  var all=terminals.concat(steinerPts);
  var n=all.length;
  if(n<2){edges=[];return;}
  var inTree=new Array(n).fill(false);
  var minD=new Array(n).fill(Infinity);
  var minE=new Array(n).fill(-1);
  edges=[];
  inTree[0]=true;
  for(var i=1;i<n;i++){minD[i]=dist(all[0],all[i]);minE[i]=0;}
  for(var iter=0;iter<n-1;iter++){
    var u=-1,best=Infinity;
    for(var i=0;i<n;i++){if(!inTree[i]&&minD[i]<best){best=minD[i];u=i;}}
    if(u===-1)break;
    inTree[u]=true;
    var ta=u<terminals.length?'t':'s';
    var tb=minE[u]<terminals.length?'t':'s';
    edges.push([u,minE[u],ta,tb]);
    for(var i=0;i<n;i++){
      if(!inTree[i]){var d=dist(all[u],all[i]);if(d<minD[i]){minD[i]=d;minE[i]=u;}}
    }
  }
}

function optimizeStep(){
  if(!optimizing||terminals.length<3)return;
  var all=terminals.concat(steinerPts);
  var tn=terminals.length,sn=steinerPts.length;
  if(sn===0)return;

  var adj=[];
  for(var i=0;i<all.length;i++)adj.push([]);
  for(var i=0;i<edges.length;i++){
    adj[edges[i][0]].push(edges[i][1]);
    adj[edges[i][1]].push(edges[i][0]);
  }

  var moved=false;
  for(var si=0;si<sn;si++){
    var idx=tn+si;
    var neighbors=adj[idx];
    if(neighbors.length<2)continue;
    var fx=0,fy=0;
    for(var j=0;j<neighbors.length;j++){
      var nb=all[neighbors[j]];
      var dx=nb.x-steinerPts[si].x,dy=nb.y-steinerPts[si].y;
      var d=Math.sqrt(dx*dx+dy*dy);
      if(d>0.0001){fx+=dx/d;fy+=dy/d;}
    }
    var step=0.002*speed;
    var nx=steinerPts[si].x+fx*step;
    var ny=steinerPts[si].y+fy*step;
    nx=Math.max(0.02,Math.min(0.98,nx));
    ny=Math.max(0.02,Math.min(0.98,ny));
    if(Math.abs(nx-steinerPts[si].x)>0.00001||Math.abs(ny-steinerPts[si].y)>0.00001)moved=true;
    steinerPts[si].x=nx;steinerPts[si].y=ny;
  }

  for(var si=sn-1;si>=0;si--){
    var idx=tn+si;
    var neighbors=adj[idx];
    if(neighbors.length<=1){
      steinerPts.splice(si,1);
      moved=true;
    }
  }

  // 直前のループで steinerPts を splice しているため、sn は古い長さになっている。
  // sn のまま回すと steinerPts[si] が undefined になり dist() で落ちる（ページ全体が停止）。
  for(var si=steinerPts.length-1;si>=0;si--){
    var tooClose=false;
    for(var j=0;j<terminals.length;j++){
      if(dist(steinerPts[si],terminals[j])<0.015){tooClose=true;break;}
    }
    if(!tooClose){
      for(var j=0;j<steinerPts.length;j++){
        if(j!==si&&dist(steinerPts[si],steinerPts[j])<0.01){tooClose=true;break;}
      }
    }
    if(tooClose){steinerPts.splice(si,1);moved=true;}
  }

  if(moved)rebuildEdges();
}

function treeLength(edgeList,allPts){
  var len=0;
  for(var i=0;i<edgeList.length;i++){
    len+=dist(allPts[edgeList[i][0]],allPts[edgeList[i][1]]);
  }
  return len;
}

function mstLength(){
  var len=0;
  for(var i=0;i<mstEdges.length;i++){
    len+=dist(terminals[mstEdges[i][0]],terminals[mstEdges[i][1]]);
  }
  return len;
}

function toScreen(p){return{x:p.x*W,y:p.y*H};}
function fromScreen(sx,sy){return{x:sx/W,y:sy/H};}

function draw(){
  X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);
  animT+=0.01;
  var all=terminals.concat(steinerPts);

  if(showMst&&mstEdges.length>0){
    X.strokeStyle='rgba(123,44,191,0.25)';
    X.lineWidth=1.5*D;
    X.setLineDash([4*D,6*D]);
    X.beginPath();
    for(var i=0;i<mstEdges.length;i++){
      var a=toScreen(terminals[mstEdges[i][0]]),b=toScreen(terminals[mstEdges[i][1]]);
      X.moveTo(a.x,a.y);X.lineTo(b.x,b.y);
    }
    X.stroke();
    X.setLineDash([]);
  }

  if(edges.length>0){
    for(var i=0;i<edges.length;i++){
      var a=toScreen(all[edges[i][0]]),b=toScreen(all[edges[i][1]]);
      var grad=X.createLinearGradient(a.x,a.y,b.x,b.y);
      grad.addColorStop(0,'rgba(0,245,212,0.7)');
      grad.addColorStop(1,'rgba(0,200,180,0.5)');
      X.strokeStyle=grad;
      X.lineWidth=2*D;
      X.beginPath();X.moveTo(a.x,a.y);X.lineTo(b.x,b.y);X.stroke();

      var pulse=((animT*2+i*0.3)%1);
      var px=a.x+(b.x-a.x)*pulse,py=a.y+(b.y-a.y)*pulse;
      X.fillStyle='rgba(0,245,212,'+(0.8*(1-Math.abs(pulse-0.5)*2))+')';
      X.beginPath();X.arc(px,py,2.5*D,0,Math.PI*2);X.fill();
    }
  }

  for(var i=0;i<steinerPts.length;i++){
    var s=toScreen(steinerPts[i]);
    X.fillStyle='rgba(123,44,191,0.15)';
    X.beginPath();X.arc(s.x,s.y,12*D,0,Math.PI*2);X.fill();
    X.fillStyle='#7b2cbf';
    X.beginPath();X.arc(s.x,s.y,4*D,0,Math.PI*2);X.fill();
    X.strokeStyle='rgba(123,44,191,0.6)';X.lineWidth=1*D;
    X.beginPath();X.arc(s.x,s.y,7*D,0,Math.PI*2);X.stroke();

    var adj2=[];
    for(var j=0;j<edges.length;j++){
      var idx=terminals.length+i;
      if(edges[j][0]===idx)adj2.push(edges[j][1]);
      else if(edges[j][1]===idx)adj2.push(edges[j][0]);
    }
    if(adj2.length>=2){
      for(var j=0;j<adj2.length;j++){
        for(var k=j+1;k<adj2.length;k++){
          var pA=all[adj2[j]],pB=all[adj2[k]],pC=steinerPts[i];
          var dx1=pA.x-pC.x,dy1=pA.y-pC.y,dx2=pB.x-pC.x,dy2=pB.y-pC.y;
          var d1=Math.sqrt(dx1*dx1+dy1*dy1),d2=Math.sqrt(dx2*dx2+dy2*dy2);
          if(d1>0.001&&d2>0.001){
            var angle=Math.acos(Math.max(-1,Math.min(1,(dx1*dx2+dy1*dy2)/(d1*d2))))*180/Math.PI;
            if(Math.abs(angle-120)<15){
              var aS=toScreen({x:pC.x+dx1/d1*0.025,y:pC.y+dy1/d1*0.025});
              var bS=toScreen({x:pC.x+dx2/d2*0.025,y:pC.y+dy2/d2*0.025});
              X.strokeStyle='rgba(0,245,212,0.3)';X.lineWidth=0.8*D;
              X.beginPath();X.arc(s.x,s.y,10*D,
                Math.atan2(aS.y-s.y,aS.x-s.x),
                Math.atan2(bS.y-s.y,bS.x-s.x));X.stroke();
            }
          }
        }
      }
    }
  }

  for(var i=0;i<terminals.length;i++){
    var s=toScreen(terminals[i]);
    X.fillStyle='rgba(0,245,212,0.08)';
    X.beginPath();X.arc(s.x,s.y,18*D,0,Math.PI*2);X.fill();
    X.fillStyle='#00f5d4';
    X.beginPath();X.arc(s.x,s.y,5.5*D,0,Math.PI*2);X.fill();
    X.strokeStyle='rgba(0,245,212,0.4)';X.lineWidth=1.2*D;
    X.beginPath();X.arc(s.x,s.y,9*D,0,Math.PI*2);X.stroke();
  }

  var sLen=treeLength(edges,all);
  var mLen=mstLength();
  var ratio=mLen>0?sLen/mLen:1;
  hudTerms.textContent=terminals.length;
  hudSteiner.textContent=steinerPts.length;
  hudLen.textContent=sLen.toFixed(3);
  hudRatio.textContent=ratio.toFixed(3);
}

function frame(){
  for(var i=0;i<speed;i++)optimizeStep();
  draw();
  requestAnimationFrame(frame);
}

function findNearest(mx,my,threshold){
  var p=fromScreen(mx*D,my*D);
  var best=-1,bestD=threshold/(Math.min(innerWidth,innerHeight)),type='';
  for(var i=0;i<terminals.length;i++){
    var d=dist(p,terminals[i]);
    if(d<bestD){bestD=d;best=i;type='t';}
  }
  for(var i=0;i<steinerPts.length;i++){
    var d=dist(p,steinerPts[i]);
    if(d<bestD){bestD=d;best=i;type='s';}
  }
  return{idx:best,type:type};
}

function onDown(e){
  e.preventDefault();
  var rect=C.getBoundingClientRect();
  var mx,my;
  if(e.touches){mx=e.touches[0].clientX-rect.left;my=e.touches[0].clientY-rect.top;}
  else{mx=e.clientX-rect.left;my=e.clientY-rect.top;}

  if(e.button===2){
    var hit=findNearest(mx,my,40);
    if(hit.idx>=0&&hit.type==='t'){
      terminals.splice(hit.idx,1);
      computeMST();initSteiner();
      if(typeof SFX!=='undefined')SFX.play('pop');
    }
    return;
  }

  var hit=findNearest(mx,my,30);
  if(hit.idx>=0){
    dragging=hit.idx;dragType=hit.type;
  }else{
    var p=fromScreen(mx*D,my*D);
    terminals.push({x:p.x,y:p.y});
    computeMST();initSteiner();
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }
}

function onMove(e){
  if(dragging<0)return;
  e.preventDefault();
  var rect=C.getBoundingClientRect();
  var mx,my;
  if(e.touches){mx=e.touches[0].clientX-rect.left;my=e.touches[0].clientY-rect.top;}
  else{mx=e.clientX-rect.left;my=e.clientY-rect.top;}
  var p=fromScreen(mx*D,my*D);
  p.x=Math.max(0.02,Math.min(0.98,p.x));
  p.y=Math.max(0.02,Math.min(0.98,p.y));
  if(dragType==='t'){
    terminals[dragging]=p;
    computeMST();rebuildEdges();
  }else{
    steinerPts[dragging]=p;
    rebuildEdges();
  }
  if(typeof SFX!=='undefined')SFX.play('move',80);
}

function onUp(){dragging=-1;dragType='';}

C.addEventListener('mousedown',onDown);
C.addEventListener('mousemove',onMove);
C.addEventListener('mouseup',onUp);
C.addEventListener('touchstart',onDown,{passive:false});
C.addEventListener('touchmove',onMove,{passive:false});
C.addEventListener('touchend',onUp);
C.addEventListener('contextmenu',function(e){e.preventDefault();});

document.getElementById('btn-optimize').addEventListener('click',function(){
  optimizing=!optimizing;
  this.classList.toggle('active',optimizing);
  if(typeof SFX!=='undefined')SFX.play('toggle');
});
document.getElementById('btn-reset').addEventListener('click',function(){
  randomPoints(parseInt(slPoints.value));
  if(typeof SFX!=='undefined')SFX.play('click');
});
document.getElementById('btn-random').addEventListener('click',function(){
  randomPoints(parseInt(slPoints.value));
  if(typeof SFX!=='undefined')SFX.play('click');
});
document.getElementById('btn-show-mst').addEventListener('click',function(){
  showMst=!showMst;
  this.classList.toggle('active',showMst);
  if(typeof SFX!=='undefined')SFX.play('toggle');
});
slSpeed.addEventListener('input',function(){speed=parseInt(this.value);svSpeed.textContent=speed;});
slPoints.addEventListener('input',function(){svPoints.textContent=this.value;});

window.addEventListener('resize',resize);
resize();
randomPoints(6);
frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
