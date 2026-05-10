<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('interference');

$pageName = 'interference';
$pageTitle = 'INTERFERENCE - PULSE';
$pageDesc = '波の干渉パターン。複数の波源が重なり合い、強め合いと弱め合いの定在波模様を描く。';
$pageCanonical = 'https://pulse.subara3.com/interference.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
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
  SOURCES: <span id="hudS" class="v">0</span><br>
  WAVELENGTH: <span id="hudW" class="v">0</span>
</div>

<div id="info">
  wave superposition<br>
  <span class="v">constructive · destructive</span><br>
  standing wave patterns
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add source'],
  ['type' => 'drag', 'label' => 'move source'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">WAVELENGTH</label>
    <div class="slider-row">
      <input type="range" id="sWave" min="10" max="80" value="35">
      <span class="slider-value" id="vWave">35</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sSpeed" min="0" max="100" value="40">
      <span class="slider-value" id="vSpeed">2.0</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">AMPLITUDE</label>
    <div class="slider-row">
      <input type="range" id="sAmp" min="10" max="100" value="70">
      <span class="slider-value" id="vAmp">0.7</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnPause">PAUSE</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, gw, gh, scale = 3;
  var imgData, pix;

  function resize() {
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    gw = Math.ceil(W / scale);
    gh = Math.ceil(H / scale);
    imgData = X.createImageData(gw, gh);
    pix = imgData.data;
  }
  resize();
  addEventListener('resize', resize);

  var sources = [];
  var wavelength = 35, speed = 2.0, amplitude = 0.7;
  var time = 0, paused = false;
  var dragging = null, mx = 0, my = 0;
  var MAX_SOURCES = 12;

  function addSource(x, y) {
    if (sources.length >= MAX_SOURCES) return;
    sources.push({ x: x, y: y, phase: 0 });
    updateHud();
  }

  function initSources() {
    sources = [];
    var cx = W / 2, cy = H / 2;
    var dist = Math.min(W, H) * 0.2;
    addSource(cx - dist, cy);
    addSource(cx + dist, cy);
    addSource(cx, cy - dist);
  }

  function findSource(x, y, radius) {
    var best = -1, bd = radius * radius;
    for (var i = 0; i < sources.length; i++) {
      var dx = sources[i].x - x, dy = sources[i].y - y;
      var d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function updateHud() {
    document.getElementById('hudS').textContent = sources.length;
    document.getElementById('hudW').textContent = wavelength;
  }

  function render() {
    var k = 2 * Math.PI / wavelength;
    var omega = speed * 0.05;
    var ns = sources.length;
    if (ns === 0) {
      for (var i = 0; i < pix.length; i += 4) {
        pix[i] = 10; pix[i+1] = 10; pix[i+2] = 15; pix[i+3] = 255;
      }
      X.putImageData(imgData, 0, 0);
      X.drawImage(C, 0, 0, gw, gh, 0, 0, W, H);
      return;
    }

    var sx = [], sy = [];
    for (var i = 0; i < ns; i++) {
      sx[i] = sources[i].x / scale;
      sy[i] = sources[i].y / scale;
    }

    var t = time * omega;
    var idx = 0;
    for (var y = 0; y < gh; y++) {
      for (var x = 0; x < gw; x++) {
        var sum = 0;
        for (var i = 0; i < ns; i++) {
          var dx = x - sx[i], dy = y - sy[i];
          var r = Math.sqrt(dx * dx + dy * dy);
          sum += Math.sin(k * r - t + sources[i].phase);
        }
        sum = sum / ns * amplitude;

        var r, g, b;
        if (sum > 0) {
          var v = Math.min(sum, 1);
          r = Math.floor(10 + v * (0 - 10));
          g = Math.floor(10 + v * (245 - 10));
          b = Math.floor(15 + v * (212 - 15));
        } else {
          var v = Math.min(-sum, 1);
          r = Math.floor(10 + v * (123 - 10));
          g = Math.floor(10 + v * (44 - 10));
          b = Math.floor(15 + v * (191 - 15));
        }

        pix[idx]     = r;
        pix[idx + 1] = g;
        pix[idx + 2] = b;
        pix[idx + 3] = 255;
        idx += 4;
      }
    }

    X.putImageData(imgData, 0, 0);
    X.imageSmoothingEnabled = true;
    X.drawImage(C, 0, 0, gw, gh, 0, 0, W, H);

    X.save();
    for (var i = 0; i < ns; i++) {
      var s = sources[i];
      var pulse = 0.5 + 0.5 * Math.sin(t * 3 + i);
      X.beginPath();
      X.arc(s.x, s.y, 8 + pulse * 4, 0, Math.PI * 2);
      X.strokeStyle = 'rgba(0,245,212,' + (0.4 + pulse * 0.3) + ')';
      X.lineWidth = 1.5;
      X.stroke();
      X.beginPath();
      X.arc(s.x, s.y, 3, 0, Math.PI * 2);
      X.fillStyle = '#00f5d4';
      X.fill();
    }
    X.restore();
  }

  function loop() {
    if (!paused) time++;
    render();
    requestAnimationFrame(loop);
  }

  C.addEventListener('mousedown', function(e) {
    var ex = e.clientX, ey = e.clientY;
    if (e.button === 2) {
      var idx = findSource(ex, ey, 40);
      if (idx >= 0) {
        sources.splice(idx, 1);
        updateHud();
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      return;
    }
    var idx = findSource(ex, ey, 30);
    if (idx >= 0) {
      dragging = idx;
    } else {
      addSource(ex, ey);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  C.addEventListener('mousemove', function(e) {
    mx = e.clientX; my = e.clientY;
    if (dragging !== null && sources[dragging]) {
      sources[dragging].x = mx;
      sources[dragging].y = my;
    }
  });

  C.addEventListener('mouseup', function() {
    if (dragging !== null) {
      if (typeof SFX !== 'undefined') SFX.play('move', 100);
      dragging = null;
    }
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchId = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length >= 2) {
      var t0 = e.touches[0];
      var idx = findSource(t0.clientX, t0.clientY, 40);
      if (idx >= 0) {
        sources.splice(idx, 1);
        updateHud();
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      dragging = null;
      return;
    }
    var t = e.touches[0];
    mx = t.clientX; my = t.clientY;
    touchId = t.identifier;
    var idx = findSource(mx, my, 35);
    if (idx >= 0) {
      dragging = idx;
    } else {
      addSource(mx, my);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) {
        mx = e.touches[i].clientX;
        my = e.touches[i].clientY;
        if (dragging !== null && sources[dragging]) {
          sources[dragging].x = mx;
          sources[dragging].y = my;
        }
        break;
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    for (var i = 0; i < e.changedTouches.length; i++) {
      if (e.changedTouches[i].identifier === touchId) {
        if (dragging !== null) {
          if (typeof SFX !== 'undefined') SFX.play('move', 100);
          dragging = null;
        }
        touchId = null;
        break;
      }
    }
  });

  document.getElementById('sWave').addEventListener('input', function() {
    wavelength = parseInt(this.value);
    document.getElementById('vWave').textContent = wavelength;
    updateHud();
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  document.getElementById('sSpeed').addEventListener('input', function() {
    speed = this.value / 20;
    document.getElementById('vSpeed').textContent = speed.toFixed(1);
  });
  document.getElementById('sAmp').addEventListener('input', function() {
    amplitude = this.value / 100;
    document.getElementById('vAmp').textContent = amplitude.toFixed(1);
  });

  document.getElementById('btnPause').addEventListener('click', function() {
    paused = !paused;
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    this.classList.toggle('active', paused);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    time = 0;
    initSources();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  initSources();
  updateHud();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>