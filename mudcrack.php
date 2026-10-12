<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('mudcrack');

$pageName = 'mudcrack';
$pageTitle = 'MUDCRACK - PULSE';
$pageDesc = '乾いていく泥に白いひびが伸び、ほかのひびに当たると止まる。ひびの途中からは直角に新しいひびが分かれ、地面が大小の区画に分かれていく。古いひびは少しずつ消えて、空いたところにまた新しいひびが入る。押すとまわりのひびが消えて、そこから水色のひびが四方へ走り、なぞると指の跡に沿ってひびが入る。';
$pageCanonical = 'https://pulse.subara3.com/mudcrack.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-bend">BEND</label><input type="range" id="k-bend" min="0" max="100" value="35"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* ひびは短い線分（かけら）の列。どのかけらがどのマスを通ったかを粗い格子に書いておき、
     伸びている先が別のひびのマスに入ったら止める */
  var CELL = 3, GX = 0, GY = 0, own = null;
  var MAXP = 14000;
  var px0 = new Float32Array(MAXP), py0 = new Float32Array(MAXP), px1 = new Float32Array(MAXP), py1 = new Float32Array(MAXP);
  var pDie = new Float32Array(MAXP), pFade = new Float32Array(MAXP), pHot = new Float32Array(MAXP);
  var pFam = new Int32Array(MAXP), pGen = new Uint8Array(MAXP), pLive = new Uint8Array(MAXP), pClear = new Uint8Array(MAXP);
  var free = [], top = 0;
  /* 最近できたかけら。枝はなるべく伸びている最中の場所から出す */
  var recent = new Int32Array(300), recN = 0;
  /* 伸びている先端 */
  var heads = [];
  var famSeq = 1, clock = 0;
  var speed = 56, bend = 0.35, target = 20;
  var WID = [2.4, 1.6, 1.15, 0.85, 0.65], LUM = [0.95, 0.86, 0.76, 0.66, 0.56];
  var LEVELS = 8, buckets = [];
  for (var b = 0; b < WID.length * LEVELS; b++) buckets.push([]);
  var hotList = [];

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function newSlot() {
    if (free.length) return free.pop();
    if (top < MAXP) return top++;
    return -1;
  }

  function addPiece(x, y, fam, gen, hot) {
    var s = newSlot();
    if (s < 0) return -1;
    recent[recN++ % recent.length] = s;
    px0[s] = px1[s] = x; py0[s] = py1[s] = y;
    pDie[s] = 1e9;
    pFade[s] = 5; pHot[s] = hot; pFam[s] = fam; pGen[s] = gen; pLive[s] = 1; pClear[s] = 0;
    return s;
  }

  function cellAt(x, y) {
    var cx = x / CELL | 0, cy = y / CELL | 0;
    if (cx < 0 || cy < 0 || cx >= GX || cy >= GY) return -1;
    return cy * GX + cx;
  }

  function blockedBy(c, fam) {
    var o = own[c];
    return o > 0 && pFam[o - 1] !== fam;
  }

  function addHead(x, y, a, fam, gen, hot, boost, from) {
    var s = addPiece(from ? from[0] : x, from ? from[1] : y, fam, gen, hot);
    if (s < 0) return null;
    px1[s] = x; py1[s] = y;
    var h = { x: x, y: y, a: a, fam: fam, gen: gen, hot: hot, piece: s, len: 0, boost: boost || 1,
      curv: rnd(-1, 1) * bend * 0.006, lc: cellAt(x, y), dead: false };
    heads.push(h);
    return h;
  }

  /* 先端を d だけ進める。1px ずつ進めて、新しいマスに入るたびに当たりを調べる */
  function advance(h, d) {
    if (!pLive[h.piece] || pClear[h.piece]) { h.dead = true; return; }
    var n = Math.ceil(d), st = d / n;
    for (var i = 0; i < n; i++) {
      h.a += h.curv * st;
      var nx = h.x + Math.cos(h.a) * st, ny = h.y + Math.sin(h.a) * st;
      var c = cellAt(nx, ny);
      if (c < 0) { h.dead = true; return; }
      if (c !== h.lc) {
        var hit = blockedBy(c, h.fam);
        if (!hit && h.lc >= 0) {
          /* 斜めに抜けると細い線をすり抜けるので、角の 2 マスも見る */
          var lx = h.lc % GX, ly = h.lc / GX | 0, cx = c % GX, cy = c / GX | 0;
          if (lx !== cx && ly !== cy) {
            var c1 = ly * GX + cx, c2 = cy * GX + lx;
            hit = blockedBy(c1, h.fam) || blockedBy(c2, h.fam);
            if (!hit && !own[c1]) own[c1] = h.piece + 1;
          }
        }
        if (hit) {
          px1[h.piece] = nx; py1[h.piece] = ny;
          h.dead = true;
          SFX.play('tick', 2600);
          return;
        }
        if (!own[c]) own[c] = h.piece + 1;
        h.lc = c;
      }
      h.x = nx; h.y = ny;
      px1[h.piece] = nx; py1[h.piece] = ny;
      h.len += st;
      if (h.len > 16) {
        var s = addPiece(nx, ny, h.fam, h.gen, h.hot);
        if (s < 0) { h.dead = true; return; }
        h.piece = s; h.len = 0;
        h.curv += rnd(-1, 1) * bend * 0.0012;
        h.curv *= 0.97;
      }
    }
  }

  /* 生きているかけらの途中から、直角に新しいひびを出す */
  function branch() {
    if (top === 0) return false;
    for (var tries = 0; tries < 14; tries++) {
      var s = (Math.random() < 0.6 && recN > 0) ? recent[Math.random() * Math.min(recN, recent.length) | 0] : Math.random() * top | 0;
      if (!pLive[s] || pClear[s] || pDie[s] - clock < 6) continue;
      var dx = px1[s] - px0[s], dy = py1[s] - py0[s], L = Math.hypot(dx, dy);
      if (L < 4) continue;
      var t = Math.random(), x = px0[s] + dx * t, y = py0[s] + dy * t;
      var a = Math.atan2(dy, dx) + (Math.random() < 0.5 ? 1 : -1) * Math.PI / 2 + rnd(-0.18, 0.18) * (0.4 + bend);
      var sx = x + Math.cos(a) * 6, sy = y + Math.sin(a) * 6;
      /* 前が 30px ほど空いているときだけ出す。区画が細かくなりすぎない */
      if (!room(sx, sy, a, 44)) continue;
      addHead(sx, sy, a, famSeq++, Math.min(3, pGen[s] + 1), pHot[s] > clock ? pHot[s] : 0, 1, [x, y]);
      return true;
    }
    return false;
  }

  /* 前と左右が空いているか。すぐ横に平行なひびが並ばないようにする */
  function room(x, y, a, d) {
    var ca = Math.cos(a), sa = Math.sin(a);
    for (var r = 0; r <= d; r += 6) {
      var c = cellAt(x + ca * r, y + sa * r);
      if (c < 0 || own[c]) return false;
      if (r >= 12) {
        var c1 = cellAt(x + ca * r - sa * 18, y + sa * r + ca * 18), c2 = cellAt(x + ca * r + sa * 18, y + sa * r - ca * 18);
        if ((c1 >= 0 && own[c1]) || (c2 >= 0 && own[c2])) return false;
      }
    }
    return true;
  }

  /* 何もないところに、両方向へ伸びる長いひびを置く */
  function seedAt(x, y) {
    var c = cellAt(x, y);
    if (c < 0 || own[c]) return false;
    var a = Math.random() * Math.PI, f = famSeq++;
    addHead(x, y, a, f, 0, 0, 1);
    addHead(x, y, a + Math.PI, f, 0, 0, 1);
    return true;
  }
  function seed() {
    for (var tries = 0; tries < 30; tries++) {
      if (seedAt(rnd(W * 0.05, W * 0.95), rnd(H * 0.05, H * 0.95))) return;
    }
  }

  /* ときどき円い範囲が湿って、ひびが中心から外へ順に閉じる。乾くとまた長いひびから割れ直す */
  var nextWash = 14, pend = [];
  function crowd(x, y, R) {
    var n = 0, i0 = Math.max(0, (x - R) / CELL | 0), i1 = Math.min(GX - 1, (x + R) / CELL | 0);
    var j0 = Math.max(0, (y - R) / CELL | 0), j1 = Math.min(GY - 1, (y + R) / CELL | 0);
    for (var j = j0; j <= j1; j += 4) for (var i = i0; i <= i1; i += 4) if (own[j * GX + i]) n++;
    return n;
  }
  function wash() {
    var R = Math.sqrt(W * H) * 0.24, bx = 0, by = 0, best = -1;
    for (var k = 0; k < 4; k++) {
      var x = rnd(0, W), y = rnd(0, H), n = crowd(x, y, R * 0.6);
      if (n > best) { best = n; bx = x; by = y; }
    }
    for (var s = 0; s < top; s++) {
      if (!pLive[s] || pClear[s] || pDie[s] < 1e8) continue;
      var d = Math.hypot((px0[s] + px1[s]) * 0.5 - bx, (py0[s] + py1[s]) * 0.5 - by);
      if (d > R) continue;
      pFade[s] = 1.6; pDie[s] = clock + 0.4 + d / R * 2.4 + 1.6;
    }
    for (var m = 0; m < 4; m++) {
      var ang = Math.random() * 6.2832, rr = m ? R * rnd(0.3, 0.6) : 0;
      pend.push({ t: clock + 2.6 + m * 0.5 + rr / R * 2, x: bx + Math.cos(ang) * rr, y: by + Math.sin(ang) * rr });
    }
  }

  function clearCells(s) {
    var x0 = Math.min(px0[s], px1[s]) - 2 * CELL, x1 = Math.max(px0[s], px1[s]) + 2 * CELL;
    var y0 = Math.min(py0[s], py1[s]) - 2 * CELL, y1 = Math.max(py0[s], py1[s]) + 2 * CELL;
    var i0 = Math.max(0, x0 / CELL | 0), i1 = Math.min(GX - 1, x1 / CELL | 0);
    var j0 = Math.max(0, y0 / CELL | 0), j1 = Math.min(GY - 1, y1 / CELL | 0);
    for (var j = j0; j <= j1; j++) for (var i = i0; i <= i1; i++) if (own[j * GX + i] === s + 1) own[j * GX + i] = 0;
    pClear[s] = 1;
  }

  /* 古いかけらは薄れはじめた時点で格子から外す。空いたところに次のひびが入れる */
  function age() {
    for (var s = 0; s < top; s++) {
      if (!pLive[s]) continue;
      if (!pClear[s] && clock > pDie[s] - pFade[s]) clearCells(s);
      if (clock > pDie[s]) { pLive[s] = 0; free.push(s); }
    }
  }

  function step(dt) {
    clock += dt;
    var nh = 0;
    for (var i = 0; i < heads.length; i++) {
      var h = heads[i];
      advance(h, speed * h.boost * dt);
      h.boost = 1 + (h.boost - 1) * Math.exp(-dt * 1.1);
      if (!h.dead) heads[nh++] = h;
    }
    heads.length = nh;
    var tries = 0;
    while (heads.length < target && tries++ < 6) {
      if (!branch()) { if (heads.length < target * 0.5) seed(); break; }
    }
    age();
    if (clock > nextWash) { wash(); nextWash = clock + rnd(7, 10); }
    for (var p = pend.length - 1; p >= 0; p--) {
      if (clock < pend[p].t) continue;
      var q = pend.splice(p, 1)[0];
      if (!seedAt(q.x, q.y)) seedAt(q.x + rnd(-20, 20), q.y + rnd(-20, 20));
    }
  }

  function alloc() {
    GX = Math.ceil(W / CELL) + 1; GY = Math.ceil(H / CELL) + 1;
    own = new Int32Array(GX * GY);
    pLive.fill(0); free = []; top = 0; recN = 0; heads = []; clock = 0; nextWash = 14; pend = [];
    target = Math.round(8 + W * H / 48000);
    var n = 3 + Math.round(W * H / 700000);
    for (var k = 0; k < n; k++) seed();
    /* 開いたときにはもう地面が割れはじめている */
    for (var f = 0; f < 300; f++) step(1 / 30);
  }

  /* ---------------------------------------------------------- 描く */
  var DEEP = P.deep, LN = P.line;
  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.7 });
    for (var b = 0; b < buckets.length; b++) buckets[b].length = 0;
    hotList.length = 0;
    for (var s = 0; s < top; s++) {
      if (!pLive[s]) continue;
      var a = Math.min(1, (pDie[s] - clock) / pFade[s]);
      if (a <= 0) continue;
      if (pHot[s] > clock) { hotList.push(s); continue; }
      var lv = Math.min(LEVELS - 1, Math.ceil(a * LEVELS) - 1);
      buckets[pGen[s] * LEVELS + lv].push(s);
    }
    ctx.lineCap = 'round';
    /* 太いひびの下に暗い影を敷いて、溝に見せる */
    for (var g = 0; g < 2; g++) {
      for (var l = 0; l < LEVELS; l++) {
        var list = buckets[g * LEVELS + l];
        if (!list.length) continue;
        ctx.strokeStyle = P.rgba(DEEP, 0.75 * (l + 1) / LEVELS);
        ctx.lineWidth = WID[g] + 3.5;
        ctx.beginPath();
        for (var i = 0; i < list.length; i++) { var q = list[i]; ctx.moveTo(px0[q] + 1, py0[q] + 1.2); ctx.lineTo(px1[q] + 1, py1[q] + 1.2); }
        ctx.stroke();
      }
    }
    for (var gg = 0; gg < WID.length; gg++) {
      for (var ll = 0; ll < LEVELS; ll++) {
        var lst = buckets[gg * LEVELS + ll];
        if (!lst.length) continue;
        ctx.strokeStyle = P.rgba(LN, LUM[gg] * (ll + 1) / LEVELS);
        ctx.lineWidth = WID[gg];
        ctx.beginPath();
        for (var k = 0; k < lst.length; k++) { var r = lst[k]; ctx.moveTo(px0[r], py0[r]); ctx.lineTo(px1[r], py1[r]); }
        ctx.stroke();
      }
    }
    /* 触って入ったひびは、しばらく水色。時間とともに白へ戻る */
    for (var m = 0; m < hotList.length; m++) {
      var p = hotList[m], k2 = Math.min(1, (pHot[p] - clock) / 3);
      var fa = Math.min(1, (pDie[p] - clock) / pFade[p]);
      ctx.strokeStyle = P.rgba(P.mix(LN, P.accent, k2), fa * (0.75 + 0.25 * k2));
      ctx.lineWidth = WID[pGen[p]] + 0.6 * k2;
      ctx.beginPath(); ctx.moveTo(px0[p], py0[p]); ctx.lineTo(px1[p], py1[p]); ctx.stroke();
    }
    /* 伸びている先端 */
    ctx.fillStyle = P.rgba(LN, 0.9);
    ctx.beginPath();
    for (var hI = 0; hI < heads.length; hI++) { var h = heads[hI]; ctx.moveTo(h.x + 1.5, h.y); ctx.arc(h.x, h.y, 1.5, 0, 6.2832); }
    ctx.fill();
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, dragFam = 0;

  /* 押したところのひびを消して、そこから水色のひびを四方へ走らせる */
  function burst(x, y) {
    var R = Math.max(50, Math.min(W, H) * 0.09);
    for (var s = 0; s < top; s++) {
      if (!pLive[s] || pClear[s]) continue;
      var dx = px1[s] - px0[s], dy = py1[s] - py0[s], L2 = dx * dx + dy * dy || 1;
      var t = Math.max(0, Math.min(1, ((x - px0[s]) * dx + (y - py0[s]) * dy) / L2));
      if (Math.hypot(px0[s] + dx * t - x, py0[s] + dy * t - y) < R) {
        pFade[s] = 0.45; pDie[s] = clock + 0.45;
        clearCells(s);
      }
    }
    var n = 6 + (Math.random() * 3 | 0), a0 = Math.random() * 6.2832, f = dragFam;
    for (var i = 0; i < n; i++) {
      var a = a0 + i * 6.2832 / n + rnd(-0.25, 0.25);
      addHead(x + Math.cos(a) * 3, y + Math.sin(a) * 3, a, f, i % 2 ? 1 : 0, clock + 6, 4.2, [x, y]);
    }
  }

  /* 指の跡そのものをひびにして、ときどき横へ枝を出す */
  function trace(x0, y0, x1, y1) {
    var s = addPiece(x0, y0, dragFam, 1, clock + 6);
    if (s < 0) return;
    px1[s] = x1; py1[s] = y1;
    var L = Math.hypot(x1 - x0, y1 - y0), n = Math.ceil(L);
    for (var i = 0; i <= n; i++) {
      var c = cellAt(x0 + (x1 - x0) * i / n, y0 + (y1 - y0) * i / n);
      if (c >= 0 && !own[c]) own[c] = s + 1;
      if (c >= 0 && c + 1 < own.length && !own[c + 1]) own[c + 1] = s + 1;
      if (c >= GX && !own[c - GX]) own[c - GX] = s + 1;
    }
    if (Math.random() < 0.3) {
      var a = Math.atan2(y1 - y0, x1 - x0) + (Math.random() < 0.5 ? 1 : -1) * Math.PI / 2 + rnd(-0.2, 0.2);
      addHead(x1 + Math.cos(a) * 6, y1 + Math.sin(a) * 6, a, famSeq++, 2, clock + 6, 2.6, [x1, y1]);
    }
  }

  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; dragFam = famSeq++;
    burst(last.x, last.y);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var x = e.clientX, y = e.clientY;
    if (Math.hypot(x - last.x, y - last.y) < 6) return;
    trace(last.x, last.y, x, y);
    last = { x: x, y: y };
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kSpeed = document.getElementById('k-speed'), kBend = document.getElementById('k-bend');
  function readSpeed() { speed = 20 + kSpeed.value * 0.9; }
  function readBend() { bend = kBend.value / 100; }
  kSpeed.addEventListener('input', readSpeed);
  kBend.addEventListener('input', readBend);
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readBend();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    step(dt);
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
