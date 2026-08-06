<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('roche');

$pageName = 'roche';
$pageTitle = 'ROCHE - PULSE';
$pageDesc = 'ロシュ限界。潮汐力が自己重力を超えるとき、衛星は引き裂かれて環になる。';
$pageCanonical = 'https://pulse.subara3.com/roche.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>BODIES <span id="hud-bodies">0</span></div>
  <div>FRAGMENTS <span id="hud-frags">0</span></div>
  <div>ROCHE <span id="hud-roche">0</span></div>
  <div>NEAREST <span id="hud-dist">-</span></div>
</div>
<div id="info-label"><span>ROCHE LIMIT</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'orbit'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">MASS RATIO</label>
    <input type="range" id="sl-mass" min="5" max="50" value="20" step="1">
    <span class="slider-value" id="sv-mass">20</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">COHESION</label>
    <input type="range" id="sl-cohesion" min="1" max="30" value="10" step="1">
    <span class="slider-value" id="sv-cohesion">10</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SAT. SIZE</label>
    <input type="range" id="sl-size" min="3" max="8" value="5" step="1">
    <span class="slider-value" id="sv-size">5</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">TRAIL</label>
    <input type="range" id="sl-trail" min="0" max="100" value="50" step="5">
    <span class="slider-value" id="sv-trail">50</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-roche">ROCHE ZONE</button>
    <button class="ctrl-btn active" id="btn-tidal">TIDAL ARROWS</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-presets">PRESETS</button>
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
  </div>
  <div class="preset-group" id="preset-btns" style="display:none">
    <button class="preset-btn" data-preset="stable">STABLE ORBIT</button>
    <button class="preset-btn" data-preset="disrupt">DISRUPTION</button>
    <button class="preset-btn" data-preset="ring">RING FORMATION</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr, cx, cy;

  var TEAL = [0, 245, 212];
  var PURPLE = [123, 44, 191];
  var BG = [10, 10, 15];

  var massRatio = 20;
  var cohesionStr = 10;
  var satSize = 5;
  var trailAlpha = 0.5;
  var showRoche = true;
  var showTidal = true;

  var G = 800;
  var planetMass = 1;
  var planetRadius = 30;
  var dt = 0.016;

  var satellites = [];
  var fragments = [];
  var trails = [];
  var maxTrails = 2000;

  var dragging = false;
  var dragStart = null;
  var dragCurrent = null;

  var hBodies = document.getElementById('hud-bodies');
  var hFrags = document.getElementById('hud-frags');
  var hRoche = document.getElementById('hud-roche');
  var hDist = document.getElementById('hud-dist');

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2;
    cy = H / 2;
  }

  function rgba(c, a) {
    return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
  }

  function lerpColor(a, b, t) {
    return [
      a[0] + (b[0] - a[0]) * t,
      a[1] + (b[1] - a[1]) * t,
      a[2] + (b[2] - a[2]) * t
    ];
  }

  function rocheLimit() {
    var densityRatio = massRatio;
    return planetRadius * 2.44 * Math.pow(densityRatio, 1 / 3);
  }

  function createSatellite(px, py, vx, vy) {
    var sat = {
      particles: [],
      springs: [],
      alive: true,
      color: lerpColor(TEAL, PURPLE, Math.random() * 0.3)
    };

    var n = satSize;
    var spacing = 6;
    var idx = 0;

    for (var row = -n; row <= n; row++) {
      for (var col = -n; col <= n; col++) {
        var dx = col * spacing;
        var dy = row * spacing * 0.866;
        if (row % 2 !== 0) dx += spacing * 0.5;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist > n * spacing) continue;

        sat.particles.push({
          x: px + dx, y: py + dy,
          vx: vx, vy: vy,
          id: idx++,
          free: false
        });
      }
    }

    for (var i = 0; i < sat.particles.length; i++) {
      for (var j = i + 1; j < sat.particles.length; j++) {
        var pi = sat.particles[i], pj = sat.particles[j];
        var d = Math.hypot(pi.x - pj.x, pi.y - pj.y);
        if (d < spacing * 1.2) {
          sat.springs.push({
            a: i, b: j,
            rest: d,
            alive: true
          });
        }
      }
    }

    satellites.push(sat);
    if (window.SFX) SFX.play('spawn');
    return sat;
  }

  function updatePhysics() {
    var rl = rocheLimit();

    for (var si = satellites.length - 1; si >= 0; si--) {
      var sat = satellites[si];
      var aliveCount = 0;

      for (var i = 0; i < sat.particles.length; i++) {
        var p = sat.particles[i];
        var dx = p.x - cx;
        var dy = p.y - cy;
        var dist = Math.sqrt(dx * dx + dy * dy);

        if (dist < planetRadius) {
          p.x = cx + (dx / dist) * (planetRadius + 2);
          p.y = cy + (dy / dist) * (planetRadius + 2);
          var dot = (p.vx * dx + p.vy * dy) / dist;
          p.vx -= 1.8 * dot * dx / dist;
          p.vy -= 1.8 * dot * dy / dist;
          p.vx *= 0.8;
          p.vy *= 0.8;
          SFX.play('collide', 200);
        }

        if (dist > 1) {
          var force = G * planetMass / (dist * dist);
          var ax = -force * dx / dist;
          var ay = -force * dy / dist;
          p.vx += ax * dt;
          p.vy += ay * dt;
        }
      }

      var breakThreshold = cohesionStr * 0.8;
      for (var i = 0; i < sat.springs.length; i++) {
        var s = sat.springs[i];
        if (!s.alive) continue;

        var pa = sat.particles[s.a];
        var pb = sat.particles[s.b];
        var dx = pb.x - pa.x;
        var dy = pb.y - pa.y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        var stretch = dist - s.rest;

        var cmx = (pa.x + pb.x) / 2 - cx;
        var cmy = (pa.y + pb.y) / 2 - cy;
        var cmDist = Math.sqrt(cmx * cmx + cmy * cmy);

        var tidalFactor = (cmDist < rl) ? Math.pow(rl / Math.max(cmDist, planetRadius + 10), 3) : 0.5;
        var effectiveBreak = breakThreshold / tidalFactor;

        if (Math.abs(stretch) > effectiveBreak) {
          s.alive = false;
          pa.free = true;
          pb.free = true;
          SFX.play('pop', 100);
          continue;
        }

        var k = cohesionStr * 0.15;
        var fx = k * stretch * dx / dist;
        var fy = k * stretch * dy / dist;
        pa.vx += fx * dt;
        pa.vy += fy * dt;
        pb.vx -= fx * dt;
        pb.vy -= fy * dt;

        var dampF = 0.3;
        var dvx = pb.vx - pa.vx;
        var dvy = pb.vy - pa.vy;
        pa.vx += dampF * dvx * dt;
        pa.vy += dampF * dvy * dt;
        pb.vx -= dampF * dvx * dt;
        pb.vy -= dampF * dvy * dt;
      }

      for (var i = 0; i < sat.particles.length; i++) {
        var p = sat.particles[i];
        p.x += p.vx * dt;
        p.y += p.vy * dt;

        var hasSpring = false;
        for (var j = 0; j < sat.springs.length; j++) {
          if (sat.springs[j].alive && (sat.springs[j].a === i || sat.springs[j].b === i)) {
            hasSpring = true;
            break;
          }
        }
        if (!hasSpring) {
          p.free = true;
        }
        if (!p.free) aliveCount++;
      }

      if (aliveCount === 0) {
        for (var i = 0; i < sat.particles.length; i++) {
          var p = sat.particles[i];
          fragments.push({
            x: p.x, y: p.y,
            vx: p.vx, vy: p.vy,
            color: sat.color,
            life: 1
          });
        }
        satellites.splice(si, 1);
      }
    }

    for (var i = fragments.length - 1; i >= 0; i--) {
      var f = fragments[i];
      var dx = f.x - cx;
      var dy = f.y - cy;
      var dist = Math.sqrt(dx * dx + dy * dy);

      if (dist < planetRadius) {
        f.x = cx + (dx / dist) * (planetRadius + 1);
        f.y = cy + (dy / dist) * (planetRadius + 1);
        var dot = (f.vx * dx + f.vy * dy) / dist;
        f.vx -= 1.8 * dot * dx / dist;
        f.vy -= 1.8 * dot * dy / dist;
        f.vx *= 0.7;
        f.vy *= 0.7;
      }

      if (dist > 1) {
        var force = G * planetMass / (dist * dist);
        f.vx -= force * dx / dist * dt;
        f.vy -= force * dy / dist * dt;
      }

      f.x += f.vx * dt;
      f.y += f.vy * dt;

      f.life -= 0.0003;
      if (f.life <= 0 || f.x < -200 || f.x > W + 200 || f.y < -200 || f.y > H + 200) {
        fragments.splice(i, 1);
      }
    }

    if (trailAlpha > 0) {
      for (var si = 0; si < satellites.length; si++) {
        var sat = satellites[si];
        var sumx = 0, sumy = 0, cnt = 0;
        for (var i = 0; i < sat.particles.length; i++) {
          if (!sat.particles[i].free) {
            sumx += sat.particles[i].x;
            sumy += sat.particles[i].y;
            cnt++;
          }
        }
        if (cnt > 0) {
          trails.push({ x: sumx / cnt, y: sumy / cnt, color: sat.color, age: 0 });
        }
      }
      for (var i = 0; i < fragments.length; i++) {
        if (Math.random() < 0.05) {
          trails.push({ x: fragments[i].x, y: fragments[i].y, color: fragments[i].color, age: 0 });
        }
      }
      while (trails.length > maxTrails) trails.shift();
      for (var i = trails.length - 1; i >= 0; i--) {
        trails[i].age++;
        if (trails[i].age > 300) trails.splice(i, 1);
      }
    }
  }

  function drawPlanet() {
    var grad = X.createRadialGradient(cx, cy, 0, cx, cy, planetRadius * 3);
    grad.addColorStop(0, rgba(TEAL, 0.08));
    grad.addColorStop(0.3, rgba(TEAL, 0.03));
    grad.addColorStop(1, 'transparent');
    X.fillStyle = grad;
    X.beginPath();
    X.arc(cx, cy, planetRadius * 3, 0, Math.PI * 2);
    X.fill();

    grad = X.createRadialGradient(cx, cy, 0, cx, cy, planetRadius);
    grad.addColorStop(0, rgba(TEAL, 0.6));
    grad.addColorStop(0.7, rgba(TEAL, 0.3));
    grad.addColorStop(1, rgba(TEAL, 0.1));
    X.fillStyle = grad;
    X.beginPath();
    X.arc(cx, cy, planetRadius, 0, Math.PI * 2);
    X.fill();

    X.strokeStyle = rgba(TEAL, 0.4);
    X.lineWidth = 1.5;
    X.beginPath();
    X.arc(cx, cy, planetRadius, 0, Math.PI * 2);
    X.stroke();
  }

  function drawRocheZone() {
    if (!showRoche) return;
    var rl = rocheLimit();

    X.setLineDash([8, 6]);
    X.strokeStyle = rgba(PURPLE, 0.35);
    X.lineWidth = 1.5;
    X.beginPath();
    X.arc(cx, cy, rl, 0, Math.PI * 2);
    X.stroke();
    X.setLineDash([]);

    var grad = X.createRadialGradient(cx, cy, planetRadius, cx, cy, rl);
    grad.addColorStop(0, rgba(PURPLE, 0.04));
    grad.addColorStop(1, rgba(PURPLE, 0.01));
    X.fillStyle = grad;
    X.beginPath();
    X.arc(cx, cy, rl, 0, Math.PI * 2);
    X.fill();
  }

  function drawTidalArrows(px, py) {
    if (!showTidal) return;

    var dx = px - cx;
    var dy = py - cy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist < planetRadius + 5) return;

    var radX = dx / dist;
    var radY = dy / dist;
    var tanX = -radY;
    var tanY = radX;

    var tidalMag = G * planetMass * 2 / (dist * dist * dist) * 20;
    tidalMag = Math.min(tidalMag, 40);

    X.strokeStyle = rgba(PURPLE, 0.5);
    X.lineWidth = 1.5;

    var arrowLen = tidalMag * 1.5;

    X.beginPath();
    X.moveTo(px + radX * 5, py + radY * 5);
    X.lineTo(px + radX * (5 + arrowLen), py + radY * (5 + arrowLen));
    X.stroke();
    drawArrowHead(px + radX * (5 + arrowLen), py + radY * (5 + arrowLen), radX, radY);

    X.beginPath();
    X.moveTo(px - radX * 5, py - radY * 5);
    X.lineTo(px - radX * (5 + arrowLen), py - radY * (5 + arrowLen));
    X.stroke();
    drawArrowHead(px - radX * (5 + arrowLen), py - radY * (5 + arrowLen), -radX, -radY);

    var compArrow = arrowLen * 0.5;
    X.beginPath();
    X.moveTo(px + tanX * 5, py + tanY * 5);
    X.lineTo(px - tanX * 0, py - tanY * 0);
    X.stroke();

    X.beginPath();
    X.moveTo(px - tanX * 5, py - tanY * 5);
    X.lineTo(px + tanX * 0, py + tanY * 0);
    X.stroke();
  }

  function drawArrowHead(x, y, dirX, dirY) {
    var size = 5;
    var perpX = -dirY;
    var perpY = dirX;
    X.beginPath();
    X.moveTo(x, y);
    X.lineTo(x - dirX * size + perpX * size * 0.4, y - dirY * size + perpY * size * 0.4);
    X.lineTo(x - dirX * size - perpX * size * 0.4, y - dirY * size - perpY * size * 0.4);
    X.closePath();
    X.fillStyle = rgba(PURPLE, 0.5);
    X.fill();
  }

  function drawTrails() {
    if (trailAlpha <= 0) return;
    for (var i = 0; i < trails.length; i++) {
      var t = trails[i];
      var a = trailAlpha * (1 - t.age / 300) * 0.3;
      X.fillStyle = rgba(t.color, a);
      X.fillRect(t.x - 0.5, t.y - 0.5, 1, 1);
    }
  }

  function drawSatellites() {
    for (var si = 0; si < satellites.length; si++) {
      var sat = satellites[si];

      for (var i = 0; i < sat.springs.length; i++) {
        var s = sat.springs[i];
        if (!s.alive) continue;
        var pa = sat.particles[s.a];
        var pb = sat.particles[s.b];
        var stretch = Math.abs(Math.hypot(pb.x - pa.x, pb.y - pa.y) - s.rest);
        var tension = Math.min(1, stretch / (cohesionStr * 0.5));
        var col = lerpColor(sat.color, PURPLE, tension);
        X.strokeStyle = rgba(col, 0.3 + tension * 0.3);
        X.lineWidth = 1;
        X.beginPath();
        X.moveTo(pa.x, pa.y);
        X.lineTo(pb.x, pb.y);
        X.stroke();
      }

      var cmx = 0, cmy = 0, cnt = 0;
      for (var i = 0; i < sat.particles.length; i++) {
        var p = sat.particles[i];
        var glow = p.free ? 0.4 : 0.8;
        var r = p.free ? 2 : 3;
        X.fillStyle = rgba(sat.color, glow);
        X.beginPath();
        X.arc(p.x, p.y, r, 0, Math.PI * 2);
        X.fill();

        if (!p.free) {
          cmx += p.x; cmy += p.y; cnt++;
        }
      }

      if (cnt > 0 && showTidal) {
        drawTidalArrows(cmx / cnt, cmy / cnt);
      }
    }
  }

  function drawFragments() {
    for (var i = 0; i < fragments.length; i++) {
      var f = fragments[i];
      X.fillStyle = rgba(f.color, f.life * 0.6);
      X.beginPath();
      X.arc(f.x, f.y, 1.5, 0, Math.PI * 2);
      X.fill();
    }
  }

  function drawDragPreview() {
    if (!dragging || !dragStart || !dragCurrent) return;

    X.strokeStyle = rgba(TEAL, 0.4);
    X.lineWidth = 1;
    X.setLineDash([4, 4]);
    X.beginPath();
    X.moveTo(dragStart.x, dragStart.y);
    X.lineTo(dragCurrent.x, dragCurrent.y);
    X.stroke();
    X.setLineDash([]);

    var vx = (dragCurrent.x - dragStart.x) * 0.5;
    var vy = (dragCurrent.y - dragStart.y) * 0.5;

    var dx = dragStart.x - cx;
    var dy = dragStart.y - cy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    var speed = Math.sqrt(vx * vx + vy * vy);
    var orbitalV = Math.sqrt(G * planetMass / Math.max(dist, 1));

    X.fillStyle = rgba(TEAL, 0.3);
    X.font = '10px "Orbitron", monospace';
    X.fillText('v=' + speed.toFixed(0) + ' v_orb=' + orbitalV.toFixed(0), dragStart.x + 10, dragStart.y - 10);

    X.fillStyle = rgba(TEAL, 0.15);
    X.beginPath();
    X.arc(dragStart.x, dragStart.y, satSize * 6, 0, Math.PI * 2);
    X.fill();
    X.strokeStyle = rgba(TEAL, 0.3);
    X.lineWidth = 1;
    X.beginPath();
    X.arc(dragStart.x, dragStart.y, satSize * 6, 0, Math.PI * 2);
    X.stroke();
  }

  function updateHUD() {
    var totalBound = 0;
    var nearest = Infinity;
    for (var si = 0; si < satellites.length; si++) {
      var sat = satellites[si];
      var cnt = 0;
      var cmx = 0, cmy = 0;
      for (var i = 0; i < sat.particles.length; i++) {
        if (!sat.particles[i].free) {
          cmx += sat.particles[i].x;
          cmy += sat.particles[i].y;
          cnt++;
        }
      }
      if (cnt > 0) {
        totalBound++;
        var d = Math.hypot(cmx / cnt - cx, cmy / cnt - cy);
        if (d < nearest) nearest = d;
      }
    }
    hBodies.textContent = totalBound;
    hFrags.textContent = fragments.length;
    hRoche.textContent = rocheLimit().toFixed(0) + 'px';
    hDist.textContent = nearest < Infinity ? nearest.toFixed(0) + 'px' : '-';
  }

  function draw() {
    X.fillStyle = 'rgb(' + BG[0] + ',' + BG[1] + ',' + BG[2] + ')';
    X.fillRect(0, 0, W, H);

    drawTrails();
    drawRocheZone();
    drawPlanet();
    drawFragments();
    drawSatellites();
    drawDragPreview();

    updateHUD();
    requestAnimationFrame(draw);
  }

  function tick() {
    for (var i = 0; i < 2; i++) {
      updatePhysics();
    }
    setTimeout(tick, 16);
  }

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    e.preventDefault();
    dragStart = { x: e.clientX, y: e.clientY };
    dragCurrent = { x: e.clientX, y: e.clientY };
    dragging = true;
  });

  C.addEventListener('pointermove', function(e) {
    if (!dragging) return;
    dragCurrent = { x: e.clientX, y: e.clientY };
  });

  C.addEventListener('pointerup', function(e) {
    if (e.button === 2 || !dragging) return;
    e.preventDefault();

    var dist = Math.hypot(dragCurrent.x - dragStart.x, dragCurrent.y - dragStart.y);
    var px = dragStart.x;
    var py = dragStart.y;

    var toPlanet = Math.hypot(px - cx, py - cy);
    if (toPlanet < planetRadius + 10) {
      dragging = false;
      dragStart = null;
      dragCurrent = null;
      return;
    }

    var vx, vy;
    if (dist < 5) {
      var dx = px - cx;
      var dy = py - cy;
      var d = Math.sqrt(dx * dx + dy * dy);
      var orbV = Math.sqrt(G * planetMass / d);
      vx = -dy / d * orbV;
      vy = dx / d * orbV;
    } else {
      vx = (dragCurrent.x - dragStart.x) * 0.5;
      vy = (dragCurrent.y - dragStart.y) * 0.5;
    }

    createSatellite(px, py, vx, vy);

    dragging = false;
    dragStart = null;
    dragCurrent = null;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var mx = e.clientX, my = e.clientY;

    for (var si = satellites.length - 1; si >= 0; si--) {
      var sat = satellites[si];
      for (var i = 0; i < sat.particles.length; i++) {
        if (Math.hypot(sat.particles[i].x - mx, sat.particles[i].y - my) < 20) {
          for (var j = 0; j < sat.particles.length; j++) {
            fragments.push({
              x: sat.particles[j].x,
              y: sat.particles[j].y,
              vx: sat.particles[j].vx,
              vy: sat.particles[j].vy,
              color: sat.color,
              life: 0.5
            });
          }
          satellites.splice(si, 1);
          SFX.play('pop');
          return;
        }
      }
    }
  });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length > 1) e.preventDefault();
  }, { passive: false });

  var slMass = document.getElementById('sl-mass');
  var svMass = document.getElementById('sv-mass');
  slMass.addEventListener('input', function() {
    massRatio = parseInt(this.value);
    svMass.textContent = massRatio;
    SFX.play('move', 80);
  });

  var slCohesion = document.getElementById('sl-cohesion');
  var svCohesion = document.getElementById('sv-cohesion');
  slCohesion.addEventListener('input', function() {
    cohesionStr = parseInt(this.value);
    svCohesion.textContent = cohesionStr;
    SFX.play('move', 80);
  });

  var slSize = document.getElementById('sl-size');
  var svSize = document.getElementById('sv-size');
  slSize.addEventListener('input', function() {
    satSize = parseInt(this.value);
    svSize.textContent = satSize;
    SFX.play('move', 80);
  });

  var slTrail = document.getElementById('sl-trail');
  var svTrail = document.getElementById('sv-trail');
  slTrail.addEventListener('input', function() {
    trailAlpha = this.value / 100;
    svTrail.textContent = this.value;
  });

  document.getElementById('btn-roche').addEventListener('click', function() {
    showRoche = !showRoche;
    this.classList.toggle('active', showRoche);
    SFX.play('toggle');
  });

  document.getElementById('btn-tidal').addEventListener('click', function() {
    showTidal = !showTidal;
    this.classList.toggle('active', showTidal);
    SFX.play('toggle');
  });

  document.getElementById('btn-clear').addEventListener('click', function() {
    satellites = [];
    fragments = [];
    trails = [];
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
    satellites = [];
    fragments = [];
    trails = [];

    if (name === 'stable') {
      var d = rocheLimit() * 1.8;
      var orbV = Math.sqrt(G * planetMass / d);
      createSatellite(cx + d, cy, 0, orbV);
    } else if (name === 'disrupt') {
      var d = rocheLimit() * 0.7;
      var orbV = Math.sqrt(G * planetMass / d);
      createSatellite(cx, cy - d, orbV, 0);
    } else if (name === 'ring') {
      var rl = rocheLimit();
      for (var i = 0; i < 3; i++) {
        var angle = (i / 3) * Math.PI * 2;
        var d = rl * (0.8 + i * 0.15);
        var px = cx + Math.cos(angle) * d;
        var py = cy + Math.sin(angle) * d;
        var orbV = Math.sqrt(G * planetMass / d);
        var vx = -Math.sin(angle) * orbV;
        var vy = Math.cos(angle) * orbV;
        createSatellite(px, py, vx, vy);
      }
    }

    SFX.play('success');
  }

  document.querySelectorAll('[data-preset]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      loadPreset(this.dataset.preset);
    });
  });

  function initDemo() {
    var d = rocheLimit() * 1.3;
    var orbV = Math.sqrt(G * planetMass / d);
    createSatellite(cx + d, cy, 0, orbV * 0.85);

    var d2 = rocheLimit() * 2;
    var orbV2 = Math.sqrt(G * planetMass / d2);
    createSatellite(cx - d2, cy, 0, -orbV2);
  }

  resize();
  window.addEventListener('resize', resize);
  initDemo();
  tick();
  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
