<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('topopt');

$pageName = 'topopt';
$pageTitle = 'TOPOPT - PULSE';
$pageDesc = '位相最適化。荷重と支持を配置すると、最小限の材料で力を伝える最も効率的な構造が自己組織的に現れる。';
$pageCanonical = 'https://pulse.subara3.com/topopt.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">TOPOPT</span><br>ITER <b id="h-iter">0</b><br>C <b id="h-comp">&mdash;</b><br>VOL <b id="h-vol">0.40</b></div>
<div class="mark">TOPOPT</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'load'],
  ['type' => 'drag', 'label' => 'direct'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <label class="ctrl-label">VOLUME</label>
      <input type="range" id="sl-vol" min="15" max="70" step="1" value="40">
      <span class="slider-value" id="sv-vol">0.40</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="mode-btn active" data-preset="cantilever">CANTILEVER</button>
      <button class="mode-btn" data-preset="bridge">BRIDGE</button>
      <button class="mode-btn" data-preset="mbb">MBB</button>
      <button class="mode-btn" data-preset="tower">TOWER</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){ 'use strict';

  /* ── Canvas ── */
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  /* ── Grid parameters ── */
  var NEX = 60, NEY = 30;
  var nNodes = (NEX + 1) * (NEY + 1);
  var nDof = 2 * nNodes;
  var nEl = NEX * NEY;

  /* ── SIMP parameters ── */
  var penal = 3.0;
  var E0 = 1.0;
  var Emin = 1e-9;
  var rmin = 1.5;
  var volFrac = 0.40;
  var moveLimit = 0.2;

  /* ── State arrays ── */
  var rho = new Float64Array(nEl);
  var dc = new Float64Array(nEl);
  var dcFiltered = new Float64Array(nEl);
  var U = new Float64Array(nDof);
  var F = new Float64Array(nDof);

  /* ── Element DOF mapping (precomputed) ── */
  var edof = new Int32Array(nEl * 8);
  (function buildEdof() {
    for (var ey = 0; ey < NEY; ey++) {
      for (var ex = 0; ex < NEX; ex++) {
        var eIdx = ey * NEX + ex;
        var n1 = ey * (NEX + 1) + ex;         // BL
        var n2 = n1 + 1;                        // BR
        var n3 = (ey + 1) * (NEX + 1) + ex + 1; // TR
        var n4 = (ey + 1) * (NEX + 1) + ex;     // TL
        var base = eIdx * 8;
        edof[base    ] = 2 * n1;
        edof[base + 1] = 2 * n1 + 1;
        edof[base + 2] = 2 * n2;
        edof[base + 3] = 2 * n2 + 1;
        edof[base + 4] = 2 * n3;
        edof[base + 5] = 2 * n3 + 1;
        edof[base + 6] = 2 * n4;
        edof[base + 7] = 2 * n4 + 1;
      }
    }
  })();

  /* ── Element stiffness matrix (8x8, from 88-line code) ── */
  var KE = new Float64Array(64);
  (function buildKE() {
    var nu = 0.3;
    var k = new Float64Array(8);
    k[0] = 1/2 - nu/6;
    k[1] = 1/8 + nu/8;
    k[2] = -1/4 - nu/12;
    k[3] = -1/8 + 3*nu/8;
    k[4] = -1/4 + nu/12;
    k[5] = -1/8 - nu/8;
    k[6] = nu/6;
    k[7] = 1/8 - 3*nu/8;

    // Pattern: row i maps to indices in k[]
    var pat = [
      [0,1,2,3,4,5,6,7],
      [1,0,7,6,5,4,3,2],
      [2,7,0,5,6,3,4,1],
      [3,6,5,0,7,2,1,4],
      [4,5,6,7,0,1,2,3],
      [5,4,3,2,1,0,7,6],
      [6,3,4,1,2,7,0,5],
      [7,2,1,4,3,6,5,0]
    ];

    var scale = 1 / (1 - nu * nu);
    for (var i = 0; i < 8; i++) {
      for (var j = 0; j < 8; j++) {
        KE[i * 8 + j] = scale * k[pat[i][j]];
      }
    }
  })();

  /* ── Sensitivity filter weights (precomputed) ── */
  var filterNeighbors = []; // filterNeighbors[e] = [{idx, weight}, ...]
  (function buildFilter() {
    var rmin2 = rmin;
    for (var e = 0; e < nEl; e++) {
      var ey = (e / NEX) | 0;
      var ex = e % NEX;
      var cx = ex + 0.5;
      var cy = ey + 0.5;
      var neighbors = [];
      var iMin = Math.max(0, Math.floor(cx - rmin2 - 1));
      var iMax = Math.min(NEX - 1, Math.ceil(cx + rmin2));
      var jMin = Math.max(0, Math.floor(cy - rmin2 - 1));
      var jMax = Math.min(NEY - 1, Math.ceil(cy + rmin2));
      for (var jj = jMin; jj <= jMax; jj++) {
        for (var ii = iMin; ii <= iMax; ii++) {
          var dist = Math.sqrt((ii + 0.5 - cx) * (ii + 0.5 - cx) + (jj + 0.5 - cy) * (jj + 0.5 - cy));
          if (dist < rmin2) {
            neighbors.push({ idx: jj * NEX + ii, weight: rmin2 - dist });
          }
        }
      }
      filterNeighbors.push(neighbors);
    }
  })();

  /* ── Boundary conditions ── */
  var fixedDofs = [];
  var loads = []; // [{node, fx, fy}]
  var userLoads = []; // [{node, fx, fy}]
  var currentPreset = 'cantilever';

  function setupPreset(name) {
    currentPreset = name;
    fixedDofs = [];
    loads = [];

    if (name === 'cantilever') {
      // Left wall fully fixed
      for (var iy = 0; iy <= NEY; iy++) {
        var n = iy * (NEX + 1);
        fixedDofs.push(2 * n, 2 * n + 1);
      }
      // Downward point load at mid-right
      var loadNode = Math.round(NEY / 2) * (NEX + 1) + NEX;
      loads.push({ node: loadNode, fx: 0, fy: -1 });
    }
    else if (name === 'bridge') {
      // Bottom-left corner pinned
      var bl = 0;
      fixedDofs.push(2 * bl, 2 * bl + 1);
      // Bottom-right corner pinned
      var br = NEX;
      fixedDofs.push(2 * br, 2 * br + 1);
      // Downward load at top center
      var topCenter = NEY * (NEX + 1) + Math.round(NEX / 2);
      loads.push({ node: topCenter, fx: 0, fy: -1 });
    }
    else if (name === 'mbb') {
      // Left edge x-fixed (roller)
      for (var iy2 = 0; iy2 <= NEY; iy2++) {
        var n2 = iy2 * (NEX + 1);
        fixedDofs.push(2 * n2); // x-fixed only
      }
      // Bottom-right corner y-fixed
      var brCorner = NEX;
      fixedDofs.push(2 * brCorner + 1);
      // Downward load at top-left
      var topLeft = NEY * (NEX + 1);
      loads.push({ node: topLeft, fx: 0, fy: -1 });
    }
    else if (name === 'tower') {
      // Bottom wall fully fixed
      for (var ix = 0; ix <= NEX; ix++) {
        fixedDofs.push(2 * ix, 2 * ix + 1);
      }
      // Downward load at top center
      var topC = NEY * (NEX + 1) + Math.round(NEX / 2);
      loads.push({ node: topC, fx: 0, fy: -1 });
    }
  }

  /* ── Assemble force vector ── */
  function assembleForce() {
    for (var i = 0; i < nDof; i++) F[i] = 0;
    var allLoads = loads.concat(userLoads);
    for (var k = 0; k < allLoads.length; k++) {
      var ld = allLoads[k];
      F[2 * ld.node]     += ld.fx;
      F[2 * ld.node + 1] += ld.fy;
    }
  }

  /* ── Matrix-free CG solver ── */
  // Compute K*v product element-by-element (matrix-free)
  var Kv_temp = new Float64Array(nDof);
  var ue_temp = new Float64Array(8);
  var ke_temp = new Float64Array(8);

  function matVecProduct(v, result) {
    for (var i = 0; i < nDof; i++) result[i] = 0;

    for (var e = 0; e < nEl; e++) {
      var Ee = Emin + Math.pow(rho[e], penal) * (E0 - Emin);
      var base = e * 8;

      // Gather element DOFs
      for (var ii = 0; ii < 8; ii++) ue_temp[ii] = v[edof[base + ii]];

      // ke = KE * ue * Ee
      for (var ii2 = 0; ii2 < 8; ii2++) {
        var s = 0;
        var row = ii2 * 8;
        for (var jj = 0; jj < 8; jj++) s += KE[row + jj] * ue_temp[jj];
        ke_temp[ii2] = s * Ee;
      }

      // Scatter
      for (var ii3 = 0; ii3 < 8; ii3++) result[edof[base + ii3]] += ke_temp[ii3];
    }

    // Apply fixed DOFs (large-number method contribution to product)
    for (var f = 0; f < fixedDofs.length; f++) {
      var d = fixedDofs[f];
      result[d] += 1e9 * v[d];
    }
  }

  // Diagonal preconditioner
  var diagK = new Float64Array(nDof);
  function buildDiagPrecond() {
    for (var i = 0; i < nDof; i++) diagK[i] = 0;
    for (var e = 0; e < nEl; e++) {
      var Ee = Emin + Math.pow(rho[e], penal) * (E0 - Emin);
      var base = e * 8;
      for (var ii = 0; ii < 8; ii++) {
        diagK[edof[base + ii]] += Ee * KE[ii * 8 + ii];
      }
    }
    for (var f = 0; f < fixedDofs.length; f++) {
      diagK[fixedDofs[f]] += 1e9;
    }
    // Invert for preconditioner
    for (var i2 = 0; i2 < nDof; i2++) {
      diagK[i2] = diagK[i2] > 1e-30 ? 1 / diagK[i2] : 0;
    }
  }

  var cg_r = new Float64Array(nDof);
  var cg_z = new Float64Array(nDof);
  var cg_p = new Float64Array(nDof);
  var cg_Ap = new Float64Array(nDof);

  function solveCG(maxIter, tol) {
    // Apply large-number method to RHS as well
    assembleForce();
    for (var f = 0; f < fixedDofs.length; f++) {
      var d = fixedDofs[f];
      F[d] = 0; // fixed DOFs: displacement = 0
    }

    buildDiagPrecond();

    // Initial guess: use previous U
    matVecProduct(U, cg_r);
    for (var i = 0; i < nDof; i++) cg_r[i] = F[i] - cg_r[i];

    // z = M^-1 * r
    var rz = 0;
    for (var i2 = 0; i2 < nDof; i2++) {
      cg_z[i2] = diagK[i2] * cg_r[i2];
      rz += cg_r[i2] * cg_z[i2];
    }

    for (var i3 = 0; i3 < nDof; i3++) cg_p[i3] = cg_z[i3];

    for (var iter = 0; iter < maxIter; iter++) {
      matVecProduct(cg_p, cg_Ap);

      var pAp = 0;
      for (var i4 = 0; i4 < nDof; i4++) pAp += cg_p[i4] * cg_Ap[i4];

      if (pAp < 1e-30) break;
      var alpha = rz / pAp;

      var rz_new = 0;
      for (var i5 = 0; i5 < nDof; i5++) {
        U[i5] += alpha * cg_p[i5];
        cg_r[i5] -= alpha * cg_Ap[i5];
        cg_z[i5] = diagK[i5] * cg_r[i5];
        rz_new += cg_r[i5] * cg_z[i5];
      }

      if (Math.sqrt(Math.abs(rz_new)) < tol) break;

      var beta = rz_new / rz;
      for (var i6 = 0; i6 < nDof; i6++) {
        cg_p[i6] = cg_z[i6] + beta * cg_p[i6];
      }
      rz = rz_new;
    }
  }

  /* ── Compute element compliance sensitivities ── */
  var ue_sens = new Float64Array(8);
  function computeSensitivities() {
    for (var e = 0; e < nEl; e++) {
      var base = e * 8;
      // Gather element displacements
      for (var ii = 0; ii < 8; ii++) ue_sens[ii] = U[edof[base + ii]];

      // u^T * KE * u
      var uKu = 0;
      for (var ii2 = 0; ii2 < 8; ii2++) {
        var s = 0;
        var row = ii2 * 8;
        for (var jj = 0; jj < 8; jj++) s += KE[row + jj] * ue_sens[jj];
        uKu += ue_sens[ii2] * s;
      }

      dc[e] = -penal * Math.pow(rho[e], penal - 1) * (E0 - Emin) * uKu;
    }
  }

  /* ── Sensitivity filter ── */
  function filterSensitivities() {
    for (var e = 0; e < nEl; e++) {
      var neighbors = filterNeighbors[e];
      var sumHxDc = 0;
      var sumH = 0;
      for (var k = 0; k < neighbors.length; k++) {
        var nb = neighbors[k];
        sumHxDc += nb.weight * rho[nb.idx] * dc[nb.idx];
        sumH += nb.weight;
      }
      dcFiltered[e] = sumHxDc / (rho[e] * sumH);
    }
  }

  /* ── OC update ── */
  var rhoNew = new Float64Array(nEl);
  function ocUpdate() {
    var l1 = 0, l2 = 1e9;
    var move = moveLimit;

    while (l2 - l1 > 1e-9) {
      var lmid = 0.5 * (l1 + l2);
      var vol = 0;
      for (var e = 0; e < nEl; e++) {
        var Be = Math.sqrt(-dcFiltered[e] / lmid);
        var xnew = rho[e] * Be;
        var lo = Math.max(0.001, rho[e] - move);
        var hi = Math.min(1.0, rho[e] + move);
        if (xnew < lo) xnew = lo;
        if (xnew > hi) xnew = hi;
        rhoNew[e] = xnew;
        vol += xnew;
      }
      if (vol / nEl > volFrac) l1 = lmid; else l2 = lmid;
    }

    var maxChange = 0;
    for (var e2 = 0; e2 < nEl; e2++) {
      var ch = Math.abs(rhoNew[e2] - rho[e2]);
      if (ch > maxChange) maxChange = ch;
      rho[e2] = rhoNew[e2];
    }
    return maxChange;
  }

  /* ── Compute compliance ── */
  function computeCompliance() {
    var c = 0;
    for (var i = 0; i < nDof; i++) c += F[i] * U[i];
    return c;
  }

  /* ── Optimization state ── */
  var iteration = 0;
  var converged = false;
  var compliance = 0;
  var running = true;

  function initDensity() {
    for (var e = 0; e < nEl; e++) rho[e] = volFrac;
    for (var i = 0; i < nDof; i++) U[i] = 0;
    iteration = 0;
    converged = false;
    compliance = 0;
    particles = [];
  }

  function restart() {
    setupPreset(currentPreset);
    initDensity();
    running = true;
  }

  /* ── Particles (post-convergence) ── */
  var particles = [];
  var maxParticles = 80;

  function spawnParticle() {
    // Spawn from load points, drift toward supports
    var allLoads2 = loads.concat(userLoads);
    if (allLoads2.length === 0) return;
    var ld = allLoads2[(Math.random() * allLoads2.length) | 0];
    var nx = ld.node % (NEX + 1);
    var ny = (ld.node / (NEX + 1)) | 0;

    particles.push({
      x: nx, y: ny,
      vx: (Math.random() - 0.5) * 0.3,
      vy: (Math.random() - 0.5) * 0.3,
      life: 1.0,
      decay: 0.003 + Math.random() * 0.004
    });
  }

  function updateParticles() {
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      // Follow density gradient
      var gx = Math.round(p.x);
      var gy = Math.round(p.y);
      if (gx >= 0 && gx < NEX && gy >= 0 && gy < NEY) {
        var eIdx = gy * NEX + gx;
        // Drift toward higher density neighbors
        var dxGrad = 0, dyGrad = 0;
        if (gx > 0) dxGrad -= rho[eIdx] - rho[eIdx - 1];
        if (gx < NEX - 1) dxGrad += rho[eIdx + 1] - rho[eIdx];
        if (gy > 0) dyGrad -= rho[eIdx] - rho[eIdx - NEX];
        if (gy < NEY - 1) dyGrad += rho[eIdx + NEX] - rho[eIdx];

        p.vx += dxGrad * 0.05;
        p.vy += dyGrad * 0.05;
      }

      p.vx *= 0.97;
      p.vy *= 0.97;
      p.x += p.vx;
      p.y += p.vy;
      p.life -= p.decay;

      if (p.life <= 0 || p.x < -1 || p.x > NEX + 1 || p.y < -1 || p.y > NEY + 1) {
        particles.splice(i, 1);
      }
    }
    if (particles.length < maxParticles && Math.random() < 0.15) {
      spawnParticle();
    }
  }

  /* ── Rendering ── */
  var offCanvas = document.createElement('canvas');
  offCanvas.width = NEX;
  offCanvas.height = NEY;
  var offCtx = offCanvas.getContext('2d');
  var imgData = offCtx.createImageData(NEX, NEY);
  var pixData = imgData.data;

  // Domain layout
  var domX, domY, elemSize;

  function calcLayout() {
    var margin = 80;
    var availW = W / dpr - margin * 2;
    var availH = H / dpr - margin * 2;
    elemSize = Math.min(availW / NEX, availH / NEY);
    if (elemSize < 2) elemSize = 2;
    var domW = NEX * elemSize;
    var domH = NEY * elemSize;
    domX = (W / dpr - domW) / 2;
    domY = (H / dpr - domH) / 2;
  }

  function renderDensity() {
    for (var ey = 0; ey < NEY; ey++) {
      for (var ex = 0; ex < NEX; ex++) {
        var e = ey * NEX + ex;
        var d = rho[e];
        // Flip Y: ey=0 is bottom in physical space, but top of image for screen
        var imgY = NEY - 1 - ey;
        var off = (imgY * NEX + ex) * 4;
        pixData[off]     = 0;
        pixData[off + 1] = Math.round(245 * d);
        pixData[off + 2] = Math.round(212 * d);
        var a = Math.round(255 * d * d * 1.5);
        if (a > 255) a = 255;
        pixData[off + 3] = a;
      }
    }
    offCtx.putImageData(imgData, 0, 0);

    ctx.save();
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(offCanvas, domX * dpr, domY * dpr, NEX * elemSize * dpr, NEY * elemSize * dpr);
    ctx.restore();
  }

  function drawDomainOutline() {
    ctx.save();
    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 1;
    ctx.strokeRect(domX * dpr, domY * dpr, NEX * elemSize * dpr, NEY * elemSize * dpr);
    ctx.restore();
  }

  // Convert grid node to screen coordinates (node at physical position)
  function nodeToScreen(node) {
    var nx = node % (NEX + 1);
    var ny = (node / (NEX + 1)) | 0;
    // ey=0 is bottom in physical, screen Y increases downward
    var sx = domX + nx * elemSize;
    var sy = domY + (NEY - ny) * elemSize;
    return { x: sx * dpr, y: sy * dpr };
  }

  function drawSupports() {
    ctx.save();
    var triSize = Math.max(4, elemSize * 0.5) * dpr;
    ctx.fillStyle = 'rgba(123,44,191,0.7)';

    // Collect unique fixed nodes
    var fixedNodes = {};
    for (var i = 0; i < fixedDofs.length; i++) {
      fixedNodes[(fixedDofs[i] / 2) | 0] = true;
    }
    for (var nodeId in fixedNodes) {
      var pos = nodeToScreen(parseInt(nodeId));
      ctx.beginPath();
      ctx.moveTo(pos.x, pos.y);
      ctx.lineTo(pos.x - triSize * 0.5, pos.y + triSize);
      ctx.lineTo(pos.x + triSize * 0.5, pos.y + triSize);
      ctx.closePath();
      ctx.fill();
    }
    ctx.restore();
  }

  function drawLoads() {
    ctx.save();
    var arrowLen = Math.max(10, elemSize * 1.5) * dpr;
    var arrowHead = arrowLen * 0.35;
    ctx.strokeStyle = 'rgba(181,140,255,0.9)';
    ctx.fillStyle = 'rgba(181,140,255,0.9)';
    ctx.lineWidth = 2 * dpr;

    var allLoads3 = loads.concat(userLoads);
    for (var i = 0; i < allLoads3.length; i++) {
      var ld = allLoads3[i];
      var pos = nodeToScreen(ld.node);
      var dx = ld.fx;
      var dy = -ld.fy; // screen Y is flipped
      var mag = Math.sqrt(dx * dx + dy * dy);
      if (mag < 1e-10) continue;
      dx /= mag; dy /= mag;

      var startX = pos.x - dx * arrowLen;
      var startY = pos.y - dy * arrowLen;

      ctx.beginPath();
      ctx.moveTo(startX, startY);
      ctx.lineTo(pos.x, pos.y);
      ctx.stroke();

      // Arrowhead
      var ax = -dx, ay = -dy;
      var px = -ay, py = ax;
      ctx.beginPath();
      ctx.moveTo(pos.x, pos.y);
      ctx.lineTo(pos.x + (ax + px * 0.4) * arrowHead, pos.y + (ay + py * 0.4) * arrowHead);
      ctx.lineTo(pos.x + (ax - px * 0.4) * arrowHead, pos.y + (ay - py * 0.4) * arrowHead);
      ctx.closePath();
      ctx.fill();
    }
    ctx.restore();
  }

  function drawParticles() {
    if (!converged) return;
    updateParticles();
    ctx.save();
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      // Convert to screen
      var sx = (domX + p.x * elemSize) * dpr;
      var sy = (domY + (NEY - p.y) * elemSize) * dpr;
      var alpha = p.life * 0.6;
      var r = (1.5 + p.life) * dpr;
      ctx.beginPath();
      ctx.arc(sx, sy, r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,' + alpha.toFixed(3) + ')';
      ctx.fill();
    }
    ctx.restore();
  }

  function render() {
    ctx.clearRect(0, 0, W, H);
    renderDensity();
    drawDomainOutline();
    drawSupports();
    drawLoads();
    drawParticles();
  }

  /* ── HUD update ── */
  var elIter = document.getElementById('h-iter');
  var elComp = document.getElementById('h-comp');
  var elVol = document.getElementById('h-vol');

  function updateHUD() {
    elIter.textContent = iteration;
    elComp.textContent = compliance > 0 ? compliance.toFixed(2) : '—';
    var vol = 0;
    for (var e = 0; e < nEl; e++) vol += rho[e];
    elVol.textContent = (vol / nEl).toFixed(3);
  }

  /* ── Resize ── */
  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth * dpr;
    H = window.innerHeight * dpr;
    C.width = W;
    C.height = H;
    C.style.width = window.innerWidth + 'px';
    C.style.height = window.innerHeight + 'px';
    calcLayout();
  }
  window.addEventListener('resize', resize);

  /* ── Main loop ── */
  var sfxTickLast = 0;

  function step() {
    requestAnimationFrame(step);

    if (running && !converged) {
      // One optimization iteration
      solveCG(500, 1e-6);
      computeSensitivities();
      filterSensitivities();
      var maxChange = ocUpdate();
      iteration++;
      compliance = computeCompliance();

      // SFX tick every ~10 iterations
      var now = Date.now();
      if (iteration % 10 === 0 && now - sfxTickLast > 200) {
        SFX.play('tick', 200);
        sfxTickLast = now;
      }

      if (maxChange < 0.01 && iteration > 10) {
        converged = true;
        SFX.play('complete');
      }
    }

    render();
    updateHUD();
  }

  /* ── Interaction: click to add load ── */
  function screenToNode(sx, sy) {
    // sx, sy in CSS pixels
    var gx = (sx - domX) / elemSize;
    var gy = NEY - (sy - domY) / elemSize; // flip Y
    var ix = Math.round(gx);
    var iy = Math.round(gy);
    if (ix < 0) ix = 0; if (ix > NEX) ix = NEX;
    if (iy < 0) iy = 0; if (iy > NEY) iy = NEY;
    return iy * (NEX + 1) + ix;
  }

  var dragStart = null;
  var dragNode = -1;

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return; // right-click handled separately
    e.preventDefault();
    var rect = C.getBoundingClientRect();
    var sx = e.clientX - rect.left;
    var sy = e.clientY - rect.top;
    dragNode = screenToNode(sx, sy);
    dragStart = { x: e.clientX, y: e.clientY };
  });

  C.addEventListener('pointerup', function(e) {
    if (e.button === 2) return;
    if (dragNode < 0 || !dragStart) { dragNode = -1; dragStart = null; return; }
    var dx = e.clientX - dragStart.x;
    var dy = e.clientY - dragStart.y;
    var dist = Math.sqrt(dx * dx + dy * dy);

    var fx = 0, fy = 0;
    if (dist < 10) {
      // Simple click: downward load
      fy = -1;
    } else {
      // Drag: force in drag direction (screen to physical)
      var mag = dist;
      fx = dx / mag;
      fy = -(dy / mag); // flip Y for physical
    }

    // Toggle: if same node already has a user load, remove it
    var found = -1;
    for (var i = 0; i < userLoads.length; i++) {
      if (userLoads[i].node === dragNode) { found = i; break; }
    }
    if (found >= 0 && dist < 10) {
      userLoads.splice(found, 1);
      SFX.play('pop');
    } else {
      if (found >= 0) userLoads.splice(found, 1);
      userLoads.push({ node: dragNode, fx: fx, fy: fy });
      SFX.play('spawn');
    }

    // Restart optimization with current density as starting point
    converged = false;
    iteration = 0;
    particles = [];
    running = true;

    dragNode = -1;
    dragStart = null;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    userLoads = [];
    SFX.play('click');
    restart();
  });

  /* ── Touch support ── */
  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) {
      e.preventDefault();
      var t = e.touches[0];
      var rect = C.getBoundingClientRect();
      dragNode = screenToNode(t.clientX - rect.left, t.clientY - rect.top);
      dragStart = { x: t.clientX, y: t.clientY };
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (dragNode < 0 || !dragStart) return;
    var t = e.changedTouches[0];
    var dx = t.clientX - dragStart.x;
    var dy = t.clientY - dragStart.y;
    var dist = Math.sqrt(dx * dx + dy * dy);

    var fx = 0, fy = 0;
    if (dist < 10) {
      fy = -1;
    } else {
      var mag = dist;
      fx = dx / mag;
      fy = -(dy / mag);
    }

    var found = -1;
    for (var i = 0; i < userLoads.length; i++) {
      if (userLoads[i].node === dragNode) { found = i; break; }
    }
    if (found >= 0 && dist < 10) {
      userLoads.splice(found, 1);
      SFX.play('pop');
    } else {
      if (found >= 0) userLoads.splice(found, 1);
      userLoads.push({ node: dragNode, fx: fx, fy: fy });
      SFX.play('spawn');
    }

    converged = false;
    iteration = 0;
    particles = [];
    running = true;
    dragNode = -1;
    dragStart = null;
  });

  /* ── Controls ── */
  var slVol = document.getElementById('sl-vol');
  var svVol = document.getElementById('sv-vol');
  slVol.addEventListener('input', function() {
    volFrac = parseInt(slVol.value) / 100;
    svVol.textContent = volFrac.toFixed(2);
    initDensity();
    running = true;
  });

  var presetBtns = document.querySelectorAll('[data-preset]');
  for (var b = 0; b < presetBtns.length; b++) {
    presetBtns[b].addEventListener('click', (function(btn) {
      return function() {
        for (var i = 0; i < presetBtns.length; i++) presetBtns[i].classList.remove('active');
        btn.classList.add('active');
        currentPreset = btn.getAttribute('data-preset');
        userLoads = [];
        SFX.play('toggle');
        restart();
      };
    })(presetBtns[b]));
  }

  document.getElementById('btn-reset').addEventListener('click', function() {
    SFX.play('click');
    userLoads = [];
    restart();
  });

  /* ── Init ── */
  resize();
  setupPreset('cantilever');
  initDensity();
  step();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
