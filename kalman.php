<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('kalman');

$pageName = 'kalman';
$pageTitle = 'KALMAN - PULSE';
$pageDesc = 'ノイズを含む観測から隠れた軌道を推定するカルマンフィルタのCanvas可視化。';
$pageCanonical = 'https://pulse.subara3.com/kalman.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;left:1rem;top:4rem;z-index:20;color:rgba(255,255,255,.54);font:12px/1.75 var(--font-mono,"JetBrains Mono",monospace);letter-spacing:.05em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .violet{color:#b58cff}.hud .dim{color:rgba(255,255,255,.32)}
.readout{position:fixed;right:1rem;bottom:1rem;z-index:20;text-align:right;color:rgba(255,255,255,.42);font:11px/1.7 var(--font-mono,"JetBrains Mono",monospace);letter-spacing:.05em;pointer-events:none}
.readout b{color:#b58cff;font-weight:700}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;color:rgba(255,255,255,.18);font:11px var(--font-display,"Space Grotesk",sans-serif);letter-spacing:.24em;pointer-events:none}
@media(max-width:760px){.hud{top:3.2rem;left:.75rem;font-size:10px;line-height:1.6}.readout{right:.75rem;bottom:4.7rem;font-size:9px}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <span class="violet">KALMAN</span> <span class="dim">FILTER</span><br>
  GAIN <b id="h-gain">0.00</b><br>
  SIGMA <b id="h-sigma">0.00</b><br>
  RESIDUAL <b id="h-residual">0.00</b><br>
  SENSOR <b id="h-noise">0.18</b>
</div>
<div class="readout">
  MODE <b id="r-mode">AUTO</b><br>
  PINGS <b id="r-pings">0</b><br>
  TRUST <b id="r-trust">50</b>
</div>
<div class="mark">KALMAN</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'sensor ping'],
  ['type' => 'drag', 'label' => 'guide estimate'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">SENSOR</span>
    <input type="range" id="sl-noise" min="4" max="42" value="18">
    <span class="slider-value" id="sv-noise">0.18</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">PROCESS</span>
    <input type="range" id="sl-process" min="2" max="36" value="14">
    <span class="slider-value" id="sv-process">0.14</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sl-speed" min="4" max="22" value="10">
    <span class="slider-value" id="sv-speed">1.0</span>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-ping">PING</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var canvas = document.getElementById('canvas');
var ctx = canvas.getContext('2d');
var W = 0, H = 0, DPR = 1;
var view = {cx:0, cy:0, scale:1};
var teal = [0,245,212], purple = [123,44,191], white = [255,255,255];
var trueState, estState, covX, covY;
var truth = [], estimates = [], measurements = [], echoes = [];
var t = 0, sinceMeasure = 0, pings = 0, autoMeasure = true, dragging = false;
var sensorNoise = 0.18, processNoise = 0.14, speed = 1.0;
var gainRead = 0, residualRead = 0;

var hGain = document.getElementById('h-gain');
var hSigma = document.getElementById('h-sigma');
var hResidual = document.getElementById('h-residual');
var hNoise = document.getElementById('h-noise');
var rMode = document.getElementById('r-mode');
var rPings = document.getElementById('r-pings');
var rTrust = document.getElementById('r-trust');
var slNoise = document.getElementById('sl-noise');
var slProcess = document.getElementById('sl-process');
var slSpeed = document.getElementById('sl-speed');
var svNoise = document.getElementById('sv-noise');
var svProcess = document.getElementById('sv-process');
var svSpeed = document.getElementById('sv-speed');
var btnAuto = document.getElementById('btn-auto');

function rgba(c,a){return 'rgba('+c[0]+','+c[1]+','+c[2]+','+a+')'}
function mix(a,b,t){return [a[0]+(b[0]-a[0])*t|0,a[1]+(b[1]-a[1])*t|0,a[2]+(b[2]-a[2])*t|0]}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function gauss(){
  var u = 1 - Math.random(), v = 1 - Math.random();
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(6.28318530718 * v);
}
function play(name){if(window.SFX && typeof SFX.play === 'function') SFX.play(name || 'click')}

function resize(){
  DPR = Math.min(2, window.devicePixelRatio || 1);
  W = window.innerWidth; H = window.innerHeight;
  canvas.width = Math.floor(W * DPR); canvas.height = Math.floor(H * DPR);
  canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
  ctx.setTransform(DPR,0,0,DPR,0,0);
  var pad = Math.min(W,H) * 0.08;
  view.scale = Math.max(180, Math.min(W,H) * 0.38);
  view.cx = W * 0.5;
  view.cy = H * 0.52 + pad * 0.15;
}

function toScreen(p){return {x:view.cx + p.x * view.scale, y:view.cy + p.y * view.scale}}
function toWorld(x,y){return {x:(x - view.cx) / view.scale, y:(y - view.cy) / view.scale}}

function reset(){
  t = 0; sinceMeasure = 0; pings = 0;
  trueState = {x:-0.64,y:-0.16,vx:0.34,vy:0.52};
  estState = {x:-0.42,y:0.24,vx:0.05,vy:0.02};
  covX = [0.24,0,0,0.12];
  covY = [0.24,0,0,0.12];
  truth = []; estimates = []; measurements = []; echoes = [];
  for(var i=0;i<24;i++) step(0.035, true);
  play('reset');
}

function predictAxis(cov, dt, q){
  var p00 = cov[0], p01 = cov[1], p10 = cov[2], p11 = cov[3];
  var dt2 = dt * dt, dt3 = dt2 * dt, dt4 = dt2 * dt2;
  cov[0] = p00 + dt * (p10 + p01) + dt2 * p11 + q * dt4 * 0.25;
  cov[1] = p01 + dt * p11 + q * dt3 * 0.5;
  cov[2] = p10 + dt * p11 + q * dt3 * 0.5;
  cov[3] = p11 + q * dt2;
}

function updateAxis(posKey, velKey, cov, z, r){
  var p00 = cov[0], p01 = cov[1], p10 = cov[2], p11 = cov[3];
  var innovation = z - estState[posKey];
  var s = p00 + r;
  var k0 = p00 / s, k1 = p10 / s;
  estState[posKey] += k0 * innovation;
  estState[velKey] += k1 * innovation;
  cov[0] = (1 - k0) * p00;
  cov[1] = (1 - k0) * p01;
  cov[2] = p10 - k1 * p00;
  cov[3] = p11 - k1 * p01;
  return {gain:k0, residual:innovation};
}

function predict(dt){
  estState.x += estState.vx * dt;
  estState.y += estState.vy * dt;
  predictAxis(covX, dt, processNoise * 0.08);
  predictAxis(covY, dt, processNoise * 0.08);
}

function observe(mx,my, manual){
  var r = sensorNoise * sensorNoise;
  var ux = updateAxis('x','vx',covX,mx,r);
  var uy = updateAxis('y','vy',covY,my,r);
  gainRead = (ux.gain + uy.gain) * 0.5;
  residualRead = Math.sqrt(ux.residual * ux.residual + uy.residual * uy.residual);
  pings++;
  measurements.push({x:mx,y:my,age:0,manual:manual});
  if(measurements.length > 80) measurements.shift();
  echoes.push({x:mx,y:my,r:0.02,a:1});
}

function sensorPing(manualPoint){
  if(manualPoint) observe(manualPoint.x, manualPoint.y, true);
  else observe(trueState.x + gauss() * sensorNoise, trueState.y + gauss() * sensorNoise, false);
}

function step(dt, quiet){
  t += dt;
  var ax = 0.38 * Math.sin(t * 0.82) + 0.18 * Math.cos(t * 1.71);
  var ay = 0.34 * Math.cos(t * 0.63) - 0.16 * Math.sin(t * 1.37);
  trueState.vx += ax * dt;
  trueState.vy += ay * dt;
  var sp = Math.sqrt(trueState.vx * trueState.vx + trueState.vy * trueState.vy);
  if(sp > 0.82){trueState.vx *= 0.82 / sp; trueState.vy *= 0.82 / sp}
  trueState.x += trueState.vx * dt;
  trueState.y += trueState.vy * dt;
  if(trueState.x < -0.92 || trueState.x > 0.92){trueState.x = clamp(trueState.x,-0.92,0.92); trueState.vx *= -0.78}
  if(trueState.y < -0.72 || trueState.y > 0.72){trueState.y = clamp(trueState.y,-0.72,0.72); trueState.vy *= -0.78}

  predict(dt);
  sinceMeasure += dt;
  if(autoMeasure && sinceMeasure > 0.22){
    sinceMeasure = 0;
    sensorPing(null);
  }
  truth.push({x:trueState.x,y:trueState.y});
  estimates.push({x:estState.x,y:estState.y});
  if(truth.length > 220) truth.shift();
  if(estimates.length > 220) estimates.shift();
  for(var i=measurements.length-1;i>=0;i--){measurements[i].age += dt; if(measurements[i].age > 7) measurements.splice(i,1)}
  for(var j=echoes.length-1;j>=0;j--){echoes[j].r += dt * 0.48; echoes[j].a -= dt * 0.42; if(echoes[j].a <= 0) echoes.splice(j,1)}
  if(!quiet) updateHud();
}

function drawGrid(){
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0,0,W,H);
  ctx.save();
  ctx.translate(view.cx, view.cy);
  ctx.strokeStyle = rgba(white,.035);
  ctx.lineWidth = 1;
  var stepPx = view.scale / 5;
  for(var x = -view.scale; x <= view.scale; x += stepPx){
    ctx.beginPath(); ctx.moveTo(x,-view.scale*.82); ctx.lineTo(x,view.scale*.82); ctx.stroke();
  }
  for(var y = -view.scale*.8; y <= view.scale*.8; y += stepPx){
    ctx.beginPath(); ctx.moveTo(-view.scale*1.05,y); ctx.lineTo(view.scale*1.05,y); ctx.stroke();
  }
  ctx.strokeStyle = rgba(teal,.12);
  ctx.strokeRect(-view.scale*1.02,-view.scale*.78,view.scale*2.04,view.scale*1.56);
  ctx.restore();
}

function drawPath(points, color, alpha, width){
  if(points.length < 2) return;
  ctx.beginPath();
  for(var i=0;i<points.length;i++){
    var p = toScreen(points[i]);
    if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
  }
  ctx.strokeStyle = rgba(color, alpha);
  ctx.lineWidth = width;
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  ctx.stroke();
}

function drawEllipse(){
  var p = toScreen(estState);
  var sx = Math.sqrt(Math.max(covX[0],0.0001)) * view.scale;
  var sy = Math.sqrt(Math.max(covY[0],0.0001)) * view.scale;
  ctx.save();
  ctx.translate(p.x,p.y);
  for(var i=3;i>=1;i--){
    ctx.beginPath();
    ctx.ellipse(0,0,sx*i,sy*i,0,0,Math.PI*2);
    ctx.strokeStyle = rgba(mix(teal,purple,i/3), .12);
    ctx.lineWidth = 1.2;
    ctx.stroke();
  }
  ctx.restore();
}

function drawMeasurements(){
  for(var i=0;i<measurements.length;i++){
    var m = measurements[i], p = toScreen(m);
    var a = Math.max(0, 1 - m.age / 7);
    ctx.strokeStyle = rgba(m.manual ? teal : purple, .22 + a * .5);
    ctx.lineWidth = m.manual ? 2 : 1;
    ctx.beginPath();
    ctx.moveTo(p.x-7,p.y); ctx.lineTo(p.x+7,p.y);
    ctx.moveTo(p.x,p.y-7); ctx.lineTo(p.x,p.y+7);
    ctx.stroke();
  }
  for(var j=0;j<echoes.length;j++){
    var e = echoes[j], q = toScreen(e);
    ctx.beginPath();
    ctx.arc(q.x,q.y,e.r * view.scale,0,Math.PI*2);
    ctx.strokeStyle = rgba(teal, e.a * .23);
    ctx.lineWidth = 1.4;
    ctx.stroke();
  }
}

function drawActors(){
  var tp = toScreen(trueState), ep = toScreen(estState);
  ctx.beginPath();
  ctx.arc(tp.x,tp.y,7,0,Math.PI*2);
  ctx.strokeStyle = rgba(purple,.92);
  ctx.lineWidth = 2;
  ctx.stroke();
  ctx.beginPath();
  ctx.arc(ep.x,ep.y,6,0,Math.PI*2);
  ctx.fillStyle = rgba(teal,.92);
  ctx.fill();
  ctx.strokeStyle = rgba(white,.42);
  ctx.lineWidth = 1;
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(ep.x,ep.y);
  ctx.lineTo(ep.x + estState.vx * view.scale * .28, ep.y + estState.vy * view.scale * .28);
  ctx.strokeStyle = rgba(teal,.6);
  ctx.lineWidth = 2;
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(tp.x,tp.y);
  ctx.lineTo(tp.x + trueState.vx * view.scale * .24, tp.y + trueState.vy * view.scale * .24);
  ctx.strokeStyle = rgba(purple,.5);
  ctx.stroke();
}

function drawBars(){
  var x = W - 156, y = Math.max(82, H * .18), w = 96;
  var trust = clamp(1 - Math.sqrt(covX[0] + covY[0]), 0, 1);
  ctx.fillStyle = rgba(white,.08);
  ctx.fillRect(x,y,w,3);
  ctx.fillRect(x,y+22,w,3);
  ctx.fillStyle = rgba(teal,.82);
  ctx.fillRect(x,y,w*gainRead,3);
  ctx.fillStyle = rgba(purple,.82);
  ctx.fillRect(x,y+22,w*trust,3);
  ctx.fillStyle = rgba(white,.36);
  ctx.font = '10px monospace';
  ctx.fillText('GAIN', x, y-8);
  ctx.fillText('TRUST', x, y+14);
}

function draw(){
  drawGrid();
  drawPath(truth, purple, .25, 2.2);
  drawPath(estimates, teal, .72, 2.4);
  drawMeasurements();
  drawEllipse();
  drawActors();
  drawBars();
}

function updateHud(){
  var sigma = Math.sqrt(Math.max(covX[0] + covY[0], 0));
  var trust = Math.round(clamp(1 - sigma, 0, 1) * 100);
  hGain.textContent = gainRead.toFixed(2);
  hSigma.textContent = sigma.toFixed(2);
  hResidual.textContent = residualRead.toFixed(2);
  hNoise.textContent = sensorNoise.toFixed(2);
  rMode.textContent = autoMeasure ? 'AUTO' : 'MANUAL';
  rPings.textContent = pings;
  rTrust.textContent = trust;
}

function pointerPoint(e){
  var r = canvas.getBoundingClientRect();
  return toWorld(e.clientX - r.left, e.clientY - r.top);
}

canvas.addEventListener('pointerdown', function(e){
  if(e.button === 2){ reset(); return; }
  dragging = true;
  canvas.setPointerCapture(e.pointerId);
  var p = pointerPoint(e);
  sensorPing(p);
  play('click');
});
canvas.addEventListener('pointermove', function(e){
  if(!dragging) return;
  var p = pointerPoint(e);
  observe(clamp(p.x,-1,1), clamp(p.y,-.8,.8), true);
});
canvas.addEventListener('pointerup', function(e){
  dragging = false;
  try{canvas.releasePointerCapture(e.pointerId)}catch(err){}
});
canvas.addEventListener('pointercancel', function(){dragging = false});
canvas.addEventListener('contextmenu', function(e){e.preventDefault(); reset()});

slNoise.addEventListener('input', function(){
  sensorNoise = Number(slNoise.value) / 100;
  svNoise.textContent = sensorNoise.toFixed(2);
  updateHud();
});
slProcess.addEventListener('input', function(){
  processNoise = Number(slProcess.value) / 100;
  svProcess.textContent = processNoise.toFixed(2);
});
slSpeed.addEventListener('input', function(){
  speed = Number(slSpeed.value) / 10;
  svSpeed.textContent = speed.toFixed(1);
});
document.getElementById('btn-auto').addEventListener('click', function(){
  autoMeasure = !autoMeasure;
  btnAuto.classList.toggle('active', autoMeasure);
  updateHud();
  play('toggle');
});
document.getElementById('btn-ping').addEventListener('click', function(){
  sensorPing(null);
  play('click');
});
document.getElementById('btn-reset').addEventListener('click', reset);

var last = performance.now();
function loop(now){
  var dt = Math.min(.045, (now - last) / 1000) * speed;
  last = now;
  for(var i=0;i<2;i++) step(dt * .5, false);
  draw();
  requestAnimationFrame(loop);
}

window.addEventListener('resize', resize);
resize();
reset();
updateHud();
requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
