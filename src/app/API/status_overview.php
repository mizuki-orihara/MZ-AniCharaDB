<?php
// 各モジュールの *_status.json を収集して返すサブAPI

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'GET only.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';

$scan_roots = [
    BASE_DIR,
    DIR_MAIN_DB,
];

$results = [];
$seen = [];

foreach ($scan_roots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $name = $file->getFilename();
        if (!str_ends_with($name, STATUS_JSON_SUFFIX)) {
            continue;
        }

        $real_path = $file->getPathname();
        if (isset($seen[$real_path])) {
            continue;
        }
        $seen[$real_path] = true;

        $json_text = @file_get_contents($real_path);
        if ($json_text === false) {
            $results[] = [
                'module' => basename($name, STATUS_JSON_SUFFIX),
                'path' => str_replace(BASE_DIR . '/', '', $real_path),
                'updated_at' => date('c', $file->getMTime()),
                'status' => 'error',
                'message' => 'status file read failed',
                'raw' => null,
            ];
            continue;
        }

        $decoded = json_decode($json_text, true);
        if (!is_array($decoded)) {
            $results[] = [
                'module' => basename($name, STATUS_JSON_SUFFIX),
                'path' => str_replace(BASE_DIR . '/', '', $real_path),
                'updated_at' => date('c', $file->getMTime()),
                'status' => 'error',
                'message' => 'status file parse failed',
                'raw' => null,
            ];
            continue;
        }

        if (isset($decoded['status'])) {
            $status = (string)$decoded['status'];
        } elseif (isset($decoded['result'])) {
            $status = (string)$decoded['result'];
        } else {
            $status = (($decoded['fail_count'] ?? 0) > 0) ? 'warning' : 'ok';
        }

        $message = isset($decoded['message'])
            ? (string)$decoded['message']
            : (isset($decoded['gate_status_log']) ? (string)$decoded['gate_status_log'] : 'no message');

        $results[] = [
            'module' => basename($name, STATUS_JSON_SUFFIX),
            'path' => str_replace(BASE_DIR . '/', '', $real_path),
            'updated_at' => date('c', $file->getMTime()),
            'status' => (string)$status,
            'message' => (string)$message,
            'raw' => $decoded,
        ];
    }
}

usort($results, function (array $a, array $b): int {
    return strcmp($a['module'], $b['module']);
});

$summary = [
    'total' => count($results),
    'ok' => 0,
    'warning' => 0,
    'error' => 0,
    'other' => 0,
];

foreach ($results as $item) {
    $s = strtolower($item['status']);
    if ($s === 'ok' || $s === 'success') {
        $summary['ok']++;
    } elseif ($s === 'warning') {
        $summary['warning']++;
    } elseif ($s === 'error' || $s === 'failed' || $s === 'fail') {
        $summary['error']++;
    } else {
        $summary['other']++;
    }
}

echo json_encode([
    'status' => 'ok',
    'generated_at' => date('c'),
    'summary' => $summary,
    'modules' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
