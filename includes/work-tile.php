<?php
/**
 * 作品タイル。トップの各コレクションとアーカイブで同じものを使う。
 *
 * お気に入りの表示とプレビュー動画の再生は js/pulse-kit.js（PULSE.tiles）が受け持つ。
 * お気に入りボタンはリンクの外に置く（<a> の中に <button> は入れられない）。
 *
 * $opts:
 *   'big'      => true  大きく見せる（説明文も出す）
 *   'autoplay' => true  ホバーを待たずに、画面に入ったら再生する
 *   'search'   => '...' アーカイブの絞り込み用の文字列
 *   'sub'      => false コレクション名を出さない（コレクションの中に並べるとき）
 */
if (!function_exists('pulseWorkTile')) {
    function pulseWorkTile(array $c, array $opts = []): string
    {
        global $PULSE_COLLECTIONS;
        $p    = $c['page'];
        $h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $jpg  = pulsePreview($p, 'jpg');
        $mp4  = pulsePreview($p, 'mp4');
        $big  = !empty($opts['big']);
        $col  = $PULSE_COLLECTIONS[$c['group']]['name'] ?? '';
        $cls  = 'work' . ($big ? ' work--big' : '');

        $attrs = ' data-page="' . $h($p) . '" data-group="' . $h($c['group'] ?? '') . '"';
        if (isset($opts['search'])) $attrs .= ' data-search="' . $h($opts['search']) . '"';

        $media = $jpg
            ? '<video muted loop playsinline preload="none" poster="' . $h($jpg) . '"'
              . ($mp4 ? ' data-mp4="' . $h($mp4) . '"' : '')
              . (!empty($opts['autoplay']) ? ' data-autoplay' : '')
              . ' aria-hidden="true"></video>'
            : '<span class="work-noimg"><i class="fa-solid ' . $h($c['icon'] ?? 'fa-circle') . '" aria-hidden="true"></i></span>';

        $marks = '';
        if (!empty($c['isNew']))     $marks .= '<span class="wk-mark wk-mark--new">NEW</span>';

        $out  = '<article class="' . $cls . '"' . $attrs . '>';
        $out .= '<a class="work-media" href="' . $h($p) . '.php" tabindex="-1" aria-hidden="true">' . $media . '</a>';
        $out .= '<button class="work-fav" type="button" data-fav aria-pressed="false" aria-label="お気に入りに入れる">'
              . '<i class="fa-regular fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i></button>';
        $out .= '<a class="work-cap" href="' . $h($p) . '.php">';
        $out .= '<span class="work-title">' . $h($c['title']) . '</span>';
        $out .= $marks ? '<span class="work-marks">' . $marks . '</span>' : '';
        $out .= '</a>';
        if ($big) {
            $out .= '<p class="work-desc">' . $h($c['desc'] ?? '') . '</p>';
        } elseif ($opts['sub'] ?? true) {
            $out .= '<p class="work-sub">' . $h($col) . '</p>';
        }
        $out .= '</article>';
        return $out;
    }
}
