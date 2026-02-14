<?php
/**
 * PULSE 共有カラーシステム
 *
 * サイト全体で統一された配色哲学を提供する。
 * 各コンテンツページは独自の色を定義せず、このシステムを使う。
 *
 * 使い方:
 * <?php require_once __DIR__ . '/includes/colors.php'; ?>
 * （<script>タグの前に配置）
 *
 * JavaScript API:
 * P.accent        → [0, 245, 212]     サイトメインカラー（teal）
 * P.sub           → [123, 44, 191]    サイトサブカラー（purple）
 * P.bg            → [10, 10, 15]      背景色
 * P.rgba(color,a) → 'rgba(0,245,212,0.5)'  CSS文字列生成
 * P.hex(color)    → '#00f5d4'         HEX文字列生成
 * P.hsl(h,s,l)    → [r, g, b]        HSL→RGB変換
 * P.mix(a,b,t)    → [r, g, b]        2色補間（t=0→a, t=1→b）
 * P.dim(color,f)  → [r, g, b]        明度調整（f=0.5で半分の明るさ）
 * P.bright(c,f)   → [r, g, b]        白方向に明るく（f=0.3で30%白寄り）
 *
 * 配色思想:
 * - メイン(60%): teal系 #00f5d4 — 主要な要素、アクティブ状態
 * - サブ(30%): purple系 #7b2cbf — 対比要素、セカンダリ状態
 * - アクセント(10%): 白 or 補色 — ハイライト、注目点
 * - 背景: #0a0a0f の深い黒 — 常に暗く、要素を浮かび上がらせる
 */
?>
<script>
const P = (function() {
  const accent  = [0, 245, 212];
  const sub     = [123, 44, 191];
  const bg      = [10, 10, 15];

  function rgba(c, a) {
    return 'rgba(' + (c[0]|0) + ',' + (c[1]|0) + ',' + (c[2]|0) + ',' + a + ')';
  }

  function hex(c) {
    return '#' + ((1<<24)+(c[0]<<16)+(c[1]<<8)+c[2]).toString(16).slice(1);
  }

  function hsl(h, s, l) {
    s /= 100; l /= 100;
    const k = n => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  function mix(a, b, t) {
    return [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t];
  }

  function dim(c, f) {
    return [c[0]*f, c[1]*f, c[2]*f];
  }

  function bright(c, f) {
    return [c[0]+(255-c[0])*f, c[1]+(255-c[1])*f, c[2]+(255-c[2])*f];
  }

  return { accent, sub, bg, rgba, hex, hsl, mix, dim, bright };
})();
</script>
