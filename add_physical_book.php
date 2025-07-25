<?php
require_once 'db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    $name = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

$pdo = getDatabaseConnection();
$libraryPath = realpath(__DIR__ . '/ebooks');
if ($libraryPath === false) {
    $libraryPath = __DIR__ . '/ebooks';
}

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

            $authors = array_map('trim', preg_split('/,|;/', $authors_str));
            $firstAuthor = $authors[0];

            // Add authors
            foreach ($authors as $author) {
                $stmt = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?))');
                $stmt->execute([$author, $author]);
            }

            // Add book (uuid4 handled by DB)
            $bookPath = safe_filename($title);
            $stmt = $pdo->prepare(
                'INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, uuid)
                 VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, ?, uuid4())'
            );
            $stmt->execute([$title, $title, $firstAuthor, $bookPath]);
            $bookId = (int)$pdo->lastInsertId();

            // Link authors
            foreach ($authors as $author) {
                $pdo->exec("INSERT INTO books_authors_link (book, author) 
                            SELECT $bookId, id FROM authors WHERE name=" . $pdo->quote($author));
            }

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

            // Handle custom columns
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

            // Create folders
            $authorFolderName = safe_filename($firstAuthor . (count($authors) > 1 ? ' et al.' : ''));
            $authorFolder = $libraryPath . '/' . $authorFolderName;
            if (!is_dir($authorFolder)) {
                mkdir($authorFolder, 0777, true);
            }

            $bookFolder = $authorFolder . '/' . safe_filename($title) . " ($bookId)";
            if (!is_dir($bookFolder)) {
                mkdir($bookFolder, 0777, true);
            }

            // Move uploaded file
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $destFile = $bookFolder . '/' . safe_filename($title) . ' - ' . safe_filename($firstAuthor) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $destFile);

            // Add entry to 'data' table (linking the book to its file format)
            $stmt = $pdo->prepare('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$bookId, strtoupper($ext), filesize($destFile), safe_filename($title)]);

            // Fetch the UUID from the database
            $uuid = $pdo->query("SELECT uuid FROM books WHERE id = $bookId")->fetchColumn();

            // Generate metadata.opf with UUID, tags, etc.
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
            file_put_contents($bookFolder . '/metadata.opf', $opf);

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
    <script src="theme.js"></script>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container my-4">
    <h1 class="mb-4">Add Book</h1>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php elseif ($errors): ?>
        <div class="alert alert-danger"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <div class="mb-3">
            <label for="title" class="form-label">Title</label>
            <input type="text" name="title" id="title" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="authors" class="form-label">Author(s)</label>
            <input type="text" name="authors" id="authors" class="form-control" placeholder="Separate multiple authors with commas" required>
        </div>
        <div class="mb-3">
            <label for="file" class="form-label">Book File</label>
            <input type="file" name="file" id="file" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="tags" class="form-label">Tags</label>
            <input type="text" name="tags" id="tags" class="form-control" placeholder="Optional, comma separated">
        </div>
        <button type="submit" class="btn btn-primary">Add</button>
        <a href="list_books.php" class="btn btn-secondary ms-2">Back</a>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
