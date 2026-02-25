<?php
/**
 * API/uid.php
 * 役割: server_config.json の server_uid をクライアントに返す
 *
 * GET → {"uid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"}
 *
 * console/ 以下のどのHTMLからも叩けるよう独立したエンドポイントとして設置
 */

require_once __DIR__ . '/../API/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'GET only.']);
    exit;
}

// server_config.json から server_uid を取得
if (!file_exists(SERVER_CONFIG_JSON)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server_config.json not found.']);
    exit;
}

$config = json_decode(file_get_contents(SERVER_CONFIG_JSON), true);
$uid    = $config['server_uid'] ?? null;

if (empty($uid) || $uid === 'xxxxxxxx') {
    // 未設定（初期値のまま）
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'server_uid is not configured. Run setup.php.']);
    exit;
}

echo json_encode(['status' => 'ok', 'uid' => $uid]);
exit;
