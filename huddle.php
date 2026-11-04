<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('huddle');

$pageName = 'huddle';
$pageTitle = 'HUDDLE - PULSE';
$pageDesc = '雪の吹きつける氷の上で、何百羽ものペンギンが押し合って1つの大きな塊になっている。風上の端で冷えたものは塊の縁を歩いて風下へ回りこみ、ときどき一歩ずつ詰める動きが塊を横切っていく。押すとまわりのペンギンが水色になって指から離れ、すき間があく。なぞると指の通り道が割れて、離すとすき間が埋まっていく。';
$pageCanonical = 'https://pulse.subara3.com/huddle.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-snow">SNOW</label><input type="range" id="k-snow" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-gather">GATHER</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* ---------------------------------------------------------- ペンギン
     上から見た 1 羽 = 楕円の胴と丸い頭。互いに押し合い、塊の中心へ弱く寄る。
     縁にいて風上を向いたものは冷え、冷えきると縁に沿って風下へ歩き、風下で塊にもぐりこむ */
  var N = 0, R = 10, A = 100, B = 100, CX = 0, CY = 0;
  var px, py, vx, vy, hd, ph, wav, cold, walk, tuck, fear, side, ox, oy, nb, lx, ly;
  var CELL = 1, GX = 1, GY = 1, head, next;
  var snow = [];
  var trail, tctx;
  var wind = 0.55, snowAmt = 0.5;
  var T = 0, windA = 0, gust = 1;
  var wave = -1, waveNext = 2.5;
  var down = false, fx = 0, fy = 0, fr = 0, last = null;

  function alloc() {
    R = Math.max(8, Math.min(20, Math.min(W, H) / 52));
    A = W * 0.44; B = H * 0.41;
    CX = W / 2; CY = H / 2;
    /* 楕円の中に六方格子で並べる。開いた瞬間から塊になっている */
    var pts = [], s = R * 2.02, rh = s * Math.sqrt(3) / 2;
    for (var j = -Math.ceil(B / rh); j <= Math.ceil(B / rh); j++) {
      for (var i = -Math.ceil(A / s) - 1; i <= Math.ceil(A / s) + 1; i++) {
        var x = i * s + (j & 1 ? s / 2 : 0), y = j * rh;
        if ((x * x) / (A * A) + (y * y) / (B * B) < 1) pts.push(x, y);
      }
    }
    N = pts.length / 2;
    px = new Float32Array(N); py = new Float32Array(N); vx = new Float32Array(N); vy = new Float32Array(N);
    hd = new Float32Array(N); cold = new Float32Array(N); walk = new Float32Array(N); tuck = new Float32Array(N);
    fear = new Float32Array(N); side = new Float32Array(N); ox = new Float32Array(N); oy = new Float32Array(N);
    nb = new Uint8Array(N); ph = new Float32Array(N); wav = new Float32Array(N); lx = new Float32Array(N); ly = new Float32Array(N);
    for (var k = 0; k < N; k++) {
      px[k] = CX + pts[k * 2] + (Math.random() - 0.5) * R * 0.3;
      py[k] = CY + pts[k * 2 + 1] + (Math.random() - 0.5) * R * 0.3;
      lx[k] = px[k]; ly[k] = py[k];
      hd[k] = windA + (Math.random() - 0.5) * 0.6;
      cold[k] = Math.random() * 0.8;
      side[k] = Math.random() < 0.5 ? -1 : 1;
      ph[k] = Math.random();
    }
    CELL = R * 2.7;
    GX = Math.ceil(W / CELL) + 3; GY = Math.ceil(H / CELL) + 3;
    head = new Int32Array(GX * GY); next = new Int32Array(N);

    trail = document.createElement('canvas');
    trail.width = Math.max(1, Math.round(W / 2)); trail.height = Math.max(1, Math.round(H / 2));
    tctx = trail.getContext('2d');

    snow.length = 0;
    var ns = Math.round(W * H / 5000);
    for (var q = 0; q < ns; q++) snow.push({ x: Math.random() * W, y: Math.random() * H, s: 0.4 + Math.random() * 0.6, l: Math.random() });
  }

  /* 下絵: 明るさ 12 段 × 向き 36 通りを先に描いておく */
  var BUCK = 6, ANG = 36, spr = [], sprS = 1, DPR = 1;
  function sprites() {
    spr.length = 0;
    sprS = Math.ceil(R * 2.6);
    var px2 = Math.ceil(sprS * DPR), rx = R * 0.96, ry = R * 0.74;
    for (var g = 0; g < BUCK * 2; g++) {
      var t = (g % BUCK + 0.5) / BUCK;
      var col = g < BUCK ? P.mix(P.steel, P.line, t) : P.mix(P.line, P.accent, 0.4 + t * 0.6);
      var al = g < BUCK ? 0.35 + t * 0.6 : 0.5 + t * 0.5;
      for (var a = 0; a < ANG; a++) {
        var cv = document.createElement('canvas');
        cv.width = cv.height = px2;
        var c = cv.getContext('2d'), h = a / ANG * Math.PI * 2, cs = Math.cos(h), sn = Math.sin(h);
        c.setTransform(DPR, 0, 0, DPR, 0, 0);
        var m = sprS / 2;
        c.fillStyle = P.rgba(P.deep, 0.92);
        c.beginPath(); c.ellipse(m, m, rx, ry, h, 0, Math.PI * 2); c.fill();
        c.strokeStyle = P.rgba(col, al);
        c.lineWidth = Math.max(1, R * 0.09);
        c.beginPath();
        c.ellipse(m, m, rx, ry, h, 0, Math.PI * 2);
        var hx = m + cs * R * 0.5, hy = m + sn * R * 0.5, hr = R * 0.36;
        c.moveTo(hx + hr, hy);
        c.arc(hx, hy, hr, 0, Math.PI * 2);
        c.moveTo(hx + cs * hr, hy + sn * hr);
        c.lineTo(hx + cs * (hr + R * 0.24), hy + sn * (hr + R * 0.24));
        c.stroke();
        spr.push(cv);
      }
    }
  }

  function grid() {
    head.fill(-1);
    for (var k = 0; k < N; k++) {
      var gx = Math.min(GX - 1, Math.max(0, Math.floor(px[k] / CELL) + 1));
      var gy = Math.min(GY - 1, Math.max(0, Math.floor(py[k] / CELL) + 1));
      var c = gy * GX + gx;
      next[k] = head[c]; head[c] = k;
    }
  }

  function step(dt) {
    var wx = Math.cos(windA), wy = Math.sin(windA);
    var R2 = R * 2, NR = CELL, NR2 = NR * NR;
    var AA = A * A, BB = B * B, RR = A * B;
    var damp = Math.exp(-6 * dt);
    grid();
    for (var k = 0; k < N; k++) {
      var x = px[k], y = py[k], ax = 0, ay = 0, sx = 0, sy = 0, cnt = 0;
      var gx = Math.min(GX - 1, Math.max(0, Math.floor(x / CELL) + 1));
      var gy = Math.min(GY - 1, Math.max(0, Math.floor(y / CELL) + 1));
      for (var j = gy - 1; j <= gy + 1; j++) {
        if (j < 0 || j >= GY) continue;
        for (var i = gx - 1; i <= gx + 1; i++) {
          if (i < 0 || i >= GX) continue;
          for (var m = head[j * GX + i]; m >= 0; m = next[m]) {
            if (m === k) continue;
            var dx = x - px[m], dy = y - py[m], d2 = dx * dx + dy * dy;
            if (d2 > NR2 || d2 < 1e-6) continue;
            var d = Math.sqrt(d2);
            sx -= dx / d; sy -= dy / d; cnt++;
            if (d < R2) {
              var f = (R2 - d) / d * 220;
              ax += dx * f; ay += dy * f;
            }
          }
        }
      }
      /* 外向き: 仲間のいない側。中にいると打ち消し合って 0 に近い */
      var oxk = -sx, oyk = -sy, ol = Math.sqrt(oxk * oxk + oyk * oyk);
      var edge = Math.max(0, Math.min(1, (ol - 1.0) / 1.6)) * (cnt < 6 ? 1 : 0.4);
      if (ol > 1e-3) { oxk /= ol; oyk /= ol; }
      ox[k] = oxk; oy[k] = oyk; nb[k] = cnt;

      /* 中心へ寄る。楕円の形を保つよう、縦横で強さを変える */
      var cx = x - CX, cy = y - CY;
      var qn = Math.sqrt(cx * cx / AA + cy * cy / BB) + 1e-6, sq = Math.sqrt(RR);
      var gq = R * (1.2 + edge * 5 + Math.max(0, qn - 1) * 40);
      ax -= cx / AA / qn * sq * gq;
      ay -= cy / BB / qn * sq * gq;

      /* 冷え: 風上の縁で溜まり、中で抜ける */
      var expo = edge * Math.max(0, -(oxk * wx + oyk * wy) + 0.2);
      cold[k] += (expo * 0.55 * (0.3 + wind * 1.4) * gust - (edge < 0.15 ? 0.12 : 0.02)) * dt;
      if (cold[k] < 0) cold[k] = 0;

      if (walk[k] > 0) {
        /* 縁に沿って風下へ歩く */
        var tx = -oyk, ty = oxk;
        var tw = tx * wx + ty * wy;
        if (Math.abs(tw) < 0.15) { tx *= side[k]; ty *= side[k]; }
        else if (tw < 0) { tx = -tx; ty = -ty; }
        var ws = R * (3.2 + wind * 2.5);
        ax += (tx * ws - vx[k]) * 3 + wx * R * 1.5 - oxk * R * 2;
        ay += (ty * ws - vy[k]) * 3 + wy * R * 1.5 - oyk * R * 2;
        walk[k] += dt;
        var lee = oxk * wx + oyk * wy;
        if ((lee > 0.35 && walk[k] > 1.2) || walk[k] > 14) { walk[k] = 0; tuck[k] = 2.2; cold[k] = 0; }
      } else if (cold[k] > 1) {
        walk[k] = 0.001; cold[k] = 0;
      }
      if (tuck[k] > 0) {
        /* 風下の縁から中へもぐりこむ */
        tuck[k] -= dt;
        var il = Math.sqrt(cx * cx + cy * cy) + 1;
        ax -= cx / il * R * 9; ay -= cy / il * R * 9;
      }

      /* 指: 押しのける */
      if (down && fr > 0) {
        var ex = x - fx, ey = y - fy, e2 = ex * ex + ey * ey, rr = fr + R;
        if (e2 < rr * rr * 2.2) {
          var e = Math.sqrt(e2) + 0.5, pen = Math.max(0, rr - e);
          var pf = pen * 60 + Math.max(0, rr * 1.48 - e) * 6;
          ax += ex / e * pf; ay += ey / e * pf;
          fear[k] = Math.min(1, fear[k] + dt * 6);
        }
      }
      if (fear[k] > 0) fear[k] = Math.max(0, fear[k] - dt * 0.38);

      /* 足踏みの波: 風下の端から風上へ、一歩ずつ詰める帯が渡っていく */
      if (wave >= 0) {
        var along = (cx * wx + cy * wy) / sq;
        var dw = along - (1.1 - wave);
        var hit = Math.max(0, 1 - Math.abs(dw) * 9);
        if (hit > 0) { ax += wx * R * 14 * hit; ay += wy * R * 14 * hit; wav[k] = Math.max(wav[k], hit); }
      }
      if (wav[k] > 0) wav[k] = Math.max(0, wav[k] - dt * 1.2);

      /* 小さな足踏み */
      ax += (Math.random() - 0.5) * R * 6;
      ay += (Math.random() - 0.5) * R * 6;

      vx[k] = (vx[k] + ax * dt) * damp;
      vy[k] = (vy[k] + ay * dt) * damp;
    }
    var vmax = R * 30;
    for (var n = 0; n < N; n++) {
      var sp = Math.sqrt(vx[n] * vx[n] + vy[n] * vy[n]);
      if (sp > vmax) { vx[n] *= vmax / sp; vy[n] *= vmax / sp; sp = vmax; }
      px[n] += vx[n] * dt; py[n] += vy[n] * dt;
      if (px[n] < -R * 4) px[n] = -R * 4; if (px[n] > W + R * 4) px[n] = W + R * 4;
      if (py[n] < -R * 4) py[n] = -R * 4; if (py[n] > H + R * 4) py[n] = H + R * 4;
      /* 向き: 歩くものは進む向き、驚いたものは指と反対、ほかは風下 */
      var want;
      if (fear[n] > 0.3 || (walk[n] > 0 && sp > R * 0.8)) want = Math.atan2(vy[n], vx[n]);
      else want = windA + (side[n] * 0.25);
      var dd = want - hd[n];
      dd = Math.atan2(Math.sin(dd), Math.cos(dd));
      hd[n] += dd * Math.min(1, dt * (walk[n] > 0 || fear[n] > 0.3 ? 5 : 0.8));
    }
  }

  /* ---------------------------------------------------------- 描く */
  function draw(dt) {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { step: 40, major: 4, alpha: 0.7 });

    /* 足跡: 動いたものだけが、薄い点を半分の解像度の層に残す */
    tctx.globalCompositeOperation = 'destination-out';
    tctx.fillStyle = P.rgba(P.deep, Math.min(1, dt * 0.22));
    tctx.fillRect(0, 0, trail.width, trail.height);
    tctx.globalCompositeOperation = 'source-over';
    tctx.fillStyle = P.rgba(P.line, 0.5);
    for (var k = 0; k < N; k++) {
      var mx = px[k] - lx[k], my = py[k] - ly[k];
      if (mx * mx + my * my > R * R * 0.5) {
        if (walk[k] > 0 || fear[k] > 0.2) tctx.fillRect(px[k] / 2 - 0.5, py[k] / 2 - 0.5, 1, 1);
        lx[k] = px[k]; ly[k] = py[k];
      }
    }
    ctx.globalAlpha = 0.55;
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(trail, 0, 0, W, H);
    ctx.globalAlpha = 1;

    /* 1 羽ずつ、明るさの段と向きで選んだ下絵を置く */
    var half = sprS / 2;
    for (var c = 0; c < N; c++) {
      var lum = 0.22 + Math.min(1, cold[c]) * 0.3 + (walk[c] > 0 ? 0.42 : 0) + (tuck[c] > 0 ? 0.15 : 0) + wav[c] * 0.55;
      if (nb[c] < 5) lum += 0.08;
      var bi = Math.min(BUCK - 1, Math.floor(lum * BUCK));
      if (fear[c] > 0.08) bi = BUCK + Math.min(BUCK - 1, Math.floor(fear[c] * BUCK));
      var h = hd[c] + Math.sin(T * (0.5 + ph[c] * 0.4) + ph[c] * 40) * 0.22;
      var ai = Math.round(h / (Math.PI * 2) * ANG) % ANG;
      if (ai < 0) ai += ANG;
      ctx.drawImage(spr[bi * ANG + ai], px[c] - half, py[c] - half, sprS, sprS);
    }

    /* 雪: 風に流される短い線 */
    var wx = Math.cos(windA), wy = Math.sin(windA);
    var spd = (120 + wind * 420) * gust;
    var show = Math.round(snow.length * snowAmt);
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.line, 0.4);
    ctx.beginPath();
    for (var s = 0; s < snow.length; s++) {
      var f = snow[s], v = spd * f.s;
      f.x += (wx * v + Math.sin(T * 1.3 + s) * 12) * dt;
      f.y += (wy * v + Math.cos(T * 1.1 + s * 0.7) * 12) * dt;
      if (f.x < -30) f.x += W + 60; else if (f.x > W + 30) f.x -= W + 60;
      if (f.y < -30) f.y += H + 60; else if (f.y > H + 30) f.y -= H + 60;
      if (s >= show) continue;
      var L = 3 + v * 0.03 * (0.5 + f.l);
      ctx.moveTo(f.x, f.y);
      ctx.lineTo(f.x - wx * L, f.y - wy * L);
    }
    ctx.stroke();
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h, dpr) { W = w; H = h; DPR = dpr || 1; alloc(); sprites(); });

  /* ---------------------------------------------------------- 操作 */
  function scare(x, y, rad, kick) {
    for (var k = 0; k < N; k++) {
      var ex = px[k] - x, ey = py[k] - y, e2 = ex * ex + ey * ey;
      if (e2 < rad * rad) {
        var e = Math.sqrt(e2) + 1, s = (1 - e / rad) * kick;
        vx[k] += ex / e * s; vy[k] += ey / e * s;
        fear[k] = Math.max(fear[k], 1 - e / rad * 0.6);
      }
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; fx = e.clientX; fy = e.clientY; fr = R * 1.5; last = { x: fx, y: fy };
    scare(fx, fy, R * 7, R * 22);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    fx = e.clientX; fy = e.clientY;
    var d = Math.hypot(fx - last.x, fy - last.y);
    if (d < 6) return;
    scare(fx, fy, R * 4.5, R * 8);
    last = { x: fx, y: fy };
    SFX.play('move', 140);
  });
  function up() { down = false; fr = 0; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  document.getElementById('k-snow').addEventListener('input', function () { snowAmt = this.value / 100; });
  document.getElementById('k-gather').addEventListener('click', function () {
    alloc();
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  wind = document.getElementById('k-wind').value / 100;
  snowAmt = document.getElementById('k-snow').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), walkers = 0, lastTick = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now; T += dt;
    /* 風向きはゆっくり回り、強さは息をするように揺れる */
    windA = 0.35 + T * 0.012 + Math.sin(T * 0.045) * 0.9;
    gust = 0.8 + 0.25 * Math.sin(T * 0.37) + 0.15 * Math.sin(T * 0.93 + 1.7);
    if (wave >= 0) { wave += dt * 0.32; if (wave > 2.2) { wave = -1; waveNext = T + 4 + Math.random() * 5; } }
    else if (T > waveNext) wave = 0;
    if (down) fr = Math.min(R * 4.2, fr + dt * R * 14);
    step(dt * 0.5); step(dt * 0.5);
    draw(dt);
    var w = 0;
    for (var k = 0; k < N; k++) if (tuck[k] > 2.1) w++;
    if (w > 0 && T - lastTick > 3.5) { SFX.play('tick', 3000); lastTick = T; }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
