<?php
/**
 * PULSE 配色と Canvas の下ごしらえ（2026-10 青焼き版）
 *
 * 作品ページは色を自分で決めない。ここの P を使う。
 * <script> の前で require_once __DIR__ . '/includes/colors.php'; する。
 *
 * 青焼き: 藍の地に白い線。強調はシアン 1 色だけ。
 *   P.bg     [13, 34, 56]    #0d2238 藍。Canvas の地（ページ背景と同じ）
 *   P.deep   [8, 22, 38]     #081626 地より暗い藍（影・奥）
 *   P.line   [232, 240, 247] #e8f0f7 白い線。描画の主役
 *   P.accent [95, 208, 255]  #5fd0ff シアン。触ったところ・注目点だけ
 *   P.steel  [111, 138, 163] #6f8aa3 鋼色。補助線・控えめな要素
 *
 *   P.rgba(c, a)      'rgba(r,g,b,a)'
 *   P.hex(c)          '#rrggbb'
 *   P.mix(a, b, t)    2 色の補間
 *   P.ramp(t)         0→1 で 藍→鋼→白 の濃淡（密度・高さ・速さの塗り分けに）
 *   P.rampAccent(t)   0→1 で 藍→シアン→白（強調したい場の塗り分けに）
 *   P.fill(ctx, w, h, a)  地を塗る。a<1 で前フレームを残す（残像）
 *   P.grid(ctx, w, h, o)  青焼きの方眼。o = { step: 32, major: 4, alpha: 1 }
 *   P.fit(canvas, cb)     画面いっぱい + 高 DPI。サイズが変わるたび cb(w, h, dpr)
 *
 * 色相は 195〜215°（藍とシアン）の外に出さない。check_policy.py が検査する。
 * 赤・橙・黄・緑・紫は使わない。強さの差は「明るさ」と「透明度」で出す。
 */
?>
<script>
const P = (function () {
  const bg     = [13, 34, 56];
  const deep   = [8, 22, 38];
  const line   = [232, 240, 247];
  const accent = [95, 208, 255];
  const steel  = [111, 138, 163];

  function rgba(c, a) {
    return 'rgba(' + (c[0] | 0) + ',' + (c[1] | 0) + ',' + (c[2] | 0) + ',' + (a == null ? 1 : a) + ')';
  }
  function hex(c) {
    return '#' + ((1 << 24) + ((c[0] | 0) << 16) + ((c[1] | 0) << 8) + (c[2] | 0)).toString(16).slice(1);
  }
  function mix(a, b, t) {
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t];
  }
  function clamp01(t) { return t < 0 ? 0 : t > 1 ? 1 : t; }
  function ramp(t) {
    t = clamp01(t);
    return t < 0.5 ? mix(bg, steel, t * 2) : mix(steel, line, (t - 0.5) * 2);
  }
  function rampAccent(t) {
    t = clamp01(t);
    return t < 0.6 ? mix(bg, accent, t / 0.6) : mix(accent, line, (t - 0.6) / 0.4);
  }

  function fill(ctx, w, h, a) {
    ctx.fillStyle = rgba(bg, a == null ? 1 : a);
    ctx.fillRect(0, 0, w, h);
  }

  function grid(ctx, w, h, o) {
    o = o || {};
    const step = o.step || 32, major = o.major || 4, k = o.alpha == null ? 1 : o.alpha;
    ctx.save();
    ctx.lineWidth = 1;
    for (let pass = 0; pass < 2; pass++) {
      ctx.strokeStyle = rgba(line, (pass ? 0.075 : 0.03) * k);
      ctx.beginPath();
      for (let i = 0, x = 0.5; x < w; i++, x += step) {
        if ((i % major === 0) === !!pass) { ctx.moveTo(x, 0); ctx.lineTo(x, h); }
      }
      for (let i = 0, y = 0.5; y < h; i++, y += step) {
        if ((i % major === 0) === !!pass) { ctx.moveTo(0, y); ctx.lineTo(w, y); }
      }
      ctx.stroke();
    }
    ctx.restore();
  }

  function fit(canvas, cb) {
    const ctx = canvas.getContext('2d');
    function resize() {
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const w = window.innerWidth, h = window.innerHeight;
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
      canvas.style.width = w + 'px';
      canvas.style.height = h + 'px';
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      if (cb) cb(w, h, dpr);
    }
    window.addEventListener('resize', resize);
    resize();
    return ctx;
  }

  return { bg, deep, line, accent, steel, rgba, hex, mix, ramp, rampAccent, fill, grid, fit };
})();

/* 手ざわりの層（全作品共通）。
   画面に文字を出さない代わりに、指やマウスの位置へ水色の小さな輪を出し、
   押した瞬間に輪を広げ、なぞった跡を短く残す。作品の canvas の上に重ねる別の canvas に描くので、
   作品側の描画には一切触れない。QA で作品そのものの反応を測るときは ?noring=1 で消せる。 */
(function () {
  if (/[?&]noring=1/.test(location.search)) return;
  var A = P.accent;
  var fx, c, W = 0, H = 0, dpr = 1;
  var hover = null, down = false, rings = [], trail = [], raf = 0, last = 0;

  function boot() {
    var art = document.getElementById('canvas');
    if (!art) return;
    fx = document.createElement('canvas');
    fx.setAttribute('aria-hidden', 'true');
    fx.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:5';
    document.body.appendChild(fx);
    c = fx.getContext('2d');
    art.style.cursor = 'none';   /* 輪がカーソルの代わり。JS が動かないときは CSS の十字のまま */
    size();
    window.addEventListener('resize', size);
    /* 作品の canvas の上で起きたものだけ拾う（ナビや設定パネルの操作では出さない） */
    art.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'mouse') hover = { x: e.clientX, y: e.clientY };
      if (down) {
        var p = trail[trail.length - 1];
        if (!p || Math.hypot(e.clientX - p.x, e.clientY - p.y) > 6) trail.push({ x: e.clientX, y: e.clientY, t: 0 });
      }
      kick();
    });
    art.addEventListener('pointerdown', function (e) {
      down = true;
      rings.push({ x: e.clientX, y: e.clientY, t: 0 });
      trail.push({ x: e.clientX, y: e.clientY, t: 0 });
      kick();
    });
    function up() { down = false; kick(); }
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
    art.addEventListener('pointerleave', function () { hover = null; kick(); });
  }
  function size() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    fx.width = Math.round(W * dpr); fx.height = Math.round(H * dpr);
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    kick();
  }
  function kick() { if (!raf) { last = performance.now(); raf = requestAnimationFrame(frame); } }
  function frame(now) {
    var dt = Math.min(0.05, (now - last) / 1000); last = now;
    c.clearRect(0, 0, W, H);
    var busy = false;
    /* なぞった跡: 0.5 秒で消える点線 */
    for (var i = trail.length - 1; i >= 0; i--) {
      var q = trail[i]; q.t += dt;
      var a = 1 - q.t / 0.5;
      if (a <= 0) { trail.splice(i, 1); continue; }
      c.fillStyle = P.rgba(A, 0.55 * a);
      c.beginPath(); c.arc(q.x, q.y, 1.6 + a * 1.2, 0, 6.2832); c.fill();
      busy = true;
    }
    /* 押した瞬間: 0.7 秒で広がって消える輪 */
    for (var j = rings.length - 1; j >= 0; j--) {
      var r = rings[j]; r.t += dt;
      var k = r.t / 0.7;
      if (k >= 1) { rings.splice(j, 1); continue; }
      var e = 1 - Math.pow(1 - k, 3);
      c.strokeStyle = P.rgba(A, 0.8 * (1 - k));
      c.lineWidth = 1.5;
      c.beginPath(); c.arc(r.x, r.y, 10 + e * 48, 0, 6.2832); c.stroke();
      busy = true;
    }
    /* マウスの位置: 小さな輪。押している間は少し縮んで濃くなる */
    if (hover) {
      c.strokeStyle = P.rgba(A, down ? 0.9 : 0.5);
      c.lineWidth = 1.2;
      c.beginPath(); c.arc(hover.x, hover.y, down ? 7 : 11, 0, 6.2832); c.stroke();
      c.fillStyle = P.rgba(A, 0.9);
      c.beginPath(); c.arc(hover.x, hover.y, 1.4, 0, 6.2832); c.fill();
    }
    if (busy) raf = requestAnimationFrame(frame); else raf = 0;
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
