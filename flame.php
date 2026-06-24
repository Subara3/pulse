<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('flame');

$pageName = 'flame';
$pageTitle = 'FLAME - PULSE';
$pageDesc = 'フラクタルフレーム。非線形変換の反復が光る有機的形態を紡ぎ出す。ログ密度レンダリングで密度が光に変わる。';
$pageCanonical = 'https://pulse.subara3.com/flame.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:4.5rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  ITER <span class="v" id="h-iter">0</span><br>
  XFORMS <span class="v" id="h-xf">0</span><br>
  VAR <span class="v" id="h-var">SIN</span>
</div>

<div id="info">
  <span class="v">Fractal Flame</span><br>
  nonlinear IFS &middot; log-density
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'randomize'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row" id="var-btns" style="flex-wrap:wrap">
      <button class="control-btn active" data-v="sinusoidal">SIN</button>
      <button class="control-btn" data-v="spherical">SPH</button>
      <button class="control-btn" data-v="swirl">SWR</button>
      <button class="control-btn" data-v="horseshoe">HSH</button>
      <button class="control-btn" data-v="polar">POL</button>
      <button class="control-btn" data-v="heart">HRT</button>
      <button class="control-btn" data-v="disc">DSC</button>
      <button class="control-btn" data-v="spiral">SPR</button>
      <button class="control-btn" data-v="diamond">DMD</button>
      <button class="control-btn" data-v="julia">JUL</button>
      <button class="control-btn" data-v="mix">MIX</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SYMMETRY</span>
      <input type="range" id="sl-sym" min="1" max="8" value="1" step="1">
      <span class="slider-value" id="sv-sym">1</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">GAMMA</span>
      <input type="range" id="sl-gamma" min="10" max="50" value="22" step="1">
      <span class="slider-value" id="sv-gamma">2.2</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-rand">RANDOMIZE</button>
      <button class="control-btn" id="btn-mut">MUTATE</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, rW, rH;
var offC, offCtx, imgData;

var transforms = [];
var symmetry = 1;
var gamma = 2.2;
var totalIter = 0;
var ix = 0, iy = 0, ic = 0;
var viewX = 0, viewY = 0, viewScale = 2;
var maxDens = 0;
var curVar = 'sinusoidal';
var mixMode = false;
var ppu = 1;

var hR, hG, hB, hA;

var PAL = 256;
var palR = new Float32Array(PAL);
var palG = new Float32Array(PAL);
var palB = new Float32Array(PAL);

function hsl2rgb(h, s, l) {
  s /= 100; l /= 100;
  var c = (1 - Math.abs(2 * l - 1)) * s;
  var x = c * (1 - Math.abs((h / 60) % 2 - 1));
  var m = l - c / 2;
  var r, g, b;
  if (h < 60) { r=c; g=x; b=0; }
  else if (h < 120) { r=x; g=c; b=0; }
  else if (h < 180) { r=0; g=c; b=x; }
  else if (h < 240) { r=0; g=x; b=c; }
  else if (h < 300) { r=x; g=0; b=c; }
  else { r=c; g=0; b=x; }
  return [(r+m)*255, (g+m)*255, (b+m)*255];
}

(function(){
  for (var i = 0; i < PAL; i++) {
    var t = i / (PAL - 1);
    var rgb = hsl2rgb(172 + t * 100, 100 - t * 37, 48 - t * 2);
    palR[i] = rgb[0] / 255;
    palG[i] = rgb[1] / 255;
    palB[i] = rgb[2] / 255;
  }
})();

var GLUT_N = 1024;
var gammaLUT = new Float32Array(GLUT_N);
function buildGammaLUT() {
  var inv = 1 / gamma;
  for (var i = 0; i < GLUT_N; i++) gammaLUT[i] = Math.pow(i / (GLUT_N - 1), inv);
}
buildGammaLUT();

var V = {
  sinusoidal: function(x,y){return [Math.sin(x), Math.sin(y)]},
  spherical: function(x,y){var r2=x*x+y*y+1e-6;return [x/r2, y/r2]},
  swirl: function(x,y){var r2=x*x+y*y,s=Math.sin(r2),c=Math.cos(r2);return [x*s-y*c, x*c+y*s]},
  horseshoe: function(x,y){var r=1/(Math.sqrt(x*x+y*y)+1e-6);return [(x-y)*(x+y)*r, 2*x*y*r]},
  polar: function(x,y){return [Math.atan2(y,x)/Math.PI, Math.sqrt(x*x+y*y)-1]},
  heart: function(x,y){var r=Math.sqrt(x*x+y*y),t=Math.atan2(y,x);return [r*Math.sin(t*r), -r*Math.cos(t*r)]},
  disc: function(x,y){var r=Math.sqrt(x*x+y*y),t=Math.atan2(y,x)/Math.PI;return [t*Math.sin(Math.PI*r), t*Math.cos(Math.PI*r)]},
  spiral: function(x,y){var r=Math.sqrt(x*x+y*y)+1e-6,t=Math.atan2(y,x);return [(Math.cos(t)+Math.sin(r))/r, (Math.sin(t)-Math.cos(r))/r]},
  diamond: function(x,y){var r=Math.sqrt(x*x+y*y),t=Math.atan2(y,x);return [Math.sin(t)*Math.cos(r), Math.cos(t)*Math.sin(r)]},
  julia: function(x,y){var r=Math.pow(x*x+y*y,0.25),t=Math.atan2(y,x)/2,o=Math.random()<0.5?0:Math.PI;return [r*Math.cos(t+o), r*Math.sin(t+o)]}
};
var VNAMES = Object.keys(V);
var VLABELS = {sinusoidal:'SIN',spherical:'SPH',swirl:'SWR',horseshoe:'HSH',polar:'POL',heart:'HRT',disc:'DSC',spiral:'SPR',diamond:'DMD',julia:'JUL'};

function makeTransform(vn) {
  var a = Math.random()*2-1, b = Math.random()*2-1;
  var d = Math.random()*2-1, e = Math.random()*2-1;
  var sum = a*a+b*b+d*d+e*e;
  var det = a*e - b*d;
  var disc = Math.sqrt(Math.max(0, sum*sum - 4*det*det));
  var sv = Math.sqrt(0.5*(sum+disc));
  if (sv > 0.85) { var sc = 0.85/sv; a*=sc; b*=sc; d*=sc; e*=sc; }
  return {
    a:a, b:b, c:(Math.random()-0.5)*0.8,
    d:d, e:e, f:(Math.random()-0.5)*0.8,
    w: Math.random()+0.2, color: 0, variation: vn, cumW: 0
  };
}

function normWeights() {
  var tw = 0, cum = 0;
  for (var i = 0; i < transforms.length; i++) tw += transforms[i].w;
  for (var i = 0; i < transforms.length; i++) { transforms[i].w /= tw; cum += transforms[i].w; transforms[i].cumW = cum; }
}

function pickT() {
  var r = Math.random();
  for (var i = 0; i < transforms.length; i++) if (r <= transforms[i].cumW) return transforms[i];
  return transforms[transforms.length - 1];
}

function autoFrame() {
  var tx = 0, ty = 0;
  var mnX = 1e9, mxX = -1e9, mnY = 1e9, mxY = -1e9;
  for (var i = 0; i < 100; i++) {
    var t = pickT();
    var ax = t.a*tx+t.b*ty+t.c, ay = t.d*tx+t.e*ty+t.f;
    var v = V[t.variation](ax, ay);
    tx = v[0]; ty = v[1];
    if (!isFinite(tx)||!isFinite(ty)){tx=Math.random()*2-1;ty=Math.random()*2-1;}
  }
  for (var i = 0; i < 5000; i++) {
    var t = pickT();
    var ax = t.a*tx+t.b*ty+t.c, ay = t.d*tx+t.e*ty+t.f;
    var v = V[t.variation](ax, ay);
    tx = v[0]; ty = v[1];
    if (!isFinite(tx)||!isFinite(ty)){tx=Math.random()*2-1;ty=Math.random()*2-1;continue;}
    if (symmetry > 1) {
      var ang = (2*Math.PI/symmetry)*Math.floor(Math.random()*symmetry);
      var cs = Math.cos(ang), sn = Math.sin(ang);
      var rx = tx*cs-ty*sn; ty = tx*sn+ty*cs; tx = rx;
    }
    if (tx<mnX)mnX=tx; if(tx>mxX)mxX=tx; if(ty<mnY)mnY=ty; if(ty>mxY)mxY=ty;
  }
  if (!isFinite(mnX)){viewX=0;viewY=0;viewScale=2;return;}
  viewX = (mnX+mxX)/2; viewY = (mnY+mxY)/2;
  viewScale = Math.max(mxX-mnX, (mxY-mnY)*W/H) * 1.3;
  if (viewScale < 0.01) viewScale = 2;
}

function generateFlame() {
  for (var attempt = 0; attempt < 10; attempt++) {
    var n = 2 + Math.floor(Math.random()*3);
    transforms = [];
    for (var i = 0; i < n; i++) {
      var vn = mixMode ? VNAMES[Math.floor(Math.random()*VNAMES.length)] : curVar;
      var t = makeTransform(vn);
      t.color = n > 1 ? i/(n-1) : 0;
      transforms.push(t);
    }
    normWeights();
    autoFrame();
    if (viewScale >= 0.01 && viewScale < 100) break;
  }
  ix = Math.random()*2-1; iy = Math.random()*2-1; ic = 0;
  resetHist();
}

function mutateFlame() {
  for (var i = 0; i < transforms.length; i++) {
    var t = transforms[i];
    t.a += (Math.random()-0.5)*0.08; t.b += (Math.random()-0.5)*0.08;
    t.c += (Math.random()-0.5)*0.05;
    t.d += (Math.random()-0.5)*0.08; t.e += (Math.random()-0.5)*0.08;
    t.f += (Math.random()-0.5)*0.05;
    var sum = t.a*t.a+t.b*t.b+t.d*t.d+t.e*t.e;
    var det = t.a*t.e-t.b*t.d;
    var disc = Math.sqrt(Math.max(0,sum*sum-4*det*det));
    var sv = Math.sqrt(0.5*(sum+disc));
    if (sv > 0.85){var sc=0.85/sv;t.a*=sc;t.b*=sc;t.d*=sc;t.e*=sc;}
  }
  autoFrame();
  resetHist();
}

function resetHist() {
  var n = rW * rH;
  if (!hR || hR.length !== n) {
    hR = new Float32Array(n); hG = new Float32Array(n);
    hB = new Float32Array(n); hA = new Float32Array(n);
    imgData = offCtx.createImageData(rW, rH);
  } else {
    hR.fill(0); hG.fill(0); hB.fill(0); hA.fill(0);
  }
  maxDens = 0; totalIter = 0;
  ppu = rW / viewScale;
}

function iterate() {
  var t = pickT();
  var ax = t.a*ix+t.b*iy+t.c, ay = t.d*ix+t.e*iy+t.f;
  var v = V[t.variation](ax, ay);
  ax = v[0]; ay = v[1];
  if (symmetry > 1) {
    var ang = (2*Math.PI/symmetry)*Math.floor(Math.random()*symmetry);
    var cs = Math.cos(ang), sn = Math.sin(ang);
    var rx = ax*cs-ay*sn; ay = ax*sn+ay*cs; ax = rx;
  }
  ix = ax; iy = ay;
  if (!isFinite(ix)||!isFinite(iy)){ix=Math.random()*2-1;iy=Math.random()*2-1;return;}
  ic = (ic + t.color) * 0.5;
  var sx = ((ix-viewX)*ppu + rW*0.5)|0;
  var sy = (-(iy-viewY)*ppu + rH*0.5)|0;
  if (sx >= 0 && sx < rW && sy >= 0 && sy < rH) {
    var idx = sy*rW+sx;
    var ci = (ic*(PAL-1)+0.5)|0;
    hR[idx] += palR[ci]; hG[idx] += palG[ci]; hB[idx] += palB[ci];
    hA[idx]++;
    if (hA[idx] > maxDens) maxDens = hA[idx];
  }
}

function render() {
  var data = imgData.data;
  var logMaxD = maxDens > 0 ? Math.log(maxDens+1) : 1;
  var n = rW*rH;
  for (var i = 0; i < n; i++) {
    var j = i<<2;
    var a = hA[i];
    if (a > 0) {
      var logA = Math.log(a+1)/logMaxD;
      var li = (logA*(GLUT_N-1)+0.5)|0;
      if (li >= GLUT_N) li = GLUT_N-1;
      var b = gammaLUT[li];
      var s = b*255/a;
      data[j]   = Math.min(255, hR[i]*s|0);
      data[j+1] = Math.min(255, hG[i]*s|0);
      data[j+2] = Math.min(255, hB[i]*s|0);
      data[j+3] = 255;
    } else {
      data[j]=10; data[j+1]=10; data[j+2]=15; data[j+3]=255;
    }
  }
  offCtx.putImageData(imgData, 0, 0);
  ctx.imageSmoothingEnabled = true;
  ctx.drawImage(offC, 0, 0, W, H);
}

var hIter = document.getElementById('h-iter');
var hXf = document.getElementById('h-xf');
var hVar = document.getElementById('h-var');
function updateHUD() {
  if (totalIter >= 1e6) hIter.textContent = (totalIter/1e6).toFixed(1)+'M';
  else if (totalIter >= 1e3) hIter.textContent = (totalIter/1e3|0)+'K';
  else hIter.textContent = totalIter;
  hXf.textContent = transforms.length;
  hVar.textContent = mixMode ? 'MIX' : (VLABELS[curVar]||curVar.toUpperCase());
}

function resize() {
  W = window.innerWidth; H = window.innerHeight;
  C.width = W; C.height = H;
  rW = Math.max(1, Math.floor(W*0.5));
  rH = Math.max(1, Math.floor(H*0.5));
  offC = document.createElement('canvas');
  offC.width = rW; offC.height = rH;
  offCtx = offC.getContext('2d');
  hR = hG = hB = hA = null;
  resetHist();
}

var dragSX, dragSY, dragged;
C.addEventListener('mousedown', function(e){
  dragSX = e.clientX; dragSY = e.clientY; dragged = false;
});
C.addEventListener('mousemove', function(e){
  if (dragSX === undefined) return;
  var dx = e.clientX-dragSX, dy = e.clientY-dragSY;
  if (Math.abs(dx)>4||Math.abs(dy)>4) {
    dragged = true;
    viewX -= dx*viewScale/W; viewY += dy*viewScale/W;
    dragSX = e.clientX; dragSY = e.clientY;
    ppu = rW/viewScale; resetHist();
  }
});
C.addEventListener('mouseup', function(){
  if (!dragged && dragSX !== undefined) {
    generateFlame();
    if(typeof SFX!=='undefined') SFX.play('spawn');
  }
  dragSX = undefined;
});
C.addEventListener('contextmenu', function(e){e.preventDefault()});
C.addEventListener('wheel', function(e){
  e.preventDefault();
  var f = e.deltaY > 0 ? 1.15 : 1/1.15;
  var mX = (e.clientX-W/2)*viewScale/W+viewX;
  var mY = -(e.clientY-H/2)*viewScale/W+viewY;
  viewScale *= f;
  viewX = mX-(e.clientX-W/2)*viewScale/W;
  viewY = mY+(e.clientY-H/2)*viewScale/W;
  ppu = rW/viewScale; resetHist();
  if(typeof SFX!=='undefined') SFX.play('tick',100);
}, {passive:false});

var tSX, tSY, tDist, tMoved;
C.addEventListener('touchstart', function(e){
  e.preventDefault();
  if (e.touches.length===1) { tSX=e.touches[0].clientX; tSY=e.touches[0].clientY; tMoved=false; }
  if (e.touches.length===2) {
    var a=e.touches[0],b=e.touches[1];
    tDist=Math.hypot(b.clientX-a.clientX, b.clientY-a.clientY);
    tMoved=true;
  }
},{passive:false});
C.addEventListener('touchmove', function(e){
  e.preventDefault();
  if (e.touches.length===1 && tSX!==undefined) {
    var dx=e.touches[0].clientX-tSX, dy=e.touches[0].clientY-tSY;
    if (Math.abs(dx)>3||Math.abs(dy)>3) {
      tMoved=true;
      viewX-=dx*viewScale/W; viewY+=dy*viewScale/W;
      tSX=e.touches[0].clientX; tSY=e.touches[0].clientY;
      ppu=rW/viewScale; resetHist();
    }
  } else if (e.touches.length===2 && tDist) {
    var a=e.touches[0],b=e.touches[1];
    var d=Math.hypot(b.clientX-a.clientX, b.clientY-a.clientY);
    viewScale *= tDist/d;
    tDist=d; ppu=rW/viewScale; resetHist();
  }
},{passive:false});
C.addEventListener('touchend', function(e){
  if (!tMoved && e.touches.length===0) {
    generateFlame();
    if(typeof SFX!=='undefined') SFX.play('spawn');
  }
  if (e.touches.length<2) tDist=undefined;
},{passive:false});

document.getElementById('var-btns').addEventListener('click', function(e){
  var btn = e.target.closest('.control-btn');
  if (!btn) return;
  var v = btn.getAttribute('data-v');
  if (v==='mix') { mixMode=true; } else { mixMode=false; curVar=v; }
  this.querySelectorAll('.control-btn').forEach(function(b){b.classList.remove('active')});
  btn.classList.add('active');
  generateFlame();
  if(typeof SFX!=='undefined') SFX.play('click');
});

document.getElementById('sl-sym').addEventListener('input', function(){
  symmetry = parseInt(this.value);
  document.getElementById('sv-sym').textContent = symmetry;
  resetHist();
  if(typeof SFX!=='undefined') SFX.play('tick',80);
});

document.getElementById('sl-gamma').addEventListener('input', function(){
  gamma = parseInt(this.value)/10;
  document.getElementById('sv-gamma').textContent = gamma.toFixed(1);
  buildGammaLUT();
});

document.getElementById('btn-rand').addEventListener('click', function(){
  generateFlame();
  if(typeof SFX!=='undefined') SFX.play('spawn');
});

document.getElementById('btn-mut').addEventListener('click', function(){
  mutateFlame();
  if(typeof SFX!=='undefined') SFX.play('toggle');
});

var frameNum = 0;
function draw() {
  var t0 = performance.now();
  var count = 0;
  while (performance.now()-t0 < 12) {
    for (var j=0;j<1000;j++) iterate();
    count += 1000;
  }
  totalIter += count;

  frameNum++;
  if (frameNum%3===0 || totalIter < 80000) {
    render();
    updateHUD();
  }
  requestAnimationFrame(draw);
}

window.addEventListener('resize', resize);
resize();
generateFlame();
requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
