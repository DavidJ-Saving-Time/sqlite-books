<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

$source = $_POST['source'] ?? '';
if (!in_array($source, ['amazon', 'kindle', 'goodreads'], true)) {
    $source = '';
}

$dbPath = currentDatabasePath();
if (!file_exists($dbPath)) {
    echo json_encode(['error' => 'Database not found']);
    exit;
}

$user     = currentUser();
$cacheDir = __DIR__ . '/../cache/' . $user;
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

$outFile = $cacheDir . '/cover_dl_' . $bookId . '.jpg';
$script  = __DIR__ . '/../scripts/download_cover.py';

$sourceArg = $source ? ' --source ' . escapeshellarg($source) : '';
$cmd = sprintf(
    'python3 %s --book-id %d --db-path %s --out-file %s%s 2>/dev/null',
    escapeshellarg($script),
    $bookId,
    escapeshellarg($dbPath),
    escapeshellarg($outFile),
    $sourceArg
);

$output = shell_exec($cmd);
if ($output === null) {
    echo json_encode(['error' => 'Script execution failed']);
    exit;
}

$result = json_decode(trim($output), true);
if (!is_array($result)) {
    echo json_encode(['error' => 'Invalid script output']);
    exit;
}
if (!empty($result['error'])) {
    echo json_encode(['error' => $result['error']]);
    exit;
}

// Convert filesystem path to web URL
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
$webPath = '/' . ltrim(str_replace($docRoot, '', $outFile), '/');

echo json_encode([
    'ok'          => true,
    'source'      => $result['source']  ?? 'unknown',
    'preview_url' => $webPath,
    'width'       => (int)($result['width']   ?? 0),
    'height'      => (int)($result['height']  ?? 0),
    'size_kb'     => (int)($result['size_kb'] ?? 0),
]);
