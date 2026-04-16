<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hyperbolic');

$pageName = 'hyperbolic';
$pageTitle = 'HYPERBOLIC - PULSE';
$pageDesc = 'ポアンカレ円盤モデル。双曲幾何学のタイリングが無限に広がる非ユークリッドの世界。';
$pageCanonical = 'https://pulse.subara3.com/hyperbolic.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:grab}
#canvas:active{cursor:grabbing}
.ui{position:fixed;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none}
.hud{top:1rem;right:1rem;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:0.6rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud">
  {<span id="hudP" class="v">5</span>,<span id="hudQ" class="v">4</span>}<br>
  TILES: <span id="hudN" class="v">0</span>
</div>

<div id="info">
  Schl&auml;fli {<span class="v" id="infoP">5</span>,<span class="v" id="infoQ">4</span>}<br>
  <span class="v" id="infoDesc">pentagons &middot; 4 at vertex</span>
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'navigate'],
  ['type' => 'wheel', 'label' => 'rotate'],
  ['type' => 'click', 'label' => 'highlight'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">TILING</label>
    <div class="btn-row" id="tBtns">
      <button class="control-btn active" data-p="5" data-q="4">{5,4}</button>
      <button class="control-btn" data-p="4" data-q="5">{4,5}</button>
      <button class="control-btn" data-p="7" data-q="3">{7,3}</button>
      <button class="control-btn" data-p="3" data-q="7">{3,7}</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">COLOR</label>
    <div class="btn-row" id="cBtns">
      <button class="control-btn active" data-m="0">DUAL</button>
      <button class="control-btn" data-m="1">DEPTH</button>
      <button class="control-btn" data-m="2">WIRE</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">SPEED</label>
      <span class="slider-value" id="spdVal">1.0</span>
    </div>
    <input type="range" id="spdSlider" min="0" max="40" step="1" value="10">
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="animBtn">ROTATE: ON</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d', { alpha: false });
  var W, H, dR, dCx, dCy;

  var P = 5, Q = 4;
  var tiles = [];
  var colorMode = 0;
  var ang = 0, autoRot = true, rotSpd = 0.001;
  var wx = 0, wy = 0;
  var dragging = false, lmx = 0, lmy = 0, dragDist = 0;
  var flash = -1, flashT = 0;
  var isMob = window.innerWidth < 768 || ('ontouchstart' in window);
  var maxT = isMob ? 800 : 1500;

  var descs = {
    '5,4': 'pentagons \u00b7 4 at vertex',
    '4,5': 'squares \u00b7 5 at vertex',
    '7,3': 'heptagons \u00b7 3 at vertex',
    '3,7': 'triangles \u00b7 7 at vertex'
  };

  var _acosh = Math.acosh || function(x) { return Math.log(x + Math.sqrt(x * x - 1)); };
  var _tanh = Math.tanh || function(x) { var e = Math.exp(2 * x); return (e - 1) / (e + 1); };

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    dR = Math.min(W, H) * 0.44;
    dCx = W / 2;
    dCy = H / 2;
  }

  function geoC(x1, y1, x2, y2) {
    var a1 = 2 * x1, b1 = 2 * y1, c1 = 1 + x1 * x1 + y1 * y1;
    var a2 = 2 * x2, b2 = 2 * y2, c2 = 1 + x2 * x2 + y2 * y2;
    var det = a1 * b2 - a2 * b1;
    if (det * det < 1e-18) return null;
    var cx = (c1 * b2 - c2 * b1) / det;
    var cy = (a1 * c2 - a2 * c1) / det;
    var dx = cx - x1, dy = cy - y1;
    return [cx, cy, Math.sqrt(dx * dx + dy * dy)];
  }

  function reflP(px, py, gcx, gcy, gr2) {
    var dx = px - gcx, dy = py - gcy;
    var d2 = dx * dx + dy * dy;
    if (d2 < 1e-20) return [px, py];
    var s = gr2 / d2;
    return [gcx + s * dx, gcy + s * dy];
  }

  function mobT(zx, zy, ax, ay) {
    var nx = zx - ax, ny = zy - ay;
    var cr = ax * zx + ay * zy;
    var ci = ax * zy - ay * zx;
    var dr = 1 - cr, di = -ci;
    var dd = dr * dr + di * di;
    return [(nx * dr + ny * di) / dd, (ny * dr - nx * di) / dd];
  }

  function xform(px, py) {
    var zx = px, zy = py;
    if (wx !== 0 || wy !== 0) {
      var m = mobT(px, py, wx, wy);
      zx = m[0]; zy = m[1];
    }
    if (ang !== 0) {
      var c = Math.cos(ang), s = Math.sin(ang);
      var rx = zx * c - zy * s;
      zy = zx * s + zy * c;
      zx = rx;
    }
    return [zx, zy];
  }

  function genTiling() {
    var cosP = Math.cos(Math.PI / P);
    var sinQ = Math.sin(Math.PI / Q);
    var R = _acosh(cosP / sinQ);
    var r = _tanh(R / 2);

    var cvx = new Float64Array(P), cvy = new Float64Array(P);
    for (var k = 0; k < P; k++) {
      var a = 2 * Math.PI * k / P - Math.PI / 2;
      cvx[k] = r * Math.cos(a);
      cvy[k] = r * Math.sin(a);
    }

    tiles = [];
    var vis = {};
    var q = [];
    var qi = 0;

    function hsh(vx, vy) {
      var cx = 0, cy = 0;
      for (var i = 0; i < P; i++) { cx += vx[i]; cy += vy[i]; }
      return (Math.round(cx / P * 1000) | 0) + ',' + (Math.round(cy / P * 1000) | 0);
    }

    function add(vx, vy, depth, orient) {
      var key = hsh(vx, vy);
      if (vis[key]) return;
      vis[key] = true;
      var cx = 0, cy = 0;
      for (var i = 0; i < P; i++) { cx += vx[i]; cy += vy[i]; }
      cx /= P; cy /= P;
      if (cx * cx + cy * cy > 0.97) return;
      tiles.push({ vx: new Float64Array(vx), vy: new Float64Array(vy), d: depth, o: orient });
      q.push(tiles.length - 1);
    }

    add(cvx, cvy, 0, 0);

    while (qi < q.length && tiles.length < maxT) {
      var t = tiles[q[qi++]];
      for (var i = 0; i < P; i++) {
        var j = (i + 1) % P;
        var geo = geoC(t.vx[i], t.vy[i], t.vx[j], t.vy[j]);
        if (!geo) continue;
        var gr2 = geo[2] * geo[2];
        var nvx = new Float64Array(P), nvy = new Float64Array(P);
        for (var k = 0; k < P; k++) {
          var ref = reflP(t.vx[k], t.vy[k], geo[0], geo[1], gr2);
          nvx[P - 1 - k] = ref[0];
          nvy[P - 1 - k] = ref[1];
        }
        add(nvx, nvy, t.d + 1, 1 - t.o);
      }
    }

    document.getElementById('hudP').textContent = P;
    document.getElementById('hudQ').textContent = Q;
    document.getElementById('hudN').textContent = tiles.length;
    document.getElementById('infoP').textContent = P;
    document.getElementById('infoQ').textContent = Q;
    document.getElementById('infoDesc').textContent = descs[P + ',' + Q] || '';
    flash = -1;
  }

  function addTPath(idx, txA, tyA) {
    var tx = txA[idx], ty = tyA[idx];
    var mnx = tx[0], mxx = tx[0], mny = ty[0], mxy = ty[0];
    for (var j = 1; j < P; j++) {
      if (tx[j] < mnx) mnx = tx[j]; if (tx[j] > mxx) mxx = tx[j];
      if (ty[j] < mny) mny = ty[j]; if (ty[j] > mxy) mxy = ty[j];
    }
    var sz = Math.max(mxx - mnx, mxy - mny) * dR;
    if (sz < 0.5) return false;

    var segs = sz < 4 ? 1 : sz < 12 ? 3 : sz < 40 ? 5 : 8;
    ctx.moveTo(dCx + tx[0] * dR, dCy - ty[0] * dR);

    for (var e = 0; e < P; e++) {
      var e1 = (e + 1) % P;
      var x1 = tx[e], y1 = ty[e], x2 = tx[e1], y2 = ty[e1];

      if (segs <= 1) {
        ctx.lineTo(dCx + x2 * dR, dCy - y2 * dR);
        continue;
      }

      var geo = geoC(x1, y1, x2, y2);
      if (!geo) {
        ctx.lineTo(dCx + x2 * dR, dCy - y2 * dR);
        continue;
      }

      var a1 = Math.atan2(y1 - geo[1], x1 - geo[0]);
      var a2 = Math.atan2(y2 - geo[1], x2 - geo[0]);
      var diff = a2 - a1;
      if (diff > Math.PI) diff -= 2 * Math.PI;
      if (diff < -Math.PI) diff += 2 * Math.PI;

      var midA = a1 + diff / 2;
      var mx = geo[0] + geo[2] * Math.cos(midA);
      var my = geo[1] + geo[2] * Math.sin(midA);
      if (mx * mx + my * my > 1.001) {
        diff = diff > 0 ? diff - 2 * Math.PI : diff + 2 * Math.PI;
      }

      for (var s = 1; s <= segs; s++) {
        var aa = a1 + (s / segs) * diff;
        var px = geo[0] + geo[2] * Math.cos(aa);
        var py = geo[1] + geo[2] * Math.sin(aa);
        ctx.lineTo(dCx + px * dR, dCy - py * dR);
      }
    }
    ctx.closePath();
    return true;
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var g = ctx.createRadialGradient(dCx, dCy, dR * 0.88, dCx, dCy, dR * 1.06);
    g.addColorStop(0, 'rgba(0,245,212,0)');
    g.addColorStop(0.5, 'rgba(0,245,212,0.04)');
    g.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(dCx, dCy, dR * 1.06, 0, Math.PI * 2);
    ctx.fill();

    ctx.beginPath();
    ctx.arc(dCx, dCy, dR, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0,245,212,0.15)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    ctx.save();
    ctx.beginPath();
    ctx.arc(dCx, dCy, dR - 0.5, 0, Math.PI * 2);
    ctx.clip();

    var txA = [], tyA = [];
    var maxD = 0;
    for (var i = 0; i < tiles.length; i++) {
      var t = tiles[i];
      if (t.d > maxD) maxD = t.d;
      var tx = new Float64Array(P), ty = new Float64Array(P);
      for (var j = 0; j < P; j++) {
        var p = xform(t.vx[j], t.vy[j]);
        tx[j] = p[0]; ty[j] = p[1];
      }
      txA.push(tx); tyA.push(ty);
    }

    if (colorMode === 0) {
      ctx.beginPath();
      for (var i = 0; i < tiles.length; i++) { if (tiles[i].o === 0) addTPath(i, txA, tyA); }
      ctx.fillStyle = 'rgba(0,200,180,0.1)';
      ctx.fill();

      ctx.beginPath();
      for (var i = 0; i < tiles.length; i++) { if (tiles[i].o === 1) addTPath(i, txA, tyA); }
      ctx.fillStyle = 'rgba(110,40,180,0.1)';
      ctx.fill();

      ctx.beginPath();
      for (var i = 0; i < tiles.length; i++) addTPath(i, txA, tyA);
      ctx.strokeStyle = 'rgba(0,245,212,0.35)';
      ctx.lineWidth = 0.6;
      ctx.stroke();

    } else if (colorMode === 1) {
      for (var d = maxD; d >= 0; d--) {
        var tt = maxD > 0 ? d / maxD : 0;
        var hue = Math.round(172 + tt * 106);
        var lit = Math.round(48 - tt * 14);
        ctx.beginPath();
        for (var i = 0; i < tiles.length; i++) { if (tiles[i].d === d) addTPath(i, txA, tyA); }
        ctx.fillStyle = 'hsla(' + hue + ',75%,' + lit + '%,0.25)';
        ctx.fill();
      }
      ctx.beginPath();
      for (var i = 0; i < tiles.length; i++) addTPath(i, txA, tyA);
      ctx.strokeStyle = 'rgba(0,245,212,0.2)';
      ctx.lineWidth = 0.4;
      ctx.stroke();

    } else {
      ctx.beginPath();
      for (var i = 0; i < tiles.length; i++) addTPath(i, txA, tyA);
      ctx.strokeStyle = 'rgba(0,245,212,0.5)';
      ctx.lineWidth = 0.7;
      ctx.stroke();
    }

    if (flash >= 0 && flash < tiles.length && flashT > 0) {
      ctx.beginPath();
      addTPath(flash, txA, tyA);
      ctx.fillStyle = 'rgba(0,245,212,' + (flashT * 0.4).toFixed(3) + ')';
      ctx.fill();
      ctx.strokeStyle = 'rgba(0,245,212,' + (flashT * 0.8).toFixed(3) + ')';
      ctx.lineWidth = 2;
      ctx.stroke();
      flashT -= 0.02;
      if (flashT <= 0) flash = -1;
    }

    var dg = ctx.createRadialGradient(dCx, dCy, dR * 0.85, dCx, dCy, dR);
    dg.addColorStop(0, 'rgba(0,0,0,0)');
    dg.addColorStop(1, 'rgba(10,10,15,0.5)');
    ctx.fillStyle = dg;
    ctx.beginPath();
    ctx.arc(dCx, dCy, dR, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
  }

  function loop() {
    if (autoRot) ang += rotSpd;
    render();
    requestAnimationFrame(loop);
  }

  var animBtn = document.getElementById('animBtn');

  function dStart(x, y) {
    dragging = true; lmx = x; lmy = y; dragDist = 0;
  }

  function dMove(x, y) {
    if (!dragging) return;
    var ddx = x - lmx, ddy = y - lmy;
    dragDist += Math.abs(ddx) + Math.abs(ddy);

    if (dragDist > 5 && autoRot) {
      autoRot = false;
      animBtn.textContent = 'ROTATE: OFF';
      animBtn.classList.remove('active');
    }

    var dx = ddx / dR, dy = -ddy / dR;
    var c = Math.cos(-ang), s = Math.sin(-ang);
    var rx = dx * c - dy * s, ry = dx * s + dy * c;
    var hx = -rx * 0.7, hy = -ry * 0.7;

    var cr = hx * wx + hy * wy, ci = hx * wy - hy * wx;
    var dr = 1 + cr, di = ci, dd = dr * dr + di * di;
    var nr = wx + hx, ni = wy + hy;
    wx = (nr * dr + ni * di) / dd;
    wy = (ni * dr - nr * di) / dd;

    var ww = wx * wx + wy * wy;
    if (ww > 0.88) { var sc = Math.sqrt(0.88 / ww); wx *= sc; wy *= sc; }

    lmx = x; lmy = y;
    if (typeof SFX !== 'undefined') SFX.play('move', 150);
  }

  function dEnd() { dragging = false; }

  function handleTap(sx, sy) {
    if (dragDist > 10) return;
    var px = (sx - dCx) / dR, py = -(sy - dCy) / dR;
    if (px * px + py * py > 1) return;

    var best = -1, bd = 1;
    for (var i = 0; i < tiles.length; i++) {
      var cx = 0, cy = 0;
      for (var j = 0; j < P; j++) {
        var p = xform(tiles[i].vx[j], tiles[i].vy[j]);
        cx += p[0]; cy += p[1];
      }
      cx /= P; cy /= P;
      var dd = (px - cx) * (px - cx) + (py - cy) * (py - cy);
      if (dd < bd) { bd = dd; best = i; }
    }
    if (best >= 0) {
      flash = best; flashT = 1;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }

  C.addEventListener('mousedown', function(e) { if (e.button === 0) dStart(e.clientX, e.clientY); });
  C.addEventListener('mousemove', function(e) { dMove(e.clientX, e.clientY); });
  C.addEventListener('mouseup', function(e) { handleTap(e.clientX, e.clientY); dEnd(); });
  C.addEventListener('mouseleave', dEnd);

  var touchActive = false;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault(); touchActive = true;
    dStart(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    dMove(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    handleTap(lmx, lmy); dEnd();
    setTimeout(function() { touchActive = false; }, 300);
  });
  C.addEventListener('click', function(e) { if (touchActive) e.preventDefault(); });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    ang += e.deltaY * 0.002;
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  }, { passive: false });

  var tBtns = document.querySelectorAll('#tBtns .control-btn');
  var cBtns = document.querySelectorAll('#cBtns .control-btn');
  var resetBtn = document.getElementById('resetBtn');
  var spdSlider = document.getElementById('spdSlider');
  var spdVal = document.getElementById('spdVal');

  for (var i = 0; i < tBtns.length; i++) {
    tBtns[i].addEventListener('click', function() {
      var np = parseInt(this.dataset.p), nq = parseInt(this.dataset.q);
      if (np === P && nq === Q) return;
      P = np; Q = nq; wx = 0; wy = 0; ang = 0;
      for (var j = 0; j < tBtns.length; j++) tBtns[j].classList.remove('active');
      this.classList.add('active');
      genTiling();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  for (var i = 0; i < cBtns.length; i++) {
    cBtns[i].addEventListener('click', function() {
      colorMode = parseInt(this.dataset.m);
      for (var j = 0; j < cBtns.length; j++) cBtns[j].classList.remove('active');
      this.classList.add('active');
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  animBtn.addEventListener('click', function() {
    autoRot = !autoRot;
    this.textContent = 'ROTATE: ' + (autoRot ? 'ON' : 'OFF');
    if (autoRot) this.classList.add('active'); else this.classList.remove('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    wx = 0; wy = 0; ang = 0; autoRot = true; rotSpd = 0.001;
    spdSlider.value = 10; spdVal.textContent = '1.0';
    animBtn.textContent = 'ROTATE: ON'; animBtn.classList.add('active');
    flash = -1;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  spdSlider.addEventListener('input', function() {
    rotSpd = parseFloat(this.value) / 10000;
    spdVal.textContent = (this.value / 10).toFixed(1);
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  });

  window.addEventListener('resize', resize);
  resize();
  genTiling();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
