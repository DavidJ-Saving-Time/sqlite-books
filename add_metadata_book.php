<?php
require_once 'db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    $name = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$title = trim($_POST['title'] ?? '');
$authors_str = trim($_POST['authors'] ?? '');
$tags_str = trim($_POST['tags'] ?? '');

if ($title === '' || $authors_str === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Title and authors are required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $authors = array_map('trim', preg_split('/,|;/', $authors_str));
    $firstAuthor = $authors[0];

    foreach ($authors as $author) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?))');
        $stmt->execute([$author, $author]);
    }

    $tmpPath = safe_filename($title);
    $stmt = $pdo->prepare(
        'INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, uuid)
         VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, ?, uuid4())'
    );
    $stmt->execute([$title, $title, $firstAuthor, $tmpPath]);
    $bookId = (int)$pdo->lastInsertId();

    foreach ($authors as $author) {
        $pdo->exec("INSERT INTO books_authors_link (book, author) SELECT $bookId, id FROM authors WHERE name=" . $pdo->quote($author));
    }

    $tags = [];
    if ($tags_str !== '') {
        $tags = array_map('trim', preg_split('/,|;/', $tags_str));
        foreach ($tags as $tag) {
            $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES (" . $pdo->quote($tag) . ")");
            $pdo->exec("INSERT INTO books_tags_link (book, tag) SELECT $bookId, id FROM tags WHERE name=" . $pdo->quote($tag));
        }
    }

    $authorFolderName = safe_filename($firstAuthor . (count($authors) > 1 ? ' et al.' : ''));
    $bookFolderName = safe_filename($title) . " ($bookId)";
    $bookPath = $authorFolderName . '/' . $bookFolderName;
    $fullBookFolder = $libraryPath . '/' . $bookPath;

    if (!is_dir(dirname($fullBookFolder))) {
        mkdir(dirname($fullBookFolder), 0777, true);
    }
    if (!is_dir($fullBookFolder)) {
        mkdir($fullBookFolder, 0777, true);
    }

    $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$bookPath, $bookId]);

    $uuid = $pdo->query("SELECT uuid FROM books WHERE id = $bookId")->fetchColumn();

    $tagsXml = '';
    foreach ($tags as $tag) {
        $tagsXml .= "    <dc:subject>" . htmlspecialchars($tag) . "</dc:subject>\n";
    }

    $timestamp = date('Y-m-d\TH:i:s');
    $opf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<package version=\"2.0\" xmlns=\"http://www.idpf.org/2007/opf\">\n  <metadata>\n" .
           "    <dc:title>" . htmlspecialchars($title) . "</dc:title>\n" .
           "    <dc:creator opf:role=\"aut\">" . htmlspecialchars($firstAuthor) . "</dc:creator>\n" .
           $tagsXml .
           "    <dc:language>eng</dc:language>\n" .
           "    <dc:identifier opf:scheme=\"uuid\">$uuid</dc:identifier>\n" .
           "    <meta name=\"calibre:timestamp\" content=\"$timestamp+00:00\"/>\n" .
           "  </metadata>\n</package>";
    file_put_contents($fullBookFolder . '/metadata.opf', $opf);

    $pdo->commit();

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'book_id' => $bookId]);
} catch (Exception $e) {
    $pdo->rollBack();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
