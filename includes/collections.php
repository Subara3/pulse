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
        'promise' => 'ほんのわずかな差が、まったく違う結末になる。',
        'icon' => 'fa-hurricane',
    ],
    'grow' => [
        'name' => '育つもの',
        'en' => 'GROWTH',
        'promise' => '誰も形を決めていないのに、形が決まっていく。',
        'icon' => 'fa-seedling',
    ],
    'flow' => [
        'name' => '流れるもの',
        'en' => 'FLOW',
        'promise' => '流れが場所を覚えていて、渦になって残る。',
        'icon' => 'fa-water',
    ],
    'swarm' => [
        'name' => '群れるもの',
        'en' => 'SWARM',
        'promise' => '指揮者がいないのに、全体が揃ってしまう。',
        'icon' => 'fa-crow',
    ],
    'lattice' => [
        'name' => '格子のもの',
        'en' => 'LATTICE',
        'promise' => 'ごく単純な規則だけで、模様が湧いてくる。',
        'icon' => 'fa-border-all',
    ],
    'number' => [
        'name' => '数えるもの',
        'en' => 'NUMBER',
        'promise' => '数字が並ぶだけで、そこに形が見えてくる。',
        'icon' => 'fa-hashtag',
    ],
    'curve' => [
        'name' => '描くもの',
        'en' => 'CURVE',
        'promise' => '式がそのまま一本の線になって出てくる。',
        'icon' => 'fa-bezier-curve',
    ],
    'fractal' => [
        'name' => '繰り返すもの',
        'en' => 'FRACTAL',
        'promise' => 'どこまで拡大しても、まだ続いている。',
        'icon' => 'fa-snowflake',
    ],
    'light' => [
        'name' => '光るもの',
        'en' => 'LIGHT',
        'promise' => '波が重なって、明暗の縞に変わる。',
        'icon' => 'fa-lightbulb',
    ],
    'force' => [
        'name' => '引き合うもの',
        'en' => 'FORCE',
        'promise' => '見えない力に、はっきりした形がある。',
        'icon' => 'fa-magnet',
    ],
    'tile' => [
        'name' => '敷き詰めるもの',
        'en' => 'TILING',
        'promise' => '隙間なく埋まるのに、二度と同じ並びが来ない。',
        'icon' => 'fa-shapes',
    ],
    'mind' => [
        'name' => '探すもの',
        'en' => 'SEARCH',
        'promise' => '手探りのくせに、ちゃんと最短を見つける。',
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
