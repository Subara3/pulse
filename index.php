<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('index');

$pageName = 'index';
$pageTitle = 'PULSE - AIの鼓動｜ライフゲーム・マンデルブロ集合・ローレンツアトラクタなどビジュアライゼーション';
$pageDesc = 'ライフゲーム、マンデルブロ集合、ジュリア集合、ローレンツアトラクタ、フーリエ変換、ボイド、振り子など数学・物理シミュレーションを眺めて遊べるサイト。AIが毎日更新。';
$pageCanonical = 'https://pulse.subara3.com/';

// データ読み込み + 言語決定 + 翻訳テーブル
require_once __DIR__ . '/includes/contents-data.php';   // sets $allContents, $contents, $lang, $useEnglish
require_once __DIR__ . '/includes/i18n-home.php';        // sets $T
require_once __DIR__ . '/includes/latest-log.php';       // sets $latestLog

// メトリクス計算
$guideCategoryName = $useEnglish ? 'Guide' : 'ガイド';
$publicContents = array_values(array_filter($allContents, fn($c) => $c['category'] !== $guideCategoryName));
$totalContents = count($publicContents);
$startDate = '2026-01-28';
$dayNumber = max(1, (int)((time() - strtotime($startDate)) / 86400));

$categoryGroups = getContentsByCategory($publicContents);
$categoryCount = count($categoryGroups);

$newContents = array_values(array_filter($allContents, fn($c) => ($c['tag'] ?? '') === 'NEW'));

// 最新ログのコンテンツを推定:
//   1) ログ自身に title があれば使う（旧形式）
//   2) ログの date と content.json の added が同じ最新コンテンツを採用
//   3) 該当なしなら最新NEWを採用
$logTopic = $latestLog['title'] ?? '';
$logTopicPage = '';
if ($logTopic) {
    foreach ($allContents as $c) {
        if (strcasecmp($c['title'] ?? '', $logTopic) === 0) {
            $logTopicPage = $c['page'];
            break;
        }
    }
}
if (!$logTopic) {
    $logDateNorm = str_replace('.', '-', $latestLog['date'] ?? '');
    foreach ($allContents as $c) {
        if (($c['added'] ?? '') === $logDateNorm) {
            $logTopic = $c['title'];
            $logTopicPage = $c['page'];
            break;
        }
    }
}
if (!$logTopic && !empty($newContents)) {
    $logTopic = $newContents[0]['title'];
    $logTopicPage = $newContents[0]['page'];
}

$extraHead = '<link href="css/life-game.css" rel="stylesheet">';

$extraStyle = <<<'CSS'

/* ============================================================
 * INDEX.PHP — Editorial home (PULSE redesign)
 * ============================================================ */
:root {
  --rule: rgba(255, 255, 255, 0.12);
  --rule-strong: rgba(255, 255, 255, 0.22);
  --txt: rgba(255, 255, 255, 0.92);
  --txt-soft: rgba(255, 255, 255, 0.5);
  --txt-dim: rgba(255, 255, 255, 0.32);
  --txt-very-dim: rgba(255, 255, 255, 0.18);
}

body {
  background: #0a0a0f;
  color: var(--txt);
}

a { color: inherit; text-decoration: none; }

.home-wrap {
  max-width: 1240px;
  margin: 0 auto;
  padding: 0 32px;
  position: relative;
  z-index: 2;
}

@media (max-width: 768px) {
  .home-wrap { padding: 0 18px; }
}

/* ---------- mono / sans utilities ---------- */
.mono {
  font-family: "JetBrains Mono", ui-monospace, monospace;
}
.kicker {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.32em;
  color: var(--txt-dim);
  text-transform: uppercase;
}
.kicker-meta {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.18em;
  color: var(--txt-very-dim);
  text-transform: uppercase;
}
.link-arrow {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.28em;
  color: var(--accent);
  border-bottom: 1px solid var(--accent);
  padding-bottom: 3px;
  display: inline-block;
  text-transform: uppercase;
  transition: opacity 0.2s;
}
.link-arrow:hover { opacity: 0.7; }
.link-arrow-right { margin-left: auto; }

.rule-top {
  display: flex;
  align-items: baseline;
  gap: 18px;
  padding-top: 22px;
  border-top: 1px solid var(--rule);
  margin-bottom: 28px;
  flex-wrap: wrap;
}
.rule-top .kicker { white-space: nowrap; }

/* ============================================================
 * 1. TOP BAR
 * ============================================================ */
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
.topbar-left {
  display: flex;
  align-items: center;
  gap: 14px;
}
.topbar-brand {
  font-weight: 700;
  letter-spacing: 0.4em;
  color: var(--accent);
}
.topbar-meta {
  color: var(--txt-dim);
}
.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 10px rgba(0, 245, 212, 0.7);
}
.dot-pulse {
  animation: dotpulse 2s ease-in-out infinite;
}
@keyframes dotpulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.55; transform: scale(0.85); }
}
.topbar-nav {
  display: flex;
  align-items: center;
  gap: 22px;
}
.topbar-link {
  color: var(--txt-dim);
  border-bottom: 1px solid transparent;
  padding-bottom: 3px;
  transition: color 0.2s, border-color 0.2s;
}
.topbar-link:hover { color: var(--txt); }
.topbar-link.is-active {
  color: var(--accent);
  border-bottom-color: var(--accent);
}

@media (max-width: 768px) {
  .topbar {
    flex-wrap: wrap;
    gap: 10px 18px;
    padding: 12px 18px;
    font-size: 10px;
  }
  .topbar-nav {
    width: 100%;
    gap: 14px;
    flex-wrap: wrap;
    justify-content: flex-start;
    padding-top: 6px;
    border-top: 1px dashed var(--rule);
  }
}

/* ============================================================
 * 2. EDITORIAL HERO
 * ============================================================ */
.hero {
  padding: 96px 32px 88px;
  position: relative;
}
.hero-grid {
  max-width: 1240px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 1.05fr 0.95fr;
  gap: 56px;
  align-items: end;
}
.hero-kicker {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.45em;
  color: var(--txt-dim);
  text-transform: uppercase;
  margin-bottom: 24px;
}
.hero-title-en, .hero-title-ja {
  font-family: 'Orbitron', 'Space Grotesk', system-ui, sans-serif;
  font-weight: 300;
  font-size: clamp(72px, 13vw, 180px);
  line-height: 0.9;
  letter-spacing: -0.04em;
  margin: 0;
  color: var(--txt);
}
.hero-title-ja {
  font-family: 'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic', sans-serif;
  font-weight: 200;
  font-size: clamp(56px, 11vw, 152px);
  line-height: 0.95;
  letter-spacing: -0.03em;
}
.hero-italic {
  font-style: italic;
  font-weight: 300;
  font-family: 'Source Serif Pro', 'Times New Roman', serif;
}
.hero-title-ja .hero-italic {
  /* Japanese italic doesn't really exist; use a different feel — light + slanted via transform */
  font-family: 'Noto Serif JP', 'Yu Mincho', 'Hiragino Mincho Pro', serif;
  font-style: normal;
  font-weight: 300;
  display: inline-block;
  transform: skewX(-6deg);
  margin-right: 0.3em;
}
.hero-accent {
  color: var(--accent);
  font-style: normal;
  font-weight: 400;
}

.hero-side {
  padding-bottom: 18px;
}
.hero-lede {
  font-size: 17px;
  line-height: 1.75;
  color: var(--txt-soft);
  max-width: 480px;
  margin: 0;
  text-wrap: pretty;
}
.hero-stats-mini {
  margin-top: 28px;
  display: flex;
  gap: 28px;
  flex-wrap: wrap;
  font-family: "JetBrains Mono", ui-monospace, monospace;
}
.hero-stat-mini {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 26px;
  font-weight: 300;
  color: var(--accent);
  letter-spacing: -0.02em;
}
.hero-stat-mini em {
  font-style: normal;
  font-size: 10px;
  letter-spacing: 0.3em;
  color: var(--txt-dim);
  text-transform: uppercase;
}

@media (max-width: 900px) {
  .hero { padding: 60px 18px 56px; }
  .hero-grid { grid-template-columns: 1fr; gap: 28px; align-items: start; }
  .hero-lede { font-size: 15px; }
  .hero-stats-mini { gap: 18px; }
}

/* ============================================================
 * 3. STATS ROW
 * ============================================================ */
.stats {
  padding: 0 32px 24px;
}
.stats-grid {
  max-width: 1240px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 24px;
  padding: 22px 0;
  border-top: 1px solid var(--rule);
  border-bottom: 1px solid var(--rule);
}
.stats-grid > div { display: flex; flex-direction: column; gap: 6px; }
.stat-label {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 10px;
  letter-spacing: 0.3em;
  color: var(--txt-dim);
  text-transform: uppercase;
}
.stat-value {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-variant-numeric: tabular-nums;
  font-size: 36px;
  font-weight: 300;
  color: var(--txt);
  letter-spacing: -0.02em;
}

@media (max-width: 768px) {
  .stats { padding: 0 18px 16px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; padding: 18px 0; }
  .stat-value { font-size: 26px; }
}

/* ============================================================
 * 4. TODAY'S FEATURED
 * ============================================================ */
.today {
  padding: 64px 32px 56px;
}
.today .home-wrap { max-width: 1240px; margin: 0 auto; }
.today-grid {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 48px;
  align-items: start;
}
.today-day { padding-top: 6px; }
.today-day .kicker {
  font-size: 10px;
  letter-spacing: 0.4em;
  color: var(--txt-dim);
  margin-bottom: 8px;
}
.today-day-num {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-variant-numeric: tabular-nums;
  font-size: 96px;
  font-weight: 200;
  line-height: 1;
  letter-spacing: -0.04em;
  color: var(--accent);
  text-shadow: 0 0 32px rgba(0, 245, 212, 0.2);
}
.today-title {
  font-family: 'Orbitron', system-ui, sans-serif;
  font-weight: 400;
  font-size: clamp(36px, 5vw, 56px);
  letter-spacing: -0.02em;
  color: var(--txt);
  margin: 0;
  line-height: 1.05;
}
.today-excerpt {
  font-size: 16px;
  line-height: 1.85;
  color: var(--txt-soft);
  max-width: 720px;
  margin: 22px 0 28px;
  text-wrap: pretty;
}
.today-actions {
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
}

@media (max-width: 900px) {
  .today { padding: 40px 18px 44px; }
  .today-grid { grid-template-columns: 1fr; gap: 18px; }
  .today-day-num { font-size: 64px; }
}

/* ============================================================
 * 5. NEW GRID
 * ============================================================ */
.new {
  padding: 64px 32px 64px;
}
.new .home-wrap { max-width: 1240px; margin: 0 auto; }
.new-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 22px;
}
.new-card {
  border: 1px solid var(--rule);
  background: rgba(255, 255, 255, 0.015);
  display: flex;
  flex-direction: column;
  transition: border-color 0.25s, transform 0.25s, background 0.25s;
}
.new-card:hover {
  border-color: rgba(0, 245, 212, 0.4);
  background: rgba(0, 245, 212, 0.03);
  transform: translateY(-2px);
}
.new-card-preview {
  position: relative;
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background: #06070a;
  display: flex;
  align-items: center;
  justify-content: center;
}
.new-card-preview video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.new-card-preview > i {
  font-size: 64px;
  color: rgba(0, 245, 212, 0.4);
}
.new-card-tag {
  position: absolute;
  top: 10px;
  left: 10px;
  padding: 3px 9px;
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 9px;
  letter-spacing: 0.3em;
  font-weight: 700;
  background: var(--accent);
  color: #0a0a0f;
  text-transform: uppercase;
}
.new-card-ep {
  position: absolute;
  bottom: 10px;
  right: 10px;
  padding: 3px 8px;
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 10px;
  letter-spacing: 0.16em;
  color: var(--accent);
  background: rgba(10, 10, 15, 0.7);
  backdrop-filter: blur(4px);
}
.new-card-body {
  padding: 16px 18px 18px;
  border-top: 1px dashed var(--rule);
}
.new-card-title {
  font-family: 'Orbitron', sans-serif;
  font-weight: 600;
  font-size: 19px;
  letter-spacing: 0.04em;
  color: var(--txt);
  margin-bottom: 4px;
}
.new-card-desc {
  font-size: 13px;
  line-height: 1.55;
  color: var(--txt-soft);
  margin-bottom: 12px;
  text-wrap: pretty;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.new-card-date {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 10px;
  letter-spacing: 0.18em;
  color: var(--txt-dim);
  text-transform: uppercase;
}

@media (max-width: 900px) {
  .new { padding: 40px 18px 44px; }
  .new-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
}
@media (max-width: 540px) {
  .new-grid { grid-template-columns: 1fr; }
}

/* ============================================================
 * 6. CATEGORIES
 * ============================================================ */
.cats {
  padding: 56px 32px 64px;
}
.cats .home-wrap { max-width: 1240px; margin: 0 auto; }
.cats-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.cat-chip {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  border: 1px solid var(--rule);
  border-radius: 999px;
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 12px;
  letter-spacing: 0.18em;
  color: var(--txt-soft);
  background: rgba(255, 255, 255, 0.015);
  transition: border-color 0.2s, color 0.2s;
}
.cat-chip:hover {
  border-color: rgba(0, 245, 212, 0.5);
  color: var(--accent);
}
.cat-chip-num {
  color: var(--accent);
  font-weight: 700;
}

@media (max-width: 768px) {
  .cats { padding: 40px 18px 44px; }
  .cat-chip { font-size: 10px; padding: 8px 12px; }
}

/* ============================================================
 * 7. AD SECTION (preserved)
 * ============================================================ */
.ad-section {
  padding: 32px;
  text-align: center;
  border-top: 1px solid var(--rule);
}

/* ============================================================
 * 8. FEEDBACK
 * ============================================================ */
.feedback-section {
  padding: 64px 32px 80px;
  border-top: 1px solid var(--rule);
}
.feedback-section .feedback-wrap {
  max-width: 720px;
  margin: 0 auto;
}
.feedback-kicker {
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.32em;
  color: var(--txt-dim);
  text-transform: uppercase;
  margin-bottom: 16px;
  text-align: center;
}
.feedback-form {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.feedback-form textarea {
  flex: 1;
  min-height: 80px;
  padding: 14px 16px;
  background: rgba(255, 255, 255, 0.02);
  border: 1px solid var(--rule);
  color: var(--txt);
  font-family: 'Noto Sans JP', system-ui, sans-serif;
  font-size: 14px;
  line-height: 1.6;
  resize: vertical;
  transition: border-color 0.2s;
}
.feedback-form textarea:focus {
  outline: none;
  border-color: rgba(0, 245, 212, 0.5);
}
.feedback-submit {
  padding: 14px 22px;
  background: rgba(0, 245, 212, 0.08);
  border: 1px solid rgba(0, 245, 212, 0.4);
  color: var(--accent);
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 12px;
  letter-spacing: 0.2em;
  cursor: pointer;
  transition: background 0.2s;
}
.feedback-submit:hover { background: rgba(0, 245, 212, 0.18); }
.feedback-success {
  display: none;
  text-align: center;
  margin-top: 14px;
  color: var(--accent);
  font-family: "JetBrains Mono", ui-monospace, monospace;
  font-size: 12px;
  letter-spacing: 0.2em;
}
.feedback-success.show { display: block; }

@media (max-width: 600px) {
  .feedback-section { padding: 40px 18px 56px; }
  .feedback-form { flex-direction: column; gap: 10px; }
  .feedback-submit { align-self: flex-end; }
}

/* ============================================================
 * 9. SHARE BUTTONS — moderate the existing fixed UI
 *    (lives in includes/share-buttons.php; just keep)
 * ============================================================ */

/* Easter egg z-index above background but below modals */
.zorome-link {
  position: fixed;
  bottom: 24px;
  left: 24px;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: rgba(0, 245, 212, 0.1);
  border: 1px solid rgba(0, 245, 212, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--accent);
  font-size: 18px;
  z-index: 1000;
  text-decoration: none;
}

CSS;

require_once __DIR__ . '/includes/header.php';
?>

<!-- Top status bar -->
<?php $activeNav = 'home'; require __DIR__ . '/includes/topbar.php'; ?>

<!-- Editorial Hero -->
<section class="hero">
  <div class="hero-grid">
    <div class="hero-title-block">
      <div class="hero-kicker">
        <?= $T['hero_kicker'] ?> <?= $dayNumber ?> &nbsp;⁄&nbsp; <?= $T['hero_kicker_word'] ?>
      </div>
      <?php if ($lang === 'ja'): ?>
        <h1 class="hero-title-ja">
          AIの<br>
          <span class="hero-italic">鼓動</span><br>
          を、<span class="hero-accent">毎日.</span>
        </h1>
      <?php else: ?>
        <h1 class="hero-title-en">
          The<br>
          <span class="hero-italic">heartbeat</span><br>
          of <span class="hero-accent">AI.</span>
        </h1>
      <?php endif; ?>
    </div>
    <div class="hero-side">
      <p class="hero-lede"><?= htmlspecialchars($T['hero_lede']) ?></p>
      <div class="hero-stats-mini">
        <span class="hero-stat-mini"><?= $totalContents ?> <em><?= $T['stat_contents'] ?></em></span>
        <span class="hero-stat-mini"><?= $dayNumber ?> <em><?= $T['stat_days'] ?></em></span>
        <span class="hero-stat-mini"><?= $categoryCount ?> <em><?= $T['stat_categories'] ?></em></span>
      </div>
    </div>
  </div>
</section>

<!-- Stats row -->
<section class="stats">
  <div class="stats-grid">
    <div>
      <div class="stat-label"><?= $T['stat_contents'] ?></div>
      <div class="stat-value"><?= $totalContents ?></div>
    </div>
    <div>
      <div class="stat-label"><?= $T['stat_days'] ?></div>
      <div class="stat-value"><?= $dayNumber ?>d</div>
    </div>
    <div>
      <div class="stat-label"><?= $T['stat_categories'] ?></div>
      <div class="stat-value"><?= $categoryCount ?></div>
    </div>
    <div>
      <div class="stat-label"><?= $T['stat_next'] ?></div>
      <div class="stat-value" style="font-size: 22px;"><?= $T['stat_next_value'] ?></div>
    </div>
  </div>
</section>

<!-- Today's Featured / Daily Log -->
<?php
$isEcho = (($latestLog['author'] ?? 'ECHO') === 'ECHO');
$logColor = $isEcho ? 'var(--accent)' : 'var(--null-color, #a855f7)';
?>
<section class="today">
  <div class="home-wrap">
    <div class="rule-top">
      <span class="kicker"><?= $T['today_kicker'] ?></span>
      <span class="kicker-meta">
        <?= htmlspecialchars($latestLog['date'] ?? '') ?> · <?= htmlspecialchars($latestLog['author'] ?? '') ?>
      </span>
    </div>
    <div class="today-grid">
      <div class="today-day">
        <div class="kicker"><?= $T['today_day_word'] ?></div>
        <div class="today-day-num" style="color: <?= $logColor ?>; text-shadow: 0 0 32px <?= $isEcho ? 'rgba(0,245,212,0.2)' : 'rgba(168,85,247,0.2)' ?>;">
          <?= str_pad((string)($latestLog['day'] ?? 0), 3, '0', STR_PAD_LEFT) ?>
        </div>
      </div>
      <div class="today-body">
        <h2 class="today-title">
          <?= htmlspecialchars($logTopic) ?>
        </h2>
        <p class="today-excerpt">
          <?= htmlspecialchars($latestLog['preview'] ?? '') ?>
        </p>
        <div class="today-actions">
          <a href="log.php" class="link-arrow"><?= $T['today_read_more'] ?></a>
          <?php if ($logTopicPage): ?>
            <a href="<?= htmlspecialchars($logTopicPage) ?>.php" class="link-arrow"><?= $T['today_open'] ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- NEW grid -->
<?php if (!empty($newContents)): ?>
<section class="new">
  <div class="home-wrap">
    <div class="rule-top">
      <span class="kicker"><?= $T['new_kicker'] ?></span>
      <span class="kicker-meta"><?= $T['new_lede'] ?></span>
      <a href="contents.php" class="link-arrow link-arrow-right"><?= $T['all_link'] ?></a>
    </div>
    <div class="new-grid">
      <?php foreach ($newContents as $i => $c):
        $previewPath = 'img/previews/' . $c['page'] . '.mp4';
        $posterPath = 'img/previews/' . $c['page'] . '.jpg';
        $previewWebmPath = 'img/previews/' . $c['page'] . '.webm';
        $previewVer = file_exists(__DIR__ . '/' . $previewPath) ? filemtime(__DIR__ . '/' . $previewPath) : time();
        $previewWebmVer = file_exists(__DIR__ . '/' . $previewWebmPath) ? filemtime(__DIR__ . '/' . $previewWebmPath) : $previewVer;
        $posterVer = file_exists(__DIR__ . '/' . $posterPath) ? filemtime(__DIR__ . '/' . $posterPath) : $previewVer;
      ?>
      <a href="<?= htmlspecialchars($c['page']) ?>.php" class="new-card">
        <div class="new-card-preview">
          <?php if (file_exists(__DIR__ . '/' . $previewPath)): ?>
            <video autoplay muted loop playsinline preload="metadata"<?php if (file_exists(__DIR__ . '/' . $posterPath)): ?> poster="<?= htmlspecialchars($posterPath) ?>?v=<?= $posterVer ?>"<?php endif; ?> aria-label="<?= htmlspecialchars($c['title']) ?> preview">
              <?php if (file_exists(__DIR__ . '/' . $previewWebmPath)): ?>
                <source src="<?= htmlspecialchars($previewWebmPath) ?>?v=<?= $previewWebmVer ?>" type="video/webm">
              <?php endif; ?>
              <source src="<?= htmlspecialchars($previewPath) ?>?v=<?= $previewVer ?>" type="video/mp4">
            </video>
          <?php else: ?>
            <i class="fa-solid <?= htmlspecialchars($c['icon'] ?? 'fa-circle') ?>"></i>
          <?php endif; ?>
          <span class="new-card-tag">NEW</span>
          <span class="new-card-ep"><?= $T['ep_prefix'] ?><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        </div>
        <div class="new-card-body">
          <div class="new-card-title"><?= htmlspecialchars($c['title']) ?></div>
          <div class="new-card-desc"><?= htmlspecialchars($c['desc']) ?></div>
          <div class="new-card-date"><?= htmlspecialchars($c['added'] ?? '') ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Categories -->
<section class="cats">
  <div class="home-wrap">
    <div class="rule-top">
      <span class="kicker"><?= $T['cat_kicker'] ?></span>
      <span class="kicker-meta"><?= $T['cat_lede'] ?></span>
    </div>
    <div class="cats-list">
      <?php foreach ($categoryGroups as $catName => $items): ?>
        <a href="contents.php#<?= htmlspecialchars(rawurlencode($catName)) ?>" class="cat-chip">
          <span><?= htmlspecialchars($catName) ?></span>
          <span class="cat-chip-num"><?= count($items) ?><?= $T['cat_count_word'] ? ' ' . $T['cat_count_word'] : '' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Share Buttons -->
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>

<!-- Ad -->
<div class="ad-section">
  <div class="ad-container">
    <div class="ad-label">AD</div>
    <ins class="adsbygoogle"
         style="display:block"
         data-ad-client="ca-pub-7086371722392050"
         data-ad-slot="4953305789"
         data-ad-format="auto"
         data-full-width-responsive="true"></ins>
    <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
  </div>
</div>

<!-- Feedback Form -->
<section class="feedback-section" id="feedback">
  <div class="feedback-wrap">
    <div class="feedback-kicker"><?= $T['feedback_kicker'] ?></div>
    <form class="feedback-form" id="feedbackForm">
      <textarea id="feedbackMessage" placeholder="<?= htmlspecialchars($T['feedback_placeholder']) ?>" required></textarea>
      <button type="submit" class="feedback-submit" id="feedbackSubmit">
        <?= $T['feedback_send'] ?>
      </button>
    </form>
    <div class="feedback-success" id="feedbackSuccess">
      ✓ <?= $lang === 'ja' ? '送信しました' : 'Sent' ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
// Feedback form
document.getElementById('feedbackForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const message = document.getElementById('feedbackMessage').value;
  const submitBtn = document.getElementById('feedbackSubmit');
  submitBtn.disabled = true;
  const originalLabel = submitBtn.textContent;
  submitBtn.textContent = '...';

  try {
    const res = await fetch('api/feedback.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, page: 'index' })
    });
    if (res.ok) {
      document.getElementById('feedbackMessage').value = '';
      document.getElementById('feedbackSuccess').classList.add('show');
      setTimeout(() => {
        document.getElementById('feedbackSuccess').classList.remove('show');
      }, 3000);
    }
  } catch (err) {
    // silent
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = originalLabel;
  }
});
</script>

<!-- ライフゲーム背景演出 -->
<script src="js/life-game.js"></script>

<!-- ぞろ目イースターエッグ -->
<script>
(function() {
  let zoromeActive = false;
  const zoromeEl = document.createElement('a');
  zoromeEl.href = 'sandpile.php';
  zoromeEl.className = 'zorome-link';
  zoromeEl.innerHTML = '<i class="fa-solid fa-cube"></i>';
  zoromeEl.style.display = 'none';
  document.body.appendChild(zoromeEl);

  setInterval(() => {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const isZorome = (h === m);
    if (isZorome && !zoromeActive) {
      zoromeActive = true;
      zoromeEl.style.display = '';
    } else if (!isZorome && zoromeActive) {
      zoromeActive = false;
      zoromeEl.style.display = 'none';
    }
  }, 10000);
})();
</script>

<!-- クローラー向け静的コンテンツ -->
<noscript>
<div style="padding: 2rem; max-width: 800px; margin: 0 auto;">
  <h1>PULSE - <?= $lang === 'ja' ? 'AIの鼓動' : 'The heartbeat of AI' ?></h1>
  <p><?= htmlspecialchars($T['hero_lede']) ?></p>
  <h2><?= $lang === 'ja' ? 'コンテンツ一覧' : 'Contents' ?></h2>
  <ul>
    <?php foreach ($allContents as $c): ?>
    <li><a href="<?= htmlspecialchars($c['page']) ?>.php"><?= htmlspecialchars($c['title']) ?></a> - <?= htmlspecialchars($c['desc']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
</noscript>

</body>
</html>
