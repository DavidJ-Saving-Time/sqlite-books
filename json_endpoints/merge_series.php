<?php
/**
 * Merge one or more series records into a single "keep" series.
 * Moves books_series_link entries and deletes the now-empty series rows.
 * No filesystem changes are required for series.
 *
 * POST: keep_id=<int>  merge_ids[]=<int> ...
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$keepId   = isset($_POST['keep_id']) ? (int)$_POST['keep_id'] : 0;
$mergeIds = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['merge_ids'] ?? [])),
    fn($id) => $id > 0 && $id !== $keepId
)));

if ($keepId <= 0 || empty($mergeIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDatabaseConnection();

$keepRow = $pdo->prepare('SELECT id, name FROM series WHERE id = ?');
$keepRow->execute([$keepId]);
if (!$keepRow->fetch(PDO::FETCH_ASSOC)) {
    http_response_code(404);
    echo json_encode(['error' => 'Keep series not found']);
    exit;
}

$in = implode(',', array_fill(0, count($mergeIds), '?'));
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM series WHERE id IN ($in)");
$checkStmt->execute($mergeIds);
if ((int)$checkStmt->fetchColumn() !== count($mergeIds)) {
    http_response_code(404);
    echo json_encode(['error' => 'One or more merge series not found']);
    exit;
}

$booksMoved = 0;

try {
    $pdo->beginTransaction();

    foreach ($mergeIds as $mergeId) {
        // Get all books in this series
        $booksStmt = $pdo->prepare(
            'SELECT book FROM books_series_link WHERE series = ?'
        );
        $booksStmt->execute([$mergeId]);
        $books = $booksStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($books as $bookId) {
            $bookId = (int)$bookId;
            // If the book is already in the keep series, drop the duplicate link
            $exists = $pdo->prepare(
                'SELECT 1 FROM books_series_link WHERE book = ? AND series = ?'
            );
            $exists->execute([$bookId, $keepId]);
            if ($exists->fetchColumn()) {
                $pdo->prepare(
                    'DELETE FROM books_series_link WHERE book = ? AND series = ?'
                )->execute([$bookId, $mergeId]);
            } else {
                $pdo->prepare(
                    'UPDATE books_series_link SET series = ? WHERE book = ? AND series = ?'
                )->execute([$keepId, $bookId, $mergeId]);
                $booksMoved++;
            }
        }

        // Delete the now-empty series record
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM books_series_link WHERE series = ?');
        $remaining->execute([$mergeId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM series WHERE id = ?')->execute([$mergeId]);
        }
    }

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'books_moved' => $booksMoved]);
