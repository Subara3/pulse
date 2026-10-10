<?php
/**
 * 全画面Canvasページの設定パネル（2026-10 青焼き版）
 *
 * 画面には何も出さず、右下の小さなボタンだけを置く。押すと設定が開く。
 * 眺めるのが主で、設定は「いじりたい人だけが開く」もの。
 *
 * 使い方:
 * <div id="controls" class="drawer-controls">
 *   <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
 *   <div class="ctl-btns"><button type="button" class="active" data-mode="a">CALM</button><button type="button" data-mode="b">DENSE</button></div>
 *   <div class="ctl-btns"><button type="button" id="k-reset">RESET</button></div>
 * </div>
 * <?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
 *
 * - スライダーは <input type="range" id="...">、選んだボタンには .active。左下の「共有」がこの 2 つを URL に残す
 * - ラベルは英語の短い単語 1 つ。数値の表示は付けない
 * - 旧クラス（.slider-row / .btn-row / .control-btn など）も同じ見た目で受ける
 */
?>
<button id="drawer-toggle" class="drawer-toggle" type="button" aria-label="Open settings" aria-controls="controls" aria-expanded="false">
  <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M3 6h9M15 6h2M3 14h2M8 14h9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="13.5" cy="6" r="1.8" fill="none" stroke="currentColor" stroke-width="1.4"/><circle cx="6.5" cy="14" r="1.8" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>
</button>
<div id="drawer-overlay" class="drawer-overlay" aria-hidden="true"></div>

<style>
.drawer-toggle {
  position: fixed;
  right: 1.25rem;
  bottom: 1.25rem;
  z-index: 9101;
  width: 44px; height: 44px;
  display: grid; place-items: center;
  color: var(--text-2);
  background: rgba(13, 34, 56, 0.84);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid var(--line-mid);
  border-radius: 50%;
  cursor: pointer;
  transition: color var(--dur) var(--ease), border-color var(--dur) var(--ease), transform var(--dur) var(--ease);
}
.drawer-toggle:hover { color: var(--cyan); border-color: var(--cyan-line); }
.drawer-toggle.open { color: var(--cyan); border-color: var(--cyan-line); transform: rotate(90deg); }

.drawer-overlay { display: none; position: fixed; inset: 0; z-index: 9098; }
.drawer-overlay.visible { display: block; }

.drawer-controls {
  position: fixed;
  right: 1.25rem;
  bottom: calc(1.25rem + 54px);
  z-index: 9099;
  width: 264px;
  max-height: min(68vh, 560px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1rem 1.05rem 1.1rem;
  background: rgba(10, 28, 47, 0.95);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1px solid var(--line-mid);
  border-radius: 6px;
  box-shadow: 0 16px 44px rgba(0, 0, 0, 0.45);
  opacity: 0;
  visibility: hidden;
  transform: translateY(6px);
  transition: opacity 0.25s var(--ease), transform 0.25s var(--ease), visibility 0.25s;
}
.drawer-controls.open { opacity: 1; visibility: visible; transform: none; }

/* 行 */
.drawer-controls .ctl-row,
.drawer-controls .slider-row,
.drawer-controls .ctrl-row,
.drawer-controls .control-row {
  display: grid;
  grid-template-columns: 4.6rem minmax(0, 1fr);
  align-items: center;
  gap: 0.7rem;
}
.drawer-controls label,
.drawer-controls .slider-label,
.drawer-controls .ctrl-label {
  font-family: var(--font-mono);
  font-size: 0.62rem;
  letter-spacing: 0.12em;
  color: var(--text-3);
  text-transform: uppercase;
}
.drawer-controls .slider-value,
.drawer-controls .ctrl-val { display: none; }

.drawer-controls input[type="range"] {
  -webkit-appearance: none;
  appearance: none;
  width: 100%;
  height: 18px;
  background: transparent;
  cursor: pointer;
}
.drawer-controls input[type="range"]::-webkit-slider-runnable-track { height: 1px; background: var(--line-strong); }
.drawer-controls input[type="range"]::-moz-range-track { height: 1px; background: var(--line-strong); }
.drawer-controls input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px; height: 12px; margin-top: -6px;
  border-radius: 50%;
  background: var(--ink-1);
  border: 1.5px solid var(--cyan);
}
.drawer-controls input[type="range"]::-moz-range-thumb {
  width: 10px; height: 10px; border-radius: 50%;
  background: var(--ink-1); border: 1.5px solid var(--cyan);
}

/* ボタン */
.drawer-controls .ctl-btns,
.drawer-controls .btn-row,
.drawer-controls .button-group,
.drawer-controls .control-group,
.drawer-controls .mode-group,
.drawer-controls .action-group,
.drawer-controls .preset-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}
.drawer-controls button {
  padding: 0.42rem 0.7rem;
  font-family: var(--font-mono);
  font-size: 0.64rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--text-2);
  background: transparent;
  border: 1px solid var(--line-mid);
  border-radius: 3px;
  cursor: pointer;
  transition: color var(--dur) var(--ease), border-color var(--dur) var(--ease), background var(--dur) var(--ease);
}
.drawer-controls button:hover { color: var(--text); border-color: var(--line-strong); }
.drawer-controls button.active { color: var(--cyan); border-color: var(--cyan-line); background: var(--cyan-soft); }

@media (max-width: 768px) {
  .drawer-toggle { right: 0.75rem; bottom: 0.75rem; }
  .drawer-controls {
    left: 0; right: 0; bottom: 0;
    width: 100%;
    max-height: 62vh;
    padding: 1.1rem 1.2rem calc(1.4rem + env(safe-area-inset-bottom));
    border-radius: 10px 10px 0 0;
    border-width: 1px 0 0;
    transform: translateY(100%);
  }
  .drawer-controls.open { transform: none; }
  .drawer-controls::before {
    content: ''; display: block; flex: none;
    width: 36px; height: 3px; margin: 0 auto 0.3rem;
    background: var(--line-strong); border-radius: 2px;
  }
  .drawer-controls button { padding: 0.55rem 0.8rem; }
}
</style>

<script>
(function () {
  var toggle = document.getElementById('drawer-toggle');
  var overlay = document.getElementById('drawer-overlay');
  var controls = document.querySelector('.drawer-controls');
  if (!toggle || !controls) return;
  var isOpen = false, y0 = 0, y1 = 0;

  function set(open) {
    isOpen = open;
    toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close settings' : 'Open settings');
    overlay.classList.toggle('visible', open);
    controls.classList.toggle('open', open);
    controls.style.transform = '';
  }
  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    set(!isOpen);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  overlay.addEventListener('click', function () { set(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen) set(false); });

  /* スマホは下に引いて閉じる */
  controls.addEventListener('touchstart', function (e) { y0 = y1 = e.touches[0].clientY; }, { passive: true });
  controls.addEventListener('touchmove', function (e) {
    y1 = e.touches[0].clientY;
    if (y1 - y0 > 0 && controls.scrollTop === 0) controls.style.transform = 'translateY(' + (y1 - y0) + 'px)';
  }, { passive: true });
  controls.addEventListener('touchend', function () {
    controls.style.transform = '';
    if (y1 - y0 > 80 && controls.scrollTop === 0) set(false);
  });
})();
</script>
