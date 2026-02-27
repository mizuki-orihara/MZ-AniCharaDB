<?php
// DBカードのキーをスキャンしてリスト化するモジュール
// ネストはドット記法で展開し、ユニークキーを1行1キーで出力する
// 使い方: php key_scanner.php [枚数上限]
//   例: php key_scanner.php 500   → 最大500枚をスキャン
//       php key_scanner.php       → 全数スキャン

require_once __DIR__ . '/config.php';

// 枚数上限（コマンドライン引数で指定、省略時は全数）
$limit = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : PHP_INT_MAX;

// 対象ファイル取得
$files = glob(DIR_MAIN_DB . '/*.json');
if (empty($files)) {
	echo "対象ファイルが見つかりません。\n";
	exit(1);
}
$files = array_slice($files, 0, $limit);

// JSONを再帰的に走査してキーをドット記法でリストアップ
function extract_keys(array $data, string $prefix = ''): array {
	$keys = [];
	foreach ($data as $key => $value) {
		$full_key = $prefix === '' ? $key : $prefix . '.' . $key;
		$keys[] = $full_key;
		if (is_array($value)) {
			$keys = array_merge($keys, extract_keys($value, $full_key));
		}
	}
	return $keys;
}

// 全カードからキーを収集
$all_keys = [];
foreach ($files as $file) {
	$card = json_decode(file_get_contents($file), true);
	if (!is_array($card)) continue;
	$keys = extract_keys($card);
	foreach ($keys as $key) {
		$all_keys[$key] = true;
	}
}

// トップレベル順を保ちつつネストをぶら下げる形でソート
ksort($all_keys);

// 出力
$output_path = __DIR__ . '/key_list.txt';
file_put_contents($output_path, implode("\n", array_keys($all_keys)) . "\n");

echo "スキャン完了: " . count($files) . "枚 / " . count($all_keys) . "キー\n";
echo "出力先: {$output_path}\n";
