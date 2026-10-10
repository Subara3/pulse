<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fractal');

$pageName = 'fractal';
$pageTitle = 'FRACTAL - PULSE';
$pageDesc = '黒い図形の縁にある細かな枝模様に向かって、画面がゆっくり拡大し続ける。触った点のほうへ拡大の中心が移る（マンデルブロ集合）。';
$pageCanonical = 'https://pulse.subara3.com/fractal.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="lines">LINES</button><button type="button" data-look="glow">GLOW</button></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, sized = false;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; if (sized) restart(true); sized = true; });

  /*
   * 見せ方: 画面は「鍵画像」を拡大して描き、その間に 1.5 倍先の鍵画像を少しずつ計算しておく。
   * 拡大の中心（錨）は複素平面上の 1 点に固定し、画面上の位置は潜るにつれ中央へ寄せる。
   * 線は縁までの距離（距離推定）で引くので、どの深さでも同じ細さで縁が光る。
   */
  var KS = 0.75;          // 鍵画像の解像度（CSS px あたり）
  var F = 1.5;            // 鍵画像ごとの拡大率
  var MG = 0.09;          // 鍵画像の余白（画面幅に対する割合）
  var look = 'lines';
  var rate = 0.11;        // 1 秒あたりの log 拡大量

  var SPOTS = [
    [-0.743643887037151, 0.131825904205330],
    [0.3602404434376143, -0.6413130610648032],
    [-0.1010963638456221, 0.9562865108091415],
    [-1.5436890126920764, 0.0000000000000001],
    [0.0, 1.0],
    [-0.7746806106269039, -0.1374168856037867],
    [-0.235125, 0.827215]
  ];
  var spotIdx = Math.floor(Math.random() * SPOTS.length);

  /* 濃淡表（藍→鋼→白）を 256 段で持っておく */
  var LUT = new Uint8Array(256 * 3);
  for (var i = 0; i < 256; i++) {
    var c = P.ramp(i / 255);
    LUT[i * 3] = c[0]; LUT[i * 3 + 1] = c[1]; LUT[i * 3 + 2] = c[2];
  }
  var DEEP = P.deep;

  /* ---------------------------------------------------------- 旅（錨と拡大） */
  var J = null;           // { ax, ay, sx, sy, ub, u0, u }
  function S(u) {
    var k = Math.pow(u / J.ub, 0.5);
    return [W / 2 + (J.sx - W / 2) * k, H / 2 + (J.sy - H / 2) * k];
  }
  function viewAt(u) {
    var s = S(u);
    return { x0: J.ax - s[0] * u, y0: J.ay + s[1] * u, u: u };
  }
  function newJourney() {
    var sp = SPOTS[spotIdx % SPOTS.length]; spotIdx++;
    var u0 = Math.max(3.3 / W, 2.5 / H);
    var cx = -0.65, cy = 0;
    var x0 = cx - W / 2 * u0, y0 = cy + H / 2 * u0;
    return { ax: sp[0], ay: sp[1], sx: (sp[0] - x0) / u0, sy: (y0 - sp[1]) / u0, ub: u0, u0: u0, u: u0 };
  }

  /* ---------------------------------------------------------- 鍵画像 */
  function makeKey(v) {
    var mw = W * MG, mh = H * MG;
    var cw = W + mw * 2, ch = H + mh * 2;
    var pw = Math.ceil(cw * KS), ph = Math.ceil(ch * KS);
    var depth = Math.max(0, Math.log2(J.u0 / v.u));
    var cv = document.createElement('canvas');
    cv.width = pw; cv.height = ph;
    return {
      x0: v.x0 - mw * v.u, y0: v.y0 + mh * v.u, u: v.u, cw: cw, ch: ch,
      pw: pw, ph: ph, pu: v.u / KS, row: 0, done: false,
      maxIt: Math.min(4000, Math.round(180 + depth * 55)),
      band: 5 + depth * 0.25,
      nu: new Float32Array(pw * ph), de: new Float32Array(pw * ph),
      img: new ImageData(pw, ph), cv: cv, cx: cv.getContext('2d')
    };
  }

  function computeRows(K, budget) {
    var t0 = performance.now();
    var pw = K.pw, pu = K.pu, maxIt = K.maxIt, nuA = K.nu, deA = K.de, upx = K.u;
    while (K.row < K.ph) {
      var j = K.row, ci = K.y0 - (j + 0.5) * pu, base = j * pw;
      for (var i = 0; i < pw; i++) {
        var cr = K.x0 + (i + 0.5) * pu;
        var q = (cr - 0.25) * (cr - 0.25) + ci * ci;
        if (q * (q + (cr - 0.25)) < 0.25 * ci * ci || (cr + 1) * (cr + 1) + ci * ci < 0.0625) {
          nuA[base + i] = -1; continue;
        }
        var zr = 0, zi = 0, dr = 0, di = 0, zr2 = 0, zi2 = 0, n = 0;
        var pr = 0, pi = 0, per = 0, plen = 8, inside = false;
        for (; n < maxIt; n++) {
          var ndr = 2 * (zr * dr - zi * di) + 1;
          di = 2 * (zr * di + zi * dr); dr = ndr;
          zi = 2 * zr * zi + ci; zr = zr2 - zi2 + cr;
          zr2 = zr * zr; zi2 = zi * zi;
          if (zr2 + zi2 > 1e8) break;
          if (Math.abs(zr - pr) + Math.abs(zi - pi) < 1e-15) { inside = true; break; }
          if (++per === plen) { per = 0; plen += plen; pr = zr; pi = zi; }
        }
        if (inside || n >= maxIt) { nuA[base + i] = -1; continue; }
        var m2 = zr2 + zi2, lz = 0.5 * Math.log(m2);
        nuA[base + i] = Math.max(1, n + 1 - Math.log(lz / Math.LN2) / Math.LN2);
        var dz = Math.sqrt(dr * dr + di * di) || 1e-30;
        deA[base + i] = Math.sqrt(m2) * lz / dz / upx;   // 縁までの距離（CSS px）
      }
      K.row++;
      if (performance.now() - t0 > budget) break;
    }
    if (K.row >= K.ph) { colorize(K); K.done = true; }
  }

  function colorize(K) {
    var d = K.img.data, n = K.pw * K.ph, band = K.band, lines = look === 'lines';
    var ext = 0, bright = 0;
    for (var k = 0; k < n; k++) {
      var o = k * 4, nu = K.nu[k];
      if (nu < 0) { d[o] = DEEP[0]; d[o + 1] = DEEP[1]; d[o + 2] = DEEP[2]; d[o + 3] = 255; continue; }
      ext++;
      var px = K.de[k];
      var a = 0.9 * Math.exp(-px * 1.25) + 0.2 * Math.exp(-px * 0.05);
      if (lines) {
        var f = Math.log(nu) * band; f -= Math.floor(f);
        var e = f < 0.5 ? f : 1 - f;
        a += Math.max(0, 1 - e * 12) * 0.16 * Math.exp(-px * 0.004);
      } else {
        a += 0.14 * Math.exp(-px * 0.012);
      }
      if (a > 1) a = 1;
      if (a > 0.45) bright++;
      var li = (a * 255 | 0) * 3;
      d[o] = LUT[li]; d[o + 1] = LUT[li + 1]; d[o + 2] = LUT[li + 2]; d[o + 3] = 255;
    }
    K.cx.putImageData(K.img, 0, 0);
    K.boring = ext < n * 0.04 || bright < n * 0.0015;
  }

  function drawKey(K, v, alpha) {
    if (!K || !K.done) return;
    var m = K.u / v.u;
    ctx.globalAlpha = alpha;
    ctx.drawImage(K.cv, (K.x0 - v.x0) / v.u, (v.y0 - K.y0) / v.u, K.cw * m, K.ch * m);
    ctx.globalAlpha = 1;
  }

  /* ---------------------------------------------------------- 状態 */
  var cur = null, pend = null, fadeOld = null, fadeT = 0;
  var oldJ = null, oldView = null, nextJ = null, nextKey = null, jfade = 0;
  var marks = [];
  var boost = 0;

  function restart(sync) {
    J = newJourney();
    cur = makeKey(viewAt(J.u));
    computeRows(cur, sync ? 1e9 : 8);
    pend = makeKey(viewAt(J.u / F));
    fadeOld = null; oldJ = null; nextJ = null; nextKey = null;
  }

  /* 次の旅: 新しい全景を裏で用意し、できたら数秒かけて重ねて入れ替える */
  function beginNext() {
    if (nextJ) return;
    var keepJ = J;
    nextJ = newJourney();
    J = nextJ; nextKey = makeKey(viewAt(nextJ.u)); J = keepJ;
  }

  /* ---------------------------------------------------------- 操作 */
  function pointAt(x, y) {
    /* 触れた近くで、いちばん縁に近い外側の点を拾う（真っ黒や空っぽへ潜らないように） */
    var v = viewAt(J.u), K = cur;
    var bx = x, by = y, best = -1;
    if (K && K.done) {
      var R = 34;
      for (var dy = -R; dy <= R; dy += 2) {
        for (var dx = -R; dx <= R; dx += 2) {
          if (dx * dx + dy * dy > R * R) continue;
          var wx = v.x0 + (x + dx) * v.u, wy = v.y0 - (y + dy) * v.u;
          var i = Math.floor((wx - K.x0) / K.pu), j = Math.floor((K.y0 - wy) / K.pu);
          if (i < 0 || j < 0 || i >= K.pw || j >= K.ph) continue;
          var nu = K.nu[j * K.pw + i];
          if (nu < 0) continue;
          var sc = -K.de[j * K.pw + i] - Math.hypot(dx, dy) * 0.04;
          if (best < 0 || sc > bestS) { var bestS = sc; best = 1; bx = x + dx; by = y + dy; }
        }
      }
    }
    return [bx, by, v];
  }
  canvas.addEventListener('pointerdown', function (e) {
    if (nextJ && jfade > 0) return;
    var r = pointAt(e.clientX, e.clientY), v = r[2];
    J.ax = v.x0 + r[0] * v.u; J.ay = v.y0 - r[1] * v.u;
    J.sx = r[0]; J.sy = r[1]; J.ub = J.u;
    pend = makeKey(viewAt(J.u / F));
    boost = 1;
    marks.push({ t: 0 });
    if (marks.length > 1) marks.shift();
    SFX.play('ripple', 300);
  });

  document.getElementById('k-speed').addEventListener('input', function () { rate = 0.04 + this.value / 100 * 0.18; });
  rate = 0.04 + document.getElementById('k-speed').value / 100 * 0.18;
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      [cur, pend, nextKey].forEach(function (K) { if (K && K.done) colorize(K); });
      SFX.play('toggle');
    });
  });
  document.getElementById('k-next').addEventListener('click', function () { beginNext(); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  restart(true);
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;

    if (nextJ) {
      if (!nextKey.done) { var kj = J; J = nextJ; computeRows(nextKey, 5); J = kj; }
      else jfade += dt / 3.5;
    } else if (!pend.done) {
      computeRows(pend, 5);
    }

    /* 拡大: 先の鍵画像の計算が遅れていたら、それに合わせて緩める */
    boost = Math.max(0, boost - dt * 0.35);
    var spd = rate * (1 + boost * 1.6);
    var prog = Math.log(cur.u / J.u) / Math.log(F);
    var rp = pend.done ? 1 : pend.row / pend.ph;
    var mult = Math.max(0, Math.min(1, 1 - (prog - rp) * 3));
    if (nextJ) mult = 0.35;
    J.u = Math.max(pend.u, J.u * Math.exp(-spd * mult * dt));
    if (!nextJ && pend.done && J.u <= pend.u * 1.0000001) {
      fadeOld = cur; fadeT = 1;
      cur = pend;
      if (cur.boring || J.u < J.u0 * 2e-9) beginNext();
      pend = makeKey(viewAt(J.u / F));
    }

    var v = viewAt(J.u);
    P.fill(ctx, W, H);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    if (fadeOld) {
      drawKey(cur, v, 1);
      fadeT -= dt / 0.6;
      if (fadeT > 0) drawKey(fadeOld, v, fadeT); else fadeOld = null;
    } else {
      drawKey(cur, v, 1);
    }

    /* 錨: 触ったところに細いシアンの輪。中央へ寄っていくのが見える */
    for (var n = marks.length - 1; n >= 0; n--) {
      var mk = marks[n];
      mk.t += dt;
      var a = mk.t < 0.3 ? mk.t / 0.3 : Math.max(0, 1 - (mk.t - 0.3) / 3.2);
      if (a <= 0 || nextJ) { marks.splice(n, 1); continue; }
      var s = S(J.u);
      ctx.strokeStyle = P.rgba(P.accent, a * 0.7);
      ctx.lineWidth = 1.3;
      ctx.beginPath(); ctx.arc(s[0], s[1], 16 + mk.t * 6, 0, Math.PI * 2); ctx.stroke();
    }

    /* 次の旅の全景を重ねていく */
    if (nextJ && nextKey.done) {
      var kj2 = J; J = nextJ; var nv = viewAt(J.u); J = kj2;
      var fa = Math.min(1, jfade);
      drawKey(nextKey, nv, fa * fa * (3 - 2 * fa));
      if (jfade >= 1) {
        J = nextJ; cur = nextKey; nextJ = null; nextKey = null; jfade = 0; fadeOld = null;
        pend = makeKey(viewAt(J.u / F));
        SFX.play('grow', 2000);
      }
    }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
