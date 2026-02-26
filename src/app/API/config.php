<?php
// config.php - 構造定義（パス・スキーマ・固定値）-

// ベースディレクトリ（APIフォルダから見たappフォルダの絶対パスを取得）
define('BASE_DIR',            realpath(__DIR__ . '/..'));
define('DIR_IMPORT_NORM',     BASE_DIR . '/01a_import_norm');
define('DIR_IMPORT_BULK',     BASE_DIR . '/01b_import_bulk');
define('DIR_IMPORT_RAW_ARC',  BASE_DIR . '/01c_import_raw_arc');  // archiver.php 書き出し先
define('DIR_VALID',           BASE_DIR . '/02_valid');
define('DIR_VALID_CONFIRMED', BASE_DIR . '/02_valid/Confirmed');   // dispatcher.php 読み出し元①
define('DIR_DISPATCH',        BASE_DIR . '/03a_dispatch');
define('DIR_DISCARD_TEMP',    BASE_DIR . '/03a_dispatch/~discardtemp'); // dispatcher.php 破棄一時置き場
define('DIR_MERGE',           BASE_DIR . '/03b_merge');
define('DIR_MERGE_CONF',      BASE_DIR . '/03b_merge/M_Confirmed');    // dispatcher.php 読み出し元②
define('DIR_MAIN_DB',         BASE_DIR . '/mainDB');
define('DIR_MAIN_DB_OLD',     BASE_DIR . '/mainDB_OLD');
define('DIR_REGISTERCACHE',   BASE_DIR . '/mainDB/registercache');     // dispatcher.php 新規カード置き場
define('INDEX_JSON',          BASE_DIR . '/mainDB/index.json');        // dispatcher.php 照合元

// task_master.jsonのパス
define('TASK_MASTER_JSON',    BASE_DIR . '/API/task_master.json');

/**
 * 各モジュールのステータスの記録場所
 *
 * 例：
 * src/app/archiver.php        → src/app/archiver_status.json
 * src/app/normalizer.php      → src/app/normalizer_status.json
 * mainDB/registercache/indexer_add.php → mainDB/registercache/indexer_add_status.json
 * つまりPHPと同じ場所に _status.json を作る
 */
define('STATUS_JSON_SUFFIX', '_status.json');

// normalizer.php が使う定数
define('DIR_VALID_ERROR',  BASE_DIR . '/02_valid/error');   // エラーカード置き場
define('DIR_API',          BASE_DIR . '/API');              // ロックファイル・ログ置き場
define('SCHEMA_VERSION',   '1.0');

// import_Norm.php / uid.php が使う定数
define('SERVER_CONFIG_JSON', BASE_DIR . '/API/server_config.json');
define('IMPORT_MAX_SIZE',    1048576);  // 1送信あたりの総サイズ上限 1024KB
define('IMPORT_MAX_FILES',   30);       // 1送信あたりのファイル数上限
define('IMPORT_SOFT_LIMIT',  250);      // セッション累積ソフト上限（警告のみ）
