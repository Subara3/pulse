<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('soapfilm');

$pageName = 'soapfilm';
$pageTitle = 'SOAPFILM - PULSE';
$pageDesc = '縦に張った石けんの膜に光の縞が横に何本も並び、膜が上から薄くなるにつれて縞がゆっくり下へ流れていく。膜の中ではところどころで渦が巻き、縞が巻き込まれて曲がる。押すとそこの膜が厚くなって輪の縞が出て、なぞると縞が指の動きに引きずられる。';
$pageCanonical = 'https://pulse.subara3.com/soapfilm.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-swirl">SWIRL</label><input type="range" id="k-swirl" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-drain">DRAIN</label><input type="range" id="k-drain" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" id="k-still">STILL</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 膜の厚さを粗い格子で持ち、流れで運んで、厚さから縞を塗る。
     厚さの単位は「縞 1 本ぶん」。上ほど薄く、下ほど厚い */
  var NX, NY, h, h2, glow, base, ux, uy, img, buf, bctx;
  var vort = [];          /* 膜の中で巻く渦 */
  var finger = { on: false, x: 0, y: 0, vx: 0, vy: 0, w: 0 };
  var swirl = 0.5, drain = 0.4, phase = 0, clock = 0;
  var LUT = new Uint8ClampedArray(256 * 3), LUTA = new Uint8ClampedArray(256 * 3);

  for (var n = 0; n < 256; n++) {
    var c = P.ramp(0.06 + n / 255 * 0.94), ca = P.rampAccent(0.1 + n / 255 * 0.9);
    LUT[n * 3] = c[0]; LUT[n * 3 + 1] = c[1]; LUT[n * 3 + 2] = c[2];
    LUTA[n * 3] = ca[0]; LUTA[n * 3 + 1] = ca[1]; LUTA[n * 3 + 2] = ca[2];
  }

  function alloc() {
    NX = Math.max(120, Math.min(260, Math.round(W / 4)));
    NY = Math.max(80, Math.min(300, Math.round(NX * H / W)));
    h = new Float32Array(NX * NY); h2 = new Float32Array(NX * NY);
    glow = new Float32Array(NX * NY);
    ux = new Float32Array(NX * NY); uy = new Float32Array(NX * NY);
    base = new Float32Array(NY);
    for (var j = 0; j < NY; j++) base[j] = 0.4 + 15 * Math.pow(j / (NY - 1), 1.35);
    for (var k = 0; k < NX * NY; k++) h[k] = base[(k / NX) | 0];
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    vort.length = 0;
    for (var i = 0; i < 7; i++) vort.push(newVortex(true));
    /* 開いた瞬間から縞が曲がっているように、先に少し回しておく */
    for (var s = 0; s < 40; s++) advance(0.05);
  }

  function newVortex(any) {
    var m = Math.min(NX, NY);
    return {
      x: Math.random() * NX,
      y: (any ? 0.1 + Math.random() * 0.85 : 0.5 + Math.random() * 0.45) * NY,
      r: m * (0.07 + Math.random() * 0.1),
      s: (Math.random() < 0.5 ? -1 : 1) * (0.8 + Math.random() * 0.7),
      t: any ? Math.random() * 10 : 0,
      life: 9 + Math.random() * 10,
      dx: (Math.random() - 0.5) * 1.2,
      dy: -(0.6 + Math.random() * 1.4)      /* 薄い膜はゆっくり浮き上がる */
    };
  }

  /* 速さの場: 渦（ガウス形の流れ関数）＋指の引きずり＋ゆるい全体のうねり */
  function field() {
    ux.fill(0); uy.fill(0);
    var amp = 0.4 + swirl * 2.2;
    for (var v = 0; v < vort.length; v++) {
      var o = vort[v];
      var env = Math.sin(Math.PI * Math.min(1, o.t / o.life));
      var S = o.s * env * amp * o.r * o.r * 0.25, R2 = o.r * o.r, rr = o.r * 3;
      addSwirl(o.x, o.y, S, R2, rr);
    }
    if (finger.w > 0.01) {
      var fx = finger.x / W * NX, fy = finger.y / H * NY, fr = Math.min(NX, NY) * 0.09, F2 = fr * fr;
      var gvx = finger.vx / W * NX, gvy = finger.vy / H * NY;
      for (var j = Math.max(0, (fy - fr * 3) | 0); j < Math.min(NY, fy + fr * 3); j++) {
        for (var i = Math.max(0, (fx - fr * 3) | 0); i < Math.min(NX, fx + fr * 3); i++) {
          var dx = i - fx, dy = j - fy, e = Math.exp(-(dx * dx + dy * dy) / F2) * finger.w;
          var k = j * NX + i;
          ux[k] += gvx * e; uy[k] += gvy * e;
        }
      }
    }
  }
  function addSwirl(cx, cy, S, R2, rr) {
    for (var j = Math.max(0, (cy - rr) | 0); j < Math.min(NY, cy + rr); j++) {
      for (var i = Math.max(0, (cx - rr) | 0); i < Math.min(NX, cx + rr); i++) {
        var dx = i - cx, dy = j - cy, e = Math.exp(-(dx * dx + dy * dy) / R2) * 2 * S / R2;
        var k = j * NX + i;
        ux[k] += -dy * e; uy[k] += dx * e;
      }
    }
  }

  /* 厚さを流れで運び（後ろをたどって拾う）、元の厚さの並びへゆっくり戻す */
  function advance(dt) {
    clock += dt;
    for (var v = vort.length - 1; v >= 0; v--) {
      var o = vort[v];
      o.t += dt; o.x += o.dx * dt; o.y += o.dy * dt;
      if (o.t > o.life || o.y < -o.r) vort[v] = newVortex(false);
    }
    field();
    var relax = 0.06 * dt, wob = Math.sin(clock * 0.13) * 0.6;
    for (var j = 0; j < NY; j++) {
      var row = j * NX, sway = Math.sin(clock * 0.21 + j * 0.035) * 0.5 + wob;
      for (var i = 0; i < NX; i++) {
        var k = row + i;
        var sx = i - (ux[k] + sway) * dt, sy = j - uy[k] * dt;
        if (sx < 0) sx = 0; else if (sx > NX - 1.001) sx = NX - 1.001;
        if (sy < 0) sy = 0; else if (sy > NY - 1.001) sy = NY - 1.001;
        var x0 = sx | 0, y0 = sy | 0, fx = sx - x0, fy = sy - y0, q = y0 * NX + x0;
        var a = h[q] + (h[q + 1] - h[q]) * fx, b = h[q + NX] + (h[q + NX + 1] - h[q + NX]) * fx;
        var val = a + (b - a) * fy;
        h2[k] = val + (base[j] - val) * relax;
        glow[k] *= 1 - 0.7 * dt;
      }
    }
    var t = h; h = h2; h2 = t;
  }

  /* 押したところの膜を厚くする。厚さの山のまわりに輪の縞が出る */
  function thicken(x, y, amt, rad) {
    var cx = x / W * NX, cy = y / H * NY, r = rad * Math.min(NX, NY), R2 = r * r;
    for (var j = Math.max(0, (cy - r * 2.5) | 0); j < Math.min(NY, cy + r * 2.5); j++) {
      for (var i = Math.max(0, (cx - r * 2.5) | 0); i < Math.min(NX, cx + r * 2.5); i++) {
        var dx = i - cx, dy = j - cy, e = Math.exp(-(dx * dx + dy * dy) / R2);
        var k = j * NX + i;
        h[k] += amt * e;
        glow[k] = Math.min(1, glow[k] + e * 0.9);
      }
    }
  }

  /* 塗る: 厚さが縞 1 本ぶん変わるごとに白い線。上の薄すぎる膜は暗く沈む */
  function shade() {
    var d = img.data, ph = phase;
    for (var k = 0, o = 0; k < NX * NY; k++, o += 4) {
      var th = h[k];
      var c = 0.5 + 0.5 * Math.cos(6.2832 * (th - ph));
      var band = c * c * c * c;
      var con = th < 0.2 ? 0.25 : th > 1.6 ? 1 : 0.25 + (th - 0.2) / 1.4 * 0.75;
      var n = (band * con * 255) | 0, g = glow[k], m = n * 3;
      if (g > 0.02) {
        d[o] = LUT[m] + (LUTA[m] - LUT[m]) * g;
        d[o + 1] = LUT[m + 1] + (LUTA[m + 1] - LUT[m + 1]) * g;
        d[o + 2] = LUT[m + 2] + (LUTA[m + 2] - LUT[m + 2]) * g;
      } else {
        d[o] = LUT[m]; d[o + 1] = LUT[m + 1]; d[o + 2] = LUT[m + 2];
      }
      d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  ctx = P.fit(canvas, function (w, hh) { W = w; H = hh; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastT0 = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; lastT0 = performance.now();
    finger.on = true; finger.x = last.x; finger.y = last.y; finger.vx = 0; finger.vy = 0; finger.w = 1;
    thicken(last.x, last.y, 3, 0.085);
    /* 押したところに渦を 1 つ置く。縞の輪がねじれていく */
    var v = newVortex(false);
    v.x = last.x / W * NX; v.y = last.y / H * NY; v.dy = -0.4; v.dx = 0;
    v.r = Math.min(NX, NY) * 0.11; v.t = 0; v.life = 8;
    vort.push(v);
    if (vort.length > 16) vort.splice(0, 1);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now(), dtm = Math.max(0.008, (now - lastT0) / 1000);
    var p = { x: e.clientX, y: e.clientY };
    var vx = (p.x - last.x) / dtm, vy = (p.y - last.y) / dtm, sp = Math.hypot(vx, vy);
    if (sp > 1600) { vx *= 1600 / sp; vy *= 1600 / sp; }
    finger.vx += (vx - finger.vx) * 0.6; finger.vy += (vy - finger.vy) * 0.6;
    finger.x = p.x; finger.y = p.y;
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist > 4) {
      var cx = last.x / W * NX, cy = last.y / H * NY;
      var gk = ((Math.max(0, Math.min(NY - 1, cy)) | 0) * NX) + (Math.max(0, Math.min(NX - 1, cx)) | 0);
      glow[gk] = 1;
      thicken(p.x, p.y, 0.25, 0.04);
      SFX.play('move', 140);
    }
    last = p; lastT0 = now;
  });
  function up() { down = false; finger.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-swirl').addEventListener('input', function () { swirl = this.value / 100; });
  document.getElementById('k-drain').addEventListener('input', function () { drain = this.value / 100; });
  document.getElementById('k-still').addEventListener('click', function () {
    for (var k = 0; k < NX * NY; k++) { h[k] = base[(k / NX) | 0]; glow[k] = 0; }
    vort.length = 0;
    for (var i = 0; i < 7; i++) vort.push(newVortex(true));
    SFX.play('click');
  });
  swirl = document.getElementById('k-swirl').value / 100;
  drain = document.getElementById('k-drain').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    /* 膜が薄くなる: 同じ厚さの線が下へ下がっていく */
    phase = (phase + dt * (0.03 + drain * 0.25)) % 1;
    if (!finger.on) {
      finger.w *= Math.max(0, 1 - 2.5 * dt);
      finger.vx *= 1 - 3 * dt; finger.vy *= 1 - 3 * dt;
    } else {
      finger.vx *= 1 - 4 * dt; finger.vy *= 1 - 4 * dt;
      thicken(finger.x, finger.y, 0.6 * dt, 0.05);
    }
    advance(dt);
    shade();
    chime += dt;
    if (chime > 5) { chime = 0; if (Math.random() < 0.5) SFX.play('ripple', 900); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
