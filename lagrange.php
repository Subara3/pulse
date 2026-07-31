<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lagrange');

$pageName = 'lagrange';
$pageTitle = 'LAGRANGE - PULSE';
$pageDesc = '制限三体問題のラグランジュ点。共回転座標系で有効ポテンシャルとゼロ速度曲線を描き、L1-L5の平衡点を体験する。';
$pageCanonical = 'https://pulse.subara3.com/lagrange.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>μ <span id="hud-mu">0.012</span></div>
  <div>N <span id="hud-n">0</span></div>
  <div>J <span id="hud-jacobi">—</span></div>
</div>
<div id="info-label"><span>ROTATING FRAME</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'launch'],
  ['type' => 'drag', 'label' => 'aim'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">MASS RATIO</label>
    <input type="range" id="sl-mu" min="1" max="100" value="12" step="1">
    <span class="slider-value" id="sv-mu">0.012</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">JACOBI</label>
    <input type="range" id="sl-jacobi" min="0" max="100" value="60" step="1">
    <span class="slider-value" id="sv-jacobi">3.20</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="100" value="50" step="1">
    <span class="slider-value" id="sv-speed">1.0×</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-potential">POTENTIAL</button>
    <button class="ctrl-btn active" id="btn-zvc">ZVC</button>
    <button class="ctrl-btn" id="btn-labels">LABELS</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-auto">AUTO</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr, cx, cy, SC;

  var mu = 0.012;
  var showPot = true, showZVC = true, showLabels = false, autoOn = true;
  var spdMul = 1;
  var jacobiLevel = 3.2;
  var particles = [], MAXP = 40, TRAIL = 300;
  var lastT = 0, autoTmr = 0;
  var isDown = false, moved = false, dStart = null, dEnd = null;
  var potImg = null, potDirty = true;

  var hMu = document.getElementById('hud-mu');
  var hN = document.getElementById('hud-n');
  var hJ = document.getElementById('hud-jacobi');

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2; cy = H / 2;
    SC = Math.min(W, H) * 0.28;
    potDirty = true;
  }

  function effPot(x, y) {
    var x1 = x + mu, y1 = y;
    var x2 = x - (1 - mu), y2 = y;
    var r1 = Math.sqrt(x1 * x1 + y1 * y1);
    var r2 = Math.sqrt(x2 * x2 + y2 * y2);
    if (r1 < 0.01) r1 = 0.01;
    if (r2 < 0.01) r2 = 0.01;
    return -(x * x + y * y) / 2 - (1 - mu) / r1 - mu / r2 - mu * (1 - mu) / 2;
  }

  function jacobi(x, y, vx, vy) {
    return -2 * effPot(x, y) - (vx * vx + vy * vy);
  }

  function findL1() {
    var lo = -mu, hi = 1 - mu, mid;
    for (var i = 0; i < 60; i++) {
      mid = (lo + hi) / 2;
      var x1 = mid + mu, x2 = mid - (1 - mu);
      var f = mid - (1 - mu) / (x1 * x1) * (x1 > 0 ? 1 : -1) + mu / (x2 * x2) * (x2 > 0 ? 1 : -1);
      if (f < 0) lo = mid; else hi = mid;
    }
    return mid;
  }

  function findL2() {
    var lo = 1 - mu, hi = 3, mid;
    for (var i = 0; i < 60; i++) {
      mid = (lo + hi) / 2;
      var x1 = mid + mu, x2 = mid - (1 - mu);
      var f = mid - (1 - mu) / (x1 * x1) - mu / (x2 * x2);
      if (f < 0) lo = mid; else hi = mid;
    }
    return mid;
  }

  function findL3() {
    var lo = -3, hi = -mu, mid;
    for (var i = 0; i < 60; i++) {
      mid = (lo + hi) / 2;
      var x1 = mid + mu, x2 = mid - (1 - mu);
      var f = mid + (1 - mu) / (x1 * x1) + mu / (x2 * x2);
      if (f > 0) lo = mid; else hi = mid;
    }
    return mid;
  }

  function getLagrangePoints() {
    var l1x = findL1(), l2x = findL2(), l3x = findL3();
    return [
      { x: l1x, y: 0, label: 'L1' },
      { x: l2x, y: 0, label: 'L2' },
      { x: l3x, y: 0, label: 'L3' },
      { x: 0.5 - mu, y: Math.sqrt(3) / 2, label: 'L4' },
      { x: 0.5 - mu, y: -Math.sqrt(3) / 2, label: 'L5' }
    ];
  }

  function renderPotential() {
    var step = 3;
    var pw = Math.ceil(W / step), ph = Math.ceil(H / step);
    var oc = document.createElement('canvas');
    oc.width = pw; oc.height = ph;
    var ox = oc.getContext('2d');
    var id = ox.createImageData(pw, ph);
    var d = id.data;

    for (var py = 0; py < ph; py++) {
      for (var px = 0; px < pw; px++) {
        var sx = (px * step - cx) / SC;
        var sy = (py * step - cy) / SC;
        var U = -effPot(sx, sy);
        var v = (U - 1.2) / 3.5;
        v = Math.max(0, Math.min(1, v));
        var idx = (py * pw + px) * 4;
        var r = Math.floor(v * 0 + (1 - v) * 10);
        var g = Math.floor(v * 80 + (1 - v) * 10);
        var b = Math.floor(v * 65 + (1 - v) * 15);
        d[idx] = r; d[idx + 1] = g; d[idx + 2] = b; d[idx + 3] = 40 + Math.floor(v * 50);
      }
    }
    ox.putImageData(id, 0, 0);
    potImg = oc;
    potDirty = false;
  }

  function accel(x, y, vx, vy) {
    var x1 = x + mu, x2 = x - (1 - mu);
    var r1sq = x1 * x1 + y * y;
    var r2sq = x2 * x2 + y * y;
    var r1 = Math.sqrt(r1sq); var r2 = Math.sqrt(r2sq);
    if (r1 < 0.005) r1 = 0.005;
    if (r2 < 0.005) r2 = 0.005;
    var r1c = r1 * r1 * r1, r2c = r2 * r2 * r2;

    var ax = x + 2 * vy - (1 - mu) * x1 / r1c - mu * x2 / r2c;
    var ay = y - 2 * vx - (1 - mu) * y / r1c - mu * y / r2c;
    return [ax, ay];
  }

  function rk4(p, dt) {
    var x = p.x, y = p.y, vx = p.vx, vy = p.vy;

    var a1 = accel(x, y, vx, vy);
    var k1vx = a1[0], k1vy = a1[1];
    var k1x = vx, k1y = vy;

    var a2 = accel(x + k1x * dt / 2, y + k1y * dt / 2, vx + k1vx * dt / 2, vy + k1vy * dt / 2);
    var k2vx = a2[0], k2vy = a2[1];
    var k2x = vx + k1vx * dt / 2, k2y = vy + k1vy * dt / 2;

    var a3 = accel(x + k2x * dt / 2, y + k2y * dt / 2, vx + k2vx * dt / 2, vy + k2vy * dt / 2);
    var k3vx = a3[0], k3vy = a3[1];
    var k3x = vx + k2vx * dt / 2, k3y = vy + k2vy * dt / 2;

    var a4 = accel(x + k3x * dt, y + k3y * dt, vx + k3vx * dt, vy + k3vy * dt);
    var k4vx = a4[0], k4vy = a4[1];
    var k4x = vx + k3vx * dt, k4y = vy + k3vy * dt;

    p.x += (k1x + 2 * k2x + 2 * k3x + k4x) * dt / 6;
    p.y += (k1y + 2 * k2y + 2 * k3y + k4y) * dt / 6;
    p.vx += (k1vx + 2 * k2vx + 2 * k3vx + k4vx) * dt / 6;
    p.vy += (k1vy + 2 * k2vy + 2 * k3vy + k4vy) * dt / 6;
  }

  function spawnParticle(sx, sy, svx, svy) {
    if (particles.length >= MAXP) particles.shift();
    var hue = 170 + Math.random() * 90;
    particles.push({ x: sx, y: sy, vx: svx, vy: svy, trail: [], age: 0, hue: hue });
    if (typeof SFX !== 'undefined') SFX.play('spawn', 80);
  }

  function autoSpawn() {
    var lps = getLagrangePoints();
    var idx = Math.floor(Math.random() * 5);
    var lp = lps[idx];
    var off = 0.02 + Math.random() * 0.05;
    var ang = Math.random() * Math.PI * 2;
    var px = lp.x + Math.cos(ang) * off;
    var py = lp.y + Math.sin(ang) * off;
    var vs = 0.05 + Math.random() * 0.15;
    var va = Math.random() * Math.PI * 2;
    spawnParticle(px, py, Math.cos(va) * vs, Math.sin(va) * vs);
  }

  function update(dt) {
    var sub = 4;
    var sdt = dt / sub;
    if (autoOn) {
      autoTmr += dt;
      if (autoTmr > 1.2) { autoTmr = 0; autoSpawn(); }
    }
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      for (var s = 0; s < sub; s++) rk4(p, sdt);
      p.trail.push({ x: p.x, y: p.y });
      if (p.trail.length > TRAIL) p.trail.shift();
      p.age += dt;
      var r = Math.sqrt(p.x * p.x + p.y * p.y);
      if (r > 8 || p.age > 120) particles.splice(i, 1);
    }
  }

  function drawZVC() {
    var step = 4;
    for (var py = 0; py < H; py += step) {
      for (var px = 0; px < W; px += step) {
        var sx = (px - cx) / SC;
        var sy = (py - cy) / SC;
        var cj = -2 * effPot(sx, sy);
        if (cj > jacobiLevel) {
          X.fillStyle = 'rgba(123,44,191,0.12)';
          X.fillRect(px, py, step, step);
        }
      }
    }

    X.strokeStyle = 'rgba(123,44,191,0.5)';
    X.lineWidth = 1.2;
    var res = 2;
    for (var py = 0; py < H; py += res) {
      for (var px = 0; px < W; px += res) {
        var sx = (px - cx) / SC;
        var sy = (py - cy) / SC;
        var c00 = -2 * effPot(sx, sy) - jacobiLevel;
        var c10 = -2 * effPot(sx + res / SC, sy) - jacobiLevel;
        var c01 = -2 * effPot(sx, sy + res / SC) - jacobiLevel;
        if ((c00 > 0) !== (c10 > 0) || (c00 > 0) !== (c01 > 0)) {
          X.fillStyle = 'rgba(123,44,191,0.6)';
          X.fillRect(px, py, 1.5, 1.5);
        }
      }
    }
  }

  function drawBodies() {
    var m1x = cx + (-mu) * SC, m1y = cy;
    var m2x = cx + (1 - mu) * SC, m2y = cy;

    var g1 = X.createRadialGradient(m1x, m1y, 2, m1x, m1y, 25);
    g1.addColorStop(0, 'rgba(0,245,212,0.9)');
    g1.addColorStop(0.3, 'rgba(0,245,212,0.3)');
    g1.addColorStop(1, 'rgba(0,245,212,0)');
    X.beginPath(); X.arc(m1x, m1y, 25, 0, Math.PI * 2);
    X.fillStyle = g1; X.fill();
    X.beginPath(); X.arc(m1x, m1y, 6, 0, Math.PI * 2);
    X.fillStyle = '#00f5d4'; X.fill();

    var r2 = 3 + mu * 15;
    var g2 = X.createRadialGradient(m2x, m2y, 1, m2x, m2y, r2 * 3);
    g2.addColorStop(0, 'rgba(123,44,191,0.9)');
    g2.addColorStop(0.3, 'rgba(123,44,191,0.3)');
    g2.addColorStop(1, 'rgba(123,44,191,0)');
    X.beginPath(); X.arc(m2x, m2y, r2 * 3, 0, Math.PI * 2);
    X.fillStyle = g2; X.fill();
    X.beginPath(); X.arc(m2x, m2y, r2, 0, Math.PI * 2);
    X.fillStyle = '#7b2cbf'; X.fill();

    X.strokeStyle = 'rgba(0,245,212,0.06)';
    X.lineWidth = 0.7;
    X.setLineDash([4, 6]);
    X.beginPath(); X.arc(cx, cy, SC, 0, Math.PI * 2); X.stroke();
    X.setLineDash([]);
  }

  function drawLagrangePoints() {
    var lps = getLagrangePoints();
    for (var i = 0; i < lps.length; i++) {
      var lp = lps[i];
      var px = cx + lp.x * SC, py = cy + lp.y * SC;
      X.strokeStyle = 'rgba(0,245,212,0.35)';
      X.lineWidth = 1;
      X.beginPath();
      X.moveTo(px - 6, py); X.lineTo(px + 6, py);
      X.moveTo(px, py - 6); X.lineTo(px, py + 6);
      X.stroke();

      if (showLabels) {
        X.font = '10px "Orbitron", monospace';
        X.fillStyle = 'rgba(0,245,212,0.5)';
        X.fillText(lp.label, px + 9, py + 4);
      }
    }
  }

  function drawParticles() {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var tr = p.trail;

      if (tr.length > 2) {
        var segs = 6, sl = Math.floor(tr.length / segs);
        for (var s = 0; s < segs; s++) {
          var sa = s * sl, sb = Math.min(sa + sl + 1, tr.length);
          if (sb - sa < 2) continue;
          var al = ((s + 0.5) / segs) * 0.4;
          X.beginPath();
          X.moveTo(cx + tr[sa].x * SC, cy + tr[sa].y * SC);
          for (var j = sa + 1; j < sb; j++) {
            X.lineTo(cx + tr[j].x * SC, cy + tr[j].y * SC);
          }
          X.strokeStyle = 'hsla(' + Math.floor(p.hue) + ',70%,60%,' + al.toFixed(3) + ')';
          X.lineWidth = 0.6 + al * 2.5;
          X.stroke();
        }
      }

      var px = cx + p.x * SC, py = cy + p.y * SC;
      var gl = X.createRadialGradient(px, py, 1, px, py, 10);
      gl.addColorStop(0, 'hsla(' + Math.floor(p.hue) + ',70%,65%,0.6)');
      gl.addColorStop(1, 'hsla(' + Math.floor(p.hue) + ',70%,65%,0)');
      X.beginPath(); X.arc(px, py, 10, 0, Math.PI * 2);
      X.fillStyle = gl; X.fill();
      X.beginPath(); X.arc(px, py, 2.5, 0, Math.PI * 2);
      X.fillStyle = 'hsl(' + Math.floor(p.hue) + ',70%,70%)';
      X.fill();
    }
  }

  function draw() {
    X.fillStyle = '#0a0a0f'; X.fillRect(0, 0, W, H);

    if (showPot && potImg) {
      X.drawImage(potImg, 0, 0, W, H);
    }

    if (showZVC) drawZVC();
    drawBodies();
    drawLagrangePoints();
    drawParticles();

    if (isDown && dStart && dEnd) {
      X.strokeStyle = 'rgba(0,245,212,0.4)';
      X.lineWidth = 1.5;
      X.setLineDash([4, 4]);
      X.beginPath();
      X.moveTo(dStart[0], dStart[1]);
      X.lineTo(dEnd[0], dEnd[1]);
      X.stroke();
      X.setLineDash([]);

      var dx = dEnd[0] - dStart[0], dy = dEnd[1] - dStart[1];
      var l = Math.sqrt(dx * dx + dy * dy);
      if (l > 5) {
        var nx = dx / l, ny = dy / l;
        X.beginPath();
        X.moveTo(dEnd[0], dEnd[1]);
        X.lineTo(dEnd[0] - 8 * nx + 4 * ny, dEnd[1] - 8 * ny - 4 * nx);
        X.lineTo(dEnd[0] - 8 * nx - 4 * ny, dEnd[1] - 8 * ny + 4 * nx);
        X.closePath();
        X.fillStyle = 'rgba(0,245,212,0.4)';
        X.fill();
      }
    }

    hN.textContent = particles.length;
    if (particles.length > 0) {
      var lp = particles[particles.length - 1];
      var j = jacobi(lp.x, lp.y, lp.vx, lp.vy);
      hJ.textContent = j.toFixed(3);
    }
  }

  function loop(t) {
    if (!lastT) lastT = t;
    var dt = Math.min((t - lastT) / 1000, 0.05) * spdMul;
    lastT = t;
    if (potDirty && showPot) renderPotential();
    if (dt > 0) update(dt);
    draw();
    requestAnimationFrame(loop);
  }

  function screenToWorld(sx, sy) {
    return [(sx - cx) / SC, (sy - cy) / SC];
  }

  function gxy(e) {
    var r = C.getBoundingClientRect();
    if (e.touches && e.touches.length) {
      return [e.touches[0].clientX - r.left, e.touches[0].clientY - r.top];
    }
    return [e.clientX - r.left, e.clientY - r.top];
  }

  function fire() {
    if (!isDown) return;
    isDown = false;
    if (dStart) {
      var w = screenToWorld(dStart[0], dStart[1]);
      var vx = 0, vy = 0;
      if (moved && dEnd) {
        vx = (dEnd[0] - dStart[0]) / SC * 0.6;
        vy = (dEnd[1] - dStart[1]) / SC * 0.6;
      }
      spawnParticle(w[0], w[1], vx, vy);
    }
    dStart = null; dEnd = null;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    isDown = true; moved = false;
    dStart = gxy(e); dEnd = null;
  });
  C.addEventListener('mousemove', function(e) {
    if (!isDown) return; moved = true; dEnd = gxy(e);
  });
  document.addEventListener('mouseup', fire);

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    isDown = true; moved = false;
    dStart = gxy(e); dEnd = null;
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!isDown) return; moved = true; dEnd = gxy(e);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault(); fire();
  }, { passive: false });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    particles.length = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var slMu = document.getElementById('sl-mu'), svMu = document.getElementById('sv-mu');
  var slJ = document.getElementById('sl-jacobi'), svJ = document.getElementById('sv-jacobi');
  var slS = document.getElementById('sl-speed'), svS = document.getElementById('sv-speed');

  slMu.addEventListener('input', function() {
    mu = this.value / 1000;
    svMu.textContent = mu.toFixed(3); hMu.textContent = mu.toFixed(3);
    potDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  });
  slJ.addEventListener('input', function() {
    jacobiLevel = 2.5 + this.value / 100 * 2.5;
    svJ.textContent = jacobiLevel.toFixed(2);
  });
  slS.addEventListener('input', function() {
    spdMul = this.value / 50;
    svS.textContent = spdMul.toFixed(1) + '×';
  });

  document.getElementById('btn-potential').addEventListener('click', function() {
    showPot = !showPot;
    this.classList.toggle('active', showPot);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-zvc').addEventListener('click', function() {
    showZVC = !showZVC;
    this.classList.toggle('active', showZVC);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-labels').addEventListener('click', function() {
    showLabels = !showLabels;
    this.classList.toggle('active', showLabels);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-auto').addEventListener('click', function() {
    autoOn = !autoOn;
    this.classList.toggle('active', autoOn);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    particles.length = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  window.addEventListener('resize', function() { resize(); });

  var lps = getLagrangePoints();
  for (var i = 0; i < lps.length; i++) {
    var lp = lps[i];
    var off = 0.03;
    var ang = Math.random() * Math.PI * 2;
    spawnParticle(lp.x + Math.cos(ang) * off, lp.y + Math.sin(ang) * off,
                  Math.cos(ang + 1.5) * 0.08, Math.sin(ang + 1.5) * 0.08);
  }

  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
