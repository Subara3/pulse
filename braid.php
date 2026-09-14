<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('braid');

$pageName = 'braid';
$pageTitle = 'BRAID - PULSE';
$pageDesc = '数学的組紐。N本の糸が交差しながら流れ、組紐群のトポロジーを編む。';
$pageCanonical = 'https://pulse.subara3.com/braid.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #braid-word{position:fixed;top:16px;left:50%;transform:translateX(-50%);color:rgba(0,245,212,.45);font:12px "Space Grotesk",monospace;pointer-events:none;z-index:10;max-width:80vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;letter-spacing:.08em}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>STRANDS <span id="hud-n">4</span></div>
  <div>CROSSINGS <span id="hud-k">0</span></div>
</div>
<div id="braid-word"></div>
<div id="info-label"><span>BRAID</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'cross'],
  ['type' => 'drag', 'label' => 'scroll'],
  ['type' => 'right', 'label' => 'undo'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row"><label>STRANDS</label><input type="range" id="sl-strands" min="3" max="8" value="4"></div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-auto" class="mode-btn active">AUTO</button>
      <button id="btn-close" class="mode-btn">CLOSE</button>
      <button id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;
  var hudN = document.getElementById('hud-n');
  var hudK = document.getElementById('hud-k');
  var braidWordEl = document.getElementById('braid-word');

  var BG = '#0a0a0f';
  var N = 4;
  var GAP = 70;
  var MARGIN = 60;
  var crossings = [];
  var autoMode = true;
  var closedMode = false;
  var scrollY = 0;
  var scrollTarget = 0;
  var autoTimer = 0;
  var AUTO_DELAY = 0.85;
  var MAX_CROSS = 60;
  var particles = [];
  var PARTICLE_PER_STRAND = 6;
  var PARTICLE_SPEED = 90;
  var time = 0;
  var lastFrame = 0;
  var colors = [];
  var permsCache = null;

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function buildColors() {
    colors = [];
    for (var i = 0; i < N; i++) {
      var h = brandHue(i / Math.max(N - 1, 1));
      colors.push({
        over: 'hsl(' + h + ',85%,62%)',
        norm: 'hsl(' + h + ',75%,48%)',
        under: 'hsl(' + h + ',50%,28%)',
        glow: 'hsla(' + h + ',80%,55%,.35)',
        dot: 'hsl(' + h + ',90%,78%)'
      });
    }
  }

  function resize() {
    var dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function sX(pos) {
    var m = Math.max(50, W * 0.1);
    if (N <= 1) return W / 2;
    return m + pos * (W - 2 * m) / (N - 1);
  }

  function lY(k) {
    return MARGIN + k * GAP - scrollY;
  }

  function computePerms() {
    if (permsCache) return permsCache;
    var res = [];
    var p = [];
    for (var i = 0; i < N; i++) p.push(i);
    res.push(p.slice());
    for (var k = 0; k < crossings.length; k++) {
      p = p.slice();
      var c = crossings[k];
      var tmp = p[c.pos];
      p[c.pos] = p[c.pos + 1];
      p[c.pos + 1] = tmp;
      res.push(p.slice());
    }
    permsCache = res;
    return res;
  }

  function invalidatePerms() { permsCache = null; }

  function addCrossing(pos, over) {
    if (pos < 0 || pos >= N - 1) return;
    crossings.push({ pos: pos, over: over });
    if (crossings.length > MAX_CROSS) crossings.shift();
    invalidatePerms();
    updateHUD();
  }

  function addRandom() {
    var pos = Math.floor(Math.random() * (N - 1));
    var over = Math.random() < 0.5;
    addCrossing(pos, over);
    if (typeof SFX !== 'undefined') SFX.play('spawn', 150);
  }

  function removeLast() {
    if (crossings.length === 0) return;
    crossings.pop();
    invalidatePerms();
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }

  function resetBraid() {
    crossings = [];
    scrollY = 0;
    scrollTarget = 0;
    invalidatePerms();
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  }

  function braidWord() {
    if (crossings.length === 0) return '∅';
    var w = '';
    var shown = crossings.length > 30 ? crossings.slice(-30) : crossings;
    if (crossings.length > 30) w = '…';
    for (var i = 0; i < shown.length; i++) {
      var c = shown[i];
      w += 'σ';
      w += String.fromCharCode(0x2080 + c.pos + 1);
      if (!c.over) w += '⁻¹';
    }
    return w;
  }

  function updateHUD() {
    hudN.textContent = N;
    hudK.textContent = crossings.length;
    braidWordEl.textContent = braidWord();
  }

  // ---- Drawing ----
  function bezier(x1, y1, x2, y2) {
    var dy = y2 - y1;
    ctx.moveTo(x1, y1);
    ctx.bezierCurveTo(x1, y1 + dy * 0.4, x2, y2 - dy * 0.4, x2, y2);
  }

  function smoothX(x1, x2, t) {
    var s = t * t * (3 - 2 * t);
    return x1 + (x2 - x1) * s;
  }

  function drawStraight(x, y1, y2, ci) {
    if (y2 < -10 || y1 > H + 10) return;
    var col = colors[ci];
    ctx.save();
    ctx.strokeStyle = col.norm;
    ctx.lineWidth = 3;
    ctx.shadowColor = col.glow;
    ctx.shadowBlur = 8;
    ctx.beginPath();
    ctx.moveTo(x, Math.max(y1, -20));
    ctx.lineTo(x, Math.min(y2, H + 20));
    ctx.stroke();
    ctx.restore();
  }

  function drawCrossing(c, y1, y2, perm) {
    if (y2 < -20 || y1 > H + 20) return;
    var a = perm[c.pos], b = perm[c.pos + 1];
    var xA = sX(c.pos), xB = sX(c.pos + 1);
    var dy = y2 - y1;
    var oi, ui, ofx, otx, ufx, utx;
    if (c.over) { oi = a; ui = b; ofx = xA; otx = xB; ufx = xB; utx = xA; }
    else        { oi = b; ui = a; ofx = xB; otx = xA; ufx = xA; utx = xB; }

    ctx.save();
    ctx.strokeStyle = BG;
    ctx.lineWidth = 11;
    ctx.shadowBlur = 0;
    ctx.beginPath();
    bezier(ofx, y1, otx, y2);
    ctx.stroke();
    ctx.restore();

    ctx.save();
    ctx.strokeStyle = colors[ui].under;
    ctx.lineWidth = 2.5;
    ctx.shadowColor = colors[ui].glow;
    ctx.shadowBlur = 5;
    ctx.beginPath();
    bezier(ufx, y1, utx, y2);
    ctx.stroke();
    ctx.restore();

    ctx.save();
    ctx.strokeStyle = colors[oi].over;
    ctx.lineWidth = 3.5;
    ctx.shadowColor = colors[oi].glow;
    ctx.shadowBlur = 14;
    ctx.beginPath();
    bezier(ofx, y1, otx, y2);
    ctx.stroke();
    ctx.restore();
  }

  function drawClosure(perms) {
    if (!closedMode || crossings.length === 0) return;
    var topPerm = perms[0];
    var botPerm = perms[perms.length - 1];
    var yTop = lY(0);
    var yBot = lY(crossings.length);
    var arcW = 30;

    ctx.save();
    ctx.lineWidth = 2.5;
    ctx.shadowBlur = 8;
    for (var j = 0; j < N; j++) {
      var si = topPerm[j];
      var bPos = -1;
      for (var p = 0; p < N; p++) { if (botPerm[p] === si) { bPos = p; break; } }
      var xT = sX(j), xB = sX(bPos);
      var col = colors[si];
      ctx.strokeStyle = col.norm;
      ctx.shadowColor = col.glow;
      var cx = Math.min(xT, xB) - arcW - j * 8;
      ctx.beginPath();
      ctx.moveTo(xT, yTop);
      ctx.bezierCurveTo(cx, yTop, cx, yBot, xB, yBot);
      ctx.stroke();
    }
    ctx.restore();
  }

  function renderBraid() {
    var perms = computePerms();
    var kLen = crossings.length;

    var topY = lY(0);
    var botY = lY(kLen);

    for (var k = -1; k <= kLen; k++) {
      var y1, y2;
      if (k < 0) { y1 = -20; y2 = topY; }
      else if (k >= kLen) { y1 = botY; y2 = H + 20; }
      else { y1 = lY(k); y2 = lY(k + 1); }

      if (y2 < -20 || y1 > H + 20) continue;

      var pi = (k < 0) ? 0 : (k >= kLen ? kLen : k);
      var perm = perms[pi];

      if (k >= 0 && k < kLen) {
        var c = crossings[k];
        for (var j = 0; j < N; j++) {
          if (j === c.pos || j === c.pos + 1) continue;
          drawStraight(sX(j), y1, y2, perm[j]);
        }
        drawCrossing(c, y1, y2, perm);
      } else {
        for (var j = 0; j < N; j++) {
          drawStraight(sX(j), y1, y2, perm[j]);
        }
      }
    }

    drawClosure(perms);
  }

  // ---- Particles ----
  function initParticles() {
    particles = [];
    for (var s = 0; s < N; s++) {
      for (var i = 0; i < PARTICLE_PER_STRAND; i++) {
        particles.push({
          strand: s,
          y: Math.random() * H
        });
      }
    }
  }

  function particleX(strand, py) {
    var perms = computePerms();
    var kLen = crossings.length;
    for (var k = 0; k < kLen; k++) {
      var y1 = lY(k);
      var y2 = lY(k + 1);
      if (py >= y1 && py < y2) {
        var perm = perms[k];
        var pos = -1;
        for (var j = 0; j < N; j++) { if (perm[j] === strand) { pos = j; break; } }
        if (pos < 0) return W / 2;
        var c = crossings[k];
        if (pos === c.pos || pos === c.pos + 1) {
          var t = (py - y1) / (y2 - y1);
          var newPos = (pos === c.pos) ? c.pos + 1 : c.pos;
          return smoothX(sX(pos), sX(newPos), t);
        }
        return sX(pos);
      }
    }
    var pi = (kLen === 0 || py < lY(0)) ? 0 : kLen;
    var perm = perms[pi];
    for (var j = 0; j < N; j++) { if (perm[j] === strand) return sX(j); }
    return W / 2;
  }

  function updateParticles(dt) {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      p.y += PARTICLE_SPEED * dt;
      if (p.y > H + 20) p.y = -20;
      if (p.strand >= N) {
        p.strand = p.strand % N;
      }
    }
  }

  function drawParticles() {
    ctx.save();
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var x = particleX(p.strand, p.y);
      var col = colors[p.strand];
      ctx.fillStyle = col.dot;
      ctx.shadowColor = col.glow;
      ctx.shadowBlur = 10;
      ctx.beginPath();
      ctx.arc(x, p.y, 2.5, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.restore();
  }

  // ---- Scroll ----
  function updateScroll(dt) {
    var braidH = crossings.length * GAP;
    var viewH = H - 2 * MARGIN;
    if (braidH > viewH) {
      scrollTarget = braidH - viewH;
    } else {
      scrollTarget = 0;
    }
    scrollY += (scrollTarget - scrollY) * Math.min(1, 5 * dt);
  }

  // ---- Interaction ----
  function findSlot(cx, cy) {
    var bestPos = 0;
    var bestDist = Infinity;
    for (var j = 0; j < N - 1; j++) {
      var mx = (sX(j) + sX(j + 1)) / 2;
      var d = Math.abs(cx - mx);
      if (d < bestDist) { bestDist = d; bestPos = j; }
    }
    return bestPos;
  }

  function handleClick(cx, cy) {
    if (closedMode) return;
    var pos = findSlot(cx, cy);
    var over = cx <= (sX(pos) + sX(pos + 1)) / 2;
    addCrossing(pos, over);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  var isDragging = false;
  var dragStartY = 0;
  var dragScrollStart = 0;

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) { e.preventDefault(); removeLast(); return; }
    if (e.button !== 0) return;
    isDragging = false;
    dragStartY = e.clientY;
    dragScrollStart = scrollY;
  });

  C.addEventListener('pointermove', function(e) {
    if (e.buttons !== 1) return;
    var dy = e.clientY - dragStartY;
    if (Math.abs(dy) > 8) isDragging = true;
    if (isDragging && !autoMode) {
      scrollY = dragScrollStart - dy;
      scrollTarget = scrollY;
    }
  });

  C.addEventListener('pointerup', function(e) {
    if (e.button !== 0) return;
    if (!isDragging) handleClick(e.clientX, e.clientY);
    isDragging = false;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var lastTap = 0;
  C.addEventListener('touchend', function(e) {
    var now = Date.now();
    if (now - lastTap < 300) {
      removeLast();
      e.preventDefault();
    }
    lastTap = now;
  }, { passive: false });

  // ---- Controls ----
  var slStrands = document.getElementById('sl-strands');
  var btnAuto = document.getElementById('btn-auto');
  var btnClose = document.getElementById('btn-close');
  var btnReset = document.getElementById('btn-reset');

  slStrands.addEventListener('input', function() {
    var v = parseInt(this.value);
    if (v === N) return;
    N = v;
    crossings = [];
    scrollY = 0; scrollTarget = 0;
    buildColors();
    invalidatePerms();
    initParticles();
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  btnAuto.addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    if (autoMode) closedMode = false;
    if (btnClose) btnClose.classList.toggle('active', closedMode);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  btnClose.addEventListener('click', function() {
    closedMode = !closedMode;
    this.classList.toggle('active', closedMode);
    if (closedMode) { autoMode = false; btnAuto.classList.remove('active'); }
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  btnReset.addEventListener('click', function() { resetBraid(); });

  // ---- Loop ----
  function frame(ts) {
    if (!lastFrame) lastFrame = ts;
    var dt = Math.min((ts - lastFrame) / 1000, 0.05);
    lastFrame = ts;
    time += dt;

    if (autoMode) {
      autoTimer += dt;
      if (autoTimer >= AUTO_DELAY) {
        autoTimer -= AUTO_DELAY;
        addRandom();
      }
    }

    updateScroll(dt);
    updateParticles(dt);

    ctx.clearRect(0, 0, W, H);
    renderBraid();
    drawParticles();

    requestAnimationFrame(frame);
  }

  // ---- Init ----
  resize();
  buildColors();
  for (var i = 0; i < 8; i++) {
    crossings.push({ pos: Math.floor(Math.random() * (N - 1)), over: Math.random() < 0.5 });
  }
  invalidatePerms();
  initParticles();
  updateHUD();
  window.addEventListener('resize', function() { resize(); initParticles(); });
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
