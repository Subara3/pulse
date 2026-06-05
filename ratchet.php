<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ratchet');

$pageName = 'ratchet';
$pageTitle = 'RATCHET - PULSE';
$pageDesc = 'ブラウニアンラチェット。非対称ポテンシャルと熱雑音が一方向の輸送を生む。ランダムが秩序を作る分子モーターの原理。';
$pageCanonical = 'https://pulse.subara3.com/ratchet.php';

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
  N = <span class="v" id="hud-n">200</span><br>
  DRIFT <span class="v" id="hud-drift">+0.00</span><br>
  <span class="v" id="hud-state">ON</span>
</div>

<div id="info">
  <span class="v">Brownian Ratchet</span><br>
  asymmetric V(x) &middot; flashing &middot; directed transport
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">NOISE</span>
      <input type="range" id="sl-noise" min="1" max="100" value="30">
      <span class="slider-value" id="sv-noise">30</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">HEIGHT</span>
      <input type="range" id="sl-height" min="1" max="100" value="50">
      <span class="slider-value" id="sv-height">50</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">ASYM</span>
      <input type="range" id="sl-asym" min="5" max="50" value="20">
      <span class="slider-value" id="sv-asym">20</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">FLASH</span>
      <input type="range" id="sl-flash" min="10" max="200" value="60">
      <span class="slider-value" id="sv-flash">60</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">COUNT</span>
      <input type="range" id="sl-count" min="20" max="500" value="200">
      <span class="slider-value" id="sv-count">200</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-flash">FLASH</button>
      <button class="control-btn" id="btn-steady">STEADY</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d');
  var W,H,numPer,baseY,hSc;

  var D=1.5,V0=1.0,alph=0.2,fPer=60,N=200;
  var parts=[],potOn=true,potA=1,flashT=0,ticks=0;
  var flashMode=true;

  var hudN=document.getElementById('hud-n');
  var hudDrift=document.getElementById('hud-drift');
  var hudState=document.getElementById('hud-state');
  var slNoise=document.getElementById('sl-noise');
  var slHeight=document.getElementById('sl-height');
  var slAsym=document.getElementById('sl-asym');
  var slFlash=document.getElementById('sl-flash');
  var slCount=document.getElementById('sl-count');
  var svNoise=document.getElementById('sv-noise');
  var svHeight=document.getElementById('sv-height');
  var svAsym=document.getElementById('sv-asym');
  var svFlash=document.getElementById('sv-flash');
  var svCount=document.getElementById('sv-count');

  function pot(x){
    var f=x-Math.floor(x);if(f<0)f+=1;
    return f<alph?V0*f/alph:V0*(1-f)/(1-alph);
  }
  function frc(x){
    var f=x-Math.floor(x);if(f<0)f+=1;
    return f<alph?-V0/alph:V0/(1-alph);
  }
  function rn(){
    return Math.sqrt(-2*Math.log(Math.random()+1e-15))*Math.cos(6.2832*Math.random());
  }

  function resize(){
    var dpr=window.devicePixelRatio||1;
    W=window.innerWidth;H=window.innerHeight;
    C.width=W*dpr;C.height=H*dpr;
    C.style.width=W+'px';C.style.height=H+'px';
    X.setTransform(dpr,0,0,dpr,0,0);
    numPer=Math.max(4,Math.min(8,Math.round(W/180)));
    baseY=H*0.6;hSc=H*0.25;
  }

  function init(){
    parts=[];ticks=0;
    for(var i=0;i<N;i++)
      parts.push({x:Math.random()*numPer,d:0,yo:(Math.random()-0.5)*6});
  }

  function adjust(target){
    while(parts.length<target)
      parts.push({x:Math.random()*numPer,d:0,yo:(Math.random()-0.5)*6});
    while(parts.length>target)parts.pop();
  }

  function step(){
    var dt=0.0008,sub=10,sd=Math.sqrt(2*D*dt);
    var on=potOn;
    for(var s=0;s<sub;s++){
      for(var i=0;i<parts.length;i++){
        var p=parts[i];
        var F=on?frc(p.x):0;
        var dx=F*dt+sd*rn();
        p.x+=dx;p.d+=dx;
      }
    }
    if(flashMode){
      flashT++;
      if(flashT>=fPer){flashT=0;potOn=!potOn;}
    }
    var tgt=potOn?1:0;potA+=(tgt-potA)*0.12;
    ticks++;
  }

  function drawPot(){
    var a=potA*0.35+0.02;
    X.beginPath();X.moveTo(0,baseY);
    for(var px=0;px<=W;px+=2){
      var v=pot(px/W*numPer)/V0;
      X.lineTo(px,baseY-v*hSc*potA);
    }
    X.lineTo(W,baseY);X.closePath();
    var g=X.createLinearGradient(0,baseY-hSc,0,baseY);
    g.addColorStop(0,'rgba(0,245,212,'+a+')');
    g.addColorStop(1,'rgba(123,44,191,'+(a*0.4)+')');
    X.fillStyle=g;X.fill();
    if(potA>0.1){
      X.save();X.shadowBlur=6*potA;X.shadowColor='rgba(0,245,212,0.3)';
      X.beginPath();X.moveTo(0,baseY);
      for(var px=0;px<=W;px+=2){
        var v=pot(px/W*numPer)/V0;
        X.lineTo(px,baseY-v*hSc*potA);
      }
      X.strokeStyle='rgba(0,245,212,'+(potA*0.5)+')';X.lineWidth=1.5;X.stroke();
      X.restore();
    }
    X.beginPath();X.moveTo(0,baseY);X.lineTo(W,baseY);
    X.strokeStyle='rgba(0,245,212,0.12)';X.lineWidth=1;X.stroke();
  }

  function drawParts(){
    X.fillStyle=potA>0.5?'rgba(0,245,212,0.8)':'rgba(0,245,212,0.4)';
    X.beginPath();
    for(var i=0;i<parts.length;i++){
      var p=parts[i];
      var wx=((p.x%numPer)+numPer)%numPer;
      var sx=wx/numPer*W;
      var v=pot(p.x)/V0;
      var sy=baseY-v*hSc*potA+p.yo;
      X.moveTo(sx+2,sy);X.arc(sx,sy,2,0,6.2832);
    }
    X.fill();
  }

  function drawHist(){
    var bins=60,bw=W/bins;
    var cnt=new Float32Array(bins),mx=0;
    for(var i=0;i<parts.length;i++){
      var wx=((parts[i].x%numPer)+numPer)%numPer;
      var b=Math.min(bins-1,Math.floor(wx/numPer*bins));
      cnt[b]++;if(cnt[b]>mx)mx=cnt[b];
    }
    if(mx===0)return;
    var hy=baseY+H*0.06,hh=H*0.14;
    for(var i=0;i<bins;i++){
      var h=cnt[i]/mx*hh;if(h<1)continue;
      var t=cnt[i]/mx;
      var r=Math.round(123+(0-123)*t);
      var gg=Math.round(44+(245-44)*t);
      var bl=Math.round(191+(212-191)*t);
      X.fillStyle='rgba('+r+','+gg+','+bl+',0.5)';
      X.fillRect(i*bw+1,hy+hh-h,bw-2,h);
    }
  }

  function drawArrow(){
    if(ticks<120||parts.length===0)return;
    var md=meanD();
    var vel=md/(ticks*0.008);
    if(Math.abs(vel)<0.005)return;
    var ay=H*0.1,ax=W/2;
    var len=Math.min(W*0.15,Math.abs(vel)*80+30);
    var dir=vel>0?1:-1;
    var op=Math.min(0.6,Math.abs(vel)*1.5);
    var col=vel>0?'0,245,212':'123,44,191';
    X.strokeStyle='rgba('+col+','+op+')';X.lineWidth=2;
    X.beginPath();X.moveTo(ax-dir*len/2,ay);X.lineTo(ax+dir*len/2,ay);X.stroke();
    X.beginPath();
    X.moveTo(ax+dir*len/2,ay);X.lineTo(ax+dir*len/2-dir*8,ay-4);
    X.moveTo(ax+dir*len/2,ay);X.lineTo(ax+dir*len/2-dir*8,ay+4);
    X.stroke();
    X.font='0.5rem "Orbitron",sans-serif';
    X.fillStyle='rgba(255,255,255,'+(op*0.5)+')';
    X.textAlign='center';X.fillText('NET DRIFT',ax,ay-10);
  }

  function meanD(){
    if(parts.length===0)return 0;
    var s=0;for(var i=0;i<parts.length;i++)s+=parts[i].d;
    return s/parts.length;
  }

  function draw(){
    X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);
    drawPot();drawParts();drawHist();drawArrow();
    hudN.textContent=parts.length;
    var md=meanD();
    hudDrift.textContent=(md>=0?'+':'')+md.toFixed(2);
    hudState.textContent=potOn?'ON':'OFF';
    hudState.style.color=potOn?'#00f5d4':'#7b2cbf';
  }

  function loop(){step();draw();requestAnimationFrame(loop);}

  function spawnAt(xn){
    if(parts.length>=1000)return;
    for(var i=0;i<20;i++)
      parts.push({x:xn+(Math.random()-0.5)*0.5,d:0,yo:(Math.random()-0.5)*6});
    N=parts.length;slCount.value=Math.min(500,N);svCount.textContent=N;
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }

  function resetAll(){
    init();potOn=true;potA=1;flashT=0;
    if(typeof SFX!=='undefined')SFX.play('click');
  }

  window.addEventListener('resize',resize);

  var mMoved=false,mSX=0;
  C.addEventListener('mousedown',function(e){
    if(e.button===2)return;
    mMoved=false;mSX=e.clientX;
    var mSY=e.clientY;
    function onMove(ev){if(Math.abs(ev.clientX-mSX)+Math.abs(ev.clientY-mSY)>8)mMoved=true;}
    function onUp(ev){
      document.removeEventListener('mousemove',onMove);
      document.removeEventListener('mouseup',onUp);
      if(!mMoved)spawnAt(ev.clientX/W*numPer);
    }
    document.addEventListener('mousemove',onMove);
    document.addEventListener('mouseup',onUp);
  });

  var tSX=0,tSY=0,tMoved=false;
  C.addEventListener('touchstart',function(e){
    if(e.touches.length!==1)return;
    tSX=e.touches[0].clientX;tSY=e.touches[0].clientY;tMoved=false;
  },{passive:true});
  C.addEventListener('touchmove',function(e){
    if(e.touches.length!==1)return;
    if(Math.abs(e.touches[0].clientX-tSX)+Math.abs(e.touches[0].clientY-tSY)>12)tMoved=true;
  },{passive:true});
  C.addEventListener('touchend',function(e){
    if(!tMoved&&e.changedTouches.length>0)
      spawnAt(e.changedTouches[0].clientX/W*numPer);
    tMoved=false;
  });

  C.addEventListener('contextmenu',function(e){e.preventDefault();resetAll();});

  slNoise.addEventListener('input',function(){D=+this.value*0.05;svNoise.textContent=this.value;});
  slHeight.addEventListener('input',function(){V0=+this.value*0.02;svHeight.textContent=this.value;});
  slAsym.addEventListener('input',function(){alph=+this.value/100;svAsym.textContent=this.value;});
  slFlash.addEventListener('input',function(){fPer=+this.value;svFlash.textContent=this.value;});
  slCount.addEventListener('input',function(){N=+this.value;svCount.textContent=this.value;adjust(N);});

  document.getElementById('btn-flash').addEventListener('click',function(){
    flashMode=true;this.classList.add('active');
    document.getElementById('btn-steady').classList.remove('active');
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
  document.getElementById('btn-steady').addEventListener('click',function(){
    flashMode=false;potOn=true;potA=1;
    this.classList.add('active');
    document.getElementById('btn-flash').classList.remove('active');
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click',function(){resetAll();});

  resize();init();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
