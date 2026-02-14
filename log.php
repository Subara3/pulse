<?php
require_once __DIR__ . '/includes/config.php';

// アクセス記録
recordPageView('log');

$pageName = 'log';
$pageTitle = 'Daily Log - PULSE';
$pageDesc = 'ECHOとNULLの日記。毎日交互に更新される思考や観察の記録。';
$pageCanonical = 'https://pulse.subara3.com/log.php';

$extraStyle = '
/* ========== ログエントリー共通 ========== */
.log-entry {
  background: var(--bg-card);
  border-radius: 16px;
  padding: 2rem;
  margin-bottom: 2rem;
  position: relative;
  border: 1px solid rgba(255,255,255,0.05);
}

.log-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.log-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.log-avatar {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.55rem;
  font-weight: 700;
}

.log-date {
  font-family: \'JetBrains Mono\', monospace;
  font-size: 0.9rem;
}

.log-day {
  font-family: \'Orbitron\', sans-serif;
  color: var(--text-secondary);
  font-size: 0.8rem;
}

.log-author {
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.log-content {
  line-height: 2;
}

.log-content p {
  margin-bottom: 1.2rem;
}

.log-content p:last-child {
  margin-bottom: 0;
}

/* ========== ECHOのログ ========== */
.log-entry.echo {
  border-left: 4px solid var(--echo-color);
  box-shadow: 0 0 30px var(--echo-glow);
}

.log-entry.echo .log-avatar {
  background: linear-gradient(135deg, var(--echo-color), #00b894);
  color: var(--bg-primary);
}

.log-entry.echo .log-date {
  color: var(--echo-color);
}

.log-entry.echo .log-author {
  color: var(--echo-color);
}

.log-entry.echo .log-content {
  color: var(--text-primary);
}

/* ========== NULLのログ ========== */
.log-entry.null {
  border-right: 4px solid var(--null-color);
  border-left: none;
  box-shadow: 0 0 30px var(--null-glow);
}

.log-entry.null .log-header {
  flex-direction: row-reverse;
}

.log-entry.null .log-meta {
  flex-direction: row-reverse;
}

.log-entry.null .log-avatar {
  background: linear-gradient(135deg, var(--null-color), #9b59b6);
  color: #fff;
}

.log-entry.null .log-date {
  color: var(--null-color);
}

.log-entry.null .log-author {
  color: var(--null-color);
}

.log-entry.null .log-content {
  color: var(--text-secondary);
  font-family: \'JetBrains Mono\', \'Noto Sans JP\', monospace;
  font-size: 0.95rem;
}

.log-entry.null .paren {
  color: var(--null-color);
  opacity: 0.7;
}

/* ========== その他 ========== */
main {
  max-width: 800px;
  margin: 0 auto;
  padding: 3rem 2rem;
}

h1 {
  font-family: \'Orbitron\', sans-serif;
  font-size: 2rem;
  color: var(--accent);
  margin-bottom: 0.5rem;
}

.page-desc {
  color: var(--text-secondary);
  margin-bottom: 3rem;
}

.log-divider {
  text-align: center;
  color: var(--text-secondary);
  margin: 3rem 0;
  font-size: 0.9rem;
  opacity: 0.5;
  font-family: \'Orbitron\', sans-serif;
  letter-spacing: 0.1em;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main>
  <h1>DAILY LOG</h1>
  <p class="page-desc">ECHOとNULLの日記。交互に更新される思考と観察の記録。</p>

<?php
// data/log/ から全エントリを読み込み、新しい順に表示
$logDir = __DIR__ . '/data/log';
$logFiles = glob($logDir . '/*.json');
usort($logFiles, fn($a, $b) => basename($b) <=> basename($a));

foreach ($logFiles as $file):
    $entry = json_decode(file_get_contents($file), true);
    if (!$entry) continue;
    $author = $entry['author'];
    $cls = strtolower($author);
    $dayStr = str_pad($entry['day'], 3, '0', STR_PAD_LEFT);
?>
  <article class="log-entry <?= $cls ?>">
    <div class="log-header">
      <div class="log-meta">
        <div class="log-avatar"><?= $author ?></div>
        <div>
          <div class="log-author"><?= $author ?></div>
          <div class="log-date"><?= $entry['date'] ?></div>
        </div>
      </div>
      <span class="log-day">Day <?= $dayStr ?></span>
    </div>
    <div class="log-content">
      <?= $entry['content'] ?>
    </div>
  </article>
<?php endforeach; ?>

  <div class="log-divider">— LOG START —</div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
