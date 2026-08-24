<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('qlearn');

$pageName = 'qlearn';
$pageTitle = 'QLEARN - PULSE';
$pageDesc = 'Q学習。エージェントが試行錯誤から最適経路を学ぶ。価値関数のヒートマップが報酬源からゆっくり広がる。';
$pageCanonical = 'https://pulse.subara3.com/qlearn.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
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
  EP <span class="v" id="hud-ep">0</span><br>
  STEPS <span class="v" id="hud-st">0</span><br>
  &epsilon; = <span class="v" id="hud-eps">0.30</span>
</div>

<div id="info">
  <span class="v">Q-Learning</span><br>
  Q(s,a) &larr; Q + &alpha;[r + &gamma; max Q&prime; &minus; Q]
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'wall'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'goal'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">EPSILON</span>
      <input type="range" id="sl-eps" min="0" max="100" value="30">
      <span class="slider-value" id="sv-eps">0.30</span>
    </div>
    <div class="slider-row">
      <span class="slider-label">ALPHA</span>
      <input type="range" id="sl-alpha" min="1" max="100" value="15">
      <span class="slider-value" id="sv-alpha">0.15</span>
    </div>
    <div class="slider-row">
      <span class="slider-label">GAMMA</span>
      <input type="range" id="sl-gamma" min="0" max="99" value="95">
      <span class="slider-value" id="sv-gamma">0.95</span>
    </div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="50" value="10">
      <span class="slider-value" id="sv-speed">10</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-multi">MULTI</button>
      <button class="control-btn" id="btn-clear">CLEAR</button>
      <button class="control-btn" id="btn-reset">RESET Q</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;

var CELL,cols,rows,ox,oy;
var Q,walls,goalR,goalC,vmap;

var epsilon=0.30,alpha=0.15,gamma=0.95,speed=10;
var agents=[],nAgents=5,episodes=0,totalSteps=0;
var DR=[-1,0,1,0],DC=[0,1,0,-1];
var sparks=[],qMaxS=1;
var mDown=false,drawVal=-1,lastCell=null,longTimer=null,hoverCell=null;

var hudEp=document.getElementById('hud-ep');
var hudSt=document.getElementById('hud-st');
var hudEps=document.getElementById('hud-eps');

function resize(){
  dpr=Math.min(devicePixelRatio||1,2);
  W=innerWidth;H=innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
}

function initGrid(){
  CELL=Math.max(18,Math.min(36,Math.floor(Math.min(W/24,H/16))));
  cols=Math.max(8,Math.min(40,Math.floor((W-20)/CELL)));
  rows=Math.max(6,Math.min(25,Math.floor((H-60)/CELL)));
  ox=Math.floor((W-cols*CELL)/2);
  oy=Math.floor((H-rows*CELL)/2);
  Q=new Float64Array(rows*cols*4);
  walls=new Uint8Array(rows*cols);
  vmap=new Float64Array(rows*cols);
  goalR=rows-2;goalC=cols-2;

  var r1=Math.floor(rows*0.33),r2=Math.floor(rows*0.66);
  for(var c=1;c<=Math.floor(cols*0.72);c++) walls[r1*cols+c]=1;
  for(var c=Math.floor(cols*0.28);c<cols-1;c++) walls[r2*cols+c]=1;

  agents=[];
  for(var i=0;i<nAgents;i++) agents.push(mkAgent());
  episodes=0;totalSteps=0;
}

function mkAgent(){
  var a={r:0,c:0,steps:0,trail:[]};
  placeA(a);return a;
}

function placeA(a){
  for(var t=0;t<500;t++){
    a.r=Math.random()*rows|0;a.c=Math.random()*cols|0;
    if(!walls[a.r*cols+a.c]&&!(a.r===goalR&&a.c===goalC))break;
  }
  a.steps=0;a.trail=[];
}

function qi(r,c,a){return(r*cols+c)*4+a;}

function maxQV(r,c){
  var b=-1e9;
  for(var a=0;a<4;a++){var v=Q[qi(r,c,a)];if(v>b)b=v;}
  return b;
}

function bestA(r,c){
  var b=-1e9,ba=0;
  for(var a=0;a<4;a++){var v=Q[qi(r,c,a)];if(v>b){b=v;ba=a;}}
  return ba;
}

function stepA(ag){
  var r=ag.r,c=ag.c;
  var a=Math.random()<epsilon?(Math.random()*4|0):bestA(r,c);
  var nr=r+DR[a],nc=c+DC[a],rw=-1;
  if(nr<0||nr>=rows||nc<0||nc>=cols||walls[nr*cols+nc]){nr=r;nc=c;rw=-2;}
  var done=nr===goalR&&nc===goalC;
  if(done)rw=100;
  var idx=qi(r,c,a);
  Q[idx]+=alpha*(rw+(done?0:gamma*maxQV(nr,nc))-Q[idx]);
  ag.r=nr;ag.c=nc;ag.steps++;totalSteps++;
  ag.trail.push({x:ox+nc*CELL+CELL/2,y:oy+nr*CELL+CELL/2,age:0});
  if(ag.trail.length>18)ag.trail.shift();
  if(done){
    episodes++;
    addSpark(ox+nc*CELL+CELL/2,oy+nr*CELL+CELL/2);
    if(typeof SFX!=='undefined')SFX.play('success',300);
    placeA(ag);
  }else if(ag.steps>rows*cols*3){placeA(ag);}
}

function addSpark(x,y){
  for(var i=0;i<8;i++){
    var a=Math.PI*2*i/8;
    sparks.push({x:x,y:y,vx:Math.cos(a)*(1+Math.random()*2),vy:Math.sin(a)*(1+Math.random()*2),life:25+Math.random()*15});
  }
}

function cellAt(px,py){
  var c=Math.floor((px-ox)/CELL),r=Math.floor((py-oy)/CELL);
  return(r>=0&&r<rows&&c>=0&&c<cols)?{r:r,c:c}:null;
}

function setWall(r,c,v){if(!(r===goalR&&c===goalC))walls[r*cols+c]=v;}

function moveGoal(r,c){
  walls[r*cols+c]=0;goalR=r;goalC=c;
  Q.fill(0);vmap.fill(0);episodes=0;totalSteps=0;
  for(var i=0;i<agents.length;i++)placeA(agents[i]);
  if(typeof SFX!=='undefined')SFX.play('toggle');
}

C.addEventListener('contextmenu',function(e){e.preventDefault();});

C.addEventListener('mousedown',function(e){
  if(e.button===2){var cell=cellAt(e.clientX,e.clientY);if(cell)moveGoal(cell.r,cell.c);return;}
  mDown=true;
  var cell=cellAt(e.clientX,e.clientY);
  if(cell){drawVal=walls[cell.r*cols+cell.c]?0:1;setWall(cell.r,cell.c,drawVal);lastCell=cell;if(typeof SFX!=='undefined')SFX.play('tick');}
});

C.addEventListener('mousemove',function(e){
  hoverCell=cellAt(e.clientX,e.clientY);
  if(!mDown||drawVal<0)return;
  var cell=cellAt(e.clientX,e.clientY);
  if(cell&&(!lastCell||cell.r!==lastCell.r||cell.c!==lastCell.c)){
    setWall(cell.r,cell.c,drawVal);lastCell=cell;if(typeof SFX!=='undefined')SFX.play('tick',50);
  }
});

window.addEventListener('mouseup',function(){mDown=false;drawVal=-1;lastCell=null;});
C.addEventListener('mouseleave',function(){hoverCell=null;});

C.addEventListener('touchstart',function(e){
  if(e.touches.length!==1)return;
  var t=e.touches[0],cell=cellAt(t.clientX,t.clientY);
  if(!cell)return;
  e.preventDefault();
  var cr=cell.r,cc=cell.c;
  longTimer=setTimeout(function(){moveGoal(cr,cc);longTimer=null;drawVal=-1;},500);
  mDown=true;drawVal=walls[cell.r*cols+cell.c]?0:1;
  setWall(cell.r,cell.c,drawVal);lastCell=cell;
  if(typeof SFX!=='undefined')SFX.play('tick');
},{passive:false});

C.addEventListener('touchmove',function(e){
  if(longTimer){clearTimeout(longTimer);longTimer=null;}
  if(!mDown||drawVal<0)return;
  var t=e.touches[0],cell=cellAt(t.clientX,t.clientY);
  if(cell&&(!lastCell||cell.r!==lastCell.r||cell.c!==lastCell.c)){
    setWall(cell.r,cell.c,drawVal);lastCell=cell;if(typeof SFX!=='undefined')SFX.play('tick',50);
  }
  e.preventDefault();
},{passive:false});

C.addEventListener('touchend',function(){
  if(longTimer){clearTimeout(longTimer);longTimer=null;}
  mDown=false;drawVal=-1;lastCell=null;
});

function render(){
  X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);

  var qMax=1;
  for(var r=0;r<rows;r++)for(var c=0;c<cols;c++){
    var mq=maxQV(r,c);if(mq>qMax)qMax=mq;
  }
  qMaxS+=(qMax-qMaxS)*0.05;if(qMaxS<1)qMaxS=1;
  for(var r=0;r<rows;r++)for(var c=0;c<cols;c++){
    var idx=r*cols+c;
    var tgt=Math.max(0,maxQV(r,c))/qMaxS;
    vmap[idx]+=(tgt-vmap[idx])*0.06;
  }

  var g=1;
  for(var r=0;r<rows;r++)for(var c=0;c<cols;c++){
    var x=ox+c*CELL+g,y=oy+r*CELL+g,s=CELL-g*2,idx=r*cols+c;
    if(walls[idx]){
      X.fillStyle='rgba(123,44,191,0.2)';X.fillRect(x,y,s,s);
      X.strokeStyle='rgba(123,44,191,0.4)';X.lineWidth=1;X.strokeRect(x+.5,y+.5,s-1,s-1);
    }else if(r===goalR&&c===goalC){
      var p=0.35+0.2*Math.sin(Date.now()*0.004);
      X.fillStyle='rgba(0,245,212,'+p+')';X.fillRect(x,y,s,s);
      X.save();X.shadowColor='#00f5d4';X.shadowBlur=15;
      X.fillRect(x,y,s,s);X.restore();
    }else{
      var v=vmap[idx];
      if(v>0.005){X.fillStyle='rgba(0,245,212,'+(v*0.35)+')';X.fillRect(x,y,s,s);}
      X.strokeStyle='rgba(255,255,255,0.025)';X.lineWidth=.5;X.strokeRect(x+.5,y+.5,s-1,s-1);
    }
  }

  if(hoverCell&&!mDown){
    var hx=ox+hoverCell.c*CELL+g,hy=oy+hoverCell.r*CELL+g;
    X.strokeStyle='rgba(0,245,212,0.25)';X.lineWidth=1.5;
    X.strokeRect(hx,hy,CELL-g*2,CELL-g*2);
  }

  X.lineCap='round';X.lineWidth=1;
  for(var r=0;r<rows;r++)for(var c=0;c<cols;c++){
    if(walls[r*cols+c]||(r===goalR&&c===goalC))continue;
    var v=vmap[r*cols+c];if(v<0.015)continue;
    var a=bestA(r,c);
    var cx=ox+c*CELL+CELL/2,cy=oy+r*CELL+CELL/2;
    var len=CELL*.22,dx=DC[a],dy=DR[a];
    var op=Math.min(0.45,v*0.55);
    X.strokeStyle='rgba(0,245,212,'+op+')';
    X.beginPath();X.moveTo(cx-dx*len,cy-dy*len);X.lineTo(cx+dx*len,cy+dy*len);X.stroke();
    var ax=cx+dx*len,ay=cy+dy*len,hl=CELL*.1;
    X.beginPath();
    X.moveTo(ax,ay);X.lineTo(ax-dx*hl+dy*hl,ay-dy*hl-dx*hl);
    X.moveTo(ax,ay);X.lineTo(ax-dx*hl-dy*hl,ay-dy*hl+dx*hl);
    X.stroke();
  }

  for(var i=0;i<agents.length;i++){
    var ag=agents[i];
    for(var j=ag.trail.length-1;j>=0;j--){
      var tr=ag.trail[j];tr.age++;
      if(tr.age>28){ag.trail.splice(j,1);continue;}
      X.fillStyle='rgba(0,245,212,'+(1-tr.age/28)*0.1+')';
      X.beginPath();X.arc(tr.x,tr.y,2,0,Math.PI*2);X.fill();
    }
  }

  for(var i=0;i<agents.length;i++){
    var ag=agents[i],ax=ox+ag.c*CELL+CELL/2,ay=oy+ag.r*CELL+CELL/2;
    X.fillStyle='rgba(0,245,212,0.25)';X.beginPath();X.arc(ax,ay,5,0,Math.PI*2);X.fill();
    X.fillStyle='rgba(255,255,255,0.85)';X.beginPath();X.arc(ax,ay,2.5,0,Math.PI*2);X.fill();
  }

  for(var i=sparks.length-1;i>=0;i--){
    var sp=sparks[i];sp.x+=sp.vx;sp.y+=sp.vy;sp.vx*=.95;sp.vy*=.95;sp.life--;
    if(sp.life<=0){sparks.splice(i,1);continue;}
    X.fillStyle='rgba(0,245,212,'+(sp.life/40)+')';
    X.beginPath();X.arc(sp.x,sp.y,1.5,0,Math.PI*2);X.fill();
  }

  hudEp.textContent=episodes;
  hudSt.textContent=totalSteps>99999?(totalSteps/1000|0)+'k':totalSteps;
}

function update(){
  for(var s=0;s<speed;s++)for(var i=0;i<agents.length;i++)stepA(agents[i]);
}

function bind(sid,vid,fn){
  var sl=document.getElementById(sid),sv=document.getElementById(vid);
  sl.addEventListener('input',function(){fn(sl,sv);});
}
bind('sl-eps','sv-eps',function(s,v){epsilon=s.value/100;v.textContent=epsilon.toFixed(2);hudEps.textContent=epsilon.toFixed(2);});
bind('sl-alpha','sv-alpha',function(s,v){alpha=s.value/100;v.textContent=alpha.toFixed(2);});
bind('sl-gamma','sv-gamma',function(s,v){gamma=s.value/100;v.textContent=gamma.toFixed(2);});
bind('sl-speed','sv-speed',function(s,v){speed=parseInt(s.value);v.textContent=speed;});

document.getElementById('btn-clear').addEventListener('click',function(){
  walls.fill(0);if(typeof SFX!=='undefined')SFX.play('click');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  Q.fill(0);vmap.fill(0);episodes=0;totalSteps=0;
  for(var i=0;i<agents.length;i++)placeA(agents[i]);
  if(typeof SFX!=='undefined')SFX.play('click');
});

var multiBtn=document.getElementById('btn-multi');
multiBtn.addEventListener('click',function(){
  if(nAgents===1){nAgents=5;multiBtn.classList.add('active');}
  else{nAgents=1;multiBtn.classList.remove('active');}
  agents=[];for(var i=0;i<nAgents;i++)agents.push(mkAgent());
  if(typeof SFX!=='undefined')SFX.play('toggle');
});

resize();initGrid();
window.addEventListener('resize',function(){
  resize();
  CELL=Math.max(18,Math.min(36,Math.floor(Math.min(W/cols,H/rows)*0.9)));
  ox=Math.floor((W-cols*CELL)/2);oy=Math.floor((H-rows*CELL)/2);
});

(function loop(){update();render();requestAnimationFrame(loop);})();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
