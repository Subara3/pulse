<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('swarmalator');

$pageName = 'swarmalator';
$pageTitle = 'SWARMALATOR - PULSE';
$pageDesc = '短い線の粒が、向きの近いものどうしで集まって輪になる。輪を一周すると線の向きも一周していて、全体の向きがゆっくり回り続ける。触るとそこへ粒が引き寄せられ、離すと輪に戻る。';
$pageCanonical = 'https://pulse.subara3.com/swarmalator.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-spin">SPIN</label><input type="range" id="k-spin" min="0" max="100" value="35"></div>
  <div class="ctl-btns"><button type="button" class="active" data-mode="ring">RING</button><button type="button" data-mode="split">SPLIT</button><button type="button" data-mode="drift">DRIFT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W = 0, H = 0;

  /* 粒は位置と向き（位相）を持つ。向きが近いほど強く引き合い、近いほど向きを揃え（または離し）合う */
  var N = 480;
  var x = new Float32Array(N), y = new Float32Array(N), th = new Float32Array(N), om = new Float32Array(N);
  var dx = new Float32Array(N), dy = new Float32Array(N), dth = new Float32Array(N);
  var cs = new Float32Array(N), sn = new Float32Array(N);
  var MODES = { ring: [1, 0], split: [1, -0.1], drift: [1, -0.75] };
  var J = 1, Kc = 0, tJ = 1, tK = 0, spin = 0.3;

  for (var i = 0; i < N; i++) {
    var a = Math.random() * Math.PI * 2, r = Math.sqrt(Math.random()) * 1.6;
    x[i] = Math.cos(a) * r; y[i] = Math.sin(a) * r;
    th[i] = Math.random() * Math.PI * 2;
    om[i] = 0;
  }

  /* 指: 模型の座標での位置と強さ */
  var probe = { x: 0, y: 0, on: 0, sx: 0, sy: 0 };

  function step(h) {
    var i, j;
    for (i = 0; i < N; i++) { cs[i] = Math.cos(th[i]); sn[i] = Math.sin(th[i]); dx[i] = 0; dy[i] = 0; dth[i] = 0; }
    var invN = 1 / N;
    for (i = 0; i < N; i++) {
      var xi = x[i], yi = y[i], ci = cs[i], si = sn[i];
      var ax = 0, ay = 0, at = 0;
      for (j = i + 1; j < N; j++) {
        var rx = x[j] - xi, ry = y[j] - yi;
        var d2 = rx * rx + ry * ry + 1e-4, d = Math.sqrt(d2);
        var cosd = cs[j] * ci + sn[j] * si;      /* cos(θj − θi) */
        var sind = sn[j] * ci - cs[j] * si;      /* sin(θj − θi) */
        var f = (1 + J * cosd) / d - 1 / d2;     /* 引き合い − 押し合い */
        var fx = rx * f, fy = ry * f;
        ax += fx; ay += fy; dx[j] -= fx; dy[j] -= fy;
        var g = sind / d;
        at += g; dth[j] -= g;
      }
      dx[i] += ax; dy[i] += ay; dth[i] += at;
    }
    var pr = probe.on;
    for (i = 0; i < N; i++) {
      var vx = dx[i] * invN, vy = dy[i] * invN;
      if (pr > 0) {
        var qx = probe.x - x[i], qy = probe.y - y[i], qd = Math.sqrt(qx * qx + qy * qy) + 0.02;
        if (qd < 0.9) { var k = (1 - qd / 0.9) * 1.6 * pr; vx += qx / qd * k; vy += qy / qd * k; }
      }
      x[i] += vx * h; y[i] += vy * h;
      th[i] += (spin + om[i] + Kc * dth[i] * invN) * h;
    }
  }

  /* 見える範囲に合わせて、中心と縮尺をゆっくり追う */
  var cx = 0, cy = 0, scale = 0, T = 0;
  function frameView(dt) {
    var mx = 0, my = 0, i;
    for (i = 0; i < N; i++) { mx += x[i]; my += y[i]; }
    mx /= N; my /= N;
    var rr = [];
    for (i = 0; i < N; i += 3) rr.push(Math.hypot(x[i] - mx, y[i] - my));
    rr.sort(function (a, b) { return a - b; });
    var ext = rr[Math.floor(rr.length * 0.96)] || 1;
    var want = Math.min(W, H) * 0.36 / Math.max(0.3, ext);
    var k = scale ? Math.min(1, dt * 0.6) : 1;
    cx += (mx - cx) * k; cy += (my - cy) * k; scale += (want - scale) * k;
  }

  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 36, major: 4, alpha: 0.6 });
    var ox = W / 2, oy = H / 2, L = Math.max(4, Math.min(8, scale * 0.045));
    /* 明るさは「向き」で決める。基準の向きはゆっくり回るので、明るい帯が輪をめぐって見える */
    var ref = T * 0.15;
    var BUCK = 6, b, i;
    ctx.lineCap = 'round';
    for (b = 0; b < BUCK; b++) {
      var u = (b + 0.5) / BUCK;
      ctx.strokeStyle = P.rgba(P.ramp(0.5 + u * 0.5), 0.4 + u * 0.6);
      ctx.lineWidth = 1 + u * 0.6;
      ctx.beginPath();
      for (i = 0; i < N; i++) {
        var lv = (Math.cos(th[i] - ref) + 1) * 0.5;
        if (Math.min(BUCK - 1, (lv * BUCK) | 0) !== b) continue;
        var sx = ox + (x[i] - cx) * scale, sy = oy + (y[i] - cy) * scale;
        /* 針: 根元から向きの方へだけ伸ばす（向きが一周すると針も一周して見える） */
        var c = Math.cos(th[i]) * L, s = Math.sin(th[i]) * L;
        ctx.moveTo(sx - c * 0.35, sy - s * 0.35); ctx.lineTo(sx + c * 1.3, sy + s * 1.3);
      }
      ctx.stroke();
    }
    if (probe.on > 0.02) {
      ctx.fillStyle = P.rgba(P.accent, 0.8 * probe.on);
      ctx.beginPath(); ctx.arc(probe.sx, probe.sy, 2.5, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = P.rgba(P.accent, 0.35 * probe.on);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(probe.sx, probe.sy, 0.9 * scale, 0, Math.PI * 2); ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false;
  function setProbe(e) {
    probe.sx = e.clientX; probe.sy = e.clientY;
    probe.x = cx + (e.clientX - W / 2) / scale;
    probe.y = cy + (e.clientY - H / 2) / scale;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; setProbe(e); probe.on = 1;
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    setProbe(e);
    SFX.play('move', 200);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function spinFrom(v) { return v / 100 * 0.9; }
  document.getElementById('k-spin').addEventListener('input', function () { spin = spinFrom(+this.value); });
  document.querySelectorAll('[data-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-mode]').forEach(function (o) { o.classList.toggle('active', o === b); });
      var m = MODES[b.dataset.mode]; tJ = m[0]; tK = m[1];
      SFX.play('toggle');
    });
  });
  spin = spinFrom(+document.getElementById('k-spin').value);
  (function () {
    var act = document.querySelector('[data-mode].active');
    if (act) { var m = MODES[act.dataset.mode]; J = tJ = m[0]; Kc = tK = m[1]; }
  })();

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; });
  /* 最初から輪の形が見えるよう、少し先まで進めておく */
  for (var w0 = 0; w0 < 500; w0++) step(0.06);
  frameView(1);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now; T += dt;
    /* モードを替えたときは、結合の強さを数秒かけて移す */
    J += (tJ - J) * Math.min(1, dt * 0.8);
    Kc += (tK - Kc) * Math.min(1, dt * 0.8);
    if (!down) probe.on = Math.max(0, probe.on - dt * 1.5);
    step(dt * 1.2); step(dt * 1.2); step(dt * 1.2);
    frameView(dt);
    draw();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
