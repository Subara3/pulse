<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('particlelife');

$pageName = 'particlelife';
$pageTitle = 'PARTICLE LIFE - PULSE';
$pageDesc = '円い皿の中で4種類の粒が、相手の種類によって寄ったり離れたりして、いくつかの塊にまとまる。しばらくすると寄り方の決まりが替わり、塊が一度ほどけて組み直される。触ったところの粒はかき混ぜられる。';
$pageCanonical = 'https://pulse.subara3.com/particlelife.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-calm">CALM</label><input type="range" id="k-calm" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-reach">REACH</label><input type="range" id="k-reach" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W = 0, H = 0;

  /* 種類は色ではなく記号で見分ける: 点・輪・十字・短い線 */
  var K = 4, MAXN = 1400, N = 0;
  var px = new Float32Array(MAXN), py = new Float32Array(MAXN);
  var vx = new Float32Array(MAXN), vy = new Float32Array(MAXN);
  var kind = new Uint8Array(MAXN);
  var rule = new Float32Array(K * K), target = new Float32Array(K * K), from = new Float32Array(K * K);
  var morph = 1, morphWait = 40;
  var RMAX = 80, BETA = 0.3, FORCE = 9, friction = 0.04;
  var GW = 1, GH = 1, CW = 1, CH = 1, head = new Int32Array(1), nextI = new Int32Array(MAXN);

  /* でたらめな表だと粉が散るだけになりやすいので、塊が育って動き回る表を先に選んでおき、
     そこから少しだけ揺らして使う（3 つを順に巡る） */
  var PRESETS = [
    [0.04, 0.26, 0.01, 0.44, -0.3, 0.29, -0.95, -0.8, -0.04, -0.46, 0.28, -0.19, 0.93, -0.4, -0.5, 0.11],
    [0.35, 0.64, -0.17, -0.11, 0.04, 0.18, 0.66, 0.15, 0.63, 0.18, -0.77, -0.88, -0.17, -0.69, -0.1, 0.69],
    [0.59, 0.92, -0.49, -0.13, -0.99, -0.1, 0.03, 0.79, 0.58, 0.02, 0.45, 0.84, -0.36, 0.34, 0.16, -0.07]
  ];




  var presetAt = Math.floor(Math.random() * PRESETS.length);
  function randomRule(out) {
    var src = PRESETS[presetAt % PRESETS.length];
    presetAt++;
    for (var i = 0; i < K * K; i++) out[i] = Math.max(-1, Math.min(1, src[i] + (Math.random() - 0.5) * 0.12));
  }
  function seed() {
    DX = W / 2; DY = H / 2; DR = Math.min(W, H) * 0.41;
    N = Math.round(Math.min(MAXN, Math.PI * DR * DR / 300));
    for (var i = 0; i < N; i++) {
      var a0 = Math.random() * 6.2832, r0 = Math.sqrt(Math.random()) * DR * 0.95;
      px[i] = DX + Math.cos(a0) * r0; py[i] = DY + Math.sin(a0) * r0;
      vx[i] = 0; vy[i] = 0; kind[i] = i % K;
    }
  }
  function grid() {
    GW = Math.max(1, Math.floor(W / RMAX)); GH = Math.max(1, Math.floor(H / RMAX));
    CW = W / GW; CH = H / GH;
    head = new Int32Array(GW * GH);
  }

  function force(r, a) {
    if (r < BETA) return r / BETA - 1;
    if (r < 1) return a * (1 - Math.abs(2 * r - 1 - BETA) / (1 - BETA));
    return 0;
  }

  var stir = { x: 0, y: 0, on: 0 };

  function step(dt) {
    var i, j, k;
    head.fill(-1);
    for (i = 0; i < N; i++) {
      var gx = Math.min(GW - 1, (px[i] / CW) | 0), gy = Math.min(GH - 1, (py[i] / CH) | 0);
      k = gy * GW + gx; nextI[i] = head[k]; head[k] = i;
    }
    var fr = Math.pow(0.5, dt / friction);
    var R2 = RMAX * RMAX;
    for (i = 0; i < N; i++) {
      var x = px[i], y = py[i], ki = kind[i] * K;
      var cx = Math.min(GW - 1, (x / CW) | 0), cy = Math.min(GH - 1, (y / CH) | 0);
      var ax = 0, ay = 0;
      for (var oy = -1; oy <= 1; oy++) {
        var yy = cy + oy; if (yy < 0 || yy >= GH) continue;
        for (var ox = -1; ox <= 1; ox++) {
          var xx = cx + ox; if (xx < 0 || xx >= GW) continue;
          for (j = head[yy * GW + xx]; j >= 0; j = nextI[j]) {
            if (j === i) continue;
            var dx = px[j] - x, dy = py[j] - y;
            var d2 = dx * dx + dy * dy;
            if (d2 > R2 || d2 < 1e-6) continue;
            var d = Math.sqrt(d2);
            var f = force(d / RMAX, rule[ki + kind[j]]);
            ax += dx / d * f; ay += dy / d * f;
          }
        }
      }
      if (stir.on > 0) {
        var sx = x - stir.x, sy = y - stir.y, sd = Math.sqrt(sx * sx + sy * sy) + 1;
        if (sd < 120) {
          var s = (1 - sd / 120) * 6 * stir.on;
          /* 外へ押しながら少し回す */
          ax += (sx / sd * 0.6 - sy / sd) * s; ay += (sy / sd * 0.6 + sx / sd) * s;
        }
      }
      vx[i] = vx[i] * fr + ax * RMAX * FORCE * dt;
      vy[i] = vy[i] * fr + ay * RMAX * FORCE * dt;
    }
    for (i = 0; i < N; i++) {
      px[i] += vx[i] * dt; py[i] += vy[i] * dt;
      /* シャーレの縁: 外へ出かけたら内へ戻し、外向きの速さを弱める */
      var ex = px[i] - DX, ey = py[i] - DY, ed = Math.sqrt(ex * ex + ey * ey);
      if (ed > DR - 24) {
        var push = (ed - DR + 24) / 24 * 520 * dt;
        vx[i] -= ex / ed * push; vy[i] -= ey / ed * push;
      }
      if (ed > DR) {
        var ux = ex / ed, uy = ey / ed, vr = vx[i] * ux + vy[i] * uy;
        px[i] = DX + ux * DR; py[i] = DY + uy * DR;
        if (vr > 0) { vx[i] -= ux * vr * 1.5; vy[i] -= uy * vr * 1.5; }
      }
    }
  }

  /* 顕微鏡で少し拡大して見ている。模型の世界は画面の 1/Z の大きさ */
  var Z = 1.2, SW = 0, SH = 0, DX = 0, DY = 0, DR = 1;
  var LINE = P.line, STEEL = P.steel;
  function draw() {
    P.fill(ctx, SW, SH);
    P.grid(ctx, SW, SH, { step: 40, major: 5, alpha: 0.55 });
    var i, X, Y;
    /* シャーレ: 縁は 2 本の細い線と、外側の目盛り */
    var CX = DX * Z, CY = DY * Z, CR = DR * Z;
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(LINE, 0.32);
    ctx.beginPath(); ctx.arc(CX, CY, CR + 6, 0, 6.2832); ctx.stroke();
    ctx.strokeStyle = P.rgba(LINE, 0.12);
    ctx.beginPath(); ctx.arc(CX, CY, CR + 11, 0, 6.2832); ctx.stroke();
    ctx.strokeStyle = P.rgba(STEEL, 0.4);
    ctx.beginPath();
    for (var t = 0; t < 72; t++) {
      var ta = t / 72 * 6.2832, tl = t % 6 === 0 ? 9 : 4;
      ctx.moveTo(CX + Math.cos(ta) * (CR + 14), CY + Math.sin(ta) * (CR + 14));
      ctx.lineTo(CX + Math.cos(ta) * (CR + 14 + tl), CY + Math.sin(ta) * (CR + 14 + tl));
    }
    ctx.stroke();
    /* 0: 白い点 */
    ctx.fillStyle = P.rgba(LINE, 0.92);
    ctx.beginPath();
    for (i = 0; i < N; i++) if (kind[i] === 0) { X = px[i] * Z; Y = py[i] * Z; ctx.moveTo(X + 1.8, Y); ctx.arc(X, Y, 1.8, 0, 6.2832); }
    ctx.fill();
    /* 1: 鋼色の輪 */
    ctx.strokeStyle = P.rgba(P.mix(STEEL, LINE, 0.55), 0.85);
    ctx.lineWidth = 0.9;
    ctx.beginPath();
    for (i = 0; i < N; i++) if (kind[i] === 1) { X = px[i] * Z; Y = py[i] * Z; ctx.moveTo(X + 2.8, Y); ctx.arc(X, Y, 2.8, 0, 6.2832); }
    ctx.stroke();
    /* 2: 十字 */
    ctx.strokeStyle = P.rgba(LINE, 0.72);
    ctx.lineWidth = 0.9;
    ctx.beginPath();
    for (i = 0; i < N; i++) if (kind[i] === 2) {
      X = px[i] * Z; Y = py[i] * Z;
      ctx.moveTo(X - 2.6, Y); ctx.lineTo(X + 2.6, Y);
      ctx.moveTo(X, Y - 2.6); ctx.lineTo(X, Y + 2.6);
    }
    ctx.stroke();
    /* 3: 小さな四角 */
    ctx.strokeStyle = P.rgba(P.mix(STEEL, LINE, 0.8), 0.8);
    ctx.lineWidth = 0.9;
    ctx.beginPath();
    for (i = 0; i < N; i++) if (kind[i] === 3) { X = px[i] * Z; Y = py[i] * Z; ctx.rect(X - 2, Y - 2, 4, 4); }
    ctx.stroke();

    /* 触ったところ */
    if (stir.on > 0.02) {
      X = stir.x * Z; Y = stir.y * Z;
      ctx.strokeStyle = P.rgba(P.accent, 0.4 * stir.on);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(X, Y, 40 + (1 - stir.on) * 60, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = P.rgba(P.accent, 0.75 * stir.on);
      ctx.beginPath(); ctx.arc(X, Y, 2.5, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; stir.x = e.clientX / Z; stir.y = e.clientY / Z; stir.on = 1;
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    stir.x = e.clientX / Z; stir.y = e.clientY / Z; stir.on = 1;
    SFX.play('move', 180);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  /* 決まりを替えるときは、シャーレを軽く揺すって塊をほどき、新しい決まりで組み直させる */
  function newRules() {
    from.set(rule); randomRule(target); morph = 0; morphWait = 36;
    for (var i = 0; i < N; i++) { var a = Math.random() * 6.2832, v = 40 + Math.random() * 80; vx[i] += Math.cos(a) * v; vy[i] += Math.sin(a) * v; }
  }
  function calmFrom(v) { return 0.09 - v / 100 * 0.07; }
  function reachFrom(v) { return 60 + v / 100 * 44; }
  document.getElementById('k-calm').addEventListener('input', function () { friction = calmFrom(+this.value); });
  document.getElementById('k-reach').addEventListener('input', function () { RMAX = reachFrom(+this.value); grid(); });
  document.getElementById('k-new').addEventListener('click', function () { newRules(); SFX.play('toggle'); });
  friction = calmFrom(+document.getElementById('k-calm').value);
  RMAX = reachFrom(+document.getElementById('k-reach').value);

  ctx = P.fit(canvas, function (w, h) { SW = w; SH = h; W = w / Z; H = h / Z; grid(); seed(); });
  randomRule(rule); target.set(rule);
  /* 読み込んだ直後から塊が見えるよう、少し先まで進めておく */
  for (var w = 0; w < 60; w++) step(1 / 30);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    /* しばらくすると決まりがゆっくり入れ替わる */
    morphWait -= dt;
    if (morphWait <= 0) newRules();
    if (morph < 1) {
      morph = Math.min(1, morph + dt / 4);
      var e = morph * morph * (3 - 2 * morph);
      for (var i = 0; i < K * K; i++) rule[i] = from[i] + (target[i] - from[i]) * e;
    }
    if (!down) stir.on = Math.max(0, stir.on - dt * 1.2);
    step(Math.min(dt, 1 / 30));
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
