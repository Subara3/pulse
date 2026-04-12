<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('diffgrow');

$pageName = 'diffgrow';
$pageTitle = 'DIFFGROW - PULSE';
$pageDesc = '微分成長。閉曲線が分裂と反発を繰り返し、珊瑚のような有機的形態へ自己組織化する。';
$pageCanonical = 'https://pulse.subara3.com/diffgrow.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 0.6rem; right: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add seed'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div class="ui hud" id="hud">
  NODES: <span id="hudNodes" class="highlight">0</span><br>
  CURVES: <span id="hudCurves" class="highlight">0</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>GROWTH</label>
    <input type="range" id="growthSlider" min="0" max="50" value="20">
  </div>
  <div class="slider-wrap">
    <label>REPULSION</label>
    <input type="range" id="repulsionSlider" min="10" max="80" value="30">
  </div>
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="5" value="2">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="seedBtn">SEED</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');

  var hudNodes = document.getElementById('hudNodes');
  var hudCurves = document.getElementById('hudCurves');

  var growthSlider = document.getElementById('growthSlider');
  var repulsionSlider = document.getElementById('repulsionSlider');
  var speedSlider = document.getElementById('speedSlider');
  var seedBtn = document.getElementById('seedBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0, cx = 0, cy = 0;
  var curves = [];
  var MAX_TOTAL_NODES = 2000;

  // Physics params (updated from sliders each frame)
  var maxDist = 10;
  var minDist = 3;
  var repulsionRadius = 25;
  var repulsionForce = 0.3;
  var attractionForce = 0.1;
  var smoothing = 0.1;
  var growthForce = 0.02;
  var stepsPerFrame = 2;

  // Spatial hash
  var CELL_SIZE = 30;
  var grid = {};

  function gridKey(x, y) {
    return (x / CELL_SIZE | 0) + ',' + (y / CELL_SIZE | 0);
  }

  function buildSpatialHash() {
    grid = {};
    for (var ci = 0; ci < curves.length; ci++) {
      var nodes = curves[ci];
      for (var ni = 0; ni < nodes.length; ni++) {
        var n = nodes[ni];
        var key = gridKey(n.x, n.y);
        if (!grid[key]) grid[key] = [];
        grid[key].push({ ci: ci, ni: ni, x: n.x, y: n.y });
      }
    }
  }

  function getNearby(x, y) {
    var result = [];
    var gx = x / CELL_SIZE | 0;
    var gy = y / CELL_SIZE | 0;
    for (var dx = -1; dx <= 1; dx++) {
      for (var dy = -1; dy <= 1; dy++) {
        var key = (gx + dx) + ',' + (gy + dy);
        var cell = grid[key];
        if (cell) {
          for (var i = 0; i < cell.length; i++) {
            result.push(cell[i]);
          }
        }
      }
    }
    return result;
  }

  function totalNodes() {
    var count = 0;
    for (var i = 0; i < curves.length; i++) count += curves[i].length;
    return count;
  }

  function createCircle(x, y, radius, nodeCount) {
    var nodes = [];
    for (var i = 0; i < nodeCount; i++) {
      var angle = (i / nodeCount) * Math.PI * 2;
      // Small random perturbation
      var r = radius + (Math.random() - 0.5) * 2;
      nodes.push({
        x: x + Math.cos(angle) * r,
        y: y + Math.sin(angle) * r,
        vx: 0,
        vy: 0,
        age: 0
      });
    }
    return nodes;
  }

  function centroid(nodes) {
    var sx = 0, sy = 0;
    for (var i = 0; i < nodes.length; i++) {
      sx += nodes[i].x;
      sy += nodes[i].y;
    }
    return { x: sx / nodes.length, y: sy / nodes.length };
  }

  function simulate() {
    var total = totalNodes();

    for (var ci = 0; ci < curves.length; ci++) {
      var nodes = curves[ci];
      if (nodes.length < 3) continue;

      var cen = centroid(nodes);

      // Subdivision
      if (total < MAX_TOTAL_NODES) {
        var subdivided = false;
        for (var i = nodes.length - 1; i >= 0; i--) {
          var next = (i + 1) % nodes.length;
          var dx = nodes[next].x - nodes[i].x;
          var dy = nodes[next].y - nodes[i].y;
          var dist = Math.sqrt(dx * dx + dy * dy);
          if (dist > maxDist && total < MAX_TOTAL_NODES) {
            var newNode = {
              x: (nodes[i].x + nodes[next].x) * 0.5 + (Math.random() - 0.5) * 0.5,
              y: (nodes[i].y + nodes[next].y) * 0.5 + (Math.random() - 0.5) * 0.5,
              vx: 0,
              vy: 0,
              age: 0
            };
            nodes.splice(i + 1, 0, newNode);
            total++;
            subdivided = true;
          }
        }
        if (subdivided && typeof SFX !== 'undefined') {
          SFX.play('grow', 200);
        }
      }

      // Forces
      for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        var fx = 0, fy = 0;

        // Repulsion from nearby non-neighbors
        var nearby = getNearby(node.x, node.y);
        for (var j = 0; j < nearby.length; j++) {
          var other = nearby[j];
          // Skip self and direct neighbors in same curve
          if (other.ci === ci) {
            var diff = Math.abs(other.ni - i);
            var wrapDiff = nodes.length - diff;
            if (diff <= 1 || wrapDiff <= 1) continue;
          }
          var rdx = node.x - other.x;
          var rdy = node.y - other.y;
          var rd = Math.sqrt(rdx * rdx + rdy * rdy);
          if (rd < repulsionRadius && rd > 0.1) {
            var strength = repulsionForce * (1 - rd / repulsionRadius);
            fx += (rdx / rd) * strength;
            fy += (rdy / rd) * strength;
          }
        }

        // Attraction to neighbors (spring)
        var prev = (i - 1 + nodes.length) % nodes.length;
        var next = (i + 1) % nodes.length;

        var dpx = nodes[prev].x - node.x;
        var dpy = nodes[prev].y - node.y;
        var dpd = Math.sqrt(dpx * dpx + dpy * dpy);
        if (dpd > minDist) {
          fx += dpx * attractionForce;
          fy += dpy * attractionForce;
        }

        var dnx = nodes[next].x - node.x;
        var dny = nodes[next].y - node.y;
        var dnd = Math.sqrt(dnx * dnx + dny * dny);
        if (dnd > minDist) {
          fx += dnx * attractionForce;
          fy += dny * attractionForce;
        }

        // Smoothing (average with neighbors)
        var avgX = (nodes[prev].x + nodes[next].x) * 0.5;
        var avgY = (nodes[prev].y + nodes[next].y) * 0.5;
        fx += (avgX - node.x) * smoothing;
        fy += (avgY - node.y) * smoothing;

        // Growth force (outward from centroid)
        if (growthForce > 0) {
          var gx = node.x - cen.x;
          var gy = node.y - cen.y;
          var gd = Math.sqrt(gx * gx + gy * gy);
          if (gd > 1) {
            fx += (gx / gd) * growthForce;
            fy += (gy / gd) * growthForce;
          }
        }

        node.vx = (node.vx + fx) * 0.5;
        node.vy = (node.vy + fy) * 0.5;
      }

      // Apply velocities
      for (var i = 0; i < nodes.length; i++) {
        nodes[i].x += nodes[i].vx;
        nodes[i].y += nodes[i].vy;
        nodes[i].age++;
      }
    }
  }

  function render() {
    // Trail: alpha-fade background
    ctx.fillStyle = 'rgba(10,10,15,0.03)';
    ctx.fillRect(0, 0, W, H);

    for (var ci = 0; ci < curves.length; ci++) {
      var nodes = curves[ci];
      if (nodes.length < 3) continue;

      // Faint interior fill
      ctx.beginPath();
      ctx.moveTo(nodes[0].x, nodes[0].y);
      for (var i = 1; i < nodes.length; i++) {
        var prev = nodes[i - 1];
        var curr = nodes[i];
        var mx = (prev.x + curr.x) * 0.5;
        var my = (prev.y + curr.y) * 0.5;
        ctx.quadraticCurveTo(prev.x, prev.y, mx, my);
      }
      // Close curve smoothly
      var last = nodes[nodes.length - 1];
      var first = nodes[0];
      var mx = (last.x + first.x) * 0.5;
      var my = (last.y + first.y) * 0.5;
      ctx.quadraticCurveTo(last.x, last.y, mx, my);
      ctx.closePath();
      ctx.fillStyle = 'rgba(0,245,212,0.015)';
      ctx.fill();

      // Stroke the curve
      ctx.beginPath();
      ctx.moveTo(nodes[0].x, nodes[0].y);
      for (var i = 1; i < nodes.length; i++) {
        var prev = nodes[i - 1];
        var curr = nodes[i];
        var mx = (prev.x + curr.x) * 0.5;
        var my = (prev.y + curr.y) * 0.5;
        ctx.quadraticCurveTo(prev.x, prev.y, mx, my);
      }
      ctx.quadraticCurveTo(last.x, last.y, mx, my);
      ctx.closePath();
      ctx.strokeStyle = 'rgba(0,245,212,0.7)';
      ctx.lineWidth = 1.5;
      ctx.stroke();

      // Glow on young nodes (recently subdivided)
      for (var i = 0; i < nodes.length; i++) {
        if (nodes[i].age < 30) {
          var alpha = 0.4 * (1 - nodes[i].age / 30);
          ctx.beginPath();
          ctx.arc(nodes[i].x, nodes[i].y, 3, 0, Math.PI * 2);
          ctx.fillStyle = 'rgba(0,245,212,' + alpha + ')';
          ctx.fill();
        }
      }
    }

    // Update HUD
    hudNodes.textContent = totalNodes();
    hudCurves.textContent = curves.length;
  }

  function updateParams() {
    growthForce = parseInt(growthSlider.value, 10) / 1000;
    repulsionForce = parseInt(repulsionSlider.value, 10) / 100;
    stepsPerFrame = parseInt(speedSlider.value, 10);
  }

  function loop() {
    updateParams();
    buildSpatialHash();
    for (var s = 0; s < stepsPerFrame; s++) {
      simulate();
      if (s < stepsPerFrame - 1) buildSpatialHash();
    }
    render();
    requestAnimationFrame(loop);
  }

  function addSeed(x, y) {
    if (totalNodes() >= MAX_TOTAL_NODES) return;
    var radius = 15 + Math.random() * 10;
    var count = 30 + Math.floor(Math.random() * 15);
    curves.push(createCircle(x, y, radius, count));
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function initScene() {
    curves = [];
    // Clear canvas fully
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    // Start with one circle at center
    curves.push(createCircle(cx, cy, 30, 40));
  }

  function resetWithSeed() {
    curves = [];
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    // Randomized initial circle with perturbation
    var radius = 25 + Math.random() * 15;
    var count = 35 + Math.floor(Math.random() * 15);
    var ox = cx + (Math.random() - 0.5) * 60;
    var oy = cy + (Math.random() - 0.5) * 60;
    curves.push(createCircle(ox, oy, radius, count));
  }

  // Events
  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    return [
      (e.clientX - r.left) * (W / r.width),
      (e.clientY - r.top) * (H / r.height)
    ];
  }

  canvas.addEventListener('click', function(e) {
    var pos = getPos(e);
    addSeed(pos[0], pos[1]);
  });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.changedTouches[0];
    var pos = getPos(t);
    addSeed(pos[0], pos[1]);
  }, { passive: false });

  resetBtn.addEventListener('click', function() {
    initScene();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  seedBtn.addEventListener('click', function() {
    resetWithSeed();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  growthSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  repulsionSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  speedSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      document.body.classList.toggle('hide-ui');
    }
  });

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
  }

  window.addEventListener('resize', function() {
    resize();
  });

  // Hide UI style
  var style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .drawer-controls, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // Start
  resize();
  initScene();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
