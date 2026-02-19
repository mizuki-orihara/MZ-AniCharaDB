<?php
// config.php ? 構造定義（パス・スキーマ・固定値）

// ベースディレクトリ（APIフォルダから見たappフォルダの絶対パスを取得）
define('BASE_DIR',        realpath(__DIR__ . '/..'));
define('import_norm_DIR', BASE_DIR . '/01a_import_norm');
define('import_bulk_DIR', BASE_DIR . '/01b_import_bulk');
define('valid_DIR',       BASE_DIR . '/02_valid');
define('dispatch_DIR',    BASE_DIR . '/03a_dispatch');
define('merge_DIR',       BASE_DIR . '/03b_merge');
define('mainDB_DIR',      BASE_DIR . '/mainDB');



