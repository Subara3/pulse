<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('turmite');

$pageName = 'turmite';
$pageTitle = 'TURMITE - PULSE';
$pageDesc = 'ターマイト。ラングトンのアリの一般化。色と内部状態のルールテーブルから三角形、渦巻き、フラクタルが現れる。';
$pageCanonical = 'https://pulse.subara3.com/turmite.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
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
  <span class="v" id="hud-name">LANGTON</span><br>
  RULE <span class="v" id="hud-rule">RL</span><br>
  STEP <span class="v" id="hud-step">0</span>
</div>

<div id="info">
  <span class="v">Turmite</span><br>
  2D Turing machine &middot; color &times; state &rarr; action
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'relocate'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="6" value="3">
      <span class="slider-value" id="sv-speed">3</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-run">RUN</button>
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
  <div class="preset-group" id="preset-btns">
    <button class="preset-btn active" data-p="0">LANGTON</button>
    <button class="preset-btn" data-p="1">TRIANGLE</button>
    <button class="preset-btn" data-p="2">SQUARE</button>
    <button class="preset-btn" data-p="3">SPIRAL</button>
    <button class="preset-btn" data-p="4">CORAL</button>
    <button class="preset-btn" data-p="5">CRYSTAL</button>
    <button class="preset-btn" data-p="6">WORM</button>
    <button class="preset-btn" data-p="7">CHASE</button>
    <button class="preset-btn" data-p="8">RANDOM</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d');
var W,H,grid,imgD,imgDat;
var ax,ay,adir,astate,steps;
var running=true,speedLvl=3;
var speeds=[10,100,1000,5000,25000,100000];
var DX=[0,1,0,-1],DY=[-1,0,1,0];

var presets=[
  {name:'LANGTON',type:'ant',rule:'RL'},
  {name:'TRIANGLE',type:'ant',rule:'RLR'},
  {name:'SQUARE',type:'ant',rule:'LLRR'},
  {name:'SPIRAL',type:'ant',rule:'LRRL'},
  {name:'CORAL',type:'ant',rule:'LRRRRRLLR'},
  {name:'CRYSTAL',type:'ant',rule:'RRLLLRLLLRRR'},
  {name:'WORM',type:'turmite',s:2,c:2,
   rules:[[[1,1,1],[1,1,0]],[[0,0,0],[0,0,1]]],ruleStr:'2S×2C'},
  {name:'CHASE',type:'turmite',s:2,c:2,
   rules:[[[1,1,1],[0,3,1]],[[1,3,0],[0,1,0]]],ruleStr:'2S×2C'},
  {name:'RANDOM',type:'random'}
];

var curPreset=0,palette,antRule,tmRule,curType,numColors;

function h2r(p,q,t){
  if(t<0)t+=1;if(t>1)t-=1;
  if(t<1/6)return p+(q-p)*6*t;
  if(t<.5)return q;
  if(t<2/3)return p+(q-p)*(2/3-t)*6;
  return p;
}
function hsl(h,s,l){
  h/=360;s/=100;l/=100;
  if(s===0){var v=Math.round(l*255);return[v,v,v];}
  var q=l<.5?l*(1+s):l+s-l*s,p=2*l-q;
  return[Math.round(h2r(p,q,h+1/3)*255),Math.round(h2r(p,q,h)*255),Math.round(h2r(p,q,h-1/3)*255)];
}

function makePal(n){
  var p=[[10,10,15]];
  if(n<=1)return p;
  if(n===2){p.push([0,245,212]);return p;}
  if(n===3){p.push([0,245,212]);p.push([123,44,191]);return p;}
  for(var i=1;i<n;i++){
    var t=(i-1)/(n-2);
    p.push(hsl(170+t*120,75+15*Math.sin(t*Math.PI),48+12*(1-2*Math.abs(t-.5))));
  }
  return p;
}

function resize(){
  W=innerWidth;H=innerHeight;
  C.width=W;C.height=H;
  C.style.width=W+'px';C.style.height=H+'px';
  ctx.imageSmoothingEnabled=false;
}

function clearGrid(){
  grid=new Uint8Array(W*H);
  imgD=ctx.createImageData(W,H);
  imgDat=imgD.data;
  for(var i=0,n4=W*H*4;i<n4;i+=4){
    imgDat[i]=10;imgDat[i+1]=10;imgDat[i+2]=15;imgDat[i+3]=255;
  }
  ax=Math.floor(W/2);ay=Math.floor(H/2);
  adir=0;astate=0;steps=0;
}

function loadPreset(idx){
  curPreset=idx;
  var p=presets[idx];
  var ruleStr;

  if(p.type==='random'){
    var nc=2+Math.floor(Math.random()*6);
    var r='';
    for(var i=0;i<nc;i++)r+=Math.random()<.5?'R':'L';
    curType='ant';numColors=nc;
    palette=makePal(nc);
    antRule=[];
    for(var i=0;i<nc;i++)antRule.push(r.charAt(i)==='R'?1:3);
    ruleStr=r;
    document.getElementById('hud-name').textContent=r;
  }else if(p.type==='ant'){
    curType='ant';numColors=p.rule.length;
    palette=makePal(numColors);
    antRule=[];
    for(var i=0;i<numColors;i++)antRule.push(p.rule.charAt(i)==='R'?1:3);
    ruleStr=p.rule;
    document.getElementById('hud-name').textContent=p.name;
  }else{
    curType='turmite';numColors=p.c;
    palette=makePal(numColors);
    tmRule=p.rules;
    ruleStr=p.ruleStr||'';
    document.getElementById('hud-name').textContent=p.name;
  }

  document.getElementById('hud-rule').textContent=ruleStr;
  clearGrid();
}

function frame(){
  if(running){
    var n=speeds[speedLvl-1],w=W,h=H;
    if(curType==='ant'){
      var rl=antRule,nc=numColors;
      for(var i=0;i<n;i++){
        var idx=ay*w+ax;
        var col=grid[idx];
        var nw=(col+1)%nc;
        grid[idx]=nw;
        var c=palette[nw];
        var j=idx*4;
        imgDat[j]=c[0];imgDat[j+1]=c[1];imgDat[j+2]=c[2];
        adir=(adir+rl[col])&3;
        ax+=DX[adir];ay+=DY[adir];
        if(ax<0)ax+=w;if(ax>=w)ax-=w;
        if(ay<0)ay+=h;if(ay>=h)ay-=h;
      }
    }else{
      var rl=tmRule;
      for(var i=0;i<n;i++){
        var idx=ay*w+ax;
        var col=grid[idx];
        var r=rl[astate][col];
        grid[idx]=r[0];
        var c=palette[r[0]];
        var j=idx*4;
        imgDat[j]=c[0];imgDat[j+1]=c[1];imgDat[j+2]=c[2];
        adir=(adir+r[1])&3;
        astate=r[2];
        ax+=DX[adir];ay+=DY[adir];
        if(ax<0)ax+=w;if(ax>=w)ax-=w;
        if(ay<0)ay+=h;if(ay>=h)ay-=h;
      }
    }
    steps+=n;
    ctx.putImageData(imgD,0,0);
    ctx.fillStyle='rgba(255,255,255,0.85)';
    ctx.fillRect(ax-1,ay-1,3,3);
  }

  var s=steps;
  document.getElementById('hud-step').textContent=
    s>999999?(s/1e6).toFixed(1)+'M':s>999?(s/1e3).toFixed(1)+'K':s;

  requestAnimationFrame(frame);
}

C.addEventListener('click',function(e){
  if(e.button!==0)return;
  ax=Math.max(0,Math.min(W-1,e.clientX));
  ay=Math.max(0,Math.min(H-1,e.clientY));
  SFX.play('spawn');
});

C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  clearGrid();
  SFX.play('click');
});

var touchTimer=null;
C.addEventListener('touchstart',function(e){
  e.preventDefault();
  var t=e.touches[0];
  var tx=Math.round(t.clientX),ty=Math.round(t.clientY);
  ax=Math.max(0,Math.min(W-1,tx));
  ay=Math.max(0,Math.min(H-1,ty));
  SFX.play('spawn');
  touchTimer=setTimeout(function(){
    clearGrid();
    SFX.play('click');
    touchTimer=null;
  },500);
},{passive:false});

C.addEventListener('touchmove',function(e){
  e.preventDefault();
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
},{passive:false});

C.addEventListener('touchend',function(){
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
});

document.getElementById('sl-speed').addEventListener('input',function(){
  speedLvl=+this.value;
  document.getElementById('sv-speed').textContent=speedLvl;
});

document.getElementById('btn-run').addEventListener('click',function(){
  running=!running;
  this.textContent=running?'RUN':'PAUSE';
  this.classList.toggle('active',running);
  SFX.play('toggle');
});

document.getElementById('btn-clear').addEventListener('click',function(){
  clearGrid();
  SFX.play('click');
});

var pbtns=document.querySelectorAll('#preset-btns .preset-btn');
pbtns.forEach(function(btn){
  btn.addEventListener('click',function(){
    pbtns.forEach(function(b){b.classList.remove('active');});
    this.classList.add('active');
    loadPreset(+this.dataset.p);
    SFX.play('toggle');
  });
});

resize();
loadPreset(0);
addEventListener('resize',function(){resize();loadPreset(curPreset);});
frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
