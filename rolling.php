<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rolling');

$pageName = 'rolling';
$pageTitle = 'ROLLING - PULSE';
$pageDesc = '同じ坂でも球・円盤・球殻・リングで着く順が違う。回転に取られるエネルギーの配分が速さを決めている。勝者を当ててみる。';
$pageCanonical = 'https://pulse.subara3.com/rolling.php';
$extraStyle = '
#canvas{position:fixed;top:0;left:0;touch-action:none}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px);pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}}
.mode-bar{position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:20;display:flex;gap:2px;background:rgba(10,10,15,.55);border:1px solid rgba(255,255,255,.12);border-radius:6px;padding:2px;backdrop-filter:blur(12px)}
.mode-btn{background:transparent;border:none;color:rgba(255,255,255,.45);font:11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.1em;padding:5px 10px;cursor:pointer;border-radius:4px;transition:color .2s,background .2s}
.mode-btn.active{color:#00f5d4;background:rgba(0,245,212,.12)}
.mode-btn:hover{color:rgba(255,255,255,.8)}
@media(max-width:760px){.mode-bar{top:.75rem}.mode-btn{font-size:10px;padding:4px 7px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  MODE <b id="h-mode">RACE</b><br>
  ANGLE <b id="h-angle">25°</b><br>
  <span class="sub" id="h-score"></span>
</div>
<div class="mode-bar">
  <button class="mode-btn active" data-m="race">RACE</button>
  <button class="mode-btn" data-m="predict">PREDICT</button>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'predict / start'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">ANGLE</span>
    <input type="range" id="sl-angle" min="10" max="55" value="25">
    <span class="slider-value" id="v-angle">25°</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H;

var SHAPES = [
  {name:'SPHERE', k:0.4, color:[0,245,212], drawFn:'sphere'},
  {name:'DISK',   k:0.5, color:[0,190,200], drawFn:'disk'},
  {name:'SHELL',  k:2/3, color:[150,80,230], drawFn:'shell'},
  {name:'RING',   k:1.0, color:[181,140,255], drawFn:'ring'}
];

var mode = 'race';
var angleDeg = 25;
var G_PX = 1800;
var FRICTION = 80;
var OBJ_R = 16;

var lanes = [];
var raceState = 'rolling';
var raceTimer = 0;
var pauseTimer = 0;
var finishOrder = [];
var prediction = -1;
var streak = 0;
var showResult = 0;
var resultMsg = '';

var slAngle = document.getElementById('sl-angle');
var elMode = document.getElementById('h-mode');
var elAngle = document.getElementById('h-angle');
var elScore = document.getElementById('h-score');
var elAngleV = document.getElementById('v-angle');

function resize() {
  W = window.innerWidth; H = window.innerHeight;
  C.width = W; C.height = H;
  resetRace();
}

function laneGeometry() {
  var n = SHAPES.length;
  var pad = Math.min(60, H * 0.08);
  var laneH = (H - pad * 2) / n;
  var rampFrac = 0.45;
  var margin = Math.min(40, W * 0.04);
  var trackW = W - margin * 2;
  var rampW = trackW * rampFrac;
  var flatW = trackW - rampW;
  var angleRad = angleDeg * Math.PI / 180;
  var rampDy = rampW * Math.tan(angleRad);
  if (rampDy > laneH * 0.75) {
    rampDy = laneH * 0.75;
    rampW = rampDy / Math.tan(angleRad);
    flatW = trackW - rampW;
  }
  return {n:n, pad:pad, laneH:laneH, margin:margin, trackW:trackW,
          rampW:rampW, flatW:flatW, rampDy:rampDy, angleRad:angleRad};
}

function resetRace() {
  var g = laneGeometry();
  lanes = [];
  finishOrder = [];
  raceTimer = 0;
  showResult = 0;

  for (var i = 0; i < g.n; i++) {
    var baseY = g.pad + g.laneH * i + g.laneH * 0.5;
    var startX = g.margin;
    var startY = baseY - g.rampDy * 0.5;
    var bottomX = g.margin + g.rampW;
    var bottomY = baseY + g.rampDy * 0.5;
    var endX = g.margin + g.rampW + g.flatW;
    lanes.push({
      shape: SHAPES[i], x: startX + OBJ_R, y: startY,
      vx: 0, vy: 0, dist: 0,
      startX: startX, startY: startY,
      bottomX: bottomX, bottomY: bottomY,
      endX: endX, endY: bottomY,
      rampLen: Math.sqrt(g.rampW * g.rampW + g.rampDy * g.rampDy),
      finished: false, finishTime: 0,
      trail: []
    });
  }

  if (mode === 'race') raceState = 'rolling';
  else { raceState = 'waiting'; prediction = -1; }
  updateHUD();
}

function updateHUD() {
  elMode.textContent = mode === 'race' ? 'RACE' : 'PREDICT';
  elAngle.textContent = angleDeg + '°';
  if (mode === 'predict' && streak > 0) {
    elScore.textContent = streak + '/3 CORRECT';
  } else {
    elScore.textContent = '';
  }
}

function stepPhysics(dt) {
  var g = laneGeometry();
  var sinA = Math.sin(g.angleRad);
  var cosA = Math.cos(g.angleRad);
  var dirX = g.rampW / Math.sqrt(g.rampW * g.rampW + g.rampDy * g.rampDy);
  var dirY = g.rampDy / Math.sqrt(g.rampW * g.rampW + g.rampDy * g.rampDy);

  for (var i = 0; i < lanes.length; i++) {
    var L = lanes[i];
    if (L.finished) continue;

    var onRamp = L.x < L.bottomX;
    var accel, fricDecel;

    if (onRamp) {
      accel = G_PX * sinA / (1 + L.shape.k);
      L.vx += accel * dirX * dt;
      L.vy += accel * dirY * dt;
    } else {
      var speed = Math.sqrt(L.vx * L.vx + L.vy * L.vy);
      if (speed > 0.5) {
        fricDecel = FRICTION * dt;
        var ratio = Math.max(0, 1 - fricDecel / speed);
        L.vx *= ratio;
        L.vy *= ratio;
      }
      L.vy *= 0.9;
    }

    L.x += L.vx * dt;
    L.y += L.vy * dt;

    if (!onRamp && L.y !== L.bottomY) {
      L.y += (L.bottomY - L.y) * 0.15;
    }

    if (L.x < L.startX + OBJ_R) { L.x = L.startX + OBJ_R; L.vx = 0; L.vy = 0; }

    L.trail.push({x: L.x, y: L.y});
    if (L.trail.length > 300) L.trail.shift();

    if (L.x >= L.endX - OBJ_R && !L.finished) {
      L.finished = true;
      L.finishTime = raceTimer;
      L.x = L.endX - OBJ_R;
      L.vx = 0; L.vy = 0;
      finishOrder.push(i);
      if (finishOrder.length === 1) SFX.play('success');
      else SFX.play('tick');
    }
  }
}

function drawTrack(L, g, idx) {
  var c = L.shape.color;
  ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.12)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(L.startX, L.startY);
  ctx.lineTo(L.bottomX, L.bottomY);
  ctx.lineTo(L.endX, L.endY);
  ctx.stroke();

  ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.06)';
  ctx.setLineDash([4,4]);
  ctx.beginPath();
  ctx.moveTo(L.endX, L.bottomY - g.laneH * 0.35);
  ctx.lineTo(L.endX, L.bottomY + g.laneH * 0.35);
  ctx.stroke();
  ctx.setLineDash([]);
}

function drawTrail(L) {
  var c = L.shape.color;
  var n = L.trail.length;
  if (n < 2) return;
  for (var j = 1; j < n; j++) {
    var a = j / n * 0.4;
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(L.trail[j-1].x, L.trail[j-1].y);
    ctx.lineTo(L.trail[j].x, L.trail[j].y);
    ctx.stroke();
  }
}

function drawShape(L, time) {
  var c = L.shape.color;
  var x = L.x, y = L.y;
  var rot = L.dist || (L.trail.length > 1 ?
    (function(){ var d = 0; for (var j = 1; j < L.trail.length; j++) { var dx = L.trail[j].x - L.trail[j-1].x; d += Math.abs(dx); } return d / OBJ_R; })() : 0);

  ctx.save();
  ctx.translate(x, y);
  ctx.rotate(rot);

  var fn = L.shape.drawFn;
  if (fn === 'sphere') {
    ctx.beginPath();
    ctx.arc(0, 0, OBJ_R, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.3)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.8)';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(-OBJ_R, 0); ctx.lineTo(OBJ_R, 0);
    ctx.moveTo(0, -OBJ_R); ctx.lineTo(0, OBJ_R);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.4)';
    ctx.lineWidth = 1;
    ctx.stroke();
  } else if (fn === 'disk') {
    ctx.beginPath();
    ctx.arc(0, 0, OBJ_R, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.35)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.8)';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(0, 0, OBJ_R * 0.4, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.15)';
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(-OBJ_R, 0); ctx.lineTo(OBJ_R, 0);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.4)';
    ctx.lineWidth = 1;
    ctx.stroke();
  } else if (fn === 'shell') {
    ctx.beginPath();
    ctx.arc(0, 0, OBJ_R, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.8)';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(0, 0, OBJ_R * 0.75, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.3)';
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(-OBJ_R, 0); ctx.lineTo(OBJ_R, 0);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.3)';
    ctx.stroke();
  } else {
    ctx.beginPath();
    ctx.arc(0, 0, OBJ_R, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.8)';
    ctx.lineWidth = 3;
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(-OBJ_R, 0); ctx.lineTo(OBJ_R, 0);
    ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.4)';
    ctx.lineWidth = 1;
    ctx.stroke();
  }

  ctx.restore();
}

function drawLabel(L, g, idx) {
  var c = L.shape.color;
  var baseY = g.pad + g.laneH * idx;
  ctx.font = '11px ui-monospace,SFMono-Regular,Menlo,monospace';
  ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.6)';
  ctx.textAlign = 'left';
  ctx.fillText(L.shape.name + ' (k=' + L.shape.k.toFixed(2) + ')', L.startX + 2, baseY + 14);

  if (L.finished) {
    var rank = finishOrder.indexOf(idx) + 1;
    ctx.font = 'bold 14px ui-monospace,SFMono-Regular,Menlo,monospace';
    ctx.fillStyle = rank === 1 ? '#00f5d4' : 'rgba(255,255,255,0.5)';
    ctx.textAlign = 'right';
    ctx.fillText('#' + rank, L.endX - 4, baseY + 14);
  }
}

function drawEnergyBar(L, g, idx) {
  var speed2 = L.vx * L.vx + L.vy * L.vy;
  var maxSpeed2 = 2 * G_PX * g.rampDy / (1 + L.shape.k);
  if (maxSpeed2 < 1) maxSpeed2 = 1;
  var transRatio = 1 / (1 + L.shape.k);
  var rotRatio = L.shape.k / (1 + L.shape.k);
  var fraction = speed2 / maxSpeed2;
  if (fraction > 1) fraction = 1;

  var barW = Math.min(100, g.trackW * 0.1);
  var barH = 4;
  var bx = L.endX - barW - 4;
  var by = g.pad + g.laneH * idx + g.laneH - 20;

  ctx.fillStyle = 'rgba(255,255,255,0.06)';
  ctx.fillRect(bx, by, barW, barH);

  var c = L.shape.color;
  var tw = barW * fraction * transRatio;
  var rw = barW * fraction * rotRatio;
  ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.6)';
  ctx.fillRect(bx, by, tw, barH);
  ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.3)';
  ctx.fillRect(bx + tw, by, rw, barH);

  ctx.font = '9px ui-monospace,SFMono-Regular,Menlo,monospace';
  ctx.fillStyle = 'rgba(255,255,255,0.3)';
  ctx.textAlign = 'right';
  ctx.fillText('TRANS ' + Math.round(transRatio * 100) + '%', bx - 4, by + 4);
}

function drawPredictOverlay() {
  if (mode !== 'predict' || raceState !== 'waiting') return;
  var g = laneGeometry();
  ctx.font = '14px ui-monospace,SFMono-Regular,Menlo,monospace';
  ctx.textAlign = 'center';
  ctx.fillStyle = 'rgba(0,245,212,0.6)';
  ctx.fillText('CLICK THE SHAPE THAT WILL WIN', W * 0.5, H - 30);

  for (var i = 0; i < lanes.length; i++) {
    if (prediction === i) {
      var c = lanes[i].shape.color;
      ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.5)';
      ctx.lineWidth = 2;
      ctx.strokeRect(lanes[i].x - OBJ_R - 6, lanes[i].y - OBJ_R - 6, OBJ_R * 2 + 12, OBJ_R * 2 + 12);
    }
  }
}

function drawResultOverlay() {
  if (showResult <= 0) return;
  var alpha = Math.min(1, showResult);
  ctx.font = 'bold 24px ui-monospace,SFMono-Regular,Menlo,monospace';
  ctx.textAlign = 'center';
  ctx.fillStyle = resultMsg === 'CORRECT!' ? 'rgba(0,245,212,' + alpha + ')' : 'rgba(181,140,255,' + alpha + ')';
  ctx.fillText(resultMsg, W * 0.5, H * 0.5);
}

function allFinished() {
  for (var i = 0; i < lanes.length; i++) if (!lanes[i].finished) return false;
  return true;
}

function tick() {
  var dt = 1 / 60;
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  var g = laneGeometry();

  if (raceState === 'rolling') {
    raceTimer += dt;
    stepPhysics(dt);
    if (allFinished()) {
      raceState = 'done';
      pauseTimer = 0;
      if (mode === 'predict' && prediction >= 0) {
        if (finishOrder[0] === prediction) {
          streak++;
          resultMsg = 'CORRECT!';
          SFX.play('correct');
          if (streak >= 3) {
            if (window.PULSE) PULSE.clear();
            resultMsg = 'COMPLETE!';
            SFX.play('complete');
          }
        } else {
          streak = 0;
          resultMsg = 'WRONG — ' + lanes[finishOrder[0]].shape.name + ' WINS';
          SFX.play('fail');
        }
        showResult = 3;
        updateHUD();
      }
    }
  } else if (raceState === 'done') {
    pauseTimer += dt;
    if (showResult > 0) showResult -= dt;
    if (mode === 'race' && pauseTimer > 2.5) {
      angleDeg = 10 + Math.floor(Math.random() * 46);
      slAngle.value = angleDeg;
      elAngleV.textContent = angleDeg + '°';
      resetRace();
      raceState = 'rolling';
      updateHUD();
    }
  }

  for (var i = 0; i < lanes.length; i++) {
    drawTrack(lanes[i], g, i);
    drawTrail(lanes[i]);
  }
  for (var i = 0; i < lanes.length; i++) {
    drawShape(lanes[i], raceTimer);
    drawLabel(lanes[i], g, i);
    drawEnergyBar(lanes[i], g, i);
  }

  drawPredictOverlay();
  drawResultOverlay();

  requestAnimationFrame(tick);
}

C.addEventListener('pointerdown', function(e) {
  e.preventDefault();
  if (mode === 'predict') {
    if (raceState === 'waiting') {
      var g = laneGeometry();
      var x = e.clientX, y = e.clientY;
      for (var i = 0; i < lanes.length; i++) {
        var ly = g.pad + g.laneH * i;
        if (y >= ly && y < ly + g.laneH) {
          prediction = i;
          SFX.play('spawn');
          raceState = 'rolling';
          break;
        }
      }
    } else if (raceState === 'done') {
      angleDeg = 10 + Math.floor(Math.random() * 46);
      slAngle.value = angleDeg;
      elAngleV.textContent = angleDeg + '°';
      resetRace();
      updateHUD();
    }
  } else {
    if (raceState === 'done') {
      resetRace();
    }
  }
});

var modeBtns = document.querySelectorAll('.mode-btn');
modeBtns.forEach(function(btn) {
  btn.addEventListener('click', function() {
    modeBtns.forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    mode = btn.getAttribute('data-m');
    streak = 0;
    resetRace();
    updateHUD();
    SFX.play('toggle');
  });
});

slAngle.addEventListener('input', function() {
  angleDeg = parseInt(slAngle.value);
  elAngleV.textContent = angleDeg + '°';
  resetRace();
  updateHUD();
});

document.getElementById('btn-reset').addEventListener('click', function() {
  streak = 0;
  angleDeg = 25;
  slAngle.value = 25;
  elAngleV.textContent = '25°';
  resetRace();
  updateHUD();
  SFX.play('click');
});

window.addEventListener('resize', resize);
resize();
tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
