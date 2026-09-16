<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('maxflow');

$pageName = 'maxflow';
$pageTitle = 'MAXFLOW - PULSE';
$pageDesc = '最大流。容量制約のあるネットワークにEdmonds-Karpアルゴリズムが増加路を見つけ、最小カットが浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/maxflow.php';
$extraStyle = '
#hud {
  position: fixed; top: 16px; left: 16px; z-index: 100;
  font-family: var(--font-mono, "JetBrains Mono", monospace);
  font-size: 0.65rem; color: rgba(255,255,255,0.56);
  pointer-events: none; line-height: 1.7;
}
#hud span { color: #00f5d4; }
#hud .pur { color: #b58cff; }
@media (max-width: 768px) {
  #hud { top: 60px; font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  MODE <span id="hud-mode">AUTO</span><br>
  FLOW <span id="hud-flow">0</span> / <span class="pur" id="hud-max">?</span><br>
  ITER <span id="hud-iter">0</span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add node'],
  ['type' => 'drag', 'label' => 'connect'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="mode-btn" data-mode="edit">EDIT</button>
      <button class="mode-btn active" data-mode="auto">AUTO</button>
      <button class="mode-btn" data-mode="step">STEP</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label class="ctrl-label">SPEED</label>
      <input type="range" id="sl-speed" min="1" max="10" value="3">
      <span class="slider-value" id="sv-speed">3</span>
    </div>
    <div class="slider-row" id="cap-row" style="display:none">
      <label class="ctrl-label">CAPACITY</label>
      <input type="range" id="sl-cap" min="1" max="20" value="5">
      <span class="slider-value" id="sv-cap">5</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-next">NEXT</button>
      <button id="btn-reset">RESET</button>
      <button id="btn-preset">PRESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W, H, dpr;
  var TEAL = [0, 245, 212];
  var PURPLE = [123, 44, 191];
  var NR = 20;
  var AR = 10;

  var nodes = [], edges = [];
  var src = 0, snk = -1;
  var totalFlow = 0, iter = 0;
  var spd = 3;
  var modeStr = 'auto';

  var phase = 'idle';
  var phaseT = 0;
  var bfsRes = null;
  var cutSet = [];
  var pushProg = 0;
  var hlTimer = 0;

  var dragFrom = -1, isDrag = false;
  var mpos = {x: 0, y: 0};
  var selEdge = -1;
  var lpTimer = null;
  var ptrDown = false;

  var time = 0, lastT = 0;

  var hudMode = document.getElementById('hud-mode');
  var hudFlow = document.getElementById('hud-flow');
  var hudMax = document.getElementById('hud-max');
  var hudIter = document.getElementById('hud-iter');

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mixC(a, b, t) { return [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t]; }
  function dist(a, b) { var dx = a.x-b.x, dy = a.y-b.y; return Math.sqrt(dx*dx+dy*dy); }
  function ptSeg(px, py, x1, y1, x2, y2) {
    var dx = x2-x1, dy = y2-y1, t = ((px-x1)*dx+(py-y1)*dy)/(dx*dx+dy*dy+1e-9);
    t = Math.max(0, Math.min(1, t));
    var cx = x1+t*dx, cy = y1+t*dy;
    return Math.sqrt((px-cx)*(px-cx)+(py-cy)*(py-cy));
  }

  function updateHUD() {
    hudMode.textContent = modeStr.toUpperCase();
    hudFlow.textContent = totalFlow;
    hudMax.textContent = phase === 'done' ? totalFlow : '?';
    hudIter.textContent = iter;
  }

  function resize() {
    var oW = W || window.innerWidth, oH = H || window.innerHeight;
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    canvas.width = W * dpr; canvas.height = H * dpr;
    canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].x *= W / oW; nodes[i].y *= H / oH;
    }
  }

  function nodeLabel(i) {
    if (i === src) return 'S';
    if (i === snk) return 'T';
    var n = 0;
    for (var j = 0; j < nodes.length; j++) {
      if (j === src || j === snk) continue;
      if (j === i) return String.fromCharCode(65 + n);
      n++;
    }
    return '?';
  }

  function preset() {
    var c = [[0.12,0.50],[0.33,0.22],[0.33,0.78],[0.67,0.22],[0.67,0.78],[0.88,0.50]];
    nodes = [];
    for (var i = 0; i < c.length; i++) nodes.push({x: c[i][0]*W, y: c[i][1]*H});
    src = 0; snk = 5;
    edges = [
      {from:0,to:1,cap:8,flow:0},{from:0,to:2,cap:6,flow:0},
      {from:1,to:3,cap:4,flow:0},{from:1,to:4,cap:5,flow:0},
      {from:2,to:3,cap:3,flow:0},{from:2,to:4,cap:5,flow:0},
      {from:3,to:5,cap:9,flow:0},{from:4,to:5,cap:3,flow:0}
    ];
    totalFlow = 0; iter = 0;
    resetAlgo();
    selEdge = -1;
    document.getElementById('cap-row').style.display = 'none';
    updateHUD();
  }

  function resetAlgo() {
    phase = 'idle'; phaseT = 0;
    bfsRes = null; cutSet = []; pushProg = 0; hlTimer = 0;
  }

  function runBFS() {
    var n = nodes.length;
    var par = new Array(n).fill(-1);
    var pEdge = new Array(n).fill(-1);
    var vis = new Array(n).fill(false);
    vis[src] = true;
    var q = [src], order = [src];
    while (q.length > 0) {
      var u = q.shift();
      for (var i = 0; i < edges.length; i++) {
        var e = edges[i];
        if (e.from === u && !vis[e.to] && e.cap - e.flow > 0) {
          vis[e.to] = true; par[e.to] = u; pEdge[e.to] = i;
          q.push(e.to); order.push(e.to);
          if (e.to === snk) return buildRes(par, pEdge, order, vis);
        }
        if (e.to === u && !vis[e.from] && e.flow > 0) {
          vis[e.from] = true; par[e.from] = u; pEdge[e.from] = -(i+1);
          q.push(e.from); order.push(e.from);
          if (e.from === snk) return buildRes(par, pEdge, order, vis);
        }
      }
    }
    return {found: false, order: order, vis: vis};
  }

  function buildRes(par, pEdge, order, vis) {
    var path = [], pe = [], v = snk;
    while (v !== src) { path.unshift(v); pe.unshift(pEdge[v]); v = par[v]; }
    path.unshift(src);
    var bn = Infinity;
    for (var i = 0; i < pe.length; i++) {
      if (pe[i] >= 0) bn = Math.min(bn, edges[pe[i]].cap - edges[pe[i]].flow);
      else bn = Math.min(bn, edges[-(pe[i]+1)].flow);
    }
    return {found: true, path: path, pe: pe, bn: bn, order: order, vis: vis};
  }

  function applyFlow() {
    if (!bfsRes || !bfsRes.found) return;
    for (var i = 0; i < bfsRes.pe.length; i++) {
      if (bfsRes.pe[i] >= 0) edges[bfsRes.pe[i]].flow += bfsRes.bn;
      else edges[-(bfsRes.pe[i]+1)].flow -= bfsRes.bn;
    }
    totalFlow += bfsRes.bn;
    iter++;
  }

  function computeCut() {
    var n = nodes.length;
    var vis = new Array(n).fill(false);
    vis[src] = true;
    var q = [src];
    while (q.length > 0) {
      var u = q.shift();
      for (var i = 0; i < edges.length; i++) {
        var e = edges[i];
        if (e.from === u && !vis[e.to] && e.cap - e.flow > 0) { vis[e.to] = true; q.push(e.to); }
        if (e.to === u && !vis[e.from] && e.flow > 0) { vis[e.from] = true; q.push(e.from); }
      }
    }
    cutSet = vis;
  }

  function isOnPath(idx) {
    if (!bfsRes || !bfsRes.found) return false;
    for (var j = 0; j < bfsRes.pe.length; j++) {
      if (bfsRes.pe[j] === idx || bfsRes.pe[j] === -(idx+1)) return true;
    }
    return false;
  }

  function stepAuto(dt) {
    phaseT += dt * spd;
    if (phase === 'bfs') {
      if (!bfsRes) bfsRes = runBFS();
      if (phaseT > 0.7) {
        if (bfsRes.found) { phase = 'path'; phaseT = 0; SFX.play('ripple'); }
        else { phase = 'done'; computeCut(); SFX.play('success'); }
      }
    } else if (phase === 'path') {
      if (phaseT > 0.9) { phase = 'push'; phaseT = 0; pushProg = 0; }
    } else if (phase === 'push') {
      pushProg = Math.min(1, phaseT / 0.7);
      if (phaseT > 0.7) {
        applyFlow(); bfsRes = null;
        phase = 'bfs'; phaseT = 0;
        SFX.play('ripple', 80);
      }
    }
    updateHUD();
  }

  function doStep() {
    if (phase === 'done') return;
    bfsRes = runBFS();
    if (bfsRes.found) {
      applyFlow(); hlTimer = 1.2;
      SFX.play('ripple');
    } else {
      phase = 'done'; computeCut();
      SFX.play('success');
    }
    updateHUD();
  }

  function edgePts(e) {
    var a = nodes[e.from], b = nodes[e.to];
    var dx = b.x-a.x, dy = b.y-a.y;
    var len = Math.sqrt(dx*dx+dy*dy) || 1;
    var nx = dx/len, ny = dy/len;
    var off = 0;
    for (var i = 0; i < edges.length; i++) {
      if (edges[i].from === e.to && edges[i].to === e.from) { off = 8; break; }
    }
    var ox = -ny*off, oy = nx*off;
    return {
      x1: a.x+nx*(NR+2)+ox, y1: a.y+ny*(NR+2)+oy,
      x2: b.x-nx*(NR+2)+ox, y2: b.y-ny*(NR+2)+oy,
      mx: (a.x+b.x)/2+ox, my: (a.y+b.y)/2+oy
    };
  }

  function drawArrow(x, y, dx, dy) {
    var len = Math.sqrt(dx*dx+dy*dy) || 1;
    var nx = dx/len, ny = dy/len;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x-AR*nx+AR*0.4*ny, y-AR*ny-AR*0.4*nx);
    ctx.lineTo(x-AR*nx-AR*0.4*ny, y-AR*ny+AR*0.4*nx);
    ctx.closePath();
    ctx.fill();
  }

  function drawEdge(e, idx) {
    var ep = edgePts(e);
    var ratio = e.cap > 0 ? e.flow / e.cap : 0;
    var showHL = (phase === 'path' || phase === 'push' || hlTimer > 0) && isOnPath(idx);
    var isCut = phase === 'done' && cutSet[e.from] && !cutSet[e.to];

    var alpha = 0.15 + ratio * 0.5;
    var col = TEAL;
    if (isCut) { col = PURPLE; alpha = 0.85; }
    if (showHL) { alpha = 0.9; }

    ctx.strokeStyle = rgba(col, alpha);
    ctx.lineWidth = isCut ? 3 : (1.5 + ratio * 2);
    if (isCut) ctx.setLineDash([6, 4]);
    ctx.beginPath();
    ctx.moveTo(ep.x1, ep.y1); ctx.lineTo(ep.x2, ep.y2);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = rgba(col, alpha);
    drawArrow(ep.x2, ep.y2, ep.x2-ep.x1, ep.y2-ep.y1);

    if (e.flow > 0 && phase !== 'done') {
      var np = Math.min(Math.ceil(e.flow), 4);
      for (var j = 0; j < np; j++) {
        var t = ((time * 0.001 * spd * 0.25 + j / np) % 1);
        var px = ep.x1 + (ep.x2-ep.x1)*t;
        var py = ep.y1 + (ep.y2-ep.y1)*t;
        ctx.beginPath(); ctx.arc(px, py, 2, 0, Math.PI*2);
        ctx.fillStyle = rgba(TEAL, 0.6); ctx.fill();
      }
    }

    var dx = ep.x2-ep.x1, dy = ep.y2-ep.y1;
    var len = Math.sqrt(dx*dx+dy*dy) || 1;
    var px = -dy/len, py = dx/len;
    if (py > 0) { px = -px; py = -py; }
    var lx = ep.mx + px * 14, ly = ep.my + py * 14;
    ctx.font = '600 11px "Space Grotesk", sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = rgba([255,255,255], showHL ? 1 : 0.55);
    ctx.fillText(e.flow + '/' + e.cap, lx, ly);

    if (idx === selEdge) {
      ctx.strokeStyle = rgba(TEAL, 0.4);
      ctx.lineWidth = 1; ctx.setLineDash([3, 3]);
      ctx.strokeRect(lx-18, ly-9, 36, 18);
      ctx.setLineDash([]);
    }
  }

  function drawNode(i) {
    var nd = nodes[i];
    var isSrc = i === src, isSnk = i === snk;
    var onPath = bfsRes && bfsRes.found && bfsRes.path.indexOf(i) >= 0 &&
                 (phase === 'path' || phase === 'push' || hlTimer > 0);
    var inBFS = bfsRes && bfsRes.order && bfsRes.order.indexOf(i) >= 0 &&
                (phase === 'bfs' || phase === 'path' || phase === 'push');
    var cutS = phase === 'done' && cutSet[i];
    var cutT = phase === 'done' && !cutSet[i];

    if (isSrc || onPath || cutS) {
      ctx.beginPath(); ctx.arc(nd.x, nd.y, NR+10, 0, Math.PI*2);
      ctx.fillStyle = rgba(TEAL, onPath ? 0.12 : 0.08); ctx.fill();
    }
    if (isSnk || cutT) {
      ctx.beginPath(); ctx.arc(nd.x, nd.y, NR+10, 0, Math.PI*2);
      ctx.fillStyle = rgba(PURPLE, 0.08); ctx.fill();
    }

    ctx.beginPath(); ctx.arc(nd.x, nd.y, NR, 0, Math.PI*2);
    ctx.fillStyle = 'rgba(10,10,15,0.92)'; ctx.fill();
    var bc = isSrc ? TEAL : (isSnk ? PURPLE : (inBFS ? TEAL : [255,255,255]));
    var ba = (isSrc||isSnk||onPath) ? 0.9 : (inBFS ? 0.5 : 0.2);
    ctx.strokeStyle = rgba(bc, ba);
    ctx.lineWidth = (isSrc||isSnk||onPath) ? 2.5 : 1.5;
    ctx.stroke();

    ctx.font = '700 14px "Space Grotesk", sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = isSrc ? rgba(TEAL, 0.9) : (isSnk ? '#b58cff' : rgba([255,255,255], 0.65));
    ctx.fillText(nodeLabel(i), nd.x, nd.y);
  }

  function drawPushWave() {
    if (phase !== 'push' || !bfsRes || !bfsRes.found) return;
    var p = bfsRes.path;
    var totalLen = 0, segs = [];
    for (var i = 0; i < p.length-1; i++) {
      var a = nodes[p[i]], b = nodes[p[i+1]];
      var d = dist(a, b); segs.push(d); totalLen += d;
    }
    var target = pushProg * totalLen, acc = 0;
    for (var i = 0; i < segs.length; i++) {
      if (acc + segs[i] >= target || i === segs.length-1) {
        var t = segs[i] > 0 ? Math.min(1, (target-acc)/segs[i]) : 0;
        var a = nodes[p[i]], b = nodes[p[i+1]];
        var px = a.x+(b.x-a.x)*t, py = a.y+(b.y-a.y)*t;
        ctx.save();
        ctx.shadowColor = rgba(TEAL, 0.8); ctx.shadowBlur = 18;
        ctx.beginPath(); ctx.arc(px, py, 5, 0, Math.PI*2);
        ctx.fillStyle = rgba(TEAL, 0.9); ctx.fill();
        ctx.restore();
        break;
      }
      acc += segs[i];
    }
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    for (var i = 0; i < edges.length; i++) drawEdge(edges[i], i);
    drawPushWave();
    if (isDrag && dragFrom >= 0) {
      ctx.strokeStyle = rgba(TEAL, 0.35);
      ctx.lineWidth = 2; ctx.setLineDash([6, 4]);
      ctx.beginPath();
      ctx.moveTo(nodes[dragFrom].x, nodes[dragFrom].y);
      ctx.lineTo(mpos.x, mpos.y);
      ctx.stroke(); ctx.setLineDash([]);
    }
    for (var i = 0; i < nodes.length; i++) drawNode(i);

    if ((phase === 'path' || phase === 'push') && bfsRes && bfsRes.found) {
      ctx.font = '700 15px "Space Grotesk", sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = rgba(TEAL, 0.75);
      ctx.fillText('+' + bfsRes.bn, W/2, 28);
    }
    if (phase === 'done') {
      ctx.font = '600 14px "Space Grotesk", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillStyle = rgba(TEAL, 0.8);
      ctx.fillText('MAX FLOW = ' + totalFlow, W/2, 28);
      ctx.fillStyle = rgba(PURPLE, 0.5);
      ctx.fillText('MIN CUT', W/2, 48);
    }
  }

  function update(dt) {
    if (modeStr === 'auto' && phase !== 'idle' && phase !== 'done') {
      stepAuto(dt);
    }
    if (hlTimer > 0) {
      hlTimer -= dt * spd;
      if (hlTimer <= 0) { hlTimer = 0; bfsRes = null; }
    }
  }

  function nodeAt(x, y) {
    for (var i = nodes.length-1; i >= 0; i--) {
      if (dist({x:x,y:y}, nodes[i]) < NR+5) return i;
    }
    return -1;
  }

  function edgeAt(x, y) {
    var best = -1, bd = 15;
    for (var i = 0; i < edges.length; i++) {
      var ep = edgePts(edges[i]);
      var d = ptSeg(x, y, ep.x1, ep.y1, ep.x2, ep.y2);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function addNode(x, y) {
    nodes.push({x: x, y: y});
    if (snk < 0 && nodes.length >= 2) snk = nodes.length - 1;
    SFX.play('spawn');
  }

  function addEdge(from, to) {
    if (from === to) return;
    for (var i = 0; i < edges.length; i++) {
      if (edges[i].from === from && edges[i].to === to) {
        edges[i].cap = Math.min(edges[i].cap + 1, 20);
        SFX.play('tick'); return;
      }
    }
    edges.push({from: from, to: to, cap: 5, flow: 0});
    SFX.play('tick');
  }

  function removeNode(idx) {
    if (idx === src || idx === snk) return;
    edges = edges.filter(function(e) { return e.from !== idx && e.to !== idx; });
    for (var j = 0; j < edges.length; j++) {
      if (edges[j].from > idx) edges[j].from--;
      if (edges[j].to > idx) edges[j].to--;
    }
    nodes.splice(idx, 1);
    if (src > idx) src--;
    if (snk > idx) snk--;
    SFX.play('pop');
  }

  function removeEdge(idx) {
    edges.splice(idx, 1);
    selEdge = -1;
    document.getElementById('cap-row').style.display = 'none';
    SFX.play('pop');
  }

  function onDown(x, y, isRight) {
    ptrDown = true; mpos.x = x; mpos.y = y;
    if (isRight) {
      if (modeStr !== 'edit') return;
      var ni = nodeAt(x, y);
      if (ni >= 0 && ni !== src && ni !== snk) { removeNode(ni); return; }
      var ei = edgeAt(x, y);
      if (ei >= 0) { removeEdge(ei); return; }
      return;
    }
    var ni = nodeAt(x, y);
    if (ni >= 0) {
      dragFrom = ni; isDrag = false;
      if (modeStr === 'edit') {
        lpTimer = setTimeout(function() {
          if (ni !== src && ni !== snk) { removeNode(ni); dragFrom = -1; }
        }, 600);
      }
    } else if (modeStr === 'edit') {
      var ei = edgeAt(x, y);
      if (ei >= 0) {
        selEdge = ei;
        var cr = document.getElementById('cap-row');
        var sc = document.getElementById('sl-cap');
        var sv = document.getElementById('sv-cap');
        cr.style.display = ''; sc.value = edges[ei].cap; sv.textContent = edges[ei].cap;
        SFX.play('click');
      } else {
        selEdge = -1;
        document.getElementById('cap-row').style.display = 'none';
        addNode(x, y);
      }
    }
  }

  function onMove(x, y) {
    mpos.x = x; mpos.y = y;
    if (ptrDown && dragFrom >= 0 && dist({x:x,y:y}, nodes[dragFrom]) > 12) {
      isDrag = true; clearTimeout(lpTimer);
    }
  }

  function onUp(x, y) {
    clearTimeout(lpTimer);
    if (isDrag && dragFrom >= 0 && modeStr === 'edit') {
      var ni = nodeAt(x, y);
      if (ni >= 0 && ni !== dragFrom) addEdge(dragFrom, ni);
    }
    dragFrom = -1; isDrag = false; ptrDown = false;
  }

  canvas.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var r = canvas.getBoundingClientRect();
    onDown(e.clientX-r.left, e.clientY-r.top, e.button === 2);
  });
  canvas.addEventListener('mousemove', function(e) {
    var r = canvas.getBoundingClientRect();
    onMove(e.clientX-r.left, e.clientY-r.top);
  });
  canvas.addEventListener('mouseup', function(e) {
    var r = canvas.getBoundingClientRect();
    onUp(e.clientX-r.left, e.clientY-r.top);
  });
  canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0], r = canvas.getBoundingClientRect();
    onDown(t.clientX-r.left, t.clientY-r.top, false);
  }, {passive: false});
  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0], r = canvas.getBoundingClientRect();
    onMove(t.clientX-r.left, t.clientY-r.top);
  }, {passive: false});
  canvas.addEventListener('touchend', function(e) {
    e.preventDefault(); onUp(mpos.x, mpos.y);
  }, {passive: false});

  function setMode(m) {
    modeStr = m;
    for (var i = 0; i < edges.length; i++) edges[i].flow = 0;
    totalFlow = 0; iter = 0;
    phase = 'idle'; phaseT = 0;
    bfsRes = null; cutSet = []; hlTimer = 0; pushProg = 0;
    selEdge = -1;
    document.getElementById('cap-row').style.display = 'none';
    if (m === 'auto') { phase = 'bfs'; phaseT = 0; }
    SFX.play('toggle');
    updateHUD();
  }

  document.querySelectorAll('.mode-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.mode-btn').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      setMode(btn.dataset.mode);
    });
  });

  document.getElementById('btn-next').addEventListener('click', function() {
    if (modeStr !== 'step') return;
    if (phase === 'done') return;
    doStep();
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    for (var i = 0; i < edges.length; i++) edges[i].flow = 0;
    totalFlow = 0; iter = 0;
    phase = 'idle'; phaseT = 0;
    bfsRes = null; cutSet = []; hlTimer = 0;
    if (modeStr === 'auto') { phase = 'bfs'; phaseT = 0; }
    SFX.play('click'); updateHUD();
  });

  document.getElementById('btn-preset').addEventListener('click', function() {
    preset();
    if (modeStr === 'auto') { phase = 'bfs'; phaseT = 0; }
    SFX.play('click'); updateHUD();
  });

  document.getElementById('sl-speed').addEventListener('input', function() {
    spd = parseInt(this.value);
    document.getElementById('sv-speed').textContent = spd;
  });

  document.getElementById('sl-cap').addEventListener('input', function() {
    if (selEdge >= 0) {
      edges[selEdge].cap = parseInt(this.value);
      document.getElementById('sv-cap').textContent = this.value;
    }
  });

  resize(); preset();
  window.addEventListener('resize', resize);
  updateHUD();

  setTimeout(function() {
    if (modeStr === 'auto' && phase === 'idle') { phase = 'bfs'; phaseT = 0; }
  }, 1500);

  function loop(t) {
    var dt = lastT ? (t - lastT) / 1000 : 0;
    lastT = t; time = t;
    if (dt > 0.1) dt = 0.1;
    update(dt);
    draw();
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
