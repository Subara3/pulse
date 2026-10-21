<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('shore');

$pageName = 'shore';
$pageTitle = 'SHORE - PULSE';
$pageDesc = '沖から来た波が砕けて砂浜を駆け上がり、引いていく。引いた跡には泡が細い線になって残り、濡れた砂は少しずつ乾く。砂の上を押すとそこに丸い溝が彫られ、なぞると指の跡に溝が引かれて、次の波がそれを少しずつ消していく。海を押すと、そこから波が浜へ押し寄せる。';
$pageCanonical = 'https://pulse.subara3.com/shore.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-swell">SWELL</label><input type="range" id="k-swell" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-smooth">SMOOTH</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 上が海、下が砂浜。波打ち際は列ごとの「水の先端の位置と速さ」だけで解く。
     砕けた波は先端を押し上げ、斜面がそれを引き戻す。泡は水に乗って運ばれ、
     引き波に置いていかれたものが砂の上に線になって残る。砂の濡れと溝は粗い格子。 */
  var NX = 0, NY = 0, CW = 1, CH = 1;
  var edge, vel, base, wet, groove, hot, grain, img, buf, bctx;
  var waves = [];
  var FMAX = 5000, fx = new Float32Array(FMAX), fy = new Float32Array(FMAX), fl = new Float32Array(FMAX);
  var fw = new Float32Array(FMAX), fa = new Float32Array(FMAX), fs = new Uint8Array(FMAX), fHead = 0;
  var G = 1, V0 = 1, CS = 1, BRK = 1;
  var swell = 0.55, pace = 0.45, time = 0, nextWave = 0, quiet = false;

  function alloc() {
    NX = Math.max(140, Math.min(320, Math.round(W / 4)));
    CW = W / NX;
    NY = Math.max(60, Math.round(H / CW));
    CH = H / NY;
    edge = new Float32Array(NX); vel = new Float32Array(NX); base = new Float32Array(NX);
    wet = new Float32Array(NX * NY); groove = new Float32Array(NX * NY); hot = new Float32Array(NX * NY);
    grain = new Float32Array(NX * NY);
    /* 砂の地: 風が残した細かい波紋と、粒のざらつき */
    var rp = Math.random() * 6.283;
    for (var k = 0; k < grain.length; k++) {
      var gxp = (k % NX) * CW, gyp = Math.floor(k / NX) * CH;
      var rip = Math.sin(gyp * 0.32 + 2.4 * Math.sin(gxp * 0.011 + rp) + 1.2 * Math.sin(gxp * 0.027 - gyp * 0.01));
      grain[k] = 0.5 + 0.38 * rip * rip * rip + (Math.random() - 0.5) * 0.35;
    }
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    G = H * 0.9; V0 = H * 0.84; CS = H * 0.13; BRK = H * 0.06;
    waves.length = 0; fl.fill(0); fHead = 0;
    updateBase();
    for (var i = 0; i < NX; i++) { edge[i] = base[i]; vel[i] = 0; }
    /* 開いた瞬間から泡の跡と濡れた砂があるように、10 秒ぶん先に回しておく */
    quiet = true; nextWave = 0;
    for (var s = 0; s < 300; s++) sim(1 / 30);
    quiet = false;
    nextWave = Math.min(nextWave, 0.6);
  }

  function updateBase() {
    var tide = 0.42 + 0.025 * Math.sin(time * 0.12);
    for (var i = 0; i < NX; i++) {
      var x = (i + 0.5) / NX;
      base[i] = H * (tide + 0.018 * Math.sin(x * 8.2 + time * 0.05) + 0.01 * Math.sin(x * 19 - time * 0.03));
    }
  }

  function colOf(x) { return Math.max(0, Math.min(NX - 1, Math.floor(x / CW))); }

  function addFoam(x, y, w, acc) {
    var n = fHead; fHead = (fHead + 1) % FMAX;
    fx[n] = x; fy[n] = y; fl[n] = 0.7 + Math.random() * 0.3; fw[n] = w; fa[n] = acc || 0; fs[n] = 0;
  }

  /* 沖の波: 曲がった波頭が浜へ進み、砕けたら列ごとに先端（ボア）になって駆け上がる */
  function addWave() {
    var a = (0.55 + swell * 0.75) * (0.8 + Math.random() * 0.4);
    var k1 = 6.283 / (W * (0.5 + Math.random() * 0.5)), k2 = 6.283 / (W * (0.18 + Math.random() * 0.15));
    var p1 = Math.random() * 6.283, p2 = Math.random() * 6.283;
    var kb = 6.283 / (W * (0.7 + Math.random() * 0.8)), pb = Math.random() * 6.283, bA = H * (0.02 + Math.random() * 0.035);
    var w = { Y: -H * 0.1, bend: new Float32Array(NX), amp: new Float32Array(NX), yb: new Float32Array(NX),
              vb: new Float32Array(NX), st: new Uint8Array(NX), left: NX };
    for (var i = 0; i < NX; i++) {
      var x = (i + 0.5) * CW;
      w.bend[i] = bA * Math.sin(x * kb + pb);
      w.amp[i] = a * (1 + 0.12 * Math.sin(x * k1 + p1) + 0.06 * Math.sin(x * k2 + p2));
    }
    waves.push(w);
  }

  /* 指で起こす波: 押したところから、その幅だけ浜へ押し寄せる */
  function localWave(x, y) {
    var R = Math.max(70, W * 0.12);
    var w = { Y: null, bend: null, amp: null, yb: new Float32Array(NX), vb: new Float32Array(NX),
              st: new Uint8Array(NX), left: 0 };
    w.st.fill(2);
    for (var i = 0; i < NX; i++) {
      var d = Math.abs((i + 0.5) * CW - x) / R;
      if (d >= 1) continue;
      var p = Math.cos(d * Math.PI * 0.5);
      w.st[i] = 1; w.yb[i] = y - (1 - p * p) * 6; w.vb[i] = V0 * (0.62 + 0.42 * p); w.left++;
    }
    waves.push(w);
  }

  function sim(dt) {
    time += dt;
    updateBase();
    nextWave -= dt;
    if (nextWave <= 0) { addWave(); nextWave = (7.5 - pace * 4.5) * (0.8 + Math.random() * 0.4); }

    for (var n = waves.length - 1; n >= 0; n--) {
      var w = waves[n];
      if (w.Y !== null) w.Y += CS * dt;
      for (var i = 0; i < NX; i++) {
        var s = w.st[i];
        if (s === 2) continue;
        var x = (i + 0.5) * CW;
        if (s === 0) {
          var cy = w.Y + w.bend[i];
          if (cy >= base[i] - BRK) {
            w.st[i] = 1; w.yb[i] = cy; w.vb[i] = w.amp[i] * V0;
            if (Math.random() < 0.5) addFoam(x + (Math.random() - 0.5) * CW, cy, 0.3 + Math.random() * 0.3);
            if (!quiet && i === (NX >> 1)) SFX.play('ripple', 2500);
          }
          continue;
        }
        if (w.yb[i] > base[i]) w.vb[i] -= G * 0.55 * dt;
        w.yb[i] += w.vb[i] * dt;
        if (Math.random() < 0.09) addFoam(x + (Math.random() - 0.5) * CW, w.yb[i] - Math.random() * 4, 0.5 + Math.random() * 0.45);
        if (w.yb[i] >= edge[i]) {
          if (w.vb[i] > vel[i]) { vel[i] = w.vb[i]; edge[i] = w.yb[i]; }
          w.st[i] = 2; w.left--;
        } else if (w.vb[i] <= 0) { w.st[i] = 2; w.left--; }
      }
      /* left はまだ先端が残っている列の数。沖の波はうねりの線が浜を越えるまで残す */
      if (w.left <= 0 && (w.Y === null || w.Y > H)) waves.splice(n, 1);
    }

    /* 水の先端: 斜面が引き戻し、底がゆっくり止める。隣の列とは少しだけならす */
    var fr = Math.exp(-0.55 * dt);
    for (var i2 = 0; i2 < NX; i2++) {
      var v = (vel[i2] - G * dt) * fr;
      var e = edge[i2] + v * dt;
      if (e <= base[i2]) { e = base[i2]; if (v < 0) v = 0; }
      if (e > H + 20) { e = H + 20; v = Math.min(v, 0); }
      edge[i2] = e; vel[i2] = v;
    }
    for (var pass = 0; pass < 2; pass++) {
      var pe = edge[0], pv = vel[0];
      for (var i3 = 1; i3 < NX - 1; i3++) {
        var ce = edge[i3], cv = vel[i3];
        edge[i3] = ce + 0.12 * (pe + edge[i3 + 1] - 2 * ce);
        vel[i3] = cv + 0.16 * (pv + vel[i3 + 1] - 2 * cv);
        pe = ce; pv = cv;
      }
      edge[0] = edge[1]; vel[0] = vel[1]; edge[NX - 1] = edge[NX - 2]; vel[NX - 1] = vel[NX - 2];
    }
    for (var i4 = 0; i4 < NX; i4++) if (vel[i4] > 0 && Math.random() < 0.14) addFoam((i4 + Math.random()) * CW, edge[i4] - 2, 0.75 + Math.random() * 0.25);

    /* 砂: 水をかぶったら濡れて、溝は流れの強さに応じて埋まる。乾くのはゆっくり */
    for (var c = 0; c < NX; c++) {
      var ev = edge[c], spd = Math.min(1, Math.abs(vel[c]) / V0), bs = base[c];
      var fill = 1 - dt * (0.12 + 1.1 * spd);
      for (var j = 0; j < NY; j++) {
        var k = j * NX + c, y = (j + 0.5) * CH;
        if (y < ev) {
          wet[k] = 1;
          if (y > bs - BRK) groove[k] *= fill;
          else groove[k] *= 1 - dt * 0.3;
        } else {
          wet[k] = Math.max(0, wet[k] - dt * 0.07);
          groove[k] = Math.max(0, groove[k] - dt * 0.006);
        }
        if (hot[k] > 0) hot[k] = Math.max(0, hot[k] - dt * 0.45);
      }
    }

    /* 泡: 水の中では先端に少し遅れてついていき、引き波に置いていかれると砂に残る */
    for (var f = 0; f < FMAX; f++) {
      if (fl[f] <= 0) continue;
      var fc = colOf(fx[f]);
      if (fa[f] > 0) fa[f] = Math.max(0, fa[f] - dt * 0.6);
      if (fs[f] === 0) {
        if (fy[f] < edge[fc] - 0.5) {
          if (fy[f] > base[fc] - BRK * 1.5) {
            var vv = vel[fc] * (vel[fc] > 0 ? fw[f] : fw[f] * 0.55);
            fy[f] = Math.min(edge[fc] - 0.5, fy[f] + vv * dt);
            fl[f] -= dt * 0.1;
          } else {
            fy[f] -= dt * 3;
            fl[f] -= dt * 0.22;
          }
          fx[f] += (Math.random() - 0.5) * dt * 14;
        } else { fs[f] = 1; }
      } else {
        if (fy[f] < edge[fc] - 1.5) fs[f] = 0;
        fl[f] -= dt * 0.11;
      }
    }
  }

  /* 溝を彫る。prof(d) は中心からの距離（px）で深さを返す */
  function carve(x, y, r, prof) {
    var i0 = Math.max(0, Math.floor((x - r) / CW)), i1 = Math.min(NX - 1, Math.ceil((x + r) / CW));
    var j0 = Math.max(0, Math.floor((y - r) / CH)), j1 = Math.min(NY - 1, Math.ceil((y + r) / CH));
    for (var j = j0; j <= j1; j++) {
      for (var i = i0; i <= i1; i++) {
        var d = Math.hypot((i + 0.5) * CW - x, (j + 0.5) * CH - y);
        var val = prof(d);
        if (val <= 0.02) continue;
        var k = j * NX + i;
        if (val > groove[k]) groove[k] = val;
        if (val > hot[k]) hot[k] = val;
      }
    }
  }
  function ringProf(d) { var a = (d - 18) / 4.5; return Math.max(Math.exp(-a * a), d < 5 ? 0.8 * (1 - d / 5) : 0); }
  function lineProf(d) { var a = d / 5.5; return a < 1 ? 1 - a * a : 0; }

  /* ---------------------------------------------------------- 描く */
  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel, AC = P.accent;
  function shade() {
    var d = img.data;
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var k = j * NX + i, o = k * 4;
        var gr = groove[k];
        var gx = (i > 0 && i < NX - 1) ? groove[k - 1] - groove[k + 1] : 0;
        var gy = (j > 0 && j < NY - 1) ? groove[k - NX] - groove[k + NX] : 0;
        var s = (gx * 0.8 + gy * 0.6) * 1.6;
        var t = 0.32 * wet[k] + gr * 0.25;
        var r = BG[0] + (DEEP[0] - BG[0]) * t, g = BG[1] + (DEEP[1] - BG[1]) * t, b = BG[2] + (DEEP[2] - BG[2]) * t;
        var sd = (grain[k] - 0.5) * 0.16 * (1 - wet[k] * 0.6);
        r += (ST[0] - BG[0]) * sd; g += (ST[1] - BG[1]) * sd; b += (ST[2] - BG[2]) * sd;
        if (s > 0) {
          var hi = Math.min(1, s), c = hot[k] * 0.9, cr = ST[0] + (AC[0] - ST[0]) * c, cg = ST[1] + (AC[1] - ST[1]) * c, cb = ST[2] + (AC[2] - ST[2]) * c;
          r += (cr - r) * hi * 0.85; g += (cg - g) * hi * 0.85; b += (cb - b) * hi * 0.85;
        } else if (s < 0) {
          var lo = Math.min(1, -s) * 0.7;
          r += (DEEP[0] - r) * lo; g += (DEEP[1] - g) * lo; b += (DEEP[2] - b) * lo;
        }
        d[o] = r; d[o + 1] = g; d[o + 2] = b; d[o + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  var WATER = P.rgba(P.deep, 0.3);
  var FOAM = [P.rgba(P.line, 0.18), P.rgba(P.line, 0.38), P.rgba(P.line, 0.62), P.rgba(P.line, 0.85)];
  function render() {
    shade();
    P.grid(ctx, W, H, { alpha: 0.6 });

    /* 水 */
    ctx.fillStyle = WATER;
    ctx.beginPath();
    ctx.moveTo(0, 0); ctx.lineTo(W, 0); ctx.lineTo(W, edge[NX - 1]);
    for (var i = NX - 1; i >= 0; i--) ctx.lineTo((i + 0.5) * CW, edge[i]);
    ctx.lineTo(0, edge[0]);
    ctx.closePath();
    ctx.fill();

    /* 沖のうねり: 細い横線が浜へ寄ってきて、砕ける手前で薄れる */
    var gap = Math.max(14, H * 0.03), sh = (time * CS * 0.55) % gap;
    ctx.lineWidth = 1;
    for (var yy = -gap + sh; yy < H * 0.5; yy += gap) {
      ctx.strokeStyle = P.rgba(ST, 0.1 + 0.18 * Math.max(0, Math.min(1, yy / (H * 0.4))));
      ctx.beginPath();
      var pw = false;
      for (var ix = 0; ix <= NX; ix += 2) {
        var cx = Math.min(NX - 1, ix), wy = yy + H * 0.012 * Math.sin(ix * CW * 0.006 + yy * 0.02 + time * 0.2);
        if (wy > base[cx] - BRK * 1.2) { pw = false; continue; }
        if (!pw) { ctx.moveTo(ix * CW, wy); pw = true; } else ctx.lineTo(ix * CW, wy);
      }
      ctx.stroke();
    }

    /* 沖の波頭と、その後ろのうねり */
    ctx.lineWidth = 1.2;
    for (var n = 0; n < waves.length; n++) {
      var w = waves[n];
      if (w.Y === null) continue;
      var prog = Math.max(0, Math.min(1, (w.Y + H * 0.1) / (H * 0.5)));
      for (var L = 0; L < 4; L++) {
        var off = L * L * 6 + L * 10, al = (0.3 + 0.6 * prog) / (1 + L * 1.6);
        ctx.lineWidth = L === 0 ? 1.8 : 1;
        ctx.strokeStyle = P.rgba(LN, al);
        ctx.beginPath();
        var pen = false;
        for (var i1 = 0; i1 < NX; i1++) {
          var y = w.Y + w.bend[i1] - off;
          if (w.st[i1] !== 0 && L === 0) { pen = false; continue; }
          if (y > base[i1] - BRK) { pen = false; continue; }
          if (!pen) { ctx.moveTo(i1 * CW, y); pen = true; } else ctx.lineTo((i1 + 0.5) * CW, y);
        }
        ctx.stroke();
      }
    }

    /* 砕けて駆け上がる先端 */
    ctx.lineWidth = 1.6;
    ctx.strokeStyle = P.rgba(LN, 0.8);
    ctx.beginPath();
    for (var m = 0; m < waves.length; m++) {
      var wb = waves[m], pn = false;
      for (var i2 = 0; i2 < NX; i2++) {
        if (wb.st[i2] !== 1) { pn = false; continue; }
        if (!pn) { ctx.moveTo(i2 * CW, wb.yb[i2]); pn = true; } else ctx.lineTo((i2 + 0.5) * CW, wb.yb[i2]);
      }
    }
    ctx.stroke();

    /* 波打ち際の線: 駆け上がっているところほど明るい */
    ctx.lineWidth = 1;
    for (var band = 0; band < 3; band++) {
      ctx.strokeStyle = P.rgba(LN, 0.2 + band * 0.25);
      ctx.beginPath();
      var pd = false;
      for (var i3 = 0; i3 < NX; i3++) {
        var sp = Math.min(2, Math.max(0, vel[i3] / V0 * 3 + (edge[i3] - base[i3] > 2 ? 1 : 0)));
        if (Math.round(sp) !== band) { pd = false; continue; }
        var xx = (i3 + 0.5) * CW;
        if (!pd) { ctx.moveTo(xx - CW * 0.5, edge[i3]); pd = true; }
        ctx.lineTo(xx + CW * 0.5, edge[i3]);
      }
      ctx.stroke();
    }

    /* 泡 */
    for (var q = 0; q < 4; q++) {
      ctx.fillStyle = FOAM[q];
      ctx.beginPath();
      for (var f = 0; f < FMAX; f++) {
        if (fl[f] <= 0 || fa[f] > 0.05) continue;
        var lv = fl[f] * (fs[f] ? 1 : 0.75);
        if (Math.min(3, Math.floor(lv * 4)) !== q) continue;
        ctx.rect(fx[f] - 0.8, fy[f] - 0.8, 1.7, 1.7);
      }
      ctx.fill();
    }
    for (var f2 = 0; f2 < FMAX; f2++) {
      if (fl[f2] <= 0 || fa[f2] <= 0.05) continue;
      ctx.fillStyle = P.rgba(P.mix(LN, AC, Math.min(1, fa[f2])), Math.min(1, fl[f2] + 0.2));
      ctx.fillRect(fx[f2] - 1, fy[f2] - 1, 2.2, 2.2);
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastT0 = 0;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  function inWater(p) { return p.y < edge[colOf(p.x)] - 2; }
  function burst(x, y) {
    for (var i = 0; i < 40; i++) {
      var a = Math.random() * 6.283, r = 4 + Math.random() * 26;
      addFoam(x + Math.cos(a) * r, y + Math.sin(a) * r * 0.6, 0.6 + Math.random() * 0.4, 1);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e); lastT0 = performance.now();
    if (inWater(last)) {
      localWave(last.x, last.y);
      burst(last.x, last.y);
      SFX.play('ripple');
    } else {
      carve(last.x, last.y, 24, ringProf);
      SFX.play('spawn');
    }
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 3) return;
    var now = performance.now(), dts = Math.max(0.008, (now - lastT0) / 1000);
    var n = Math.ceil(dist / 3);
    for (var i = 1; i <= n; i++) {
      var q = { x: last.x + (p.x - last.x) * i / n, y: last.y + (p.y - last.y) * i / n };
      if (inWater(q)) {
        if (i % 3 === 0) addFoam(q.x + (Math.random() - 0.5) * 6, q.y + (Math.random() - 0.5) * 6, 0.8, 1);
        var c = colOf(q.x), push = Math.max(-V0 * 0.4, Math.min(V0 * 0.6, (p.y - last.y) / dts * 0.6));
        for (var dc = -2; dc <= 2; dc++) {
          var cc = c + dc;
          if (cc >= 0 && cc < NX && q.y > base[cc] - BRK) vel[cc] += (push - vel[cc]) * 0.15;
        }
      } else {
        carve(q.x, q.y, 7, lineProf);
      }
    }
    last = p; lastT0 = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-swell').addEventListener('input', function () { swell = this.value / 100; });
  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  document.getElementById('k-smooth').addEventListener('click', function () {
    groove.fill(0); hot.fill(0);
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  swell = document.getElementById('k-swell').value / 100;
  pace = document.getElementById('k-pace').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    sim(dt);
    render();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
