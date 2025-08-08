<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();
require_once __DIR__ . '/../annas_archive.php';

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$title = trim($_POST['title'] ?? '');
$authors = trim($_POST['authors'] ?? '');
$year = trim($_POST['year'] ?? '');
$imgUrl = trim($_POST['imgurl'] ?? '');
$coverData = trim($_POST['coverdata'] ?? '');
$descriptionPost = trim($_POST['description'] ?? '');
$md5 = trim($_POST['md5'] ?? '');
$bookPath = null; // track path for returning updated cover url

if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();

    $pathStmt = $pdo->prepare('SELECT path FROM books WHERE id = :id');
    $pathStmt->execute([':id' => $bookId]);
    $bookPath = $pathStmt->fetchColumn();
    if ($bookPath === false) {
        $bookPath = null;
    }

    if ($coverData !== '') {
        if ($bookPath !== null) {
            $libraryPath = getLibraryPath();
            $data = base64_decode($coverData);
            if ($data !== false) {
                $coverFile = $libraryPath . '/' . $bookPath . '/cover.jpg';
                $dir = dirname($coverFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($coverFile, $data);
                $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = :id')->execute([':id' => $bookId]);
            }
        }
    } elseif ($imgUrl !== '') {
        if ($bookPath !== null) {
            $libraryPath = getLibraryPath();
            $data = @file_get_contents($imgUrl);
            if ($data !== false) {
                $coverFile = $libraryPath . '/' . $bookPath . '/cover.jpg';
                $dir = dirname($coverFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($coverFile, $data);
                $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = :id')->execute([':id' => $bookId]);
            }
        }
    }

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
        $insertAuthor = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (:name, author_sort(:name))');
        foreach ($authorsList as $a) {
            $insertAuthor->execute([':name' => $a]);
        }
        $pdo->prepare('DELETE FROM books_authors_link WHERE book = :book')->execute([':book' => $bookId]);
        foreach ($authorsList as $a) {
            $aid = $pdo->query('SELECT id FROM authors WHERE name=' . $pdo->quote($a))->fetchColumn();
            if ($aid !== false) {
                $linkStmt = $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (:book, :author)');
                $linkStmt->execute([':book' => $bookId, ':author' => $aid]);
            }
        }
        $pdo->prepare('UPDATE books SET author_sort = author_sort(:sort) WHERE id = :id')->execute([':sort' => $primaryAuthor, ':id' => $bookId]);
    }

    if ($descriptionPost !== '') {
        $stmt = $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text) '
            . 'ON CONFLICT(book) DO UPDATE SET text=excluded.text');
        $stmt->execute([':book' => $bookId, ':text' => $descriptionPost]);
    }

    if ($md5 !== '') {
        $info = annas_archive_info($md5);
        $description = '';
        if (isset($info['description']) && is_string($info['description'])) {
            $description = $info['description'];
        } elseif (isset($info['descr']) && is_string($info['descr'])) {
            $description = $info['descr'];
        } elseif (isset($info['comment']) && is_string($info['comment'])) {
            $description = $info['comment'];
        } elseif (isset($info['descriptions']['description'])) {
            $desc = $info['descriptions']['description'];
            if (is_array($desc)) {
                $description = (string) reset($desc);
            } elseif (is_string($desc)) {
                $description = $desc;
            }
        }
        if ($description !== '') {
            $stmt = $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text) '
                . 'ON CONFLICT(book) DO UPDATE SET text=excluded.text');
            $stmt->execute([':book' => $bookId, ':text' => $description]);
        }
    }

    $pdo->commit();

    // Prepare data for AJAX refresh
    $coverUrl = '';
    if ($bookPath !== null) {
        $coverUrl = getLibraryPath() . '/' . $bookPath . '/cover.jpg';
    }

    $authorsHtml = '';
    $authorRows = $pdo->prepare('SELECT a.id, a.name FROM authors a JOIN books_authors_link l ON a.id = l.author WHERE l.book = :book ORDER BY l.id');
    $authorRows->execute([':book' => $bookId]);
    $authorLinks = [];
    foreach ($authorRows as $row) {
        $url = 'list_books.php?author_id=' . urlencode((string)$row['id']);
        $authorLinks[] = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($row['name']) . '</a>';
    }
    if ($authorLinks) {
        $authorsHtml = implode(', ', $authorLinks);
    }

    echo json_encode([
        'status' => 'ok',
        'cover_url' => $coverUrl,
        'authors_html' => $authorsHtml,
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
