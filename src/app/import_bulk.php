<?php
<?php
/**
 * xxxxxx.php - モジュール側ゲートチェック見本
 * 
 *
 * ゲートが閉まっていたら何もせずexit(0)
 * 処理完了もexit(0)
 * エラーはexit(1)
 **/

require_once __DIR__ . '/api/config.php';

// ===== ゲートチェック =====
// task_master.jsonを読んで自分のゲートが開いているか確認

$task_master_path = __DIR__ . '/api/task_master.json';
$task_master      = json_decode(file_get_contents($task_master_path), true);

if ($task_master['gates']['xxxxx'] !== false) { //「is gate are open?ゲート開いてる？」
    // ゲートが閉まっている → 何もせず正常終了
    // core.phpは「正常終了」として次のモジュールへ進む
    exit(0);
}

// ===== 処理本体 =====