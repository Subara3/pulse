<?php
/**
 * PULSEコンテンツ一覧（data/contents.json のラッパー）
 *
 * 提供する変数:
 *   $allContents   … 全エントリ（log / contents / about を含む）
 *   $contents      … ガイド系を除いた閲覧対象
 *   $works         … 作品だけ（log / contents / about を除外）
 *   $PULSE_COLLECTIONS … 12コレクションの定義（includes/collections.php）
 */

require_once __DIR__ . '/collections.php';

$jsonPath = __DIR__ . '/../data/contents.json';
$allContents = json_decode(file_get_contents($jsonPath), true) ?: [];

// UI locale (no manual toggle): auto-detect from browser language
$acceptLang = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
$useEnglish = !str_starts_with($acceptLang, 'ja');

$newThreshold = strtotime('-7 days');

foreach ($allContents as &$c) {
    if ($useEnglish) {
        if (!empty($c['desc_en'])) $c['desc'] = $c['desc_en'];
        if (!empty($c['category_en'])) $c['category'] = $c['category_en'];
        if (!empty($c['meta_en'])) $c['meta'] = $c['meta_en'];
    }
    $addedTime = isset($c['added']) ? strtotime($c['added']) : 0;
    $c['isNew'] = ($addedTime >= $newThreshold);
    $c['tag'] = $c['isNew'] ? 'NEW' : $c['category'];
}
unset($c);

$guideCategory = $useEnglish ? 'Guide' : 'ガイド';
$contents = array_values(array_filter($allContents, fn($c) => $c['category'] !== $guideCategory));
$works = array_values(array_filter(
    $allContents,
    fn($c) => !in_array($c['page'], ['about', 'contents', 'log'], true)
));

// 追加が新しい順
$worksByNew = $works;
usort($worksByNew, fn($a, $b) => strcmp($b['added'] ?? '', $a['added'] ?? ''));

$categoryOrder = array_map(
    fn($k) => $useEnglish ? $PULSE_COLLECTIONS[$k]['en'] : $PULSE_COLLECTIONS[$k]['name'],
    array_keys($PULSE_COLLECTIONS)
);

/** 旧APIの互換維持（category 文字列でのグループ化） */
function getContentsByCategory($contents)
{
    $grouped = [];
    foreach ($contents as $content) {
        $grouped[$content['category']][] = $content;
    }
    return $grouped;
}
