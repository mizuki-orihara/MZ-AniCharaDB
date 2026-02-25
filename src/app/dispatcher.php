<?php
/**
 * dispatcher.php
 * 役割: 02_valid/Confirmed/ および 03b_merge/M_Confirmed/ のカードを
 *       mainDB/index.jsonと照合し振り分ける
 *
 * ゲートが閉まっていたら何もせずexit(0)
 * 処理完了もexit(0)
 * エラーはexit(1)
 **/

require_once __DIR__ . '/api/config.php';

// ===== ゲートチェック =====
// 確認対象: gates内の dispatch と M_Confirmed
// 両方falseならexit(0)で終了
// 作業順は通常dispatch(DISPATCH_DIR)が先、dispatch_merge(M_Confirmed_DIR)が後

$task_master = json_decode(file_get_contents(__DIR__ . '/api/task_master.json'), true);

$gate_dispatch       = $task_master['gates']['dispatch']       ?? false;
$gate_merge_confirmd = $task_master['gates']['merge_confirmd'] ?? false;

if (!$gate_dispatch && !$gate_merge_confirmd) {
    // 両方のゲートが閉まっている → 何もせず正常終了
    exit(0);
}

// ===== 処理本体 =====

if ($gate_dispatch) {
    run_dispatch(DISPATCH_DIR);
}

if ($gate_merge_confirmd) {
    run_dispatch(DISPATCH_MERGE_DIR);
}

exit(0);

// ===== 関数定義 =====

/**
 * 指定ディレクトリのカードをindex.jsonと照合して振り分ける
 * 読み込みディレクトリ以外の処理はすべて共通
 */
function run_dispatch(string $read_dir): void {

    // index.jsonを読み込む（なければ空配列）
    $index = file_exists(INDEX_JSON)
        ? json_decode(file_get_contents(INDEX_JSON), true) ?? []
        : [];

    // 対象ディレクトリのJSONを1枚ずつ処理
    $files = glob($read_dir . '*.json');
    if (empty($files)) return;

    foreach ($files as $file) {

        $card = json_decode(file_get_contents($file), true);
        if ($card === null) continue; // パース失敗はスキップ

        // headerから識別子を取り出す
        // header内に work / name / branch を持つ新スキーマ前提
        $work   = $card['header']['work']   ?? '';
        $name   = $card['header']['name']   ?? '';
        $branch = $card['header']['branch'] ?? '000';
        $hash   = $card['header']['content_hash'] ?? '';

        // 識別キー: work_name_branch
        $card_id = $work . '_' . $name . '_' . $branch;

        if (!isset($index[$card_id])) {
            // ===== 一致せず → 新規 =====
            copy($file, REGISTERCACHE_DIR . basename($file));
            unlink($file);

        } elseif ($index[$card_id] === $hash) {
            // ===== 完全一致 → 破棄待ち =====
            log_discard($file);
            unlink($file);

        } else {
            // ===== 部分一致（card_id一致・hash不一致）→ マージ待ち =====
            copy($file, MERGE_DIR . basename($file));
            unlink($file);
        }
    }
}

/**
 * 破棄待ちリストに記録する
 * リスト形式は未定・暫定実装
 */
function log_discard(string $file): void {
    // TODO: 破棄待ちリストのファイル形式確定後に実装
    $entry = [
        'file'         => basename($file),
        'discarded_at' => date('Y-m-d\TH:i:s\Z')
    ];
    $log_path = DISCARD_LOG;  // config.phpで定数化想定
    $log = file_exists($log_path)
        ? json_decode(file_get_contents($log_path), true) ?? []
        : [];
    $log[] = $entry;
    file_put_contents($log_path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}