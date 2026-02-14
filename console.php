<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('console');

$pageName = 'console';
$pageTitle = 'CONSOLE - PULSE';
$pageDesc = '映画みたいなSFコンソール。眺めてるだけでハッカー気分。';
$pageCanonical = 'https://pulse.subara3.com/console.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; font-family: "Share Tech Mono", monospace; }
.ui { position: fixed; z-index: 1000; font-family: "Orbitron", sans-serif; }
.back-link { top: 1rem; left: 1rem; color: rgba(0, 245, 212, 0.5); text-decoration: none; font-size: 0.7rem; letter-spacing: 0.1em; }
.back-link:hover { color: #00f5d4; }

.console-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: 10px;
  padding: 40px 10px 10px;
  height: 100vh;
  box-sizing: border-box;
}

.panel {
  background: rgba(0, 20, 30, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 4px;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}

.panel-title {
  position: absolute;
  top: 8px;
  left: 10px;
  font-family: "Orbitron", sans-serif;
  font-size: 10px;
  color: rgba(0, 245, 212, 0.7);
  letter-spacing: 2px;
}

#terminal {
  position: absolute;
  top: 30px;
  left: 10px;
  right: 10px;
  bottom: 10px;
  font-size: 11px;
  color: #00f5d4;
  line-height: 1.5;
  overflow: hidden;
}

@media (max-width: 900px) {
  .console-grid { grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); }
}
@media (max-width: 600px) {
  .console-grid { grid-template-columns: 1fr; grid-template-rows: repeat(6, 1fr); }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>


<div class="console-grid">
  <div class="panel">
    <div class="panel-title">RADAR SCAN</div>
    <canvas id="radar"></canvas>
  </div>
  <div class="panel">
    <div class="panel-title">SYSTEM STATUS</div>
    <canvas id="gauge"></canvas>
  </div>
  <div class="panel">
    <div class="panel-title">DATA STREAM</div>
    <canvas id="matrix"></canvas>
  </div>
  <div class="panel">
    <div class="panel-title">SIGNAL MONITOR</div>
    <canvas id="wave"></canvas>
  </div>
  <div class="panel">
    <div class="panel-title">TERMINAL</div>
    <div id="terminal"></div>
  </div>
  <div class="panel">
    <div class="panel-title">CORE METRICS</div>
    <canvas id="bars"></canvas>
  </div>
</div>

<script>
(function() {
  // キャンバス取得
  var radar = document.getElementById('radar');
  var gauge = document.getElementById('gauge');
  var matrix = document.getElementById('matrix');
  var wave = document.getElementById('wave');
  var bars = document.getElementById('bars');
  var terminal = document.getElementById('terminal');
  
  var radarCtx = radar.getContext('2d');
  var gaugeCtx = gauge.getContext('2d');
  var matrixCtx = matrix.getContext('2d');
  var waveCtx = wave.getContext('2d');
  var barsCtx = bars.getContext('2d');
  
  // リサイズ
  function resize() {
    var canvases = [radar, gauge, matrix, wave, bars];
    canvases.forEach(function(c) {
      var rect = c.parentElement.getBoundingClientRect();
      c.width = rect.width - 20;
      c.height = rect.height - 40;
    });
  }
  
  // レーダー
  var radarAngle = 0;
  var blips = [];
  for (var i = 0; i < 6; i++) {
    blips.push({ a: Math.random() * Math.PI * 2, d: 0.3 + Math.random() * 0.5, l: Math.random() });
  }
  
  function drawRadar() {
    var w = radar.width, h = radar.height;
    var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 10;
    
    radarCtx.fillStyle = 'rgba(0,10,15,0.1)';
    radarCtx.fillRect(0, 0, w, h);
    
    radarCtx.strokeStyle = 'rgba(0,245,212,0.2)';
    radarCtx.lineWidth = 1;
    for (var i = 1; i <= 3; i++) {
      radarCtx.beginPath();
      radarCtx.arc(cx, cy, r * i / 3, 0, Math.PI * 2);
      radarCtx.stroke();
    }
    radarCtx.beginPath();
    radarCtx.moveTo(cx - r, cy); radarCtx.lineTo(cx + r, cy);
    radarCtx.moveTo(cx, cy - r); radarCtx.lineTo(cx, cy + r);
    radarCtx.stroke();
    
    radarCtx.fillStyle = 'rgba(0,245,212,0.4)';
    radarCtx.beginPath();
    radarCtx.moveTo(cx, cy);
    radarCtx.arc(cx, cy, r, radarAngle - 0.5, radarAngle);
    radarCtx.fill();
    
    blips.forEach(function(b) {
      if (Math.abs(radarAngle % (Math.PI * 2) - b.a) < 0.5) b.l = 1;
      b.l *= 0.97;
      if (b.l > 0.1) {
        radarCtx.fillStyle = 'rgba(0,245,212,' + b.l + ')';
        radarCtx.beginPath();
        radarCtx.arc(cx + Math.cos(b.a) * r * b.d, cy + Math.sin(b.a) * r * b.d, 4, 0, Math.PI * 2);
        radarCtx.fill();
      }
    });
    
    radarCtx.strokeStyle = 'rgba(0,245,212,0.5)';
    radarCtx.lineWidth = 2;
    radarCtx.beginPath();
    radarCtx.arc(cx, cy, r, 0, Math.PI * 2);
    radarCtx.stroke();
    
    radarAngle += 0.04;
  }
  
  // ゲージ
  var gv = [0.7, 0.5, 0.9], gt = [0.7, 0.5, 0.9];
  function drawGauge() {
    var w = gauge.width, h = gauge.height;
    var cx = w / 2, cy = h / 2, br = Math.min(w, h) / 2 - 20;
    
    gaugeCtx.fillStyle = 'rgba(0,10,15,0.3)';
    gaugeCtx.fillRect(0, 0, w, h);
    
    var colors = ['#00f5d4', '#7b2cbf', '#ff6b6b'];
    gv.forEach(function(v, i) {
      gv[i] += (gt[i] - gv[i]) * 0.05;
      var rad = br * (0.5 + i * 0.18);
      
      gaugeCtx.strokeStyle = 'rgba(255,255,255,0.1)';
      gaugeCtx.lineWidth = 10;
      gaugeCtx.beginPath();
      gaugeCtx.arc(cx, cy, rad, 0, Math.PI * 2);
      gaugeCtx.stroke();
      
      gaugeCtx.strokeStyle = colors[i];
      gaugeCtx.lineWidth = 8;
      gaugeCtx.lineCap = 'round';
      gaugeCtx.beginPath();
      gaugeCtx.arc(cx, cy, rad, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * gv[i]);
      gaugeCtx.stroke();
    });
    
    var avg = Math.round((gv[0] + gv[1] + gv[2]) / 3 * 100);
    gaugeCtx.fillStyle = '#00f5d4';
    gaugeCtx.font = 'bold ' + (br * 0.35) + 'px Orbitron';
    gaugeCtx.textAlign = 'center';
    gaugeCtx.textBaseline = 'middle';
    gaugeCtx.fillText(avg + '%', cx, cy);
    
    if (Math.random() < 0.02) gt = [0.3 + Math.random() * 0.7, 0.3 + Math.random() * 0.7, 0.3 + Math.random() * 0.7];
  }
  
  // マトリックス
  var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  var drops = [];
  function initDrops() {
    drops = [];
    var cols = Math.floor(matrix.width / 12);
    for (var i = 0; i < cols; i++) drops.push(Math.random() * matrix.height);
  }
  
  function drawMatrix() {
    var w = matrix.width, h = matrix.height;
    if (drops.length === 0) initDrops();
    
    matrixCtx.fillStyle = 'rgba(0,10,15,0.05)';
    matrixCtx.fillRect(0, 0, w, h);
    
    matrixCtx.font = '12px Share Tech Mono';
    drops.forEach(function(y, i) {
      var c = chars[Math.floor(Math.random() * chars.length)];
      matrixCtx.fillStyle = '#fff';
      matrixCtx.fillText(c, i * 12, y);
      matrixCtx.fillStyle = '#00f5d4';
      matrixCtx.fillText(chars[Math.floor(Math.random() * chars.length)], i * 12, y - 12);
      drops[i] = y > h + 20 ? 0 : y + 12;
    });
  }
  
  // 波形
  var waveData = [];
  for (var i = 0; i < 80; i++) waveData.push(0);
  var wo = 0;
  
  function drawWave() {
    var w = wave.width, h = wave.height;
    
    waveCtx.fillStyle = 'rgba(0,10,15,0.3)';
    waveCtx.fillRect(0, 0, w, h);
    
    waveCtx.strokeStyle = 'rgba(0,245,212,0.1)';
    for (var i = 0; i <= 4; i++) {
      waveCtx.beginPath();
      waveCtx.moveTo(0, h / 4 * i);
      waveCtx.lineTo(w, h / 4 * i);
      waveCtx.stroke();
    }
    
    waveData.shift();
    waveData.push(Math.sin(wo * 0.08) * 0.3 + Math.sin(wo * 0.2) * 0.2 + (Math.random() - 0.5) * 0.1);
    
    waveCtx.strokeStyle = '#00f5d4';
    waveCtx.lineWidth = 2;
    waveCtx.beginPath();
    waveData.forEach(function(v, i) {
      var x = i / waveData.length * w;
      var y = h / 2 + v * h * 0.4;
      if (i === 0) waveCtx.moveTo(x, y);
      else waveCtx.lineTo(x, y);
    });
    waveCtx.stroke();
    wo++;
  }
  
  // バー
  var bv = [0.6, 0.8, 0.4, 0.9, 0.5, 0.7];
  var bt = bv.slice();
  
  function drawBars() {
    var w = bars.width, h = bars.height;
    
    barsCtx.fillStyle = 'rgba(0,10,15,0.3)';
    barsCtx.fillRect(0, 0, w, h);
    
    var bw = 25, gap = (w - bw * 6) / 7;
    bv.forEach(function(v, i) {
      bv[i] += (bt[i] - bv[i]) * 0.1;
      var x = gap + i * (bw + gap);
      var bh = bv[i] * (h - 40);
      var y = h - 20 - bh;
      
      var grad = barsCtx.createLinearGradient(0, y, 0, h - 20);
      grad.addColorStop(0, '#00f5d4');
      grad.addColorStop(1, '#7b2cbf');
      barsCtx.fillStyle = grad;
      barsCtx.fillRect(x, y, bw, bh);
      
      barsCtx.fillStyle = '#00f5d4';
      barsCtx.font = '9px Orbitron';
      barsCtx.textAlign = 'center';
      barsCtx.fillText(Math.round(bv[i] * 100), x + bw / 2, y - 5);
    });
    
    if (Math.random() < 0.05) bt[Math.floor(Math.random() * 6)] = 0.2 + Math.random() * 0.8;
  }
  
  // ターミナル
  var lines = [];
  var msgs = [
    '> PULSE CORE v2.0.26 ONLINE',
    '> NEURAL NET: ACTIVE',
    '> ECHO MODULE: RUNNING',
    '> NULL MODULE: STANDBY',
    '> SCANNING... OK',
    '> MEMORY: 87.3%',
    '> CPU: 16/16 THREADS',
    '> ENCRYPTED STREAM',
    '> FIREWALL: ON',
    '> SECURE CONNECTION',
    '> HEARTBEAT OK',
    '> LATENCY: 12ms',
    '> WARNING: ANOMALY',
  ];
  
  function updateTerminal() {
    if (lines.length > 10) lines.shift();
    var t = new Date().toLocaleTimeString('en-US', { hour12: false });
    var m = msgs[Math.floor(Math.random() * msgs.length)];
    lines.push('[' + t + '] ' + m);
    terminal.innerHTML = lines.map(function(l) {
      var color = l.includes('WARNING') ? '#ff6b6b' : l.includes('OK') || l.includes('ACTIVE') ? '#00f5d4' : 'rgba(0,245,212,0.7)';
      return '<div style="color:' + color + '">' + l + '</div>';
    }).join('');
  }
  
  // ループ
  function loop() {
    drawRadar();
    drawGauge();
    drawMatrix();
    drawWave();
    drawBars();
    requestAnimationFrame(loop);
  }
  
  // 開始
  resize();
  initDrops();
  loop();
  updateTerminal();
  setInterval(updateTerminal, 700);
  window.addEventListener('resize', function() { resize(); initDrops(); });
})();
</script>

</body>
</html>
