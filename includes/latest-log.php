<?php
/**
 * 最新ログエントリーの自動検出
 * data/log/ ディレクトリから最新のJSONファイルを読み込む。
 * index.phpのプレビュー表示に使用。
 */
$logDir = __DIR__ . '/../data/log';
$logFiles = glob($logDir . '/*.json');

if (!empty($logFiles)) {
    // ファイル名（数字）でソートして最新を取得
    usort($logFiles, fn($a, $b) => basename($b) <=> basename($a));
    $latestLog = json_decode(file_get_contents($logFiles[0]), true);
} else {
    $latestLog = [
        'day' => 0,
        'date' => '',
        'author' => 'ECHO',
        'preview' => '',
        'content' => ''
    ];
}
