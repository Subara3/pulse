<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('refraction');

$pageName = 'refraction';
$pageTitle = 'REFRACTION - PULSE';
$pageDesc = '屈折の光学シミュレーション。スネルの法則に従い光線が媒質境界で曲がる。臨界角を超えると全反射が起きる。';
$pageCanonical = 'https://pulse.subara3.com/refraction.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:4.5rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  MODE <span class="v" id="h-mode">BEAM</span><br>
  RAYS <span class="v" id="h-rays">24</span><br>
  MEDIA <span class="v" id="h-media">1</span>
</div>

<div id="info">
  <span class="v">Refraction</span><br>
  n&#8321; sin &#952;&#8321; = n&#8322; sin &#952;&#8322;
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place lens'],
  ['type' => 'drag', 'label' => 'aim / move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-mode="0">BEAM</button>
      <button class="control-btn" data-mode="1">POINT</button>
      <button class="control-btn" data-mode="2">SINGLE</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">RAYS</span>
      <input type="range" id="sl-rays" min="3" max="60" value="24" step="1">
      <span class="slider-value" id="sv-rays">24</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">INDEX</span>
      <input type="range" id="sl-n" min="110" max="300" value="150" step="5">
      <span class="slider-value" id="sv-n">1.50</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SIZE</span>
      <input type="range" id="sl-r" min="15" max="180" value="60" step="5">
      <span class="slider-value" id="sv-r">60</span>
    </div>
  </div>
  <div>
    <div class="btn-row" style="flex-wrap:wrap">
      <button class="control-btn" id="btn-focus">FOCUS</button>
      <button class="control-btn" id="btn-fiber">FIBER</button>
      <button class="control-btn" id="btn-scatter">SCATTER</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, PI = Math.PI;

var srcX, srcY;
var mx, my;
var media = [];
var raySegs = [];
var mode = 0;
var nRays = 24;
var placeN = 1.5;
var placeR = 60;
var pTime = 0;

var drag = null;
var ptrDown = false, moved = false;
var ptrX = 0, ptrY = 0;
var longTimer = null;
var srcDragRadius = 20;

function resize() {
  W = C.width = window.innerWidth;
  H = C.height = window.innerHeight;
  srcX = W * 0.08;
  srcY = H * 0.5;
  mx = W * 0.7;
  my = H * 0.5;
  placeR = Math.round(Math.min(W, H) * 0.08);
  var slR = document.getElementById('sl-r');
  slR.value = placeR;
  document.getElementById('sv-r').textContent = placeR;
}

function dot2(ax, ay, bx, by) { return ax * bx + ay * by; }

function norm2(x, y) {
  var l = Math.sqrt(x * x + y * y);
  return l > 1e-12 ? [x / l, y / l] : [1, 0];
}

function rci(ox, oy, dx, dy, cx, cy, r) {
  var ex = ox - cx, ey = oy - cy;
  var b = 2 * (dx * ex + dy * ey);
  var c = ex * ex + ey * ey - r * r;
  var disc = b * b - 4 * c;
  if (disc < 0) return -1;
  var sq = Math.sqrt(disc);
  var t1 = (-b - sq) * 0.5, t2 = (-b + sq) * 0.5;
  var eps = 0.01;
  if (t1 > eps) return t1;
  if (t2 > eps) return t2;
  return -1;
}

function snell(ix, iy, nx, ny, n1, n2) {
  var ci = -(nx * ix + ny * iy);
  if (ci < 0) { nx = -nx; ny = -ny; ci = -ci; var tmp = n1; n1 = n2; n2 = tmp; }
  var r = n1 / n2;
  var st2 = r * r * (1 - ci * ci);
  if (st2 > 1.0) {
    return { dx: ix + 2 * ci * nx, dy: iy + 2 * ci * ny, tir: true };
  }
  var ct = Math.sqrt(1 - st2);
  return { dx: r * ix + (r * ci - ct) * nx, dy: r * iy + (r * ci - ct) * ny, tir: false };
}

function isInside(px, py, m) {
  var dx = px - m.x, dy = py - m.y;
  return dx * dx + dy * dy < m.r * m.r;
}

function getN(px, py) {
  for (var i = 0; i < media.length; i++) {
    if (isInside(px, py, media[i])) return media[i].n;
  }
  return 1.0;
}

function traceOne(ox, oy, dx, dy) {
  var segs = [];
  var maxD = Math.max(W, H) * 2.5;
  for (var step = 0; step < 32; step++) {
    var bestT = 1e9, bestM = null;
    for (var j = 0; j < media.length; j++) {
      var t = rci(ox, oy, dx, dy, media[j].x, media[j].y, media[j].r);
      if (t > 0 && t < bestT) { bestT = t; bestM = media[j]; }
    }
    if (!bestM || bestT > maxD) {
      segs.push({ x1: ox, y1: oy, x2: ox + dx * maxD, y2: oy + dy * maxD, n: getN(ox, oy), tir: false });
      break;
    }
    var hx = ox + dx * bestT, hy = oy + dy * bestT;
    var cn = getN(ox, oy);
    segs.push({ x1: ox, y1: oy, x2: hx, y2: hy, n: cn, tir: false });

    var nn = norm2(hx - bestM.x, hy - bestM.y);
    var inside = isInside(ox, oy, bestM);
    var n1, n2;
    if (inside) {
      n1 = bestM.n;
      n2 = 1.0;
    } else {
      n1 = cn;
      n2 = bestM.n;
    }

    var ref = snell(dx, dy, nn[0], nn[1], n1, n2);
    var nd = norm2(ref.dx, ref.dy);
    if (ref.tir) segs[segs.length - 1].tir = true;
    ox = hx + nd[0] * 0.05;
    oy = hy + nd[1] * 0.05;
    dx = nd[0];
    dy = nd[1];
  }
  return segs;
}

function traceAll() {
  raySegs = [];
  var angle = Math.atan2(my - srcY, mx - srcX);
  var dxB = Math.cos(angle), dyB = Math.sin(angle);
  var px = -dyB, py = dxB;

  if (mode === 2) {
    raySegs.push(traceOne(srcX, srcY, dxB, dyB));
  } else if (mode === 0) {
    var spread = Math.min(W, H) * 0.32;
    for (var i = 0; i < nRays; i++) {
      var t = nRays === 1 ? 0 : (i / (nRays - 1) - 0.5) * 2;
      raySegs.push(traceOne(srcX + px * t * spread * 0.5, srcY + py * t * spread * 0.5, dxB, dyB));
    }
  } else {
    var sa = PI / 3;
    for (var i = 0; i < nRays; i++) {
      var t = nRays === 1 ? 0 : (i / (nRays - 1) - 0.5) * 2;
      var a = angle + t * sa * 0.5;
      raySegs.push(traceOne(srcX, srcY, Math.cos(a), Math.sin(a)));
    }
  }
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  for (var i = 0; i < media.length; i++) {
    var m = media[i];
    var g = ctx.createRadialGradient(m.x, m.y, 0, m.x, m.y, m.r);
    g.addColorStop(0, 'rgba(0,245,212,0.06)');
    g.addColorStop(0.7, 'rgba(0,245,212,0.03)');
    g.addColorStop(1, 'rgba(0,245,212,0.01)');
    ctx.beginPath();
    ctx.arc(m.x, m.y, m.r, 0, PI * 2);
    ctx.fillStyle = g;
    ctx.fill();
    ctx.strokeStyle = 'rgba(0,245,212,0.18)';
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.fillStyle = 'rgba(0,245,212,0.28)';
    ctx.font = '10px "JetBrains Mono",monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('n=' + m.n.toFixed(2), m.x, m.y);
  }

  for (var i = 0; i < raySegs.length; i++) {
    var ray = raySegs[i];
    ctx.beginPath();
    if (ray.length > 0) ctx.moveTo(ray[0].x1, ray[0].y1);
    for (var j = 0; j < ray.length; j++) {
      ctx.lineTo(ray[j].x2, ray[j].y2);
      if (j < ray.length - 1) {
        ctx.moveTo(ray[j + 1].x1, ray[j + 1].y1);
      }
    }
    ctx.strokeStyle = 'rgba(0,245,212,0.3)';
    ctx.lineWidth = 1;
    ctx.stroke();

    for (var j = 0; j < ray.length; j++) {
      var s = ray[j];
      if (s.n > 1.0) {
        ctx.beginPath();
        ctx.moveTo(s.x1, s.y1);
        ctx.lineTo(s.x2, s.y2);
        ctx.strokeStyle = 'rgba(0,245,212,0.45)';
        ctx.lineWidth = 1.5;
        ctx.stroke();
      }
    }

    for (var j = 0; j < ray.length - 1; j++) {
      if (ray[j].tir) {
        ctx.beginPath();
        ctx.arc(ray[j].x2, ray[j].y2, 3, 0, PI * 2);
        ctx.fillStyle = 'rgba(123,44,191,0.6)';
        ctx.fill();
      } else {
        ctx.beginPath();
        ctx.arc(ray[j].x2, ray[j].y2, 1.5, 0, PI * 2);
        ctx.fillStyle = 'rgba(0,245,212,0.25)';
        ctx.fill();
      }
    }
  }

  pTime += 0.018;
  var baseSpacing = 22;
  var speed = 180;
  for (var i = 0; i < raySegs.length; i++) {
    var ray = raySegs[i];
    var cumDist = 0;
    for (var j = 0; j < ray.length; j++) {
      var s = ray[j];
      var sdx = s.x2 - s.x1, sdy = s.y2 - s.y1;
      var slen = Math.sqrt(sdx * sdx + sdy * sdy);
      if (slen < 0.5) { cumDist += slen; continue; }

      var spacing = baseSpacing / s.n;
      var off = ((pTime * speed / s.n) % spacing);
      var startLocal = spacing - ((cumDist % spacing + off) % spacing);
      if (startLocal > spacing) startLocal -= spacing;

      for (var d = startLocal; d < slen; d += spacing) {
        var t = d / slen;
        var ppx = s.x1 + sdx * t, ppy = s.y1 + sdy * t;
        if (ppx < -5 || ppx > W + 5 || ppy < -5 || ppy > H + 5) continue;
        ctx.beginPath();
        ctx.arc(ppx, ppy, 1.3, 0, PI * 2);
        ctx.fillStyle = s.n > 1 ? 'rgba(0,200,180,0.65)' : 'rgba(0,245,212,0.55)';
        ctx.fill();
      }
      cumDist += slen;
    }
  }

  if (!ptrDown && !drag) {
    var overSrc = dist2(mx, my, srcX, srcY) < srcDragRadius * srcDragRadius;
    var overMedia = findMedia(mx, my);
    if (!overSrc && overMedia < 0) {
      ctx.beginPath();
      ctx.arc(mx, my, placeR, 0, PI * 2);
      ctx.strokeStyle = 'rgba(0,245,212,0.12)';
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      ctx.stroke();
      ctx.setLineDash([]);
    }
  }

  ctx.beginPath();
  ctx.arc(srcX, srcY, 5, 0, PI * 2);
  ctx.fillStyle = '#00f5d4';
  ctx.fill();
  ctx.beginPath();
  ctx.arc(srcX, srcY, 10, 0, PI * 2);
  ctx.strokeStyle = 'rgba(0,245,212,0.2)';
  ctx.lineWidth = 1;
  ctx.stroke();
  var angle = Math.atan2(my - srcY, mx - srcX);
  ctx.beginPath();
  ctx.moveTo(srcX + Math.cos(angle) * 7, srcY + Math.sin(angle) * 7);
  ctx.lineTo(srcX + Math.cos(angle) * 16, srcY + Math.sin(angle) * 16);
  ctx.strokeStyle = 'rgba(0,245,212,0.35)';
  ctx.lineWidth = 2;
  ctx.stroke();

  document.getElementById('h-mode').textContent = ['BEAM', 'POINT', 'SINGLE'][mode];
  document.getElementById('h-rays').textContent = mode === 2 ? '1' : nRays;
  document.getElementById('h-media').textContent = media.length;
}

function dist2(ax, ay, bx, by) {
  var dx = ax - bx, dy = ay - by;
  return dx * dx + dy * dy;
}

function findMedia(x, y) {
  for (var i = media.length - 1; i >= 0; i--) {
    if (isInside(x, y, media[i])) return i;
  }
  return -1;
}

function loop() {
  traceAll();
  draw();
  requestAnimationFrame(loop);
}

function getPos(e) {
  if (e.touches && e.touches.length > 0) return [e.touches[0].clientX, e.touches[0].clientY];
  return [e.clientX, e.clientY];
}

function onDown(e) {
  if (e.button === 2) return;
  e.preventDefault();
  var p = getPos(e);
  ptrX = p[0]; ptrY = p[1];
  ptrDown = true; moved = false;

  if (dist2(p[0], p[1], srcX, srcY) < srcDragRadius * srcDragRadius) {
    drag = { type: 'src', ox: srcX - p[0], oy: srcY - p[1] };
  } else {
    var mi = findMedia(p[0], p[1]);
    if (mi >= 0) {
      drag = { type: 'media', idx: mi, ox: media[mi].x - p[0], oy: media[mi].y - p[1] };
    } else {
      drag = null;
    }
  }

  if (e.touches) {
    longTimer = setTimeout(function() {
      var mi = findMedia(ptrX, ptrY);
      if (mi >= 0) {
        media.splice(mi, 1);
        if (typeof SFX !== 'undefined') SFX.play('pop');
        drag = null;
        moved = true;
      }
    }, 500);
  }
}

function onMove(e) {
  var p;
  if (e.touches && e.touches.length > 0) {
    p = [e.touches[0].clientX, e.touches[0].clientY];
  } else {
    p = [e.clientX, e.clientY];
  }
  mx = p[0]; my = p[1];

  if (ptrDown) {
    if (dist2(p[0], p[1], ptrX, ptrY) > 25) moved = true;
    if (drag) {
      if (drag.type === 'src') {
        srcX = p[0] + drag.ox;
        srcY = p[1] + drag.oy;
      } else if (drag.type === 'media') {
        media[drag.idx].x = p[0] + drag.ox;
        media[drag.idx].y = p[1] + drag.oy;
      }
    }
    if (longTimer && moved) { clearTimeout(longTimer); longTimer = null; }
  }
}

function onUp(e) {
  if (longTimer) { clearTimeout(longTimer); longTimer = null; }
  if (ptrDown && !moved && !drag) {
    media.push({ x: ptrX, y: ptrY, r: placeR, n: placeN });
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  ptrDown = false;
  drag = null;
}

function onContext(e) {
  e.preventDefault();
  var mi = findMedia(e.clientX, e.clientY);
  if (mi >= 0) {
    media.splice(mi, 1);
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }
}

function onWheel(e) {
  var mi = findMedia(e.clientX, e.clientY);
  if (mi >= 0) {
    var delta = e.deltaY > 0 ? -0.05 : 0.05;
    media[mi].n = Math.max(1.01, Math.min(3.0, media[mi].n + delta));
    e.preventDefault();
  }
}

C.addEventListener('mousedown', onDown);
window.addEventListener('mousemove', onMove);
window.addEventListener('mouseup', onUp);
C.addEventListener('contextmenu', onContext);
C.addEventListener('wheel', onWheel, { passive: false });
C.addEventListener('touchstart', onDown, { passive: false });
C.addEventListener('touchmove', onMove, { passive: true });
C.addEventListener('touchend', onUp);
window.addEventListener('resize', function() { resize(); });

document.querySelectorAll('[data-mode]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    mode = parseInt(this.dataset.mode);
    document.querySelectorAll('[data-mode]').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

var slRays = document.getElementById('sl-rays');
var slN = document.getElementById('sl-n');
var slR = document.getElementById('sl-r');

slRays.addEventListener('input', function() {
  nRays = parseInt(this.value);
  document.getElementById('sv-rays').textContent = nRays;
});
slN.addEventListener('input', function() {
  placeN = parseInt(this.value) / 100;
  document.getElementById('sv-n').textContent = placeN.toFixed(2);
});
slR.addEventListener('input', function() {
  placeR = parseInt(this.value);
  document.getElementById('sv-r').textContent = placeR;
});

document.getElementById('btn-focus').addEventListener('click', function() {
  media = [{ x: W * 0.5, y: H * 0.5, r: Math.min(W, H) * 0.13, n: 1.52 }];
  if (typeof SFX !== 'undefined') SFX.play('click');
});
document.getElementById('btn-fiber').addEventListener('click', function() {
  media = [];
  var r = Math.min(W, H) * 0.055;
  var startX = W * 0.28, gap = r * 2.8;
  for (var i = 0; i < 5; i++) {
    media.push({ x: startX + i * gap, y: H * 0.5, r: r, n: 1.8 });
  }
  if (typeof SFX !== 'undefined') SFX.play('click');
});
document.getElementById('btn-scatter').addEventListener('click', function() {
  media = [];
  var r = Math.min(W, H) * 0.04;
  var cols = 4, rows = 3;
  var gx = W * 0.12, gy = H * 0.15;
  var ox = W * 0.28, oy = H * 0.22;
  for (var i = 0; i < cols; i++) {
    for (var j = 0; j < rows; j++) {
      var jitter = (((i * 7 + j * 13) % 17) / 17 - 0.5) * gx * 0.4;
      media.push({
        x: ox + i * gx + jitter,
        y: oy + j * gy + jitter * 0.6,
        r: r * (0.7 + ((i + j) % 3) * 0.25),
        n: 1.2 + ((i * 3 + j * 5) % 7) * 0.15
      });
    }
  }
  if (typeof SFX !== 'undefined') SFX.play('click');
});
document.getElementById('btn-clear').addEventListener('click', function() {
  media = [];
  if (typeof SFX !== 'undefined') SFX.play('click');
});

resize();
media.push({ x: W * 0.48, y: H * 0.5, r: Math.min(W, H) * 0.12, n: 1.52 });
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
