<?php
/**
 * Save a GR cover as the book's cover.jpg.
 *
 * POST book_id
 * POST source   — 'local' (gr_covers/{gr_id}.jpg) or 'cdn' (cached download)
 *
 * Returns {"ok": true, "cover_url": "..."} or {"error": "..."}
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = (int)($_POST['book_id'] ?? 0);
$source = $_POST['source'] ?? '';
if ($bookId <= 0 || !in_array($source, ['local', 'cdn'], true)) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo     = getDatabaseConnection();
$user    = currentUser();
$appRoot = __DIR__ . '/..';

if ($source === 'local') {
    $grRow = $pdo->prepare(
        "SELECT val FROM identifiers WHERE book = ? AND type = 'goodreads' LIMIT 1"
    );
    $grRow->execute([$bookId]);
    $grId = (int)$grRow->fetchColumn();
    if (!$grId) {
        echo json_encode(['error' => 'No Goodreads ID found for this book']);
        exit;
    }
    $srcFile = $appRoot . '/gr_covers/' . $grId . '.jpg';
    if (!file_exists($srcFile)) {
        echo json_encode(['error' => 'Local gr_covers file not found for GR ID ' . $grId]);
        exit;
    }
} else {
    $srcFile = $appRoot . '/cache/' . $user . '/cover_dl_gr_cdn_' . $bookId . '.jpg';
    if (!file_exists($srcFile)) {
        echo json_encode(['error' => 'CDN cover not found — re-open the GR cover preview']);
        exit;
    }
}

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

if (!copy($srcFile, $coverFile)) {
    echo json_encode(['error' => 'Failed to save cover file']);
    exit;
}

$pdo->prepare('UPDATE books SET has_cover = 1, last_modified = CURRENT_TIMESTAMP WHERE id = ?')
    ->execute([$bookId]);

$coverUrl = getLibraryWebPath() . '/' . $bookPath . '/cover.jpg?t=' . time();
echo json_encode(['ok' => true, 'cover_url' => $coverUrl]);
