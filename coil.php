<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('coil');

$pageName = 'coil';
$pageTitle = 'COIL - PULSE';
$pageDesc = '上に並んだ口から細い糸が落ち、下へ流れる床の上に輪や8の字を描きながら積もっていく。輪の大きさと巻き方はゆっくり変わり、描いた線は床といっしょに下へ流れていく。押すと近くの口が指の下へ来て水色の糸を落とし、まわりの線が押しのけられる。なぞると口が指についてきて、指の跡に沿って輪が並ぶ。';
$pageCanonical = 'https://pulse.subara3.com/coil.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 糸は「床の座標」で覚える。床が流れた分 scroll が増え、画面の y = 床の y + scroll */
  var CAP = 9000;
  var nozzles = [];
  var scroll = 0, clock = 0;
  var speedK = 0.4, sizeK = 0.5;
  var V = 40, rowY = 100, hang = 80, spacing = 200, pushR = 60;
  var down = false, held = null, px = 0, py = 0, lastHeat = -99;

  function makeNozzle(k, n) {
    return {
      k: k,
      home: (k + 0.5) * spacing,
      x: (k + 0.5) * spacing, y: rowY,
      th: Math.random() * 6.28, ps: Math.random() * 6.28,
      dir: Math.random() < 0.5 ? -1 : 1, flipIn: 6 + Math.random() * 12,
      heat: 0,
      bx: new Float64Array(CAP), by: new Float64Array(CAP),
      bh: new Float32Array(CAP), bt: new Float32Array(CAP),
      head: 0, count: 0, lx: 1e9, ly: 1e9,
      cx: 0, cy: 0
    };
  }

  function layout() {
    var n = Math.max(3, Math.round(W / 175));
    spacing = W / n;
    rowY = Math.max(70, H * 0.14);
    hang = rowY * 0.78;
    pushR = Math.max(50, Math.min(W, H) * 0.1);
    V = H * (0.01 + speedK * 0.06);
    nozzles = [];
    for (var k = 0; k < n; k++) nozzles.push(makeNozzle(k, n));
    /* 開いた瞬間から画面が埋まっているように、床が一度流れきるまで先に回しておく */
    var fill = (H - rowY) / V + 1.5;
    for (var t = 0; t < fill; t += 1 / 30) advance(1 / 30, true);
  }

  function push(nz, x, y, h) {
    var yb = y - scroll;
    if (Math.abs(x - nz.lx) + Math.abs(yb - nz.ly) < 3) return;
    var i = nz.head;
    nz.bx[i] = x; nz.by[i] = yb; nz.bh[i] = h; nz.bt[i] = clock;
    nz.head = (i + 1) % CAP;
    if (nz.count < CAP) nz.count++;
    nz.lx = x; nz.ly = yb;
  }

  /* 落ちた糸が床に着く点: 輪を描く巻き方と、左右に振る巻き方を混ぜる */
  function contact(nz, R) {
    var t = clock, k = nz.k;
    var m = 0.5 + 0.5 * Math.sin(t * 0.07 + k * 1.9);
    m = m * m * (3 - 2 * m);
    var cx = R * Math.cos(nz.th), cy = R * Math.sin(nz.th);
    var mx = 1.5 * R * Math.sin(nz.ps), my = 0.7 * R * Math.sin(2 * nz.ps);
    nz.cx = nz.x + cx + (mx - cx) * m;
    nz.cy = nz.y + cy + (my - cy) * m;
  }

  function advance(dt, warm) {
    var SUB = 3, h = dt / SUB;
    var Rb = spacing * (0.16 + sizeK * 0.3);
    for (var s = 0; s < SUB; s++) {
      clock += h;
      scroll += V * h;
      for (var n = 0; n < nozzles.length; n++) {
        var nz = nozzles[n], k = nz.k, t = clock;
        var R = Rb * (0.62 + 0.42 * Math.sin(t * 0.11 + k * 1.7));
        var w = 6 + 2 * Math.sin(t * 0.05 + k * 2.3);
        if (nz === held) {
          R *= 1.25;
          nz.heat = 1; lastHeat = clock;
          var a = 1 - Math.exp(-h * 18);
          nz.x += (px - nz.x) * a; nz.y += (py - nz.y) * a;
        } else {
          nz.heat = Math.max(0, nz.heat - h * 0.35);
          var hx = nz.home + spacing * 0.32 * Math.sin(t * 0.09 + k * 2.1);
          var b = 1 - Math.exp(-h * 1.2);
          nz.x += (hx - nz.x) * b; nz.y += (rowY - nz.y) * b;
        }
        nz.flipIn -= h;
        if (nz.flipIn < 0) {
          nz.dir = -nz.dir; nz.flipIn = 7 + Math.random() * 14;
          if (!warm) SFX.play('tick', 2500);
        }
        nz.th += w * nz.dir * h;
        nz.ps += w * 0.6 * h;
        contact(nz, R);
        push(nz, nz.cx, nz.cy, nz.heat);
      }
    }
  }

  /* 押しているあいだ、指のまわりの線を外へよける（指が落としている糸は除く） */
  function part(dt) {
    var r2 = pushR * pushR;
    for (var n = 0; n < nozzles.length; n++) {
      var nz = nozzles[n];
      if (nz === held) continue;
      var bx = nz.bx, by = nz.by;
      for (var c = 0; c < nz.count; c++) {
        var i = (nz.head - 1 - c + CAP) % CAP;
        var dx = bx[i] - px, dy = by[i] + scroll - py, d2 = dx * dx + dy * dy;
        if (d2 >= r2 || d2 < 0.01) continue;
        var d = Math.sqrt(d2), f = 1 - d / pushR;
        var mv = Math.min(pushR - d, f * f * 260 * dt);
        bx[i] += dx / d * mv; by[i] += dy / d * mv;
      }
    }
  }

  function strandPath(nz, sx, sy) {
    ctx.beginPath();
    var started = false;
    for (var c = nz.count - 1; c >= 0; c--) {
      var i = (nz.head - 1 - c + CAP) % CAP;
      var y = nz.by[i] + scroll + sy;
      if (y > H + 40) { started = false; continue; }
      if (!started) { ctx.moveTo(nz.bx[i] + sx, y); started = true; }
      else ctx.lineTo(nz.bx[i] + sx, y);
    }
    if (nz.count) ctx.lineTo(nz.cx + sx, nz.cy + sy);
  }

  /* 床の方眼も一緒に流す */
  function floor() {
    P.fill(ctx, W, H);
    var step = 32, off = scroll % (step * 4);
    ctx.lineWidth = 1;
    for (var pass = 0; pass < 2; pass++) {
      ctx.strokeStyle = P.rgba(P.line, pass ? 0.07 : 0.028);
      ctx.beginPath();
      for (var i = 0, x = 0.5; x < W; i++, x += step) {
        if ((i % 4 === 0) === !!pass) { ctx.moveTo(x, 0); ctx.lineTo(x, H); }
      }
      for (var j = -4; ; j++) {
        var y = Math.round(j * step + off) + 0.5;
        if (y > H) break;
        if (y < 0) continue;
        if ((((j % 4) + 4) % 4 === 0) === !!pass) { ctx.moveTo(0, y); ctx.lineTo(W, y); }
      }
      ctx.stroke();
    }
  }

  var BINS = 4;
  function draw() {
    floor();
    ctx.lineJoin = 'round'; ctx.lineCap = 'round';
    var n, nz;
    /* 白い糸（影を太線で重ねると重いので付けない） */
    ctx.strokeStyle = P.rgba(P.line, 0.82);
    ctx.lineWidth = 1.4;
    for (n = 0; n < nozzles.length; n++) { strandPath(nozzles[n], 0, 0); ctx.stroke(); }

    /* 指が落とした糸だけ水色を重ね、8 秒かけて白へ戻す */
    for (var bIdx = 1; bIdx <= BINS && clock - lastHeat < 8.5; bIdx++) {
      var lo = (bIdx - 0.5) / BINS, hi = (bIdx + 0.5) / BINS;
      ctx.strokeStyle = P.rgba(P.accent, Math.min(1, bIdx / BINS) * 0.95);
      ctx.lineWidth = 1.6;
      ctx.beginPath();
      for (n = 0; n < nozzles.length; n++) {
        nz = nozzles[n];
        var prevOk = false;
        for (var c = nz.count - 1; c >= 0; c--) {
          var i = (nz.head - 1 - c + CAP) % CAP;
          var hv = nz.bh[i] * Math.max(0, 1 - (clock - nz.bt[i]) / 8);
          var ok = hv > 0.04 && hv >= lo && hv < hi;
          var y = nz.by[i] + scroll;
          if (ok && y < H + 40) {
            if (prevOk) ctx.lineTo(nz.bx[i], y); else ctx.moveTo(nz.bx[i], y);
            prevOk = true;
          } else prevOk = false;
        }
      }
      ctx.stroke();
    }

    /* 上から落ちてくる糸と、その口 */
    for (n = 0; n < nozzles.length; n++) {
      nz = nozzles[n];
      var tx = nz.x, ty = nz.y - hang;
      var col = nz.heat > 0.05 ? P.mix(P.line, P.accent, nz.heat) : P.line;
      ctx.strokeStyle = P.rgba(P.steel, 0.35);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(tx, 0); ctx.lineTo(tx, ty - 3); ctx.stroke();
      ctx.strokeStyle = P.rgba(col, 0.9);
      ctx.lineWidth = 1.6;
      ctx.beginPath();
      ctx.moveTo(tx, ty);
      ctx.bezierCurveTo(tx, ty + hang * 0.55, nz.cx + (tx - nz.cx) * 0.6, nz.cy - hang * 0.25, nz.cx, nz.cy);
      ctx.stroke();
      ctx.fillStyle = P.rgba(P.steel, 0.9);
      ctx.fillRect(tx - 6, ty - 4, 12, 4);
      ctx.fillStyle = P.rgba(col, 0.9);
      ctx.beginPath(); ctx.arc(nz.cx, nz.cy, 1.8, 0, 6.2832); ctx.fill();
    }
  }

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; held = null; scroll = 0; layout(); });

  /* ---------------------------------------------------------- 操作 */
  function nearest(x) {
    var best = null, bd = 1e9;
    for (var n = 0; n < nozzles.length; n++) {
      var d = Math.abs(nozzles[n].x - x);
      if (d < bd) { bd = d; best = nozzles[n]; }
    }
    return best;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY;
    held = nearest(px);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var d = Math.hypot(e.clientX - px, e.clientY - py);
    px = e.clientX; py = e.clientY;
    if (d > 2) SFX.play('move', 140);
  });
  function up() { down = false; held = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { speedK = document.getElementById('k-speed').value / 100; V = H * (0.01 + speedK * 0.06); }
  function readSize() { sizeK = document.getElementById('k-size').value / 100; }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-size').addEventListener('input', readSize);
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readSize();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    advance(dt, false);
    if (down) part(dt);
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
