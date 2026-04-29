<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('mazegen');

$pageName = 'mazegen';
$pageTitle = 'MAZEGEN - PULSE';
$pageDesc = '迷路生成アルゴリズムの可視化。4つのアルゴリズムが壁を削り、通路を刻んでいく。';
$pageCanonical = 'https://pulse.subara3.com/mazegen.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  ALGORITHM: <span id="hudAlgo" class="v">DFS</span><br>
  GRID: <span id="hudGrid" class="v">-</span><br>
  CARVED: <span id="hudCarved" class="v">0 / 0</span>
</div>

<div id="info">
  maze generation<br>
  <span class="v">4 algorithms</span> carve passages<br>
  same grid, different patterns
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'regenerate'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">GRID</label>
    <div class="slider-row">
      <input type="range" id="sldSize" min="10" max="60" value="30">
      <span class="slider-value" id="valSize">30</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="80" value="15">
      <span class="slider-value" id="valSpeed">15</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">ALGORITHM</label>
    <div class="preset-group" id="algoGroup">
      <button class="preset-btn active" data-a="0">DFS</button>
      <button class="preset-btn" data-a="1">PRIM</button>
      <button class="preset-btn" data-a="2">KRUSKAL</button>
      <button class="preset-btn" data-a="3">WILSON</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnAuto">AUTO: ON</button>
      <button class="control-btn" id="btnGen">GENERATE</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  var gridTarget = 30;
  var gW, gH, cellSz, total;
  var wallT, wallR, wallB, wallL;
  var vis, hlArr;
  var algoIdx = 0;
  var algoNames = ['DFS', 'PRIM', 'KRUSKAL', 'WILSON'];
  var st = null;
  var isDone = false;
  var speed = 15;
  var autoRegen = true;
  var carved = 0;
  var doneTime = 0;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function compGrid() {
    cellSz = Math.min(W * 0.9, H * 0.82) / gridTarget;
    cellSz = Math.max(6, Math.floor(cellSz));
    gW = Math.floor(W * 0.92 / cellSz);
    gH = Math.floor(H * 0.84 / cellSz);
    gW = Math.max(5, Math.min(gW, 100));
    gH = Math.max(5, Math.min(gH, 80));
    total = gW * gH;
  }

  function initGrid() {
    compGrid();
    wallT = new Uint8Array(total); wallR = new Uint8Array(total);
    wallB = new Uint8Array(total); wallL = new Uint8Array(total);
    vis = new Uint8Array(total);
    hlArr = new Uint8Array(total);
    for (var i = 0; i < total; i++) {
      wallT[i] = wallR[i] = wallB[i] = wallL[i] = 1;
    }
    carved = 0;
  }

  function I(x, y) { return y * gW + x; }

  function rmWall(x1, y1, x2, y2) {
    if (x2 === x1 + 1) { wallR[I(x1,y1)] = 0; wallL[I(x2,y2)] = 0; }
    else if (x2 === x1 - 1) { wallL[I(x1,y1)] = 0; wallR[I(x2,y2)] = 0; }
    else if (y2 === y1 + 1) { wallB[I(x1,y1)] = 0; wallT[I(x2,y2)] = 0; }
    else if (y2 === y1 - 1) { wallT[I(x1,y1)] = 0; wallB[I(x2,y2)] = 0; }
  }

  var DX = [0, 1, 0, -1], DY = [-1, 0, 1, 0];

  function neighbors(x, y, checkVis) {
    var nb = [];
    for (var d = 0; d < 4; d++) {
      var nx = x + DX[d], ny = y + DY[d];
      if (nx >= 0 && nx < gW && ny >= 0 && ny < gH) {
        if (checkVis === undefined || vis[I(nx, ny)] === checkVis)
          nb.push({x: nx, y: ny});
      }
    }
    return nb;
  }

  // DFS
  function dfsInit() {
    var sx = Math.floor(Math.random() * gW), sy = Math.floor(Math.random() * gH);
    vis[I(sx, sy)] = 1; carved = 1;
    return {stack: [{x: sx, y: sy}]};
  }
  function dfsStep(s) {
    for (var i = 0; i < total; i++) hlArr[i] = 0;
    if (!s.stack.length) return true;
    var cur = s.stack[s.stack.length - 1];
    for (var i = 0; i < s.stack.length - 1; i++) hlArr[I(s.stack[i].x, s.stack[i].y)] = 3;
    hlArr[I(cur.x, cur.y)] = 2;
    var nb = neighbors(cur.x, cur.y, 0);
    if (nb.length) {
      var next = nb[Math.floor(Math.random() * nb.length)];
      rmWall(cur.x, cur.y, next.x, next.y);
      vis[I(next.x, next.y)] = 1; carved++;
      s.stack.push(next);
    } else {
      s.stack.pop();
    }
    return false;
  }

  // Prim
  function primInit() {
    var sx = Math.floor(Math.random() * gW), sy = Math.floor(Math.random() * gH);
    vis[I(sx, sy)] = 1; carved = 1;
    var fr = [];
    var nb = neighbors(sx, sy, 0);
    for (var i = 0; i < nb.length; i++) fr.push(nb[i]);
    return {fr: fr, frSet: new Set(fr.map(function(p) { return I(p.x, p.y); }))};
  }
  function primStep(s) {
    for (var i = 0; i < total; i++) hlArr[i] = 0;
    if (!s.fr.length) return true;
    s.fr.forEach(function(p) { hlArr[I(p.x, p.y)] = 1; });
    var ri = Math.floor(Math.random() * s.fr.length);
    var cell = s.fr[ri];
    var vn = neighbors(cell.x, cell.y, 1);
    if (vn.length) {
      var conn = vn[Math.floor(Math.random() * vn.length)];
      rmWall(cell.x, cell.y, conn.x, conn.y);
      vis[I(cell.x, cell.y)] = 1; carved++;
      hlArr[I(cell.x, cell.y)] = 2;
      var newNb = neighbors(cell.x, cell.y, 0);
      for (var i = 0; i < newNb.length; i++) {
        var k = I(newNb[i].x, newNb[i].y);
        if (!s.frSet.has(k)) { s.fr.push(newNb[i]); s.frSet.add(k); }
      }
    }
    s.fr.splice(ri, 1); s.frSet.delete(I(cell.x, cell.y));
    for (var i = s.fr.length - 1; i >= 0; i--) {
      if (vis[I(s.fr[i].x, s.fr[i].y)]) { s.frSet.delete(I(s.fr[i].x, s.fr[i].y)); s.fr.splice(i, 1); }
    }
    return false;
  }

  // Kruskal
  function krusInit() {
    var edges = [];
    for (var y = 0; y < gH; y++)
      for (var x = 0; x < gW; x++) {
        if (x < gW - 1) edges.push({x1:x,y1:y,x2:x+1,y2:y});
        if (y < gH - 1) edges.push({x1:x,y1:y,x2:x,y2:y+1});
      }
    for (var i = edges.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = edges[i]; edges[i] = edges[j]; edges[j] = t;
    }
    var par = new Int32Array(total), rnk = new Uint8Array(total);
    for (var i = 0; i < total; i++) par[i] = i;
    return {edges: edges, ei: 0, par: par, rnk: rnk};
  }
  function kFind(p, x) { while (p[x] !== x) { p[x] = p[p[x]]; x = p[x]; } return x; }
  function kUnion(p, r, a, b) {
    var ra = kFind(p,a), rb = kFind(p,b);
    if (ra === rb) return false;
    if (r[ra] < r[rb]) { var t=ra; ra=rb; rb=t; }
    p[rb] = ra; if (r[ra]===r[rb]) r[ra]++;
    return true;
  }
  function krusStep(s) {
    for (var i = 0; i < total; i++) hlArr[i] = 0;
    while (s.ei < s.edges.length) {
      var e = s.edges[s.ei++];
      var a = I(e.x1,e.y1), b = I(e.x2,e.y2);
      if (kUnion(s.par, s.rnk, a, b)) {
        rmWall(e.x1,e.y1,e.x2,e.y2);
        if (!vis[a]) { vis[a]=1; carved++; }
        if (!vis[b]) { vis[b]=1; carved++; }
        hlArr[a] = 2; hlArr[b] = 2;
        return false;
      }
    }
    return true;
  }

  // Wilson
  function wilsInit() {
    var sx = Math.floor(Math.random()*gW), sy = Math.floor(Math.random()*gH);
    vis[I(sx,sy)] = 1; carved = 1;
    return {walking: false, path: [], pathSet: new Set()};
  }
  function wilsStep(s) {
    for (var i = 0; i < total; i++) hlArr[i] = 0;
    if (carved >= total) return true;
    if (!s.walking) {
      var cands = [];
      for (var i = 0; i < total; i++) if (!vis[i]) cands.push(i);
      if (!cands.length) return true;
      var si = cands[Math.floor(Math.random()*cands.length)];
      var sx = si % gW, sy = (si / gW) | 0;
      s.path = [{x:sx,y:sy}];
      s.pathSet = new Set([si]);
      s.walking = true;
    }
    for (var i = 0; i < s.path.length; i++) hlArr[I(s.path[i].x,s.path[i].y)] = 3;
    var last = s.path[s.path.length-1];
    hlArr[I(last.x,last.y)] = 2;
    var d = Math.floor(Math.random()*4);
    var nx = last.x+DX[d], ny = last.y+DY[d];
    if (nx<0||nx>=gW||ny<0||ny>=gH) return false;
    var nk = I(nx,ny);
    if (vis[nk]) {
      s.path.push({x:nx,y:ny});
      for (var i = 0; i < s.path.length-1; i++) {
        var a=s.path[i], b=s.path[i+1];
        vis[I(a.x,a.y)] = 1; carved++;
        rmWall(a.x,a.y,b.x,b.y);
      }
      s.walking = false;
      if (typeof SFX!=='undefined') SFX.play('spawn',50);
      return false;
    }
    if (s.pathSet.has(nk)) {
      var li = -1;
      for (var i = 0; i < s.path.length; i++) {
        if (I(s.path[i].x,s.path[i].y)===nk) { li=i; break; }
      }
      for (var i = s.path.length-1; i > li; i--) s.pathSet.delete(I(s.path[i].x,s.path[i].y));
      s.path.length = li+1;
      return false;
    }
    s.path.push({x:nx,y:ny});
    s.pathSet.add(nk);
    return false;
  }

  var algos = [
    {init:dfsInit, step:dfsStep},
    {init:primInit, step:primStep},
    {init:krusInit, step:krusStep},
    {init:wilsInit, step:wilsStep}
  ];

  function startGen() {
    initGrid();
    st = algos[algoIdx].init();
    isDone = false;
    updateHud();
  }

  function updateHud() {
    document.getElementById('hudAlgo').textContent = algoNames[algoIdx];
    document.getElementById('hudGrid').textContent = gW + '×' + gH;
    document.getElementById('hudCarved').textContent = carved + ' / ' + total;
  }

  function render() {
    ctx.clearRect(0, 0, W, H);
    var offX = (W - gW * cellSz) / 2;
    var offY = (H - gH * cellSz) / 2;

    for (var y = 0; y < gH; y++) {
      for (var x = 0; x < gW; x++) {
        var i = I(x, y), h = hlArr[i];
        if (h || vis[i]) {
          var px = offX + x * cellSz, py = offY + y * cellSz;
          ctx.fillStyle = h === 2 ? 'rgba(0,245,212,0.35)'
            : h === 1 ? 'rgba(123,44,191,0.22)'
            : h === 3 ? 'rgba(0,245,212,0.1)'
            : 'rgba(0,245,212,0.03)';
          ctx.fillRect(px, py, cellSz, cellSz);
        }
      }
    }

    ctx.beginPath();
    for (var y = 0; y < gH; y++) {
      for (var x = 0; x < gW; x++) {
        var i = I(x, y);
        var px = offX + x * cellSz, py = offY + y * cellSz;
        if (wallT[i]) { ctx.moveTo(px, py); ctx.lineTo(px + cellSz, py); }
        if (wallL[i]) { ctx.moveTo(px, py); ctx.lineTo(px, py + cellSz); }
      }
    }
    for (var x = 0; x < gW; x++) {
      if (wallB[I(x, gH-1)]) {
        ctx.moveTo(offX + x*cellSz, offY + gH*cellSz);
        ctx.lineTo(offX + (x+1)*cellSz, offY + gH*cellSz);
      }
    }
    for (var y = 0; y < gH; y++) {
      if (wallR[I(gW-1, y)]) {
        ctx.moveTo(offX + gW*cellSz, offY + y*cellSz);
        ctx.lineTo(offX + gW*cellSz, offY + (y+1)*cellSz);
      }
    }
    ctx.strokeStyle = 'rgba(0,245,212,0.3)';
    ctx.lineWidth = 1;
    ctx.stroke();

    ctx.strokeStyle = 'rgba(0,245,212,0.5)';
    ctx.lineWidth = 1.5;
    ctx.strokeRect(offX, offY, gW * cellSz, gH * cellSz);
  }

  function frame(now) {
    if (!isDone) {
      var spf = Math.ceil(speed * speed / 8);
      if (algoIdx === 3) spf = Math.max(spf, speed * 5);
      for (var s = 0; s < spf; s++) {
        if (algos[algoIdx].step(st)) {
          isDone = true;
          for (var i = 0; i < total; i++) hlArr[i] = 0;
          doneTime = now;
          if (typeof SFX !== 'undefined') SFX.play('complete');
          break;
        }
      }
      updateHud();
    } else if (autoRegen && now - doneTime > 2500) {
      startGen();
      if (typeof SFX !== 'undefined') SFX.play('click');
    }
    render();
    requestAnimationFrame(frame);
  }

  resize();
  window.addEventListener('resize', function() { resize(); startGen(); });
  startGen();
  requestAnimationFrame(frame);

  C.addEventListener('click', function() {
    startGen();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  document.getElementById('sldSize').addEventListener('input', function() {
    gridTarget = parseInt(this.value);
    document.getElementById('valSize').textContent = gridTarget;
    startGen();
  });

  document.getElementById('sldSpeed').addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('valSpeed').textContent = speed;
  });

  document.querySelectorAll('#algoGroup .preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#algoGroup .preset-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      algoIdx = parseInt(this.dataset.a);
      startGen();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('btnAuto').addEventListener('click', function() {
    autoRegen = !autoRegen;
    this.textContent = 'AUTO: ' + (autoRegen ? 'ON' : 'OFF');
    this.classList.toggle('active', autoRegen);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnGen').addEventListener('click', function() {
    startGen();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
