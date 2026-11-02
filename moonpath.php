<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('moonpath');

$pageName = 'moonpath';
$pageTitle = 'MOONPATH - PULSE';
$pageDesc = '夜の海に月の光が映り、水平線から手前まで、波のきらめきが縦の帯になって揺れている。月はゆっくり動き、光の帯もそれについて動く。海を押すとそこに風が当たってさざ波が立ち、帯の外でも水色の光の粒が散る。なぞると指の跡に沿ってさざ波が流れ、空を押すと月がそこへ移る。';
$pageCanonical = 'https://pulse.subara3.com/moonpath.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 視点は海面から高さ 1。画面の 1 行は水面の 1 本の距離 Z に対応する */
  var CS = 3;                      /* 海の格子 1 マスの画素数 */
  var CAM = 1, F = 1, Y0 = 0, CX = 0;
  var NX = 0, NY = 0;
  var rowZ, rowDx, rowBeta, rowVar, colTheta;
  var sL, sF, gL, gF, gV, gW;      /* 斜面（横・奥）と、風の当たった場所の上乗せ */
  var img, buf, bctx;
  var LUT = new Uint8Array(256 * 3), LUTA = new Uint8Array(256 * 3);
  var stars = [], clouds = [];
  var gusts = [];
  var wind = 0.5, drift = 0.4;
  var time = 0, simT = 0;
  var moon = { phi: 0, alpha: 0.2 }, user = null;

  /* 波: 風下（奥）へ向かう成分を中心に 14 本。短い波ほど速く、勾配はどれもほぼ同じ */
  var NW = 16, waves = [];
  (function () {
    var seed = 7;
    function rnd() { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }
    for (var w = 0; w < NW; w++) {
      var lam = 1.7 * Math.pow(0.8, w);
      var k = 2 * Math.PI / lam;
      var dir = (rnd() * 2 - 1) * 1.25;
      waves.push({ kx: k * Math.sin(dir), kz: k * Math.cos(dir), k: k, om: Math.sqrt(1.2 * k), p: rnd() * 6.283, s: 0.026 + rnd() * 0.012 });
    }
  })();

  for (var q = 0; q < 256; q++) {
    var c1 = P.ramp(q / 255), c2 = P.rampAccent(q / 255);
    LUT[q * 3] = c1[0]; LUT[q * 3 + 1] = c1[1]; LUT[q * 3 + 2] = c1[2];
    LUTA[q * 3] = c2[0]; LUTA[q * 3 + 1] = c2[1]; LUTA[q * 3 + 2] = c2[2];
  }

  function alloc() {
    var port = H > W;
    Y0 = Math.round(H * (port ? 0.4 : 0.36));
    F = Math.max(W * 0.9, H * 0.8);
    CX = W / 2;
    CS = W * H > 1.2e6 ? 4 : 3;
    NX = Math.ceil(W / CS);
    NY = Math.ceil((H - Y0) / CS);
    var n = NX * NY;
    sL = new Float32Array(NX); sF = new Float32Array(NX);
    gL = new Float32Array(n); gF = new Float32Array(n); gV = new Float32Array(n); gW = new Float32Array(n);
    rowZ = new Float32Array(NY); rowDx = new Float32Array(NY); rowBeta = new Float32Array(NY); rowVar = new Float32Array(NY);
    colTheta = new Float32Array(NX);
    for (var i = 0; i < NX; i++) colTheta[i] = Math.atan(((i + 0.5) * CS - CX) / F);
    for (var j = 0; j < NY; j++) {
      var dy = (j + 0.5) * CS;
      var b = Math.atan(dy / F);
      rowBeta[j] = b;
      rowZ[j] = CAM / Math.tan(b);
      rowDx[j] = rowZ[j] / F * CS;
    }
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');

    stars = [];
    var ns = Math.round(W * Y0 / 2600);
    for (var s = 0; s < ns; s++) stars.push({ x: Math.random() * W, y: Math.random() * (Y0 - 8), a: 0.15 + Math.random() * 0.5, p: Math.random() * 6.283, r: Math.random() < 0.12 ? 1.3 : 0.8 });
    clouds = [];
    for (var c = 0; c < 5; c++) clouds.push({ x: Math.random() * W, y: Y0 * (0.18 + Math.random() * 0.62), len: W * (0.16 + Math.random() * 0.22), n: 2 + (Math.random() * 3 | 0), v: 4 + Math.random() * 6 });
  }

  /* 波の解像できない細かさは、行ごとの「ぼけ」に回す。遠くほど帯がやわらかく光る */
  function rowAtten(j, w) {
    var wv = waves[w];
    var dz = rowZ[j] * rowZ[j] / (CAM * F) * CS;
    var qx = Math.abs(wv.kx * rowDx[j]), qz = Math.abs(wv.kz * dz);
    var qq = Math.sqrt(qx * qx + qz * qz) / Math.PI;
    var a = (0.7 - qq) / 0.4;
    return a < 0 ? 0 : a > 1 ? 1 : a;
  }

  function toWorld(x, y) {
    var dy = y - Y0;
    if (dy < 3) return null;
    var Z = CAM * F / dy;
    return { X: (x - CX) / F * Z, Z: Z };
  }

  function addGust(x, y, touched, str) {
    var p = toWorld(x, y);
    if (!p) return;
    var R = (touched ? 120 : 130) * p.Z / F;
    var ks = [];
    for (var m = 0; m < 4; m++) {
      var d = Math.random() * 6.283, k = 2 * Math.PI / (R * (0.16 + Math.random() * 0.1));
      ks.push({ kx: k * Math.cos(d), kz: k * Math.sin(d), om: 2.5 + Math.random() * 2, p: Math.random() * 6.283 });
    }
    gusts.push({ X: p.X, Z: p.Z, R: R, t: 0, life: touched ? 5 : 7, str: str, touched: touched, ks: ks });
    if (gusts.length > 40) gusts.shift();
  }

  /* 風の当たった場所: 細かいさざ波の斜面と、ばらつき（光の広がり）を足す */
  function stampGusts() {
    gL.fill(0); gF.fill(0); gV.fill(0); gW.fill(0);
    for (var n = 0; n < gusts.length; n++) {
      var g = gusts[n];
      var u = g.t / g.life;
      var env = Math.min(1, g.t / 0.18) * (1 - u) * (1 - u) * g.str;
      if (env <= 0.001) continue;
      var RZ = g.R * 2.4;
      var zNear = Math.max(0.05, g.Z - RZ), zFar = g.Z + RZ;
      var j0 = Math.max(0, Math.floor(CAM * F / zFar / CS)), j1 = Math.min(NY - 1, Math.ceil(CAM * F / zNear / CS));
      var sx = CX + g.X / g.Z * F, rx = g.R / g.Z * F * 1.15 + CS;
      var i0 = Math.max(0, Math.floor((sx - rx) / CS)), i1 = Math.min(NX - 1, Math.ceil((sx + rx) / CS));
      var R2 = g.R * g.R, RZ2 = RZ * RZ;
      for (var j = j0; j <= j1; j++) {
        var Z = rowZ[j], dz = Z - g.Z;
        if (dz * dz > RZ2) continue;
        var row = j * NX;
        for (var i = i0; i <= i1; i++) {
          var X = ((i + 0.5) * CS - CX) / F * Z, dx = X - g.X;
          var d2 = dx * dx / R2 + dz * dz / RZ2;
          if (d2 >= 1) continue;
          var e = (1 - d2); e = e * e * env;
          var al = 0, af = 0;
          for (var m = 0; m < 4; m++) {
            var kk = g.ks[m];
            var s = Math.sin(kk.kx * X + kk.kz * Z - kk.om * simT + kk.p);
            al += kk.kx * s; af += kk.kz * s;
          }
          var amp = 0.13 * e / (g.ks[0].kx * g.ks[0].kx + g.ks[0].kz * g.ks[0].kz + 1e-9) * Math.sqrt(g.ks[0].kx * g.ks[0].kx + g.ks[0].kz * g.ks[0].kz);
          var k = row + i;
          gL[k] += al * amp; gF[k] += af * amp;
          gV[k] += 0.02 * e * e;
          if (g.touched) gW[k] = Math.max(gW[k], e);
        }
      }
    }
  }

  /* 海を塗る。水面の小さな面が月を目へ向けて返す割合が、そのマスの明るさ */
  function shadeSea() {
    var d = img.data;
    var phi = moon.phi, alpha = moon.alpha;
    var amp = 0.45 + wind * 1.1;
    var s0 = 0.022 * 0.022;
    for (var j = 0; j < NY; j++) {
      var Z = rowZ[j], dxr = rowDx[j];
      sL.fill(0); sF.fill(0);
      var uv = 0;
      var X0 = (0.5 * CS - CX) / F * Z;
      for (var w = 0; w < NW; w++) {
        var wv = waves[w];
        var at = rowAtten(j, w);
        var a = wv.s * amp;
        uv += (1 - at * at) * a * a * 0.5;
        if (at <= 0) continue;
        var ph = wv.kx * X0 + wv.kz * Z - wv.om * simT + wv.p;
        var sn = Math.sin(ph), cs = Math.cos(ph);
        var dl = wv.kx * dxr, cd = Math.cos(dl), sd = Math.sin(dl);
        var al = -a * at * wv.kx / wv.k, af = -a * at * wv.kz / wv.k;
        for (var i = 0; i < NX; i++) {
          sL[i] += al * sn; sF[i] += af * sn;
          var ns = sn * cd + cs * sd; cs = cs * cd - sn * sd; sn = ns;
        }
      }
      rowVar[j] = uv;
      var beta = rowBeta[j];
      var reqF = (beta - alpha) * 0.5;
      var haze = Math.min(1, j / (NY * 0.12));
      var row = j * NX;
      for (var i2 = 0; i2 < NX; i2++) {
        var k = row + i2;
        var vl = sL[i2] + gL[k], vf = sF[i2] + gF[k];
        var reqL = (colTheta[i2] - phi) * 0.5;
        var el = vl - reqL, ef = vf - reqF;
        var d2 = el * el + ef * ef;
        var v2 = s0 + uv + gV[k];
        var I = Math.exp(-d2 / (2 * v2)) * Math.sqrt(s0 / v2);
        var halo = Math.exp(-d2 / (2 * (0.007 + v2))) * 0.09;
        var tex = vf * 2.2 + 0.5; tex = tex < 0 ? 0 : tex > 1 ? 1 : tex;
        var t = 0.02 + tex * 0.09 * haze + (1 - haze) * 0.03 + halo + I * 0.98;
        if (t > 1) t = 1;
        var ti = (t * 255) | 0, o = k * 4, gw = gW[k];
        if (gw > 0.01) {
          gw = Math.min(1, gw * 1.4);
          var ta = Math.min(255, ti + (gw * 40) | 0);
          d[o]     = LUT[ti * 3]     + (LUTA[ta * 3]     - LUT[ti * 3])     * gw;
          d[o + 1] = LUT[ti * 3 + 1] + (LUTA[ta * 3 + 1] - LUT[ti * 3 + 1]) * gw;
          d[o + 2] = LUT[ti * 3 + 2] + (LUTA[ta * 3 + 2] - LUT[ti * 3 + 2]) * gw;
        } else {
          d[o] = LUT[ti * 3]; d[o + 1] = LUT[ti * 3 + 1]; d[o + 2] = LUT[ti * 3 + 2];
        }
        d[o + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, Y0, NX * CS, NY * CS);
  }

  function moonXY() {
    return { x: CX + Math.tan(moon.phi) * F, y: Y0 - Math.tan(moon.alpha) * F };
  }

  function drawSky(dt) {
    var g = ctx.createLinearGradient(0, 0, 0, Y0);
    g.addColorStop(0, P.rgba(P.deep));
    g.addColorStop(1, P.rgba(P.bg));
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, Y0 + 1);
    P.grid(ctx, W, Y0, { alpha: 0.5 });

    for (var s = 0; s < stars.length; s++) {
      var st = stars[s];
      ctx.fillStyle = P.rgba(P.steel, st.a * (0.8 + 0.2 * Math.sin(time * 0.7 + st.p)));
      ctx.fillRect(st.x, st.y, st.r, st.r);
    }

    var m = moonXY();
    var glow = ctx.createRadialGradient(m.x, m.y, 0, m.x, m.y, 120);
    glow.addColorStop(0, P.rgba(P.line, 0.22));
    glow.addColorStop(1, P.rgba(P.line, 0));
    ctx.fillStyle = glow;
    ctx.fillRect(m.x - 120, m.y - 120, 240, 240);
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.lineWidth = 1;
    for (var r = 1; r <= 2; r++) { ctx.beginPath(); ctx.arc(m.x, m.y, 14 + r * 16, 0, 6.2832); ctx.stroke(); }
    ctx.fillStyle = P.rgba(P.line, 0.95);
    ctx.beginPath(); ctx.arc(m.x, m.y, 12, 0, 6.2832); ctx.fill();

    /* 薄い雲: 横に引いた細線の束がゆっくり流れる */
    ctx.lineWidth = 1;
    for (var c = 0; c < clouds.length; c++) {
      var cl = clouds[c];
      cl.x += cl.v * dt * (0.4 + wind);
      if (cl.x - cl.len > W) cl.x = -cl.len * 0.2;
      for (var l = 0; l < cl.n; l++) {
        var off = (l - cl.n / 2) * 5, ln = cl.len * (1 - Math.abs(l - cl.n / 2) * 0.22);
        var y = cl.y + off;
        var gr = ctx.createLinearGradient(cl.x - ln, 0, cl.x, 0);
        gr.addColorStop(0, P.rgba(P.steel, 0));
        gr.addColorStop(0.5, P.rgba(P.steel, 0.32));
        gr.addColorStop(1, P.rgba(P.steel, 0));
        ctx.strokeStyle = gr;
        ctx.beginPath(); ctx.moveTo(cl.x - ln, y + 0.5); ctx.lineTo(cl.x, y + 0.5); ctx.stroke();
      }
    }

    ctx.strokeStyle = P.rgba(P.steel, 0.6);
    ctx.beginPath(); ctx.moveTo(0, Y0 + 0.5); ctx.lineTo(W, Y0 + 0.5); ctx.stroke();
  }

  function updateMoon(dt) {
    var aMax = Math.atan(Math.max(30, Y0 - 46) / F), aMin = Math.atan(Math.min(60, Y0 * 0.4) / F);
    var pMax = Math.atan(W * 0.26 / F);
    var tp, ta;
    if (user) {
      tp = user.phi; ta = user.alpha;
      user.hold -= dt;
      if (user.hold <= 0 && !down) user = null;
    } else {
      tp = pMax * Math.sin(time * 0.09);
      ta = aMin + (aMax - aMin) * (0.5 + 0.5 * Math.sin(time * 0.067 + 1.3));
    }
    ta = Math.max(aMin, Math.min(aMax, ta));
    var k = Math.min(1, dt * (user ? 3 : 1.5));
    moon.phi += (tp - moon.phi) * k;
    moon.alpha += (ta - moon.alpha) * k;
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });
  moon.phi = 0; moon.alpha = Math.atan(Math.max(30, Y0 - 46) / F) * 0.6;
  for (var g0 = 0; g0 < 4; g0++) { addGust(Math.random() * W, Y0 + (H - Y0) * (0.2 + Math.random() * 0.7), false, 0.6); gusts[gusts.length - 1].t = 1 + Math.random() * 3; }

  /* ---------------------------------------------------------- 操作 */
  var down = false, mode = null, last = null;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  function setMoon(p) {
    user = { phi: Math.atan((p.x - CX) / F), alpha: Math.atan(Math.max(0, Y0 - p.y) / F), hold: 9 };
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e);
    if (last.y < Y0 + 4) { mode = 'sky'; setMoon(last); SFX.play('tick'); }
    else { mode = 'sea'; addGust(last.x, last.y, true, 1); SFX.play('ripple'); }
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e);
    if (mode === 'sky') { setMoon(p); SFX.play('move', 220); last = p; return; }
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 22) return;
    var n = Math.ceil(dist / 30);
    for (var i = 1; i <= n; i++) addGust(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, true, 0.8);
    last = p;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  document.getElementById('k-drift').addEventListener('input', function () { drift = this.value / 100; });
  wind = document.getElementById('k-wind').value / 100;
  drift = document.getElementById('k-drift').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), gustAcc = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    time += dt * (0.3 + drift * 1.8);
    simT += dt * 0.7;
    updateMoon(dt);

    gustAcc += dt * (0.15 + wind * 0.4);
    if (gustAcc > 1) {
      gustAcc -= 1;
      addGust(Math.random() * W, Y0 + (H - Y0) * (0.15 + Math.random() * 0.8), false, 0.15 + wind * 0.25);
    }
    for (var n = gusts.length - 1; n >= 0; n--) {
      var g = gusts[n];
      g.t += dt;
      if (g.t >= g.life) { gusts.splice(n, 1); continue; }
      var gr = 1 + dt * 0.12;          /* 風下（奥）へ流れていく */
      g.Z *= gr; g.R *= gr; g.X *= gr;
    }

    drawSky(dt);
    stampGusts();
    shadeSea();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
