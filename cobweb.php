<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cobweb');

$pageName = 'cobweb';
$pageTitle = 'COBWEB - PULSE';
$pageDesc = '関数を描いて初期値を決め、蜘蛛の巣図を走らせる。折れ線が固定点に巻きつくか、乱れるかが見える。';
$pageCanonical = 'https://pulse.subara3.com/cobweb.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MAP <span id="hud-mode">LOGISTIC</span></div>
  <div id="hud-prow"><span id="hud-plbl">r</span> = <span id="hud-pval">3.20</span></div>
  <div>ITER <span id="hud-iter">0</span></div>
  <div>STATE <span id="hud-state">&mdash;</span></div>
</div>
<div id="info-label"><span>COBWEB</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'start point'],
  ['type' => 'drag',  'label' => 'draw (DRAW)'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MAP</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" data-map="LOGISTIC">LOGISTIC</button>
    <button class="ctrl-btn" data-map="TENT">TENT</button>
    <button class="ctrl-btn" data-map="SINE">SINE</button>
    <button class="ctrl-btn" data-map="DRAW">DRAW</button>
  </div>
  <div class="slider-row" id="row-param">
    <label class="slider-label" id="lbl-param">r</label>
    <input type="range" id="sl-param" class="ctrl-slider" min="0" max="1000" value="675">
    <span class="slider-val" id="val-param">3.20</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" class="ctrl-slider" min="1" max="30" value="8">
    <span class="slider-val" id="val-speed">8</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">STEPS</label>
    <input type="range" id="sl-steps" class="ctrl-slider" min="10" max="300" value="100">
    <span class="slider-val" id="val-steps">100</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-auto">AUTO</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;
  var pL, pT, pW, pH;

  function resize(){
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    var mT = 90, mB = 150, mL = 70, mR = 50;
    if (W < 600) { mL = 45; mR = 25; mT = 70; mB = 120; }
    var avW = W - mL - mR, avH = H - mT - mB;
    var s = Math.min(avW, avH);
    if (s < 150) s = 150;
    pW = pH = s;
    pL = mL + (avW - s) / 2;
    pT = mT + (avH - s) / 2;
  }

  function toSX(x){ return pL + x * pW; }
  function toSY(y){ return pT + pH - y * pH; }
  function fromSX(sx){ return (sx - pL) / pW; }
  function fromSY(sy){ return (pT + pH - sy) / pH; }

  var mapMode = 'LOGISTIC';
  var param = 3.2;
  var startX = 0.2;
  var maxSteps = 100;
  var animSpeed = 8;
  var autoMode = false;
  var pts = [];
  var animProg = 0;
  var customY = null;
  var isDrawing = false;
  var lastDX = -1, lastDY = -1;

  var pDef = {
    LOGISTIC: { min: 0.5, max: 4.0, val: 3.2, name: 'r' },
    TENT:     { min: 0.5, max: 2.0, val: 1.6, name: 's' },
    SINE:     { min: 0.3, max: 1.0, val: 0.85, name: 'a' },
    DRAW:     { min: 0, max: 1, val: 0, name: '—' }
  };

  function fMap(x){
    switch(mapMode){
      case 'LOGISTIC': return param * x * (1 - x);
      case 'TENT': return param * (0.5 - Math.abs(x - 0.5));
      case 'SINE': return param * Math.sin(Math.PI * x);
      case 'DRAW': return fCustom(x);
    }
    return x;
  }

  function fCustom(x){
    if(!customY) return 2.8 * x * (1 - x);
    var n = customY.length - 1;
    var idx = x * n;
    var lo = Math.floor(idx), hi = lo + 1;
    if(lo < 0) lo = 0;
    if(hi > n) hi = n;
    var t = idx - lo;
    return customY[lo] * (1 - t) + customY[hi] * t;
  }

  function brandHue(t){
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function genCobweb(){
    pts = [];
    var x = Math.max(0.001, Math.min(0.999, startX));
    pts.push([x, 0]);
    for(var i = 0; i < maxSteps; i++){
      var y = fMap(x);
      if(!isFinite(y) || y < -2 || y > 3) break;
      pts.push([x, y]);
      pts.push([y, y]);
      x = y;
      if(x < -2 || x > 3) break;
    }
    animProg = 0;
  }

  function detectState(){
    if(pts.length < 20) return '—';
    var tail = [];
    var start = Math.max(0, pts.length - 40);
    for(var i = start; i < pts.length; i += 2){
      tail.push(pts[i][0]);
    }
    if(tail.length < 4) return '—';
    var last = tail.length;
    var allSame = true;
    for(var i = 1; i < last; i++){
      if(Math.abs(tail[i] - tail[0]) > 0.003){ allSame = false; break; }
    }
    if(allSame) return 'FIXED';
    var p2 = true;
    for(var i = 2; i < last; i++){
      if(Math.abs(tail[i] - tail[i-2]) > 0.008){ p2 = false; break; }
    }
    if(p2) return 'PERIOD-2';
    if(last >= 8){
      var p4 = true;
      for(var i = 4; i < last; i++){
        if(Math.abs(tail[i] - tail[i-4]) > 0.015){ p4 = false; break; }
      }
      if(p4) return 'PERIOD-4';
    }
    if(last >= 6){
      var p3 = true;
      for(var i = 3; i < last; i++){
        if(Math.abs(tail[i] - tail[i-3]) > 0.01){ p3 = false; break; }
      }
      if(p3) return 'PERIOD-3';
    }
    return 'CHAOS';
  }

  /* ---- draw ---- */
  function draw(){
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    drawGrid();
    drawDiagonal();
    drawFunc();
    drawFixedPts();
    drawWeb();
    drawStart();
    hudUpdate();
  }

  function drawGrid(){
    ctx.strokeStyle = 'rgba(255,255,255,0.05)';
    ctx.lineWidth = 1;
    for(var i = 1; i < 10; i++){
      var v = i / 10;
      ctx.beginPath(); ctx.moveTo(toSX(v), toSY(0)); ctx.lineTo(toSX(v), toSY(1)); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(toSX(0), toSY(v)); ctx.lineTo(toSX(1), toSY(v)); ctx.stroke();
    }
    ctx.strokeStyle = 'rgba(255,255,255,0.18)';
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(toSX(0), toSY(0)); ctx.lineTo(toSX(1), toSY(0)); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(toSX(0), toSY(0)); ctx.lineTo(toSX(0), toSY(1)); ctx.stroke();
    ctx.fillStyle = 'rgba(255,255,255,0.22)';
    ctx.font = '10px "Space Grotesk",sans-serif';
    ctx.textAlign = 'center';
    for(var i = 0; i <= 10; i += 2){
      ctx.fillText((i/10).toFixed(1), toSX(i/10), toSY(0) + 15);
    }
    ctx.textAlign = 'right';
    for(var i = 2; i <= 10; i += 2){
      ctx.fillText((i/10).toFixed(1), toSX(0) - 7, toSY(i/10) + 4);
    }
    ctx.fillStyle = 'rgba(255,255,255,0.13)';
    ctx.textAlign = 'center';
    ctx.fillText('x', toSX(0.5), toSY(0) + 30);
    ctx.save();
    ctx.translate(toSX(0) - 28, toSY(0.5));
    ctx.rotate(-Math.PI/2);
    ctx.fillText('f(x)', 0, 0);
    ctx.restore();
  }

  function drawDiagonal(){
    ctx.save();
    ctx.setLineDash([6, 4]);
    ctx.strokeStyle = 'rgba(255,255,255,0.18)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(toSX(0), toSY(0));
    ctx.lineTo(toSX(1), toSY(1));
    ctx.stroke();
    ctx.restore();
    ctx.fillStyle = 'rgba(255,255,255,0.1)';
    ctx.font = '9px "Space Grotesk",sans-serif';
    ctx.textAlign = 'left';
    var lx = Math.min(0.95, 1), ly = lx;
    ctx.fillText('y = x', toSX(lx) + 5, toSY(ly) - 5);
  }

  function drawFunc(){
    ctx.save();
    ctx.beginPath();
    ctx.rect(toSX(-0.02), toSY(1.05), pW * 1.04, pH * 1.1);
    ctx.clip();
    ctx.beginPath();
    ctx.strokeStyle = '#00f5d4';
    ctx.lineWidth = 2.5;
    ctx.shadowColor = 'rgba(0,245,212,0.35)';
    ctx.shadowBlur = 10;
    var first = true;
    for(var i = 0; i <= 500; i++){
      var x = i / 500;
      var y = fMap(x);
      if(!isFinite(y)){ first = true; continue; }
      var sx = toSX(x), sy = toSY(y);
      if(first){ ctx.moveTo(sx, sy); first = false; }
      else ctx.lineTo(sx, sy);
    }
    ctx.stroke();
    ctx.shadowBlur = 0;
    ctx.restore();
  }

  function drawFixedPts(){
    var prev = fMap(0) - 0;
    for(var i = 1; i <= 500; i++){
      var x = i / 500;
      var diff = fMap(x) - x;
      if(prev * diff <= 0 && prev !== diff){
        var x0 = (i-1)/500;
        var t = Math.abs(prev) / (Math.abs(prev) + Math.abs(diff));
        var xf = x0 + t / 500;
        var h = 0.0005;
        var fp = (fMap(xf + h) - fMap(xf - h)) / (2 * h);
        var stable = Math.abs(fp) < 1;
        ctx.beginPath();
        ctx.arc(toSX(xf), toSY(xf), stable ? 5 : 4, 0, Math.PI*2);
        if(stable){
          ctx.fillStyle = 'rgba(0,245,212,0.5)';
          ctx.fill();
        }
        ctx.strokeStyle = stable ? 'rgba(0,245,212,0.7)' : 'rgba(123,44,191,0.5)';
        ctx.lineWidth = 1.5;
        ctx.stroke();
      }
      prev = diff;
    }
  }

  function drawWeb(){
    var n = Math.min(Math.floor(animProg), pts.length - 1);
    var frac = animProg - Math.floor(animProg);

    ctx.save();
    ctx.beginPath();
    ctx.rect(toSX(-0.02), toSY(1.05), pW * 1.04, pH * 1.1);
    ctx.clip();

    for(var i = 0; i < n; i++){
      var p0 = pts[i], p1 = pts[i+1];
      var iter = Math.floor(i / 2);
      var t = iter / Math.max(1, maxSteps);
      var hue = brandHue(t);
      var alpha = Math.max(0.12, 1 - t * 0.7);
      var lw = Math.max(0.4, 1.8 - t * 1.2);
      ctx.beginPath();
      ctx.strokeStyle = 'hsla(' + hue + ',80%,55%,' + alpha + ')';
      ctx.lineWidth = lw;
      ctx.moveTo(toSX(p0[0]), toSY(p0[1]));
      ctx.lineTo(toSX(p1[0]), toSY(p1[1]));
      ctx.stroke();
    }

    if(n >= 0 && n < pts.length - 1 && frac > 0){
      var p0 = pts[n], p1 = pts[n+1];
      var px = p0[0] + (p1[0] - p0[0]) * frac;
      var py = p0[1] + (p1[1] - p0[1]) * frac;
      var iter = Math.floor(n / 2);
      var t = iter / Math.max(1, maxSteps);
      var hue = brandHue(t);
      ctx.beginPath();
      ctx.strokeStyle = 'hsla(' + hue + ',80%,55%,0.9)';
      ctx.lineWidth = 2;
      ctx.moveTo(toSX(p0[0]), toSY(p0[1]));
      ctx.lineTo(toSX(px), toSY(py));
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(toSX(px), toSY(py), 5, 0, Math.PI*2);
      ctx.fillStyle = '#00f5d4';
      ctx.shadowColor = 'rgba(0,245,212,0.6)';
      ctx.shadowBlur = 12;
      ctx.fill();
      ctx.shadowBlur = 0;
    } else if(pts.length > 1 && n >= pts.length - 1){
      var last = pts[pts.length - 1];
      ctx.beginPath();
      ctx.arc(toSX(last[0]), toSY(last[1]), 4, 0, Math.PI*2);
      ctx.fillStyle = 'rgba(123,44,191,0.8)';
      ctx.shadowColor = 'rgba(123,44,191,0.5)';
      ctx.shadowBlur = 10;
      ctx.fill();
      ctx.shadowBlur = 0;
    }
    ctx.restore();
  }

  function drawStart(){
    ctx.beginPath();
    ctx.arc(toSX(startX), toSY(0), 5, 0, Math.PI*2);
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    ctx.fill();
    ctx.strokeStyle = '#00f5d4';
    ctx.lineWidth = 1.5;
    ctx.stroke();
    ctx.fillStyle = 'rgba(255,255,255,0.3)';
    ctx.font = '9px "Space Grotesk",sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('x₀', toSX(startX), toSY(0) + 26);
  }

  function hudUpdate(){
    document.getElementById('hud-mode').textContent = mapMode;
    var row = document.getElementById('hud-prow');
    if(mapMode === 'DRAW'){ row.style.display = 'none'; }
    else {
      row.style.display = '';
      document.getElementById('hud-plbl').textContent = pDef[mapMode].name;
      document.getElementById('hud-pval').textContent = param.toFixed(2);
    }
    var it = Math.max(0, Math.floor(Math.min(animProg, pts.length - 1) / 2));
    document.getElementById('hud-iter').textContent = it;
    document.getElementById('hud-state').textContent =
      animProg >= pts.length - 1 ? detectState() : '…';
  }

  /* ---- loop ---- */
  function loop(){
    if(animProg < pts.length - 1){
      var oldI = Math.floor(animProg / 2);
      animProg += animSpeed * 0.16;
      if(animProg > pts.length - 1) animProg = pts.length - 1;
      var newI = Math.floor(animProg / 2);
      if(newI > oldI) SFX.play('tick', 60);
    }
    if(autoMode && mapMode !== 'DRAW' && animProg >= pts.length - 1){
      var pd = pDef[mapMode];
      param += (pd.max - pd.min) * 0.002;
      if(param > pd.max) param = pd.min;
      syncSlider();
      genCobweb();
    }
    draw();
    requestAnimationFrame(loop);
  }

  /* ---- events ---- */
  function getXY(e){
    if(e.touches && e.touches.length)
      return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    return { x: e.clientX, y: e.clientY };
  }
  function inPlot(x, y){
    return x >= pL - 10 && x <= pL + pW + 10 && y >= pT - 10 && y <= pT + pH + 10;
  }

  C.addEventListener('mousedown', function(e){
    e.preventDefault();
    if(e.button === 2){ genCobweb(); SFX.play('click'); return; }
    var p = getXY(e);
    if(!inPlot(p.x, p.y)) return;
    if(mapMode === 'DRAW'){
      isDrawing = true;
      initCustom();
      lastDX = fromSX(p.x); lastDY = fromSY(p.y);
      paintCustom(lastDX, lastDY, lastDX, lastDY);
      genCobweb();
      SFX.play('spawn');
    } else {
      startX = Math.max(0.001, Math.min(0.999, fromSX(p.x)));
      genCobweb();
      SFX.play('spawn');
    }
  });
  C.addEventListener('mousemove', function(e){
    if(!isDrawing) return;
    var p = getXY(e);
    var nx = fromSX(p.x), ny = fromSY(p.y);
    paintCustom(lastDX, lastDY, nx, ny);
    lastDX = nx; lastDY = ny;
    genCobweb();
  });
  C.addEventListener('mouseup', function(){
    if(isDrawing){ isDrawing = false; lastDX = lastDY = -1; genCobweb(); }
  });
  C.addEventListener('contextmenu', function(e){ e.preventDefault(); });

  C.addEventListener('touchstart', function(e){
    e.preventDefault();
    var p = getXY(e);
    if(!inPlot(p.x, p.y)) return;
    if(mapMode === 'DRAW'){
      isDrawing = true;
      initCustom();
      lastDX = fromSX(p.x); lastDY = fromSY(p.y);
      paintCustom(lastDX, lastDY, lastDX, lastDY);
      genCobweb();
      SFX.play('spawn');
    } else {
      startX = Math.max(0.001, Math.min(0.999, fromSX(p.x)));
      genCobweb();
      SFX.play('spawn');
    }
  }, {passive:false});
  C.addEventListener('touchmove', function(e){
    if(!isDrawing) return;
    e.preventDefault();
    var p = getXY(e);
    var nx = fromSX(p.x), ny = fromSY(p.y);
    paintCustom(lastDX, lastDY, nx, ny);
    lastDX = nx; lastDY = ny;
    genCobweb();
  }, {passive:false});
  C.addEventListener('touchend', function(){
    if(isDrawing){ isDrawing = false; lastDX = lastDY = -1; genCobweb(); }
  });

  function initCustom(){
    if(customY) return;
    customY = new Float32Array(300);
    for(var i = 0; i < 300; i++){
      var x = i / 299;
      customY[i] = 2.8 * x * (1 - x);
    }
  }
  function paintCustom(x0, y0, x1, y1){
    x0 = Math.max(0, Math.min(1, x0));
    x1 = Math.max(0, Math.min(1, x1));
    y0 = Math.max(0, Math.min(1, y0));
    y1 = Math.max(0, Math.min(1, y1));
    var n = customY.length - 1;
    var steps = Math.max(1, Math.ceil(Math.abs(x1 - x0) * n * 2));
    for(var s = 0; s <= steps; s++){
      var t = s / steps;
      var xi = x0 + (x1 - x0) * t;
      var yi = y0 + (y1 - y0) * t;
      var idx = Math.round(xi * n);
      if(idx >= 0 && idx <= n) customY[idx] = yi;
    }
  }

  /* ---- controls ---- */
  var mapBtns = document.querySelectorAll('[data-map]');
  mapBtns.forEach(function(btn){
    btn.addEventListener('click', function(){
      mapBtns.forEach(function(b){ b.classList.remove('active'); });
      this.classList.add('active');
      mapMode = this.getAttribute('data-map');
      param = pDef[mapMode].val;
      paramUI();
      syncSlider();
      genCobweb();
      SFX.play('toggle');
    });
  });

  var slP = document.getElementById('sl-param');
  var valP = document.getElementById('val-param');
  var lblP = document.getElementById('lbl-param');
  var rowP = document.getElementById('row-param');

  function syncSlider(){
    if(mapMode === 'DRAW') return;
    var pd = pDef[mapMode];
    slP.value = Math.round((param - pd.min) / (pd.max - pd.min) * 1000);
    valP.textContent = param.toFixed(2);
  }
  function paramUI(){
    if(mapMode === 'DRAW'){ rowP.style.display = 'none'; }
    else { rowP.style.display = ''; lblP.textContent = pDef[mapMode].name; syncSlider(); }
  }
  slP.addEventListener('input', function(){
    var pd = pDef[mapMode];
    param = pd.min + (pd.max - pd.min) * this.value / 1000;
    valP.textContent = param.toFixed(2);
    genCobweb();
  });

  var slSp = document.getElementById('sl-speed');
  var valSp = document.getElementById('val-speed');
  slSp.addEventListener('input', function(){
    animSpeed = +this.value; valSp.textContent = this.value;
  });

  var slSt = document.getElementById('sl-steps');
  var valSt = document.getElementById('val-steps');
  slSt.addEventListener('input', function(){
    maxSteps = +this.value; valSt.textContent = this.value; genCobweb();
  });

  document.getElementById('btn-auto').addEventListener('click', function(){
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', function(){
    if(mapMode === 'DRAW') customY = null;
    startX = 0.2;
    param = pDef[mapMode].val;
    paramUI(); syncSlider();
    genCobweb();
    SFX.play('click');
  });

  /* ---- init ---- */
  resize();
  genCobweb();
  addEventListener('resize', function(){ resize(); draw(); });
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
