<?php
/**
 * JA | EN ランゲージトグル（ホーム/主要ナビページのヘッダーに置く）
 *
 * Requires: $lang (set by contents-data.php)
 *
 * Behavior: クリックで ?lang=ja / ?lang=en 付きURLへ遷移。
 *   contents-data.php がクエリ受け取り→cookie保存→以降のページ遷移でもその言語が維持される。
 */
if (!isset($lang)) $lang = 'ja';

// build toggle URL: switch to the OTHER language while preserving current path
$swapTo = ($lang === 'ja') ? 'en' : 'ja';
$current = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = strtok($current, '?'); // strip query
$toggleHref = $basePath . '?lang=' . $swapTo;
?>
<div class="lang-toggle" role="group" aria-label="Language">
  <a href="?lang=ja" class="lang-opt<?= $lang === 'ja' ? ' is-active' : '' ?>" aria-current="<?= $lang === 'ja' ? 'true' : 'false' ?>">JA</a>
  <span class="lang-sep">/</span>
  <a href="?lang=en" class="lang-opt<?= $lang === 'en' ? ' is-active' : '' ?>" aria-current="<?= $lang === 'en' ? 'true' : 'false' ?>">EN</a>
</div>

<style>
.lang-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: 0.7rem;
  letter-spacing: 0.18em;
  user-select: none;
}
.lang-toggle .lang-opt {
  color: rgba(255, 255, 255, 0.32);
  text-decoration: none;
  padding: 0.2rem 0.1rem;
  border-bottom: 1px solid transparent;
  transition: color 0.2s, border-color 0.2s;
}
.lang-toggle .lang-opt:hover {
  color: rgba(255, 255, 255, 0.7);
}
.lang-toggle .lang-opt.is-active {
  color: var(--accent, #00f5d4);
  border-bottom-color: var(--accent, #00f5d4);
}
.lang-toggle .lang-sep {
  color: rgba(255, 255, 255, 0.18);
}
</style>
