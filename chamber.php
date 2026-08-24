<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('chamber');

$pageName = 'chamber';
$pageTitle = 'CHAMBER - PULSE';
$pageDesc = '荷電粒子の飛跡。磁場中を螺旋する粒子が減速し、崩壊で枝分かれする。泡箱の再現。';
$pageCanonical = 'https://pulse.subara3.com/chamber.php';

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
  <span class="v" id="hud-n">0</span> TRACKS<br>
  B = <span class="v" id="hud-b">1.0</span> T<br>
  <span class="v" id="hud-m">e&#x207B;</span>
</div>

<div id="info">
  <span class="v">Bubble Chamber</span><br>
  Lorentz force &middot; ionization loss &middot; decay
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'shoot'],
  ['type' => 'drag', 'label' => 'aim'],
  ['type' => 'right', 'label' => 'event'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">B FIELD</span>
      <input type="range" id="sl-b" min="1" max="30" value="10">
      <span class="slider-value" id="sv-b">1.0</span>
    </div>
    <div class="slider-row">
      <span class="slider-label">LOSS</span>
      <input type="range" id="sl-l" min="0" max="20" value="6">
      <span class="slider-value" id="sv-l">0.6</span>
    </div>
  </div>
  <div class="preset-group" id="type-btns">
    <button class="preset-btn active" data-t="electron">e&#x207B;</button>
    <button class="preset-btn" data-t="positron">e&#x207A;</button>
    <button class="preset-btn" data-t="muon">&#x03BC;&#x207B;</button>
    <button class="preset-btn" data-t="pion">&#x03C0;&#x207A;</button>
    <button class="preset-btn" data-t="proton">p</button>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-auto">AUTO</button>
      <button class="control-btn" id="btn-event">EVENT</button>
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;
var acc,aX;

var BSCALE=0.06,LSCALE=0.004;

var TYPES={
  electron:  {sym:'e⁻',q:-1,m:1, stable:true, h:270},
  positron:  {sym:'e⁺',q:1, m:1, stable:true, h:175},
  muon:      {sym:'μ⁻',q:-1,m:6, stable:false,h:258,life:280,to:['electron']},
  antimuon:  {sym:'μ⁺',q:1, m:6, stable:false,h:188,life:280,to:['positron']},
  pion:      {sym:'π⁺',q:1, m:10,stable:false,h:180,life:160,to:['antimuon']},
  antipion:  {sym:'π⁻',q:-1,m:10,stable:false,h:268,life:160,to:['muon']},
  proton:    {sym:'p',q:1, m:50,stable:true, h:170},
  gamma:     {sym:'γ',q:0, m:1, stable:false,h:0,  life:100,to:['electron','positron']}
};

var particles=[],flashes=[];
var selType='electron',bField=1.0,lossRate=0.6;
var autoOn=true,autoT=0,totalTracks=0;
var drag=null;

var hudN=document.getElementById('hud-n');
var hudB=document.getElementById('hud-b');
var hudM=document.getElementById('hud-m');

function resize(){
  dpr=Math.min(window.devicePixelRatio||1,2);
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
  acc=document.createElement('canvas');
  aX=acc.getContext('2d');
  acc.width=W*dpr;acc.height=H*dpr;
  aX.setTransform(dpr,0,0,dpr,0,0);
  aX.fillStyle='#0a0a0f';
  aX.fillRect(0,0,W,H);
}

function Pt(x,y,vx,vy,tn){
  var t=TYPES[tn];
  this.x=x;this.y=y;this.px=x;this.py=y;
  this.vx=vx;this.vy=vy;
  this.tn=tn;this.q=t.q;this.m=t.m;this.h=t.h;
  this.stable=t.stable;this.to=t.to||null;
  this.age=0;this.alive=true;
  this.decayAt=t.stable?1e9:(t.life||200)*(0.5+Math.random()*1.0);
  if(t.h===0){
    this.sc='rgba(160,165,180,0.5)';
    this.gc='rgba(160,165,180,0.06)';
    this.hc='rgba(200,205,220,0.8)';
  }else{
    this.sc='hsla('+t.h+',80%,60%,0.7)';
    this.gc='hsla('+t.h+',85%,50%,0.1)';
    this.hc='hsla('+t.h+',100%,75%,0.9)';
  }
}

Pt.prototype.step=function(){
  if(!this.alive)return;
  this.px=this.x;this.py=this.y;

  if(this.q!==0){
    var w=this.q*bField*BSCALE/this.m;
    var c=Math.cos(w),s=Math.sin(w);
    var nvx=this.vx*c+this.vy*s;
    var nvy=-this.vx*s+this.vy*c;
    this.vx=nvx;this.vy=nvy;
  }

  var d=1-lossRate*LSCALE;
  this.vx*=d;this.vy*=d;

  this.x+=this.vx;this.y+=this.vy;
  this.age++;

  if(!this.stable&&this.age>=this.decayAt){this.doDecay();return;}
  if(this.vx*this.vx+this.vy*this.vy<0.01){this.alive=false;return;}
  var mg=200;
  if(this.x<-mg||this.x>W+mg||this.y<-mg||this.y>H+mg)this.alive=false;
};

Pt.prototype.doDecay=function(){
  this.alive=false;
  if(!this.to)return;
  addFlash(this.x,this.y);
  if(typeof SFX!=='undefined')SFX.play('pop',80);
  var spd=Math.sqrt(this.vx*this.vx+this.vy*this.vy);
  var a=Math.atan2(this.vy,this.vx);
  var n=this.to.length;
  for(var i=0;i<n;i++){
    var sp=(i-(n-1)/2)*0.3+(Math.random()-0.5)*0.1;
    var sv=spd*(0.5+Math.random()*0.5);
    addPt(this.x,this.y,Math.cos(a+sp)*sv,Math.sin(a+sp)*sv,this.to[i]);
  }
};

function addPt(x,y,vx,vy,tn){
  if(particles.length<120){
    particles.push(new Pt(x,y,vx,vy,tn));
    totalTracks++;
  }
}

function addFlash(x,y){
  flashes.push({x:x,y:y,age:0,max:25});
}

function collisionEvent(x,y){
  var ts=['electron','positron','muon','antimuon','pion','antipion','proton','gamma'];
  var n=4+Math.floor(Math.random()*5);
  for(var i=0;i<n;i++){
    var a=Math.PI*2*i/n+(Math.random()-0.5)*0.5;
    var v=1.5+Math.random()*3;
    addPt(x,y,Math.cos(a)*v,Math.sin(a)*v,ts[Math.floor(Math.random()*ts.length)]);
  }
  addFlash(x,y);
  if(typeof SFX!=='undefined')SFX.play('spawn');
}

function shoot(x,y,vx,vy){
  addPt(x,y,vx,vy,selType);
  if(typeof SFX!=='undefined')SFX.play('spawn');
}

function update(){
  for(var i=particles.length-1;i>=0;i--){
    particles[i].step();
    if(!particles[i].alive)particles.splice(i,1);
  }
  for(var i=flashes.length-1;i>=0;i--){
    flashes[i].age++;
    if(flashes[i].age>=flashes[i].max)flashes.splice(i,1);
  }
  if(autoOn){
    autoT++;
    if(autoT%50===0){
      var side=Math.floor(Math.random()*4);
      var x,y,spd=2+Math.random()*2.5,vx,vy;
      switch(side){
        case 0:x=0;y=Math.random()*H;vx=spd;vy=(Math.random()-0.5)*2;break;
        case 1:x=W;y=Math.random()*H;vx=-spd;vy=(Math.random()-0.5)*2;break;
        case 2:x=Math.random()*W;y=0;vx=(Math.random()-0.5)*2;vy=spd;break;
        default:x=Math.random()*W;y=H;vx=(Math.random()-0.5)*2;vy=-spd;
      }
      var ts=['electron','positron','muon','antimuon','pion','antipion','proton'];
      addPt(x,y,vx,vy,ts[Math.floor(Math.random()*ts.length)]);
    }
    if(autoT%250===0){
      collisionEvent(W*0.15+Math.random()*W*0.7,H*0.15+Math.random()*H*0.7);
    }
  }
  hudN.textContent=totalTracks;
}

function render(){
  aX.globalCompositeOperation='source-over';
  aX.fillStyle='rgba(10,10,15,0.02)';
  aX.fillRect(0,0,W,H);

  aX.globalCompositeOperation='lighter';
  aX.lineCap='round';
  for(var i=0;i<particles.length;i++){
    var p=particles[i];
    aX.strokeStyle=p.sc;
    aX.lineWidth=1.3;
    aX.beginPath();
    aX.moveTo(p.px,p.py);
    aX.lineTo(p.x,p.y);
    aX.stroke();
    aX.strokeStyle=p.gc;
    aX.lineWidth=6;
    aX.beginPath();
    aX.moveTo(p.px,p.py);
    aX.lineTo(p.x,p.y);
    aX.stroke();
  }
  aX.globalCompositeOperation='source-over';

  X.clearRect(0,0,W,H);
  X.drawImage(acc,0,0,acc.width,acc.height,0,0,W,H);

  X.globalCompositeOperation='lighter';
  for(var i=0;i<particles.length;i++){
    var p=particles[i];
    X.beginPath();
    X.arc(p.x,p.y,2.5,0,Math.PI*2);
    X.fillStyle=p.hc;
    X.fill();
  }
  for(var i=0;i<flashes.length;i++){
    var f=flashes[i];
    var t=1-f.age/f.max;
    X.beginPath();
    X.arc(f.x,f.y,t*12+2,0,Math.PI*2);
    X.fillStyle='rgba(200,230,255,'+(t*0.4)+')';
    X.fill();
    X.beginPath();
    X.arc(f.x,f.y,t*5+1,0,Math.PI*2);
    X.fillStyle='rgba(255,255,255,'+(t*0.6)+')';
    X.fill();
  }
  X.globalCompositeOperation='source-over';

  if(drag){
    var dx=drag.ex-drag.sx,dy=drag.ey-drag.sy;
    var dd=Math.sqrt(dx*dx+dy*dy);
    if(dd>5){
      X.strokeStyle='rgba(0,245,212,0.25)';
      X.lineWidth=1;
      X.setLineDash([4,4]);
      X.beginPath();
      X.moveTo(drag.sx,drag.sy);
      X.lineTo(drag.ex,drag.ey);
      X.stroke();
      X.setLineDash([]);
      var ang=Math.atan2(dy,dx);
      X.fillStyle='rgba(0,245,212,0.25)';
      X.beginPath();
      X.moveTo(drag.ex,drag.ey);
      X.lineTo(drag.ex-8*Math.cos(ang-0.3),drag.ey-8*Math.sin(ang-0.3));
      X.lineTo(drag.ex-8*Math.cos(ang+0.3),drag.ey-8*Math.sin(ang+0.3));
      X.fill();
    }
  }
}

function loop(){update();render();requestAnimationFrame(loop);}

C.addEventListener('mousedown',function(e){
  if(e.button===2){collisionEvent(e.clientX,e.clientY);return;}
  if(e.button===0)drag={sx:e.clientX,sy:e.clientY,ex:e.clientX,ey:e.clientY};
});
C.addEventListener('mousemove',function(e){
  if(drag){drag.ex=e.clientX;drag.ey=e.clientY;}
});
C.addEventListener('mouseup',function(e){
  if(!drag||e.button!==0)return;
  var dx=drag.ex-drag.sx,dy=drag.ey-drag.sy;
  var dd=Math.sqrt(dx*dx+dy*dy);
  if(dd<8){
    var tx=W/2,ty=H/2;
    var ddx=tx-drag.sx,ddy=ty-drag.sy;
    var ddd=Math.sqrt(ddx*ddx+ddy*ddy)||1;
    shoot(drag.sx,drag.sy,ddx/ddd*2.5,ddy/ddd*2.5);
  }else{
    var s=Math.max(1,Math.min(dd*0.025,5));
    shoot(drag.sx,drag.sy,dx/dd*s,dy/dd*s);
  }
  drag=null;
});
C.addEventListener('contextmenu',function(e){e.preventDefault();});

var tch=null;
C.addEventListener('touchstart',function(e){
  e.preventDefault();
  var t=e.touches[0];
  tch={x:t.clientX,y:t.clientY,t:Date.now(),mx:t.clientX,my:t.clientY};
},{passive:false});
C.addEventListener('touchmove',function(e){
  if(tch){var t=e.touches[0];tch.mx=t.clientX;tch.my=t.clientY;}
},{passive:true});
C.addEventListener('touchend',function(e){
  if(!tch)return;
  var dt=Date.now()-tch.t;
  var dx=tch.mx-tch.x,dy=tch.my-tch.y;
  var dd=Math.sqrt(dx*dx+dy*dy);
  if(dt>500){collisionEvent(tch.x,tch.y);}
  else if(dd>25){
    var s=Math.max(1,Math.min(dd*0.025,5));
    shoot(tch.x,tch.y,dx/dd*s,dy/dd*s);
  }else{
    var tx=W/2,ty=H/2;
    var ddx=tx-tch.x,ddy=ty-tch.y;
    var ddd=Math.sqrt(ddx*ddx+ddy*ddy)||1;
    shoot(tch.x,tch.y,ddx/ddd*2.5,ddy/ddd*2.5);
  }
  tch=null;
});

document.addEventListener('keydown',function(e){
  if(e.target.tagName==='INPUT')return;
  if(e.key===' '){
    autoOn=!autoOn;
    document.getElementById('btn-auto').classList.toggle('active',autoOn);
    if(typeof SFX!=='undefined')SFX.play('toggle');
    e.preventDefault();
  }else if(e.key==='c'||e.key==='C'){
    particles=[];flashes=[];totalTracks=0;
    aX.globalCompositeOperation='source-over';
    aX.fillStyle='#0a0a0f';aX.fillRect(0,0,W,H);
    if(typeof SFX!=='undefined')SFX.play('click');
  }else if(e.key==='e'||e.key==='E'){
    collisionEvent(W/2+(Math.random()-0.5)*300,H/2+(Math.random()-0.5)*200);
  }
});

document.getElementById('sl-b').addEventListener('input',function(){
  bField=this.value/10;
  document.getElementById('sv-b').textContent=bField.toFixed(1);
  hudB.textContent=bField.toFixed(1);
});
document.getElementById('sl-l').addEventListener('input',function(){
  lossRate=this.value/10;
  document.getElementById('sv-l').textContent=lossRate.toFixed(1);
});

var typeBtns=document.querySelectorAll('#type-btns .preset-btn');
typeBtns.forEach(function(b){
  b.addEventListener('click',function(){
    typeBtns.forEach(function(p){p.classList.remove('active');});
    this.classList.add('active');
    selType=this.dataset.t;
    hudM.textContent=TYPES[selType].sym;
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
});

document.getElementById('btn-auto').addEventListener('click',function(){
  autoOn=!autoOn;
  this.classList.toggle('active',autoOn);
  if(typeof SFX!=='undefined')SFX.play('toggle');
});
document.getElementById('btn-event').addEventListener('click',function(){
  collisionEvent(W/2+(Math.random()-0.5)*300,H/2+(Math.random()-0.5)*200);
});
document.getElementById('btn-clear').addEventListener('click',function(){
  particles=[];flashes=[];totalTracks=0;
  aX.globalCompositeOperation='source-over';
  aX.fillStyle='#0a0a0f';aX.fillRect(0,0,W,H);
  if(typeof SFX!=='undefined')SFX.play('click');
});

resize();
window.addEventListener('resize',resize);
collisionEvent(W*0.5,H*0.5);
setTimeout(function(){collisionEvent(W*0.35,H*0.6);},800);
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
