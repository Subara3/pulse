<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('spiderweb');

$pageName = 'spiderweb';
$pageTitle = 'SPIDERWEB - PULSE';
$pageDesc = '画面いっぱいに張られた放射状の糸の上を、小さなクモが外側から内側へ回りながら、渦巻きの糸を1本ずつ張っていく。巣は風を受けてゆっくりたわみ、糸についた露が揺れる。押すとまわりの糸が指のほうへ寄せ集められて水色に光り、なぞると寄せた糸が指についてきて、離すと震えながら元に戻る。';
$pageCanonical = 'https://pulse.subara3.com/spiderweb.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-dew">DEW</label><input type="range" id="k-dew" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /*
   * 巣は「縦糸（放射）」と「横糸（渦巻き）」の 2 種類の糸でできている。
   * 結び目を点にして Verlet 積分で動かし、糸は伸びた分だけ引き戻す（縮むのは自由な、ロープの拘束）。
   * 縦糸は最初から全部張ってあり、クモが外から内へ回りながら横糸を 1 本ずつ足していく。
   * 張り終えてしばらくすると、古い巣は薄れ、別の場所に新しい巣が張られる。
   */
  var webs = [];          /* [0] が今の巣。それ以外は薄れていく古い巣 */
  var wind = 0.4, dew = 0.5;
  var ptr = null, down = false;
  var T = 0, FOC = 900;

  function rayLen(hx, hy, dx, dy) {
    var t = 1e9;
    if (dx > 1e-6) t = Math.min(t, (W - hx) / dx);
    if (dx < -1e-6) t = Math.min(t, -hx / dx);
    if (dy > 1e-6) t = Math.min(t, (H - hy) / dy);
    if (dy < -1e-6) t = Math.min(t, -hy / dy);
    return t;
  }

  function makeWeb(prebuilt) {
    var m = Math.min(W, H);
    var N = Math.max(22, Math.min(34, Math.round(Math.sqrt(W * H) / 30)));
    var K = Math.max(16, Math.min(30, Math.round(Math.max(W, H) / 40)));
    var K0 = 3;
    var hx = W * (0.5 + (Math.random() - 0.5) * 0.22), hy = H * (0.5 + (Math.random() - 0.5) * 0.22);
    var NP = 1 + N * K + N;
    var w = {
      N: N, K: K, NP: NP, hx: hx, hy: hy,
      x: new Float32Array(NP), y: new Float32Array(NP), z: new Float32Array(NP),
      ox: new Float32Array(NP), oy: new Float32Array(NP), oz: new Float32Array(NP),
      fix: new Uint8Array(NP), glow: new Float32Array(NP),
      alpha: 1, fade: 0, built: 0, nSpiral: 0, hold: 0, spin: 0
    };
    var rot = Math.random() * Math.PI * 2;
    function put(i, x, y) { w.x[i] = w.ox[i] = x; w.y[i] = w.oy[i] = y; }
    put(0, hx, hy);
    for (var s = 0; s < N; s++) {
      var a = rot + (s + (Math.random() - 0.5) * 0.35) / N * Math.PI * 2;
      var dx = Math.cos(a), dy = Math.sin(a), L = rayLen(hx, hy, dx, dy) + 24;
      /* 横糸の届く範囲は、画面の縁と円の中間の形にする（角ばった枠にしない） */
      var Ls = Math.min(L * 0.95, Math.pow(L, 0.6) * Math.pow(m * 0.55, 0.4));
      for (var k = 0; k < K; k++) {
        var f = (K0 + k + s / N) / (K0 + K);
        put(1 + s * K + k, hx + dx * Ls * f, hy + dy * Ls * f);
      }
      var an = 1 + N * K + s;
      put(an, hx + dx * L, hy + dy * L);
      w.fix[an] = 1;
    }
    /* 糸: 縦糸を先に並べ、そのあとに横糸を「外側から内側へ」の順で並べる */
    var A = [], B = [], R = [], S = [];
    function link(a, b, slack, kind) {
      A.push(a); B.push(b);
      R.push(Math.hypot(w.x[a] - w.x[b], w.y[a] - w.y[b]) * slack);
      S.push(kind);
    }
    for (s = 0; s < N; s++) {
      link(0, 1 + s * K, 0.97, 0);
      for (k = 0; k < K - 1; k++) link(1 + s * K + k, 1 + s * K + k + 1, 0.97, 0);
      link(1 + s * K + K - 1, 1 + N * K + s, 0.97, 0);
    }
    w.nSpoke = A.length;
    for (var j = N * K - 1; j >= 0; j--) {
      var kk = Math.floor(j / N), ss = j % N;
      if (ss < N - 1) link(1 + ss * K + kk, 1 + (ss + 1) * K + kk, 1.0, 1);
      else if (kk + 1 < K) link(1 + ss * K + kk, 1 + kk + 1, 1.0, 1);
    }
    w.nSpiral = A.length - w.nSpoke;
    w.A = new Int32Array(A); w.B = new Int32Array(B); w.R = new Float32Array(R); w.S = new Uint8Array(S);
    w.NL = A.length;
    /* 露: 横糸の上の決まった位置に置く。DEW で見せる数を変える */
    var nd = w.nSpiral * 2;
    w.dl = new Int32Array(nd); w.dt = new Float32Array(nd); w.dr = new Float32Array(nd); w.dp = new Float32Array(nd);
    for (var d = 0; d < nd; d++) {
      w.dl[d] = w.nSpoke + Math.floor(Math.random() * w.nSpiral);
      w.dt[d] = 0.15 + Math.random() * 0.7;
      w.dr[d] = Math.random();
      w.dp[d] = Math.random() * 6.28;
    }
    w.built = Math.floor(w.nSpiral * prebuilt);
    w.rate = w.nSpiral / Math.max(30, Math.min(55, w.nSpiral / 14));
    return w;
  }

  function init() {
    webs = [makeWeb(0.45)];
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; init(); });

  /* ---------------------------------------------------------- 物理 */
  function simulate(w, h) {
    var NP = w.NP, x = w.x, y = w.y, z = w.z, ox = w.ox, oy = w.oy, oz = w.oz, fix = w.fix;
    var str = wind * (Math.sin(T * 0.21) * 0.7 + Math.sin(T * 0.57 + 1.3) * 0.4 + 0.25);
    var R = Math.min(W, H) * 0.17, R2 = R * R;
    var h2 = h * h;
    for (var i = 0; i < NP; i++) {
      if (fix[i]) continue;
      var vx = (x[i] - ox[i]) * 0.985, vy = (y[i] - oy[i]) * 0.985, vz = (z[i] - oz[i]) * 0.97;
      ox[i] = x[i]; oy[i] = y[i]; oz[i] = z[i];
      /* 風: 面を前後に押し、少し横にも流す。場所ごとに強さがずれるので巣がうねる */
      var g = Math.sin(x[i] * 0.004 + T * 0.8) * 0.5 + Math.sin(y[i] * 0.005 - T * 0.6) * 0.5;
      var az = str * (900 + 700 * g) - z[i] * 4;
      var ax = str * 60 * (0.6 + g), ay = 18 + str * 30 * Math.sin(x[i] * 0.006 + T * 0.4);
      x[i] += vx + ax * h2; y[i] += vy + ay * h2; z[i] += vz + az * h2;
      /* 指: まわりの結び目を、指との距離を縮める向きに寄せる */
      if (down && ptr && w === webs[0]) {
        var dx = x[i] - ptr.x, dy = y[i] - ptr.y, d2 = dx * dx + dy * dy;
        if (d2 < R2) {
          var q = 1 - Math.sqrt(d2) / R, wq = q * q * 0.35;
          x[i] -= dx * 0.7 * wq; y[i] -= dy * 0.7 * wq; z[i] *= 1 - wq;
          if (q > w.glow[i]) w.glow[i] = q;
        }
      }
    }
    var A = w.A, B = w.B, RL = w.R;
    var nAct = w.nSpoke + Math.min(w.nSpiral, Math.floor(w.built));
    for (var it = 0; it < 5; it++) {
      for (var l = 0; l < nAct; l++) {
        var a = A[l], b = B[l];
        var ex = x[b] - x[a], ey = y[b] - y[a], ez = z[b] - z[a];
        var len = Math.sqrt(ex * ex + ey * ey + ez * ez);
        if (len <= RL[l] || len < 1e-6) continue;
        var wa = fix[a] ? 0 : 1, wb = fix[b] ? 0 : 1, ws = wa + wb;
        if (!ws) continue;
        var c = (len - RL[l]) / len / ws * 0.9;
        x[a] += ex * c * wa; y[a] += ey * c * wa; z[a] += ez * c * wa;
        x[b] -= ex * c * wb; y[b] -= ey * c * wb; z[b] -= ez * c * wb;
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  var cx = 0, cy = 0;
  function sx(w, i) { return cx + (w.x[i] - cx) * FOC / (FOC + w.z[i]); }
  function sy(w, i) { return cy + (w.y[i] - cy) * FOC / (FOC + w.z[i]); }

  function drawWeb(w) {
    var al = w.alpha;
    if (al <= 0.01) return;
    var A = w.A, B = w.B, NL = w.NL, nb = Math.floor(w.built), l, a, b;
    var nAct = w.nSpoke + Math.min(w.nSpiral, nb);
    ctx.lineWidth = 1;
    /* 縦糸は控えめに、横糸ははっきりと */
    ctx.strokeStyle = P.rgba(P.line, 0.3 * al);
    ctx.beginPath();
    for (l = 0; l < w.nSpoke; l++) {
      a = A[l]; b = B[l];
      ctx.moveTo(sx(w, a), sy(w, a)); ctx.lineTo(sx(w, b), sy(w, b));
    }
    ctx.stroke();
    ctx.strokeStyle = P.rgba(P.line, 0.62 * al);
    ctx.lineWidth = 0.9;
    ctx.beginPath();
    for (l = w.nSpoke; l < nAct; l++) {
      a = A[l]; b = B[l];
      ctx.moveTo(sx(w, a), sy(w, a)); ctx.lineTo(sx(w, b), sy(w, b));
    }
    ctx.stroke();
    /* クモが今張っている 1 本: 張り終えたところまで */
    var spider = null;
    if (nb < w.nSpiral) {
      l = w.nSpoke + nb; a = A[l]; b = B[l];
      var f = w.built - nb;
      var ax = sx(w, a), ay = sy(w, a), bx = sx(w, b), by = sy(w, b);
      var px = ax + (bx - ax) * f, py = ay + (by - ay) * f;
      ctx.beginPath(); ctx.moveTo(ax, ay); ctx.lineTo(px, py); ctx.stroke();
      spider = { x: px, y: py, a: Math.atan2(by - ay, bx - ax) };
    }
    /* 触ったところの糸: 水色。明るさを 3 段に分けてまとめて描く */
    var G = w.glow;
    for (var band = 0; band < 3; band++) {
      var lo = 0.08 + band * 0.3, hi = lo + 0.3;
      ctx.strokeStyle = P.rgba(P.accent, (0.35 + band * 0.25) * al);
      ctx.lineWidth = 1 + band * 0.3;
      ctx.beginPath();
      var any = false;
      for (l = 0; l < nAct; l++) {
        a = A[l]; b = B[l];
        var gg = Math.min(G[a], G[b]);
        if (gg < lo || (band < 2 && gg >= hi)) continue;
        ctx.moveTo(sx(w, a), sy(w, a)); ctx.lineTo(sx(w, b), sy(w, b));
        any = true;
      }
      if (any) ctx.stroke();
    }
    /* 露: 横糸についた小さな粒。ゆっくり明るさが揺れる */
    var nd = w.dl.length, lim = dew;
    ctx.fillStyle = P.rgba(P.line, 0.75 * al);
    ctx.beginPath();
    for (var d = 0; d < nd; d++) {
      if (w.dr[d] > lim) continue;
      l = w.dl[d];
      if (l >= nAct) continue;
      a = A[l]; b = B[l];
      var t = w.dt[d];
      var dx = sx(w, a) + (sx(w, b) - sx(w, a)) * t, dy = sy(w, a) + (sy(w, b) - sy(w, a)) * t;
      var r = 0.7 + 0.9 * (0.5 + 0.5 * Math.sin(T * 0.7 + w.dp[d])) * (1 - w.dr[d] * 0.6);
      ctx.moveTo(dx + r, dy); ctx.arc(dx, dy, r, 0, 6.2832);
    }
    ctx.fill();
    if (spider) drawSpider(spider, al);
  }

  function drawSpider(s, al) {
    var c = Math.cos(s.a), n = Math.sin(s.a);
    ctx.strokeStyle = P.rgba(P.line, 0.8 * al);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var side = -1; side <= 1; side += 2) {
      for (var k = 0; k < 4; k++) {
        var la = (k - 1.5) * 0.5 + side * Math.PI / 2;
        var swing = Math.sin(T * 9 + k * 1.7 + side) * 0.18;
        var ca = Math.cos(la + swing), na = Math.sin(la + swing);
        var kx = s.x + (c * ca - n * na) * 5, ky = s.y + (n * ca + c * na) * 5;
        var fx = s.x + (c * ca - n * na) * 9 + c * (k - 1.5) * 2, fy = s.y + (n * ca + c * na) * 9 + n * (k - 1.5) * 2;
        ctx.moveTo(s.x, s.y); ctx.lineTo(kx, ky); ctx.lineTo(fx, fy);
      }
    }
    ctx.stroke();
    ctx.fillStyle = P.rgba(P.line, 0.95 * al);
    ctx.beginPath();
    ctx.arc(s.x + c * 2, s.y + n * 2, 2.2, 0, 6.2832);
    ctx.moveTo(s.x - c * 3 + 3.6, s.y - n * 3);
    ctx.arc(s.x - c * 3, s.y - n * 3, 3.6, 0, 6.2832);
    ctx.fill();
  }

  /* ---------------------------------------------------------- 操作 */
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; ptr = pos(e);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e);
    if (ptr && Math.hypot(p.x - ptr.x, p.y - ptr.y) > 4) SFX.play('move', 140);
    ptr = p;
  });
  function up() {
    if (down) SFX.play('ripple');
    down = false;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  document.getElementById('k-dew').addEventListener('input', function () { dew = this.value / 100; });
  document.getElementById('k-new').addEventListener('click', function () {
    renew();
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  wind = document.getElementById('k-wind').value / 100;
  dew = document.getElementById('k-dew').value / 100;

  /* 今の巣を薄れさせ、新しい巣を張りはじめる */
  function renew() {
    webs[0].fade = 1;
    webs.unshift(makeWeb(0.12));
    if (webs.length > 3) webs.length = 3;
  }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), ringAt = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    T += dt;
    cx = W / 2; cy = H / 2;
    var cur = webs[0];
    /* クモが横糸を張り進める。1 周するごとに小さく鳴らす */
    if (cur.built < cur.nSpiral) {
      var before = Math.floor(cur.built / cur.N);
      cur.built = Math.min(cur.nSpiral, cur.built + cur.rate * dt);
      if (Math.floor(cur.built / cur.N) !== before && before % 2 === 0) SFX.play('tick', 2500);
    } else {
      cur.hold += dt;
      if (cur.hold > 16) { renew(); SFX.play('grow'); }
    }
    var n = dt > 0.025 ? 2 : 1, h = dt / n;
    for (var k = webs.length - 1; k >= 0; k--) {
      var w = webs[k];
      if (w.fade) {
        w.alpha -= dt / 4;
        if (w.alpha <= 0) { webs.splice(k, 1); continue; }
      }
      for (var s = 0; s < n; s++) simulate(w, h);
      var G = w.glow, dec = dt * (down ? 0.5 : 0.9);
      for (var i = 0; i < w.NP; i++) if (G[i] > 0) G[i] = Math.max(0, G[i] - dec);
    }
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.55 });
    for (k = webs.length - 1; k >= 0; k--) drawWeb(webs[k]);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
