<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('waterwheel');

$pageName = 'waterwheel';
$pageTitle = 'WATERWHEEL - PULSE';
$pageDesc = '上から水を受ける水車が、右に回ったり左に回ったりを、決まった順番なしにくり返す。押すとそこから水が落ちて下のカップにたまり、水車をなぞるとその向きに水車が回る。';
$pageCanonical = 'https://pulse.subara3.com/waterwheel.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-leak">LEAK</label><input type="range" id="k-leak" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-still">STILL</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 水車は半径 1 の単位で解く。カップは縁の 14 か所に吊ってあり、上の口から水を受け、底から少しずつ漏れる。
     重い側が下がって回るので、受ける・漏れるの釣り合いで回る向きが入れ替わる（マルカスの水車 = ローレンツ方程式） */
  var N = 14, CW = 0.12, CTOP = 0.06, CH = 0.24;
  var G = 4, NU = 2.5, I0 = 1, TS = 0.8;
  var mass = new Float32Array(N), glow = new Float32Array(N);
  var th = 0.05, om = 0;
  var flow = 1.5, leak = 0.1;
  var cx = 0, cy = 0, R = 1;

  /* 背景の線: 回る速さと、水の重心の高さを組にして残す。左右 2 つの輪を行き来する形になる */
  var TN = 2400, trW = new Float32Array(TN), trB = new Float32Array(TN), tHead = 0, tLen = 0;
  var wS = 1.5, bLo = 0, bHi = 1;

  /* 水滴: 0 は注ぐ水（カップに入ると消える）、1 は漏れた水 */
  var DN = 1400, dx = new Float32Array(DN), dy = new Float32Array(DN), dvx = new Float32Array(DN), dvy = new Float32Array(DN);
  var dk = new Uint8Array(DN), dacc = new Uint8Array(DN), dOn = new Uint8Array(DN), dNext = 0;

  var pour = null;          /* 指から落ちる水 { x, y }（単位系） */
  var grab = null;          /* 水車をなぞっている間 { a, w } */
  var lastSign = 1, revCool = 0;

  function cupX(i) { return Math.sin(th + 6.2832 * i / N); }
  function cupTop(i) { return -Math.cos(th + 6.2832 * i / N) + CTOP; }

  /* 単位系の (x, y) から真下に落ちる水が、最初に入るカップ */
  function cupBelow(x, y) {
    var best = -1, bt = 1e9, bw = 0;
    for (var i = 0; i < N; i++) {
      var d = Math.abs(cupX(i) - x), t = cupTop(i);
      if (d < CW && t > y && t < bt) { bt = t; best = i; bw = Math.min(1, (CW - d) / (CW * 0.3)); }
    }
    return best < 0 ? null : { i: best, w: bw };
  }

  function physics(h) {
    var a = cupBelow(0, -1.4);
    if (a) mass[a.i] += flow * a.w * h;
    if (pour) {
      var b = cupBelow(pour.x, pour.y);
      if (b) { mass[b.i] += 3 * b.w * h; glow[b.i] = 1; }
    }
    var tq = 0, M = 0;
    for (var i = 0; i < N; i++) {
      mass[i] -= leak * mass[i] * h;
      tq += mass[i] * Math.sin(th + 6.2832 * i / N);
      M += mass[i];
    }
    om += (G * tq - NU * om) / (I0 + M) * h;
    if (grab) om += (grab.w - om) * Math.min(1, h * 14);
    th += om * h;
  }

  function record() {
    var B = 0, M = 0;
    for (var i = 0; i < N; i++) { B += mass[i] * Math.cos(th + 6.2832 * i / N); M += mass[i]; }
    trW[tHead] = om; trB[tHead] = M > 0 ? B / M : 0;
    tHead = (tHead + 1) % TN; if (tLen < TN) tLen++;
  }

  /* 開いた瞬間から回っているように、先に 40 秒ぶん回しておく */
  function prewarm() {
    for (var s = 0; s < 40 * 60; s++) {
      physics(TS / 120); physics(TS / 120);
      if (s >= 40 * 60 - TN) record();
    }
    wS = 0.5; bLo = 1; bHi = -1;
    for (var k = 0; k < tLen; k++) { wS = Math.max(wS, Math.abs(trW[k])); bLo = Math.min(bLo, trB[k]); bHi = Math.max(bHi, trB[k]); }
  }

  function layout() {
    R = Math.min(W * 0.4, H * 0.34);
    cx = W / 2; cy = H * 0.53;
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; layout(); });

  /* ---------------------------------------------------------- 水滴 */
  function addDrop(x, y, vx, vy, kind, acc) {
    var k = dNext; dNext = (dNext + 1) % DN;
    dx[k] = x; dy[k] = y; dvx[k] = vx; dvy[k] = vy; dk[k] = kind; dacc[k] = acc ? 1 : 0; dOn[k] = 1;
  }
  function stepDrops(h) {
    var g = R * 5;
    for (var k = 0; k < DN; k++) {
      if (!dOn[k]) continue;
      dvy[k] += g * h; dx[k] += dvx[k] * h; dy[k] += dvy[k] * h;
      if (dy[k] > H + 10) { dOn[k] = 0; continue; }
      if (dk[k] === 0) {
        var ux = (dx[k] - cx) / R, uy = (dy[k] - cy) / R;
        for (var i = 0; i < N; i++) {
          var t = cupTop(i);
          if (uy > t && uy < t + CH && Math.abs(cupX(i) - ux) < CW * 0.9) { dOn[k] = 0; break; }
        }
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  function drawTrace() {
    if (tLen < 2) return;
    var sx = W * 0.44 / wS, mid = (bLo + bHi) / 2, sy = H * 0.4 / Math.max(0.05, (bHi - bLo) / 2);
    var start = (tHead - tLen + TN) % TN, CH8 = 8, per = Math.ceil(tLen / CH8);
    ctx.lineWidth = 1;
    for (var c = 0; c < CH8; c++) {
      ctx.strokeStyle = P.rgba(P.steel, 0.08 + 0.42 * (c + 1) / CH8);
      ctx.beginPath();
      for (var n = c * per; n <= Math.min(tLen - 1, (c + 1) * per); n++) {
        var k = (start + n) % TN;
        var x = W / 2 + trW[k] * sx, y = H / 2 - (trB[k] - mid) * sy;
        if (n === c * per) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      ctx.stroke();
    }
    var kl = (tHead - 1 + TN) % TN;
    ctx.fillStyle = P.rgba(P.line, 0.8);
    ctx.beginPath();
    ctx.arc(W / 2 + trW[kl] * sx, H / 2 - (trB[kl] - mid) * sy, 2.2, 0, 6.2832);
    ctx.fill();
  }

  function drawWheel() {
    var spoutY = cy - R * 1.4;
    /* 注ぎ口 */
    ctx.strokeStyle = P.rgba(P.line, 0.55);
    ctx.lineWidth = 1;
    ctx.strokeRect(cx - R * 0.05, -2, R * 0.1, spoutY + 2);

    /* 輪と輻 */
    ctx.strokeStyle = P.rgba(P.steel, 0.55);
    ctx.beginPath();
    for (var i = 0; i < N; i++) {
      var p = th + 6.2832 * i / N, s = Math.sin(p), c = Math.cos(p);
      ctx.moveTo(cx + s * R * 0.12, cy - c * R * 0.12);
      ctx.lineTo(cx + s * R, cy - c * R);
    }
    ctx.stroke();
    ctx.strokeStyle = P.rgba(P.line, 0.85);
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.arc(cx, cy, R, 0, 6.2832); ctx.stroke();
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(cx, cy, R * 0.94, 0, 6.2832); ctx.stroke();
    ctx.beginPath(); ctx.arc(cx, cy, R * 0.12, 0, 6.2832); ctx.stroke();
    ctx.beginPath(); ctx.arc(cx, cy, R * 0.04, 0, 6.2832); ctx.stroke();

    /* カップ: 縁の点から吊り下がり、いつも口を上に向けている */
    var hw = CW * R, top = CTOP * R, hgt = CH * R;
    for (var j = 0; j < N; j++) {
      var q = th + 6.2832 * j / N, px = cx + Math.sin(q) * R, py = cy - Math.cos(q) * R;
      var g = glow[j], fill = 1 - Math.exp(-mass[j] / 1.5);
      var y0 = py + top, y1 = y0 + hgt;
      /* 水 */
      if (fill > 0.01) {
        ctx.fillStyle = g > 0.02 ? P.rgba(P.mix(P.steel, P.accent, g), 0.35 + 0.4 * fill) : P.rgba(P.steel, 0.3 + 0.4 * fill);
        var wy = y1 - (hgt - 2) * fill;
        ctx.fillRect(px - hw * 0.86 + 1, wy, hw * 1.72 - 2, y1 - wy - 1);
        ctx.strokeStyle = P.rgba(g > 0.02 ? P.accent : P.line, 0.5 + 0.4 * Math.max(g, fill));
        ctx.beginPath(); ctx.moveTo(px - hw * 0.86 + 1, wy); ctx.lineTo(px + hw * 0.86 - 1, wy); ctx.stroke();
      }
      ctx.strokeStyle = g > 0.02 ? P.rgba(P.mix(P.line, P.accent, g), 0.95) : P.rgba(P.line, 0.85);
      ctx.lineWidth = 1.2 + g;
      ctx.beginPath();
      ctx.moveTo(px - hw, y0); ctx.lineTo(px - hw * 0.86, y1); ctx.lineTo(px + hw * 0.86, y1); ctx.lineTo(px + hw, y0);
      ctx.stroke();
      ctx.lineWidth = 1;
      ctx.strokeStyle = P.rgba(P.steel, 0.7);
      ctx.beginPath(); ctx.moveTo(px - hw, y0); ctx.lineTo(px, py); ctx.lineTo(px + hw, y0); ctx.stroke();
      ctx.fillStyle = P.rgba(P.line, 0.9);
      ctx.beginPath(); ctx.arc(px, py, 1.8, 0, 6.2832); ctx.fill();
    }
  }

  function drawDrops() {
    ctx.lineWidth = 1.3;
    for (var pass = 0; pass < 2; pass++) {
      ctx.strokeStyle = pass ? P.rgba(P.accent, 0.85) : P.rgba(P.line, 0.6);
      ctx.beginPath();
      for (var k = 0; k < DN; k++) {
        if (!dOn[k] || dacc[k] !== pass) continue;
        var L = Math.min(14, dvy[k] * 0.018 + 1.5);
        ctx.moveTo(dx[k], dy[k] - L); ctx.lineTo(dx[k], dy[k]);
      }
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, moved = 0, lastA = 0, lastMoveT = 0;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  function onWheel(p) { var r = Math.hypot(p.x - cx, p.y - cy) / R; return r > 0.2 && r < 1.45; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e); moved = 0;
    lastA = Math.atan2(last.x - cx, -(last.y - cy)); lastMoveT = performance.now();
    pour = { x: (last.x - cx) / R, y: (last.y - cy) / R };
    /* 指の真下のカップには、押した瞬間にまとめて水が入る */
    var b = cupBelow(pour.x, pour.y - CH);
    if (b) { mass[b.i] += 1.6; glow[b.i] = 1; }
    for (var n = 0; n < 10; n++) addDrop(last.x + (Math.random() - 0.5) * 8, last.y, 0, R * (0.2 + Math.random() * 0.6), 0, true);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), now = performance.now();
    moved += Math.hypot(p.x - last.x, p.y - last.y);
    pour.x = (p.x - cx) / R; pour.y = (p.y - cy) / R;
    var a = Math.atan2(p.x - cx, -(p.y - cy));
    if (moved > 8 && onWheel(p)) {
      /* なぞった角度の速さで水車を回す。指を止めれば水車も止まる */
      var da = a - lastA;
      if (da > Math.PI) da -= 6.2832; else if (da < -Math.PI) da += 6.2832;
      var w = da / Math.max(0.008, (now - lastMoveT) / 1000) / TS;
      w = Math.max(-6, Math.min(6, w));
      grab = { w: grab ? grab.w * 0.4 + w * 0.6 : w };
      for (var i = 0; i < N; i++) {
        var q = th + 6.2832 * i / N;
        if (Math.hypot(cx + Math.sin(q) * R - p.x, cy - Math.cos(q) * R - p.y) < R * 0.5) glow[i] = Math.max(glow[i], 0.8);
      }
      SFX.play('move', 140);
    } else {
      grab = null;
    }
    lastA = a; lastMoveT = now; last = p;
  });
  function up() { down = false; pour = null; grab = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readFlow() { flow = 0.8 + document.getElementById('k-flow').value / 100 * 1.4; }
  function readLeak() { leak = 0.04 + document.getElementById('k-leak').value / 100 * 0.12; }
  document.getElementById('k-flow').addEventListener('input', readFlow);
  document.getElementById('k-leak').addEventListener('input', readLeak);
  document.getElementById('k-still').addEventListener('click', function () {
    mass.fill(0); om = 0; th += (Math.random() - 0.5) * 0.2;
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readFlow(); readLeak();
  prewarm();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), spoutAcc = 0, pourAcc = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    /* 指が止まったまま水車の上にあるとき、なぞりの速さを 0 へ戻す */
    if (grab && now - lastMoveT > 80) grab.w *= Math.pow(0.02, dt);
    var n = Math.max(1, Math.ceil(dt * 120));
    for (var s = 0; s < n; s++) physics(dt * TS / n);
    record();
    for (var i = 0; i < N; i++) glow[i] = Math.max(0, glow[i] - dt * 1.2);

    /* 向きが変わったら小さく鳴らす（数秒に 1 回まで） */
    revCool -= dt;
    var sg = om >= 0 ? 1 : -1;
    if (sg !== lastSign && Math.abs(om) > 0.02) { if (revCool <= 0) { SFX.play('tick'); revCool = 3; } lastSign = sg; }

    /* 図の大きさは、最近の揺れ幅にゆっくり合わせる */
    var tw = 0.5, tlo = 1, thi = -1;
    for (var k = 0; k < tLen; k++) { tw = Math.max(tw, Math.abs(trW[k])); tlo = Math.min(tlo, trB[k]); thi = Math.max(thi, trB[k]); }
    var ease = Math.min(1, dt * 1.5);
    wS += (tw - wS) * ease; bLo += (tlo - bLo) * ease; bHi += (thi - bHi) * ease;

    spoutAcc += dt * (20 + flow * 18);
    while (spoutAcc > 1) { spoutAcc -= 1; addDrop(cx + (Math.random() - 0.5) * R * 0.07, cy - R * 1.4, 0, R * 0.4, 0, false); }
    if (pour) {
      pourAcc += dt * 45;
      while (pourAcc > 1) { pourAcc -= 1; addDrop(cx + pour.x * R + (Math.random() - 0.5) * 6, cy + pour.y * R, 0, R * 0.3, 0, true); }
      if (cupBelow(pour.x, pour.y)) SFX.play('ripple', 450);
    }
    for (var j = 0; j < N; j++) {
      if (Math.random() < mass[j] * leak * dt * 30) {
        var q = th + 6.2832 * j / N;
        addDrop(cx + Math.sin(q) * R + (Math.random() - 0.5) * CW * R, cy - Math.cos(q) * R + (CTOP + CH) * R,
          Math.cos(q) * om * R * TS, Math.sin(q) * om * R * TS * 0.3 + R * 0.1, 1, false);
      }
    }
    stepDrops(dt);

    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H);
    drawTrace();
    drawDrops();
    drawWheel();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
