<?php
/**
 * PULSE コレクション定義（12分類）
 *
 * data/contents.json の "group" キーがここのキーと対応する。
 * 分類を増減するときは scripts/check_policy.py も必ず通すこと。
 */

$PULSE_COLLECTIONS = [
    'chaos' => [
        'name' => '予測できないもの',
        'en' => 'CHAOS',
        'promise' => 'はじめのわずかな違いで、その後の動きがまったく変わります。',
        'icon' => 'fa-hurricane',
    ],
    'grow' => [
        'name' => '育つもの',
        'en' => 'GROWTH',
        'promise' => '形を決めて与えなくても、少しずつ形ができていきます。',
        'icon' => 'fa-seedling',
    ],
    'flow' => [
        'name' => '流れるもの',
        'en' => 'FLOW',
        'promise' => '水や空気の流れ、波の広がりを描いています。',
        'icon' => 'fa-water',
    ],
    'swarm' => [
        'name' => '群れるもの',
        'en' => 'SWARM',
        'promise' => '一つひとつは近くの仲間に合わせるだけで、全体がそろって動きます。',
        'icon' => 'fa-crow',
    ],
    'lattice' => [
        'name' => '格子のもの',
        'en' => 'LATTICE',
        'promise' => 'マス目ごとの簡単な規則だけで、模様ができていきます。',
        'icon' => 'fa-border-all',
    ],
    'number' => [
        'name' => '数えるもの',
        'en' => 'NUMBER',
        'promise' => '数の並びを点や線にすると、形が見えてきます。',
        'icon' => 'fa-hashtag',
    ],
    'curve' => [
        'name' => '描くもの',
        'en' => 'CURVE',
        'promise' => '式をそのまま線にして描いています。',
        'icon' => 'fa-bezier-curve',
    ],
    'fractal' => [
        'name' => '繰り返すもの',
        'en' => 'FRACTAL',
        'promise' => '拡大しても、同じような形がずっと続きます。',
        'icon' => 'fa-snowflake',
    ],
    'light' => [
        'name' => '光るもの',
        'en' => 'LIGHT',
        'promise' => '光が曲がったり重なったりして、明るい縞ができます。',
        'icon' => 'fa-lightbulb',
    ],
    'force' => [
        'name' => '引き合うもの',
        'en' => 'FORCE',
        'promise' => '重力や電気の力を、線や動きにして描いています。',
        'icon' => 'fa-magnet',
    ],
    'tile' => [
        'name' => '敷き詰めるもの',
        'en' => 'TILING',
        'promise' => 'すき間なく平面を埋める並べ方です。',
        'icon' => 'fa-shapes',
    ],
    'mind' => [
        'name' => '探すもの',
        'en' => 'SEARCH',
        'promise' => '迷路や網の中で、道を探していく様子を描いています。',
        'icon' => 'fa-compass',
    ],
];

/** コレクションキー → 表示名 */
function pulseCollectionName(string $key): string
{
    global $PULSE_COLLECTIONS;
    return $PULSE_COLLECTIONS[$key]['name'] ?? $key;
}

/** そのコレクションに属する作品だけを返す */
function pulseByCollection(array $contents, string $key): array
{
    return array_values(array_filter($contents, fn($c) => ($c['group'] ?? '') === $key));
}

/** プレビュー動画/静止画のパスを解決（無ければ null） */
function pulsePreview(string $page, string $ext, string $base = ''): ?string
{
    $rel = 'img/previews/' . $page . '.' . $ext;
    $abs = __DIR__ . '/../' . $rel;
    return file_exists($abs) ? $base . $rel . '?v=' . filemtime($abs) : null;
}

/**
 * 日付から決まる「今日の1本」。同じ日なら誰が見ても同じ、日が変われば変わる。
 */
function pulsePickOfTheDay(array $contents, string $ymd): ?array
{
    global $PULSE_COLLECTIONS;
    $pool = array_values(array_filter(
        $contents,
        fn($c) => isset($c['group']) && isset($PULSE_COLLECTIONS[$c['group']])
    ));
    if (!$pool) return null;
    $seed = crc32('pulse-' . $ymd);
    return $pool[$seed % count($pool)];
}
