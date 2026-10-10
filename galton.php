<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('galton');

$pageName = 'galton';
$pageTitle = 'GALTON BOARD - PULSE';
$pageDesc = '玉が釘に当たるたびに左右へ跳ね、その跡が重なって、真ん中ほど明るい扇形になる。押すとそこの釘が光って玉がこぼれ出し、指を置いたままにすると近くの釘が玉を押しのけて流れが曲がる。';
$pageCanonical = 'https://pulse.subara3.com/galton.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-trail">TRAIL</label><input type="range" id="k-trail" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ROWS = 22;
  var TOP = 0, DY = 0, DX = 0, CX = 0, FLOOR = 0;
  /* 光の跡は粗い格子にためて、拡大して描く */
  var CELL = 4, GX = 0, GY = 0, dens, img, buf, bctx;
  var heap = new Float32Array(ROWS + 1);
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h;
    DY = Math.min(h * 0.64 / ROWS, w * 0.92 / ROWS / 1.5);
    DX = DY * 1.5;
    CX = w / 2;
    /* 釘の三角と下の山をひとまとまりにして、画面の上下中央に置く */
    var gap = Math.min(h * 0.17, DY * 9);
    TOP = Math.max(h * 0.08, (h - DY * ROWS - gap) / 2);
    FLOOR = TOP + DY * ROWS + gap;
    GX = Math.ceil(w / CELL); GY = Math.ceil(h / CELL);
    dens = new Float32Array(GX * GY);
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas'); buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
  });

  function pegX(row, j) { return CX + (j - row / 2) * DX; }
  function pegY(row) { return TOP + row * DY; }

  var balls = [];
  var flow = 14, decay = 0.16, t = 0;

  function launch(row, j) {
    row = row || 0; j = j || 0;
    balls.push({ row: row, j: j, u: Math.random() * 0.2, dir: 0, x: pegX(row, j), y: pegY(row) - DY, fall: -1, vy: 0, spd: 0.9 + Math.random() * 0.25 });
    pick(balls[balls.length - 1]);
  }
  /* 触った点のまわりの釘が灯り、光がそこにたまる。いちばん近い釘から玉がこぼれ出る */
  function touch(x, y, power, nBalls) {
    var R = 110;
    for (var r = 0; r < ROWS; r++) {
      var py = pegY(r);
      if (Math.abs(py - y) > R * 1.5) continue;
      for (var j = 0; j <= r; j++) {
        var dx = pegX(r, j) - x, dy = py - y, e = Math.exp(-(dx * dx + dy * dy) / (R * R));
        if (e < 0.05) continue;
        var id = r * (ROWS + 1) + j;
        pegGlow[id] = Math.max(pegGlow[id], e * power);
      }
    }
    /* 指のまわりに淡い光をためる（光の跡と同じようにゆっくり薄れる） */
    var cr = Math.ceil(R * 1.2 / CELL), ci = Math.round(x / CELL), cj = Math.round(y / CELL);
    for (var gj = Math.max(0, cj - cr); gj < Math.min(GY, cj + cr); gj++) {
      for (var gi = Math.max(0, ci - cr); gi < Math.min(GX, ci + cr); gi++) {
        var ddx = (gi - ci) * CELL, ddy = (gj - cj) * CELL, ee = Math.exp(-(ddx * ddx + ddy * ddy) / (R * R * 0.45));
        if (ee > 0.03) dens[gj * GX + gi] = Math.max(dens[gj * GX + gi], 0.75 * ee * power);
      }
    }
    var row = Math.max(0, Math.min(ROWS - 1, Math.round((y - TOP) / DY)));
    var jj = Math.max(0, Math.min(row, Math.round((x - CX) / DX + row / 2)));
    for (var k = 0; k < nBalls; k++) launch(row, jj);
  }

  /* 釘で右へ行くか左へ行くか。指の近くの釘は、指から遠ざかる側へ押す */
  function pick(b) {
    var p = 0.5 + 0.06 * Math.sin(t * 0.07);
    var x = pegX(b.row, b.j), y = pegY(b.row);
    if (hold) {
      var dx = x - ptr.x, dy = y - ptr.y, d = Math.hypot(dx, dy), R = Math.max(70, DX * 3.2);
      if (d < R * 1.6) {
        var k = Math.exp(-(d * d) / (R * R));
        p += (dx >= 0 ? 1 : -1) * 0.45 * k;
        pegGlow[b.row * (ROWS + 1) + b.j] = 1;
      }
    }
    b.dir = Math.random() < p ? 1 : 0;
  }

  var pegGlow = new Float32Array((ROWS + 1) * (ROWS + 1));

  function deposit(x, y, a) {
    var gx = x / CELL, gy = y / CELL, i = Math.floor(gx), j = Math.floor(gy);
    if (i < 0 || j < 0 || i >= GX - 1 || j >= GY - 1) return;
    var fx = gx - i, fy = gy - j, k = j * GX + i;
    dens[k] += a * (1 - fx) * (1 - fy); dens[k + 1] += a * fx * (1 - fy);
    dens[k + GX] += a * (1 - fx) * fy; dens[k + GX + 1] += a * fx * fy;
  }

  function stepBalls(dt) {
    var hop = 4.2;                          /* 1 秒に跳ねる段数 */
    for (var n = balls.length - 1; n >= 0; n--) {
      var b = balls[n];
      var ox = b.x, oy = b.y;
      if (b.fall < 0) {
        b.u += dt * hop * b.spd;
        while (b.u >= 1 && b.fall < 0) {
          b.u -= 1;
          b.j += b.dir; b.row++;
          if (b.row >= ROWS) { b.fall = 0; b.vy = DY * 2; break; }
          pick(b);
          if (Math.random() < 0.02) SFX.play('tick', 1800);
        }
        if (b.fall < 0) {
          var u = b.u, x0 = pegX(b.row, b.j), y0 = pegY(b.row);
          var x1 = pegX(b.row + 1, b.j + b.dir);
          b.x = x0 + (x1 - x0) * (0.5 - 0.5 * Math.cos(Math.PI * u));
          b.y = y0 - DY * 0.35 + DY * u - DY * 0.42 * Math.sin(Math.PI * u);
        }
      }
      if (b.fall >= 0) {
        /* 最後の釘を抜けたら、まっすぐ下の山へ落ちる */
        b.x = pegX(ROWS, b.j);
        b.vy += DY * 18 * dt;
        b.y += b.vy * dt;
        var land = FLOOR - heapH(b.j);
        if (b.y >= land) {
          heap[b.j] += 1;
          balls.splice(n, 1);
          if (Math.random() < 0.08) SFX.play('collide', 2600);
          continue;
        }
      }
      /* 通り道に光をためる（動いた距離に比例） */
      var seg = Math.hypot(b.x - ox, b.y - oy), m = Math.max(1, Math.ceil(seg / 2));
      var amt = 0.05 * (b.fall >= 0 ? 0.25 : 1);
      for (var s = 1; s <= m; s++) deposit(ox + (b.x - ox) * s / m, oy + (b.y - oy) * s / m, amt * Math.min(2, seg / m));
    }
  }

  /* 山の高さ: 玉の数をそのまま積まず、なだらかに丸めて見せる */
  var HEAPK = 0;
  function heapH(j) { return heap[j] * HEAPK; }

  var BG = P.bg, ST = P.steel, LN = P.line, AC = P.accent;
  function shade() {
    var d = img.data, gain = 1.3;
    for (var k = 0, o = 0; k < dens.length; k++, o += 4) {
      var v = 1 - Math.exp(-dens[k] * gain);
      var c;
      /* 藍 → 鋼 → 白。白は筋の芯にだけ届く */
      if (v < 0.6) { var a = v / 0.6; d[o] = BG[0] + (ST[0] - BG[0]) * a; d[o + 1] = BG[1] + (ST[1] - BG[1]) * a; d[o + 2] = BG[2] + (ST[2] - BG[2]) * a; }
      else { var b2 = (v - 0.6) / 0.4 * 0.85; d[o] = ST[0] + (LN[0] - ST[0]) * b2; d[o + 1] = ST[1] + (LN[1] - ST[1]) * b2; d[o + 2] = ST[2] + (LN[2] - ST[2]) * b2; }
      d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, GX * CELL, GY * CELL);
  }

  var PEG = P.rgba(LN, 0.42);
  function drawPegs(dt) {
    var plain = new Path2D(), fall = Math.exp(-dt * 1.5);
    for (var r = 0; r < ROWS; r++) {
      for (var j = 0; j <= r; j++) {
        var id = r * (ROWS + 1) + j, g = pegGlow[id];
        var x = pegX(r, j), y = pegY(r);
        if (g > 0.03) {
          /* 指が押している釘だけ、シアンに灯る */
          pegGlow[id] = g * fall;
          ctx.fillStyle = P.rgba(P.mix(LN, AC, Math.min(1, g * 1.4)), 0.5 + 0.45 * g);
          ctx.beginPath(); ctx.arc(x, y, 1.6 + g * 3.4, 0, Math.PI * 2); ctx.fill();
        } else {
          pegGlow[id] = 0;
          plain.moveTo(x + 1.4, y); plain.arc(x, y, 1.4, 0, Math.PI * 2);
        }
      }
    }
    ctx.fillStyle = PEG;
    ctx.fill(plain);
  }

  function drawHeap() {
    /* 山の稜線を 1 本のなめらかな線にする */
    var pts = [];
    var n = ROWS + 1;
    for (var j = -2; j <= n + 1; j++) {
      var h = 0, wsum = 0;
      for (var q = -2; q <= 2; q++) {
        var jj = j + q;
        if (jj < 0 || jj >= n) continue;
        var w = Math.exp(-q * q / 2.2);
        h += heapH(jj) * w; wsum += w;
      }
      pts.push([pegX(ROWS, j), FLOOR - (wsum ? h / 2.6 * 1.0 : 0) * 1]);
    }
    ctx.beginPath();
    ctx.moveTo(pts[0][0], FLOOR);
    for (var i = 0; i < pts.length - 1; i++) {
      var mx = (pts[i][0] + pts[i + 1][0]) / 2, my = (pts[i][1] + pts[i + 1][1]) / 2;
      ctx.quadraticCurveTo(pts[i][0], pts[i][1], mx, my);
    }
    ctx.lineTo(pts[pts.length - 1][0], FLOOR);
    ctx.fillStyle = P.rgba(ST, 0.16);
    ctx.fill();
    ctx.lineWidth = 1.2;
    ctx.strokeStyle = P.rgba(LN, 0.8);
    ctx.stroke();
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(ST, 0.45);
    ctx.beginPath(); ctx.moveTo(pegX(ROWS, -3), FLOOR + 0.5); ctx.lineTo(pegX(ROWS, ROWS + 3), FLOOR + 0.5); ctx.stroke();
  }

  function drawBalls() {
    ctx.fillStyle = P.rgba(LN, 0.95);
    ctx.beginPath();
    for (var n = 0; n < balls.length; n++) {
      var b = balls[n];
      ctx.moveTo(b.x + 2.2, b.y);
      ctx.arc(b.x, b.y, 2.2, 0, Math.PI * 2);
    }
    ctx.fill();
  }

  /* ---------------------------------------------------------- 操作 */
  var hold = false, ptr = { x: -999, y: -999 }, lastTouch = { x: 0, y: 0 };
  canvas.addEventListener('pointerdown', function (e) {
    hold = true; ptr.x = e.clientX; ptr.y = e.clientY; lastTouch = { x: ptr.x, y: ptr.y };
    touch(ptr.x, ptr.y, 1, 12);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!hold) return;
    ptr.x = e.clientX; ptr.y = e.clientY;
    if (Math.hypot(ptr.x - lastTouch.x, ptr.y - lastTouch.y) > 22) {
      touch(ptr.x, ptr.y, 0.8, 2);
      lastTouch = { x: ptr.x, y: ptr.y };
    }
    SFX.play('move', 300);
  });
  function up() { hold = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var flowEl = document.getElementById('k-flow'), trailEl = document.getElementById('k-trail');
  function readFlow() { flow = 3 + flowEl.value / 100 * 26; }
  function readTrail() { decay = 0.5 - trailEl.value / 100 * 0.42; }
  flowEl.addEventListener('input', readFlow);
  trailEl.addEventListener('input', readTrail);
  readFlow(); readTrail();
  document.getElementById('k-clear').addEventListener('click', function () {
    dens.fill(0); heap.fill(0); balls.length = 0; SFX.play('click');
  });

  /* 最初から筋が見えているように、少し走らせておく */
  (function warm() {
    for (var i = 0; i < 260; i++) {
      t += 1 / 30;
      if (Math.random() < flow / 30) launch();
      stepBalls(1 / 30);
      decayAll(1 / 30);
    }
  })();
  function decayAll(dt) {
    var f = Math.exp(-dt * decay);
    for (var k = 0; k < dens.length; k++) dens[k] *= f;
    /* 山はゆっくり沈む。全体を少しずつ縮めて、いつまでも積もりきらない */
    var sum = 0;
    for (var j = 0; j <= ROWS; j++) { heap[j] *= Math.exp(-dt * 0.05); sum += heap[j]; }
    var peak = 0;
    for (var j2 = 0; j2 <= ROWS; j2++) if (heap[j2] > peak) peak = heap[j2];
    /* 山の背丈は床の下の余白に収める */
    var room = (FLOOR - pegY(ROWS)) * 0.8;
    HEAPK = peak > 0 ? Math.min(DY * 0.25, room / peak) : DY * 0.25;
  }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    t += dt;
    acc += dt * flow;
    while (acc > 1) { acc -= 1; launch(); }
    stepBalls(dt);
    decayAll(dt);
    shade();
    P.grid(ctx, W, H, { alpha: 0.5 });
    drawPegs(dt);
    drawHeap();
    drawBalls();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
