<?php
/**
 * ランダムに1本へ飛ばすだけのページ。
 * ?g=chaos のようにコレクションを指定すると、その中から選ぶ。
 */
require_once __DIR__ . '/includes/contents-data.php';

$pool = array_values(array_filter(
    $allContents,
    fn($c) => !in_array($c['page'], ['about', 'contents', 'log'], true)
));

$g = isset($_GET['g']) ? preg_replace('/[^a-z]/', '', (string)$_GET['g']) : '';
if ($g !== '') {
    $filtered = array_values(array_filter($pool, fn($c) => ($c['group'] ?? '') === $g));
    if ($filtered) $pool = $filtered;
}

$target = $pool ? $pool[random_int(0, count($pool) - 1)]['page'] : 'index';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ' . $target . '.php', true, 302);
exit;
