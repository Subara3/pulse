<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('maze');

$pageName = 'maze';
$pageTitle = 'MAZE - PULSE';
$pageDesc = '1か所から通路がのびて迷路ができていく。できあがると光の点が入口から通路をたどり、行き止まりでは引き返しながら出口まで進む。触ると、そこから通路に沿って光が広がる。まだ迷路のない場所を触ると、そこから新しい通路が育つ。';
$pageCanonical = 'https://pulse.subara3.com/maze.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-cell">CELL</label><input type="range" id="k-cell" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  var CS = 24, COLS, ROWS, OX, OY, N;
  var open, born, tree, preGrow = false;                 /* 通路のビット（1 上 2 右 4 下 8 左）と、育った時刻 */
  var DX = [0, 1, 0, -1], DY = [-1, 0, 1, 0], BIT = [1, 2, 4, 8], OPP = [4, 8, 1, 2];
  var phase = 'grow', clock = 0, phaseT = 0;
  var active = [], grownCount = 0;
  var pace = 0.4, cellK = 0.45;

  /* 探索: 光は通路をたどり、行き止まりでは引き返す */
  var start, goal, stack, seen, trail, head = { from: 0, to: 0, t: 1 };
  var deadTrail = [];             /* 引き返した通路 { a, b, t } */
  var glows = [];                 /* 触ったところから通路を伝う光 */

  function build() {
    CS = Math.round(16 + cellK * 22);
    var mx = Math.max(24, W * 0.05), my = Math.max(24, H * 0.07);
    COLS = Math.max(8, Math.floor((W - mx * 2) / CS));
    ROWS = Math.max(6, Math.floor((H - my * 2) / CS));
    OX = Math.round((W - COLS * CS) / 2) + 0.5; OY = Math.round((H - ROWS * CS) / 2) + 0.5;
    N = COLS * ROWS;
    open = new Uint8Array(N);
    born = new Float32Array(N).fill(-1);
    seen = new Uint8Array(N);
    glows = []; deadTrail = []; stack = []; trail = [];
    phase = 'grow'; phaseT = 0;
    var s = ((ROWS * (0.3 + Math.random() * 0.4)) | 0) * COLS + ((COLS * (0.3 + Math.random() * 0.4)) | 0);
    born[s] = clock; active = [s]; grownCount = 1;
    /* 開いた瞬間から迷路が見えるよう、最初の半分ほどは一気に育てておく */
    preGrow = true;
    tree = new Int32Array(N).fill(-1); tree[s] = s;
  }
  function cx(c) { return OX + (c % COLS + 0.5) * CS; }
  function cy(c) { return OY + (((c / COLS) | 0) + 0.5) * CS; }
  function nb(c, d) {
    var x = c % COLS + DX[d], y = ((c / COLS) | 0) + DY[d];
    if (x < 0 || y < 0 || x >= COLS || y >= ROWS) return -1;
    return y * COLS + x;
  }

  /* 育てる: たいていは一番新しい枝を伸ばし、ときどき古い枝から分かれる */
  function growStep() {
    var idx, c, dirs;
    for (;;) {
      if (!active.length) return false;
      idx = Math.random() < 0.72 ? active.length - 1 : (Math.random() * active.length) | 0;
      c = active[idx]; dirs = [];
      for (var d = 0; d < 4; d++) { var m = nb(c, d); if (m >= 0 && born[m] < 0) dirs.push(d); }
      if (dirs.length) break;
      active.splice(idx, 1);
    }
    var dd = dirs[(Math.random() * dirs.length) | 0], n = nb(c, dd);
    open[c] |= BIT[dd]; open[n] |= OPP[dd];
    born[n] = clock; active.push(n); grownCount++; tree[n] = tree[c];
    return true;
  }

  /* 別々に育った枝どうしを、境目の壁を 1 枚ずつ抜いてつなぐ（迷路は 1 本の木のまま） */
  function joinTrees() {
    var par = {};
    function f(x) { while (par[x] != null && par[x] !== x) x = par[x]; return x; }
    var order = [];
    for (var c = 0; c < N; c++) order.push(c);
    for (var i = N - 1; i > 0; i--) { var j = (Math.random() * (i + 1)) | 0, t = order[i]; order[i] = order[j]; order[j] = t; }
    for (var q = 0; q < N; q++) {
      var a = order[q];
      for (var d = 1; d <= 2; d++) {
        var b = nb(a, d);
        if (b < 0) continue;
        var ra = f(tree[a]), rb = f(tree[b]);
        if (ra === rb) continue;
        par[ra] = rb; par[rb] = rb;
        open[a] |= BIT[d]; open[b] |= OPP[d];
      }
    }
  }
  function beginSearch() {
    phase = 'search'; phaseT = 0;
    var r1 = (Math.random() * ROWS) | 0, r2 = (Math.random() * ROWS) | 0;
    start = r1 * COLS + ((Math.random() * Math.max(1, COLS * 0.12)) | 0);
    goal = r2 * COLS + COLS - 1 - ((Math.random() * Math.max(1, COLS * 0.12)) | 0);
    seen.fill(0); seen[start] = 1;
    stack = [start]; deadTrail = [];
    head = { from: start, to: start, t: 1 };
    SFX.play('toggle');
  }
  function searchStep() {
    var c = stack[stack.length - 1];
    if (c === goal) { phase = 'found'; phaseT = 0; SFX.play('grow'); return; }
    var gx = goal % COLS, gy = (goal / COLS) | 0, best = -1, bs = 1e9;
    for (var d = 0; d < 4; d++) {
      if (!(open[c] & BIT[d])) continue;
      var m = nb(c, d);
      if (seen[m]) continue;
      /* 出口のほうへ少し引かれるが、迷いもする */
      var sc = Math.abs(m % COLS - gx) + Math.abs(((m / COLS) | 0) - gy) + Math.random() * 6;
      if (sc < bs) { bs = sc; best = m; }
    }
    if (best >= 0) {
      seen[best] = 1; stack.push(best);
      head = { from: c, to: best, t: 0, back: false };
    } else {
      stack.pop();
      var p = stack[stack.length - 1];
      deadTrail.push({ a: c, b: p, t: 0 });
      head = { from: c, to: p, t: 0, back: true };
      if (Math.random() < 0.3) SFX.play('tick', 500);
    }
  }

  /* 触ったセルから、通路の道のりで光を広げる */
  function glowFrom(x, y) {
    var c = Math.floor((y - OY) / CS) * COLS + Math.floor((x - OX) / CS);
    var gx = Math.floor((x - OX) / CS), gy = Math.floor((y - OY) / CS);
    if (gx < 0 || gy < 0 || gx >= COLS || gy >= ROWS) {
      gx = Math.max(0, Math.min(COLS - 1, gx)); gy = Math.max(0, Math.min(ROWS - 1, gy));
      c = gy * COLS + gx;
    }
    if (born[c] < 0) {
      /* まだ育っていない場所なら、そこから新しい枝が育ちはじめる */
      if (phase !== 'grow') return false;
      born[c] = clock; tree[c] = c; active.push(c); grownCount++;
      for (var bu = 0; bu < 45; bu++) growStep();
    }
    var dist = new Int16Array(N).fill(-1), q = [c];
    dist[c] = 0;
    for (var h = 0; h < q.length; h++) {
      var u = q[h];
      for (var d = 0; d < 4; d++) {
        if (!(open[u] & BIT[d])) continue;
        var v = nb(u, d);
        if (dist[v] < 0) { dist[v] = dist[u] + 1; q.push(v); }
      }
    }
    glows.push({ dist: dist, t: 0 });
    if (glows.length > 4) glows.shift();
    return true;
  }

  /* ---------------------------------------------------------- 描く */
  function draw(dt) {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: CS, major: 4, alpha: 0.55 });
    var fadeAll = phase === 'fade' ? Math.max(0, 1 - phaseT / 2.4) : 1;
    var i, c, a;

    /* 育った範囲は、うっすら明るい床になる */
    for (var fb = 0; fb < 3; fb++) {
      ctx.fillStyle = P.rgba(P.steel, (0.08 + fb * 0.08) * fadeAll);
      ctx.beginPath();
      for (c = 0; c < N; c++) {
        if (born[c] < 0) continue;
        var fa2 = Math.min(1, (clock - born[c]) / 0.8);
        if (Math.min(2, (fa2 * 3) | 0) !== fb) continue;
        ctx.rect(OX + (c % COLS) * CS, OY + ((c / COLS) | 0) * CS, CS, CS);
      }
      ctx.fill();
    }

    /* 触った光: 通路の中心線に沿って、前線だけが明るい */
    if (glows.length) {
      ctx.lineCap = 'round';
      ctx.lineWidth = Math.max(2, CS * 0.18);
      for (var g = glows.length - 1; g >= 0; g--) {
        var G = glows[g];
        G.t += dt;
        var front = 3 + G.t * 22, life = Math.max(0, 1 - G.t / 4);
        if (life <= 0) { glows.splice(g, 1); continue; }
        for (var b = 0; b < 4; b++) {
          /* 光の届いたセルは床ごと明るくなる */
          ctx.fillStyle = P.rgba(P.accent, (b + 1) / 4 * 0.3 * life * fadeAll);
          ctx.beginPath();
          for (c = 0; c < N; c++) {
            var dcf = G.dist[c];
            if (dcf < 0) continue;
            var kf = front - dcf;
            if (kf < 0 || kf > 10) continue;
            var lvf = kf < 1 ? kf : Math.exp(-(kf - 1) * 0.32);
            if (((lvf * 4) | 0) !== b) continue;
            ctx.rect(OX + (c % COLS) * CS + 1.5, OY + ((c / COLS) | 0) * CS + 1.5, CS - 3, CS - 3);
          }
          ctx.fill();
          ctx.strokeStyle = P.rgba(P.accent, (b + 1) / 4 * 0.7 * life * fadeAll);
          ctx.beginPath();
          for (c = 0; c < N; c++) {
            var dc = G.dist[c];
            if (dc < 0) continue;
            var k = front - dc;
            if (k < 0 || k > 10) continue;
            var lv = k < 1 ? k : Math.exp(-(k - 1) * 0.32);
            if (((lv * 4) | 0) !== b) continue;
            for (var d = 1; d <= 2; d++) {
              if (!(open[c] & BIT[d])) continue;
              var m = nb(c, d);
              if (G.dist[m] < 0) continue;
              ctx.moveTo(cx(c), cy(c)); ctx.lineTo(cx(m), cy(m));
            }
          }
          ctx.stroke();
        }
      }
    }

    /* 壁: 育ったセルのまわりだけ。育ったばかりの壁は薄く始まる */
    ctx.lineCap = 'square';
    ctx.lineWidth = 2;
    for (var bk = 0; bk < 3; bk++) {
      ctx.strokeStyle = P.rgba(P.line, (0.35 + bk * 0.22) * fadeAll);
      ctx.beginPath();
      for (c = 0; c < N; c++) {
        if (born[c] < 0) continue;
        a = Math.min(1, (clock - born[c]) / 0.8);
        if (Math.min(2, (a * 3) | 0) !== bk) continue;
        var x0 = OX + (c % COLS) * CS, y0 = OY + ((c / COLS) | 0) * CS;
        /* 上と左は自分で、右と下は隣が未成長か外のときだけ自分で引く */
        if (!(open[c] & 1)) { ctx.moveTo(x0, y0); ctx.lineTo(x0 + CS, y0); }
        if (!(open[c] & 8)) { ctx.moveTo(x0, y0); ctx.lineTo(x0, y0 + CS); }
        var r = nb(c, 1), dn = nb(c, 2);
        if (!(open[c] & 2) && (r < 0 || born[r] < 0)) { ctx.moveTo(x0 + CS, y0); ctx.lineTo(x0 + CS, y0 + CS); }
        if (!(open[c] & 4) && (dn < 0 || born[dn] < 0)) { ctx.moveTo(x0, y0 + CS); ctx.lineTo(x0 + CS, y0 + CS); }
      }
      ctx.stroke();
    }

    /* 育っている枝の先 */
    if (phase === 'grow') {
      ctx.fillStyle = P.rgba(P.line, 0.5);
      for (i = Math.max(0, active.length - 3); i < active.length; i++) {
        ctx.beginPath(); ctx.arc(cx(active[i]), cy(active[i]), 2, 0, Math.PI * 2); ctx.fill();
      }
    }

    if (phase === 'search' || phase === 'found' || phase === 'fade') {
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      /* 引き返した道は鋼色の細い線で残り、だんだん薄れる */
      ctx.lineWidth = 1.5;
      for (var s = 0; s < 3; s++) {
        ctx.strokeStyle = P.rgba(P.steel, (0.18 + s * 0.14) * fadeAll);
        ctx.beginPath();
        for (i = 0; i < deadTrail.length; i++) {
          var T = deadTrail[i];
          var lv2 = Math.max(0, 1 - T.t / 14);
          if (Math.min(2, (lv2 * 3) | 0) !== s) continue;
          ctx.moveTo(cx(T.a), cy(T.a)); ctx.lineTo(cx(T.b), cy(T.b));
        }
        ctx.stroke();
      }
      for (i = 0; i < deadTrail.length; i++) deadTrail[i].t += dt;

      /* いま辿っている道 */
      var glowK = phase === 'found' ? Math.min(1, phaseT / 1.5) : 0;
      ctx.lineWidth = 2 + glowK * 1.5;
      ctx.strokeStyle = P.rgba(P.line, (0.72 + glowK * 0.25) * fadeAll);
      ctx.beginPath();
      var last = stack.length - 1;
      var fwd = phase === 'search' && !head.back && head.t < 1 && stack[last] === head.to;
      if (fwd) last--;
      for (i = 0; i <= last; i++) {
        if (i === 0) ctx.moveTo(cx(stack[0]), cy(stack[0])); else ctx.lineTo(cx(stack[i]), cy(stack[i]));
      }
      var hx = cx(head.from) + (cx(head.to) - cx(head.from)) * head.t;
      var hy = cy(head.from) + (cy(head.to) - cy(head.from)) * head.t;
      if (phase === 'search' && head.t < 1) ctx.lineTo(hx, hy);
      ctx.stroke();

      /* 入口と出口 */
      ctx.lineWidth = 1.25;
      ctx.strokeStyle = P.rgba(P.line, 0.7 * fadeAll);
      ctx.beginPath(); ctx.arc(cx(goal), cy(goal), CS * 0.3, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = P.rgba(P.line, 0.6 * fadeAll);
      ctx.beginPath(); ctx.arc(cx(start), cy(start), 2.5, 0, Math.PI * 2); ctx.fill();

      /* 光: 画面にひとつだけの焦点 */
      if (phase === 'search') {
        var hg = ctx.createRadialGradient(hx, hy, 0, hx, hy, CS * 1.4);
        hg.addColorStop(0, P.rgba(P.accent, 0.45));
        hg.addColorStop(1, P.rgba(P.accent, 0));
        ctx.fillStyle = hg;
        ctx.beginPath(); ctx.arc(hx, hy, CS * 1.4, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = P.rgba(P.line, 0.95);
        ctx.beginPath(); ctx.arc(hx, hy, 3, 0, Math.PI * 2); ctx.fill();
      }
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    if (glowFrom(last.x, last.y)) SFX.play('ripple');
    else SFX.play('tick');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (Math.hypot(p.x - last.x, p.y - last.y) < CS * 3) return;
    last = p;
    if (glowFrom(p.x, p.y)) SFX.play('move', 260);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kp = document.getElementById('k-pace'), kc = document.getElementById('k-cell');
  kp.addEventListener('input', function () { pace = this.value / 100; });
  kc.addEventListener('change', function () { cellK = this.value / 100; build(); });
  kc.addEventListener('input', function () { cellK = this.value / 100; });
  pace = kp.value / 100; cellK = kc.value / 100;
  document.getElementById('k-new').addEventListener('click', function () { build(); SFX.play('click'); });

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), growAcc = 0, moveAcc = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now; clock += dt; phaseT += dt;
    if (phase === 'grow') {
      if (preGrow) { preGrow = false; for (var pg = 0; pg < N * 0.45; pg++) growStep(); }
      /* 12〜26 秒で画面いっぱいに育つ */
      growAcc += dt * N / (26 - pace * 14);
      while (growAcc > 1) { growAcc -= 1; if (!growStep()) break; }
      if (!active.length) { joinTrees(); phase = 'rest'; phaseT = 0; }
      if (Math.random() < dt * 0.4) SFX.play('grow', 2500);
    } else if (phase === 'rest') {
      if (phaseT > 1.2) beginSearch();
    } else if (phase === 'search') {
      var sp = 6 + pace * 14;
      head.t += dt * sp;
      if (head.t >= 1) { head.t = 1; searchStep(); }
    } else if (phase === 'found') {
      if (phaseT > 6) { phase = 'fade'; phaseT = 0; }
    } else if (phase === 'fade') {
      if (phaseT > 2.6) build();
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
