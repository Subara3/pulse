<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('meander');

$pageName = 'meander';
$pageTitle = 'MEANDER - PULSE';
$pageDesc = 'まっすぐな川に少し曲がりを足すと、曲がりが自分で大きくなり、やがて首がちぎれて三日月湖になる。';
$pageCanonical = 'https://pulse.subara3.com/meander.php';

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
  CUTOFFS <b id="h-cut">0</b><br>
  SINUOSITY <b id="h-sin">1.00</b><br>
  <span class="sub">POINTS</span> <b id="h-pts">0</b>
</div>

<div id="info">
  curvature-driven bank erosion<br>
  <span class="v">positive feedback → oxbow cutoff</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'bend'],
  ['type' => 'drag', 'label' => 'perturb'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sldSpeed" min="0" max="300" value="100">
      <span class="slider-value" id="valSpeed">1.0</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">NOISE</span>
      <input type="range" id="sldNoise" min="0" max="100" value="30">
      <span class="slider-value" id="valNoise">0.30</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;
  var TAU = Math.PI * 2;

  var trCvs = document.createElement('canvas');
  var trCtx = trCvs.getContext('2d');

  var pts = [];
  var oxbows = [];
  var particles = [];
  var flashes = [];
  var landmarks = [];
  var cutN = 0, age = 0;
  var speed = 1.0, noiseK = 0.3;

  var SP = 4, MIG = 150, SMO = 0.12, MAX_R = 2.0;
  var CUT_D = 18, CUT_A = 55, DLAG = 5;
  var NP = 35, RW = 5;

  var mx = -1, my = -1, mdown = false;
  var lastSfx = 0;

  var hCut, hSin, hPts;

  function resize() {
    dpr = Math.min(devicePixelRatio || 1, 2);
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    trCvs.width = W; trCvs.height = H;
    if (!pts.length) init();
  }

  function init() {
    pts = []; oxbows = []; particles = []; flashes = [];
    cutN = 0; age = 0;
    trCtx.clearRect(0, 0, W, H);

    var mg = 25;
    var wide = W >= H;
    var len = (wide ? W : H) - 2 * mg;
    var n = Math.floor(len / SP);
    var amp = (wide ? H : W) * 0.1;

    for (var i = 0; i <= n; i++) {
      var t = i / n;
      var main = mg + t * len;
      var off = amp * Math.sin(t * TAU * 1.35)
              + amp * 0.4 * Math.sin(t * TAU * 2.65 + 0.7)
              + amp * 0.15 * Math.sin(t * TAU * 4.5 + 2.1);
      if (wide) pts.push({x: main, y: H * 0.5 + off});
      else pts.push({x: W * 0.5 + off, y: main});
    }

    for (var i = 0; i < NP; i++) {
      particles.push({
        pos: Math.random() * (pts.length - 1),
        spd: 0.3 + Math.random() * 0.6,
        sz: 0.8 + Math.random() * 1.2
      });
    }

    landmarks = [];
    for (var i = 0; i < 60; i++) {
      landmarks.push({
        x: Math.random() * W,
        y: Math.random() * H,
        sz: 0.3 + Math.random() * 0.7
      });
    }

    hud();
  }

  function kappa(i) {
    if (i < 1 || i >= pts.length - 1) return 0;
    var ax = pts[i].x - pts[i-1].x, ay = pts[i].y - pts[i-1].y;
    var bx = pts[i+1].x - pts[i].x, by = pts[i+1].y - pts[i].y;
    var cross = ax * by - ay * bx;
    var dot = ax * bx + ay * by;
    var ang = Math.atan2(cross, dot);
    var ds = (Math.sqrt(ax*ax + ay*ay) + Math.sqrt(bx*bx + by*by)) * 0.5;
    return ds > 0.01 ? ang / ds : 0;
  }

  function evolve() {
    var n = pts.length;
    if (n < 8) { init(); return; }

    var K = new Array(n);
    for (var i = 0; i < n; i++) K[i] = kappa(i);

    var np = new Array(n);
    for (var i = 0; i < n; i++) np[i] = {x: pts[i].x, y: pts[i].y};

    var pin = 5;
    for (var i = pin; i < n - pin; i++) {
      var li = Math.max(1, i - DLAG);
      var k = K[li] * 0.55 + K[i] * 0.45;

      var i0 = i - 1, i1 = i + 1;
      var tx = pts[i1].x - pts[i0].x, ty = pts[i1].y - pts[i0].y;
      var tl = Math.sqrt(tx*tx + ty*ty);
      if (tl < 0.01) continue;
      var nx = -ty / tl, ny = tx / tl;

      var taper = Math.sin(Math.PI * (i - pin) / (n - 2 * pin));
      var rate = MIG * speed * k * taper;
      if (rate > MAX_R) rate = MAX_R;
      if (rate < -MAX_R) rate = -MAX_R;

      np[i].x += nx * rate + (Math.random() - 0.5) * noiseK * 0.06 * speed;
      np[i].y += ny * rate + (Math.random() - 0.5) * noiseK * 0.06 * speed;

      var mg = Math.min(W, H) * 0.1;
      if (np[i].x < mg) np[i].x += (mg - np[i].x) * 0.04;
      if (np[i].x > W - mg) np[i].x += (W - mg - np[i].x) * 0.04;
      if (np[i].y < mg) np[i].y += (mg - np[i].y) * 0.04;
      if (np[i].y > H - mg) np[i].y += (H - mg - np[i].y) * 0.04;
    }

    for (var i = pin; i < n - pin; i++) {
      var sx = np[i].x * (1 - SMO) + (np[i-1].x + np[i+1].x) * 0.5 * SMO;
      var sy = np[i].y * (1 - SMO) + (np[i-1].y + np[i+1].y) * 0.5 * SMO;
      np[i] = {x: sx, y: sy};
    }

    pts = np;
    age++;
  }

  function checkCut() {
    var n = pts.length;
    if (n < CUT_A + 10) return;
    var dSq = CUT_D * CUT_D;
    var bi = -1, bj = -1, bd = dSq;

    for (var i = 0; i < n - CUT_A; i++) {
      var px = pts[i].x, py = pts[i].y;
      for (var j = i + CUT_A; j < n; j++) {
        var dx = px - pts[j].x, dy = py - pts[j].y;
        var d2 = dx*dx + dy*dy;
        if (d2 < bd) { bd = d2; bi = i; bj = j; }
      }
    }

    if (bi >= 0) {
      var loop = pts.slice(bi, bj + 1);
      var cx = (pts[bi].x + pts[bj].x) * 0.5;
      var cy = (pts[bi].y + pts[bj].y) * 0.5;
      oxbows.push({pts: loop, alpha: 0.9});
      flashes.push({x: cx, y: cy, r: 20, alpha: 1});

      var before = pts.slice(0, bi + 1);
      var after = pts.slice(bj);
      before[before.length - 1] = {x: cx, y: cy};
      after[0] = {x: cx, y: cy};
      pts = before.concat(after);

      cutN++;
      SFX.play('pop');
      hud();
    }
  }

  function resample() {
    if (pts.length < 4) return;
    var arc = [0];
    for (var i = 1; i < pts.length; i++) {
      var dx = pts[i].x - pts[i-1].x, dy = pts[i].y - pts[i-1].y;
      arc[i] = arc[i-1] + Math.sqrt(dx*dx + dy*dy);
    }
    var total = arc[arc.length - 1];
    if (total < SP * 8) return;

    var nn = Math.max(12, Math.round(total / SP));
    var np = [{x: pts[0].x, y: pts[0].y}];
    var j = 1;
    for (var i = 1; i < nn; i++) {
      var target = (i / nn) * total;
      while (j < pts.length - 1 && arc[j] < target) j++;
      var sl = arc[j] - arc[j-1];
      var t = sl > 0.001 ? (target - arc[j-1]) / sl : 0;
      np.push({
        x: pts[j-1].x + (pts[j].x - pts[j-1].x) * t,
        y: pts[j-1].y + (pts[j].y - pts[j-1].y) * t
      });
    }
    np.push({x: pts[pts.length-1].x, y: pts[pts.length-1].y});
    pts = np;
  }

  function sinuosity() {
    if (pts.length < 2) return 1;
    var arc = 0;
    for (var i = 1; i < pts.length; i++) {
      var dx = pts[i].x - pts[i-1].x, dy = pts[i].y - pts[i-1].y;
      arc += Math.sqrt(dx*dx + dy*dy);
    }
    var dx = pts[pts.length-1].x - pts[0].x;
    var dy = pts[pts.length-1].y - pts[0].y;
    var st = Math.sqrt(dx*dx + dy*dy);
    return st > 1 ? arc / st : 1;
  }

  function updParticles() {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      p.pos += p.spd * speed * 0.5;
      if (p.pos >= pts.length - 1) p.pos -= pts.length - 1;
      if (p.pos < 0) p.pos = 0;
    }
  }

  function drawRiver(points, width, style) {
    if (points.length < 2) return;
    ctx.strokeStyle = style;
    ctx.lineWidth = width;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (var i = 1; i < points.length; i++) ctx.lineTo(points[i].x, points[i].y);
    ctx.stroke();
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Landmarks
    ctx.fillStyle = 'rgba(255,255,255,0.04)';
    for (var i = 0; i < landmarks.length; i++) {
      var lm = landmarks[i];
      ctx.beginPath();
      ctx.arc(lm.x, lm.y, lm.sz, 0, TAU);
      ctx.fill();
    }

    // Trail (floodplain)
    trCtx.fillStyle = 'rgba(10,10,15,0.004)';
    trCtx.fillRect(0, 0, W, H);
    trCtx.globalAlpha = 0.012;
    trCtx.strokeStyle = '#00f5d4';
    trCtx.lineWidth = RW * 3;
    trCtx.lineCap = 'round';
    trCtx.lineJoin = 'round';
    if (pts.length > 1) {
      trCtx.beginPath();
      trCtx.moveTo(pts[0].x, pts[0].y);
      for (var i = 1; i < pts.length; i++) trCtx.lineTo(pts[i].x, pts[i].y);
      trCtx.stroke();
    }
    trCtx.globalAlpha = 1;

    ctx.globalAlpha = 0.5;
    ctx.drawImage(trCvs, 0, 0);
    ctx.globalAlpha = 1;

    // Oxbow lakes
    for (var i = oxbows.length - 1; i >= 0; i--) {
      var ob = oxbows[i];
      ob.alpha *= 0.9998;
      if (ob.alpha < 0.02) { oxbows.splice(i, 1); continue; }
      if (ob.pts.length < 3) continue;

      ctx.globalAlpha = ob.alpha * 0.15;
      ctx.fillStyle = '#7b2cbf';
      ctx.beginPath();
      ctx.moveTo(ob.pts[0].x, ob.pts[0].y);
      for (var j = 1; j < ob.pts.length; j++) ctx.lineTo(ob.pts[j].x, ob.pts[j].y);
      ctx.closePath();
      ctx.fill();

      ctx.globalAlpha = ob.alpha * 0.6;
      drawRiver(ob.pts, RW * 1.2, '#7b2cbf');
    }
    ctx.globalAlpha = 1;

    // River: glow → body → core
    drawRiver(pts, RW * 5, 'rgba(0,245,212,0.05)');
    drawRiver(pts, RW * 2.5, 'rgba(0,245,212,0.3)');
    drawRiver(pts, RW * 0.8, 'rgba(0,245,212,0.85)');

    // Flow particles
    ctx.fillStyle = 'rgba(255,255,255,0.45)';
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var idx = ~~p.pos;
      var frac = p.pos - idx;
      if (idx < 0 || idx >= pts.length - 1) continue;
      var px = pts[idx].x + (pts[idx+1].x - pts[idx].x) * frac;
      var py = pts[idx].y + (pts[idx+1].y - pts[idx].y) * frac;
      ctx.beginPath();
      ctx.arc(px, py, p.sz, 0, TAU);
      ctx.fill();
    }

    // Cutoff flashes
    for (var i = flashes.length - 1; i >= 0; i--) {
      var f = flashes[i];
      f.alpha -= 0.015;
      f.r += 1.5;
      if (f.alpha <= 0) { flashes.splice(i, 1); continue; }
      ctx.beginPath();
      ctx.arc(f.x, f.y, f.r, 0, TAU);
      ctx.strokeStyle = 'rgba(0,245,212,' + (f.alpha * 0.6) + ')';
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(f.x, f.y, f.r * 0.6, 0, TAU);
      ctx.strokeStyle = 'rgba(123,44,191,' + (f.alpha * 0.4) + ')';
      ctx.stroke();
    }
  }

  function hud() {
    if (!hCut) {
      hCut = document.getElementById('h-cut');
      hSin = document.getElementById('h-sin');
      hPts = document.getElementById('h-pts');
    }
    if (hCut) hCut.textContent = cutN;
    if (hSin) hSin.textContent = sinuosity().toFixed(2);
    if (hPts) hPts.textContent = pts.length;
  }

  function perturb(x, y, str) {
    var rad = 60;
    for (var i = 5; i < pts.length - 5; i++) {
      var dx = pts[i].x - x, dy = pts[i].y - y;
      var d2 = dx*dx + dy*dy;
      if (d2 > rad * rad) continue;
      var d = Math.sqrt(d2);
      if (d < 0.1) continue;
      var i0 = i - 1, i1 = i + 1;
      var tx = pts[i1].x - pts[i0].x, ty = pts[i1].y - pts[i0].y;
      var tl = Math.sqrt(tx*tx + ty*ty);
      if (tl < 0.01) continue;
      var nx = -ty / tl, ny = tx / tl;
      var proj = (x - pts[i].x) * nx + (y - pts[i].y) * ny;
      var sign = proj > 0 ? 1 : -1;
      var fac = (1 - d / rad) * str;
      pts[i].x += nx * sign * fac;
      pts[i].y += ny * sign * fac;
    }
  }

  function getPos(e) {
    var t = e.touches ? e.touches[0] : e;
    var r = C.getBoundingClientRect();
    return {x: t.clientX - r.left, y: t.clientY - r.top};
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    mdown = true;
    var p = getPos(e);
    perturb(p.x, p.y, 5);
    SFX.play('ripple');
  });
  C.addEventListener('mousemove', function(e) {
    if (!mdown) return;
    var p = getPos(e);
    perturb(p.x, p.y, 1.5);
    var now = Date.now();
    if (now - lastSfx > 120) { SFX.play('move', 50); lastSfx = now; }
  });
  C.addEventListener('mouseup', function() { mdown = false; });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    init();
    SFX.play('click');
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    mdown = true;
    var p = getPos(e);
    perturb(p.x, p.y, 5);
    SFX.play('ripple');
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!mdown) return;
    var p = getPos(e);
    perturb(p.x, p.y, 1.5);
    var now = Date.now();
    if (now - lastSfx > 120) { SFX.play('move', 50); lastSfx = now; }
  }, {passive: false});
  C.addEventListener('touchend', function() { mdown = false; });

  var sS = document.getElementById('sldSpeed');
  var sN = document.getElementById('sldNoise');
  var bR = document.getElementById('btnReset');

  if (sS) sS.addEventListener('input', function() {
    speed = parseFloat(this.value) / 100;
    document.getElementById('valSpeed').textContent = speed.toFixed(1);
  });
  if (sN) sN.addEventListener('input', function() {
    noiseK = parseFloat(this.value) / 100;
    document.getElementById('valNoise').textContent = noiseK.toFixed(2);
  });
  if (bR) bR.addEventListener('click', function() {
    init();
    SFX.play('click');
  });

  var frame = 0;
  function loop() {
    frame++;
    if (frame % 2 === 0) evolve();
    if (frame % 8 === 0) checkCut();
    if (frame % 6 === 0) resample();
    updParticles();
    draw();
    if (frame % 30 === 0) hud();
    requestAnimationFrame(loop);
  }

  addEventListener('resize', resize);
  resize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
