<?php
/**
 * Checks whether a book with the given title already exists in the library.
 * GET ?title=...&author=...  (author is optional, used to filter results)
 * Returns {"duplicates":[{"id":N,"title":"...","authors":"..."},...]}
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$title  = trim($_GET['title']  ?? '');
$author = trim($_GET['author'] ?? '');

if ($title === '') {
    echo json_encode(['duplicates' => []]);
    exit;
}

$pdo = getDatabaseConnection();

$stmt = $pdo->prepare("
    SELECT b.id, b.title, GROUP_CONCAT(a.name, ', ') AS authors
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    WHERE LOWER(b.title) = LOWER(:title)
    GROUP BY b.id
    LIMIT 10
");
$stmt->execute([':title' => $title]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($author !== '') {
    $needle = strtolower($author);
    $rows = array_values(array_filter($rows, fn($r) =>
        str_contains(strtolower($r['authors'] ?? ''), $needle)
    ));
}

echo json_encode(['duplicates' => $rows]);
