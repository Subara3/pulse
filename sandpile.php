<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('sandpile');

$pageName = 'sandpile';
$pageTitle = 'SANDPILE - PULSE';
$pageDesc = '中心に砂粒が1粒ずつ落ち、4粒たまったマスは隣の4マスに1粒ずつ崩れて、高さの境目を線で描いた模様が円く広がり、画面の上下に届くと横へ伸びて画面を埋めていく。押すとそこに砂がまとめて落ちて、まわりに水色の小さな模様の円が広がり、なぞると指の跡に沿って砂が落ちて模様がつながる。';
$pageCanonical = 'https://pulse.subara3.com/sandpile.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pour">POUR</label><input type="range" id="k-pour" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-src="1">ONE</button><button type="button" data-src="2">TWO</button><button type="button" data-src="4">FOUR</button></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 砂山モデル。1 マスに 4 粒たまると、4 方向へ 1 粒ずつ配って崩れる。端から落ちた粒は消える。
     マスそのものは塗らず、高さの違う隣どうしの境目にだけ細い線を引く。
     高さのそろった区画は空白に、市松に混ざった区画は細かい網になって、曼荼羅の区割りが線画で浮かぶ */
  var NX, NY, S, ox, oy, h, flash, mark, stack, BW, BH, img, buf, bctx;
  var pour = 0.4, srcCount = 1, sources = [];
  var state = 'grow', stateT = 0, lost = 0;

  function alloc() {
    /* 画面全体を砂の台にする。山は短い辺に届いたあと、長い辺の向きへ伸びて画面を埋めていく */
    var side = Math.min(W, H);
    S = side > 1000 ? 6 : side > 520 ? 5 : 4;
    NX = Math.floor(W / S) | 1; NY = Math.floor(H / S) | 1;
    while (NX * NY > 62000) { S++; NX = Math.floor(W / S) | 1; NY = Math.floor(H / S) | 1; }
    ox = Math.round((W - NX * S) / 2); oy = Math.round((H - NY * S) / 2);
    BW = NX * S + 1; BH = NY * S + 1;
    img = new ImageData(BW, BH);
    buf = document.createElement('canvas'); buf.width = BW; buf.height = BH;
    bctx = buf.getContext('2d');
    h = new Uint16Array(NX * NY); flash = new Float32Array(NX * NY); mark = new Float32Array(NX * NY);
    stack = new Int32Array(NX * NY * 4);
    restart();
    /* 開いた瞬間から模様が見えるように、少し積んでおく（積みすぎると開くのが遅れるので、粒の数に上限を置く） */
    var pre = Math.round(Math.min(16000, Math.min(NX, NY) * Math.min(NX, NY) * 0.9) / sources.length);
    /* 崩れの結果は置く順に依らないので、まとめて置いてから一度に崩す */
    for (var q = 0; q < sources.length; q++) drop(sources[q][0], sources[q][1], pre, false);
    relax(1e9);
    flash.fill(0);
  }

  function restart() {
    h.fill(0); flash.fill(0); mark.fill(0);
    var cx = (NX - 1) >> 1, cy = (NY - 1) >> 1, wide = NX >= NY;
    var dx = Math.round(NX * (srcCount === 2 ? (wide ? 0.22 : 0.17) : 0.2)), dy = Math.round(NY * (srcCount === 2 ? (wide ? 0.17 : 0.22) : 0.2));
    sources = srcCount === 1 ? [[cx, cy]]
      : srcCount === 2 ? (wide ? [[cx - dx, cy], [cx + dx, cy]] : [[cx, cy - dy], [cx, cy + dy]])
      : [[cx - dx, cy - dy], [cx + dx, cy - dy], [cx - dx, cy + dy], [cx + dx, cy + dy]];
    state = 'grow'; stateT = 0; lost = 0;
  }

  /* 1 粒置いて、崩れが止まるまで配る。budget を超えたら続きは次のフレームへ持ち越す */
  var sp = 0, touch = { i: 0, j: 0 }, touchT = 0;
  function drop(i, j, n, touched) {
    if (i < 0 || j < 0 || i >= NX || j >= NY) return;
    var k = j * NX + i;
    h[k] += n;
    if (touched) for (var b = -4; b <= 4; b++) for (var a = -4; a <= 4; a++) {
      if (a * a + b * b > 16 || i + a < 0 || j + b < 0 || i + a >= NX || j + b >= NY) continue;
      mark[(j + b) * NX + i + a] = 1;
    }
    if (h[k] >= 4) stack[sp++] = k;
  }
  function relax(budget) {
    while (sp > 0 && budget > 0) {
      var k = stack[--sp];
      if (h[k] < 4) continue;
      var q = h[k] >> 2;
      h[k] -= q * 4;
      flash[k] = 1;
      budget--;
      var i = k % NX, j = (k - i) / NX;
      /* 触った所の近くで起きた崩れだけをシアンで残す */
      if (touchT > 0 && Math.abs(i - touch.i) + Math.abs(j - touch.j) < 22) mark[k] = 1;
      spill(i + 1, j, q); spill(i - 1, j, q); spill(i, j + 1, q); spill(i, j - 1, q);
      if (h[k] >= 4) stack[sp++] = k;
    }
  }
  function spill(i, j, q) {
    if (i < 0 || j < 0 || i >= NX || j >= NY) {
      /* 長い辺の端からこぼれ始めたら、画面いっぱいに広がったということ */
      if (NX >= NY ? (i < 0 || i >= NX) : (j < 0 || j >= NY)) lost += q;
      return;
    }
    var k = j * NX + i;
    h[k] += q;
    if (h[k] >= 4 && h[k] - q < 4) stack[sp++] = k;
  }
  function addSources(n) {
    for (var r = 0; r < n; r++) {
      for (var s = 0; s < sources.length; s++) drop(sources[s][0], sources[s][1], 1, false);
      relax(1e9);
    }
  }

  /* ---------------------------------------------------------- 描く */
  /* 境目の線。画素 (x, y) が S の倍数の列・行に乗っていれば、その両側のマスの高さの差で線の濃さを決める。
     マスの中は、崩れた直後だけ白く、触った所の崩れはシアンにうっすら光る */
  var BG = P.bg, LN = P.line, AC = P.accent, ST = P.steel;
  var EDGE = [];
  (function () {
    var cols = [null, P.mix(BG, ST, 0.75), P.mix(BG, LN, 0.7), P.mix(BG, LN, 0.95)];
    for (var dlt = 0; dlt < 4; dlt++) EDGE.push(cols[dlt]);
  })();
  function hAt(i, j) { return (i < 0 || j < 0 || i >= NX || j >= NY) ? 0 : h[j * NX + i]; }
  /* マスごとに上と左の境目を調べ、S×S の画素の塊をまとめて書く（32 ビットで 1 画素ずつ） */
  var LE = new Uint32Array(1), le8 = new Uint8Array(LE.buffer);
  function pack(r, g, b) { le8[0] = r; le8[1] = g; le8[2] = b; le8[3] = 255; return LE[0]; }
  var EDGE32 = [0, pack(EDGE[1][0], EDGE[1][1], EDGE[1][2]), pack(EDGE[2][0], EDGE[2][1], EDGE[2][2]), pack(EDGE[3][0], EDGE[3][1], EDGE[3][2])];
  function draw() {
    P.fill(ctx, W, H);
    var d32 = new Uint32Array(img.data.buffer), i, j, x, y;
    for (j = 0; j <= NY; j++) {
      for (i = 0; i <= NX; i++) {
        var inside = i < NX && j < NY;
        var c = inside ? h[j * NX + i] : 0;
        var l = i > 0 && j < NY ? h[j * NX + i - 1] : 0;
        var u = j > 0 && i < NX ? h[(j - 1) * NX + i] : 0;
        var ul = i > 0 && j > 0 ? h[(j - 1) * NX + i - 1] : 0;
        var dl = Math.abs(c - l), du = Math.abs(c - u), dc = Math.abs(ul - c);
        if (dl > 3) dl = 3;
        if (du > 3) du = 3;
        var dcor = Math.max(dl, du, dc > 3 ? 3 : dc);
        var fillC = 0;
        if (inside) {
          var k = j * NX + i, fl = flash[k], mk = mark[k];
          if (fl > 0.04 || mk > 0.04) {
            var a = fl * 0.12, bb = mk > 1 ? 0.32 : mk * 0.32;
            fillC = pack(BG[0] + (LN[0] - BG[0]) * a + (AC[0] - BG[0]) * bb,
                         BG[1] + (LN[1] - BG[1]) * a + (AC[1] - BG[1]) * bb,
                         BG[2] + (LN[2] - BG[2]) * a + (AC[2] - BG[2]) * bb);
          }
        }
        var x0 = i * S, y0 = j * S;
        var xe = Math.min(BW, x0 + S), ye = Math.min(BH, y0 + S);
        /* 角の 1 画素 */
        d32[y0 * BW + x0] = dcor ? EDGE32[dcor] : fillC;
        /* 上の辺 */
        var top = du ? EDGE32[du] : fillC, row = y0 * BW;
        for (x = x0 + 1; x < xe; x++) d32[row + x] = top;
        /* 左の辺と中 */
        var left = dl ? EDGE32[dl] : fillC;
        for (y = y0 + 1; y < ye; y++) {
          row = y * BW;
          d32[row + x0] = left;
          for (x = x0 + 1; x < xe; x++) d32[row + x] = fillC;
        }
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(buf, ox, oy, BW, BH);
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, here = null;
  function cellOf(e) { return { i: Math.floor((e.clientX - ox) / S), j: Math.floor((e.clientY - oy) / S) }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; here = cellOf(e);
    touch = here; touchT = 2;
    drop(here.i, here.j, 2600, true);
    SFX.play('collide');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = cellOf(e);
    if (p.i === here.i && p.j === here.j) return;
    here = p; touch = p; touchT = 2;
    drop(here.i, here.j, 60, true);
    SFX.play('tick', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-pour').addEventListener('input', function () { pour = this.value / 100; });
  document.querySelectorAll('[data-src]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-src]').forEach(function (o) { o.classList.toggle('active', o === b); });
      srcCount = +b.dataset.src;
      restart();
      SFX.play('toggle');
    });
  });
  document.getElementById('k-clear').addEventListener('click', function () { restart(); SFX.play('click'); });
  pour = document.getElementById('k-pour').value / 100;

  ctx = P.fit(canvas, function (w, h2) { W = w; H = h2; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0, lostT = 0, sndT = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    stateT += dt;
    if (touchT > 0) touchT -= dt;
    if (state === 'grow') {
      /* 押しているあいだは、指の下に砂を足し続ける */
      if (down && here) { drop(here.i, here.j, 6, true); touchT = 2; }
      acc += dt * (120 + pour * 700);
      var n = Math.floor(acc); acc -= n;
      for (var r = 0; r < n && sp === 0; r++) for (var s = 0; s < sources.length; s++) drop(sources[s][0], sources[s][1], 1, false);
      relax(40000);
      /* 端から粒がこぼれ続けるようになったら（山が枠いっぱい）、しばらく眺めてから溶かして積み直す */
      lostT += dt;
      if (lostT > 4) { if (lost > 200 && stateT > 20) { state = 'full'; stateT = 0; } lost = 0; lostT = 0; }
    } else if (state === 'full') {
      acc += dt * 20;
      var m = Math.floor(acc); acc -= m;
      for (var r2 = 0; r2 < m && sp === 0; r2++) for (var s2 = 0; s2 < sources.length; s2++) drop(sources[s2][0], sources[s2][1], 1, false);
      relax(40000);
      if (stateT > 14) { state = 'fade'; stateT = 0; }
    } else if (state === 'fade') {
      /* 外側から順に砂を消していく */
      var cx = (NX - 1) / 2, cy = (NY - 1) / 2, rad = (1 - stateT / 4) * Math.max(NX, NY) * 0.75;
      for (var j = 0; j < NY; j++) for (var i = 0; i < NX; i++) {
        if (Math.max(Math.abs(i - cx), Math.abs(j - cy)) + Math.abs(i - cx) * 0.2 + Math.abs(j - cy) * 0.2 > rad) h[j * NX + i] = 0;
      }
      if (stateT > 4) {
        restart();
      }
    }
    for (var k = 0; k < flash.length; k++) {
      if (flash[k] > 0) flash[k] *= 0.88;
      if (mark[k] > 0) mark[k] *= 0.985;
    }
    sndT += dt;
    if (sp > 400 && sndT > 3) { sndT = 0; SFX.play('ripple', 3000); }
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
