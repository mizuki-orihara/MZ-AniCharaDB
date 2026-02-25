<?php
/**
 * import_Norm.php
 * 役割: 外部からのJSONファイルを受信し、セッションごとの一時フォルダに保存する
 *
 * GET  → uid返却（uid.phpに委譲しているが、直接叩いた場合も同じ）
 * POST → ファイル受信・保存
 *
 * レスポンスコード:
 *   200 OK
 *   400 Bad Request      (JSONパース失敗 / 不正リクエスト)
 *   413 Payload Too Large (サイズ・個数オーバー)
 *   500 Internal Error   (書き込み失敗)
 *   503 Service Unavailable (ゲート閉鎖)
 */

require_once __DIR__ . '/API/config.php';

header('Content-Type: application/json; charset=utf-8');

// ===== ゲートチェック =====

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);
if (($task_master['gates']['receiver'] ?? false) !== true) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Receiver gate is closed.']);
    update_status('error', 'Gate closed.', null);
    exit;
}

// ===== POSTチェック =====

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'POST only.']);
    exit;
}

// ===== SID取得（クッキーから、なければ400） =====

$sid = $_COOKIE['sid'] ?? '';
if (empty($sid) || !preg_match('/^[a-f0-9]{6}-[a-f0-9]{6}$/', $sid)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing SID.']);
    exit;
}

// ===== 受信データ取得 =====

$json_data = file_get_contents('php://input');
if (empty($json_data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload.']);
    exit;
}

// ===== サイズチェック =====

if (strlen($json_data) > IMPORT_MAX_SIZE) {
    http_response_code(413);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Payload too large.',
        'limit'   => IMPORT_MAX_SIZE,
        'actual'  => strlen($json_data),
    ]);
    exit;
}

// ===== JSONパース確認 =====

$parsed = json_decode($json_data, true);
if ($parsed === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'JSON parse failed.']);
    exit;
}

// ===== セッションディレクトリの準備 =====

$session_dir = DIR_IMPORT_NORM . '/~temp_' . $sid;
if (!is_dir($session_dir)) {
    if (!mkdir($session_dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to create session directory.']);
        update_status('error', 'mkdir failed: ~temp_' . $sid, $sid);
        exit;
    }
}

// ===== セッション累積ファイル数チェック（ソフト上限） =====

$existing_count = count(glob($session_dir . '/*.json') ?: []);
$warn = $existing_count >= IMPORT_SOFT_LIMIT;

// ===== ファイル名の決定 =====

$raw_filename = $_SERVER['HTTP_X_FILE_NAME'] ?? '';
if (!empty($raw_filename)) {
    $original_name = basename(urldecode($raw_filename));
    $file_name     = date('His') . '_' . $original_name;
} else {
    $file_name = 'received_' . date('Ymd_His') . '_' . uniqid() . '.json';
}

$save_path = $session_dir . '/' . $file_name;

// ===== 保存 =====

if (file_put_contents($save_path, $json_data) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Write failed.']);
    update_status('error', 'Write failed: ' . $file_name, $sid);
    exit;
}

// ===== 正常終了 =====

update_status('success', 'Received: ' . $file_name, $sid);
echo json_encode([
    'status'       => 'success',
    'file'         => $file_name,
    'sid'          => $sid,
    'session_count'=> $existing_count + 1,
    'warn_soft_limit' => $warn,
]);
exit;


// ============================================================
// 補助関数
// ============================================================

/**
 * import_Norm_status.json の更新
 */
function update_status(string $status_code, string $message, ?string $sid): void {
    $status = [
        'last_update'     => date('Y-m-d H:i:s'),
        'status'          => $status_code,
        'message'         => $message,
        'current_session' => $sid ? '~temp_' . $sid : null,
    ];
    file_put_contents(
        __DIR__ . '/import_Norm_status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
