<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('superformula');

$pageName = 'superformula';
$pageTitle = 'SUPERFORMULA - PULSE';
$pageDesc = 'スーパーフォーミュラ。たった1つの式から花・葉・貝・結晶が生まれる。';
$pageCanonical = 'https://pulse.subara3.com/superformula.php';
$extraStyle = '
#hud{position:fixed;top:1.2rem;right:1.2rem;font-family:monospace;font-size:.7rem;color:rgba(0,245,212,.6);text-align:right;pointer-events:none;line-height:1.9}
#controls{position:fixed;bottom:1.2rem;right:1.2rem;display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end}
#controls button{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.25);color:rgba(0,245,212,.8);font-family:monospace;font-size:.65rem;padding:.3rem .65rem;cursor:pointer;letter-spacing:.05em;transition:background .15s}
#controls button:hover{background:rgba(0,245,212,.15)}
#info{position:fixed;bottom:1.2rem;left:1.2rem;font-family:monospace;font-size:.62rem;color:rgba(0,245,212,.35);pointer-events:none}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">SUPERFORMULA</div>
<div id="controls">
  <button onclick="addShape()">+ SHAPE</button>
  <button onclick="resetAll()">RESET</button>
</div>
<div id="info">superformula · Gielis (2003) · natural morphology</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const cv = document.getElementById('canvas');
  const ctx = cv.getContext('2d');
  let W, H;

  // r(θ) = (|cos(mθ/4)|^n2 + |sin(mθ/4)|^n3)^(-1/n1)
  function sf(theta, m, n1, n2, n3) {
    const t = m * theta / 4;
    const c = Math.pow(Math.abs(Math.cos(t)), n2);
    const s = Math.pow(Math.abs(Math.sin(t)), n3);
    const v = c + s;
    return v < 1e-10 ? 0 : Math.pow(v, -1 / n1);
  }

  function buildPts(m, n1, n2, n3, size, steps) {
    steps = steps || 320;
    const xs = [], ys = [];
    for (let i = 0; i <= steps; i++) {
      const th = (i / steps) * 2 * Math.PI;
      const r = sf(th, m, n1, n2, n3) * size;
      xs.push(r * Math.cos(th));
      ys.push(r * Math.sin(th));
    }
    return {xs, ys};
  }

  const PRESETS = [
    {m:4,  n1:2,    n2:2,    n3:2   }, // square
    {m:5,  n1:1,    n2:1,    n3:1   }, // starfish
    {m:3,  n1:5,    n2:18,   n3:18  }, // triangle
    {m:6,  n1:1,    n2:1,    n3:1   }, // snowflake
    {m:2,  n1:1,    n2:2,    n3:2   }, // lens
    {m:8,  n1:1,    n2:1,    n3:1   }, // octagram
    {m:5,  n1:2,    n2:7,    n3:7   }, // petal
    {m:4,  n1:0.5,  n2:0.5,  n3:0.5 }, // rounded square
    {m:7,  n1:3,    n2:4,    n3:17  }, // leafy
    {m:3,  n1:1,    n2:0.5,  n3:0.5 }, // trifoil
    {m:6,  n1:0.3,  n2:0.3,  n3:6   }, // sea star
    {m:2,  n1:0.5,  n2:0.5,  n3:0.5 }, // ellipse
    {m:10, n1:1,    n2:1,    n3:1   }, // decagram
    {m:4,  n1:100,  n2:100,  n3:100 }, // near-square
    {m:3,  n1:0.3,  n2:0.3,  n3:0.3 }, // trefoil
  ];

  function rndPreset() {
    return PRESETS[Math.floor(Math.random() * PRESETS.length)];
  }

  class Shape {
    constructor(x, y) {
      this.x = x;
      this.y = y;
      this.vx = (Math.random() - 0.5) * 0.5;
      this.vy = (Math.random() - 0.5) * 0.5;
      this.size = 55 + Math.random() * 65;
      this.rot  = Math.random() * Math.PI * 2;
      this.rotSpd = (Math.random() - 0.5) * 0.005;
      this.dragging = false;

      const p = rndPreset();
      this.m  = p.m;
      this.n1 = p.n1; this.n2 = p.n2; this.n3 = p.n3;

      const q = rndPreset();
      this.tm = q.m;
      this.tn1 = q.n1; this.tn2 = q.n2; this.tn3 = q.n3;

      this.morphT   = Math.floor(Math.random() * 300);
      this.morphDur = 260 + Math.random() * 280;

      this.hue   = Math.random() < 0.55 ? 170 + Math.random() * 35 : 260 + Math.random() * 35;
      this.alpha = 0.55 + Math.random() * 0.3;
    }

    _nextPreset() {
      const p = rndPreset();
      this.tm = p.m; this.tn1 = p.n1; this.tn2 = p.n2; this.tn3 = p.n3;
      this.morphT = 0;
      this.morphDur = 260 + Math.random() * 280;
    }

    update() {
      if (this.dragging) return;

      this.x += this.vx;
      this.y += this.vy;
      this.rot += this.rotSpd;

      const mg = this.size * 1.1;
      if (this.x < mg || this.x > W - mg) {
        this.vx *= -1;
        this.x = Math.max(mg, Math.min(W - mg, this.x));
      }
      if (this.y < mg || this.y > H - mg) {
        this.vy *= -1;
        this.y = Math.max(mg, Math.min(H - mg, this.y));
      }

      this.morphT++;
      if (this.morphT >= this.morphDur) this._nextPreset();

      // smooth interpolation toward target
      const k = 0.014;
      this.n1 += (this.tn1 - this.n1) * k;
      this.n2 += (this.tn2 - this.n2) * k;
      this.n3 += (this.tn3 - this.n3) * k;
      if (this.morphT / this.morphDur > 0.5) this.m = this.tm;
    }

    draw() {
      const n1 = Math.max(0.02, this.n1);
      const n2 = Math.max(0.02, this.n2);
      const n3 = Math.max(0.02, this.n3);
      const {xs, ys} = buildPts(this.m, n1, n2, n3, this.size);

      ctx.save();
      ctx.translate(this.x, this.y);
      ctx.rotate(this.rot);

      ctx.beginPath();
      for (let i = 0; i < xs.length; i++) {
        if (i === 0) ctx.moveTo(xs[i], ys[i]);
        else ctx.lineTo(xs[i], ys[i]);
      }
      ctx.closePath();

      ctx.strokeStyle = `hsla(${this.hue},78%,62%,${this.alpha})`;
      ctx.lineWidth = 1.3;
      ctx.stroke();

      ctx.fillStyle = `hsla(${this.hue},78%,38%,${this.alpha * 0.13})`;
      ctx.fill();

      ctx.restore();
    }

    hit(px, py) {
      return Math.hypot(px - this.x, py - this.y) < this.size * 1.4;
    }
  }

  let shapes = [];
  let dragShape = null, dragOff = {x:0, y:0};
  let selectedShape = null;

  window.resetAll = function() {
    shapes = [];
    const n = Math.max(6, Math.min(10, Math.floor(W * H / 55000)));
    for (let i = 0; i < n; i++) {
      shapes.push(new Shape(
        110 + Math.random() * (W - 220),
        110 + Math.random() * (H - 220)
      ));
    }
    selectedShape = null;
    SFX.play('toggle');
  };

  window.addShape = function() {
    if (shapes.length >= 20) return;
    const s = new Shape(
      W * 0.3 + Math.random() * W * 0.4,
      H * 0.3 + Math.random() * H * 0.4
    );
    s.vx = 0; s.vy = 0;
    shapes.push(s);
    selectedShape = s;
    SFX.play('spawn');
  };

  function getXY(e) {
    const r = cv.getBoundingClientRect();
    return {x: e.clientX - r.left, y: e.clientY - r.top};
  }

  cv.addEventListener('mousedown', e => {
    if (e.button === 2) return;
    const {x, y} = getXY(e);
    for (let i = shapes.length - 1; i >= 0; i--) {
      if (shapes[i].hit(x, y)) {
        dragShape = shapes[i];
        dragShape.dragging = true;
        selectedShape = dragShape;
        dragOff = {x: x - dragShape.x, y: y - dragShape.y};
        SFX.play('move', 80);
        return;
      }
    }
    if (shapes.length < 20) {
      const s = new Shape(x, y);
      s.vx = 0; s.vy = 0;
      shapes.push(s);
      selectedShape = s;
      SFX.play('spawn');
    }
  });

  cv.addEventListener('mousemove', e => {
    if (!dragShape) return;
    const {x, y} = getXY(e);
    dragShape.x = x - dragOff.x;
    dragShape.y = y - dragOff.y;
  });

  cv.addEventListener('mouseup', () => {
    if (dragShape) { dragShape.dragging = false; dragShape = null; }
  });

  cv.addEventListener('contextmenu', e => {
    e.preventDefault();
    const {x, y} = getXY(e);
    for (let i = shapes.length - 1; i >= 0; i--) {
      if (shapes[i].hit(x, y)) {
        if (selectedShape === shapes[i]) selectedShape = null;
        shapes.splice(i, 1);
        SFX.play('pop');
        return;
      }
    }
  });

  // Touch support
  let touchId = null;
  cv.addEventListener('touchstart', e => {
    e.preventDefault();
    const t = e.changedTouches[0];
    touchId = t.identifier;
    const r = cv.getBoundingClientRect();
    const x = t.clientX - r.left, y = t.clientY - r.top;
    for (let i = shapes.length - 1; i >= 0; i--) {
      if (shapes[i].hit(x, y)) {
        dragShape = shapes[i];
        dragShape.dragging = true;
        selectedShape = dragShape;
        dragOff = {x: x - dragShape.x, y: y - dragShape.y};
        return;
      }
    }
    if (shapes.length < 20) {
      const s = new Shape(x, y);
      s.vx = 0; s.vy = 0;
      shapes.push(s);
      selectedShape = s;
      SFX.play('spawn');
    }
  }, {passive: false});

  cv.addEventListener('touchmove', e => {
    e.preventDefault();
    if (!dragShape) return;
    const t = Array.from(e.changedTouches).find(tc => tc.identifier === touchId);
    if (!t) return;
    const r = cv.getBoundingClientRect();
    dragShape.x = t.clientX - r.left - dragOff.x;
    dragShape.y = t.clientY - r.top - dragOff.y;
  }, {passive: false});

  cv.addEventListener('touchend', () => {
    if (dragShape) { dragShape.dragging = false; dragShape = null; }
    touchId = null;
  });

  function updateHUD() {
    const hud = document.getElementById('hud');
    const s = selectedShape && shapes.includes(selectedShape) ? selectedShape : null;
    if (s) {
      hud.innerHTML =
        `m&nbsp;= ${s.m}<br>` +
        `n1 = ${s.n1.toFixed(2)}<br>` +
        `n2 = ${s.n2.toFixed(2)}<br>` +
        `n3 = ${s.n3.toFixed(2)}<br>` +
        `<span style="opacity:.35">${shapes.length} shapes</span>`;
    } else {
      hud.textContent = shapes.length + ' SHAPES';
    }
  }

  function loop() {
    ctx.fillStyle = 'rgba(10,10,15,0.18)';
    ctx.fillRect(0, 0, W, H);
    shapes.forEach(s => { s.update(); s.draw(); });
    updateHUD();
    requestAnimationFrame(loop);
  }

  function resize() {
    cv.width  = W = window.innerWidth;
    cv.height = H = window.innerHeight;
  }

  window.addEventListener('resize', resize);
  resize();
  resetAll();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
