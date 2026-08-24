<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('plasma');

$pageName = 'plasma';
$pageTitle = 'PLASMA - PULSE';
$pageDesc = '二成分プラズマシミュレーション。電子とイオンのクーロン相互作用からデバイ遮蔽とプラズマ振動が創発する。';
$pageCanonical = 'https://pulse.subara3.com/plasma.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MODE <span id="hud-mode">EXPLORE</span></div>
  <div>e&#8315; <span id="hud-ne">0</span> &middot; i&#8314; <span id="hud-ni">0</span></div>
</div>
<div id="info-label"><span>PLASMA</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add / place'],
  ['type' => 'drag', 'label' => 'perturb'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row" id="mode-btns">
    <button class="ctrl-btn active" data-val="explore">EXPLORE</button>
    <button class="ctrl-btn" data-val="pulse">PULSE</button>
    <button class="ctrl-btn" data-val="screen">SCREEN</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">TEMP</label>
    <input type="range" id="sl-temp" min="5" max="200" value="80" step="5">
    <span class="slider-value" id="sv-temp">80</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">COUPLE</label>
    <input type="range" id="sl-k" min="100" max="2000" value="800" step="100">
    <span class="slider-value" id="sv-k">800</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-field">FIELD</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;

  var KCoulomb = 800;
  var SOFT2 = 196;
  var ME = 1, MI_MASS = 30;
  var DAMP = 0.9975;
  var MAXV = 14;
  var SUB = 4;
  var targetTemp = 80;

  var mode = 'explore';
  var showField = false;
  var particles = [];
  var testCharge = null;
  var dragging = false, dragX = 0, dragY = 0;
  var fc = 0;
  var pulseHist = [];

  var TEAL = [0, 245, 212];
  var PURP = [123, 44, 191];

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }

  function resize() {
    dpr = devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function makePart(x, y, q, m, type) {
    var sp = Math.sqrt(targetTemp / m) * (0.4 + Math.random() * 0.6);
    var a = Math.random() * 6.2832;
    return { x: x, y: y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp,
             ax: 0, ay: 0, q: q, m: m, type: type, trail: [] };
  }

  function addPair(cx, cy) {
    if (particles.length >= 200) return false;
    var off = 10 + Math.random() * 10;
    var a = Math.random() * 6.2832;
    particles.push(makePart(cx + Math.cos(a) * off, cy + Math.sin(a) * off, -1, ME, 'e'));
    particles.push(makePart(cx - Math.cos(a) * off, cy - Math.sin(a) * off, 1, MI_MASS, 'i'));
    return true;
  }

  function removeNear(x, y) {
    var best = -1, bestD = 2500;
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var d = (p.x - x) * (p.x - x) + (p.y - y) * (p.y - y);
      if (d < bestD) { bestD = d; best = i; }
    }
    if (best < 0) return false;
    var removed = particles[best];
    var partner = -1, partnerD = Infinity;
    for (var i = 0; i < particles.length; i++) {
      if (i === best || particles[i].type === removed.type) continue;
      var d = (particles[i].x - removed.x) * (particles[i].x - removed.x) +
              (particles[i].y - removed.y) * (particles[i].y - removed.y);
      if (d < partnerD) { partnerD = d; partner = i; }
    }
    if (partner >= 0) {
      var hi = Math.max(best, partner), lo = Math.min(best, partner);
      particles.splice(hi, 1);
      particles.splice(lo, 1);
    } else {
      particles.splice(best, 1);
    }
    return true;
  }

  function initParticles() {
    particles = [];
    testCharge = null;
    pulseHist = [];
    var pairs = Math.round(Math.min(W, H) / 30);
    pairs = Math.max(15, Math.min(pairs, 40));
    var mx = 80;
    for (var i = 0; i < pairs; i++) {
      addPair(mx + Math.random() * (W - 2 * mx), mx + Math.random() * (H - 2 * mx));
    }
    if (mode === 'screen') testCharge = { x: W * 0.5, y: H * 0.5, q: 8 };
    updateHUD();
  }

  function updateHUD() {
    var ne = 0, ni = 0;
    for (var i = 0; i < particles.length; i++) {
      if (particles[i].type === 'e') ne++; else ni++;
    }
    document.getElementById('hud-ne').textContent = ne;
    document.getElementById('hud-ni').textContent = ni;
    document.getElementById('hud-mode').textContent = mode.toUpperCase();
  }

  function computeForces() {
    var n = particles.length;
    for (var i = 0; i < n; i++) { particles[i].ax = 0; particles[i].ay = 0; }
    for (var i = 0; i < n; i++) {
      var pi = particles[i];
      for (var j = i + 1; j < n; j++) {
        var pj = particles[j];
        var dx = pj.x - pi.x, dy = pj.y - pi.y;
        var r2 = dx * dx + dy * dy + SOFT2;
        var r = Math.sqrt(r2);
        var fac = -KCoulomb * pi.q * pj.q / (r2 * r);
        pi.ax += fac * dx / pi.m;
        pi.ay += fac * dy / pi.m;
        pj.ax -= fac * dx / pj.m;
        pj.ay -= fac * dy / pj.m;
      }
    }
    if (testCharge) {
      for (var i = 0; i < n; i++) {
        var pi = particles[i];
        var dx = testCharge.x - pi.x, dy = testCharge.y - pi.y;
        var r2 = dx * dx + dy * dy + SOFT2;
        var r = Math.sqrt(r2);
        var fac = -KCoulomb * pi.q * testCharge.q / (r2 * r);
        pi.ax += fac * dx / pi.m;
        pi.ay += fac * dy / pi.m;
      }
    }
    if (dragging) {
      var DR2 = 8100;
      for (var i = 0; i < n; i++) {
        var pi = particles[i];
        var dx = pi.x - dragX, dy = pi.y - dragY;
        var d2 = dx * dx + dy * dy;
        if (d2 < DR2 && d2 > 1) {
          var d = Math.sqrt(d2);
          var f = 3.0 * (1 - d / 90);
          pi.ax += f * dx / d / pi.m;
          pi.ay += f * dy / d / pi.m;
        }
      }
    }
  }

  function step() {
    var h = 1.0 / SUB;
    for (var s = 0; s < SUB; s++) {
      computeForces();
      for (var i = 0; i < particles.length; i++) {
        var p = particles[i];
        p.vx = (p.vx + p.ax * h) * DAMP;
        p.vy = (p.vy + p.ay * h) * DAMP;
        var sp2 = p.vx * p.vx + p.vy * p.vy;
        if (sp2 > MAXV * MAXV) {
          var sc = MAXV / Math.sqrt(sp2);
          p.vx *= sc; p.vy *= sc;
        }
        p.x += p.vx * h;
        p.y += p.vy * h;
        if (p.x < 5) { p.x = 5; p.vx = Math.abs(p.vx) * 0.5; }
        if (p.x > W - 5) { p.x = W - 5; p.vx = -Math.abs(p.vx) * 0.5; }
        if (p.y < 5) { p.y = 5; p.vy = Math.abs(p.vy) * 0.5; }
        if (p.y > H - 5) { p.y = H - 5; p.vy = -Math.abs(p.vy) * 0.5; }
      }
    }
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      p.trail.push({ x: p.x, y: p.y });
      if (p.trail.length > 22) p.trail.shift();
    }
    fc++;
    if (fc % 6 === 0) thermostat();
    if (mode === 'pulse') trackPulse();
  }

  function trackPulse() {
    var exAvg = 0, ixAvg = 0, ne = 0, ni = 0;
    for (var i = 0; i < particles.length; i++) {
      if (particles[i].type === 'e') { exAvg += particles[i].x; ne++; }
      else { ixAvg += particles[i].x; ni++; }
    }
    if (ne > 0 && ni > 0) {
      pulseHist.push((exAvg / ne) - (ixAvg / ni));
      if (pulseHist.length > 300) pulseHist.shift();
    }
  }

  function thermostat() {
    var coupling = mode === 'pulse' ? 0.03 : 0.08;
    function rescale(type, mass) {
      var ke = 0, count = 0;
      for (var i = 0; i < particles.length; i++) {
        if (particles[i].type !== type) continue;
        ke += 0.5 * mass * (particles[i].vx * particles[i].vx + particles[i].vy * particles[i].vy);
        count++;
      }
      if (count === 0) return;
      var avg = ke / count;
      if (avg < 0.001) avg = 0.001;
      var s = 1 + (Math.sqrt(targetTemp * 0.5 / avg) - 1) * coupling;
      for (var i = 0; i < particles.length; i++) {
        if (particles[i].type === type) {
          particles[i].vx *= s;
          particles[i].vy *= s;
        }
      }
    }
    rescale('e', ME);
    rescale('i', MI_MASS);
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);
    drawGrid();
    if (showField) drawField();
    drawTrails();
    drawParticles();
    if (testCharge) drawTestCharge();
    if (mode === 'pulse' && pulseHist.length > 2) drawPulseGraph();
  }

  function drawGrid() {
    X.strokeStyle = 'rgba(255,255,255,0.015)';
    X.lineWidth = 1;
    var g = 80;
    X.beginPath();
    for (var x = g; x < W; x += g) { X.moveTo(x, 0); X.lineTo(x, H); }
    for (var y = g; y < H; y += g) { X.moveTo(0, y); X.lineTo(W, y); }
    X.stroke();
  }

  function drawTrails() {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i], t = p.trail, len = t.length;
      if (len < 2) continue;
      var c = p.type === 'e' ? TEAL : PURP;
      X.strokeStyle = rgba(c, 0.12);
      X.lineWidth = p.type === 'e' ? 0.8 : 1;
      X.beginPath();
      X.moveTo(t[0].x, t[0].y);
      for (var j = 1; j < len; j++) X.lineTo(t[j].x, t[j].y);
      X.stroke();
    }
  }

  function drawParticles() {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var c = p.type === 'e' ? TEAL : PURP;
      var rad = p.type === 'e' ? 2.5 : 3.5;
      var gr = p.type === 'e' ? 10 : 14;
      var g = X.createRadialGradient(p.x, p.y, 0, p.x, p.y, gr);
      g.addColorStop(0, rgba(c, 0.22));
      g.addColorStop(1, rgba(c, 0));
      X.fillStyle = g;
      X.beginPath(); X.arc(p.x, p.y, gr, 0, 6.2832); X.fill();
      X.fillStyle = rgba(c, 0.9);
      X.beginPath(); X.arc(p.x, p.y, rad, 0, 6.2832); X.fill();
    }
  }

  function drawTestCharge() {
    var tc = testCharge;
    var pulse = 0.5 + 0.3 * Math.sin(performance.now() * 0.003);
    var g = X.createRadialGradient(tc.x, tc.y, 0, tc.x, tc.y, 40);
    g.addColorStop(0, 'rgba(123,44,191,' + (0.25 * pulse) + ')');
    g.addColorStop(1, 'rgba(123,44,191,0)');
    X.fillStyle = g;
    X.beginPath(); X.arc(tc.x, tc.y, 40, 0, 6.2832); X.fill();
    X.strokeStyle = 'rgba(123,44,191,' + (0.35 + 0.3 * pulse) + ')';
    X.lineWidth = 2;
    X.beginPath(); X.arc(tc.x, tc.y, 18, 0, 6.2832); X.stroke();
    X.strokeStyle = 'rgba(123,44,191,' + (0.55 + 0.2 * pulse) + ')';
    X.lineWidth = 2;
    X.beginPath();
    X.moveTo(tc.x - 7, tc.y); X.lineTo(tc.x + 7, tc.y);
    X.moveTo(tc.x, tc.y - 7); X.lineTo(tc.x, tc.y + 7);
    X.stroke();
  }

  function drawField() {
    var nx = Math.ceil(W / 55), ny = Math.ceil(H / 55);
    var cw = W / nx, ch = H / ny;
    X.lineWidth = 1;
    X.beginPath();
    for (var ix = 0; ix < nx; ix++) {
      for (var iy = 0; iy < ny; iy++) {
        var px = (ix + 0.5) * cw, py = (iy + 0.5) * ch;
        var ex = 0, ey = 0;
        for (var i = 0; i < particles.length; i++) {
          var p = particles[i];
          var dx = px - p.x, dy = py - p.y;
          var r2 = dx * dx + dy * dy + SOFT2 * 4;
          var r = Math.sqrt(r2);
          var f = p.q / (r2 * r);
          ex += f * dx; ey += f * dy;
        }
        if (testCharge) {
          var dx = px - testCharge.x, dy = py - testCharge.y;
          var r2 = dx * dx + dy * dy + SOFT2 * 4;
          var r = Math.sqrt(r2);
          var f = testCharge.q / (r2 * r);
          ex += f * dx; ey += f * dy;
        }
        var mag = Math.sqrt(ex * ex + ey * ey);
        if (mag < 0.0000005) continue;
        var len = Math.min(18, Math.log(1 + mag * 80000) * 3);
        if (len < 1.5) continue;
        var ux = ex / mag, uy = ey / mag;
        X.moveTo(px, py);
        X.lineTo(px + ux * len, py + uy * len);
      }
    }
    X.strokeStyle = rgba(TEAL, 0.18);
    X.stroke();
  }

  function drawPulseGraph() {
    var gw = Math.min(240, W * 0.3);
    var gh = Math.min(70, H * 0.09);
    if (gh < 30 || gw < 80) return;
    var gx = 16, gy = H - gh - 65;
    X.fillStyle = 'rgba(10,10,15,0.82)';
    X.fillRect(gx, gy, gw, gh);
    X.strokeStyle = 'rgba(255,255,255,0.06)';
    X.lineWidth = 1;
    X.strokeRect(gx, gy, gw, gh);
    var cy = gy + gh * 0.5;
    X.strokeStyle = 'rgba(255,255,255,0.08)';
    X.setLineDash([3, 3]);
    X.beginPath(); X.moveTo(gx, cy); X.lineTo(gx + gw, cy); X.stroke();
    X.setLineDash([]);
    X.font = '8px Space Grotesk,monospace';
    X.fillStyle = 'rgba(255,255,255,0.2)';
    X.textAlign = 'left';
    X.fillText('e⁻ DISPLACEMENT', gx + 5, gy + 11);
    var maxD = 1;
    for (var i = 0; i < pulseHist.length; i++) {
      var v = Math.abs(pulseHist[i]);
      if (v > maxD) maxD = v;
    }
    X.save();
    X.beginPath(); X.rect(gx, gy, gw, gh); X.clip();
    X.strokeStyle = rgba(TEAL, 0.7);
    X.lineWidth = 1.5;
    X.beginPath();
    for (var i = 0; i < pulseHist.length; i++) {
      var px = gx + (i / 300) * gw;
      var py = cy - (pulseHist[i] / maxD) * (gh * 0.4);
      if (i === 0) X.moveTo(px, py); else X.lineTo(px, py);
    }
    X.stroke();
    X.restore();
  }

  function handleClick(x, y, isRight) {
    if (isRight) {
      if (mode === 'screen') {
        testCharge = null;
        if (window.SFX) SFX.play('pop');
      } else {
        if (removeNear(x, y)) {
          if (window.SFX) SFX.play('pop');
          updateHUD();
        }
      }
      return;
    }
    if (mode === 'explore') {
      if (addPair(x, y)) {
        if (window.SFX) SFX.play('spawn');
        updateHUD();
      }
    } else if (mode === 'pulse') {
      pulseHist = [];
      var shift = (x - W * 0.5) * 0.08;
      shift = Math.max(-60, Math.min(60, shift));
      if (Math.abs(shift) < 10) shift = shift >= 0 ? 30 : -30;
      for (var i = 0; i < particles.length; i++) {
        if (particles[i].type === 'e') {
          particles[i].x += shift;
          particles[i].x = Math.max(5, Math.min(W - 5, particles[i].x));
        }
      }
      if (window.SFX) SFX.play('ripple');
    } else if (mode === 'screen') {
      testCharge = { x: x, y: y, q: 8 };
      if (window.SFX) SFX.play('spawn');
    }
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var r = C.getBoundingClientRect();
    handleClick(e.clientX - r.left, e.clientY - r.top, false);
    dragging = true;
    dragX = e.clientX - r.left;
    dragY = e.clientY - r.top;
  });
  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var r = C.getBoundingClientRect();
    dragX = e.clientX - r.left;
    dragY = e.clientY - r.top;
    if (window.SFX) SFX.play('move', 120);
  });
  C.addEventListener('mouseup', function() { dragging = false; });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var r = C.getBoundingClientRect();
    handleClick(e.clientX - r.left, e.clientY - r.top, true);
  });

  var longPress = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0], r = C.getBoundingClientRect();
    var tx = t.clientX - r.left, ty = t.clientY - r.top;
    handleClick(tx, ty, false);
    dragging = true; dragX = tx; dragY = ty;
    longPress = setTimeout(function() {
      handleClick(tx, ty, true);
      longPress = null;
    }, 500);
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (longPress) { clearTimeout(longPress); longPress = null; }
    if (!dragging) return;
    var t = e.touches[0], r = C.getBoundingClientRect();
    dragX = t.clientX - r.left;
    dragY = t.clientY - r.top;
  }, { passive: false });
  C.addEventListener('touchend', function() {
    dragging = false;
    if (longPress) { clearTimeout(longPress); longPress = null; }
  });

  document.querySelectorAll('#mode-btns button').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#mode-btns button').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      var newMode = this.dataset.val;
      if (newMode === 'pulse' && mode !== 'pulse') {
        pulseHist = [];
        for (var i = 0; i < particles.length; i++) {
          if (particles[i].type === 'e') {
            particles[i].x += 40;
            if (particles[i].x > W - 5) particles[i].x = W - 5;
          }
        }
      }
      if (newMode === 'screen') {
        testCharge = { x: W * 0.5, y: H * 0.5, q: 8 };
      } else {
        testCharge = null;
      }
      mode = newMode;
      updateHUD();
      if (window.SFX) SFX.play('toggle');
    });
  });

  var slTemp = document.getElementById('sl-temp');
  var svTemp = document.getElementById('sv-temp');
  var slK = document.getElementById('sl-k');
  var svK = document.getElementById('sv-k');
  slTemp.addEventListener('input', function() { targetTemp = +this.value; svTemp.textContent = targetTemp; });
  slK.addEventListener('input', function() { KCoulomb = +this.value; svK.textContent = KCoulomb; });

  document.getElementById('btn-field').addEventListener('click', function() {
    showField = !showField;
    this.classList.toggle('active', showField);
    if (window.SFX) SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    initParticles();
    if (window.SFX) SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  initParticles();
  (function loop() { step(); draw(); requestAnimationFrame(loop); })();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
