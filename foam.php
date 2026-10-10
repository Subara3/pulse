<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('foam');

$pageName = 'foam';
$pageTitle = 'FOAM - PULSE';
$pageDesc = '泡が押し合い、境目の膜が少しずつ曲がっていく。辺の少ない小さな泡から縮んで消え、ときどき新しい小さな泡がまとまって出てくる。触ったところに小さな泡が増える。';
$pageCanonical = 'https://pulse.subara3.com/foam.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-froth">FROTH</label><input type="range" id="k-froth" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-fresh">FRESH</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 泡 i は中心 c と重み w を持ち、点 x は |x − c| − w がいちばん小さい泡に属する。
     重みの差が境目を曲げ、小さい泡は外へふくらんだ膜で囲まれる */
  var S = 2.5, NX, NY, LAB, GAP, img, buf, bctx;
  var MAXB = 320, n = 0;
  var bx = new Float32Array(MAXB), by = new Float32Array(MAXB), bw = new Float32Array(MAXB);
  var vx = new Float32Array(MAXB), vy = new Float32Array(MAXB), vw = new Float32Array(MAXB);
  var tint = new Float32Array(MAXB), born = new Float32Array(MAXB);
  var area = new Float32Array(MAXB), sx = new Float32Array(MAXB), sy = new Float32Array(MAXB), sides = new Uint8Array(MAXB);
  var adj = new Uint8Array(MAXB * MAXB);
  var TS = 12, TX, TY, cand = [], TD = new Float32Array(MAXB);
  var pace = 0.4, froth = 0.45;

  function alloc() {
    NX = Math.ceil(W / S); NY = Math.ceil(H / S);
    LAB = new Int16Array(NX * NY);
    LAB2 = new Int16Array(NX * NY);
    GAP = new Float32Array(NX * NY);
    PB = new Float32Array(NX * NY);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    TX = Math.ceil(NX / TS); TY = Math.ceil(NY / TS);
    cand = new Array(TX * TY);
    fresh();
  }
  var PB, LAB2;
  var fade = new Float32Array(MAXB);

  function add(x, y, w, t) {
    if (n >= MAXB) return -1;
    bx[n] = x; by[n] = y; bw[n] = w; vx[n] = vy[n] = vw[n] = 0;
    tint[n] = t || 0; born[n] = 1; fade[n] = 1;
    return n++;
  }
  function remove(i) {
    n--;
    bx[i] = bx[n]; by[i] = by[n]; bw[i] = bw[n]; vx[i] = vx[n]; vy[i] = vy[n]; vw[i] = vw[n];
    tint[i] = tint[n]; born[i] = born[n]; fade[i] = fade[n];
  }
  function fresh() {
    n = 0;
    var count = Math.round(NX * NY / 700);
    for (var i = 0; i < count; i++) add(Math.random() * NX, Math.random() * NY, Math.random() * 5, 0);
    for (i = 0; i < n; i++) born[i] = 0;
  }
  /* 点 (x, y) でいちばん小さいスコア */
  function bestScore(x, y) {
    var b = 1e9;
    for (var i = 0; i < n; i++) {
      var s = Math.hypot(x - bx[i], y - by[i]) - bw[i];
      if (s < b) b = s;
    }
    return b;
  }
  /* 半径 rho（格子単位）ほどの泡の群れを吹きこむ */
  function blow(x, y, count, spread, rmin, rmax, t) {
    for (var k = 0; k < count; k++) {
      var a = Math.random() * Math.PI * 2, d = Math.sqrt(Math.random()) * spread;
      var px = x + Math.cos(a) * d, py = y + Math.sin(a) * d;
      if (px < 1 || py < 1 || px > NX - 1 || py > NY - 1) continue;
      var rho = rmin + Math.random() * (rmax - rmin);
      add(px, py, rho - bestScore(px, py), t);
    }
  }

  /* ---------------------------------------------------------- 塗り分け */
  function candidates() {
    var hd = TS * 0.7072;
    for (var ty = 0; ty < TY; ty++) for (var tx = 0; tx < TX; tx++) {
      var cx = (tx + 0.5) * TS, cy = (ty + 0.5) * TS, up = 1e9, i, d;
      for (i = 0; i < n; i++) {
        var ddx = cx - bx[i], ddy = cy - by[i];
        d = Math.sqrt(ddx * ddx + ddy * ddy) - bw[i];
        TD[i] = d;
        if (d < up) up = d;
      }
      var list = [], lim = up + hd * 2 + 8;
      for (i = 0; i < n; i++) if (TD[i] <= lim) list.push(i);
      cand[ty * TX + tx] = list;
    }
  }
  function label() {
    candidates();
    for (var j = 0; j < NY; j++) {
      var ty = (j / TS) | 0;
      for (var i = 0; i < NX; i++) {
        var list = cand[ty * TX + ((i / TS) | 0)];
        var s1 = 1e9, s2 = 1e9, s3 = 1e9, l1 = 0, l2 = 0;
        for (var q = 0; q < list.length; q++) {
          var b = list[q], dx = i - bx[b], dy = j - by[b];
          var s = Math.sqrt(dx * dx + dy * dy) - bw[b];
          if (s < s1) { s3 = s2; s2 = s1; s1 = s; l2 = l1; l1 = b; }
          else if (s < s2) { s3 = s2; s2 = s; l2 = b; }
          else if (s < s3) s3 = s;
        }
        var k = j * NX + i;
        LAB[k] = l1; LAB2[k] = l2; GAP[k] = s2 - s1; PB[k] = s3 - s1;
      }
    }
  }

  /* 面積・重心・隣の数を数え、泡の動きを決める */
  function measure(span) {
    var i, k;
    area.fill(0, 0, n); sx.fill(0, 0, n); sy.fill(0, 0, n);
    adj.fill(0, 0, n * MAXB);
    for (var j = 0; j < NY; j++) for (i = 0; i < NX; i++) {
      k = j * NX + i;
      var l = LAB[k];
      area[l]++; sx[l] += i; sy[l] += j;
      if (i < NX - 1) { var r = LAB[k + 1]; if (r !== l) { adj[l * MAXB + r] = 1; adj[r * MAXB + l] = 1; } }
      if (j < NY - 1) { var dn = LAB[k + NX]; if (dn !== l) { adj[l * MAXB + dn] = 1; adj[dn * MAXB + l] = 1; } }
    }
    for (i = 0; i < n; i++) {
      var c = 0, row = i * MAXB;
      for (k = 0; k < n; k++) c += adj[row + k];
      /* 画面の縁に触れている泡は、縁も 1 辺と数える */
      var cx = sx[i] / Math.max(1, area[i]), cy = sy[i] / Math.max(1, area[i]);
      var rr = Math.sqrt(area[i] / Math.PI);
      if (cx < rr || cy < rr || cx > NX - rr || cy > NY - rr) c += 1;
      sides[i] = c;
    }
    var rate = 0.35 + pace * 1.6;
    for (i = n - 1; i >= 0; i--) {
      if (area[i] < 9 && born[i] <= 0) { remove(i); popped++; continue; }
      /* 消えかけの泡は膜ごと薄れていく */
      fade[i] = born[i] > 0 ? 1 : Math.min(1, (area[i] - 9) / 32);
      if (area[i] < 1) continue;
      var gx = sx[i] / area[i], gy = sy[i] / area[i];
      /* 重心へ少し寄る（膜の張力で形が整う） */
      vx[i] = (gx - bx[i]) * 0.25 * rate; vy[i] = (gy - by[i]) * 0.25 * rate;
      /* 辺が 6 より少ない泡は縮み、多い泡は育つ */
      vw[i] = (sides[i] - 6) * 0.3 * rate;
    }
  }

  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel, AC = P.accent;
  function shade() {
    var d = img.data;
    for (var k = 0, len = NX * NY; k < len; k++) {
      var o = k * 4, g = GAP[k];
      var wall = 1 - g / 1.45; wall = wall < 0 ? 0 : wall;
      var node = 1 - PB[k] / 3.2; node = node < 0 ? 0 : node;
      /* 膜のすぐ内側をうっすら明るく。泡に厚みが出る */
      var film = Math.exp(-g * 0.12) * 0.3;
      var r = BG[0] + (ST[0] - BG[0]) * film, gg = BG[1] + (ST[1] - BG[1]) * film, b = BG[2] + (ST[2] - BG[2]) * film;
      var l = LAB[k], l2 = LAB2[k], tn = Math.max(tint[l], tint[l2] * 0.5);
      var a = Math.min(1, wall * 0.62 + node * 0.3) * Math.min(fade[l], fade[l2]);
      if (a > 0) {
        var lc0 = LN[0], lc1 = LN[1], lc2 = LN[2];
        if (tn > 0) { lc0 += (AC[0] - lc0) * tn; lc1 += (AC[1] - lc1) * tn; lc2 += (AC[2] - lc2) * tn; }
        r += (lc0 - r) * a; gg += (lc1 - gg) * a; b += (lc2 - b) * a;
      } else if (tn > 0) {
        var ti = tn * film * 0.8;
        r += (AC[0] - r) * ti; gg += (AC[1] - gg) * ti; b += (AC[2] - b) * ti;
      }
      d[o] = r; d[o + 1] = gg; d[o + 2] = b; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
  }
  function blit() {
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, NX * S, NY * S);
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, popped = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    blow(last.x / S, last.y / S, 9, 21, 4, 10, 1);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (Math.hypot(p.x - last.x, p.y - last.y) < 36) return;
    last = p;
    blow(p.x / S, p.y / S, 2, 7, 3, 7, 1);
    SFX.play('pop', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kp = document.getElementById('k-pace'), kf = document.getElementById('k-froth');
  kp.addEventListener('input', function () { pace = this.value / 100; });
  kf.addEventListener('input', function () { froth = this.value / 100; });
  pace = kp.value / 100; froth = kf.value / 100;
  document.getElementById('k-fresh').addEventListener('click', function () { fresh(); SFX.play('click'); });

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), tick = 0, frothT = 4;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    for (var i = 0; i < n; i++) {
      bx[i] += vx[i] * dt; by[i] += vy[i] * dt; bw[i] += vw[i] * dt;
      if (tint[i] > 0) tint[i] = Math.max(0, tint[i] - dt * 0.35);
      if (born[i] > 0) born[i] -= dt;
      if (bx[i] < 0) bx[i] = 0; if (by[i] < 0) by[i] = 0;
      if (bx[i] > NX) bx[i] = NX; if (by[i] > NY) by[i] = NY;
    }
    /* 泡が減ってきたら、どこかに新しい泡の群れが湧く */
    frothT -= dt;
    var floor = NX * NY / (2400 - froth * 1600);
    if (frothT <= 0 && n < floor) {
      frothT = 3 + Math.random() * 3;
      blow(NX * (0.15 + Math.random() * 0.7), NY * (0.15 + Math.random() * 0.7), 10 + Math.round(froth * 14), 19 + froth * 16, 3, 9, 0);
      SFX.play('grow', 3000);
    }
    tick++;
    if (tick % 2 === 0) {
      label();
      if (tick % 6 === 0) {
        measure();
        /* 重みは差だけが効く。平均を引いて数値が大きくならないようにする */
        var mw = 0;
        for (i = 0; i < n; i++) mw += bw[i];
        mw /= Math.max(1, n);
        for (i = 0; i < n; i++) bw[i] -= mw;
        if (popped) { SFX.play('pop', 1500); popped = 0; }
      }
      shade();
    }
    blit();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
