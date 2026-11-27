<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('knight');

$pageName = 'knight';
$pageTitle = 'KNIGHT - PULSE';
$pageDesc = '画面いっぱいの盤の上を点が縦2マス横1マスずつ跳んで、まだ通っていないマスをたどり、行き止まりでは跡を消しながら引き返して、跳んだ跡の直線が重なって網目になる。押すとそこのマスから新しい道が速く伸び、なぞると点が指のほうへ跳んでついてくる。';
$pageCanonical = 'https://pulse.subara3.com/knight.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 盤: cols x rows のマス。跳び方は桂馬（±1,±2）の 8 通り */
  var DX = [1, 2, 2, 1, -1, -2, -2, -1], DY = [-2, -1, 1, 2, 2, 1, -1, -2];
  var cols = 0, rows = 0, N = 0, cell = 40, ox = 0, oy = 0;
  var visited = null, path = [], cands = [], backs = 0;
  var ghosts = [];          /* 終わった道。ゆっくり薄れる */
  var pulses = [];          /* 押したところのシアンの輪 */
  var glow = null;          /* マスごとの光（押したところのまわり・跳んだ直後） */
  var hop = 1, retract = 0; /* hop: 今の一歩の進み具合 0→1, retract: 引き返し中の戻り具合 */
  var speed = 0.4, sizeK = 0.4, boost = 0;
  var finger = null, down = false, lastCell = -1, farT = 0;
  var hold = 0;             /* 一周し終えた絵を見せておく時間 */

  function cx(i) { return ox + (i % cols + 0.5) * cell; }
  function cy(i) { return oy + ((i / cols | 0) + 0.5) * cell; }
  function idx(x, y) { return y * cols + x; }
  function cellAt(px, py) {
    var x = Math.floor((px - ox) / cell), y = Math.floor((py - oy) / cell);
    x = Math.max(0, Math.min(cols - 1, x)); y = Math.max(0, Math.min(rows - 1, y));
    return idx(x, y);
  }

  function degree(i) {
    var x = i % cols, y = i / cols | 0, n = 0;
    for (var k = 0; k < 8; k++) {
      var nx = x + DX[k], ny = y + DY[k];
      if (nx >= 0 && ny >= 0 && nx < cols && ny < rows && !visited[idx(nx, ny)]) n++;
    }
    return n;
  }

  /* 次に跳ぶ候補を、出口の少ないマスから順に並べる（ワーンスドルフの規則）。
     指があるときは指に近いマスを強く優先する。同点は盤の中心から遠いほうへ */
  function order(i) {
    var x = i % cols, y = i / cols | 0, list = [];
    var mx = (cols - 1) / 2, my = (rows - 1) / 2;
    var fc = finger ? cellAt(finger.x, finger.y) : -1;
    var fx = fc >= 0 ? fc % cols : 0, fy = fc >= 0 ? (fc / cols | 0) : 0;
    for (var k = 0; k < 8; k++) {
      var nx = x + DX[k], ny = y + DY[k];
      if (nx < 0 || ny < 0 || nx >= cols || ny >= rows) continue;
      var j = idx(nx, ny);
      if (visited[j]) continue;
      var s = degree(j) * 10 - Math.hypot(nx - mx, ny - my) * 0.3 + Math.random() * 0.2;
      if (fc >= 0) s = Math.hypot(nx - fx, ny - fy) * 6 + degree(j) * 2;
      list.push({ j: j, s: s });
    }
    list.sort(function (a, b) { return b.s - a.s; });   /* 後ろから pop する */
    return list.map(function (o) { return o.j; });
  }

  function retire() {
    if (path.length > 1) ghosts.push({ p: path.slice(), a: 0.55 });
    if (ghosts.length > 3) ghosts.shift();
  }

  function startAt(i) {
    retire();
    visited.fill(0);
    path = [i]; cands = [order(i)];
    visited[i] = 1;
    hop = 1; retract = 0; backs = 0; hold = 0;
  }

  /* 一歩進む。進めなければ 1 つ戻る（戻りは描画で線を縮めて見せる） */
  function advance() {
    if (path.length >= N) {
      /* 全部のマスを回り終えた。しばらく眺めさせてから別のマスで始める */
      if (hold <= 0) { hold = 3; SFX.play('grow'); }
      return;
    }
    var top = cands[cands.length - 1];
    while (top.length && visited[top[top.length - 1]]) top.pop();
    if (top.length) {
      var j = top.pop();
      visited[j] = 1; path.push(j); cands.push(order(j));
      glow[j] = Math.max(glow[j], 0.6);
      hop = 0;
      if (path.length % 9 === 0) SFX.play('tick', 1500);
      return;
    }
    /* 行き止まり。引き返して別の道を試す。試しすぎたらそこで切り上げる */
    backs++;
    if (backs > N * 3 || path.length < 2) { startAt(path[path.length - 1]); return; }
    retract = 1;
  }

  function popOne() {
    var j = path.pop(); cands.pop();
    visited[j] = 0;
  }

  /* 開いた瞬間から絵があるように、盤を作ったら少し先まで解いておく */
  function layout() {
    cell = Math.round(26 + sizeK * 46);
    cols = Math.max(5, Math.floor((W - 8) / cell));
    rows = Math.max(5, Math.floor((H - 8) / cell));
    cell = Math.min((W - 8) / cols, (H - 8) / rows);
    ox = (W - cols * cell) / 2; oy = (H - rows * cell) / 2;
    N = cols * rows;
    visited = new Uint8Array(N);
    glow = new Float32Array(N);
    ghosts = []; path = []; cands = [];
    startAt((Math.random() * N) | 0);
    var guard = 0;
    while (path.length < N && guard++ < N * 6) { advance(); if (retract) { popOne(); retract = 0; } }
    startAt((Math.random() * N) | 0);   /* 解き終えた道は薄い跡として残る */
    guard = 0;
    while (path.length < Math.min(N * 0.3, 40) && guard++ < 400) { advance(); if (retract) { popOne(); retract = 0; } }
    hop = 1;
  }

  /* 状態をすべて宣言してから呼ぶ */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; layout(); });

  /* ---------------------------------------------------------- 描く */
  function seg(a, b) { ctx.moveTo(cx(a), cy(a)); ctx.lineTo(cx(b), cy(b)); }

  function draw(dt) {
    P.fill(ctx, W, H, 1);
    ctx.save();
    ctx.translate(ox - cell, oy - cell);
    P.grid(ctx, W + cell * 2, H + cell * 2, { step: cell / 2, major: 2, alpha: 0.7 });
    ctx.restore();

    /* マスの中心の点 */
    ctx.fillStyle = P.rgba(P.steel, 0.35);
    for (var i = 0; i < N; i++) {
      ctx.fillRect(cx(i) - 1, cy(i) - 1, 2, 2);
    }

    /* 終わった道 */
    ctx.lineWidth = 1;
    for (var g = 0; g < ghosts.length; g++) {
      var gh = ghosts[g];
      gh.a -= dt * (ghosts.length > 2 && g === 0 ? 0.03 : 0.008);   /* 新しい跡が重なるほど古い跡から消える */
      if (gh.a <= 0) { ghosts.splice(g--, 1); continue; }
      ctx.strokeStyle = P.rgba(P.steel, gh.a);
      ctx.beginPath();
      for (var s = 1; s < gh.p.length; s++) seg(gh.p[s - 1], gh.p[s]);
      ctx.stroke();
    }

    /* 今の道: 古い区間は鋼色、新しい区間ほど白く太く */
    var L = path.length, done = L >= N;
    var last = L - 1;
    var BANDS = 6;
    for (var b = 0; b < BANDS; b++) {
      var lo = Math.floor(last * b / BANDS), hi = Math.floor(last * (b + 1) / BANDS);
      if (hi <= lo) continue;
      var t = (b + 1) / BANDS;
      if (done) t = 0.6 + 0.4 * t;
      ctx.strokeStyle = P.rgba(P.ramp(0.5 + 0.5 * t), 0.35 + 0.6 * t);
      ctx.lineWidth = 0.8 + 0.6 * t;
      ctx.beginPath();
      for (var q = Math.max(1, lo); q <= hi; q++) {
        if (q === last && (hop < 1 || retract > 0)) continue;
        seg(path[q - 1], path[q]);
      }
      ctx.stroke();
    }
    /* 動いている一歩（伸びる・縮む） */
    var hx = L ? cx(path[last]) : 0, hy = L ? cy(path[last]) : 0;
    if (L > 1 && (hop < 1 || retract > 0)) {
      var a0 = path[last - 1], f = retract > 0 ? retract : hop;
      f = f * f * (3 - 2 * f);
      hx = cx(a0) + (cx(path[last]) - cx(a0)) * f;
      hy = cy(a0) + (cy(path[last]) - cy(a0)) * f;
      ctx.strokeStyle = P.rgba(retract > 0 ? P.steel : P.line, 0.95);
      ctx.lineWidth = 1.6;
      ctx.beginPath(); ctx.moveTo(cx(a0), cy(a0)); ctx.lineTo(hx, hy); ctx.stroke();
    }

    /* 通ったマスの点、光っているマス */
    for (var m = 0; m < N; m++) {
      var gl = glow[m];
      if (gl > 0.01) {
        glow[m] = gl * Math.exp(-dt * 1.6);
        ctx.fillStyle = P.rgba(P.accent, Math.min(0.9, gl));
        ctx.beginPath(); ctx.arc(cx(m), cy(m), 1.5 + gl * cell * 0.12, 0, 6.2832); ctx.fill();
      } else if (visited[m]) {
        ctx.fillStyle = P.rgba(P.line, 0.5);
        ctx.fillRect(cx(m) - 1.2, cy(m) - 1.2, 2.4, 2.4);
      }
    }

    /* 先頭の点（画面に 1 つだけの焦点） */
    if (L) {
      ctx.fillStyle = P.rgba(P.accent, 0.18);
      ctx.beginPath(); ctx.arc(hx, hy, cell * 0.32, 0, 6.2832); ctx.fill();
      ctx.fillStyle = P.rgba(P.accent, 1);
      ctx.beginPath(); ctx.arc(hx, hy, 3.2, 0, 6.2832); ctx.fill();
    }

    /* 押したところの輪 */
    for (var r = pulses.length - 1; r >= 0; r--) {
      var pu = pulses[r]; pu.t += dt;
      var k = pu.t / 1.2;
      if (k >= 1) { pulses.splice(r, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, 0.5 * (1 - k));
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(pu.x, pu.y, cell * (0.4 + k * 2.6), 0, 6.2832); ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  function lightAround(i, rad) {
    var x0 = i % cols, y0 = i / cols | 0;
    for (var y = Math.max(0, y0 - rad); y <= Math.min(rows - 1, y0 + rad); y++) {
      for (var x = Math.max(0, x0 - rad); x <= Math.min(cols - 1, x0 + rad); x++) {
        var d = Math.hypot(x - x0, y - y0);
        if (d <= rad) { var j = idx(x, y); glow[j] = Math.max(glow[j], 0.8 * (1 - d / (rad + 1))); }
      }
    }
  }

  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    finger = { x: e.clientX, y: e.clientY };
    var c = cellAt(finger.x, finger.y);
    lastCell = c; farT = 0;
    startAt(c);
    lightAround(c, 2);
    pulses.push({ x: cx(c), y: cy(c), t: 0 });
    boost = 1.2;   /* 押した直後は速く跳んで、まわりにすぐ線が増える */
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    finger = { x: e.clientX, y: e.clientY };
    var c = cellAt(finger.x, finger.y);
    if (c !== lastCell) { lastCell = c; glow[c] = Math.max(glow[c], 0.7); SFX.play('move', 160); }
    boost = Math.max(boost, 0.4);
  });
  function up() { down = false; finger = null; farT = 0; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kSpeed = document.getElementById('k-speed'), kSize = document.getElementById('k-size');
  kSpeed.addEventListener('input', function () { speed = this.value / 100; });
  kSize.addEventListener('input', function () { sizeK = this.value / 100; layout(); });
  speed = kSpeed.value / 100;
  if (Math.abs(kSize.value / 100 - sizeK) > 0.001) { sizeK = kSize.value / 100; layout(); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    boost = Math.max(0, boost - dt);
    var rate = 2 + speed * 9;                       /* 1 秒に跳ぶ回数 */
    if (boost > 0) rate = Math.max(rate, down ? 16 : 26);

    /* 指が先頭から離れたまま届かないときは、指のマスから道を引き直す */
    if (down && finger && path.length) {
      var h = path[path.length - 1];
      var d = Math.hypot(cx(h) - finger.x, cy(h) - finger.y) / cell;
      farT = d > 3.2 ? farT + dt : 0;
      if (farT > 0.6) {
        var c = cellAt(finger.x, finger.y);
        startAt(c); lightAround(c, 1); farT = 0; boost = 0.6;
      }
    }

    if (hold > 0) {
      hold -= dt;
      if (hold <= 0) { startAt((Math.random() * N) | 0); }
    } else if (retract > 0) {
      retract -= dt * rate * 2;
      if (retract <= 0) { retract = 0; popOne(); advance(); }
    } else {
      hop += dt * rate;
      var steps = 0;
      while (hop >= 1 && steps++ < 4 && hold <= 0 && retract <= 0) {
        var carry = hop - 1;
        advance();
        if (hop === 0) hop = Math.min(0.99, carry); else break;
      }
    }
    draw(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
