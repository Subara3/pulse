<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('toothpick');

$pageName = 'toothpick';
$pageTitle = 'TOOTHPICK - PULSE';
$pageDesc = '爪楊枝数列。1本の爪楊枝から始まり、むき出しの端に直角の枝が生え続け、自己相似フラクタルが現れる。';
$pageCanonical = 'https://pulse.subara3.com/toothpick.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; }
.hud { top: 3.2rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.63rem; line-height: 1.8; text-align: right; letter-spacing: 0.05em; }
.hud .v { color: #00f5d4; font-weight: 700; }
@media (max-width: 768px) {
  .hud { top: 2.8rem; right: 0.65rem; font-size: 0.54rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  STEP: <span id="hudStep" class="v">0</span><br>
  TOTAL: <span id="hudTotal" class="v">1</span><br>
  ACTIVE: <span id="hudActive" class="v">2</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add seed'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="playBtn">PAUSE</button>
    <button class="control-btn" id="stepBtn">STEP</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="resetBtn">RESET</button>
    <button class="control-btn" id="fitBtn">FIT</button>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label>SPEED</label>
      <input type="range" id="speedSlider" min="1" max="20" step="1" value="3">
      <span class="slider-value" id="speedVal">3/s</span>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d', { alpha: false });
  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0;

  var tps = [];
  var occ = {};
  var act = {};
  var gen = 0, maxGen = 0;

  var camX = 0, camY = 0, scl = 20;
  var autoFit = true;

  var playing = true, speed = 3, accum = 0, lastT = 0, flashT = 0;

  var pDown = false, pMoved = false;
  var dsx = 0, dsy = 0, dcx = 0, dcy = 0;
  var pinchD = 0;

  var hudStep = document.getElementById('hudStep');
  var hudTotal = document.getElementById('hudTotal');
  var hudActive = document.getElementById('hudActive');
  var playBtn = document.getElementById('playBtn');
  var stepBtn = document.getElementById('stepBtn');
  var resetBtn = document.getElementById('resetBtn');
  var fitBtn = document.getElementById('fitBtn');
  var spdSlider = document.getElementById('speedSlider');
  var spdVal = document.getElementById('speedVal');

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    C.width = Math.floor(W * DPR);
    C.height = Math.floor(H * DPR);
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }

  function K(x, y) { return x + ',' + y; }
  function P(k) { var p = k.split(','); return [+p[0], +p[1]]; }

  function addTP(cx, cy, o, g) {
    tps.push({ cx: cx, cy: cy, o: o, g: g });
    occ[K(cx, cy)] = true;
  }

  function endpts(cx, cy, o) {
    return o === 'H'
      ? [[cx - 1, cy], [cx + 1, cy]]
      : [[cx, cy - 1], [cx, cy + 1]];
  }

  function perpO(o) { return o === 'H' ? 'V' : 'H'; }

  function doReset() {
    tps = []; occ = {}; act = {};
    gen = 0; maxGen = 0;
    addTP(0, 0, 'V', 0);
    act[K(0, -1)] = 'H';
    act[K(0, 1)] = 'H';
    updHUD();
  }

  function doStep() {
    var keys = Object.keys(act);
    if (keys.length === 0) return false;
    gen++; maxGen = gen;

    var ntp = [];
    for (var i = 0; i < keys.length; i++) {
      var p = P(keys[i]);
      ntp.push({ cx: p[0], cy: p[1], o: act[keys[i]] });
    }
    act = {};

    for (var i = 0; i < ntp.length; i++)
      addTP(ntp[i].cx, ntp[i].cy, ntp[i].o, gen);

    var ne = {};
    for (var i = 0; i < ntp.length; i++) {
      var co = perpO(ntp[i].o);
      var ep = endpts(ntp[i].cx, ntp[i].cy, ntp[i].o);
      for (var j = 0; j < 2; j++) {
        var kk = K(ep[j][0], ep[j][1]);
        if (occ[kk]) continue;
        ne[kk] = ne[kk] ? { c: ne[kk].c + 1, o: co } : { c: 1, o: co };
      }
    }

    var nk = Object.keys(ne);
    for (var i = 0; i < nk.length; i++)
      if (ne[nk[i]].c === 1) act[nk[i]] = ne[nk[i]].o;

    flashT = 1;
    updHUD();
    if (typeof SFX !== 'undefined') SFX.play('spawn', 100);
    return true;
  }

  function updHUD() {
    hudStep.textContent = gen;
    hudTotal.textContent = tps.length;
    hudActive.textContent = Object.keys(act).length;
  }

  function fitView() {
    var x0 = 1e9, x1 = -1e9, y0 = 1e9, y1 = -1e9;
    for (var i = 0; i < tps.length; i++) {
      var t = tps[i];
      var ep = endpts(t.cx, t.cy, t.o);
      for (var j = 0; j < 2; j++) {
        if (ep[j][0] < x0) x0 = ep[j][0];
        if (ep[j][0] > x1) x1 = ep[j][0];
        if (ep[j][1] < y0) y0 = ep[j][1];
        if (ep[j][1] > y1) y1 = ep[j][1];
      }
    }
    if (x0 > x1) { x0 = -2; x1 = 2; y0 = -2; y1 = 2; }
    var bw = x1 - x0 + 4, bh = y1 - y0 + 4;
    scl = Math.max(1, Math.min(60, Math.min(W / bw, H / bh)));
    camX = (x0 + x1) / 2;
    camY = (y0 + y1) / 2;
  }

  function w2s(wx, wy) {
    return [W / 2 + (wx - camX) * scl, H / 2 + (wy - camY) * scl];
  }

  function s2w(sx, sy) {
    return [(sx - W / 2) / scl + camX, (sy - H / 2) / scl + camY];
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (autoFit && tps.length > 0) fitView();

    var lw = Math.max(0.8, Math.min(4, scl * 0.1));
    ctx.lineCap = 'round';

    var i = 0;
    while (i < tps.length) {
      var gg = tps[i].g;
      var t = maxGen > 0 ? gg / maxGen : 0;
      var hue = 275 - t * 107;
      var sat = 65 + t * 25;
      var lit = 30 + t * 25;
      var alp = 0.5 + t * 0.5;
      var isNew = gg === maxGen;

      if (isNew && flashT > 0) {
        lit += flashT * 20;
        alp = Math.min(1, alp + flashT * 0.3);
      }

      ctx.strokeStyle = 'hsla(' + (hue | 0) + ',' + (sat | 0) + '%,' + (lit | 0) + '%,' + alp.toFixed(2) + ')';
      ctx.lineWidth = isNew ? lw * 1.5 : lw;

      if (isNew && flashT > 0.3) {
        ctx.shadowBlur = flashT * 8;
        ctx.shadowColor = 'hsla(168,100%,60%,0.5)';
      }

      ctx.beginPath();
      while (i < tps.length && tps[i].g === gg) {
        var tp = tps[i];
        var ep = endpts(tp.cx, tp.cy, tp.o);
        var s1 = w2s(ep[0][0], ep[0][1]);
        var s2 = w2s(ep[1][0], ep[1][1]);
        ctx.moveTo(s1[0], s1[1]);
        ctx.lineTo(s2[0], s2[1]);
        i++;
      }
      ctx.stroke();

      if (isNew) ctx.shadowBlur = 0;
    }

    var ak = Object.keys(act);
    if (ak.length > 0 && scl > 2) {
      var r = Math.max(1, scl * 0.1);
      ctx.fillStyle = 'rgba(0,245,212,0.45)';
      ctx.shadowBlur = 3;
      ctx.shadowColor = '#00f5d4';
      ctx.beginPath();
      for (var j = 0; j < ak.length; j++) {
        var p = P(ak[j]);
        var sp = w2s(p[0], p[1]);
        ctx.moveTo(sp[0] + r, sp[1]);
        ctx.arc(sp[0], sp[1], r, 0, Math.PI * 2);
      }
      ctx.fill();
      ctx.shadowBlur = 0;
    }
  }

  C.addEventListener('pointerdown', function(e) {
    if (!e.isPrimary || e.button === 2) return;
    var r = C.getBoundingClientRect();
    dsx = e.clientX - r.left; dsy = e.clientY - r.top;
    dcx = camX; dcy = camY;
    pDown = true; pMoved = false;
    C.setPointerCapture(e.pointerId);
  });

  C.addEventListener('pointermove', function(e) {
    if (!e.isPrimary || !pDown) return;
    var r = C.getBoundingClientRect();
    var mx = e.clientX - r.left, my = e.clientY - r.top;
    var dx = mx - dsx, dy = my - dsy;
    if (dx * dx + dy * dy > 16) pMoved = true;
    if (pMoved) {
      autoFit = false;
      camX = dcx - dx / scl;
      camY = dcy - dy / scl;
    }
  });

  C.addEventListener('pointerup', function(ev) {
    if (!ev.isPrimary || !pDown) return;
    pDown = false;
    if (!pMoved) {
      var r = C.getBoundingClientRect();
      var w = s2w(ev.clientX - r.left, ev.clientY - r.top);
      var cx = Math.round(w[0]), cy = Math.round(w[1]);
      if (!occ[K(cx, cy)]) {
        addTP(cx, cy, 'V', gen);
        var ep = endpts(cx, cy, 'V');
        for (var j = 0; j < 2; j++) {
          var ek = K(ep[j][0], ep[j][1]);
          if (!occ[ek]) {
            if (act[ek]) delete act[ek];
            else act[ek] = 'H';
          }
        }
        updHUD();
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    }
  });

  C.addEventListener('pointercancel', function(e) {
    if (e.isPrimary) pDown = false;
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    autoFit = false;
    var r = C.getBoundingClientRect();
    var mx = e.clientX - r.left, my = e.clientY - r.top;
    var wb = s2w(mx, my);
    scl *= e.deltaY < 0 ? 1.15 : 1 / 1.15;
    scl = Math.max(0.5, Math.min(120, scl));
    var wa = s2w(mx, my);
    camX -= wa[0] - wb[0];
    camY -= wa[1] - wb[1];
  }, { passive: false });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 2) {
      var dx = e.touches[0].clientX - e.touches[1].clientX;
      var dy = e.touches[0].clientY - e.touches[1].clientY;
      pinchD = Math.sqrt(dx * dx + dy * dy);
    }
  }, { passive: true });

  C.addEventListener('touchmove', function(e) {
    if (e.touches.length === 2) {
      e.preventDefault();
      var dx = e.touches[0].clientX - e.touches[1].clientX;
      var dy = e.touches[0].clientY - e.touches[1].clientY;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (pinchD > 0) {
        autoFit = false;
        scl = Math.max(0.5, Math.min(120, scl * (d / pinchD)));
      }
      pinchD = d;
    }
  }, { passive: false });

  C.addEventListener('touchend', function() { pinchD = 0; }, { passive: true });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  playBtn.addEventListener('click', function() {
    playing = !playing;
    this.textContent = playing ? 'PAUSE' : 'PLAY';
    this.classList.toggle('active', playing);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  stepBtn.addEventListener('click', function() {
    doStep();
  });

  resetBtn.addEventListener('click', function() {
    doReset(); autoFit = true;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  fitBtn.addEventListener('click', function() {
    autoFit = true; fitView();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  spdSlider.addEventListener('input', function() {
    speed = parseInt(this.value, 10);
    spdVal.textContent = speed + '/s';
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });

  window.addEventListener('resize', resize);

  function loop(ts) {
    if (!ts) ts = performance.now();
    var dt = Math.min(200, ts - lastT);
    lastT = ts;

    if (flashT > 0) flashT = Math.max(0, flashT - dt / 300);

    if (playing && Object.keys(act).length > 0) {
      accum += dt;
      var interval = 1000 / speed;
      var steps = 0;
      while (accum >= interval && steps < 5) {
        doStep();
        accum -= interval;
        steps++;
      }
      if (accum > interval * 2) accum = 0;
    }

    draw();
    requestAnimationFrame(loop);
  }

  resize();
  doReset();
  for (var i = 0; i < 3; i++) doStep();
  flashT = 0;
  fitView();
  lastT = performance.now();
  requestAnimationFrame(loop);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
