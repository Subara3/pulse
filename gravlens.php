<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gravlens');

$pageName = 'gravlens';
$pageTitle = 'GRAVLENS - PULSE';
$pageDesc = '星と方眼が右から左へゆっくり流れ、見えない重い点のまわりで方眼が曲がって見える。その点の真後ろを通る星は、輪の形に引き伸ばされる。指でなぞると、重い点が指についてくる。';
$pageCanonical = 'https://pulse.subara3.com/gravlens.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-mass">MASS</label><input type="range" id="k-mass" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="35"></div>
  <div class="ctl-btns"><button type="button" class="active" data-lens="one">ONE</button><button type="button" data-lens="pair">PAIR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 重力レンズ。画面の各点から、光が曲げられて来た「元の位置」を逆にたどって、背景の絵を引いてくる。
     点の質量のレンズでは、元の位置 = 見えている位置 − θE² × (位置 − レンズ) / 距離² */
  var RS = 2, RW, RH, src, img, buf, bctx, lut;
  var mass = 0.5, drift = 0.35, mode = 'one';

  function alloc() {
    RS = W * H > 1600000 ? 3 : W * H > 600000 ? 2.4 : 2;
    RW = Math.ceil(W / RS); RH = Math.ceil(H / RS);
    img = new ImageData(RW, RH);
    buf = document.createElement('canvas'); buf.width = RW; buf.height = RH;
    bctx = buf.getContext('2d');
    buildSource();
    if (!lut) {
      lut = new Uint8Array(256 * 3);
      for (var i = 0; i < 256; i++) {
        var c = P.ramp(Math.pow(i / 255, 0.85));
        lut[i * 3] = c[0]; lut[i * 3 + 1] = c[1]; lut[i * 3 + 2] = c[2];
      }
    }
    if (!lens.x) { lens.x = lens.tx = W * 0.5; lens.y = lens.ty = H * 0.5; anchor.x = W * 0.5; anchor.y = H * 0.5; }
  }

  /* 背景の絵（横につながる帯）: 薄い方眼、ちらばる星、楕円の淡い銀河 */
  var SW, SH;
  function buildSource() {
    SW = RW * 2; SH = RH;
    src = new Float32Array(SW * SH);
    var g = Math.max(10, Math.round(44 / RS)), i, j;
    for (j = 0; j < SH; j++) {
      for (i = 0; i < SW; i++) {
        var gx = i % g, gy = j % g, v = 0;
        if (gx === 0 || gy === 0) v = 0.17;
        if ((gx === 0 && (i / g | 0) % 4 === 0) || (gy === 0 && (j / g | 0) % 4 === 0)) v = 0.3;
        src[j * SW + i] = v;
      }
    }
    var stars = Math.round(SW * SH / 900);
    for (var n = 0; n < stars; n++) {
      var b = Math.random();
      var bright = 0.35 + Math.pow(b, 3) * 0.9, r = 0.6 + Math.pow(b, 4) * 1.6;
      blob(Math.random() * SW, Math.random() * SH, r, r, 0, bright);
    }
    for (var m = 0; m < 5; m++) {
      var rx = (14 + Math.random() * 22) / RS * 2, ry = rx * (0.3 + Math.random() * 0.4);
      var gx0 = Math.random() * SW, gy0 = SH * (0.15 + Math.random() * 0.7), ga = Math.random() * Math.PI;
      blob(gx0, gy0, rx * 0.7, ry * 0.7, ga, 0.22);
      blob(gx0, gy0, rx * 0.22, ry * 0.22, ga, 0.4);
    }
  }
  function blob(cx, cy, rx, ry, ang, amp) {
    var ext = Math.ceil(Math.max(rx, ry) * 3), c = Math.cos(ang), s = Math.sin(ang);
    for (var dy = -ext; dy <= ext; dy++) {
      for (var dx = -ext; dx <= ext; dx++) {
        var u = (dx * c + dy * s) / rx, v = (-dx * s + dy * c) / ry;
        var w = Math.exp(-(u * u + v * v) * 0.5) * amp;
        if (w < 0.01) continue;
        var x = ((Math.round(cx) + dx) % SW + SW) % SW, y = Math.round(cy) + dy;
        if (y < 0 || y >= SH) continue;
        var k = y * SW + x;
        src[k] = Math.min(1, src[k] + w);
      }
    }
  }

  /* ---------------------------------------------------------- レンズ */
  var lens = { x: 0, y: 0, tx: 0, ty: 0 }, anchor = { x: 0, y: 0 }, held = false, clock = 0, scroll = 0;
  function masses() {
    var rE = Math.min(W, H) * (0.07 + mass * 0.13);
    if (mode === 'one') return [{ x: lens.x, y: lens.y, e2: rE * rE }];
    var a = clock * 0.35, d = rE * 0.55;
    return [
      { x: lens.x + Math.cos(a) * d, y: lens.y + Math.sin(a) * d, e2: rE * rE * 0.5 },
      { x: lens.x - Math.cos(a) * d, y: lens.y - Math.sin(a) * d, e2: rE * rE * 0.5 }
    ];
  }

  function render() {
    var ms = masses(), nm = ms.length, d = img.data;
    var mx = [], my = [], me = [];
    for (var q = 0; q < nm; q++) { mx.push(ms[q].x / RS); my.push(ms[q].y / RS); me.push(ms[q].e2 / (RS * RS)); }
    var soft = 0.6, off = scroll;
    for (var j = 0; j < RH; j++) {
      for (var i = 0; i < RW; i++) {
        var bx = i, by = j;
        for (q = 0; q < nm; q++) {
          var dx = i - mx[q], dy = j - my[q], r2 = dx * dx + dy * dy + soft;
          var f = me[q] / r2;
          bx -= dx * f; by -= dy * f;
        }
        /* 元の位置から背景を双線形で引く（縦は端で止め、横は帯をつなぐ） */
        bx += off;
        var x0 = Math.floor(bx), y0 = Math.floor(by), fx = bx - x0, fy = by - y0, v;
        if (y0 < 0 || y0 >= SH - 1) v = 0;
        else {
          x0 %= SW; if (x0 < 0) x0 += SW;
          var x1 = x0 + 1 === SW ? 0 : x0 + 1, r0 = y0 * SW, r1 = r0 + SW;
          v = (src[r0 + x0] * (1 - fx) + src[r0 + x1] * fx) * (1 - fy) + (src[r1 + x0] * (1 - fx) + src[r1 + x1] * fx) * fy;
        }
        var li = (v > 1 ? 255 : v * 255) | 0, o = (j * RW + i) * 4;
        d[o] = lut[li * 3]; d[o + 1] = lut[li * 3 + 1]; d[o + 2] = lut[li * 3 + 2]; d[o + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, RW * RS, RH * RS);

    /* 重い点そのものは見えない。位置だけを、シアンの小さな輪で示す（画面で唯一の焦点） */
    for (q = 0; q < nm; q++) {
      ctx.strokeStyle = P.rgba(P.accent, held ? 0.9 : 0.6);
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.arc(ms[q].x, ms[q].y, 3.5, 0, Math.PI * 2);
      ctx.stroke();
    }
    /* 輪の大きさ（アインシュタイン半径）を、ごく薄い破線で添える */
    var rE = Math.sqrt(ms[0].e2 * nm);
    ctx.save();
    ctx.setLineDash([2, 7]);
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.beginPath();
    ctx.arc(lens.x, lens.y, rE, 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();
  }

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    held = true; lens.tx = e.clientX; lens.ty = e.clientY;
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!held) return;
    lens.tx = e.clientX; lens.ty = e.clientY;
    SFX.play('move', 220);
  });
  function up() {
    if (!held) return;
    held = false; anchor.x = lens.tx; anchor.y = lens.ty; wanderT = 0;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-mass').addEventListener('input', function () { mass = this.value / 100; });
  document.getElementById('k-drift').addEventListener('input', function () { drift = this.value / 100; });
  document.querySelectorAll('[data-lens]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-lens]').forEach(function (o) { o.classList.toggle('active', o === b); });
      mode = b.dataset.lens;
      SFX.play('toggle');
    });
  });
  mass = document.getElementById('k-mass').value / 100;
  drift = document.getElementById('k-drift').value / 100;

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), wanderT = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt; wanderT += dt;
    scroll += dt * (2 + drift * 26) / RS;
    if (scroll > SW) scroll -= SW;
    if (!held) {
      /* 離しているあいだは、置いた場所のまわりをゆっくり漂う。漂い始めは小さく */
      var amp = Math.min(1, wanderT / 6);
      lens.tx = anchor.x + Math.sin(clock * 0.13) * W * 0.12 * amp;
      lens.ty = anchor.y + Math.sin(clock * 0.17 + 1) * H * 0.1 * amp;
    }
    var e = 1 - Math.exp(-dt * (held ? 9 : 2));
    lens.x += (lens.tx - lens.x) * e; lens.y += (lens.ty - lens.y) * e;
    render();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
