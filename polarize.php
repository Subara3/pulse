<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('polarize');

$pageName = 'polarize';
$pageTitle = 'POLARIZE - PULSE';
$pageDesc = '偏光の可視化。偏光子が光の振動方向を選別し、マリュスの法則に従って強度が変わる。交差偏光子の間に45°の偏光子を挟むと、消えたはずの光が復活する。';
$pageCanonical = 'https://pulse.subara3.com/polarize.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>TRANSMIT <span id="hud-t">100%</span></div>
  <div>FILTERS <span id="hud-f">0</span></div>
</div>
<div id="info-label"><span>POLARIZE</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add filter'],
  ['type' => 'drag', 'label' => 'rotate'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">PRESET</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" data-preset="single">SINGLE</button>
    <button class="ctrl-btn" data-preset="crossed">CROSSED</button>
    <button class="ctrl-btn" data-preset="rescue">RESCUE</button>
    <button class="ctrl-btn" data-preset="sweep">SWEEP</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;
  function resize() { W = C.width = innerWidth; H = C.height = innerHeight; }
  resize();
  addEventListener('resize', function() { resize(); initPs(); });

  var pols = [];
  var sweeping = false;
  var time = 0;
  var dragIdx = -1, dragStartY = 0, dragStartA = 0;
  var hoverIdx = -1;
  var longTimer = null, touchMoved = false, touchId = null;

  function sortPols() { pols.sort(function(a, b) { return a.x - b.x; }); }

  function nearPol(mx) {
    var best = -1, bd = 30;
    for (var i = 0; i < pols.length; i++) {
      var d = Math.abs(mx - pols[i].x);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function addPol(x, a) {
    pols.push({ x: x, angle: a });
    sortPols();
    updHud();
    SFX.play('spawn');
  }

  function rmPol(i) {
    pols.splice(i, 1);
    updHud();
    SFX.play('pop');
  }

  var NUM = 600;
  var ps = [];

  function mkP(sx) {
    return {
      x: sx !== undefined ? sx : -Math.random() * 80,
      y: Math.random() * H,
      vx: 1.0 + Math.random() * 0.8,
      vy: (Math.random() - 0.5) * 0.12,
      ia: Math.random() * Math.PI,
      ph: Math.random() * Math.PI * 2,
      sz: 1.2 + Math.random() * 0.5
    };
  }

  function initPs() {
    ps = [];
    for (var i = 0; i < NUM; i++) ps.push(mkP(Math.random() * W));
  }

  function stateAt(ia, x) {
    var a = ia, I = 1;
    for (var i = 0; i < pols.length; i++) {
      if (x > pols[i].x) {
        var d = a - pols[i].angle;
        var c = Math.cos(d);
        I *= c * c;
        a = pols[i].angle;
      }
    }
    return { a: a, I: I };
  }

  function outI() {
    if (!pols.length) return 1;
    var s = pols.slice().sort(function(a, b) { return a.x - b.x; });
    var I = 0.5;
    for (var i = 1; i < s.length; i++) {
      var d = s[i].angle - s[i - 1].angle;
      I *= Math.cos(d) * Math.cos(d);
    }
    return I;
  }

  var hudT = document.getElementById('hud-t');
  var hudF = document.getElementById('hud-f');
  function updHud() {
    hudT.textContent = Math.round(outI() * 100) + '%';
    hudF.textContent = pols.length;
  }

  var pBtns = document.querySelectorAll('[data-preset]');
  function setPreset(name) {
    pols = [];
    sweeping = false;
    if (name === 'single') {
      pols.push({ x: W * 0.5, angle: Math.PI / 6 });
    } else if (name === 'crossed') {
      pols.push({ x: W * 0.35, angle: 0 });
      pols.push({ x: W * 0.65, angle: Math.PI / 2 });
    } else if (name === 'rescue') {
      pols.push({ x: W * 0.25, angle: 0 });
      pols.push({ x: W * 0.5, angle: Math.PI / 4 });
      pols.push({ x: W * 0.75, angle: Math.PI / 2 });
    } else if (name === 'sweep') {
      pols.push({ x: W * 0.5, angle: 0 });
      sweeping = true;
    }
    sortPols();
    updHud();
    SFX.play('toggle');
    for (var i = 0; i < pBtns.length; i++)
      pBtns[i].classList.toggle('active', pBtns[i].getAttribute('data-preset') === name);
  }

  for (var i = 0; i < pBtns.length; i++)
    pBtns[i].addEventListener('click', function() { setPreset(this.getAttribute('data-preset')); });

  document.getElementById('btn-clear').addEventListener('click', function() {
    pols = []; sweeping = false; updHud(); SFX.play('click');
    for (var i = 0; i < pBtns.length; i++) pBtns[i].classList.remove('active');
  });

  function drawPolarizers() {
    for (var i = 0; i < pols.length; i++) {
      var p = pols[i];
      var ca = Math.cos(p.angle), sa = Math.sin(p.angle);

      var g = ctx.createLinearGradient(p.x - 40, 0, p.x + 40, 0);
      g.addColorStop(0, 'rgba(123,44,191,0)');
      g.addColorStop(0.35, 'rgba(123,44,191,0.04)');
      g.addColorStop(0.5, 'rgba(123,44,191,0.1)');
      g.addColorStop(0.65, 'rgba(123,44,191,0.04)');
      g.addColorStop(1, 'rgba(123,44,191,0)');
      ctx.fillStyle = g;
      ctx.fillRect(p.x - 40, 0, 80, H);

      ctx.fillStyle = 'rgba(123,44,191,0.45)';
      ctx.fillRect(p.x - 2, 0, 4, H);

      ctx.strokeStyle = 'rgba(181,140,255,0.22)';
      ctx.lineWidth = 1;
      for (var y = 8; y < H; y += 14) {
        ctx.beginPath();
        ctx.moveTo(p.x - 10 * ca, y - 10 * sa);
        ctx.lineTo(p.x + 10 * ca, y + 10 * sa);
        ctx.stroke();
      }

      ctx.beginPath();
      ctx.arc(p.x, H / 2, 7, 0, Math.PI * 2);
      ctx.fillStyle = i === hoverIdx ? 'rgba(181,140,255,0.5)' : 'rgba(123,44,191,0.35)';
      ctx.fill();
      ctx.strokeStyle = 'rgba(181,140,255,0.6)';
      ctx.lineWidth = 1;
      ctx.stroke();

      var deg = ((p.angle * 180 / Math.PI) % 180 + 180) % 180;
      ctx.fillStyle = 'rgba(181,140,255,0.7)';
      ctx.font = '11px "Space Grotesk",monospace';
      ctx.textAlign = 'center';
      ctx.fillText(Math.round(deg) + '°', p.x, 28);
    }
    ctx.textAlign = 'left';
  }

  function drawParticles() {
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      var st = stateAt(p.ia, p.x);
      var I = st.I;
      if (I < 0.005) continue;

      var osc = Math.sin(p.ph) * p.sz * 6;
      var ca = Math.cos(st.a), sa = Math.sin(st.a);

      ctx.strokeStyle = 'rgba(0,245,212,' + (I * 0.4).toFixed(3) + ')';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(p.x + osc * ca, p.y + osc * sa);
      ctx.lineTo(p.x - osc * ca, p.y - osc * sa);
      ctx.stroke();

      var r = p.sz * Math.max(I, 0.15);
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,' + (I * 0.6).toFixed(3) + ')';
      ctx.fill();
    }
  }

  function drawEndOn() {
    var r = 26, cx = 50, cy = H - 60;

    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,245,212,0.03)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 1;
    ctx.stroke();

    if (!pols.length) {
      for (var j = 0; j < 6; j++) {
        var la = (time * 0.4 + j * Math.PI / 6) % Math.PI;
        ctx.strokeStyle = 'rgba(0,245,212,0.18)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(cx - r * 0.6 * Math.cos(la), cy - r * 0.6 * Math.sin(la));
        ctx.lineTo(cx + r * 0.6 * Math.cos(la), cy + r * 0.6 * Math.sin(la));
        ctx.stroke();
      }
    } else {
      var ti = outI();
      if (ti > 0.01) {
        var sorted = pols.slice().sort(function(a, b) { return a.x - b.x; });
        var fa = sorted[sorted.length - 1].angle;
        var osc = Math.sin(time * 3) * r * 0.75 * Math.sqrt(ti);
        var ca = Math.cos(fa), sa = Math.sin(fa);
        ctx.strokeStyle = 'rgba(0,245,212,' + (ti * 0.7).toFixed(3) + ')';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(cx - osc * ca, cy - osc * sa);
        ctx.lineTo(cx + osc * ca, cy + osc * sa);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(cx + osc * ca, cy + osc * sa, 2.5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,245,212,' + (ti * 0.7).toFixed(3) + ')';
        ctx.fill();
      }
    }

    ctx.fillStyle = 'rgba(0,245,212,0.25)';
    ctx.font = '9px "Space Grotesk",monospace';
    ctx.textAlign = 'center';
    ctx.fillText('E-FIELD', cx, cy + r + 14);
    ctx.textAlign = 'left';
  }

  function drawBar() {
    var ti = outI();
    var bw = 3, bh = H * 0.5, bx = W - 16, by = (H - bh) / 2;
    ctx.fillStyle = 'rgba(0,245,212,0.05)';
    ctx.fillRect(bx, by, bw, bh);
    var fh = bh * ti;
    ctx.fillStyle = 'rgba(0,245,212,0.35)';
    ctx.fillRect(bx, by + bh - fh, bw, fh);
    ctx.fillStyle = 'rgba(0,245,212,0.35)';
    ctx.font = '9px "Space Grotesk",monospace';
    ctx.textAlign = 'center';
    ctx.fillText(Math.round(ti * 100) + '%', bx + 1, by - 6);
    ctx.textAlign = 'left';
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var mx = e.clientX, my = e.clientY;
    if (e.button === 2) { var idx = nearPol(mx); if (idx >= 0) rmPol(idx); return; }
    var idx = nearPol(mx);
    if (idx >= 0) {
      dragIdx = idx; dragStartY = my; dragStartA = pols[idx].angle;
    } else {
      addPol(mx, Math.random() * Math.PI);
    }
  });
  C.addEventListener('mousemove', function(e) {
    if (dragIdx >= 0) {
      pols[dragIdx].angle = dragStartA + (e.clientY - dragStartY) * 0.008;
      updHud();
      SFX.play('move', 80);
    } else {
      hoverIdx = nearPol(e.clientX);
    }
  });
  C.addEventListener('mouseup', function() { dragIdx = -1; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var idx = nearPol(e.clientX);
    if (idx >= 0) {
      pols[idx].angle += e.deltaY * 0.002;
      updHud();
      SFX.play('tick', 120);
    }
  }, { passive: false });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length !== 1) return;
    var t = e.touches[0];
    touchId = t.identifier; touchMoved = false;
    var mx = t.clientX, my = t.clientY;
    var idx = nearPol(mx);
    if (idx >= 0) {
      dragIdx = idx; dragStartY = my; dragStartA = pols[idx].angle;
      longTimer = setTimeout(function() { if (!touchMoved && dragIdx >= 0) { rmPol(dragIdx); dragIdx = -1; } }, 500);
    } else {
      addPol(mx, Math.random() * Math.PI);
    }
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault(); touchMoved = true;
    if (longTimer) { clearTimeout(longTimer); longTimer = null; }
    for (var i = 0; i < e.changedTouches.length; i++) {
      var t = e.changedTouches[i];
      if (t.identifier === touchId && dragIdx >= 0) {
        pols[dragIdx].angle = dragStartA + (t.clientY - dragStartY) * 0.008;
        updHud();
        SFX.play('move', 80);
      }
    }
  }, { passive: false });
  C.addEventListener('touchend', function() {
    if (longTimer) { clearTimeout(longTimer); longTimer = null; }
    dragIdx = -1; touchId = null;
  });

  function loop() {
    time += 1 / 60;
    if (sweeping && pols.length) { pols[0].angle = (time * 0.5) % Math.PI; updHud(); }
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      p.x += p.vx; p.y += p.vy; p.ph += 0.07;
      if (p.x > W + 20 || p.y < -20 || p.y > H + 20) ps[i] = mkP();
    }
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    drawPolarizers();
    drawParticles();
    drawEndOn();
    drawBar();
    requestAnimationFrame(loop);
  }

  initPs();
  setPreset('single');
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
