<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('moire');

$pageName = 'moire';
$pageTitle = 'MOIRÉ - PULSE';
$pageDesc = '細い同心円を2組重ねると、太い縞が浮かんで見える。片方の中心がゆっくり動くにつれて、縞の向きと本数が変わる。押すと動く方の中心が指の位置へ移り、指のまわりでは2組の円が水色に浮かんで重なり方が見える。';
$pageCanonical = 'https://pulse.subara3.com/moire.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pitch">PITCH</label><input type="range" id="k-pitch" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="rings">RINGS</button><button type="button" data-look="lines">LINES</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /*
   * 同心円 2 組を重ねたとき、目に見える太い縞は「2 つの中心からの距離の差」が間隔の整数倍になる所に出る。
   * その線（双曲線の束）を等高線として引き、元の細い円は薄く敷く。
   * LINES では片方を平行線に替える。縞は放物線の束になる。
   */
  var CS = 6, GX, GY, fd, fs, img, buf, bctx;
  var look = 'rings', pitch = 14;
  var mv = { x: 0, y: 0, tx: 0, ty: 0, held: false, lit: 0 };
  var fx0 = 0, fy0 = 0, gAng = 0, clock = 0;
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h;
    GX = Math.ceil(W / CS) + 2; GY = Math.ceil(H / CS) + 2;
    fd = new Float32Array(GX * GY); fs = new Float32Array(GX * GY);
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas');
    buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
  });

  /* 2 つの場（差と和）を粗い格子で計算する */
  function fields() {
    var nx = Math.cos(gAng), ny = Math.sin(gAng), inv = 1 / pitch, i, j, k;
    for (j = 0; j < GY; j++) {
      var y = j * CS;
      for (i = 0; i < GX; i++) {
        var x = i * CS; k = j * GX + i;
        var r2 = Math.hypot(x - mv.x, y - mv.y), b;
        if (look === 'rings') b = Math.hypot(x - fx0, y - fy0);
        else b = (x - W / 2) * nx + (y - H / 2) * ny + Math.hypot(W, H);
        fd[k] = (r2 - b) * inv;
      }
    }
    /* 縞の間隔が狭すぎる所（格子より細かい所）は薄くする。ぎらつきを出さない */
    for (j = 0; j < GY; j++) {
      for (i = 0; i < GX; i++) {
        k = j * GX + i;
        var gx = fd[i < GX - 1 ? k + 1 : k] - fd[i > 0 ? k - 1 : k];
        var gy = fd[j < GY - 1 ? k + GX : k] - fd[j > 0 ? k - GX : k];
        var spacing = 2 * CS / (Math.sqrt(gx * gx + gy * gy) + 1e-6);
        var f = (spacing - 16) / 30;
        fs[k] = f < 0 ? 0 : f > 1 ? 1 : f * f * (3 - 2 * f);
      }
    }
  }

  /* マーチングスクエア。1 マスごとに、そのマスを横切る「整数 + 0.5」の高さだけを引く */
  function contourAll(F, fmin, fmax) {
    for (var j = 0; j < GY - 1; j++) {
      var y0 = j * CS, y1 = y0 + CS;
      for (var i = 0; i < GX - 1; i++) {
        var k = j * GX + i;
        var A = F[k], B = F[k + 1], C = F[k + GX + 1], D = F[k + GX];
        var lo = Math.min(A, B, C, D), hi = Math.max(A, B, C, D);
        var m0 = Math.ceil(lo - 0.5), m1 = Math.floor(hi - 0.5);
        if (m1 < m0) continue;
        var fa = fs[k];
        if (fa < fmin || fa >= fmax) continue;
        var x0 = i * CS, x1 = x0 + CS;
        for (var m = m0; m <= m1; m++) {
          var L = m + 0.5, a = A - L, b = B - L, c = C - L, d = D - L;
          var idx = (a > 0 ? 1 : 0) | (b > 0 ? 2 : 0) | (c > 0 ? 4 : 0) | (d > 0 ? 8 : 0);
          if (idx === 0 || idx === 15) continue;
          var tx = x0 + CS * a / (a - b), bx = x0 + CS * d / (d - c);
          var ly = y0 + CS * a / (a - d), ry = y0 + CS * b / (b - c);
          switch (idx) {
            case 1: case 14: ctx.moveTo(x0, ly); ctx.lineTo(tx, y0); break;
            case 2: case 13: ctx.moveTo(tx, y0); ctx.lineTo(x1, ry); break;
            case 3: case 12: ctx.moveTo(x0, ly); ctx.lineTo(x1, ry); break;
            case 4: case 11: ctx.moveTo(x1, ry); ctx.lineTo(bx, y1); break;
            case 6: case 9: ctx.moveTo(tx, y0); ctx.lineTo(bx, y1); break;
            case 7: case 8: ctx.moveTo(x0, ly); ctx.lineTo(bx, y1); break;
            case 5: ctx.moveTo(x0, ly); ctx.lineTo(tx, y0); ctx.moveTo(x1, ry); ctx.lineTo(bx, y1); break;
            case 10: ctx.moveTo(tx, y0); ctx.lineTo(x1, ry); ctx.moveTo(x0, ly); ctx.lineTo(bx, y1); break;
          }
        }
      }
    }
  }

  /* 縞の明暗: 距離の差が間隔の整数倍の所が明るく、半端な所が暗い（重なった 2 枚の透け方） */
  var BG = P.bg, ST = P.steel;
  function bands() {
    var d = img.data;
    for (var k = 0; k < GX * GY; k++) {
      var t = 0.5 + 0.5 * Math.cos(fd[k] * Math.PI * 2);
      t = t * t * 0.6 * fs[k];
      var o = k * 4;
      d[o] = BG[0] + (ST[0] - BG[0]) * t; d[o + 1] = BG[1] + (ST[1] - BG[1]) * t; d[o + 2] = BG[2] + (ST[2] - BG[2]) * t; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, GX, GY, 0, 0, GX * CS, GY * CS);
  }

  /* 元の細い模様（同心円・平行線）を薄く引く */
  function ringsAt(cx, cy, alpha) {
    var far = Math.hypot(Math.max(cx, W - cx), Math.max(cy, H - cy));
    ctx.strokeStyle = P.rgba(P.line, alpha);
    ctx.beginPath();
    for (var r = pitch; r < far; r += pitch) { ctx.moveTo(cx + r, cy); ctx.arc(cx, cy, r, 0, Math.PI * 2); }
    ctx.stroke();
  }
  function linesAt(alpha) {
    var far = Math.hypot(W, H), c = Math.cos(gAng), s = Math.sin(gAng);
    ctx.strokeStyle = P.rgba(P.line, alpha);
    ctx.beginPath();
    var off = (far % pitch);
    for (var d = -far + off; d < far; d += pitch) {
      var px = W / 2 + c * d, py = H / 2 + s * d;
      ctx.moveTo(px - s * far, py + c * far); ctx.lineTo(px + s * far, py - c * far);
    }
    ctx.stroke();
  }

  function cross(x, y, col, a) {
    ctx.strokeStyle = P.rgba(col, a);
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.moveTo(x - 10, y); ctx.lineTo(x + 10, y);
    ctx.moveTo(x, y - 10); ctx.lineTo(x, y + 10);
    ctx.stroke();
    ctx.beginPath(); ctx.arc(x, y, 4.5, 0, Math.PI * 2); ctx.stroke();
  }

  function draw() {
    fields();
    bands();
    ctx.lineWidth = 1;
    ringsAt(mv.x, mv.y, 0.06);
    if (look === 'rings') ringsAt(fx0, fy0, 0.06); else linesAt(0.06);
    /* 暗い縞の芯（双曲線・放物線の束）を細い白線で引く */
    ctx.lineWidth = 1;
    var cuts = [0.08, 0.4, 0.75, 1.01];
    for (var q = 0; q < 3; q++) {
      ctx.strokeStyle = P.rgba(P.line, 0.45 * (cuts[q] + cuts[q + 1]) / 2);
      ctx.beginPath();
      contourAll(fd, cuts[q], cuts[q + 1]);
      ctx.stroke();
    }
    if (look === 'rings') cross(fx0, fy0, P.steel, 0.6);
    /* つかんでいる間は、指のまわりで 2 組の円そのものが浮かび上がる（重なりの目が見える） */
    if (mv.lit > 0.01) {
      var R0 = 230;
      ctx.save();
      ctx.beginPath(); ctx.arc(mv.x, mv.y, R0, 0, Math.PI * 2); ctx.clip();
      ctx.lineWidth = 1.3;
      ctx.strokeStyle = P.rgba(P.accent, 0.7 * mv.lit);
      ctx.beginPath();
      for (var r = pitch; r < R0 * 2; r += pitch) { ctx.moveTo(mv.x + r, mv.y); ctx.arc(mv.x, mv.y, r, 0, Math.PI * 2); }
      ctx.stroke();
      ctx.strokeStyle = P.rgba(P.line, 0.42 * mv.lit);
      ctx.beginPath();
      if (look === 'rings') {
        var dc = Math.hypot(mv.x - fx0, mv.y - fy0);
        for (r = Math.max(pitch, Math.floor((dc - R0) / pitch) * pitch); r < dc + R0; r += pitch) { ctx.moveTo(fx0 + r, fy0); ctx.arc(fx0, fy0, r, 0, Math.PI * 2); }
      } else {
        var c = Math.cos(gAng), sn = Math.sin(gAng), far = Math.hypot(W, H);
        for (var d = -far + (far % pitch); d < far; d += pitch) {
          var px = W / 2 + c * d, py = H / 2 + sn * d;
          if (Math.abs((mv.x - px) * c + (mv.y - py) * sn) > R0) continue;
          ctx.moveTo(px - sn * far, py + c * far); ctx.lineTo(px + sn * far, py - c * far);
        }
      }
      ctx.stroke();
      ctx.restore();
    }
    cross(mv.x, mv.y, P.mix(P.line, P.accent, mv.lit), 0.6 + 0.35 * mv.lit);
  }

  /* 触っていないとき、動く中心は画面の中をゆっくり 8 の字に歩く */
  function wander(t) {
    var x = W / 2 + W * 0.22 * Math.sin(t * 0.035 + 0.6), y = H / 2 + H * 0.22 * Math.sin(t * 0.052 + 1.8);
    /* LINES では、縞の開く側が画面に入るよう、中心を線の向きの反対側へ寄せる */
    if (look === 'lines') { x -= Math.cos(gAng) * W * 0.3; y -= Math.sin(gAng) * H * 0.3; }
    return { x: x, y: y };
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, resume = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; mv.held = true;
    mv.tx = e.clientX; mv.ty = e.clientY;
    SFX.play('tick', 200);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    mv.tx = e.clientX; mv.ty = e.clientY;
    SFX.play('move', 220);
  });
  function up() { down = false; mv.held = false; resume = 4; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function setPitch(v) { pitch = 12 + v * 20; }
  document.getElementById('k-pitch').addEventListener('input', function () { setPitch(this.value / 100); });
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });
  setPitch(document.getElementById('k-pitch').value / 100);
  var act = document.querySelector('[data-look].active');
  if (act) look = act.dataset.look;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), walk = 0;
  var st = wander(0);
  mv.x = mv.tx = st.x; mv.y = mv.ty = st.y;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt;
    /* 止まっている方の中心もわずかに漂う */
    fx0 = W / 2 - W * 0.06 * Math.sin(clock * 0.03); fy0 = H / 2 - H * 0.05 * Math.cos(clock * 0.025);
    gAng = 0.3 + 0.5 * Math.sin(clock * 0.018);
    if (!mv.held) {
      walk += dt;
      var w = wander(walk);
      if (resume > 0) resume = Math.max(0, resume - dt);
      /* 離したあと 4 秒はその場にとどまり、それから秒速 25px 以下でゆっくり元の歩みへ戻る */
      if (resume <= 0) {
        var ex = w.x - mv.tx, ey = w.y - mv.ty, el = Math.hypot(ex, ey), stepMax = dt * 25;
        if (el > stepMax) { ex *= stepMax / el; ey *= stepMax / el; }
        mv.tx += ex; mv.ty += ey;
      }
    }
    var k = mv.held ? 1 : Math.min(1, dt * 2.5);
    mv.x += (mv.tx - mv.x) * k; mv.y += (mv.ty - mv.y) * k;
    mv.lit = mv.held ? Math.min(1, mv.lit + dt * 5) : Math.max(0, mv.lit - dt * 2.2);
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
