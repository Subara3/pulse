<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('bezier');

$pageName     = 'bezier';
$pageTitle    = 'BÉZIER - PULSE';
$pageDesc     = 'ベジェ曲線。補間の補間が曲線を生む、de Casteljauアルゴリズムの可視化。';
$pageCanonical = 'https://pulse.subara3.com/bezier.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas {
  position: fixed; inset: 0; width: 100%; height: 100%;
  touch-action: none; cursor: crosshair;
}
.hud {position:fixed;top:3.5rem;right:1rem;z-index: 100;pointer-events: none;font-family: "Orbitron","Segoe UI",sans-serif;font-size: 0.62rem;color: rgba(255,255,255,0.3);line-height: 2;letter-spacing: 0.06em;}
.hud .v { color: #00f5d4; font-weight: 700; }

@media (max-width: 768px) {
  .hud {top:3rem;right:0.6rem;font-size: 0.52rem;}

}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="hud">
  DEGREE: <span id="hudDeg" class="v">3</span><br>
  T:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudT"   class="v">0.00</span><br>
  POINTS: <span id="hudPts" class="v">4</span>
</div>

<div id="controls" class="drawer-controls">
  <button class="control-btn active" id="btnSteps">STEPS: ON</button>
  <button class="control-btn" id="btnReset">RESET</button>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add point'],
  ['type' => 'drag',  'label' => 'move point'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx    = canvas.getContext('2d');

  var hudDeg = document.getElementById('hudDeg');
  var hudT   = document.getElementById('hudT');
  var hudPts = document.getElementById('hudPts');
  var btnSteps = document.getElementById('btnSteps');
  var btnReset = document.getElementById('btnReset');

  var W = 0, H = 0;
  var ACCENT = [0, 245, 212];
  var SUB    = [123, 44, 191];

  function rgba(c, a) { return 'rgba('+c[0]+','+c[1]+','+c[2]+','+a+')'; }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function lerpPt(p, q, t) { return { x: lerp(p.x, q.x, t), y: lerp(p.y, q.y, t) }; }
  function dist2(p, q) { var dx = p.x-q.x, dy = p.y-q.y; return dx*dx+dy*dy; }
  function mixRGB(a, b, f) {
    return [
      Math.round(lerp(a[0], b[0], f)),
      Math.round(lerp(a[1], b[1], f)),
      Math.round(lerp(a[2], b[2], f))
    ];
  }

  // State
  var pts = [];           // control points
  var curveCache = [];    // 300-step pre-computed curve
  var t = 0;
  var tSpeed = 0.006;
  var showSteps = true;
  var drag = { active: false, idx: -1 };
  var lastMoveSound = 0;

  /* ── de Casteljau ────────────────────────────────────────── */
  function deCasteljau(points, t) {
    var levels = [ points.map(function(p){ return {x:p.x, y:p.y}; }) ];
    var cur = levels[0];
    while (cur.length > 1) {
      var next = [];
      for (var i = 0; i < cur.length - 1; i++) {
        next.push(lerpPt(cur[i], cur[i+1], t));
      }
      levels.push(next);
      cur = next;
    }
    return levels;  // last level = [finalPoint]
  }

  function rebuildCurve() {
    curveCache = [];
    if (pts.length < 2) return;
    var STEPS = 300;
    for (var i = 0; i <= STEPS; i++) {
      var levels = deCasteljau(pts, i / STEPS);
      curveCache.push(levels[levels.length - 1][0]);
    }
  }

  /* ── Default layout ──────────────────────────────────────── */
  function initDefaultPoints() {
    var cx = W * 0.5, cy = H * 0.5;
    var rx = Math.min(W, H) * 0.3;
    var ry = Math.min(W, H) * 0.22;
    pts = [
      { x: cx - rx,       y: cy + ry },
      { x: cx - rx * 0.4, y: cy - ry * 1.5 },
      { x: cx + rx * 0.4, y: cy - ry * 1.5 },
      { x: cx + rx,       y: cy + ry }
    ];
    t = 0;
    rebuildCurve();
  }

  /* ── Draw ────────────────────────────────────────────────── */
  function draw() {
    ctx.clearRect(0, 0, W, H);

    if (pts.length < 2) {
      ctx.save();
      ctx.fillStyle = rgba(ACCENT, 0.18);
      ctx.font = '0.65rem Orbitron,sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('CLICK ANYWHERE TO ADD CONTROL POINTS', W/2, H/2);
      ctx.restore();
      return;
    }

    /* Full curve (faint guide) */
    if (curveCache.length > 1) {
      ctx.beginPath();
      ctx.moveTo(curveCache[0].x, curveCache[0].y);
      for (var i = 1; i < curveCache.length; i++) {
        ctx.lineTo(curveCache[i].x, curveCache[i].y);
      }
      ctx.strokeStyle = rgba(ACCENT, 0.13);
      ctx.lineWidth   = 1.5;
      ctx.setLineDash([]);
      ctx.stroke();
    }

    /* Control polygon (dashed purple) */
    ctx.beginPath();
    ctx.moveTo(pts[0].x, pts[0].y);
    for (var i = 1; i < pts.length; i++) ctx.lineTo(pts[i].x, pts[i].y);
    ctx.strokeStyle = rgba(SUB, 0.32);
    ctx.lineWidth   = 1;
    ctx.setLineDash([5, 5]);
    ctx.stroke();
    ctx.setLineDash([]);

    /* de Casteljau animation at current t */
    var levels  = deCasteljau(pts, t);
    var nLevels = levels.length;  // nLevels = pts.length

    if (showSteps) {
      for (var lvl = 1; lvl < nLevels; lvl++) {
        var level = levels[lvl];
        var frac  = (lvl - 1) / Math.max(nLevels - 2, 1);
        var col   = mixRGB(ACCENT, SUB, frac);
        var alp   = 0.7 - frac * 0.3;

        /* Connecting lines for this level */
        if (level.length >= 2) {
          ctx.beginPath();
          ctx.moveTo(level[0].x, level[0].y);
          for (var j = 1; j < level.length; j++) ctx.lineTo(level[j].x, level[j].y);
          ctx.strokeStyle = rgba(col, alp * 0.65);
          ctx.lineWidth   = 1.2 + (nLevels - lvl) * 0.4;
          ctx.stroke();
        }

        /* Dots at each interpolated point */
        var dotR = (lvl === nLevels - 1) ? 5 : 3.5;
        for (var j = 0; j < level.length; j++) {
          ctx.beginPath();
          ctx.arc(level[j].x, level[j].y, dotR, 0, Math.PI * 2);
          ctx.fillStyle = rgba(col, alp);
          ctx.fill();
        }
      }
    }

    /* Glowing traced path up to t */
    var trailEnd = Math.round(t * (curveCache.length - 1));
    if (trailEnd > 0 && curveCache.length > 1) {
      ctx.beginPath();
      ctx.moveTo(curveCache[0].x, curveCache[0].y);
      for (var i = 1; i <= trailEnd; i++) ctx.lineTo(curveCache[i].x, curveCache[i].y);
      ctx.strokeStyle  = rgba(ACCENT, 0.9);
      ctx.lineWidth    = 2.5;
      ctx.shadowColor  = rgba(ACCENT, 0.55);
      ctx.shadowBlur   = 10;
      ctx.stroke();
      ctx.shadowBlur   = 0;
    }

    /* Final point on curve (white dot) */
    var fp = levels[nLevels - 1][0];
    ctx.beginPath();
    ctx.arc(fp.x, fp.y, 6, 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(fp.x, fp.y, 11, 0, Math.PI * 2);
    ctx.strokeStyle = rgba(ACCENT, 0.55);
    ctx.lineWidth   = 2;
    ctx.stroke();

    /* Control point circles */
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i];
      /* Fill */
      ctx.beginPath();
      ctx.arc(p.x, p.y, 8, 0, Math.PI * 2);
      ctx.fillStyle = rgba(ACCENT, 0.1);
      ctx.fill();
      /* Ring */
      ctx.beginPath();
      ctx.arc(p.x, p.y, 8, 0, Math.PI * 2);
      ctx.strokeStyle = rgba(ACCENT, 0.75);
      ctx.lineWidth   = 2;
      ctx.stroke();
    }

    /* HUD */
    hudDeg.textContent = pts.length - 1;
    hudT.textContent   = t.toFixed(2);
    hudPts.textContent = pts.length;
  }

  /* ── Animation loop ──────────────────────────────────────── */
  function loop() {
    t += tSpeed;
    if (t >= 1) {
      t = 0;
      SFX.play('complete', 1000);
    }
    draw();
    requestAnimationFrame(loop);
  }

  /* ── Hit test ────────────────────────────────────────────── */
  function hitTest(pos) {
    var best = -1, bestD = 14 * 14;
    for (var i = 0; i < pts.length; i++) {
      var d = dist2(pts[i], pos);
      if (d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  /* ── Pointer helpers ─────────────────────────────────────── */
  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    var src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
  }

  /* ── Mouse events ────────────────────────────────────────── */
  canvas.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var pos = getPos(e);
    if (e.button === 2) {
      var idx = hitTest(pos);
      if (idx >= 0 && pts.length > 2) {
        pts.splice(idx, 1);
        rebuildCurve();
        SFX.play('pop');
      }
      return;
    }
    var idx = hitTest(pos);
    if (idx >= 0) {
      drag = { active: true, idx: idx };
    } else {
      pts.push({ x: pos.x, y: pos.y });
      rebuildCurve();
      SFX.play('spawn');
    }
  });

  canvas.addEventListener('mousemove', function(e) {
    if (!drag.active) return;
    var pos = getPos(e);
    pts[drag.idx].x = pos.x;
    pts[drag.idx].y = pos.y;
    rebuildCurve();
    var now = Date.now();
    if (now - lastMoveSound > 50) { SFX.play('move'); lastMoveSound = now; }
  });

  canvas.addEventListener('mouseup',    function() { drag = { active: false, idx: -1 }; });
  canvas.addEventListener('mouseleave', function() { drag = { active: false, idx: -1 }; });
  canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  /* ── Touch events ────────────────────────────────────────── */
  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var pos = getPos(e);
    var idx = hitTest(pos);
    if (idx >= 0) {
      drag = { active: true, idx: idx };
    } else {
      pts.push({ x: pos.x, y: pos.y });
      rebuildCurve();
      SFX.play('spawn');
    }
  }, { passive: false });

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!drag.active) return;
    var pos = getPos(e);
    pts[drag.idx].x = pos.x;
    pts[drag.idx].y = pos.y;
    rebuildCurve();
    var now = Date.now();
    if (now - lastMoveSound > 50) { SFX.play('move'); lastMoveSound = now; }
  }, { passive: false });

  canvas.addEventListener('touchend', function() { drag = { active: false, idx: -1 }; });

  /* ── Resize ──────────────────────────────────────────────── */
  function resize() {
    var oldW = W, oldH = H;
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    if (pts.length === 0) {
      initDefaultPoints();
    } else if (oldW > 0 && oldH > 0) {
      var sx = W / oldW, sy = H / oldH;
      for (var i = 0; i < pts.length; i++) {
        pts[i].x *= sx;
        pts[i].y *= sy;
      }
      rebuildCurve();
    }
  }

  /* ── Buttons ─────────────────────────────────────────────── */
  btnSteps.addEventListener('click', function() {
    showSteps = !showSteps;
    btnSteps.textContent = 'STEPS: ' + (showSteps ? 'ON' : 'OFF');
    btnSteps.classList.toggle('active', showSteps);
    SFX.play('toggle');
  });

  btnReset.addEventListener('click', function() {
    initDefaultPoints();
    SFX.play('click');
  });

  /* ── Boot ────────────────────────────────────────────────── */
  window.addEventListener('resize', resize);
  resize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
