<?php
/**
 * 上部ステータスバー（index/about/contents/log共通）
 *
 * Requires: $T (i18n-home.php), $latestLog (latest-log.php), $lang
 *
 * Optional: $activeNav  ('home' | 'contents' | 'log' | 'about')
 *           デフォルトは 'home'。is-active 表示用。
 */
// contents-data.php sets $lang and the cookie; required for the language toggle to function
if (!isset($lang)) {
    require_once __DIR__ . '/contents-data.php';
}
if (!isset($T)) {
    require_once __DIR__ . '/i18n-home.php';
}
if (!isset($latestLog)) {
    require_once __DIR__ . '/latest-log.php';
}
if (!isset($activeNav)) $activeNav = 'home';
?>
<div class="topbar">
  <div class="topbar-left">
    <a href="index.php" style="display: inline-flex; align-items: center; gap: 14px; color: inherit; text-decoration: none;">
      <span class="dot dot-pulse"></span>
      <span class="topbar-brand">PULSE</span>
    </a>
    <span class="topbar-meta">
      <?= htmlspecialchars($latestLog['date'] ?? '') ?>
      &nbsp;·&nbsp;
      <?= $T['top_day_word'] ?> <?= str_pad((string)($latestLog['day'] ?? 0), 3, '0', STR_PAD_LEFT) ?>
      &nbsp;·&nbsp;
      <span style="color: var(--accent);"><?= $T['top_live'] ?></span>
    </span>
  </div>
  <nav class="topbar-nav" aria-label="primary">
    <a href="index.php" class="topbar-link<?= $activeNav === 'home' ? ' is-active' : '' ?>"><?= $T['nav_home'] ?></a>
    <a href="contents.php" class="topbar-link<?= $activeNav === 'contents' ? ' is-active' : '' ?>"><?= $T['nav_contents'] ?></a>
    <a href="log.php" class="topbar-link<?= $activeNav === 'log' ? ' is-active' : '' ?>"><?= $T['nav_log'] ?></a>
    <a href="about.php" class="topbar-link<?= $activeNav === 'about' ? ' is-active' : '' ?>"><?= $T['nav_about'] ?></a>
    <?php require __DIR__ . '/lang-toggle.php'; ?>
  </nav>
</div>

<style>
/* topbar.php がページ単独で使われるとき用の最小スタイル（index.php側にもあるが重複OK）*/
:root {
  --rule: rgba(255, 255, 255, 0.12);
  --txt-soft: rgba(255, 255, 255, 0.5);
  --txt-dim: rgba(255, 255, 255, 0.32);
}
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 32px;
  border-bottom: 1px solid var(--rule);
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  position: relative;
  z-index: 5;
}
.topbar-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.topbar-brand { font-weight: 700; letter-spacing: 0.4em; color: var(--accent, #00f5d4); }
.topbar-meta  { color: var(--txt-dim); }
.dot {
  display: inline-block; width: 8px; height: 8px; border-radius: 50%;
  background: var(--accent, #00f5d4);
  box-shadow: 0 0 10px rgba(0, 245, 212, 0.7);
}
.dot-pulse { animation: dotpulse 2s ease-in-out infinite; }
@keyframes dotpulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.55; transform: scale(0.85); }
}
.topbar-nav { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
.topbar-link {
  color: var(--txt-dim);
  border-bottom: 1px solid transparent;
  padding-bottom: 3px;
  text-decoration: none;
  transition: color 0.2s, border-color 0.2s;
}
.topbar-link:hover { color: var(--txt-soft); }
.topbar-link.is-active {
  color: var(--accent, #00f5d4);
  border-bottom-color: var(--accent, #00f5d4);
}
@media (max-width: 768px) {
  .topbar { flex-wrap: wrap; gap: 10px 18px; padding: 12px 18px; font-size: 10px; }
  .topbar-nav { width: 100%; gap: 14px; padding-top: 6px; border-top: 1px dashed var(--rule); }
}
</style>
