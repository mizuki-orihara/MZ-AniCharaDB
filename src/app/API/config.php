<?php
// config.php — 構造定義（パス・スキーマ・固定値）

// ベースディレクトリ（このファイルの位置を基点に一元管理）
define('BASE_DIR',        realpath (__DIR__ .'/MZ-AniCharaDB/src/app'));
define('import_norm_DIR', BASE_DIR .'/01a_import_norm');
define('import_bulk_DIR',  BASE_DIR .'/01b_import_bulk');
define('varid_DIR',       BASE_DIR .'/02_varid');
define('dispatch_DIR',     BASE_DIR .'/03a_dispatch');
define('marge_DIR',        BASE_DIR .'/03b_marge');
define('mainDB_DIR',      BASE_DIR .'/MainDB' ); 





