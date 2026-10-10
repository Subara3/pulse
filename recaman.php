<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('recaman');

$pageName = 'recaman';
$pageTitle = 'RECAMÁN - PULSE';
$pageDesc = '戻れるときは戻り、戻れないときは進む。この決まりで、一本の線の上に半円を重ねていく。押した位置からは大きな半円の道がもう一本伸び、なぞると指が通った弧が光る。';
$pageCanonical = 'https://pulse.subara3.com/recaman.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="line">LINE</button><button type="button" data-look="ring">RING</button></div>
  <div class="ctl-btns"><button type="button" id="k-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; });

  var NMAX = 420;          /* 1 本の道はここまで描いたら薄れて描き直す */
  var MEMORY = 160;        /* 古い弧がどれくらいの手数で薄れるか */
  var look = 'line', morph = 0;   /* 0 = 直線, 1 = 輪 */
  var pace = 1.6;          /* 1 秒あたりの手数 */
  var walks = [];
  var STARTS = [0, 0, 7, 20, 3, 41, 12];
  var cycle = 0;

  /* 引けるなら引く、引けなければ足す */
  function Walk(a0, main, mult) {
    this.m = mult || 1;        /* 歩幅の倍率。触って始めた道は大きな歩幅で描く */
    this.vals = [a0];
    this.seen = {}; this.seen[a0] = 1;
    this.n = 0;            /* 描き終えた弧の数 */
    this.prog = 0;         /* 描きかけの弧の進み 0..1 */
    this.alpha = 0;        /* 全体の濃さ（出入りでゆっくり変わる） */
    this.fade = false;
    this.main = main;
    this.max = a0;
    this.next();
  }
  Walk.prototype.next = function () {
    var k = this.vals.length, a = this.vals[k - 1];
    var b = a - k * this.m;
    if (b < 0 || this.seen[b]) b = a + k * this.m;
    this.seen[b] = 1;
    this.vals.push(b);
    if (b > this.max) this.max = b;
  };
  Walk.prototype.advance = function (steps) {
    this.prog += steps;
    while (this.prog >= 1) {
      this.prog -= 1; this.n++;
      if (this.vals.length <= this.n + 1) this.next();
    }
    while (this.vals.length <= this.n + 1) this.next();
  };

  function reset() {
    walks.length = 0;
    var w = new Walk(STARTS[cycle % STARTS.length], true);
    for (var i = 0; i < 70; i++) w.advance(1);
    walks.push(w);
    view.span = view.tSpan = Math.max(20, w.max * 1.1) * 1.4;
    view.step = view.tStep = Math.max(8, w.n + 1) * 1.4;
  }

  /* ---------------------------------------------------------- 見え方 */
  var view = { span: 40, step: 20, tSpan: 40, tStep: 20 };   /* 横幅に入れる値の幅 / いちばん大きい弧の幅 */
  var X0 = 0, S = 1, Y0 = 0, VY = 1;
  function frameView(dt) {
    var span = 10, step = 6;
    for (var i = 0; i < walks.length; i++) {
      var w = walks[i];
      if (!w.main) continue;            /* 触って始めた道は縮尺の計算に入れない */
      var top = w.vals[w.n + 1] > w.max ? w.vals[w.n + 1] : w.max;
      span = Math.max(span, top * 1.06 + 2);
      step = Math.max(step, w.n + 1);
    }
    /* 広げるときは早めに、縮めるときはゆっくり */
    /* 引きは段階的に: はみ出しそうになったときだけ、少し余裕をもって一度に引く。
       それ以外のあいだは画面の縮尺を止めておく */
    if (span > view.tSpan * 0.97 || span < view.tSpan / 2.4) view.tSpan = span * 1.3;
    if (step > view.tStep * 0.97 || step < view.tStep / 2.4) view.tStep = step * 1.3;
    view.span += (view.tSpan - view.span) * Math.min(1, dt * 3);
    view.step += (view.tStep - view.step) * Math.min(1, dt * 3);
    S = Math.min((W * 0.94) / view.span, (H * 0.94) / view.step);
    X0 = (W - view.span * S) / 2;
    /* 横長の画面では弧を縦に少し伸ばし、上下も埋める */
    VY = Math.max(1, Math.min(1.45, (H * 0.45) / (view.step / 2 * S)));
    Y0 = H / 2;
  }

  /* 直線上の点（値 v, 高さ h）を画面へ。輪のときは数直線を円に巻く */
  var out = [0, 0];
  function map(v, h) {
    if (morph < 0.001) { out[0] = X0 + v * S; out[1] = Y0 - h * S * VY; return out; }
    var R0 = Math.min(W, H) * 0.29;
    var ang = -Math.PI / 2 + (v / view.span) * Math.PI * 2;
    /* 上の弧は輪の外へ、下の弧は内へふくらむ。ふくらみは輪の幅に収める */
    var hh = h / view.span * 3.4, th = (Math.exp(2 * hh) - 1) / (Math.exp(2 * hh) + 1);
    var rr = R0 * (1 + 0.62 * th);
    var rx = W / 2 + Math.cos(ang) * rr, ry = H / 2 + Math.sin(ang) * rr;
    var lx = X0 + v * S, ly = Y0 - h * S * VY;
    var e = morph * morph * (3 - 2 * morph);
    out[0] = lx + (rx - lx) * e; out[1] = ly + (ry - ly) * e;
    return out;
  }

  /* 弧 k（vals[k] から vals[k+1] へ）を u0..u1 だけ描く */
  function arcPath(p, w, k, u1) {
    var a = w.vals[k], b = w.vals[k + 1];
    var side = (k % 2 === 0) ? 1 : -1;
    var c = (a + b) / 2, r = Math.abs(b - a) / 2, dir = b > a ? 1 : -1;
    var seg = Math.max(8, Math.min(140, Math.ceil(r * S / 2.2)));
    var m = Math.max(1, Math.ceil(seg * u1));
    for (var i = 0; i <= m; i++) {
      var phi = Math.PI * Math.min(u1, i / seg);
      var q = map(c - dir * r * Math.cos(phi), side * r * Math.sin(phi));
      if (i === 0) p.moveTo(q[0], q[1]); else p.lineTo(q[0], q[1]);
    }
    return out;
  }

  var LN = P.line, AC = P.accent, ST = P.steel;
  var BUCKETS = 6;
  function drawWalk(w) {
    if (w.alpha <= 0.002) return;
    var paths = [];
    for (var q = 0; q < BUCKETS; q++) paths.push(new Path2D());
    var near = [];
    for (var k = 0; k < w.n; k++) {
      var age = (w.n - k + w.prog) / MEMORY;
      var a = 0.1 + 0.9 * Math.exp(-age * 1.6);
      var bi = Math.min(BUCKETS - 1, Math.floor(a * BUCKETS));
      arcPath(paths[bi], w, k, 1);
      if (glowArcs[k + ':' + walks.indexOf(w)]) near.push(k);
    }
    ctx.lineWidth = 1.6;
    for (var q2 = 0; q2 < BUCKETS; q2++) {
      ctx.strokeStyle = P.rgba(LN, (0.36 + 0.6 * (q2 + 0.5) / BUCKETS) * w.alpha);
      ctx.stroke(paths[q2]);
    }
    /* 描きかけの弧と、その先端 */
    var cur = new Path2D();
    arcPath(cur, w, w.n, w.prog);
    var tip = [out[0], out[1]];
    ctx.lineWidth = 2;
    ctx.strokeStyle = P.rgba(LN, 0.95 * w.alpha);
    ctx.stroke(cur);
    /* 触った弧は太く光る（ふちに淡い光、芯は白に近いシアン） */
    if (near.length) {
      var hp = new Path2D(), lv = 0;
      for (var j = 0; j < near.length; j++) { arcPath(hp, w, near[j], 1); lv = Math.max(lv, glowT[near[j] + ':' + walks.indexOf(w)] || 0); }
      var g = Math.min(1, lv / 0.8);
      ctx.lineCap = 'round';
      ctx.lineWidth = 9;
      ctx.strokeStyle = P.rgba(AC, 0.16 * g * w.alpha);
      ctx.stroke(hp);
      ctx.lineWidth = 2.6;
      ctx.strokeStyle = P.rgba(P.mix(AC, LN, 0.55), 0.95 * g * w.alpha);
      ctx.stroke(hp);
      ctx.lineCap = 'butt';
    }
    ctx.fillStyle = P.rgba(w.main ? LN : AC, w.alpha);
    ctx.beginPath(); ctx.arc(tip[0], tip[1], 2.2, 0, Math.PI * 2); ctx.fill();
  }

  function drawAxis() {
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(ST, 0.35);
    ctx.beginPath();
    var n = 80;
    for (var i = 0; i <= n; i++) {
      var q = map(view.span * i / n, 0);
      if (i === 0) ctx.moveTo(q[0], q[1]); else ctx.lineTo(q[0], q[1]);
    }
    ctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  var glowArcs = {}, glowT = {};
  var hold = false, ptr = { x: 0, y: 0 };
  /* 画面の点から値を逆算（直線と輪で別） */
  function valueAt(x, y) {
    if (morph > 0.5) {
      var ang = Math.atan2(y - H / 2, x - W / 2) + Math.PI / 2;
      if (ang < 0) ang += Math.PI * 2;
      return ang / (Math.PI * 2) * view.span;
    }
    return (x - X0) / S;
  }
  function touchArcs(x, y, reach) {
    /* 指の近くを通る弧を探して、しばらく灯す */
    var hit = 0;
    for (var wi = 0; wi < walks.length; wi++) {
      var w = walks[wi];
      for (var k = 0; k < w.n; k++) {
        var a = w.vals[k], b = w.vals[k + 1], side = (k % 2 === 0) ? 1 : -1;
        var c = (a + b) / 2, r = Math.abs(b - a) / 2, on = false;
        if (morph < 0.001) {
          var dx = x - (X0 + c * S), dy = Y0 - y;
          on = dy * side >= -reach && Math.abs(Math.hypot(dx, dy / VY) - r * S) < reach;
        } else {
          for (var s2 = 0; s2 <= 24 && !on; s2++) {
            var q = map(c - r * Math.cos(Math.PI * s2 / 24), side * r * Math.sin(Math.PI * s2 / 24));
            on = Math.abs(q[0] - x) < reach && Math.abs(q[1] - y) < reach;
          }
        }
        if (on) { glowT[k + ':' + wi] = 1.8; hit++; }
      }
    }
    return hit;
  }
  canvas.addEventListener('pointerdown', function (e) {
    hold = true; ptr.x = e.clientX; ptr.y = e.clientY;
    var v = Math.max(0, Math.round(valueAt(ptr.x, ptr.y)));
    /* もう一本の道をそこから始める。多すぎたら古いものから薄れる */
    var side = walks.filter(function (w) { return !w.main && !w.fade; });
    if (side.length >= 2) side[0].fade = true;
    walks.push(new Walk(v, false, Math.max(1, Math.round(56 / S))));
    touchArcs(ptr.x, ptr.y, 46);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!hold) return;
    ptr.x = e.clientX; ptr.y = e.clientY;
    touchArcs(ptr.x, ptr.y, 30);
    SFX.play('move', 260);
  });
  function up() { hold = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var paceEl = document.getElementById('k-pace');
  function readPace() { pace = 0.5 + paceEl.value / 100 * 4.5; }
  paceEl.addEventListener('input', readPace);
  readPace();
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-reset').addEventListener('click', function () {
    cycle++; reset(); SFX.play('click');
  });

  /* ---------------------------------------------------------- 回す */
  reset();
  walks[0].alpha = 1;
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    morph += ((look === 'ring' ? 1 : 0) - morph) * Math.min(1, dt * 1.5);
    if (Math.abs(morph - (look === 'ring' ? 1 : 0)) < 0.001) morph = look === 'ring' ? 1 : 0;

    for (var i = walks.length - 1; i >= 0; i--) {
      var w = walks[i];
      /* 大きな弧ほどゆっくり描く */
      var r = Math.abs(w.vals[w.n + 1] - w.vals[w.n]);
      /* 触って始めた道は、最初の数手を速く描いてすぐ形が出るようにする */
      var boost = (!w.main && w.n < 4) ? 6 : (!w.main ? 2 : 1);
      w.advance(dt * pace * Math.min(1.6, 6 / Math.sqrt(r / w.m + 4)) * 1.4 * boost);
      if (w.n >= NMAX || (!w.main && w.n >= 13)) w.fade = true;
      w.alpha += ((w.fade ? 0 : 1) - w.alpha) * Math.min(1, dt * (w.fade ? 0.5 : (w.main ? 1.2 : 12)));
      if (w.fade && w.alpha < 0.01) {
        walks.splice(i, 1);
        if (w.main) { cycle++; var nw = new Walk(STARTS[cycle % STARTS.length], true); nw.advance(3); walks.push(nw); }
      }
    }
    if (!walks.some(function (w) { return w.main; })) { var m = new Walk(0, true); walks.push(m); }

    glowArcs = {};
    for (var key in glowT) {
      glowT[key] -= dt;
      if (glowT[key] <= 0) delete glowT[key]; else glowArcs[key] = 1;
    }

    frameView(dt);
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.6 });
    drawAxis();
    for (var j = 0; j < walks.length; j++) drawWalk(walks[j]);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
