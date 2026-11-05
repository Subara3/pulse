<?php
/**
 * シンプルアクセスログ
 * 各ページの先頭で require_once 'includes/access_log.php'; するだけ
 */

// ログディレクトリ
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// 今日の日付でファイル分け
$logFile = $logDir . '/access_' . date('Y-m') . '.log';

// 記録する情報
$data = [
    'time' => date('Y-m-d H:i:s'),
    'page' => $_SERVER['REQUEST_URI'] ?? '/',
    'ref' => $_SERVER['HTTP_REFERER'] ?? '',
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];
// IPはハッシュにして残す。ソルトはリポジトリに置かず、サーバーの secrets.php で
// define('PULSE_LOG_SALT', '...') する。未設定のあいだはIPを残さない。
$secretsPath = __DIR__ . '/../../web/config/secrets.php';
if (!defined('PULSE_LOG_SALT') && file_exists($secretsPath)) require_once $secretsPath;
if (defined('PULSE_LOG_SALT') && PULSE_LOG_SALT !== '') {
    $data['ip'] = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . PULSE_LOG_SALT);
}

// ログに追記
file_put_contents($logFile, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
