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

/**
 * Process a single book upload and return result information.
 */
function processBook(PDO $pdo, string $libraryPath, string $title, string $authors_str, string $tags_str, string $series_str, string $series_index_str, string $description_str, array $file): array {
    $errors = [];
    $message = '';
    $bookLink = '';

    if ($title === '' || $authors_str === '') {
        $errors[] = 'Title and authors are required.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
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

            $series = trim($series_str);
            $seriesIndex = $series_index_str !== '' ? (float)$series_index_str : 1.0;

            $extra = fetchAdditionalMetadata($title, $firstAuthor);
            $publisher  = $extra['publisher'] ?? '';
            $languages  = $extra['languages'] ?? ['eng'];
            $identifier = $extra['isbn'] ?? '';
            $pubdate    = $extra['pubdate'] ?? null;
            $description = trim($description_str);

            foreach ($authors as $author) {
                $stmt = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?))');
                $stmt->execute([$author, $author]);
            }


            $tmpPath = safe_filename($title);

            $stmt = $pdo->prepare(
                'INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, uuid)'
                . ' VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, ?, uuid4())'
            );
            $stmt->execute([$title, $title, $firstAuthor, $seriesIndex, $tmpPath]);
            $bookId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)')->execute([$bookId]);
            touchLastModified($pdo, $bookId);

            foreach ($authors as $author) {
                $pdo->exec("INSERT OR IGNORE INTO books_authors_link (book, author)" .
                            " SELECT $bookId, id FROM authors WHERE name=" . $pdo->quote($author));
            }
            touchLastModified($pdo, $bookId);

            $tags = [];
            if ($tags_str !== '') {
                $tags = array_map('trim', preg_split('/,|;/', $tags_str));
                foreach ($tags as $tag) {
                    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES (" . $pdo->quote($tag) . ")");
                    $pdo->exec("INSERT INTO books_tags_link (book, tag)" .
                                " SELECT $bookId, id FROM tags WHERE name=" . $pdo->quote($tag));
                }
            }
            if ($description !== '') {
                $pdo->prepare('INSERT INTO comments (book, text) VALUES (?, ?) ON CONFLICT(book) DO UPDATE SET text=excluded.text')
                    ->execute([$bookId, $description]);
            }
            touchLastModified($pdo, $bookId);
            if ($series !== '') {
                $pdo->prepare('INSERT OR IGNORE INTO series (name, sort) VALUES (?, ?)')->execute([$series, $series]);
                $pdo->prepare('DELETE FROM books_series_link WHERE book=?')->execute([$bookId]);
                $pdo->prepare('INSERT INTO books_series_link(book,series) SELECT ?, id FROM series WHERE name=?')
                    ->execute([$bookId, $series]);
                touchLastModified($pdo, $bookId);
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
            $pdo->prepare('UPDATE books SET timestamp=CURRENT_TIMESTAMP WHERE id=?')->execute([$bookId]);
            touchLastModified($pdo, $bookId);

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $baseFileName = safe_filename($title) . ' - ' . safe_filename($firstAuthor);
            $uploadedTmp = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $uploadedTmp);

            $finalFile = $uploadedTmp;
            $finalExt  = $ext;

            if (in_array($ext, ['zip', 'rar'])) {
                $tmpDir = sys_get_temp_dir() . '/' . uniqid('extract_', true);
                mkdir($tmpDir);

                $supported = ['epub','mobi','azw3','pdf','txt'];
                $found = '';

                if ($ext === 'zip') {
                    $zip = new ZipArchive();
                    if ($zip->open($uploadedTmp) === true) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = $zip->getNameIndex($i);
                            $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            if (in_array($e, $supported)) {
                                $zip->extractTo($tmpDir, $name);
                                $found = $tmpDir . '/' . $name;
                                $finalExt = $e;
                                break;
                            }
                        }
                        $zip->close();
                    }
                } else {
                    if (class_exists('RarArchive')) {
                        $rar = RarArchive::open($uploadedTmp);
                        if ($rar) {
                            foreach ($rar->getEntries() as $entry) {
                                $name = $entry->getName();
                                $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                                if (in_array($e, $supported)) {
                                    $entry->extract($tmpDir);
                                    $found = $tmpDir . '/' . $name;
                                    $finalExt = $e;
                                    break;
                                }
                            }
                            $rar->close();
                        }
                    } elseif (trim(shell_exec('command -v unrar'))) {
                        $list = shell_exec('unrar lb ' . escapeshellarg($uploadedTmp));
                        foreach (preg_split('/\r?\n/', $list) as $name) {
                            $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            if ($name !== '' && in_array($e, $supported)) {
                                shell_exec('unrar x -inul ' . escapeshellarg($uploadedTmp) . ' ' . escapeshellarg($name) . ' ' . escapeshellarg($tmpDir));
                                $found = $tmpDir . '/' . $name;
                                $finalExt = $e;
                                break;
                            }
                        }
                    }
                }

                if ($found !== '') {
                    $finalFile = $found;
                }
            }

            $destFile = $fullBookFolder . '/' . $baseFileName . '.' . $finalExt;
            rename($finalFile, $destFile);
            if ($uploadedTmp !== $finalFile && file_exists($uploadedTmp)) {
                unlink($uploadedTmp);
            }
            if (isset($tmpDir) && is_dir($tmpDir)) {
                array_map('unlink', glob($tmpDir . '/*'));
                @rmdir($tmpDir);
            }

            $coverFile = $fullBookFolder . '/cover.jpg';
            shell_exec('LANG=C ebook-meta --get-cover=' . escapeshellarg($coverFile) . ' ' . escapeshellarg($destFile) . ' 2>/dev/null');
            if (file_exists($coverFile) && filesize($coverFile) > 0) {
                $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$bookId]);
                touchLastModified($pdo, $bookId);
            } else {
                @unlink($coverFile);
            }

            $stmt = $pdo->prepare('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$bookId, strtoupper($finalExt), filesize($destFile), $baseFileName]);
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

            $uuid = $pdo->query("SELECT uuid FROM books WHERE id = $bookId")->fetchColumn();

            $tagsXml = '';
            foreach ($tags as $tag) {
                $tagsXml .= "    <dc:subject>" . htmlspecialchars($tag) . "</dc:subject>\n";
            }

            $timestamp = date('Y-m-d\TH:i:s');
            $languageCode = $languages[0] ?? 'eng';
            $publisherXml = $publisher !== '' ? "    <dc:publisher>" . htmlspecialchars($publisher) . "</dc:publisher>\n" : '';
            $isbnXml = $identifier !== '' ? "    <dc:identifier opf:scheme=\"ISBN\">$identifier</dc:identifier>\n" : '';
            $descriptionXml = $description !== '' ? "    <dc:description>" . htmlspecialchars($description) . "</dc:description>\n" : '';
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
            $message = 'Book added successfully.';
            $bookLink = 'list_books.php?search=' . urlencode($title);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    return [
        'message' => $message,
        'link' => $bookLink,
        'errors' => $errors,
        'title' => $title,
    ];
}

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titles = $_POST['title'] ?? [];
    $authors = $_POST['authors'] ?? [];
    $tags = $_POST['tags'] ?? [];
    $seriesArr = $_POST['series'] ?? [];
    $seriesIndexArr = $_POST['series_index'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $files = $_FILES['files'] ?? null;

    if ($files && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $file = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
            $results[] = processBook(
                $pdo,
                $libraryPath,
                trim($titles[$i] ?? ''),
                trim($authors[$i] ?? ''),
                trim($tags[$i] ?? ''),
                trim($seriesArr[$i] ?? ''),
                trim($seriesIndexArr[$i] ?? ''),
                trim($descriptions[$i] ?? ''),
                $file
            );
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Multiple Books</title>
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
<body style="padding-top:80px">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1 class="mb-4 text-center">Add Multiple Books</h1>

    <?php foreach ($results as $res): ?>
        <?php if ($res['message']): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($res['title']) ?>: <?= htmlspecialchars($res['message']) ?>
                <?php if ($res['link']): ?>
                    <a class="alert-link" href="<?= htmlspecialchars($res['link']) ?>">View Book</a>
                <?php endif; ?>
            </div>
        <?php elseif ($res['errors']): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($res['title']) ?>: <?= htmlspecialchars(implode(' ', $res['errors'])) ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="card shadow-sm p-4">
        <form method="post" enctype="multipart/form-data">
            <div class="mb-4">
                <label for="files" class="form-label fw-bold">Upload Book Files</label>
                <div class="file-upload-highlight" onclick="document.getElementById('files').click();">
                    <p class="mb-1"><strong>Click to upload book files</strong> or drag & drop here</p>
                    <small class="text-muted">(Supported formats: PDF, EPUB, etc.)</small>
                </div>
                <input type="file" name="files[]" id="files" class="form-control mt-2" style="display:none;" multiple required>
            </div>

            <div id="bookEntries"></div>

            <button type="submit" id="submitBtn" class="btn btn-primary" style="display:none;">Add Books</button>
            <a href="list_books.php" id="backBtn" class="btn btn-secondary ms-2" style="display:none;">Back</a>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const filesInput = document.getElementById('files');
const entriesContainer = document.getElementById('bookEntries');
const submitBtn = document.getElementById('submitBtn');
const backBtn = document.getElementById('backBtn');

filesInput.addEventListener('change', () => {
    entriesContainer.innerHTML = '';
    Array.from(filesInput.files).forEach((file, idx) => {
        const entry = document.createElement('div');
        entry.className = 'mb-4 p-3 border rounded';
        entry.innerHTML = `
            <div class="mb-3 text-center">
                <img id="cover-${idx}" src="" alt="Cover" class="img-fluid" style="max-height:300px; display:none;">
            </div>
            <div class="mb-3">
                <label for="title-${idx}" class="form-label">Title</label>
                <input type="text" name="title[]" id="title-${idx}" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="authors-${idx}" class="form-label">Author(s)</label>
                <input type="text" name="authors[]" id="authors-${idx}" class="form-control" placeholder="Separate multiple authors with commas" required>
            </div>
            <div class="mb-3">
                <label for="series-${idx}" class="form-label">Series</label>
                <input type="text" name="series[]" id="series-${idx}" class="form-control" placeholder="Optional">
            </div>
            <div class="mb-3">
                <label for="series_index-${idx}" class="form-label">Series Index</label>
                <input type="number" step="0.1" name="series_index[]" id="series_index-${idx}" class="form-control" placeholder="Optional">
            </div>
            <div class="mb-3">
                <label for="tags-${idx}" class="form-label">Tags</label>
                <input type="text" name="tags[]" id="tags-${idx}" class="form-control" placeholder="Optional, comma separated">
            </div>
            <div class="mb-3">
                <label for="description-${idx}" class="form-label">Description</label>
                <textarea name="description[]" id="description-${idx}" class="form-control" rows="3" placeholder="Optional"></textarea>
            </div>`;
        entriesContainer.appendChild(entry);

        const fd = new FormData();
        fd.append('file', file);
        fetch('ebook_meta.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.title) document.getElementById('title-' + idx).value = data.title;
                if (data.authors) {
                    document.getElementById('authors-' + idx).value = Array.isArray(data.authors)
                        ? data.authors.join(', ')
                        : String(data.authors).replace(/ and /g, ', ');
                }
                if (data.series) {
                    document.getElementById('series-' + idx).value = data.series;
                }
                if (data.series_index) {
                    document.getElementById('series_index-' + idx).value = data.series_index;
                }
                if (data.cover) {
                    const img = document.getElementById('cover-' + idx);
                    img.src = 'data:image/jpeg;base64,' + data.cover;
                    img.style.display = 'block';
                }
                if (data.comments) {
                    document.getElementById('description-' + idx).value = data.comments;
                }
            })
            .catch(() => {});
    });
    if (filesInput.files.length > 0) {
        submitBtn.style.display = 'inline-block';
        backBtn.style.display = 'inline-block';
    } else {
        submitBtn.style.display = 'none';
        backBtn.style.display = 'none';
    }
});
</script>
</body>
</html>

