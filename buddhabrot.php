<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('buddhabrot');

$pageName = 'buddhabrot';
$pageTitle = 'BUDDHABROT - PULSE';
$pageDesc = 'たくさんの点が飛び去った道筋を重ねると、ぼんやり光る左右対称の形が浮かび、時間とともにゆっくり形が変わる。触ったところから出発した点の道筋だけが水色で表示される。';
$pageCanonical = 'https://pulse.subara3.com/buddhabrot.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-turn">TURN</label><input type="range" id="k-turn" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-depth">DEPTH</label><input type="range" id="k-depth" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /*
   * c を乱数で選んで z → z² + c を回し、外へ逃げた c についてだけ、途中で通った z を全部数える。
   * 数えた濃さを長時間露光のように重ねる（古い分は少しずつ薄れる）。
   * 描く平面を z 平面から c 平面へ少しだけ傾けていくと、雲がねじれる（4 次元の回転）。
   */
  var NX, NY, den, acc, img, buf, bctx, sc, cx, cy;
  var orbX = new Float64Array(2000), orbY = new Float64Array(2000);
  function alloc() {
    var s = Math.min(1, Math.sqrt(330000 / (W * H)));
    NX = Math.max(200, Math.round(W * s)); NY = Math.max(120, Math.round(H * s));
    den = new Float32Array(NX * NY);
    acc = new Float32Array(NX * NY);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas'); buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    /* 実軸を縦に置く（-2 が上） */
    sc = Math.min(NY * 0.9 / 3.1, NX * 0.9 / 2.7);
    cx = NX / 2; cy = NY / 2;
    peak = 1; apeak = 1;
  }

  var LUT = new Uint8Array(256 * 3), LUTA = new Uint8Array(256 * 3);
  for (var i = 0; i < 256; i++) {
    var c = P.ramp(i / 255), ca = P.rampAccent(i / 255);
    LUT[i * 3] = c[0]; LUT[i * 3 + 1] = c[1]; LUT[i * 3 + 2] = c[2];
    LUTA[i * 3] = ca[0]; LUTA[i * 3 + 1] = ca[1]; LUTA[i * 3 + 2] = ca[2];
  }

  /* 濃さ → 明るさ（平方根で持ち上げ、薄い霞は地に沈める）を先に表にしておく */
  var TONE = new Uint16Array(1024), TL = new Float32Array(1024);
  for (var q = 0; q < 1024; q++) {
    var l = Math.sqrt(q / 1023); l = l < 0.06 ? 0 : (l - 0.06) / 0.94;
    TL[q] = l; TONE[q] = ((l * 0.97 * 255) | 0) * 3;
  }

  var theta = 0, turn = 0.45, maxIt = 600, t = 0;
  var cosT = 1, sinT = 0;

  /* 画面の点 → 平面座標（re: 縦, im: 横） */
  function plot(arr, zr, zi, cr, ci, w) {
    var X = zr * cosT + cr * sinT, Y = zi * cosT + ci * sinT;
    var py = (cy + (X + 0.45) * sc) | 0;
    var px1 = (cx + Y * sc) | 0, px2 = (cx - Y * sc) | 0;
    if (py < 0 || py >= NY) return;
    var row = py * NX;
    if (px1 >= 0 && px1 < NX) arr[row + px1] += w;
    if (px2 >= 0 && px2 < NX) arr[row + px2] += w;   // 共役も同じ形になる
  }

  function sample(budget) {
    var t0 = performance.now(), mi = maxIt;
    do {
      for (var rep = 0; rep < 200; rep++) {
        var cr = Math.random() * 3.1 - 2.05, ci = Math.random() * 1.5;
        var q = (cr - 0.25) * (cr - 0.25) + ci * ci;
        if (q * (q + (cr - 0.25)) < 0.25 * ci * ci || (cr + 1) * (cr + 1) + ci * ci < 0.0625) continue;
        var zr = 0, zi = 0, n = 0, zr2 = 0, zi2 = 0;
        for (; n < mi; n++) {
          zi = 2 * zr * zi + ci; zr = zr2 - zi2 + cr;
          zr2 = zr * zr; zi2 = zi * zi;
          orbX[n] = zr; orbY[n] = zi;
          if (zr2 + zi2 > 4) break;
        }
        if (n >= mi || n < 8) continue;
        for (var k = 0; k < n; k++) plot(den, orbX[k], orbY[k], cr, ci, 1);
      }
    } while (performance.now() - t0 < budget);
  }

  /* 触ったところから出発する道筋（逃げなくても描く） */
  var touch = { on: false, x: 0, y: 0, s: 0 };
  function sampleTouch(cnt) {
    var re = (touch.y / H * NY - cy) / sc - 0.45, im = (touch.x / W * NX - cx) / sc;
    for (var r = 0; r < cnt; r++) {
      var a = Math.random() * Math.PI * 2, d = Math.sqrt(Math.random()) * 0.035;
      var cr = re + Math.cos(a) * d, ci = im + Math.sin(a) * d;
      var zr = 0, zi = 0, zr2 = 0, zi2 = 0;
      for (var n = 0; n < 260; n++) {
        zi = 2 * zr * zi + ci; zr = zr2 - zi2 + cr;
        zr2 = zr * zr; zi2 = zi * zi;
        if (zr2 + zi2 > 4) break;
        plot(acc, zr, zi, cr, ci, 1);
      }
    }
  }

  var peak = 1, apeak = 1;
  function shade() {
    var d = img.data, len = NX * NY, mx = 0, amx = 0;
    var inv = 1 / peak, ainv = 1 / apeak;
    for (var k = 0; k < len; k++) {
      var v = den[k], a = acc[k], o = k * 4;
      if (v > mx) mx = v;
      if (a > amx) amx = a;
      den[k] = v * 0.988;
      acc[k] = a * 0.9;
      var q = (v * inv * 1023) | 0; if (q > 1023) q = 1023;
      var li = TONE[q], l = TL[q];
      if (a > 0.01) {
        var w = a * ainv; w = w > 1 ? 1 : Math.sqrt(w);
        var la = ((Math.min(1, l * 0.6 + w * 0.75) * 255) | 0) * 3, ww = Math.min(1, w * 1.4) * touch.s;
        d[o] = LUT[li] + (LUTA[la] - LUT[li]) * ww; d[o + 1] = LUT[li + 1] + (LUTA[la + 1] - LUT[li + 1]) * ww;
        d[o + 2] = LUT[li + 2] + (LUTA[la + 2] - LUT[li + 2]) * ww;
      } else { d[o] = LUT[li]; d[o + 1] = LUT[li + 1]; d[o + 2] = LUT[li + 2]; }
      d[o + 3] = 255;
    }
    /* 明るさの基準は「ほぼ最大」をゆっくり追う。真ん中の芯だけが白くなる */
    peak += (Math.max(1, mx * 0.62) - peak) * 0.12;
    apeak += (Math.max(1, amx * 0.5) - apeak) * 0.2;
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    touch.on = true; touch.x = e.clientX; touch.y = e.clientY;
    canvas.setPointerCapture(e.pointerId);
    SFX.play('spawn', 250);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!touch.on) return;
    touch.x = e.clientX; touch.y = e.clientY;
    SFX.play('move', 260);
  });
  function up() { touch.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-turn').addEventListener('input', function () { turn = this.value / 100; });
  document.getElementById('k-depth').addEventListener('input', function () { maxIt = Math.round(150 + this.value / 100 * 1500); });
  turn = document.getElementById('k-turn').value / 100;
  maxIt = Math.round(150 + document.getElementById('k-depth').value / 100 * 1500);
  document.getElementById('k-clear').addEventListener('click', function () { den.fill(0); acc.fill(0); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  sample(220);
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    t += dt;
    /* ゆっくり傾けては戻す（約 70 秒で一往復） */
    theta = turn * 0.75 * (0.5 - 0.5 * Math.cos(t * 0.09));
    cosT = Math.cos(theta); sinT = Math.sin(theta);
    touch.s += ((touch.on ? 1 : 0) - touch.s) * Math.min(1, dt * (touch.on ? 6 : 1.2));
    sample(4.5);
    if (touch.on) sampleTouch(160);
    shade();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
