<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('factors');

$pageName = 'factors';
$pageTitle = 'FACTORS - PULSE';
$pageDesc = '数えた数だけの点が、その数を掛け算に分けた形どおりに、小さな多角形を入れ子にして並ぶ。数が1つ増えるたびに点が飛んで並び直し、素数のときは1つの大きな輪になる。押すとまわりの点がはじけて水色に光り、なぞると点が指についてきて、離すと元の並びに戻る。';
$pageCanonical = 'https://pulse.subara3.com/factors.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-spin">SPIN</label><input type="range" id="k-spin" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-trail">TRAIL</label><input type="range" id="k-trail" min="0" max="100" value="55"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 数 n を素因数に分け、小さい因数を外側にして多角形を入れ子に組む。
     点 i の位置は「各段で何番目の頂点か」の並び idx から毎フレーム計算し、
     実際の点はばねでそこへ追いつく。数が変わると目標が入れ替わり、点が飛んで並び直す */
  var MAXN = 640, MAXD = 12;
  var n = 0, dir = 1, fac = [], rad = [];
  var idx = new Int16Array(MAXN * MAXD);
  var px = new Float32Array(MAXN), py = new Float32Array(MAXN);
  var vx = new Float32Array(MAXN), vy = new Float32Array(MAXN);
  var tx = new Float32Array(MAXN), ty = new Float32Array(MAXN);
  var glow = new Float32Array(MAXN), grab = new Float32Array(MAXN);
  var CX = 0, CY = 0, S = 1, DR = 2;
  var paceK = 0.45, spinK = 0.5, trailK = 0.55;
  var t = 0, hold = 0, newest = -1, ext = 1;
  var down = false, fx = 0, fy = 0, lastX = 0, lastY = 0;
  /* 点の残像だけを別の板に描いて薄めていく。線まで残すと、回る線の跡が面になって濁る */
  var trail = document.createElement('canvas'), tctx = trail.getContext('2d');

  function factorize(m) {
    var f = [];
    for (var p = 2; p * p <= m; p++) while (m % p === 0) { f.push(p); m /= p; }
    if (m > 1) f.push(m);
    return f;
  }

  /* 内側から外へ半径を積む。点の半径を 1 として、子どうしが重ならない外接半径を選ぶ */
  function layout(m) {
    fac = factorize(m);
    rad = [];
    var r = 1;
    for (var l = fac.length - 1; l >= 0; l--) {
      var p = fac[l];
      var R = p === 2 ? r * 1.2 : r * 1.2 / Math.sin(Math.PI / p);
      rad[l] = R;
      r += R;
    }
    var D = fac.length;
    for (var i = 0; i < m; i++) {
      var q = i;
      for (var k = D - 1; k >= 0; k--) { idx[i * MAXD + k] = q % fac[k]; q = (q / fac[k]) | 0; }
    }
    scale();
  }

  function scale() { CX = W / 2; CY = H / 2; }

  /* 実際に広がっている半径を測り、画面に収まる倍率へゆっくり寄せる */
  function targets(dt) {
    var D = fac.length, ext = 1;
    for (var i = 0; i < n; i++) {
      var A = -Math.PI / 2, x = 0, y = 0;
      for (var l = 0; l < D; l++) {
        var w = (l % 2 ? -1 : 1) * (0.05 + 0.04 * l) * spinK * 2;
        /* 2 つ組の下の段は向きを直角に振る。2 が続いても一列にならず、四角に組まれる */
        A += t * w + idx[i * MAXD + l] * 6.2832 / fac[l] + (l && fac[l] === 2 ? Math.PI / 2 : 0);
        x += rad[l] * Math.cos(A); y += rad[l] * Math.sin(A);
      }
      tx[i] = x; ty[i] = y;
      var r = x * x + y * y;
      if (r > ext) ext = r;
    }
    var goal = Math.min(W, H) * 0.45 / (Math.sqrt(ext) + 1);
    S = dt < 0 ? goal : S + (goal - S) * Math.min(1, dt * 1.5);
    DR = Math.max(1.3, Math.min(5, S * 0.8));
    for (var j = 0; j < n; j++) { tx[j] = CX + tx[j] * S; ty[j] = CY + ty[j] * S; }
    return Math.sqrt(ext);
  }

  /* 並び直すとき、点と新しい席を角度の順で組にする。点は回りながら隣の席へ移り、画面を横切らない */
  var ox = new Float32Array(MAXN), oy = new Float32Array(MAXN), ovx = new Float32Array(MAXN),
      ovy = new Float32Array(MAXN), og = new Float32Array(MAXN);
  function byAngle(xs, ys, m) {
    var a = [];
    for (var i = 0; i < m; i++) a.push(i);
    var ang = a.map(function (i) { return Math.atan2(ys[i] - CY, xs[i] - CX); });
    a.sort(function (p, q) { return ang[p] - ang[q]; });
    return a;
  }
  function setN(m) {
    var prev = n, i;
    var oldOrd = byAngle(px, py, prev);
    for (i = 0; i < prev; i++) { ox[i] = px[i]; oy[i] = py[i]; ovx[i] = vx[i]; ovy[i] = vy[i]; og[i] = glow[i]; }
    n = m;
    layout(n);
    targets(0);
    var newOrd = byAngle(tx, ty, n);
    for (i = 0; i < n; i++) { px[i] = CX; py[i] = CY; vx[i] = 0; vy[i] = 0; glow[i] = 0; grab[i] = 0; }
    newest = -1;
    if (n >= prev) {
      var used = new Uint8Array(n);
      for (var k = 0; k < prev; k++) {
        var j = newOrd[Math.floor(k * n / prev)], o = oldOrd[k];
        used[j] = 1;
        px[j] = ox[o]; py[j] = oy[o]; vx[j] = ovx[o]; vy[j] = ovy[o]; glow[j] = og[o];
      }
      for (i = 0; i < n; i++) if (!used[i]) { newest = i; glow[i] = 1; }
    } else {
      for (var q = 0; q < n; q++) {
        var jj = newOrd[q], oo = oldOrd[Math.floor(q * prev / n)];
        px[jj] = ox[oo]; py[jj] = oy[oo]; vx[jj] = ovx[oo]; vy[jj] = ovy[oo]; glow[jj] = og[oo];
      }
    }
  }

  function advance() {
    if (n >= MAXN) dir = -1;
    if (n <= 2) dir = 1;
    setN(n + dir);
    SFX.play(fac.length === 1 ? 'grow' : 'tick', 300);
  }

  function rebuild() {
    if (!n) {
      scale();
      /* 開いた瞬間は入れ子が見える数から始める（素数の輪から始めない） */
      var m0;
      do { m0 = 72 + Math.floor(Math.random() * 120); } while (factorize(m0).length < 3);
      setN(m0);
      targets(-1);
      for (var i = 0; i < n; i++) { px[i] = tx[i]; py[i] = ty[i]; glow[i] = 0; }
      newest = -1;
    } else scale();
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h, dpr) {
    W = w; H = h;
    trail.width = Math.round(w * dpr); trail.height = Math.round(h * dpr);
    tctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    rebuild();
  });

  /* ---------------------------------------------------------- 描く */
  var LN = P.line, ST = P.steel, AC = P.accent;
  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.8 });
    var D = fac.length;
    /* 外枠の輪と、いちばん外の段の頂点へ伸びる補助線 */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(ST, 0.1);
    ctx.beginPath(); ctx.arc(CX, CY, ext * S + DR * 4, 0, 6.2832); ctx.stroke();
    /* 段ごとの中心を親の中心と結ぶ。設計図の引き出し線のような細い枝 */
    if (D > 1) {
      ctx.strokeStyle = P.rgba(ST, 0.16);
      ctx.lineWidth = 0.8;
      ctx.beginPath();
      for (var i0 = 0; i0 < n; i0++) {
        var A = -Math.PI / 2, x = 0, y = 0;
        for (var l = 0; l < D - 1; l++) {
          var w = (l % 2 ? -1 : 1) * (0.05 + 0.04 * l) * spinK * 2;
          A += t * w + idx[i0 * MAXD + l] * 6.2832 / fac[l] + (l && fac[l] === 2 ? Math.PI / 2 : 0);
          var nx = x + rad[l] * Math.cos(A), ny = y + rad[l] * Math.sin(A);
          /* 枝はその段の最初の点のときだけ引く（同じ枝を何度も引かない） */
          var first = true;
          for (var l2 = l + 1; l2 < D; l2++) if (idx[i0 * MAXD + l2]) { first = false; break; }
          if (first) { ctx.moveTo(CX + x * S, CY + y * S); ctx.lineTo(CX + nx * S, CY + ny * S); }
          x = nx; y = ny;
        }
      }
      ctx.stroke();
    }
    /* いちばん内側の多角形の辺。同じ多角形の点は idx が連続している */
    if (D) {
      var p = fac[D - 1];
      ctx.strokeStyle = P.rgba(ST, 0.42);
      ctx.lineWidth = 0.8;
      ctx.beginPath();
      for (var g = 0; g < n; g += p) {
        ctx.moveTo(px[g], py[g]);
        for (var j = 1; j < p; j++) ctx.lineTo(px[g + j], py[g + j]);
        if (p > 2) ctx.closePath();
      }
      ctx.stroke();
    }
    tctx.globalCompositeOperation = 'destination-out';
    tctx.fillStyle = P.rgba(P.bg, 0.22 + (1 - trailK) * 0.6);
    tctx.fillRect(0, 0, W, H);
    tctx.globalCompositeOperation = 'source-over';
    for (var i = 0; i < n; i++) {
      var k = glow[i];
      tctx.fillStyle = P.rgba(k > 0.02 ? P.mix(LN, AC, Math.min(1, k * 1.4)) : LN, 0.92);
      tctx.beginPath(); tctx.arc(px[i], py[i], DR * (1 + 0.5 * k), 0, 6.2832); tctx.fill();
    }
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.drawImage(trail, 0, 0);
    ctx.restore();
  }

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    down = true; fx = lastX = e.clientX; fy = lastY = e.clientY;
    /* 押した点のまわりを外へはじく */
    var R = Math.max(90, Math.min(W, H) * 0.16);
    for (var i = 0; i < n; i++) {
      var dx = px[i] - fx, dy = py[i] - fy, d = Math.hypot(dx, dy);
      if (d > R) continue;
      var f = 1 - d / R, u = d > 0.5 ? 1 / d : 0;
      vx[i] += dx * u * 620 * f; vy[i] += dy * u * 620 * f;
      glow[i] = Math.max(glow[i], 0.4 + 0.6 * f);
    }
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    fx = e.clientX; fy = e.clientY;
    var mx = fx - lastX, my = fy - lastY;
    lastX = fx; lastY = fy;
    /* 指のまわりの点を、指の動いたぶんだけ一緒に動かす */
    var R = Math.max(80, Math.min(W, H) * 0.14);
    for (var i = 0; i < n; i++) {
      var d = Math.hypot(px[i] - fx, py[i] - fy);
      if (d > R) continue;
      var f = 1 - d / R;
      f = f * f * (3 - 2 * f);
      px[i] += mx * f; py[i] += my * f;
      grab[i] = Math.max(grab[i], f);
      glow[i] = Math.max(glow[i], 0.25 + 0.6 * f);
    }
    if (Math.abs(mx) + Math.abs(my) > 2) SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readCtl() {
    paceK = document.getElementById('k-pace').value / 100;
    spinK = document.getElementById('k-spin').value / 100;
    trailK = document.getElementById('k-trail').value / 100;
  }
  ['k-pace', 'k-spin', 'k-trail'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', readCtl);
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readCtl();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    t += dt;
    hold += dt;
    if (hold > 4.2 - paceK * 3.2) { hold = 0; advance(); }
    ext = targets(dt);
    var K = 24, C = 9;
    for (var i = 0; i < n; i++) {
      /* つかまれている点はばねを弱めて、指についていかせる */
      var k = K * (1 - (down ? 0.85 : 0) * grab[i]);
      vx[i] += (k * (tx[i] - px[i]) - C * vx[i]) * dt;
      vy[i] += (k * (ty[i] - py[i]) - C * vy[i]) * dt;
      px[i] += vx[i] * dt; py[i] += vy[i] * dt;
      glow[i] *= Math.exp(-dt * (i === newest ? 0.7 : 1.1));
      if (!down) grab[i] *= Math.exp(-dt * 6);
    }
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
