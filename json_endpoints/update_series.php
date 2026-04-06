<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId     = (int)($_POST['book_id'] ?? 0);
$seriesId   = isset($_POST['series_id'])    ? (int)$_POST['series_id']            : null;
$seriesName = isset($_POST['series_name'])  ? trim((string)$_POST['series_name'])  : null;
$seriesIndex= isset($_POST['series_index']) ? trim((string)$_POST['series_index']) : null;

if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book_id']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    // If a series name was sent, resolve or create the series
    if ($seriesName !== null) {
        if ($seriesName === '') {
            // Clear the series link
            $pdo->prepare('DELETE FROM books_series_link WHERE book = ?')->execute([$bookId]);
            $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookId]);
            echo json_encode(['status' => 'ok', 'series_id' => null]);
            exit;
        }
        // Look for an existing series (case-insensitive)
        $stmt = $pdo->prepare('SELECT id FROM series WHERE name = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([$seriesName]);
        $resolvedId = $stmt->fetchColumn();
        if (!$resolvedId) {
            // Create a new series
            $sort = preg_replace('/^(A |An |The )/i', '', $seriesName);
            $pdo->prepare('INSERT INTO series (name, sort) VALUES (?, ?)')->execute([$seriesName, $sort]);
            $resolvedId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM books_series_link WHERE book = ?')->execute([$bookId]);
        $pdo->prepare('INSERT INTO books_series_link (book, series) VALUES (?, ?)')->execute([$bookId, $resolvedId]);
        $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookId]);
        echo json_encode(['status' => 'ok', 'series_id' => (int)$resolvedId, 'series_name' => $seriesName]);
        exit;
    }

    if ($seriesId !== null) {
        // Remove existing series link
        $pdo->prepare('DELETE FROM books_series_link WHERE book = ?')->execute([$bookId]);
        if ($seriesId > 0) {
            // Verify the series exists
            $exists = $pdo->prepare('SELECT id FROM series WHERE id = ?');
            $exists->execute([$seriesId]);
            if (!$exists->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'Series not found']);
                exit;
            }
            $pdo->prepare('INSERT INTO books_series_link (book, series) VALUES (?, ?)')->execute([$bookId, $seriesId]);
        }
        $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookId]);
    }

    if ($seriesIndex !== null) {
        $idx = $seriesIndex !== '' ? (float)$seriesIndex : 1.0;
        $pdo->prepare('UPDATE books SET series_index = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$idx, $bookId]);
    }

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
