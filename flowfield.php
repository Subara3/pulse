<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('flowfield');

$pageName = 'flowfield';
$pageTitle = 'FLOWFIELD - PULSE';
$pageDesc = 'ノイズ場に従うパーティクル。Perlinノイズが生む流れに粒子が乗り、有機的な軌跡を描く。';
$pageCanonical = 'https://pulse.subara3.com/flowfield.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; cursor: crosshair; }
.ui { position: fixed; z-index: 100; font-family: "Space Grotesk", sans-serif; pointer-events: none; }
.hud { top: 60px; right: 20px; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.hud .sub { color: #c455ff; }
.info { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.2); font-size: 0.6rem; letter-spacing: 0.05em; line-height: 1.8; }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 50px; right: 0.5rem; }
  .info { bottom: 1rem; left: 1rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud" id="hud">
  PARTICLES: <span id="hudParticles" class="highlight">2000</span><br>
  PALETTE: <span id="hudPalette" class="sub">AURORA</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'disturb'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">SCALE</label>
      <input type="range" id="scaleSlider" min="20" max="100" value="50">
      <span class="slider-value" id="scaleVal">0.005</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">SPEED</label>
      <input type="range" id="speedSlider" min="5" max="30" value="15">
      <span class="slider-value" id="speedVal">1.5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">PARTICLES</label>
      <input type="range" id="particleSlider" min="500" max="4000" step="100" value="2000">
      <span class="slider-value" id="particleVal">2000</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="paletteBtn">PALETTE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
'use strict';

var canvas = document.getElementById('canvas');
var ctx = canvas.getContext('2d');
var W, H;

// ===== Perlin Noise (permutation-based, inline) =====
var perm = new Uint8Array(512);
var grad3 = [
  [1,1,0],[-1,1,0],[1,-1,0],[-1,-1,0],
  [1,0,1],[-1,0,1],[1,0,-1],[-1,0,-1],
  [0,1,1],[0,-1,1],[0,1,-1],[0,-1,-1]
];

function initPerm() {
  var p = [];
  for (var i = 0; i < 256; i++) p[i] = i;
  for (var i = 255; i > 0; i--) {
    var j = Math.floor(Math.random() * (i + 1));
    var tmp = p[i]; p[i] = p[j]; p[j] = tmp;
  }
  for (var i = 0; i < 512; i++) perm[i] = p[i & 255];
}

function fade(t) { return t * t * t * (t * (t * 6 - 15) + 10); }
function lerp(a, b, t) { return a + t * (b - a); }
function dot3(g, x, y, z) { return g[0]*x + g[1]*y + g[2]*z; }

function noise3D(x, y, z) {
  var X = Math.floor(x) & 255;
  var Y = Math.floor(y) & 255;
  var Z = Math.floor(z) & 255;
  x -= Math.floor(x);
  y -= Math.floor(y);
  z -= Math.floor(z);
  var u = fade(x), v = fade(y), w = fade(z);

  var A  = perm[X] + Y;
  var AA = perm[A] + Z;
  var AB = perm[A + 1] + Z;
  var B  = perm[X + 1] + Y;
  var BA = perm[B] + Z;
  var BB = perm[B + 1] + Z;

  return lerp(
    lerp(
      lerp(dot3(grad3[perm[AA] % 12], x, y, z),
           dot3(grad3[perm[BA] % 12], x-1, y, z), u),
      lerp(dot3(grad3[perm[AB] % 12], x, y-1, z),
           dot3(grad3[perm[BB] % 12], x-1, y-1, z), u), v),
    lerp(
      lerp(dot3(grad3[perm[AA+1] % 12], x, y, z-1),
           dot3(grad3[perm[BA+1] % 12], x-1, y, z-1), u),
      lerp(dot3(grad3[perm[AB+1] % 12], x, y-1, z-1),
           dot3(grad3[perm[BB+1] % 12], x-1, y-1, z-1), u), v), w);
}

initPerm();

// ===== Palettes =====
var palettes = [
  { name: 'AURORA', hueMin: 170, hueMax: 280 },
  { name: 'OCEAN',  hueMin: 170, hueMax: 200 },
  { name: 'NEBULA', hueMin: 240, hueMax: 290 }
];
var paletteIndex = 0;

// ===== Parameters =====
var noiseScale = 0.005;
var speedMult = 1.5;
var particleCount = 2000;
var zOff = 0;

// ===== Disturbances =====
var disturbances = [];

// ===== Particles =====
var particles = [];

function createParticle() {
  return {
    x: Math.random() * W,
    y: Math.random() * H,
    vx: 0,
    vy: 0,
    speed: 0.5 + Math.random() * 0.5
  };
}

function initParticles(count) {
  particles = [];
  for (var i = 0; i < count; i++) {
    particles.push(createParticle());
  }
}

function adjustParticleCount(target) {
  while (particles.length < target) {
    particles.push(createParticle());
  }
  if (particles.length > target) {
    particles.length = target;
  }
  particleCount = target;
}

// ===== Resize =====
function resize() {
  W = canvas.width = window.innerWidth;
  H = canvas.height = window.innerHeight;
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
}

// ===== Update & Draw =====
function getAngle(x, y) {
  var n = noise3D(x * noiseScale, y * noiseScale, zOff);
  return n * Math.PI * 4;
}

function update() {
  var pal = palettes[paletteIndex];
  var hueRange = pal.hueMax - pal.hueMin;

  zOff += 0.0008;

  // Update disturbances
  for (var d = disturbances.length - 1; d >= 0; d--) {
    disturbances[d].life -= 1/60;
    if (disturbances[d].life <= 0) {
      disturbances.splice(d, 1);
    }
  }

  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    var angle = getAngle(p.x, p.y);
    var mag = speedMult * p.speed;

    p.vx = Math.cos(angle) * mag;
    p.vy = Math.sin(angle) * mag;

    // Apply disturbances
    for (var d = 0; d < disturbances.length; d++) {
      var dist = disturbances[d];
      var dx = p.x - dist.x;
      var dy = p.y - dist.y;
      var r = Math.sqrt(dx * dx + dy * dy);
      if (r < dist.radius && r > 0) {
        var force = (1 - r / dist.radius) * dist.strength * (dist.life / dist.maxLife);
        p.vx += (dx / r) * force;
        p.vy += (dy / r) * force;
      }
    }

    p.x += p.vx;
    p.y += p.vy;

    // Wrap
    if (p.x < 0) p.x += W;
    if (p.x > W) p.x -= W;
    if (p.y < 0) p.y += H;
    if (p.y > H) p.y -= H;

    // Color based on angle
    var hue = pal.hueMin + ((angle / (Math.PI * 4) + 0.5) % 1) * hueRange;
    var sat = 70 + Math.sin(angle) * 20;
    var light = 50 + Math.cos(angle * 0.5) * 15;

    ctx.fillStyle = 'hsl(' + hue + ',' + sat + '%,' + light + '%)';
    ctx.fillRect(p.x, p.y, 1.5, 1.5);
  }
}

function draw() {
  // Fade background
  ctx.fillStyle = 'rgba(10,10,15,0.05)';
  ctx.fillRect(0, 0, W, H);

  update();
}

// ===== Animation Loop =====
function loop() {
  draw();
  requestAnimationFrame(loop);
}

// ===== HUD =====
var hudParticles = document.getElementById('hudParticles');
var hudPalette = document.getElementById('hudPalette');

function updateHUD() {
  hudParticles.textContent = particles.length;
  hudPalette.textContent = palettes[paletteIndex].name;
}

// ===== Controls =====
var scaleSlider = document.getElementById('scaleSlider');
var speedSlider = document.getElementById('speedSlider');
var particleSlider = document.getElementById('particleSlider');
var scaleVal = document.getElementById('scaleVal');
var speedVal = document.getElementById('speedVal');
var particleVal = document.getElementById('particleVal');
var paletteBtn = document.getElementById('paletteBtn');
var resetBtn = document.getElementById('resetBtn');

scaleSlider.addEventListener('input', function() {
  noiseScale = this.value / 10000;
  scaleVal.textContent = noiseScale.toFixed(4);
});

speedSlider.addEventListener('input', function() {
  speedMult = this.value / 10;
  speedVal.textContent = speedMult.toFixed(1);
});

particleSlider.addEventListener('input', function() {
  var target = parseInt(this.value);
  adjustParticleCount(target);
  particleVal.textContent = target;
  updateHUD();
});

paletteBtn.addEventListener('click', function() {
  paletteIndex = (paletteIndex + 1) % palettes.length;
  updateHUD();
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});

resetBtn.addEventListener('click', function() {
  zOff = 0;
  initPerm();
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  initParticles(particleCount);
  disturbances = [];
  updateHUD();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

// ===== Click / Touch interaction =====
function addDisturbance(x, y) {
  disturbances.push({
    x: x,
    y: y,
    radius: 150,
    strength: 8,
    life: 2.0,
    maxLife: 2.0
  });
  if (typeof SFX !== 'undefined') SFX.play('ripple');
}

canvas.addEventListener('click', function(e) {
  addDisturbance(e.clientX, e.clientY);
});

canvas.addEventListener('touchstart', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.touches.length; i++) {
    addDisturbance(e.touches[i].clientX, e.touches[i].clientY);
  }
}, { passive: false });

window.addEventListener('resize', resize);

// ===== Init =====
resize();
initParticles(particleCount);
updateHUD();
requestAnimationFrame(loop);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
