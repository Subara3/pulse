<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('clack');

$pageName = 'clack';
$pageTitle = 'CLACK - PULSE';
$pageDesc = '質量の違うブロックが壁との間で弾む。跳ね返りの回数を当ててみる。答えには円周率が隠れている。';
$pageCanonical = 'https://pulse.subara3.com/clack.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.hud .pi-reveal{color:#00f5d4;opacity:0;transition:opacity 0.8s ease}
.hud .pi-reveal.show{opacity:1}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <span class="sub" id="h-mode">WATCH</span><br>
  MASS RATIO <b id="h-mass">1:100</b><br>
  COLLISIONS <b id="h-count">0</b><br>
  <span class="pi-reveal" id="h-pi"></span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'launch'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row">
    <button class="control-btn active" data-mode="WATCH">WATCH</button>
    <button class="control-btn" data-mode="GUESS">GUESS</button>
    <button class="control-btn" data-mode="PHASE">PHASE</button>
  </div>
  <div class="slider-row"><label class="slider-label">MASS RATIO</label></div>
  <div class="btn-row" id="mass-btns">
    <button class="control-btn" data-mass="1">&times;1</button>
    <button class="control-btn active" data-mass="100">&times;100</button>
    <button class="control-btn" data-mass="10000">&times;10<sup>4</sup></button>
    <button class="control-btn" data-mass="1000000">&times;10<sup>6</sup></button>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sl-speed" min="1" max="10" value="5" step="1">
    <span class="slider-value" id="sv-speed">5</span>
  </div>
  <div id="guess-wrap" style="display:none">
    <div class="slider-row">
      <span class="slider-label">YOUR GUESS</span>
      <input type="range" id="sl-guess" min="1" max="400" value="31" step="1">
      <span class="slider-value" id="sv-guess">31</span>
    </div>
    <div class="btn-row">
      <button class="control-btn" id="btn-lock">LOCK</button>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-launch">LAUNCH</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, dpr;

var massRatio = 100;
var mode = 'WATCH';
var speedMul = 5;
var guess = 31;
var guessLocked = false;

var events = [];
var totalCollisions = 0;
var playIndex = 0;
var playTime = 0;
var animInterval = 0.04;
var simDone = false;
var running = false;
var cleared = false;
var piRevealed = false;

var wallXFrac = 0.12;
var floorYFrac = 0.7;
var smallW, bigW, smallH, bigH;

var smallX, smallV;
var bigX, bigV;

var flashes = [];
var countDisplay = 0;

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function resize() {
  dpr = window.devicePixelRatio || 1;
  W = C.width = window.innerWidth * dpr;
  H = C.height = window.innerHeight * dpr;
  C.style.width = window.innerWidth + 'px';
  C.style.height = window.innerHeight + 'px';
  computeSizes();
}

function computeSizes() {
  var unit = Math.min(W, H) * 0.06;
  smallW = unit * 1.2;
  smallH = unit * 1.2;
  bigW = Math.min(unit * 2.5, W * 0.18);
  bigH = unit * 2.5;
}

function wallX() { return W * wallXFrac; }
function floorY() { return H * floorYFrac; }

function computeCollisions(M) {
  if (M <= 0) return 0;
  return Math.floor(Math.PI / Math.atan(1 / Math.sqrt(M)));
}

function simulate() {
  events = [];
  totalCollisions = 0;
  var m1 = 1;
  var m2 = massRatio;
  var v1 = 0;
  var v2 = -1;
  var x1 = 0.5;
  var x2 = 0.85;
  var maxIter = computeCollisions(massRatio) + 10;

  for (var i = 0; i < maxIter && i < 100000; i++) {
    var tWall = (v1 < 0) ? -x1 / v1 : Infinity;
    var dv = v1 - v2;
    var dx = x1 - x2;
    var tBlock = (dv < 0) ? Infinity : (dx > 0 ? Infinity : -dx / dv);
    if (dv > 0) tBlock = -dx / dv;
    else tBlock = Infinity;
    if (v1 < v2) tBlock = (x2 - x1) / (v1 - v2);
    else tBlock = Infinity;

    if (tWall === Infinity && tBlock === Infinity) break;

    if (tWall <= 0) tWall = Infinity;
    if (tBlock <= 0 || tBlock !== tBlock) tBlock = Infinity;

    if (tWall < tBlock) {
      x1 += v1 * tWall;
      x2 += v2 * tWall;
      x1 = 0;
      v1 = -v1;
      totalCollisions++;
      events.push({ t: (events.length > 0 ? events[events.length-1].t : 0) + tWall, type: 'wall', x1: x1, x2: x2, v1: v1, v2: v2 });
    } else {
      x1 += v1 * tBlock;
      x2 += v2 * tBlock;
      var newV1 = ((m1 - m2) * v1 + 2 * m2 * v2) / (m1 + m2);
      var newV2 = ((m2 - m1) * v2 + 2 * m1 * v1) / (m1 + m2);
      v1 = newV1;
      v2 = newV2;
      totalCollisions++;
      events.push({ t: (events.length > 0 ? events[events.length-1].t : 0) + tBlock, type: 'block', x1: x1, x2: x2, v1: v1, v2: v2 });
    }

    if (v1 >= 0 && v2 >= 0 && v2 >= v1) break;
  }
}

function initPositions() {
  var wX = wallX();
  var range = W - wX - bigW - 20 * dpr;
  smallX = wX + range * 0.35;
  bigX = wX + range * 0.7;
  smallV = 0;
  bigV = 0;
  countDisplay = 0;
  flashes = [];
  playIndex = 0;
  playTime = 0;
  simDone = false;
  running = false;
  piRevealed = false;
  guessLocked = false;
  document.getElementById('h-pi').classList.remove('show');
  document.getElementById('h-pi').textContent = '';
  document.getElementById('h-count').textContent = '0';
  updateGuessRange();
}

function updateGuessRange() {
  var expected = computeCollisions(massRatio);
  var sl = document.getElementById('sl-guess');
  sl.max = Math.max(expected * 2, 20);
  sl.value = Math.round(expected * 0.8);
  guess = parseInt(sl.value);
  document.getElementById('sv-guess').textContent = guess;
}

function launch() {
  if (running) return;
  simulate();
  if (totalCollisions === 0) return;
  animInterval = Math.max(0.008, Math.min(0.25, 4.0 / totalCollisions));
  running = true;
  playIndex = 0;
  playTime = 0;
  countDisplay = 0;
  flashes = [];
  piRevealed = false;
  document.getElementById('h-pi').classList.remove('show');
  document.getElementById('h-pi').textContent = '';

  var wX = wallX();
  var range = W - wX - bigW - 20 * dpr;
  smallX = wX + range * 0.35;
  bigX = wX + range * 0.7;
  smallV = 0;
  bigV = -1;
  SFX.play('spawn');
}

function posForEvent(idx) {
  if (idx < 0) {
    return { x1: 0.5, x2: 0.85, v1: 0, v2: -1 };
  }
  return events[idx];
}

function blockScreenX(normX) {
  var wX = wallX();
  var range = W - wX - bigW - 20 * dpr;
  return wX + normX * range;
}

function updateSim(dt) {
  if (!running || simDone) return;
  playTime += dt;

  while (playIndex < events.length) {
    var evTime = playIndex * animInterval;
    if (playTime < evTime) {
      var prevEv = posForEvent(playIndex - 1);
      var frac = 0;
      if (animInterval > 0) {
        var prevTime = (playIndex - 1) * animInterval;
        if (playIndex === 0) prevTime = 0;
        frac = (playTime - prevTime) / animInterval;
        if (frac < 0) frac = 0;
        if (frac > 1) frac = 1;
      }
      var startState = playIndex === 0 ? { x1: 0.5, x2: 0.85 } : events[playIndex - 1];
      var endState = events[playIndex];
      smallX = blockScreenX(startState.x1 + (endState.x1 - startState.x1) * frac);
      bigX = blockScreenX(startState.x2 + (endState.x2 - startState.x2) * frac);
      break;
    }

    var ev = events[playIndex];
    countDisplay = playIndex + 1;
    document.getElementById('h-count').textContent = countDisplay;
    smallX = blockScreenX(ev.x1);
    bigX = blockScreenX(ev.x2);

    if (ev.type === 'wall') {
      flashes.push({ x: wallX(), y: floorY() - smallH * 0.5, life: 1, kind: 'wall' });
      SFX.play('collide', 30);
    } else {
      var cx = (smallX + smallW + bigX) * 0.5;
      flashes.push({ x: cx, y: floorY() - Math.max(smallH, bigH) * 0.5, life: 1, kind: 'block' });
      SFX.play('tick', 30);
    }

    playIndex++;
    if (playIndex >= events.length) {
      simDone = true;
      onSimDone();
      break;
    }
  }
}

function onSimDone() {
  SFX.play('complete');
  var piStr = '';
  var nc = totalCollisions;
  var digits = nc.toString();
  var nExp = Math.round(Math.log10(massRatio));
  var dCount = Math.floor(nExp / 2) + 1;
  if (dCount >= 1 && digits.length >= dCount) {
    piStr = digits[0] + (dCount > 1 ? '.' + digits.substring(1, dCount) : '');
  } else {
    piStr = digits;
  }
  var piEl = document.getElementById('h-pi');
  piEl.textContent = 'π ≈ ' + piStr + '  (' + totalCollisions + ' hits)';
  piEl.classList.add('show');
  piRevealed = true;

  if (mode === 'GUESS' && guessLocked && guess === totalCollisions && !cleared) {
    cleared = true;
    if (window.PULSE) PULSE.clear();
    SFX.play('success');
  }
}

function drawWall() {
  var wX = wallX();
  var fy = floorY();
  ctx.strokeStyle = 'rgba(255,255,255,0.35)';
  ctx.lineWidth = 3 * dpr;
  ctx.beginPath();
  ctx.moveTo(wX, fy);
  ctx.lineTo(wX, fy - H * 0.45);
  ctx.stroke();

  ctx.strokeStyle = 'rgba(255,255,255,0.12)';
  ctx.lineWidth = 1 * dpr;
  var hashLen = 10 * dpr;
  for (var y = fy; y > fy - H * 0.45; y -= 12 * dpr) {
    ctx.beginPath();
    ctx.moveTo(wX, y);
    ctx.lineTo(wX - hashLen, y + hashLen * 0.7);
    ctx.stroke();
  }
}

function drawFloor() {
  var fy = floorY();
  ctx.strokeStyle = 'rgba(255,255,255,0.2)';
  ctx.lineWidth = 2 * dpr;
  ctx.beginPath();
  ctx.moveTo(wallX() - 10 * dpr, fy);
  ctx.lineTo(W, fy);
  ctx.stroke();
}

function drawBlock(x, y, w, h, hue, label) {
  var sat = 80, lit = 50;
  ctx.fillStyle = 'hsla(' + hue + ',' + sat + '%,' + lit + '%,0.85)';
  ctx.fillRect(x, y - h, w, h);

  ctx.strokeStyle = 'hsla(' + hue + ',' + sat + '%,' + (lit + 15) + '%,0.6)';
  ctx.lineWidth = 1.5 * dpr;
  ctx.strokeRect(x, y - h, w, h);

  if (label) {
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.font = (10 * dpr) + 'px ui-monospace,monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, x + w * 0.5, y - h * 0.5);
  }
}

function drawFlashes() {
  for (var i = flashes.length - 1; i >= 0; i--) {
    var f = flashes[i];
    f.life -= 0.035;
    if (f.life <= 0) { flashes.splice(i, 1); continue; }
    var r = (1 - f.life) * 40 * dpr;
    var alpha = f.life * 0.6;
    var col = f.kind === 'wall' ? 'rgba(255,255,255,' : 'rgba(0,245,212,';
    ctx.beginPath();
    ctx.arc(f.x, f.y, r, 0, Math.PI * 2);
    ctx.fillStyle = col + alpha * 0.3 + ')';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(f.x, f.y, r * 0.4, 0, Math.PI * 2);
    ctx.fillStyle = col + alpha + ')';
    ctx.fill();
  }
}

function drawCount() {
  var cx = W * 0.5;
  var cy = H * 0.25;
  ctx.fillStyle = 'rgba(0,245,212,0.12)';
  ctx.font = 'bold ' + (Math.min(W, H) * 0.18) + 'px "Space Grotesk",sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(countDisplay, cx, cy);

  if (piRevealed) {
    ctx.fillStyle = 'rgba(0,245,212,0.4)';
    ctx.font = (Math.min(W, H) * 0.04) + 'px "Space Grotesk",sans-serif';
    ctx.fillText('π is hiding in the count', cx, cy + Math.min(W, H) * 0.12);
  }
}

function drawPhaseSpace() {
  if (mode !== 'PHASE') return;
  var cx = W * 0.78;
  var cy = H * 0.28;
  var rad = Math.min(W, H) * 0.12;

  ctx.strokeStyle = 'rgba(255,255,255,0.1)';
  ctx.lineWidth = 1 * dpr;
  ctx.beginPath();
  ctx.arc(cx, cy, rad, 0, Math.PI * 2);
  ctx.stroke();

  ctx.strokeStyle = 'rgba(255,255,255,0.06)';
  ctx.beginPath();
  ctx.moveTo(cx - rad * 1.1, cy);
  ctx.lineTo(cx + rad * 1.1, cy);
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(cx, cy - rad * 1.1);
  ctx.lineTo(cx, cy + rad * 1.1);
  ctx.stroke();

  ctx.fillStyle = 'rgba(255,255,255,0.25)';
  ctx.font = (8 * dpr) + 'px ui-monospace,monospace';
  ctx.textAlign = 'center';
  ctx.fillText('v₁', cx + rad * 1.15, cy + 4 * dpr);
  ctx.fillText('v₂·√M', cx, cy - rad * 1.1);

  if (events.length === 0) return;

  var sqM = Math.sqrt(massRatio);
  var maxV = 1.2;

  ctx.strokeStyle = 'rgba(0,245,212,0.5)';
  ctx.lineWidth = 1.5 * dpr;
  ctx.beginPath();
  var first = true;
  var initV1 = 0, initV2 = -1;
  var px = cx + (initV1 / maxV) * rad;
  var py = cy - (initV2 * sqM / maxV) * rad;
  ctx.moveTo(px, py);

  for (var i = 0; i < events.length && i <= playIndex; i++) {
    var ev = events[i];
    px = cx + (ev.v1 / maxV) * rad;
    py = cy - (ev.v2 * sqM / maxV) * rad;
    ctx.lineTo(px, py);
  }
  ctx.stroke();

  if (playIndex > 0 && playIndex <= events.length) {
    var last = events[Math.min(playIndex - 1, events.length - 1)];
    var lx = cx + (last.v1 / maxV) * rad;
    var ly = cy - (last.v2 * sqM / maxV) * rad;
    ctx.beginPath();
    ctx.arc(lx, ly, 4 * dpr, 0, Math.PI * 2);
    ctx.fillStyle = '#00f5d4';
    ctx.fill();
  }
}

function drawGuessUI() {
  if (mode !== 'GUESS') return;
  if (simDone || !guessLocked) return;
  var cx = W * 0.5;
  var cy = H * 0.15;
  ctx.fillStyle = 'rgba(181,140,255,0.3)';
  ctx.font = (12 * dpr) + 'px "Space Grotesk",sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText('YOUR GUESS: ' + guess, cx, cy);
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  drawWall();
  drawFloor();
  drawFlashes();

  var fy = floorY();

  drawBlock(smallX, fy, smallW, smallH, 175, 'm');
  drawBlock(bigX, fy, bigW, bigH, 280, 'M');

  drawCount();
  drawPhaseSpace();
  drawGuessUI();
}

var lastTime = 0;
function loop(ts) {
  requestAnimationFrame(loop);
  var dt = lastTime ? (ts - lastTime) / 1000 : 0;
  lastTime = ts;
  if (dt > 0.1) dt = 0.1;

  var steps = Math.max(1, Math.round(speedMul / 5));
  for (var s = 0; s < steps; s++) {
    updateSim(dt / steps);
  }
  draw();
}

var modeBtns = document.querySelectorAll('[data-mode]');
modeBtns.forEach(function(b) {
  b.addEventListener('click', function() {
    modeBtns.forEach(function(bb) { bb.classList.remove('active'); });
    b.classList.add('active');
    mode = b.dataset.mode;
    document.getElementById('h-mode').textContent = mode;
    document.getElementById('guess-wrap').style.display = mode === 'GUESS' ? '' : 'none';
    SFX.play('toggle');
  });
});

var massBtns = document.querySelectorAll('[data-mass]');
massBtns.forEach(function(b) {
  b.addEventListener('click', function() {
    massBtns.forEach(function(bb) { bb.classList.remove('active'); });
    b.classList.add('active');
    massRatio = parseInt(b.dataset.mass);
    var label = massRatio >= 1000000 ? '1:10⁶' : massRatio >= 10000 ? '1:10⁴' : '1:' + massRatio;
    document.getElementById('h-mass').textContent = label;
    initPositions();
    SFX.play('click');
  });
});

var slSpeed = document.getElementById('sl-speed');
var svSpeed = document.getElementById('sv-speed');
slSpeed.addEventListener('input', function() {
  speedMul = parseInt(slSpeed.value);
  svSpeed.textContent = speedMul;
});

var slGuess = document.getElementById('sl-guess');
var svGuess = document.getElementById('sv-guess');
slGuess.addEventListener('input', function() {
  if (guessLocked) return;
  guess = parseInt(slGuess.value);
  svGuess.textContent = guess;
});

document.getElementById('btn-lock').addEventListener('click', function() {
  if (guessLocked) return;
  guessLocked = true;
  this.classList.add('active');
  this.textContent = 'LOCKED: ' + guess;
  SFX.play('tick');
});

document.getElementById('btn-launch').addEventListener('click', function() {
  launch();
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initPositions();
  SFX.play('click');
});

C.addEventListener('click', function() {
  if (!running && !simDone) launch();
});

C.addEventListener('touchend', function(e) {
  if (!running && !simDone) {
    e.preventDefault();
    launch();
  }
});

window.addEventListener('resize', function() { resize(); });

resize();
initPositions();
setTimeout(function() { launch(); }, 600);
requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
