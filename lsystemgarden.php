<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lsystemgarden');

$pageName = 'lsystemgarden';
$pageTitle = 'L-SYSTEM GARDEN - PULSE';
$pageDesc = '画面の下から草や木が少しずつ伸びて、風が吹くたびに左右になびく。指を動かすと近くの草が揺れ、押したところから新しい芽が伸びる。';
$pageCanonical = 'https://pulse.subara3.com/lsystemgarden.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-grow">GROW</label><input type="range" id="k-grow" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-reseed">RESEED</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ready = false;

  /* 4 種類の草。文字列の書き換え規則で枝ぶりが決まる */
  var SPECIES = [
    { axiom: 'X', rules: { X: 'F+[[X]-X]-F[-FX]+X', F: 'FF' }, angle: 22, iter: 4, tip: 'dot', hgt: 1.0 },
    { axiom: 'F', rules: { F: 'F[+F]F[-F][F]' }, angle: 19, iter: 3, tip: 'dot', hgt: 0.7 },
    { axiom: 'X', rules: { X: 'F[+X][-X]FX', F: 'FF' }, angle: 26, iter: 5, tip: 'ring', hgt: 0.9 },
    { axiom: 'F', rules: { F: 'FF-[-F+F+F]+[+F-F-F]' }, angle: 20, iter: 3, tip: 'none', hgt: 0.85 },
    { axiom: 'X', rules: { X: 'F[-X]F[+X]-X', F: 'FF' }, angle: 14, iter: 5, tip: 'dot', hgt: 1.15 }
  ];
  SPECIES.forEach(function (s) {
    var str = s.axiom;
    for (var i = 0; i < s.iter; i++) {
      var out = '';
      for (var c = 0; c < str.length; c++) out += s.rules[str[c]] || str[c];
      str = out;
    }
    s.str = str;
  });

  var plants = [], motes = [], marks = [], blades = [];
  var windAmt = 0.4, growRate = 0.45, T = 0;

  function makePlant(x, z, touched, preGrow) {
    var sp = SPECIES[(Math.random() * SPECIES.length) | 0];
    var str = sp.str, a0 = sp.angle * Math.PI / 180;
    var n = 0;
    for (var c = 0; c < str.length; c++) if (str[c] === 'F') n++;
    var parent = new Int16Array(n), rel = new Float32Array(n), len = new Float32Array(n),
        dist = new Float32Array(n), tip = new Uint8Array(n).fill(1), head = new Float32Array(n);
    var lean = (Math.random() - 0.5) * 0.25;
    var cur = -1, hd = -Math.PI / 2 + lean, stack = [], k = 0, maxD = 1;
    for (c = 0; c < str.length; c++) {
      var ch = str[c];
      if (ch === 'F') {
        parent[k] = cur;
        rel[k] = hd - (cur < 0 ? -Math.PI / 2 + lean : head[cur]);
        head[k] = hd;
        len[k] = 0.8 + Math.random() * 0.4;
        dist[k] = (cur < 0 ? 0 : dist[cur]) + 1;
        if (dist[k] > maxD) maxD = dist[k];
        if (cur >= 0) tip[cur] = 0;
        cur = k++;
      } else if (ch === '+') hd += a0 * (0.75 + Math.random() * 0.5);
      else if (ch === '-') hd -= a0 * (0.75 + Math.random() * 0.5);
      else if (ch === '[') stack.push(cur, hd);
      else if (ch === ']') { hd = stack.pop(); cur = stack.pop(); }
    }
    /* 静止時の高さを測って、狙いの背丈に合わせる */
    var X = new Float32Array(n), Y = new Float32Array(n), A = new Float32Array(n), top = 0;
    for (k = 0; k < n; k++) {
      var p = parent[k], py = p < 0 ? 0 : Y[p];
      Y[k] = py + Math.sin(head[k]) * len[k];
      if (Y[k] < top) top = Y[k];
    }
    var h = H * (0.18 + z * 0.42) * sp.hgt * (0.75 + Math.random() * 0.4);
    return {
      sp: sp, n: n, parent: parent, rel: rel, len: len, dist: dist, tip: tip,
      X: X, Y: Y, A: A, maxD: maxD, scale: h / Math.max(1, -top),
      bx: x, by: H + 2 - (1 - z) * H * 0.07, base: -Math.PI / 2 + lean, z: z,
      g: preGrow ? 0.25 + Math.random() * 0.8 : 0, speed: 0.75 + Math.random() * 0.5,
      life: 45 + Math.random() * 60, age: preGrow ? Math.random() * 40 : 0,
      fade: 1, sw: 0, sv: 0, cool: touched ? 1 : 0, grown: !!preGrow
    };
  }

  function target() { return Math.round(Math.max(8, Math.min(20, W / 85))); }

  function reseed() {
    plants.length = 0;
    var n = target();
    for (var i = 0; i < n; i++) {
      var z = Math.random();
      plants.push(makePlant((i + 0.2 + Math.random() * 0.6) / n * W, z, false, true));
    }
    plants.sort(function (a, b) { return a.z - b.z; });
  }

  function resize(w, h) {
    W = w; H = h;
    reseed();
    blades.length = 0;
    var nb = Math.round(W / 7);
    for (var b = 0; b < nb; b++) blades.push({ x: Math.random() * W, h: H * (0.015 + Math.pow(Math.random(), 2) * 0.07), lean: (Math.random() - 0.5) * 0.5, a: 0.18 + Math.random() * 0.3 });
    motes.length = 0;
    for (var i = 0; i < 46; i++) motes.push({ x: Math.random() * W, y: H * (0.25 + Math.random() * 0.75), r: 0.6 + Math.random() * 1.1, ph: Math.random() * 6.28 });
  }

  /* 風: 横へ流れていく突風の帯。草の列が順番になびく */
  function wind(x) {
    var base = 0.35 + 0.65 * (0.5 + 0.5 * Math.sin(T * 0.06));
    var gust = Math.sin(x * 0.0042 - T * 0.75) * 0.55 + Math.sin(x * 0.011 - T * 1.6 + 1.3) * 0.22;
    return windAmt * (base * 0.7 + gust * (0.4 + base * 0.6));
  }

  var LN = P.line, ST = P.steel, AC = P.accent;
  function drawPlant(pl) {
    var n = pl.n, g = pl.g * pl.maxD, k2 = 2 / (pl.maxD * pl.maxD);
    var sway = pl.sw + (1 - pl.fade) * 0.9 * (pl.base > -Math.PI / 2 ? 1 : -1);
    var col = P.mix(ST, LN, 0.35 + pl.z * 0.65);
    if (pl.cool > 0) col = P.mix(col, AC, pl.cool);
    var alpha = (0.42 + pl.z * 0.5) * pl.fade;
    var paths = [new Path2D(), new Path2D(), new Path2D()], tips = new Path2D(), anyTip = false;
    var s = pl.scale;
    for (var k = 0; k < n; k++) {
      var p = pl.parent[k];
      var px = p < 0 ? pl.bx : pl.X[p], py = p < 0 ? pl.by : pl.Y[p], pa = p < 0 ? pl.base : pl.A[p];
      var f = g - pl.dist[k] + 1;
      if (f <= 0) { pl.X[k] = px; pl.Y[k] = py; pl.A[k] = pa; continue; }
      if (f > 1) f = 1;
      var a = pa + pl.rel[k] + sway * k2 * pl.dist[k];
      pl.A[k] = a;
      var x = px + Math.cos(a) * pl.len[k] * s * f, y = py + Math.sin(a) * pl.len[k] * s * f;
      pl.X[k] = x; pl.Y[k] = y;
      var q = pl.dist[k] / pl.maxD, b = q < 0.3 ? 0 : q < 0.65 ? 1 : 2;
      paths[b].moveTo(px, py); paths[b].lineTo(x, y);
      if (pl.tip[k] && f === 1 && pl.sp.tip !== 'none') {
        anyTip = true;
        if (pl.sp.tip === 'ring') { tips.moveTo(x + 2.2, y); tips.arc(x, y, 2.2, 0, 6.2832); }
        else { tips.moveTo(x + 1.1, y); tips.arc(x, y, 1.1, 0, 6.2832); }
      }
    }
    var wz = 0.55 + pl.z * 0.75;
    ctx.strokeStyle = P.rgba(col, alpha);
    ctx.lineWidth = 1.7 * wz; ctx.stroke(paths[0]);
    ctx.strokeStyle = P.rgba(col, alpha * 0.9);
    ctx.lineWidth = 1.05 * wz; ctx.stroke(paths[1]);
    ctx.strokeStyle = P.rgba(col, alpha * 0.75);
    ctx.lineWidth = 0.65 * wz; ctx.stroke(paths[2]);
    if (anyTip) {
      if (pl.sp.tip === 'ring') { ctx.lineWidth = 0.8; ctx.strokeStyle = P.rgba(col, alpha * 0.8); ctx.stroke(tips); }
      else { ctx.fillStyle = P.rgba(col, alpha * 0.85); ctx.fill(tips); }
    }
  }

  var ctx = P.fit(canvas, function (w, h) { resize(w, h); ready = true; });

  /* ---------------------------------------------------------- 操作 */
  var down = false, lastX = 0, lastY = 0, lastMoveT = 0;
  function pushAt(x, vx) {
    for (var i = 0; i < plants.length; i++) {
      var pl = plants[i], dx = (pl.bx - x) / 170;
      var f = Math.exp(-dx * dx);
      pl.sv += Math.max(-3, Math.min(3, vx * 0.012)) * f;
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; lastX = e.clientX; lastY = e.clientY;
    var z = 0.7 + Math.random() * 0.3;
    var pl = makePlant(e.clientX, z, true, false);
    plants.push(pl);
    plants.sort(function (a, b) { return a.z - b.z; });
    /* 増えすぎたら、いちばん古い株から枯れはじめる */
    var alive = plants.filter(function (p) { return p.age < p.life; });
    if (alive.length > target() + 4) {
      alive.sort(function (a, b) { return (b.age - b.life) - (a.age - a.life); });
      alive[0].age = alive[0].life;
    }
    marks.push({ x: e.clientX, y: pl.by - 2, t: 0 });
    pushAt(e.clientX, (Math.random() - 0.5) * 60);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (e.pointerType !== 'mouse' && !down) return;
    var dx = e.clientX - lastX;
    lastX = e.clientX; lastY = e.clientY;
    if (Math.abs(dx) < 1) return;
    pushAt(e.clientX, down ? dx * 1.6 : dx * 0.6);
    for (var i = 0; i < motes.length; i++) {
      var m = motes[i], ddx = m.x - e.clientX, ddy = m.y - e.clientY;
      if (ddx * ddx + ddy * ddy < 9000) m.x += dx * 0.3;
    }
    if (down) SFX.play('move', 220);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kWind = document.getElementById('k-wind'), kGrow = document.getElementById('k-grow');
  kWind.addEventListener('input', function () { windAmt = this.value / 100; });
  kGrow.addEventListener('input', function () { growRate = this.value / 100; });
  document.getElementById('k-reseed').addEventListener('click', function () {
    reseed();
    plants.forEach(function (p) { p.g = Math.random() * 0.15; p.age = 0; p.grown = false; });
    SFX.play('click');
  });
  windAmt = kWind.value / 100;
  growRate = kGrow.value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    T += dt;
    if (!ready) { requestAnimationFrame(frame); return; }

    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 36, major: 4, alpha: 0.55 });

    var alive = 0;
    for (var i = plants.length - 1; i >= 0; i--) {
      var pl = plants[i];
      pl.age += dt;
      if (pl.g < 1) {
        pl.g = Math.min(1, pl.g + dt * (0.012 + growRate * 0.07) * pl.speed);
        if (pl.g >= 1 && !pl.grown) { pl.grown = true; SFX.play('grow', 5000); }
      }
      if (pl.cool > 0 && pl.g > 0.6) pl.cool = Math.max(0, pl.cool - dt * 0.08);
      if (pl.age > pl.life) {
        pl.fade -= dt / 9;
        if (pl.fade <= 0) { plants.splice(i, 1); continue; }
      } else alive++;
      var stiff = 5 + (1 - pl.z) * 3;
      var force = wind(pl.bx) * stiff * 0.4;
      pl.sv += (force - pl.sw * stiff - pl.sv * 1.6) * dt;
      pl.sw += pl.sv * dt;
    }
    /* 枯れて減った分は、どこかに芽が出て埋まる */
    if (alive < target() && Math.random() < dt * 0.6) {
      var np = makePlant(Math.random() * W, Math.random(), false, false);
      plants.push(np);
      plants.sort(function (a, b) { return a.z - b.z; });
    }

    for (i = 0; i < plants.length; i++) drawPlant(plants[i]);

    /* 足元の細い草。風の帯がここでも見える */
    ctx.lineWidth = 0.8;
    for (var pass = 0; pass < 2; pass++) {
      ctx.strokeStyle = P.rgba(pass ? P.line : P.steel, pass ? 0.32 : 0.5);
      ctx.beginPath();
      for (i = pass; i < blades.length; i += 2) {
        var bl = blades[i], bend = bl.lean + wind(bl.x) * 1.3;
        var tx = bl.x + Math.sin(bend) * bl.h, ty = H - Math.cos(bend) * bl.h;
        ctx.moveTo(bl.x, H + 1);
        ctx.quadraticCurveTo(bl.x + Math.sin(bend) * bl.h * 0.25, H - bl.h * 0.5, tx, ty);
      }
      ctx.stroke();
    }

    /* 風に乗って漂う綿毛 */
    ctx.fillStyle = P.rgba(P.line, 0.4);
    ctx.beginPath();
    for (i = 0; i < motes.length; i++) {
      var m = motes[i];
      m.x += (12 + wind(m.x) * 90) * dt;
      m.y += Math.sin(T * 0.5 + m.ph) * 6 * dt - 3 * dt;
      if (m.x > W + 10) { m.x = -10; m.y = H * (0.3 + Math.random() * 0.7); }
      if (m.x < -10) m.x = W + 10;
      if (m.y < H * 0.15) m.y = H;
      ctx.moveTo(m.x + m.r, m.y);
      ctx.arc(m.x, m.y, m.r, 0, 6.2832);
    }
    ctx.fill();

    ctx.lineWidth = 1;
    for (i = marks.length - 1; i >= 0; i--) {
      var mk = marks[i];
      mk.t += dt;
      var a = 1 - mk.t / 1.4;
      if (a <= 0) { marks.splice(i, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, a * 0.6);
      ctx.beginPath();
      ctx.ellipse(mk.x, mk.y, 4 + mk.t * 40, 1.5 + mk.t * 8, 0, 0, Math.PI * 2);
      ctx.stroke();
    }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
