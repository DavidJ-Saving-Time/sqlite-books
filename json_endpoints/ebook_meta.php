<?php
require_once __DIR__ . '/../db.php';
requireLogin();

header('Content-Type: application/json');

function parse_ebook_meta(string $text): array {
    $result = [];

    // Remove ANSI escape codes (if any)
    $text = preg_replace('/\x1b\[[0-9;]*m/', '', $text);

    $currentKey = null;
    foreach (preg_split("/\r\n|\r|\n/", $text) as $line) {
        if (preg_match('/^\s*([^:]+?)\s*:\s*(.*)$/', $line, $matches)) {
            $k = strtolower(trim($matches[1]));
            $v = trim($matches[2]);

            if ($k === 'author(s)') $k = 'authors';
            if ($k === 'identifiers') $k = 'identifiers';
            if ($k === 'series index') $k = 'series_index';

            // Sometimes the series index is appended to the series name as "Name [1]"
            if ($k === 'series' && preg_match('/^(.*)\[(.+)\]\s*$/', $v, $m)) {
                $v = trim($m[1]);
                $result['series_index'] = is_numeric($m[2]) ? (float)$m[2] : $m[2];
            }

            $result[$k] = $v;
            $currentKey = $k;
        } elseif ($currentKey === 'comments') {
            // Multiline comments come after the initial "Comments:" line
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $result['comments'] = trim(($result['comments'] ?? '') . "\n" . $trimmed);
            }
        }
    }

    // Convert identifiers like "isbn:9781035909759" to ["isbn" => "9781035909759"]
    if (!empty($result['identifiers']) && is_string($result['identifiers'])) {
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

    if (isset($result['series_index']) && is_numeric($result['series_index'])) {
        $result['series_index'] = (float)$result['series_index'];
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

// Ensure we use English output and extract cover to a temp file
$coverTmp = sys_get_temp_dir() . '/' . uniqid('cover_', true) . '.jpg';
$cmd = 'LANG=C ebook-meta --get-cover=' . escapeshellarg($coverTmp) . ' ' . escapeshellarg($path) . ' 2>/dev/null';
$out = shell_exec($cmd);

if ($out === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to run ebook-meta']);
    exit;
}

$data = parse_ebook_meta($out);

// Include cover image if one was extracted
if (file_exists($coverTmp) && filesize($coverTmp) > 0) {
    $data['cover'] = base64_encode(file_get_contents($coverTmp));
    unlink($coverTmp);
} else {
    @unlink($coverTmp);
}

$data['status'] = 'ok';

echo json_encode($data);
