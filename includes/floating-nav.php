<?php
/**
 * 全画面Canvasページ用のフローティングナビ。
 *
 * 方針: 1本見たあと「次の1本」に必ず辿り着けること。
 *  - HOME / SHUFFLE は従来どおり
 *  - メニューを開くと「同じコレクションの隣のもの」をサムネ付きで出す
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
$_navGroupEn = $PULSE_COLLECTIONS[$_navGroup]['en'] ?? '';

// 同じコレクションから6本
$_siblings = [];
if ($_navGroup) {
    $_siblings = pulseByCollection($_navPool, $_navGroup);
    shuffle($_siblings);
    $_siblings = array_slice($_siblings, 0, 6);
}
$_navPages = array_map(fn($c) => $c['page'], $_navPool);
?>

<div class="floating-nav" id="floatingNav">
  <a href="index.php" class="float-btn float-back" title="ホームへ" aria-label="ホームへ">
    <i class="fa-solid fa-house"></i>
  </a>
  <button class="float-btn float-random" id="randomBtn" title="ランダムに次の1本" aria-label="ランダムに次の1本">
    <i class="fa-solid fa-shuffle"></i>
  </button>
  <button class="float-btn float-menu" id="floatMenuBtn" title="ほかのページ" aria-label="ほかのページ" aria-expanded="false">
    <i class="fa-solid fa-ellipsis"></i>
  </button>

  <div class="float-panel" id="floatSubmenu" role="dialog" aria-label="ほかのページ">
    <?php if ($_navCurrent): ?>
    <div class="fp-head">
      <span class="fp-en"><?php echo $_navGroupEn; ?></span>
      <span class="fp-ja"><?php echo $_navGroupLabel; ?></span>
    </div>
    <?php endif; ?>

    <?php if ($_siblings): ?>
    <div class="fp-list">
      <?php foreach ($_siblings as $s): $t = pulsePreview($s['page'], 'jpg'); ?>
      <a class="fp-item" href="<?php echo $s['page']; ?>.php">
        <?php if ($t): ?><img src="<?php echo $t; ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="fp-noimg"></span><?php endif; ?>
        <span class="fp-name"><?php echo htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="fp-foot">
      <a href="contents.php<?php echo $_navGroup ? '?g=' . $_navGroup . '#' . $_navGroup : ''; ?>">ARCHIVE</a>
      <a href="log.php">LOG</a>
      <a href="about.php">ABOUT</a>
    </div>
  </div>
</div>

<style>
.floating-nav {
  position: fixed;
  bottom: 2rem;
  left: 2rem;
  display: flex;
  flex-direction: column-reverse;
  gap: 0.7rem;
  z-index: 1000;
}

.float-btn {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: rgba(16, 16, 23, 0.86);
  backdrop-filter: blur(8px);
  border: 1px solid var(--line-mid);
  color: var(--text-2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.05rem;
  cursor: pointer;
  transition: all 0.3s var(--ease);
  text-decoration: none;
}
.float-btn:hover {
  background: var(--teal);
  color: var(--ink-0);
  border-color: var(--teal);
  transform: scale(1.07);
}
.float-back { color: var(--teal); border-color: var(--teal-line); }
.float-random { color: var(--null-color); border-color: rgba(155, 108, 255, 0.35); }
.float-random:hover { background: var(--null-color); color: #fff; border-color: var(--null-color); }

/* --- パネル --- */
.float-panel {
  position: absolute;
  bottom: 0;
  left: 56px;
  width: 268px;
  padding: 0.85rem;
  background: rgba(12, 12, 18, 0.96);
  backdrop-filter: blur(14px);
  border: 1px solid var(--line-mid);
  border-radius: 6px;
  opacity: 0;
  visibility: hidden;
  transform: translateX(-8px);
  transition: all 0.28s var(--ease);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.55);
}
.float-panel.open { opacity: 1; visibility: visible; transform: none; }

.fp-head {
  display: flex; align-items: baseline; gap: 0.5rem;
  padding-bottom: 0.6rem; margin-bottom: 0.6rem;
  border-bottom: 1px solid var(--line);
}
.fp-en {
  font-family: var(--font-mono); font-size: 0.6rem; letter-spacing: 0.2em;
  color: var(--teal); text-transform: uppercase;
}
.fp-ja { font-size: 0.78rem; color: var(--text-2); }

.fp-list { display: grid; gap: 0.25rem; max-height: 46vh; overflow-y: auto; }
.fp-item {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.3rem; border-radius: 4px;
  text-decoration: none; color: var(--text-2);
  transition: background 0.2s var(--ease), color 0.2s var(--ease);
}
.fp-item:hover { background: rgba(0, 245, 212, 0.1); color: var(--teal); }
.fp-item img, .fp-noimg {
  width: 54px; height: 31px; flex: none; object-fit: cover;
  border-radius: 2px; background: var(--ink-0); opacity: 0.75;
}
.fp-item:hover img { opacity: 1; }
.fp-name {
  font-family: var(--font-display); font-weight: 700;
  font-size: 0.72rem; letter-spacing: 0.04em;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.fp-foot {
  display: flex; gap: 0.4rem; margin-top: 0.7rem;
  padding-top: 0.6rem; border-top: 1px solid var(--line);
}
.fp-foot a {
  flex: 1; text-align: center; padding: 0.4rem 0.2rem;
  font-family: var(--font-mono); font-size: 0.6rem; letter-spacing: 0.1em;
  color: var(--text-3); text-decoration: none; border-radius: 3px;
  transition: all 0.2s var(--ease);
}
.fp-foot a:hover { color: var(--teal); background: rgba(0, 245, 212, 0.08); }

@media (max-width: 768px) {
  .floating-nav { bottom: 1rem; left: 1rem; gap: 0.5rem; }
  .float-btn { width: 40px; height: 40px; font-size: 0.9rem; }

  .floating-nav .float-back,
  .floating-nav .float-random {
    opacity: 0; transform: scale(0.5); pointer-events: none;
    transition: all 0.28s var(--ease);
  }
  .floating-nav.expanded .float-back,
  .floating-nav.expanded .float-random {
    opacity: 1; transform: scale(1); pointer-events: auto;
  }
  .floating-nav .float-menu i::before { content: "\f0c9"; }
  .floating-nav.expanded .float-menu i::before { content: "\f00d"; }

  .float-panel { bottom: 48px; left: 0; width: min(78vw, 268px); }
}
</style>

<script>
(function () {
  var pages = <?php echo json_encode($_navPages); ?>;
  var randomBtn = document.getElementById('randomBtn');
  if (randomBtn && pages.length) {
    randomBtn.addEventListener('click', function () {
      location.href = pages[Math.floor(Math.random() * pages.length)] + '.php';
    });
  }

  var menuBtn = document.getElementById('floatMenuBtn');
  var panel = document.getElementById('floatSubmenu');
  var nav = document.getElementById('floatingNav');
  var isMobile = window.innerWidth <= 768;

  function closeAll() {
    if (panel) panel.classList.remove('open');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
    if (isMobile && nav) nav.classList.remove('expanded');
  }

  if (menuBtn && nav) {
    menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (isMobile && !nav.classList.contains('expanded')) {
        nav.classList.add('expanded');
        return;
      }
      var open = panel.classList.toggle('open');
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!nav.contains(e.target)) closeAll();
    });
  }

  document.addEventListener('keydown', function (e) {
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA') return;
    if (e.key === 'Escape') {
      if (panel && panel.classList.contains('open')) { closeAll(); return; }
      location.href = 'index.php';
    }
    /* r でランダム、a でアーカイブ */
    if (e.key === 'r' && pages.length) location.href = pages[Math.floor(Math.random() * pages.length)] + '.php';
    if (e.key === 'a') location.href = 'contents.php';
  });
})();
</script>
