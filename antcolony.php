<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('antcolony');

$pageName = 'antcolony';
$pageTitle = 'ANTCOLONY - PULSE';
$pageDesc = '巣を出たアリが餌を見つけると、帰り道に匂いを残す。匂いの濃い道を通るアリが増えるので、巣と餌を結ぶ白い道が太くなっていく。タップすると餌が置かれ、ドラッグすると匂いの道を引ける。';
$pageCanonical = 'https://pulse.subara3.com/antcolony.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-ants">ANTS</label><input type="range" id="k-ants" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-fade">FADE</label><input type="range" id="k-fade" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W = 0, H = 0;

  /* 匂いは粗い格子に置き、拡大して描く。帰り道の匂い（餌へ）と、行き道の匂い（巣へ）の 2 枚 */
  var C = 3, GX = 1, GY = 1, toFood, toHome, img, buf, bctx;
  var MAXA = 1400, N = 0;
  var ax = new Float32Array(MAXA), ay = new Float32Array(MAXA), ang = new Float32Array(MAXA);
  var carry = new Uint8Array(MAXA), age = new Float32Array(MAXA);
  var nest = { x: 0, y: 0, r: 16 };
  var foods = [];
  var fade = 10;

  function alloc() {
    GX = Math.ceil(W / C); GY = Math.ceil(H / C);
    toFood = new Float32Array(GX * GY); toHome = new Float32Array(GX * GY);
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas'); buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
    nest.x = W * 0.5; nest.y = H * 0.54;
    foods = [];
    for (var i = 0; i < 3; i++) addFood(null, null, 260);
  }

  function addFood(x, y, amount) {
    if (x == null) {
      /* 巣から程よく離れた所に、ほかの餌と重ならないように置く */
      for (var tries = 0; tries < 30; tries++) {
        var a = Math.random() * Math.PI * 2, r = Math.min(W, H) * (0.26 + Math.random() * 0.2);
        x = nest.x + Math.cos(a) * r * (W / H > 1.2 ? 1.5 : 1); y = nest.y + Math.sin(a) * r;
        if (x < 40 || x > W - 40 || y < 40 || y > H - 40) continue;
        var ok = true;
        for (var k = 0; k < foods.length; k++) if (Math.hypot(foods[k].x - x, foods[k].y - y) < 160) ok = false;
        if (ok) break;
      }
    }
    var bits = [];
    for (var b = 0; b < 46; b++) { var aa = Math.random() * 6.283, rr = Math.sqrt(Math.random()) * 17; bits.push([Math.cos(aa) * rr, Math.sin(aa) * rr]); }
    foods.push({ x: x, y: y, n: amount, n0: amount, bits: bits, t: 0 });
  }

  function setCount(n) {
    n = Math.max(80, Math.min(MAXA, n | 0));
    for (var i = N; i < n; i++) {
      ax[i] = nest.x; ay[i] = nest.y; ang[i] = Math.random() * 6.283; carry[i] = 0; age[i] = Math.random() * 3;
    }
    N = n;
  }

  function sense(f, x, y) {
    var i = (x / C) | 0, j = (y / C) | 0;
    if (i < 0 || j < 0 || i >= GX || j >= GY) return -1;
    return f[j * GX + i];
  }

  function step(dt) {
    var SPEED = 46, SD = 15, SA = 0.55, TURN = 5;
    for (var i = 0; i < N; i++) {
      var x = ax[i], y = ay[i], a = ang[i];
      var f = carry[i] ? toHome : toFood;
      var l = sense(f, x + Math.cos(a - SA) * SD, y + Math.sin(a - SA) * SD);
      var c = sense(f, x + Math.cos(a) * SD, y + Math.sin(a) * SD);
      var r = sense(f, x + Math.cos(a + SA) * SD, y + Math.sin(a + SA) * SD);
      if (c < l || c < r) a += (l > r ? -1 : 1) * TURN * dt;
      a += (Math.random() - 0.5) * 3.2 * dt;
      /* 近くまで来たら、巣や餌そのものへ向かう */
      if (carry[i]) {
        var nd = Math.hypot(nest.x - x, nest.y - y);
        if (nd < 60) a = steerTo(a, Math.atan2(nest.y - y, nest.x - x), dt * 6);
        if (nd < nest.r) { carry[i] = 0; age[i] = 0; a += Math.PI; }
      } else {
        for (var k = 0; k < foods.length; k++) {
          var fd = foods[k];
          if (fd.n <= 0) continue;
          var d = Math.hypot(fd.x - x, fd.y - y);
          if (d < 45) a = steerTo(a, Math.atan2(fd.y - y, fd.x - x), dt * 6);
          if (d < 10) { fd.n--; carry[i] = 1; age[i] = 0; a += Math.PI; break; }
        }
      }
      /* 画面の縁に沿って歩き続けないよう、縁が近いと内へ向きを変える */
      var m = 36;
      if (x < m || x > W - m || y < m || y > H - m) a = steerTo(a, Math.atan2(H / 2 - y, W / 2 - x), dt * 2.5);
      x += Math.cos(a) * SPEED * dt; y += Math.sin(a) * SPEED * dt;
      if (x < 2 || x > W - 2) { a = Math.PI - a; x = Math.max(2, Math.min(W - 2, x)); }
      if (y < 2 || y > H - 2) { a = -a; y = Math.max(2, Math.min(H - 2, y)); }
      ax[i] = x; ay[i] = y; ang[i] = a;
      /* 出てきた場所から離れるほど、置く匂いは薄くなる */
      age[i] += dt;
      var gi = (x / C) | 0, gj = (y / C) | 0;
      if (gi >= 0 && gj >= 0 && gi < GX && gj < GY) {
        var amt = Math.exp(-age[i] / 9) * dt * 6;
        var g = gj * GX + gi;
        if (carry[i]) toFood[g] = Math.min(4, toFood[g] + amt);
        else toHome[g] = Math.min(4, toHome[g] + amt);
      }
    }
    /* 匂いは少しずつ薄れ、にじむ */
    blurAcc += dt;
    if (++blurTick % 2 === 0) {
      var ev = Math.exp(-blurAcc / fade);
      blur(toFood, ev); blur(toHome, ev);
      blurAcc = 0;
    }
  }
  var blurTick = 0, blurAcc = 0;
  function steerTo(a, b, k) {
    var d = Math.atan2(Math.sin(b - a), Math.cos(b - a));
    return a + d * Math.min(1, k);
  }
  var tmp = null;
  function blur(f, ev) {
    if (!tmp || tmp.length !== f.length) tmp = new Float32Array(f.length);
    for (var j = 1; j < GY - 1; j++) {
      var row = j * GX;
      for (var i = 1; i < GX - 1; i++) {
        var k = row + i;
        tmp[k] = (f[k] * 0.952 + (f[k - 1] + f[k + 1] + f[k - GX] + f[k + GX]) * 0.012) * ev;
      }
    }
    f.set(tmp);
  }

  var BG = P.bg, ST = P.steel, LN = P.line, DEEP = P.deep;
  function shade() {
    var d = img.data;
    for (var k = 0, n = GX * GY; k < n; k++) {
      var f0 = toFood[k], h0 = toHome[k];
      var fv = f0 / (f0 + 1.6), hv = h0 / (h0 + 1.4);
      /* 巣への匂いは鋼色の薄い霞、餌への匂いは白い道 */
      var r = BG[0] + (ST[0] - BG[0]) * hv * 0.55, g = BG[1] + (ST[1] - BG[1]) * hv * 0.55, b = BG[2] + (ST[2] - BG[2]) * hv * 0.55;
      var t = fv * 0.85;
      r += (LN[0] - r) * t; g += (LN[1] - g) * t; b += (LN[2] - b) * t;
      var o = k * 4;
      d[o] = r; d[o + 1] = g; d[o + 2] = b; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  var marks = [];
  function draw(dt) {
    shade();
    P.grid(ctx, W, H, { step: 48, major: 4, alpha: 0.45 });
    /* 巣 */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(LN, 0.7);
    ctx.beginPath(); ctx.arc(nest.x, nest.y, nest.r, 0, Math.PI * 2); ctx.stroke();
    ctx.strokeStyle = P.rgba(LN, 0.25);
    ctx.beginPath(); ctx.arc(nest.x, nest.y, nest.r + 6, 0, Math.PI * 2); ctx.stroke();
    /* 餌: 残りの量だけ粒が見える */
    for (var k = foods.length - 1; k >= 0; k--) {
      var fd = foods[k];
      fd.t += dt;
      var show = Math.ceil(fd.bits.length * Math.max(0, fd.n) / fd.n0);
      if (fd.n <= 0) { foods.splice(k, 1); continue; }
      ctx.fillStyle = P.rgba(LN, 0.9);
      ctx.beginPath();
      for (var b = 0; b < show; b++) { var bx = fd.x + fd.bits[b][0], by = fd.y + fd.bits[b][1]; ctx.moveTo(bx + 1.9, by); ctx.arc(bx, by, 1.9, 0, 6.283); }
      ctx.fill();
      if (fd.mine && fd.t < 2.5) {
        ctx.strokeStyle = P.rgba(P.accent, 0.6 * (1 - fd.t / 2.5));
        ctx.beginPath(); ctx.arc(fd.x, fd.y, 16 + fd.t * 10, 0, Math.PI * 2); ctx.stroke();
      }
    }
    /* アリ: 探しているものは鋼色、餌を運ぶものは白 */
    for (var pass = 0; pass < 2; pass++) {
      ctx.fillStyle = pass ? P.rgba(LN, 1) : P.rgba(P.mix(ST, LN, 0.5), 0.85);
      ctx.beginPath();
      for (var i = 0; i < N; i++) {
        if (carry[i] !== pass) continue;
        var cx = Math.cos(ang[i]), sy = Math.sin(ang[i]);
        ctx.moveTo(ax[i] + cx * 3, ay[i] + sy * 3);
        ctx.lineTo(ax[i] - cx * 3 - sy * 1.6, ay[i] - sy * 3 + cx * 1.6);
        ctx.lineTo(ax[i] - cx * 3 + sy * 1.6, ay[i] - sy * 3 - cx * 1.6);
      }
      ctx.fill();
    }
    /* 指でなぞった跡 */
    ctx.lineWidth = 1;
    for (var m = marks.length - 1; m >= 0; m--) {
      var mk = marks[m];
      mk.t += dt;
      var al = 1 - mk.t / 1.2;
      if (al <= 0) { marks.splice(m, 1); continue; }
      ctx.fillStyle = P.rgba(P.accent, al * 0.6);
      ctx.beginPath(); ctx.arc(mk.x, mk.y, 2, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, moved = 0;
  function lay(x, y) {
    var gi = (x / C) | 0, gj = (y / C) | 0;
    for (var j = gj - 2; j <= gj + 2; j++) for (var i = gi - 2; i <= gi + 2; i++) {
      if (i < 0 || j < 0 || i >= GX || j >= GY) continue;
      var k = j * GX + i; toFood[k] = Math.min(4, toFood[k] + 0.9); toHome[k] = Math.min(4, toHome[k] + 0.5);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; moved = 0;
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY }, d = Math.hypot(p.x - last.x, p.y - last.y);
    if (d < 4) return;
    moved += d;
    var n = Math.ceil(d / 4);
    for (var s = 1; s <= n; s++) lay(last.x + (p.x - last.x) * s / n, last.y + (p.y - last.y) * s / n);
    marks.push({ x: p.x, y: p.y, t: 0 });
    last = p;
    SFX.play('move', 160);
  });
  function up(e) {
    if (!down) return;
    down = false;
    /* なぞらずに離したら、そこに餌を置く */
    if (moved < 12 && e.type === 'pointerup') {
      if (foods.length > 7) foods.shift();
      addFood(last.x, last.y, 220);
      foods[foods.length - 1].mine = true;
      /* 置いたところから匂いが立ち、近くのアリがすぐ向きを変えて集まる */
      var gi0 = (last.x / C) | 0, gj0 = (last.y / C) | 0, RR = 20;
      for (var j = gj0 - RR; j <= gj0 + RR; j++) for (var i = gi0 - RR; i <= gi0 + RR; i++) {
        if (i < 0 || j < 0 || i >= GX || j >= GY) continue;
        var q = 1 - Math.hypot(i - gi0, j - gj0) / RR; if (q <= 0) continue;
        toFood[j * GX + i] = Math.min(4, toFood[j * GX + i] + Math.sqrt(q) * 4);
      }
      for (var k = 0; k < N; k++) {
        if (carry[k]) continue;
        var dd = Math.hypot(ax[k] - last.x, ay[k] - last.y);
        if (dd < 220) ang[k] = Math.atan2(last.y - ay[k], last.x - ax[k]);
      }
      SFX.play('spawn');
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function antsFrom(v) { return 300 + v / 100 * 1100; }
  function fadeFrom(v) { return 22 - v / 100 * 18; }
  document.getElementById('k-ants').addEventListener('input', function () {
    var n = antsFrom(+this.value) | 0;
    if (n > N) setCount(n); else N = n;
  });
  document.getElementById('k-fade').addEventListener('input', function () { fade = fadeFrom(+this.value); });
  document.getElementById('k-clear').addEventListener('click', function () {
    toFood.fill(0); toHome.fill(0);
    SFX.play('click');
  });
  fade = fadeFrom(+document.getElementById('k-fade').value);

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); N = 0; setCount(antsFrom(+document.getElementById('k-ants').value)); });
  /* 開いたときにはもう道ができ始めているよう、少し先まで進めておく */
  for (var w0 = 0; w0 < 420; w0++) step(1 / 30);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), foodWait = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    /* 餌がなくなったら、しばらくして別の場所に新しい餌が出る */
    if (foods.length < 3) {
      foodWait += dt;
      if (foodWait > 4) { addFood(null, null, 260); foodWait = 0; SFX.play('tick', 4000); }
    }
    step(dt);
    draw(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
