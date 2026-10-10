<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('billiard');

$pageName = 'billiard';
$pageTitle = 'BILLIARD - PULSE';
$pageDesc = '角の丸い長方形の台の中を、玉が壁で跳ね返りながら走り続け、通った跡の直線が重なって台を埋めていく。押したところからは玉が全方向へ一斉に走り出し、なぞるとその向きへ玉が流れ出す。台を楕円や円に替えると、跡の通る範囲が決まった帯に限られる。';
$pageCanonical = 'https://pulse.subara3.com/billiard.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-balls">BALLS</label><input type="range" id="k-balls" min="1" max="6" value="5"></div>
  <div class="ctl-btns"><button type="button" class="active" data-shape="stadium">STADIUM</button><button type="button" data-shape="ellipse">ELLIPSE</button><button type="button" data-shape="circle">CIRCLE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, S = 100;
  /* 縦長の画面では台を縦向きに置く（描くときに 90 度回し、触った位置も同じだけ戻す） */
  var tall = false;
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; tall = h > w;
    S = tall ? Math.min(w * 0.9 / 2, h * 0.8 / 4) : Math.min(w * 0.84 / 4, h * 0.78 / 2);
  });

  /* 台は幅 4・高さ 2 の箱に収める。STADIUM は半円 2 つを直線でつないだ形 */
  var shape = 'stadium';
  var SA = 1, SR = 1, EA = 2, EB = 1, CR = 1;
  var EPS = 1e-7;

  /* 点 (x,y) から向き (dx,dy) に進んで最初に当たる壁。[t, nx, ny] を返す */
  var HIT = [0, 0, 0];
  function hitConic(x, y, dx, dy, a, b, cx) {
    var X = x - cx;
    var A = dx * dx / (a * a) + dy * dy / (b * b);
    var B = 2 * (X * dx / (a * a) + y * dy / (b * b));
    var C = X * X / (a * a) + y * y / (b * b) - 1;
    var D = B * B - 4 * A * C;
    if (D < 0) return -1;
    var sq = Math.sqrt(D);
    var t = (-B + sq) / (2 * A);
    return t;
  }
  function hit(x, y, dx, dy) {
    var best = 1e9, nx = 0, ny = 0, t, hx;
    if (shape === 'ellipse' || shape === 'circle') {
      var a = shape === 'ellipse' ? EA : CR, b = shape === 'ellipse' ? EB : CR;
      t = hitConic(x, y, dx, dy, a, b, 0);
      var px = x + dx * t, py = y + dy * t;
      nx = px / (a * a); ny = py / (b * b);
      var l = Math.hypot(nx, ny); HIT[0] = t; HIT[1] = nx / l; HIT[2] = ny / l;
      return HIT;
    }
    /* 上下の直線 */
    if (dy > EPS) { t = (SR - y) / dy; hx = x + dx * t; if (t > EPS && hx >= -SA && hx <= SA && t < best) { best = t; nx = 0; ny = 1; } }
    if (dy < -EPS) { t = (-SR - y) / dy; hx = x + dx * t; if (t > EPS && hx >= -SA && hx <= SA && t < best) { best = t; nx = 0; ny = -1; } }
    /* 左右の半円 */
    for (var side = -1; side <= 1; side += 2) {
      var cx = side * SA;
      var X = x - cx, B2 = X * dx + y * dy, C2 = X * X + y * y - SR * SR, D2 = B2 * B2 - C2;
      if (D2 < 0) continue;
      var s2 = Math.sqrt(D2);
      var cands = [-B2 + s2, -B2 - s2];
      for (var k = 0; k < 2; k++) {
        t = cands[k];
        if (t <= EPS || t >= best) continue;
        hx = x + dx * t;
        if (side > 0 ? hx < SA - 1e-9 : hx > -SA + 1e-9) continue;
        best = t; nx = (hx - cx) / SR; ny = (y + dy * t) / SR;
      }
    }
    HIT[0] = best; HIT[1] = nx; HIT[2] = ny;
    return HIT;
  }
  function inside(x, y) {
    if (shape === 'ellipse') return x * x / (EA * EA) + y * y / (EB * EB) < 1;
    if (shape === 'circle') return x * x + y * y < CR * CR;
    if (Math.abs(x) <= SA) return Math.abs(y) < SR;
    var cx = x > 0 ? SA : -SA;
    return (x - cx) * (x - cx) + y * y < SR * SR;
  }

  /* ---------------------------------------------------------- 玉 */
  var MAXB = 240;
  var balls = [];
  function Ball(x, y, ang, mine) {
    this.bx = new Float32Array(MAXB); this.by = new Float32Array(MAXB);
    this.n = 0; this.head = 0;
    this.x = x; this.y = y; this.dx = Math.cos(ang); this.dy = Math.sin(ang);
    this.left = 0; this.hot = mine ? 1 : 0; this.mine = mine;
    this.push(x, y);
    this.aim();
  }
  Ball.prototype.push = function (x, y) {
    this.bx[this.head] = x; this.by[this.head] = y;
    this.head = (this.head + 1) % MAXB; if (this.n < MAXB) this.n++;
  };
  Ball.prototype.aim = function () {
    var h = hit(this.x, this.y, this.dx, this.dy);
    this.left = h[0]; this.nx = h[1]; this.ny = h[2];
    /* 数値誤差で台の外へ抜けたら、中心寄りに戻す */
    if (!(this.left > 0 && this.left < 10)) {
      this.x *= 0.5; this.y *= 0.5;
      h = hit(this.x, this.y, this.dx, this.dy);
      this.left = h[0]; this.nx = h[1]; this.ny = h[2];
    }
  };
  /* 距離 s だけ進める。壁に当たったら跳ね返る */
  Ball.prototype.go = function (s) {
    var bounced = false, guard = 0;
    while (s > 0 && guard++ < 50) {
      if (s < this.left) {
        this.x += this.dx * s; this.y += this.dy * s; this.left -= s; s = 0;
      } else {
        this.x += this.dx * this.left; this.y += this.dy * this.left; s -= this.left;
        var d = this.dx * this.nx + this.dy * this.ny;
        this.dx -= 2 * d * this.nx; this.dy -= 2 * d * this.ny;
        var l = Math.hypot(this.dx, this.dy); this.dx /= l; this.dy /= l;
        this.push(this.x, this.y);
        this.aim();
        bounced = true;
      }
    }
    return bounced;
  };
  function randomBall(mine) {
    var x, y;
    do { x = (Math.random() * 2 - 1) * 2; y = (Math.random() * 2 - 1); } while (!inside(x * 0.9, y * 0.9));
    var b = new Ball(x * 0.9, y * 0.9, Math.random() * Math.PI * 2, mine);
    /* すでに長く走ったところから見せる */
    b.go(MAXB * 2.2);
    return b;
  }
  var count = 3;
  /* 古い玉は消さずに、網ごとゆっくり薄れさせる */
  var dying = [];
  function retire() {
    while (balls.length > count) { var o = balls.shift(); o.fade = 1; dying.push(o); }
  }
  function refill() {
    while (balls.length < count) balls.push(randomBall(false));
    retire();
  }

  /* ---------------------------------------------------------- 描く */
  function X(x) { return x * S; }
  function Y(y) { return y * S; }
  function drawTable(alpha) {
    ctx.strokeStyle = P.rgba(P.line, 0.55 * alpha);
    ctx.lineWidth = 1.3;
    ctx.beginPath();
    if (shape === 'ellipse') ctx.ellipse(X(0), Y(0), EA * S, EB * S, 0, 0, Math.PI * 2);
    else if (shape === 'circle') ctx.arc(X(0), Y(0), CR * S, 0, Math.PI * 2);
    else {
      ctx.moveTo(X(-SA), Y(-SR)); ctx.lineTo(X(SA), Y(-SR));
      ctx.arc(X(SA), Y(0), SR * S, -Math.PI / 2, Math.PI / 2);
      ctx.lineTo(X(-SA), Y(SR));
      ctx.arc(X(-SA), Y(0), SR * S, Math.PI / 2, Math.PI * 1.5);
    }
    ctx.stroke();
    /* 外側にもう 1 本、ごく薄く */
    ctx.strokeStyle = P.rgba(P.steel, 0.18 * alpha);
    ctx.lineWidth = 1;
    ctx.beginPath();
    var g = 10 / S;
    if (shape === 'ellipse') ctx.ellipse(X(0), Y(0), (EA + g) * S, (EB + g) * S, 0, 0, Math.PI * 2);
    else if (shape === 'circle') ctx.arc(X(0), Y(0), (CR + g) * S, 0, Math.PI * 2);
    else {
      ctx.moveTo(X(-SA), Y(-SR - g)); ctx.lineTo(X(SA), Y(-SR - g));
      ctx.arc(X(SA), Y(0), (SR + g) * S, -Math.PI / 2, Math.PI / 2);
      ctx.lineTo(X(-SA), Y(SR + g));
      ctx.arc(X(-SA), Y(0), (SR + g) * S, Math.PI / 2, Math.PI * 1.5);
    }
    ctx.stroke();
  }
  var GROUPS = 8;
  function drawBall(b, alpha) {
    var col = P.mix(P.line, P.accent, b.hot);
    var n = b.n, per = Math.ceil(n / GROUPS);
    ctx.lineWidth = 1 + b.hot * 0.8;
    for (var g = 0; g < GROUPS; g++) {
      var s0 = g * per, s1 = Math.min(n - 1, s0 + per);
      if (s0 >= n - 1) break;
      var age = (g + 1) / GROUPS;
      ctx.strokeStyle = P.rgba(col, (0.1 + age * age * 0.45) * alpha);
      ctx.beginPath();
      for (var s = s0; s <= s1; s++) {
        var i = (b.head - n + s + MAXB) % MAXB;
        if (s === s0) ctx.moveTo(X(b.bx[i]), Y(b.by[i])); else ctx.lineTo(X(b.bx[i]), Y(b.by[i]));
      }
      if (s1 === n - 1) ctx.lineTo(X(b.x), Y(b.y));
      ctx.stroke();
    }
    /* 最後の跳ね返りから今の位置まで */
    var li = (b.head - 1 + MAXB) % MAXB;
    ctx.strokeStyle = P.rgba(col, (0.7 + b.hot * 0.25) * alpha);
    ctx.lineWidth = 1.2 + b.hot * 1.4;
    ctx.beginPath(); ctx.moveTo(X(b.bx[li]), Y(b.by[li])); ctx.lineTo(X(b.x), Y(b.y)); ctx.stroke();
    ctx.fillStyle = P.rgba(col, alpha);
    ctx.beginPath(); ctx.arc(X(b.x), Y(b.y), 3.2 + b.hot * 1.6, 0, Math.PI * 2); ctx.fill();
  }

  /* ---------------------------------------------------------- 操作 */
  var downP = null, lastP = null, sparks = [];
  function world(e) {
    var dx = (e.clientX - W / 2) / S, dy = (e.clientY - H / 2) / S;
    return tall ? [dy, -dx] : [dx, dy];
  }
  function pull(p) {
    var k = 1, g = 0;
    while (!inside(p[0] * k, p[1] * k) && g++ < 60) k *= 0.96;
    return [p[0] * k * 0.995, p[1] * k * 0.995];
  }
  function spark(p, ang) {
    var b = new Ball(p[0], p[1], ang, true);
    b.life = 6; b.fadeRate = 0.3;
    sparks.push(b);
    while (sparks.length > 80) { var o = sparks.shift(); o.fade = 1; dying.push(o); }
  }
  /* 押すと、その点から玉が全方向へ一斉に走り出す */
  canvas.addEventListener('pointerdown', function (e) {
    var p = pull(world(e));
    downP = p; lastP = p;
    var a0 = Math.random() * Math.PI * 2;
    for (var i = 0; i < 18; i++) spark(p, a0 + i / 18 * Math.PI * 2);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  /* なぞると、なぞった向きへ玉が少しずつ広がりながら流れ出す */
  canvas.addEventListener('pointermove', function (e) {
    if (!downP) return;
    var p = world(e), dx = p[0] - lastP[0], dy = p[1] - lastP[1];
    if (Math.hypot(dx, dy) * S < 16) return;
    var q = pull(p), ang = Math.atan2(dy, dx);
    spark(q, ang - 0.3); spark(q, ang); spark(q, ang + 0.3);
    lastP = p;
    SFX.play('move', 160);
  });
  function up() { downP = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var speed = 1;
  var kSpeed = document.getElementById('k-speed'), kBalls = document.getElementById('k-balls');
  function readSpeed() { speed = 0.35 + kSpeed.value / 100 * 2.6; }
  kSpeed.addEventListener('input', readSpeed);
  kBalls.addEventListener('input', function () { count = +this.value; refill(); });
  readSpeed(); count = +kBalls.value;
  var shapeBtns = document.querySelectorAll('[data-shape]');
  var fadeIn = 1;
  function setShape(sh) {
    shape = sh;
    shapeBtns.forEach(function (o) { o.classList.toggle('active', o.dataset.shape === sh); });
    balls.length = 0; dying.length = 0; sparks.length = 0; refill(); fadeIn = 0;
  }
  shapeBtns.forEach(function (b) { b.addEventListener('click', function () { setShape(b.dataset.shape); SFX.play('toggle'); }); });
  refill();
  setTimeout(function () {
    var a = document.querySelector('[data-shape].active');
    if (a && a.dataset.shape !== shape) setShape(a.dataset.shape);
  }, 400);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), swapT = 30;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    /* しばらくごとに、いちばん古い玉が新しい玉に入れ替わる */
    swapT -= dt;
    if (swapT <= 0) {
      var nb = randomBall(false);
      nb.n = 1; nb.bx[0] = nb.x; nb.by[0] = nb.y; nb.head = 1;
      balls.push(nb); retire();
      swapT = 28 + Math.random() * 10;
    }
    fadeIn = Math.min(1, fadeIn + dt * 1.2);
    P.fill(ctx, W, H);
    ctx.save();
    ctx.translate(W / 2, H / 2);
    if (tall) ctx.rotate(Math.PI / 2);
    drawTable(1);
    for (var i = 0; i < balls.length; i++) {
      var b = balls[i];
      if (b.go(speed * dt) && b.mine && b.hot > 0.2) SFX.play('collide', 380);
      b.hot = Math.max(0, b.hot - dt * 0.05);
      drawBall(b, fadeIn);
    }
    for (var si = sparks.length - 1; si >= 0; si--) {
      var sp = sparks[si];
      if (sp.go(speed * dt) && sp.hot > 0.5) SFX.play('collide', 300);
      sp.hot = Math.max(0, sp.hot - dt * 0.45);
      sp.life -= dt;
      if (sp.life <= 0) { sparks.splice(si, 1); sp.fade = 1; dying.push(sp); continue; }
      drawBall(sp, fadeIn);
    }
    for (var d = dying.length - 1; d >= 0; d--) {
      var o = dying[d];
      o.fade -= dt * (o.fadeRate || 0.08);
      if (o.fade <= 0) { dying.splice(d, 1); continue; }
      o.go(speed * dt);
      drawBall(o, fadeIn * o.fade);
    }
    ctx.restore();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
