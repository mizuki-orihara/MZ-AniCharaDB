<?php
// config.php - 構造定義（パス・スキーマ・固定値）-

// ベースディレクトリ（APIフォルダから見たappフォルダの絶対パスを取得）
define('BASE_DIR',          realpath(__DIR__ . '/..'));
define('DIR_IMPORT_NORM',   BASE_DIR . '/01a_import_norm');
define('DIR_IMPORT_BULK',   BASE_DIR . '/01b_import_bulk');
define('DIR_VALID',         BASE_DIR . '/02_valid');
define('DIR_DISPATCH',      BASE_DIR . '/03a_dispatch');
define('DIR_MERGE',         BASE_DIR . '/03b_merge');
define('DIR_MERGE_CONF',    BASE_DIR . '/03b_merge/M_Confirmed');
define('DIR_MAIN_DB',       BASE_DIR . '/mainDB');

//task_master.jsonのパス
define('TASK_MASTER_JSON',   BASE_DIR . '/API/task_master.json');
/**各モジュールのステータスの記録場所
 *
 *例：
 *
 * /API/import_Norm.php　なら　
 * /API/import_Norm_status.json
 * /mainDB/registercache/indexer_add.php　なら
 * /mainDB/registercache/indexer_add_status.json
 *　つまりPHPと同じ場所になければ、新規でそこに作らせる
 */
define('STATUS_JSON_SUFFIX', '_status.json');

