<?php
//ノーマライズによって転記の済んだ原本のアーカイブ処理
// 01a_import_norm/~temp[SID]を、01c_import_raw_arc/へ、~[SID].zipにして保管

require_once __DIR__ . '/API/config.php';

//ゲートチェック
$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);
if (($task_master['gates']['archive'] ?? false) !== true) {
	// ゲートが閉まっている → 何もせず正常終了
	exit(0);
}

// ▼ EXTEND: ゲート以外の起動条件（時間帯制限など）を追加するならここ

//ノーマライザーが残した作業済みリスト（01a_import_norm/DONE.json）を読み込む
$done_list_path = DIR_IMPORT_NORM . '/DONE.json';
if (!file_exists($done_list_path)) {
	// DONE.jsonがない → 何もせず正常終了
	exit(0);
}

// DONE.jsonを読み込んで、追記されて24時間以上経過したディレクトリを圧縮
// log記録用タイマースタート
$status_start_time = microtime(true);

// ループ前（DONE.json読み込みの近く）で一度だけ開く
$archiver_log_path = __DIR__ . '/API/archiver_log.json';
$archiver_log = file_exists($archiver_log_path)
    ? json_decode(file_get_contents($archiver_log_path), true) ?? []
    : [];

$session_count  = 0;
$total_archived = 0;
$error_count    = 0;

// 書き出し先ディレクトリの存在確認（なければ作成）
if (!is_dir(DIR_IMPORT_RAW_ARC)) {
	mkdir(DIR_IMPORT_RAW_ARC, 0755, true);
}

$done_list = json_decode(file_get_contents($done_list_path), true);
$now = time();
foreach ($done_list as $sid => $timestamp) {
	// 処理済み（配列化済み）のエントリはスキップ
	if (!is_string($timestamp)) continue;

	if ($now - strtotime($timestamp) >= 24 * 3600) {
		$temp_dir = DIR_IMPORT_NORM . '/~temp_' . $sid;
		if (is_dir($temp_dir)) {
			$zip_path = DIR_IMPORT_RAW_ARC . '/~' . $sid . '.zip';
			$zip = new ZipArchive();
			if ($zip->open($zip_path, ZipArchive::CREATE) === true) {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_dir), RecursiveIteratorIterator::LEAVES_ONLY);
				foreach ($files as $file) {
					if (!$file->isDir()) {
						$filePath = $file->getRealPath();
						$relativePath = substr($filePath, strlen($temp_dir) + 1);
						$zip->addFile($filePath, $relativePath);
					}
				}
				$zip->close();

				// zip成功時のみ削除・記録（失敗時は~tempをそのまま残す）
				$file_count = count(glob($temp_dir . '/*.json'));
				$done_list[$sid] = [$timestamp, $file_count]; // [タイムスタンプ, 枚数] に更新

				// ログエントリ追加　最新が上に来るよう追記して上書き
				array_unshift($archiver_log, [
					'status'     => 'success',
					'timestamp'  => date('c'),
					'sid'        => $sid,
					'zip_name'   => basename($zip_path),
					'file_count' => $file_count,
				]);

				// ディレクトリ削除
				$iter = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
					RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ($iter as $file) {
					if ($file->isDir()) {
						rmdir($file->getRealPath());
					} else {
						unlink($file->getRealPath());
					}
				}
				rmdir($temp_dir); // 親ディレクトリ本体を削除

				$session_count++;
				$total_archived += $file_count;

			} else {
				// ZIP失敗 → ~tempは残したままエラーをログに記録
				$error_count++;
				array_unshift($archiver_log, [
					'status'    => 'error',
					'timestamp' => date('c'),
					'sid'       => $sid,
					'zip_name'  => basename($zip_path),
					'message'   => 'ZipArchive::open() failed. ~temp directory preserved.',
				]);
			}
		}
	}
}

// 更新されたDONEリストと、アーカイバーの作業ログを保存
file_put_contents($done_list_path, json_encode($done_list, JSON_PRETTY_PRINT));
file_put_contents($archiver_log_path, json_encode($archiver_log, JSON_PRETTY_PRINT));

// アーカイバー自身の作業記録（直近実行のスナップショット）
$status_path = __DIR__ . '/archiver_status.json';
$status = [
	'timestamp'            => date('c'),
	'archived_sessions'    => $session_count,
	'total_files_archived' => $total_archived,
	'error_count'          => $error_count,
	'processing_time_sec'  => round(microtime(true) - $status_start_time, 2),
	// ▼ EXTEND: 追加ステータスはここ
];
file_put_contents($status_path, json_encode($status, JSON_PRETTY_PRINT));
