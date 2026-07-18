<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('coulomb');

$pageName = 'coulomb';
$pageTitle = 'COULOMB - PULSE';
$pageDesc = 'クーロン結晶。荷電粒子が調和トラップの中で反発し合い、同心円状の殻構造に自己組織化する。';
$pageCanonical = 'https://pulse.subara3.com/coulomb.php';
$extraStyle = '
body{background:#0a0a0f;margin:0;overflow:hidden}
canvas{position:fixed;top:0;left:0;width:100%;height:100%}
.hud{position:fixed;top:3.2rem;left:1rem;z-index:999;
  font:.7rem "JetBrains Mono","Fira Code",monospace;color:rgba(255,255,255,.56);
  display:flex;flex-direction:column;gap:.18rem;pointer-events:none}
.hud b{color:#00f5d4;min-width:2.5em;display:inline-block;text-align:right}
@media(max-width:768px){.hud{top:2.8rem;font-size:.6rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>N <b id="hN">0</b></div>
  <div>CFG <b id="hCfg">—</b></div>
  <div>E <b id="hE">0</b></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">TRAP</span>
    <input type="range" id="sTrap" min="0.2" max="5" step="0.1" value="1">
    <span class="slider-value" id="vTrap">1.0</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">TEMP</span>
    <input type="range" id="sTemp" min="0" max="2" step="0.01" value="0.05">
    <span class="slider-value" id="vTemp">0.05</span>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="btnBonds">BONDS</button>
    <button class="control-btn active" id="btnShells">SHELLS</button>
    <button class="control-btn" id="btnAuto">AUTO</button>
    <button class="control-btn" id="btnShake">SHAKE</button>
    <button class="control-btn" id="btnReset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var X = C.getContext('2d');
  var W, H, cx, cy, dpr;

  var BASE_K = 0.001;
  var trapMul = 1;
  var charge = 2.5;
  var damping = 0.88;
  var temp = 0.05;
  var dt = 0.4;
  var SOFT = 8;
  var SUBS = 8;
  var MAX_N = 150;

  var particles = [];
  var shells = [];
  var cfgStr = '—';
  var energy = 0;
  var showBonds = true;
  var showShellRings = true;
  var autoAdd = false;
  var bonds = [];
  var effects = [];
  var frame = 0;

  var dragging = false;
  var dragIdx = -1;
  var mx = 0, my = 0;
  var ptrStart = null;
  var ptrMoved = false;
  var longTimer = null;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2;
    cy = H / 2;
  }

  function rgba(r, g, b, a) {
    return 'rgba(' + (r | 0) + ',' + (g | 0) + ',' + (b | 0) + ',' + a + ')';
  }

  function addAt(x, y) {
    if (particles.length >= MAX_N) return;
    if (x === undefined) {
      var a = Math.random() * Math.PI * 2;
      var r = 20 + Math.random() * 80;
      x = cx + Math.cos(a) * r;
      y = cy + Math.sin(a) * r;
    }
    particles.push({ x: x, y: y, vx: 0, vy: 0 });
    effects.push({ x: x, y: y, r: 0, grow: 2.5, a: 0.7, c: 0 });
    SFX.play('spawn');
  }

  function removeAt(px, py) {
    if (!particles.length) return;
    var best = -1, bd = Infinity;
    for (var i = 0; i < particles.length; i++) {
      var dx = particles[i].x - px, dy = particles[i].y - py;
      var d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    if (best >= 0 && bd < 3600) {
      effects.push({ x: particles[best].x, y: particles[best].y, r: 4, grow: 2, a: 0.5, c: 1 });
      particles.splice(best, 1);
      if (dragging && dragIdx === best) { dragging = false; dragIdx = -1; }
      else if (dragging && dragIdx > best) dragIdx--;
      SFX.play('pop');
    }
  }

  function step() {
    var n = particles.length;
    var k = BASE_K * trapMul;
    for (var i = 0; i < n; i++) {
      var p = particles[i];
      if (dragging && i === dragIdx) {
        p.x += (mx - p.x) * 0.3;
        p.y += (my - p.y) * 0.3;
        p.vx = 0; p.vy = 0;
        continue;
      }
      var fx = -k * (p.x - cx);
      var fy = -k * (p.y - cy);
      for (var j = 0; j < n; j++) {
        if (j === i) continue;
        var dx = p.x - particles[j].x;
        var dy = p.y - particles[j].y;
        var r = Math.sqrt(dx * dx + dy * dy);
        if (r < SOFT) r = SOFT;
        var f = charge / (r * r);
        fx += f * dx / r;
        fy += f * dy / r;
      }
      if (temp > 0) {
        fx += (Math.random() - 0.5) * temp;
        fy += (Math.random() - 0.5) * temp;
      }
      p.vx = (p.vx + fx * dt) * damping;
      p.vy = (p.vy + fy * dt) * damping;
      p.x += p.vx * dt;
      p.y += p.vy * dt;
    }
  }

  function analyze() {
    var n = particles.length;
    if (n === 0) { shells = []; cfgStr = '—'; energy = 0; bonds = []; return; }
    var sx = 0, sy = 0;
    for (var i = 0; i < n; i++) { sx += particles[i].x; sy += particles[i].y; }
    sx /= n; sy /= n;
    var radii = [];
    for (var i = 0; i < n; i++) {
      var dx = particles[i].x - sx, dy = particles[i].y - sy;
      radii.push({ i: i, r: Math.sqrt(dx * dx + dy * dy) });
    }
    radii.sort(function(a, b) { return a.r - b.r; });

    shells = [];
    var cur = [radii[0].r];
    var gap = Math.max(15, radii[radii.length - 1].r * 0.1);
    for (var i = 1; i < radii.length; i++) {
      if (radii[i].r - radii[i - 1].r > gap) {
        var avg = cur.reduce(function(s, v) { return s + v; }, 0) / cur.length;
        shells.push({ r: avg, n: cur.length });
        cur = [];
      }
      cur.push(radii[i].r);
    }
    var avg = cur.reduce(function(s, v) { return s + v; }, 0) / cur.length;
    shells.push({ r: avg, n: cur.length });
    cfgStr = shells.map(function(s) { return s.n; }).join('-');

    var k = BASE_K * trapMul;
    var e = 0;
    for (var i = 0; i < n; i++) {
      var p = particles[i];
      var dr2 = (p.x - cx) * (p.x - cx) + (p.y - cy) * (p.y - cy);
      e += 0.5 * k * dr2;
      for (var j = i + 1; j < n; j++) {
        var dx = p.x - particles[j].x, dy = p.y - particles[j].y;
        var r = Math.sqrt(dx * dx + dy * dy);
        if (r < SOFT) r = SOFT;
        e += charge / r;
      }
    }
    energy = e;

    if (showBonds && n > 1 && n <= 100) {
      bonds = [];
      var drawn = {};
      for (var i = 0; i < n; i++) {
        var p = particles[i];
        var nbrs = [];
        for (var j = 0; j < n; j++) {
          if (j === i) continue;
          var dx = p.x - particles[j].x, dy = p.y - particles[j].y;
          nbrs.push({ j: j, d: Math.sqrt(dx * dx + dy * dy) });
        }
        nbrs.sort(function(a, b) { return a.d - b.d; });
        var cnt = Math.min(6, nbrs.length);
        for (var k2 = 0; k2 < cnt; k2++) {
          var j = nbrs[k2].j;
          var key = Math.min(i, j) * 1000 + Math.max(i, j);
          if (drawn[key] || nbrs[k2].d > 140) continue;
          drawn[key] = true;
          bonds.push({ a: i, b: j, d: nbrs[k2].d });
        }
      }
    } else if (!showBonds || n > 100) {
      bonds = [];
    }
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    var maxR = Math.min(W, H) * 0.45;
    for (var r = 50; r < maxR; r += 50) {
      X.beginPath();
      X.arc(cx, cy, r, 0, Math.PI * 2);
      X.strokeStyle = rgba(0, 245, 212, 0.03);
      X.lineWidth = 1;
      X.stroke();
    }
    X.beginPath();
    X.arc(cx, cy, 2.5, 0, Math.PI * 2);
    X.fillStyle = rgba(0, 245, 212, 0.1);
    X.fill();

    if (showShellRings && shells.length > 0 && particles.length > 0) {
      var sx = 0, sy = 0;
      for (var i = 0; i < particles.length; i++) {
        sx += particles[i].x; sy += particles[i].y;
      }
      sx /= particles.length; sy /= particles.length;
      X.setLineDash([5, 5]);
      X.lineWidth = 1;
      for (var i = 0; i < shells.length; i++) {
        X.beginPath();
        X.arc(sx, sy, shells[i].r, 0, Math.PI * 2);
        X.strokeStyle = rgba(123, 44, 191, 0.2);
        X.stroke();
      }
      X.setLineDash([]);
    }

    if (bonds.length > 0) {
      X.lineWidth = 1;
      for (var i = 0; i < bonds.length; i++) {
        var b = bonds[i];
        var pa = particles[b.a], pb = particles[b.b];
        var t = Math.min(1, b.d / 120);
        var alpha = 0.15 * (1 - t * 0.7);
        var r = (0 + 123 * t) | 0;
        var g = (245 - 201 * t) | 0;
        var bl = (212 - 21 * t) | 0;
        X.strokeStyle = rgba(r, g, bl, alpha);
        X.beginPath();
        X.moveTo(pa.x, pa.y);
        X.lineTo(pb.x, pb.y);
        X.stroke();
      }
    }

    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var isDrag = dragging && i === dragIdx;
      var gr = isDrag ? 28 : 20;
      var cr = isDrag ? 5 : 3.5;
      var g = X.createRadialGradient(p.x, p.y, 0, p.x, p.y, gr);
      if (isDrag) {
        g.addColorStop(0, rgba(0, 245, 212, 0.7));
        g.addColorStop(0.4, rgba(0, 245, 212, 0.15));
        g.addColorStop(1, rgba(0, 245, 212, 0));
      } else {
        g.addColorStop(0, rgba(0, 245, 212, 0.45));
        g.addColorStop(0.5, rgba(0, 245, 212, 0.08));
        g.addColorStop(1, rgba(0, 245, 212, 0));
      }
      X.fillStyle = g;
      X.beginPath();
      X.arc(p.x, p.y, gr, 0, Math.PI * 2);
      X.fill();
      X.fillStyle = isDrag ? '#fff' : '#00f5d4';
      X.beginPath();
      X.arc(p.x, p.y, cr, 0, Math.PI * 2);
      X.fill();
    }

    for (var i = effects.length - 1; i >= 0; i--) {
      var e = effects[i];
      e.r += e.grow;
      e.a -= 0.025;
      if (e.a <= 0) { effects.splice(i, 1); continue; }
      var col = e.c === 1 ? '123,44,191' : '0,245,212';
      X.strokeStyle = 'rgba(' + col + ',' + e.a + ')';
      X.lineWidth = 1.5;
      X.beginPath();
      X.arc(e.x, e.y, e.r, 0, Math.PI * 2);
      X.stroke();
    }

    document.getElementById('hN').textContent = particles.length;
    document.getElementById('hCfg').textContent = cfgStr;
    document.getElementById('hE').textContent = energy < 1000 ? energy.toFixed(0) : (energy / 1000).toFixed(1) + 'k';
  }

  function loop() {
    frame++;
    for (var s = 0; s < SUBS; s++) step();
    if (frame % 10 === 0) analyze();
    if (autoAdd && frame % 60 === 0 && particles.length < MAX_N) addAt();
    draw();
    requestAnimationFrame(loop);
  }

  function findNearest(px, py) {
    var best = -1, bd = Infinity;
    for (var i = 0; i < particles.length; i++) {
      var dx = particles[i].x - px, dy = particles[i].y - py;
      var d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    return bd < 900 ? best : -1;
  }

  function clearLong() { if (longTimer) { clearTimeout(longTimer); longTimer = null; } }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    ptrStart = { x: e.clientX, y: e.clientY };
    ptrMoved = false;
    mx = e.clientX; my = e.clientY;
    var idx = findNearest(mx, my);
    if (idx >= 0) { dragging = true; dragIdx = idx; }
  });
  C.addEventListener('mousemove', function(e) {
    mx = e.clientX; my = e.clientY;
    if (ptrStart) {
      var dx = mx - ptrStart.x, dy = my - ptrStart.y;
      if (dx * dx + dy * dy > 36) ptrMoved = true;
    }
  });
  C.addEventListener('mouseup', function(e) {
    if (e.button === 2) return;
    if (dragging) { dragging = false; dragIdx = -1; SFX.play('click'); }
    else if (!ptrMoved && ptrStart) addAt(mx, my);
    ptrStart = null;
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    removeAt(e.clientX, e.clientY);
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    mx = t.clientX; my = t.clientY;
    ptrStart = { x: mx, y: my };
    ptrMoved = false;
    var idx = findNearest(mx, my);
    if (idx >= 0) { dragging = true; dragIdx = idx; }
    clearLong();
    longTimer = setTimeout(function() {
      if (!ptrMoved) { removeAt(mx, my); ptrStart = null; }
      longTimer = null;
    }, 500);
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    mx = t.clientX; my = t.clientY;
    if (ptrStart) {
      var dx = mx - ptrStart.x, dy = my - ptrStart.y;
      if (dx * dx + dy * dy > 36) { ptrMoved = true; clearLong(); }
    }
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    clearLong();
    if (dragging) { dragging = false; dragIdx = -1; SFX.play('click'); }
    else if (!ptrMoved && ptrStart) addAt(mx, my);
    ptrStart = null;
  });

  var sTrap = document.getElementById('sTrap');
  var sTemp = document.getElementById('sTemp');
  sTrap.addEventListener('input', function() {
    trapMul = parseFloat(this.value);
    document.getElementById('vTrap').textContent = trapMul.toFixed(1);
  });
  sTemp.addEventListener('input', function() {
    temp = parseFloat(this.value);
    document.getElementById('vTemp').textContent = temp.toFixed(2);
  });
  document.getElementById('btnBonds').addEventListener('click', function() {
    showBonds = !showBonds; this.classList.toggle('active', showBonds); SFX.play('toggle');
  });
  document.getElementById('btnShells').addEventListener('click', function() {
    showShellRings = !showShellRings; this.classList.toggle('active', showShellRings); SFX.play('toggle');
  });
  document.getElementById('btnAuto').addEventListener('click', function() {
    autoAdd = !autoAdd; this.classList.toggle('active', autoAdd); SFX.play('toggle');
  });
  document.getElementById('btnShake').addEventListener('click', function() {
    for (var i = 0; i < particles.length; i++) {
      particles[i].vx += (Math.random() - 0.5) * 8;
      particles[i].vy += (Math.random() - 0.5) * 8;
    }
    SFX.play('collide');
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    particles = []; shells = []; bonds = []; effects = []; cfgStr = '—'; energy = 0;
    SFX.play('click');
    seed();
  });

  function seed() {
    for (var i = 0; i < 19; i++) {
      var a = Math.random() * Math.PI * 2;
      var r = Math.random() * 100;
      particles.push({ x: cx + Math.cos(a) * r, y: cy + Math.sin(a) * r, vx: 0, vy: 0 });
    }
    analyze();
  }

  resize();
  window.addEventListener('resize', resize);
  seed();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
