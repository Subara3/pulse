<?php
/**
 * 全画面Canvasページ用のナビ（左下のプレート）。
 *
 * 2026-10 展示室版。丸ボタンの縦列をやめ、作品の札のように 1 枚にまとめた。
 *  - 作品名とコレクション、コレクション内の前後
 *  - お気に入り / この状態を共有 / ランダム / 音（audio-consent.php が #fnActions に差し込む）
 *  - メニューを開くと同じコレクションの全作品とサイト内リンク
 * 狭い画面では作品名とメニューだけを出し、ほかの操作はメニューの中に入れる。
 *
 * お気に入り・共有の中身は js/pulse-kit.js。ここでは PULSE.page を置くだけ。
 */
require_once __DIR__ . '/contents-data.php';

$_navSelf = $pageName ?? '';
$_navPool = array_values(array_filter($works, fn($c) => $c['page'] !== $_navSelf));
$_navCurrent = null;
foreach ($allContents as $c) {
    if ($c['page'] === $_navSelf) { $_navCurrent = $c; break; }
}
$_navGroup = $_navCurrent['group'] ?? '';
$_navGroupLabel = $PULSE_COLLECTIONS[$_navGroup]['name'] ?? '';

// 同じコレクションの全作品（トップ・アーカイブと同じ新しい順）
$_siblings = $_navGroup ? pulseByCollection($worksByNew, $_navGroup) : [];
$_pos = 0;
foreach ($_siblings as $i => $s) { if ($s['page'] === $_navSelf) { $_pos = $i; break; } }
$_n = count($_siblings);
$_prev = $_n > 1 ? $_siblings[($_pos - 1 + $_n) % $_n] : null;
$_next = $_n > 1 ? $_siblings[($_pos + 1) % $_n] : null;
$_navPages = array_map(fn($c) => $c['page'], $_navPool);
$_h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<script>window.PULSE = window.PULSE || {}; PULSE.page = <?php echo json_encode($_navSelf); ?>;</script>

<div class="floating-nav" id="floatingNav">
  <a href="index.php<?php echo $_navGroup ? '#' . $_navGroup : ''; ?>" class="fn-home" aria-label="PULSE トップへ" title="PULSE トップへ">
    <span class="fn-dot" aria-hidden="true"></span>
  </a>

  <div class="fn-label">
    <span class="fn-title"><?php echo $_h($_navCurrent['title'] ?? strtoupper($_navSelf)); ?></span>
    <?php if ($_navGroup): ?>
    <span class="fn-sub"><?php echo $_h($_navGroupLabel); ?> <span class="fn-pos"><?php echo $_pos + 1; ?> / <?php echo $_n; ?></span></span>
    <?php endif; ?>
  </div>

  <div class="fn-actions" id="fnActions">
    <?php if ($_prev): ?>
    <a class="float-btn fn-wide" href="<?php echo $_h($_prev['page']); ?>.php" title="前：<?php echo $_h($_prev['title']); ?>" aria-label="前の作品 <?php echo $_h($_prev['title']); ?>"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
    <a class="float-btn fn-wide" href="<?php echo $_h($_next['page']); ?>.php" title="次：<?php echo $_h($_next['title']); ?>" aria-label="次の作品 <?php echo $_h($_next['title']); ?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
    <?php endif; ?>
    <span class="fn-sep fn-wide" aria-hidden="true"></span>
    <button class="float-btn fn-wide" type="button" data-act="fav" aria-pressed="false" title="お気に入り" aria-label="お気に入りに入れる"><i class="fa-regular fa-star" aria-hidden="true"></i></button>
    <button class="float-btn fn-wide" type="button" data-act="share" title="この状態を共有" aria-label="この状態のリンクを共有"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
    <button class="float-btn fn-wide" type="button" data-act="random" title="ランダムに次の1本" aria-label="ランダムに次の1本"><i class="fa-solid fa-shuffle" aria-hidden="true"></i></button>
    <button class="float-btn" type="button" id="floatMenuBtn" title="同じコレクションの作品" aria-label="同じコレクションの作品" aria-expanded="false" aria-controls="floatSubmenu"><i class="fa-solid fa-grip" aria-hidden="true"></i></button>
  </div>

  <div class="float-panel" id="floatSubmenu" role="dialog" aria-label="同じコレクションの作品">
    <div class="fp-acts" aria-label="この作品">
      <button type="button" data-act="fav" aria-pressed="false"><i class="fa-regular fa-star" aria-hidden="true"></i><span>お気に入り</span></button>
      <button type="button" data-act="share"><i class="fa-solid fa-link" aria-hidden="true"></i><span>共有</span></button>
      <button type="button" data-act="random"><i class="fa-solid fa-shuffle" aria-hidden="true"></i><span>ランダム</span></button>
    </div>

    <?php if ($_siblings): ?>
    <p class="fp-head"><?php echo $_h($_navGroupLabel); ?></p>
    <div class="fp-list">
      <?php foreach ($_siblings as $s): $t = pulsePreview($s['page'], 'jpg'); $me = $s['page'] === $_navSelf; ?>
      <a class="fp-item<?php echo $me ? ' is-current' : ''; ?>" href="<?php echo $_h($s['page']); ?>.php"<?php echo $me ? ' aria-current="page"' : ''; ?>>
        <?php if ($t): ?><img src="<?php echo $_h($t); ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="fp-noimg"></span><?php endif; ?>
        <span class="fp-name"><?php echo $_h($s['title']); ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="fp-foot">
      <a href="contents.php">さがす</a>
      <a href="about.php">PULSE について</a>
    </div>
  </div>
</div>

<style>
.floating-nav {
  position: fixed;
  left: 1.25rem;
  bottom: 1.25rem;
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: 0.2rem;
  max-width: calc(100vw - 2.5rem);
  padding: 0.35rem 0.4rem 0.35rem 0.35rem;
  background: rgba(13, 34, 56, 0.84);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid var(--line-mid);
  border-radius: 999px;
  box-shadow: 0 10px 34px rgba(0, 0, 0, 0.45);
}
.fn-home {
  width: 38px; height: 38px; flex: none;
  display: grid; place-items: center;
  border-radius: 50%;
  transition: background var(--dur) var(--ease);
}
.fn-home:hover { background: var(--teal-soft); }
.fn-dot {
  width: 9px; height: 9px; border-radius: 50%;
  background: var(--teal); box-shadow: 0 0 10px var(--teal);
  animation: beat 2.4s var(--ease) infinite;
}
.fn-label { display: flex; flex-direction: column; min-width: 0; padding: 0 0.7rem 0 0.15rem; line-height: 1.25; }
.fn-title {
  font-family: var(--font-display); font-weight: 700;
  font-size: 0.86rem; letter-spacing: 0.08em; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  display: flex; align-items: center; gap: 0.4rem;
}
.fn-sub { font-size: 0.66rem; color: var(--text-3); white-space: nowrap; }
.fn-pos { font-family: var(--font-mono); margin-left: 0.3rem; }
.fn-actions { display: flex; align-items: center; gap: 0.15rem; }
.fn-sep { width: 1px; height: 18px; background: var(--line-mid); margin: 0 0.25rem; }

.float-btn {
  width: 36px; height: 36px; flex: none;
  display: grid; place-items: center;
  border: 0; border-radius: 50%;
  background: transparent;
  color: var(--text-2);
  font-size: 0.86rem;
  cursor: pointer;
  text-decoration: none;
  transition: background var(--dur) var(--ease), color var(--dur) var(--ease);
}
.float-btn:hover { background: var(--teal-soft); color: var(--teal); }
.float-btn[aria-pressed="true"], .float-btn[aria-expanded="true"] { color: var(--teal); }
.floating-nav .pa-sound-btn { width: 36px; height: 36px; border: 0; background: transparent; }

/* --- パネル --- */
.float-panel {
  position: absolute;
  left: 0;
  bottom: calc(100% + 0.6rem);
  width: 300px;
  max-width: calc(100vw - 2.5rem);
  padding: 0.9rem;
  background: rgba(10, 28, 47, 0.97);
  backdrop-filter: blur(14px);
  border: 1px solid var(--line-mid);
  border-radius: 10px;
  box-shadow: 0 16px 44px rgba(0, 0, 0, 0.55);
  opacity: 0;
  visibility: hidden;
  transform: translateY(6px);
  transition: opacity 0.25s var(--ease), transform 0.25s var(--ease), visibility 0.25s;
}
.float-panel.open { opacity: 1; visibility: visible; transform: none; }
.fp-acts { display: none; gap: 0.4rem; margin-bottom: 0.8rem; }
.fp-acts button {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.3rem;
  padding: 0.55rem 0.2rem; font-size: 0.7rem; color: var(--text-2);
  background: var(--ink-2); border: 1px solid var(--line); border-radius: 6px; cursor: pointer;
}
.fp-acts button[aria-pressed="true"] { color: var(--teal); border-color: var(--teal-line); }
.fp-head { font-size: 0.72rem; color: var(--text-3); margin-bottom: 0.45rem; }
.fp-list { display: grid; gap: 0.2rem; max-height: 44vh; overflow-y: auto; }
.fp-item {
  display: flex; align-items: center; gap: 0.65rem;
  padding: 0.3rem; border-radius: 5px;
  text-decoration: none; color: var(--text-2);
  transition: background 0.2s var(--ease), color 0.2s var(--ease);
}
.fp-item:hover { background: var(--teal-soft); color: var(--teal); }
.fp-item.is-current { color: var(--teal); background: rgba(95, 208, 255, 0.06); }
.fp-item img, .fp-noimg {
  width: 56px; height: 32px; flex: none; object-fit: cover;
  border-radius: 2px; background: var(--ink-0); opacity: 0.8;
}
.fp-item:hover img, .fp-item.is-current img { opacity: 1; }
.fp-name {
  font-family: var(--font-display); font-weight: 700;
  font-size: 0.74rem; letter-spacing: 0.06em;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fp-foot {
  display: flex; gap: 0.3rem; margin-top: 0.75rem;
  padding-top: 0.6rem; border-top: 1px solid var(--line);
}
.fp-foot a {
  flex: 1 1 auto; text-align: center; padding: 0.4rem 0.2rem; white-space: nowrap;
  font-size: 0.7rem; color: var(--text-3); text-decoration: none; border-radius: 4px;
  transition: all 0.2s var(--ease);
}
.fp-foot a:hover { color: var(--teal); background: var(--teal-soft); }

@media (max-width: 768px) {
  .floating-nav { left: 0.75rem; bottom: 0.75rem; max-width: calc(100vw - 5.5rem); }
  .fn-wide { display: none; }
  .fn-title { font-size: 0.78rem; }
  .fp-acts { display: flex; }
  .float-panel { width: min(86vw, 300px); }
}
</style>
<script>
(function () {
  var pages = <?php echo json_encode($_navPages); ?>;
  var nav = document.getElementById('floatingNav');
  var menuBtn = document.getElementById('floatMenuBtn');
  var panel = document.getElementById('floatSubmenu');

  function act(name) {
    if (name === 'random' && pages.length) {
      location.href = pages[Math.floor(Math.random() * pages.length)] + '.php';
    } else if (name === 'fav' && window.PULSE && PULSE.fav) {
      var on = PULSE.fav.toggle(PULSE.page);
      PULSE.toast(on ? 'お気に入りに入れました' : 'お気に入りから外しました');
    } else if (name === 'share' && window.PULSE && PULSE.share) {
      PULSE.share();
    }
  }
  nav.querySelectorAll('[data-act]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.stopPropagation(); act(b.dataset.act); });
  });

  function paint() {
    if (!window.PULSE || !PULSE.fav) return;
    var fav = PULSE.fav.has(PULSE.page);
    nav.querySelectorAll('[data-act="fav"]').forEach(function (b) {
      b.setAttribute('aria-pressed', fav ? 'true' : 'false');
      b.setAttribute('aria-label', fav ? 'お気に入りから外す' : 'お気に入りに入れる');
      var i = b.querySelector('i');
      i.className = (fav ? 'fa-solid' : 'fa-regular') + ' fa-star';
    });
  }
  document.addEventListener('DOMContentLoaded', paint);
  document.addEventListener('pulse:fav', paint);

  function close() { panel.classList.remove('open'); menuBtn.setAttribute('aria-expanded', 'false'); }
  menuBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = panel.classList.toggle('open');
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      var cur = panel.querySelector('.fp-item.is-current');
      if (cur) cur.scrollIntoView({ block: 'nearest' });
    }
  });
  document.addEventListener('click', function (e) { if (!nav.contains(e.target)) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
