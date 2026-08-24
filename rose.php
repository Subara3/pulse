<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rose');

$pageName = 'rose';
$pageTitle = 'ROSE - PULSE';
$pageDesc = 'モーラーローズ。バラ曲線上の点を等角間隔で結ぶと、幾何学的な星形パターンが無数に生まれる。';
$pageCanonical = 'https://pulse.subara3.com/rose.php';

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
  n = <span class="v" id="hud-n">2</span><br>
  d = <span class="v" id="hud-d">39</span>
</div>

<div id="info">
  <span class="v">Maurer Rose</span><br>
  r = sin(n&middot;&theta;) &middot; d&deg; angular step
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'randomize'],
  ['type' => 'drag', 'label' => 'adjust d'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">PETALS n</span>
      <input type="range" id="sl-n" min="2" max="20" value="2">
      <span class="slider-value" id="sv-n">2</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">STEP d</span>
      <input type="range" id="sl-d" min="1" max="360" value="39">
      <span class="slider-value" id="sv-d">39</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-spd" min="1" max="20" value="5">
      <span class="slider-value" id="sv-spd">5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">GLOW</span>
      <input type="range" id="sl-glow" min="0" max="20" value="10">
      <span class="slider-value" id="sv-glow">10</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-auto">AUTO</button>
      <button class="control-btn" id="btn-curve">CURVE</button>
      <button class="control-btn" id="btn-rnd">RANDOM</button>
    </div>
  </div>
  <div>
    <span class="slider-label">PRESETS</span>
    <div class="btn-row preset-group" id="preset-btns">
      <button class="preset-btn" data-n="2" data-d="39">n2 d39</button>
      <button class="preset-btn" data-n="3" data-d="47">n3 d47</button>
      <button class="preset-btn" data-n="4" data-d="31">n4 d31</button>
      <button class="preset-btn" data-n="6" data-d="71">n6 d71</button>
      <button class="preset-btn" data-n="7" data-d="19">n7 d19</button>
      <button class="preset-btn" data-n="5" data-d="97">n5 d97</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d');
  var W,H,cx,cy,sc;
  var n=2,d=39,gw=10,spd=5;
  var autoOn=true,showCurve=false;
  var curD=39,userDrag=false;
  var isDrag=false,dStartX=0,dStartD=0;

  var hudN=document.getElementById('hud-n');
  var hudD=document.getElementById('hud-d');
  var slN=document.getElementById('sl-n');
  var slD=document.getElementById('sl-d');
  var slSpd=document.getElementById('sl-spd');
  var slGlow=document.getElementById('sl-glow');
  var svN=document.getElementById('sv-n');
  var svD=document.getElementById('sv-d');
  var svSpd=document.getElementById('sv-spd');
  var svGlow=document.getElementById('sv-glow');

  function resize(){
    var dpr=window.devicePixelRatio||1;
    W=window.innerWidth;H=window.innerHeight;
    C.width=W*dpr;C.height=H*dpr;
    C.style.width=W+'px';C.style.height=H+'px';
    X.setTransform(dpr,0,0,dpr,0,0);
    cx=W/2;cy=H/2;
    sc=Math.min(W,H)*0.38;
  }

  function maurerPts(n,dv){
    var p=new Float64Array(722);
    for(var k=0;k<=360;k++){
      var th=k*dv*Math.PI/180;
      var r=Math.sin(n*th);
      p[k*2]=cx+r*Math.cos(th)*sc;
      p[k*2+1]=cy+r*Math.sin(th)*sc;
    }
    return p;
  }

  function drawMaurer(n,dv){
    var p=maurerPts(n,dv);

    var grad=X.createRadialGradient(cx,cy,sc*0.05,cx,cy,sc);
    grad.addColorStop(0,'rgba(0,245,212,0.6)');
    grad.addColorStop(0.5,'rgba(0,200,200,0.45)');
    grad.addColorStop(1,'rgba(123,44,191,0.35)');

    if(gw>0){
      X.save();
      X.shadowBlur=gw;
      X.shadowColor='rgba(0,245,212,0.3)';
      X.strokeStyle='rgba(0,245,212,0.12)';
      X.lineWidth=3;
      X.beginPath();
      X.moveTo(p[0],p[1]);
      for(var i=2;i<722;i+=2)X.lineTo(p[i],p[i+1]);
      X.stroke();
      X.restore();
    }

    X.strokeStyle=grad;
    X.lineWidth=1;
    X.beginPath();
    X.moveTo(p[0],p[1]);
    for(var i=2;i<722;i+=2)X.lineTo(p[i],p[i+1]);
    X.stroke();
  }

  function drawRose(n){
    X.save();
    X.shadowBlur=6;
    X.shadowColor='rgba(0,245,212,0.4)';
    X.strokeStyle='rgba(0,245,212,0.45)';
    X.lineWidth=1.5;
    X.beginPath();
    var steps=720;
    for(var k=0;k<=steps;k++){
      var th=k*Math.PI*2/steps;
      var r=Math.sin(n*th);
      var x=cx+r*Math.cos(th)*sc;
      var y=cy+r*Math.sin(th)*sc;
      if(k===0)X.moveTo(x,y);
      else X.lineTo(x,y);
    }
    X.stroke();
    X.restore();
  }

  function draw(){
    if(autoOn){
      X.fillStyle='rgba(10,10,15,0.06)';
    }else{
      X.fillStyle='#0a0a0f';
    }
    X.fillRect(0,0,W,H);

    var useD=autoOn?curD:d;
    if(showCurve)drawRose(n);
    drawMaurer(n,useD);

    hudN.textContent=n;
    hudD.textContent=Math.round(useD);
  }

  function loop(){
    if(autoOn&&!userDrag){
      curD+=spd*0.01;
      if(curD>=360)curD-=360;
      d=Math.round(curD);
      slD.value=d;svD.textContent=d;
    }
    draw();
    requestAnimationFrame(loop);
  }

  function randomize(){
    n=2+Math.floor(Math.random()*19);
    d=1+Math.floor(Math.random()*359);
    curD=d;
    slN.value=n;svN.textContent=n;
    slD.value=d;svD.textContent=d;
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }

  function setParams(nn,dd){
    n=nn;d=dd;curD=dd;
    slN.value=nn;svN.textContent=nn;
    slD.value=dd;svD.textContent=dd;
  }

  window.addEventListener('resize',resize);

  slN.addEventListener('input',function(){
    n=+this.value;svN.textContent=n;
    if(typeof SFX!=='undefined')SFX.play('tick');
  });
  slD.addEventListener('input',function(){
    d=+this.value;svD.textContent=d;curD=d;
  });
  slSpd.addEventListener('input',function(){
    spd=+this.value;svSpd.textContent=spd;
  });
  slGlow.addEventListener('input',function(){
    gw=+this.value;svGlow.textContent=gw;
  });

  document.getElementById('btn-auto').addEventListener('click',function(){
    autoOn=!autoOn;
    this.classList.toggle('active');
    if(autoOn)curD=d;
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
  document.getElementById('btn-curve').addEventListener('click',function(){
    showCurve=!showCurve;
    this.classList.toggle('active');
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
  document.getElementById('btn-rnd').addEventListener('click',function(){
    randomize();
  });

  var pBtns=document.querySelectorAll('#preset-btns .preset-btn');
  for(var i=0;i<pBtns.length;i++)(function(btn){
    btn.addEventListener('click',function(){
      setParams(+btn.dataset.n,+btn.dataset.d);
      if(typeof SFX!=='undefined')SFX.play('tick');
    });
  })(pBtns[i]);

  C.addEventListener('mousedown',function(e){
    if(e.button===2)return;
    isDrag=false;userDrag=true;
    dStartX=e.clientX;dStartD=curD;

    function onMove(e){
      var dx=e.clientX-dStartX;
      if(Math.abs(dx)>5)isDrag=true;
      if(isDrag){
        var nd=dStartD+dx*0.5;
        nd=((nd%360)+360)%360;
        if(nd<1)nd+=360;
        d=Math.round(nd);curD=nd;
        slD.value=d;svD.textContent=d;
      }
    }
    function onUp(){
      document.removeEventListener('mousemove',onMove);
      document.removeEventListener('mouseup',onUp);
      userDrag=false;
      if(!isDrag)randomize();
      else if(typeof SFX!=='undefined')SFX.play('move');
      setTimeout(function(){isDrag=false;},0);
    }
    document.addEventListener('mousemove',onMove);
    document.addEventListener('mouseup',onUp);
  });

  var tStartX=0,tDrag=false,tStartD=0;
  C.addEventListener('touchstart',function(e){
    if(e.touches.length!==1)return;
    tStartX=e.touches[0].clientX;
    tStartD=curD;tDrag=false;userDrag=true;
  },{passive:true});
  C.addEventListener('touchmove',function(e){
    if(e.touches.length!==1)return;
    var dx=e.touches[0].clientX-tStartX;
    if(Math.abs(dx)>10)tDrag=true;
    if(tDrag){
      var nd=tStartD+dx*0.5;
      nd=((nd%360)+360)%360;
      if(nd<1)nd+=360;
      d=Math.round(nd);curD=nd;
      slD.value=d;svD.textContent=d;
    }
  },{passive:true});
  C.addEventListener('touchend',function(){
    userDrag=false;
    if(!tDrag)randomize();
    else if(typeof SFX!=='undefined')SFX.play('move');
    tDrag=false;
  });

  C.addEventListener('contextmenu',function(e){
    e.preventDefault();
    setParams(2,39);
    if(typeof SFX!=='undefined')SFX.play('click');
  });

  resize();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
