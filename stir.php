<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('stir');

$pageName = 'stir';
$pageTitle = 'STIR - PULSE';
$pageDesc = '何本もの縞が並んだ水を、円を描いて回る棒がかき混ぜる。縞は棒に引かれて細く伸び、折り返されて、何重にも重なった層になる。ときどき櫛が端から端へ渡り、混ざりきったところに新しい縞を引き直す。押すとそこに水色のしずくが落ちて渦が巻き、しずくはすぐに細い筋に引き伸ばされる。なぞると水が指についてきて、縞が指の跡に沿って引きずられる。';
$pageCanonical = 'https://pulse.subara3.com/stir.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-swirl">SWIRL</label><input type="range" id="k-swirl" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-pat="bands">BANDS</button><button type="button" data-pat="rings">RINGS</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 水は粗い格子で解いて拡大する。A = 白い縞の濃さ、B = 触ったしずく（水色）、REF = 戻っていく元の縞 */
  var NX = 0, NY = 0, CS = 4, A, A2, B, B2, REF, img, buf, bctx;
  var rods = [], finger = null;
  var pat = 'bands', speed = 1, swirl = 1;
  var clock = 0, nextPlan = 22, planNo = 0;
  /* 櫛: ときどき端から端へ渡って、混ざりきった水に新しい縞を引き直す */
  var comb = -1, nextComb = 24;
  var S = 1, L = 1, horiz = true;

  function buildRef() {
    var lam = S / 7.5, cx = NX / 2, cy = NY / 2;
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var u;
        if (pat === 'rings') u = Math.hypot(i - cx, j - cy) * CS / lam;
        else u = (horiz ? j : i) * CS / lam + 0.25;
        var f = u - Math.floor(u);
        /* やわらかい角の矩形波: 半分が縞、半分が地 */
        var s = Math.min(f, 1 - f) * 2;           /* 0..1 */
        var v = (s - 0.5) * 6 + 0.5;
        REF[j * NX + i] = v < 0 ? 0 : v > 1 ? 1 : v;
      }
    }
  }

  function alloc() {
    S = Math.min(W, H); L = Math.max(W, H); horiz = W >= H;
    CS = Math.max(3, Math.sqrt(W * H / 105000));
    NX = Math.ceil(W / CS); NY = Math.ceil(H / CS);
    var n = NX * NY;
    A = new Float32Array(n); A2 = new Float32Array(n);
    B = new Float32Array(n); B2 = new Float32Array(n);
    REF = new Float32Array(n);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas'); buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    buildRef();
    A.set(REF);
    /* 棒は長い辺に沿って並ぶ。隣どうしの円が少し重なるので、縞が棒から棒へ受け渡されて折れる */
    var count = Math.max(3, Math.min(6, Math.round(L / S * 1.7)));
    var span = L * 0.82, gap = span / count;
    var ro = Math.min(gap * 0.56, S * 0.33);
    rods = [];
    for (var k = 0; k < count; k++) {
      var along = (L - span) / 2 + gap * (k + 0.5);
      rods.push({
        cx: horiz ? along : W / 2, cy: horiz ? H / 2 : along,
        ro: ro, th: k * Math.PI / 2, om: 0.42, omT: 0.42,
        x: 0, y: 0, vx: 0, vy: 0, kap: 0
      });
    }
    place(0);
    /* 開いた瞬間から折れた層が見えるように、少し先まで回しておく */
    for (var s = 0; s < 36; s++) { place(0.05); advect(0.05); }
  }

  /* 回し方をときどき替える: 全部同じ向き / 1 本おきに逆 / 速さ違い */
  function newPlan() {
    planNo++;
    var kind = planNo % 3;
    for (var k = 0; k < rods.length; k++) {
      var base = 0.36 + Math.random() * 0.14;
      if (kind === 0) rods[k].omT = base;
      else if (kind === 1) rods[k].omT = (k % 2 ? -1 : 1) * base;
      else rods[k].omT = base * (k % 2 ? 1.6 : 0.8) * (Math.random() < 0.5 ? -1 : 1);
    }
    SFX.play('tick');
  }

  function place(dt) {
    for (var k = 0; k < rods.length; k++) {
      var r = rods[k];
      r.om += (r.omT - r.om) * Math.min(1, dt * 0.35);
      r.th += r.om * dt;
      var nx = r.cx + Math.cos(r.th) * r.ro, ny = r.cy + Math.sin(r.th) * r.ro;
      r.vx = -Math.sin(r.th) * r.ro * r.om; r.vy = Math.cos(r.th) * r.ro * r.om;
      r.x = nx; r.y = ny;
      /* 棒そのものも回っていて、まわりの水をひねる */
      r.kap = (r.om > 0 ? 1 : -1) * swirl * 0.35 * r.ro * r.ro * 0.45;
      r.R = r.ro * 0.62;
    }
  }

  /* 流れ関数から作るので水は縮まない: ψ = (V×d + κ)·exp(-d²/R²) */
  var SRC = [];
  function sources() {
    SRC.length = 0;
    for (var k = 0; k < rods.length; k++) SRC.push(rods[k]);
    if (finger && finger.w > 0.01) SRC.push(finger);
  }

  function advect(dt) {
    sources();
    var m = SRC.length, sx = new Float32Array(m), sy = new Float32Array(m), svx = new Float32Array(m),
        svy = new Float32Array(m), sk = new Float32Array(m), sr = new Float32Array(m), w = new Float32Array(m);
    for (var q = 0; q < m; q++) {
      var o = SRC[q], ww = o.w == null ? 1 : o.w;
      sx[q] = o.x; sy[q] = o.y; svx[q] = o.vx * ww; svy[q] = o.vy * ww; sk[q] = o.kap * ww;
      sr[q] = 1 / (o.R * o.R);
    }
    var relax = Math.min(1, dt / 40), sharp = dt * 0.25, fadeB = Math.exp(-dt / 7), k = dt / CS;
    var xmax = NX - 1.001, ymax = NY - 1.001;
    var band = L * 0.1, cpos = comb, crate = Math.min(1, dt * 2.5);
    var edge = Math.max(2, Math.min(NX, NY) * 0.05);
    for (var j = 0; j < NY; j++) {
      var py = (j + 0.5) * CS, ey = Math.min(1, j / edge, (NY - 1 - j) / edge);
      for (var i = 0; i < NX; i++) {
        var px = (i + 0.5) * CS, u = 0, v = 0;
        for (q = 0; q < m; q++) {
          var dx = px - sx[q], dy = py - sy[q], d2 = (dx * dx + dy * dy) * sr[q];
          if (d2 > 9) continue;
          var e = Math.exp(-d2), c = (svx[q] * dy - svy[q] * dx + sk[q]) * e * 2 * sr[q];
          u += svx[q] * e - c * dy;
          v += svy[q] * e + c * dx;
        }
        /* 画面の縁では水を止める */
        var ee = Math.min(ey, i / edge, (NX - 1 - i) / edge);
        if (ee < 1) { u *= ee; v *= ee; }
        /* さかのぼって、そこにあった水の色を拾う */
        var bx = i - u * k, by = j - v * k;
        if (bx < 0) bx = 0; else if (bx > xmax) bx = xmax;
        if (by < 0) by = 0; else if (by > ymax) by = ymax;
        var x0 = bx | 0, y0 = by | 0, fx = bx - x0, fy = by - y0, o0 = y0 * NX + x0, o1 = o0 + NX;
        var a = (A[o0] * (1 - fx) + A[o0 + 1] * fx) * (1 - fy) + (A[o1] * (1 - fx) + A[o1 + 1] * fx) * fy;
        var b = (B[o0] * (1 - fx) + B[o0 + 1] * fx) * (1 - fy) + (B[o1] * (1 - fx) + B[o1 + 1] * fx) * fy;
        var idx = j * NX + i;
        var rl = relax;
        if (cpos >= 0) {
          /* 櫛の通ったすぐ後ろだけ、元の縞へ速く戻す */
          var behind = cpos - (horiz ? px : py);
          if (behind > 0 && behind < band) rl = Math.max(rl, crate * Math.sin(behind / band * Math.PI));
        }
        a += (REF[idx] - a) * rl;
        /* 境目がにじみきらないよう、濃い/薄いへ少しずつ寄せる */
        a += sharp * (a - 0.5) * a * (1 - a) * 4;
        A2[idx] = a < 0 ? 0 : a > 1 ? 1 : a;
        B2[idx] = b * fadeB;
      }
    }
    var t = A; A = A2; A2 = t; t = B; B = B2; B2 = t;
  }

  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel, AC = P.accent;
  function shade() {
    var d = img.data;
    for (var n = 0, o = 0; n < A.length; n++, o += 4) {
      var a = A[n], b = B[n], ix = n % NX;
      /* 縞の中は鋼色で薄く、境目だけを白い線にする。混ざってぼやけたところは線にしない */
      var gx = (ix > 0 && ix < NX - 1) ? A[n + 1] - A[n - 1] : 0;
      var gy = (n >= NX && n < A.length - NX) ? A[n + NX] - A[n - NX] : 0;
      var ln = Math.min(1, Math.sqrt(gx * gx + gy * gy) * 1.9); ln = ln * Math.sqrt(ln);
      var f = a * 0.22;
      var r = BG[0] + (ST[0] - BG[0]) * f, g = BG[1] + (ST[1] - BG[1]) * f, bl = BG[2] + (ST[2] - BG[2]) * f;
      if (a < 0.08) { var dd = (0.08 - a) * 4; r += (DEEP[0] - r) * dd; g += (DEEP[1] - g) * dd; bl += (DEEP[2] - bl) * dd; }
      r += (LN[0] - r) * ln * 0.9; g += (LN[1] - g) * ln * 0.9; bl += (LN[2] - bl) * ln * 0.9;
      if (b > 0.004) {
        var c = b > 1 ? 1 : b; c = c * 0.85;
        r += (AC[0] - r) * c; g += (AC[1] - g) * c; bl += (AC[2] - bl) * c;
      }
      d[o] = r; d[o + 1] = g; d[o + 2] = bl; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  function drawRods() {
    ctx.save();
    ctx.lineWidth = 1;
    ctx.setLineDash([3, 6]);
    ctx.strokeStyle = P.rgba(ST, 0.35);
    for (var k = 0; k < rods.length; k++) {
      ctx.beginPath(); ctx.arc(rods[k].cx, rods[k].cy, rods[k].ro, 0, Math.PI * 2); ctx.stroke();
    }
    ctx.setLineDash([]);
    var rr = Math.max(5, S * 0.014);
    for (k = 0; k < rods.length; k++) {
      var r = rods[k];
      ctx.fillStyle = P.rgba(DEEP, 0.85);
      ctx.strokeStyle = P.rgba(LN, 0.9);
      ctx.lineWidth = 1.4;
      ctx.beginPath(); ctx.arc(r.x, r.y, rr, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      /* 棒の自転が見える小さな刻み */
      var sp = clock * r.om * 2.2;
      ctx.beginPath();
      ctx.moveTo(r.x + Math.cos(sp) * rr * 0.55, r.y + Math.sin(sp) * rr * 0.55);
      ctx.lineTo(r.x - Math.cos(sp) * rr * 0.55, r.y - Math.sin(sp) * rr * 0.55);
      ctx.stroke();
    }
    ctx.restore();
  }

  function drawComb() {
    if (comb < 0) return;
    var a = Math.min(1, comb / (L * 0.08), (L * 1.1 - comb) / (L * 0.08));
    var step = S / 30, len = S / 60;
    ctx.save();
    ctx.strokeStyle = P.rgba(ST, 0.55 * a);
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    if (horiz) { ctx.moveTo(comb, 0); ctx.lineTo(comb, H); for (var y = step / 2; y < H; y += step) { ctx.moveTo(comb, y); ctx.lineTo(comb - len, y); } }
    else { ctx.moveTo(0, comb); ctx.lineTo(W, comb); for (var x = step / 2; x < W; x += step) { ctx.moveTo(x, comb); ctx.lineTo(x, comb - len); } }
    ctx.stroke();
    ctx.restore();
  }

  /* しずく: 水色の濃さを円く置く */
  function dropInk(x, y, rad, amt) {
    var cx = x / CS, cy = y / CS, r = rad / CS;
    for (var j = Math.max(0, Math.floor(cy - r)); j < Math.min(NY, Math.ceil(cy + r)); j++) {
      for (var i = Math.max(0, Math.floor(cx - r)); i < Math.min(NX, Math.ceil(cx + r)); i++) {
        var dd = Math.hypot(i - cx, j - cy) / r;
        if (dd < 1) { var idx = j * NX + i; B[idx] = Math.min(1.4, B[idx] + amt * (1 - dd * dd)); }
      }
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; finger = null; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastMoveT = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    last = { x: e.clientX, y: e.clientY };
    lastMoveT = performance.now();
    var R = S * 0.13;
    /* 押したところで渦が巻き、そばの縞を巻き込む */
    finger = { x: last.x, y: last.y, vx: 0, vy: 0, kap: R * R * 2.4, R: R, w: 1 };
    dropInk(last.x, last.y, S * 0.055, 1.3);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down || !finger) return;
    var now = performance.now(), dtm = Math.max(0.008, (now - lastMoveT) / 1000);
    var p = { x: e.clientX, y: e.clientY };
    var vx = (p.x - last.x) / dtm, vy = (p.y - last.y) / dtm, cap = S * 2.2, sp = Math.hypot(vx, vy);
    if (sp > cap) { vx *= cap / sp; vy *= cap / sp; }
    finger.vx += (vx - finger.vx) * 0.5; finger.vy += (vy - finger.vy) * 0.5;
    finger.x = p.x; finger.y = p.y;
    /* なぞる間は渦を弱め、水を指の向きへ運ぶほうを主にする */
    finger.kap *= 0.9;
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist > 6) {
      var nn = Math.ceil(dist / (S * 0.03));
      for (var i = 1; i <= nn; i++) dropInk(last.x + (p.x - last.x) * i / nn, last.y + (p.y - last.y) * i / nn, S * 0.022, 0.6);
      SFX.play('move', 140);
    }
    last = p; lastMoveT = now;
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { speed = 0.25 + document.getElementById('k-speed').value / 100 * 1.7; }
  function readSwirl() { swirl = document.getElementById('k-swirl').value / 100 * 2; }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-swirl').addEventListener('input', readSwirl);
  document.querySelectorAll('[data-pat]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-pat]').forEach(function (o) { o.classList.toggle('active', o === b); });
      pat = b.dataset.pat;
      buildRef(); A.set(REF);
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readSwirl();
  var act = document.querySelector('[data-pat].active');
  if (act && act.dataset.pat !== pat) { pat = act.dataset.pat; buildRef(); A.set(REF); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    var sdt = dt * speed;
    clock += sdt;
    if (clock > nextPlan) { nextPlan = clock + 20 + Math.random() * 8; newPlan(); }
    if (comb < 0 && clock > nextComb) { comb = 0; SFX.play('grow'); }
    if (comb >= 0) {
      comb += L / 7 * sdt;
      if (comb > L + L * 0.1) { comb = -1; nextComb = clock + 20 + Math.random() * 6; }
    }
    if (finger) {
      if (!down) { finger.w -= dt * 1.6; if (finger.w <= 0) finger = null; }
      else if (now - lastMoveT > 90) { finger.vx *= 0.85; finger.vy *= 0.85; }
    }
    place(sdt);
    /* 指の動きは画面の速さどおりに効かせる（SPEED で鈍らせない） */
    if (finger && speed > 0) { finger.vx /= speed; finger.vy /= speed; finger.kap /= speed; }
    advect(sdt);
    if (finger && speed > 0) { finger.vx *= speed; finger.vy *= speed; finger.kap *= speed; }
    shade();
    P.grid(ctx, W, H, { alpha: 0.6 });
    drawComb();
    drawRods();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
