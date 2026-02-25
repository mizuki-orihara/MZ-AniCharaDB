<?php
/**
 * normalizer.php
 * 役割：01a_import_norm/内の ~temp_* フォルダからカードを読み出し、
 *       正規化・複製転記して 02_valid/Confirmed/ へ昇格させる
 *
 * 読み出し元：01a_import_norm/~temp_* / *.json
 * 書き出し先（正常）：02_valid/Confirmed/  [work]_[name]_[branch]_[hash].json
 * 書き出し先（エラー）：02_valid/error/  [raw_filename].json
 * エラーリスト：02_valid/error/errors.json  ["エラー理由 / ファイル名", ...] 発生順
 */

require_once __DIR__ . '/API/config.php';

// ===== log・status記録用の初期化 =====

$start_time    = microtime(true);
$last_file     = '';
$total_count   = 0;
$success_count = 0;
$fail_count    = 0;
$errors        = [];

// ===== ゲートチェック =====

$task_master = json_decode(file_get_contents(TASK_MASTER_JSON), true);

if (($task_master['gates']['valid_verify'] ?? false) !== true) {
    // ゲートが閉まっている → 何もせず正常終了
    write_status_log('valid_verify:closed', $last_file, 0, 0, 0, []);
    exit(0);
}

// ===== ロック取得 =====
// ロックファイルは API/ 以下に置く（Confirmed/ を汚さない）

$lock_path = DIR_API . '/normalizer.lock';

if (file_exists($lock_path)) {
    $lock     = json_decode(file_get_contents($lock_path), true);
    $lock_age = time() - strtotime($lock['at'] ?? '1970-01-01');
    if ($lock_age < 60) {
        // 60秒以内のロックは有効 → 競合回避のため終了
        write_status_log('valid_verify:locked', $last_file, 0, 0, 0, ['別プロセスが占有中']);
        exit(0);
    }
    // 古いロックは無効として上書き
}

file_put_contents($lock_path, json_encode([
    'pid' => getmypid(),
    'at'  => date('c')
]));

// ===== 書き出し先ディレクトリの準備 =====

foreach ([DIR_VALID_CONFIRMED, DIR_VALID_ERROR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// ===== メインループ =====

$temp_dirs = glob(DIR_IMPORT_NORM . '/~temp_*', GLOB_ONLYDIR);

foreach ($temp_dirs as $temp_dir) {
    $files = glob($temp_dir . '/*.json');

    foreach ($files as $file_path) {
        $raw_filename = basename($file_path);
        $last_file    = $raw_filename;
        $total_count++;

        // --- JSON読み込み ---
        $raw = json_decode(file_get_contents($file_path), true);
        if ($raw === null) {
            $fail_count++;
            $errors[] = "JSON_PARSE_ERROR / {$raw_filename}";
            move_to_error($file_path, $raw_filename);
            continue;
        }

        // --- 正規化 ---
        $result = normalize_card($raw);
        if ($result['status'] !== 'ok') {
            $fail_count++;
            $errors[] = $result['error'] . ' / ' . $raw_filename;
            move_to_error($file_path, $raw_filename);
            continue;
        }

        $card = $result['card'];

        // --- 書き出しファイル名の生成 ---
        // [work]_[name]_[branch]_[hash].json
        // 値は原文保持、ファイル名生成時だけサニタイズ
        $safe_work    = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $card['header']['work']   ?? 'unknown');
        $safe_name    = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $card['header']['name']   ?? 'unknown');
        $branch       = $card['header']['branch'] ?? 'main';
        $hash         = substr($card['header']['content_hash'], 0, 8);
        $new_filename = "{$safe_work}_{$safe_name}_{$branch}_{$hash}.json";

        // --- アトミック書き込み ---
        $dest     = DIR_VALID_CONFIRMED . '/' . $new_filename;
        $tmp_dest = $dest . '.tmp';
        file_put_contents($tmp_dest,
            json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        rename($tmp_dest, $dest);

        $success_count++;
    }
}

// ===== エラーリスト書き出し =====

if (!empty($errors)) {
    $error_list_path = DIR_VALID_ERROR . '/errors.json';
    $existing = file_exists($error_list_path)
        ? json_decode(file_get_contents($error_list_path), true) ?? []
        : [];
    $merged = array_merge($existing, $errors);
    file_put_contents($error_list_path,
        json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

// ===== ロック解放・ステータス記録・終了 =====

@unlink($lock_path);
write_status_log('valid_verify:open', $last_file, $total_count, $success_count, $fail_count, $errors);
exit(0);


// ============================================================
// 補助関数
// ============================================================

/**
 * カードの正規化
 *
 * header構造:
 *   schema / uuid / content_hash / generated_at / branch / work / name
 *   work・name・branch はheader内を正式置き場とする
 *   ルート直下にあれば吸収してheaderに移設、なければ空欄を作る
 *
 * personality_parameters バリデーション仕様:
 *   - 軸名・labels は自由（触らない）
 *   - 各軸に labels(2要素) / values(1?2要素) / anchor(0 or 1) が必要
 *   - anchor側の値が信頼値（0.0?10.0）
 *   - values が1つだけ → 反対側を 10.0 - anchor値 で補完
 *   - values が2つある → anchor側を正として反対側を 10.0 - anchor値 で上書き
 *
 * @return array ['status'=>'ok','card'=>[...]] or ['status'=>'error','error'=>'理由']
 */
function normalize_card(array $raw): array {

    $meta = $raw['header'] ?? [];

    // --- work / name をheaderから or ルート直下から吸収、なければ空欄 ---
    $work   = trim($meta['work']   ?? $raw['work']   ?? '');
    $name   = trim($meta['name']   ?? $raw['name']   ?? '');
    $branch = trim($meta['branch'] ?? $raw['branch'] ?? 'main');
    $uuid   = $meta['uuid']        ?? $raw['uuid']   ?? generate_uuid();

    $card = [];

    // --- header ブロック ---
    $card['header'] = [
        'schema'       => SCHEMA_VERSION,
        'uuid'         => $uuid,
        'content_hash' => '',   // 後で計算
        'generated_at' => $meta['generated_at'] ?? $raw['generated_at'] ?? date('c'),
        'branch'       => $branch,
        'work'         => $work,
        'name'         => $name,
    ];

    // --- ルート直下の推奨項目（なければ空欄） ---
    $card['name']         = $name;  // headerと同値・参照用として残す
    $card['work']         = $work;  // 同上
    $card['tags']         = $raw['tags']         ?? [];
    $card['profile']      = $raw['profile']      ?? [];
    $card['rating']       = $raw['rating']        ?? [];
    $card['affiliations'] = $raw['affiliations']  ?? [];
    $card['comments']     = $raw['comments']      ?? [];

    // --- personality_parameters の正規化 ---
    $params = $raw['personality_parameters'] ?? null;
    if ($params !== null) {
        foreach ($params as $axis_name => $axis) {
            $values = $axis['values'] ?? [];
            $anchor = $axis['anchor'] ?? 0;

            // anchor値の存在・範囲チェック
            if (!isset($values[$anchor]) || !is_numeric($values[$anchor])) {
                return ['status' => 'error', 'error' => "INVALID_ANCHOR:personality_parameters.{$axis_name}"];
            }
            $anchor_val = (float)$values[$anchor];
            if ($anchor_val < 0.0 || $anchor_val > 10.0) {
                return ['status' => 'error', 'error' => "OUT_OF_RANGE:personality_parameters.{$axis_name}"];
            }

            // 反対側インデックス
            $other = $anchor === 0 ? 1 : 0;

            // 補完 or 上書き
            $params[$axis_name]['values'][$anchor] = $anchor_val;
            $params[$axis_name]['values'][$other]  = round(10.0 - $anchor_val, 10);
        }
        $card['personality_parameters'] = $params;
    } else {
        $card['personality_parameters'] = null;
    }

    // --- 拡張ブロック透過転記（既知キー以外はそのまま通す） ---
    $known_keys = [
        'header', 'uuid', 'generated_at', 'branch',
        'name', 'work', 'tags', 'profile', 'rating',
        'affiliations', 'comments', 'personality_parameters', 'product',
    ];
    foreach ($raw as $key => $val) {
        if (!in_array($key, $known_keys, true)) {
            $card[$key] = $val;
        }
    }

    // --- オプション項目 ---
    if (isset($raw['product'])) $card['product'] = $raw['product'];

    // --- content_hash生成（headerを除外してコンテンツのみ） ---
    $hash_target = $card;
    unset($hash_target['header']);
    ksort($hash_target);
    $card['header']['content_hash'] = md5(json_encode($hash_target, JSON_UNESCAPED_UNICODE));

    return ['status' => 'ok', 'card' => $card];
}

/**
 * エラーファイルを 02_valid/error/ に移動
 */
function move_to_error(string $src_path, string $filename): void {
    if (!is_dir(DIR_VALID_ERROR)) mkdir(DIR_VALID_ERROR, 0755, true);
    rename($src_path, DIR_VALID_ERROR . '/' . $filename);
}

/**
 * UUID v4 生成
 */
function generate_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * ステータスログ書き込み
 * 保存先: src/app/normalizer_status.json（モジュールと同列）
 */
function write_status_log(
    string $gate_status_log,
    string $last_file,
    int    $total_count,
    int    $success_count,
    int    $fail_count,
    array  $errors
): void {
    global $start_time;
    $elapsed = microtime(true) - $start_time;
    $status  = [
        'date'            => date('Y-m-d H:i:s'),
        'gate_status_log' => $gate_status_log,
        'last_file'       => $last_file,
        'total_count'     => $total_count,
        'success_count'   => $success_count,
        'fail_count'      => $fail_count,
        'throughput'      => $elapsed > 0 ? round($total_count / $elapsed, 2) : 0,
        'errors'          => $errors,
    ];
    file_put_contents(
        __DIR__ . '/normalizer_status.json',
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
