<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('viscous');

$pageName = 'viscous';
$pageTitle = 'VISCOUS - PULSE';
$pageDesc = 'ヘレ・ショー流の粘性フィンガリング。低粘性流体が高粘性流体を押し分けるとき、界面が不安定になり分岐する指状構造が成長する。';
$pageCanonical = 'https://pulse.subara3.com/viscous.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>FINGERS <span id="hud-fingers">0</span></div>
  <div>COVERAGE <span id="hud-cov">0</span>%</div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inject'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">VISCOSITY</label>
    <input type="range" id="sl-visc" min="1" max="20" value="10">
    <span class="slider-value" id="sv-visc">10</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">TENSION</label>
    <input type="range" id="sl-tens" min="0" max="20" value="5">
    <span class="slider-value" id="sv-tens">5</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-spd" min="1" max="10" value="5">
    <span class="slider-value" id="sv-spd">5</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-pressure">PRESSURE</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, GW, GH, CS = 4;
  var grid, pressure, velocity;
  var sources = [];
  var showPressure = false;
  var viscosity = 10, tension = 5, speed = 5;
  var fingerCount = 0, coverage = 0;
  var hudF = document.getElementById('hud-fingers');
  var hudC = document.getElementById('hud-cov');

  function resize() {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    GW = Math.ceil(W / CS);
    GH = Math.ceil(H / CS);
    initGrid();
  }

  function initGrid() {
    var len = GW * GH;
    grid = new Uint8Array(len);
    pressure = new Float32Array(len);
    velocity = new Float32Array(len);
    sources = [];
    addInitialSource();
  }

  function addInitialSource() {
    var cx = Math.floor(GW / 2);
    var cy = Math.floor(GH / 2);
    var r = 3;
    for (var dy = -r; dy <= r; dy++) {
      for (var dx = -r; dx <= r; dx++) {
        if (dx * dx + dy * dy <= r * r) {
          var gx = cx + dx, gy = cy + dy;
          if (gx >= 0 && gx < GW && gy >= 0 && gy < GH) {
            grid[gy * GW + gx] = 1;
            pressure[gy * GW + gx] = 1.0;
          }
        }
      }
    }
    sources.push({ x: cx, y: cy, active: true });
  }

  function injectAt(px, py) {
    var gx = Math.floor(px / CS);
    var gy = Math.floor(py / CS);
    if (gx < 0 || gx >= GW || gy < 0 || gy >= GH) return;
    var r = 3;
    for (var dy = -r; dy <= r; dy++) {
      for (var dx = -r; dx <= r; dx++) {
        if (dx * dx + dy * dy <= r * r) {
          var nx = gx + dx, ny = gy + dy;
          if (nx >= 0 && nx < GW && ny >= 0 && ny < GH) {
            grid[ny * GW + nx] = 1;
            pressure[ny * GW + nx] = 1.0;
          }
        }
      }
    }
    sources.push({ x: gx, y: gy, active: true });
  }

  function countNeighbors(idx) {
    var x = idx % GW, y = (idx / GW) | 0;
    var c = 0;
    if (x > 0 && grid[idx - 1]) c++;
    if (x < GW - 1 && grid[idx + 1]) c++;
    if (y > 0 && grid[idx - GW]) c++;
    if (y < GH - 1 && grid[idx + GW]) c++;
    return c;
  }

  function isBoundary(idx) {
    if (!grid[idx]) return false;
    var x = idx % GW, y = (idx / GW) | 0;
    if (x <= 0 || x >= GW - 1 || y <= 0 || y >= GH - 1) return true;
    return !grid[idx - 1] || !grid[idx + 1] || !grid[idx - GW] || !grid[idx + GW];
  }

  function stepPressure() {
    var visc = viscosity * 0.05 + 0.1;
    var tens = tension * 0.003;
    var len = GW * GH;

    for (var i = 0; i < sources.length; i++) {
      if (!sources[i].active) continue;
      var si = sources[i].y * GW + sources[i].x;
      if (si >= 0 && si < len) pressure[si] = 1.0;
    }

    var newP = new Float32Array(len);
    for (var i = 0; i < len; i++) {
      if (!grid[i]) continue;
      var x = i % GW, y = (i / GW) | 0;
      var sum = 0, cnt = 0;
      if (x > 0 && grid[i - 1]) { sum += pressure[i - 1]; cnt++; }
      if (x < GW - 1 && grid[i + 1]) { sum += pressure[i + 1]; cnt++; }
      if (y > 0 && grid[i - GW]) { sum += pressure[i - GW]; cnt++; }
      if (y < GH - 1 && grid[i + GW]) { sum += pressure[i + GW]; cnt++; }
      if (cnt > 0) {
        newP[i] = pressure[i] + 0.2 * (sum / cnt - pressure[i]);
      } else {
        newP[i] = pressure[i];
      }
    }
    pressure = newP;
  }

  function stepGrow() {
    var visc = viscosity * 0.05 + 0.1;
    var tens = tension * 0.15;
    var iterations = speed;
    var grew = false;

    for (var iter = 0; iter < iterations; iter++) {
      var candidates = [];
      var len = GW * GH;

      for (var i = 0; i < len; i++) {
        if (grid[i]) continue;
        var x = i % GW, y = (i / GW) | 0;
        if (x <= 0 || x >= GW - 1 || y <= 0 || y >= GH - 1) continue;

        var adjP = 0, adjC = 0;
        if (grid[i - 1]) { adjP += pressure[i - 1]; adjC++; }
        if (grid[i + 1]) { adjP += pressure[i + 1]; adjC++; }
        if (grid[i - GW]) { adjP += pressure[i - GW]; adjC++; }
        if (grid[i + GW]) { adjP += pressure[i + GW]; adjC++; }
        if (adjC === 0) continue;

        var pAvg = adjP / adjC;
        var filledNeighbors = adjC;

        var diag = 0;
        if (grid[i - GW - 1]) diag++;
        if (grid[i - GW + 1]) diag++;
        if (grid[i + GW - 1]) diag++;
        if (grid[i + GW + 1]) diag++;

        var curvature = (filledNeighbors + diag * 0.5) / 4.5;
        var tensionPenalty = curvature * tens;

        var noise = (Math.random() - 0.3) * visc;
        var prob = pAvg * (1 + noise) - tensionPenalty;

        if (prob > 0.1) {
          candidates.push({ idx: i, prob: prob });
        }
      }

      candidates.sort(function(a, b) { return b.prob - a.prob; });
      var maxGrow = Math.max(1, Math.floor(candidates.length * 0.3));

      for (var j = 0; j < Math.min(maxGrow, candidates.length); j++) {
        var ci = candidates[j].idx;
        if (!grid[ci]) {
          grid[ci] = 1;
          pressure[ci] = candidates[j].prob * 0.5;
          grew = true;
        }
      }
    }
    return grew;
  }

  function computeStats() {
    var filled = 0, boundary = 0;
    var len = GW * GH;
    for (var i = 0; i < len; i++) {
      if (grid[i]) {
        filled++;
        if (isBoundary(i)) boundary++;
      }
    }
    coverage = ((filled / len) * 100).toFixed(1);
    fingerCount = Math.max(0, Math.floor(boundary / 8));
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    var imgData = X.createImageData(GW, GH);
    var d = imgData.data;
    var len = GW * GH;

    for (var i = 0; i < len; i++) {
      var off = i * 4;
      if (grid[i]) {
        var p = pressure[i];
        var bnd = isBoundary(i);

        if (showPressure) {
          var r = Math.floor(p * 123);
          var g = Math.floor(40 + p * 200);
          var b = Math.floor(180 + p * 32);
          d[off] = r; d[off + 1] = g; d[off + 2] = b; d[off + 3] = 255;
        } else {
          if (bnd) {
            d[off] = 0; d[off + 1] = 245; d[off + 2] = 212; d[off + 3] = 255;
          } else {
            var age = Math.min(1, p * 1.5);
            var r2 = Math.floor(0 + age * 60);
            var g2 = Math.floor(80 + age * 120);
            var b2 = Math.floor(120 + age * 60);
            d[off] = r2; d[off + 1] = g2; d[off + 2] = b2; d[off + 3] = 255;
          }
        }
      } else {
        d[off] = 10; d[off + 1] = 10; d[off + 2] = 15; d[off + 3] = 255;
      }
    }

    X.save();
    X.imageSmoothingEnabled = false;
    var tempC = document.createElement('canvas');
    tempC.width = GW; tempC.height = GH;
    var tempX = tempC.getContext('2d');
    tempX.putImageData(imgData, 0, 0);
    X.drawImage(tempC, 0, 0, W, H);
    X.restore();

    for (var s = 0; s < sources.length; s++) {
      if (!sources[s].active) continue;
      var sx = sources[s].x * CS + CS / 2;
      var sy = sources[s].y * CS + CS / 2;
      X.beginPath();
      X.arc(sx, sy, 6, 0, Math.PI * 2);
      X.fillStyle = 'rgba(123, 44, 191, 0.8)';
      X.fill();
      X.beginPath();
      X.arc(sx, sy, 10, 0, Math.PI * 2);
      X.strokeStyle = 'rgba(123, 44, 191, 0.4)';
      X.lineWidth = 1;
      X.stroke();
    }
  }

  var frameCount = 0;
  function loop() {
    frameCount++;
    stepPressure();
    if (frameCount % 2 === 0) {
      var grew = stepGrow();
      if (grew && frameCount % 10 === 0) {
        if (typeof SFX !== 'undefined') SFX.play('ripple', 100);
      }
    }
    if (frameCount % 15 === 0) {
      computeStats();
      hudF.textContent = fingerCount;
      hudC.textContent = coverage;
    }
    draw();
    requestAnimationFrame(loop);
  }

  var dragging = false;

  function getPos(e) {
    var t = e.touches ? e.touches[0] : e;
    var r = C.getBoundingClientRect();
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    e.preventDefault();
    var p = getPos(e);
    injectAt(p.x, p.y);
    dragging = true;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var p = getPos(e);
    var gx = Math.floor(p.x / CS);
    var gy = Math.floor(p.y / CS);
    if (gx >= 0 && gx < GW && gy >= 0 && gy < GH) {
      var r = 2;
      for (var dy = -r; dy <= r; dy++) {
        for (var dx = -r; dx <= r; dx++) {
          if (dx * dx + dy * dy <= r * r) {
            var nx = gx + dx, ny = gy + dy;
            if (nx >= 0 && nx < GW && ny >= 0 && ny < GH) {
              grid[ny * GW + nx] = 1;
              pressure[ny * GW + nx] = 0.8;
            }
          }
        }
      }
    }
  });

  C.addEventListener('mouseup', function() { dragging = false; });
  C.addEventListener('mouseleave', function() { dragging = false; });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var p = getPos(e);
    injectAt(p.x, p.y);
    dragging = true;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!dragging) return;
    var t = e.touches[0];
    var r2 = C.getBoundingClientRect();
    var px = t.clientX - r2.left, py = t.clientY - r2.top;
    var gx = Math.floor(px / CS);
    var gy = Math.floor(py / CS);
    if (gx >= 0 && gx < GW && gy >= 0 && gy < GH) {
      var rad = 2;
      for (var dy = -rad; dy <= rad; dy++) {
        for (var dx = -rad; dx <= rad; dx++) {
          if (dx * dx + dy * dy <= rad * rad) {
            var nx = gx + dx, ny = gy + dy;
            if (nx >= 0 && nx < GW && ny >= 0 && ny < GH) {
              grid[ny * GW + nx] = 1;
              pressure[ny * GW + nx] = 0.8;
            }
          }
        }
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function() { dragging = false; });

  var slV = document.getElementById('sl-visc');
  var svV = document.getElementById('sv-visc');
  var slT = document.getElementById('sl-tens');
  var svT = document.getElementById('sv-tens');
  var slS = document.getElementById('sl-spd');
  var svS = document.getElementById('sv-spd');
  var btnP = document.getElementById('btn-pressure');
  var btnR = document.getElementById('btn-reset');

  slV.addEventListener('input', function() {
    viscosity = +this.value; svV.textContent = this.value;
  });
  slT.addEventListener('input', function() {
    tension = +this.value; svT.textContent = this.value;
  });
  slS.addEventListener('input', function() {
    speed = +this.value; svS.textContent = this.value;
  });
  btnP.addEventListener('click', function() {
    showPressure = !showPressure;
    this.classList.toggle('active', showPressure);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  btnR.addEventListener('click', function() {
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('click');
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
