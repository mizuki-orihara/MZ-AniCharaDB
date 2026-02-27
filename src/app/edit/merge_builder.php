<?php
/**
 * merge_builder.php
 * 役割: merge_editor.html からの選択結果を受け取り、
 *       新カードを生成して 03b_merge/M_Confirmed/ へ配置する
 *
 * POST JSON:
 *   card_id      : string   識別キー
 *   maindb_file  : string   mainDB側のファイル名
 *   merge_file   : string   03b_merge側のファイル名
 *   selections   : object   key_path => 'main' | 'merge' | 'del'
 *
 * 処理:
 *   1. 新カードJSON生成 → 03b_merge/M_Confirmed/
 *   2. mainDB内の旧カード → mainDB_OLD/ へ移動
 *   3. 03b_merge内の差分ファイル削除
 *   4. merge_group_list.json から当該エントリ削除
 *
 * レスポンス:
 *   200 { status: 'ok', new_file: '...' }
 *   400 { status: 'error', message: '...' }
 *   500 { status: 'error', message: '...' }
 */

require_once __DIR__ . '/../API/config.php';

header('Content-Type: application/json; charset=utf-8');

// ===== POSTチェック =====

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'POST only.']);
    exit;
}

// ===== リクエスト読み込み =====

$body = json_decode(file_get_contents('php://input'), true);
if ($body === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'JSON parse failed.']);
    exit;
}

$card_id     = $body['card_id']     ?? '';
$maindb_file = $body['maindb_file'] ?? '';
$merge_file  = $body['merge_file']  ?? '';
$selections  = $body['selections']  ?? [];

if (empty($card_id) || empty($maindb_file) || empty($merge_file) || empty($selections)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}

// ===== ファイルパス解決 =====

$maindb_path  = DIR_MAIN_DB . '/' . basename($maindb_file);
$merge_path   = DIR_MERGE   . '/' . basename($merge_file);

if (!file_exists($maindb_path)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'mainDB file not found: ' . $maindb_file]);
    exit;
}
if (!file_exists($merge_path)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'merge file not found: ' . $merge_file]);
    exit;
}

// ===== カード読み込み =====

$main_card  = json_decode(file_get_contents($maindb_path), true);
$merge_card = json_decode(file_get_contents($merge_path), true);

if ($main_card === null || $merge_card === null) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to parse card JSON.']);
    exit;
}

// ===== 新カード組み立て =====
// selectionsに従い、key_pathごとにmain/merge/delを適用
// headerはコア項目として mainDB側をそのまま使用

$new_card = build_card($main_card, $merge_card, $selections);

// ===== content_hash 再計算 =====

$hash_target = $new_card;
unset($hash_target['header']);
ksort($hash_target);
$new_card['header']['content_hash'] = md5(json_encode($hash_target, JSON_UNESCAPED_UNICODE));

// ===== ファイル名生成 =====

$safe_work = preg_replace('/[^\p{L}\p{N}\-]/u', '-', preg_replace('/[\s\x{3000}]+/u', '-', trim($new_card['header']['work'] ?? 'unknown')));
$safe_name = preg_replace('/[^\p{L}\p{N}\-]/u', '-', preg_replace('/[\s\x{3000}]+/u', '-', trim($new_card['header']['name'] ?? 'unknown')));
$branch    = $new_card['header']['branch'] ?? 'main';
$hash      = substr($new_card['header']['content_hash'], 0, 8);
$new_filename = "{$safe_work}_{$safe_name}_{$branch}_{$hash}.json";

// ===== 書き出し先ディレクトリの準備 =====

foreach ([DIR_MERGE_CONF, DIR_MAIN_DB_OLD] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// ===== 1. 新カード → M_Confirmed/ =====

$dest = DIR_MERGE_CONF . '/' . $new_filename;
if (file_put_contents($dest, json_encode($new_card, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to write new card.']);
    exit;
}

// ===== 2. mainDB旧カード → mainDB_OLD/ =====

if (!rename($maindb_path, DIR_MAIN_DB_OLD . '/' . basename($maindb_file))) {
    // 書き込みは成功しているのでロールバックはしない、エラーログのみ
    error_log('merge_builder: failed to move maindb file to OLD: ' . $maindb_file);
}

// ===== 3. 03b_merge内の差分ファイル削除 =====

@unlink($merge_path);

// ===== 4. merge_group_list.json からエントリ削除 =====

$group_list_path = DIR_API . '/merge_group_list.json';
if (file_exists($group_list_path)) {
    $group_list = json_decode(file_get_contents($group_list_path), true) ?? [];
    $group_list = array_values(array_filter($group_list, fn($e) => $e['card_id'] !== $card_id));
    file_put_contents(
        $group_list_path,
        json_encode($group_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

// ===== 正常終了 =====

echo json_encode([
    'status'   => 'ok',
    'new_file' => $new_filename,
]);
exit;


// ============================================================
// 補助関数
// ============================================================

/**
 * selectionsに従い新カードを組み立てる
 *
 * header は mainDB側を優先（コア項目）
 * generated_at は現在時刻に更新
 *
 * selections の key_path 形式:
 *   "tags"                → ルート直下キー
 *   "header.schema"       → headerの子キー
 *   "profile.name_reading"→ profileの子キー
 */
function build_card(array $main, array $merge, array $selections): array {
    $new = [];

    // header: mainをベースにgenerated_atだけ更新
    $new['header'] = $main['header'];
    $new['header']['generated_at'] = date('c');

    // header以外の全キーをunionで処理
    $all_keys = array_unique(array_merge(array_keys($main), array_keys($merge)));

    foreach ($all_keys as $key) {
        if ($key === 'header') continue;

        // このキーに対するselectionsを収集
        // key_pathが "key.subkey" の場合、keyレベルのselectionsを確認
        $selection = $selections[$key] ?? 'main';

        if ($selection === 'del') {
            // 削除 → 新カードに含めない
            continue;
        }

        $source = $selection === 'merge' ? $merge : $main;

        if (isset($source[$key])) {
            // サブキー単位のselectionsがあれば適用
            if (is_array($source[$key]) && !array_is_list($source[$key])) {
                $new[$key] = build_subkeys($main[$key] ?? [], $merge[$key] ?? [], $selections, $key);
            } else {
                $new[$key] = $source[$key];
            }
        } elseif ($selection === 'main' && isset($merge[$key])) {
            // mainになかったがmergeにある場合、mergeから取る
            $new[$key] = $merge[$key];
        }
    }

    return $new;
}

/**
 * サブキー単位のselectionsを適用してオブジェクトを組み立てる
 */
function build_subkeys(array $main_obj, array $merge_obj, array $selections, string $prefix): array {
    $result   = [];
    $all_keys = array_unique(array_merge(array_keys($main_obj), array_keys($merge_obj)));

    foreach ($all_keys as $sub) {
        $path      = "{$prefix}.{$sub}";
        $selection = $selections[$path] ?? $selections[$prefix] ?? 'main';

        if ($selection === 'del') continue;

        $source = $selection === 'merge' ? $merge_obj : $main_obj;
        if (isset($source[$sub])) {
            $result[$sub] = $source[$sub];
        } elseif ($selection === 'main' && isset($merge_obj[$sub])) {
            $result[$sub] = $merge_obj[$sub];
        }
    }

    return $result;
}

/**
 * PHPの array_is_list 互換（PHP8.1未満対応）
 */
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
