<?php
/**
 * merge_scanner.php
 * 役割: 03b_merge/ 内のカードを起点に、mainDB/ も含めた
 *       card_id単位の重複グループリストを生成する
 *
 * 出力: API/merge_group_list.json
 *
 * 実行タイミング: indexer_add / indexer_rebuild の後を想定
 *
 * 処理完了もexit(0)
 * エラーはexit(1)
 *
 * グループ構造:
 * [
 *   {
 *     "card_id": "響け_ユーフォニアム_高坂麗奈_main",
 *     "maindb":  ["...60fa9de2.json", ...],  // mainDB内の同card_id
 *     "merge":   ["...ee4659bf.json", ...]   // 03b_merge内の同card_id
 *   },
 *   ...
 * ]
 */

require_once __DIR__ . '/API/config.php';

$start_time = microtime(true);

// ===== ゲートチェック =====

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);
if (($task_master['gates']['dispatch'] ?? false) !== true) {
    write_status(0, 'Gate closed.');
    exit(0);
}

// ===== merge/ 内を走査してcard_idでグルーピング =====

$merge_files = glob(DIR_MERGE . '/' . '*.json') ?: [];

if (empty($merge_files)) {
    // マージ待ちなし → 空リストを書き出して終了
    write_group_list([]);
    write_status(0, 'No files in merge folder.');
    exit(0);
}

// card_id => [filename, ...] のグループマップ（merge側）
$merge_groups = [];
foreach ($merge_files as $file_path) {
    $filename = basename($file_path);
    $card_id  = extract_card_id($filename);
    if ($card_id === null) continue;
    $merge_groups[$card_id][] = $filename;
}

// ===== mainDB/ を走査して同card_idのファイルをピックアップ =====

$maindb_files = glob(DIR_MAIN_DB . '/' . '*.json') ?: [];
$exclude = ['index.json', 'indexer_add_status.json', 'indexer_rebuild_status.json'];

// card_id => [filename, ...] のグループマップ（mainDB側）
$maindb_groups = [];
foreach ($maindb_files as $file_path) {
    $filename = basename($file_path);
    if (in_array($filename, $exclude, true)) continue;

    $card_id = extract_card_id($filename);
    if ($card_id === null) continue;

    // merge側に存在するcard_idのみ対象
    if (!isset($merge_groups[$card_id])) continue;

    $maindb_groups[$card_id][] = $filename;
}

// ===== グループリスト組み立て =====

$group_list = [];
foreach ($merge_groups as $card_id => $merge_files_list) {
    $group_list[] = [
        'card_id' => $card_id,
        'maindb'  => $maindb_groups[$card_id] ?? [],
        'merge'   => $merge_files_list,
    ];
}

// card_id順にソート
usort($group_list, fn($a, $b) => strcmp($a['card_id'], $b['card_id']));

write_group_list($group_list);
write_status(count($group_list), 'Done.');
exit(0);


// ============================================================
// 補助関数
// ============================================================

/**
 * ファイル名からcard_idを逆引き
 * 命名規則: {safe_work}_{safe_name}_{branch}_{hash8}.json
 * 末尾の _xxxxxxxx（_+8文字）を除いた部分がcard_id
 *
 * @return string|null  card_id、または解析不能な場合null
 */
function extract_card_id(string $filename): ?string {
    $basename = basename($filename, '.json');
    if (strlen($basename) < 10) return null;  // _+8文字 = 9文字以上必要
    return substr($basename, 0, -9);
}

/**
 * merge_group_list.json を書き出す
 */
function write_group_list(array $group_list): void {
    file_put_contents(
        DIR_API . '/merge_group_list.json',
        json_encode($group_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * ステータス記録
 */
function write_status(int $group_count, string $message): void {
    global $start_time;
    $status = [
        'date'        => date('Y-m-d H:i:s'),
        'group_count' => $group_count,
        'message'     => $message,
        'elapsed_sec' => round(microtime(true) - $start_time, 3),
    ];
    file_put_contents(
        __DIR__ . '/merge_scanner_status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
