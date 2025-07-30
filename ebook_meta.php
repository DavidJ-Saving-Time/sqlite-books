<?php
require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

function parse_ebook_meta(string $text): array {
    $result = [];

    // Remove ANSI escape codes (if any)
    $text = preg_replace('/\x1b\[[0-9;]*m/', '', $text);

    foreach (preg_split("/\r\n|\r|\n/", $text) as $line) {
        if (preg_match('/^\s*([^:]+?)\s*:\s*(.+)$/', $line, $matches)) {
            $k = strtolower(trim($matches[1]));
            $v = trim($matches[2]);

            if ($k === 'author(s)') $k = 'authors';
            if ($k === 'identifiers') $k = 'identifiers';

            $result[$k] = $v;
        }
    }

    // Convert identifiers like "isbn:9781035909759" to ["isbn" => "9781035909759"]
    if (!empty($result['identifiers'])) {
        $identifiers = [];
        foreach (explode(',', $result['identifiers']) as $id) {
            [$key, $value] = array_map('trim', explode(':', $id, 2));
            $identifiers[$key] = $value;
        }
        $result['identifiers'] = $identifiers;
    }

    // Convert authors string to array, removing any author sort text like
    // "John Doe [Doe, John]" that Calibre's `ebook-meta` may include
    if (!empty($result['authors'])) {
        $clean = preg_replace('/\[[^\]]*\]/', '', $result['authors']);
        $authors = preg_split('/\s*&\s*|\s+and\s+|,/', $clean);
        $authors = array_filter(array_map('trim', $authors), fn($a) => $a !== '');
        $result['authors'] = array_values($authors);
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

    // Extract the original extension
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (!$ext) $ext = 'epub'; // fallback extension if none provided

    // Move uploaded file to a new temp file with correct extension
    $tmpFile = sys_get_temp_dir() . '/' . uniqid('ebook_', true) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $tmpFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }

    $path = $tmpFile;
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

// Ensure we use English output
$cmd = 'LANG=C ebook-meta ' . escapeshellarg($path) . ' 2>/dev/null';
$out = shell_exec($cmd);

if ($out === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to run ebook-meta']);
    exit;
}

$data = parse_ebook_meta($out);
$data['status'] = 'ok';

echo json_encode($data);