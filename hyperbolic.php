<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hyperbolic');

$pageName = 'hyperbolic';
$pageTitle = 'HYPERBOLIC - PULSE';
$pageDesc = '円の中に同じ形の七角形のタイルが敷かれ、縁に近いタイルほど小さく描かれる。並びはゆっくり流れ、縁へ消えたタイルと入れ替わりに反対側から新しいタイルが出てくる。触るとまわりのタイルが光り、触ったタイルが円の中央へ寄ってくる。なぞると、なぞった道のタイルが光る。';
$pageCanonical = 'https://pulse.subara3.com/hyperbolic.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-pq="7,3">SEVEN</button><button type="button" data-pq="5,4">FIVE</button><button type="button" data-pq="4,5">FOUR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, DX = 0, DY = 0, DR = 0, ctx;

  /* ------------------------------------------------ 円盤の中の床（元の座標で一度だけ作る） */
  var PQ = [7, 3];
  var vx, vy, nV;            /* 頂点（多角形の中心も頂点として持つ） */
  var edges, nE;             /* 辺: 頂点番号の組 */
  var polys = [];            /* { c: 中心の頂点番号, v: [頂点番号...] } */
  var keyOf = {};            /* 中心の位置 → 多角形番号 */
  var edgeC = [];            /* 中央の多角形の辺の円 { x, y, r } */

  function orthoCircle(ux, uy, wx, wy) {
    /* u, w を通り、単位円に直交する円 */
    var det = 2 * (ux * wy - uy * wx);
    if (Math.abs(det) < 1e-12) return null;
    var a = ux * ux + uy * uy + 1, b = wx * wx + wy * wy + 1;
    var cx = (a * wy - b * uy) / det, cy = (b * ux - a * wx) / det;
    return { x: cx, y: cy, r: Math.sqrt(cx * cx + cy * cy - 1) };
  }
  function reflect(c, x, y) {
    var dx = x - c.x, dy = y - c.y, d2 = dx * dx + dy * dy, k = c.r * c.r / d2;
    return [c.x + dx * k, c.y + dy * k];
  }
  function key(x, y) { return Math.round(x * 2e5) + ':' + Math.round(y * 2e5); }

  function build() {
    var p = PQ[0], q = PQ[1];
    var R = Math.acosh(1 / (Math.tan(Math.PI / p) * Math.tan(Math.PI / q)));
    var r0 = Math.tanh(R / 2);
    var cap = 60000;
    vx = new Float64Array(cap); vy = new Float64Array(cap); nV = 0;
    var vkey = {};
    function vert(x, y) {
      var k = key(x, y);
      if (vkey[k] != null) return vkey[k];
      vx[nV] = x; vy[nV] = y; vkey[k] = nV;
      return nV++;
    }
    var ekey = {}, elist = [];
    polys = []; keyOf = {};
    function addPoly(cx, cy, pts) {
      var ci = vert(cx, cy), ids = [];
      for (var i = 0; i < pts.length; i++) ids.push(vert(pts[i][0], pts[i][1]));
      for (i = 0; i < ids.length; i++) {
        var a = ids[i], b = ids[(i + 1) % ids.length], k = a < b ? a + ',' + b : b + ',' + a;
        if (!ekey[k]) { ekey[k] = 1; elist.push(a, b); }
      }
      keyOf[key(cx, cy)] = polys.length;
      polys.push({ c: ci, v: ids, pts: pts, x: cx, y: cy });
    }
    var base = [];
    for (var i = 0; i < p; i++) {
      var t = (2 * i + 1) * Math.PI / p - Math.PI / 2;
      base.push([r0 * Math.cos(t), r0 * Math.sin(t)]);
    }
    edgeC = [];
    for (i = 0; i < p; i++) edgeC.push(orthoCircle(base[i][0], base[i][1], base[(i + 1) % p][0], base[(i + 1) % p][1]));
    addPoly(0, 0, base);
    var head = 0, minSize = 0.007;
    while (head < polys.length && polys.length < 9000 && nV < cap - 40) {
      var P0 = polys[head++], pts = P0.pts;
      for (i = 0; i < p; i++) {
        var a = pts[i], b = pts[(i + 1) % p];
        var c = orthoCircle(a[0], a[1], b[0], b[1]);
        if (!c) continue;
        var nc = reflect(c, P0.x, P0.y);
        if (keyOf[key(nc[0], nc[1])] != null) continue;
        var np = [];
        for (var j = 0; j < p; j++) np.push(reflect(c, pts[j][0], pts[j][1]));
        var size = Math.hypot(np[0][0] - np[1][0], np[0][1] - np[1][1]);
        if (size < minSize) continue;
        addPoly(nc[0], nc[1], np);
      }
    }
    edges = new Int32Array(elist); nE = elist.length / 2;
    sx = new Float32Array(nV); sy = new Float32Array(nV);
    lit = [];
  }

  /* ------------------------------------------------ 見る向き: z → M(z) か M(z̄) */
  var M = [1, 0, 0, 0, 0, 0, 1, 0], flip = 0;
  function mul(A, B) {
    function cm(ar, ai, br, bi) { return [ar * br - ai * bi, ar * bi + ai * br]; }
    var r = new Array(8);
    for (var i = 0; i < 2; i++) for (var j = 0; j < 2; j++) {
      var s1 = cm(A[i * 4], A[i * 4 + 1], B[j * 2], B[j * 2 + 1]);
      var s2 = cm(A[i * 4 + 2], A[i * 4 + 3], B[4 + j * 2], B[4 + j * 2 + 1]);
      r[i * 4 + j * 2] = s1[0] + s2[0]; r[i * 4 + j * 2 + 1] = s1[1] + s2[1];
    }
    var dr = r[0] * r[6] - r[1] * r[7] - (r[2] * r[4] - r[3] * r[5]);
    var di = r[0] * r[7] + r[1] * r[6] - (r[2] * r[5] + r[3] * r[4]);
    var s = 1 / Math.sqrt(Math.sqrt(dr * dr + di * di));
    for (i = 0; i < 8; i++) r[i] *= s;
    return r;
  }
  function apply(x, y, out) {
    if (flip) y = -y;
    var nr = M[0] * x - M[1] * y + M[2], ni = M[0] * y + M[1] * x + M[3];
    var dr = M[4] * x - M[5] * y + M[6], di = M[4] * y + M[5] * x + M[7];
    var d2 = dr * dr + di * di;
    out[0] = (nr * dr + ni * di) / d2; out[1] = (ni * dr - nr * di) / d2;
  }
  function invert(x, y) {
    /* (d w - b) / (-c w + a) */
    var nr = M[6] * x - M[7] * y - M[2], ni = M[6] * y + M[7] * x - M[3];
    var dr = -(M[4] * x - M[5] * y) + M[0], di = -(M[4] * y + M[5] * x) + M[1];
    var d2 = dr * dr + di * di;
    var zx = (nr * dr + ni * di) / d2, zy = (ni * dr - nr * di) / d2;
    return flip ? [zx, -zy] : [zx, zy];
  }
  /* 円盤の平行移動 T_a(z) = (z + a) / (ā z + 1) を左から掛ける */
  function translate(ax, ay) {
    M = mul([1, 0, ax, ay, ax, -ay, 1, 0], M);
  }
  function rotate(t) {
    var c = Math.cos(t / 2), s = Math.sin(t / 2);
    M = mul([c, s, 0, 0, 0, 0, c, -s], M);
  }
  function tAB(ax, ay, bx, by) { /* a を b へ運ぶ: T_b ∘ T_{-a} */
    translate(-ax, -ay); translate(bx, by);
  }
  /* 画面の中心が中央の多角形から出たら、床の対称性で付け替える（見た目は変わらない） */
  function rebase() {
    for (var guard = 0; guard < 6; guard++) {
      var a = invert(0, 0), hit = -1;
      for (var k = 0; k < edgeC.length; k++) {
        var c = edgeC[k];
        if (Math.hypot(a[0] - c.x, a[1] - c.y) < c.r) { hit = k; break; }
      }
      if (hit < 0) return;
      var e = edgeC[hit];
      var MR = [e.x, e.y, -1, 0, 1, 0, -e.x, e.y];
      if (flip) { MR = [e.x, -e.y, -1, 0, 1, 0, -e.x, -e.y]; M = mul(M, MR); flip = 0; }
      else { M = mul(M, MR); flip = 1; }
      /* 灯っているタイルも、同じ場所に見える多角形へ付け替える */
      for (var n = lit.length - 1; n >= 0; n--) {
        var pl = polys[lit[n].i], rc = reflect(e, pl.x, pl.y), j = keyOf[key(rc[0], rc[1])];
        if (j == null) lit.splice(n, 1); else lit[n].i = j;
      }
    }
  }

  /* ------------------------------------------------ 描く */
  var sx, sy, lit = [], tmp = [0, 0];
  var NB = 8, buckets = [];
  function smooth(a, b, x) { var t = (x - a) / (b - a); t = t < 0 ? 0 : t > 1 ? 1 : t; return t * t * (3 - 2 * t); }
  function arcTo(i, j) {
    var ux = sx[i], uy = sy[i], wx = sx[j], wy = sy[j];
    /* 円盤座標に戻して直交円を求め、画面座標の弧にする */
    var c = orthoCircle((ux - DX) / DR, (uy - DY) / DR, (wx - DX) / DR, (wy - DY) / DR);
    if (!c || c.r > 60) { ctx.lineTo(wx, wy); return; }
    var cx = DX + c.x * DR, cy = DY + c.y * DR, r = c.r * DR;
    var a1 = Math.atan2(uy - cy, ux - cx), a2 = Math.atan2(wy - cy, wx - cx);
    var d = a2 - a1;
    while (d > Math.PI) d -= 2 * Math.PI;
    while (d < -Math.PI) d += 2 * Math.PI;
    ctx.arc(cx, cy, r, a1, a2, d < 0);
  }
  function polyPath(pl) {
    var v = pl.v;
    ctx.moveTo(sx[v[0]], sy[v[0]]);
    for (var i = 0; i < v.length; i++) arcTo(v[i], v[(i + 1) % v.length]);
    ctx.closePath();
  }
  function draw(dt) {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.8 });
    ctx.fillStyle = P.rgba(P.bg, 1);
    ctx.beginPath(); ctx.arc(DX, DY, DR + 14, 0, Math.PI * 2); ctx.fill();
    /* 円盤は中央ほど明るい板にして、縁の無限の遠くへ沈ませる */
    var dg = ctx.createRadialGradient(DX, DY, 0, DX, DY, DR);
    dg.addColorStop(0, P.rgba(P.steel, 0.3));
    dg.addColorStop(0.75, P.rgba(P.steel, 0.17));
    dg.addColorStop(1, P.rgba(P.deep, 0.4));
    ctx.fillStyle = dg;
    ctx.beginPath(); ctx.arc(DX, DY, DR, 0, Math.PI * 2); ctx.fill();

    for (var i = 0; i < nV; i++) {
      apply(vx[i], vy[i], tmp);
      sx[i] = DX + tmp[0] * DR; sy[i] = DY + tmp[1] * DR;
    }
    ctx.save();
    ctx.beginPath(); ctx.arc(DX, DY, DR, 0, Math.PI * 2); ctx.clip();

    /* 触れたタイル: シアンで灯り、床と一緒に流れながら消えていく */
    for (var n = lit.length - 1; n >= 0; n--) {
      var L = lit[n];
      L.a -= dt * 0.5;
      if (L.a <= 0) { lit.splice(n, 1); continue; }
      ctx.fillStyle = P.rgba(P.accent, L.a * 0.68);
      ctx.beginPath(); polyPath(polys[L.i]); ctx.fill();
    }

    /* 補助線: 大きく見えるタイルだけ、中心から角へ細い線 */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.steel, 0.3);
    ctx.beginPath();
    for (n = 0; n < polys.length; n++) {
      var pl = polys[n], c0 = pl.c, v0 = pl.v[0];
      var sz = Math.hypot(sx[c0] - sx[v0], sy[c0] - sy[v0]);
      if (sz < 18) continue;
      for (var k = 0; k < pl.v.length; k++) {
        ctx.moveTo(sx[c0], sy[c0]); arcTo(c0, pl.v[k]);
      }
    }
    ctx.stroke();

    for (var b = 0; b < NB; b++) buckets[b] = [];
    for (var e = 0; e < nE; e++) {
      var a = edges[e * 2], bb = edges[e * 2 + 1];
      var len = Math.hypot(sx[a] - sx[bb], sy[a] - sy[bb]);
      if (len < 1.2) continue;
      var al = smooth(1.2, 16, len);
      buckets[Math.min(NB - 1, (al * NB) | 0)].push(e);
    }
    for (b = 0; b < NB; b++) {
      var list = buckets[b];
      if (!list.length) continue;
      ctx.lineWidth = b > 6 ? 2.2 : b > 3 ? 1.5 : 1;
      ctx.strokeStyle = P.rgba(P.line, 0.25 + (b + 0.5) / NB * 0.7);
      ctx.beginPath();
      for (var m = 0; m < list.length; m++) {
        var ea = edges[list[m] * 2], eb = edges[list[m] * 2 + 1];
        ctx.moveTo(sx[ea], sy[ea]);
        arcTo(ea, eb);
      }
      ctx.stroke();
    }
    ctx.restore();

    /* 縁: 無限の遠くは、この細い円 */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.line, 0.55);
    ctx.beginPath(); ctx.arc(DX, DY, DR, 0, Math.PI * 2); ctx.stroke();
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.beginPath(); ctx.arc(DX, DY, DR + 14, 0, Math.PI * 2); ctx.stroke();
  }

  /* ------------------------------------------------ 操作 */
  var drift = 0.4, down = false, last = null, vel = [0, 0], held = 0, lastMove = 0;
  function toDisk(e) {
    var x = (e.clientX - DX) / DR, y = (e.clientY - DY) / DR, r = Math.hypot(x, y);
    if (r > 0.985) { x *= 0.985 / r; y *= 0.985 / r; }
    return [x, y];
  }
  /* 指の下のタイルと、画面上で近くにあるタイルを灯す */
  function light(w, rad) {
    var z = invert(w[0], w[1]), best = -1, bd = 1e9, i, n;
    var px0 = DX + w[0] * DR, py0 = DY + w[1] * DR, added = false;
    function on(k, a) {
      for (var m = 0; m < lit.length; m++) if (lit[m].i === k) { lit[m].a = Math.max(lit[m].a, a); return; }
      lit.push({ i: k, a: a }); added = true;
    }
    for (i = 0; i < polys.length; i++) {
      var d = Math.hypot(polys[i].x - z[0], polys[i].y - z[1]);
      if (d < bd) { bd = d; best = i; }
      var c = polys[i].c, sd = Math.hypot(sx[c] - px0, sy[c] - py0);
      if (sd < rad) on(i, 0.5 + 0.4 * (1 - sd / rad));
    }
    if (best >= 0) on(best, 1);
    while (lit.length > 120) lit.shift();
    return added;
  }
  var pull = null, downAt = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = toDisk(e); vel = [0, 0]; lastMove = performance.now();
    light(last, 160);
    downAt = last; pull = null;
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = toDisk(e);
    if (Math.hypot(p[0] - last[0], p[1] - last[1]) < 0.01) return;
    /* なぞった道のタイルが順に灯る */
    var steps = Math.ceil(Math.hypot(p[0] - last[0], p[1] - last[1]) / 0.03), lit1 = false;
    for (var k = 1; k <= steps; k++) {
      var q = [last[0] + (p[0] - last[0]) * k / steps, last[1] + (p[1] - last[1]) * k / steps];
      if (light(q, 120)) lit1 = true;
    }
    last = p;
    if (lit1) SFX.play('move', 180);
  });
  function up() {
    down = false;
    /* 軽く押しただけなら、押したタイルを円の中央へたぐり寄せる */
    if (downAt && Math.hypot(last[0] - downAt[0], last[1] - downAt[1]) < 0.02) {
      pull = { z: invert(last[0], last[1]), t: 0 }; held = 0;
    }
    downAt = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kd = document.getElementById('k-drift');
  kd.addEventListener('input', function () { drift = this.value / 100; });
  drift = kd.value / 100;
  document.querySelectorAll('[data-pq]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-pq]').forEach(function (o) { o.classList.toggle('active', o === b); });
      PQ = b.dataset.pq.split(',').map(Number);
      M = [1, 0, 0, 0, 0, 0, 1, 0]; flip = 0;
      build();
      SFX.play('toggle');
    });
  });

  build();
  ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; DX = w / 2; DY = h / 2;
    DR = Math.min(w, h) * 0.47;
  });

  /* ------------------------------------------------ 回す */
  var lastT = performance.now(), T = 0, chime = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now; T += dt;
    if (pull) {
      var pw = [0, 0];
      apply(pull.z[0], pull.z[1], pw);
      var kq = Math.min(1, dt * 5);
      tAB(pw[0], pw[1], pw[0] * (1 - kq), pw[1] * (1 - kq));
      rebase();
      /* 付け替えで元の座標が変わるので、今の位置から指し直す */
      var pz = invert(pw[0] * (1 - kq), pw[1] * (1 - kq));
      pull.z = pz; pull.t += dt;
      if (pull.t > 0.9 || Math.hypot(pw[0], pw[1]) < 0.01) pull = null;
    } else if (!down) {
      /* 放っておくと、向きをゆっくり変えながら流れ続ける */
      var th = T * 0.045 + Math.sin(T * 0.11) * 1.3;
      var sp = (0.006 + drift * 0.025) * dt;
      var ax = Math.cos(th) * sp, ay = Math.sin(th) * sp;
      if (held > 0) {
        ax = ax * (1 - held) + vel[0] * dt * held; ay = ay * (1 - held) + vel[1] * dt * held;
        held = Math.max(0, held - dt * 0.7);
      }
      translate(ax, ay);
      rotate(dt * 0.004);
      rebase();
    }
    draw(dt);
    chime += dt;
    if (chime > 8) { chime = 0; SFX.play('tick', 4000); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
