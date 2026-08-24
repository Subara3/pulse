<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('metaball');

$pageName = 'metaball';
$pageTitle = 'METABALL - PULSE';
$pageDesc = 'メタボール。暗黙曲面の等値面が有機的に融合・分裂する。';
$pageCanonical = 'https://pulse.subara3.com/metaball.php';

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
  BLOBS: <span id="hudN" class="v">6</span><br>
  MODE: <span id="hudM" class="v">GLOW</span>
</div>

<div id="info">
  metaball<br>
  <span class="v">implicit surface · isosurface</span><br>
  f(x,y) = &Sigma; r&sup2;/d&sup2;
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">THRESHOLD</label>
    <div class="slider-row">
      <input type="range" id="sThresh" min="5" max="30" value="10">
      <span class="slider-value" id="vThresh">1.0</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SIZE</label>
    <div class="slider-row">
      <input type="range" id="sSize" min="20" max="150" value="60">
      <span class="slider-value" id="vSize">100%</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sSpeed" min="0" max="100" value="40">
      <span class="slider-value" id="vSpeed">0.40</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">MODE</label>
    <div class="btn-row">
      <button class="control-btn active" data-mode="0">GLOW</button>
      <button class="control-btn" data-mode="1">OUTLINE</button>
      <button class="control-btn" data-mode="2">FIELD</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, rW, rH, RES;
  var offC, offX, imgData;

  var threshold = 1.0;
  var sizeScale = 1.0;
  var speedMul = 0.40;
  var mode = 0;

  var blobs = [];
  var MAX_BLOBS = 30;
  var dragIdx = -1;
  var dragOffX = 0, dragOffY = 0;

  var hudN = document.getElementById('hudN');
  var hudM = document.getElementById('hudM');
  var modeNames = ['GLOW', 'OUTLINE', 'FIELD'];

  var PAL_SIZE = 1024;
  var pal = new Uint8Array(PAL_SIZE * 3);
  var _bx = new Float64Array(MAX_BLOBS);
  var _by = new Float64Array(MAX_BLOBS);
  var _br2 = new Float64Array(MAX_BLOBS);

  function createBlob(x, y) {
    return {
      x: x, y: y,
      vx: (Math.random() - 0.5) * 4,
      vy: (Math.random() - 0.5) * 4,
      r: 55 + Math.random() * 20
    };
  }

  function initBlobs() {
    blobs = [];
    var cx = W / 2, cy = H / 2;
    var spread = Math.min(W, H) * 0.18;
    for (var i = 0; i < 6; i++) {
      var angle = i * Math.PI * 2 / 6 + Math.random() * 0.5;
      var dist = spread * (0.4 + Math.random() * 0.8);
      blobs.push(createBlob(cx + Math.cos(angle) * dist, cy + Math.sin(angle) * dist));
    }
  }

  function lerp(a, b, t) { return a + (b - a) * t; }
  function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }

  function buildPalette() {
    var half = PAL_SIZE / 2;
    for (var i = 0; i < PAL_SIZE; i++) {
      var r, g, b;
      if (mode === 0) {
        if (i < half * 0.75) {
          var s = i / (half * 0.75);
          var s3 = s * s * s;
          r = lerp(10, 70, s3);
          g = lerp(10, 25, s3);
          b = lerp(15, 100, s3);
        } else if (i < half) {
          var s = (i - half * 0.75) / (half * 0.25);
          r = lerp(70, 0, s);
          g = lerp(25, 100, s);
          b = lerp(100, 140, s);
        } else if (i < half * 1.3) {
          var s = (i - half) / (half * 0.3);
          r = 0;
          g = lerp(100, 200, s);
          b = lerp(140, 180, s);
        } else {
          var s = clamp((i - half * 1.3) / (PAL_SIZE - half * 1.3), 0, 1);
          r = lerp(0, 20, s);
          g = lerp(200, 245, s);
          b = lerp(180, 212, s);
        }
      } else if (mode === 1) {
        var dist = Math.abs(i - half);
        var edgeW = 40;
        if (dist < edgeW) {
          var ef = 1.0 - dist / edgeW;
          ef = ef * ef;
          r = 0;
          g = ef * 245;
          b = ef * 212;
        } else if (i > half) {
          r = 14; g = 20; b = 28;
        } else {
          r = 10; g = 10; b = 15;
        }
      } else {
        if (i < PAL_SIZE * 0.25) {
          var s = i / (PAL_SIZE * 0.25);
          r = lerp(10, 55, s);
          g = lerp(10, 18, s);
          b = lerp(15, 90, s);
        } else if (i < half) {
          var s = (i - PAL_SIZE * 0.25) / (PAL_SIZE * 0.25);
          r = lerp(55, 123, s);
          g = lerp(18, 44, s);
          b = lerp(90, 191, s);
        } else if (i < PAL_SIZE * 0.75) {
          var s = (i - half) / (PAL_SIZE * 0.25);
          r = lerp(123, 0, s);
          g = lerp(44, 160, s);
          b = lerp(191, 170, s);
        } else {
          var s = (i - PAL_SIZE * 0.75) / (PAL_SIZE * 0.25);
          r = lerp(0, 0, s);
          g = lerp(160, 245, s);
          b = lerp(170, 212, s);
        }
      }
      pal[i * 3] = Math.round(clamp(r, 0, 255));
      pal[i * 3 + 1] = Math.round(clamp(g, 0, 255));
      pal[i * 3 + 2] = Math.round(clamp(b, 0, 255));
    }
  }

  function resize() {
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    RES = W < 768 ? 4 : 3;
    rW = Math.ceil(W / RES);
    rH = Math.ceil(H / RES);
    offC = document.createElement('canvas');
    offC.width = rW;
    offC.height = rH;
    offX = offC.getContext('2d');
    imgData = offX.createImageData(rW, rH);
  }

  function updateBlobs() {
    for (var i = 0; i < blobs.length; i++) {
      if (i === dragIdx) continue;
      var b = blobs[i];
      b.x += b.vx * speedMul;
      b.y += b.vy * speedMul;
      var r = b.r * sizeScale;
      if (b.x < r) { b.x = r; b.vx = Math.abs(b.vx); }
      if (b.x > W - r) { b.x = W - r; b.vx = -Math.abs(b.vx); }
      if (b.y < r) { b.y = r; b.vy = Math.abs(b.vy); }
      if (b.y > H - r) { b.y = H - r; b.vy = -Math.abs(b.vy); }
    }
  }

  function render() {
    var data = imgData.data;
    var nb = blobs.length;

    if (nb === 0) {
      for (var i = 0; i < data.length; i += 4) {
        data[i] = 10; data[i + 1] = 10; data[i + 2] = 15; data[i + 3] = 255;
      }
      offX.putImageData(imgData, 0, 0);
      X.drawImage(offC, 0, 0, rW, rH, 0, 0, W, H);
      return;
    }

    for (var i = 0; i < nb; i++) {
      _bx[i] = blobs[i].x / RES;
      _by[i] = blobs[i].y / RES;
      var r = blobs[i].r * sizeScale;
      _br2[i] = (r / RES) * (r / RES);
    }

    var norm = (PAL_SIZE - 1) / (threshold * 2);

    for (var py = 0; py < rH; py++) {
      var rowOff = py * rW;
      for (var px = 0; px < rW; px++) {
        var f = 0;
        for (var i = 0; i < nb; i++) {
          var dx = px - _bx[i];
          var dy = py - _by[i];
          var d2 = dx * dx + dy * dy;
          if (d2 < 1) d2 = 1;
          f += _br2[i] / d2;
        }

        var idx = (f * norm) | 0;
        if (idx > PAL_SIZE - 1) idx = PAL_SIZE - 1;

        var off = (rowOff + px) << 2;
        var pi = idx * 3;
        data[off] = pal[pi];
        data[off + 1] = pal[pi + 1];
        data[off + 2] = pal[pi + 2];
        data[off + 3] = 255;
      }
    }

    offX.putImageData(imgData, 0, 0);
    X.imageSmoothingEnabled = true;
    X.imageSmoothingQuality = 'high';
    X.drawImage(offC, 0, 0, rW, rH, 0, 0, W, H);
  }

  function updateHUD() {
    hudN.textContent = blobs.length;
    hudM.textContent = modeNames[mode];
  }

  function frame() {
    requestAnimationFrame(frame);
    updateBlobs();
    render();
    updateHUD();
  }

  function findBlob(mx, my) {
    for (var i = blobs.length - 1; i >= 0; i--) {
      var b = blobs[i];
      var dx = mx - b.x, dy = my - b.y;
      var r = b.r * sizeScale;
      if (dx * dx + dy * dy < r * r * 2) return i;
    }
    return -1;
  }

  function endDrag() {
    if (dragIdx >= 0) {
      blobs[dragIdx].vx = (Math.random() - 0.5) * 4;
      blobs[dragIdx].vy = (Math.random() - 0.5) * 4;
      dragIdx = -1;
    }
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) {
      e.preventDefault();
      var idx = findBlob(e.clientX, e.clientY);
      if (idx >= 0) {
        blobs.splice(idx, 1);
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      return;
    }
    if (e.button !== 0) return;
    var idx = findBlob(e.clientX, e.clientY);
    if (idx >= 0) {
      dragIdx = idx;
      dragOffX = e.clientX - blobs[idx].x;
      dragOffY = e.clientY - blobs[idx].y;
    } else if (blobs.length < MAX_BLOBS) {
      blobs.push(createBlob(e.clientX, e.clientY));
      dragIdx = blobs.length - 1;
      dragOffX = 0;
      dragOffY = 0;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (dragIdx >= 0) {
      blobs[dragIdx].x = e.clientX - dragOffX;
      blobs[dragIdx].y = e.clientY - dragOffY;
      blobs[dragIdx].vx = 0;
      blobs[dragIdx].vy = 0;
      if (typeof SFX !== 'undefined') SFX.play('move', 120);
    }
  });

  C.addEventListener('mouseup', endDrag);
  C.addEventListener('mouseleave', endDrag);
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchId = -1;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      var t = e.touches[0];
      touchId = t.identifier;
      var idx = findBlob(t.clientX, t.clientY);
      if (idx >= 0) {
        dragIdx = idx;
        dragOffX = t.clientX - blobs[idx].x;
        dragOffY = t.clientY - blobs[idx].y;
      } else if (blobs.length < MAX_BLOBS) {
        blobs.push(createBlob(t.clientX, t.clientY));
        dragIdx = blobs.length - 1;
        dragOffX = 0;
        dragOffY = 0;
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId && dragIdx >= 0) {
        blobs[dragIdx].x = e.touches[i].clientX - dragOffX;
        blobs[dragIdx].y = e.touches[i].clientY - dragOffY;
        blobs[dragIdx].vx = 0;
        blobs[dragIdx].vy = 0;
        if (typeof SFX !== 'undefined') SFX.play('move', 120);
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    endDrag();
    touchId = -1;
  }, { passive: false });

  var sThresh = document.getElementById('sThresh');
  var vThresh = document.getElementById('vThresh');
  var sSize = document.getElementById('sSize');
  var vSize = document.getElementById('vSize');
  var sSpeed = document.getElementById('sSpeed');
  var vSpeed = document.getElementById('vSpeed');

  sThresh.addEventListener('input', function() {
    threshold = this.value / 10;
    vThresh.textContent = threshold.toFixed(1);
    buildPalette();
  });

  sSize.addEventListener('input', function() {
    sizeScale = parseInt(this.value) / 60;
    vSize.textContent = Math.round(sizeScale * 100) + '%';
  });

  sSpeed.addEventListener('input', function() {
    speedMul = this.value / 100;
    vSpeed.textContent = speedMul.toFixed(2);
  });

  var modeBtns = document.querySelectorAll('[data-mode]');
  for (var i = 0; i < modeBtns.length; i++) {
    (function(btn) {
      btn.addEventListener('click', function() {
        mode = parseInt(this.getAttribute('data-mode'));
        for (var j = 0; j < modeBtns.length; j++) modeBtns[j].classList.remove('active');
        this.classList.add('active');
        buildPalette();
        if (typeof SFX !== 'undefined') SFX.play('toggle');
      });
    })(modeBtns[i]);
  }

  document.getElementById('btnReset').addEventListener('click', function() {
    initBlobs();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('btnClear').addEventListener('click', function() {
    blobs = [];
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  initBlobs();
  buildPalette();
  addEventListener('resize', resize);
  frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
