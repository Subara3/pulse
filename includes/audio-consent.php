<?php
/**
 * サウンドの可否をたずねる初回の案内と、以降の切り替えボタン。
 *
 * header.php から全ページで読み込まれる。判定は localStorage の pulse_audio。
 *   未選択 → 画面の隅に小さな案内を出す（ページは隠さない・スクロールも止めない）
 *            選ばずに進めば無音のまま。案内はセッションに 1 回だけ（sessionStorage
 *            pulse_audio_prompted）。あとはナビの SOUND ボタンで選べる
 *   'on' / 'off' → 案内は出さず、切り替えボタンだけ置く
 *
 * 2026-09 まではページ全体を覆うゲートだったが、初見の人がヒーローや作品を
 * 見る前に二択を迫られるので、見せたまま選べる形に変えた。保存キーと値は同じ。
 *
 * ボタンは floating-nav があればその列に挿し、無いページでは単独で左下に置く。
 */
?>
<aside class="pa-prompt" id="paPrompt" hidden aria-labelledby="paPromptTitle">
  <div class="pa-prompt-head">
    <span class="pa-wave" aria-hidden="true">
      <svg viewBox="0 0 240 40" preserveAspectRatio="none">
        <path d="M0,20 C20,2 40,2 60,20 C80,38 100,38 120,20 C140,2 160,2 180,20 C200,38 220,38 240,20"
              fill="none" stroke="currentColor" stroke-width="2" />
      </svg>
    </span>
    <span class="pa-eyebrow">SOUND</span>
  </div>
  <p class="pa-title" id="paPromptTitle">触れると、音が返ってきます。</p>
  <p class="pa-lead">音はすべてサイン波をその場で合成しています。</p>
  <div class="pa-actions">
    <button type="button" class="pa-btn pa-btn-on" id="paYes"><i class="fa-solid fa-volume-high" aria-hidden="true"></i> 鳴らす</button>
    <button type="button" class="pa-btn" id="paNo">鳴らさない</button>
  </div>
</aside>

<style>
/* ---- 初回の案内。ページの上に「置く」だけで、覆わない ---- */
.pa-prompt {
  position: fixed;
  left: 1.5rem;
  bottom: calc(1.5rem + var(--pulse-ad-h, 0px));
  z-index: 9500;
  width: min(330px, calc(100vw - 2rem));
  padding: 1rem 1.1rem 1.05rem;
  background: rgba(13, 13, 19, 0.94);
  backdrop-filter: blur(12px);
  border: 1px solid var(--line-mid, rgba(255,255,255,0.13));
  border-left: 2px solid var(--teal, #00f5d4);
  border-radius: var(--radius, 4px);
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.55);
  animation: paIn 0.42s var(--ease, ease) both 0.9s;
}
.pa-prompt[hidden] { display: none; }
.pa-prompt.closing { animation: paOut 0.3s var(--ease, ease) both; }
/* Canvas ページでは左下にナビのプレート（高さ約 48px）がいるので、その上に出す */
.pa-prompt.pa-prompt--canvas { left: 1.25rem; bottom: calc(1.25rem + 58px + var(--pulse-ad-h, 0px)); }

@keyframes paIn  { from { opacity: 0; transform: translateY(12px) } to { opacity: 1; transform: none } }
@keyframes paOut { from { opacity: 1 } to { opacity: 0; transform: translateY(8px); visibility: hidden } }

.pa-prompt-head { display: flex; align-items: center; gap: 0.6rem; }
.pa-wave {
  width: 46px;
  height: 12px;
  color: var(--teal, #00f5d4);
  flex: none;
}
.pa-wave svg { width: 100%; height: 100%; display: block; }
.pa-wave path {
  stroke-dasharray: 210 60;
  animation: paWave 4.2s linear infinite;
}
@keyframes paWave { to { stroke-dashoffset: -270 } }

.pa-eyebrow {
  font-family: var(--font-mono, monospace);
  font-size: 0.6rem;
  letter-spacing: 0.3em;
  color: var(--teal, #00f5d4);
}

.pa-title {
  margin-top: 0.55rem;
  font-family: var(--font-body, sans-serif);
  font-size: 0.9rem;
  font-weight: 700;
  line-height: 1.6;
  color: var(--text, #e9e9ef);
}
.pa-lead {
  margin-top: 0.15rem;
  font-size: 0.72rem;
  line-height: 1.7;
  color: var(--text-2, #9b9baa);
}

.pa-actions { display: flex; gap: 0.5rem; margin-top: 0.85rem; }

.pa-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  flex: 1;
  padding: 0.55rem 0.5rem;
  font-family: var(--font-mono, monospace);
  font-size: 0.72rem;
  font-weight: 500;
  letter-spacing: 0.08em;
  color: var(--text-2, #9b9baa);
  background: transparent;
  border: 1px solid var(--line-mid, rgba(255,255,255,0.13));
  border-radius: var(--radius, 4px);
  cursor: pointer;
  transition: color 0.24s var(--ease, ease), background 0.24s var(--ease, ease), border-color 0.24s var(--ease, ease);
}
.pa-btn:hover {
  color: var(--text, #e9e9ef);
  border-color: var(--line-strong, rgba(255,255,255,0.24));
  background: rgba(255, 255, 255, 0.04);
}
.pa-btn-on {
  color: var(--ink-0, #07070b);
  background: var(--teal, #00f5d4);
  border-color: var(--teal, #00f5d4);
  font-weight: 700;
}
.pa-btn-on:hover {
  color: var(--ink-0, #07070b);
  background: #5affe4;
  border-color: #5affe4;
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

@media (max-width: 620px) {
  .site-nav .pa-sound-btn { padding: 0.45rem 0.45rem; }
  .site-nav .pa-sound-btn span { display: none; }
}

@media (max-width: 768px) {
  /* 右端の操作ボタン列（歯車・floating-nav）に被らないよう、右に 4.5rem 空ける */
  .pa-prompt { left: 1rem; bottom: calc(1rem + var(--pulse-ad-h, 0px)); width: min(330px, calc(100vw - 5.5rem)); }
  .pa-prompt.pa-prompt--canvas {
    left: 0.75rem;
    bottom: calc(0.75rem + 56px + var(--pulse-ad-h, 0px));
    width: min(330px, calc(100vw - 1.5rem - 4.5rem));
  }
  .pa-prompt--canvas .pa-lead { display: none; }
  .pa-sound-btn.pa-standalone { bottom: 1rem; left: 1rem; width: 40px; height: 40px; font-size: 0.9rem; }
}
</style>

<script>
(function () {
  'use strict';
  var PA = window.PulseAudio;
  if (!PA) return;

  var prompt = document.getElementById('paPrompt');

  /* ---- 初回の案内 ---- */
  var prompted = false;
  try { prompted = sessionStorage.getItem('pulse_audio_prompted') === '1'; } catch (e) {}

  if (PA.state() === null && prompt && !prompted) {
    try { sessionStorage.setItem('pulse_audio_prompted', '1'); } catch (e) {}
    var place = function () {
      if (document.getElementById('floatingNav')) prompt.classList.add('pa-prompt--canvas');
      prompt.hidden = false;
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', place);
    else place();

    /* Canvas ページは window / document で押下を拾うものが多い。案内を押したときに
       背後の作品へ「クリック」が届かないよう、ここで止める */
    ['pointerdown', 'mousedown', 'touchstart', 'click'].forEach(function (t) {
      prompt.addEventListener(t, function (e) { e.stopPropagation(); }, { passive: true });
    });

    document.getElementById('paYes').addEventListener('click', function (e) {
      e.stopPropagation();
      PA.enable();
      /* 選んだ直後に一度だけ鳴らして、音が出ていることが分かるようにする */
      setTimeout(function () {
        PA.arp([PA.chordSemi(0, 1), PA.chordSemi(2, 1), PA.chordSemi(4, 2)], 0.13,
          { vol: 0.09, dur: 2.4, attack: 0.01, shimmer: true });
      }, 120);
    });
    document.getElementById('paNo').addEventListener('click', function (e) {
      e.stopPropagation();
      PA.disable();
    });
    /* 切り替えボタンや m キーで選んだ場合も、選択が済んだので畳む */
    PA.onChange(closePrompt);
  } else if (prompt) {
    prompt.remove();
    prompt = null;
  }

  function closePrompt() {
    if (!prompt) return;
    var p = prompt;
    prompt = null;
    p.classList.add('closing');
    setTimeout(function () { p.remove(); }, 320);
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
    btn.innerHTML = withLabel ? icon + ' <span>音</span>' : icon;
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
      /* ナビのプレートの操作列（メニューボタンの手前）に入れる */
      var acts = document.getElementById('fnActions');
      var menu = document.getElementById('floatMenuBtn');
      if (acts && menu) acts.insertBefore(btn, menu); else floating.appendChild(btn);
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
