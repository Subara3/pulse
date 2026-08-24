<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('scalefree');

$pageName = 'scalefree';
$pageTitle = 'SCALE-FREE - PULSE';
$pageDesc = 'スケールフリーネットワーク。優先的選択で新ノードがハブに引き寄せられ、べき乗則の次数分布が創発する。';
$pageCanonical = 'https://pulse.subara3.com/scalefree.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>NODES <span id="hud-nodes">0</span></div>
  <div>EDGES <span id="hud-edges">0</span></div>
  <div>MAX DEG <span id="hud-maxdeg">0</span></div>
  <div>γ <span id="hud-gamma">—</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add node'],
  ['type' => 'drag', 'label' => 'move node'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-pref">PREFERENTIAL</button>
    <button class="ctrl-btn" id="btn-random">RANDOM</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">EDGES/NODE</label>
    <input type="range" id="sl-m" min="1" max="5" value="2">
    <span class="slider-value" id="sv-m">2</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="10" value="4">
    <span class="slider-value" id="sv-speed">4</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-play">AUTO</button>
    <button class="ctrl-btn" id="btn-dist">DIST</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H;

  var hudN = document.getElementById('hud-nodes');
  var hudE = document.getElementById('hud-edges');
  var hudMD = document.getElementById('hud-maxdeg');
  var hudG = document.getElementById('hud-gamma');

  var nodes = [];
  var edges = [];
  var adj = [];
  var m = 2;
  var autoPlay = true;
  var prefMode = true;
  var showDist = false;
  var speed = 4;
  var tick = 0;
  var dragIdx = -1;
  var maxN = 300;
  var muted = true;

  function resize() {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function init() {
    nodes = [];
    edges = [];
    adj = [];
    tick = 0;

    var m0 = Math.max(m + 1, 3);
    var cx = W / 2, cy = H / 2;
    var r = Math.min(W, H) * 0.04;

    for (var i = 0; i < m0; i++) {
      var ang = (i / m0) * Math.PI * 2 - Math.PI / 2;
      nodes.push({
        x: cx + Math.cos(ang) * r + (Math.random() - 0.5) * 2,
        y: cy + Math.sin(ang) * r + (Math.random() - 0.5) * 2,
        vx: 0, vy: 0, deg: 0, glow: 0
      });
      adj.push([]);
    }

    for (var i = 0; i < m0; i++) {
      for (var j = i + 1; j < m0; j++) {
        edges.push({a: i, b: j});
        adj[i].push(j);
        adj[j].push(i);
        nodes[i].deg++;
        nodes[j].deg++;
      }
    }
  }

  function addNode(px, py) {
    if (nodes.length >= maxN) return;
    var N = nodes.length;

    var pool = [];
    for (var i = 0; i < N; i++) pool.push(i);
    var mm = Math.min(m, N);
    var targets = [];

    for (var k = 0; k < mm; k++) {
      if (pool.length === 0) break;
      var ci;

      if (prefMode) {
        var cum = [];
        var sum = 0;
        for (var i = 0; i < pool.length; i++) {
          sum += Math.max(nodes[pool[i]].deg, 0.5);
          cum.push(sum);
        }
        var rv = Math.random() * sum;
        ci = pool.length - 1;
        for (var i = 0; i < cum.length; i++) {
          if (rv <= cum[i]) { ci = i; break; }
        }
      } else {
        ci = Math.floor(Math.random() * pool.length);
      }

      targets.push(pool[ci]);
      pool.splice(ci, 1);
    }

    if (px === undefined && targets.length > 0) {
      var ti = targets[Math.floor(Math.random() * targets.length)];
      px = nodes[ti].x + (Math.random() - 0.5) * 60;
      py = nodes[ti].y + (Math.random() - 0.5) * 60;
    }
    if (px === undefined) {
      px = W / 2 + (Math.random() - 0.5) * 80;
      py = H / 2 + (Math.random() - 0.5) * 80;
    }
    px = Math.max(20, Math.min(W - 20, px));
    py = Math.max(75, Math.min(H - 20, py));

    var idx = N;
    nodes.push({x: px, y: py, vx: 0, vy: 0, deg: 0, glow: 1});
    adj.push([]);

    for (var k = 0; k < targets.length; k++) {
      var t = targets[k];
      edges.push({a: idx, b: t});
      adj[idx].push(t);
      adj[t].push(idx);
      nodes[idx].deg++;
      nodes[t].deg++;
      nodes[t].glow = Math.max(nodes[t].glow, 0.4);
    }

    if (!muted && typeof SFX !== 'undefined') SFX.play('spawn', 80);
  }

  function layout() {
    var N = nodes.length;
    if (N < 2) return;

    var k = Math.sqrt(W * H / N) * 0.4;
    var k2 = k * k;
    var sc = 0.008;
    var cutoff = k * 3.5;
    var cut2 = cutoff * cutoff;
    var cx = W / 2, cy = H / 2;

    for (var i = 0; i < N; i++) {
      if (i === dragIdx) continue;
      var ni = nodes[i];
      var fx = 0, fy = 0;

      for (var j = 0; j < N; j++) {
        if (i === j) continue;
        var dx = ni.x - nodes[j].x;
        var dy = ni.y - nodes[j].y;
        var d2 = dx * dx + dy * dy;
        if (d2 > cut2) continue;
        if (d2 < 4) d2 = 4;
        var d = Math.sqrt(d2);
        var f = k2 / d * sc;
        if (f > 6) f = 6;
        fx += (dx / d) * f;
        fy += (dy / d) * f;
      }

      var neighbors = adj[i];
      for (var j = 0; j < neighbors.length; j++) {
        var nj = nodes[neighbors[j]];
        var dx = nj.x - ni.x;
        var dy = nj.y - ni.y;
        var d = Math.sqrt(dx * dx + dy * dy);
        if (d < 0.1) continue;
        var f = d * d / k * sc;
        if (f > 6) f = 6;
        fx += (dx / d) * f;
        fy += (dy / d) * f;
      }

      fx += (cx - ni.x) * 0.0005;
      fy += (cy - ni.y) * 0.0005;

      ni.vx = (ni.vx + fx) * 0.82;
      ni.vy = (ni.vy + fy) * 0.82;
    }

    for (var i = 0; i < N; i++) {
      if (i === dragIdx) continue;
      nodes[i].x += nodes[i].vx;
      nodes[i].y += nodes[i].vy;
      nodes[i].x = Math.max(20, Math.min(W - 20, nodes[i].x));
      nodes[i].y = Math.max(75, Math.min(H - 20, nodes[i].y));
    }
  }

  function getMaxDeg() {
    var mx = 0;
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].deg > mx) mx = nodes[i].deg;
    }
    return mx;
  }

  function estGamma() {
    if (nodes.length < 30) return null;
    var kmin = Math.max(m, 1);
    var s = 0, cnt = 0;
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].deg >= kmin) {
        s += Math.log(nodes[i].deg / (kmin - 0.5));
        cnt++;
      }
    }
    if (cnt < 15 || s < 0.1) return null;
    return 1 + cnt / s;
  }

  function drawDist() {
    if (nodes.length < 5) return;
    var md = getMaxDeg();
    if (md < 1) return;

    var counts = [];
    for (var k = 0; k <= md; k++) counts[k] = 0;
    for (var i = 0; i < nodes.length; i++) counts[nodes[i].deg]++;

    var ox = 24, oy = H - 40;
    var bw = Math.min(180, W * 0.22);
    var bh = Math.min(85, H * 0.12);

    X.fillStyle = 'rgba(10,10,15,0.82)';
    X.fillRect(ox - 8, oy - bh - 20, bw + 20, bh + 32);
    X.strokeStyle = 'rgba(0,245,212,0.12)';
    X.lineWidth = 0.5;
    X.strokeRect(ox - 8, oy - bh - 20, bw + 20, bh + 32);

    X.fillStyle = 'rgba(0,245,212,0.5)';
    X.font = '9px Space Grotesk, monospace';
    X.textAlign = 'left';
    X.fillText('P(k)', ox, oy - bh - 6);

    var mc = 0;
    for (var k = 1; k <= md; k++) if (counts[k] > mc) mc = counts[k];
    if (mc === 0) return;

    var barW = Math.max(2, (bw - 4) / md);
    for (var k = 1; k <= md; k++) {
      if (counts[k] === 0) continue;
      var barH = (counts[k] / mc) * (bh - 8);
      var bx = ox + (k - 1) * barW;
      var t = (k - 1) / Math.max(md - 1, 1);
      var cr = Math.floor(t * 123);
      var cg = Math.floor(245 - t * 201);
      var cb = Math.floor(212 - t * 21);
      X.fillStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',0.5)';
      X.fillRect(bx, oy - barH, Math.max(1, barW - 1), barH);
    }

    X.fillStyle = 'rgba(255,255,255,0.3)';
    X.font = '7px monospace';
    X.fillText('1', ox, oy + 9);
    X.textAlign = 'right';
    X.fillText('' + md, ox + bw, oy + 9);
    X.textAlign = 'center';
    X.fillText('k', ox + bw / 2, oy + 9);
    X.textAlign = 'left';
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    var md = getMaxDeg() || 1;

    for (var i = 0; i < edges.length; i++) {
      var ea = nodes[edges[i].a], eb = nodes[edges[i].b];
      var degMax = Math.max(ea.deg, eb.deg);
      var alpha = 0.03 + 0.14 * (degMax / md);
      X.beginPath();
      X.moveTo(ea.x, ea.y);
      X.lineTo(eb.x, eb.y);
      X.strokeStyle = 'rgba(0,245,212,' + alpha.toFixed(3) + ')';
      X.lineWidth = 0.4 + 0.8 * (degMax / md);
      X.stroke();
    }

    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      var dr = n.deg / md;
      var r = 2.5 + dr * 10;

      if (n.glow > 0.01) {
        X.beginPath();
        X.arc(n.x, n.y, r + n.glow * 20, 0, Math.PI * 2);
        X.fillStyle = 'rgba(123,44,191,' + (n.glow * 0.2).toFixed(3) + ')';
        X.fill();
        n.glow *= 0.93;
      }

      if (dr > 0.3) {
        X.beginPath();
        X.arc(n.x, n.y, r * 2.8, 0, Math.PI * 2);
        X.fillStyle = 'rgba(0,245,212,' + (dr * 0.04).toFixed(3) + ')';
        X.fill();
      }

      X.beginPath();
      X.arc(n.x, n.y, r, 0, Math.PI * 2);
      var alpha = 0.25 + dr * 0.75;
      X.fillStyle = 'rgba(0,245,212,' + alpha.toFixed(2) + ')';
      X.fill();
    }

    if (showDist) drawDist();

    hudN.textContent = nodes.length;
    hudE.textContent = edges.length;
    hudMD.textContent = getMaxDeg();
    var g = estGamma();
    hudG.textContent = g !== null ? g.toFixed(2) : '—';
  }

  function frame() {
    if (autoPlay && nodes.length < maxN) {
      tick++;
      var interval = Math.max(1, 12 - speed);
      if (tick % interval === 0) addNode();
    }

    layout();
    layout();
    draw();
    requestAnimationFrame(frame);
  }

  document.getElementById('sl-m').addEventListener('input', function() {
    m = +this.value;
    document.getElementById('sv-m').textContent = m;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });

  document.getElementById('sl-speed').addEventListener('input', function() {
    speed = +this.value;
    document.getElementById('sv-speed').textContent = speed;
  });

  document.getElementById('btn-pref').addEventListener('click', function() {
    prefMode = true;
    this.classList.add('active');
    document.getElementById('btn-random').classList.remove('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-random').addEventListener('click', function() {
    prefMode = false;
    this.classList.add('active');
    document.getElementById('btn-pref').classList.remove('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-play').addEventListener('click', function() {
    autoPlay = !autoPlay;
    this.textContent = autoPlay ? 'AUTO' : 'PAUSED';
    this.classList.toggle('active', autoPlay);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-dist').addEventListener('click', function() {
    showDist = !showDist;
    this.classList.toggle('active', showDist);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    init();
    for (var i = 0; i < 12; i++) addNode();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  function findNode(px, py) {
    var md = getMaxDeg() || 1;
    for (var i = nodes.length - 1; i >= 0; i--) {
      var dx = nodes[i].x - px, dy = nodes[i].y - py;
      var nr = 6 + (nodes[i].deg / md) * 10;
      if (dx * dx + dy * dy < nr * nr) return i;
    }
    return -1;
  }

  var mouseDown = false;

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var rect = C.getBoundingClientRect();
    var px = e.clientX - rect.left, py = e.clientY - rect.top;
    var hit = findNode(px, py);
    if (hit >= 0) {
      dragIdx = hit;
      mouseDown = true;
    } else {
      addNode(px, py);
      mouseDown = true;
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (!mouseDown || dragIdx < 0) return;
    var rect = C.getBoundingClientRect();
    nodes[dragIdx].x = e.clientX - rect.left;
    nodes[dragIdx].y = e.clientY - rect.top;
    nodes[dragIdx].vx = 0;
    nodes[dragIdx].vy = 0;
  });

  C.addEventListener('mouseup', function() {
    dragIdx = -1;
    mouseDown = false;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    init();
    for (var i = 0; i < 12; i++) addNode();
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  var touchId = -1;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    var rect = C.getBoundingClientRect();
    var px = t.clientX - rect.left, py = t.clientY - rect.top;
    touchId = t.identifier;
    var hit = findNode(px, py);
    if (hit >= 0) {
      dragIdx = hit;
    } else {
      addNode(px, py);
    }
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragIdx < 0) return;
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) {
        var rect = C.getBoundingClientRect();
        nodes[dragIdx].x = e.touches[i].clientX - rect.left;
        nodes[dragIdx].y = e.touches[i].clientY - rect.top;
        nodes[dragIdx].vx = 0;
        nodes[dragIdx].vy = 0;
        break;
      }
    }
  }, {passive: false});

  C.addEventListener('touchend', function() {
    dragIdx = -1;
    touchId = -1;
  });

  resize();
  window.addEventListener('resize', resize);
  init();
  for (var i = 0; i < 15; i++) addNode();
  muted = false;
  frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
