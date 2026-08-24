<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('braitenberg');

$pageName = 'braitenberg';
$pageTitle = 'BRAITENBERG - PULSE';
$pageDesc = 'ブライテンベルグ・ビークル。センサーとモーターの単純な配線が恐怖・攻撃・愛着・探索の行動を生む。';
$pageCanonical = 'https://pulse.subara3.com/braitenberg.php';
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
  <div>VEHICLES <span id="hud-cnt">0</span></div>
  <div>LIGHTS <span id="hud-lgt">0</span></div>
  <div>MODE <span id="hud-mode">FEAR</span></div>
</div>
<div id="info-label"><span>BRAITENBERG</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'light'],
  ['type' => 'drag',  'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">TYPE</label></div>
  <div class="btn-row" id="type-btns">
    <button class="ctrl-btn active" data-type="0">FEAR</button>
    <button class="ctrl-btn" data-type="1">AGGRO</button>
    <button class="ctrl-btn" data-type="2">LOVE</button>
    <button class="ctrl-btn" data-type="3">EXPLORE</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">COUNT</label>
    <input type="range" id="sl-count" min="1" max="40" value="10">
    <span class="slider-value" id="sv-count">10</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">RANGE</label>
    <input type="range" id="sl-range" min="80" max="500" value="250">
    <span class="slider-value" id="sv-range">250</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="10" max="200" value="60">
    <span class="slider-value" id="sv-speed">60</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-mixed">MIXED</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;

var lights=[];
var vehicles=[];
var curType=0;
var sensorRange=250;
var baseSpeed=60;
var maxTrail=150;
var lastTime=0;

var VSIZE=13;
var SSPREAD=0.5;
var SDIST=VSIZE*0.9;
var WBASE=VSIZE*0.8;
var GAIN=140;

var TYPES=[
  {name:'FEAR',cross:false,excite:true},
  {name:'AGGRO',cross:true,excite:true},
  {name:'LOVE',cross:false,excite:false},
  {name:'EXPLORE',cross:true,excite:false}
];

var TCOLORS=[
  [0,245,212],
  [0,200,230],
  [80,220,200],
  [0,180,195]
];

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
}
window.addEventListener('resize',resize);
resize();

function makeVehicle(x,y,type){
  return{x:x,y:y,angle:Math.random()*Math.PI*2,type:type,trail:[],ls:0,rs:0};
}

function makeLight(x,y){
  return{x:x,y:y,intensity:1,phase:Math.random()*Math.PI*2};
}

function senseAt(sx,sy){
  var total=0;
  for(var i=0;i<lights.length;i++){
    var dx=lights[i].x-sx,dy=lights[i].y-sy;
    var d=Math.sqrt(dx*dx+dy*dy);
    if(d<sensorRange){
      var f=1-d/sensorRange;
      total+=f*f*lights[i].intensity;
    }
  }
  return total;
}

function updateVehicle(v,dt){
  var la=v.angle-SSPREAD;
  var ra=v.angle+SSPREAD;
  var lsx=v.x+SDIST*Math.cos(la);
  var lsy=v.y+SDIST*Math.sin(la);
  var rsx=v.x+SDIST*Math.cos(ra);
  var rsy=v.y+SDIST*Math.sin(ra);

  v.ls=senseAt(lsx,lsy);
  v.rs=senseAt(rsx,rsy);

  var t=TYPES[v.type];
  var li=t.cross?v.rs:v.ls;
  var ri=t.cross?v.ls:v.rs;

  var lm,rm;
  if(t.excite){
    lm=baseSpeed+GAIN*li;
    rm=baseSpeed+GAIN*ri;
  }else{
    lm=Math.max(3,baseSpeed-GAIN*li);
    rm=Math.max(3,baseSpeed-GAIN*ri);
  }

  var vel=(lm+rm)/2;
  var omega=(lm-rm)/WBASE;

  v.x+=vel*Math.cos(v.angle)*dt;
  v.y+=vel*Math.sin(v.angle)*dt;
  v.angle+=omega*dt;

  if(v.x<-30)v.x=W+30;
  if(v.x>W+30)v.x=-30;
  if(v.y<-30)v.y=H+30;
  if(v.y>H+30)v.y=-30;

  v.trail.push({x:v.x,y:v.y});
  if(v.trail.length>maxTrail)v.trail.shift();
}

function drawLight(l,time){
  var pulse=0.7+0.3*Math.sin(l.phase+time*1.5);
  var r=20*pulse;
  var grad=X.createRadialGradient(l.x,l.y,0,l.x,l.y,sensorRange*0.6);
  grad.addColorStop(0,'rgba(123,44,191,0.12)');
  grad.addColorStop(0.5,'rgba(123,44,191,0.03)');
  grad.addColorStop(1,'rgba(123,44,191,0)');
  X.fillStyle=grad;
  X.beginPath();X.arc(l.x,l.y,sensorRange*0.6,0,Math.PI*2);X.fill();

  var grad2=X.createRadialGradient(l.x,l.y,0,l.x,l.y,r);
  grad2.addColorStop(0,'rgba(180,100,255,0.9)');
  grad2.addColorStop(0.5,'rgba(123,44,191,0.5)');
  grad2.addColorStop(1,'rgba(123,44,191,0)');
  X.fillStyle=grad2;
  X.beginPath();X.arc(l.x,l.y,r,0,Math.PI*2);X.fill();

  X.beginPath();X.arc(l.x,l.y,5,0,Math.PI*2);
  X.fillStyle='rgba(200,140,255,0.8)';X.fill();
}

function drawVehicle(v){
  var tc=TCOLORS[v.type];
  var a=v.angle;

  if(v.trail.length>1){
    X.beginPath();
    X.moveTo(v.trail[0].x,v.trail[0].y);
    for(var i=1;i<v.trail.length;i++){
      var dx=v.trail[i].x-v.trail[i-1].x;
      var dy=v.trail[i].y-v.trail[i-1].y;
      if(dx*dx+dy*dy>2500)continue;
      X.lineTo(v.trail[i].x,v.trail[i].y);
    }
    X.strokeStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+',0.08)';
    X.lineWidth=1.5;X.stroke();
  }

  var la=a-SSPREAD;
  var ra=a+SSPREAD;
  var lsx=v.x+SDIST*Math.cos(la);
  var lsy=v.y+SDIST*Math.sin(la);
  var rsx=v.x+SDIST*Math.cos(ra);
  var rsy=v.y+SDIST*Math.sin(ra);

  var maxS=Math.max(v.ls,v.rs,0.01);
  if(v.ls>0.01){
    X.beginPath();X.arc(lsx,lsy,3+v.ls*4,0,Math.PI*2);
    X.fillStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+','+(0.1+v.ls*0.3)+')';X.fill();
  }
  if(v.rs>0.01){
    X.beginPath();X.arc(rsx,rsy,3+v.rs*4,0,Math.PI*2);
    X.fillStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+','+(0.1+v.rs*0.3)+')';X.fill();
  }

  X.save();
  X.translate(v.x,v.y);
  X.rotate(a);

  X.beginPath();
  X.moveTo(VSIZE,0);
  X.lineTo(-VSIZE*0.6,-VSIZE*0.55);
  X.lineTo(-VSIZE*0.3,0);
  X.lineTo(-VSIZE*0.6,VSIZE*0.55);
  X.closePath();

  var act=Math.min(1,(v.ls+v.rs)*2);
  X.fillStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+','+(0.15+act*0.25)+')';
  X.fill();
  X.strokeStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+','+(0.5+act*0.4)+')';
  X.lineWidth=1.2;X.stroke();

  X.restore();

  X.beginPath();X.arc(lsx,lsy,2.2,0,Math.PI*2);
  X.fillStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+',0.8)';X.fill();
  X.beginPath();X.arc(rsx,rsy,2.2,0,Math.PI*2);
  X.fillStyle='rgba('+tc[0]+','+tc[1]+','+tc[2]+',0.8)';X.fill();
}

function spawnVehicles(count,type){
  vehicles=[];
  for(var i=0;i<count;i++){
    var x=80+Math.random()*(W-160);
    var y=80+Math.random()*(H-160);
    vehicles.push(makeVehicle(x,y,type));
  }
  updateHUD();
}

function spawnMixed(count){
  vehicles=[];
  for(var i=0;i<count;i++){
    var x=80+Math.random()*(W-160);
    var y=80+Math.random()*(H-160);
    vehicles.push(makeVehicle(x,y,i%4));
  }
  updateHUD();
}

function initDefault(){
  lights=[];
  var cx=W/2,cy=H/2;
  var r=Math.min(W,H)*0.25;
  lights.push(makeLight(cx,cy));
  lights.push(makeLight(cx+r*0.9,cy-r*0.5));
  lights.push(makeLight(cx-r*0.8,cy+r*0.4));
  spawnVehicles(10,curType);
}

function updateHUD(){
  document.getElementById('hud-cnt').textContent=vehicles.length;
  document.getElementById('hud-lgt').textContent=lights.length;
  document.getElementById('hud-mode').textContent=TYPES[curType].name;
}

function findLightAt(mx,my){
  for(var i=lights.length-1;i>=0;i--){
    var dx=lights[i].x-mx,dy=lights[i].y-my;
    if(dx*dx+dy*dy<900)return i;
  }
  return-1;
}

function findVehicleAt(mx,my){
  for(var i=vehicles.length-1;i>=0;i--){
    var dx=vehicles[i].x-mx,dy=vehicles[i].y-my;
    if(dx*dx+dy*dy<400)return i;
  }
  return-1;
}

function draw(now){
  if(!lastTime)lastTime=now;
  var dt=Math.min((now-lastTime)/1000,0.05);
  lastTime=now;

  for(var i=0;i<vehicles.length;i++){
    updateVehicle(vehicles[i],dt);
  }

  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  var time=now/1000;
  for(var i=0;i<lights.length;i++){
    drawLight(lights[i],time);
  }
  for(var i=0;i<vehicles.length;i++){
    drawVehicle(vehicles[i]);
  }

  requestAnimationFrame(draw);
}
requestAnimationFrame(draw);

function getPos(e){
  var r=C.getBoundingClientRect();
  if(e.touches&&e.touches.length>0)return{x:e.touches[0].clientX-r.left,y:e.touches[0].clientY-r.top};
  return{x:e.clientX-r.left,y:e.clientY-r.top};
}

var dragIdx=-1,isDragging=false,dragOX=0,dragOY=0;

C.addEventListener('mousedown',function(e){
  if(e.button===2)return;
  var p=getPos(e);
  var idx=findLightAt(p.x,p.y);
  if(idx>=0){
    dragIdx=idx;isDragging=false;
    dragOX=p.x-lights[idx].x;dragOY=p.y-lights[idx].y;
  }else{
    dragIdx=-1;isDragging=false;
  }
});

C.addEventListener('mousemove',function(e){
  if(dragIdx<0)return;
  isDragging=true;
  var p=getPos(e);
  lights[dragIdx].x=p.x-dragOX;
  lights[dragIdx].y=p.y-dragOY;
});

C.addEventListener('mouseup',function(e){
  if(e.button===2){dragIdx=-1;isDragging=false;return;}
  if(dragIdx>=0){
    if(isDragging&&window.SFX)SFX.play('move',100);
    dragIdx=-1;isDragging=false;
    return;
  }
  if(!isDragging){
    var p=getPos(e);
    var li=findLightAt(p.x,p.y);
    if(li<0){
      lights.push(makeLight(p.x,p.y));
      updateHUD();
      if(window.SFX)SFX.play('spawn');
    }
  }
  dragIdx=-1;isDragging=false;
});

C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  var p=getPos(e);
  var li=findLightAt(p.x,p.y);
  if(li>=0){
    lights.splice(li,1);
    updateHUD();
    if(window.SFX)SFX.play('pop');
    return;
  }
  var vi=findVehicleAt(p.x,p.y);
  if(vi>=0){
    vehicles.splice(vi,1);
    updateHUD();
    if(window.SFX)SFX.play('pop');
  }
});

var touchTimer=null,touchStartP=null;
C.addEventListener('touchstart',function(e){
  if(e.touches.length!==1)return;
  var p=getPos(e);
  touchStartP=p;
  var idx=findLightAt(p.x,p.y);
  if(idx>=0){
    dragIdx=idx;isDragging=false;
    dragOX=p.x-lights[idx].x;dragOY=p.y-lights[idx].y;
    touchTimer=setTimeout(function(){
      lights.splice(dragIdx,1);
      dragIdx=-1;
      updateHUD();
      if(window.SFX)SFX.play('pop');
      touchTimer=null;
    },600);
  }else{
    dragIdx=-1;
  }
},{passive:true});

C.addEventListener('touchmove',function(e){
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
  if(dragIdx<0)return;
  e.preventDefault();
  isDragging=true;
  var p=getPos(e);
  lights[dragIdx].x=p.x-dragOX;
  lights[dragIdx].y=p.y-dragOY;
},{passive:false});

C.addEventListener('touchend',function(e){
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
  if(dragIdx>=0){
    if(isDragging&&window.SFX)SFX.play('move',100);
    dragIdx=-1;isDragging=false;
    return;
  }
  if(!isDragging&&touchStartP){
    var li=findLightAt(touchStartP.x,touchStartP.y);
    if(li<0){
      lights.push(makeLight(touchStartP.x,touchStartP.y));
      updateHUD();
      if(window.SFX)SFX.play('spawn');
    }
  }
  dragIdx=-1;isDragging=false;touchStartP=null;
});

document.querySelectorAll('#type-btns .ctrl-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.querySelectorAll('#type-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
    this.classList.add('active');
    curType=parseInt(this.dataset.type);
    var count=parseInt(document.getElementById('sl-count').value);
    spawnVehicles(count,curType);
    if(window.SFX)SFX.play('toggle');
  });
});

var slCount=document.getElementById('sl-count');
var svCount=document.getElementById('sv-count');
slCount.addEventListener('input',function(){
  var n=parseInt(this.value);
  svCount.textContent=n;
  while(vehicles.length<n)vehicles.push(makeVehicle(80+Math.random()*(W-160),80+Math.random()*(H-160),curType));
  while(vehicles.length>n)vehicles.pop();
  updateHUD();
});

var slRange=document.getElementById('sl-range');
var svRange=document.getElementById('sv-range');
slRange.addEventListener('input',function(){
  sensorRange=parseInt(this.value);
  svRange.textContent=this.value;
});

var slSpeed=document.getElementById('sl-speed');
var svSpeed=document.getElementById('sv-speed');
slSpeed.addEventListener('input',function(){
  baseSpeed=parseInt(this.value);
  svSpeed.textContent=this.value;
});

document.getElementById('btn-mixed').addEventListener('click',function(){
  document.querySelectorAll('#type-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
  var count=parseInt(slCount.value);
  spawnMixed(count);
  document.getElementById('hud-mode').textContent='MIXED';
  if(window.SFX)SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  document.querySelectorAll('#type-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
  document.querySelector('#type-btns .ctrl-btn[data-type="0"]').classList.add('active');
  curType=0;
  slCount.value=10;svCount.textContent='10';
  slRange.value=250;svRange.textContent='250';
  slSpeed.value=60;svSpeed.textContent='60';
  baseSpeed=60;sensorRange=250;
  initDefault();
  if(window.SFX)SFX.play('click');
});

initDefault();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
