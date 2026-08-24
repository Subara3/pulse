<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('dragon');

$pageName = 'dragon';
$pageTitle = 'DRAGON - PULSE';
$pageDesc = 'ドラゴン曲線。紙を半分に折り続けると現れる自己相似フラクタル。';
$pageCanonical = 'https://pulse.subara3.com/dragon.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  ORDER: <span id="hudOrd" class="v">6</span><br>
  SEGMENTS: <span id="hudSeg" class="v">64</span><br>
  TILES: <span id="hudTile" class="v">1</span>
</div>

<div id="info">
  heighway dragon &mdash; <span class="v">paper folding fractal</span><br>
  each fold doubles the segments
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'order +1'],
  ['type' => 'right', 'label' => 'order -1'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">ORDER</label>
    <div class="slider-row">
      <input type="range" id="sldOrder" min="0" max="18" value="6">
      <span class="slider-value" id="valOrder">6</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">GLOW</label>
    <div class="slider-row">
      <input type="range" id="sldGlow" min="0" max="12" value="6">
      <span class="slider-value" id="valGlow">6</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnGrow">GROW</button>
    <button class="control-btn" id="btnTile">TILE: 1</button>
    <button class="control-btn" id="btnCenter">RECENTER</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;
  var order = 6, MAX_ORD = 18;
  var tileMode = 1, growing = false, growTmr = 0;
  var drawProg = 1, drawAnim = false;
  var panX = 0, panY = 0, zoom = 1, glowStr = 6;
  var needDraw = true;
  var pts = [];

  var hudOrd = document.getElementById('hudOrd');
  var hudSeg = document.getElementById('hudSeg');
  var hudTile = document.getElementById('hudTile');
  var sldOrd = document.getElementById('sldOrder');
  var valOrd = document.getElementById('valOrder');
  var sldGlow = document.getElementById('sldGlow');
  var valGlow = document.getElementById('valGlow');
  var btnGrow = document.getElementById('btnGrow');
  var btnTile = document.getElementById('btnTile');

  function resize() {
    var d = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * d; C.height = H * d;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(d, 0, 0, d, 0, 0);
    needDraw = true;
  }
  window.addEventListener('resize', function() { resize(); fit(); });
  resize();

  function gen(n) {
    var s = 1 << n, a = new Array(s + 1);
    var DX = [1, 0, -1, 0], DY = [0, 1, 0, -1], dir = 0, x = 0, y = 0;
    a[0] = {x: 0, y: 0};
    for (var i = 0; i < s; i++) {
      x += DX[dir]; y += DY[dir];
      a[i + 1] = {x: x, y: y};
      if (i < s - 1) {
        var k = i + 1, lo = k & -k;
        dir = (k & (lo << 1)) ? (dir + 3) % 4 : (dir + 1) % 4;
      }
    }
    return a;
  }

  function fit() {
    var x0 = 0, x1 = 0, y0 = 0, y1 = 0;
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i];
      if (p.x < x0) x0 = p.x; if (p.x > x1) x1 = p.x;
      if (p.y < y0) y0 = p.y; if (p.y > y1) y1 = p.y;
    }
    if (tileMode >= 2) {
      var a = x0, b = x1, c = y0, d = y1;
      x0 = Math.min(a, -b); x1 = Math.max(b, -a);
      y0 = Math.min(c, -d); y1 = Math.max(d, -c);
    }
    if (tileMode >= 4) {
      var m = Math.max(Math.abs(x0), Math.abs(x1), Math.abs(y0), Math.abs(y1));
      x0 = -m; x1 = m; y0 = -m; y1 = m;
    }
    var bw = (x1 - x0) || 1, bh = (y1 - y0) || 1;
    zoom = Math.min(W * 0.76 / bw, H * 0.76 / bh);
    panX = -(x0 + x1) / 2 * zoom;
    panY = -(y0 + y1) / 2 * zoom;
    needDraw = true;
  }

  function rebuild() { pts = gen(order); fit(); }

  function strokeCurve(a, cnt, col, alp, ang) {
    if (cnt < 2) return;
    var ca = Math.cos(ang), sa = Math.sin(ang);
    var ox = W / 2 + panX, oy = H / 2 + panY;
    ctx.beginPath();
    var p = a[0];
    ctx.moveTo((p.x * ca - p.y * sa) * zoom + ox, (p.x * sa + p.y * ca) * zoom + oy);
    for (var i = 1; i < cnt; i++) {
      p = a[i];
      ctx.lineTo((p.x * ca - p.y * sa) * zoom + ox, (p.x * sa + p.y * ca) * zoom + oy);
    }
    ctx.strokeStyle = col;
    ctx.globalAlpha = alp;
    ctx.lineWidth = Math.max(0.6, Math.min(3, zoom * 0.25));
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.stroke();
    ctx.globalAlpha = 1;
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    var cnt = drawAnim ? Math.max(2, Math.ceil(drawProg * pts.length)) : pts.length;
    var useGlow = glowStr > 0 && pts.length < 50000;
    if (useGlow) ctx.shadowBlur = glowStr * 2;

    if (tileMode >= 4) {
      ctx.shadowColor = useGlow ? 'rgba(0,180,160,0.35)' : 'transparent';
      strokeCurve(pts, cnt, 'rgba(0,180,160,0.45)', 0.45, Math.PI / 2);
      ctx.shadowColor = useGlow ? 'rgba(140,50,200,0.35)' : 'transparent';
      strokeCurve(pts, cnt, 'rgba(140,50,200,0.45)', 0.45, -Math.PI / 2);
    }
    if (tileMode >= 2) {
      ctx.shadowColor = useGlow ? 'rgba(123,44,191,0.45)' : 'transparent';
      strokeCurve(pts, cnt, '#7b2cbf', 0.65, Math.PI);
    }
    ctx.shadowColor = useGlow ? 'rgba(0,245,212,0.5)' : 'transparent';
    strokeCurve(pts, cnt, '#00f5d4', 0.9, 0);
    ctx.shadowBlur = 0;
    ctx.shadowColor = 'transparent';

    if (drawAnim && cnt >= 2) {
      var t = pts[cnt - 1];
      ctx.beginPath();
      ctx.arc(t.x * zoom + W / 2 + panX, t.y * zoom + H / 2 + panY, Math.max(2, zoom * 0.4), 0, Math.PI * 2);
      ctx.fillStyle = '#fff';
      ctx.fill();
    }

    if (order <= 5 && !drawAnim) {
      var r = Math.max(2, zoom * 0.25);
      ctx.fillStyle = 'rgba(0,245,212,0.6)';
      for (var i = 0; i < pts.length; i++) {
        ctx.beginPath();
        ctx.arc(pts[i].x * zoom + W / 2 + panX, pts[i].y * zoom + H / 2 + panY, r, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    hudOrd.textContent = order;
    hudSeg.textContent = (1 << order).toLocaleString();
    hudTile.textContent = tileMode;
  }

  rebuild();

  function setOrder(v) {
    if (v === order || v < 0 || v > MAX_ORD) return;
    order = v;
    sldOrd.value = order;
    valOrd.textContent = order;
    rebuild();
    drawProg = 0;
    drawAnim = true;
  }

  sldOrd.addEventListener('input', function() { setOrder(+this.value); SFX.play('grow'); });
  sldGlow.addEventListener('input', function() { glowStr = +this.value; valGlow.textContent = glowStr; needDraw = true; });

  btnGrow.addEventListener('click', function() {
    growing = !growing;
    if (growing) {
      growTmr = 0;
      if (order >= MAX_ORD) setOrder(0);
      this.classList.add('active');
      this.textContent = 'STOP';
    } else {
      this.classList.remove('active');
      this.textContent = 'GROW';
    }
    SFX.play('toggle');
  });

  btnTile.addEventListener('click', function() {
    tileMode = tileMode === 1 ? 2 : tileMode === 2 ? 4 : 1;
    this.textContent = 'TILE: ' + tileMode;
    this.classList.toggle('active', tileMode > 1);
    fit();
    SFX.play('toggle');
  });

  document.getElementById('btnCenter').addEventListener('click', function() { fit(); SFX.play('click'); });

  var drag = false, dMoved = false, lx, ly;
  C.addEventListener('mousedown', function(e) {
    if (e.button === 0) { drag = true; dMoved = false; lx = e.clientX; ly = e.clientY; }
  });
  window.addEventListener('mousemove', function(e) {
    if (!drag) return;
    var dx = e.clientX - lx, dy = e.clientY - ly;
    if (Math.abs(dx) > 2 || Math.abs(dy) > 2) dMoved = true;
    panX += dx; panY += dy;
    lx = e.clientX; ly = e.clientY;
    needDraw = true;
  });
  window.addEventListener('mouseup', function(e) {
    if (drag && !dMoved && e.button === 0) { setOrder(order + 1); SFX.play('grow'); }
    drag = false;
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    if (order > 0) { setOrder(order - 1); SFX.play('pop'); }
  });
  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var mx = e.clientX - W / 2, my = e.clientY - H / 2, old = zoom;
    zoom *= e.deltaY < 0 ? 1.15 : 1 / 1.15;
    zoom = Math.max(0.01, Math.min(5000, zoom));
    var r = zoom / old;
    panX = mx - r * (mx - panX);
    panY = my - r * (my - panY);
    needDraw = true;
    SFX.play('tick', 80);
  }, {passive: false});

  var td0 = 0, tPan = false, tPinch = false, tMov = false, t0x, t0y, tlx, tly;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      tPan = true; tPinch = false; tMov = false;
      t0x = tlx = e.touches[0].clientX;
      t0y = tly = e.touches[0].clientY;
    } else if (e.touches.length === 2) {
      tPan = false; tPinch = true;
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      td0 = Math.sqrt(dx * dx + dy * dy);
    }
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (tPan && e.touches.length === 1) {
      var mx = e.touches[0].clientX, my = e.touches[0].clientY;
      panX += mx - tlx; panY += my - tly;
      tlx = mx; tly = my; tMov = true; needDraw = true;
    } else if (tPinch && e.touches.length === 2) {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      var d = Math.sqrt(dx * dx + dy * dy);
      zoom *= d / td0;
      zoom = Math.max(0.01, Math.min(5000, zoom));
      td0 = d; needDraw = true;
    }
  }, {passive: false});
  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (tPan && !tMov) { setOrder(order + 1); SFX.play('grow'); }
      tPan = false; tPinch = false;
    }
  });

  var lt = 0;
  function loop(t) {
    requestAnimationFrame(loop);
    var dt = Math.min(t - lt, 50);
    lt = t;
    if (growing) {
      growTmr += dt;
      if (growTmr > 1200) {
        growTmr = 0;
        if (order < MAX_ORD) { setOrder(order + 1); SFX.play('grow'); }
        else { growing = false; btnGrow.classList.remove('active'); btnGrow.textContent = 'GROW'; SFX.play('complete'); }
      }
    }
    if (drawAnim) {
      drawProg += dt / Math.max(300, Math.min(1500, pts.length * 0.005));
      if (drawProg >= 1) { drawProg = 1; drawAnim = false; }
      needDraw = true;
    }
    if (needDraw) { draw(); needDraw = false; }
  }
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
