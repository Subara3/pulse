<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('moire');

$pageName = 'moire';
$pageTitle = 'MOIRÉ - PULSE';
$pageDesc = 'モアレ縞。2つの周期格子が重なるとき、角度差がうなり縞を生む。3パターンで体験する干渉の数学。';
$pageCanonical = 'https://pulse.subara3.com/moire.php';
$extraStyle = '
#hud { position:fixed; bottom:20px; left:16px; font-family:monospace; font-size:11px;
       color:rgba(0,245,212,0.7); text-align:left; pointer-events:none; line-height:1.9; }
#hud .val { color:#00f5d4; }
#controls { position:fixed; bottom:20px; right:16px; display:flex; flex-direction:column;
            align-items:flex-end; gap:6px; }
.ctrl-btn { background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.3);
            color:#00f5d4; font-family:monospace; font-size:11px; padding:5px 10px;
            cursor:pointer; transition:background 0.15s; user-select:none; }
.ctrl-btn:hover { background:rgba(0,245,212,0.18); }
.ctrl-row { display:flex; align-items:center; gap:8px; }
.ctrl-label { color:rgba(0,245,212,0.6); font-family:monospace; font-size:10px; }
input[type=range] { -webkit-appearance:none; width:90px; height:3px;
                    background:rgba(0,245,212,0.25); outline:none; border-radius:2px; cursor:pointer; }
input[type=range]::-webkit-slider-thumb { -webkit-appearance:none; width:10px; height:10px;
  border-radius:50%; background:#00f5d4; cursor:pointer; }
canvas { display:block; cursor:crosshair; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  MODE &nbsp;<span class="val" id="hud-mode">LINES</span><br>
  ANGLE <span class="val" id="hud-angle">0.0</span>°<br>
  PITCH <span class="val" id="hud-pitch">20</span>px<br>
  FRINGE <span class="val" id="hud-fringe">—</span>
</div>

<div id="controls">
  <button class="ctrl-btn" id="btn-mode">MODE</button>
  <button class="ctrl-btn" id="btn-auto">AUTO ON</button>
  <div class="ctrl-row">
    <span class="ctrl-label">PITCH</span>
    <input type="range" id="slider-pitch" min="10" max="40" value="20">
  </div>
  <button class="ctrl-btn" id="btn-reset">RESET</button>
</div>

<?php
$interactionHints = [
  ['type' => 'drag',  'label' => 'rotate'],
  ['type' => 'wheel', 'label' => 'pitch'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  let W, H, cx, cy;
  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2; cy = H / 2;
    gridPatternCache = null; // invalidate on resize
  }
  resize();
  window.addEventListener('resize', resize);

  /* ---- state ---- */
  const MODE_NAMES = ['LINES', 'RINGS', 'GRID'];
  let mode   = 0;
  let pitch  = 20;
  let angle  = 0;      // rotation for LINES / GRID
  let ringOX = 0;      // offset for RINGS
  let ringOY = 0;
  let autoOn = true;

  /* auto animation */
  let autoAngle = 0;
  let autoDir   = 1;
  const AUTO_MAX  = 0.22;   // ~12.5 degrees
  const AUTO_SPD  = 0.00035; // rad/frame @ 60fps
  let   autoRT    = 0;
  const RING_R    = 36;
  const RING_SPD  = 0.0025;

  /* drag */
  let dragging  = false;
  let dragPrevX = 0;
  let dragPrevY = 0;

  /* ---- grid pattern cache ---- */
  let gridPatternCache = null;
  let gridPitchCache   = -1;

  function getGridPattern() {
    if (pitch === gridPitchCache && gridPatternCache) return gridPatternCache;
    const pc   = document.createElement('canvas');
    pc.width   = pitch;
    pc.height  = pitch;
    const pctx = pc.getContext('2d');
    const r    = Math.max(1.2, pitch * 0.21);
    pctx.fillStyle = 'rgb(0,110,95)';
    pctx.beginPath();
    pctx.arc(pitch / 2, pitch / 2, r, 0, Math.PI * 2);
    pctx.fill();
    gridPatternCache = ctx.createPattern(pc, 'repeat');
    gridPitchCache   = pitch;
    return gridPatternCache;
  }

  /* ---- draw helpers ---- */
  const LINE_COLOR = 'rgb(0,110,95)';
  const LINE_W_F   = 0.32; // fraction of pitch

  function drawLines(theta) {
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(theta);
    const diag  = Math.sqrt(W * W + H * H);
    const count = Math.ceil(diag / pitch) + 2;
    ctx.beginPath();
    for (let i = -count; i <= count; i++) {
      const y = i * pitch;
      ctx.moveTo(-diag, y);
      ctx.lineTo( diag, y);
    }
    ctx.strokeStyle = LINE_COLOR;
    ctx.lineWidth   = pitch * LINE_W_F;
    ctx.stroke();
    ctx.restore();
  }

  function drawRings(ox, oy) {
    ctx.save();
    ctx.translate(cx + ox, cy + oy);
    const extra = Math.sqrt(ox * ox + oy * oy);
    const diag  = Math.sqrt(W * W + H * H) + extra;
    const count = Math.ceil(diag / pitch) + 2;
    ctx.beginPath();
    for (let i = 1; i <= count; i++) {
      ctx.moveTo(i * pitch, 0);
      ctx.arc(0, 0, i * pitch, 0, Math.PI * 2);
    }
    ctx.strokeStyle = LINE_COLOR;
    ctx.lineWidth   = pitch * LINE_W_F;
    ctx.stroke();
    ctx.restore();
  }

  function drawGrid(theta) {
    const pattern = getGridPattern();
    if (!pattern) return;
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(theta);
    const diag = Math.sqrt(W * W + H * H);
    ctx.fillStyle = pattern;
    ctx.fillRect(-diag, -diag, diag * 2, diag * 2);
    ctx.restore();
  }

  function drawLayer(theta, ox, oy) {
    if (mode === 0) drawLines(theta);
    else if (mode === 1) drawRings(ox, oy);
    else drawGrid(theta);
  }

  /* ---- fringe spacing formula ---- */
  function fringeLabel() {
    if (mode === 1) {
      const d = Math.sqrt(ringOX * ringOX + ringOY * ringOY);
      return Math.round(d) + 'px off';
    }
    const a = Math.abs(angle);
    if (a < 0.0005) return '∞';
    return Math.round(pitch / (2 * Math.sin(a / 2))) + 'px';
  }

  /* ---- HUD ---- */
  const hudMode   = document.getElementById('hud-mode');
  const hudAngle  = document.getElementById('hud-angle');
  const hudPitch  = document.getElementById('hud-pitch');
  const hudFringe = document.getElementById('hud-fringe');

  function updateHUD() {
    hudMode.textContent  = MODE_NAMES[mode];
    if (mode === 1) {
      hudAngle.textContent = Math.round(Math.sqrt(ringOX * ringOX + ringOY * ringOY));
    } else {
      hudAngle.textContent = (angle * 180 / Math.PI).toFixed(1);
    }
    hudPitch.textContent  = pitch;
    hudFringe.textContent = fringeLabel();
  }

  /* ---- main draw ---- */
  function draw() {
    ctx.globalCompositeOperation = 'source-over';
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    ctx.globalCompositeOperation = 'lighter';
    drawLayer(0, 0, 0);               // Layer 1 – fixed
    drawLayer(angle, ringOX, ringOY); // Layer 2 – transformed
    ctx.globalCompositeOperation = 'source-over';
  }

  /* ---- animation loop ---- */
  function loop() {
    if (autoOn && !dragging) {
      if (mode === 0 || mode === 2) {
        autoAngle += AUTO_SPD * autoDir;
        if (autoAngle >  AUTO_MAX) autoDir = -1;
        if (autoAngle < -AUTO_MAX) autoDir =  1;
        angle = autoAngle;
      } else {
        autoRT += RING_SPD;
        ringOX = RING_R * Math.cos(autoRT);
        ringOY = RING_R * Math.sin(autoRT);
      }
    }
    draw();
    updateHUD();
    requestAnimationFrame(loop);
  }
  loop();

  /* ---- pointer drag ---- */
  function startDrag(x, y) {
    dragging  = true;
    dragPrevX = x;
    dragPrevY = y;
  }

  function moveDrag(x, y) {
    if (!dragging) return;
    const dx = x - dragPrevX;
    const dy = y - dragPrevY;
    dragPrevX = x;
    dragPrevY = y;
    if (mode === 1) {
      ringOX += dx;
      ringOY += dy;
    } else {
      angle      += dx * 0.005;
      autoAngle   = angle;
    }
    SFX.play('move', 50);
  }

  function endDrag() { dragging = false; }

  canvas.addEventListener('mousedown',  e => startDrag(e.clientX, e.clientY));
  canvas.addEventListener('mousemove',  e => moveDrag(e.clientX, e.clientY));
  canvas.addEventListener('mouseup',    endDrag);
  canvas.addEventListener('mouseleave', endDrag);

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    startDrag(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    moveDrag(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  canvas.addEventListener('touchend', e => { e.preventDefault(); endDrag(); }, { passive: false });

  /* ---- scroll → pitch ---- */
  canvas.addEventListener('wheel', e => {
    e.preventDefault();
    pitch = Math.max(10, Math.min(40, pitch - Math.sign(e.deltaY) * 2));
    document.getElementById('slider-pitch').value = pitch;
    gridPatternCache = null;
  }, { passive: false });

  /* ---- buttons ---- */
  document.getElementById('btn-mode').addEventListener('click', () => {
    mode      = (mode + 1) % 3;
    angle     = 0; autoAngle = 0; autoDir = 1;
    ringOX    = 0; ringOY    = 0; autoRT  = 0;
    gridPatternCache = null;
    SFX.play('toggle');
  });

  document.getElementById('btn-auto').addEventListener('click', function () {
    autoOn = !autoOn;
    this.textContent = autoOn ? 'AUTO ON' : 'AUTO OFF';
    SFX.play('toggle');
  });

  document.getElementById('slider-pitch').addEventListener('input', function () {
    pitch = parseInt(this.value);
    gridPatternCache = null;
  });

  document.getElementById('btn-reset').addEventListener('click', () => {
    mode     = 0; pitch   = 20;
    angle    = 0; autoAngle = 0; autoDir = 1;
    ringOX   = 0; ringOY  = 0;  autoRT  = 0;
    autoOn   = true;
    document.getElementById('btn-auto').textContent = 'AUTO ON';
    document.getElementById('slider-pitch').value   = 20;
    gridPatternCache = null;
    ctx.clearRect(0, 0, W, H);
    SFX.play('click');
  });
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
