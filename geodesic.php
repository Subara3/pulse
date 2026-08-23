<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('geodesic');

$pageName = 'geodesic';
$pageTitle = 'GEODESIC - PULSE';
$pageDesc = '曲面上の測地線。ガウス曲率の地形を自由に描き、最短経路の粒子が曲がる様子を眺める微分幾何学の可視化。';
$pageCanonical = 'https://pulse.subara3.com/geodesic.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;left:1rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4}
.hud .sub{color:rgba(123,44,191,.9)}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  PATHS <b id="h-paths">0</b><br>
  CURVATURE <b id="h-curv">0.00</b><br>
  <span class="sub">MODE</span> <b id="h-mode">SCULPT</b>
</div>

<div id="info">
  geodesics on curved surfaces<br>
  <span class="v">Riemannian &middot; Gauss curvature &middot; parallel transport</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'launch / sculpt'],
  ['type' => 'drag', 'label' => 'draw terrain'],
  ['type' => 'right', 'label' => 'flatten'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btnSculpt">SCULPT</button>
      <button class="control-btn" id="btnLaunch">LAUNCH</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">BRUSH</span>
      <input type="range" id="sldBrush" min="1" max="10" value="4">
      <span class="slider-value" id="valBrush">4</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sldSpeed" min="1" max="10" value="5">
      <span class="slider-value" id="valSpeed">5</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">TRAIL</span>
      <input type="range" id="sldTrail" min="1" max="10" value="7">
      <span class="slider-value" id="valTrail">7</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnPreset">PRESET</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H;

  var GW = 200, GH = 120;
  var height = new Float32Array(GW * GH);
  var cellW, cellH;

  var paths = [];
  var MAX_PATHS = 60;
  var TRAIL_LEN = 400;

  var mode = 'sculpt';
  var brushSize = 4;
  var speedMul = 1.0;
  var trailAlpha = 0.7;
  var presetIdx = 0;

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    cellW = W / GW;
    cellH = H / GH;
  }
  window.addEventListener('resize', resize);
  resize();

  function hIdx(gx, gy) {
    if (gx < 0 || gx >= GW || gy < 0 || gy >= GH) return -1;
    return gy * GW + gx;
  }

  function getH(gx, gy) {
    if (gx < 0 || gx >= GW || gy < 0 || gy >= GH) return 0;
    return height[gy * GW + gx];
  }

  function addBump(cx, cy, r, strength) {
    var gr = Math.ceil(r);
    for (var dy = -gr; dy <= gr; dy++) {
      for (var dx = -gr; dx <= gr; dx++) {
        var gx = cx + dx, gy = cy + dy;
        var idx = hIdx(gx, gy);
        if (idx < 0) continue;
        var d = Math.sqrt(dx * dx + dy * dy);
        if (d > r) continue;
        var falloff = Math.cos(d / r * Math.PI * 0.5);
        height[idx] += strength * falloff * falloff;
        if (height[idx] > 10) height[idx] = 10;
        if (height[idx] < -10) height[idx] = -10;
      }
    }
  }

  function flatten(cx, cy, r) {
    var gr = Math.ceil(r);
    for (var dy = -gr; dy <= gr; dy++) {
      for (var dx = -gr; dx <= gr; dx++) {
        var gx = cx + dx, gy = cy + dy;
        var idx = hIdx(gx, gy);
        if (idx < 0) continue;
        var d = Math.sqrt(dx * dx + dy * dy);
        if (d > r) continue;
        var falloff = Math.cos(d / r * Math.PI * 0.5);
        height[idx] *= (1 - 0.3 * falloff * falloff);
      }
    }
  }

  function gradH(x, y) {
    var gx = x / cellW, gy = y / cellH;
    var ix = Math.floor(gx), iy = Math.floor(gy);
    var fx = gx - ix, fy = gy - iy;

    var h00 = getH(ix, iy), h10 = getH(ix + 1, iy);
    var h01 = getH(ix, iy + 1), h11 = getH(ix + 1, iy + 1);

    var dhdx = ((h10 - h00) * (1 - fy) + (h11 - h01) * fy) / cellW;
    var dhdy = ((h01 - h00) * (1 - fx) + (h11 - h10) * fx) / cellH;

    return [dhdx, dhdy];
  }

  function curvAt(gx, gy) {
    var h = getH(gx, gy);
    var hxx = getH(gx + 1, gy) + getH(gx - 1, gy) - 2 * h;
    var hyy = getH(gx, gy + 1) + getH(gx, gy - 1) - 2 * h;
    var hxy = (getH(gx + 1, gy + 1) - getH(gx + 1, gy - 1) - getH(gx - 1, gy + 1) + getH(gx - 1, gy - 1)) * 0.25;
    var px = (getH(gx + 1, gy) - getH(gx - 1, gy)) * 0.5;
    var py = (getH(gx, gy + 1) - getH(gx, gy - 1)) * 0.5;
    var denom = (1 + px * px + py * py);
    var gauss = (hxx * hyy - hxy * hxy) / (denom * denom);
    return gauss;
  }

  function launchPath(x, y, angle) {
    if (paths.length >= MAX_PATHS) paths.shift();
    var speed = 1.5 * speedMul;
    paths.push({
      x: x, y: y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed,
      trail: [{x: x, y: y}],
      hue: 170 + Math.random() * 90,
      age: 0,
      alive: true
    });
  }

  function updatePaths() {
    var dt = 0.8 * speedMul;
    for (var i = 0; i < paths.length; i++) {
      var p = paths[i];
      if (!p.alive) continue;

      var g = gradH(p.x, p.y);
      var gMag = Math.sqrt(g[0] * g[0] + g[1] * g[1]);

      var factor = 3.0;
      var ax = -g[0] * factor;
      var ay = -g[1] * factor;

      var speed = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      if (speed > 0.01 && gMag > 0.001) {
        var nx = -g[1] / gMag, ny = g[0] / gMag;
        var dot = p.vx * nx + p.vy * ny;
        var curvForce = dot * gMag * 2.0;
        ax += nx * curvForce;
        ay += ny * curvForce;
      }

      p.vx += ax * dt;
      p.vy += ay * dt;

      speed = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      var targetSpeed = 1.5 * speedMul;
      if (speed > 0.01) {
        var blend = 0.05;
        var newSpeed = speed * (1 - blend) + targetSpeed * blend;
        p.vx *= newSpeed / speed;
        p.vy *= newSpeed / speed;
      }

      p.x += p.vx * dt;
      p.y += p.vy * dt;

      if (p.x < 0 || p.x >= W || p.y < 0 || p.y >= H) {
        p.alive = false;
        continue;
      }

      p.trail.push({x: p.x, y: p.y});
      if (p.trail.length > TRAIL_LEN) p.trail.shift();
      p.age++;
    }
  }

  function drawTerrain() {
    var imgData = ctx.createImageData(W, H);
    var data = imgData.data;

    for (var py = 0; py < H; py++) {
      var gy = py / cellH;
      var iy = Math.min(Math.floor(gy), GH - 2);
      var fy = gy - iy;

      for (var px = 0; px < W; px++) {
        var gx = px / cellW;
        var ix = Math.min(Math.floor(gx), GW - 2);
        var fx = gx - ix;

        var h00 = height[iy * GW + ix];
        var h10 = height[iy * GW + ix + 1];
        var h01 = height[(iy + 1) * GW + ix];
        var h11 = height[(iy + 1) * GW + ix + 1];
        var h = h00 * (1 - fx) * (1 - fy) + h10 * fx * (1 - fy) + h01 * (1 - fx) * fy + h11 * fx * fy;

        var idx = (py * W + px) * 4;

        var bright = Math.abs(h) * 0.06;
        if (h > 0) {
          data[idx] = Math.min(255, 0 + bright * 40);
          data[idx + 1] = Math.min(255, 15 + bright * 60);
          data[idx + 2] = Math.min(255, 20 + bright * 50);
        } else {
          data[idx] = Math.min(255, 10 + bright * 25);
          data[idx + 1] = Math.min(255, 10 + bright * 12);
          data[idx + 2] = Math.min(255, 15 + bright * 45);
        }
        data[idx + 3] = 255;

        var gxI = Math.floor(gx), gyI = Math.floor(gy);
        if (gxI > 0 && gxI < GW - 1 && gyI > 0 && gyI < GH - 1) {
          var dxH = (getH(gxI + 1, gyI) - getH(gxI - 1, gyI)) * 0.5;
          var dyH = (getH(gxI, gyI + 1) - getH(gxI, gyI - 1)) * 0.5;
          var shade = dxH * 0.5 - dyH * 0.3;
          data[idx] = Math.max(0, Math.min(255, data[idx] + shade * 15));
          data[idx + 1] = Math.max(0, Math.min(255, data[idx + 1] + shade * 20));
          data[idx + 2] = Math.max(0, Math.min(255, data[idx + 2] + shade * 18));
        }
      }
    }
    ctx.putImageData(imgData, 0, 0);
  }

  function drawContours() {
    ctx.globalAlpha = 0.15;
    ctx.strokeStyle = '#00f5d4';
    ctx.lineWidth = 0.5;
    for (var level = -8; level <= 8; level += 1) {
      if (level === 0) continue;
      ctx.beginPath();
      var drawn = false;
      for (var gy = 0; gy < GH - 1; gy++) {
        for (var gx = 0; gx < GW - 1; gx++) {
          var h00 = height[gy * GW + gx];
          var h10 = height[gy * GW + gx + 1];
          var h01 = height[(gy + 1) * GW + gx];

          if ((h00 - level) * (h10 - level) < 0) {
            var t = (level - h00) / (h10 - h00);
            var x = (gx + t) * cellW;
            var y = gy * cellH;
            if (!drawn) { ctx.moveTo(x, y); drawn = true; }
            else ctx.lineTo(x, y);
          }
          if ((h00 - level) * (h01 - level) < 0) {
            var t = (level - h00) / (h01 - h00);
            var x = gx * cellW;
            var y = (gy + t) * cellH;
            if (!drawn) { ctx.moveTo(x, y); drawn = true; }
            else ctx.lineTo(x, y);
          }
        }
      }
      ctx.stroke();
    }
    ctx.globalAlpha = 1;
  }

  function drawPaths() {
    for (var i = 0; i < paths.length; i++) {
      var p = paths[i];
      if (p.trail.length < 2) continue;

      var baseAlpha = p.alive ? trailAlpha : trailAlpha * 0.4;

      for (var j = 1; j < p.trail.length; j++) {
        var t = j / p.trail.length;
        var alpha = t * baseAlpha * 0.8;
        var hue = p.hue;
        var sat = 70 + t * 20;
        var lit = 45 + t * 20;
        ctx.strokeStyle = 'hsla(' + hue + ',' + sat + '%,' + lit + '%,' + alpha + ')';
        ctx.lineWidth = 0.8 + t * 1.5;
        ctx.beginPath();
        ctx.moveTo(p.trail[j - 1].x, p.trail[j - 1].y);
        ctx.lineTo(p.trail[j].x, p.trail[j].y);
        ctx.stroke();
      }

      if (p.alive) {
        var last = p.trail[p.trail.length - 1];
        ctx.fillStyle = 'hsla(' + p.hue + ',90%,70%,0.9)';
        ctx.beginPath();
        ctx.arc(last.x, last.y, 3, 0, Math.PI * 2);
        ctx.fill();

        ctx.fillStyle = 'hsla(' + p.hue + ',80%,80%,0.3)';
        ctx.beginPath();
        ctx.arc(last.x, last.y, 6, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  var frameCount = 0;
  function draw() {
    drawTerrain();
    drawContours();
    updatePaths();
    drawPaths();

    if (++frameCount % 15 === 0) {
      document.getElementById('h-paths').textContent = paths.filter(function(p){return p.alive;}).length;
      var cx = Math.floor(GW / 2), cy = Math.floor(GH / 2);
      document.getElementById('h-curv').textContent = curvAt(cx, cy).toFixed(3);
    }

    requestAnimationFrame(draw);
  }

  // --- Presets ---
  var presets = [
    function() {
      // Saddle
      for (var gy = 0; gy < GH; gy++) {
        for (var gx = 0; gx < GW; gx++) {
          var nx = (gx - GW / 2) / (GW / 2);
          var ny = (gy - GH / 2) / (GH / 2);
          height[gy * GW + gx] = 4 * (nx * nx - ny * ny);
        }
      }
    },
    function() {
      // Gaussian peaks
      height.fill(0);
      var peaks = [
        [GW * 0.3, GH * 0.4, 6, 8],
        [GW * 0.7, GH * 0.6, -5, 7],
        [GW * 0.5, GH * 0.25, 4, 6],
        [GW * 0.2, GH * 0.75, -3, 9],
        [GW * 0.8, GH * 0.3, 5, 5]
      ];
      for (var i = 0; i < peaks.length; i++) {
        addBump(Math.floor(peaks[i][0]), Math.floor(peaks[i][1]), peaks[i][3], peaks[i][2]);
      }
    },
    function() {
      // Wavy ridges
      for (var gy = 0; gy < GH; gy++) {
        for (var gx = 0; gx < GW; gx++) {
          var nx = gx / GW * Math.PI * 3;
          var ny = gy / GH * Math.PI * 2;
          height[gy * GW + gx] = 3 * Math.sin(nx) * Math.cos(ny + Math.sin(nx * 0.7));
        }
      }
    },
    function() {
      // Bowl + rim
      for (var gy = 0; gy < GH; gy++) {
        for (var gx = 0; gx < GW; gx++) {
          var nx = (gx - GW / 2) / (GW / 4);
          var ny = (gy - GH / 2) / (GH / 4);
          var r = Math.sqrt(nx * nx + ny * ny);
          height[gy * GW + gx] = 5 * (r * r - 2) * Math.exp(-r * r * 0.3);
        }
      }
    }
  ];

  function applyPreset(idx) {
    presets[idx % presets.length]();
  }

  // Initial preset
  applyPreset(1);

  // Scatter initial paths
  for (var i = 0; i < 8; i++) {
    var sx = W * 0.2 + Math.random() * W * 0.6;
    var sy = H * 0.2 + Math.random() * H * 0.6;
    var angle = Math.random() * Math.PI * 2;
    launchPath(sx, sy, angle);
  }

  // --- Interaction ---
  var painting = false;
  var isRightBtn = false;
  var lastMx = -1, lastMy = -1;

  function screenToGrid(x, y) {
    return [Math.floor(x / cellW), Math.floor(y / cellH)];
  }

  function handleDown(x, y, rightBtn) {
    painting = true;
    isRightBtn = rightBtn;
    lastMx = x; lastMy = y;

    if (mode === 'launch' && !rightBtn) {
      launchPath(x, y, Math.random() * Math.PI * 2);
      if (window.SFX) SFX.play('spawn');
    } else {
      var g = screenToGrid(x, y);
      var r = brushSize;
      if (rightBtn) {
        flatten(g[0], g[1], r);
        if (window.SFX) SFX.play('pop');
      } else {
        addBump(g[0], g[1], r, 1.5);
        if (window.SFX) SFX.play('tick');
      }
    }
  }

  function handleMove(x, y) {
    if (!painting) return;
    if (mode === 'launch' && !isRightBtn) return;

    var g = screenToGrid(x, y);
    var r = brushSize;
    if (isRightBtn) {
      flatten(g[0], g[1], r);
    } else {
      addBump(g[0], g[1], r, 0.8);
    }
    lastMx = x; lastMy = y;
  }

  function handleUp() {
    painting = false;
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    handleDown(e.clientX, e.clientY, e.button === 2);
  });
  C.addEventListener('mousemove', function(e) {
    handleMove(e.clientX, e.clientY);
  });
  C.addEventListener('mouseup', handleUp);
  C.addEventListener('mouseleave', handleUp);
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    handleDown(t.clientX, t.clientY, false);
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    handleMove(t.clientX, t.clientY);
  }, {passive: false});
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    handleUp();
  });

  // --- Controls ---
  var btnSculpt = document.getElementById('btnSculpt');
  var btnLaunch = document.getElementById('btnLaunch');
  var sldBrush = document.getElementById('sldBrush');
  var sldSpeed = document.getElementById('sldSpeed');
  var sldTrail = document.getElementById('sldTrail');

  btnSculpt.addEventListener('click', function() {
    mode = 'sculpt';
    btnSculpt.classList.add('active');
    btnLaunch.classList.remove('active');
    document.getElementById('h-mode').textContent = 'SCULPT';
    if (window.SFX) SFX.play('toggle');
  });
  btnLaunch.addEventListener('click', function() {
    mode = 'launch';
    btnLaunch.classList.add('active');
    btnSculpt.classList.remove('active');
    document.getElementById('h-mode').textContent = 'LAUNCH';
    if (window.SFX) SFX.play('toggle');
  });

  sldBrush.addEventListener('input', function() {
    brushSize = parseInt(this.value);
    document.getElementById('valBrush').textContent = brushSize;
  });
  sldSpeed.addEventListener('input', function() {
    speedMul = parseInt(this.value) / 5;
    document.getElementById('valSpeed').textContent = this.value;
  });
  sldTrail.addEventListener('input', function() {
    trailAlpha = parseInt(this.value) / 10;
    document.getElementById('valTrail').textContent = this.value;
  });

  document.getElementById('btnPreset').addEventListener('click', function() {
    presetIdx = (presetIdx + 1) % presets.length;
    applyPreset(presetIdx);
    if (window.SFX) SFX.play('click');
  });
  document.getElementById('btnClear').addEventListener('click', function() {
    height.fill(0);
    paths = [];
    if (window.SFX) SFX.play('click');
  });

  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
