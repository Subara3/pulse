<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('bubbles');

$pageName = 'bubbles';
$pageTitle = 'BUBBLES - PULSE';
$pageDesc = '水の底のいくつもの穴から泡が立ちのぼり、小さな泡はジグザグに、大きな泡は形を揺らしながら上がっていく。ぶつかった泡はくっついて大きくなり、水面でしばらく浮いてから弾ける。押すとそこから水色の泡がまとめて湧いてまわりの泡を押しのけ、なぞると水が指の向きに流れて泡の列が曲がる。';
$pageCanonical = 'https://pulse.subara3.com/bubbles.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-amount">AMOUNT</label><input type="range" id="k-amount" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-current">CURRENT</label><input type="range" id="k-current" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  var MAXB = 520;
  var bubbles = [];            /* {x,y,vx,vy,r,ph,zf,wob,wph,acc,surf,life} */
  var spray = [];              /* 水面で弾けたしぶき */
  var vents = [];              /* 水底の穴 */
  var motes = null;            /* 水中に浮かぶ細かな粒（流れを見せる） */
  var NM = 0;
  var surfY = 0, SN = 0, SD = 6, sh = null, sv = null;   /* 水面（1 次元の波） */
  var GX = 0, GY = 0, fu = null, fv = null, tu = null, tv = null;   /* 指が起こす流れ（粗い格子） */
  var bedY = null;             /* 水底の高さ（SD 刻み） */
  var amount = 0.55, size = 0.45, current = 0.4;
  var time = 0;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function alloc() {
    surfY = Math.round(H * 0.07);
    SN = Math.ceil(W / SD) + 2;
    sh = new Float32Array(SN); sv = new Float32Array(SN);
    bedY = new Float32Array(SN);
    var p1 = rnd(0, 6), p2 = rnd(0, 6);
    for (var i = 0; i < SN; i++) {
      var x = i * SD;
      bedY[i] = H - H * 0.05 - Math.sin(x * 0.006 + p1) * H * 0.015 - Math.sin(x * 0.017 + p2) * H * 0.006;
    }
    GX = 32; GY = Math.max(18, Math.round(GX * H / W));
    fu = new Float32Array(GX * GY); fv = new Float32Array(GX * GY);
    tu = new Float32Array(GX * GY); tv = new Float32Array(GX * GY);

    vents.length = 0;
    var nv = Math.max(5, Math.round(W / 85));
    for (var k = 0; k < nv; k++) {
      vents.push({
        x0: (k + 0.5 + rnd(-0.3, 0.3)) / nv * W,
        sw: rnd(20, 70), sf: rnd(0.02, 0.05), sp: rnd(0, 6),
        pf: rnd(0.08, 0.2), pp: rnd(0, 6), big: Math.random(),
        acc: 0, x: 0, y: 0
      });
    }
    NM = Math.round(W * H / 5000);
    motes = new Float32Array(NM * 3);
    for (var m = 0; m < NM; m++) {
      motes[m * 3] = Math.random() * W;
      motes[m * 3 + 1] = surfY + Math.random() * (H - surfY);
      motes[m * 3 + 2] = Math.random();
    }
    bubbles.length = 0; spray.length = 0;
    /* 開いた瞬間から画面に泡が満ちているように、数秒ぶん先に回しておく */
    for (var s = 0; s < 260; s++) update(1 / 30, true);
  }

  function bedAt(x) {
    var i = Math.max(0, Math.min(SN - 1, Math.round(x / SD)));
    return bedY[i];
  }
  function surfAt(x) {
    var i = Math.max(0, Math.min(SN - 1, Math.round(x / SD)));
    return surfY + sh[i] + Math.sin(x * 0.012 + time * 0.9) * 1.6;
  }

  /* 背景の流れ: ゆっくりうねる横向きの流れ */
  function meander(x, y) {
    return current * 34 * Math.sin(y * 0.006 - time * 0.25 + Math.sin(x * 0.003 + time * 0.07) * 2.2);
  }
  function gridIdx(x, y) {
    var gx = Math.max(0, Math.min(GX - 1, (x / W * GX) | 0));
    var gy = Math.max(0, Math.min(GY - 1, (y / H * GY) | 0));
    return gy * GX + gx;
  }
  function pushFlow(x, y, ux, uy, rad) {
    var cx = x / W * GX, cy = y / H * GY;
    for (var j = Math.max(0, Math.floor(cy - rad)); j <= Math.min(GY - 1, Math.ceil(cy + rad)); j++) {
      for (var i = Math.max(0, Math.floor(cx - rad)); i <= Math.min(GX - 1, Math.ceil(cx + rad)); i++) {
        var d = Math.hypot(i + 0.5 - cx, j + 0.5 - cy) / rad;
        if (d >= 1) continue;
        var w = (1 - d * d), k = j * GX + i;
        fu[k] += ux * w; fv[k] += uy * w;
      }
    }
  }

  function spawn(x, y, r, vx, vy, acc) {
    if (bubbles.length >= MAXB) return null;
    var b = {
      x: x, y: y, vx: vx || 0, vy: vy || 0, r: r,
      ph: rnd(0, 6.28), zf: rnd(4, 7), wob: 0, wph: rnd(0, 6.28),
      acc: acc || 0, surf: 0, life: 0
    };
    bubbles.push(b);
    return b;
  }
  function baseR() { return 2.6 + size * 7; }
  function maxR() { return 16 + size * 18; }

  function popAt(b, sound) {
    var i = Math.round(b.x / SD);
    for (var k = -2; k <= 2; k++) {
      var j = i + k;
      if (j > 0 && j < SN - 1) sv[j] += b.r * (k === 0 ? 2.4 : 1.1);
    }
    var n = b.r > 5 ? 2 + (Math.random() * 3 | 0) : 1;
    for (var s = 0; s < n; s++) {
      spray.push({ x: b.x + rnd(-b.r, b.r) * 0.5, y: b.y - b.r * 0.5, vx: rnd(-40, 40), vy: -rnd(50, 70 + b.r * 9), acc: b.acc });
    }
    if (sound && b.r > 6) SFX.play('pop', 2400);
  }

  /* ---------------------------------------------------------- 1 ステップ */
  function update(dt, warm) {
    time += dt;

    /* 流れの格子: 少しにじませてから減衰させる */
    var dec = Math.exp(-dt * 0.9);
    for (var j = 0; j < GY; j++) {
      for (var i = 0; i < GX; i++) {
        var k = j * GX + i;
        var su = fu[k] * 4, svv = fv[k] * 4, c = 4;
        if (i > 0) { su += fu[k - 1]; svv += fv[k - 1]; c++; }
        if (i < GX - 1) { su += fu[k + 1]; svv += fv[k + 1]; c++; }
        if (j > 0) { su += fu[k - GX]; svv += fv[k - GX]; c++; }
        if (j < GY - 1) { su += fu[k + GX]; svv += fv[k + GX]; c++; }
        tu[k] = su / c * dec; tv[k] = svv / c * dec;
      }
    }
    var t1 = fu; fu = tu; tu = t1; t1 = fv; fv = tv; tv = t1;

    /* 水底の穴: ゆっくり横へずれ、ときどき勢いよく吹く */
    for (var v = 0; v < vents.length; v++) {
      var vt = vents[v];
      vt.x = vt.x0 + Math.sin(time * vt.sf + vt.sp) * vt.sw;
      vt.y = bedAt(vt.x);
      var puff = Math.max(0, Math.sin(time * vt.pf + vt.pp));
      var rate = (1.5 + amount * 15) * (0.3 + puff * puff * 1.4);
      if (Math.random() < rate * dt) {
        var big = Math.random() < 0.12 + vt.big * 0.2 * puff;
        var r = big ? baseR() * rnd(1.1, 1.9) : baseR() * (0.25 + Math.pow(Math.random(), 2) * 0.8);
        spawn(vt.x + rnd(-3, 3), vt.y - r, r, rnd(-8, 8), -rnd(20, 50), vt.acc);
      }
      vt.acc *= Math.exp(-dt * 0.5);
    }

    /* 泡 */
    var n = bubbles.length;
    for (var q = n - 1; q >= 0; q--) {
      var b = bubbles[q];
      b.life += dt;
      b.acc *= Math.exp(-dt * 0.35);
      b.wob *= Math.exp(-dt * 2.2);
      b.wph += dt * (14 - Math.min(8, b.r * 0.3));
      if (b.surf > 0) {
        /* 水面に浮いている泡: 流れに乗って横へ寄り、やがて弾ける */
        b.surf -= dt;
        var gk0 = gridIdx(b.x, surfY + 4);
        b.vx += ((fu[gk0] + meander(b.x, surfY) * 0.3) - b.vx) * Math.min(1, dt * 1.5);
        b.x += b.vx * dt;
        b.y = surfAt(b.x) + b.r * 0.15;
        if (b.surf <= 0 || b.x < -40 || b.x > W + 40) {
          if (!warm) popAt(b, true); else popAt(b, false);
          bubbles.splice(q, 1);
        }
        continue;
      }
      var gk = gridIdx(b.x, b.y);
      var rise = 26 + 30 * Math.sqrt(b.r);
      var zig = 0;
      if (b.r > 2.2 && b.r < 10) { b.ph += b.zf * dt; zig = Math.cos(b.ph) * b.r * 7; }
      else if (b.r >= 10) { b.ph += 2.6 * dt; zig = Math.cos(b.ph) * 10; }
      var tx = fu[gk] + meander(b.x, b.y) + zig;
      var ty = -rise + fv[gk] * 0.7;
      var kk = Math.min(1, dt * (b.r < 4 ? 4 : 2.4));
      b.vx += (tx - b.vx) * kk;
      b.vy += (ty - b.vy) * kk;
      b.x += b.vx * dt;
      b.y += b.vy * dt;
      var bed = bedAt(b.x);
      if (b.y > bed - b.r) { b.y = bed - b.r; if (b.vy > 0) b.vy *= -0.3; }
      if (b.x < -60 || b.x > W + 60 || b.y > H + 40) { bubbles.splice(q, 1); continue; }
      if (b.y - b.r * 0.6 <= surfAt(b.x)) {
        /* 小さな泡はすぐ弾け、大きな泡ほど長く浮かぶ */
        b.surf = b.r < 2 ? 0.05 : rnd(0.6, 1.5) + b.r * 0.12;
        b.vx *= 0.4;
        var si = Math.round(b.x / SD);
        if (si > 0 && si < SN - 1) sv[si] -= b.r * 0.6;
        continue;
      }
      if (b.r > maxR()) {
        /* 大きくなりすぎた泡は割れる */
        var parts = 2 + (Math.random() * 2 | 0), cr = b.r / Math.cbrt(parts);
        bubbles.splice(q, 1);
        for (var p = 0; p < parts; p++) {
          var a = (p / parts) * 6.28 + rnd(-0.4, 0.4);
          var c2 = spawn(b.x + Math.cos(a) * cr * 0.6, b.y + Math.sin(a) * cr * 0.6, cr, b.vx + Math.cos(a) * 70, b.vy + Math.sin(a) * 40, b.acc);
          if (c2) c2.wob = 0.12;
        }
      }
    }

    /* 泡どうし: 重なったら押し合い、ときどきくっつく */
    n = bubbles.length;
    bubbles.sort(function (a, c) { return a.x - c.x; });
    for (var i1 = 0; i1 < n; i1++) {
      var A = bubbles[i1];
      if (!A || A.dead) continue;
      for (var i2 = i1 + 1; i2 < n; i2++) {
        var B = bubbles[i2];
        if (B.x - A.x > A.r + B.r + 2) break;
        if (B.dead) continue;
        var dx = B.x - A.x, dy = B.y - A.y, rr = A.r + B.r, d2 = dx * dx + dy * dy;
        if (d2 >= rr * rr) continue;
        var d = Math.sqrt(d2) || 0.01;
        if (A.surf <= 0 && B.surf <= 0 && Math.random() < dt * 2.5) {
          var va = A.r * A.r * A.r, vb = B.r * B.r * B.r, vs = va + vb;
          A.x = (A.x * va + B.x * vb) / vs; A.y = (A.y * va + B.y * vb) / vs;
          A.vx = (A.vx * va + B.vx * vb) / vs; A.vy = (A.vy * va + B.vy * vb) / vs;
          A.r = Math.cbrt(vs); A.wob = Math.min(0.18, A.wob + 0.12);
          A.acc = Math.max(A.acc, B.acc);
          B.dead = true;
          if (!warm && A.r > 9) SFX.play('collide', 1800);
          continue;
        }
        var push = (rr - d) * 0.5, nx = dx / d, ny = dy / d;
        A.x -= nx * push * 0.5; A.y -= ny * push * 0.5;
        B.x += nx * push * 0.5; B.y += ny * push * 0.5;
      }
    }
    for (var z = bubbles.length - 1; z >= 0; z--) if (bubbles[z].dead) bubbles.splice(z, 1);

    /* 水面の波 */
    for (var s = 1; s < SN - 1; s++) sv[s] += ((sh[s - 1] + sh[s + 1] - 2 * sh[s]) * 260 - sh[s] * 6) * dt;
    var sd = Math.exp(-dt * 1.6);
    for (var s2 = 1; s2 < SN - 1; s2++) { sv[s2] *= sd; sh[s2] += sv[s2] * dt; }

    /* しぶき */
    for (var w = spray.length - 1; w >= 0; w--) {
      var sp = spray[w];
      sp.vy += 320 * dt; sp.x += sp.vx * dt; sp.y += sp.vy * dt;
      if (sp.vy > 0 && sp.y > surfAt(sp.x)) spray.splice(w, 1);
    }

    /* 水中の粒 */
    for (var m = 0; m < NM; m++) {
      var mx = motes[m * 3], my = motes[m * 3 + 1];
      var mk = gridIdx(mx, my);
      mx += (fu[mk] + meander(mx, my)) * dt * 0.8;
      my += (fv[mk] * 0.8 + 3 + motes[m * 3 + 2] * 4) * dt;
      if (mx < 0) mx += W; else if (mx > W) mx -= W;
      if (my > H) my = surfY + 4;
      if (my < surfY + 2) my = H - 1;
      motes[m * 3] = mx; motes[m * 3 + 1] = my;
    }
  }

  /* ---------------------------------------------------------- 描く */
  function drawBubble(b) {
    var col = b.acc > 0.02 ? P.mix(P.line, P.accent, Math.min(1, b.acc * 1.4)) : P.line;
    var big = b.r >= 7;
    if (b.r < 1.8) {
      ctx.fillStyle = P.rgba(col, 0.55);
      ctx.fillRect(b.x - 1, b.y - 1, 2, 2);
      return;
    }
    ctx.beginPath();
    if (!big && b.wob < 0.02) {
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
    } else {
      /* 大きな泡は上下につぶれ、ぶつかった直後は形が揺れる */
      var flat = Math.min(0.22, Math.max(0, (b.r - 7) / 60));
      var osc = b.wob * Math.sin(b.wph);
      for (var k = 0; k <= 24; k++) {
        var a = k / 24 * Math.PI * 2, ca = Math.cos(a), sa = Math.sin(a);
        var rr = b.r * (1 + osc * Math.cos(2 * a) + b.wob * 0.25 * Math.sin(b.wph * 1.3) * Math.cos(3 * a));
        var px = b.x + ca * rr * (1 + flat), py = b.y + sa * rr * (1 - flat) * (sa > 0 ? 1 - flat * 0.8 : 1);
        if (k === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
      }
    }
    ctx.fillStyle = P.rgba(P.deep, 0.28);
    ctx.fill();
    ctx.strokeStyle = P.rgba(col, 0.62 + Math.min(0.3, b.r * 0.02));
    ctx.lineWidth = b.r > 10 ? 1.2 : 1;
    ctx.stroke();
    /* 光の当たる縁 */
    ctx.beginPath();
    ctx.arc(b.x, b.y, b.r * 0.66, -2.55, -1.75);
    ctx.strokeStyle = P.rgba(col, 0.85);
    ctx.lineWidth = Math.max(1, b.r * 0.1);
    ctx.stroke();
    if (b.r > 5) {
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r * 0.82, 0.5, 1.4);
      ctx.strokeStyle = P.rgba(col, 0.3);
      ctx.lineWidth = 1;
      ctx.stroke();
    }
  }

  var bgGrad = null, bgH = 0;
  function render() {
    if (!bgGrad || bgH !== H) {
      bgGrad = ctx.createLinearGradient(0, surfY, 0, H);
      bgGrad.addColorStop(0, P.rgba(P.mix(P.bg, P.steel, 0.12)));
      bgGrad.addColorStop(0.5, P.rgba(P.bg));
      bgGrad.addColorStop(1, P.rgba(P.deep));
      bgH = H;
    }
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, W, H);

    /* 水面から差しこむ光の筋 */
    ctx.save();
    for (var r = 0; r < 4; r++) {
      var cx = (0.15 + r * 0.24) * W + Math.sin(time * 0.05 + r * 1.7) * W * 0.06;
      var sw = W * (0.03 + 0.02 * Math.sin(time * 0.11 + r));
      var a = 0.028 + 0.018 * Math.sin(time * 0.17 + r * 2.1);
      var g = ctx.createLinearGradient(0, surfY, 0, H * 0.85);
      g.addColorStop(0, P.rgba(P.line, a));
      g.addColorStop(1, P.rgba(P.line, 0));
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.moveTo(cx - sw, surfY);
      ctx.lineTo(cx + sw, surfY);
      ctx.lineTo(cx + sw * 2.4 + H * 0.12, H * 0.85);
      ctx.lineTo(cx - sw * 0.6 + H * 0.12, H * 0.85);
      ctx.closePath();
      ctx.fill();
    }
    ctx.restore();

    /* 水中の粒 */
    ctx.fillStyle = P.rgba(P.steel, 0.35);
    for (var m = 0; m < NM; m++) {
      var s = 0.8 + motes[m * 3 + 2] * 0.9;
      ctx.fillRect(motes[m * 3], motes[m * 3 + 1], s, s);
    }

    /* 水底 */
    ctx.beginPath();
    ctx.moveTo(0, H);
    for (var i = 0; i < SN; i++) ctx.lineTo(i * SD, bedY[i]);
    ctx.lineTo(W, H);
    ctx.closePath();
    ctx.fillStyle = P.rgba(P.deep);
    ctx.fill();
    ctx.strokeStyle = P.rgba(P.steel, 0.55);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var i2 = 0; i2 < SN; i2++) { if (i2) ctx.lineTo(i2 * SD, bedY[i2]); else ctx.moveTo(0, bedY[0]); }
    ctx.stroke();
    for (var v = 0; v < vents.length; v++) {
      var vt = vents[v];
      ctx.beginPath();
      ctx.ellipse(vt.x, vt.y + 2, 7, 2.2, 0, 0, Math.PI * 2);
      ctx.strokeStyle = P.rgba(vt.acc > 0.05 ? P.mix(P.steel, P.accent, vt.acc) : P.steel, 0.7);
      ctx.stroke();
    }

    /* 泡 */
    for (var b = 0; b < bubbles.length; b++) if (bubbles[b].surf <= 0) drawBubble(bubbles[b]);

    /* 水面より上 */
    ctx.beginPath();
    ctx.moveTo(0, 0);
    for (var s1 = 0; s1 < SN; s1++) ctx.lineTo(s1 * SD, surfAt(s1 * SD));
    ctx.lineTo(W, 0);
    ctx.closePath();
    ctx.fillStyle = P.rgba(P.deep);
    ctx.fill();
    /* 水面に浮いた泡は水面の上に半分顔を出す */
    for (var b2 = 0; b2 < bubbles.length; b2++) if (bubbles[b2].surf > 0) drawBubble(bubbles[b2]);
    ctx.beginPath();
    for (var s2 = 0; s2 < SN; s2++) { var yy = surfAt(s2 * SD); if (s2) ctx.lineTo(s2 * SD, yy); else ctx.moveTo(0, yy); }
    ctx.strokeStyle = P.rgba(P.line, 0.7);
    ctx.lineWidth = 1.2;
    ctx.stroke();
    ctx.beginPath();
    for (var s3 = 0; s3 < SN; s3++) { var y3 = surfAt(s3 * SD) + 4; if (s3) ctx.lineTo(s3 * SD, y3); else ctx.moveTo(0, y3); }
    ctx.strokeStyle = P.rgba(P.steel, 0.25);
    ctx.lineWidth = 1;
    ctx.stroke();

    /* しぶき */
    for (var w = 0; w < spray.length; w++) {
      var sp = spray[w];
      ctx.fillStyle = P.rgba(sp.acc > 0.05 ? P.accent : P.line, 0.75);
      ctx.fillRect(sp.x - 1, sp.y - 1, 2, 2);
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, px = 0, py = 0, lastMoveT = 0, emitAcc = 0;
  function burst(x, y) {
    /* 指のまわりの泡を押しのけ、そこから泡をまとめて湧かせる */
    for (var i = 0; i < bubbles.length; i++) {
      var b = bubbles[i];
      if (b.surf > 0) continue;
      var dx = b.x - x, dy = b.y - y, d = Math.hypot(dx, dy);
      if (d < 150 && d > 0.1) {
        var f = (1 - d / 150) * 260;
        b.vx += dx / d * f; b.vy += dy / d * f;
        b.wob = Math.min(0.15, b.wob + 0.08 * (1 - d / 150));
        b.acc = Math.max(b.acc, 0.5 * (1 - d / 150));
      }
    }
    for (var k = 0; k < 8; k++) {
      var a = k / 8 * 6.28;
      pushFlow(x + Math.cos(a) * 30, y + Math.sin(a) * 30, Math.cos(a) * 90, Math.sin(a) * 90, 2);
    }
    var n = 24 + (size * 8 | 0);
    for (var j = 0; j < n; j++) {
      var ang = rnd(0, 6.28), sp = rnd(80, 260);
      var r = baseR() * (0.3 + Math.pow(Math.random(), 1.5) * 1.3);
      spawn(x + Math.cos(ang) * 4, y + Math.sin(ang) * 4, r, Math.cos(ang) * sp, Math.sin(ang) * sp, 1);
    }
    /* 水底を押したら、いちばん近い穴が水色に吹く */
    var best = null, bd = 1e9;
    for (var v = 0; v < vents.length; v++) { var dd = Math.abs(vents[v].x - x); if (dd < bd) { bd = dd; best = vents[v]; } }
    if (best && y > H * 0.7 && bd < 120) { best.acc = 1; best.pp = Math.PI / 2 - time * best.pf; }
  }

  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY; lastMoveT = performance.now();
    if (py < surfY + 10) {
      var si = Math.round(px / SD);
      for (var k = -4; k <= 4; k++) { var j = si + k; if (j > 0 && j < SN - 1) sv[j] += 60 * (1 - Math.abs(k) / 5); }
      SFX.play('ripple');
    } else {
      burst(px, py);
      SFX.play('spawn');
    }
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now(), dtm = Math.max(0.008, (now - lastMoveT) / 1000);
    var x = e.clientX, y = e.clientY, dx = x - px, dy = y - py;
    if (Math.hypot(dx, dy) < 2) return;
    var ux = Math.max(-500, Math.min(500, dx / dtm)), uy = Math.max(-500, Math.min(500, dy / dtm));
    var steps = Math.max(1, Math.ceil(Math.hypot(dx, dy) / 20));
    for (var s = 1; s <= steps; s++) pushFlow(px + dx * s / steps, py + dy * s / steps, ux * 0.35 / steps, uy * 0.35 / steps, 1.8);
    /* 指が通ったところの泡は、すぐに指の向きへ押される */
    for (var i = 0; i < bubbles.length; i++) {
      var b = bubbles[i];
      if (b.surf > 0) continue;
      var ex = b.x - x, ey = b.y - y, d = Math.hypot(ex, ey);
      if (d < 70) {
        var f = 1 - d / 70;
        b.vx += ux * 0.5 * f; b.vy += uy * 0.5 * f;
        b.acc = Math.max(b.acc, 0.35 * f);
        if (b.r > 12 && Math.hypot(ux, uy) > 350) b.r = maxR() + 1;   /* 勢いよく払うと大きな泡が割れる */
      }
    }
    if (y < surfY + 10) {
      var si = Math.round(x / SD);
      if (si > 0 && si < SN - 1) sv[si] += uy * 0.08 + 8;
    }
    px = x; py = y; lastMoveT = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readCtl() {
    amount = document.getElementById('k-amount').value / 100;
    size = document.getElementById('k-size').value / 100;
    current = document.getElementById('k-current').value / 100;
  }
  ['k-amount', 'k-size', 'k-current'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', readCtl);
  });
  readCtl();   /* 共有リンクから開いたとき、復元された値を拾う */

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    if (down && py > surfY + 10) {
      /* 押さえているあいだは、指の下から泡が湧き続ける */
      emitAcc += dt * (5 + amount * 5);
      while (emitAcc > 1) {
        emitAcc -= 1;
        spawn(px + rnd(-6, 6), py + rnd(-6, 6), baseR() * (0.3 + Math.random() * 0.9), rnd(-20, 20), -rnd(10, 60), 0.7);
      }
    }
    update(dt, false);
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
