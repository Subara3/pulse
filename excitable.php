<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('excitable');

$pageName = 'excitable';
$pageTitle = 'EXCITABLE - PULSE';
$pageDesc = '興奮性媒質。クリックで波を起こし、波面が途切れるとスパイラル波が自発的に回転し始める。心臓のリズムを支配する数学。';
$pageCanonical = 'https://pulse.subara3.com/excitable.php';

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
  <span class="v" id="hud-mode">SPIRAL</span><br>
  <span class="v" id="hud-spirals">0</span> WAVES<br>
  STEP <span class="v" id="hud-step">0</span>
</div>

<div id="info">
  <span class="v">Excitable Medium</span><br>
  Barkley model &middot; spiral waves &middot; PDE
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'excite'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'block'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">EXCITE</span>
      <input type="range" id="sl-a" min="30" max="100" value="75">
      <span class="slider-value" id="sv-a">0.75</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">EPSILON</span>
      <input type="range" id="sl-eps" min="1" max="15" value="8">
      <span class="slider-value" id="sv-eps">0.08</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="30" value="10">
      <span class="slider-value" id="sv-speed">10</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-pause">PAUSE</button>
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
  <div class="preset-group" id="preset-btns">
    <button class="preset-btn active" data-idx="0">SPIRAL</button>
    <button class="preset-btn" data-idx="1">BREAKUP</button>
    <button class="preset-btn" data-idx="2">MEANDER</button>
    <button class="preset-btn" data-idx="3">FAST</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,GW,GH,CS;
var u0,v0,u1,v1;
var offC,offX,offImg,offPix;

var paramA=0.75, paramB=0.06, paramEps=0.08, diffD=1.0;
var dt=0.018, substeps=10;
var step=0, paused=false;

var presets=[
  {a:0.75,b:0.06,eps:0.08,nm:'SPIRAL'},
  {a:0.58,b:0.05,eps:0.04,nm:'BREAKUP'},
  {a:0.68,b:0.08,eps:0.06,nm:'MEANDER'},
  {a:0.85,b:0.04,eps:0.12,nm:'FAST'}
];

var hudMode=document.getElementById('hud-mode');
var hudSp=document.getElementById('hud-spirals');
var hudStep=document.getElementById('hud-step');
var slA=document.getElementById('sl-a');
var svA=document.getElementById('sv-a');
var slEps=document.getElementById('sl-eps');
var svEps=document.getElementById('sv-eps');
var slSpd=document.getElementById('sl-speed');
var svSpd=document.getElementById('sv-speed');

function resize(){
  W=C.width=window.innerWidth;
  H=C.height=window.innerHeight;
  CS=Math.max(2,Math.ceil(Math.min(W,H)/250));
  var nw=Math.ceil(W/CS),nh=Math.ceil(H/CS);
  if(nw!==GW||nh!==GH){
    GW=nw;GH=nh;
    offC=document.createElement('canvas');
    offC.width=GW;offC.height=GH;
    offX=offC.getContext('2d');
    offImg=offX.createImageData(GW,GH);
    offPix=offImg.data;
    initGrid();
    initSpiral();
  }
}

function initGrid(){
  var sz=GW*GH;
  u0=new Float32Array(sz);
  v0=new Float32Array(sz);
  u1=new Float32Array(sz);
  v1=new Float32Array(sz);
  step=0;
}

function initSpiral(){
  var sz=GW*GH;
  for(var i=0;i<sz;i++){u0[i]=0;v0[i]=0;}
  var cx=GW>>1,cy=GH>>1;
  for(var y=0;y<GH;y++){
    for(var x=0;x<cx;x++){
      v0[y*GW+x]=0.9;
    }
  }
  for(var y=cy;y<GH;y++){
    for(var x=cx;x<GW;x++){
      u0[y*GW+x]=1;
    }
  }
}

function simulate(){
  var inv_eps=1/paramEps;
  var gw=GW,gh=GH,sz=gw*gh;
  for(var s=0;s<substeps;s++){
    for(var y=1;y<gh-1;y++){
      var row=y*gw;
      for(var x=1;x<gw-1;x++){
        var i=row+x;
        var uc=u0[i],vc=v0[i];
        var lap=u0[i-1]+u0[i+1]+u0[i-gw]+u0[i+gw]-4*uc;
        var uth=(vc+paramB)/paramA;
        var react=inv_eps*uc*(1-uc)*(uc-uth);
        var nu=uc+dt*(react+diffD*lap);
        var nv=vc+dt*(uc-vc);
        if(nu<0)nu=0;if(nu>1)nu=1;
        if(nv<0)nv=0;if(nv>1)nv=1;
        u1[i]=nu;v1[i]=nv;
      }
    }
    for(var x=0;x<gw;x++){
      u1[x]=u1[gw+x];v1[x]=v1[gw+x];
      var b=(gh-1)*gw+x,b1=(gh-2)*gw+x;
      u1[b]=u1[b1];v1[b]=v1[b1];
    }
    for(var y=0;y<gh;y++){
      var r=y*gw;
      u1[r]=u1[r+1];v1[r]=v1[r+1];
      u1[r+gw-1]=u1[r+gw-2];v1[r+gw-1]=v1[r+gw-2];
    }
    var tmp;
    tmp=u0;u0=u1;u1=tmp;
    tmp=v0;v0=v1;v1=tmp;
    step++;
  }
}

function render(){
  var sz=GW*GH;
  for(var i=0;i<sz;i++){
    var uc=u0[i],vc=v0[i];
    var eu=uc*3;if(eu>1)eu=1;
    var ev=vc*2.5;if(ev>1)ev=1;
    var rv=ev*(1-eu*0.8);
    var p=i<<2;
    offPix[p]  =(10+eu*(-10)+rv*113)|0;
    offPix[p+1]=(10+eu*235+rv*34)|0;
    offPix[p+2]=(15+eu*197+rv*176)|0;
    offPix[p+3]=255;
  }
  offX.putImageData(offImg,0,0);
  X.imageSmoothingEnabled=true;
  X.imageSmoothingQuality='low';
  X.drawImage(offC,0,0,W,H);
}

function countWaves(){
  var cnt=0,sz=GW*GH;
  for(var i=0;i<sz;i+=47){if(u0[i]>0.5)cnt++;}
  return cnt;
}

function fmtK(v){return v<1e3?''+v:v<1e6?(v/1e3).toFixed(1)+'K':(v/1e6).toFixed(1)+'M';}

var fc=0;
function loop(){
  if(!paused){
    simulate();
    render();
    fc++;
    if(fc%10===0){
      hudStep.textContent=fmtK(step);
      hudSp.textContent=countWaves();
    }
  }
  requestAnimationFrame(loop);
}

function exciteAt(gx,gy,rad){
  rad=rad||3;
  var r2=rad*rad;
  for(var dy=-rad;dy<=rad;dy++){
    for(var dx=-rad;dx<=rad;dx++){
      if(dx*dx+dy*dy>r2)continue;
      var px=gx+dx,py=gy+dy;
      if(px>=0&&px<GW&&py>=0&&py<GH){
        u0[py*GW+px]=1;
      }
    }
  }
}

function blockAt(gx,gy,rad){
  rad=rad||4;
  var r2=rad*rad;
  for(var dy=-rad;dy<=rad;dy++){
    for(var dx=-rad;dx<=rad;dx++){
      if(dx*dx+dy*dy>r2)continue;
      var px=gx+dx,py=gy+dy;
      if(px>=0&&px<GW&&py>=0&&py<GH){
        var idx=py*GW+px;
        u0[idx]=0;
        v0[idx]=1;
      }
    }
  }
}

function toGrid(cx,cy){return{x:(cx/CS)|0,y:(cy/CS)|0};}

var isDrag=false,isRight=false,dragMoved=false,msx=0,msy=0;

C.addEventListener('mousedown',function(e){
  e.preventDefault();
  isRight=e.button===2;
  isDrag=true;dragMoved=false;
  msx=e.clientX;msy=e.clientY;
  var g=toGrid(e.clientX,e.clientY);
  if(isRight){
    blockAt(g.x,g.y,4);
  }else{
    exciteAt(g.x,g.y,3);
    if(typeof SFX!=='undefined')SFX.play('ripple');
  }
});

C.addEventListener('mousemove',function(e){
  if(!isDrag)return;
  dragMoved=true;
  var g=toGrid(e.clientX,e.clientY);
  if(isRight){
    blockAt(g.x,g.y,3);
  }else{
    exciteAt(g.x,g.y,2);
  }
});

document.addEventListener('mouseup',function(){
  if(isDrag&&dragMoved&&!isRight){
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }
  isDrag=false;isRight=false;
});

C.addEventListener('contextmenu',function(e){e.preventDefault();});

var touchTimer=null,touchIsLong=false;
C.addEventListener('touchstart',function(e){
  if(e.touches.length!==1)return;
  touchIsLong=false;
  var t=e.touches[0];
  msx=t.clientX;msy=t.clientY;
  isDrag=true;dragMoved=false;
  touchTimer=setTimeout(function(){
    touchIsLong=true;
    var g=toGrid(msx,msy);
    blockAt(g.x,g.y,5);
    if(typeof SFX!=='undefined')SFX.play('tick');
  },400);
  var g=toGrid(t.clientX,t.clientY);
  exciteAt(g.x,g.y,3);
  if(typeof SFX!=='undefined')SFX.play('ripple');
},{passive:true});

C.addEventListener('touchmove',function(e){
  if(e.touches.length!==1)return;
  dragMoved=true;
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
  var t=e.touches[0];
  var g=toGrid(t.clientX,t.clientY);
  if(touchIsLong){
    blockAt(g.x,g.y,3);
  }else{
    exciteAt(g.x,g.y,2);
  }
},{passive:true});

C.addEventListener('touchend',function(){
  if(touchTimer){clearTimeout(touchTimer);touchTimer=null;}
  if(isDrag&&dragMoved&&!touchIsLong){
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }
  isDrag=false;touchIsLong=false;
});

function applyPreset(idx){
  var p=presets[idx];
  paramA=p.a;paramB=p.b||0.06;paramEps=p.eps;
  slA.value=(p.a*100)|0;svA.textContent=p.a.toFixed(2);
  slEps.value=(p.eps*100)|0;svEps.textContent=p.eps.toFixed(2);
  hudMode.textContent=p.nm;
  initGrid();initSpiral();
  if(typeof SFX!=='undefined')SFX.play('toggle');
  var bs=document.querySelectorAll('#preset-btns .preset-btn');
  bs.forEach(function(b){b.classList.remove('active');});
  if(bs[idx])bs[idx].classList.add('active');
}

slA.addEventListener('input',function(){
  paramA=+this.value/100;
  svA.textContent=paramA.toFixed(2);
});

slEps.addEventListener('input',function(){
  paramEps=+this.value/100;
  svEps.textContent=paramEps.toFixed(2);
});

slSpd.addEventListener('input',function(){
  substeps=+this.value;
  svSpd.textContent=this.value;
});

document.getElementById('btn-pause').addEventListener('click',function(){
  paused=!paused;
  this.classList.toggle('active',paused);
  this.textContent=paused?'PLAY':'PAUSE';
  if(typeof SFX!=='undefined')SFX.play('toggle');
});

document.getElementById('btn-clear').addEventListener('click',function(){
  initGrid();
  if(typeof SFX!=='undefined')SFX.play('click');
});

document.querySelectorAll('#preset-btns .preset-btn').forEach(function(btn){
  btn.addEventListener('click',function(){applyPreset(+this.dataset.idx);});
});

window.addEventListener('resize',resize);
resize();
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
