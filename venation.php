<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('venation');

$pageName = 'venation';
$pageTitle = 'VENATION - PULSE';
$pageDesc = '空間植民化アルゴリズム。誘引点に向かって枝が伸び、葉脈のような分岐構造が自己組織的に育つ。';
$pageCanonical = 'https://pulse.subara3.com/venation.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  NODES <span class="v" id="hud-nodes">0</span><br>
  ATTRACTORS <span class="v" id="hud-attr">0</span><br>
  ROOTS <span class="v" id="hud-roots">0</span>
</div>

<div id="info">
  <span class="v">Space Colonization</span><br>
  Runions et al. 2005
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'drag', 'label' => 'attract'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">BRANCH</span>
      <input type="range" id="sl-branch" min="3" max="16" value="7" step="1">
      <span class="slider-value" id="sv-branch">7</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">KILL</span>
      <input type="range" id="sl-kill" min="4" max="30" value="10" step="1">
      <span class="slider-value" id="sv-kill">10</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">INFLUENCE</span>
      <input type="range" id="sl-influence" min="30" max="200" value="80" step="5">
      <span class="slider-value" id="sv-influence">80</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="20" value="5" step="1">
      <span class="slider-value" id="sv-speed">5</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-scatter">SCATTER</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, dpr;

var nodes = [];
var attractors = [];
var roots = [];

var branchLen = 7;
var killDist = 10;
var influenceRad = 80;
var stepsPerFrame = 5;
var growing = true;

var hudNodes = document.getElementById('hud-nodes');
var hudAttr = document.getElementById('hud-attr');
var hudRoots = document.getElementById('hud-roots');

function resize() {
  dpr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth;
  H = window.innerHeight;
  C.width = W * dpr;
  C.height = H * dpr;
  C.style.width = W + 'px';
  C.style.height = H + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function Node(x, y, parent) {
  this.x = x;
  this.y = y;
  this.parent = parent;
  this.children = [];
  this.depth = parent ? parent.depth + 1 : 0;
  this.age = 0;
  this.influenceCount = 0;
  this.influenceDx = 0;
  this.influenceDy = 0;
}

function Attractor(x, y) {
  this.x = x;
  this.y = y;
  this.alive = true;
  this.age = 0;
}

function dist(a, b) {
  var dx = a.x - b.x, dy = a.y - b.y;
  return Math.sqrt(dx * dx + dy * dy);
}

function scatterAttractors(cx, cy, radius, count) {
  var added = 0;
  for (var i = 0; i < count; i++) {
    var angle = Math.random() * Math.PI * 2;
    var r = Math.sqrt(Math.random()) * radius;
    var ax = cx + Math.cos(angle) * r;
    var ay = cy + Math.sin(angle) * r;
    if (ax > 10 && ax < W - 10 && ay > 10 && ay < H - 10) {
      attractors.push(new Attractor(ax, ay));
      added++;
    }
  }
  return added;
}

function addRoot(x, y) {
  var n = new Node(x, y, null);
  nodes.push(n);
  roots.push(n);
  growing = true;
  return n;
}

function growStep() {
  if (!growing) return false;

  for (var i = 0; i < nodes.length; i++) {
    nodes[i].influenceCount = 0;
    nodes[i].influenceDx = 0;
    nodes[i].influenceDy = 0;
  }

  var anyInfluence = false;

  for (var i = attractors.length - 1; i >= 0; i--) {
    var a = attractors[i];
    if (!a.alive) continue;

    var closest = null;
    var closestDist = influenceRad;

    for (var j = 0; j < nodes.length; j++) {
      var d = dist(a, nodes[j]);
      if (d < killDist) {
        a.alive = false;
        break;
      }
      if (d < closestDist) {
        closestDist = d;
        closest = nodes[j];
      }
    }

    if (!a.alive) continue;

    if (closest) {
      var dx = a.x - closest.x;
      var dy = a.y - closest.y;
      var len = Math.sqrt(dx * dx + dy * dy);
      if (len > 0) {
        closest.influenceDx += dx / len;
        closest.influenceDy += dy / len;
        closest.influenceCount++;
        anyInfluence = true;
      }
    }
  }

  attractors = attractors.filter(function(a) { return a.alive; });

  if (!anyInfluence) {
    growing = false;
    return false;
  }

  var newNodes = [];
  for (var i = 0; i < nodes.length; i++) {
    var n = nodes[i];
    if (n.influenceCount > 0) {
      var dx = n.influenceDx / n.influenceCount;
      var dy = n.influenceDy / n.influenceCount;
      var len = Math.sqrt(dx * dx + dy * dy);
      if (len > 0) {
        dx = dx / len * branchLen;
        dy = dy / len * branchLen;
        var child = new Node(n.x + dx, n.y + dy, n);
        n.children.push(child);
        newNodes.push(child);
      }
    }
  }

  for (var i = 0; i < newNodes.length; i++) {
    nodes.push(newNodes[i]);
  }

  if (newNodes.length > 0 && typeof SFX !== 'undefined') {
    SFX.play('grow', 120);
  }

  return newNodes.length > 0;
}

function getThickness(node) {
  if (node.children.length === 0) return 0.6;
  var sum = 0;
  for (var i = 0; i < node.children.length; i++) {
    var ct = getThickness(node.children[i]);
    sum += ct * ct;
  }
  return Math.sqrt(sum) + 0.3;
}

var thicknessCache = new Map();
var cacheFrame = -1;

function getCachedThickness(node, frame) {
  if (frame !== cacheFrame) {
    thicknessCache.clear();
    cacheFrame = frame;
  }
  if (thicknessCache.has(node)) return thicknessCache.get(node);
  var t = getThickness(node);
  thicknessCache.set(node, t);
  return t;
}

var frameCount = 0;

function draw() {
  ctx.fillStyle = 'rgba(10,10,15,0.15)';
  ctx.fillRect(0, 0, W, H);

  if (frameCount % 4 === 0) {
    ctx.fillStyle = 'rgba(10,10,15,1)';
    ctx.fillRect(0, 0, W, H);

    for (var i = 0; i < attractors.length; i++) {
      var a = attractors[i];
      a.age++;
      var alpha = Math.min(a.age / 30, 0.5);
      ctx.beginPath();
      ctx.arc(a.x, a.y, 1.5, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(123,44,191,' + alpha + ')';
      ctx.fill();
    }

    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n.parent) continue;
      n.age++;
      var thick = getCachedThickness(n, frameCount);
      thick = Math.min(thick, 6);

      var depthRatio = Math.min(n.depth / 40, 1);
      var h = 170 + depthRatio * 20;
      var s = 55 + depthRatio * 15;
      var l = 40 + depthRatio * 15;

      var glow = Math.max(0, 1 - n.age / 30);
      if (glow > 0) {
        ctx.beginPath();
        ctx.moveTo(n.parent.x, n.parent.y);
        ctx.lineTo(n.x, n.y);
        ctx.strokeStyle = 'rgba(0,245,212,' + (glow * 0.3) + ')';
        ctx.lineWidth = thick + 4;
        ctx.lineCap = 'round';
        ctx.stroke();
      }

      ctx.beginPath();
      ctx.moveTo(n.parent.x, n.parent.y);
      ctx.lineTo(n.x, n.y);
      ctx.strokeStyle = 'hsl(' + h + ',' + s + '%,' + l + '%)';
      ctx.lineWidth = thick;
      ctx.lineCap = 'round';
      ctx.stroke();
    }

    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.children.length === 0 && n.age < 15) {
        var alpha = 0.6 * (1 - n.age / 15);
        ctx.beginPath();
        ctx.arc(n.x, n.y, 2.5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,245,212,' + alpha + ')';
        ctx.fill();
      }
    }
  }

  hudNodes.textContent = nodes.length;
  hudAttr.textContent = attractors.length;
  hudRoots.textContent = roots.length;
}

function initScene() {
  nodes = [];
  attractors = [];
  roots = [];
  thicknessCache.clear();
  growing = true;
  frameCount = 0;

  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  var margin = Math.min(W, H) * 0.08;
  var count = Math.floor(W * H / 800);
  count = Math.max(200, Math.min(count, 2000));

  for (var i = 0; i < count; i++) {
    var ax = margin + Math.random() * (W - margin * 2);
    var ay = margin + Math.random() * (H - margin * 2 - H * 0.15);
    attractors.push(new Attractor(ax, ay));
  }

  var numRoots = Math.max(1, Math.floor(W / 300));
  for (var i = 0; i < numRoots; i++) {
    var rx = W * (0.2 + 0.6 * (i / Math.max(numRoots - 1, 1)));
    if (numRoots === 1) rx = W * 0.5;
    addRoot(rx, H - margin);
  }
}

function loop() {
  frameCount++;
  if (growing) {
    for (var i = 0; i < stepsPerFrame; i++) {
      if (!growStep()) break;
    }
  }
  draw();
  requestAnimationFrame(loop);
}

resize();
initScene();
loop();

window.addEventListener('resize', function() {
  resize();
  initScene();
});

var isDown = false;
var lastDragX = 0, lastDragY = 0;
var isRightBtn = false;

function getPos(e) {
  if (e.touches) {
    return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  }
  return { x: e.clientX, y: e.clientY };
}

C.addEventListener('mousedown', function(e) {
  if (e.button === 2) {
    isRightBtn = true;
    var p = getPos(e);
    clearNear(p.x, p.y);
    return;
  }
  isDown = true;
  isRightBtn = false;
  var p = getPos(e);
  lastDragX = p.x;
  lastDragY = p.y;
  handleClick(p.x, p.y);
});

C.addEventListener('mousemove', function(e) {
  if (!isDown) return;
  var p = getPos(e);
  if (Math.abs(p.x - lastDragX) > 10 || Math.abs(p.y - lastDragY) > 10) {
    handleDrag(p.x, p.y);
    lastDragX = p.x;
    lastDragY = p.y;
  }
});

C.addEventListener('mouseup', function() {
  isDown = false;
  isRightBtn = false;
});

C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

var touchTimer = null;
var touchCount = 0;

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  if (e.touches.length === 2) {
    var p = getPos(e);
    clearNear(p.x, p.y);
    return;
  }
  isDown = true;
  var p = getPos(e);
  lastDragX = p.x;
  lastDragY = p.y;
  handleClick(p.x, p.y);
}, { passive: false });

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!isDown || e.touches.length > 1) return;
  var p = getPos(e);
  if (Math.abs(p.x - lastDragX) > 8 || Math.abs(p.y - lastDragY) > 8) {
    handleDrag(p.x, p.y);
    lastDragX = p.x;
    lastDragY = p.y;
  }
}, { passive: false });

C.addEventListener('touchend', function(e) {
  isDown = false;
});

function handleClick(x, y) {
  var n = scatterAttractors(x, y, 60, 30);
  if (nodes.length === 0 || roots.length === 0) {
    addRoot(x, y);
  }
  growing = true;
  if (typeof SFX !== 'undefined') SFX.play('spawn');
}

function handleDrag(x, y) {
  scatterAttractors(x, y, 25, 8);
  growing = true;
  if (typeof SFX !== 'undefined') SFX.play('tick', 80);
}

function clearNear(x, y) {
  var radius = 60;
  nodes = nodes.filter(function(n) {
    return dist(n, {x: x, y: y}) > radius;
  });
  for (var i = 0; i < nodes.length; i++) {
    nodes[i].children = nodes[i].children.filter(function(c) {
      return nodes.indexOf(c) !== -1;
    });
  }
  roots = roots.filter(function(r) { return nodes.indexOf(r) !== -1; });
  attractors = attractors.filter(function(a) {
    return dist(a, {x: x, y: y}) > radius;
  });
  if (typeof SFX !== 'undefined') SFX.play('pop');
}

var slBranch = document.getElementById('sl-branch');
var slKill = document.getElementById('sl-kill');
var slInfluence = document.getElementById('sl-influence');
var slSpeed = document.getElementById('sl-speed');
var svBranch = document.getElementById('sv-branch');
var svKill = document.getElementById('sv-kill');
var svInfluence = document.getElementById('sv-influence');
var svSpeed = document.getElementById('sv-speed');

slBranch.addEventListener('input', function() {
  branchLen = +this.value;
  svBranch.textContent = this.value;
});
slKill.addEventListener('input', function() {
  killDist = +this.value;
  svKill.textContent = this.value;
});
slInfluence.addEventListener('input', function() {
  influenceRad = +this.value;
  svInfluence.textContent = this.value;
});
slSpeed.addEventListener('input', function() {
  stepsPerFrame = +this.value;
  svSpeed.textContent = this.value;
});

document.getElementById('btn-scatter').addEventListener('click', function() {
  var count = Math.floor(W * H / 1200);
  count = Math.max(100, Math.min(count, 1000));
  var margin = Math.min(W, H) * 0.08;
  for (var i = 0; i < count; i++) {
    var ax = margin + Math.random() * (W - margin * 2);
    var ay = margin + Math.random() * (H - margin * 2);
    attractors.push(new Attractor(ax, ay));
  }
  growing = true;
  if (typeof SFX !== 'undefined') SFX.play('click');
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initScene();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
