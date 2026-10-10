<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('waves');

$pageName = 'waves';
$pageTitle = 'WAVES - PULSE';
$pageDesc = '暗い水面に雨が落ちて、輪がいくつも重なっては消えていく。押すとそこから大きな輪が広がり、なぞると指の跡に沿って波が立つ。';
$pageCanonical = 'https://pulse.subara3.com/waves.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-rain">RAIN</label><input type="range" id="k-rain" min="0" max="100" value="60"></div>
  <div class="ctl-row"><label for="k-calm">CALM</label><input type="range" id="k-calm" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="light">LIGHT</button><button type="button" data-look="lines">LINES</button></div>
  <div class="ctl-btns"><button type="button" id="k-still">STILL</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 水面は粗い格子で解き、拡大して描く */
  var NX, NY, cur, prev, img, buf, bctx;
  var rings = [];
  var look = 'light';
  var rain = 0.6, damp = 0.992;

  function alloc() {
    NX = Math.max(140, Math.min(320, Math.round(W / 4)));
    NY = Math.max(70, Math.round(NX * H / W));
    cur = new Float32Array(NX * NY);
    prev = new Float32Array(NX * NY);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    for (var i = 0; i < 5; i++) drop(Math.random() * W, Math.random() * H, 0.6 + Math.random() * 0.6, false);
  }

  /* 水滴: 小さなくぼみを置く。輪の見た目は格子の波が勝手に作る */
  function drop(x, y, power, mark, rad) {
    var cx = x / W * NX, cy = y / H * NY, r = rad || 2.6;
    for (var j = Math.max(1, Math.floor(cy - r)); j < Math.min(NY - 1, Math.ceil(cy + r)); j++) {
      for (var i = Math.max(1, Math.floor(cx - r)); i < Math.min(NX - 1, Math.ceil(cx + r)); i++) {
        var d = Math.hypot(i - cx, j - cy) / r;
        if (d < 1) cur[j * NX + i] -= power * (Math.cos(d * Math.PI) + 1) * 0.5;
      }
    }
    if (mark) rings.push({ x: x, y: y, t: 0 });
  }

  function step() {
    for (var j = 1; j < NY - 1; j++) {
      var row = j * NX;
      for (var i = 1; i < NX - 1; i++) {
        var k = row + i;
        /* 斜め 4 近傍も混ぜて、輪が四角く歪まないようにする */
        var v = (cur[k - 1] + cur[k + 1] + cur[k - NX] + cur[k + NX]) * 0.4
              + (cur[k - NX - 1] + cur[k - NX + 1] + cur[k + NX - 1] + cur[k + NX + 1]) * 0.1 - prev[k];
        prev[k] = v * damp;
      }
    }
    var t = prev; prev = cur; cur = t;
  }

  /* 斜面に光を当てて塗る。藍の地に、波の肩だけが白く光る */
  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel;
  function shade() {
    var d = img.data;
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var k = j * NX + i, o = k * 4;
        var gx = (i > 0 && i < NX - 1) ? cur[k + 1] - cur[k - 1] : 0;
        var gy = (j > 0 && j < NY - 1) ? cur[k + NX] - cur[k - NX] : 0;
        var s = (gx * 0.8 - gy * 0.6) * 10;
        var r, g, b;
        if (look === 'lines') {
          /* 等高線: 高さを細かく刻んで、刻み目だけを線にする */
          var h = cur[k] * 9, f = h - Math.floor(h);
          var a = Math.max(0, 1 - Math.abs(f - 0.5) * 9) * Math.min(1, Math.abs(cur[k]) * 14);
          r = BG[0] + (LN[0] - BG[0]) * a; g = BG[1] + (LN[1] - BG[1]) * a; b = BG[2] + (LN[2] - BG[2]) * a;
        } else if (s >= 0) {
          var hi = Math.min(1, s), hi2 = hi * hi;
          r = BG[0] + (ST[0] - BG[0]) * hi + (LN[0] - ST[0]) * hi2 * hi;
          g = BG[1] + (ST[1] - BG[1]) * hi + (LN[1] - ST[1]) * hi2 * hi;
          b = BG[2] + (ST[2] - BG[2]) * hi + (LN[2] - ST[2]) * hi2 * hi;
        } else {
          var lo = Math.min(1, -s);
          r = BG[0] + (DEEP[0] - BG[0]) * lo; g = BG[1] + (DEEP[1] - BG[1]) * lo; b = BG[2] + (DEEP[2] - BG[2]) * lo;
        }
        d[o] = r; d[o + 1] = g; d[o + 2] = b; d[o + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';   /* 'high' はソフト描画（撮影環境）で 10 倍近く重い */
    ctx.drawImage(buf, 0, 0, W, H);
  }

  /* 触ったところにだけ、シアンの細い輪を一瞬重ねる */
  function drawRings(dt) {
    ctx.lineWidth = 1;
    for (var n = rings.length - 1; n >= 0; n--) {
      var rg = rings[n];
      rg.t += dt;
      var a = 1 - rg.t / 1.6;
      if (a <= 0) { rings.splice(n, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, a * 0.55);
      ctx.beginPath();
      ctx.arc(rg.x, rg.y, 6 + rg.t * 70, 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e);
    drop(last.x, last.y, 4.5, true, 6);   /* 指の下は大きく深く。押した瞬間に輪が立つ */
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 10) return;
    var n = Math.ceil(dist / 14);
    for (var i = 1; i <= n; i++) drop(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, 1.1, false, 3.5);
    last = p;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-rain').addEventListener('input', function () { rain = this.value / 100; });
  document.getElementById('k-calm').addEventListener('input', function () { damp = 0.975 + this.value / 100 * 0.022; });
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-still').addEventListener('click', function () {
    cur.fill(0); prev.fill(0); rings.length = 0;
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  rain = document.getElementById('k-rain').value / 100;
  damp = 0.975 + document.getElementById('k-calm').value / 100 * 0.022;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), rainAcc = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    rainAcc += dt * (0.4 + rain * 5);
    while (rainAcc > 1) {
      rainAcc -= 1;
      drop(Math.random() * W, Math.random() * H, 0.9 + Math.random() * 1.1, false, 3);
      if (Math.random() < 0.35) SFX.play('ripple', 900);
    }
    step(); step();
    shade();
    drawRings(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
