<?php
/**
 * card_editor_api.php
 * 役割: card_editor.html からのリクエストを処理する
 *
 * GET  ?mode=load&card_id=XX → mainDB からカードを読み込んで返す
 * POST JSON                  → 編集内容を受け取り新カードを生成
 *
 * POST JSON:
 *   card_id  : string   現在のcard_id
 *   filename : string   現在のファイル名
 *   edits    : object   key_path => new_value
 *
 * 保存処理フロー:
 *   1. 新カードJSON生成 → 03b_merge/M_Confirmed/
 *   2. mainDB旧カード  → mainDB_OLD/ へ移動
 *   3. index.json から旧card_idエントリ削除
 *   4. dispatcher（merge_confirmdゲート）が拾う
 */

require_once __DIR__ . '/../API/config.php';

header('Content-Type: application/json; charset=utf-8');

// ===== GET: カード読み込み =====

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode     = $_GET['mode']     ?? '';
    $filename = $_GET['filename'] ?? '';

    if ($mode !== 'load' || empty($filename)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'mode=load&filename required.']);
        echo json_encode(['status' => 'error', 'message' => 'mode=load&filename が必要です。']);
        exit;
    }

    $file_path = DIR_MAIN_DB . '/' . basename($filename);

    if (!file_exists($file_path)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Card not found: ' . $filename]);
        exit;
    }

    $card = json_decode(file_get_contents($file_path), true);
    if ($card === null) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'JSON parse failed.']);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'card'   => $card,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ===== POST: 保存処理 =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if ($body === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'JSON parse failed.']);
        exit;
    }

    $card_id  = $body['card_id']  ?? '';
    $filename = $body['filename'] ?? '';
    $edits    = $body['edits']    ?? [];

    if (empty($card_id) || empty($filename) || empty($edits)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
        exit;
    }

    $src_path = DIR_MAIN_DB . '/' . basename($filename);
    if (!file_exists($src_path)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Source file not found.']);
        exit;
    }

    $card = json_decode(file_get_contents($src_path), true);
    if ($card === null) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to parse source card.']);
        exit;
    }

    // ===== 編集内容をカードに適用 =====

    $new_card = apply_edits($card, $edits);

    // ===== content_hash 再計算 =====

    $hash_target = $new_card;
    unset($hash_target['header']);
    ksort($hash_target);
    $new_card['header']['content_hash'] = md5(json_encode($hash_target, JSON_UNESCAPED_UNICODE));
    $new_card['header']['generated_at'] = date('c');

    // ===== ファイル名生成 =====

    $safe_work   = preg_replace('/[^\p{L}\p{N}\-]/u', '-', preg_replace('/[\s\x{3000}]+/u', '-', trim($new_card['header']['work']   ?? 'unknown')));
    $safe_name   = preg_replace('/[^\p{L}\p{N}\-]/u', '-', preg_replace('/[\s\x{3000}]+/u', '-', trim($new_card['header']['name']   ?? 'unknown')));
    $branch      = $new_card['header']['branch'] ?? 'main';
    $hash        = substr($new_card['header']['content_hash'], 0, 8);
    $new_filename = "{$safe_work}_{$safe_name}_{$branch}_{$hash}.json";

    // ===== ディレクトリ準備 =====

    foreach ([DIR_MERGE_CONF, DIR_MAIN_DB_OLD] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
    }

    // ===== 1. 新カード → M_Confirmed/ =====

    $dest = DIR_MERGE_CONF . '/' . $new_filename;
    if (file_put_contents($dest, json_encode($new_card, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to write new card.']);
        exit;
    }

    // ===== 2. 旧カード → mainDB_OLD/ =====

    $old_dest = DIR_MAIN_DB_OLD . '/' . basename($filename);
    if (copy($src_path, $old_dest)) {
        unlink($src_path);
    } else {
        error_log('card_editor_api: failed to copy to OLD: ' . $filename);
    }

    // ===== 3. index.json から旧card_idエントリ削除 =====

    if (file_exists(INDEX_JSON)) {
        $index = json_decode(file_get_contents(INDEX_JSON), true) ?? [];
        unset($index[$card_id]);
        file_put_contents(
            INDEX_JSON,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    // ===== 正常終了 =====

    echo json_encode([
        'status'   => 'ok',
        'new_file' => $new_filename,
    ]);
    exit;
}

// メソッド不正
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'GET or POST only.']);
exit;


// ============================================================
// 補助関数
// ============================================================

/**
 * edits（key_path => new_value）をカードに適用する
 * key_path 形式:
 *   "header.work"      → $card['header']['work']
 *   "profile.birthday" → $card['profile']['birthday']
 *   "name"             → $card['name']
 */
function apply_edits(array $card, array $edits): array {
    foreach ($edits as $key_path => $new_val) {
        $parts = explode('.', $key_path, 2);

        if (count($parts) === 1) {
            // ルート直下
            $card[$parts[0]] = cast_value($new_val, $card[$parts[0]] ?? null);
        } else {
            [$parent, $child] = $parts;
            if (isset($card[$parent]) && is_array($card[$parent])) {
                $card[$parent][$child] = cast_value($new_val, $card[$parent][$child] ?? null);
            }
        }
    }
    return $card;
}

/**
 * 文字列として受け取った値を元の型にキャストする
 */
function cast_value(string $new_val, mixed $original): mixed {
    if ($original === null)            return $new_val === '' ? null : $new_val;
    if (is_int($original))            return (int)$new_val;
    if (is_float($original))          return (float)$new_val;
    if (is_bool($original))           return filter_var($new_val, FILTER_VALIDATE_BOOLEAN);
    if (is_array($original))          return $original; // 配列は変更しない
    return $new_val;
}

/**
 * glob用にワイルドカード文字をエスケープ
 */
function glob_escape(string $str): string {
    return preg_replace('/([*?[\]])/', '[$1]', $str);
}
