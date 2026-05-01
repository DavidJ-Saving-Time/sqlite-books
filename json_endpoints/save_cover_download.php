<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

$user     = currentUser();
$cacheDir = __DIR__ . '/../cache/' . $user;
$tmpFile  = $cacheDir . '/cover_dl_' . $bookId . '.jpg';

if (!file_exists($tmpFile)) {
    echo json_encode(['error' => 'No downloaded cover found — fetch it first']);
    exit;
}

$pdo = getDatabaseConnection();

$row = $pdo->prepare('SELECT path FROM books WHERE id = ?');
$row->execute([$bookId]);
$bookPath = $row->fetchColumn();

if (!$bookPath) {
    echo json_encode(['error' => 'Book not found']);
    exit;
}

$libraryPath = getLibraryPath();
$coverFile   = rtrim($libraryPath, '/') . '/' . $bookPath . '/cover.jpg';
$coverDir    = dirname($coverFile);

if (!is_dir($coverDir) && !mkdir($coverDir, 0775, true)) {
    echo json_encode(['error' => 'Cannot create cover directory']);
    exit;
}

if (!copy($tmpFile, $coverFile)) {
    echo json_encode(['error' => 'Failed to save cover file']);
    exit;
}

@unlink($tmpFile);

$pdo->prepare('UPDATE books SET has_cover = 1, last_modified = CURRENT_TIMESTAMP WHERE id = ?')
    ->execute([$bookId]);

$coverUrl = getLibraryWebPath() . '/' . $bookPath . '/cover.jpg?t=' . time();

echo json_encode(['ok' => true, 'cover_url' => $coverUrl]);
