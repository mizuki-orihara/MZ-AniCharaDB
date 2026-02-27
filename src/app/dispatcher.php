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

$start_time = microtime(true);

// ===== ゲートチェック =====
// 確認対象: gates内の dispatch と merge_confirmd
// 両方falseならexit(0)で終了
// 作業順は通常dispatch(DIR_VALID_CONFIRMED)が先、merge_confirmd(DIR_MERGE_CONF)が後

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);

$gate_dispatch       = $task_master['gates']['dispatch']       ?? false;
$gate_merge_confirmd = $task_master['gates']['merge_confirmd'] ?? false;

if (!$gate_dispatch && !$gate_merge_confirmd) {
    // 両方のゲートが閉まっている → 何もせず正常終了
    write_status($gate_dispatch, $gate_merge_confirmd, 'success', 0, 0, 0);
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

// ===== 書き出し先ディレクトリの準備（なければ作る） =====
foreach ([DIR_REGISTERCACHE, DIR_MERGE] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// ===== index.json を一度だけ読み込む =====
$index = file_exists(INDEX_JSON)
    ? json_decode(file_get_contents(INDEX_JSON), true) ?? []
    : [];

$new_count     = 0;
$discard_count = 0;
$merge_count   = 0;

// ===== dispatcher_log.csv の準備 =====
// バッチ実行ごとに区切り行＋ヘッダーを追記する
$log_path = __DIR__ . '/dispatcher_log.csv';
$log_fh   = fopen($log_path, 'a');
fwrite($log_fh, '# ' . date('Y-m-d H:i:s') . "\n");
fputcsv($log_fh, ['timestamp', 'filename', 'card_id', 'result']);

// ===== 処理本体 =====

if ($gate_dispatch) {
    $counts = run_dispatch(DIR_VALID_CONFIRMED . '/', $index, $log_fh);
    $new_count     += $counts['new'];
    $discard_count += $counts['discard'];
    $merge_count   += $counts['merge'];
}

if ($gate_merge_confirmd) {
    $counts = run_dispatch(DIR_MERGE_CONF . '/', $index, $log_fh);
    $new_count     += $counts['new'];
    $discard_count += $counts['discard'];
    $merge_count   += $counts['merge'];
}

// ===== ログファイルを閉じる =====
fclose($log_fh);

write_status($gate_dispatch, $gate_merge_confirmd, 'success', $new_count, $discard_count, $merge_count);

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
function run_dispatch(string $read_dir, array &$index, $log_fh): array {

    $counts = ['new' => 0, 'discard' => 0, 'merge' => 0];

    // 対象ディレクトリのJSONを1枚ずつ処理
    $files = glob($read_dir . '*.json');
    if (empty($files)) return $counts;

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
        $card_id  = $work . '_' . $name . '_' . $branch;
        $filename = basename($file);
        $now      = date('Y-m-d H:i:s');

        if (!isset($index[$card_id])) {
            // ===== 一致せず → 新規 =====
            if (copy($file, DIR_REGISTERCACHE . '/' . $filename)) {
                unlink($file);
                $index[$card_id] = $hash;
                fputcsv($log_fh, [$now, $filename, $card_id, 'new']);
                $counts['new']++;
            }

        } elseif ($index[$card_id] === $hash) {
            // ===== 完全一致 → ~discardtemp に退避後、元ファイル削除 =====
            copy($file, DIR_DISCARD_TEMP . '/' . $filename);
            unlink($file);
            fputcsv($log_fh, [$now, $filename, $card_id, 'discard']);
            $counts['discard']++;

        } else {
            // ===== 部分一致（card_id一致・hash不一致）→ マージ待ち =====
            if (copy($file, DIR_MERGE . '/' . $filename)) {
                unlink($file);
                fputcsv($log_fh, [$now, $filename, $card_id, 'merge']);
                $counts['merge']++;
            }
        }
    }

    return $counts;
}

/**
 * dispatcher_status.json の書き込み
 */
function write_status(bool $gate_dispatch, bool $gate_merge_confirmd, string $result, int $new, int $discard, int $merge): void {
    global $start_time;
    $status = [
        'date'   => date('Y-m-d H:i:s'),
        'gates'  => [
            'dispatch'       => $gate_dispatch,
            'merge_confirmd' => $gate_merge_confirmd,
        ],
        'result'        => $result,
        'new_count'     => $new,
        'discard_count' => $discard,
        'merge_count'   => $merge,
        'total_count'   => $new + $discard + $merge,
        'elapsed_sec'   => round(microtime(true) - $start_time, 3),
    ];
    file_put_contents(
        __DIR__ . '/dispatcher_status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
