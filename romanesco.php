<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('romanesco');

$pageName = 'romanesco';
$pageTitle = 'ROMANESCO - PULSE';
$pageDesc = '渦に並んだつぼみの1つ1つが、また同じ渦に並んだ小さなつぼみでできていて、画面はそのつぼみの1つへ回りながらゆっくり寄っていく。押すと指のまわりのつぼみがふくらんで水色に光り、なぞると指の跡に沿ってふくらみが並んで、しばらくすると元に戻る。';
$pageCanonical = 'https://pulse.subara3.com/romanesco.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-detail">DETAIL</label><input type="range" id="k-detail" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 頭（半径 1）の中のつぼみ j は、黄金角ずつ回りながら外から内へ指数的に小さくなる。
     つぼみ j の中身は頭全体を a_j = s_j e^{iβ_j} 倍して p_j へ置いたもの（完全な自己相似）。 */
  var GA = Math.PI * (3 - Math.sqrt(5));
  var B = 0.06, NB = 190;
  var PX = new Float32Array(NB), PY = new Float32Array(NB);   /* p_j */
  var AX = new Float32Array(NB), AY = new Float32Array(NB);   /* a_j */
  var SJ = new Float32Array(NB), LT = new Float32Array(NB);   /* 大きさ・明るさ（先端ほど明るい） */
  (function () {
    var R0 = 0.76, q = 1.0 * Math.sqrt(2 * B);
    var outer = R0 * (1 + q);
    for (var j = 0; j < NB; j++) {
      var r = R0 * Math.exp(-B * j) / outer, th = j * GA, s = q * r;
      PX[j] = r * Math.cos(th); PY[j] = r * Math.sin(th);
      AX[j] = s * Math.cos(th); AY[j] = s * Math.sin(th);
      SJ[j] = s;
      LT[j] = 0.35 + 0.65 * Math.pow(1 - r / (R0 / outer), 0.8);
    }
  })();

  /* 寄っていく先のつぼみ K。f(x) = a_K x + p_K の不動点 x* に向かって f^{-t} で寄ると、
     t = 1 で画面がちょうど t = 0 に戻る */
  var K = 13;
  var aK = Math.hypot(AX[K], AY[K]), angK = Math.atan2(AY[K], AX[K]);
  var lnK = Math.log(aK);
  var FX, FY;
  (function () {
    /* x* = p / (1 - a) */
    var dx = 1 - AX[K], dy = -AY[K], dd = dx * dx + dy * dy;
    FX = (PX[K] * dx + PY[K] * dy) / dd;
    FY = (PY[K] * dx - PX[K] * dy) / dd;
  })();

  var phase = 0.15, speed = 0.4, leaf = 11, Z0 = 300;

  /* 描く粒をバケツに溜めてから、明るさごとにまとめて塗る */
  var MAXL = 70000, NA = 8, NC = 4;
  var bx = new Float32Array(MAXL), by = new Float32Array(MAXL), br = new Float32Array(MAXL);
  var bk = new Uint8Array(MAXL), nL = 0;
  var ord = new Int32Array(MAXL), cnt = new Int32Array(NA * NC + 1);
  var ox = new Float32Array(4000), oy = new Float32Array(4000), or_ = new Float32Array(4000), oa = new Float32Array(4000), nO = 0;
  var fillStyles = [];
  for (var ci = 0; ci < NC; ci++) {
    fillStyles.push([]);
    for (var ai = 0; ai < NA; ai++) {
      var col = P.mix(P.line, P.accent, ci / (NC - 1));
      fillStyles[ci].push(P.rgba(col, 0.12 + 0.88 * (ai + 0.5) / NA));
    }
  }

  /* 触ったところのふくらみ: 画面上の点を外へ押し広げ、つぼみを大きくする */
  var lens = [];
  var LR = 70;
  var wx = 0, wy = 0, ww = 0;
  function warp(x, y) {
    var dx = 0, dy = 0, w = 0;
    for (var n = 0; n < lens.length; n++) {
      var L = lens[n];
      if (L.s <= 0.002) continue;
      var ex = x - L.x, ey = y - L.y, d2 = ex * ex + ey * ey;
      if (d2 > 9 * LR * LR) continue;
      var g = L.s * Math.exp(-d2 / (2 * LR * LR));
      dx += ex * 0.55 * g; dy += ey * 0.55 * g; w += g;
    }
    wx = x + dx; wy = y + dy; ww = w > 1 ? 1 : w;
  }

  function emit(x, y, r, a) {
    if (nL >= MAXL || a < 0.04) return;
    warp(x, y);
    var ai = Math.min(NA - 1, Math.floor(a * NA)), cidx = Math.min(NC - 1, Math.round(ww * (NC - 1) * 1.3));
    bx[nL] = wx; by[nL] = wy; br[nL] = r * (1 + 0.6 * ww); bk[nL] = cidx * NA + ai; nL++;
  }

  /* 頭 1 つを描く。画面座標 = A * x + Bv（複素数） */
  function head(Ax, Ay, Bx, By, light, fade, depth) {
    var hr = Math.hypot(Ax, Ay);
    if (Bx < -hr - 40 || Bx > W + hr + 40 || By < -hr - 40 || By > H + hr + 40) return;
    if (hr > 30 && nO < 4000 && hr < 0.45 * Math.min(W, H)) {
      ox[nO] = Bx; oy[nO] = By; or_[nO] = hr; oa[nO] = fade * Math.min(1, (hr - 30) / 60) * 0.25; nO++;
    }
    for (var j = 0; j < NB; j++) {
      var sr = hr * SJ[j];
      if (sr < 0.9) break;
      var x = Ax * PX[j] - Ay * PY[j] + Bx, y = Ax * PY[j] + Ay * PX[j] + By;
      if (x < -sr * 1.4 - 20 || x > W + sr * 1.4 + 20 || y < -sr * 1.4 - 20 || y > H + sr * 1.4 + 20) continue;
      var lj = light * LT[j];
      if (sr > leaf && depth < 14 && nL < MAXL) {
        var u = Math.min(1, (sr - leaf) / leaf);
        if (u < 1) emit(x, y, sr * 0.8, lj * fade * (1 - u));
        head(Ax * AX[j] - Ay * AY[j], Ax * AY[j] + Ay * AX[j], x, y, 0.45 + 0.55 * lj, fade * u, depth + 1);
      } else {
        emit(x, y, sr * 0.8, lj * fade);
      }
    }
  }

  function render() {
    nL = 0; nO = 0;
    /* 今の頭への写像 M0(x) = Z0 a^{-t} (x - x*) + C。2 つ上の親から描き始めて画面を埋める */
    var sc = Z0 * Math.exp(-lnK * phase), an = -angK * phase;
    var Ax = sc * Math.cos(an), Ay = sc * Math.sin(an);
    var Bx = W / 2 - (Ax * FX - Ay * FY), By = H / 2 - (Ax * FY + Ay * FX);
    for (var up = 0; up < 2; up++) {
      /* 親: M'(x) = M(f^{-1}(x)) = A/a (x - p) + Bv */
      var dd = aK * aK, ix = AX[K] / dd, iy = -AY[K] / dd;
      var nAx = Ax * ix - Ay * iy, nAy = Ax * iy + Ay * ix;
      Bx = Bx - (nAx * PX[K] - nAy * PY[K]); By = By - (nAx * PY[K] + nAy * PX[K]);
      Ax = nAx; Ay = nAy;
    }
    head(Ax, Ay, Bx, By, 1, 1, 0);

    P.fill(ctx, W, H, 1);
    /* 育った頭の縁を鋼色の細い線で */
    ctx.lineWidth = 1;
    for (var o = 0; o < nO; o++) {
      if (oa[o] < 0.02) continue;
      ctx.strokeStyle = P.rgba(P.steel, oa[o]);
      ctx.beginPath(); ctx.arc(ox[o], oy[o], or_[o] * 0.99, 0, 6.2832); ctx.stroke();
    }
    /* バケツごとに並べ直してから、1 バケツ 1 回で塗る */
    cnt.fill(0);
    for (var i = 0; i < nL; i++) cnt[bk[i] + 1]++;
    for (var b = 0; b < NA * NC; b++) cnt[b + 1] += cnt[b];
    for (i = 0; i < nL; i++) ord[cnt[bk[i]]++] = i;
    var k = 0;
    for (b = 0; b < NA * NC; b++) {
      if (k >= nL || bk[ord[k]] !== b) continue;
      ctx.beginPath();
      while (k < nL && bk[ord[k]] === b) {
        var m = ord[k++], r = br[m];
        if (r < 1.3) ctx.rect(bx[m] - r, by[m] - r, r * 2, r * 2);
        else { ctx.moveTo(bx[m] + r, by[m]); ctx.arc(bx[m], by[m], r, 0, 6.2832); }
      }
      ctx.fillStyle = fillStyles[(b / NA) | 0][b % NA];
      ctx.fill();
    }
  }

  ctx = P.fit(canvas, function (w, h) {
    W = w; H = h;
    Z0 = 0.42 * Math.max(W, H);
    LR = Math.max(50, 0.09 * Math.min(W, H));
  });

  /* ---------------------------------------------------------- 操作 */
  var down = false, finger = null, last = null, pid = null;
  canvas.addEventListener('pointerdown', function (e) {
    if (down) return;   /* 操作を始めた指だけを扱う */
    pid = e.pointerId;
    down = true;
    finger = { x: e.clientX, y: e.clientY, s: 0.35, hold: true };
    lens.push(finger);
    last = { x: e.clientX, y: e.clientY };
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down || !finger || e.pointerId !== pid) return;
    finger.x = e.clientX; finger.y = e.clientY;
    var d = Math.hypot(e.clientX - last.x, e.clientY - last.y);
    if (d > LR * 0.35) {
      lens.push({ x: e.clientX, y: e.clientY, s: 0.85, hold: false });
      last = { x: e.clientX, y: e.clientY };
      SFX.play('move', 140);
    }
  });
  function up(e) {
    if (!down || e.pointerId !== pid) return;
    down = false; if (finger) finger.hold = false; finger = null; pid = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kSpeed = document.getElementById('k-speed'), kDetail = document.getElementById('k-detail');
  function readSpeed() { speed = kSpeed.value / 100; }
  function readDetail() { leaf = 19 - kDetail.value / 100 * 10; }
  kSpeed.addEventListener('input', readSpeed);
  kDetail.addEventListener('input', readDetail);
  readSpeed(); readDetail();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    phase += dt * (0.008 + speed * 0.055);
    if (phase >= 1) { phase -= 1; SFX.play('tick'); }
    for (var n = lens.length - 1; n >= 0; n--) {
      var L = lens[n];
      if (L.hold) L.s += (1 - L.s) * Math.min(1, dt * 9);
      else { L.s -= dt * 0.6; if (L.s <= 0) lens.splice(n, 1); }
    }
    /* 上限を超えたら、押している指のものは残し、古い跡から消す */
    for (var q = 0; lens.length > 40 && q < lens.length; ) {
      if (lens[q].hold) q++; else lens.splice(q, 1);
    }
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
