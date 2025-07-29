<?php
require_once 'db.php';
requireLogin();

function touchLastModified(PDO $pdo, int $bookId): void {
    $pdo->prepare('UPDATE books SET last_modified=CURRENT_TIMESTAMP WHERE id=?')->execute([$bookId]);
}

function fetchAdditionalMetadata(string $title, string $author): array {
    $meta = [];
    $url = 'https://openlibrary.org/search.json?title=' . urlencode($title) .
           '&author=' . urlencode($author) .
           '&limit=1&fields=edition_key,publisher,language,isbn';
    $resp = @file_get_contents($url);
    if ($resp !== false) {
        $data = json_decode($resp, true);
        if (isset($data['docs'][0])) {
            $d = $data['docs'][0];
            if (!empty($d['publisher'][0])) {
                $meta['publisher'] = $d['publisher'][0];
            }
            if (!empty($d['language'][0])) {
                $meta['languages'] = (array)$d['language'];
            }
            if (!empty($d['isbn'][0])) {
                $meta['isbn'] = $d['isbn'][0];
            }
            if (!empty($d['edition_key'][0])) {
                $edition = $d['edition_key'][0];
                $eResp = @file_get_contents('https://openlibrary.org/books/' . $edition . '.json');
                if ($eResp !== false) {
                    $ed = json_decode($eResp, true);
                    if (empty($meta['publisher']) && !empty($ed['publishers'][0])) {
                        $meta['publisher'] = $ed['publishers'][0];
                    }
                    if (empty($meta['languages']) && !empty($ed['languages'])) {
                        $meta['languages'] = [];
                        foreach ($ed['languages'] as $l) {
                            if (!empty($l['key'])) {
                                $meta['languages'][] = basename($l['key']);
                            }
                        }
                    }
                    if (empty($meta['isbn'])) {
                        $ids = $ed['identifiers'] ?? [];
                        if (!empty($ids['isbn_13'][0])) {
                            $meta['isbn'] = $ids['isbn_13'][0];
                        } elseif (!empty($ids['isbn_10'][0])) {
                            $meta['isbn'] = $ids['isbn_10'][0];
                        }
                    }
                    if (!empty($ed['publish_date'])) {
                        $meta['pubdate'] = $ed['publish_date'];
                    }
                }
            }
        }
    }
    return $meta;
}

function safe_filename(string $name, int $max_length = 150): string {
    $name = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$title = trim($_POST['title'] ?? '');
$authors_str = trim($_POST['authors'] ?? '');
$tags_str = trim($_POST['tags'] ?? '');
$thumbnail = trim($_POST['thumbnail'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($title === '' || $authors_str === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Title and authors are required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $authors = array_unique(array_filter(
        array_map('trim', preg_split('/,|;/', $authors_str)),
        'strlen'
    ));
    $firstAuthor = $authors[0];

    $extra = fetchAdditionalMetadata($title, $firstAuthor);
    $publisher  = $extra['publisher'] ?? '';
    $languages  = $extra['languages'] ?? ['eng'];
    $identifier = $extra['isbn'] ?? '';
    $pubdate    = $extra['pubdate'] ?? null;

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

    $pdo->prepare('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)')->execute([$bookId]);
    touchLastModified($pdo, $bookId);

    foreach ($authors as $author) {
        $pdo->exec("INSERT OR IGNORE INTO books_authors_link (book, author) SELECT $bookId, id FROM authors WHERE name=" . $pdo->quote($author));
    }
    touchLastModified($pdo, $bookId);

    $tags = [];
    if ($tags_str !== '') {
        $tags = array_map('trim', preg_split('/,|;/', $tags_str));
        foreach ($tags as $tag) {
            $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES (" . $pdo->quote($tag) . ")");
            $pdo->exec("INSERT INTO books_tags_link (book, tag) SELECT $bookId, id FROM tags WHERE name=" . $pdo->quote($tag));
        }
    }
    touchLastModified($pdo, $bookId);

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
    $pdo->prepare('UPDATE books SET timestamp=CURRENT_TIMESTAMP WHERE id=?')->execute([$bookId]);
    touchLastModified($pdo, $bookId);

    if ($description !== '') {
        $stmt = $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text)');
        $stmt->execute([':book' => $bookId, ':text' => $description]);
        touchLastModified($pdo, $bookId);
    }

    if ($thumbnail !== '') {
        $data = @file_get_contents($thumbnail);
        if ($data !== false) {
            file_put_contents($fullBookFolder . '/cover.jpg', $data);
            $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$bookId]);
            touchLastModified($pdo, $bookId);
        }
    }

    if ($publisher !== '') {
        $pdo->prepare('INSERT OR IGNORE INTO publishers(name) VALUES (?)')->execute([$publisher]);
        $pdo->prepare('DELETE FROM books_publishers_link WHERE book=?')->execute([$bookId]);
        $pdo->prepare('INSERT INTO books_publishers_link(book,publisher) SELECT ?, id FROM publishers WHERE name=?')->execute([$bookId, $publisher]);
        touchLastModified($pdo, $bookId);
    }

    $pdo->prepare('DELETE FROM books_languages_link WHERE book=?')->execute([$bookId]);
    foreach ($languages as $lang) {
        $pdo->prepare('INSERT INTO books_languages_link(book,lang_code) VALUES(?, ?)')->execute([$bookId, $lang]);
    }
    touchLastModified($pdo, $bookId);

    if ($pubdate !== null) {
        $pdo->prepare('UPDATE books SET pubdate=? WHERE id=?')->execute([$pubdate, $bookId]);
        touchLastModified($pdo, $bookId);
    }

    if ($identifier !== '') {
        $pdo->prepare('DELETE FROM identifiers WHERE book=?')->execute([$bookId]);
        $pdo->prepare('INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, ?, ?)')->execute([$bookId, 'isbn', $identifier]);
        touchLastModified($pdo, $bookId);
    }

    $uuid = $pdo->query("SELECT uuid FROM books WHERE id = $bookId")->fetchColumn();

    $tagsXml = '';
    foreach ($tags as $tag) {
        $tagsXml .= "    <dc:subject>" . htmlspecialchars($tag) . "</dc:subject>\n";
    }

    $timestamp = date('Y-m-d\TH:i:s');
    $descriptionXml = $description !== ''
        ? "    <dc:description>" . htmlspecialchars($description) . "</dc:description>\n"
        : '';
    $languageCode = $languages[0] ?? 'eng';
    $publisherXml = $publisher !== '' ? "    <dc:publisher>" . htmlspecialchars($publisher) . "</dc:publisher>\n" : '';
    $isbnXml = $identifier !== '' ? "    <dc:identifier opf:scheme=\"ISBN\">$identifier</dc:identifier>\n" : '';
    $opf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<package version=\"2.0\" xmlns=\"http://www.idpf.org/2007/opf\">\n  <metadata>\n" .
           "    <dc:title>" . htmlspecialchars($title) . "</dc:title>\n" .
           "    <dc:creator opf:role=\"aut\">" . htmlspecialchars($firstAuthor) . "</dc:creator>\n" .
           $publisherXml .
           $tagsXml .
           $descriptionXml .
           "    <dc:language>" . htmlspecialchars($languageCode) . "</dc:language>\n" .
           "    <dc:identifier opf:scheme=\"uuid\">$uuid</dc:identifier>\n" .
           $isbnXml .
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
