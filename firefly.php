<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('firefly');

$pageName = 'firefly';
$pageTitle = 'FIREFLY - PULSE';
$pageDesc = '草むらの上でホタルがばらばらの間隔で光り、近いものどうしから光る時刻が揃っていく。押したところにホタルが集まって一斉に光り、なぞった跡には明かりが残って草が照らされる。';
$pageCanonical = 'https://pulse.subara3.com/firefly.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pull">PULL</label><input type="range" id="k-pull" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-swarm">SWARM</label><input type="range" id="k-swarm" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-stir">STIR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = canvas.getContext('2d');

  /* ---------------------------------------------------------- 草 */
  var blades = [];
  function makeGrass() {
    blades = [];
    var n = Math.round(W / 4);
    for (var i = 0; i < n; i++) {
      var x = (i + Math.random()) / n * W;
      var tall = Math.pow(Math.random(), 1.6);
      blades.push({
        x: x,
        h: H * (0.05 + tall * 0.34),
        lean: (Math.random() - 0.5) * 0.5,
        ph: Math.random() * 6.28,
        a: 0.1 + Math.random() * 0.16
      });
    }
  }
  /* 草は近くのホタルの明かりを受けて、そこだけ白く浮かぶ */
  var BUCKETS = 5, bucket = [];
  function drawGrass(t) {
    var R2 = 110 * 110, i, j;
    for (i = 0; i < BUCKETS; i++) bucket[i] = [];
    for (i = 0; i < blades.length; i++) {
      var b = blades[i];
      var sway = Math.sin(t * 0.5 + b.ph + b.x * 0.004) * 0.08 + b.lean;
      b.tx = b.x + sway * b.h; b.ty = H - b.h; b.cx = b.x + sway * b.h * 0.15;
      var mx = (b.x + b.tx) * 0.5, my = H - b.h * 0.6, lit = 0;
      for (j = 0; j < N; j++) {
        var g = lightOf[j]; if (g < 0.02) continue;
        var dx = fx[j] - mx, dy = fy[j] - my, d2 = dx * dx + dy * dy;
        if (d2 < R2) lit += g * (1 - d2 / R2);
      }
      /* 指は手提げの灯りのように、近くの草を照らす */
      for (var q = 0; q < lamps.length; q++) {
        var lp = lamps[q], tdx = lp.x - mx, tdy = lp.y - my, td2 = tdx * tdx + tdy * tdy;
        if (td2 < 150 * 150) lit += lp.a * 1.8 * (1 - td2 / (150 * 150));
      }
      b.lit = Math.min(1, lit * 0.9);
      bucket[Math.min(BUCKETS - 1, Math.floor(b.lit * BUCKETS))].push(b);
    }
    ctx.lineWidth = 1;
    for (i = 0; i < BUCKETS; i++) {
      var list = bucket[i]; if (!list.length) continue;
      var u = (i + 0.5) / BUCKETS;
      ctx.strokeStyle = P.rgba(P.mix(P.steel, P.line, u * u), 0.1 + u * 0.6);
      ctx.beginPath();
      for (j = 0; j < list.length; j++) {
        var bb = list[j];
        ctx.moveTo(bb.x, H + 2);
        ctx.quadraticCurveTo(bb.cx, H - bb.h * 0.55, bb.tx, bb.ty);
      }
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- ホタル */
  var MAX = 320, N = 0;
  var fx = new Float32Array(MAX), fy = new Float32Array(MAX);
  var hd = new Float32Array(MAX), spd = new Float32Array(MAX);
  var ph = new Float32Array(MAX), per = new Float32Array(MAX), since = new Float32Array(MAX);
  var lightOf = new Float32Array(MAX);
  var pull = 0.08;

  function place(i) {
    fx[i] = Math.random() * W;
    fy[i] = H * (0.35 + Math.pow(Math.random(), 0.6) * 0.6);
    hd[i] = Math.random() * 6.28;
    spd[i] = 7 + Math.random() * 12;
    ph[i] = Math.random();
    per[i] = 3.4 * (0.95 + Math.random() * 0.1);
    since[i] = 99;
  }
  function setCount(n) {
    n = Math.max(40, Math.min(MAX, n | 0));
    for (var i = N; i < n; i++) place(i);
    N = n;
  }

  /* 光るときに近くの仲間の時計を進める。届いた仲間がしきい値を越えたら、それも続けて光る */
  var queue = [], R = 125;
  function fire(i) {
    ph[i] = 0; since[i] = 0;
    queue.push(i);
  }
  function propagate() {
    var R2 = R * R, guard = 0;
    while (queue.length && guard++ < 2000) {
      var i = queue.shift();
      for (var j = 0; j < N; j++) {
        if (j === i || since[j] < 0.6) continue;
        var dx = fx[j] - fx[i], dy = fy[j] - fy[i], d2 = dx * dx + dy * dy;
        if (d2 > R2) continue;
        var k = pull * (1 - d2 / R2);
        ph[j] += k * (0.25 + ph[j]);
        if (ph[j] >= 1) fire(j);
      }
    }
  }

  /* 光り方: ふわっと立ち上がって、ゆっくり消える */
  function glow(s) {
    if (s < 0.5) { var u = s / 0.5; return u * u * (3 - 2 * u); }
    return Math.exp(-(s - 0.5) * 1.7);
  }

  /* 光の輪郭は一度だけ描いておいて使い回す */
  var sprite = document.createElement('canvas');
  (function () {
    var S = 64; sprite.width = sprite.height = S;
    var g = sprite.getContext('2d');
    var gr = g.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
    gr.addColorStop(0, P.rgba(P.line, 0.8));
    gr.addColorStop(0.12, P.rgba(P.line, 0.38));
    gr.addColorStop(0.45, P.rgba(P.mix(P.steel, P.line, 0.5), 0.09));
    gr.addColorStop(1, P.rgba(P.steel, 0));
    g.fillStyle = gr; g.fillRect(0, 0, S, S);
  })();

  /* 宣言がそろってから画面に合わせる（P.fit はその場でコールバックを呼ぶ） */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; makeGrass(); });

  /* ---------------------------------------------------------- 操作 */
  var touch = { x: 0, y: 0, on: false, glow: 0 }, rings = [], lamps = [], lastLamp = null;
  function lightAround(x, y, r) {
    var n = 0;
    for (var i = 0; i < N; i++) {
      var dx = fx[i] - x, dy = fy[i] - y;
      if (dx * dx + dy * dy < r * r && since[i] > 0.6) { fire(i); n++; }
    }
    propagate();
    return n;
  }
  /* 指のところからホタルが飛び立つ（遠くのホタルを 1 匹ずつ呼び寄せて使う） */
  var recycle = 0;
  function release(x, y, n, r) {
    for (var k = 0; k < n; k++) {
      var i = recycle; recycle = (recycle + 1) % N;
      var a = Math.random() * 6.283, rr = Math.sqrt(Math.random()) * r;
      fx[i] = x + Math.cos(a) * rr; fy[i] = y + Math.sin(a) * rr; hd[i] = a;
      ph[i] = 0; since[i] = Math.random() * 0.15;
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    touch.on = true; touch.x = e.clientX; touch.y = e.clientY;
    lamps.push({ x: touch.x, y: touch.y, a: 1 }); lastLamp = { x: touch.x, y: touch.y };
    release(touch.x, touch.y, 14, 50);
    rings.push({ x: touch.x, y: touch.y, t: 0 });
    lightAround(touch.x, touch.y, 170);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!touch.on) return;
    touch.x = e.clientX; touch.y = e.clientY;
    if (Math.hypot(touch.x - lastLamp.x, touch.y - lastLamp.y) > 16) {
      lamps.push({ x: touch.x, y: touch.y, a: 1 }); lastLamp = { x: touch.x, y: touch.y };
      if (lamps.length > 60) lamps.shift();
      release(touch.x, touch.y, 4, 26);
    }
    if (lightAround(touch.x, touch.y, 110)) SFX.play('tick', 260);
  });
  function up() { touch.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function pullFrom(v) { return 0.01 + v / 100 * 0.1; }
  function swarmFrom(v) { return 60 + v / 100 * 240; }
  document.getElementById('k-pull').addEventListener('input', function () { pull = pullFrom(+this.value); });
  document.getElementById('k-swarm').addEventListener('input', function () {
    var n = swarmFrom(+this.value) | 0;
    if (n > N) setCount(n); else N = n;
  });
  document.getElementById('k-stir').addEventListener('click', function () {
    for (var i = 0; i < N; i++) { ph[i] = Math.random(); hd[i] = Math.random() * 6.28; }
    SFX.play('click');
  });
  pull = pullFrom(+document.getElementById('k-pull').value);

  /* ---------------------------------------------------------- 回す */
  var T = 0, lastT = performance.now(), lastChord = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now; T += dt;

    for (var lq = lamps.length - 1; lq >= 0; lq--) { lamps[lq].a -= dt * 0.45; if (lamps[lq].a <= 0) lamps.splice(lq, 1); }
    var burst = 0;
    for (var i = 0; i < N; i++) {
      /* ふらふら飛ぶ。向きを少しずつ変え、草むらの帯から出すぎない */
      hd[i] += (Math.random() - 0.5) * 2.2 * dt;
      var vx = Math.cos(hd[i]) * spd[i], vy = Math.sin(hd[i]) * spd[i] * 0.6;
      if (touch.on) {
        var tx = touch.x - fx[i], ty = touch.y - fy[i], td = Math.sqrt(tx * tx + ty * ty) + 1;
        if (td < 260) { vx += tx / td * 22 * (1 - td / 260); vy += ty / td * 22 * (1 - td / 260); }
      }
      fx[i] += vx * dt; fy[i] += vy * dt;
      if (fy[i] < H * 0.22) hd[i] += (Math.sin(hd[i]) < 0 ? 1 : 0) * dt * 2;
      if (fy[i] > H * 0.97) hd[i] -= (Math.sin(hd[i]) > 0 ? 1 : 0) * dt * 2;
      if (fx[i] < -20) fx[i] = W + 20; else if (fx[i] > W + 20) fx[i] = -20;

      since[i] += dt;
      ph[i] += dt / per[i];
      /* たまに一匹が気まぐれに時計をずらす。揃いきって止まって見えないように */
      if (Math.random() < dt / 45) ph[i] = Math.random();
      if (ph[i] >= 1) { fire(i); burst++; }
    }
    propagate();
    if (burst > N * 0.25 && T - lastChord > 4) { SFX.play('tick', 3000); lastChord = T; }

    for (i = 0; i < N; i++) lightOf[i] = glow(since[i]);
    P.fill(ctx, W, H);
    drawGrass(T);

    /* 光の輪 */
    ctx.globalCompositeOperation = 'lighter';
    /* 指でなぞった跡: やわらかい明かりがしばらく残る */
    for (var lm = 0; lm < lamps.length; lm++) {
      var la = lamps[lm].a, lr = 50 + la * 40;
      ctx.globalAlpha = la * 0.75;
      ctx.drawImage(sprite, lamps[lm].x - lr, lamps[lm].y - lr, lr * 2, lr * 2);
    }
    for (i = 0; i < N; i++) {
      var g = lightOf[i];
      if (g < 0.01) continue;
      var r = 20 + g * 26;
      ctx.globalAlpha = g * 0.62;
      ctx.drawImage(sprite, fx[i] - r, fy[i] - r, r * 2, r * 2);
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';

    /* 体: 暗いときも小さな点として見えている */
    for (i = 0; i < N; i++) {
      var gg = lightOf[i];
      ctx.fillStyle = P.rgba(P.mix(P.steel, P.line, gg), 0.14 + gg * 0.86);
      ctx.fillRect(fx[i] - 1, fy[i] - 1, 2, 2);
    }

    /* 触ったところにだけシアンの輪 */
    ctx.lineWidth = 1;
    for (var k = rings.length - 1; k >= 0; k--) {
      var rg = rings[k];
      rg.t += dt;
      var a = 1 - rg.t / 1.8;
      if (a <= 0) { rings.splice(k, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, a * 0.45);
      ctx.beginPath(); ctx.arc(rg.x, rg.y, 10 + rg.t * 80, 0, Math.PI * 2); ctx.stroke();
    }
    if (touch.on) {
      ctx.fillStyle = P.rgba(P.accent, 0.8);
      ctx.beginPath(); ctx.arc(touch.x, touch.y, 2.5, 0, Math.PI * 2); ctx.fill();
    }
    requestAnimationFrame(frame);
  }
  setCount(swarmFrom(+document.getElementById('k-swarm').value));
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
