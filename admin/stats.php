<?php
/**
 * アクセス統計ビューア（簡易版）
 * URL: /admin/stats.php?key=YOUR_SECRET_KEY
 */

// 簡易認証キー（後で変更してね）
$secretKey = 'pulse_stats_2026';

if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die('Access Denied');
}

$logDir = __DIR__ . '/../logs';
$month = $_GET['month'] ?? date('Y-m');
$logFile = $logDir . '/access_' . $month . '.log';

$logs = [];
$pageCount = [];
$dailyCount = [];
$refCount = [];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry) continue;
        $logs[] = $entry;
        
        // ページ別カウント
        $page = parse_url($entry['page'], PHP_URL_PATH) ?: $entry['page'];
        $pageCount[$page] = ($pageCount[$page] ?? 0) + 1;
        
        // 日別カウント
        $day = substr($entry['time'], 0, 10);
        $dailyCount[$day] = ($dailyCount[$day] ?? 0) + 1;
        
        // リファラ別
        if (!empty($entry['ref'])) {
            $host = parse_url($entry['ref'], PHP_URL_HOST) ?: $entry['ref'];
            $refCount[$host] = ($refCount[$host] ?? 0) + 1;
        }
    }
}

arsort($pageCount);
arsort($dailyCount);
arsort($refCount);

$totalViews = count($logs);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PULSE Stats</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    background: #0a0a0f;
    color: #e0e0e0;
    padding: 2rem;
    line-height: 1.6;
}
h1 { color: #00f5d4; margin-bottom: 0.5rem; }
h2 { color: #00f5d4; font-size: 1rem; margin: 2rem 0 1rem; border-bottom: 1px solid #333; padding-bottom: 0.5rem; }
.total { font-size: 3rem; color: #00f5d4; margin: 1rem 0; }
.label { color: #888; font-size: 0.9rem; }
table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
th, td { padding: 0.5rem; text-align: left; border-bottom: 1px solid #222; }
th { color: #888; font-weight: normal; }
td { color: #ccc; }
.num { text-align: right; color: #00f5d4; font-family: monospace; }
.bar { height: 4px; background: #00f5d4; margin-top: 4px; border-radius: 2px; }
form { margin: 1rem 0; }
select, button { padding: 0.5rem 1rem; background: #1a1a24; color: #e0e0e0; border: 1px solid #333; border-radius: 4px; }
button { background: #00f5d4; color: #0a0a0f; cursor: pointer; }
</style>
</head>
<body>
<h1>PULSE Stats</h1>
<p class="label"><?= htmlspecialchars($month) ?></p>

<form method="get">
    <input type="hidden" name="key" value="<?= htmlspecialchars($secretKey) ?>">
    <select name="month">
        <?php for ($i = 0; $i < 12; $i++): $m = date('Y-m', strtotime("-$i months")); ?>
        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= $m ?></option>
        <?php endfor; ?>
    </select>
    <button type="submit">表示</button>
</form>

<div class="total"><?= number_format($totalViews) ?></div>
<p class="label">Total Page Views</p>

<h2>ページ別</h2>
<table>
<?php $max = max($pageCount ?: [1]); foreach (array_slice($pageCount, 0, 15, true) as $page => $count): ?>
<tr>
    <td><?= htmlspecialchars($page) ?></td>
    <td class="num"><?= number_format($count) ?></td>
</tr>
<tr><td colspan="2"><div class="bar" style="width: <?= ($count / $max) * 100 ?>%"></div></td></tr>
<?php endforeach; ?>
</table>

<h2>日別</h2>
<table>
<?php ksort($dailyCount); foreach (array_slice($dailyCount, -14, null, true) as $day => $count): ?>
<tr>
    <td><?= htmlspecialchars($day) ?></td>
    <td class="num"><?= number_format($count) ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>流入元</h2>
<table>
<?php foreach (array_slice($refCount, 0, 10, true) as $ref => $count): ?>
<tr>
    <td><?= htmlspecialchars($ref) ?></td>
    <td class="num"><?= number_format($count) ?></td>
</tr>
<?php endforeach; ?>
</table>

</body>
</html>
