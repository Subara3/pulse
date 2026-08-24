<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pid');

$pageName = 'pid';
$pageTitle = 'PID - PULSE';
$pageDesc = 'PID制御のダイナミクス。比例・積分・微分の3つのゲインが制御応答を決める。5つのプリセットが描く軌跡の比較。';
$pageCanonical = 'https://pulse.subara3.com/pid.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MODE <span id="hud-mode">COMPARE</span></div>
  <div>AGENTS <span id="hud-n">5</span></div>
</div>
<div id="info-label"><span>PID CONTROL</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'target'],
  ['type' => 'drag', 'label' => 'track'],
  ['type' => 'right', 'label' => 'disturb'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row" id="mode-btns">
    <button class="ctrl-btn active" data-val="compare">COMPARE</button>
    <button class="ctrl-btn" data-val="tune">TUNE</button>
    <button class="ctrl-btn" data-val="track">TRACK</button>
  </div>
  <div id="tune-controls" style="display:none">
    <div class="slider-row">
      <label class="slider-label">Kp</label>
      <input type="range" id="sl-p" min="0" max="300" value="80" step="1">
      <span class="slider-value" id="sv-p">80</span>
    </div>
    <div class="slider-row">
      <label class="slider-label">Ki</label>
      <input type="range" id="sl-i" min="0" max="50" value="8" step="1">
      <span class="slider-value" id="sv-i">8</span>
    </div>
    <div class="slider-row">
      <label class="slider-label">Kd</label>
      <input type="range" id="sl-d" min="0" max="100" value="18" step="1">
      <span class="slider-value" id="sv-d">18</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-wind">WIND</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;
  var SUB = 8, DT = 1 / 60, MI = 8000;

  var mode = 'compare';
  var target = {x: 0, y: 0};
  var agents = [];
  var graphT = 0;
  var windOn = false, windF = 2000;
  var tracking = false;
  var Kp = 80, Ki = 8, Kd = 18;

  var presets = [
    {name: 'P',    kp: 80,  ki: 0,  kd: 0,  c: [0, 245, 212]},
    {name: 'PD',   kp: 80,  ki: 0,  kd: 18, c: [20, 190, 225]},
    {name: 'PID',  kp: 80,  ki: 8,  kd: 18, c: [75, 130, 215]},
    {name: 'SOFT', kp: 30,  ki: 3,  kd: 16, c: [130, 85, 200]},
    {name: 'FAST', kp: 200, ki: 15, kd: 22, c: [175, 70, 185]}
  ];

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }

  function makeAgent(kp, ki, kd, c, name) {
    return {
      x: 0, y: 0, vx: 0, vy: 0, ix: 0, iy: 0,
      kp: kp, ki: ki, kd: kd, c: c, name: name,
      trail: [], hist: [],
      stepStart: null, stepDir: null, stepDist: 0, arrived: false
    };
  }

  function resize() {
    dpr = devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function initAgents() {
    agents = [];
    var sx = W * 0.35, sy = H * 0.55;
    if (mode === 'tune') {
      var a = makeAgent(Kp, Ki, Kd, [0, 245, 212], 'PID');
      a.x = sx; a.y = sy;
      agents.push(a);
    } else {
      for (var i = 0; i < presets.length; i++) {
        var p = presets[i];
        var a = makeAgent(p.kp, p.ki, p.kd, p.c, p.name);
        a.x = sx; a.y = sy;
        agents.push(a);
      }
    }
    target.x = W * 0.58; target.y = H * 0.38;
    windOn = false;
    document.getElementById('btn-wind').classList.remove('active');
    recordStep();
    updateHUD();
  }

  function recordStep() {
    graphT = 0;
    for (var i = 0; i < agents.length; i++) {
      var a = agents[i];
      a.hist = [];
      a.stepStart = {x: a.x, y: a.y};
      var dx = target.x - a.x, dy = target.y - a.y;
      a.stepDist = Math.sqrt(dx * dx + dy * dy);
      if (a.stepDist > 0.5) {
        a.stepDir = {x: dx / a.stepDist, y: dy / a.stepDist};
      } else {
        a.stepDir = {x: 1, y: 0}; a.stepDist = 1;
      }
    }
  }

  function update() {
    var h = DT / SUB;
    graphT += DT;
    var wx = windOn ? windF : 0;
    for (var i = 0; i < agents.length; i++) {
      var a = agents[i];
      for (var s = 0; s < SUB; s++) {
        var ex = target.x - a.x, ey = target.y - a.y;
        a.ix += ex * h; a.iy += ey * h;
        if (a.ix > MI) a.ix = MI; if (a.ix < -MI) a.ix = -MI;
        if (a.iy > MI) a.iy = MI; if (a.iy < -MI) a.iy = -MI;
        var fx = a.kp * ex + a.ki * a.ix - a.kd * a.vx + wx;
        var fy = a.kp * ey + a.ki * a.iy - a.kd * a.vy;
        a.vx += fx * h; a.vy += fy * h;
        a.x += a.vx * h; a.y += a.vy * h;
      }
      a.trail.push({x: a.x, y: a.y});
      if (a.trail.length > 600) a.trail.shift();
      if (a.stepDist > 0.5) {
        var px = a.x - a.stepStart.x, py = a.y - a.stepStart.y;
        var proj = (px * a.stepDir.x + py * a.stepDir.y) / a.stepDist;
        a.hist.push({t: graphT, v: proj});
        if (a.hist.length > 1200) a.hist.shift();
      }
      var dist = Math.sqrt((a.x - target.x) * (a.x - target.x) + (a.y - target.y) * (a.y - target.y));
      if (dist < 5 && !a.arrived) {
        a.arrived = true;
        if (typeof SFX !== 'undefined') SFX.play('success', 2000);
      }
      if (dist > 30) a.arrived = false;
    }
  }

  function draw() {
    X.fillStyle = 'rgb(10,10,15)';
    X.fillRect(0, 0, W, H);
    drawGrid();
    if (windOn) drawWind();
    drawTarget();
    drawTrails();
    drawAgents();
    if (mode !== 'track') drawGraph();
  }

  function drawGrid() {
    X.strokeStyle = 'rgba(255,255,255,0.02)';
    X.lineWidth = 1;
    var g = 80;
    X.beginPath();
    for (var x = g; x < W; x += g) { X.moveTo(x, 0); X.lineTo(x, H); }
    for (var y = g; y < H; y += g) { X.moveTo(0, y); X.lineTo(W, y); }
    X.stroke();
  }

  function drawWind() {
    var cx = W * 0.5, cy = H * 0.08;
    X.save();
    X.strokeStyle = 'rgba(0,245,212,0.12)';
    X.lineWidth = 2;
    X.beginPath();
    for (var r = 0; r < 3; r++) {
      var ox = (r - 1) * 18;
      X.moveTo(cx - 30 + ox, cy); X.lineTo(cx + 30 + ox, cy);
      X.moveTo(cx + 22 + ox, cy - 5); X.lineTo(cx + 30 + ox, cy); X.lineTo(cx + 22 + ox, cy + 5);
    }
    X.stroke();
    X.font = '9px Space Grotesk,monospace';
    X.fillStyle = 'rgba(0,245,212,0.12)';
    X.textAlign = 'center';
    X.fillText('WIND', cx, cy + 18);
    X.restore();
  }

  function drawTarget() {
    var tx = target.x, ty = target.y;
    var p = 0.5 + 0.3 * Math.sin(performance.now() * 0.003);
    var g = X.createRadialGradient(tx, ty, 0, tx, ty, 35);
    g.addColorStop(0, 'rgba(123,44,191,' + (0.2 * p) + ')');
    g.addColorStop(1, 'rgba(123,44,191,0)');
    X.fillStyle = g;
    X.beginPath(); X.arc(tx, ty, 35, 0, Math.PI * 2); X.fill();
    X.strokeStyle = 'rgba(123,44,191,' + (0.35 + 0.3 * p) + ')';
    X.lineWidth = 1.5;
    var s = 14;
    X.beginPath();
    X.moveTo(tx - s, ty); X.lineTo(tx - 4, ty);
    X.moveTo(tx + 4, ty); X.lineTo(tx + s, ty);
    X.moveTo(tx, ty - s); X.lineTo(tx, ty - 4);
    X.moveTo(tx, ty + 4); X.lineTo(tx, ty + s);
    X.stroke();
    X.fillStyle = 'rgba(123,44,191,' + (0.7 + 0.2 * p) + ')';
    X.beginPath(); X.arc(tx, ty, 2.5, 0, Math.PI * 2); X.fill();
  }

  function drawTrails() {
    for (var i = 0; i < agents.length; i++) {
      var a = agents[i], t = a.trail, len = t.length;
      if (len < 2) continue;
      X.strokeStyle = rgba(a.c, 0.1);
      X.lineWidth = 1;
      X.beginPath(); X.moveTo(t[0].x, t[0].y);
      for (var j = 1; j < len; j++) X.lineTo(t[j].x, t[j].y);
      X.stroke();
      var rs = Math.max(0, len - 80);
      X.strokeStyle = rgba(a.c, 0.45);
      X.lineWidth = 2;
      X.beginPath(); X.moveTo(t[rs].x, t[rs].y);
      for (var j = rs + 1; j < len; j++) X.lineTo(t[j].x, t[j].y);
      X.stroke();
    }
  }

  function drawAgents() {
    for (var i = 0; i < agents.length; i++) {
      var a = agents[i];
      var g = X.createRadialGradient(a.x, a.y, 0, a.x, a.y, 20);
      g.addColorStop(0, rgba(a.c, 0.3));
      g.addColorStop(1, rgba(a.c, 0));
      X.fillStyle = g;
      X.beginPath(); X.arc(a.x, a.y, 20, 0, Math.PI * 2); X.fill();
      X.fillStyle = 'rgb(' + a.c[0] + ',' + a.c[1] + ',' + a.c[2] + ')';
      X.beginPath(); X.arc(a.x, a.y, 3.5, 0, Math.PI * 2); X.fill();
      if (agents.length > 1) {
        X.font = '9px Space Grotesk,monospace';
        X.fillStyle = rgba(a.c, 0.65);
        X.textAlign = 'left';
        X.fillText(a.name, a.x + 10, a.y - 6);
      }
    }
  }

  function drawGraph() {
    var pad = 16;
    var gw = Math.min(300, W * 0.38);
    var gh = Math.min(120, H * 0.14);
    if (gh < 50 || gw < 120) return;
    var gx = pad, gy = H - gh - 65;
    var maxT = 5, maxV = 1.5;
    var wStart = Math.max(0, graphT - maxT);

    X.fillStyle = 'rgba(10,10,15,0.82)';
    X.fillRect(gx, gy, gw, gh);
    X.strokeStyle = 'rgba(255,255,255,0.06)';
    X.lineWidth = 1;
    X.strokeRect(gx, gy, gw, gh);

    var rY = gy + gh - (1 / maxV) * gh;
    X.strokeStyle = 'rgba(123,44,191,0.3)';
    X.setLineDash([3, 3]);
    X.beginPath(); X.moveTo(gx, rY); X.lineTo(gx + gw, rY); X.stroke();
    X.setLineDash([]);

    X.font = '8px Space Grotesk,monospace';
    X.fillStyle = 'rgba(255,255,255,0.22)';
    X.textAlign = 'left';
    X.fillText('STEP RESPONSE', gx + 5, gy + 11);

    X.save();
    X.beginPath(); X.rect(gx, gy, gw, gh); X.clip();
    for (var i = 0; i < agents.length; i++) {
      var a = agents[i], hist = a.hist;
      if (hist.length < 2) continue;
      X.strokeStyle = rgba(a.c, 0.7);
      X.lineWidth = 1.2;
      X.beginPath();
      var started = false;
      for (var j = 0; j < hist.length; j++) {
        var hp = hist[j];
        if (hp.t < wStart) continue;
        var px = gx + ((hp.t - wStart) / maxT) * gw;
        var py = gy + gh - (hp.v / maxV) * gh;
        if (!started) { X.moveTo(px, py); started = true; }
        else X.lineTo(px, py);
      }
      X.stroke();
    }
    X.restore();

    X.font = '7px monospace';
    X.fillStyle = 'rgba(255,255,255,0.18)';
    X.textAlign = 'left';
    X.fillText(wStart.toFixed(1) + 's', gx + 2, gy + gh + 10);
    X.textAlign = 'right';
    X.fillText((wStart + maxT).toFixed(1) + 's', gx + gw - 2, gy + gh + 10);
    X.fillText('1.0', gx + gw + 3, rY + 3);
    X.textAlign = 'left';

    if (agents.length > 1) {
      var lx = gx + 5, ly = gy + gh + 22;
      X.font = '8px Space Grotesk,monospace';
      for (var i = 0; i < agents.length; i++) {
        var a = agents[i];
        X.fillStyle = 'rgb(' + a.c[0] + ',' + a.c[1] + ',' + a.c[2] + ')';
        X.beginPath(); X.arc(lx, ly - 3, 3, 0, Math.PI * 2); X.fill();
        X.fillStyle = rgba(a.c, 0.55);
        X.textAlign = 'left';
        X.fillText(a.name, lx + 7, ly);
        lx += X.measureText(a.name).width + 20;
      }
    }
  }

  function updateHUD() {
    document.getElementById('hud-mode').textContent = mode.toUpperCase();
    document.getElementById('hud-n').textContent = agents.length;
  }

  function setTarget(x, y) {
    target.x = x; target.y = y;
    for (var i = 0; i < agents.length; i++) {
      agents[i].ix = 0; agents[i].iy = 0;
    }
    recordStep();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function disturb() {
    var impulse = 400;
    for (var i = 0; i < agents.length; i++) {
      var ang = Math.random() * Math.PI * 2;
      agents[i].vx += Math.cos(ang) * impulse;
      agents[i].vy += Math.sin(ang) * impulse;
    }
    if (typeof SFX !== 'undefined') SFX.play('collide');
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var r = C.getBoundingClientRect();
    setTarget(e.clientX - r.left, e.clientY - r.top);
    if (mode === 'track') tracking = true;
  });
  C.addEventListener('mousemove', function(e) {
    if (!tracking) return;
    var r = C.getBoundingClientRect();
    target.x = e.clientX - r.left; target.y = e.clientY - r.top;
  });
  C.addEventListener('mouseup', function() { tracking = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); disturb(); });

  var longPress = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0], r = C.getBoundingClientRect();
    setTarget(t.clientX - r.left, t.clientY - r.top);
    if (mode === 'track') tracking = true;
    longPress = setTimeout(function() { disturb(); longPress = null; }, 500);
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (longPress) { clearTimeout(longPress); longPress = null; }
    if (!tracking) return;
    var t = e.touches[0], r = C.getBoundingClientRect();
    target.x = t.clientX - r.left; target.y = t.clientY - r.top;
  }, {passive: false});
  C.addEventListener('touchend', function() {
    tracking = false;
    if (longPress) { clearTimeout(longPress); longPress = null; }
  });

  document.querySelectorAll('#mode-btns button').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#mode-btns button').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      mode = this.dataset.val;
      document.getElementById('tune-controls').style.display = mode === 'tune' ? 'block' : 'none';
      initAgents();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  var slP = document.getElementById('sl-p'), svP = document.getElementById('sv-p');
  var slI = document.getElementById('sl-i'), svI = document.getElementById('sv-i');
  var slD = document.getElementById('sl-d'), svD = document.getElementById('sv-d');
  function syncSliders() {
    Kp = +slP.value; Ki = +slI.value; Kd = +slD.value;
    svP.textContent = Kp; svI.textContent = Ki; svD.textContent = Kd;
    if (mode === 'tune' && agents.length) {
      agents[0].kp = Kp; agents[0].ki = Ki; agents[0].kd = Kd;
    }
  }
  slP.addEventListener('input', syncSliders);
  slI.addEventListener('input', syncSliders);
  slD.addEventListener('input', syncSliders);

  document.getElementById('btn-wind').addEventListener('click', function() {
    windOn = !windOn;
    this.classList.toggle('active', windOn);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    initAgents();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);

  resize();
  initAgents();
  (function loop() {
    update();
    draw();
    requestAnimationFrame(loop);
  })();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
