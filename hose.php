<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hose');

$pageName = 'hose';
$pageTitle = 'HOSE - PULSE';
$pageDesc = '上から吊るした何本ものホースが、先から噴き出す水の勢いで勝手に揺れ出して暴れ、水が弧を描いて下の池に落ちる。押すといちばん近いホースの先をつかんで水が水色になり、なぞるとそのホースを振り回せて、放すとまた勝手に暴れ出す。';
$pageCanonical = 'https://pulse.subara3.com/hose.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-press">PRESSURE</label><input type="range" id="k-press" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="60"></div>
  <div class="ctl-row"><label for="k-spray">SPRAY</label><input type="range" id="k-spray" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 水滴: 型付き配列に詰めて、消えたものは末尾と入れ替える */
  var MAXP = 20000;
  var px = new Float32Array(MAXP), py = new Float32Array(MAXP);
  var vx = new Float32Array(MAXP), vy = new Float32Array(MAXP);
  var age = new Float32Array(MAXP);
  var fl = new Uint8Array(MAXP);           /* 1: 水色 / 2: しぶき（着水しても何も起こさない） */
  var np = 0;

  /* 池から立つ霧 */
  var MAXM = 700;
  var mx = new Float32Array(MAXM), my = new Float32Array(MAXM), mvx = new Float32Array(MAXM);
  var mage = new Float32Array(MAXM), mlife = new Float32Array(MAXM);
  var nm = 0;

  /* ホース: 節を棒でつないだ鎖。先端に、噴く水の反動が後ろ向きにかかる */
  var NODE = 22;
  var hoses = [];
  var ripples = [];
  var poolY = 0, G = 0, GH = 0, R = 40, segL = 10, vjet = 0;
  var bg = null;
  var T = 0, press = 0.5, flow = 0.6, spray = 0.4;
  var finger = { on: false, x: 0, y: 0, vx: 0, vy: 0, t: 0, inPool: false, hose: null, node: 0 };
  var FIX = 1 / 120, accT = 0;

  function rnd(a, b) { return a + Math.random() * (b - a); }
  function gauss() { return (Math.random() + Math.random() + Math.random() - 1.5) * 1.15; }

  function addP(x, y, ux, uy, f) {
    if (np >= MAXP) return;
    px[np] = x; py[np] = y; vx[np] = ux; vy[np] = uy; age[np] = 0; fl[np] = f; np++;
  }
  function killP(k) {
    np--;
    px[k] = px[np]; py[k] = py[np]; vx[k] = vx[np]; vy[k] = vy[np]; age[k] = age[np]; fl[k] = fl[np];
  }
  function addMist(x, y) {
    if (nm >= MAXM) return;
    mx[nm] = x; my[nm] = y; mvx[nm] = rnd(-10, 10); mage[nm] = 0; mlife[nm] = rnd(2.5, 5); nm++;
  }
  function ripple(x, big, cyan) {
    if (ripples.length > 110 && !big) return;
    ripples.push({ x: x, t: 0, life: big ? 2.6 : rnd(1.0, 1.8), sp: big ? 70 : rnd(18, 34), cyan: !!cyan });
  }

  function layout() {
    var tall = H > W;
    poolY = Math.round(H * (tall ? 0.86 : 0.84));
    G = H * 0.95; GH = H * 0.8;
    R = Math.max(30, Math.min(56, Math.min(W, H) * 0.06));
    vjet = Math.sqrt(2 * G * H * 0.4);
    var n = Math.max(2, Math.min(5, Math.round(W / 300)));
    var len = H * (tall ? 0.46 : 0.5);
    segL = len / (NODE - 1);
    hoses = [];
    for (var i = 0; i < n; i++) {
      var ax = W * (n === 1 ? 0.5 : 0.5 + (i - (n - 1) / 2) * Math.min(0.8 / (n - 1), 0.4));
      var h = { ax: ax, ay: -4, x: new Float32Array(NODE), y: new Float32Array(NODE),
                ox: new Float32Array(NODE), oy: new Float32Array(NODE),
                ph: i * 2.1 + Math.random(), rate: rnd(0.07, 0.12), acc: Math.random(), cyan: 0, boost: 0, tx: 0, ty: 1 };
      /* 少しだけ曲げて吊るしておく。揺れはこのわずかな差から育つ */
      var tilt = rnd(-0.5, 0.5);
      for (var k = 0; k < NODE; k++) {
        var s = k / (NODE - 1);
        h.x[k] = h.ox[k] = ax + Math.sin(s * 3) * tilt * segL * 2;
        h.y[k] = h.oy[k] = h.ay + k * segL;
      }
      hoses.push(h);
    }
    np = 0; nm = 0; ripples.length = 0;

    /* 地は一度だけ描いておく: 方眼、池の縁、池の奥 */
    var bgc = document.createElement('canvas');
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    bgc.width = Math.round(W * dpr); bgc.height = Math.round(H * dpr);
    var b = bgc.getContext('2d');
    b.setTransform(dpr, 0, 0, dpr, 0, 0);
    P.fill(b, W, H, 1);
    b.save(); b.beginPath(); b.rect(0, 0, W, poolY); b.clip();
    P.grid(b, W, H, { step: 32, major: 4, alpha: 0.7 });
    b.restore();
    b.fillStyle = P.rgba(P.deep, 0.75);
    b.fillRect(0, poolY, W, H - poolY);
    b.strokeStyle = P.rgba(P.steel, 0.7); b.lineWidth = 1;
    b.beginPath(); b.moveTo(0, poolY + 0.5); b.lineTo(W, poolY + 0.5); b.stroke();
    b.strokeStyle = P.rgba(P.steel, 0.16);
    for (var y = poolY + 8, gap = 8; y < H; gap *= 1.35, y += gap) { b.beginPath(); b.moveTo(0, y + 0.5); b.lineTo(W, y + 0.5); b.stroke(); }
    bg = bgc;

    /* 開いた瞬間から暴れて水が飛んでいるように、少し先まで回しておく */
    for (var q = 0; q < 1200; q++) { physics(FIX); drops(FIX); }
  }

  /* ---------------------------------------------------------- ホースの物理（固定刻み） */
  function physics(dt) {
    T += dt;
    var dt2 = dt * dt;
    for (var n = 0; n < hoses.length; n++) {
      var h = hoses[n], X = h.x, Y = h.y, OX = h.ox, OY = h.oy, k;
      var held = finger.on && finger.hose === h;
      for (k = 1; k < NODE; k++) {
        var ux = (X[k] - OX[k]) * 0.996, uy = (Y[k] - OY[k]) * 0.996;
        OX[k] = X[k]; OY[k] = Y[k];
        X[k] += ux; Y[k] += uy + GH * dt2;
      }
      /* 先端の向きと、噴く水の反動。圧はホースごとにゆっくり上下して、静まったり暴れたりする */
      var tx = X[NODE - 1] - X[NODE - 2], ty = Y[NODE - 1] - Y[NODE - 2], tl = Math.hypot(tx, ty) || 1;
      tx /= tl; ty /= tl; h.tx = tx; h.ty = ty;
      var pr = (0.62 + 0.48 * Math.sin(T * h.rate * 6.2832 + h.ph)) * (0.5 + press);
      pr = pr * (1 - h.boost) + 1.6 * h.boost;
      var F = 8 * GH * pr;
      X[NODE - 1] -= tx * F * dt2; Y[NODE - 1] -= ty * F * dt2;
      if (held) {
        var j = finger.node;
        X[j] += (finger.x - X[j]) * 0.5; Y[j] += (finger.y - Y[j]) * 0.5;
      }
      for (var it = 0; it < 12; it++) {
        for (k = 0; k < NODE - 1; k++) {
          var dx = X[k + 1] - X[k], dy = Y[k + 1] - Y[k], d = Math.hypot(dx, dy) || 1, f = (d - segL) / d;
          if (k === 0) { X[1] -= dx * f; Y[1] -= dy * f; }
          else { X[k] += dx * f * 0.5; Y[k] += dy * f * 0.5; X[k + 1] -= dx * f * 0.5; Y[k + 1] -= dy * f * 0.5; }
        }
        for (k = 0; k < NODE - 2; k++) {
          var ex = X[k + 2] - X[k], ey = Y[k + 2] - Y[k], e = Math.hypot(ex, ey) || 1, g = (e - segL * 1.97) / e * 0.08;
          if (k === 0) { X[2] -= ex * g; Y[2] -= ey * g; }
          else { X[k] += ex * g * 0.5; Y[k] += ey * g * 0.5; X[k + 2] -= ex * g * 0.5; Y[k + 2] -= ey * g * 0.5; }
        }
        X[0] = h.ax; Y[0] = h.ay;
        /* つかんだ節は指へ寄せるが、最後は棒の長さを優先する（ホースは伸びない） */
        if (held && it < 6) { var jj = finger.node; X[jj] += (finger.x - X[jj]) * 0.3; Y[jj] += (finger.y - Y[jj]) * 0.3; }
      }
      if (held) {
        /* 根元から順に、伸びた節だけを棒の長さへ戻す。届かないときは指の方角へまっすぐ伸びる */
        for (k = 1; k < NODE; k++) {
          var cx = X[k] - X[k - 1], cy = Y[k] - Y[k - 1], c = Math.hypot(cx, cy);
          if (c > segL * 1.02) { var r = segL * 1.02 / c; X[k] = X[k - 1] + cx * r; Y[k] = Y[k - 1] + cy * r; }
        }
      }
      h.cyan = held ? 1 : Math.max(0, h.cyan - dt * 0.6);
      h.boost = Math.max(0, h.boost - dt * 0.5);

      /* 噴く: 先端の向きに、先端の動きを足して飛ばす */
      h.acc += dt * (120 + flow * 420) * (0.5 + 0.5 * Math.min(1.4, pr));
      var jit = 0.006 + spray * 0.03, ang = 0.006 + spray * 0.03;
      var nvx = (X[NODE - 1] - OX[NODE - 1]) / dt, nvy = (Y[NODE - 1] - OY[NODE - 1]) / dt;
      var vj = vjet * (0.7 + 0.35 * Math.min(1.5, pr));
      while (h.acc >= 1) {
        h.acc -= 1;
        var s = vj * (1 + gauss() * jit), q = gauss() * ang;
        var dvx = tx + ty * q, dvy = ty - tx * q;
        /* 1 コマの間に噴いた水が団子にならないよう、噴いた時刻をばらして少し先へ進めておく */
        var ux = dvx * s + nvx * 0.6, uy = dvy * s + nvy * 0.6, back = Math.random() * 0.018;
        addP(X[NODE - 1] + tx * 3 + ux * back, Y[NODE - 1] + ty * 3 + uy * back, ux, uy, h.cyan > 0.35 ? 1 : 0);
      }
    }
  }

  /* ---------------------------------------------------------- 水滴（フレームごと） */
  function drops(dt) {
    var fon = finger.on && !finger.inPool, fx = finger.x, fy = finger.y, R2 = R * R;
    var drag = Math.exp(-0.08 * dt);
    for (var k = np - 1; k >= 0; k--) {
      age[k] += dt;
      vy[k] += G * dt; vx[k] *= drag;
      px[k] += vx[k] * dt; py[k] += vy[k] * dt;
      if (fon && age[k] > 0.3) {
        var dx = px[k] - fx, dy = py[k] - fy, d2 = dx * dx + dy * dy;
        if (d2 < R2 && d2 > 0.01) {
          /* 指に当たった水は跳ね返って、指の動きをもらう */
          var d = Math.sqrt(d2), nx = dx / d, ny = dy / d;
          var vn = vx[k] * nx + vy[k] * ny;
          if (vn < 0) { vx[k] -= 1.45 * vn * nx; vy[k] -= 1.45 * vn * ny; }
          vx[k] += nx * 50 + finger.vx * 0.4; vy[k] += ny * 50 + finger.vy * 0.4;
          px[k] = fx + nx * R; py[k] = fy + ny * R;
          fl[k] |= 1;
        }
      }
      if (py[k] >= poolY && vy[k] > 0) {
        if (!(fl[k] & 2)) {
          var r = Math.random();
          if (r < 0.02) ripple(px[k], false, fl[k] & 1);
          else if (r < 0.09) addMist(px[k], poolY - 2);
          if (Math.random() < 0.14) addP(px[k], poolY - 1, vx[k] * 0.2 + rnd(-40, 40), -rnd(40, 150), 2 | (fl[k] & 1));
        }
        killP(k); continue;
      }
      if (age[k] > 7 || px[k] < -80 || px[k] > W + 80) killP(k);
    }
    for (var m = nm - 1; m >= 0; m--) {
      mage[m] += dt;
      if (mage[m] > mlife[m]) {
        nm--; mx[m] = mx[nm]; my[m] = my[nm]; mvx[m] = mvx[nm]; mage[m] = mage[nm]; mlife[m] = mlife[nm];
        continue;
      }
      mx[m] += mvx[m] * dt; my[m] -= (10 + mage[m] * 4) * dt;
    }
    for (var q = ripples.length - 1; q >= 0; q--) {
      ripples[q].t += dt;
      if (ripples[q].t > ripples[q].life) ripples.splice(q, 1);
    }
    finger.vx *= Math.exp(-6 * dt); finger.vy *= Math.exp(-6 * dt);
  }

  function draw() {
    ctx.drawImage(bg, 0, 0, W, H);
    var k, ST = 0.022, refl = 0.3;

    /* 池に映る水: 縦に縮めて、横にゆらす */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.line, 0.08);
    ctx.beginPath();
    for (k = 0; k < np; k++) {
      if (py[k] > poolY) continue;
      var ry = poolY + (poolY - py[k]) * refl;
      if (ry > H) continue;
      var wx = Math.sin(ry * 0.35 + T * 2.4) * 2.5;
      ctx.moveTo(px[k] + wx, ry);
      ctx.lineTo(px[k] + wx - vx[k] * ST, ry + vy[k] * ST * refl);
    }
    ctx.stroke();

    /* 池の輪 */
    for (k = 0; k < ripples.length; k++) {
      var rp = ripples[k], f = rp.t / rp.life, rx = 3 + rp.t * rp.sp;
      ctx.strokeStyle = P.rgba(rp.cyan ? P.accent : P.steel, (rp.cyan ? 0.55 : 0.4) * (1 - f));
      ctx.beginPath();
      ctx.ellipse(rp.x, poolY + 4 + rx * 0.05, rx, rx * 0.16 + 0.5, 0, 0, Math.PI * 2);
      ctx.stroke();
    }

    /* 霧 */
    ctx.fillStyle = P.rgba(P.steel, 0.22);
    ctx.beginPath();
    for (k = 0; k < nm; k++) {
      var sz = 0.6 + (1 - mage[k] / mlife[k]) * 1.1;
      ctx.rect(mx[k] - sz, my[k] - sz, sz * 2, sz * 2);
    }
    ctx.fill();

    /* 水の筋: 速いところは線に、遅いところは粒に見える */
    ctx.lineCap = 'round';
    for (var pass = 0; pass < 2; pass++) {
      ctx.lineWidth = pass ? 1.4 : 1.1;
      ctx.strokeStyle = pass ? P.rgba(P.accent, 0.75) : P.rgba(P.line, 0.5);
      ctx.beginPath();
      for (k = 0; k < np; k++) {
        if ((fl[k] & 1) !== pass) continue;
        ctx.moveTo(px[k], py[k]);
        ctx.lineTo(px[k] - vx[k] * ST, py[k] - vy[k] * ST + 0.6);
      }
      ctx.stroke();
    }

    /* ホース: 節をなめらかにつなぐ。先には短い口金 */
    for (var n = 0; n < hoses.length; n++) {
      var h = hoses[n], X = h.x, Y = h.y;
      ctx.strokeStyle = P.rgba(P.deep, 0.9); ctx.lineWidth = 6;
      hosePath(X, Y); ctx.stroke();
      ctx.strokeStyle = P.rgba(P.mix(P.line, P.accent, h.cyan), 0.92); ctx.lineWidth = 2.6;
      hosePath(X, Y); ctx.stroke();
      var ex = X[NODE - 1], ey = Y[NODE - 1];
      ctx.strokeStyle = P.rgba(h.cyan > 0.05 ? P.mix(P.steel, P.accent, h.cyan) : P.steel, 1); ctx.lineWidth = 4.5;
      ctx.beginPath(); ctx.moveTo(ex - h.tx * 9, ey - h.ty * 9); ctx.lineTo(ex + h.tx * 2, ey + h.ty * 2); ctx.stroke();
    }
    ctx.lineCap = 'butt';
  }
  function hosePath(X, Y) {
    ctx.beginPath();
    ctx.moveTo(X[0], Y[0]);
    for (var k = 1; k < NODE - 1; k++) ctx.quadraticCurveTo(X[k], Y[k], (X[k] + X[k + 1]) / 2, (Y[k] + Y[k + 1]) / 2);
    ctx.lineTo(X[NODE - 1], Y[NODE - 1]);
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; layout(); });

  /* ---------------------------------------------------------- 操作 */
  function splashAt(x, y, count, up) {
    for (var i = 0; i < count; i++) {
      var a = up ? -Math.PI / 2 + rnd(-0.9, 0.9) : rnd(0, Math.PI * 2), s = rnd(60, up ? 380 : 240);
      addP(x + Math.cos(a) * 4, y + Math.sin(a) * 4, Math.cos(a) * s, Math.sin(a) * s, 1);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    finger.on = true; finger.x = e.clientX; finger.y = e.clientY; finger.vx = 0; finger.vy = 0;
    finger.t = performance.now(); finger.hose = null;
    finger.inPool = finger.y > poolY - 6;
    var best = null, bd = 1e9, bn = 0;
    if (finger.inPool) {
      /* 池を押すと、輪が立って、いちばん近いホースの圧が上がる */
      ripple(finger.x, true, true); ripple(finger.x, false, true);
      splashAt(finger.x, poolY - 2, 50, true);
      hoses.forEach(function (h) { var d = Math.abs(h.ax - finger.x); if (d < bd) { bd = d; best = h; } });
      if (best) { best.boost = 1; best.cyan = 1.2; }
      SFX.play('ripple');
    } else {
      /* いちばん近いホースの、指にいちばん近い節（根元の数節は除く）をつかむ */
      hoses.forEach(function (h) {
        for (var k = 5; k < NODE; k++) {
          var d = Math.hypot(h.x[k] - finger.x, h.y[k] - finger.y) - k * 2;
          if (d < bd) { bd = d; best = h; bn = k; }
        }
      });
      if (best) {
        var dEnd = Math.hypot(best.x[NODE - 1] - finger.x, best.y[NODE - 1] - finger.y);
        finger.hose = best; finger.node = dEnd < R * 3 || bn > NODE - 5 ? NODE - 1 : bn;
      }
      splashAt(finger.x, finger.y, 34, false);
      SFX.play('spawn');
    }
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!finger.on) return;
    var now = performance.now(), dtm = Math.max(8, now - finger.t) / 1000;
    var nx = e.clientX, ny = e.clientY;
    finger.vx = finger.vx * 0.5 + (nx - finger.x) / dtm * 0.5;
    finger.vy = finger.vy * 0.5 + (ny - finger.y) / dtm * 0.5;
    finger.x = nx; finger.y = ny; finger.t = now;
    if (finger.inPool && Math.random() < 0.3) ripple(nx, false, true);
    SFX.play('move', 160);
  });
  function up() { finger.on = false; finger.hose = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function bind(id, fn) {
    var el = document.getElementById(id);
    el.addEventListener('input', function () { fn(el.value / 100); });
    fn(el.value / 100);   /* 共有リンクから開いたとき、復元された値を拾う */
  }
  bind('k-press', function (v) { press = v; });
  bind('k-flow', function (v) { flow = v; });
  bind('k-spray', function (v) { spray = v; });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    accT += dt;
    var guard = 0;
    while (accT >= FIX && guard++ < 6) { physics(FIX); accT -= FIX; }
    if (accT > FIX) accT = 0;
    drops(dt);
    if (Math.random() < dt * 0.25) SFX.play('ripple', 3000);
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
