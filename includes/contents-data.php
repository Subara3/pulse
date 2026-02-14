<?php
/**
 * PULSEコンテンツ一覧（data/contents.json のラッパー）
 *
 * JSON をマスターデータとし、このファイルは読み取り専用ラッパー。
 * 新しいコンテンツ追加時は data/contents.json を編集すること。
 */

// JSONからコンテンツ一覧を読み込み
$jsonPath = __DIR__ . '/../data/contents.json';
$allContents = json_decode(file_get_contents($jsonPath), true) ?: [];

// タグの自動計算: 直近7日以内 → NEW、それ以外 → カテゴリ名
$newThreshold = strtotime('-7 days');
foreach ($allContents as &$c) {
    $addedTime = isset($c['added']) ? strtotime($c['added']) : 0;
    $c['tag'] = ($addedTime >= $newThreshold) ? 'NEW' : $c['category'];
}
unset($c);

// カテゴリ順の定義
$categoryOrder = ['読み物', '遊べる', '眺める', 'ガイド'];

// カテゴリでグループ化
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

// index.php用に$contentsとして提供（ガイドカテゴリはサイドメニューに移動済み）
$contents = array_values(array_filter($allContents, fn($c) => $c['category'] !== 'ガイド'));
