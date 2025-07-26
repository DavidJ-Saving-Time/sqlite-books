<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$title = trim($_POST['title'] ?? '');
$authors = trim($_POST['authors'] ?? '');
if ($title === '' || $authors === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();

    $primaryAuthor = trim(explode(',', $authors)[0]);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (:name, :sort)');
    $stmt->execute([':name' => $primaryAuthor, ':sort' => $primaryAuthor]);

    $stmt = $pdo->prepare('SELECT id FROM authors WHERE name = :name');
    $stmt->execute([':name' => $primaryAuthor]);
    $authorId = (int)$stmt->fetchColumn();

    $path = preg_replace('/[^A-Za-z0-9]+/', '_', $title);
    $insertBook = $pdo->prepare('INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path)
                                 VALUES (:title, :sort, :author_sort, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, :path)');
    $insertBook->execute([
        ':title' => $title,
        ':sort' => $title,
        ':author_sort' => $primaryAuthor,
        ':path' => $path
    ]);
    $bookId = (int)$pdo->lastInsertId();

    $balStmt = $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (:book, :author)');
    $balStmt->execute([':book' => $bookId, ':author' => $authorId]);

    $tableStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'books_custom_column_%'");
    $tables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^books_custom_column_(\d+)(?:_link)?$/', $table, $m)) {
            continue;
        }
        $colId = (int)$m[1];
        $isLink = str_ends_with($table, '_link');

        if ($isLink) {
            $infoStmt = $pdo->prepare('SELECT label, is_multiple FROM custom_columns WHERE id = :id');
            $infoStmt->execute([':id' => $colId]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
            if (!$info || (int)$info['is_multiple'] === 1) {
                continue;
            }
            $valTable = 'custom_column_' . $colId;
            $defaultId = null;
            if ($info['label'] === 'status') {
                $pdo->prepare("INSERT OR IGNORE INTO $valTable (value) VALUES ('Want to Read')")->execute();
                $defaultId = $pdo->query("SELECT id FROM $valTable WHERE value = 'Want to Read'")->fetchColumn();
            } else {
                $defaultId = $pdo->query("SELECT id FROM $valTable ORDER BY id LIMIT 1")->fetchColumn();
            }
            if ($defaultId !== false && $defaultId !== null) {
                $pdo->prepare("INSERT INTO $table (book, value) VALUES (:book, :val)")->execute([':book' => $bookId, ':val' => $defaultId]);
            }
        } else {
            $value = null;
            if ($colId === 11) {
                $value = 'Physical';
            }
            $pdo->prepare("INSERT INTO $table (book, value) VALUES (:book, :value)")->execute([':book' => $bookId, ':value' => $value]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'ok', 'book_id' => $bookId]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

