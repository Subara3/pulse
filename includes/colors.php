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
</script>
