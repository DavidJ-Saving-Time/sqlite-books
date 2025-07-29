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

/**
 * Ensure the language exists and return its ID.
 */
function getLanguageId(PDO $pdo, string $code): int {
    $stmt = $pdo->prepare('SELECT id FROM languages WHERE lang_code = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $pdo->prepare('INSERT INTO languages (lang_code) VALUES (?)')->execute([$code]);
        $id = $pdo->lastInsertId();
    }
    return (int)$id;
}

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $authors_str = trim($_POST['authors'] ?? '');
    $tags_str = trim($_POST['tags'] ?? '');
    $file = $_FILES['file'] ?? null;

    if ($title === '' || $authors_str === '') {
        $errors[] = 'Title and authors are required.';
    }
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Valid book file is required.';
    }

    if (!$errors) {
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

            // Add authors
            foreach ($authors as $author) {
                $stmt = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?))');
                $stmt->execute([$author, $author]);
            }

            // Temporary book path (will be updated after we get the bookId)
            $tmpPath = safe_filename($title);

            // Add book (uuid4 handled by DB)
            $stmt = $pdo->prepare(
                'INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, uuid)
                 VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, ?, uuid4())'
            );
            $stmt->execute([$title, $title, $firstAuthor, $tmpPath]);
            $bookId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)')->execute([$bookId]);
            touchLastModified($pdo, $bookId);

            // Link authors
            foreach ($authors as $author) {
                $pdo->exec("INSERT OR IGNORE INTO books_authors_link (book, author)
                            SELECT $bookId, id FROM authors WHERE name=" . $pdo->quote($author));
            }
            touchLastModified($pdo, $bookId);

            // Add tags
            $tags = [];
            if ($tags_str !== '') {
                $tags = array_map('trim', preg_split('/,|;/', $tags_str));
                foreach ($tags as $tag) {
                    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES (" . $pdo->quote($tag) . ")");
                    $pdo->exec("INSERT INTO books_tags_link (book, tag)
                                SELECT $bookId, id FROM tags WHERE name=" . $pdo->quote($tag));
                }
            }
            touchLastModified($pdo, $bookId);

            // Build proper folder structure
            $authorFolderName = safe_filename($firstAuthor . (count($authors) > 1 ? ' et al.' : ''));
            $bookFolderName = safe_filename($title) . " ($bookId)";
            $bookPath = $authorFolderName . '/' . $bookFolderName; // relative path for DB
            $fullBookFolder = $libraryPath . '/' . $bookPath;

            if (!is_dir(dirname($fullBookFolder))) {
                mkdir(dirname($fullBookFolder), 0777, true);
            }
            if (!is_dir($fullBookFolder)) {
                mkdir($fullBookFolder, 0777, true);
            }

            // Update book path in database
            $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$bookPath, $bookId]);
            $pdo->prepare('UPDATE books SET timestamp=CURRENT_TIMESTAMP WHERE id=?')->execute([$bookId]);
            touchLastModified($pdo, $bookId);

            // Move uploaded file
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $baseFileName = safe_filename($title) . ' - ' . safe_filename($firstAuthor);
            $destFile = $fullBookFolder . '/' . $baseFileName . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $destFile);

            // Add entry to 'data' table (linking the book to its file format)
            $stmt = $pdo->prepare('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$bookId, strtoupper($ext), filesize($destFile), $baseFileName]);
            touchLastModified($pdo, $bookId);

            if ($publisher !== '') {
                $pdo->prepare('INSERT OR IGNORE INTO publishers(name) VALUES (?)')->execute([$publisher]);
                $pdo->prepare('DELETE FROM books_publishers_link WHERE book=?')->execute([$bookId]);
                $pdo->prepare('INSERT INTO books_publishers_link(book,publisher) SELECT ?, id FROM publishers WHERE name=?')->execute([$bookId, $publisher]);
                touchLastModified($pdo, $bookId);
            }

            $pdo->prepare('DELETE FROM books_languages_link WHERE book=?')->execute([$bookId]);
            foreach ($languages as $lang) {
                $langId = getLanguageId($pdo, $lang);
                $pdo->prepare('INSERT INTO books_languages_link(book,lang_code) VALUES(?, ?)')->execute([$bookId, $langId]);
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

            // Fetch the UUID from the database
            $uuid = $pdo->query("SELECT uuid FROM books WHERE id = $bookId")->fetchColumn();

            // Generate metadata.opf with UUID, tags, etc.
            $tagsXml = '';
            foreach ($tags as $tag) {
                $tagsXml .= "    <dc:subject>" . htmlspecialchars($tag) . "</dc:subject>\n";
            }

            $timestamp = date('Y-m-d\TH:i:s');
            $languageCode = $languages[0] ?? 'eng';
            $publisherXml = $publisher !== '' ? "    <dc:publisher>" . htmlspecialchars($publisher) . "</dc:publisher>\n" : '';
            $isbnXml = $identifier !== '' ? "    <dc:identifier opf:scheme=\"ISBN\">$identifier</dc:identifier>\n" : '';
            $opf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<package version=\"2.0\" xmlns=\"http://www.idpf.org/2007/opf\">\n  <metadata>\n" .
                   "    <dc:title>" . htmlspecialchars($title) . "</dc:title>\n" .
                   "    <dc:creator opf:role=\"aut\">" . htmlspecialchars($firstAuthor) . "</dc:creator>\n" .
                   $publisherXml .
                   $tagsXml .
                   "    <dc:language>" . htmlspecialchars($languageCode) . "</dc:language>\n" .
                   "    <dc:identifier opf:scheme=\"uuid\">$uuid</dc:identifier>\n" .
                   $isbnXml .
                   "    <meta name=\"calibre:timestamp\" content=\"$timestamp+00:00\"/>\n" .
                   "  </metadata>\n</package>";
            file_put_contents($fullBookFolder . '/metadata.opf', $opf);

            $pdo->commit();
            $message = 'Book added successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Book</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <style>
        .file-upload-highlight {
            border: 2px dashed #6c757d;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .file-upload-highlight:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container my-4">
    <h1 class="mb-4 text-center">Add a New Book</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php elseif ($errors): ?>
        <div class="alert alert-danger"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm p-4">
        <form method="post" enctype="multipart/form-data">
            <!-- File Upload First -->
            <div class="mb-4">
                <label for="file" class="form-label fw-bold">Upload Book File</label>
                <div class="file-upload-highlight" onclick="document.getElementById('file').click();">
                    <p class="mb-1"><strong>Click to upload a book file</strong> or drag & drop here</p>
                    <small class="text-muted">(Supported formats: PDF, EPUB, etc.)</small>
                </div>
                <input type="file" name="file" id="file" class="form-control mt-2" style="display:none;" required>
            </div>

            <!-- Progress bar (hidden by default) -->
            <div class="mb-3" id="metadataProgress" style="display:none;">
                <label class="form-label">Fetching Metadata...</label>
                <div class="progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                         role="progressbar" style="width: 100%"></div>
                </div>
            </div>

            <!-- Book Details (hidden until file upload) -->
            <div id="bookDetails" style="display:none;">
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" name="title" id="title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="authors" class="form-label">Author(s)</label>
                    <input type="text" name="authors" id="authors" class="form-control" placeholder="Separate multiple authors with commas" required>
                </div>
                <div class="mb-3">
                    <label for="tags" class="form-label">Tags</label>
                    <input type="text" name="tags" id="tags" class="form-control" placeholder="Optional, comma separated">
                </div>
                <button type="submit" class="btn btn-primary">Add Book</button>
                <a href="list_books.php" class="btn btn-secondary ms-2">Back</a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const fileInput = document.getElementById('file');
const titleInput = document.getElementById('title');
const authorsInput = document.getElementById('authors');
const bookDetails = document.getElementById('bookDetails');
const metadataProgress = document.getElementById('metadataProgress');

fileInput.addEventListener('change', () => {
    if (!fileInput.files.length) return;

    // Show progress bar
    metadataProgress.style.display = 'block';

    const fd = new FormData();
    fd.append('file', fileInput.files[0]);
    fetch('ebook_meta.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.title && !titleInput.value) titleInput.value = data.title;
            if (data.authors && !authorsInput.value) {
                authorsInput.value = Array.isArray(data.authors)
                    ? data.authors.join(', ')
                    : String(data.authors).replace(/ and /g, ', ');
            }
        })
        .catch(() => {})
        .finally(() => {
            // Hide progress and show book details
            metadataProgress.style.display = 'none';
            bookDetails.style.display = 'block';
        });
});
</script>
</body>
</html>

