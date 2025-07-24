<?php
header('Content-Type: application/json');
require_once 'db.php';

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$title = trim($_POST['title'] ?? '');
$authors = trim($_POST['authors'] ?? '');
$year = trim($_POST['year'] ?? '');

if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();

    if ($title !== '') {
        $stmt = $pdo->prepare('UPDATE books SET title = :title, sort = :sort, last_modified = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':title' => $title, ':sort' => $title, ':id' => $bookId]);
    }

    if ($year !== '') {
        $date = preg_match('/^\d{4}$/', $year) ? $year . '-01-01' : $year;
        $stmt = $pdo->prepare('UPDATE books SET pubdate = :pubdate WHERE id = :id');
        $stmt->execute([':pubdate' => $date, ':id' => $bookId]);
    }

    if ($authors !== '') {
        $authorsList = preg_split('/\s*(?:,|;| and )\s*/i', $authors);
        $authorsList = array_filter(array_map('trim', $authorsList), 'strlen');
        if (empty($authorsList)) {
            $authorsList = [$authors];
        }
        $primaryAuthor = $authorsList[0];
        $insertAuthor = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (:name, :sort)');
        foreach ($authorsList as $a) {
            $insertAuthor->execute([':name' => $a, ':sort' => $a]);
        }
        $pdo->prepare('DELETE FROM books_authors_link WHERE book = :book')->execute([':book' => $bookId]);
        foreach ($authorsList as $a) {
            $aid = $pdo->query('SELECT id FROM authors WHERE name=' . $pdo->quote($a))->fetchColumn();
            if ($aid !== false) {
                $linkStmt = $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (:book, :author)');
                $linkStmt->execute([':book' => $bookId, ':author' => $aid]);
            }
        }
        $pdo->prepare('UPDATE books SET author_sort = :sort WHERE id = :id')->execute([':sort' => $primaryAuthor, ':id' => $bookId]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
