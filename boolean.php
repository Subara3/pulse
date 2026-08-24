<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('boolean');

$pageName = 'boolean';
$pageTitle = 'BOOLEAN - PULSE';
$pageDesc = 'ランダムブールネットワーク。N個のノードがブール関数で制御し合い、K=2付近で秩序とカオスの相転移が現れる。';
$pageCanonical = 'https://pulse.subara3.com/boolean.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>STEP <span id="hud-step">0</span></div>
  <div>NODES <span id="hud-n">16</span></div>
  <div>K <span id="hud-k">2</span></div>
  <div>CYCLE <span id="hud-cycle">---</span></div>
</div>
<div id="info-label"><span>BOOLEAN NETWORK</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'toggle'],
  ['type' => 'right', 'label' => 'scramble'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">INPUTS (K)</label></div>
  <div class="btn-row" id="k-btns">
    <button class="ctrl-btn" data-k="1">1</button>
    <button class="ctrl-btn active" data-k="2">2</button>
    <button class="ctrl-btn" data-k="3">3</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">NODES</label>
    <input type="range" id="sl-n" min="8" max="32" value="16">
    <span class="slider-value" id="sv-n">16</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="20" value="5">
    <span class="slider-value" id="sv-speed">5</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-step">STEP</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;

var n=16,k=2;
var state=[],prevState=[];
var inputs=[];
var funcs=[];
var brightness=[];
var positions=[];

var history=[];
var stateMap=new Map();
var stepCount=0;
var cycleLen=-1;
var running=true;
var speed=5;
var accumulator=0;
var lastTime=0;
var rotAngle=0;

var timelineH=0;
var netCX=0,netCY=0,netR=0;
var nodeR=8;
var cellW=3;
var MAX_CYCLE_SEARCH=5000;

var hudStep=document.getElementById('hud-step');
var hudN=document.getElementById('hud-n');
var hudK=document.getElementById('hud-k');
var hudCycle=document.getElementById('hud-cycle');

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;
  H=window.innerHeight;
  C.width=W*dpr;
  C.height=H*dpr;
  C.style.width=W+'px';
  C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
  timelineH=Math.min(H*0.25,180);
  netCX=W/2;
  netCY=(H-timelineH)*0.48;
  netR=Math.min(W*0.38,(H-timelineH)*0.38);
  nodeR=W<600?11:8;
  updatePositions();
}

function updatePositions(){
  positions=[];
  for(var i=0;i<n;i++){
    var a=(i/n)*Math.PI*2-Math.PI/2+rotAngle;
    positions.push([netCX+netR*Math.cos(a),netCY+netR*Math.sin(a)]);
  }
}

function initNetwork(){
  state=[];prevState=[];inputs=[];funcs=[];brightness=[];
  history=[];stateMap=new Map();stepCount=0;cycleLen=-1;

  for(var i=0;i<n;i++){
    state.push(Math.random()<0.5?1:0);
    brightness.push(state[i]);
    var inp=[];
    while(inp.length<k){
      var j=Math.floor(Math.random()*n);
      if(inp.indexOf(j)===-1)inp.push(j);
    }
    inputs.push(inp);
    var tSize=1<<k;
    var table=[];
    for(var j=0;j<tSize;j++) table.push(Math.random()<0.5?1:0);
    funcs.push(table);
  }
  prevState=state.slice();
  history.push(state.slice());
  stateMap.set(stateKey(),0);
  updatePositions();
  updateHUD();
}

function stateKey(){
  var s='';
  for(var i=0;i<n;i++) s+=state[i];
  return s;
}

function doStep(){
  if(cycleLen>=0) return;
  prevState=state.slice();
  var ns=new Array(n);
  for(var i=0;i<n;i++){
    var idx=0;
    for(var j=0;j<k;j++){
      if(state[inputs[i][j]]) idx|=(1<<j);
    }
    ns[i]=funcs[i][idx];
  }
  state=ns;
  stepCount++;
  history.push(state.slice());

  if(stepCount<=MAX_CYCLE_SEARCH){
    var key=stateKey();
    if(stateMap.has(key)){
      cycleLen=stepCount-stateMap.get(key);
      if(window.SFX) SFX.play('complete');
    } else {
      stateMap.set(key,stepCount);
    }
  } else if(stepCount===MAX_CYCLE_SEARCH+1){
    stateMap.clear();
  }

  if(history.length>4000) history=history.slice(-2000);
  if(window.SFX) SFX.play('tick',200);
  updateHUD();
}

function updateHUD(){
  hudStep.textContent=stepCount;
  hudN.textContent=n;
  hudK.textContent=k;
  hudCycle.textContent=cycleLen>=0?cycleLen:(stepCount>MAX_CYCLE_SEARCH?'>'+MAX_CYCLE_SEARCH:'---');
}

function draw(){
  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  for(var i=0;i<n;i++){
    brightness[i]+=(state[i]-brightness[i])*0.13;
  }

  rotAngle+=0.0003;
  updatePositions();

  drawEdges();
  drawNodes();
  drawSeparator();
  drawTimeline();
}

function drawEdges(){
  for(var i=0;i<n;i++){
    var x2=positions[i][0],y2=positions[i][1];
    for(var j=0;j<k;j++){
      var src=inputs[i][j];
      var x1=positions[src][0],y1=positions[src][1];
      var b=brightness[src];

      var mx=(x1+x2)/2,my=(y1+y2)/2;
      var cpx=mx+(netCX-mx)*0.45;
      var cpy=my+(netCY-my)*0.45;

      var alpha=b>0.5?0.08+b*0.1:0.03;
      if(b>0.5){
        X.strokeStyle='rgba(0,245,212,'+alpha+')';
        X.lineWidth=1.0+b*0.5;
      } else {
        X.strokeStyle='rgba(123,44,191,'+alpha+')';
        X.lineWidth=0.5;
      }
      X.beginPath();
      X.moveTo(x1,y1);
      X.quadraticCurveTo(cpx,cpy,x2,y2);
      X.stroke();
    }
  }
}

function drawNodes(){
  for(var i=0;i<n;i++){
    var x=positions[i][0],y=positions[i][1];
    var b=brightness[i];

    X.save();

    if(b>0.15){
      X.shadowColor='rgba(0,245,212,'+(b*0.7)+')';
      X.shadowBlur=6+b*16;
    }

    var r=Math.round(123+(0-123)*b);
    var g=Math.round(44+(245-44)*b);
    var bl=Math.round(191+(212-191)*b);
    var alpha=0.2+b*0.8;

    X.fillStyle='rgba('+r+','+g+','+bl+','+alpha+')';
    X.beginPath();
    X.arc(x,y,nodeR,0,Math.PI*2);
    X.fill();

    X.shadowBlur=0;
    X.strokeStyle=b>0.5?'rgba(0,245,212,'+(0.2+b*0.4)+')':'rgba(123,44,191,0.12)';
    X.lineWidth=1;
    X.stroke();

    X.restore();
  }
}

function drawSeparator(){
  var tY=H-timelineH;
  X.strokeStyle='rgba(0,245,212,0.12)';
  X.lineWidth=1;
  X.beginPath();
  X.moveTo(0,tY);
  X.lineTo(W,tY);
  X.stroke();
}

function drawTimeline(){
  var tY=H-timelineH;
  var maxCols=Math.floor(W/cellW);
  var visible=Math.min(history.length,maxCols);
  var startIdx=history.length-visible;
  var cellH=timelineH/n;

  X.fillStyle='rgba(5,5,10,0.85)';
  X.fillRect(0,tY,W,timelineH);

  for(var s=0;s<visible;s++){
    var col=startIdx+s;
    var isLast=(s===visible-1);
    for(var i=0;i<n;i++){
      if(history[col][i]){
        X.fillStyle=isLast?'rgba(0,245,212,0.9)':'rgba(0,245,212,0.55)';
        X.fillRect(s*cellW,tY+i*cellH,cellW,cellH);
      }
    }
  }

  if(cycleLen>0 && visible>cycleLen){
    var cycleStartX=(visible-cycleLen)*cellW;
    X.strokeStyle='rgba(0,245,212,0.3)';
    X.lineWidth=1;
    X.setLineDash([4,4]);
    X.beginPath();
    X.moveTo(cycleStartX,tY);
    X.lineTo(cycleStartX,tY+timelineH);
    X.stroke();
    X.setLineDash([]);

    X.font='9px Space Grotesk,monospace';
    X.fillStyle='rgba(0,245,212,0.5)';
    X.fillText('CYCLE',cycleStartX+4,tY+12);
  }
}

function getNodeAt(mx,my){
  var best=-1,bestD=Infinity;
  var maxD=(nodeR+16)*(nodeR+16);
  for(var i=0;i<n;i++){
    var dx=mx-positions[i][0],dy=my-positions[i][1];
    var d=dx*dx+dy*dy;
    if(d<bestD){bestD=d;best=i;}
  }
  return bestD<maxD?best:-1;
}

function toggleNode(idx){
  state[idx]=1-state[idx];
  brightness[idx]=state[idx];
  stateMap=new Map();
  stateMap.set(stateKey(),stepCount);
  cycleLen=-1;
  updateHUD();
  if(window.SFX) SFX.play('toggle');
}

function scrambleState(){
  for(var i=0;i<n;i++){
    state[i]=Math.random()<0.5?1:0;
    brightness[i]=state[i];
  }
  prevState=state.slice();
  history=[state.slice()];
  stateMap=new Map();
  stateMap.set(stateKey(),0);
  stepCount=0;
  cycleLen=-1;
  updateHUD();
  if(window.SFX) SFX.play('spawn');
}

C.addEventListener('click',function(e){
  var rect=C.getBoundingClientRect();
  var idx=getNodeAt(e.clientX-rect.left,e.clientY-rect.top);
  if(idx>=0) toggleNode(idx);
});

C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  scrambleState();
});

var touchId=null;
C.addEventListener('touchstart',function(e){
  if(e.touches.length===1){
    var t=e.touches[0];
    var rect=C.getBoundingClientRect();
    var idx=getNodeAt(t.clientX-rect.left,t.clientY-rect.top);
    if(idx>=0){
      e.preventDefault();
      touchId=t.identifier;
      toggleNode(idx);
    }
  }
},{passive:false});

C.addEventListener('touchend',function(e){
  touchId=null;
});

document.getElementById('k-btns').addEventListener('click',function(e){
  var btn=e.target.closest('[data-k]');
  if(!btn) return;
  k=parseInt(btn.dataset.k);
  var btns=document.querySelectorAll('#k-btns .ctrl-btn');
  for(var i=0;i<btns.length;i++) btns[i].classList.toggle('active',btns[i]===btn);
  initNetwork();
  if(window.SFX) SFX.play('toggle');
});

var slN=document.getElementById('sl-n'),svN=document.getElementById('sv-n');
slN.addEventListener('input',function(){
  n=parseInt(slN.value);
  svN.textContent=n;
  initNetwork();
});

var slSpd=document.getElementById('sl-speed'),svSpd=document.getElementById('sv-speed');
slSpd.addEventListener('input',function(){
  speed=parseInt(slSpd.value);
  svSpd.textContent=speed;
});

document.getElementById('btn-step').addEventListener('click',function(){
  if(cycleLen>=0){
    scrambleState();
  } else {
    doStep();
  }
  if(window.SFX) SFX.play('tick');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  initNetwork();
  if(window.SFX) SFX.play('click');
});

function tick(now){
  if(!lastTime) lastTime=now;
  var dt=now-lastTime;
  lastTime=now;

  if(running && speed>0 && cycleLen<0){
    accumulator+=dt;
    var interval=1000/speed;
    var steps=0;
    while(accumulator>=interval && cycleLen<0 && steps<3){
      doStep();
      accumulator-=interval;
      steps++;
    }
    if(accumulator>interval) accumulator=interval;
  }

  draw();
  requestAnimationFrame(tick);
}

window.addEventListener('resize',resize);
resize();
initNetwork();
requestAnimationFrame(tick);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
