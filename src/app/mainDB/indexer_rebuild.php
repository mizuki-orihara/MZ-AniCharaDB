<?php
/**
 * indexer_rebuild.php
 * 役割: mainDB/ 全JSONを走査して index.json と search_index.json を一から作り直す
 *       初回セットアップ・整合性修復用
 *
 * 処理完了もexit(0)
 * エラーはexit(1)
 */

require_once __DIR__ . '/../API/config.php';

// ===== ゲートチェック =====

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);
if (($task_master['gates']['index_rebuild'] ?? false) !== true) {
    exit(0);
}

// ===== 処理本体 =====

$start_time    = microtime(true);
$success_count = 0;
$fail_count    = 0;

// mainDB/ のJSON全件取得（除外ファイル指定）
$exclude = ['index.json', 'indexer_add_status.json', 'indexer_rebuild_status.json'];
$files   = glob(DIR_MAIN_DB . '/*.json') ?: [];
$files   = array_filter($files, fn($f) => !in_array(basename($f), $exclude, true));

// index.json・search_index.json をリセット
$search_index_path = DIR_API . '/search_index.json';
if (file_exists(INDEX_JSON))        unlink(INDEX_JSON);
if (file_exists($search_index_path)) unlink($search_index_path);

$index      = [];
$search_map = [];

foreach ($files as $file_path) {
    $filename = basename($file_path);

    $card = json_decode(file_get_contents($file_path), true);
    if ($card === null) {
        $fail_count++;
        continue;
    }

    $hash    = $card['header']['content_hash'] ?? '';
    $work    = $card['header']['work']         ?? '';
    $name    = $card['header']['name']         ?? '';
    $branch  = $card['header']['branch']       ?? '';
    $card_id = "{$work}_{$name}_{$branch}";

    // 同card_idが既にある場合は上書き（最後に走査したものが残る）
    $index[$card_id] = $hash;

    // search_index はfilenameをキーにして重複排除
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
file_put_contents(INDEX_JSON,
    json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// search_index.json 書き出し
file_put_contents($search_index_path,
    json_encode(array_values($search_map), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

write_status($success_count, $fail_count, 'Rebuild complete.');
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
        __DIR__ . '/indexer_rebuild_status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
