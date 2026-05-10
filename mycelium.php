<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('mycelium');

$pageName = 'mycelium';
$pageTitle = 'MYCELIUM - PULSE';
$pageDesc = '粘菌アルゴリズム。粒子が化学の匂いを追い、ゆっくりと菌糸ネットワークを育てる。';
$pageCanonical = 'https://pulse.subara3.com/mycelium.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.info { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.22); font-size: 0.6rem; letter-spacing: 0.05em; line-height: 1.8; max-width: min(70vw, 420px); }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 0.6rem; right: 0.6rem; }
  .info { bottom: 1rem; left: 1rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
  ['type' => 'drag', 'label' => 'drag'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  AGENTS: <span id="hudAgents" class="highlight">0</span><br>
  DENSITY: <span id="hudDensity" class="highlight">0%</span><br>
  FLOW: <span id="hudFlow" class="highlight">CALM</span>
</div>

<div class="ui info">
  TAP / DRAG: FEED SPORES<br>
  THEY SENSE TRAILS, TURN, AND GROW A LIVING MAP<br>
  SLOW MOTION MODE FOR LONG WATCHING
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>AGENTS</label>
    <input type="range" id="agentSlider" min="1200" max="14000" step="200" value="5600">
  </div>
  <div class="slider-wrap">
    <label>SENSOR</label>
    <input type="range" id="sensorSlider" min="10" max="70" value="34">
  </div>
  <div class="slider-wrap">
    <label>DECAY</label>
    <input type="range" id="decaySlider" min="1" max="40" value="14">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="flowBtn">FLOW: CALM</button>
    <button class="control-btn" id="paletteBtn">PALETTE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudAgents = document.getElementById('hudAgents');
  var hudDensity = document.getElementById('hudDensity');
  var hudFlow = document.getElementById('hudFlow');

  var agentSlider = document.getElementById('agentSlider');
  var sensorSlider = document.getElementById('sensorSlider');
  var decaySlider = document.getElementById('decaySlider');
  var flowBtn = document.getElementById('flowBtn');
  var paletteBtn = document.getElementById('paletteBtn');
  var resetBtn = document.getElementById('resetBtn');

  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0;
  var H = 0;
  var GW = 0;
  var GH = 0;
  var SCALE = 2;

  var trailA, trailB;
  var imageData, imagePixels;
  var offCanvas, offCtx;
  var agents = [];

  var pointerDown = false;
  var pointerX = 0;
  var pointerY = 0;

  var flowMode = 0; // 0 calm, 1 drift
  var paletteMode = 0;

  var params = {
    sensorAngle: 0.6,
    sensorDist: 9,
    stepSize: 1.0,
    turnSpeed: 0.17,
    deposit: 1.0,
    diffuse: 0.17,
    decay: 0.028
  };

  var PALETTES = [
    function(v) {
      var t = Math.min(1, v * 1.35);
      return [
        4 + t * 20,
        14 + t * 220,
        20 + t * 190
      ];
    },
    function(v) {
      var t = Math.min(1, v * 1.25);
      return [
        8 + t * 120,
        10 + t * 50,
        18 + t * 200
      ];
    },
    function(v) {
      var t = Math.min(1, v * 1.4);
      return [
        8 + t * 190,
        14 + t * 190,
        20 + t * 210
      ];
    }
  ];

  function idx(x, y) {
    x = (x + GW) % GW;
    y = (y + GH) % GH;
    return y * GW + x;
  }

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    ctx.setTransform(1, 0, 0, 1, 0, 0);

    SCALE = W < 768 ? 3 : 2;
    GW = Math.max(120, Math.floor(canvas.width / SCALE));
    GH = Math.max(80, Math.floor(canvas.height / SCALE));

    trailA = new Float32Array(GW * GH);
    trailB = new Float32Array(GW * GH);
    offCanvas = document.createElement('canvas');
    offCanvas.width = GW;
    offCanvas.height = GH;
    offCtx = offCanvas.getContext('2d', { alpha: false });
    imageData = offCtx.createImageData(GW, GH);
    imagePixels = imageData.data;

    seedScene(true);
  }

  function makeAgent(x, y, angle) {
    return {
      x: x,
      y: y,
      a: angle
    };
  }

  function populateAgents(count) {
    agents.length = 0;
    var cx = GW * 0.5;
    var cy = GH * 0.5;
    var ring = Math.min(GW, GH) * 0.16;
    for (var i = 0; i < count; i++) {
      var t = (i / count) * Math.PI * 2;
      var r = ring * (0.6 + Math.random() * 0.6);
      var x = cx + Math.cos(t) * r;
      var y = cy + Math.sin(t) * r;
      agents.push(makeAgent(x, y, t + Math.PI + (Math.random() - 0.5) * 0.8));
    }
  }

  function feedAt(px, py, amount, radius) {
    var gx = Math.floor((px / W) * GW);
    var gy = Math.floor((py / H) * GH);
    var r = radius || 8;
    for (var y = -r; y <= r; y++) {
      for (var x = -r; x <= r; x++) {
        var d2 = x * x + y * y;
        if (d2 > r * r) continue;
        var p = idx(gx + x, gy + y);
        trailA[p] += amount * (1 - d2 / (r * r + 1));
      }
    }
  }

  function seedScene(hardReset) {
    if (hardReset) {
      for (var i = 0; i < trailA.length; i++) {
        trailA[i] = 0;
        trailB[i] = 0;
      }
    }

    populateAgents(parseInt(agentSlider.value, 10));

    var cx = GW * 0.5;
    var cy = GH * 0.5;
    for (var i = 0; i < 12; i++) {
      var t = (i / 12) * Math.PI * 2;
      feedAt((cx + Math.cos(t) * GW * 0.08) / GW * W, (cy + Math.sin(t) * GH * 0.08) / GH * H, 1.4, 14);
    }

    hudAgents.textContent = agents.length;
  }

  function sense(agent, ang) {
    var sx = Math.floor(agent.x + Math.cos(agent.a + ang) * params.sensorDist);
    var sy = Math.floor(agent.y + Math.sin(agent.a + ang) * params.sensorDist);
    return trailA[idx(sx, sy)];
  }

  function stepAgents() {
    var i;
    for (i = 0; i < agents.length; i++) {
      var ag = agents[i];

      var left = sense(ag, -params.sensorAngle);
      var forward = sense(ag, 0);
      var right = sense(ag, params.sensorAngle);

      if (forward > left && forward > right) {
      } else if (left > right) {
        ag.a -= params.turnSpeed * (0.6 + Math.random() * 0.4);
      } else if (right > left) {
        ag.a += params.turnSpeed * (0.6 + Math.random() * 0.4);
      } else {
        ag.a += (Math.random() - 0.5) * params.turnSpeed;
      }

      if (flowMode === 1) {
        var nx = (ag.x / GW - 0.5);
        var ny = (ag.y / GH - 0.5);
        ag.a += (Math.sin(nx * 8 + performance.now() * 0.00015) + Math.cos(ny * 7 - performance.now() * 0.0002)) * 0.007;
      }

      ag.x += Math.cos(ag.a) * params.stepSize;
      ag.y += Math.sin(ag.a) * params.stepSize;

      if (ag.x < 0) ag.x += GW;
      if (ag.x >= GW) ag.x -= GW;
      if (ag.y < 0) ag.y += GH;
      if (ag.y >= GH) ag.y -= GH;

      trailA[idx(ag.x | 0, ag.y | 0)] += params.deposit;
    }

    if (pointerDown) {
      feedAt(pointerX, pointerY, 1.5, W < 768 ? 8 : 10);
      if (typeof SFX !== 'undefined') SFX.play('move', 100);
    }
  }

  function diffuseAndDecay() {
    var d = params.diffuse;
    var keep = 1 - d;
    var decay = params.decay;
    var alive = 0;

    for (var y = 0; y < GH; y++) {
      var yOff = y * GW;
      for (var x = 0; x < GW; x++) {
        var p = yOff + x;
        var c = trailA[p];
        var n = trailA[idx(x, y - 1)];
        var s = trailA[idx(x, y + 1)];
        var e = trailA[idx(x + 1, y)];
        var w = trailA[idx(x - 1, y)];

        var v = c * keep + (n + s + e + w) * 0.25 * d;
        v *= (1 - decay);
        if (v < 0.0004) v = 0;
        trailB[p] = v;
        if (v > 0.03) alive++;
      }
    }

    var tmp = trailA;
    trailA = trailB;
    trailB = tmp;

    hudDensity.textContent = Math.round((alive / trailA.length) * 100) + '%';
  }

  function drawField() {
    var pal = PALETTES[paletteMode];

    for (var i = 0; i < trailA.length; i++) {
      var v = trailA[i];
      if (v > 1.3) v = 1.3;
      var rgb = pal(v);
      var o = i * 4;
      imagePixels[o] = rgb[0] | 0;
      imagePixels[o + 1] = rgb[1] | 0;
      imagePixels[o + 2] = rgb[2] | 0;
      imagePixels[o + 3] = 255;
    }

    offCtx.putImageData(imageData, 0, 0);

    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(offCanvas, 0, 0, canvas.width, canvas.height);

    if (pointerDown) {
      ctx.beginPath();
      ctx.arc(pointerX * DPR, pointerY * DPR, 30 * DPR, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(0,245,212,0.25)';
      ctx.lineWidth = 2 * DPR;
      ctx.stroke();
    }
  }

  function updateParams() {
    var sensorDeg = parseInt(sensorSlider.value, 10);
    params.sensorAngle = sensorDeg * Math.PI / 180;
    params.turnSpeed = 0.11 + sensorDeg / 500;

    var decayRaw = parseInt(decaySlider.value, 10);
    params.decay = 0.008 + decayRaw / 900;
    params.diffuse = 0.12 + decayRaw / 400;
  }

  function loop() {
    updateParams();

    // deliberately slow and watchable
    for (var i = 0; i < 2; i++) {
      stepAgents();
      diffuseAndDecay();
    }

    drawField();
    requestAnimationFrame(loop);
  }

  function onPointer(e) {
    var t = e.touches ? e.touches[0] : e;
    pointerX = t.clientX;
    pointerY = t.clientY;
  }

  canvas.addEventListener('pointerdown', function(e) {
    pointerDown = true;
    onPointer(e);
    feedAt(pointerX, pointerY, 2.2, 12);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  window.addEventListener('pointerup', function() {
    pointerDown = false;
  });

  canvas.addEventListener('pointermove', function(e) {
    onPointer(e);
  });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
  }, { passive: false });

  flowBtn.addEventListener('click', function() {
    flowMode = (flowMode + 1) % 2;
    var label = flowMode === 0 ? 'CALM' : 'DRIFT';
    flowBtn.textContent = 'FLOW: ' + label;
    hudFlow.textContent = label;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  paletteBtn.addEventListener('click', function() {
    paletteMode = (paletteMode + 1) % PALETTES.length;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });

  resetBtn.addEventListener('click', function() {
    seedScene(true);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  agentSlider.addEventListener('change', function() {
    populateAgents(parseInt(agentSlider.value, 10));
    hudAgents.textContent = agents.length;
    if (typeof SFX !== 'undefined') SFX.play('grow');
  });

  sensorSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  decaySlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  window.addEventListener('resize', resize);

  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
