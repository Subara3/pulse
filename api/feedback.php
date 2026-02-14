<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 設定読み込み
define('ALLOW_INCLUDE', true);
require_once __DIR__ . '/../../web/config/secrets.php';

// POSTデータ取得
$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$page = trim($input['page'] ?? 'Unknown');

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

// Discord通知を送信
$result = sendDiscordFeedback($message, $page);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Feedback sent successfully']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send feedback']);
}

/**
 * Discord Webhook経由でフィードバック送信
 */
function sendDiscordFeedback($message, $page) {
    if (!defined('DISCORD_WEBHOOK_URL') || empty(DISCORD_WEBHOOK_URL)) {
        error_log('[PULSE Feedback] Webhook URL not configured');
        return false;
    }

    try {
        $embed = [
            'title' => '💬 PULSE フィードバック',
            'description' => $message,
            'color' => 0x00f5d4, // PULSEのアクセントカラー
            'fields' => [
                [
                    'name' => 'ページ',
                    'value' => $page,
                    'inline' => true
                ],
                [
                    'name' => '日時',
                    'value' => date('Y-m-d H:i:s'),
                    'inline' => true
                ]
            ],
            'footer' => [
                'text' => 'pulse.subara3.com'
            ]
        ];

        $payload = json_encode([
            'embeds' => [$embed]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(DISCORD_WEBHOOK_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 204 || $httpCode === 200);
    } catch (Exception $e) {
        error_log('[PULSE Feedback] ' . $e->getMessage());
        return false;
    }
}
