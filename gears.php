<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gears');

$pageName = 'gears';
$pageTitle = 'GEARS - PULSE';
$pageDesc = '歯車列シミュレーション。噛み合う歯車が回転比を伝え、歯形が滑らかに接触する。';
$pageCanonical = 'https://pulse.subara3.com/gears.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>GEARS <span id="hud-cnt">2</span></div>
  <div>RATIO <span id="hud-rat">1:1</span></div>
  <div>TEETH <span id="hud-tth">-</span></div>
  <div>TARGET <span id="hud-tgt">-</span></div>
  <div><span id="hud-res">make any gear turn like this</span></div>
  <div>SOLVED <span id="hud-sol">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag',  'label' => 'move'],
  ['type' => 'wheel', 'label' => 'teeth'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">TEETH</label>
    <input type="range" id="sl-teeth" min="8" max="60" value="20">
    <span class="slider-value" id="sv-teeth">20</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="0" max="100" value="80">
    <span class="slider-value" id="sv-speed">80</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-auto">AUTO</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
    <button class="ctrl-btn" id="btn-target">NEW TARGET</button>
  </div>
  <div class="slider-row"><label class="slider-label">PRESETS</label></div>
  <div class="btn-row" id="preset-btns">
    <button class="ctrl-btn" data-preset="pair">PAIR</button>
    <button class="ctrl-btn" data-preset="train">TRAIN</button>
    <button class="ctrl-btn" data-preset="planet">PLANET</button>
    <button class="ctrl-btn" data-preset="clock">CLOCK</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;

var MODULE=window.innerWidth>900?7:5;
var gears=[];
var connections=[];
var autoRotate=true;
var speed=0.8;
var nextTeeth=20;
var driverAngle=0;
var selectedIdx=-1;
var dragIdx=-1;
var dragOX=0,dragOY=0;
var isDragging=false;
var lastTime=0;

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
}
window.addEventListener('resize',resize);
resize();

function makeGear(x,y,n,colorIdx){
  return{x:x,y:y,n:n,r:n*MODULE/2,angle:0,col:colorIdx||0};
}

function toothProfile(t){
  if(t<0.18||t>=0.82)return 0;
  if(t<0.26)return(t-0.18)/0.08;
  if(t<0.74)return 1;
  return 1-(t-0.74)/0.08;
}

function drawGear(g,sel){
  var r=g.r,n=g.n,a=g.angle;
  var m=MODULE;
  var outerR=r+m;
  var innerR=r-m*1.25;
  var steps=n*10;
  var isTeal=g.col%2===0;
  var baseCol=isTeal?[0,245,212]:[123,44,191];

  X.save();
  X.translate(g.x,g.y);

  X.beginPath();
  for(var i=0;i<=steps;i++){
    var theta=a+(i/steps)*Math.PI*2;
    var frac=((i/steps*n)%1+1)%1;
    var blend=toothProfile(frac);
    var rad=innerR+(outerR-innerR)*blend;
    var px=rad*Math.cos(theta);
    var py=rad*Math.sin(theta);
    if(i===0)X.moveTo(px,py);
    else X.lineTo(px,py);
  }
  X.closePath();
  X.fillStyle='rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.12)';
  X.fill();
  X.strokeStyle=sel?'#fff':'rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.7)';
  X.lineWidth=sel?2:1.2;
  X.stroke();

  X.beginPath();
  X.arc(0,0,r,0,Math.PI*2);
  X.strokeStyle='rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.15)';
  X.setLineDash([3,6]);
  X.lineWidth=0.5;
  X.stroke();
  X.setLineDash([]);

  var hubR=Math.max(r*0.18,5);
  X.beginPath();X.arc(0,0,hubR,0,Math.PI*2);
  X.fillStyle='rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.25)';
  X.fill();
  X.strokeStyle='rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.6)';
  X.lineWidth=1;X.stroke();

  var cr=hubR*0.55;
  X.beginPath();
  X.moveTo(-cr,0);X.lineTo(cr,0);
  X.moveTo(0,-cr);X.lineTo(0,cr);
  X.strokeStyle='rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.5)';
  X.lineWidth=1;X.stroke();

  var markR=r+m+4;
  var markA=a;
  X.beginPath();
  X.arc(markR*Math.cos(markA),markR*Math.sin(markA),2,0,Math.PI*2);
  X.fillStyle='rgba('+baseCol[0]+','+baseCol[1]+','+baseCol[2]+',0.5)';
  X.fill();

  X.restore();
}

function drawConnectionLine(ga,gb){
  X.beginPath();
  X.moveTo(ga.x,ga.y);X.lineTo(gb.x,gb.y);
  X.strokeStyle='rgba(255,255,255,0.06)';
  X.lineWidth=1;X.setLineDash([4,8]);
  X.stroke();X.setLineDash([]);
}

function pitchDist(ga,gb){return ga.r+gb.r;}

function dist(a,b){var dx=a.x-b.x,dy=a.y-b.y;return Math.sqrt(dx*dx+dy*dy);}

function findGearAt(mx,my){
  for(var i=gears.length-1;i>=0;i--){
    var g=gears[i];
    if(dist({x:mx,y:my},g)<=g.r+MODULE)return i;
  }
  return-1;
}

function meshPhaseAngle(ga,gb){
  var alpha=Math.atan2(gb.y-ga.y,gb.x-ga.x);
  var beta=alpha+Math.PI;
  var tA=(((alpha-ga.angle)*ga.n/(2*Math.PI))%1+1)%1;
  var desired=(tA+0.5)%1;
  var angleB=beta-desired*2*Math.PI/gb.n;
  return angleB;
}

function addConnection(ai,bi){
  for(var i=0;i<connections.length;i++){
    var c=connections[i];
    if((c.a===ai&&c.b===bi)||(c.a===bi&&c.b===ai))return;
  }
  var ga=gears[ai],gb=gears[bi];
  gb.angle=meshPhaseAngle(ga,gb);
  connections.push({a:ai,b:bi,refA:ga.angle,refB:gb.angle});
}

function removeGearConnections(idx){
  connections=connections.filter(function(c){return c.a!==idx&&c.b!==idx;});
}

function fixConnectionIndices(removedIdx){
  connections=connections.filter(function(c){return c.a!==removedIdx&&c.b!==removedIdx;});
  for(var i=0;i<connections.length;i++){
    if(connections[i].a>removedIdx)connections[i].a--;
    if(connections[i].b>removedIdx)connections[i].b--;
  }
}

function propagateRotation(){
  if(gears.length===0)return;
  gears[0].angle=driverAngle;
  var visited=new Array(gears.length);
  for(var i=0;i<visited.length;i++)visited[i]=false;
  visited[0]=true;
  var queue=[0];
  while(queue.length>0){
    var cur=queue.shift();
    for(var ci=0;ci<connections.length;ci++){
      var c=connections[ci];
      var neighbor=-1;
      if(c.a===cur&&!visited[c.b]){
        neighbor=c.b;
        var delta=gears[cur].angle-c.refA;
        gears[neighbor].angle=c.refB-delta*(gears[c.a].n/gears[c.b].n);
      }else if(c.b===cur&&!visited[c.a]){
        neighbor=c.a;
        var delta2=gears[cur].angle-c.refB;
        gears[neighbor].angle=c.refA-delta2*(gears[c.b].n/gears[c.a].n);
      }
      if(neighbor>=0){visited[neighbor]=true;queue.push(neighbor);}
    }
  }
}

function tryAutoMesh(idx,loose){
  var g=gears[idx];
  // 新しく置いた歯車は、いちばん近い歯車に吸い付かせる（指で正確な距離に置くのは難しい）
  var only=-1;
  if(loose){
    var bestD=Infinity;
    for(var j=0;j<gears.length;j++){
      if(j===idx)continue;
      var dd=Math.abs(dist(g,gears[j])-pitchDist(g,gears[j]));
      if(dd<bestD){bestD=dd;only=j;}
    }
    if(only<0||bestD>pitchDist(g,gears[only])*0.6)only=-1;
  }
  for(var i=0;i<gears.length;i++){
    if(i===idx)continue;
    var d=dist(g,gears[i]);
    var pd=pitchDist(g,gears[i]);
    if(i===only||Math.abs(d-pd)<MODULE*3){
      var alreadyConnected=false;
      for(var ci=0;ci<connections.length;ci++){
        var c=connections[ci];
        if((c.a===idx&&c.b===i)||(c.a===i&&c.b===idx)){alreadyConnected=true;break;}
      }
      if(!alreadyConnected){
        var alpha=Math.atan2(g.y-gears[i].y,g.x-gears[i].x);
        g.x=gears[i].x+pd*Math.cos(alpha);
        g.y=gears[i].y+pd*Math.sin(alpha);
        g.r=g.n*MODULE/2;
        addConnection(i,idx);
        if(window.SFX)SFX.play('collide');
      }
    }
  }
}

function getGearRatio(idx){
  if(idx===0)return{num:1,den:1,dir:1};
  var visited=new Array(gears.length);
  for(var i=0;i<visited.length;i++)visited[i]=false;
  visited[0]=true;
  var ratios=new Array(gears.length);
  ratios[0]={n:1,d:1,s:1};
  var queue=[0];
  while(queue.length>0){
    var cur=queue.shift();
    for(var ci=0;ci<connections.length;ci++){
      var c=connections[ci];
      var neighbor=-1,nA,nB;
      if(c.a===cur&&!visited[c.b]){neighbor=c.b;nA=gears[c.a].n;nB=gears[c.b].n;}
      else if(c.b===cur&&!visited[c.a]){neighbor=c.a;nA=gears[c.b].n;nB=gears[c.a].n;}
      if(neighbor>=0){
        visited[neighbor]=true;
        ratios[neighbor]={n:ratios[cur].n*nA,d:ratios[cur].d*nB,s:-ratios[cur].s};
        queue.push(neighbor);
      }
    }
  }
  if(!ratios[idx])return null;
  var g=gcd(Math.abs(ratios[idx].n),Math.abs(ratios[idx].d));
  return{num:ratios[idx].n/g,den:ratios[idx].d/g,dir:ratios[idx].s};
}

// 目標: 「駆動歯車に対して a:b の速さで、同じ/逆向きに回る歯車を作れ」。
// 歯数比だけで速さが決まり、間に挟んだ歯車（アイドラー）は向きだけを変える
var target=null,solved=0,solvedNow=false;
function newTarget(){
  var n0=gears.length?gears[0].n:20;
  var cands=[];
  for(var p=1;p<=4;p++)for(var q=1;q<=4;q++){
    if(p===q||gcd(p,q)!==1)continue;
    // 出力の歯数 nOut = n0*q/p が 8..60 の整数になるものだけ
    if((n0*q)%p!==0)continue;
    var nOut=n0*q/p;
    if(nOut>=8&&nOut<=60&&nOut!==n0)cands.push({num:p,den:q});
  }
  if(!cands.length)cands.push({num:1,den:1});
  var t=cands[Math.floor(Math.random()*cands.length)];
  target={num:t.num,den:t.den,dir:Math.random()<0.5?1:-1};
  solvedNow=false;
  document.getElementById('hud-tgt').textContent=t.num+':'+t.den+(target.dir>0?' SAME':' REVERSE');
  document.getElementById('hud-res').textContent='make any gear turn like this';
  checkTarget();
}
function checkTarget(){
  if(!target)return;
  var hit=-1;
  for(var i=1;i<gears.length;i++){
    var r=getGearRatio(i);
    if(r&&r.num===target.num&&r.den===target.den&&r.dir===target.dir){hit=i;break;}
  }
  target.hit=hit;
  if(hit>=0&&!solvedNow){
    solvedNow=true;solved++;
    document.getElementById('hud-sol').textContent=solved;
    document.getElementById('hud-res').textContent='GEAR '+(hit+1)+' HITS IT';
    if(window.SFX)SFX.play('correct');
  }else if(hit<0&&solvedNow){
    solvedNow=false;
    document.getElementById('hud-res').textContent='make any gear turn like this';
  }
}
function gcd(a,b){while(b){var t=b;b=a%t;a=t;}return a;}

function updateHUD(){
  checkTarget();
  document.getElementById('hud-cnt').textContent=gears.length;
  if(selectedIdx>=0&&selectedIdx<gears.length){
    var g=gears[selectedIdx];
    document.getElementById('hud-tth').textContent=g.n;
    var r=getGearRatio(selectedIdx);
    if(r)document.getElementById('hud-rat').textContent=r.num+':'+r.den+(r.dir>0?' SAME':' REVERSE');
    else document.getElementById('hud-rat').textContent='-';
  }else{
    document.getElementById('hud-tth').textContent=nextTeeth;
    document.getElementById('hud-rat').textContent='-';
  }
}

function initDefault(){
  gears=[];connections=[];driverAngle=0;selectedIdx=-1;
  var cx=W>900?W*0.4:W/2,cy=H/2;
  var g1=makeGear(cx-60,cy,20,0);
  var g2=makeGear(cx+60,cy,20,1);
  gears.push(g1,g2);
  var pd=pitchDist(g1,g2);
  gears[1].x=gears[0].x+pd;
  gears[1].y=gears[0].y;
  addConnection(0,1);
  updateHUD();
}

function loadPreset(name){
  gears=[];connections=[];driverAngle=0;selectedIdx=-1;
  var cx=W>900?W*0.4:W/2,cy=H/2;
  if(name==='pair'){
    gears.push(makeGear(cx-60,cy,16,0));
    gears.push(makeGear(0,cy,32,1));
    gears[1].x=gears[0].x+pitchDist(gears[0],gears[1]);
    addConnection(0,1);
  }else if(name==='train'){
    gears.push(makeGear(cx-160,cy,12,0));
    gears.push(makeGear(0,cy,24,1));
    gears[1].x=gears[0].x+pitchDist(gears[0],gears[1]);
    gears.push(makeGear(0,cy,16,0));
    gears[2].x=gears[1].x+pitchDist(gears[1],gears[2]);
    gears.push(makeGear(0,cy,36,1));
    gears[3].x=gears[2].x+pitchDist(gears[2],gears[3]);
    addConnection(0,1);addConnection(1,2);addConnection(2,3);
  }else if(name==='planet'){
    var sunN=16,planetN=12,ringN=40;
    gears.push(makeGear(cx,cy,sunN,0));
    var orbitR=gears[0].r+planetN*MODULE/2;
    for(var i=0;i<3;i++){
      var a=i*Math.PI*2/3-Math.PI/2;
      var px=cx+orbitR*Math.cos(a);
      var py=cy+orbitR*Math.sin(a);
      gears.push(makeGear(px,py,planetN,1));
      addConnection(0,gears.length-1);
    }
  }else if(name==='clock'){
    gears.push(makeGear(cx,cy,12,0));
    gears.push(makeGear(0,cy-pitchDist(makeGear(0,0,12,0),makeGear(0,0,36,0)),36,1));
    gears[1].x=cx;gears[1].y=cy-pitchDist(gears[0],gears[1]);
    addConnection(0,1);
    gears.push(makeGear(0,0,18,0));
    gears[2].x=gears[1].x+pitchDist(gears[1],gears[2]);gears[2].y=gears[1].y;
    addConnection(1,2);
    gears.push(makeGear(0,0,48,1));
    gears[3].x=gears[2].x;gears[3].y=gears[2].y+pitchDist(gears[2],gears[3]);
    addConnection(2,3);
  }
  updateHUD();
  if(window.SFX)SFX.play('click');
}

function drawGrid(){
  X.strokeStyle='rgba(255,255,255,0.03)';
  X.lineWidth=0.5;
  var step=50;
  for(var x=step;x<W;x+=step){X.beginPath();X.moveTo(x,0);X.lineTo(x,H);X.stroke();}
  for(var y=step;y<H;y+=step){X.beginPath();X.moveTo(0,y);X.lineTo(W,y);X.stroke();}
}

function draw(now){
  if(!lastTime)lastTime=now;
  var dt=(now-lastTime)/1000;
  lastTime=now;

  if(autoRotate)driverAngle+=speed*dt;
  propagateRotation();

  X.clearRect(0,0,W,H);
  drawGrid();

  for(var ci=0;ci<connections.length;ci++){
    drawConnectionLine(gears[connections[ci].a],gears[connections[ci].b]);
  }

  for(var i=0;i<gears.length;i++){
    drawGear(gears[i],i===selectedIdx);
  }
  if(target&&target.hit>=0&&gears[target.hit]){
    var hg=gears[target.hit];
    X.beginPath();X.arc(hg.x,hg.y,hg.r+MODULE+14,0,Math.PI*2);
    X.strokeStyle='rgba(0,245,212,0.55)';X.lineWidth=2;X.stroke();
  }

  for(var i=0;i<gears.length;i++){
    var g=gears[i];
    var isTeal=g.col%2===0;
    var col=isTeal?'rgba(0,245,212,':'rgba(123,44,191,';
    X.save();
    X.translate(g.x,g.y);
    var markA=g.angle;
    var outerR=g.r+MODULE+6;
    for(var t=0;t<g.n;t++){
      var ta=markA+t*Math.PI*2/g.n;
      var tx=outerR*Math.cos(ta);
      var ty=outerR*Math.sin(ta);
      X.beginPath();X.arc(tx,ty,0.8,0,Math.PI*2);
      X.fillStyle=col+'0.15)';X.fill();
    }
    X.restore();
  }

  if(i===0&&gears.length>0){
    var dg=gears[0];
    X.beginPath();X.arc(dg.x,dg.y,dg.r+MODULE+10,0,Math.PI*2);
    X.strokeStyle='rgba(0,245,212,0.15)';X.lineWidth=1;
    X.setLineDash([2,6]);X.stroke();X.setLineDash([]);
  }

  requestAnimationFrame(draw);
}
requestAnimationFrame(draw);

function getPos(e){
  var r=C.getBoundingClientRect();
  if(e.touches&&e.touches.length>0)return{x:e.touches[0].clientX-r.left,y:e.touches[0].clientY-r.top};
  return{x:e.clientX-r.left,y:e.clientY-r.top};
}

C.addEventListener('mousedown',function(e){
  if(e.button===2)return;
  var p=getPos(e);
  var idx=findGearAt(p.x,p.y);
  if(idx>=0){
    dragIdx=idx;isDragging=false;
    dragOX=p.x-gears[idx].x;dragOY=p.y-gears[idx].y;
    selectedIdx=idx;
    updateHUD();
  }else{
    selectedIdx=-1;
    updateHUD();
  }
});

C.addEventListener('mousemove',function(e){
  if(dragIdx<0)return;
  var p=getPos(e);
  isDragging=true;
  removeGearConnections(dragIdx);
  gears[dragIdx].x=p.x-dragOX;
  gears[dragIdx].y=p.y-dragOY;
});

C.addEventListener('mouseup',function(e){
  if(dragIdx>=0){
    if(isDragging){
      tryAutoMesh(dragIdx);
      updateHUD();
    }else{
      selectedIdx=dragIdx;
      updateHUD();
    }
  }else if(!isDragging){
    var p=getPos(e);
    var idx=findGearAt(p.x,p.y);
    if(idx<0){
      var ng=makeGear(p.x,p.y,nextTeeth,gears.length%2);
      gears.push(ng);
      tryAutoMesh(gears.length-1,true);
      selectedIdx=gears.length-1;
      updateHUD();
      if(window.SFX)SFX.play('spawn');
    }
  }
  dragIdx=-1;isDragging=false;
});

C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  var p=getPos(e);
  var idx=findGearAt(p.x,p.y);
  if(idx>=0){
    if(idx===0&&gears.length>1){
      fixConnectionIndices(0);
      gears.splice(0,1);
      driverAngle=gears[0].angle;
      connections.forEach(function(c){
        c.refA=gears[c.a].angle;c.refB=gears[c.b].angle;
      });
    }else{
      fixConnectionIndices(idx);
      gears.splice(idx,1);
    }
    if(selectedIdx===idx)selectedIdx=-1;
    else if(selectedIdx>idx)selectedIdx--;
    updateHUD();
    if(window.SFX)SFX.play('pop');
  }
});

C.addEventListener('wheel',function(e){
  e.preventDefault();
  var p=getPos(e);
  var idx=findGearAt(p.x,p.y);
  if(idx>=0){
    var g=gears[idx];
    var oldN=g.n;
    g.n+=e.deltaY>0?-1:1;
    if(g.n<8)g.n=8;if(g.n>60)g.n=60;
    if(g.n!==oldN){
      g.r=g.n*MODULE/2;
      removeGearConnections(idx);
      tryAutoMesh(idx);
      selectedIdx=idx;
      updateHUD();
      if(window.SFX)SFX.play('tick',80);
    }
  }else{
    nextTeeth+=e.deltaY>0?-1:1;
    if(nextTeeth<8)nextTeeth=8;if(nextTeeth>60)nextTeeth=60;
    document.getElementById('sl-teeth').value=nextTeeth;
    document.getElementById('sv-teeth').textContent=nextTeeth;
    updateHUD();
  }
},{passive:false});

var touchId=null;
C.addEventListener('touchstart',function(e){
  if(e.touches.length!==1)return;
  var p=getPos(e);
  var idx=findGearAt(p.x,p.y);
  touchId=e.touches[0].identifier;
  if(idx>=0){
    dragIdx=idx;isDragging=false;
    dragOX=p.x-gears[idx].x;dragOY=p.y-gears[idx].y;
    selectedIdx=idx;
    updateHUD();
  }
},{passive:true});

C.addEventListener('touchmove',function(e){
  if(dragIdx<0)return;
  e.preventDefault();
  var p=getPos(e);
  isDragging=true;
  removeGearConnections(dragIdx);
  gears[dragIdx].x=p.x-dragOX;
  gears[dragIdx].y=p.y-dragOY;
},{passive:false});

C.addEventListener('touchend',function(e){
  if(dragIdx>=0){
    if(isDragging){
      tryAutoMesh(dragIdx);updateHUD();
    }else{
      selectedIdx=dragIdx;updateHUD();
    }
  }else if(!isDragging&&e.changedTouches.length>0){
    var t=e.changedTouches[0];
    var r=C.getBoundingClientRect();
    var p={x:t.clientX-r.left,y:t.clientY-r.top};
    var idx=findGearAt(p.x,p.y);
    if(idx<0){
      var ng=makeGear(p.x,p.y,nextTeeth,gears.length%2);
      gears.push(ng);
      tryAutoMesh(gears.length-1,true);
      selectedIdx=gears.length-1;
      updateHUD();
      if(window.SFX)SFX.play('spawn');
    }
  }
  dragIdx=-1;isDragging=false;touchId=null;
});

var slTeeth=document.getElementById('sl-teeth');
var svTeeth=document.getElementById('sv-teeth');
slTeeth.addEventListener('input',function(){
  nextTeeth=parseInt(this.value);svTeeth.textContent=nextTeeth;
  updateHUD();
});

var slSpeed=document.getElementById('sl-speed');
var svSpeed=document.getElementById('sv-speed');
slSpeed.addEventListener('input',function(){
  speed=parseInt(this.value)/100;svSpeed.textContent=this.value;
});

var btnAuto=document.getElementById('btn-auto');
btnAuto.addEventListener('click',function(){
  autoRotate=!autoRotate;
  this.classList.toggle('active',autoRotate);
  if(window.SFX)SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  initDefault();
  if(window.SFX)SFX.play('click');
});
document.getElementById('btn-target').addEventListener('click',function(){
  newTarget();
  if(window.SFX)SFX.play('toggle');
});

document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    loadPreset(this.dataset.preset);
  });
});

initDefault();
newTarget();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
