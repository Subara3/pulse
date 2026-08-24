<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('markov');

$pageName = 'markov';
$pageTitle = 'MARKOV - PULSE';
$pageDesc = '離散マルコフ連鎖。状態グラフ上を粒子が遷移確率に従って跳び、経験分布が定常分布に収束する。';
$pageCanonical = 'https://pulse.subara3.com/markov.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>STATES <span id="hud-states">0</span></div>
  <div>WALKERS <span id="hud-walkers">0</span></div>
  <div>STEPS <span id="hud-steps">0</span></div>
</div>
<div id="info-label"><span>MARKOV CHAIN</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add state'],
  ['type' => 'drag', 'label' => 'link / move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">WALKERS</label>
    <input type="range" id="sl-walkers" min="10" max="500" value="100" step="10">
    <span class="slider-value" id="sv-walkers">100</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="60" value="10" step="1">
    <span class="slider-value" id="sv-speed">10</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-hist">HISTOGRAM</button>
    <button class="ctrl-btn active" id="btn-arrows">ARROWS</button>
    <button class="ctrl-btn" id="btn-stationary">STATIONARY</button>
  </div>
  <div class="preset-group" id="preset-btns">
    <button class="preset-btn" data-preset="ergodic">ERGODIC (4)</button>
    <button class="preset-btn" data-preset="absorbing">ABSORBING</button>
    <button class="preset-btn" data-preset="periodic">PERIODIC</button>
    <button class="preset-btn" data-preset="gambler">GAMBLER'S RUIN</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-reset">RESET COUNTS</button>
    <button class="ctrl-btn" id="btn-clear">CLEAR ALL</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;
  var states = [], edges = [], walkers = [];
  var nWalkers = 100, stepsPerFrame = 10, totalSteps = 0;
  var showHist = true, showArrows = true, showStationary = false;
  var dragging = null, dragType = '', linkStart = null;
  var mouseX = 0, mouseY = 0, isDown = false, moved = false;
  var stateRadius = 28, nextId = 0;
  var hStates = document.getElementById('hud-states');
  var hWalkers = document.getElementById('hud-walkers');
  var hSteps = document.getElementById('hud-steps');
  var TEAL = [0, 245, 212], PURPLE = [123, 44, 191];

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  window.addEventListener('resize', resize);
  resize();

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function lerpColor(a, b, t) {
    return [a[0] + (b[0]-a[0])*t, a[1] + (b[1]-a[1])*t, a[2] + (b[2]-a[2])*t];
  }

  function addState(x, y) {
    var s = { id: nextId++, x: x, y: y, visits: 0, label: 'S' + states.length };
    states.push(s);
    normalizeEdges(s.id);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
    return s;
  }

  function removeState(id) {
    states = states.filter(function(s) { return s.id !== id; });
    edges = edges.filter(function(e) { return e.from !== id && e.to !== id; });
    walkers = walkers.filter(function(w) { return w.state !== id; });
    states.forEach(function(s) { normalizeEdges(s.id); });
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }

  function getState(id) {
    for (var i = 0; i < states.length; i++) {
      if (states[i].id === id) return states[i];
    }
    return null;
  }

  function addEdge(from, to) {
    for (var i = 0; i < edges.length; i++) {
      if (edges[i].from === from && edges[i].to === to) return;
    }
    edges.push({ from: from, to: to, weight: 1 });
    normalizeEdges(from);
    if (typeof SFX !== 'undefined') SFX.play('tick');
  }

  function normalizeEdges(fromId) {
    var outEdges = edges.filter(function(e) { return e.from === fromId; });
    if (outEdges.length === 0) return;
    var total = 0;
    outEdges.forEach(function(e) { total += e.weight; });
    outEdges.forEach(function(e) { e.prob = e.weight / total; });
  }

  function getOutEdges(fromId) {
    return edges.filter(function(e) { return e.from === fromId; });
  }

  function spawnWalkers() {
    walkers = [];
    if (states.length === 0) return;
    for (var i = 0; i < nWalkers; i++) {
      var s = states[Math.floor(Math.random() * states.length)];
      walkers.push({ state: s.id, x: s.x, y: s.y, tx: s.x, ty: s.y, t: 1 });
    }
  }

  function stepWalkers() {
    if (states.length === 0) return;
    for (var i = 0; i < walkers.length; i++) {
      var w = walkers[i];
      if (w.t < 1) continue;
      var out = getOutEdges(w.state);
      if (out.length === 0) continue;
      var r = Math.random(), cum = 0;
      for (var j = 0; j < out.length; j++) {
        cum += out[j].prob;
        if (r <= cum) {
          var ns = getState(out[j].to);
          if (ns) {
            w.state = ns.id;
            ns.visits++;
            w.tx = ns.x + (Math.random() - 0.5) * stateRadius * 0.6;
            w.ty = ns.y + (Math.random() - 0.5) * stateRadius * 0.6;
            w.t = 0;
          }
          break;
        }
      }
    }
    totalSteps++;
  }

  function computeStationary() {
    var n = states.length;
    if (n === 0) return [];
    var idMap = {};
    states.forEach(function(s, i) { idMap[s.id] = i; });
    var P = [];
    for (var i = 0; i < n; i++) {
      P[i] = [];
      for (var j = 0; j < n; j++) P[i][j] = 0;
    }
    edges.forEach(function(e) {
      if (idMap[e.from] !== undefined && idMap[e.to] !== undefined) {
        P[idMap[e.from]][idMap[e.to]] = e.prob || 0;
      }
    });
    var pi = [];
    for (var i = 0; i < n; i++) pi[i] = 1 / n;
    for (var iter = 0; iter < 200; iter++) {
      var next = [];
      for (var j = 0; j < n; j++) {
        var s = 0;
        for (var i = 0; i < n; i++) s += pi[i] * P[i][j];
        next[j] = s;
      }
      pi = next;
    }
    var result = [];
    states.forEach(function(s) {
      result.push({ id: s.id, prob: pi[idMap[s.id]] || 0 });
    });
    return result;
  }

  function hitTest(x, y) {
    for (var i = states.length - 1; i >= 0; i--) {
      var s = states[i];
      var dx = x - s.x, dy = y - s.y;
      if (dx * dx + dy * dy < stateRadius * stateRadius * 1.5) return s;
    }
    return null;
  }

  function drawArrow(x1, y1, x2, y2, prob, selfLoop, sx, sy) {
    if (selfLoop) {
      var loopR = stateRadius * 1.2;
      var ang = Math.atan2(sy - H/2, sx - W/2);
      var cx = sx + Math.cos(ang) * loopR * 1.8;
      var cy = sy + Math.sin(ang) * loopR * 1.8;
      X.beginPath();
      X.arc(cx, cy, loopR, 0, Math.PI * 1.7);
      X.strokeStyle = rgba(PURPLE, 0.3 + prob * 0.5);
      X.lineWidth = 1 + prob * 2;
      X.stroke();
      var ea = Math.PI * 1.7;
      var ax = cx + Math.cos(ea) * loopR;
      var ay = cy + Math.sin(ea) * loopR;
      drawArrowHead(ax, ay, ea + Math.PI/2, prob);
      X.fillStyle = rgba([255,255,255], 0.5);
      X.font = '9px Space Grotesk, monospace';
      X.textAlign = 'center';
      X.fillText((prob * 100 | 0) + '%', cx + Math.cos(ang) * 8, cy + Math.sin(ang) * 8);
      return;
    }
    var dx = x2 - x1, dy = y2 - y1;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist < 1) return;
    var nx = dx / dist, ny = dy / dist;
    var hasReverse = false;
    for (var i = 0; i < edges.length; i++) {
      var e = edges[i];
      var sf = getState(e.from), st = getState(e.to);
      if (sf && st && st.x === x1 && st.y === y1 && sf.x === x2 && sf.y === y2) {
        hasReverse = true; break;
      }
    }
    var offset = hasReverse ? 8 : 0;
    var perpX = -ny * offset, perpY = nx * offset;
    var sx1 = x1 + nx * stateRadius + perpX;
    var sy1 = y1 + ny * stateRadius + perpY;
    var sx2 = x2 - nx * stateRadius + perpX;
    var sy2 = y2 - ny * stateRadius + perpY;

    X.beginPath();
    X.moveTo(sx1, sy1);
    X.lineTo(sx2, sy2);
    X.strokeStyle = rgba(PURPLE, 0.3 + prob * 0.5);
    X.lineWidth = 1 + prob * 2;
    X.stroke();

    var ang = Math.atan2(sy2 - sy1, sx2 - sx1);
    drawArrowHead(sx2, sy2, ang, prob);

    var mx = (sx1 + sx2) / 2, my = (sy1 + sy2) / 2;
    X.fillStyle = rgba([255,255,255], 0.5);
    X.font = '9px Space Grotesk, monospace';
    X.textAlign = 'center';
    X.fillText((prob * 100 | 0) + '%', mx - ny * 12, my + nx * 12);
  }

  function drawArrowHead(x, y, ang, prob) {
    var sz = 6 + prob * 4;
    X.beginPath();
    X.moveTo(x, y);
    X.lineTo(x - Math.cos(ang - 0.4) * sz, y - Math.sin(ang - 0.4) * sz);
    X.lineTo(x - Math.cos(ang + 0.4) * sz, y - Math.sin(ang + 0.4) * sz);
    X.closePath();
    X.fillStyle = rgba(PURPLE, 0.5 + prob * 0.4);
    X.fill();
  }

  function drawHistogram() {
    if (!showHist || states.length === 0) return;
    var totalVisits = 0;
    states.forEach(function(s) { totalVisits += s.visits; });
    if (totalVisits === 0) return;

    var barW = Math.min(40, (W * 0.6) / states.length);
    var barMaxH = H * 0.15;
    var baseY = H - 40;
    var startX = (W - states.length * barW) / 2;

    var stationary = showStationary ? computeStationary() : [];

    states.forEach(function(s, i) {
      var frac = s.visits / totalVisits;
      var h = frac * barMaxH * states.length;
      if (h > barMaxH) h = barMaxH;
      var x = startX + i * barW;
      var col = lerpColor(TEAL, PURPLE, i / Math.max(1, states.length - 1));

      X.fillStyle = rgba(col, 0.4);
      X.fillRect(x + 2, baseY - h, barW - 4, h);
      X.strokeStyle = rgba(col, 0.7);
      X.lineWidth = 1;
      X.strokeRect(x + 2, baseY - h, barW - 4, h);

      if (showStationary) {
        var sp = 0;
        for (var j = 0; j < stationary.length; j++) {
          if (stationary[j].id === s.id) { sp = stationary[j].prob; break; }
        }
        var sh = sp * barMaxH * states.length;
        if (sh > barMaxH) sh = barMaxH;
        X.strokeStyle = rgba([255,255,255], 0.6);
        X.lineWidth = 1.5;
        X.setLineDash([4, 3]);
        X.beginPath();
        X.moveTo(x, baseY - sh);
        X.lineTo(x + barW, baseY - sh);
        X.stroke();
        X.setLineDash([]);
      }

      X.fillStyle = rgba([255,255,255], 0.6);
      X.font = '9px Space Grotesk, monospace';
      X.textAlign = 'center';
      X.fillText(s.label, x + barW / 2, baseY + 14);
      X.fillStyle = rgba(col, 0.8);
      X.fillText((frac * 100).toFixed(1) + '%', x + barW / 2, baseY - h - 6);
    });
  }

  function draw() {
    X.clearRect(0, 0, W, H);

    if (showArrows) {
      edges.forEach(function(e) {
        var sf = getState(e.from), st = getState(e.to);
        if (!sf || !st) return;
        if (e.from === e.to) {
          drawArrow(sf.x, sf.y, st.x, st.y, e.prob || 0, true, sf.x, sf.y);
        } else {
          drawArrow(sf.x, sf.y, st.x, st.y, e.prob || 0, false);
        }
      });
    }

    if (linkStart) {
      var ls = getState(linkStart);
      if (ls) {
        X.beginPath();
        X.moveTo(ls.x, ls.y);
        X.lineTo(mouseX, mouseY);
        X.strokeStyle = rgba(TEAL, 0.3);
        X.lineWidth = 2;
        X.setLineDash([6, 4]);
        X.stroke();
        X.setLineDash([]);
      }
    }

    for (var i = 0; i < walkers.length; i++) {
      var w = walkers[i];
      if (w.t < 1) {
        w.t += 0.15;
        if (w.t > 1) w.t = 1;
        w.x += (w.tx - w.x) * 0.15;
        w.y += (w.ty - w.y) * 0.15;
      }
      X.beginPath();
      X.arc(w.x, w.y, 2, 0, Math.PI * 2);
      X.fillStyle = rgba(TEAL, 0.5);
      X.fill();
    }

    states.forEach(function(s, i) {
      var col = lerpColor(TEAL, PURPLE, i / Math.max(1, states.length - 1));
      var walkerCount = 0;
      walkers.forEach(function(w) { if (w.state === s.id) walkerCount++; });
      var glow = walkerCount / Math.max(1, nWalkers) * 3;
      if (glow > 1) glow = 1;

      X.beginPath();
      X.arc(s.x, s.y, stateRadius + glow * 8, 0, Math.PI * 2);
      X.fillStyle = rgba(col, 0.05 + glow * 0.15);
      X.fill();

      X.beginPath();
      X.arc(s.x, s.y, stateRadius, 0, Math.PI * 2);
      X.strokeStyle = rgba(col, 0.5 + glow * 0.4);
      X.lineWidth = 2;
      X.stroke();
      X.fillStyle = rgba(col, 0.08 + glow * 0.1);
      X.fill();

      X.fillStyle = rgba([255,255,255], 0.8);
      X.font = '12px Space Grotesk, monospace';
      X.textAlign = 'center';
      X.textBaseline = 'middle';
      X.fillText(s.label, s.x, s.y);
    });

    drawHistogram();

    hStates.textContent = states.length;
    hWalkers.textContent = walkers.length;
    hSteps.textContent = totalSteps;
  }

  function loop() {
    for (var i = 0; i < stepsPerFrame; i++) stepWalkers();
    draw();
    requestAnimationFrame(loop);
  }

  function loadPreset(name) {
    states = []; edges = []; walkers = []; totalSteps = 0; nextId = 0;
    var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.22;

    if (name === 'ergodic') {
      var n = 4;
      for (var i = 0; i < n; i++) {
        var a = (i / n) * Math.PI * 2 - Math.PI / 2;
        addState(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
      }
      for (var i = 0; i < n; i++) {
        for (var j = 0; j < n; j++) {
          addEdge(states[i].id, states[j].id);
        }
      }
    } else if (name === 'absorbing') {
      var positions = [
        [cx - r, cy - r * 0.5],
        [cx + r, cy - r * 0.5],
        [cx - r * 0.5, cy + r * 0.5],
        [cx + r * 0.5, cy + r * 0.5],
        [cx, cy + r]
      ];
      positions.forEach(function(p) { addState(p[0], p[1]); });
      addEdge(states[0].id, states[1].id);
      addEdge(states[0].id, states[2].id);
      addEdge(states[1].id, states[0].id);
      addEdge(states[1].id, states[3].id);
      addEdge(states[2].id, states[0].id);
      addEdge(states[2].id, states[4].id);
      addEdge(states[3].id, states[1].id);
      addEdge(states[3].id, states[4].id);
      addEdge(states[4].id, states[4].id);
    } else if (name === 'periodic') {
      var n = 6;
      for (var i = 0; i < n; i++) {
        var a = (i / n) * Math.PI * 2 - Math.PI / 2;
        addState(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
      }
      for (var i = 0; i < n; i++) {
        addEdge(states[i].id, states[(i + 1) % n].id);
      }
    } else if (name === 'gambler') {
      var n = 7;
      var spacing = Math.min(W * 0.8, r * 3) / (n - 1);
      var startX = cx - spacing * (n - 1) / 2;
      for (var i = 0; i < n; i++) {
        addState(startX + i * spacing, cy);
      }
      addEdge(states[0].id, states[0].id);
      addEdge(states[n-1].id, states[n-1].id);
      for (var i = 1; i < n - 1; i++) {
        addEdge(states[i].id, states[i - 1].id);
        addEdge(states[i].id, states[i + 1].id);
      }
    }

    states.forEach(function(s) { normalizeEdges(s.id); });
    spawnWalkers();
  }

  function getEventPos(e) {
    if (e.touches && e.touches.length > 0) {
      return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }
    return { x: e.clientX, y: e.clientY };
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var pos = getEventPos(e);
    mouseX = pos.x; mouseY = pos.y;
    isDown = true; moved = false;
    var hit = hitTest(pos.x, pos.y);
    if (hit) {
      dragging = hit;
      dragType = 'move';
      linkStart = null;
    }
  });

  C.addEventListener('mousemove', function(e) {
    var pos = getEventPos(e);
    mouseX = pos.x; mouseY = pos.y;
    if (!isDown) return;
    moved = true;
    if (dragging && dragType === 'move') {
      var dx = pos.x - dragging.x, dy = pos.y - dragging.y;
      var dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > stateRadius * 1.5 && !linkStart) {
        linkStart = dragging.id;
        dragType = 'link';
      } else {
        dragging.x = pos.x;
        dragging.y = pos.y;
        walkers.forEach(function(w) {
          if (w.state === dragging.id) {
            w.tx = pos.x + (Math.random() - 0.5) * stateRadius * 0.6;
            w.ty = pos.y + (Math.random() - 0.5) * stateRadius * 0.6;
          }
        });
      }
    }
  });

  C.addEventListener('mouseup', function(e) {
    if (e.button === 2) return;
    var pos = getEventPos(e);
    if (linkStart !== null) {
      var hit = hitTest(pos.x, pos.y);
      if (hit) {
        addEdge(linkStart, hit.id);
        normalizeEdges(linkStart);
      }
      linkStart = null;
    } else if (!moved) {
      var hit = hitTest(pos.x, pos.y);
      if (!hit) {
        addState(pos.x, pos.y);
        spawnWalkers();
      }
    }
    isDown = false; moved = false; dragging = null; dragType = '';
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var pos = { x: e.clientX, y: e.clientY };
    var hit = hitTest(pos.x, pos.y);
    if (hit) {
      removeState(hit.id);
    }
  });

  var touchTimer = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var pos = getEventPos(e);
    mouseX = pos.x; mouseY = pos.y;
    isDown = true; moved = false;
    var hit = hitTest(pos.x, pos.y);
    if (hit) {
      dragging = hit;
      dragType = 'move';
      touchTimer = setTimeout(function() {
        if (dragging && !moved) {
          removeState(dragging.id);
          dragging = null;
          isDown = false;
        }
      }, 600);
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var pos = getEventPos(e);
    mouseX = pos.x; mouseY = pos.y;
    moved = true;
    if (touchTimer) { clearTimeout(touchTimer); touchTimer = null; }
    if (dragging && dragType === 'move') {
      var dx = pos.x - dragging.x, dy = pos.y - dragging.y;
      var dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > stateRadius * 2 && !linkStart) {
        linkStart = dragging.id;
        dragType = 'link';
      } else if (dragType === 'move') {
        dragging.x = pos.x;
        dragging.y = pos.y;
        walkers.forEach(function(w) {
          if (w.state === dragging.id) {
            w.tx = pos.x + (Math.random()-0.5)*stateRadius*0.6;
            w.ty = pos.y + (Math.random()-0.5)*stateRadius*0.6;
          }
        });
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (touchTimer) { clearTimeout(touchTimer); touchTimer = null; }
    if (linkStart !== null) {
      var hit = hitTest(mouseX, mouseY);
      if (hit) {
        addEdge(linkStart, hit.id);
        normalizeEdges(linkStart);
      }
      linkStart = null;
    } else if (!moved && !dragging) {
      addState(mouseX, mouseY);
      spawnWalkers();
    }
    isDown = false; moved = false; dragging = null; dragType = '';
  });

  document.getElementById('sl-walkers').addEventListener('input', function() {
    nWalkers = +this.value;
    document.getElementById('sv-walkers').textContent = nWalkers;
    spawnWalkers();
  });

  document.getElementById('sl-speed').addEventListener('input', function() {
    stepsPerFrame = +this.value;
    document.getElementById('sv-speed').textContent = stepsPerFrame;
  });

  document.getElementById('btn-hist').addEventListener('click', function() {
    showHist = !showHist;
    this.classList.toggle('active', showHist);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-arrows').addEventListener('click', function() {
    showArrows = !showArrows;
    this.classList.toggle('active', showArrows);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-stationary').addEventListener('click', function() {
    showStationary = !showStationary;
    this.classList.toggle('active', showStationary);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    totalSteps = 0;
    states.forEach(function(s) { s.visits = 0; });
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('btn-clear').addEventListener('click', function() {
    states = []; edges = []; walkers = []; totalSteps = 0; nextId = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.querySelectorAll('.preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      loadPreset(this.dataset.preset);
      document.querySelectorAll('.preset-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      if (typeof SFX !== 'undefined') SFX.play('click');
    });
  });

  loadPreset('ergodic');
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
