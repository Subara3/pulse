<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cumulus');

$pageName = 'cumulus';
$pageTitle = 'CUMULUS - PULSE';
$pageDesc = '平らな底の上に丸いふくらみが盛り上がり、その縁にまた小さなふくらみが重なって、積雲が何層もゆっくりわき上がりながら横へ流れていく。ふくらみは少しずつしぼんで消え、別のところからまたわいてくる。押すとそこのふくらみから小さなふくらみが指のほうへ次々に盛り上がって水色に光り、なぞると指の跡に沿って雲がわく。';
$pageCanonical = 'https://pulse.subara3.com/cumulus.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-billow">BILLOW</label><input type="range" id="k-billow" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 雲 = ふくらみ（円）の木。根のふくらみは平らな底に乗り、
     子のふくらみは親の縁の上側に乗る。親がふくらむと子は外へ押し出される。
     ふくらみは寿命が来るとしぼみ、空いた縁にはまた新しい子がわく */
  var MAXK = [3, 2, 2, 1, 1, 1, 1, 1];
  var MAXD = 5;
  var U = 1, layers = [], total = 0, CAP = 1400, MINR = 3.5;
  var t = 0, windMul = 1, billow = 1;
  var down = false, lx = 0, ly = 0, target = null, acc = 0;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function newPuff(cl, par, d, R, r) {
    var p = {
      cl: cl, par: par, d: d, R: R, r: r, ang: -1.5708, ox: 0, oy: 0, fixed: false,
      wph: Math.random() * 6.2832, age: 0, life: par ? rnd(9, 22) : rnd(20, 36),
      dying: false, glow: 0, kids: 0, nk: rnd(0.5, 2.5), fast: 0, x: cl.x, y: cl.base
    };
    if (par) {
      par.kids++;
      p.x = par.x; p.y = par.y;
    }
    cl.puffs.push(p);
    total++;
    return p;
  }

  function addRoot(cl, ox, R, r) {
    var p = newPuff(cl, null, 0, R, r);
    p.ox = ox;
    p.x = cl.x + ox; p.y = cl.base - 0.35 * r;
    return p;
  }

  /* 子は親の外向きの方角のまわりに、上へ向けてわかせる */
  function addKid(par, ang, r0, fast) {
    var R = par.R * rnd(0.55, 0.7);
    var k = newPuff(par.cl, par, par.d + 1, R, R * r0);
    k.ang = ang;
    k.fast = fast || 0;
    k.glow = par.glow * 0.9;
    return k;
  }

  function naturalKidAngle(p) {
    var a;
    if (!p.par) {
      var side = p.cl.w > 0 ? p.ox / (p.cl.w * 0.5) : 0;
      a = -1.5708 + (Math.random() - 0.5) * 2.2 + 0.45 * side;
    } else {
      a = p.ang + (Math.random() - 0.5) * 1.8;
    }
    return Math.max(-Math.PI + 0.15, Math.min(-0.15, a));
  }

  function newCloud(layer, x, temp) {
    var cl = { L: layer, x: x, base: layer.base + (temp ? 0 : rnd(-0.4, 0.4) * layer.R0), temp: !!temp, puffs: [], nRoots: 0, w: 0, rt: rnd(1, 3), maxD: 0 };
    if (!temp) {
      cl.nRoots = 4 + Math.floor(Math.random() * 3);
      cl.w = cl.nRoots * layer.R0 * 0.95;
      for (var i = 0; i < cl.nRoots; i++) {
        var ox = (i + 0.5 - cl.nRoots / 2) / cl.nRoots * cl.w + rnd(-0.2, 0.2) * layer.R0;
        var p = addRoot(cl, ox, rootR(cl, ox), 0);
        p.r = p.R * rnd(0.3, 1);
        p.age = Math.random() * p.life * 0.7;
      }
    }
    layer.clouds.push(cl);
    return cl;
  }

  /* 真ん中ほど大きい根にして、雲全体をこんもりさせる */
  function rootR(cl, ox) {
    var s = cl.w > 0 ? ox / (cl.w * 0.5) : 0;
    return cl.L.R0 * rnd(0.85, 1.2) * (1 - 0.35 * Math.min(1, s * s));
  }

  function alloc() {
    U = Math.min(W, H);
    MINR = Math.max(3, U * 0.006);
    CAP = Math.round(Math.max(700, Math.min(1600, W * H / 900)));
    var nL = Math.max(4, Math.min(7, Math.round(H / (U * 0.28))));
    var b0 = Math.min(H * 0.3, U * 0.3);
    layers = []; total = 0;
    for (var li = 0; li < nL; li++) {
      var f = li / (nL - 1);
      var s = 0.5 + 0.7 * f;
      var L = {
        f: f, s: s, R0: U * 0.13 * s, base: b0 + (H * 1.02 - b0) * f, vx: -(5 + 16 * f),
        clouds: [], fills: [], fillC: [], strokeC: P.ramp(0.5 + 0.48 * f),
        lw: 0.8 + 0.7 * f
      };
      L.stroke = P.rgba(L.strokeC);
      L.hl = P.rgba(P.ramp(0.2 + 0.22 * f));
      L.baseCol = P.rgba(P.ramp(0.32 + 0.4 * f));
      for (var d = 0; d < 9; d++) {
        L.fillC.push(P.ramp(0.05 + 0.022 * d + 0.06 * f));
        L.fills.push(P.rgba(L.fillC[d]));
      }
      var cw = 5 * L.R0 * 0.95;
      L.spacing = cw * rnd(1.15, 1.45);
      L.margin = cw;
      var n = Math.ceil((W + 2 * L.margin) / L.spacing) + 1;
      L.span = n * L.spacing;
      var off = Math.random() * L.spacing;
      for (var c = 0; c < n; c++) newCloud(L, -L.margin + off + c * L.spacing + rnd(-0.15, 0.15) * L.spacing, false);
      layers.push(L);
    }
    /* 開いたときから育った雲が見えるように、先に 25 秒ぶん回しておく */
    for (var k = 0; k < 500; k++) update(0.05, true);
  }

  function update(dt, warm) {
    t += dt;
    var grow = 0.25 * billow;
    for (var li = 0; li < layers.length; li++) {
      var L = layers[li];
      for (var ci = L.clouds.length - 1; ci >= 0; ci--) {
        var cl = L.clouds[ci];
        cl.x += L.vx * windMul * dt;
        if (!cl.temp && cl.x + cl.w * 0.5 + L.margin * 0.5 < -L.margin * 0.5) cl.x += L.span;
        var ps = cl.puffs, any = false, live = 0, maxD = 0;
        for (var i = 0; i < ps.length; i++) {
          var p = ps[i];
          if (p.par && p.par.dying) p.dying = true;
          p.age += dt;
          if (!p.dying && p.age > p.life) p.dying = true;
          if (p.dying) {
            p.r -= dt * p.R * 0.11 * billow;
            if (p.r < p.R * 0.08) { p.dead = true; any = true; }
          } else {
            p.r += (p.R - p.r) * Math.min(1, dt * (p.fast > 0 ? 4 : grow));
          }
          if (p.fast > 0) p.fast -= dt;
          if (p.glow > 0) p.glow = Math.max(0, p.glow - dt * 0.3);
          if (!p.par) {
            p.x = cl.x + p.ox;
            p.y = p.fixed ? p.oy : cl.base - 0.35 * p.r;
            if (!p.dying) live++;
          } else {
            var a = p.ang + 0.07 * Math.sin(t * 0.5 + p.wph), q = p.par, dd = q.r * 0.85;
            p.x = q.x + Math.cos(a) * dd;
            p.y = q.y + Math.sin(a) * dd;
          }
          if (p.d > maxD) maxD = p.d;
          if (!p.dying && p.d < MAXD && p.r > p.R * 0.55 && p.kids < MAXK[p.d] && p.R * 0.62 > MINR) {
            p.nk -= dt * billow;
            if (p.nk < 0) {
              p.nk = rnd(1, 4);
              if (total < CAP) addKid(p, naturalKidAngle(p), 0.15, 0);
            }
          }
        }
        cl.maxD = maxD;
        if (any) {
          var keep = [];
          for (var j = 0; j < ps.length; j++) {
            if (ps[j].dead) { total--; if (ps[j].par) ps[j].par.kids--; } else keep.push(ps[j]);
          }
          cl.puffs = keep;
        }
        if (!cl.temp) {
          if (live < cl.nRoots) {
            cl.rt -= dt * billow;
            if (cl.rt < 0) {
              cl.rt = rnd(2, 5);
              var ox = rnd(-0.5, 0.5) * cl.w;
              addRoot(cl, ox, rootR(cl, ox), 0);
              if (!warm) SFX.play('grow', 6000);
            }
          }
        } else if (!cl.puffs.length || cl.x + cl.w < -L.margin * 2) {
          if (target === cl) target = null;
          total -= cl.puffs.length;
          L.clouds.splice(ci, 1);
        }
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  function drawCloud(L, cl) {
    var ps = cl.puffs;
    if (!ps.length) return;
    var reach = cl.w * 0.5 + L.R0 * 3;
    if (!cl.temp && (cl.x + reach < 0 || cl.x - reach > W)) return;
    ctx.save();
    ctx.beginPath();
    ctx.rect(-10, -10, W + 20, cl.base + 10);
    ctx.clip();
    ctx.lineWidth = L.lw;
    for (var d = 0; d <= cl.maxD; d++) {
      for (var i = 0; i < ps.length; i++) {
        var p = ps[i];
        if (p.d !== d || p.r < 0.5) continue;
        var fi = Math.min(8, d);
        if (p.glow > 0.02) {
          ctx.fillStyle = P.rgba(P.mix(L.fillC[fi], P.rampAccent(0.3), p.glow * 0.55));
          ctx.strokeStyle = P.rgba(P.mix(L.strokeC, P.accent, p.glow));
        } else {
          ctx.fillStyle = L.fills[fi];
          ctx.strokeStyle = L.stroke;
        }
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, 6.2832);
        ctx.fill();
        /* 輪郭は親の外に出た部分だけ引く。親の中に入った弧は描かず、ひとつながりの雲に見せる */
        var q = p.par, a0 = 0, a1 = 6.2832;
        if (q) {
          var ddx = q.x - p.x, ddy = q.y - p.y, D = Math.sqrt(ddx * ddx + ddy * ddy);
          if (D > 0.01) {
            var c = (D * D + p.r * p.r - q.r * q.r) / (2 * D * p.r);
            if (c <= -1) continue;
            if (c < 1) {
              var th = Math.acos(c), phi = Math.atan2(ddy, ddx);
              a0 = phi + th; a1 = phi + 6.2832 - th;
            }
          }
        }
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, a0, a1);
        ctx.stroke();
        /* 左上の内側に細い弧を 1 本。ふくらみの丸さが出る */
        if (p.r > 7) {
          ctx.strokeStyle = L.hl;
          ctx.beginPath();
          ctx.arc(p.x, p.y, p.r * 0.74, -2.6, -1.75);
          ctx.stroke();
        }
      }
    }
    ctx.restore();
    /* 平らな底: 根の円が底の高さで切れるところに線を引く */
    ctx.strokeStyle = L.baseCol;
    ctx.lineWidth = L.lw;
    ctx.beginPath();
    for (var k = 0; k < ps.length; k++) {
      var q = ps[k];
      var dy = cl.base - q.y;
      if (dy < 0 || dy >= q.r) continue;
      var hw = Math.sqrt(q.r * q.r - dy * dy);
      ctx.moveTo(q.x - hw, cl.base + 0.5);
      ctx.lineTo(q.x + hw, cl.base + 0.5);
    }
    ctx.stroke();
  }

  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.8 });
    for (var li = 0; li < layers.length; li++) {
      var L = layers[li];
      for (var ci = 0; ci < L.clouds.length; ci++) drawCloud(L, L.clouds[ci]);
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  /* 指の下にあるいちばん手前のふくらみ */
  function hit(x, y) {
    for (var li = layers.length - 1; li >= 0; li--) {
      var L = layers[li];
      for (var ci = L.clouds.length - 1; ci >= 0; ci--) {
        var cl = L.clouds[ci];
        if (y > cl.base + 2) continue;
        var ps = cl.puffs;
        for (var i = ps.length - 1; i >= 0; i--) {
          var p = ps[i];
          if (p.dying) continue;
          var dx = x - p.x, dy = y - p.y;
          if (dx * dx + dy * dy < p.r * p.r * 1.1) return p;
        }
      }
    }
    return null;
  }

  function lightUp(cl, x, y, rad) {
    var ps = cl.puffs;
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i], dd = Math.hypot(p.x - x, p.y - y) - p.r;
      if (dd < rad) p.glow = Math.max(p.glow, 1 - Math.max(0, dd) / rad * 0.6);
    }
  }

  /* 押したふくらみから、指のほうへ子をわかせる */
  function burst(p, x, y, n) {
    var a0 = Math.atan2(y - p.y, x - p.x);
    if (Math.hypot(x - p.x, y - p.y) < p.r * 0.2) a0 = -1.5708;
    p.R = Math.min(p.R * 1.12, p.cl.L.R0 * 1.6);
    p.fast = 0.8;
    p.life = Math.max(p.life, p.age + 12);
    for (var i = 0; i < n; i++) {
      var a = a0 + (n > 1 ? (i / (n - 1) - 0.5) * 1.6 : 0);
      var k = addKid(p, a, 0.35, 1.5);
      k.glow = 1;
      k.life += 6;
    }
    lightUp(p.cl, x, y, p.R * 1.2);
  }

  /* 空いた空を押したときは、手前から数えて高さの合う層に小さな雲を置く */
  function layerFor(y) {
    var best = layers[0], bd = 1e9;
    for (var i = 0; i < layers.length; i++) {
      var L = layers[i], d = Math.abs(L.base - L.R0 * 0.6 - y);
      if (d < bd) { bd = d; best = L; }
    }
    return best;
  }

  function seed(x, y, cl) {
    if (!cl || y > cl.base - 4) {
      var L = layerFor(y);
      cl = newCloud(L, x, true);
      cl.base = y + L.R0 * 0.45;
    }
    var R = cl.L.R0 * rnd(0.7, 0.9);
    var p = addRoot(cl, x - cl.x, R, R * 0.4);
    p.fixed = true; p.oy = y; p.y = y;
    p.fast = 1.2; p.glow = 1; p.nk = rnd(0.3, 0.8);
    p.life = rnd(22, 32);
    cl.w = Math.max(cl.w, Math.abs(x - cl.x) * 2);
    return cl;
  }

  function press(x, y, first) {
    var p = hit(x, y);
    if (p) {
      burst(p, x, y, first ? 3 : 1);
      target = p.cl;
    } else {
      target = seed(x, y, first ? null : target);
    }
  }

  canvas.addEventListener('pointerdown', function (e) {
    down = true; lx = e.clientX; ly = e.clientY; acc = 0;
    press(lx, ly, true);
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var x = e.clientX, y = e.clientY;
    acc += Math.hypot(x - lx, y - ly);
    lx = x; ly = y;
    var step = Math.max(14, U * 0.035);
    if (acc < step) return;
    acc = 0;
    press(x, y, false);
    SFX.play('spawn', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kWind = document.getElementById('k-wind'), kBillow = document.getElementById('k-billow');
  function readWind() { windMul = 0.15 + kWind.value / 100 * 2.6; }
  function readBillow() { billow = 0.4 + kBillow.value / 100 * 1.6; }
  kWind.addEventListener('input', readWind);
  kBillow.addEventListener('input', readBillow);
  /* 共有リンクから開いたとき、復元された値を拾う */
  readWind(); readBillow();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    update(dt, false);
    draw();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
