<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('life');

$pageName = 'life';
$pageTitle = 'LIFE - PULSE';
$pageDesc = '方眼の上で、隣り合う点どうしを線で結んだ形が、生まれたり消えたりしながら変わっていく。触ったところに点がばらまかれ、そこから形が増えていく。';
$pageCanonical = 'https://pulse.subara3.com/life.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-rule="life">LIFE</button><button type="button" data-rule="high">HIGH</button><button type="button" data-rule="maze">MAZE</button></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* ライフゲーム。生きているマスを点ではなく「節」として描き、
     隣り合う生きた節どうしを細い線で結ぶ。世代と世代のあいだは線の濃さで溶かして見せる */
  var NX, NY, S, ox, oy, cur, nxt, prevA, age, mark, trace;
  var RULES = {
    life: { b: [3], s: [2, 3] },
    high: { b: [3, 6], s: [2, 3] },
    maze: { b: [3], s: [1, 2, 3, 4] }
  };
  var rule = 'life', born = [], keep = [], pace = 0.4;
  function setRule(name) {
    rule = name; born = []; keep = [];
    for (var n = 0; n <= 8; n++) { born[n] = RULES[name].b.indexOf(n) >= 0 ? 1 : 0; keep[n] = RULES[name].s.indexOf(n) >= 0 ? 1 : 0; }
  }
  setRule('life');

  function alloc() {
    S = Math.max(13, Math.min(22, Math.round(Math.min(W, H) / 42)));
    NX = Math.ceil(W / S) + 1; NY = Math.ceil(H / S) + 1;
    ox = 0.5; oy = 0.5; /* 方眼（P.grid）の交点にそろえる */
    var n = NX * NY;
    cur = new Uint8Array(n); nxt = new Uint8Array(n); prevA = new Uint8Array(n);
    age = new Float32Array(n); mark = new Float32Array(n); trace = new Float32Array(n);
    for (var i = 0; i < 7; i++) soup(Math.random() * NX, Math.random() * NY, 6 + Math.random() * 5, 0.38, false);
    for (var g = 0; g < 30; g++) gen();
  }

  /* 小さな円の中にでたらめに点をまく */
  function soup(cx, cy, r, p, touched) {
    for (var j = Math.floor(cy - r); j <= cy + r; j++) {
      for (var i = Math.floor(cx - r); i <= cx + r; i++) {
        if (Math.hypot(i - cx, j - cy) > r) continue;
        var k = ((j % NY + NY) % NY) * NX + (i % NX + NX) % NX;
        if (Math.random() < p) cur[k] = 1;
        if (touched) mark[k] = 1;
      }
    }
  }

  var pop = 0;
  function gen() {
    prevA.set(cur);
    pop = 0;
    for (var j = 0; j < NY; j++) {
      var up = ((j + NY - 1) % NY) * NX, dn = ((j + 1) % NY) * NX, row = j * NX;
      for (var i = 0; i < NX; i++) {
        var l = i === 0 ? NX - 1 : i - 1, r = i === NX - 1 ? 0 : i + 1;
        var c = cur[up + l] + cur[up + i] + cur[up + r] + cur[row + l] + cur[row + r] + cur[dn + l] + cur[dn + i] + cur[dn + r];
        var k = row + i, v = cur[k] ? keep[c] : born[c];
        nxt[k] = v;
        if (v) { pop++; age[k] = cur[k] ? age[k] + 1 : 0; trace[k] = 1; }
        else if (trace[k] > 0) trace[k] *= 0.93;
      }
    }
    var t = cur; cur = nxt; nxt = t;
  }

  /* ---------------------------------------------------------- 描く */
  /* f: 世代の途中の進み具合 0→1。生まれる節は f で現れ、消える節は f で消える */
  function alpha(k, f) {
    var a = prevA[k], b = cur[k];
    if (a && b) return 1;
    if (b) return f;
    if (a) return 1 - f;
    return 0;
  }
  function draw(f) {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: S, major: 5, alpha: 0.7 });
    var BK = 4, lines = [], hotLines = [], b;
    for (b = 0; b < BK; b++) { lines.push([]); hotLines.push([]); }
    var nodes = [], hotNodes = [], ghosts = [];
    /* 右・下・右下・左下の 4 方向だけ見れば、全部の辺を 1 回ずつ拾える */
    var DI = [1, 0, 1, -1], DJ = [0, 1, 1, 1];
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var k = j * NX + i, a = alpha(k, f);
        var x = ox + i * S, y = oy + j * S;
        if (a <= 0) {
          /* しばらく前に生きていた所は、鉛筆の跡のような小さな点で残す */
          if (trace[k] > 0.08) ghosts.push(x, y, trace[k]);
          continue;
        }
        var hot = mark[k] > 0.15;
        (hot ? hotNodes : nodes).push(x, y, a);
        for (var d = 0; d < 4; d++) {
          var ii = i + DI[d], jj = j + DJ[d];
          if (ii < 0 || ii >= NX || jj >= NY) continue;
          var a2 = alpha(jj * NX + ii, f);
          if (a2 <= 0) continue;
          var e = Math.min(a, a2);
          if (d >= 2) e *= 0.6;
          b = Math.min(BK - 1, (e * BK) | 0);
          (hot && mark[jj * NX + ii] > 0.15 ? hotLines : lines)[b].push(x, y, ox + ii * S, oy + jj * S);
        }
      }
    }
    ctx.lineCap = 'round';
    for (b = 0; b < BK; b++) {
      var la = (b + 1) / BK;
      strokeList(lines[b], P.rgba(P.line, 0.75 * la), 1);
      strokeList(hotLines[b], P.rgba(P.accent, 0.85 * la), 1.1);
    }
    ctx.fillStyle = P.rgba(P.steel, 0.5);
    ctx.beginPath();
    for (var g = 0; g < ghosts.length; g += 3) { var gr = 0.4 + ghosts[g + 2] * 0.6; ctx.rect(ghosts[g] - gr, ghosts[g + 1] - gr, gr * 2, gr * 2); }
    ctx.fill();
    dotList(nodes, P.line);
    dotList(hotNodes, P.accent);
  }
  function strokeList(list, style, w) {
    if (!list.length) return;
    ctx.strokeStyle = style; ctx.lineWidth = w;
    ctx.beginPath();
    for (var i = 0; i < list.length; i += 4) { ctx.moveTo(list[i], list[i + 1]); ctx.lineTo(list[i + 2], list[i + 3]); }
    ctx.stroke();
  }
  /* 節は小さな輪。生まれかけ・消えかけは輪が小さい */
  function dotList(list, c) {
    if (!list.length) return;
    ctx.fillStyle = P.rgba(c, 0.95);
    ctx.beginPath();
    for (var i = 0; i < list.length; i += 3) {
      var r = 0.6 + 1.3 * list[i + 2];
      ctx.moveTo(list[i] + r, list[i + 1]);
      ctx.arc(list[i], list[i + 1], r, 0, Math.PI * 2);
    }
    ctx.fill();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  function cellOf(e) { return { x: (e.clientX - ox) / S, y: (e.clientY - oy) / S, px: e.clientX, py: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = cellOf(e);
    soup(last.x, last.y, 4.5, 0.45, true);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = cellOf(e), dist = Math.hypot(p.px - last.px, p.py - last.py);
    if (dist < S * 2) return;
    var n = Math.ceil(dist / (S * 2));
    for (var i = 1; i <= n; i++) soup(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, 2, 0.5, true);
    last = p;
    SFX.play('tick', 150);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  document.querySelectorAll('[data-rule]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-rule]').forEach(function (o) { o.classList.toggle('active', o === b); });
      setRule(b.dataset.rule);
      SFX.play('toggle');
    });
  });
  document.getElementById('k-clear').addEventListener('click', function () {
    cur.fill(0); prevA.fill(0); mark.fill(0); trace.fill(0);
    soup(NX / 2, NY / 2, 6, 0.4, false);
    SFX.play('click');
  });
  pace = document.getElementById('k-pace').value / 100;

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), phase = 0, seedT = 0, quiet = 0, lastPop = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    phase += dt * (2 + pace * 8);
    while (phase >= 1) {
      phase -= 1;
      gen();
      /* 動きが止まりかけたら（数がほとんど変わらない）、どこかに点をまく */
      quiet = Math.abs(pop - lastPop) < 3 ? quiet + 1 : 0;
      lastPop = pop;
    }
    for (var k = 0; k < mark.length; k++) if (mark[k] > 0) mark[k] *= 0.985;
    seedT += dt;
    var full = NX * NY;
    if (seedT > 5 || (quiet > 12 && seedT > 2) || pop < full * 0.02) {
      seedT = 0; quiet = 0;
      soup(Math.random() * NX, Math.random() * NY, 4 + Math.random() * 4, 0.4, false);
    }
    /* 増えすぎたら、ときどき 1 か所を空ける（画面が線で埋まらないように） */
    if (pop > full * 0.22 && Math.random() < dt * 0.5) {
      var cx = Math.random() * NX, cy = Math.random() * NY, r = 8;
      for (var j = Math.floor(cy - r); j <= cy + r; j++) for (var i = Math.floor(cx - r); i <= cx + r; i++) {
        if (Math.hypot(i - cx, j - cy) < r) cur[((j % NY + NY) % NY) * NX + (i % NX + NX) % NX] = 0;
      }
    }
    draw(phase < 0.5 ? phase * 2 : 1);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
