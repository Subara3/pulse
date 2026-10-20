<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('seashell');

$pageName = 'seashell';
$pageTitle = 'SEASHELL - PULSE';
$pageDesc = '巻き貝の殻が、口の縁に輪を1本ずつ足しながら育っていく。古い輪は巻きに沿って奥へ送られ、先端に向かって小さくなる。殻の形は、平たい渦巻きから高い塔まで、ゆっくり変わっていく。押すとそこの殻が外へふくらんで水色に光り、なぞると指の跡に沿ってふくらみが並ぶ。ふくらみは殻といっしょに奥へ巻かれていく。';
$pageCanonical = 'https://pulse.subara3.com/seashell.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-turn">TURN</label><input type="range" id="k-turn" min="0" max="100" value="30"></div>
  <div class="ctl-btns"><button type="button" id="k-smooth">SMOOTH</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 殻は「管の断面（楕円）を、巻きの軸のまわりに回しながら一定の比で大きくしていく」形。
     1 巻きで W 倍、1 巻きごとに軸方向へ下がる量が SPIRE。口の輪を大きさ 1 に揃えて描くので、
     育つと古い輪が巻きに沿って奥へ送られ、先端へ縮んでいくように見える。 */
  var TAU = Math.PI * 2;
  var M = 20, PER = 48, DTH = TAU / PER, CAP = 2048, MAXR = 340, RIB = 16;
  var bump = new Float32Array(CAP * M), heat = new Float32Array(CAP * M);
  var rSlot = new Int32Array(MAXR), rA = new Float32Array(MAXR), rW = new Float32Array(MAXR);
  var NV = MAXR * M;
  var vX = new Float32Array(NV), vY = new Float32Array(NV), vD = new Float32Array(NV);
  var vF = new Float32Array(NV), vH = new Float32Array(NV);
  var sx = new Float32Array(NV), sy = new Float32Array(NV);
  var qd = new Float32Array(NV), qo = new Uint32Array(NV), qL = new Float32Array(NV);
  var nR = 0, nQ = 0;

  var theta = 40 * TAU, top = Math.floor(theta / DTH);
  var cur = { w: 1.9, h: 1.2, f: 1.3, ba: 1.1 }, tgt = { w: 1.9, h: 1.2, f: 1.3, ba: 1.1 }, morphT = 14;
  var yaw = 0.6, yawV = 0, tilt = 0.6, rho = 0, scl = 1, ox = 0, oy = 0, fitted = false;
  var speedK = 0.4, turnK = 0.3, time = 0;
  var down = false, last = null;

  /* 塗りと線の色は先に表にしておく（毎フレーム文字列を作らない） */
  var FL = 12, HL = 5, AL = 8, FILL = [], LINE = [];
  for (var li = 0; li < FL; li++) {
    FILL.push([]);
    for (var hi = 0; hi < HL; hi++) {
      var c = P.mix(P.deep, P.steel, 0.03 + li / (FL - 1) * 0.42);
      FILL[li].push(P.rgba(P.mix(c, P.accent, hi / (HL - 1) * 0.5), 1));
    }
  }
  for (var ai = 0; ai < AL; ai++) {
    LINE.push([]);
    for (var hj = 0; hj < HL; hj++) LINE[ai].push(P.rgba(P.mix(P.line, P.accent, hj / (HL - 1)), (ai + 1) / AL));
  }

  function clamp01(t) { return t < 0 ? 0 : t > 1 ? 1 : t; }
  function rnd(a, b) { return a + Math.random() * (b - a); }

  function pickTarget() {
    var h = Math.pow(Math.random(), 1.2) * 2.4;
    tgt.h = h;
    tgt.w = 1.55 + Math.random() * 1.5 * (1 - 0.45 * h / 2.4);
    tgt.f = rnd(1.15, 1.45);
    tgt.ba = rnd(0.85, 1.35);
    morphT = rnd(16, 24);
  }

  /* 輪を口から先端へ並べ、各頂点をカメラの座標に置く */
  function build() {
    var k = Math.log(cur.w) / TAU;
    var A = cur.f * (cur.w - 1) / (cur.w + 1), HS = cur.h;
    /* 上下の巻きがすき間なく重なるだけの高さを管に持たせる */
    var BA = Math.max(cur.ba, HS / cur.f * 1.05);
    var ps = Math.sin(yaw + 0.35 * Math.sin(time * 0.11)), pc = Math.cos(yaw + 0.35 * Math.sin(time * 0.11));
    var ts = Math.sin(tilt), tc = Math.cos(tilt);
    nR = 0;
    ring(0, 1, 1, 1.8, (top + 1) % CAP, 0);
    for (var i = top; nR < MAXR; i--) {
      var th = i * DTH - theta, s = Math.exp(k * th);
      if (s < 0.035) break;
      var st = s > 0.3 ? 1 : s > 0.1 ? 2 : 4;
      if (i % st) continue;
      var a = 0.3 + 0.7 * clamp01(s * 2.5);
      if (i % 2) a *= clamp01((s - 0.3) / 0.12);
      else if (i % 4) a *= clamp01((s - 0.1) / 0.05);
      var rib = i % RIB === 0;
      ring(th, s, a, rib ? 1.4 : 0.85, i % CAP, rib ? 0.1 : 0);
    }

    function ring(th, s, a, w, slot, rib) {
      var n = nR++;
      rSlot[n] = slot; rA[n] = a; rW[n] = w;
      var ca = Math.cos(th), sa = Math.sin(th);
      var cx = s * ca, cy = s * sa, cz = -s * HS;
      for (var j = 0; j < M; j++) {
        var ph = j / M * TAU, cp = Math.cos(ph), sp = Math.sin(ph);
        var b = bump[slot * M + j];
        var rr = s * A * (1 + rib + 0.6 * b * b);
        var x = cx + rr * cp * ca, y = cy + rr * cp * sa, z = cz + rr * BA * sp;
        /* 外向きの法線（こぶは無視した楕円管の法線。向きの判定だけに使う） */
        var nx = BA * cp * ca, ny = BA * cp * sa, nz = sp;
        var x1 = x * pc - y * ps, y1 = x * ps + y * pc;
        var ny1 = nx * ps + ny * pc;
        var v = n * M + j;
        vX[v] = x1;
        vY[v] = -(y1 * ts + z * tc);
        vD[v] = y1 * tc - z * ts;
        vF[v] = -(ny1 * tc - nz * ts);
        vH[v] = heat[slot * M + j];
      }
    }
  }

  /* 画面に合わせる: 長い向きを画面の長い辺にそろえ、余白を残して大きく置く */
  function place(dt) {
    var N = nR * M, mx = 0, my = 0, i;
    for (i = 0; i < N; i++) { mx += vX[i]; my += vY[i]; }
    mx /= N; my /= N;
    var sxx = 0, syy = 0, sxy = 0;
    for (i = 0; i < N; i++) {
      var dx = vX[i] - mx, dy = vY[i] - my;
      sxx += dx * dx; syy += dy * dy; sxy += dx * dy;
    }
    var ang = 0.5 * Math.atan2(2 * sxy, sxx - syy);
    var aniso = Math.sqrt((sxx - syy) * (sxx - syy) + 4 * sxy * sxy) / (sxx + syy + 1e-9);
    var want = (W >= H ? 0 : Math.PI / 2) - ang;
    var d = want - rho;
    d -= Math.round(d / Math.PI) * Math.PI;
    var rc = Math.cos(rho), rs = Math.sin(rho);
    var x0 = 1e9, x1 = -1e9, y0 = 1e9, y1 = -1e9;
    for (i = 0; i < N; i++) {
      var X = vX[i] * rc - vY[i] * rs, Y = vX[i] * rs + vY[i] * rc;
      if (X < x0) x0 = X; if (X > x1) x1 = X;
      if (Y < y0) y0 = Y; if (Y > y1) y1 = Y;
    }
    /* 縦長の画面では、短い辺を少しはみ出させてでも大きく置く */
    var ts = Math.min(W * (W < H ? 1.15 : 0.9) / (x1 - x0), H * (H < W ? 0.92 : 0.86) / (y1 - y0));
    var tx = W / 2 - ts * (x0 + x1) / 2, ty = H / 2 - ts * (y0 + y1) / 2;
    if (!fitted) { rho += d; fitted = true; scl = ts; ox = tx; oy = ty; }
    else {
      rho += d * Math.min(1, dt * 0.5 * aniso);
      var e = Math.min(1, dt * 1.2);
      scl += (ts - scl) * e; ox += (tx - ox) * e; oy += (ty - oy) * e;
    }
    rc = Math.cos(rho); rs = Math.sin(rho);
    for (i = 0; i < N; i++) {
      sx[i] = ox + scl * (vX[i] * rc - vY[i] * rs);
      sy[i] = oy + scl * (vX[i] * rs + vY[i] * rc);
    }
  }

  /* 面を奥から順に塗り、その面の縁の輪だけを線で描く（手前の面が奥の線を隠す） */
  var LX = -0.42, LY = -0.62, LD = -0.66;
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.8 });
    nQ = (nR - 1) * M;
    var q, r, j, j2, a, b, c, d;
    for (q = 0; q < nQ; q++) {
      r = (q / M) | 0; j = q % M; j2 = (j + 1) % M;
      a = r * M + j; b = r * M + j2; c = (r + 1) * M + j2; d = (r + 1) * M + j;
      qd[q] = vD[a] + vD[b] + vD[c] + vD[d];
      /* 面の法線は対角線の外積。向きは楕円管の法線にそろえる */
      var ux = vX[c] - vX[a], uy = vY[c] - vY[a], ud = vD[c] - vD[a];
      var wx = vX[d] - vX[b], wy = vY[d] - vY[b], wd = vD[d] - vD[b];
      var nx = uy * wd - ud * wy, ny = ud * wx - ux * wd, nd = ux * wy - uy * wx;
      var len = Math.sqrt(nx * nx + ny * ny + nd * nd) + 1e-12;
      nx /= len; ny /= len; nd /= len;
      var face = vF[a] + vF[b] + vF[c] + vF[d];
      if ((-nd > 0) !== (face > 0)) { nx = -nx; ny = -ny; nd = -nd; }
      var l = Math.max(0, nx * LX + ny * LY + nd * LD);
      l = face > 0 ? 0.12 + 0.88 * l : 0.08 + 0.2 * l;
      if (j % 4 === 0) l *= 1.15;
      qL[q] = l;
      qo[q] = q;
    }
    var sub = qo.subarray(0, nQ);
    sub.sort(function (p, s) { return qd[s] - qd[p]; });
    ctx.lineJoin = 'round';
    for (var k = 0; k < nQ; k++) {
      q = sub[k];
      r = (q / M) | 0; j = q % M; j2 = (j + 1) % M;
      a = r * M + j; b = r * M + j2; c = (r + 1) * M + j2; d = (r + 1) * M + j;
      var hh = (vH[a] + vH[b] + vH[c] + vH[d]) * 0.25;
      ctx.fillStyle = FILL[Math.min(FL - 1, (qL[q] * (FL - 1)) | 0)][Math.min(HL - 1, Math.round(hh * (HL - 1)))];
      ctx.beginPath();
      ctx.moveTo(sx[a], sy[a]); ctx.lineTo(sx[b], sy[b]); ctx.lineTo(sx[c], sy[c]); ctx.lineTo(sx[d], sy[d]);
      ctx.closePath();
      ctx.fill();
      var front = vF[a] + vF[b] + vF[c] + vF[d] > 0 ? 1 : 0.3;
      edge(r, a, b, front);
      edge(r + 1, d, c, front);
    }
  }
  function edge(r, a, b, front) {
    var al = rA[r] * front;
    if (al < 0.04) return;
    var h = (vH[a] + vH[b]) * 0.5;
    ctx.strokeStyle = LINE[Math.min(AL - 1, (al * AL) | 0)][Math.min(HL - 1, Math.round(h * (HL - 1)))];
    ctx.lineWidth = rW[r] + h * 0.8;
    ctx.beginPath();
    ctx.moveTo(sx[a], sy[a]); ctx.lineTo(sx[b], sy[b]);
    ctx.stroke();
  }

  /* 押したところの殻をふくらませる。いちばん手前の面だけに効かせる */
  function poke(x, y, amt) {
    var sg = Math.max(22, Math.min(W, H) * 0.06), lim = sg * sg * 6, best = 1e9, N = nR * M, v, dx, dy;
    for (v = 0; v < N; v++) {
      if (vF[v] <= 0) continue;
      dx = sx[v] - x; dy = sy[v] - y;
      if (dx * dx + dy * dy < lim && vD[v] < best) best = vD[v];
    }
    if (best === 1e9) return false;
    var tol = 0.35;
    for (v = 0; v < N; v++) {
      if (vF[v] <= 0 || vD[v] > best + tol) continue;
      dx = sx[v] - x; dy = sy[v] - y;
      var d2 = dx * dx + dy * dy;
      if (d2 >= lim) continue;
      var w = Math.exp(-d2 / (sg * sg)) * amt;
      var idx = rSlot[(v / M) | 0] * M + v % M;
      bump[idx] = Math.min(1, bump[idx] + w);
      heat[idx] = Math.min(1, heat[idx] + w * 2.5);
    }
    return true;
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; fitted = false; });

  /* ---------------------------------------------------------- 操作 */
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e);
    if (!poke(last.x, last.y, 0.75)) yawV += (last.x < ox ? -1 : 1) * 0.9;
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 6) return;
    var n = Math.ceil(dist / 12), hit = false;
    for (var i = 1; i <= n; i++) hit = poke(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, 0.22) || hit;
    if (!hit) yawV += (p.x - last.x) * 0.004;
    last = p;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speedK = this.value / 100; });
  document.getElementById('k-turn').addEventListener('input', function () { turnK = this.value / 100; });
  document.getElementById('k-smooth').addEventListener('click', function () {
    bump.fill(0); heat.fill(0);
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speedK = document.getElementById('k-speed').value / 100;
  turnK = document.getElementById('k-turn').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    time += dt;

    theta += dt * (0.12 + speedK * 1.2);
    var nt = Math.floor(theta / DTH);
    while (top < nt) {
      top++;
      var o = ((top + 1) % CAP) * M;
      for (var j = 0; j < M; j++) { bump[o + j] = 0; heat[o + j] = 0; }
      if (top % RIB === 0 && Math.random() < 0.6) SFX.play('grow', 1500);
    }

    morphT -= dt;
    if (morphT <= 0) pickTarget();
    var e = Math.min(1, dt * 0.07);
    cur.w += (tgt.w - cur.w) * e; cur.h += (tgt.h - cur.h) * e;
    cur.f += (tgt.f - cur.f) * e; cur.ba += (tgt.ba - cur.ba) * e;
    var tt = 1.25 - 0.85 * clamp01(cur.h / 1.6);
    tilt += (tt - tilt) * Math.min(1, dt * 0.3);

    yaw += dt * (turnK * 0.35 + yawV);
    yawV *= Math.exp(-dt * 1.2);

    var hk = Math.exp(-dt * 0.8);
    for (var i = 0; i < heat.length; i++) if (heat[i] > 0.002) heat[i] *= hk; else heat[i] = 0;
    if (down && last) poke(last.x, last.y, dt * 0.9);

    build();
    place(dt);
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
