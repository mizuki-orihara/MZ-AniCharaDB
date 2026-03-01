<?php
/**
 * indexer_add.php
 * 役割: mainDB/registercache/ の新規カードを mainDB/ に移動し、
 *       index.json と search_index.json に追記する
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
$files = glob(DIR_REGISTERCACHE . '/*.json') ?: [];
$files = array_filter($files, fn($f) => basename($f) !== 'indexer_add_status.json');
if (empty($files)) {
    write_status(0, 0, 'No files in registercache.');
    exit(0);
}

// index.json を読み込む（なければ空配列）
$index = file_exists(INDEX_JSON)
    ? json_decode(file_get_contents(INDEX_JSON), true) ?? []
    : [];

// search_index.json を読み込む（なければ空配列）
$search_index_path = DIR_API . '/search_index.json';
$search_index = file_exists($search_index_path)
    ? json_decode(file_get_contents($search_index_path), true) ?? []
    : [];

// search_index を filename => entry のマップに変換（重複排除用）
$search_map = [];
foreach ($search_index as $entry) {
    $search_map[$entry['filename']] = $entry;
}

// 増分をメモリ内で管理
$additions = [];

foreach ($files as $src_path) {
    $filename  = basename($src_path);
    $dest_path = DIR_MAIN_DB . '/' . $filename;

    $card = json_decode(file_get_contents($src_path), true);
    if ($card === null) {
        $fail_count++;
        continue;
    }

    $hash    = $card['header']['content_hash'] ?? '';
    $work    = $card['header']['work']         ?? '';
    $name    = $card['header']['name']         ?? '';
    $branch  = $card['header']['branch']       ?? '';
    $card_id = "{$work}_{$name}_{$branch}";

    // mainDB/ に移動
    if (!rename($src_path, $dest_path)) {
        $fail_count++;
        continue;
    }

    // index への増分（キーはcard_id）
    $additions[$card_id] = $hash;

    // search_index への増分
    $search_map[$filename] = [
        'filename' => $filename,
        'card_id'  => $card_id,
        'work'     => $work,
        'name'     => $name,
        'branch'   => $branch,
    ];

    $success_count++;
}

// index.json 書き出し
$merged_index = array_merge($index, $additions);
file_put_contents(INDEX_JSON,
    json_encode($merged_index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// search_index.json 書き出し（配列に戻す）
file_put_contents($search_index_path,
    json_encode(array_values($search_map), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
