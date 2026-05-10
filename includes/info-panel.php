<?php
/**
 * #info パネル（PULSE Frame の TL = 文脈）
 *
 * 配置は TL 固定（top:1.1rem; left:1.1rem）。floating-nav.php は BL なので衝突しない。
 *
 * 使い方A — 配列（静的テキスト）:
 *   $pageInfo = [
 *     'schelling segregation',
 *     ['accent' => 'local preference'],
 *     'global pattern emerges',
 *   ];
 *   require_once __DIR__ . '/includes/info-panel.php';
 *
 *   - 文字列要素: muted 色
 *   - ['accent' => '...'] 要素: teal accent 色
 *   - 配列順 = 表示順、改行は自動
 *
 * 使い方B — 生HTML（動的値や HTML entity を含むとき）:
 *   $pageInfo = 'hex crystal growth · Reiter model<br>α = <span class="v" id="infoA">0.50</span>';
 *   require_once __DIR__ . '/includes/info-panel.php';
 *
 *   - 文字列ならそのまま出力（エスケープしない）
 *   - .v クラスは自動で teal accent
 */

if (!isset($pageInfo) || $pageInfo === '' || $pageInfo === []) return;

if (is_array($pageInfo)) {
    $lines = [];
    foreach ($pageInfo as $line) {
        if (is_array($line) && isset($line['accent'])) {
            $lines[] = '<span class="v">' . htmlspecialchars($line['accent'], ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            $lines[] = htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8');
        }
    }
    $infoBody = implode('<br>', $lines);
} else {
    $infoBody = (string)$pageInfo;
}
?>
<div id="info"><?php echo $infoBody; ?></div>

<style>
#info {
  position: fixed;
  top: 1.1rem;
  left: 1.1rem;
  z-index: 50;
  pointer-events: none;
  font-family: "JetBrains Mono", monospace;
  font-size: 0.6rem;
  line-height: 1.7;
  letter-spacing: 0.03em;
  color: rgba(255, 255, 255, 0.22);
  max-width: min(60vw, 360px);
}

#info .v {
  color: rgba(0, 245, 212, 0.5);
}

@media (max-width: 768px) {
  #info {
    top: 0.75rem;
    left: 0.75rem;
    font-size: 0.5rem;
    max-width: 60vw;
  }
}

.hide-ui #info {
  opacity: 0 !important;
  pointer-events: none;
}
</style>
