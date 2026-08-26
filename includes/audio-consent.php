<?php
/**
 * サウンドの可否をたずねる初回ゲートと、以降の切り替えボタン。
 *
 * header.php から全ページで読み込まれる。判定は localStorage の pulse_audio。
 *   未選択 → 全画面のゲートを出す
 *   'on' / 'off' → ゲートは出さず、切り替えボタンだけ置く
 *
 * ボタンは floating-nav があればその列に挿し、無いページでは単独で左下に置く。
 */
?>
<div class="pa-gate" id="paGate" hidden aria-hidden="true">
  <div class="pa-card" role="dialog" aria-modal="true" aria-labelledby="paGateTitle">
    <span class="pa-eyebrow">SOUND</span>
    <div class="pa-wave" aria-hidden="true">
      <svg viewBox="0 0 240 40" preserveAspectRatio="none">
        <path d="M0,20 C20,2 40,2 60,20 C80,38 100,38 120,20 C140,2 160,2 180,20 C200,38 220,38 240,20"
              fill="none" stroke="currentColor" stroke-width="1.5" />
      </svg>
    </div>
    <h2 id="paGateTitle">音を鳴らしますか</h2>
    <p class="pa-lead">
      PULSE のページは、触れると音が返ってきます。<br>
      音はすべてサイン波で、その場で合成しています。
    </p>
    <div class="pa-actions">
      <button type="button" class="pa-btn pa-btn-on" id="paYes">音ありで見る</button>
      <button type="button" class="pa-btn" id="paNo">音なしで見る</button>
    </div>
    <p class="pa-note">あとから SOUND ボタンでいつでも切り替えられます。</p>
  </div>
</div>

<style>
.pa-gate {
  position: fixed;
  inset: 0;
  z-index: 12000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  background: rgba(7, 7, 11, 0.9);
  backdrop-filter: blur(10px);
  animation: paGateIn 0.5s var(--ease, ease) both;
}
.pa-gate[hidden] { display: none; }
.pa-gate.closing { animation: paGateOut 0.4s var(--ease, ease) both; }

@keyframes paGateIn  { from { opacity: 0 } to { opacity: 1 } }
@keyframes paGateOut { from { opacity: 1 } to { opacity: 0; visibility: hidden } }

.pa-card {
  width: min(420px, 100%);
  padding: 2.2rem 1.9rem 1.6rem;
  background: rgba(16, 16, 23, 0.96);
  border: 1px solid var(--line-mid, rgba(255,255,255,0.13));
  border-radius: var(--radius, 4px);
  box-shadow: 0 24px 70px rgba(0, 0, 0, 0.6);
  text-align: center;
  animation: paCardIn 0.55s var(--ease, ease) both 0.06s;
}
@keyframes paCardIn {
  from { opacity: 0; transform: translateY(14px) }
  to   { opacity: 1; transform: none }
}

.pa-eyebrow {
  display: block;
  font-family: var(--font-mono, monospace);
  font-size: 0.6rem;
  letter-spacing: 0.32em;
  color: var(--teal, #00f5d4);
  text-transform: uppercase;
}

.pa-wave {
  margin: 1.1rem auto 1.3rem;
  width: 150px;
  height: 26px;
  color: var(--teal, #00f5d4);
  opacity: 0.75;
}
.pa-wave svg { width: 100%; height: 100%; display: block; }
.pa-wave path {
  stroke-dasharray: 210 60;
  animation: paWave 4.2s linear infinite;
}
@keyframes paWave { to { stroke-dashoffset: -270 } }

.pa-card h2 {
  font-family: var(--font-display, sans-serif);
  font-size: 1.18rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--text, #e9e9ef);
  margin-bottom: 0.85rem;
}

.pa-lead {
  font-family: var(--font-body, sans-serif);
  font-size: 0.8rem;
  line-height: 1.95;
  color: var(--text-2, #9b9baa);
}

.pa-actions {
  display: flex;
  gap: 0.6rem;
  margin: 1.6rem 0 1rem;
}

.pa-btn {
  flex: 1;
  padding: 0.75rem 0.5rem;
  font-family: var(--font-display, sans-serif);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--text-2, #9b9baa);
  background: transparent;
  border: 1px solid var(--line-mid, rgba(255,255,255,0.13));
  border-radius: var(--radius, 4px);
  cursor: pointer;
  transition: all 0.24s var(--ease, ease);
}
.pa-btn:hover {
  color: var(--text, #e9e9ef);
  border-color: var(--line-strong, rgba(255,255,255,0.24));
  background: rgba(255, 255, 255, 0.04);
}
.pa-btn-on {
  color: var(--ink-1, #0a0a0f);
  background: var(--teal, #00f5d4);
  border-color: var(--teal, #00f5d4);
}
.pa-btn-on:hover {
  color: var(--ink-1, #0a0a0f);
  background: #4ffbe4;
  border-color: #4ffbe4;
}

.pa-note {
  font-family: var(--font-mono, monospace);
  font-size: 0.6rem;
  letter-spacing: 0.06em;
  color: var(--text-3, #7f7f8d);
}

/* --- 切り替えボタン：ヘッダーナビに入る場合（文書系ページ） --- */
.site-nav .pa-sound-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.5rem 0.7rem;
  font-family: var(--font-mono, monospace);
  font-size: 0.68rem;
  letter-spacing: 0.12em;
  color: var(--text-2, #9b9baa);
  background: transparent;
  border: 0;
  border-radius: var(--radius, 4px);
  cursor: pointer;
  transition: color 0.2s var(--ease, ease), background 0.2s var(--ease, ease);
}
.site-nav .pa-sound-btn:hover { color: var(--text, #e9e9ef); background: var(--ink-2, #101017); }
.site-nav .pa-sound-btn.pa-on { color: var(--teal, #00f5d4); }
.site-nav .pa-sound-btn.pa-on:hover { background: var(--teal-soft, rgba(0,245,212,0.12)); }

/* --- 切り替えボタン：どこにも入らない場合 --- */
.pa-sound-btn.pa-standalone {
  position: fixed;
  bottom: 2rem;
  left: 2rem;
  z-index: 1000;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: rgba(16, 16, 23, 0.86);
  backdrop-filter: blur(8px);
  border: 1px solid var(--line-mid, rgba(255,255,255,0.13));
  color: var(--text-2, #9b9baa);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.05rem;
  cursor: pointer;
  transition: all 0.3s var(--ease, ease);
}
.pa-sound-btn.pa-standalone:hover {
  background: var(--teal, #00f5d4);
  color: var(--ink-1, #0a0a0f);
  border-color: var(--teal, #00f5d4);
  transform: scale(1.07);
}
.pa-sound-btn.pa-on { color: var(--teal, #00f5d4); border-color: var(--teal-line, rgba(0,245,212,0.32)); }

@media (max-width: 520px) {
  .site-nav .pa-sound-btn span { display: none; }
}

@media (max-width: 768px) {
  .pa-card { padding: 1.9rem 1.3rem 1.3rem; }
  .pa-lead { font-size: 0.74rem; line-height: 2.05; }
  .pa-actions { flex-direction: column; }
  .pa-sound-btn.pa-standalone { bottom: 1rem; left: 1rem; width: 40px; height: 40px; font-size: 0.9rem; }
  /* floating-nav の中に入った場合は、他のボタンと同じ開閉ルールに従う */
  .floating-nav .pa-sound-btn {
    opacity: 0; transform: scale(0.5); pointer-events: none;
    transition: all 0.28s var(--ease, ease);
  }
  .floating-nav.expanded .pa-sound-btn {
    opacity: 1; transform: scale(1); pointer-events: auto;
  }
}
</style>

<script>
(function () {
  'use strict';
  var PA = window.PulseAudio;
  if (!PA) return;

  var gate = document.getElementById('paGate');
  var restoreScroll = function () {};

  /* ---- 初回ゲート ---- */
  if (PA.state() === null && gate) {
    gate.hidden = false;
    gate.setAttribute('aria-hidden', 'false');

    /* 選ぶまでは背後を動かさない */
    var prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    restoreScroll = function () { document.documentElement.style.overflow = prevOverflow; };

    document.getElementById('paYes').addEventListener('click', function () {
      PA.enable();
      /* 選んだ直後に一度だけ鳴らして、音が出ていることが分かるようにする */
      setTimeout(function () {
        PA.arp([PA.chordSemi(0, 1), PA.chordSemi(2, 1), PA.chordSemi(4, 2)], 0.13,
          { vol: 0.09, dur: 2.4, attack: 0.01, shimmer: true });
      }, 120);
      closeGate();
    });
    document.getElementById('paNo').addEventListener('click', function () {
      PA.disable();
      closeGate();
    });
  } else if (gate) {
    gate.remove();
    gate = null;
  }

  function closeGate() {
    if (!gate) return;
    restoreScroll();
    gate.classList.add('closing');
    setTimeout(function () { if (gate) { gate.remove(); gate = null; } }, 420);
  }

  /* ---- 切り替えボタン ---- */
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'pa-sound-btn';
  btn.id = 'paSoundBtn';
  var withLabel = false;   /* ヘッダーナビでは語も出す（アイコンだけにしない） */

  function paint() {
    var on = PA.isEnabled();
    var icon = '<i class="fa-solid ' + (on ? 'fa-volume-high' : 'fa-volume-xmark') + '" aria-hidden="true"></i>';
    btn.innerHTML = withLabel ? icon + ' <span>SOUND</span>' : icon;
    btn.title = on ? '音を止める' : '音を鳴らす';
    btn.setAttribute('aria-label', btn.title);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.classList.toggle('pa-on', on);
  }

  function mount() {
    var floating = document.getElementById('floatingNav');
    var siteNav = document.querySelector('.site-nav');
    if (floating) {
      btn.classList.add('float-btn');
      floating.appendChild(btn);   /* column-reverse なので列の先頭に出る */
    } else if (siteNav) {
      withLabel = true;
      siteNav.appendChild(btn);
    } else {
      btn.classList.add('pa-standalone');
      document.body.appendChild(btn);
    }
    paint();
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    PA.toggle();
  });
  PA.onChange(paint);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }

  /* m キーでミュート切り替え */
  document.addEventListener('keydown', function (e) {
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA') return;
    if (e.key === 'm' || e.key === 'M') PA.toggle();
  });
})();
</script>
