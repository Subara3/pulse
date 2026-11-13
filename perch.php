<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('perch');

$pageName = 'perch';
$pageTitle = 'PERCH - PULSE';
$pageDesc = '電線に小鳥がとまり、隣と詰まりすぎないように横へ跳ねて、少しずつ等間隔に並んでいく。ときどき何羽かが続けて飛び立ち、空いたところを探して別の電線に降りる。電線を押すと指に引っぱられてたわみ、そのあたりの鳥が水色になって一斉に飛び立つ。空を押さえると、近くの鳥が飛んできて指のまわりを回る。';
$pageCanonical = 'https://pulse.subara3.com/perch.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-birds">BIRDS</label><input type="range" id="k-birds" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 電線は横に並べた節点の弦。たわみ u を波の式で解き、鳥は電線の上の x だけを持つ */
  var HS = 6, X0 = -60, NN = 0;
  var wires = [];          /* { y0, sag, base, u, v, load, poleIdx } */
  var poles = [];          /* 電柱の x */
  var groups = [];         /* 腕木: { top } */
  var birds = [];
  var S = 8, D0 = 24;      /* 鳥の大きさと、落ち着く間隔 */
  var CW = 300, KW = 2500; /* 電線の波の速さと、そこから出るばね定数 */
  var birdAmt = 0.55, windAmt = 0.4;
  var windDir = 1, windT = 30, T = 0;
  var target = 40, spawnAcc = 0, trimAcc = 0;
  var ptr = null;          /* { x, y, mode: 'wire' | 'sky', w, gy } */

  function build() {
    S = Math.max(6, Math.min(11, Math.min(W, H) * 0.0145));
    D0 = S * 3.1;
    NN = Math.ceil((W - 2 * X0) / HS) + 1;
    var span = Math.max(280, Math.min(900, W * 0.56));
    poles = [];
    for (var k = -3; k <= 3; k++) {
      var px = W * 0.5 + (k + 0.5) * span;
      if (px > X0 - span && px < W - X0 + span) poles.push(px);
    }
    var fr = H > W * 1.1
      ? [[0.13, 0.20, 0.27], [0.41, 0.48, 0.55], [0.69, 0.76]]
      : [[0.17, 0.26, 0.35], [0.55, 0.64, 0.73]];
    groups = []; wires = [];
    for (var g = 0; g < fr.length; g++) {
      groups.push({ top: fr[g][0] * H - S * 1.6, bot: fr[g][fr[g].length - 1] * H });
      for (var j = 0; j < fr[g].length; j++) {
        var sg = span * (0.055 + 0.012 * ((j * 7 + g * 3) % 3));
        var w = { y0: fr[g][j] * H, base: new Float32Array(NN), u: new Float32Array(NN), v: new Float32Array(NN),
                  load: new Float32Array(NN), pin: new Uint8Array(NN) };
        for (var i = 0; i < NN; i++) {
          var x = X0 + i * HS, best = 1e9, a = 0, b = 0;
          for (var p = 0; p < poles.length - 1; p++) {
            if (x >= poles[p] && x <= poles[p + 1]) { a = poles[p]; b = poles[p + 1]; best = 0; }
          }
          var tt = best === 0 ? (x - a) / (b - a) : 0.5;
          w.base[i] = w.y0 + sg * 4 * tt * (1 - tt);
        }
        w.pin[0] = 1; w.pin[NN - 1] = 1;
        for (var q = 0; q < poles.length; q++) {
          var pi = Math.round((poles[q] - X0) / HS);
          if (pi >= 0 && pi < NN) { w.pin[pi] = 1; w.base[pi] = w.y0; }
        }
        wires.push(w);
      }
    }
    birds = [];
    target = targetCount();
    /* 最初は群れごとに固めて置く。そこから間隔が開いていく様子が見える */
    var n = 0, guard = 0;
    while (n < target * 0.88 && guard++ < 400) {
      var wi = (Math.random() * wires.length) | 0;
      var cxp = W * (0.06 + Math.random() * 0.88), m = 3 + ((Math.random() * 6) | 0);
      for (var c = 0; c < m && n < target * 0.88; c++) {
        var bx = cxp + (c - m / 2) * D0 * (0.45 + Math.random() * 0.3);
        if (bx < S * 2 || bx > W - S * 2 || nearPole(bx) < S * 1.5) continue;
        var bd = newBird(); bd.st = 0; bd.w = wi; bd.x = bx; bd.dir = Math.random() < 0.7 ? windDir : -windDir; bd.face = bd.dir;
        birds.push(bd); n++;
      }
    }
    for (var f = 0; f < target * 0.12; f++) {
      var fb = newBird(); fb.x = Math.random() * W; fb.y = H * (0.1 + Math.random() * 0.7);
      fb.vx = (Math.random() - 0.5) * 160; fb.vy = (Math.random() - 0.5) * 60; fb.ft = Math.random() * 2;
      birds.push(fb);
    }
  }

  function targetCount() {
    var cap = wires.length * W / (D0 * 1.15);
    return Math.round(cap * (0.15 + 0.6 * birdAmt));
  }
  function nearPole(x) {
    var d = 1e9;
    for (var i = 0; i < poles.length; i++) d = Math.min(d, Math.abs(x - poles[i]));
    return d;
  }
  function newBird() {
    return { st: 1, w: 0, x: 0, y: 0, vx: 0, vy: 0, dir: 1, face: 1, hp: -1, hx0: 0, hx1: 0, cd: Math.random(),
             flap: Math.random() * 6, ft: 1, tw: -1, tx: 0, wx: 0, wy: 0, orbit: false, oa: Math.random() * 6.28,
             exit: false, hl: 0, pend: -1 };
  }
  function idxOf(x) { return Math.max(0, Math.min(NN - 2, (x - X0) / HS)); }
  function wireY(w, x) {
    var f = idxOf(x), i = f | 0, t = f - i, o = wires[w];
    return (o.base[i] + o.u[i]) * (1 - t) + (o.base[i + 1] + o.u[i + 1]) * t;
  }
  function wireV(w, x) { var i = Math.round(idxOf(x)); return wires[w].v[i]; }
  function kick(w, x, amt) {
    var o = wires[w], c = Math.round(idxOf(x));
    for (var i = Math.max(1, c - 2); i <= Math.min(NN - 2, c + 2); i++) if (!o.pin[i]) o.v[i] += amt * (1 - Math.abs(i - c) * 0.3);
  }

  /* ---------------------------------------------------------- 鳥 */
  function takeoff(b, ax, ay, chain) {
    if (b.st !== 0) return;
    var spd = S * 22;
    b.st = 1; b.y = wireY(b.w, b.x) - S * 0.6; b.hp = -1; b.pend = -1;
    if (ax != null) {
      var dx = b.x - ax, dy = b.y - ay - 30, d = Math.hypot(dx, dy) || 1;
      b.vx = dx / d * spd * 1.2; b.vy = dy / d * spd * 1.2 - spd * 0.4;
    } else {
      b.vx = b.face * spd * (0.5 + Math.random() * 0.4); b.vy = -spd * (0.5 + Math.random() * 0.4);
    }
    b.ft = 1.2 + Math.random() * 3.2; b.tw = -1;
    b.wx = Math.random() * W; b.wy = H * (0.06 + Math.random() * 0.8);
    kick(b.w, b.x, 70);
    if (chain > 0) {
      for (var i = 0; i < birds.length; i++) {
        var o = birds[i];
        if (o.st === 0 && o.pend < 0 && o.w === b.w && Math.abs(o.x - b.x) < D0 * 3.2 && Math.random() < chain) {
          o.pend = 0.08 + Math.random() * 0.4;
        }
      }
    }
  }

  /* 降りる先: 電線を何本か見て、いちばん広いすき間の真ん中を選ぶ */
  function chooseLand(b) {
    var best = 0, bw = -1, bx = 0;
    for (var tr = 0; tr < 3; tr++) {
      var w = (Math.random() * wires.length) | 0, xs = [S * 2, W - S * 2];
      for (var i = 0; i < birds.length; i++) {
        var o = birds[i];
        if (o === b) continue;
        if (o.st === 0 && o.w === w) xs.push(o.x);
        else if (o.st === 1 && o.tw === w) xs.push(o.tx);
      }
      for (var p = 0; p < poles.length; p++) if (poles[p] > 0 && poles[p] < W) { xs.push(poles[p] - S * 0.8); xs.push(poles[p] + S * 0.8); }
      if (ptr) { xs.push(ptr.x - D0 * 2.5); xs.push(ptr.x + D0 * 2.5); }
      xs.sort(function (a, c) { return a - c; });
      for (var k = 0; k < xs.length - 1; k++) {
        var mid = (xs[k] + xs[k + 1]) / 2, g = xs[k + 1] - xs[k];
        if (ptr && Math.abs(mid - ptr.x) < D0 * 2.5) continue;
        if (nearPole(mid) < S * 1.2) continue;
        g *= 0.85 + Math.random() * 0.3;
        if (g > best) { best = g; bw = w; bx = mid; }
      }
    }
    if (bw < 0 || best < D0 * 1.4) { b.ft = 0.8 + Math.random() * 1.5; b.wx = Math.random() * W; b.wy = H * (0.06 + Math.random() * 0.8); return; }
    b.tw = bw; b.tx = bx + (Math.random() - 0.5) * S;
  }

  function land(b) {
    for (var i = 0; i < birds.length; i++) {
      var o = birds[i];
      if (o !== b && o.st === 0 && o.w === b.tw && Math.abs(o.x - b.tx) < D0 * 0.5) { b.tw = -1; b.ft = 0.4; return; }
    }
    b.st = 0; b.w = b.tw; b.x = b.tx; b.tw = -1; b.vx = b.vy = 0; b.hp = -1; b.cd = 0.4 + Math.random();
    b.dir = b.face; kick(b.w, b.x, 55);
    SFX.play('tick', 2600);
  }

  function updateBirds(dt) {
    var maxS = S * 26;
    /* 電線ごとに並べ、左右のすき間を見る */
    var lists = [];
    for (var w = 0; w < wires.length; w++) lists.push([]);
    for (var i = 0; i < birds.length; i++) if (birds[i].st === 0) lists[birds[i].w].push(birds[i]);
    for (w = 0; w < wires.length; w++) {
      var L = lists[w];
      L.sort(function (a, c) { return a.x - c.x; });
      for (var k = 0; k < L.length; k++) {
        var b = L[k];
        var gl = k > 0 ? b.x - L[k - 1].x : b.x + S * 2;
        var gr = k < L.length - 1 ? L[k + 1].x - b.x : W + S * 2 - b.x;
        for (var p = 0; p < poles.length; p++) {
          var dp = poles[p] - b.x;
          if (dp > 0) gr = Math.min(gr, dp * 1.7); else gl = Math.min(gl, -dp * 1.7);
        }
        b.cd -= dt;
        if (b.hp >= 0) {
          b.hp += dt / 0.24;
          if (b.hp >= 1) { b.hp = -1; b.x = b.hx1; } else b.x = b.hx0 + (b.hx1 - b.hx0) * (0.5 - 0.5 * Math.cos(b.hp * Math.PI));
        } else if (b.cd <= 0 && Math.min(gl, gr) < D0) {
          var amt = Math.min(S * 1.3, Math.abs(gr - gl) / 2);
          if (amt > S * 0.25) { b.hx0 = b.x; b.hx1 = b.x + (gr > gl ? amt : -amt); b.hp = 0; }
          b.cd = 0.25 + Math.random() * 0.9;
          if (gl + gr < D0 * 1.15 && Math.random() < 0.25) b.pend = 0.05;
        }
        /* 向き: 風上を向いた隣が多いほど、つられて向き直る */
        if (b.dir !== windDir) {
          var c = 0;
          if (k > 0 && L[k - 1].dir === windDir && gl < D0 * 2.5) c++;
          if (k < L.length - 1 && L[k + 1].dir === windDir && gr < D0 * 2.5) c++;
          if (Math.random() < dt * (0.03 + 0.7 * c)) b.dir = windDir;
        } else if (Math.random() < dt * 0.006) b.dir = -windDir;
      }
    }

    for (i = birds.length - 1; i >= 0; i--) {
      b = birds[i];
      b.hl = Math.max(0, b.hl - dt * (b.orbit ? 0 : 0.3));
      b.face += (b.dir - b.face) * Math.min(1, dt * 9);
      if (b.st === 0) {
        if (b.pend >= 0) { b.pend -= dt; if (b.pend < 0) { takeoff(b, null, null, 0.3); continue; } }
        if (Math.random() < dt / 80) { takeoff(b, null, null, 0.4); if (Math.random() < 0.3) SFX.play('pop', 4000); continue; }
        var vv = Math.abs(wireV(b.w, b.x));
        if (vv > S * 16 && Math.random() < dt * 5) { b.hl = Math.max(b.hl, 0.5); takeoff(b, null, null, 0.2); }
        continue;
      }
      /* 飛んでいる鳥: 目標へ向かう速さに少しずつ寄せ、近くの鳥と向きを揃える */
      var tx, ty, sp = maxS;
      if (b.orbit && ptr) {
        var R0 = Math.max(46, Math.min(W, H) * 0.09) * (0.8 + (b.oa % 1) * 0.6);
        var an = b.oa + T * 1.7;
        tx = ptr.x + Math.cos(an) * R0; ty = ptr.y + Math.sin(an) * R0 * 0.75;
        sp = maxS * 1.3;
      } else if (b.exit) {
        tx = b.wx; ty = b.wy;
        if (b.x < -60 || b.x > W + 60 || b.y < -60) { birds.splice(i, 1); continue; }
      } else if (b.ft > 0) {
        b.ft -= dt; tx = b.wx; ty = b.wy;
        if (Math.hypot(tx - b.x, ty - b.y) < 50) { b.wx = Math.random() * W; b.wy = H * (0.06 + Math.random() * 0.8); }
        if (b.ft <= 0) chooseLand(b);
      } else {
        if (b.tw < 0) chooseLand(b);
        if (b.tw < 0) { tx = b.wx; ty = b.wy; }
        else {
          tx = b.tx; ty = wireY(b.tw, b.tx) - S * 0.2;
          var dd = Math.hypot(tx - b.x, ty - b.y);
          if (dd < 4) { land(b); continue; }
          sp = Math.min(maxS, dd * 2.4 + 18);
          /* 上から降りるように、遠いうちは少し高めをねらう */
          if (dd > 60) ty -= Math.min(80, dd * 0.25);
        }
      }
      var ddx = tx - b.x, ddy = ty - b.y, dl = Math.hypot(ddx, ddy) || 1;
      var dvx = ddx / dl * sp, dvy = ddy / dl * sp;
      var ax = 0, ay = 0, nc = 0;
      for (var j = 0; j < birds.length; j++) {
        var o = birds[j];
        if (o === b || o.st !== 1) continue;
        var ex = o.x - b.x, ey = o.y - b.y, e2 = ex * ex + ey * ey;
        if (e2 < 4900) { ax += o.vx; ay += o.vy; nc++; }
        if (e2 < S * S * 7 && e2 > 0.01) { dvx -= ex / Math.sqrt(e2) * 60; dvy -= ey / Math.sqrt(e2) * 60; }
      }
      if (nc && b.tw < 0) { dvx = dvx * 0.7 + ax / nc * 0.3; dvy = dvy * 0.7 + ay / nc * 0.3; }
      var kk = Math.min(1, dt * (b.tw >= 0 ? 5 : 2.6));
      b.vx += (dvx - b.vx) * kk; b.vy += (dvy - b.vy) * kk;
      b.x += b.vx * dt; b.y += b.vy * dt;
      var spd = Math.hypot(b.vx, b.vy);
      b.flap += dt * (b.tw >= 0 && spd < maxS * 0.5 ? 9 : 15);
      if (Math.abs(b.vx) > 8) b.dir = b.vx > 0 ? 1 : -1;
    }
  }

  function population(dt) {
    target = targetCount();
    var live = 0;
    for (var i = 0; i < birds.length; i++) if (!birds[i].exit) live++;
    spawnAcc += dt; trimAcc += dt;
    if (live < target && spawnAcc > 0.25) {
      spawnAcc = 0;
      var b = newBird(), side = Math.random() < 0.5 ? -1 : 1;
      b.x = side < 0 ? -30 : W + 30; b.y = H * (0.05 + Math.random() * 0.5);
      b.vx = -side * S * 20; b.vy = 0; b.ft = 0.6 + Math.random() * 1.5;
      b.wx = W * (0.2 + Math.random() * 0.6); b.wy = H * (0.1 + Math.random() * 0.6);
      birds.push(b);
    } else if (live > target + 2 && trimAcc > 0.3) {
      trimAcc = 0;
      var c = birds[(Math.random() * birds.length) | 0];
      if (c && !c.exit && !c.orbit) {
        if (c.st === 0) takeoff(c, null, null, 0);
        c.exit = true; c.tw = -1;
        c.wx = c.x < W / 2 ? -120 : W + 120; c.wy = H * Math.random() * 0.5;
      }
    }
  }

  /* ---------------------------------------------------------- 電線 */
  function updateWires(dt) {
    var sub = 4, h = dt / sub, damp = Math.exp(-1.3 * h);
    var gust = 0.5 + 0.5 * Math.sin(T * 0.21) * Math.sin(T * 0.13 + 1);
    for (var w = 0; w < wires.length; w++) {
      var o = wires[w];
      o.load.fill(0);
    }
    for (var i = 0; i < birds.length; i++) {
      var b = birds[i];
      if (b.st === 0) wires[b.w].load[Math.round(idxOf(b.x))] += KW * 0.035;
    }
    for (var s = 0; s < sub; s++) {
      for (w = 0; w < wires.length; w++) {
        o = wires[w];
        var u = o.u, v = o.v, L = o.load, pin = o.pin;
        var gr = ptr && ptr.mode === 'wire' && ptr.w === w ? ptr : null;
        for (i = 1; i < NN - 1; i++) {
          if (pin[i]) continue;
          var x = X0 + i * HS;
          var wf = windAmt * 26 * (0.4 + gust) * Math.sin(T * 1.1 - x * 0.006 * windDir + w * 0.9);
          v[i] += (KW * (u[i - 1] + u[i + 1] - 2 * u[i]) + L[i] + wf) * h;
          v[i] *= damp;
        }
        for (i = 1; i < NN - 1; i++) if (!pin[i]) u[i] += v[i] * h;
        if (gr) {
          var rr = S * 3;
          var c0 = Math.max(1, Math.floor(idxOf(gr.x - rr * 3))), c1 = Math.min(NN - 2, Math.ceil(idxOf(gr.x + rr * 3)));
          for (i = c0; i <= c1; i++) {
            if (pin[i]) continue;
            var dx = (X0 + i * HS - gr.x) / rr, wt = Math.exp(-dx * dx) * 0.4;
            var tg = gr.gy - o.base[i];
            u[i] += (tg - u[i]) * wt; v[i] *= 1 - wt;
          }
        }
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  function drawBird(b) {
    var col = P.rgba(P.mix(P.line, P.accent, Math.min(1, b.hl)), 0.92);
    ctx.fillStyle = col; ctx.strokeStyle = col;
    ctx.save();
    if (b.st === 0) {
      var y = wireY(b.w, b.x) - (b.hp >= 0 ? Math.sin(b.hp * Math.PI) * S * 0.7 : 0);
      ctx.translate(b.x, y);
      ctx.scale(b.face, 1);
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(-S * 0.1, 0); ctx.lineTo(-S * 0.15, -S * 0.45);
      ctx.moveTo(S * 0.15, 0); ctx.lineTo(S * 0.1, -S * 0.45);
      ctx.stroke();
      ctx.beginPath();
      ctx.ellipse(0, -S * 0.85, S * 0.62, S * 0.42, -0.25, 0, 6.2832);
      ctx.moveTo(S * 0.79, -S * 1.3);
      ctx.arc(S * 0.52, -S * 1.3, S * 0.27, 0, 6.2832);
      ctx.fill();
      ctx.beginPath();
      ctx.moveTo(S * 0.74, -S * 1.38); ctx.lineTo(S * 1.0, -S * 1.28); ctx.lineTo(S * 0.74, -S * 1.2);
      ctx.moveTo(-S * 0.4, -S * 0.98); ctx.lineTo(-S * 1.22, -S * 0.46); ctx.lineTo(-S * 1.1, -S * 0.32); ctx.lineTo(-S * 0.45, -S * 0.6);
      ctx.fill();
    } else {
      ctx.translate(b.x, b.y);
      ctx.scale(b.dir, 1);
      ctx.rotate(Math.max(-0.6, Math.min(0.6, Math.atan2(b.vy, Math.abs(b.vx) + 1) * 0.6)));
      var wg = Math.sin(b.flap);
      ctx.beginPath();
      ctx.ellipse(0, 0, S * 0.7, S * 0.3, 0, 0, 6.2832);
      ctx.moveTo(S * 0.9, -S * 0.12); ctx.arc(S * 0.72, -S * 0.12, S * 0.2, 0, 6.2832);
      ctx.moveTo(-S * 0.5, -S * 0.1); ctx.lineTo(-S * 1.25, -S * 0.25); ctx.lineTo(-S * 1.2, S * 0.12); ctx.lineTo(-S * 0.5, S * 0.12);
      ctx.fill();
      ctx.lineWidth = Math.max(1.2, S * 0.2); ctx.lineCap = 'round';
      ctx.beginPath();
      ctx.moveTo(S * 0.15, -S * 0.1);
      ctx.lineTo(-S * 0.25, -S * 1.0 * wg);
      ctx.lineTo(-S * 0.9, -S * 1.5 * wg - S * 0.1);
      ctx.stroke();
    }
    ctx.restore();
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.7 });
    /* 電柱と腕木 */
    ctx.strokeStyle = P.rgba(P.steel, 0.75);
    for (var p = 0; p < poles.length; p++) {
      var px = poles[p];
      if (px < -40 || px > W + 40) continue;
      ctx.lineWidth = Math.max(3, S * 0.55);
      ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, H); ctx.stroke();
      ctx.lineWidth = Math.max(2, S * 0.3);
      for (var g = 0; g < groups.length; g++) {
        ctx.beginPath(); ctx.moveTo(px - S * 4, groups[g].top); ctx.lineTo(px + S * 4, groups[g].top); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(px - S * 2.6, groups[g].top); ctx.lineTo(px, groups[g].top + S * 3);
        ctx.lineTo(px + S * 2.6, groups[g].top); ctx.stroke();
      }
    }
    /* 電線 */
    ctx.lineWidth = 1.2;
    for (var w = 0; w < wires.length; w++) {
      var o = wires[w];
      ctx.strokeStyle = P.rgba(P.line, 0.62);
      ctx.beginPath();
      for (var i = 0; i < NN; i++) {
        var x = X0 + i * HS, y = o.base[i] + o.u[i];
        if (i) ctx.lineTo(x, y); else ctx.moveTo(x, y);
      }
      ctx.stroke();
      ctx.fillStyle = P.rgba(P.steel, 0.9);
      for (p = 0; p < poles.length; p++) ctx.fillRect(poles[p] - S * 0.35, o.y0 - S * 0.9, S * 0.7, S * 0.9);
      if (ptr && ptr.mode === 'wire' && ptr.w === w) {
        var rr = S * 9, c0 = Math.max(0, Math.floor(idxOf(ptr.x - rr * 2))), c1 = Math.min(NN - 1, Math.ceil(idxOf(ptr.x + rr * 2)));
        ctx.strokeStyle = P.rgba(P.accent, 0.8); ctx.lineWidth = 1.8;
        ctx.beginPath();
        for (i = c0; i <= c1; i++) {
          x = X0 + i * HS; y = o.base[i] + o.u[i];
          if (i > c0) ctx.lineTo(x, y); else ctx.moveTo(x, y);
        }
        ctx.stroke(); ctx.lineWidth = 1.2;
      }
    }
    for (var b = 0; b < birds.length; b++) drawBird(birds[b]);
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 操作 */
  function scatterAt(x, y, r) {
    for (var i = 0; i < birds.length; i++) {
      var b = birds[i];
      if (b.st !== 0 || b.w !== ptr.w) continue;
      if (Math.abs(b.x - x) < r) { b.hl = 1; takeoff(b, x, y, 0); }
    }
  }
  function recruit(x, y, n, r) {
    var cand = [];
    for (var i = 0; i < birds.length; i++) {
      var b = birds[i];
      if (b.orbit || b.exit) continue;
      var d = Math.hypot(b.x - x, (b.st === 0 ? wireY(b.w, b.x) : b.y) - y);
      if (d < r) cand.push([d, b]);
    }
    cand.sort(function (a, c) { return a[0] - c[0]; });
    for (var k = 0; k < Math.min(n, cand.length); k++) {
      var o = cand[k][1];
      if (o.st === 0) takeoff(o, x, y, 0);
      o.orbit = true; o.hl = 1; o.tw = -1;
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    var x = e.clientX, y = e.clientY, bw = -1, bd = Math.max(34, S * 4.5);
    for (var w = 0; w < wires.length; w++) {
      var d = Math.abs(wireY(w, x) - y);
      if (d < bd) { bd = d; bw = w; }
    }
    if (bw >= 0) {
      ptr = { x: x, y: y, mode: 'wire', w: bw, gy: y };
      scatterAt(x, y, Math.max(90, Math.min(W, H) * 0.16));
      SFX.play('ripple');
    } else {
      ptr = { x: x, y: y, mode: 'sky' };
      recruit(x, y, 10, Math.max(W, H));
      SFX.play('spawn');
    }
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!ptr) return;
    ptr.x = e.clientX; ptr.y = e.clientY;
    if (ptr.mode === 'wire') {
      var lim = Math.min(W, H) * 0.25, by = wires[ptr.w].y0;
      ptr.gy = Math.max(by - lim, Math.min(by + lim + 40, ptr.y));
      scatterAt(ptr.x, ptr.gy, D0 * 2.2);
    } else {
      recruit(ptr.x, ptr.y, 2, Math.max(80, Math.min(W, H) * 0.15));
    }
    SFX.play('move', 140);
  });
  function up() {
    if (!ptr) return;
    if (ptr.mode === 'wire') SFX.play('collide', 200);
    for (var i = 0; i < birds.length; i++) {
      var b = birds[i];
      if (b.orbit) { b.orbit = false; b.ft = 0.4 + Math.random() * 1.4; b.wx = b.x + b.vx; b.wy = b.y + b.vy * 0.5; }
    }
    ptr = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readCtl() {
    birdAmt = document.getElementById('k-birds').value / 100;
    windAmt = document.getElementById('k-wind').value / 100;
  }
  document.getElementById('k-birds').addEventListener('input', readCtl);
  document.getElementById('k-wind').addEventListener('input', readCtl);
  readCtl();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    T += dt;
    windT -= dt;
    if (windT < 0) { windDir = -windDir; windT = 30 + Math.random() * 25; }
    updateWires(dt);
    updateBirds(dt);
    population(dt);
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
