<?php
require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

function parse_ebook_meta(string $text): array {
    $result = [];
    foreach (preg_split("/[\r\n]+/", $text) as $line) {
        if (strpos($line, ':') === false) continue;
        list($k, $v) = array_map('trim', explode(':', $line, 2));
        $k = strtolower($k);
        if ($k === 'author(s)') $k = 'authors';
        if ($k === 'identifiers') $k = 'identifier';
        $result[$k] = $v;
    }
    return $result;
}

$path = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file']);
        exit;
    }
    $path = $file['tmp_name'];
} else {
    $rel = ltrim($_GET['path'] ?? '', '/');
    if ($rel === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing path']);
        exit;
    }
    $abs = getLibraryPath() . '/' . $rel;
    if (!file_exists($abs)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    $path = $abs;
}

$out = shell_exec('ebook-meta ' . escapeshellarg($path) . ' 2>/dev/null');
if ($out === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to run ebook-meta']);
    exit;
}
$data = parse_ebook_meta($out);
$data['status'] = 'ok';

echo json_encode($data);
