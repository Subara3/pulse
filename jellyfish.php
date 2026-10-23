<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('jellyfish');

$pageName = 'jellyfish';
$pageTitle = 'JELLYFISH - PULSE';
$pageDesc = '暗い水の中を何十匹ものクラゲが、傘を縮めては開きながらゆっくり昇っていく。近くのクラゲどうしで傘を縮める拍子と進む向きが揃い、ゆるい群れになって漂う。押すとそこから小さなクラゲが生まれ、まわりのクラゲが一斉に傘を縮めて指から離れる。なぞると指の向きに水が流れて、触手がなびく。';
$pageCanonical = 'https://pulse.subara3.com/jellyfish.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-sync">SYNC</label><input type="range" id="k-sync" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-current">CURRENT</label><input type="range" id="k-current" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" id="k-stir">STIR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx, S = 1;

  /* ---------------------------------------------------------- クラゲ
     傘を縮めた勢いで前へ進む。縮める拍子は近くの仲間に引っぱられて揃い、向きも少しずつ揃う */
  var TN = 7, TS = 16, AN = 3, AS = 10;   /* 触手の本数と節の数、口の腕の本数と節の数 */
  var jellies = [];
  var sync = 0.45, current = 0.4;

  function rot(j, lx, ly) {
    /* 傘の座標（ly が正なら後ろ）を画面の座標へ */
    var cs = Math.cos(j.th), sn = Math.sin(j.th);
    return [j.x + cs * lx - sn * ly, j.y + sn * lx + cs * ly];
  }

  function makeJelly(x, y) {
    var z = 0.3 + Math.pow(Math.random(), 0.8) * 0.7;
    var r = (9 + z * 30) * S;
    var j = {
      x: x, y: y, vx: 0, vy: 0, z: z, r: r, r0: r, tk: 0.18 + Math.random() * 0.1,
      th: (Math.random() - 0.5) * 0.8,
      ph: Math.random(), per: (2.1 + z * 1.1) * (0.92 + Math.random() * 0.16),
      c: 0, acc: 0, fired: false,
      tl: [], al: [], tlen: 0, alen: r * 0.13
    };
    j.tlen = r * j.tk;
    var k, n;
    for (k = 0; k < TN; k++) j.tl.push(new Float32Array(TS * 4));
    for (k = 0; k < AN; k++) j.al.push(new Float32Array(AS * 4));
    hang(j);
    return j;
  }
  /* 触手を傘の後ろへまっすぐ垂らして置く */
  function hang(j) {
    var k, n, a;
    for (k = 0; k < TN; k++) {
      a = anchorT(j, k);
      for (n = 0; n < TS; n++) {
        var t = j.tl[k];
        t[n * 4] = t[n * 4 + 2] = a[0] - Math.sin(j.th) * j.tlen * n;
        t[n * 4 + 1] = t[n * 4 + 3] = a[1] + Math.cos(j.th) * j.tlen * n;
      }
    }
    for (k = 0; k < AN; k++) {
      a = anchorA(j, k);
      for (n = 0; n < AS; n++) {
        var o = j.al[k];
        o[n * 4] = o[n * 4 + 2] = a[0] - Math.sin(j.th) * j.alen * n;
        o[n * 4 + 1] = o[n * 4 + 3] = a[1] + Math.cos(j.th) * j.alen * n;
      }
    }
  }
  function bellW(j) { return j.r * (1 - 0.3 * j.c); }
  function bellH(j) { return j.r * (0.72 + 0.3 * j.c); }
  function anchorT(j, k) {
    var w = bellW(j);
    return rot(j, (k / (TN - 1) - 0.5) * 1.75 * w, bellH(j) * 0.06);
  }
  function anchorA(j, k) {
    var w = bellW(j);
    return rot(j, (k - (AN - 1) / 2) * 0.16 * w, 0);
  }

  function build() {
    S = Math.max(0.72, Math.min(1.3, Math.max(W, H) / 1100));
    var n = Math.round(Math.max(32, Math.min(60, W * H / 16000)));
    jellies = [];
    for (var i = 0; i < n; i++) jellies.push(makeJelly(Math.random() * W, Math.random() * (H + 120 * S) - 40 * S));
    jellies.sort(function (a, b) { return a.z - b.z; });
    snow = new Float32Array(SN * 3);
    for (i = 0; i < SN; i++) { snow[i * 3] = Math.random() * W; snow[i * 3 + 1] = Math.random() * H; snow[i * 3 + 2] = Math.random(); }
  }

  /* ---------------------------------------------------------- 水の流れ */
  var SN = 260, snow = null;
  var blobs = [], bursts = [];
  var T = 0;
  function flowAt(x, y, out) {
    var u = Math.sin(y * 0.0042 + T * 0.11) * 14 + Math.sin(y * 0.011 - T * 0.07 + x * 0.002) * 6;
    var v = Math.cos(x * 0.0035 + T * 0.08) * 6;
    u *= current * 1.6; v *= current * 1.6;
    for (var i = 0; i < blobs.length; i++) {
      var b = blobs[i], dx = x - b.x, dy = y - b.y, d2 = dx * dx + dy * dy, R = 130 * S;
      if (d2 > R * R * 6) continue;
      var w = b.a * Math.exp(-d2 / (R * R));
      u += b.vx * w; v += b.vy * w;
    }
    for (i = 0; i < bursts.length; i++) {
      var q = bursts[i], ex = x - q.x, ey = y - q.y, d = Math.sqrt(ex * ex + ey * ey) + 1, RR = 170 * S;
      if (d > RR * 2.5) continue;
      var m = q.a * 170 * Math.exp(-d * d / (RR * RR)) * Math.min(1, d / 30);
      u += ex / d * m; v += ey / d * m;
    }
    out[0] = u; out[1] = v;
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 動かす */
  var fl = [0, 0];
  function contraction(ph) {
    /* すばやく縮めて、ゆっくり開く */
    if (ph < 0.18) return 0.5 * (1 - Math.cos(Math.PI * ph / 0.18));
    return 0.5 * (1 + Math.cos(Math.PI * (ph - 0.18) / 0.82));
  }
  function angDiff(a, b) {
    var d = a - b;
    while (d > Math.PI) d -= 6.2832;
    while (d < -Math.PI) d += 6.2832;
    return d;
  }
  var fireCount = 0;
  function fire(i) {
    var j = jellies[i], R = 170 * S, k = sync * 0.16;
    fireCount++;
    if (k <= 0) return;
    for (var m = 0; m < jellies.length; m++) {
      if (m === i) continue;
      var o = jellies[m];
      if (o.ph < 0.3) continue;
      var dx = o.x - j.x, dy = o.y - j.y, d = Math.sqrt(dx * dx + dy * dy);
      if (d > R) continue;
      o.ph = Math.min(0.999, o.ph + k * (1 - d / R) * (0.3 + o.ph));
    }
  }
  function step(dt) {
    var n = jellies.length, i, m, k, s;
    fireCount = 0;
    for (i = 0; i < n; i++) {
      var j = jellies[i];
      j.ph += dt / j.per;
      if (j.ph >= 1) { j.ph -= 1; fire(i); }
      var c0 = j.c;
      j.c = contraction(j.ph);
      var dc = j.c - c0;
      if (dc > 0) {
        var push = dc * j.r * 1.9;
        j.vx += Math.sin(j.th) * push; j.vy -= Math.cos(j.th) * push;
      }
      /* 近くの仲間と向きを揃え、離れすぎず、ぶつからない */
      var ax = 0, ay = 0, cx = 0, cy = 0, cw = 0, sep = 0, sx = 0, sy = 0;
      for (m = 0; m < n; m++) {
        if (m === i) continue;
        var o = jellies[m], dx = o.x - j.x, dy = o.y - j.y, d2 = dx * dx + dy * dy, R = 200 * S;
        if (d2 > R * R) continue;
        var d = Math.sqrt(d2) + 0.01, w = 1 - d / R;
        ax += Math.sin(o.th) * w; ay += Math.cos(o.th) * w;
        cx += dx * w; cy += dy * w; cw += w;
        var near = (j.r + o.r) * 1.15;
        if (d < near) { s = (1 - d / near); sx -= dx / d * s; sy -= dy / d * s; sep++; }
      }
      var turn = -j.th * 0.12 + (Math.random() - 0.5) * 1.4;
      if (cw > 0) {
        turn += angDiff(Math.atan2(ax, ay), j.th) * 0.5;
        j.vx += cx / cw * 0.015 * dt; j.vy += cy / cw * 0.015 * dt;
      }
      if (sep) { j.vx += sx * 70 * dt; j.vy += sy * 70 * dt; }
      j.th = angDiff(j.th + turn * dt, 0);
      var drag = Math.exp(-1.25 * dt);
      j.vx *= drag; j.vy *= drag;
      flowAt(j.x, j.y, fl);
      j.x += (j.vx + fl[0] * (1.1 - j.z * 0.4)) * dt;
      j.y += (j.vy + fl[1] * (1.1 - j.z * 0.4) + 3) * dt;
      j.acc = Math.max(0, j.acc - dt * 0.4);
      /* 生まれたばかりの小さなクラゲは、ゆっくり大きくなる */
      if (j.r < j.r0) { j.r = Math.min(j.r0, j.r + j.r0 * 0.02 * dt); j.tlen = j.r * j.tk; j.alen = j.r * 0.13; }

      /* 画面の外へ出たら反対側から戻す。触手も同じだけずらす */
      var mg = j.r * 5, ox = 0, oy = 0;
      if (j.y < -mg) oy = H + mg * 0.4 - j.y;
      else if (j.y > H + mg) oy = -mg * 0.4 - j.y;
      if (j.x < -mg) ox = W + mg * 0.6 - j.x;
      else if (j.x > W + mg) ox = -mg * 0.6 - j.x;
      if (oy && !ox) ox = Math.random() * W - j.x;
      if (ox || oy) {
        j.x += ox; j.y += oy;
        hang(j);
      }

      /* 触手: 前の位置との差で惰性をつけ、根元から順に長さを保つ */
      for (k = 0; k < TN + AN; k++) {
        var arm = k >= TN, t = arm ? j.al[k - TN] : j.tl[k], ns = arm ? AS : TS, L = arm ? j.alen : j.tlen;
        var a = arm ? anchorA(j, k - TN) : anchorT(j, k);
        t[0] = t[2] = a[0]; t[1] = t[3] = a[1];
        for (s = 1; s < ns; s++) {
          var p = s * 4, px = t[p], py = t[p + 1];
          flowAt(px, py, fl);
          var nx = px + (px - t[p + 2]) * 0.9 + fl[0] * dt * 0.06 * (1 + s * 0.08);
          var ny = py + (py - t[p + 3]) * 0.9 + fl[1] * dt * 0.06 * (1 + s * 0.08) + 6 * dt * dt;
          t[p + 2] = px; t[p + 3] = py;
          var qx = nx - t[p - 4], qy = ny - t[p - 3], ql = Math.sqrt(qx * qx + qy * qy) + 1e-6;
          t[p] = t[p - 4] + qx / ql * L; t[p + 1] = t[p - 3] + qy / ql * L;
        }
      }
    }
    if (fireCount >= Math.max(3, n * 0.18) && T - lastChord > 5) { SFX.play('tick', 4000); lastChord = T; }

    for (i = blobs.length - 1; i >= 0; i--) { blobs[i].a -= dt * 0.9; if (blobs[i].a <= 0) blobs.splice(i, 1); }
    for (i = bursts.length - 1; i >= 0; i--) { bursts[i].a -= dt * 1.4; if (bursts[i].a <= 0) bursts.splice(i, 1); }

    for (i = 0; i < SN; i++) {
      var sxi = snow[i * 3], syi = snow[i * 3 + 1];
      flowAt(sxi, syi, fl);
      sxi += fl[0] * dt; syi += (fl[1] + 4 + snow[i * 3 + 2] * 5) * dt;
      if (syi > H + 4) { syi = -4; sxi = Math.random() * W; }
      if (syi < -6) syi = H + 4;
      if (sxi < -4) sxi = W + 4; else if (sxi > W + 4) sxi = -4;
      snow[i * 3] = sxi; snow[i * 3 + 1] = syi;
    }
  }

  /* ---------------------------------------------------------- 描く */
  var shine = null;
  function drawJelly(j) {
    var w = bellW(j), h = bellH(j), z = j.z;
    var col = P.mix(P.mix(P.steel, P.line, z), P.accent, j.acc);
    var al = 0.28 + z * 0.62;
    var k, s, t, ns;

    /* 触手 */
    ctx.lineWidth = 0.6 + z * 0.5;
    ctx.strokeStyle = P.rgba(col, al * 0.55);
    ctx.beginPath();
    for (k = 0; k < TN; k++) {
      t = j.tl[k];
      ctx.moveTo(t[0], t[1]);
      for (s = 1; s < TS; s++) ctx.lineTo(t[s * 4], t[s * 4 + 1]);
    }
    ctx.stroke();
    /* 口の腕: 少し太く、ひだを波打たせる */
    ctx.lineWidth = 0.8 + z * 1.2;
    ctx.strokeStyle = P.rgba(col, al * 0.42);
    ctx.beginPath();
    for (k = 0; k < AN; k++) {
      t = j.al[k];
      ctx.moveTo(t[0], t[1]);
      for (s = 1; s < AS; s++) {
        var p = s * 4, ddx = t[p] - t[p - 4], ddy = t[p + 1] - t[p - 3], dl = Math.sqrt(ddx * ddx + ddy * ddy) + 1e-6;
        var wv = Math.sin(s * 1.4 + T * 2.2 + k * 2) * j.r * 0.07 * (s / AS + 0.3);
        ctx.lineTo(t[p] - ddy / dl * wv, t[p + 1] + ddx / dl * wv);
      }
    }
    ctx.stroke();

    /* 傘 */
    ctx.save();
    ctx.translate(j.x, j.y);
    ctx.rotate(j.th);
    ctx.beginPath();
    ctx.moveTo(-w * 0.9, h * 0.06);
    ctx.bezierCurveTo(-w * 1.06, -h * 0.55, -w * 0.58, -h, 0, -h);
    ctx.bezierCurveTo(w * 0.58, -h, w * 1.06, -h * 0.55, w * 0.9, h * 0.06);
    ctx.quadraticCurveTo(0, -h * (0.14 + j.c * 0.12), -w * 0.9, h * 0.06);
    ctx.closePath();
    ctx.fillStyle = P.rgba(P.mix(P.bg, col, 0.07 + j.acc * 0.08), 0.72);
    ctx.fill();
    ctx.lineWidth = 0.8 + z * 0.7;
    ctx.strokeStyle = P.rgba(col, al);
    ctx.stroke();

    /* 傘の内側の線: 内側のドームと、中心から縁へ走る筋 */
    ctx.lineWidth = 0.6;
    ctx.strokeStyle = P.rgba(col, al * 0.35);
    ctx.beginPath();
    ctx.moveTo(-w * 0.62, -h * 0.02);
    ctx.bezierCurveTo(-w * 0.7, -h * 0.5, -w * 0.38, -h * 0.74, 0, -h * 0.74);
    ctx.bezierCurveTo(w * 0.38, -h * 0.74, w * 0.7, -h * 0.5, w * 0.62, -h * 0.02);
    for (k = -2; k <= 2; k++) {
      ctx.moveTo(k * w * 0.08, -h * 0.9);
      ctx.quadraticCurveTo(k * w * 0.32, -h * 0.55, k * w * 0.4, h * 0.0);
    }
    ctx.stroke();
    /* 傘の中の 4 つの輪 */
    ctx.strokeStyle = P.rgba(col, al * 0.5);
    ctx.beginPath();
    for (k = 0; k < 4; k++) {
      var gx = (k - 1.5) * w * 0.2, gy = -h * 0.48 + Math.abs(k - 1.5) * h * 0.08;
      ctx.moveTo(gx + w * 0.07, gy);
      ctx.ellipse(gx, gy, w * 0.07, h * 0.1, 0, 0, 6.2832);
    }
    ctx.stroke();
    ctx.restore();
  }

  function draw() {
    P.fill(ctx, W, H);
    /* 上から差す光 */
    if (!shine || shine.h !== H) {
      shine = ctx.createLinearGradient(0, 0, 0, H);
      shine.addColorStop(0, P.rgba(P.steel, 0.16));
      shine.addColorStop(0.55, P.rgba(P.steel, 0.03));
      shine.addColorStop(1, P.rgba(P.deep, 0.5));
      shine.h = H;
    }
    ctx.fillStyle = shine;
    ctx.fillRect(0, 0, W, H);
    P.grid(ctx, W, H, { alpha: 0.45 });

    for (var i = 0; i < SN; i++) {
      var u = snow[i * 3 + 2];
      ctx.fillStyle = P.rgba(P.steel, 0.2 + u * 0.35);
      ctx.fillRect(snow[i * 3], snow[i * 3 + 1], 1 + u, 1 + u);
    }
    for (i = 0; i < jellies.length; i++) drawJelly(jellies[i]);

    ctx.lineWidth = 1;
    for (var k = rings.length - 1; k >= 0; k--) {
      var rg = rings[k];
      var a = 1 - rg.t / 1.6;
      if (a <= 0) { rings.splice(k, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, a * 0.4);
      ctx.beginPath(); ctx.arc(rg.x, rg.y, 10 + rg.t * 90 * S, 0, Math.PI * 2); ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastTime = 0, rings = [], lastChord = 0;
  function startle(x, y, R) {
    var n = 0;
    for (var i = 0; i < jellies.length; i++) {
      var j = jellies[i], dx = j.x - x, dy = j.y - y, d = Math.sqrt(dx * dx + dy * dy) + 0.01;
      if (d > R) continue;
      var f = 1 - d / R;
      /* 指から離れる向きへ傘を向け、すぐに縮める */
      var away = Math.atan2(dx, -dy);
      j.th = angDiff(j.th + angDiff(away, j.th) * (0.45 + f * 0.4), 0);
      if (j.ph > 0.25) j.ph = 0.999;
      j.vx += dx / d * 70 * f * S; j.vy += dy / d * 70 * f * S;
      j.acc = Math.min(1, j.acc + 0.5 + f * 0.5);
      n++;
    }
    return n;
  }
  /* 押したところから小さなクラゲが生まれる。画面の外にいるものから順に呼び寄せて使う */
  function hatch(x, y) {
    var order = jellies.slice().sort(function (a, b) { return out(b) - out(a); });
    for (var k = 0; k < 3; k++) {
      var j = order[k], a = k / 3 * 6.2832 + Math.random();
      j.x = x + Math.cos(a) * 8 * S; j.y = y + Math.sin(a) * 8 * S;
      j.th = Math.atan2(Math.cos(a), -Math.sin(a));
      j.z = 0.55 + Math.random() * 0.3; j.r0 = (9 + j.z * 30) * S; j.r = j.r0 * 0.55;
      j.tlen = j.r * j.tk; j.alen = j.r * 0.13;
      j.vx = j.vy = 0; j.ph = 0.999; j.c = 0; j.acc = 1;
      hang(j);
    }
    jellies.sort(function (a, b) { return a.z - b.z; });
  }
  function out(j) {
    var dx = Math.max(0, -j.x, j.x - W), dy = Math.max(0, -j.y, j.y - H);
    return dx + dy + Math.random() * 10;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; lastTime = performance.now();
    hatch(last.x, last.y);
    startle(last.x, last.y, 240 * S);
    bursts.push({ x: last.x, y: last.y, a: 1 });
    rings.push({ x: last.x, y: last.y, t: 0 });
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY }, now = performance.now();
    var dx = p.x - last.x, dy = p.y - last.y, dist = Math.sqrt(dx * dx + dy * dy);
    if (dist < 6) return;
    var sp = Math.min(1, 900 / Math.max(1, dist / Math.max(0.008, (now - lastTime) / 1000)));
    var vx = dx / Math.max(0.008, (now - lastTime) / 1000) * sp, vy = dy / Math.max(0.008, (now - lastTime) / 1000) * sp;
    blobs.push({ x: p.x, y: p.y, vx: vx * 0.4, vy: vy * 0.4, a: 1 });
    if (blobs.length > 40) blobs.shift();
    for (var i = 0; i < jellies.length; i++) {
      var j = jellies[i], ex = j.x - p.x, ey = j.y - p.y;
      if (ex * ex + ey * ey < 140 * 140 * S * S) j.acc = Math.min(1, j.acc + 0.08);
    }
    last = p; lastTime = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-sync').addEventListener('input', function () { sync = this.value / 100; });
  document.getElementById('k-current').addEventListener('input', function () { current = this.value / 100; });
  document.getElementById('k-stir').addEventListener('click', function () {
    for (var i = 0; i < jellies.length; i++) { jellies[i].ph = Math.random(); jellies[i].th = (Math.random() - 0.5) * 1.6; }
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  sync = document.getElementById('k-sync').value / 100;
  current = document.getElementById('k-current').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now; T += dt;
    for (var k = 0; k < rings.length; k++) rings[k].t += dt;
    step(dt);
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
