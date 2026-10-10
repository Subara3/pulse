<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('efield');

$pageName = 'efield';
$pageTitle = 'EFIELD - PULSE';
$pageDesc = '白い点から出た線が弧を描いて輪につながり、線の上を小さな粒が流れていく。押したところに水色の点が出て、まわりの線のつながり方が変わる。なぞった跡は薄く光り、線がそこを避けて曲がる。';
$pageCanonical = 'https://pulse.subara3.com/efield.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="22"></div>
  <div class="ctl-row"><label for="k-lines">LINES</label><input type="range" id="k-lines" min="0" max="100" value="75"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="both">CONTOUR</button><button type="button" data-look="lines">BARE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /*
   * 点電荷（白い点が +、輪が −）が漂う。+ から出る電気力線を、場の向きに沿って
   * 小刻みに積分して引く。地には電位の等高線を薄く敷く（粗い格子で解いて拡大）。
   */
  var Q = [];
  function seed() {
    Q = [];
    for (var i = 0; i < 6; i++) {
      Q.push({
        q: i % 2 ? -1 : 1, x: 0, y: 0,
        cx: 0.18 + 0.64 * ((i * 0.618 + 0.3) % 1), cy: 0.2 + 0.6 * ((i * 0.382 + 0.55) % 1),
        ax: 0.08 + 0.06 * Math.random(), ay: 0.07 + 0.06 * Math.random(),
        fx: 0.05 + 0.05 * Math.random(), fy: 0.04 + 0.05 * Math.random(), ph: Math.random() * 6.28,
        rot: Math.random() * 6.28
      });
    }
  }
  seed();
  var finger = { q: 0, x: 0, y: 0, on: false, rot: 0 };
  /* なぞった跡に残る、少しずつ消えていく電荷。跡に沿って線が曲がり、薄く光る */
  var ghosts = [], lastGhost = [0, 0];

  /* 場 E と電位 V（2 次元なので E ∝ r / |r|²、V ∝ -log r） */
  function field(x, y, out) {
    var ex = 0, ey = 0;
    for (var i = 0; i < Q.length; i++) {
      var c = Q[i], dx = x - c.x, dy = y - c.y, r2 = dx * dx + dy * dy + 1;
      ex += c.q * dx / r2; ey += c.q * dy / r2;
    }
    if (finger.q > 0.01) {
      var fx = x - finger.x, fy = y - finger.y, f2 = fx * fx + fy * fy + 1;
      ex += finger.q * fx / f2; ey += finger.q * fy / f2;
    }
    for (var g = 0; g < ghosts.length; g++) {
      var gh = ghosts[g], gx = x - gh.x, gy = y - gh.y, g2 = gx * gx + gy * gy + 1, gq = gh.q * 0.6;
      ex += gq * gx / g2; ey += gq * gy / g2;
    }
    out[0] = ex; out[1] = ey;
  }

  /* ---------------------------------------------------------- 等電位（粗い格子で値を取り、等高線は線分で引く） */
  var GX, GY, GV, GS;
  function alloc() {
    GS = 9;               /* P.fit が先に呼ぶので、ここで決める */
    GX = Math.ceil(W / GS) + 2; GY = Math.ceil(H / GS) + 2;
    GV = new Float32Array(GX * GY);
  }
  var DV = 0.85;
  function contours() {
    P.fill(ctx, W, H);
    for (var j = 0; j < GY; j++) {
      var y = j * GS;
      for (var i = 0; i < GX; i++) {
        var x = i * GS, v = 0;
        for (var k = 0; k < Q.length; k++) {
          var c = Q[k], dx = x - c.x, dy = y - c.y;
          v -= c.q * Math.log(dx * dx + dy * dy + 60);
        }
        if (finger.q > 0.01) { var fx = x - finger.x, fy = y - finger.y; v -= finger.q * Math.log(fx * fx + fy * fy + 60); }
        for (var g = 0; g < ghosts.length; g++) { var hx = x - ghosts[g].x, hy = y - ghosts[g].y; v -= ghosts[g].q * 0.6 * Math.log(hx * hx + hy * hy + 60); }
        GV[j * GX + i] = v / DV;
      }
    }
    /* マーチングスクエア: 各マスで、四隅の値をまたぐ等高線の切れ端をつなぐ */
    ctx.beginPath();
    var e = [0, 0, 0, 0, 0, 0, 0, 0];
    for (var j2 = 0; j2 < GY - 1; j2++) for (var i2 = 0; i2 < GX - 1; i2++) {
      var k0 = j2 * GX + i2, a = GV[k0], b = GV[k0 + 1], c2 = GV[k0 + GX + 1], d = GV[k0 + GX];
      var lo = Math.min(a, b, c2, d), hi = Math.max(a, b, c2, d);
      if (hi - lo > 3) continue;                     // 電荷のすぐそばは混みすぎるので省く
      var x0 = i2 * GS, y0 = j2 * GS;
      for (var L = Math.floor(lo) + 1; L <= hi; L++) {
        var n = 0;
        if ((a < L) !== (b < L)) { e[n++] = x0 + (L - a) / (b - a) * GS; e[n++] = y0; }
        if ((b < L) !== (c2 < L)) { e[n++] = x0 + GS; e[n++] = y0 + (L - b) / (c2 - b) * GS; }
        if ((c2 < L) !== (d < L)) { e[n++] = x0 + (L - d) / (c2 - d) * GS; e[n++] = y0 + GS; }
        if ((d < L) !== (a < L)) { e[n++] = x0; e[n++] = y0 + (L - a) / (d - a) * GS; }
        if (n >= 4) { ctx.moveTo(e[0], e[1]); ctx.lineTo(e[2], e[3]); }
        if (n >= 8) { ctx.moveTo(e[4], e[5]); ctx.lineTo(e[6], e[7]); }
      }
    }
    ctx.strokeStyle = P.rgba(P.steel, 0.55);
    ctx.lineWidth = 1;
    ctx.stroke();
  }

  /* ---------------------------------------------------------- 力線 */
  var E = [0, 0], E2 = [0, 0];
  var lineCount = 16;
  function trace(x, y) {
    var pts = [x, y], h = 4, len = 0;
    for (var n = 0; n < 520; n++) {
      field(x, y, E);
      var m = Math.hypot(E[0], E[1]) || 1e-9;
      var mx = x + E[0] / m * h * 0.5, my = y + E[1] / m * h * 0.5;
      field(mx, my, E2);
      var m2 = Math.hypot(E2[0], E2[1]) || 1e-9;
      x += E2[0] / m2 * h; y += E2[1] / m2 * h;
      pts.push(x, y); len += h;
      if (x < -80 || y < -80 || x > W + 80 || y > H + 80) break;
      var hit = false;
      for (var k = 0; k < Q.length; k++) if (Q[k].q < 0 && (x - Q[k].x) * (x - Q[k].x) + (y - Q[k].y) * (y - Q[k].y) < 36) { hit = true; break; }
      if (hit) break;
    }
    return { p: pts, len: len };
  }

  var t = 0, tb = 0, drift = 0.4, look = 'both';
  function draw(dt) {
    if (look === 'both') contours(); else P.fill(ctx, W, H);
    var gap = 64, beadOff = (tb * 7) % gap;
    var srcs = Q.filter(function (c) { return c.q > 0; });
    if (finger.q > 0.3) srcs = srcs.concat([{ q: finger.q, x: finger.x, y: finger.y, rot: finger.rot, me: true }]);
    for (var s = 0; s < srcs.length; s++) {
      var c = srcs[s], nl = c.me ? Math.max(6, Math.round(lineCount * 0.75)) : lineCount;
      var fq = Math.min(1, (finger.q - 0.3) / 0.7), lineA = c.me ? 0.9 * Math.sqrt(fq) : 0.78;
      var col = c.me ? P.accent : P.line;
      ctx.strokeStyle = P.rgba(col, lineA);
      ctx.lineWidth = c.me ? 1.5 : 1.2;
      var beads = [];
      ctx.beginPath();
      for (var l = 0; l < nl; l++) {
        var a = c.rot + l / nl * Math.PI * 2;
        var tr = trace(c.x + Math.cos(a) * 7, c.y + Math.sin(a) * 7), p = tr.p;
        ctx.moveTo(p[0], p[1]);
        for (var k = 2; k < p.length; k += 2) ctx.lineTo(p[k], p[k + 1]);
        /* 粒: 弧長に沿って一定間隔で並べ、時間で送る */
        for (var b = beadOff + 12; b < tr.len; b += gap) {
          var idx = Math.floor(b / 4) * 2;
          if (idx + 1 < p.length) beads.push(p[idx], p[idx + 1], Math.min(1, b / 40, (tr.len - b) / 30));
        }
      }
      ctx.stroke();
      /* 粒は端で小さくなる（出入りがちらつかないように） */
      ctx.fillStyle = P.rgba(col, c.me ? 0.9 * fq : 0.75);
      ctx.beginPath();
      for (var bi = 0; bi < beads.length; bi += 3) {
        var rb = 2 * beads[bi + 2];
        if (rb < 0.3) continue;
        ctx.moveTo(beads[bi] + rb, beads[bi + 1]);
        ctx.arc(beads[bi], beads[bi + 1], rb, 0, Math.PI * 2);
      }
      ctx.fill();
    }
    /* 電荷 */
    for (var i = 0; i < Q.length; i++) {
      var q = Q[i];
      if (q.q > 0) {
        ctx.fillStyle = P.rgba(P.line, 0.95);
        ctx.beginPath(); ctx.arc(q.x, q.y, 4.2, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = P.rgba(P.line, 0.25);
        ctx.beginPath(); ctx.arc(q.x, q.y, 9, 0, Math.PI * 2); ctx.stroke();
      } else {
        ctx.strokeStyle = P.rgba(P.line, 0.9);
        ctx.lineWidth = 1.3;
        ctx.beginPath(); ctx.arc(q.x, q.y, 6, 0, Math.PI * 2); ctx.stroke();
        ctx.lineWidth = 1;
      }
    }
    for (var gj = 0; gj < ghosts.length; gj++) {
      var gh2 = ghosts[gj], gg = ctx.createRadialGradient(gh2.x, gh2.y, 0, gh2.x, gh2.y, 115);
      gg.addColorStop(0, P.rgba(P.accent, 0.48 * gh2.q)); gg.addColorStop(1, P.rgba(P.accent, 0));
      ctx.fillStyle = gg;
      ctx.beginPath(); ctx.arc(gh2.x, gh2.y, 115, 0, Math.PI * 2); ctx.fill();
    }
    if (finger.q > 0.01) {
      /* 指の電荷のまわりは場が強いので、薄く光らせる */
      var gq = Math.min(1, finger.q), hr = 150;
      var gr = ctx.createRadialGradient(finger.x, finger.y, 0, finger.x, finger.y, hr);
      gr.addColorStop(0, P.rgba(P.accent, 0.42 * gq));
      gr.addColorStop(0.5, P.rgba(P.accent, 0.18 * gq));
      gr.addColorStop(1, P.rgba(P.accent, 0));
      ctx.fillStyle = gr;
      ctx.beginPath(); ctx.arc(finger.x, finger.y, hr, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = P.rgba(P.accent, Math.min(1, finger.q));
      ctx.beginPath(); ctx.arc(finger.x, finger.y, 6, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    finger.on = true; finger.x = e.clientX; finger.y = e.clientY; lastGhost = [finger.x, finger.y];
    if (finger.q < 0.05) finger.rot = Math.random() * 6.28;
    finger.q = 1.3;                      // 押した瞬間から線が出る
    canvas.setPointerCapture(e.pointerId);
    SFX.play('spawn', 200);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!finger.on) return;
    finger.x = e.clientX; finger.y = e.clientY;
    if (Math.hypot(finger.x - lastGhost[0], finger.y - lastGhost[1]) > 45) {
      ghosts.push({ x: finger.x, y: finger.y, q: 1 }); lastGhost = [finger.x, finger.y];
      if (ghosts.length > 10) ghosts.shift();
    }
    SFX.play('move', 200);
  });
  function up() { finger.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-drift').addEventListener('input', function () { drift = this.value / 100; });
  document.getElementById('k-lines').addEventListener('input', function () { lineCount = Math.round(8 + this.value / 100 * 18); });
  drift = document.getElementById('k-drift').value / 100;
  lineCount = Math.round(8 + document.getElementById('k-lines').value / 100 * 18);
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });

  /* ---------------------------------------------------------- 回す */
  function place() {
    for (var i = 0; i < Q.length; i++) {
      var c = Q[i];
      c.x = (c.cx + c.ax * Math.sin(t * c.fx + c.ph)) * W;
      c.y = (c.cy + c.ay * Math.sin(t * c.fy + c.ph * 1.7)) * H;
    }
  }
  var lastT = performance.now(), nearAcc = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    t += dt * (0.03 + drift * 0.45);
    tb += dt;
    place();
    for (var gi = ghosts.length - 1; gi >= 0; gi--) { ghosts[gi].q -= dt / 2.4; if (ghosts[gi].q <= 0) ghosts.splice(gi, 1); }
    finger.q += ((finger.on ? 1.3 : 0) - finger.q) * Math.min(1, dt * (finger.on ? 3 : 0.45));
    /* 電荷どうしがすれ違うと小さく鳴る（控えめに） */
    nearAcc += dt;
    if (nearAcc > 3) {
      nearAcc = 0;
      for (var i = 0; i < Q.length; i++) for (var j = i + 1; j < Q.length; j++)
        if (Q[i].q !== Q[j].q && Math.hypot(Q[i].x - Q[j].x, Q[i].y - Q[j].y) < 70) SFX.play('tick', 4000);
    }
    draw(dt);
    requestAnimationFrame(frame);
  }
  place();
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
