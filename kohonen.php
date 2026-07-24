<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('kohonen');

$pageName = 'kohonen';
$pageTitle = 'KOHONEN - PULSE';
$pageDesc = 'コホネン自己組織化写像。ニューラル格子がデータの形に自ら変形し、トポロジーを保存した地図を描く。';
$pageCanonical = 'https://pulse.subara3.com/kohonen.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Orbitron",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>STEP <span id="hud-step">0</span></div>
  <div>LR <span id="hud-lr">0.500</span></div>
  <div>σ <span id="hud-sigma">10.0</span></div>
  <div>DATA <span id="hud-data">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add data'],
  ['type' => 'drag', 'label' => 'draw data'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-spiral">SPIRAL</button>
    <button class="ctrl-btn" id="btn-circle">CIRCLE</button>
    <button class="ctrl-btn" id="btn-cluster">CLUSTER</button>
    <button class="ctrl-btn" id="btn-cross">CROSS</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">GRID</label>
    <input type="range" id="sl-grid" min="5" max="30" value="15">
    <span class="slider-value" id="sv-grid">15×15</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="50" value="10">
    <span class="slider-value" id="sv-speed">10</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">RATE</label>
    <input type="range" id="sl-lr" min="5" max="100" value="50">
    <span class="slider-value" id="sv-lr">0.50</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-umatrix">U-MTX</button>
    <button class="ctrl-btn active" id="btn-play">PLAY</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H;

  var hudStep = document.getElementById('hud-step');
  var hudLR = document.getElementById('hud-lr');
  var hudSigma = document.getElementById('hud-sigma');
  var hudData = document.getElementById('hud-data');

  var gridW = 15, gridH = 15;
  var neurons = [];
  var nGlow = [];
  var dataPoints = [];
  var step = 0;
  var maxSteps = 15000;
  var initLR = 0.5;
  var initSigma;
  var stepsPerFrame = 10;
  var isPlaying = true;
  var showUMatrix = false;
  var bmuI = -1, bmuJ = -1;
  var lastInput = null;

  function resize() {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function initGrid() {
    neurons = [];
    nGlow = [];
    step = 0;
    bmuI = -1;
    bmuJ = -1;
    lastInput = null;
    initSigma = Math.max(gridW, gridH) / 2;
    var cx = W / 2, cy = H / 2;
    var spread = Math.min(W, H) * 0.06;
    for (var i = 0; i < gridH; i++) {
      neurons[i] = [];
      nGlow[i] = [];
      for (var j = 0; j < gridW; j++) {
        var fx = gridW > 1 ? j / (gridW - 1) : 0.5;
        var fy = gridH > 1 ? i / (gridH - 1) : 0.5;
        neurons[i][j] = {
          x: cx + (fx - 0.5) * spread,
          y: cy + (fy - 0.5) * spread
        };
        nGlow[i][j] = 0;
      }
    }
  }

  function genSpiral(n) {
    dataPoints = [];
    var cx = W / 2, cy = H / 2;
    var maxR = Math.min(W, H) * 0.35;
    for (var k = 0; k < n; k++) {
      var t = k / n;
      var angle = t * Math.PI * 4.5;
      var r = (0.08 + t * 0.92) * maxR + (Math.random() - 0.5) * 12;
      dataPoints.push({ x: cx + Math.cos(angle) * r, y: cy + Math.sin(angle) * r });
    }
  }

  function genCircle(n) {
    dataPoints = [];
    var cx = W / 2, cy = H / 2;
    var r = Math.min(W, H) * 0.32;
    for (var k = 0; k < n; k++) {
      var angle = Math.random() * Math.PI * 2;
      var rr = r + (Math.random() - 0.5) * 18;
      dataPoints.push({ x: cx + Math.cos(angle) * rr, y: cy + Math.sin(angle) * rr });
    }
  }

  function genClusters(n) {
    dataPoints = [];
    var cx = W / 2, cy = H / 2;
    var span = Math.min(W, H) * 0.28;
    var centers = [
      { x: cx - span * 0.6, y: cy - span * 0.5 },
      { x: cx + span * 0.55, y: cy - span * 0.35 },
      { x: cx + span * 0.15, y: cy + span * 0.55 },
      { x: cx - span * 0.45, y: cy + span * 0.4 }
    ];
    for (var k = 0; k < n; k++) {
      var c = centers[Math.floor(Math.random() * centers.length)];
      var u1 = Math.random() || 1e-10, u2 = Math.random();
      var g1 = Math.sqrt(-2 * Math.log(u1)) * Math.cos(2 * Math.PI * u2);
      var g2 = Math.sqrt(-2 * Math.log(u1)) * Math.sin(2 * Math.PI * u2);
      dataPoints.push({ x: c.x + g1 * span * 0.09, y: c.y + g2 * span * 0.09 });
    }
  }

  function genCross(n) {
    dataPoints = [];
    var cx = W / 2, cy = H / 2;
    var len = Math.min(W, H) * 0.32;
    var w = len * 0.05;
    for (var k = 0; k < n; k++) {
      if (Math.random() < 0.5) {
        dataPoints.push({
          x: cx + (Math.random() - 0.5) * len * 2,
          y: cy + (Math.random() - 0.5) * w * 2
        });
      } else {
        dataPoints.push({
          x: cx + (Math.random() - 0.5) * w * 2,
          y: cy + (Math.random() - 0.5) * len * 2
        });
      }
    }
  }

  function trainStep() {
    if (dataPoints.length === 0) return;
    var idx = Math.floor(Math.random() * dataPoints.length);
    var input = dataPoints[idx];
    lastInput = input;

    var bestDist = Infinity;
    bmuI = 0; bmuJ = 0;
    for (var i = 0; i < gridH; i++) {
      for (var j = 0; j < gridW; j++) {
        var dx = neurons[i][j].x - input.x;
        var dy = neurons[i][j].y - input.y;
        var d = dx * dx + dy * dy;
        if (d < bestDist) { bestDist = d; bmuI = i; bmuJ = j; }
      }
    }

    var t = Math.min(step / maxSteps, 1);
    var lr = initLR * Math.exp(-3 * t);
    var sigma = initSigma * Math.exp(-3 * t) + 0.3;
    var s2inv = 1 / (2 * sigma * sigma);

    for (var i = 0; i < gridH; i++) {
      for (var j = 0; j < gridW; j++) {
        var di = i - bmuI, dj = j - bmuJ;
        var inf = Math.exp(-(di * di + dj * dj) * s2inv);
        if (inf > 0.001) {
          var n = neurons[i][j];
          n.x += lr * inf * (input.x - n.x);
          n.y += lr * inf * (input.y - n.y);
          nGlow[i][j] = Math.max(nGlow[i][j], inf);
        }
      }
    }

    if (step < maxSteps) step++;
  }

  function eDist(a, b) {
    var dx = a.x - b.x, dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    X.fillStyle = 'rgba(123,44,191,0.35)';
    for (var k = 0; k < dataPoints.length; k++) {
      X.beginPath();
      X.arc(dataPoints[k].x, dataPoints[k].y, 2.5, 0, Math.PI * 2);
      X.fill();
    }

    if (showUMatrix && gridH > 1 && gridW > 1) {
      var refD = Math.min(W, H) * 0.55 / Math.max(gridW, gridH);
      for (var i = 0; i < gridH - 1; i++) {
        for (var j = 0; j < gridW - 1; j++) {
          var n00 = neurons[i][j], n01 = neurons[i][j + 1];
          var n10 = neurons[i + 1][j], n11 = neurons[i + 1][j + 1];
          var avg = (eDist(n00, n01) + eDist(n00, n10) + eDist(n01, n11) + eDist(n10, n11)) / 4;
          var u = Math.min(1, avg / (refD * 1.5));
          X.beginPath();
          X.moveTo(n00.x, n00.y);
          X.lineTo(n01.x, n01.y);
          X.lineTo(n11.x, n11.y);
          X.lineTo(n10.x, n10.y);
          X.closePath();
          X.fillStyle = 'rgba(' + (50 + (u * 73) | 0) + ',' + (18 + (u * 26) | 0) + ',' + (90 + (u * 101) | 0) + ',' + (0.08 + u * 0.35).toFixed(2) + ')';
          X.fill();
        }
      }
    }

    X.lineWidth = 0.8;
    for (var i = 0; i < gridH; i++) {
      for (var j = 0; j < gridW; j++) {
        var n = neurons[i][j];
        if (j < gridW - 1) {
          var n2 = neurons[i][j + 1];
          var d = eDist(n, n2);
          var a = Math.max(0.06, Math.min(0.5, 0.5 - d * 0.002));
          X.beginPath();
          X.moveTo(n.x, n.y);
          X.lineTo(n2.x, n2.y);
          X.strokeStyle = 'rgba(0,245,212,' + a.toFixed(2) + ')';
          X.stroke();
        }
        if (i < gridH - 1) {
          var n2 = neurons[i + 1][j];
          var d = eDist(n, n2);
          var a = Math.max(0.06, Math.min(0.5, 0.5 - d * 0.002));
          X.beginPath();
          X.moveTo(n.x, n.y);
          X.lineTo(n2.x, n2.y);
          X.strokeStyle = 'rgba(0,245,212,' + a.toFixed(2) + ')';
          X.stroke();
        }
      }
    }

    var training = isPlaying && step < maxSteps;
    for (var i = 0; i < gridH; i++) {
      for (var j = 0; j < gridW; j++) {
        var n = neurons[i][j];
        var g = nGlow[i][j];
        var isBMU = training && i === bmuI && j === bmuJ;
        var r = isBMU ? 4 : (1.5 + g * 2.5);
        var alpha = isBMU ? 1 : (0.4 + g * 0.6);
        X.beginPath();
        X.arc(n.x, n.y, r, 0, Math.PI * 2);
        X.fillStyle = 'rgba(0,245,212,' + alpha.toFixed(2) + ')';
        X.fill();
        if (isBMU) {
          X.beginPath();
          X.arc(n.x, n.y, 10, 0, Math.PI * 2);
          X.fillStyle = 'rgba(0,245,212,0.1)';
          X.fill();
        }
        nGlow[i][j] *= 0.85;
      }
    }

    if (training && lastInput && bmuI >= 0) {
      X.beginPath();
      X.arc(lastInput.x, lastInput.y, 5, 0, Math.PI * 2);
      X.strokeStyle = 'rgba(123,44,191,0.7)';
      X.lineWidth = 1.5;
      X.stroke();

      X.beginPath();
      X.setLineDash([3, 6]);
      X.moveTo(neurons[bmuI][bmuJ].x, neurons[bmuI][bmuJ].y);
      X.lineTo(lastInput.x, lastInput.y);
      X.strokeStyle = 'rgba(0,245,212,0.12)';
      X.lineWidth = 0.5;
      X.stroke();
      X.setLineDash([]);
    }

    var t = Math.min(step / maxSteps, 1);
    hudStep.textContent = step;
    hudLR.textContent = (initLR * Math.exp(-3 * t)).toFixed(3);
    hudSigma.textContent = (initSigma * Math.exp(-3 * t) + 0.3).toFixed(1);
  }

  function frame() {
    if (isPlaying) {
      for (var k = 0; k < stepsPerFrame; k++) trainStep();
    }
    draw();
    requestAnimationFrame(frame);
  }

  var slGrid = document.getElementById('sl-grid');
  var slSpeed = document.getElementById('sl-speed');
  var slLR = document.getElementById('sl-lr');
  var svGrid = document.getElementById('sv-grid');
  var svSpeed = document.getElementById('sv-speed');
  var svLR = document.getElementById('sv-lr');
  var btnPlay = document.getElementById('btn-play');

  slGrid.addEventListener('input', function() {
    gridW = gridH = +this.value;
    svGrid.textContent = this.value + '×' + this.value;
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });

  slSpeed.addEventListener('input', function() {
    stepsPerFrame = +this.value;
    svSpeed.textContent = this.value;
  });

  slLR.addEventListener('input', function() {
    initLR = +this.value / 100;
    svLR.textContent = initLR.toFixed(2);
  });

  btnPlay.addEventListener('click', function() {
    isPlaying = !isPlaying;
    this.textContent = isPlaying ? 'PLAY' : 'PAUSE';
    this.classList.toggle('active', isPlaying);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('btn-umatrix').addEventListener('click', function() {
    showUMatrix = !showUMatrix;
    this.classList.toggle('active', showUMatrix);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  var presetIds = ['btn-spiral', 'btn-circle', 'btn-cluster', 'btn-cross'];
  var presetFns = [
    function() { genSpiral(500); },
    function() { genCircle(500); },
    function() { genClusters(500); },
    function() { genCross(500); }
  ];

  presetIds.forEach(function(id, idx) {
    document.getElementById(id).addEventListener('click', function() {
      presetIds.forEach(function(pid) { document.getElementById(pid).classList.remove('active'); });
      this.classList.add('active');
      presetFns[idx]();
      initGrid();
      hudData.textContent = dataPoints.length;
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  var dragging = false;
  var lastAddTime = 0;

  function boostTraining() {
    if (step > maxSteps * 0.4) step = Math.floor(maxSteps * 0.4);
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    dragging = true;
    var rect = C.getBoundingClientRect();
    dataPoints.push({ x: e.clientX - rect.left, y: e.clientY - rect.top });
    hudData.textContent = dataPoints.length;
    boostTraining();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var now = Date.now();
    if (now - lastAddTime < 30) return;
    lastAddTime = now;
    var rect = C.getBoundingClientRect();
    dataPoints.push({ x: e.clientX - rect.left, y: e.clientY - rect.top });
    hudData.textContent = dataPoints.length;
    boostTraining();
  });

  C.addEventListener('mouseup', function() { dragging = false; });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    dataPoints = [];
    initGrid();
    hudData.textContent = 0;
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    dragging = true;
    var touch = e.touches[0];
    var rect = C.getBoundingClientRect();
    dataPoints.push({ x: touch.clientX - rect.left, y: touch.clientY - rect.top });
    hudData.textContent = dataPoints.length;
    boostTraining();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!dragging) return;
    var now = Date.now();
    if (now - lastAddTime < 40) return;
    lastAddTime = now;
    var touch = e.touches[0];
    var rect = C.getBoundingClientRect();
    dataPoints.push({ x: touch.clientX - rect.left, y: touch.clientY - rect.top });
    hudData.textContent = dataPoints.length;
    boostTraining();
  }, { passive: false });

  C.addEventListener('touchend', function() { dragging = false; });

  resize();
  window.addEventListener('resize', function() {
    var oW = W, oH = H;
    resize();
    if (oW > 0 && oH > 0) {
      var sx = W / oW, sy = H / oH;
      for (var k = 0; k < dataPoints.length; k++) {
        dataPoints[k].x *= sx;
        dataPoints[k].y *= sy;
      }
      for (var i = 0; i < gridH; i++) {
        for (var j = 0; j < gridW; j++) {
          neurons[i][j].x *= sx;
          neurons[i][j].y *= sy;
        }
      }
    }
  });

  genSpiral(500);
  initGrid();
  hudData.textContent = dataPoints.length;
  frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
