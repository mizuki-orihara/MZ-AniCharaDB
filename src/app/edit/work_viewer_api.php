<?php
/**
 * work_viewer_api.php
 * 役割: mainDB/ を走査し、作品単位のキャラ×キーマトリクスデータを返す
 *
 * GET ?mode=works          → 作品リストを返す
 * GET ?mode=matrix&work=XX → 指定作品のキャラ×キーマトリクスを返す
 *
 * レスポンス:
 *   200 JSON
 *   400 Bad Request
 *   500 Internal Error
 */

require_once __DIR__ . '/../API/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'GET only.']);
    exit;
}

$mode = $_GET['mode'] ?? '';
$exclude = ['index.json', 'indexer_add_status.json', 'indexer_rebuild_status.json'];

// ===== mainDB走査（共通） =====

$files = glob(DIR_MAIN_DB . '/*.json') ?: [];

// カードを読み込んでwork単位にグループ化
$work_map = [];  // work => [ card_id => card ]

foreach ($files as $file_path) {
    $filename = basename($file_path);
    if (in_array($filename, $exclude, true)) continue;

    $card = json_decode(file_get_contents($file_path), true);
    if (!is_array($card)) continue;

    $work    = $card['header']['work'] ?? '';
    $card_id = $card['header']['work'] . '_' . $card['header']['name'] . '_' . $card['header']['branch'];
    if (empty($work)) continue;

    $card['_filename'] = basename($file_path);
    $work_map[$work][$card_id] = $card;
}

ksort($work_map);

// ===== mode=works: 作品リストを返す =====

if ($mode === 'works') {
    $works = [];
    foreach ($work_map as $work => $cards) {
        $works[] = [
            'work'  => $work,
            'count' => count($cards),
        ];
    }
    echo json_encode(['status' => 'ok', 'works' => $works], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== mode=matrix: 指定作品のマトリクスを返す =====

if ($mode === 'matrix') {
    $work = $_GET['work'] ?? '';
    if (empty($work)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'work parameter required.']);
        exit;
    }

    if (!isset($work_map[$work])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'work not found.']);
        exit;
    }

    $cards = $work_map[$work];

    // 全カードのキーをunionで収集（フラット展開）
    $all_keys = [];
    $chara_data = [];  // card_id => flattened

    foreach ($cards as $card_id => $card) {
        $flat = flatten_card($card);
        $chara_data[$card_id] = $flat;
        foreach (array_keys($flat) as $key) {
            $all_keys[$key] = true;
        }
    }

    // headerキー → その他キーの順でソート
    $header_keys = array_filter(array_keys($all_keys), fn($k) => str_starts_with($k, 'header.'));
    $other_keys  = array_filter(array_keys($all_keys), fn($k) => !str_starts_with($k, 'header.'));
    sort($header_keys);
    sort($other_keys);
    $ordered_keys = array_values(array_merge($header_keys, $other_keys));

    // キャラ名リスト（表示用）+ filename
    $charas = [];
    foreach ($cards as $card_id => $card) {
        $charas[$card_id] = [
            'name'     => $card['header']['name'] ?? $card_id,
            'filename' => $card['_filename'] ?? '',
        ];
    }

    echo json_encode([
        'status'  => 'ok',
        'work'    => $work,
        'charas'  => $charas,
        'keys'    => $ordered_keys,
        'matrix'  => $chara_data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// mode未指定
http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'mode parameter required. (works|matrix)']);
exit;


// ============================================================
// 補助関数
// ============================================================

/**
 * カードをフラット展開（1段のみ）
 * header以外のオブジェクトは1段展開、配列・深いネストはそのまま
 */
function flatten_card(array $card): array {
    $result = [];
    foreach ($card as $key => $val) {
        if ($key === 'header') {
            // headerは子キーを展開
            foreach ($val as $hk => $hv) {
                $result["header.{$hk}"] = $hv;
            }
        } elseif (is_array($val) && !array_is_list($val)) {
            // 連想配列は1段展開
            foreach ($val as $sk => $sv) {
                $result["{$key}.{$sk}"] = $sv;
            }
        } else {
            $result[$key] = $val;
        }
    }
    return $result;
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
