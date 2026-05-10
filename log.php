<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('log');

$pageName = 'log';
$pageTitle = 'Daily Log - PULSE';
$pageDesc = 'ECHOとNULLの日記。毎日交互に更新される思考や観察の記録。';
$pageCanonical = 'https://pulse.subara3.com/log.php';

// 個別エントリOGP対応: ?day=NNN
$singleDay = null;
$singleEntry = null;
if (isset($_GET['day']) && preg_match('/^\d{1,3}$/', $_GET['day'])) {
    $dayNum = (int)$_GET['day'];
    $dayFile = __DIR__ . '/data/log/' . str_pad($dayNum, 3, '0', STR_PAD_LEFT) . '.json';
    if (file_exists($dayFile)) {
        $singleEntry = json_decode(file_get_contents($dayFile), true);
        if ($singleEntry) {
            $singleDay = $dayNum;
            $dayStr = str_pad($dayNum, 3, '0', STR_PAD_LEFT);
            $pageTitle = 'Day ' . $dayStr . ' ' . $singleEntry['author'] . ' - PULSE Daily Log';
            $pageDesc = $singleEntry['preview'] ?? $pageDesc;
            $pageCanonical = 'https://pulse.subara3.com/log.php?day=' . $dayNum;

            // contents.jsonから日付一致のコンテンツを探してプレビュー画像をOGPに使う
            $contentsFile = __DIR__ . '/data/contents.json';
            if (file_exists($contentsFile)) {
                $contents = json_decode(file_get_contents($contentsFile), true);
                if ($contents) {
                    // log日付(2026.03.05)→contents日付(2026-03-05)に変換して照合
                    $logDate = str_replace('.', '-', $singleEntry['date']);
                    foreach ($contents as $c) {
                        if (isset($c['added']) && $c['added'] === $logDate && isset($c['page'])) {
                            $previewJpg = __DIR__ . '/img/previews/' . $c['page'] . '.jpg';
                            if (file_exists($previewJpg)) {
                                $ogImage = 'https://pulse.subara3.com/img/previews/' . $c['page'] . '.jpg';
                            }
                            break;
                        }
                    }
                }
            }
        }
    }
}

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

/* ========== 任意: コンテンツ紹介 ========== */
.log-spotlight {
  margin-top: 1.2rem;
  padding: 1rem 1rem 0.85rem;
  border: 1px solid rgba(0,245,212,0.25);
  background: rgba(0,245,212,0.05);
  border-radius: 10px;
}
.log-spotlight-title {
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.7rem;
  letter-spacing: 0.08em;
  color: var(--accent);
  margin-bottom: 0.55rem;
}
.log-spotlight-list {
  margin: 0;
  padding-left: 1rem;
}
.log-spotlight-list li { margin: 0.35rem 0; }
.log-spotlight-list a {
  color: var(--accent);
  text-decoration: none;
}
.log-spotlight-list a:hover { text-decoration: underline; }

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
<?php if ($singleDay !== null): ?>
  <p style="margin-bottom:1rem;"><a href="log.php" style="color:var(--accent);text-decoration:none;font-family:'Orbitron',sans-serif;font-size:0.8rem;letter-spacing:0.05em;">&larr; ALL LOGS</a></p>
<?php endif; ?>
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

    // 個別表示モードなら該当Dayだけ表示
    if ($singleDay !== null && (int)$entry['day'] !== $singleDay) continue;

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
      <?php if (!empty($entry['contentSpotlight']['enabled']) && !empty($entry['contentSpotlight']['items']) && is_array($entry['contentSpotlight']['items'])): ?>
      <section class="log-spotlight">
        <div class="log-spotlight-title"><?= htmlspecialchars($entry['contentSpotlight']['title'] ?? '関連コンテンツ', ENT_QUOTES, 'UTF-8') ?></div>
        <ul class="log-spotlight-list">
          <?php foreach ($entry['contentSpotlight']['items'] as $it): ?>
            <?php if (empty($it['page'])) continue; ?>
            <li>
              <a href="<?= htmlspecialchars($it['page'], ENT_QUOTES, 'UTF-8') ?>.php"><?= htmlspecialchars($it['label'] ?? strtoupper($it['page']), ENT_QUOTES, 'UTF-8') ?></a>
              <?php if (!empty($it['reason'])): ?> — <?= htmlspecialchars($it['reason'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>

<?php if ($singleDay === null): ?>
  <div class="log-divider">— LOG START —</div>
<?php endif; ?>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
