<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('transport');

$pageName = 'transport';
$pageTitle = 'TRANSPORT - PULSE';
$pageDesc = '最適輸送。二つの分布を最小コストで結ぶ割り当てを求め、粒子の集団流動として描く。';
$pageCanonical = 'https://pulse.subara3.com/transport.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>N <span id="hud-n">0</span></div>
  <div>W&#x2082; <span id="hud-w2">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'attract'],
  ['type' => 'right', 'label' => 'next'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">N</label>
    <input type="range" id="sl-n" min="30" max="400" value="150" step="10">
    <span class="slider-value" id="sv-n">150</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="20" value="8" step="1">
    <span class="slider-value" id="sv-speed">8</span>
  </div>
  <div class="preset-group">
    <button class="preset-btn" data-p="random">RANDOM</button>
    <button class="preset-btn" data-p="circle">CIRCLE</button>
    <button class="preset-btn" data-p="grid">GRID</button>
    <button class="preset-btn" data-p="gauss">GAUSS</button>
    <button class="preset-btn" data-p="spiral">SPIRAL</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-auto">AUTO</button>
    <button class="ctrl-btn active" id="btn-method">OPTIMAL</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, N = 150, speed = 0.004;
  var src = [], tgt = [], assign = [], phase = [];
  var offX, offY;
  var t = 0, dwell = 0, DWELL = 50;
  var animating = true, optimal = true;
  var pDown = false, pX = 0, pY = 0;
  var shapes = ['grid', 'random', 'gauss', 'spiral', 'ring', 'circle'];
  var sIdx = 0;
  var hudN = document.getElementById('hud-n');
  var hudW2 = document.getElementById('hud-w2');

  function resize() {
    var d = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * d; C.height = H * d;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(d, 0, 0, d, 0, 0);
  }

  function rndDist(n) {
    var m = 60, p = [];
    for (var i = 0; i < n; i++)
      p.push({x: m + Math.random() * (W - 2 * m), y: m + Math.random() * (H - 2 * m)});
    return p;
  }
  function cirDist(n, cx, cy, r) {
    var p = [];
    for (var i = 0; i < n; i++) {
      var a = i / n * 6.2832;
      p.push({x: cx + r * Math.cos(a), y: cy + r * Math.sin(a)});
    }
    return p;
  }
  function grdDist(n, cx, cy, sz) {
    var p = [], s = Math.ceil(Math.sqrt(n)), st = sz / s;
    var ox = cx - sz / 2, oy = cy - sz / 2;
    for (var i = 0; i < n; i++)
      p.push({x: ox + (i % s + 0.5) * st, y: oy + (Math.floor(i / s) + 0.5) * st});
    return p;
  }
  function gauDist(n, cx, cy, sig) {
    var p = [];
    for (var i = 0; i < n; i++) {
      var u = Math.random() || 1e-4, v = Math.random() * 6.2832;
      var r = Math.sqrt(-2 * Math.log(u)) * sig;
      p.push({x: cx + r * Math.cos(v), y: cy + r * Math.sin(v)});
    }
    return p;
  }
  function spiDist(n, cx, cy, r) {
    var p = [];
    for (var i = 0; i < n; i++) {
      var f = i / n, a = f * 4 * Math.PI;
      p.push({x: cx + r * f * Math.cos(a), y: cy + r * f * Math.sin(a)});
    }
    return p;
  }
  function rngDist(n, cx, cy, r1, r2) {
    var p = [];
    for (var i = 0; i < n; i++) {
      var a = Math.random() * 6.2832;
      var r = Math.sqrt(r1 * r1 + Math.random() * (r2 * r2 - r1 * r1));
      p.push({x: cx + r * Math.cos(a), y: cy + r * Math.sin(a)});
    }
    return p;
  }

  function genShape(name) {
    var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.32;
    switch (name) {
      case 'random': return rndDist(N);
      case 'circle': return cirDist(N, cx, cy, r);
      case 'grid':   return grdDist(N, cx, cy, r * 1.5);
      case 'gauss':  return gauDist(N, cx, cy, r * 0.28);
      case 'spiral': return spiDist(N, cx, cy, r);
      case 'ring':   return rngDist(N, cx, cy, r * 0.5, r * 0.85);
      default:       return rndDist(N);
    }
  }

  function d2(a, b) { var dx = a.x - b.x, dy = a.y - b.y; return dx * dx + dy * dy; }
  function ease(v) { return v * v * (3 - 2 * v); }

  function solve() {
    var n = Math.min(src.length, tgt.length);
    if (n === 0) { assign = []; phase = []; return; }
    if (!optimal) {
      assign = [];
      for (var i = 0; i < n; i++) assign.push(i);
      for (var i = n - 1; i > 0; i--) {
        var j = (Math.random() * (i + 1)) | 0;
        var tmp = assign[i]; assign[i] = assign[j]; assign[j] = tmp;
      }
    } else {
      assign = new Array(n);
      var used = new Uint8Array(n);
      for (var i = 0; i < n; i++) {
        var bj = -1, bd = 1e18;
        for (var j = 0; j < n; j++) {
          if (used[j]) continue;
          var dd = d2(src[i], tgt[j]);
          if (dd < bd) { bd = dd; bj = j; }
        }
        assign[i] = bj; used[bj] = 1;
      }
      for (var pass = 0; pass < 3; pass++) {
        var imp = false;
        for (var i = 0; i < n - 1; i++) {
          for (var j = i + 1; j < n; j++) {
            var c1 = d2(src[i], tgt[assign[i]]) + d2(src[j], tgt[assign[j]]);
            var c2 = d2(src[i], tgt[assign[j]]) + d2(src[j], tgt[assign[i]]);
            if (c2 < c1 - 1e-10) {
              var tmp = assign[i]; assign[i] = assign[j]; assign[j] = tmp;
              imp = true;
            }
          }
        }
        if (!imp) break;
      }
    }
    phase = [];
    var cost = 0;
    for (var i = 0; i < n; i++) {
      phase.push(Math.random() * 0.12);
      cost += d2(src[i], tgt[assign[i]]);
    }
    offX = new Float64Array(n);
    offY = new Float64Array(n);
    hudN.textContent = n;
    hudW2.textContent = Math.sqrt(cost / n).toFixed(0);
  }

  function morphTo(name) {
    var n = src.length;
    if (n > 0 && assign.length >= n && t > 0.01) {
      var ns = [];
      for (var i = 0; i < n; i++) {
        var j = assign[i];
        var lt = Math.max(0, Math.min(1, t * 1.12 - phase[i]));
        var e = ease(lt);
        ns.push({x: src[i].x + (tgt[j].x - src[i].x) * e, y: src[i].y + (tgt[j].y - src[i].y) * e});
      }
      src = ns;
    }
    tgt = genShape(name);
    if (src.length !== tgt.length) src = genShape('circle');
    solve();
    t = 0; dwell = 0;
  }

  function nextS() {
    sIdx = (sIdx + 1) % shapes.length;
    morphTo(shapes[sIdx]);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  function init() {
    var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.32;
    src = cirDist(N, cx, cy, r);
    tgt = genShape('grid');
    solve();
    t = 0; dwell = 0; sIdx = 0;
  }

  function render() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);
    var n = Math.min(src.length, tgt.length);
    if (n === 0) return;

    X.beginPath();
    X.strokeStyle = 'rgba(0,245,212,0.035)';
    X.lineWidth = 0.5;
    for (var i = 0; i < n; i++) {
      var j = assign[i];
      X.moveTo(src[i].x, src[i].y);
      X.lineTo(tgt[j].x, tgt[j].y);
    }
    X.stroke();

    X.fillStyle = 'rgba(0,245,212,0.1)';
    X.beginPath();
    for (var i = 0; i < n; i++) {
      X.moveTo(src[i].x + 1.5, src[i].y);
      X.arc(src[i].x, src[i].y, 1.5, 0, 6.2832);
    }
    X.fill();

    X.fillStyle = 'rgba(123,44,191,0.1)';
    X.beginPath();
    for (var i = 0; i < n; i++) {
      X.moveTo(tgt[i].x + 1.5, tgt[i].y);
      X.arc(tgt[i].x, tgt[i].y, 1.5, 0, 6.2832);
    }
    X.fill();

    for (var i = 0; i < n; i++) {
      var j = assign[i];
      var lt = Math.max(0, Math.min(1, t * 1.12 - phase[i]));
      var e = ease(lt);
      var px = src[i].x + (tgt[j].x - src[i].x) * e + offX[i];
      var py = src[i].y + (tgt[j].y - src[i].y) * e + offY[i];
      var cr = (123 * e) | 0;
      var cg = (245 - 201 * e) | 0;
      var cb = (212 - 21 * e) | 0;

      X.beginPath();
      X.moveTo(src[i].x, src[i].y);
      X.lineTo(px, py);
      X.strokeStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',0.08)';
      X.lineWidth = 1;
      X.stroke();

      X.beginPath();
      X.arc(px, py, 5, 0, 6.2832);
      X.fillStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',0.1)';
      X.fill();

      X.beginPath();
      X.arc(px, py, 2.5, 0, 6.2832);
      X.fillStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',0.85)';
      X.fill();
    }
  }

  function gp(e) {
    return e.touches && e.touches.length > 0
      ? {x: e.touches[0].clientX, y: e.touches[0].clientY}
      : {x: e.clientX, y: e.clientY};
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault(); pDown = true;
    var p = gp(e); pX = p.x; pY = p.y;
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  });
  C.addEventListener('mousemove', function(e) {
    if (!pDown) return;
    var p = gp(e); pX = p.x; pY = p.y;
    if (typeof SFX !== 'undefined') SFX.play('move', 100);
  });
  C.addEventListener('mouseup', function() { pDown = false; });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    nextS();
  });
  C.addEventListener('touchstart', function(e) {
    e.preventDefault(); pDown = true;
    var p = gp(e); pX = p.x; pY = p.y;
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var p = gp(e); pX = p.x; pY = p.y;
    if (typeof SFX !== 'undefined') SFX.play('move', 100);
  }, {passive: false});
  C.addEventListener('touchend', function() { pDown = false; });

  var slN = document.getElementById('sl-n');
  var svN = document.getElementById('sv-n');
  slN.addEventListener('input', function() { svN.textContent = this.value; });
  slN.addEventListener('change', function() { N = parseInt(this.value); init(); });

  var slSp = document.getElementById('sl-speed');
  var svSp = document.getElementById('sv-speed');
  slSp.addEventListener('input', function() {
    speed = parseInt(this.value) * 0.0005;
    svSp.textContent = this.value;
  });

  document.querySelectorAll('.preset-btn').forEach(function(b) {
    b.addEventListener('click', function() {
      morphTo(this.dataset.p);
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('btn-auto').addEventListener('click', function() {
    animating = !animating;
    this.classList.toggle('active', animating);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-method').addEventListener('click', function() {
    optimal = !optimal;
    this.textContent = optimal ? 'OPTIMAL' : 'RANDOM';
    this.classList.toggle('active', optimal);
    solve(); t = 0; dwell = 0;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', function() { resize(); init(); });
  resize();
  init();

  (function loop() {
    if (animating && src.length > 0) {
      if (t >= 1) {
        dwell++;
        if (dwell >= DWELL) nextS();
      } else {
        t += speed;
        if (t > 1) t = 1;
      }
    }
    var n = src.length;
    if (offX) {
      for (var i = 0; i < n; i++) { offX[i] *= 0.93; offY[i] *= 0.93; }
      if (pDown) {
        for (var i = 0; i < n; i++) {
          var j = assign[i];
          var lt = Math.max(0, Math.min(1, t * 1.12 - phase[i]));
          var e = ease(lt);
          var px = src[i].x + (tgt[j].x - src[i].x) * e + offX[i];
          var py = src[i].y + (tgt[j].y - src[i].y) * e + offY[i];
          var dx = pX - px, dy = pY - py;
          var dd = Math.sqrt(dx * dx + dy * dy) + 1;
          if (dd < 120) {
            var f = (1 - dd / 120) * 3;
            offX[i] += dx / dd * f;
            offY[i] += dy / dd * f;
          }
        }
      }
    }
    render();
    requestAnimationFrame(loop);
  })();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
