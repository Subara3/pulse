<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('primes');

$pageName = 'primes';
$pageTitle = 'PRIMES - PULSE';
$pageDesc = '素数の並びをUlam spiralとして描き、密度と双子素数の筋を探索するCanvas可視化。';
$pageCanonical = 'https://pulse.subara3.com/primes.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(123,44,191,.9)}
.legend-line{position:fixed;right:1rem;bottom:1rem;z-index:55;width:min(28vw,230px);height:2px;background:linear-gradient(90deg,rgba(0,245,212,.15),rgba(0,245,212,.9),rgba(123,44,191,.9));opacity:.76;pointer-events:none}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.legend-line{right:.75rem;bottom:4.4rem;width:38vw}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="hud">
  Ulam <b id="h-mode">SPIRAL</b><br>
  LIMIT <b id="h-limit">4200</b><br>
  PRIMES <b id="h-count">0</b><br>
  <span class="sub">ZOOM</span> <b id="h-zoom">1.00</b>
</div>
<div class="legend-line"></div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
  ['type' => 'click', 'label' => 'pulse'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-mode="spiral">SPIRAL</button>
      <button class="control-btn" data-mode="twins">TWINS</button>
      <button class="control-btn" data-mode="density">DENSITY</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">LIMIT</span>
      <input type="range" id="sl-limit" min="900" max="12000" value="4200" step="300">
      <span class="slider-value" id="sv-limit">4200</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SCALE</span>
      <input type="range" id="sl-scale" min="55" max="170" value="100" step="5">
      <span class="slider-value" id="sv-scale">1.00</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-flow">FLOW</button>
      <button class="control-btn" id="btn-center">CENTER</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var canvas = document.getElementById('canvas');
var ctx = canvas.getContext('2d');
var W = 0, H = 0, DPR = 1;
var limit = 4200;
var scale = 1;
var zoom = 1;
var mode = 'spiral';
var flow = true;
var panX = 0, panY = 0;
var drag = false, lastX = 0, lastY = 0;
var tick = 0;
var primes = new Uint8Array(limit + 1);
var points = [];
var pulses = [];
var hMode = document.getElementById('h-mode');
var hLimit = document.getElementById('h-limit');
var hCount = document.getElementById('h-count');
var hZoom = document.getElementById('h-zoom');
var slLimit = document.getElementById('sl-limit');
var slScale = document.getElementById('sl-scale');
var svLimit = document.getElementById('sv-limit');
var svScale = document.getElementById('sv-scale');
var btnFlow = document.getElementById('btn-flow');
var btnCenter = document.getElementById('btn-center');

function resize(){
  DPR = Math.min(2, window.devicePixelRatio || 1);
  W = canvas.width = Math.floor(window.innerWidth * DPR);
  H = canvas.height = Math.floor(window.innerHeight * DPR);
  canvas.style.width = window.innerWidth + 'px';
  canvas.style.height = window.innerHeight + 'px';
}

function sieve(){
  primes = new Uint8Array(limit + 1);
  primes.fill(1);
  primes[0] = 0; primes[1] = 0;
  for(var p=2;p*p<=limit;p++){
    if(primes[p]){
      for(var q=p*p;q<=limit;q+=p) primes[q] = 0;
    }
  }
  buildPoints();
}

function spiralPosition(n){
  if(n === 1) return {x:0,y:0};
  var k = Math.ceil((Math.sqrt(n) - 1) / 2);
  var t = 2 * k + 1;
  var m = t * t;
  var side = t - 1;
  if(n >= m - side) return {x:k - (m - n), y:-k};
  m -= side;
  if(n >= m - side) return {x:-k, y:-k + (m - n)};
  m -= side;
  if(n >= m - side) return {x:-k + (m - n), y:k};
  m -= side;
  return {x:k, y:k - (m - n)};
}

function buildPoints(){
  points = [];
  var count = 0;
  for(var n=2;n<=limit;n++){
    if(!primes[n]) continue;
    count++;
    var pos = spiralPosition(n);
    var twin = (n > 3 && primes[n - 2]) || (n + 2 <= limit && primes[n + 2]);
    points.push({n:n,x:pos.x,y:pos.y,twin:twin});
  }
  hCount.textContent = count;
  updateHud();
}

function updateHud(){
  hMode.textContent = mode.toUpperCase();
  hLimit.textContent = String(limit);
  hZoom.textContent = (zoom * scale).toFixed(2);
  svLimit.textContent = String(limit);
  svScale.textContent = scale.toFixed(2);
}

function drawBackground(cx, cy, step){
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0,0,W,H);
  var grid = Math.max(16 * DPR, step * 4);
  ctx.strokeStyle = 'rgba(0,245,212,0.045)';
  ctx.lineWidth = 1 * DPR;
  ctx.beginPath();
  var ox = ((cx % grid) + grid) % grid;
  var oy = ((cy % grid) + grid) % grid;
  for(var x=ox;x<W;x+=grid){ ctx.moveTo(x,0); ctx.lineTo(x,H); }
  for(var y=oy;y<H;y+=grid){ ctx.moveTo(0,y); ctx.lineTo(W,y); }
  ctx.stroke();
}

function densityAlpha(n){
  var root = Math.sqrt(n);
  return Math.max(.16, Math.min(.9, 1 / Math.log(Math.max(3, root)) * 2.2));
}

function draw(){
  tick += flow ? 0.012 : 0.002;
  var base = Math.max(2.2 * DPR, Math.min(W,H) / 150);
  var step = base * scale * zoom;
  var cx = W / 2 + panX * DPR;
  var cy = H / 2 + panY * DPR;
  drawBackground(cx, cy, step);

  ctx.save();
  ctx.translate(cx, cy);
  ctx.globalCompositeOperation = 'lighter';

  ctx.strokeStyle = 'rgba(123,44,191,.34)';
  ctx.lineWidth = Math.max(1, step * .055);
  ctx.beginPath();
  var tail = Math.min(limit, 1400);
  for(var n=Math.max(2,limit-tail);n<=limit;n++){
    var sp = spiralPosition(n);
    var lx = sp.x * step, ly = sp.y * step;
    if(n === Math.max(2,limit-tail)) ctx.moveTo(lx,ly); else ctx.lineTo(lx,ly);
  }
  ctx.stroke();

  for(var i=0;i<points.length;i++){
    var p = points[i];
    var sx = p.x * step;
    var sy = p.y * step;
    if(sx < -cx - 20 || sx > W - cx + 20 || sy < -cy - 20 || sy > H - cy + 20) continue;
    var wave = .5 + .5 * Math.sin(tick * 2 + p.n * .017);
    var radius = Math.max(1.2 * DPR, step * (mode === 'density' ? .32 : .22));
    var alpha = mode === 'density' ? densityAlpha(p.n) : .36 + wave * .28;
    if(mode === 'twins' && p.twin){
      ctx.fillStyle = 'rgba(123,44,191,' + (.62 + wave * .28) + ')';
      radius *= 1.55;
    } else if(mode === 'twins') {
      ctx.fillStyle = 'rgba(0,245,212,.16)';
      radius *= .78;
    } else {
      ctx.fillStyle = 'rgba(0,245,212,' + alpha + ')';
    }
    ctx.beginPath();
    ctx.arc(sx, sy, radius, 0, Math.PI * 2);
    ctx.fill();
  }

  for(var j=pulses.length-1;j>=0;j--){
    var u = pulses[j];
    u.life += .025;
    var r = u.life * 180 * DPR;
    ctx.strokeStyle = 'rgba(0,245,212,' + Math.max(0, .75 - u.life) + ')';
    ctx.lineWidth = (2.4 - u.life) * DPR;
    ctx.beginPath();
    ctx.arc((u.x * DPR - cx), (u.y * DPR - cy), r, 0, Math.PI * 2);
    ctx.stroke();
    if(u.life >= 1) pulses.splice(j,1);
  }

  ctx.restore();
  requestAnimationFrame(draw);
}

function setMode(next){
  mode = next;
  document.querySelectorAll('[data-mode]').forEach(function(btn){
    btn.classList.toggle('active', btn.dataset.mode === mode);
  });
  updateHud();
}

canvas.addEventListener('pointerdown', function(e){
  drag = true;
  lastX = e.clientX; lastY = e.clientY;
  pulses.push({x:e.clientX,y:e.clientY,life:0});
  if (typeof SFX !== 'undefined') SFX.play('ripple', 90);
});
window.addEventListener('pointermove', function(e){
  if(!drag) return;
  panX += e.clientX - lastX;
  panY += e.clientY - lastY;
  lastX = e.clientX; lastY = e.clientY;
});
window.addEventListener('pointerup', function(){ drag = false; });
canvas.addEventListener('wheel', function(e){
  e.preventDefault();
  zoom *= e.deltaY < 0 ? 1.1 : .9;
  zoom = Math.max(.45, Math.min(3.2, zoom));
  updateHud();
  if (typeof SFX !== 'undefined') SFX.play('move', 120);
}, {passive:false});
canvas.addEventListener('touchstart', function(e){
  var t = e.touches && e.touches[0];
  if(!t) return;
  pulses.push({x:t.clientX,y:t.clientY,life:0});
  if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
}, {passive:true});

document.querySelectorAll('[data-mode]').forEach(function(btn){
  btn.addEventListener('click', function(){
    setMode(btn.dataset.mode);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});
slLimit.addEventListener('input', function(){
  limit = parseInt(slLimit.value, 10);
  sieve();
  if (typeof SFX !== 'undefined') SFX.play('tick', 80);
});
slScale.addEventListener('input', function(){
  scale = parseInt(slScale.value, 10) / 100;
  updateHud();
  if (typeof SFX !== 'undefined') SFX.play('move', 80);
});
btnFlow.addEventListener('click', function(){
  flow = !flow;
  btnFlow.classList.toggle('active', flow);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});
btnCenter.addEventListener('click', function(){
  panX = 0; panY = 0; zoom = 1;
  updateHud();
  if (typeof SFX !== 'undefined') SFX.play('success');
});

window.addEventListener('resize', resize);
resize();
sieve();
draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
