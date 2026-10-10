<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystal');

$pageName = 'crystal';
$pageTitle = 'CRYSTAL - PULSE';
$pageDesc = '画面の中央で雪の結晶が六つの方向へ育ち、育ちきると消えて次の結晶が育ちはじめる。押すとそこに小さな結晶がすぐにでき、なぞるとそちら側の枝がよく伸びる。';
$pageCanonical = 'https://pulse.subara3.com/crystal.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" class="active" data-form="cycle">CYCLE</button><button type="button" data-form="fern">FERN</button><button type="button" data-form="plate">PLATE</button></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /* 雪の結晶の模型（六角格子のマスに水蒸気がたまり、1 を超えると凍る） */
  var PRESETS = {
    fern:  [{ a: 1, b: 0.40, g: 0.001 }, { a: 1, b: 0.35, g: 0.0015 }],
    plate: [{ a: 1, b: 0.50, g: 0.02 }, { a: 1, b: 0.62, g: 0.012 }]
  };
  var CYCLE = [PRESETS.fern[0], PRESETS.plate[0], PRESETS.fern[1], PRESETS.plate[1]];
  var form = 'cycle', cycleN = 0, prm = CYCLE[0];

  var NX, NY, cw, rh, N, s, s2, u, fr, fa, rec, wet, img, buf, bctx;
  var bx0, bx1, by0, by1;           /* 凍ったマスの外接枠 */
  var cx, cy, maxr = 0, rmax = 1, tGrow = 0, hold = 0, fade = 0, pace = 0.45;
  var nSteps = 0, fcount = 0;

  function alloc() {
    cw = Math.max(3.5, Math.min(W, H) / 150);
    rh = cw * Math.sqrt(3) / 2;
    NX = Math.ceil(W / cw) + 2;
    NY = Math.ceil(H / rh) + 2;
    N = NX * NY;
    s = new Float32Array(N); s2 = new Float32Array(N); u = new Float32Array(N);
    fr = new Uint8Array(N); fa = new Float32Array(N); rec = new Uint8Array(N); wet = new Float32Array(N);
    img = new ImageData(NX * 2, NY);
    buf = document.createElement('canvas');
    buf.width = NX * 2; buf.height = NY;
    bctx = buf.getContext('2d');
    start(true);
  }

  function pickPreset() {
    if (form === 'cycle') { prm = CYCLE[cycleN % CYCLE.length]; cycleN++; }
    else { var l = PRESETS[form]; prm = l[(Math.random() * l.length) | 0]; }
  }

  function start(pre) {
    pickPreset();
    s.fill(prm.b); s2.fill(prm.b); u.fill(prm.b);
    fr.fill(0); fa.fill(0); rec.fill(0); wet.fill(0);
    cx = (NX / 2) | 0; cy = (NY / 2) | 0;
    bx0 = bx1 = cx; by0 = by1 = cy;
    maxr = 0; tGrow = 0; hold = 0; fade = 0; nSteps = 0;
    rmax = Math.min(NX * 0.5, NY * rh / cw * 0.5) * 0.86;
    /* 中心は小さな六角の板から始める。角から枝が伸びる */
    hexPlate(cx, cy, pre ? 9 : 4);
    /* 開いた瞬間から形が見えるように、先に育てておく */
    tGrow = pre ? 26 : 6;
    var t0 = performance.now();
    while (maxr < target() && performance.now() - t0 < (pre ? 600 : 160)) step();
  }

  function hexPlate(i0, j0, rad) {
    for (var j = j0 - rad - 1; j <= j0 + rad + 1; j++) {
      for (var i = i0 - rad - 2; i <= i0 + rad + 2; i++) {
        var dx = i - i0 + ((j & 1) - (j0 & 1)) * 0.5, dy = (j - j0) * 0.866;
        /* 六角形の内側か（向かい合う辺の 3 組で判定） */
        var a1 = Math.abs(dy), a2 = Math.abs(dy * 0.5 + dx * 0.866), a3 = Math.abs(dy * 0.5 - dx * 0.866);
        if (Math.max(a1, a2, a3) <= rad * 0.866) {
          seed(i, j);
          var dd = Math.sqrt(dx * dx + dy * dy);
          if (dd > maxr) maxr = dd;
        }
      }
    }
  }
  /* 押したところに、六方向へ腕を出した小さな結晶をすぐに置く */
  function star(i0, j0, len) {
    for (var j = j0 - len; j <= j0 + len; j++) {
      for (var i = i0 - len - 1; i <= i0 + len + 1; i++) {
        if (i < 2 || j < 2 || i >= NX - 2 || j >= NY - 2) continue;
        var dx = i - i0 + ((j & 1) - (j0 & 1)) * 0.5, dy = (j - j0) * 0.866, r = Math.sqrt(dx * dx + dy * dy);
        if (r > len) continue;
        var th = Math.atan2(dy, dx), off = Math.abs(Math.sin(th * 3)) * r;
        if (off < 1.3 - r * 0.04 || r < 2) seed(i, j);
      }
    }
  }
  function seed(i, j) {
    if (i < 2 || j < 2 || i >= NX - 2 || j >= NY - 2) return;
    var k = j * NX + i;
    if (fr[k]) return;
    s[k] = 1; fr[k] = 1; fa[k] = maxr;
    if (i < bx0) bx0 = i; if (i > bx1) bx1 = i;
    if (j < by0) by0 = j; if (j > by1) by1 = j;
  }

  function target() { return rmax * Math.pow(Math.min(1, tGrow / (56 - pace * 36)), 0.85); }

  function step() {
    var M = 34, a12 = prm.a / 12, gam = prm.g;
    nSteps++;
    var I0 = Math.max(1, bx0 - M), I1 = Math.min(NX - 2, bx1 + M);
    var J0 = Math.max(1, by0 - M), J1 = Math.min(NY - 2, by1 + M);
    var i, j, k, o;
    for (j = J0; j <= J1; j++) {
      o = j & 1;
      for (i = I0; i <= I1; i++) {
        k = j * NX + i;
        var r = fr[k] | fr[k - 1] | fr[k + 1] | fr[k - NX + o - 1] | fr[k - NX + o] | fr[k + NX + o - 1] | fr[k + NX + o];
        rec[k] = r; u[k] = r ? 0 : s[k];
      }
    }
    for (j = J0; j <= J1; j++) {
      o = j & 1;
      for (i = I0; i <= I1; i++) {
        k = j * NX + i;
        var sum = u[k - 1] + u[k + 1] + u[k - NX + o - 1] + u[k - NX + o] + u[k + NX + o - 1] + u[k + NX + o];
        var v = u[k] + a12 * (sum - 6 * u[k]);
        if (rec[k]) v += s[k] + gam;
        s2[k] = v;
      }
    }
    for (j = J0; j <= J1; j++) {
      for (i = I0; i <= I1; i++) {
        k = j * NX + i;
        s[k] = s2[k];
        if (!fr[k] && s[k] >= 1) {
          fr[k] = 1; fa[k] = maxr;
          if (i < bx0) bx0 = i; if (i > bx1) bx1 = i;
          if (j < by0) by0 = j; if (j > by1) by1 = j;
          var dx = i - cx + ((j & 1) - (cy & 1)) * 0.5, dy = (j - cy) * 0.866;
          var rr = Math.sqrt(dx * dx + dy * dy);
          if (rr > maxr && rr < rmax * 1.2) maxr = rr;
        }
      }
    }
  }

  /* 塗り: 凍ったマスは薄い鋼色、育った跡（凍った時刻の等高線）は白い細線、縁はいちばん明るく */
  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel, AC = P.accent;
  var FAR = P.mix(BG, ST, 0.07), ICE = P.mix(BG, ST, 0.42), RING = P.mix(ST, LN, 0.55);
  function shade() {
    var d = img.data, b = prm.b, vis = 1 - fade;
    var i, j, k, o, r, g, bl;
    for (j = 0; j < NY; j++) {
      o = j & 1;
      var edgeRow = j === 0 || j === NY - 1;
      for (i = 0; i < NX; i++) {
        k = j * NX + i;
        if (fr[k] && !edgeRow && i > 0 && i < NX - 1) {
          var n1 = k - 1, n2 = k + 1, n3 = k - NX + o - 1, n4 = k - NX + o, n5 = k + NX + o - 1, n6 = k + NX + o;
          var edge = !(fr[n1] & fr[n2] & fr[n3] & fr[n4] & fr[n5] & fr[n6]);
          var c;
          if (edge) c = LN;
          else {
            /* 早く凍ったところほど氷が厚く、明るい */
            var age = (s[k] - 1) / prm.g / (nSteps + 1);
            if (age > 1) age = 1; else if (age < 0) age = 0;
            age = Math.sqrt(age) * 0.85;
            c = [ICE[0] + (RING[0] - ICE[0]) * age, ICE[1] + (RING[1] - ICE[1]) * age, ICE[2] + (RING[2] - ICE[2]) * age];
          }
          r = BG[0] + (c[0] - BG[0]) * vis; g = BG[1] + (c[1] - BG[1]) * vis; bl = BG[2] + (c[2] - BG[2]) * vis;
          /* 触ったあたりの氷はしばらくシアンに光る */
          var wf = wet[k];
          if (wf > 0.004) { wf = Math.min(1, wf) * 0.7; r += (AC[0] - r) * wf; g += (AC[1] - g) * wf; bl += (AC[2] - bl) * wf; }
        } else {
          /* 水蒸気: 結晶のまわりは吸い取られて暗くなる。凍りかけのマスはほのかに光る */
          var sv = s[k], dep = 1 - sv / b;
          if (dep < 0) dep = 0; else if (dep > 1) dep = 1;
          dep *= vis;
          r = FAR[0] + (DEEP[0] - FAR[0]) * dep; g = FAR[1] + (DEEP[1] - FAR[1]) * dep; bl = FAR[2] + (DEEP[2] - FAR[2]) * dep;
          if (rec[k] && sv > 0.5) {
            var f = (sv - 0.5) * 2; f = f * f * 0.6 * vis;
            r += (LN[0] - r) * f; g += (LN[1] - g) * f; bl += (LN[2] - bl) * f;
          }
          var w = wet[k];
          if (w > 0.004) {
            var wa = Math.min(1, w) * 0.4;
            r += (AC[0] - r) * wa; g += (AC[1] - g) * wa; bl += (AC[2] - bl) * wa;
          }
        }
        var p = (j * NX * 2 + i * 2 + o) * 4;
        d[p] = r; d[p + 1] = g; d[p + 2] = bl; d[p + 3] = 255;
        if (i < NX - 1 || !o) { d[p + 4] = r; d[p + 5] = g; d[p + 6] = bl; d[p + 7] = 255; }
      }
      if (o) { var p0 = j * NX * 2 * 4; d[p0] = d[p0 + 4]; d[p0 + 1] = d[p0 + 5]; d[p0 + 2] = d[p0 + 6]; d[p0 + 3] = 255; }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, -cw * 0.5, -rh * 0.5, NX * cw, NY * rh);
    P.grid(ctx, W, H, { step: 40, major: 4, alpha: 0.45 });
  }

  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  function cellAt(x, y) {
    var j = Math.round(y / rh), i = Math.round(x / cw - 0.5 * (j & 1));
    return [i, j];
  }
  /* なぞったところに水蒸気を足す。そちら側の枝が太り、伸びが早くなる */
  function moisten(x, y, amt) {
    var c = cellAt(x, y), R = 11;
    for (var j = Math.max(1, c[1] - R); j <= Math.min(NY - 2, c[1] + R); j++) {
      for (var i = Math.max(1, c[0] - R); i <= Math.min(NX - 2, c[0] + R); i++) {
        var dx = i - c[0] + ((j & 1) - (c[1] & 1)) * 0.5, dy = (j - c[1]) * 0.866;
        var q = (dx * dx + dy * dy) / (R * R);
        if (q >= 1) continue;
        var k = j * NX + i, f = (1 - q) * (1 - q);
        wet[k] = Math.min(1.4, wet[k] + f * amt * 2.2);
        if (!fr[k]) s[k] = Math.min(0.97, s[k] + f * amt * prm.b * 0.9);
      }
    }
    if (c[0] - R < bx0 - 20 || c[0] + R > bx1 + 20 || c[1] - R < by0 - 20 || c[1] + R > by1 + 20) {
      /* 外接枠の外でも水蒸気が動けるように、計算範囲を少し広げる */
      bx0 = Math.max(2, Math.min(bx0, c[0] - R + 14)); bx1 = Math.min(NX - 3, Math.max(bx1, c[0] + R - 14));
      by0 = Math.max(2, Math.min(by0, c[1] - R + 14)); by1 = Math.min(NY - 3, Math.max(by1, c[1] + R - 14));
    }
  }
  var down = false, last = null, moved = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0;
    last = { x: e.clientX, y: e.clientY };
    moisten(last.x, last.y, 1.2);
    var c0 = cellAt(last.x, last.y);
    star(c0[0], c0[1], 14);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY }, dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 6) return;
    moved += dist;
    var n = Math.ceil(dist / 8);
    for (var i = 1; i <= n; i++) moisten(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, 0.22);
    last = p;
    SFX.play('move', 160);
  });
  function up() {
    down = false;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { down = false; });

  var kPace = document.getElementById('k-pace');
  kPace.addEventListener('input', function () { pace = this.value / 100; });
  document.querySelectorAll('[data-form]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-form]').forEach(function (o) { o.classList.toggle('active', o === b); });
      form = b.dataset.form;
      fade = 0.0001; hold = 99;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-new').addEventListener('click', function () {
    fade = 0.0001; hold = 99;
    SFX.play('click');
  });
  pace = kPace.value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    if (fade > 0) {
      fade += dt / 4.5;
      if (fade >= 1) start(false);
    } else {
      tGrow += dt;
      var t0 = performance.now(), want = target();
      /* 目安の大きさに届いているときも、ときどき 1 歩だけ進めて、置いた種がゆっくり育つようにする */
      var ns = 0, extra = (fcount++ % 4 === 0) && maxr < rmax ? 1 : 0;
      while ((maxr < want || ns < extra) && performance.now() - t0 < 6) { step(); ns++; }
      /* 枠の外へ伸びきるか、育ちきって少し眺めたら、溶かして次の結晶へ */
      var full = maxr >= rmax * 0.985 || bx0 < 4 || bx1 > NX - 5 || by0 < 4 || by1 > NY - 5;
      if (full || tGrow > 140) {
        hold += dt;
        if (hold > 14) { fade = 0.0001; SFX.play('pop', 3000); }
      }
      if (Math.random() < dt * 0.25) SFX.play('tick', 4000);
    }
    for (var k = 0; k < N; k++) if (wet[k] > 0) wet[k] = wet[k] > 0.004 ? wet[k] * (1 - dt * 0.45) : 0;
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
