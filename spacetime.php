<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('spacetime');

$pageName = 'spacetime';
$pageTitle = 'SPACETIME - PULSE';
$pageDesc = 'ミンコフスキー時空図。ローレンツブーストで光円錐・世界線・同時性が変形する特殊相対論の可視化。';
$pageCanonical = 'https://pulse.subara3.com/spacetime.php';
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
  <div>EVENTS <span id="hud-events">0</span></div>
  <div>WORLDLINES <span id="hud-lines">0</span></div>
  <div>&beta; <span id="hud-beta">0.00</span></div>
  <div>&gamma; <span id="hud-gamma">1.00</span></div>
</div>
<div id="info-label"><span>MINKOWSKI</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place event'],
  ['type' => 'drag', 'label' => 'worldline'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">BOOST &beta;</label>
    <input type="range" id="sl-beta" min="-95" max="95" value="0" step="1">
    <span class="slider-value" id="sv-beta">0.00</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">GRID</label>
    <input type="range" id="sl-grid" min="20" max="80" value="40" step="5">
    <span class="slider-value" id="sv-grid">40</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">TRAIL</label>
    <input type="range" id="sl-trail" min="0" max="100" value="60" step="5">
    <span class="slider-value" id="sv-trail">60</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-lightcone">LIGHT CONE</button>
    <button class="ctrl-btn active" id="btn-simlines">SIMULTANEITY</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-presets">PRESETS</button>
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
  </div>
  <div class="preset-group" id="preset-btns" style="display:none">
    <button class="preset-btn" data-preset="twin">TWIN PARADOX</button>
    <button class="preset-btn" data-preset="lightpulse">LIGHT PULSE</button>
    <button class="preset-btn" data-preset="barn">BARN &amp; POLE</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;
  var cx, cy;
  var scale = 40;
  var beta = 0;
  var showLightCone = true;
  var showSimLines = true;
  var trailAlpha = 0.6;

  var events = [];
  var worldlines = [];
  var dragging = false;
  var dragLine = null;
  var selectedEvent = -1;

  var TEAL = [0, 245, 212];
  var PURPLE = [123, 44, 191];
  var BG = [10, 10, 15];

  var hEvents = document.getElementById('hud-events');
  var hLines = document.getElementById('hud-lines');
  var hBeta = document.getElementById('hud-beta');
  var hGamma = document.getElementById('hud-gamma');

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2;
    cy = H * 0.6;
  }

  function lorentz(t, x) {
    var g = 1 / Math.sqrt(1 - beta * beta);
    return {
      t: g * (t - beta * x),
      x: g * (x - beta * t)
    };
  }

  function toScreen(t, x) {
    var boosted = lorentz(t, x);
    return {
      sx: cx + boosted.x * scale,
      sy: cy - boosted.t * scale
    };
  }

  function fromScreen(sx, sy) {
    var bx = (sx - cx) / scale;
    var bt = (cy - sy) / scale;
    var g = 1 / Math.sqrt(1 - beta * beta);
    return {
      t: g * (bt + beta * bx),
      x: g * (bx + beta * bt)
    };
  }

  function rgba(c, a) {
    return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
  }

  function drawGrid() {
    var range = Math.max(W, H) / scale + 10;
    X.lineWidth = 0.5;

    for (var i = -Math.ceil(range); i <= Math.ceil(range); i++) {
      X.strokeStyle = rgba([255,255,255], i === 0 ? 0.15 : 0.04);
      var a = toScreen(i, -range);
      var b = toScreen(i, range);
      X.beginPath(); X.moveTo(a.sx, a.sy); X.lineTo(b.sx, b.sy); X.stroke();
      a = toScreen(-range, i);
      b = toScreen(range, i);
      X.beginPath(); X.moveTo(a.sx, a.sy); X.lineTo(b.sx, b.sy); X.stroke();
    }

    if (showSimLines) {
      X.strokeStyle = rgba(PURPLE, 0.25);
      X.lineWidth = 1;
      X.setLineDash([4, 6]);
      for (var i = -Math.ceil(range); i <= Math.ceil(range); i++) {
        if (i === 0) continue;
        var a = toScreen(i, -range);
        var b = toScreen(i, range);
        X.beginPath(); X.moveTo(a.sx, a.sy); X.lineTo(b.sx, b.sy); X.stroke();
      }
      X.setLineDash([]);
    }

    X.strokeStyle = rgba(TEAL, 0.4);
    X.lineWidth = 1.5;
    var o = toScreen(0, 0);
    X.beginPath(); X.moveTo(0, o.sy); X.lineTo(W, o.sy); X.stroke();
    var top = toScreen(-range, 0);
    var bot = toScreen(range, 0);
    X.beginPath(); X.moveTo(top.sx, top.sy); X.lineTo(bot.sx, bot.sy); X.stroke();

    X.fillStyle = rgba(TEAL, 0.3);
    X.font = '10px Space Grotesk, monospace';
    X.textAlign = 'center';
    var xOff = toScreen(0, 0);
    X.fillText('x', W - 12, xOff.sy - 6);
    X.fillText('ct', o.sx + 14, 20);
  }

  function drawLightCones() {
    if (!showLightCone) return;
    var range = Math.max(W, H) / scale + 10;

    X.lineWidth = 1.2;
    X.strokeStyle = rgba([255, 220, 100], 0.3);
    X.setLineDash([3, 5]);

    var a1 = toScreen(range, range);
    var a2 = toScreen(-range, -range);
    X.beginPath(); X.moveTo(a1.sx, a1.sy); X.lineTo(a2.sx, a2.sy); X.stroke();

    var b1 = toScreen(range, -range);
    var b2 = toScreen(-range, range);
    X.beginPath(); X.moveTo(b1.sx, b1.sy); X.lineTo(b2.sx, b2.sy); X.stroke();
    X.setLineDash([]);

    var o = toScreen(0, 0);
    var coneGrad = X.createRadialGradient(o.sx, o.sy, 0, o.sx, o.sy, scale * 6);
    coneGrad.addColorStop(0, 'rgba(0, 245, 212, 0.06)');
    coneGrad.addColorStop(1, 'rgba(0, 245, 212, 0)');

    X.save();
    X.beginPath();
    X.moveTo(a1.sx, a1.sy);
    X.lineTo(o.sx, o.sy);
    X.lineTo(b1.sx, b1.sy);
    X.closePath();
    X.fillStyle = coneGrad;
    X.fill();

    X.beginPath();
    X.moveTo(a2.sx, a2.sy);
    X.lineTo(o.sx, o.sy);
    X.lineTo(b2.sx, b2.sy);
    X.closePath();
    X.fillStyle = coneGrad;
    X.fill();
    X.restore();
  }

  function drawEventLightCone(ev) {
    var range = Math.max(W, H) / scale + 10;
    var p = toScreen(ev.t, ev.x);

    X.strokeStyle = rgba(PURPLE, 0.2);
    X.lineWidth = 0.8;
    X.setLineDash([2, 4]);

    var futR = toScreen(ev.t + range, ev.x + range);
    var futL = toScreen(ev.t + range, ev.x - range);
    var pastR = toScreen(ev.t - range, ev.x + range);
    var pastL = toScreen(ev.t - range, ev.x - range);

    X.beginPath(); X.moveTo(futR.sx, futR.sy); X.lineTo(p.sx, p.sy); X.lineTo(futL.sx, futL.sy); X.stroke();
    X.beginPath(); X.moveTo(pastR.sx, pastR.sy); X.lineTo(p.sx, p.sy); X.lineTo(pastL.sx, pastL.sy); X.stroke();
    X.setLineDash([]);
  }

  function drawEvents() {
    for (var i = 0; i < events.length; i++) {
      var ev = events[i];
      var p = toScreen(ev.t, ev.x);

      if (showLightCone && i === selectedEvent) {
        drawEventLightCone(ev);
      }

      X.beginPath();
      X.arc(p.sx, p.sy, 5, 0, Math.PI * 2);
      X.fillStyle = i === selectedEvent ? rgba(TEAL, 1) : rgba(TEAL, 0.8);
      X.fill();

      X.beginPath();
      X.arc(p.sx, p.sy, 8, 0, Math.PI * 2);
      X.strokeStyle = rgba(TEAL, 0.3);
      X.lineWidth = 1;
      X.stroke();

      if (ev.label) {
        X.fillStyle = rgba([255,255,255], 0.6);
        X.font = '9px Space Grotesk, monospace';
        X.textAlign = 'center';
        X.fillText(ev.label, p.sx, p.sy - 14);
      }
    }
  }

  function drawWorldlines() {
    for (var i = 0; i < worldlines.length; i++) {
      var wl = worldlines[i];
      if (wl.points.length < 2) continue;

      X.beginPath();
      var first = toScreen(wl.points[0].t, wl.points[0].x);
      X.moveTo(first.sx, first.sy);
      for (var j = 1; j < wl.points.length; j++) {
        var pt = toScreen(wl.points[j].t, wl.points[j].x);
        X.lineTo(pt.sx, pt.sy);
      }
      X.strokeStyle = rgba(wl.color, trailAlpha);
      X.lineWidth = 2;
      X.stroke();

      var totalTau = 0;
      for (var j = 1; j < wl.points.length; j++) {
        var dt = wl.points[j].t - wl.points[j-1].t;
        var dx = wl.points[j].x - wl.points[j-1].x;
        var ds2 = dt * dt - dx * dx;
        if (ds2 > 0) totalTau += Math.sqrt(ds2);
      }

      var mid = wl.points[Math.floor(wl.points.length / 2)];
      var mp = toScreen(mid.t, mid.x);
      X.fillStyle = rgba(wl.color, 0.5);
      X.font = '8px JetBrains Mono, monospace';
      X.textAlign = 'left';
      X.fillText('τ=' + totalTau.toFixed(1), mp.sx + 8, mp.sy);

      for (var j = 0; j < wl.points.length; j += Math.max(1, Math.floor(wl.points.length / 8))) {
        var tp = toScreen(wl.points[j].t, wl.points[j].x);
        X.beginPath();
        X.arc(tp.sx, tp.sy, 2, 0, Math.PI * 2);
        X.fillStyle = rgba(wl.color, 0.6);
        X.fill();
      }
    }
  }

  function drawDragLine() {
    if (!dragLine || dragLine.points.length < 2) return;
    X.beginPath();
    var first = toScreen(dragLine.points[0].t, dragLine.points[0].x);
    X.moveTo(first.sx, first.sy);
    for (var j = 1; j < dragLine.points.length; j++) {
      var pt = toScreen(dragLine.points[j].t, dragLine.points[j].x);
      X.lineTo(pt.sx, pt.sy);
    }
    X.strokeStyle = rgba(TEAL, 0.8);
    X.lineWidth = 2;
    X.setLineDash([4, 4]);
    X.stroke();
    X.setLineDash([]);
  }

  function drawSpacetimeInterval() {
    if (events.length < 2) return;
    for (var i = 0; i < events.length; i++) {
      for (var j = i + 1; j < events.length; j++) {
        var dt = events[j].t - events[i].t;
        var dx = events[j].x - events[i].x;
        var ds2 = dt * dt - dx * dx;

        var pi = toScreen(events[i].t, events[i].x);
        var pj = toScreen(events[j].t, events[j].x);
        var mx = (pi.sx + pj.sx) / 2;
        var my = (pi.sy + pj.sy) / 2;

        var kind = ds2 > 0.01 ? 'timelike' : (ds2 < -0.01 ? 'spacelike' : 'lightlike');
        var col = kind === 'timelike' ? TEAL : (kind === 'spacelike' ? PURPLE : [255, 220, 100]);

        X.strokeStyle = rgba(col, 0.15);
        X.lineWidth = 0.5;
        X.setLineDash([2, 4]);
        X.beginPath(); X.moveTo(pi.sx, pi.sy); X.lineTo(pj.sx, pj.sy); X.stroke();
        X.setLineDash([]);

        X.fillStyle = rgba(col, 0.4);
        X.font = '8px JetBrains Mono, monospace';
        X.textAlign = 'center';
        var label = 's²=' + ds2.toFixed(1);
        X.fillText(label, mx, my - 6);
      }
    }
  }

  function drawParticles() {
    var time = performance.now() * 0.001;
    for (var i = 0; i < worldlines.length; i++) {
      var wl = worldlines[i];
      if (wl.points.length < 2) continue;

      var totalLen = 0;
      for (var j = 1; j < wl.points.length; j++) {
        var dt = wl.points[j].t - wl.points[j-1].t;
        var dx = wl.points[j].x - wl.points[j-1].x;
        totalLen += Math.sqrt(dt*dt + dx*dx);
      }

      var speed = 2;
      var pos = (time * speed) % totalLen;
      var accum = 0;
      for (var j = 1; j < wl.points.length; j++) {
        var dt = wl.points[j].t - wl.points[j-1].t;
        var dx = wl.points[j].x - wl.points[j-1].x;
        var segLen = Math.sqrt(dt*dt + dx*dx);
        if (accum + segLen >= pos) {
          var frac = (pos - accum) / segLen;
          var pt = toScreen(
            wl.points[j-1].t + frac * dt,
            wl.points[j-1].x + frac * dx
          );
          X.beginPath();
          X.arc(pt.sx, pt.sy, 4, 0, Math.PI * 2);
          X.fillStyle = rgba(wl.color, 0.9);
          X.fill();
          X.beginPath();
          X.arc(pt.sx, pt.sy, 8, 0, Math.PI * 2);
          X.fillStyle = rgba(wl.color, 0.15);
          X.fill();
          break;
        }
        accum += segLen;
      }
    }
  }

  function draw() {
    X.fillStyle = 'rgb(' + BG[0] + ',' + BG[1] + ',' + BG[2] + ')';
    X.fillRect(0, 0, W, H);
    drawGrid();
    drawLightCones();
    drawSpacetimeInterval();
    drawWorldlines();
    drawEvents();
    drawDragLine();
    drawParticles();

    hEvents.textContent = events.length;
    hLines.textContent = worldlines.length;
    hBeta.textContent = beta.toFixed(2);
    var g = 1 / Math.sqrt(Math.max(0.001, 1 - beta * beta));
    hGamma.textContent = g.toFixed(2);

    requestAnimationFrame(draw);
  }

  function getWlColor() {
    var colors = [
      TEAL,
      PURPLE,
      [0, 200, 220],
      [100, 60, 200],
      [0, 180, 190],
      [160, 50, 180]
    ];
    return colors[worldlines.length % colors.length];
  }

  function findNearestEvent(sx, sy) {
    var best = -1, bestD = 20;
    for (var i = 0; i < events.length; i++) {
      var p = toScreen(events[i].t, events[i].x);
      var d = Math.hypot(p.sx - sx, p.sy - sy);
      if (d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  var pointerDown = false;
  var dragStartTime = 0;
  var startSx = 0, startSy = 0;

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    pointerDown = true;
    dragStartTime = performance.now();
    startSx = e.clientX;
    startSy = e.clientY;
    var coords = fromScreen(e.clientX, e.clientY);
    dragLine = { points: [{ t: coords.t, x: coords.x }], color: getWlColor() };

    var near = findNearestEvent(e.clientX, e.clientY);
    if (near >= 0) selectedEvent = near;
  });

  C.addEventListener('pointermove', function(e) {
    if (!pointerDown) return;
    var dist = Math.hypot(e.clientX - startSx, e.clientY - startSy);
    if (dist > 5) {
      dragging = true;
      var coords = fromScreen(e.clientX, e.clientY);
      if (dragLine) {
        var last = dragLine.points[dragLine.points.length - 1];
        var dd = Math.hypot(coords.t - last.t, coords.x - last.x);
        if (dd > 0.15) {
          dragLine.points.push({ t: coords.t, x: coords.x });
        }
      }
    }
  });

  C.addEventListener('pointerup', function(e) {
    if (e.button === 2) return;
    if (dragging && dragLine && dragLine.points.length >= 2) {
      worldlines.push(dragLine);
      SFX.play('spawn');
    } else if (!dragging) {
      var coords = fromScreen(e.clientX, e.clientY);
      var near = findNearestEvent(e.clientX, e.clientY);
      if (near >= 0) {
        selectedEvent = near;
      } else {
        events.push({ t: coords.t, x: coords.x, label: 'E' + events.length });
        selectedEvent = events.length - 1;
        SFX.play('tick');
      }
    }
    pointerDown = false;
    dragging = false;
    dragLine = null;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var sx = e.clientX, sy = e.clientY;
    var near = findNearestEvent(sx, sy);
    if (near >= 0) {
      events.splice(near, 1);
      if (selectedEvent >= events.length) selectedEvent = events.length - 1;
      SFX.play('pop');
      return;
    }
    for (var i = worldlines.length - 1; i >= 0; i--) {
      var wl = worldlines[i];
      for (var j = 0; j < wl.points.length; j++) {
        var p = toScreen(wl.points[j].t, wl.points[j].x);
        if (Math.hypot(p.sx - sx, p.sy - sy) < 15) {
          worldlines.splice(i, 1);
          SFX.play('pop');
          return;
        }
      }
    }
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    scale = Math.max(10, Math.min(120, scale - e.deltaY * 0.05));
  }, { passive: false });

  var slBeta = document.getElementById('sl-beta');
  var svBeta = document.getElementById('sv-beta');
  slBeta.addEventListener('input', function() {
    beta = this.value / 100;
    svBeta.textContent = beta.toFixed(2);
    SFX.play('move', 80);
  });

  var slGrid = document.getElementById('sl-grid');
  var svGrid = document.getElementById('sv-grid');
  slGrid.addEventListener('input', function() {
    scale = parseInt(this.value);
    svGrid.textContent = scale;
  });

  var slTrail = document.getElementById('sl-trail');
  var svTrail = document.getElementById('sv-trail');
  slTrail.addEventListener('input', function() {
    trailAlpha = this.value / 100;
    svTrail.textContent = this.value;
  });

  var btnLC = document.getElementById('btn-lightcone');
  btnLC.addEventListener('click', function() {
    showLightCone = !showLightCone;
    this.classList.toggle('active', showLightCone);
    SFX.play('toggle');
  });

  var btnSim = document.getElementById('btn-simlines');
  btnSim.addEventListener('click', function() {
    showSimLines = !showSimLines;
    this.classList.toggle('active', showSimLines);
    SFX.play('toggle');
  });

  document.getElementById('btn-clear').addEventListener('click', function() {
    events = [];
    worldlines = [];
    selectedEvent = -1;
    SFX.play('click');
  });

  var presetsVisible = false;
  document.getElementById('btn-presets').addEventListener('click', function() {
    presetsVisible = !presetsVisible;
    document.getElementById('preset-btns').style.display = presetsVisible ? 'flex' : 'none';
    this.classList.toggle('active', presetsVisible);
    SFX.play('toggle');
  });

  function loadPreset(name) {
    events = [];
    worldlines = [];
    selectedEvent = -1;
    beta = 0;
    slBeta.value = 0;
    svBeta.textContent = '0.00';

    if (name === 'twin') {
      events.push({ t: 0, x: 0, label: 'START' });
      events.push({ t: 6, x: 0, label: 'RETURN' });
      events.push({ t: 3, x: 2, label: 'TURN' });

      worldlines.push({
        points: [{ t: 0, x: 0 }, { t: 6, x: 0 }],
        color: TEAL
      });
      worldlines.push({
        points: [{ t: 0, x: 0 }, { t: 3, x: 2 }, { t: 6, x: 0 }],
        color: PURPLE
      });
    } else if (name === 'lightpulse') {
      events.push({ t: 0, x: 0, label: 'EMIT' });
      events.push({ t: 5, x: 5, label: 'DETECT R' });
      events.push({ t: 5, x: -5, label: 'DETECT L' });

      worldlines.push({
        points: [{ t: 0, x: 0 }, { t: 5, x: 5 }],
        color: [200, 200, 100]
      });
      worldlines.push({
        points: [{ t: 0, x: 0 }, { t: 5, x: -5 }],
        color: [200, 200, 100]
      });
      worldlines.push({
        points: [{ t: -2, x: 0 }, { t: 8, x: 0 }],
        color: TEAL
      });
    } else if (name === 'barn') {
      worldlines.push({
        points: [{ t: -3, x: -1 }, { t: 8, x: -1 }],
        color: TEAL
      });
      worldlines.push({
        points: [{ t: -3, x: 1 }, { t: 8, x: 1 }],
        color: TEAL
      });
      worldlines.push({
        points: [{ t: -2, x: -4 }, { t: 8, x: 4.5 }],
        color: PURPLE
      });
      worldlines.push({
        points: [{ t: -2, x: -1.5 }, { t: 8, x: 7 }],
        color: PURPLE
      });
      events.push({ t: 2.6, x: -1, label: 'FRONT IN' });
      events.push({ t: 3.4, x: 1, label: 'BACK IN' });
    }

    SFX.play('success');
  }

  document.querySelectorAll('[data-preset]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      loadPreset(this.dataset.preset);
    });
  });

  function initDemo() {
    events.push({ t: 0, x: 0, label: 'O' });
    events.push({ t: 4, x: 2, label: 'A' });
    events.push({ t: 3, x: -2, label: 'B' });

    worldlines.push({
      points: [{ t: -3, x: 0 }, { t: 0, x: 0 }, { t: 5, x: 0 }],
      color: TEAL
    });
    worldlines.push({
      points: [{ t: -1, x: -3 }, { t: 4, x: 2 }],
      color: PURPLE
    });

    selectedEvent = 0;
  }

  resize();
  window.addEventListener('resize', resize);
  initDemo();
  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
