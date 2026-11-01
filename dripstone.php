<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('dripstone');

$pageName = 'dripstone';
$pageTitle = 'DRIPSTONE - PULSE';
$pageDesc = '洞窟の天井から鍾乳石が垂れ、先から水のしずくが落ちるたびに、真下の床の石筍が少しずつ伸びる。上と下が届いたところは柱になり、洞窟はゆっくり横へ流れていく。押すとそこへ鍾乳石か石筍が伸びて水色に光り、しずくが続けて落ちる。なぞると指の跡に沿って石の先が伸びてくる。';
$pageCanonical = 'https://pulse.subara3.com/dripstone.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drip">DRIP</label><input type="range" id="k-drip" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 洞窟は奥と手前の 2 層。どちらも天井と床の輪郭を正弦波の和で決め、
     石は層ごとの「世界の x」に置く。カメラが右へ流れるので、左へ出た石は捨て、右に新しく足す。
     鍾乳石の先にしずくがたまり、落ちて床（石筍の先）に当たるたびに石筍が伸びる。 */
  var layers = [];
  var drips = [], splashes = [];
  var U = 1, T = 0;
  var dripRate = 1, drift = 1;
  var BAND = 14;

  function makeLayer(back) {
    return {
      back: back, par: back ? 0.55 : 1, cam: 0, maxX: 0, forms: [],
      c0: back ? 0.17 : 0.075, ca: back ? 0.025 : 0.035,
      f0: back ? 0.79 : 0.905, fa: back ? 0.02 : 0.03,
      p: [Math.random() * 6.3, Math.random() * 6.3, Math.random() * 6.3, Math.random() * 6.3, Math.random() * 6.3, Math.random() * 6.3],
      gapMin: back ? 38 : 58, gapMax: back ? 95 : 140, fatK: back ? 0.65 : 1
    };
  }
  function ceilY(L, x) {
    return H * (L.c0 + L.ca * (0.55 * Math.sin(x * 0.0043 + L.p[0]) + 0.3 * Math.sin(x * 0.0121 + L.p[1]) + 0.15 * Math.sin(x * 0.029 + L.p[2])));
  }
  function floorY(L, x) {
    return H * (L.f0 + L.fa * (0.55 * Math.sin(x * 0.0037 + L.p[3]) + 0.3 * Math.sin(x * 0.0109 + L.p[4]) + 0.15 * Math.sin(x * 0.033 + L.p[5])));
  }

  function makeForm(L, x, grown) {
    var gap = floorY(L, x) - ceilY(L, x);
    var f = { x: x, lt: 3, lm: 0, gt: 0, gm: 0, fat: (0.7 + Math.random() * 0.7) * L.fatK,
              ph: Math.random() * 6.3, joined: false, waist: 0, glow: 0, flash: 0,
              per: 1.8 + Math.random() * 3.2, dripT: Math.random() * 3, burst: 0, user: false };
    if (grown) {
      var r = Math.random();
      if (r < 0.2) {
        f.lt = gap * (0.35 + Math.random() * 0.3); f.lm = gap - f.lt; f.joined = true; f.waist = f.fat * (3 + Math.random() * 4);
      } else {
        f.lt = gap * (0.04 + Math.pow(Math.random(), 1.4) * 0.5);
        f.lm = Math.min(gap - f.lt - 20, gap * Math.pow(Math.random(), 1.3) * 0.36);
        if (f.lm < 0) f.lm = 0;
      }
    }
    return f;
  }

  function fillLayer(L) {
    L.forms = []; L.maxX = L.cam - 120;
    while (L.maxX < L.cam + W + 120) {
      L.maxX += L.gapMin + Math.random() * (L.gapMax - L.gapMin);
      L.forms.push(makeForm(L, L.maxX, true));
    }
  }

  function build() {
    U = H / 800;
    layers = [makeLayer(true), makeLayer(false)];
    for (var i = 0; i < layers.length; i++) { layers[i].cam = Math.random() * 3000; fillLayer(layers[i]); }
    drips.length = 0; splashes.length = 0;
  }

  function gapOf(L, f) { return floorY(L, f.x) - ceilY(L, f.x); }

  /* 上と下の先が届いたら柱にする */
  function checkJoin(L, f) {
    if (f.joined) return;
    var gap = gapOf(L, f);
    if (f.lt + f.lm >= gap - 1.5) {
      var over = f.lt + f.lm - gap;
      f.lm -= over * 0.5; f.lt -= over * 0.5;
      f.gt = f.gm = 0;
      f.joined = true; f.flash = 1; f.waist = f.fat * 2.5;
      if (!L.back) SFX.play('grow', 1500);
    }
  }

  function releaseDrip(L, f, accent) {
    var cy = ceilY(L, f.x);
    drips.push({ L: L, f: f, x: f.x, y: cy + f.lt + 2, vy: 0, accent: accent });
    f.gt += 0.35 * U;   /* 鍾乳石もほんの少し伸びる */
  }

  function update(dt) {
    T += dt;
    for (var li = 0; li < layers.length; li++) {
      var L = layers[li];
      L.cam += dt * 9 * drift * L.par;
      /* 左へ出た石を捨て、右に足す */
      for (var i = L.forms.length - 1; i >= 0; i--) if (L.forms[i].x - L.cam < -120) L.forms.splice(i, 1);
      while (L.maxX < L.cam + W + 120) {
        L.maxX += L.gapMin + Math.random() * (L.gapMax - L.gapMin);
        L.forms.push(makeForm(L, L.maxX, true));
      }
      for (var j = 0; j < L.forms.length; j++) {
        var f = L.forms[j];
        var k = Math.min(1, dt * 9);
        if (f.gt > 0.01) { var a = f.gt * k; f.lt += a; f.gt -= a; }
        if (f.gm > 0.01) { var b = f.gm * k; f.lm += b; f.gm -= b; }
        checkJoin(L, f);
        if (f.joined) { f.waist = Math.min(f.fat * 7, f.waist + dt * 0.25); }
        f.glow = Math.max(0, f.glow - dt * 0.3);
        f.flash = Math.max(0, f.flash - dt * 0.8);
        if (f.joined) continue;
        f.dripT -= dt * (f.burst > 0 ? 1 : dripRate);
        if (f.dripT <= 0) {
          if (f.burst > 0) { f.burst--; releaseDrip(L, f, true); f.dripT = f.burst > 0 ? 0.16 : f.per; }
          else { releaseDrip(L, f, false); f.dripT = f.per * (0.8 + Math.random() * 0.4); }
        }
      }
    }
    var g = 1.7 * H;
    for (var d = drips.length - 1; d >= 0; d--) {
      var q = drips[d];
      q.vy += g * dt; q.y += q.vy * dt;
      var hit = floorY(q.L, q.x) - q.f.lm;
      if (q.y >= hit) {
        drips.splice(d, 1);
        if (!q.f.joined) {
          q.f.gm += (q.accent ? 5 : 2.4) * U;
          if (q.accent) q.f.glow = Math.max(q.f.glow, 0.9);
        }
        splashes.push({ L: q.L, x: q.x, y: hit, t: 0, accent: q.accent });
        if (!q.L.back) SFX.play(q.accent ? 'ripple' : 'tick', q.accent ? 110 : 2800);
      }
    }
    for (var s = splashes.length - 1; s >= 0; s--) { splashes[s].t += dt; if (splashes[s].t > 0.7) splashes.splice(s, 1); }
  }

  /* ---------------------------------------------------------- 描く */
  function drawRock(L) {
    var deep = L.back ? P.mix(P.bg, P.deep, 0.55) : P.deep;
    var edge = L.back ? P.steel : P.line;
    var step = 8, x, sx;
    /* 天井 */
    ctx.fillStyle = P.rgba(deep, 1);
    ctx.beginPath(); ctx.moveTo(-2, -2);
    for (sx = -step; sx <= W + step; sx += step) ctx.lineTo(sx, ceilY(L, sx + L.cam));
    ctx.lineTo(W + 2, -2); ctx.closePath(); ctx.fill();
    /* 床 */
    ctx.beginPath(); ctx.moveTo(-2, H + 2);
    for (sx = -step; sx <= W + step; sx += step) ctx.lineTo(sx, floorY(L, sx + L.cam));
    ctx.lineTo(W + 2, H + 2); ctx.closePath(); ctx.fill();
    /* 輪郭と、岩の層の線 */
    ctx.lineWidth = 1;
    for (var o = 0; o < 4; o++) {
      ctx.strokeStyle = o === 0 ? P.rgba(edge, L.back ? 0.5 : 0.75) : P.rgba(P.steel, (L.back ? 0.16 : 0.28) / o);
      var off = o * (L.back ? 7 : 10) * (1 + o * 0.25);
      ctx.beginPath();
      for (sx = -step; sx <= W + step; sx += step) {
        x = sx + L.cam;
        var y = ceilY(L, x) - off + (o ? Math.sin(x * 0.02 + o * 2) * 1.5 : 0);
        if (sx === -step) ctx.moveTo(sx, y); else ctx.lineTo(sx, y);
      }
      ctx.stroke();
      ctx.beginPath();
      for (sx = -step; sx <= W + step; sx += step) {
        x = sx + L.cam;
        var y2 = floorY(L, x) + off + (o ? Math.sin(x * 0.017 + o * 3) * 1.5 : 0);
        if (sx === -step) ctx.moveTo(sx, y2); else ctx.lineTo(sx, y2);
      }
      ctx.stroke();
    }
  }

  /* 鍾乳石（下向き, dir=1）と石筍（上向き, dir=-1）の半幅 */
  function wTite(f, len, s) {
    var b = f.fat * (2.6 + 0.065 * len);
    var w = b * Math.pow(1 - s, 0.75) * (1 + 0.1 * Math.sin(s * len * 0.11 + f.ph)) + 0.9;
    return f.joined ? Math.max(w, f.waist + 1.5) : w;
  }
  function wMite(f, len, s) {
    var b = f.fat * (4 + 0.085 * len);
    var w = b * Math.pow(Math.max(0, 1 - Math.pow(s, 2.4)), 0.55) * (1 + 0.08 * Math.sin(s * len * 0.09 + f.ph * 1.7)) + 0.6;
    return f.joined ? Math.max(w, f.waist + 1.5) : w;
  }

  function drawPiece(x, y0, len, dir, f, wf, stroke, band) {
    if (len < 1) return;
    var n = Math.max(5, Math.ceil(len / 7)), i, s, w;
    var start = y0 - dir * 14;
    ctx.beginPath();
    ctx.moveTo(x - wf(f, len, 0), start);
    for (i = 0; i <= n; i++) { s = i / n; ctx.lineTo(x - wf(f, len, s), y0 + dir * s * len); }
    for (i = n; i >= 0; i--) { s = i / n; ctx.lineTo(x + wf(f, len, s), y0 + dir * s * len); }
    ctx.lineTo(x + wf(f, len, 0), start);
    ctx.fill();
    ctx.strokeStyle = stroke;
    ctx.stroke();
    /* 育った跡の輪 */
    if (len > BAND + 3) {
      ctx.beginPath();
      for (var d = BAND; d < len - 3; d += BAND) {
        w = wf(f, len, d / len);
        var yy = y0 + dir * d;
        ctx.moveTo(x + w, yy);
        ctx.ellipse(x, yy, w, Math.max(0.5, w * 0.22), 0, 0, Math.PI);
      }
      ctx.strokeStyle = band;
      ctx.stroke();
    }
  }

  function drawForms(L) {
    var base = L.back ? P.steel : P.line, fillC = L.back ? P.mix(P.bg, P.deep, 0.3) : P.bg;
    ctx.lineWidth = 1;
    ctx.lineJoin = 'round';
    for (var i = 0; i < L.forms.length; i++) {
      var f = L.forms[i], x = f.x - L.cam;
      if (x < -60 || x > W + 60) continue;
      var c = P.mix(base, P.accent, Math.min(1, f.glow));
      if (f.flash > 0) c = P.mix(c, P.line, f.flash * 0.6);
      var a = L.back ? 0.55 : 0.9;
      ctx.fillStyle = P.rgba(fillC, 1);
      var st = P.rgba(c, a), bd = P.rgba(P.mix(P.steel, P.accent, Math.min(1, f.glow)), L.back ? 0.18 : 0.34);
      var cy = ceilY(L, f.x), fy = floorY(L, f.x);
      drawPiece(x, fy, f.lm, -1, f, wMite, st, bd);
      drawPiece(x, cy, f.lt, 1, f, wTite, st, bd);
      /* 先にたまっていくしずく */
      if (!f.joined) {
        var sw = f.burst > 0 ? 0.6 : Math.max(0, 1 - f.dripT / 0.9);
        if (sw > 0) {
          ctx.fillStyle = P.rgba(c, (L.back ? 0.45 : 0.85) * sw);
          ctx.beginPath(); ctx.arc(x, cy + f.lt + 1 + sw * 1.6, 0.8 + sw * 1.6 * (L.back ? 0.7 : 1), 0, 6.2832); ctx.fill();
        }
      }
    }
  }

  function drawDrips(L) {
    ctx.lineWidth = L.back ? 1 : 1.4;
    for (var i = 0; i < drips.length; i++) {
      var q = drips[i];
      if (q.L !== L) continue;
      var x = q.x - L.cam, col = q.accent ? P.accent : (L.back ? P.steel : P.line);
      ctx.strokeStyle = P.rgba(col, L.back ? 0.35 : 0.5);
      ctx.beginPath(); ctx.moveTo(x, q.y - Math.min(18, q.vy * 0.022)); ctx.lineTo(x, q.y); ctx.stroke();
      ctx.fillStyle = P.rgba(col, L.back ? 0.6 : 0.95);
      ctx.beginPath(); ctx.arc(x, q.y, L.back ? 1.3 : 2.2, 0, 6.2832); ctx.fill();
    }
    for (var s = 0; s < splashes.length; s++) {
      var p = splashes[s];
      if (p.L !== L) continue;
      var k = p.t / 0.7, e = 1 - Math.pow(1 - k, 2), r = (p.accent ? 22 : 12) * e * (L.back ? 0.7 : 1) + 1;
      ctx.strokeStyle = P.rgba(p.accent ? P.accent : (L.back ? P.steel : P.line), (1 - k) * (L.back ? 0.35 : 0.6));
      ctx.beginPath(); ctx.ellipse(p.x - L.cam, p.y, r, r * 0.28, 0, 0, 6.2832); ctx.stroke();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 操作 */
  /* 指の位置に石の先を伸ばす。近くに石がなければ、そこに新しい石を生やす */
  function reach(px, py, press) {
    var L = layers[1], xw = px + L.cam, best = null, bd = 1e9, i;
    for (i = 0; i < L.forms.length; i++) {
      var d = Math.abs(L.forms[i].x - xw);
      if (d < bd) { bd = d; best = L.forms[i]; }
    }
    var lim = press ? 32 : 30;
    if (!best || bd > lim) {
      var userN = 0;
      for (i = 0; i < L.forms.length; i++) if (L.forms[i].user) userN++;
      if (userN < 40 || !best) {
        best = makeForm(L, xw, false);
        best.user = true; best.fat = 0.55 + Math.random() * 0.3; best.lt = 2;
        best.dripT = press ? 0 : 0.6 + Math.random();
        L.forms.push(best);
      }
    }
    var f = best, cy = ceilY(L, f.x), fy = floorY(L, f.x), gap = fy - cy;
    var tipT = cy + f.lt + f.gt, tipM = fy - f.lm - f.gm;
    if (!f.joined) {
      if (py > tipT && py < tipM) {
        /* 指が隙間にあれば、近いほうの先を指まで伸ばす */
        if (py - tipT < tipM - py) f.gt += py - tipT;
        else f.gm += tipM - py;
      } else if (press) {
        /* 石そのものを押したら、その石をひと伸びさせる */
        if (py <= tipT) f.gt += Math.max(0, Math.min(gap * 0.08, tipM - tipT - 2));
        else f.gm += Math.max(0, Math.min(gap * 0.08, tipM - tipT - 2));
      }
      if (f.lt + f.gt + f.lm + f.gm > gap) {
        var over = f.lt + f.gt + f.lm + f.gm - gap;
        if (f.gm >= over) f.gm -= over; else { f.gt = Math.max(0, f.gt - (over - f.gm)); f.gm = 0; }
      }
      if (press) { f.burst = 4; f.dripT = 0.05; }
    }
    f.glow = 1;
    return f;
  }

  var down = false, last = null;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e);
    reach(last.x, last.y, true);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 12) return;
    var n = Math.ceil(dist / 14);
    for (var i = 1; i <= n; i++) reach(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, false);
    last = p;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readDrip() { dripRate = 0.3 + document.getElementById('k-drip').value / 100 * 1.6; }
  function readDrift() { drift = document.getElementById('k-drift').value / 100 * 2.5; }
  document.getElementById('k-drip').addEventListener('input', readDrip);
  document.getElementById('k-drift').addEventListener('input', readDrift);
  /* 共有リンクから開いたとき、復元された値を拾う */
  readDrip(); readDrift();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    update(dt);
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.5 });
    /* 石の根元は岩の中に埋める。石を先に描き、岩で上から覆う */
    drawForms(layers[0]);
    drawRock(layers[0]);
    drawDrips(layers[0]);
    drawForms(layers[1]);
    drawRock(layers[1]);
    drawDrips(layers[1]);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
