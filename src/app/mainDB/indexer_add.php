<?php
/**
 * indexer_add.php
 * 役割: mainDB/registercache/ の新規カードを mainDB/ に移動し、
 *       index.json に追記する
 *
 * 処理完了もexit(0)
 * エラーはexit(1)
 */

require_once __DIR__ . '/../API/config.php';

// ===== ゲートチェック =====

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);
if (($task_master['gates']['index_add'] ?? false) !== true) {
    exit(0);
}

// ===== 処理本体 =====

$start_time    = microtime(true);
$success_count = 0;
$fail_count    = 0;

// registercache/ のJSONを取得
$files = glob(DIR_REGISTERCACHE . '/' . '*.json') ?: [];
$files = array_filter($files, fn($f) => basename($f) !== 'indexer_add_status.json');
if (empty($files)) {
    write_status(0, 0, 'No files in registercache.');
    exit(0);
}

// index.json を読み込む（なければ空配列）
$index = file_exists(INDEX_JSON)
    ? json_decode(file_get_contents(INDEX_JSON), true) ?? []
    : [];

// 増分をメモリ内で管理
$additions = [];

foreach ($files as $src_path) {
    $filename  = basename($src_path);
    $dest_path = DIR_MAIN_DB . '/' . $filename;

    // JSONからcard_idとcontent_hashを取得
    $card = json_decode(file_get_contents($src_path), true);
    if ($card === null) {
        $fail_count++;
        continue;
    }
    $hash    = $card['header']['content_hash'] ?? '';
    $card_id = ($card['header']['work']   ?? '')
             . '_' . ($card['header']['name']   ?? '')
             . '_' . ($card['header']['branch'] ?? '');

    // mainDB/ に移動
    if (!rename($src_path, $dest_path)) {
        $fail_count++;
        continue;
    }

    // メモリ内に増分を積む（キーはcard_id）
    $additions[$card_id] = $hash;
    $success_count++;
}

// 既存index + 増分をマージして1回だけ書き出す
$merged_index = array_merge($index, $additions);
file_put_contents(INDEX_JSON,
    json_encode($merged_index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

write_status($success_count, $fail_count, 'Done.');
exit(0);


// ============================================================
// 補助関数
// ============================================================

function write_status(int $success, int $fail, string $message): void {
    global $start_time;
    $status = [
        'date'          => date('Y-m-d H:i:s'),
        'success_count' => $success,
        'fail_count'    => $fail,
        'message'       => $message,
        'elapsed_sec'   => round(microtime(true) - $start_time, 2),
    ];
    file_put_contents(
        __DIR__ . '/indexer_add_status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
