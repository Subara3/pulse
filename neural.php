<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('neural');

$pageName = 'neural';
$pageTitle = 'NEURAL - PULSE';
$pageDesc = 'ニューラルネットワークの分類境界をリアルタイムで可視化。点を置くと、ネットワークが境界を学習していく。';
$pageCanonical = 'https://pulse.subara3.com/neural.php';

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

<div class="hud" id="hud">
  POINTS: <span class="v" id="hud-points">0</span><br>
  EPOCH: <span class="v" id="hud-epoch">0</span><br>
  LOSS: <span class="v" id="hud-loss">-</span><br>
  ACC: <span class="v" id="hud-acc">-</span>
</div>

<div id="info">
  <span class="v">neural network</span><br>
  f(x) = σ(Wx + b)
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'class A'],
  ['type' => 'right', 'label' => 'class B'],
  ['type' => 'drag', 'label' => 'draw'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">LR</span>
      <input type="range" id="lr-slider" min="1" max="50" value="20">
      <span class="slider-value" id="lr-val">0.20</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">HIDDEN</span>
      <input type="range" id="hidden-slider" min="4" max="24" value="8" step="2">
      <span class="slider-value" id="hidden-val">8</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-clear">CLEAR</button>
    <button class="control-btn" id="btn-reset-net">RESET NET</button>
  </div>
  <div class="preset-group">
    <button class="preset-btn" id="preset-circle">● CIRCLE</button>
    <button class="preset-btn" id="preset-spiral">◎ SPIRAL</button>
    <button class="preset-btn" id="preset-xor">✕ XOR</button>
    <button class="preset-btn" id="preset-moons">☽ MOONS</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  window.addEventListener('resize', resize);
  resize();

  var TEAL = [0, 245, 212];
  var PURPLE = [123, 44, 191];
  var BG = [10, 10, 15];

  var points = [];
  var lr = 0.20;
  var hiddenSize = 8;
  var epoch = 0;
  var epochsPerFrame = 40;

  var net = null;
  var boundaryImg = null;
  var boundaryDirty = true;
  var gridRes = 4;

  function sigmoid(x) {
    if (x > 15) return 1;
    if (x < -15) return 0;
    return 1 / (1 + Math.exp(-x));
  }

  function sigmoidDeriv(s) {
    return s * (1 - s);
  }

  function relu(x) {
    return x > 0 ? x : 0.01 * x;
  }

  function reluDeriv(x) {
    return x > 0 ? 1 : 0.01;
  }

  function createNet(h) {
    var w1 = [], b1 = [];
    var w2 = [], b2 = [];
    var w3 = [], b3 = [0];
    for (var i = 0; i < h; i++) {
      w1.push([(Math.random() - 0.5) * 2, (Math.random() - 0.5) * 2]);
      b1.push((Math.random() - 0.5) * 0.5);
    }
    for (var i = 0; i < h; i++) {
      var row = [];
      for (var j = 0; j < h; j++) {
        row.push((Math.random() - 0.5) * Math.sqrt(2 / h));
      }
      w2.push(row);
      b2.push((Math.random() - 0.5) * 0.5);
    }
    for (var i = 0; i < h; i++) {
      w3.push((Math.random() - 0.5) * Math.sqrt(2 / h));
    }
    return { w1: w1, b1: b1, w2: w2, b2: b2, w3: w3, b3: b3, h: h };
  }

  function forward(net, x0, x1) {
    var h1 = [];
    for (var i = 0; i < net.h; i++) {
      h1.push(relu(net.w1[i][0] * x0 + net.w1[i][1] * x1 + net.b1[i]));
    }
    var h2 = [];
    for (var i = 0; i < net.h; i++) {
      var s = net.b2[i];
      for (var j = 0; j < net.h; j++) {
        s += net.w2[i][j] * h1[j];
      }
      h2.push(relu(s));
    }
    var out = net.b3[0];
    for (var i = 0; i < net.h; i++) {
      out += net.w3[i] * h2[i];
    }
    return { h1: h1, h2: h2, out: sigmoid(out) };
  }

  function train(net, pts, learningRate) {
    if (pts.length === 0) return 0;

    var totalLoss = 0;
    var indices = [];
    for (var i = 0; i < pts.length; i++) indices.push(i);
    for (var i = indices.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var tmp = indices[i]; indices[i] = indices[j]; indices[j] = tmp;
    }

    var batchSize = Math.min(32, pts.length);
    for (var b = 0; b < batchSize; b++) {
      var p = pts[indices[b]];
      var x0 = p.x, x1 = p.y, target = p.c;
      var fwd = forward(net, x0, x1);
      var pred = fwd.out;
      var h1 = fwd.h1;
      var h2 = fwd.h2;

      var eps = 1e-7;
      totalLoss += -(target * Math.log(pred + eps) + (1 - target) * Math.log(1 - pred + eps));

      var dout = pred - target;

      var dw3 = [];
      var db3 = dout;
      var dh2 = [];
      for (var i = 0; i < net.h; i++) {
        dw3.push(dout * h2[i]);
        dh2.push(dout * net.w3[i] * reluDeriv(h2[i]));
      }

      var dw2 = [];
      var db2 = [];
      var dh1 = [];
      for (var i = 0; i < net.h; i++) dh1.push(0);

      for (var i = 0; i < net.h; i++) {
        var row = [];
        for (var j = 0; j < net.h; j++) {
          row.push(dh2[i] * h1[j]);
          dh1[j] += dh2[i] * net.w2[i][j];
        }
        dw2.push(row);
        db2.push(dh2[i]);
      }

      for (var i = 0; i < net.h; i++) {
        dh1[i] *= reluDeriv(h1[i]);
      }

      var scale = learningRate / batchSize;
      for (var i = 0; i < net.h; i++) {
        net.w3[i] -= scale * dw3[i];
        net.b2[i] -= scale * db2[i];
        net.w1[i][0] -= scale * dh1[i] * x0;
        net.w1[i][1] -= scale * dh1[i] * x1;
        net.b1[i] -= scale * dh1[i];
        for (var j = 0; j < net.h; j++) {
          net.w2[i][j] -= scale * dw2[i][j];
        }
      }
      net.b3[0] -= scale * db3;
    }

    return totalLoss / batchSize;
  }

  function predict(net, x0, x1) {
    return forward(net, x0, x1).out;
  }

  function initNet() {
    net = createNet(hiddenSize);
    epoch = 0;
    boundaryDirty = true;
  }
  initNet();

  function toNorm(px, py) {
    var s = Math.min(W, H);
    return {
      x: (px - W / 2) / (s * 0.4),
      y: (py - H / 2) / (s * 0.4)
    };
  }

  function toScreen(nx, ny) {
    var s = Math.min(W, H);
    return {
      x: nx * s * 0.4 + W / 2,
      y: ny * s * 0.4 + H / 2
    };
  }

  function addPoint(px, py, cls) {
    var n = toNorm(px, py);
    points.push({ x: n.x, y: n.y, c: cls });
    boundaryDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function clearPoints() {
    points = [];
    initNet();
    boundaryDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('click');
  }

  function generateCircle() {
    clearPoints();
    for (var i = 0; i < 80; i++) {
      var a = Math.random() * Math.PI * 2;
      var r = Math.random() * 0.5;
      var nx = Math.cos(a) * r;
      var ny = Math.sin(a) * r;
      points.push({ x: nx, y: ny, c: r < 0.25 ? 0 : 1 });
    }
    boundaryDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  function generateSpiral() {
    clearPoints();
    for (var i = 0; i < 100; i++) {
      var t = i / 100 * 3 * Math.PI;
      var r = t / (3 * Math.PI) * 0.8;
      var nx = Math.cos(t) * r + (Math.random() - 0.5) * 0.08;
      var ny = Math.sin(t) * r + (Math.random() - 0.5) * 0.08;
      points.push({ x: nx, y: ny, c: 0 });
    }
    for (var i = 0; i < 100; i++) {
      var t = i / 100 * 3 * Math.PI + Math.PI;
      var r = t / (3 * Math.PI + Math.PI) * 0.8;
      var nx = Math.cos(t) * r + (Math.random() - 0.5) * 0.08;
      var ny = Math.sin(t) * r + (Math.random() - 0.5) * 0.08;
      points.push({ x: nx, y: ny, c: 1 });
    }
    boundaryDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  function generateXOR() {
    clearPoints();
    for (var i = 0; i < 120; i++) {
      var nx = (Math.random() - 0.5) * 2;
      var ny = (Math.random() - 0.5) * 2;
      var cls = (nx * ny > 0) ? 0 : 1;
      points.push({ x: nx, y: ny, c: cls });
    }
    boundaryDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  function generateMoons() {
    clearPoints();
    for (var i = 0; i < 100; i++) {
      var a = Math.random() * Math.PI;
      var r = 0.6 + (Math.random() - 0.5) * 0.15;
      points.push({ x: Math.cos(a) * r, y: Math.sin(a) * r, c: 0 });
    }
    for (var i = 0; i < 100; i++) {
      var a = Math.PI + Math.random() * Math.PI;
      var r = 0.6 + (Math.random() - 0.5) * 0.15;
      points.push({ x: Math.cos(a) * r + 0.5, y: Math.sin(a) * r + 0.3, c: 1 });
    }
    boundaryDirty = true;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  var isDrawing = false;
  var drawClass = 0;
  var lastDrawTime = 0;

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) {
      isDrawing = true;
      drawClass = 1;
      addPoint(e.clientX, e.clientY, 1);
    } else {
      isDrawing = true;
      drawClass = 0;
      addPoint(e.clientX, e.clientY, 0);
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (!isDrawing) return;
    var now = Date.now();
    if (now - lastDrawTime < 60) return;
    lastDrawTime = now;
    addPoint(e.clientX, e.clientY, drawClass);
  });

  window.addEventListener('mouseup', function() {
    isDrawing = false;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
  });

  var lastTouchId = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    isDrawing = true;
    drawClass = 0;
    lastTouchId = t.identifier;
    addPoint(t.clientX, t.clientY, 0);
    if (e.touches.length > 1) {
      drawClass = 1;
      var t2 = e.touches[1];
      addPoint(t2.clientX, t2.clientY, 1);
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!isDrawing) return;
    var now = Date.now();
    if (now - lastDrawTime < 60) return;
    lastDrawTime = now;
    for (var i = 0; i < e.touches.length; i++) {
      var t = e.touches[i];
      var cls = i === 0 ? drawClass : 1;
      addPoint(t.clientX, t.clientY, cls);
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) isDrawing = false;
  });

  document.getElementById('btn-clear').addEventListener('click', clearPoints);
  document.getElementById('btn-reset-net').addEventListener('click', function() {
    initNet();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
  document.getElementById('preset-circle').addEventListener('click', generateCircle);
  document.getElementById('preset-spiral').addEventListener('click', generateSpiral);
  document.getElementById('preset-xor').addEventListener('click', generateXOR);
  document.getElementById('preset-moons').addEventListener('click', generateMoons);

  var lrSlider = document.getElementById('lr-slider');
  var lrVal = document.getElementById('lr-val');
  lrSlider.addEventListener('input', function() {
    lr = parseInt(lrSlider.value) / 100;
    lrVal.textContent = lr.toFixed(2);
  });

  var hiddenSlider = document.getElementById('hidden-slider');
  var hiddenVal = document.getElementById('hidden-val');
  hiddenSlider.addEventListener('input', function() {
    hiddenSize = parseInt(hiddenSlider.value);
    hiddenVal.textContent = hiddenSize;
    initNet();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  function renderBoundary() {
    var res = gridRes;
    var gw = Math.ceil(W / res);
    var gh = Math.ceil(H / res);
    if (!boundaryImg || boundaryImg.width !== gw || boundaryImg.height !== gh) {
      boundaryImg = ctx.createImageData(gw, gh);
    }
    var data = boundaryImg.data;
    for (var gy = 0; gy < gh; gy++) {
      for (var gx = 0; gx < gw; gx++) {
        var px = gx * res + res / 2;
        var py = gy * res + res / 2;
        var n = toNorm(px, py);
        var p = predict(net, n.x, n.y);
        var idx = (gy * gw + gx) * 4;

        var r = BG[0] + (TEAL[0] - BG[0]) * (1 - p) * 0.25 + (PURPLE[0] - BG[0]) * p * 0.25;
        var g = BG[1] + (TEAL[1] - BG[1]) * (1 - p) * 0.25 + (PURPLE[1] - BG[1]) * p * 0.25;
        var b = BG[2] + (TEAL[2] - BG[2]) * (1 - p) * 0.25 + (PURPLE[2] - BG[2]) * p * 0.25;

        var edge = Math.abs(p - 0.5);
        var edgeFactor = Math.max(0, 1 - edge * 6);
        r += 255 * edgeFactor * 0.15;
        g += 255 * edgeFactor * 0.15;
        b += 255 * edgeFactor * 0.15;

        data[idx] = Math.min(255, r | 0);
        data[idx + 1] = Math.min(255, g | 0);
        data[idx + 2] = Math.min(255, b | 0);
        data[idx + 3] = 255;
      }
    }
    return { img: boundaryImg, gw: gw, gh: gh };
  }

  function drawNetGraph() {
    if (!net) return;
    var gx = 70;
    var gy = H - 140;
    var gw = 140;
    var gh = 100;
    var layers = [2, net.h, net.h, 1];
    var layerX = [];
    for (var l = 0; l < layers.length; l++) {
      layerX.push(gx + (l / (layers.length - 1)) * gw);
    }

    ctx.globalAlpha = 0.08;
    for (var l = 0; l < layers.length - 1; l++) {
      for (var i = 0; i < layers[l]; i++) {
        for (var j = 0; j < layers[l + 1]; j++) {
          var y1 = gy + ((i + 0.5) / layers[l]) * gh;
          var y2 = gy + ((j + 0.5) / layers[l + 1]) * gh;
          var w;
          if (l === 0) w = net.w1[j][i];
          else if (l === 1) w = net.w2[j][i];
          else w = net.w3[j];
          var absW = Math.min(Math.abs(w), 3) / 3;
          ctx.strokeStyle = w > 0 ? 'rgba(0,245,212,' + (0.2 + absW * 0.8) + ')' : 'rgba(123,44,191,' + (0.2 + absW * 0.8) + ')';
          ctx.lineWidth = 0.5 + absW * 1.5;
          ctx.globalAlpha = 0.15 + absW * 0.35;
          ctx.beginPath();
          ctx.moveTo(layerX[l], y1);
          ctx.lineTo(layerX[l + 1], y2);
          ctx.stroke();
        }
      }
    }
    ctx.globalAlpha = 1;

    for (var l = 0; l < layers.length; l++) {
      for (var i = 0; i < layers[l]; i++) {
        var nx = layerX[l];
        var ny = gy + ((i + 0.5) / layers[l]) * gh;
        var r = 3;
        ctx.fillStyle = l === 0 ? 'rgba(255,255,255,0.5)' : l === layers.length - 1 ? '#00f5d4' : 'rgba(0,245,212,0.35)';
        ctx.beginPath();
        ctx.arc(nx, ny, r, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  var prevLoss = Infinity;
  var successPlayed = false;

  function draw() {
    requestAnimationFrame(draw);

    if (points.length >= 2) {
      var loss = 0;
      for (var e = 0; e < epochsPerFrame; e++) {
        loss = train(net, points, lr);
        epoch++;
      }
      boundaryDirty = true;

      var correct = 0;
      for (var i = 0; i < points.length; i++) {
        var p = predict(net, points[i].x, points[i].y);
        if ((p >= 0.5 && points[i].c === 1) || (p < 0.5 && points[i].c === 0)) correct++;
      }
      var acc = correct / points.length;

      document.getElementById('hud-epoch').textContent = epoch;
      document.getElementById('hud-loss').textContent = loss.toFixed(3);
      document.getElementById('hud-acc').textContent = (acc * 100).toFixed(0) + '%';

      if (acc >= 0.99 && !successPlayed) {
        successPlayed = true;
        if (typeof SFX !== 'undefined') SFX.play('complete');
      }
      if (acc < 0.95) successPlayed = false;
    }

    document.getElementById('hud-points').textContent = points.length;

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (points.length >= 2 && net) {
      var bd = renderBoundary();
      var tmpC = document.createElement('canvas');
      tmpC.width = bd.gw;
      tmpC.height = bd.gh;
      var tmpCtx = tmpC.getContext('2d');
      tmpCtx.putImageData(bd.img, 0, 0);
      ctx.imageSmoothingEnabled = true;
      ctx.drawImage(tmpC, 0, 0, W, H);
    }

    for (var i = 0; i < points.length; i++) {
      var p = points[i];
      var s = toScreen(p.x, p.y);
      var col = p.c === 0 ? TEAL : PURPLE;
      ctx.fillStyle = 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',0.9)';
      ctx.beginPath();
      ctx.arc(s.x, s.y, 4, 0, Math.PI * 2);
      ctx.fill();
      ctx.strokeStyle = 'rgba(255,255,255,0.4)';
      ctx.lineWidth = 0.8;
      ctx.stroke();
    }

    drawNetGraph();

    if (points.length < 2) {
      ctx.fillStyle = 'rgba(255,255,255,0.15)';
      ctx.font = '12px "Orbitron", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('CLICK TO PLACE POINTS', W / 2, H / 2 - 10);
      ctx.font = '10px "JetBrains Mono", monospace';
      ctx.fillStyle = 'rgba(255,255,255,0.1)';
      ctx.fillText('LEFT = CLASS A   RIGHT = CLASS B', W / 2, H / 2 + 10);
    }
  }

  generateMoons();
  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
