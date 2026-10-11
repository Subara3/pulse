<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pane');

$pageName = 'pane';
$pageTitle = 'PANE - PULSE';
$pageDesc = '曇った窓ガラスに雨粒がつき、くっついて大きくなった粒が下へ流れ落ちていく。流れた跡は曇りが取れ、小さな粒が点々と残る。押すとまわりの粒が指の下に集まり、なぞると曇りが拭われて、離すと集めた水が流れ落ちる。';
$pageCanonical = 'https://pulse.subara3.com/pane.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-rain">RAIN</label><input type="range" id="k-rain" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-fog">FOG</label><input type="range" id="k-fog" min="0" max="100" value="60"></div>
  <div class="ctl-btns"><button type="button" id="k-wipe">WIPE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 曇りは粗い格子で持ち、拡大して描く */
  var G = 6, NX = 0, NY = 0, fog = null, grain = null, img = null, buf = null, bctx = null;
  /* 粒: x, y, r, 流れているか, 速さ, 横ゆれ, 流れ出す大きさ, 触った光 */
  var drops = [];
  var S = 1, RC = 7, maxN = 600;
  var rain = 0.55, fogK = 0.6;
  var held = null, down = false, last = null, clock = 0, idle = 0;
  /* 近くの粒をすぐ引けるよう、升目ごとに粒を分けておく */
  var CS = 40, BX = 0, BY = 0, bins = [];

  function mkDrop(x, y, r) {
    return { x: x, y: y, r: r, slide: false, v: 0, ph: Math.random() * 6.28, rc: RC * (0.85 + Math.random() * 0.35), hot: 0, trail: 0, dead: false };
  }

  function alloc() {
    S = Math.max(0.7, Math.min(1.3, Math.sqrt(W * H) / 900));
    RC = 8 * S;
    maxN = Math.round(W * H / 800);
    BX = Math.ceil(W / CS) + 1; BY = Math.ceil(H / CS) + 1;
    NX = Math.ceil(W / G) + 1; NY = Math.ceil(H / G) + 1;
    fog = new Float32Array(NX * NY);
    grain = new Float32Array(NX * NY);
    for (var i = 0; i < fog.length; i++) { fog[i] = 0.75 + Math.random() * 0.25; grain[i] = 0.8 + Math.random() * 0.4; }
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    drops = []; held = null;
    rebin();
    var n = Math.round(maxN * 0.7);
    for (var k = 0; k < n; k++) addRain(Math.random() * W, Math.random() * H, (1 + Math.pow(Math.random(), 2.5) * 7.5) * S);
    /* 開いたときにもう流れた跡があるよう、少しだけ先に進めておく */
    for (var s = 0; s < 70; s++) sim(0.05, true);
  }

  /* 曇りを拭う。粒が流れた跡・指でなぞった跡 */
  function wipe(x, y, rad) {
    var i0 = Math.max(0, Math.floor((x - rad) / G)), i1 = Math.min(NX - 1, Math.ceil((x + rad) / G));
    var j0 = Math.max(0, Math.floor((y - rad) / G)), j1 = Math.min(NY - 1, Math.ceil((y + rad) / G));
    for (var j = j0; j <= j1; j++) {
      for (var i = i0; i <= i1; i++) {
        var d = Math.hypot(i * G - x, j * G - y) / rad;
        if (d < 1) { var k = j * NX + i; fog[k] = Math.min(fog[k], d * d * 0.5); }
      }
    }
  }
  function fogAt(x, y) {
    var i = Math.round(x / G), j = Math.round(y / G);
    if (i < 0 || j < 0 || i >= NX || j >= NY) return 1;
    return fog[j * NX + i];
  }

  /* 2 つの粒を 1 つに。水の量（面積）を足し、重い方へ寄る */
  function absorb(a, b) {
    var ma = a.r * a.r, mb = b.r * b.r, m = ma + mb;
    if (!a.slide && a !== held) { a.x = (a.x * ma + b.x * mb) / m; a.y = (a.y * ma + b.y * mb) / m; }
    a.r = Math.sqrt(m);
    if (b.hot > a.hot) a.hot = b.hot * 0.8;
    b.dead = true;
  }

  function rebin() {
    bins.length = BX * BY;
    for (var b = 0; b < bins.length; b++) { if (bins[b]) bins[b].length = 0; else bins[b] = []; }
    for (var i = 0; i < drops.length; i++) put(drops[i]);
  }
  function put(d) {
    var bx = Math.max(0, Math.min(BX - 1, Math.floor(d.x / CS))), by = Math.max(0, Math.min(BY - 1, Math.floor(d.y / CS)));
    bins[by * BX + bx].push(d);
  }
  /* (x, y) から rad + 相手の半径 以内にいる粒に fn を呼ぶ。fn が true を返したら打ち切る */
  function near(x, y, rad, fn) {
    var R = rad + 22 * S;
    var x0 = Math.max(0, Math.floor((x - R) / CS)), x1 = Math.min(BX - 1, Math.floor((x + R) / CS));
    var y0 = Math.max(0, Math.floor((y - R) / CS)), y1 = Math.min(BY - 1, Math.floor((y + R) / CS));
    for (var by = y0; by <= y1; by++) for (var bx = x0; bx <= x1; bx++) {
      var c = bins[by * BX + bx];
      for (var i = 0; i < c.length; i++) {
        var o = c[i];
        if (o.dead) continue;
        var dx = o.x - x, dy = o.y - y, rr = o.r + rad;
        if (dx * dx + dy * dy < rr * rr && fn(o)) return;
      }
    }
  }

  function addRain(x, y, r) {
    var d = mkDrop(x, y, r), hit = null;
    near(x, y, r, function (o) { if (o !== held) { hit = o; return true; } return false; });
    if (hit) absorb(hit, d);
    else { drops.push(d); put(d); }
  }

  /* 動いている粒（流れる粒・指の粒）が、触れた粒を飲みこむ */
  function sweep(a, reach) {
    near(a.x, a.y, a.r + reach, function (o) {
      if (o === a || o === held || a.dead) return false;
      if (o.slide && o.r > a.r && a !== held) { absorb(o, a); return true; }
      absorb(a, o);
      return false;
    });
  }

  function sim(dt, quiet) {
    clock += dt;
    rebin();
    /* 雨: 画面の広さに比例して降る。多すぎるときは小さな粒が早く乾く */
    var n = drops.length;
    var rate = W * H / 7000 * (0.15 + rain * 1.5) * (n > maxN ? 0.4 : 1);
    var k = rate * dt;
    while (k > 0) {
      if (k >= 1 || Math.random() < k) addRain(Math.random() * W, Math.random() * H, (1 + Math.pow(Math.random(), 2.5) * 2.6) * S);
      k -= 1;
    }
    var dry = n > maxN * 0.85 ? 0.12 : 0.03;
    var fogGrow = dt * (0.004 + fogK * 0.03);

    for (var i = 0; i < drops.length; i++) {
      var d = drops[i];
      if (d.dead) continue;
      if (d.hot > 0) d.hot = Math.max(0, d.hot - dt * 0.35);
      if (d === held) continue;
      /* 指が止まっている間、近くの粒が指の下へ寄ってくる */
      if (held && !d.slide) {
        var hx = held.x - d.x, hy = held.y - d.y, hd = Math.hypot(hx, hy), reach = 90 * S;
        if (hd < reach && hd > 0.1) {
          var sp = (1 - hd / reach) * 160 * S * dt;
          d.x += hx / hd * sp; d.y += hy / hd * sp;
          d.hot = Math.max(d.hot, 0.6);
        }
      }
      if (!d.slide) {
        if (d.r > d.rc) { d.slide = true; d.v = 0; d.trail = 0; if (!quiet && Math.random() < 0.5) SFX.play('tick', 2600); }
        else if (d.r < 1.7 * S) { d.r -= dt * dry * S; if (d.r < 0.5 * S) d.dead = true; }
        else {
          /* 曇りの水を少しずつ吸って育ち、隣の粒に触れたら 1 つになる */
          d.r += dt * 0.05 * S * (0.3 + fogK);
          if (((i + (clock * 20 | 0)) & 7) === 0) sweep(d, 0);
        }
        continue;
      }
      /* 流れる粒: 大きいほど速い。前の跡（曇りの取れた筋）へ寄っていく */
      var vt = (26 + (d.r - d.rc * 0.7) * 22) * S;
      d.v += (vt - d.v) * Math.min(1, dt * 2.5);
      var look = d.r + 5 * S;
      var steer = (fogAt(d.x - look, d.y + d.r * 2) - fogAt(d.x + look, d.y + d.r * 2)) * 30 * S;
      var vx = steer + Math.sin(clock * 0.9 + d.ph) * 9 * S;
      var dy = d.v * dt;
      d.x += vx * dt; d.y += dy;
      wipe(d.x, d.y, d.r + 2 * S);
      sweep(d, 0);
      if (d.dead) continue;
      /* 通った跡に小さな粒を落として、少し小さくなる */
      d.trail += dy;
      var gap = (9 + Math.random() * 16) * S;
      if (d.trail > gap) {
        d.trail = 0;
        var tr = (0.7 + Math.random() * 1.1) * S;
        var t = mkDrop(d.x + (Math.random() - 0.5) * d.r * 0.6, d.y - d.r * 1.3, tr);
        t.hot = d.hot * 0.9;
        drops.push(t);
        d.r = Math.sqrt(Math.max(0.2, d.r * d.r - tr * tr * 0.9));
      }
      if (d.r < d.rc * 0.72) { d.slide = false; d.rc = RC * (0.85 + Math.random() * 0.35); }
      if (d.y - d.r > H + 4) d.dead = true;
      if (d.x < -20 || d.x > W + 20) d.dead = true;
    }
    /* しばらく何も流れないときは、いちばん大きな粒が重さに負ける */
    var moving = false, big = null;
    for (var m = 0; m < drops.length; m++) {
      var dm = drops[m];
      if (dm.dead || dm === held) continue;
      if (dm.slide) { moving = true; break; }
      if (!big || dm.r > big.r) big = dm;
    }
    idle = moving ? 0 : idle + dt;
    if (idle > 1.5 && big) { big.r = Math.max(big.r, big.rc + 0.5 * S); idle = 0; }
    /* 曇りはゆっくり戻る */
    var top = 0.35 + fogK * 0.65;
    for (var f = 0; f < fog.length; f++) if (fog[f] < top) fog[f] = Math.min(top, fog[f] + fogGrow);
    /* 消えた粒を片付ける */
    var w = 0;
    for (var q = 0; q < drops.length; q++) if (!drops[q].dead) drops[w++] = drops[q];
    drops.length = w;
  }

  /* ---------------------------------------------------------- 描く */
  var BG = P.bg, ST = P.steel;
  function drawFog() {
    var px = img.data;
    for (var k = 0; k < fog.length; k++) {
      var a = Math.min(1, fog[k] * grain[k]) * 0.34, o = k * 4;
      px[o] = BG[0] + (ST[0] - BG[0]) * a;
      px[o + 1] = BG[1] + (ST[1] - BG[1]) * a;
      px[o + 2] = BG[2] + (ST[2] - BG[2]) * a;
      px[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, NX * G, NY * G);
    P.grid(ctx, W, H, { step: Math.round(32 * S), alpha: 0.8 });
  }

  function drawDrops() {
    var small = S * 2.2, i, d, ry;
    /* 小さな粒は点で */
    ctx.fillStyle = P.rgba(P.line, 0.42);
    ctx.beginPath();
    for (i = 0; i < drops.length; i++) {
      d = drops[i];
      if (d.r >= small || d.hot > 0.05) continue;
      ctx.moveTo(d.x + d.r * 0.8, d.y); ctx.arc(d.x, d.y, d.r * 0.8, 0, 6.2832);
    }
    ctx.fill();
    /* 大きな粒: 曇りの取れた暗い中身 */
    ctx.fillStyle = P.rgba(P.deep, 0.5);
    ctx.beginPath();
    for (i = 0; i < drops.length; i++) {
      d = drops[i];
      if (d.r < small && d.hot <= 0.05) continue;
      ry = d.r * (d.slide ? 1 + Math.min(0.35, d.v / (260 * S)) : 1);
      ctx.moveTo(d.x + d.r, d.y); ctx.ellipse(d.x, d.y, d.r, ry, 0, 0, 6.2832);
    }
    ctx.fill();
    /* 縁の細線 */
    ctx.strokeStyle = P.rgba(P.line, 0.42);
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    for (i = 0; i < drops.length; i++) {
      d = drops[i];
      if (d.r < small || d.hot > 0.05) continue;
      ry = d.r * (d.slide ? 1 + Math.min(0.35, d.v / (260 * S)) : 1);
      ctx.moveTo(d.x + d.r, d.y); ctx.ellipse(d.x, d.y, d.r, ry, 0, 0, 6.2832);
    }
    ctx.stroke();
    /* 下側の明るい三日月（向こうの光が粒で曲がって集まる） */
    ctx.strokeStyle = P.rgba(P.line, 0.7);
    ctx.beginPath();
    for (i = 0; i < drops.length; i++) {
      d = drops[i];
      if (d.r < small || d.hot > 0.05) continue;
      ry = d.r * (d.slide ? 1 + Math.min(0.35, d.v / (260 * S)) : 1);
      var cr = d.r * 0.78, cy = ry / d.r;
      ctx.moveTo(d.x + Math.cos(0.5) * cr, d.y + Math.sin(0.5) * cr * cy);
      ctx.ellipse(d.x, d.y, cr, cr * cy, 0, 0.5, 2.0);
    }
    ctx.lineWidth = 1.2 * S;
    ctx.stroke();
    /* 左上の縁に短い光 */
    ctx.strokeStyle = P.rgba(P.line, 0.8);
    ctx.beginPath();
    for (i = 0; i < drops.length; i++) {
      d = drops[i];
      if (d.r < small * 1.4) continue;
      var hr = d.r * 0.8;
      ctx.moveTo(d.x + Math.cos(3.75) * hr, d.y + Math.sin(3.75) * hr);
      ctx.arc(d.x, d.y, hr, 3.75, 4.45);
    }
    ctx.lineWidth = 1.1 * S;
    ctx.stroke();
    /* 触った粒・指の粒だけシアンの縁 */
    ctx.lineWidth = 1.2;
    for (i = 0; i < drops.length; i++) {
      d = drops[i];
      if (d.hot <= 0.05) continue;
      ry = d.r * (d.slide ? 1 + Math.min(0.35, d.v / (260 * S)) : 1);
      ctx.strokeStyle = P.rgba(P.accent, Math.min(0.9, d.hot));
      ctx.beginPath(); ctx.ellipse(d.x, d.y, Math.max(0.6, d.r), Math.max(0.6, ry), 0, 0, 6.2832); ctx.stroke();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e);
    if (held) { held.slide = true; held = null; }
    held = mkDrop(last.x, last.y, 8 * S);
    held.hot = 1;
    drops.push(held);
    rebin();
    /* 指のまわりの粒をまとめて指の下へ */
    sweep(held, 34 * S);
    held.r = Math.min(20 * S, held.r);
    wipe(last.x, last.y, 40 * S);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down || !held) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 2) return;
    rebin();
    var n = Math.ceil(dist / 5);
    for (var i = 1; i <= n; i++) {
      held.x = last.x + (p.x - last.x) * i / n;
      held.y = last.y + (p.y - last.y) * i / n;
      wipe(held.x, held.y, 22 * S);
      sweep(held, 12 * S);
    }
    held.r = Math.min(20 * S, held.r);
    held.trail += dist;
    if (held.trail > 16 * S) {
      held.trail = 0;
      var t = mkDrop(held.x - (p.x - last.x) * 0.6, held.y - (p.y - last.y) * 0.6, (0.8 + Math.random()) * S);
      t.hot = 0.7;
      drops.push(t);
    }
    held.hot = 1;
    last = p;
    SFX.play('move', 140);
  });
  function up() {
    if (!down) return;
    down = false;
    if (held) {
      /* 集めた水は流れ落ちる大きさにして放す */
      held.r = Math.max(held.r, RC * 1.25);
      held.rc = RC; held.slide = true; held.v = 30 * S;
      held = null;
      SFX.play('ripple');
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-rain').addEventListener('input', function () { rain = this.value / 100; });
  document.getElementById('k-fog').addEventListener('input', function () { fogK = this.value / 100; });
  document.getElementById('k-wipe').addEventListener('click', function () {
    /* 窓を拭く: 大きな粒を払い、曇りを取る。雨はまた降りつづける */
    for (var i = 0; i < drops.length; i++) if (drops[i] !== held && drops[i].r > 1.4 * S) drops[i].dead = true;
    fog.fill(0.05);
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  rain = document.getElementById('k-rain').value / 100;
  fogK = document.getElementById('k-fog').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    sim(dt, false);
    drawFog();
    drawDrops();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
