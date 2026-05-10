<?php
/**
 * PULSEコンテンツ一覧（data/contents.json のラッパー）
 */

$jsonPath = __DIR__ . '/../data/contents.json';
$allContents = json_decode(file_get_contents($jsonPath), true) ?: [];

// UI locale (no manual toggle): auto-detect from browser language
$acceptLang = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
$useEnglish = !str_starts_with($acceptLang, 'ja');

foreach ($allContents as &$c) {
    if ($useEnglish) {
        if (!empty($c['desc_en'])) $c['desc'] = $c['desc_en'];
        if (!empty($c['category_en'])) $c['category'] = $c['category_en'];
        if (!empty($c['meta_en'])) $c['meta'] = $c['meta_en'];
    }
}
unset($c);

// タグの自動計算: 直近7日以内 → NEW、それ以外 → カテゴリ名
$newThreshold = strtotime('-7 days');
foreach ($allContents as &$c) {
    $addedTime = isset($c['added']) ? strtotime($c['added']) : 0;
    $c['tag'] = ($addedTime >= $newThreshold) ? 'NEW' : $c['category'];
}
unset($c);

$categoryOrder = $useEnglish
    ? ['Read', 'Play', 'Watch', 'Guide']
    : ['読み物', '遊べる', '眺める', 'ガイド'];

function getContentsByCategory($contents) {
    $grouped = [];
    foreach ($contents as $content) {
        $cat = $content['category'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = $content;
    }
    return $grouped;
}

$guideCategory = $useEnglish ? 'Guide' : 'ガイド';
$contents = array_values(array_filter($allContents, fn($c) => $c['category'] !== $guideCategory));
