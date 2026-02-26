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

require_once __DIR__ . '/API/config.php';

// ===== ゲートチェック =====
// 確認対象: gates内の dispatch と merge_confirmd
// 両方falseならexit(0)で終了
// 作業順は通常dispatch(DIR_VALID_CONFIRMED)が先、merge_confirmd(DIR_MERGE_CONF)が後

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);

$gate_dispatch       = $task_master['gates']['dispatch']       ?? false;
$gate_merge_confirmd = $task_master['gates']['merge_confirmd'] ?? false;

if (!$gate_dispatch && !$gate_merge_confirmd) {
    // 両方のゲートが閉まっている → 何もせず正常終了
    exit(0);
}

// ===== ~discardtemp の準備 =====
// 起動時に残骸があれば先に削除、新規作成
if (is_dir(DIR_DISCARD_TEMP)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(DIR_DISCARD_TEMP, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir(DIR_DISCARD_TEMP);
}
mkdir(DIR_DISCARD_TEMP, 0755, true);

// ===== index.json を一度だけ読み込む =====
$index = file_exists(INDEX_JSON)
    ? json_decode(file_get_contents(INDEX_JSON), true) ?? []
    : [];

// ===== 処理本体 =====

if ($gate_dispatch) {
    run_dispatch(DIR_VALID_CONFIRMED . '/', $index);
}

if ($gate_merge_confirmd) {
    run_dispatch(DIR_MERGE_CONF . '/', $index);
}

// ===== ~discardtemp を処理完了後に削除 =====
if (is_dir(DIR_DISCARD_TEMP)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(DIR_DISCARD_TEMP, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir(DIR_DISCARD_TEMP);
}

exit(0);

// ===== 関数定義 =====

/**
 * 指定ディレクトリのカードをindex.jsonと照合して振り分ける
 * 読み込みディレクトリ以外の処理はすべて共通
 */
function run_dispatch(string $read_dir, array $index): void {

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
            copy($file, DIR_REGISTERCACHE . '/' . basename($file));
            unlink($file);

        } elseif ($index[$card_id] === $hash) {
            // ===== 完全一致 → ~discartemp に退避後、元ファイル削除 =====
            copy($file, DIR_DISCARD_TEMP . '/' . basename($file));
            unlink($file);

        } else {
            // ===== 部分一致（card_id一致・hash不一致）→ マージ待ち =====
            copy($file, DIR_MERGE . '/' . basename($file));
            unlink($file);
        }
    }
}
