<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('motile');

$pageName = 'motile';
$pageTitle = 'MOTILE - PULSE';
$pageDesc = '自己駆動粒子の非平衡相分離。引力なしで密度と持続性だけからクラスタが自発的に形成・融合・蒸発する。';
$pageCanonical = 'https://pulse.subara3.com/motile.php';

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
  PARTICLES <b id="h-count">0</b><br>
  DENSITY <b id="h-density">0.00</b><br>
  PERSISTENCE <b id="h-persist">0</b><br>
  <span class="sub">CLUSTERS</span> <b id="h-clusters">0</b>
</div>

<div id="info">
  active brownian particles<br>
  <span class="v">motility-induced phase separation</span><br>
  no attraction, yet clustering
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inject'],
  ['type' => 'drag', 'label' => 'repel'],
  ['type' => 'right', 'label' => 'attract'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">DENSITY</span>
      <input type="range" id="sldDensity" min="5" max="80" value="40">
      <span class="slider-value" id="valDensity">40</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">PERSISTENCE</span>
      <input type="range" id="sldPersist" min="1" max="100" value="50">
      <span class="slider-value" id="valPersist">50</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sldSpeed" min="5" max="100" value="40">
      <span class="slider-value" id="valSpeed">40</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">RADIUS</span>
      <input type="range" id="sldRadius" min="2" max="8" value="4">
      <span class="slider-value" id="valRadius">4</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btnTrail">TRAIL</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
  function brandHue(t){t=t<0?0:t>1?1:t;var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);return 172+e*118}
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var particles = [];
  var maxN = 2000;
  var radius = 4;
  var swimSpeed = 2.0;
  var rotNoise = 0.15;
  var showTrail = true;
  var trailCanvas, trailCtx;
  var mouseX = -1000, mouseY = -1000, mouseDown = false, rightDown = false;
  var mouseForce = 120;
  var PI = Math.PI, TAU = PI * 2;
  var gridCellSize, gridW, gridH, grid;
  var frameCount = 0;

  function createParticle(x, y) {
    return {
      x: x,
      y: y,
      angle: Math.random() * TAU,
      vx: 0,
      vy: 0
    };
  }

  function initParticles(n) {
    particles = [];
    for (var i = 0; i < n; i++) {
      var x = radius + Math.random() * (W / dpr - radius * 2);
      var y = radius + Math.random() * (H / dpr - radius * 2);
      particles.push(createParticle(x, y));
    }
  }

  function buildGrid() {
    gridCellSize = radius * 2.5;
    gridW = Math.ceil((W / dpr) / gridCellSize) + 1;
    gridH = Math.ceil((H / dpr) / gridCellSize) + 1;
    grid = new Array(gridW * gridH);
  }

  function clearGrid() {
    for (var i = 0; i < grid.length; i++) grid[i] = null;
  }

  function insertGrid(idx) {
    var p = particles[idx];
    var gx = Math.floor(p.x / gridCellSize);
    var gy = Math.floor(p.y / gridCellSize);
    if (gx < 0) gx = 0; if (gx >= gridW) gx = gridW - 1;
    if (gy < 0) gy = 0; if (gy >= gridH) gy = gridH - 1;
    var key = gy * gridW + gx;
    p._next = grid[key];
    grid[key] = idx;
  }

  function update() {
    var n = particles.length;
    var ww = W / dpr, hh = H / dpr;
    var r2 = radius * 2;
    var r2sq = r2 * r2;

    clearGrid();
    for (var i = 0; i < n; i++) insertGrid(i);

    for (var i = 0; i < n; i++) {
      var p = particles[i];
      p.angle += (Math.random() - 0.5) * rotNoise * 2;
      p.vx = Math.cos(p.angle) * swimSpeed;
      p.vy = Math.sin(p.angle) * swimSpeed;
    }

    for (var i = 0; i < n; i++) {
      var pi = particles[i];
      var gx = Math.floor(pi.x / gridCellSize);
      var gy = Math.floor(pi.y / gridCellSize);

      for (var dy = -1; dy <= 1; dy++) {
        var ny = gy + dy;
        if (ny < 0 || ny >= gridH) continue;
        for (var dx = -1; dx <= 1; dx++) {
          var nx = gx + dx;
          if (nx < 0 || nx >= gridW) continue;
          var idx = grid[ny * gridW + nx];
          while (idx !== null) {
            if (idx > i) {
              var pj = particles[idx];
              var ddx = pj.x - pi.x;
              var ddy = pj.y - pi.y;
              var dsq = ddx * ddx + ddy * ddy;
              if (dsq < r2sq && dsq > 0.01) {
                var d = Math.sqrt(dsq);
                var overlap = r2 - d;
                var fx = (ddx / d) * overlap * 0.5;
                var fy = (ddy / d) * overlap * 0.5;
                pi.vx -= fx;
                pi.vy -= fy;
                pj.vx += fx;
                pj.vy += fy;
              }
            }
            idx = particles[idx]._next;
          }
        }
      }
    }

    if (mouseDown || rightDown) {
      var sign = rightDown ? -1 : 1;
      for (var i = 0; i < n; i++) {
        var p = particles[i];
        var ddx = p.x - mouseX;
        var ddy = p.y - mouseY;
        var dsq = ddx * ddx + ddy * ddy;
        if (dsq < mouseForce * mouseForce && dsq > 1) {
          var d = Math.sqrt(dsq);
          var strength = (1 - d / mouseForce) * 3 * sign;
          p.vx += (ddx / d) * strength;
          p.vy += (ddy / d) * strength;
        }
      }
    }

    for (var i = 0; i < n; i++) {
      var p = particles[i];
      p.x += p.vx;
      p.y += p.vy;

      if (p.x < radius) { p.x = radius; p.angle = PI - p.angle + (Math.random() - 0.5) * 0.5; }
      if (p.x > ww - radius) { p.x = ww - radius; p.angle = PI - p.angle + (Math.random() - 0.5) * 0.5; }
      if (p.y < radius) { p.y = radius; p.angle = -p.angle + (Math.random() - 0.5) * 0.5; }
      if (p.y > hh - radius) { p.y = hh - radius; p.angle = -p.angle + (Math.random() - 0.5) * 0.5; }
    }
  }

  function countClusters() {
    var n = particles.length;
    if (n === 0) return 0;
    var visited = new Uint8Array(n);
    var clusterCount = 0;
    var threshold = radius * 3;
    var threshSq = threshold * threshold;
    var stack = [];

    for (var i = 0; i < n; i++) {
      if (visited[i]) continue;
      var neighbors = 0;
      var pi = particles[i];
      var gx = Math.floor(pi.x / gridCellSize);
      var gy = Math.floor(pi.y / gridCellSize);
      for (var dy = -1; dy <= 1; dy++) {
        var ny = gy + dy;
        if (ny < 0 || ny >= gridH) continue;
        for (var dx = -1; dx <= 1; dx++) {
          var nx = gx + dx;
          if (nx < 0 || nx >= gridW) continue;
          var idx = grid[ny * gridW + nx];
          while (idx !== null) {
            if (idx !== i) neighbors++;
            idx = particles[idx]._next;
          }
        }
      }
      if (neighbors < 4) { visited[i] = 1; continue; }

      clusterCount++;
      stack.push(i);
      visited[i] = 1;
      while (stack.length > 0) {
        var ci = stack.pop();
        var pc = particles[ci];
        var cgx = Math.floor(pc.x / gridCellSize);
        var cgy = Math.floor(pc.y / gridCellSize);
        for (var dy = -1; dy <= 1; dy++) {
          var nny = cgy + dy;
          if (nny < 0 || nny >= gridH) continue;
          for (var dx = -1; dx <= 1; dx++) {
            var nnx = cgx + dx;
            if (nnx < 0 || nnx >= gridW) continue;
            var idx = grid[nny * gridW + nnx];
            while (idx !== null) {
              if (!visited[idx]) {
                var pj = particles[idx];
                var ddx = pj.x - pc.x;
                var ddy = pj.y - pc.y;
                if (ddx * ddx + ddy * ddy < threshSq) {
                  visited[idx] = 1;
                  stack.push(idx);
                }
              }
              idx = particles[idx]._next;
            }
          }
        }
      }
    }
    return clusterCount;
  }

  function draw() {
    ctx.save();
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    if (showTrail) {
      trailCtx.fillStyle = 'rgba(10,10,15,0.03)';
      trailCtx.fillRect(0, 0, W / dpr, H / dpr);
    }

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W / dpr, H / dpr);

    if (showTrail) {
      for (var i = 0; i < particles.length; i++) {
        var p = particles[i];
        var speed = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
        var t = Math.min(speed / (swimSpeed * 2), 1);
        var h = brandHue(t);
        trailCtx.fillStyle = 'hsla(' + h + ',70%,50%,0.15)';
        trailCtx.beginPath();
        trailCtx.arc(p.x, p.y, radius * 0.3, 0, TAU);
        trailCtx.fill();
      }
      ctx.globalAlpha = 0.6;
      ctx.drawImage(trailCanvas, 0, 0);
      ctx.globalAlpha = 1;
    }

    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var speed = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      var t = Math.min(speed / (swimSpeed * 2), 1);
      var h = brandHue(t);

      ctx.beginPath();
      ctx.arc(p.x, p.y, radius, 0, TAU);
      ctx.fillStyle = 'hsla(' + h + ',65%,45%,0.6)';
      ctx.fill();

      ctx.beginPath();
      ctx.arc(p.x, p.y, radius * 0.5, 0, TAU);
      ctx.fillStyle = 'hsla(' + h + ',80%,65%,0.9)';
      ctx.fill();

      var dx = Math.cos(p.angle) * radius * 1.2;
      var dy = Math.sin(p.angle) * radius * 1.2;
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      ctx.lineTo(p.x + dx, p.y + dy);
      ctx.strokeStyle = 'rgba(0,245,212,0.25)';
      ctx.lineWidth = 0.5;
      ctx.stroke();
    }

    if (mouseDown || rightDown) {
      ctx.beginPath();
      ctx.arc(mouseX, mouseY, mouseForce, 0, TAU);
      ctx.strokeStyle = rightDown ? 'rgba(123,44,191,0.3)' : 'rgba(0,245,212,0.2)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    ctx.restore();

    frameCount++;
    if (frameCount % 30 === 0) {
      var ww = W / dpr, hh = H / dpr;
      var area = ww * hh;
      var particleArea = particles.length * PI * radius * radius;
      var density = (particleArea / area * 100).toFixed(1);
      document.getElementById('h-count').textContent = particles.length;
      document.getElementById('h-density').textContent = density + '%';
      document.getElementById('h-persist').textContent = (1 / rotNoise).toFixed(0);
      document.getElementById('h-clusters').textContent = countClusters();
    }

    update();
    requestAnimationFrame(draw);
  }

  function resize() {
    dpr = Math.min(2, window.devicePixelRatio || 1);
    W = Math.floor(innerWidth * dpr);
    H = Math.floor(innerHeight * dpr);
    C.width = W; C.height = H;
    C.style.width = innerWidth + 'px';
    C.style.height = innerHeight + 'px';

    trailCanvas = document.createElement('canvas');
    trailCanvas.width = W / dpr;
    trailCanvas.height = H / dpr;
    trailCtx = trailCanvas.getContext('2d');

    buildGrid();
  }

  function resetSim() {
    var ww = W / dpr, hh = H / dpr;
    var area = ww * hh;
    var densityPct = parseInt(document.getElementById('sldDensity').value);
    var n = Math.min(maxN, Math.floor(area * densityPct / 100 / (PI * radius * radius)));
    initParticles(n);
    if (trailCtx) {
      trailCtx.fillStyle = '#0a0a0f';
      trailCtx.fillRect(0, 0, W / dpr, H / dpr);
    }
  }

  function getPointerPos(e) {
    mouseX = e.clientX;
    mouseY = e.clientY;
  }

  C.addEventListener('pointerdown', function(e) {
    getPointerPos(e);
    if (e.button === 2) {
      rightDown = true;
    } else {
      mouseDown = true;
    }
  });

  C.addEventListener('pointermove', function(e) {
    getPointerPos(e);
    if ((mouseDown || rightDown) && window.SFX) SFX.play('move', 120);
  });

  C.addEventListener('pointerup', function(e) {
    if (!mouseDown && !rightDown) return;
    if (e.button === 2) {
      rightDown = false;
    } else {
      if (mouseDown) {
        var burst = 15;
        for (var i = 0; i < burst; i++) {
          var a = Math.random() * TAU;
          var r = Math.random() * 30 + 10;
          var px = mouseX + Math.cos(a) * r;
          var py = mouseY + Math.sin(a) * r;
          if (px > radius && px < W / dpr - radius && py > radius && py < H / dpr - radius) {
            if (particles.length < maxN * 1.5) {
              particles.push(createParticle(px, py));
            }
          }
        }
        if (window.SFX) SFX.play('spawn');
      }
      mouseDown = false;
    }
  });

  C.addEventListener('pointerleave', function() { mouseDown = false; rightDown = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) {
      mouseX = e.touches[0].clientX;
      mouseY = e.touches[0].clientY;
      mouseDown = true;
    }
  }, { passive: true });

  C.addEventListener('touchmove', function(e) {
    if (e.touches.length === 1) {
      mouseX = e.touches[0].clientX;
      mouseY = e.touches[0].clientY;
      if (window.SFX) SFX.play('move', 120);
    }
  }, { passive: true });

  C.addEventListener('touchend', function(e) {
    if (mouseDown) {
      var burst = 15;
      for (var i = 0; i < burst; i++) {
        var a = Math.random() * TAU;
        var r = Math.random() * 30 + 10;
        var px = mouseX + Math.cos(a) * r;
        var py = mouseY + Math.sin(a) * r;
        if (px > radius && px < W / dpr - radius && py > radius && py < H / dpr - radius) {
          if (particles.length < maxN * 1.5) {
            particles.push(createParticle(px, py));
          }
        }
      }
      if (window.SFX) SFX.play('spawn');
    }
    mouseDown = false;
    rightDown = false;
  }, { passive: true });

  document.getElementById('sldDensity').addEventListener('input', function() {
    document.getElementById('valDensity').textContent = this.value;
  });
  document.getElementById('sldDensity').addEventListener('change', function() {
    resetSim();
    if (window.SFX) SFX.play('toggle');
  });

  document.getElementById('sldPersist').addEventListener('input', function() {
    document.getElementById('valPersist').textContent = this.value;
    rotNoise = 1 / (parseInt(this.value) * 0.1 + 1);
  });

  document.getElementById('sldSpeed').addEventListener('input', function() {
    document.getElementById('valSpeed').textContent = this.value;
    swimSpeed = parseInt(this.value) * 0.05;
  });

  document.getElementById('sldRadius').addEventListener('input', function() {
    document.getElementById('valRadius').textContent = this.value;
    radius = parseInt(this.value);
    buildGrid();
  });

  document.getElementById('btnTrail').addEventListener('click', function() {
    showTrail = !showTrail;
    this.classList.toggle('active', showTrail);
    if (!showTrail && trailCtx) {
      trailCtx.fillStyle = '#0a0a0f';
      trailCtx.fillRect(0, 0, W / dpr, H / dpr);
    }
    if (window.SFX) SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    resetSim();
    if (window.SFX) SFX.play('click');
  });

  window.addEventListener('resize', function() {
    resize();
    resetSim();
  });

  resize();
  resetSim();
  requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
