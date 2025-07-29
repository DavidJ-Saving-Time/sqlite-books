<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid book ID');
}

// Fetch basic book info for editing
$stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$stmt->execute([$id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    die('Book not found');
}
$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$description = $commentStmt->fetchColumn() ?: '';

$updated = false;

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $authorsInput = trim($_POST['authors'] ?? '');
    $authorSortInput = trim($_POST['author_sort'] ?? '');
    $descriptionInput = trim($_POST['description'] ?? '');
    $seriesIdInput = $_POST['series_id'] ?? '';
    $newSeriesName = trim($_POST['new_series'] ?? '');
    $seriesIndexInput = trim($_POST['series_index'] ?? '');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE books SET title = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$title, $id]);

    if ($authorsInput !== '') {
        $authorsList = preg_split('/\s*(?:,|;| and )\s*/i', $authorsInput);
        $authorsList = array_filter(array_map('trim', $authorsList), 'strlen');
        if (empty($authorsList)) {
            $authorsList = [$authorsInput];
        }
        $primaryAuthor = $authorsList[0];
        $insertAuthor = $pdo->prepare(
            'INSERT INTO authors (name, sort)
             VALUES (:name, author_sort(:name))
             ON CONFLICT(name) DO UPDATE SET sort = excluded.sort'
        );
        foreach ($authorsList as $a) {
            $insertAuthor->execute([':name' => $a]);
        }
        $pdo->prepare('DELETE FROM books_authors_link WHERE book = :book')->execute([':book' => $id]);
        foreach ($authorsList as $a) {
            $aid = $pdo->query('SELECT id FROM authors WHERE name=' . $pdo->quote($a))->fetchColumn();
            if ($aid !== false) {
                $linkStmt = $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (:book, :author)');
                $linkStmt->execute([':book' => $id, ':author' => $aid]);
            }
        }
        if ($authorSortInput === '') {
            $sortStmt = $pdo->prepare('SELECT author_sort(:name)');
            $sortStmt->execute([':name' => $primaryAuthor]);
            $authorSortInput = (string)$sortStmt->fetchColumn();
        }
        $pdo->prepare('UPDATE books SET author_sort = :sort WHERE id = :id')
            ->execute([':sort' => $authorSortInput, ':id' => $id]);
    }

    if ($descriptionInput !== '') {
        $descStmt = $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text) '
            . 'ON CONFLICT(book) DO UPDATE SET text = excluded.text');
        $descStmt->execute([':book' => $id, ':text' => $descriptionInput]);
    } else {
        $pdo->prepare('DELETE FROM comments WHERE book = ?')->execute([$id]);
    }

    // Handle series update
    $seriesIndex = $seriesIndexInput !== '' ? (float)$seriesIndexInput : (float)$book['series_index'];
    if ($seriesIdInput === '' && $newSeriesName === '') {
        $pdo->prepare('DELETE FROM books_series_link WHERE book = :book')
            ->execute([':book' => $id]);
    } else {
        if ($seriesIdInput === 'new' || ($seriesIdInput === '' && $newSeriesName !== '')) {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO series (name, sort) VALUES (:name, :sort)');
            $stmt->execute([':name' => $newSeriesName, ':sort' => $newSeriesName]);
            $stmt = $pdo->prepare('SELECT id FROM series WHERE name = :name');
            $stmt->execute([':name' => $newSeriesName]);
            $seriesId = (int)$stmt->fetchColumn();
        } else {
            $seriesId = (int)$seriesIdInput;
        }
        $pdo->prepare('DELETE FROM books_series_link WHERE book = :book')
            ->execute([':book' => $id]);
        $pdo->prepare('INSERT OR REPLACE INTO books_series_link (book, series) VALUES (:book, :series)')
            ->execute([':book' => $id, ':series' => $seriesId]);
    }
    $pdo->prepare('UPDATE books SET series_index = :idx WHERE id = :id')
        ->execute([':idx' => $seriesIndex, ':id' => $id]);

    if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        $libraryPath = getLibraryPath();
        $destDir = $libraryPath . '/' . $book['path'];
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $destFile = $destDir . '/cover.jpg';
        move_uploaded_file($_FILES['cover']['tmp_name'], $destFile);
        $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$id]);
    }

    $pdo->commit();
    $updated = true;

    // Refresh description for display
    $description = $descriptionInput;
}

$sort = $_GET['sort'] ?? 'title';

// Fetch full book details for display
$stmt = $pdo->prepare("SELECT b.*,
        (SELECT GROUP_CONCAT(a.name, ', ')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS authors,
        (SELECT GROUP_CONCAT(a.id || ':' || a.name, '|')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS author_data,
        s.id AS series_id,
        s.name AS series
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id
    WHERE b.id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    die('Book not found');
}

$commentStmt->execute([$id]);
$comment = $commentStmt->fetchColumn();
if ($comment !== false && $comment !== null) {
    $description = $comment;
}

$tagsStmt = $pdo->prepare("SELECT GROUP_CONCAT(t.name, ', ')
    FROM books_tags_link btl
    JOIN tags t ON btl.tag = t.id
    WHERE btl.book = ?");
$tagsStmt->execute([$id]);
$tags = $tagsStmt->fetchColumn();

// Fetch list of all series for dropdown
$seriesList = [];
try {
    $stmt = $pdo->query('SELECT id, name FROM series ORDER BY name COLLATE NOCASE');
    $seriesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $seriesList = [];
}

// Fetch saved recommendations if present
try {
    $recId = ensureSingleValueColumn($pdo, '#recommendation', 'Recommendation');
    $valTable  = "custom_column_{$recId}";
    $linkTable = "books_custom_column_{$recId}_link";
    $recStmt = $pdo->prepare("SELECT v.value FROM $linkTable l JOIN $valTable v ON l.value = v.id WHERE l.book = ?");
    $recStmt->execute([$id]);
    $savedRecommendations = $recStmt->fetchColumn();
} catch (PDOException $e) {
    $savedRecommendations = null;
}

$missingFile = !bookHasFile($book['path']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($book['title']) ?></title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <style>
        #description { min-height: 200px; resize: vertical; }
    </style>
</head>
<body class="pt-5" data-book-id="<?= (int)$book['id'] ?>" data-search-query="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <a href="list_books.php" class="btn btn-secondary mb-3">Back to list</a>
    <h1 class="mb-0">
        <?php if ($missingFile): ?>
            <i class="fa-solid fa-circle-exclamation text-danger me-1" title="File missing"></i>
        <?php endif; ?>
        <?= htmlspecialchars($book['title']) ?>
    </h1>
    <?php
        $formattedPubdate = '';
        if (!empty($book['pubdate'])) {
            try {
                $dt = new DateTime($book['pubdate']);
                $formattedPubdate = $dt->format('jS \of F Y');
            } catch (Exception $e) {
                $formattedPubdate = htmlspecialchars($book['pubdate']);
            }
        }
    ?>
    <p class="mb-4">
        <?php if (!empty($book['isbn'])): ?>
            <strong>ISBN:</strong> <?= htmlspecialchars($book['isbn']) ?><br>
        <?php endif; ?>
        <?php if ($formattedPubdate): ?>
            <strong>Published:</strong> <?= htmlspecialchars($formattedPubdate) ?>
        <?php endif; ?>
    </p>
    <button type="button" id="recommendBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary mb-4">Get Book Recommendations</button>
    <button type="button" id="synopsisBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary mb-4 ms-2">Generate Synopsis</button>
    <?php
        $annasQuery = urlencode($book['title'] . ' ' . $book['authors']);
        $annasUrl = 'list_books.php?source=annas&search=' . $annasQuery;
    ?>
    <a href="<?= htmlspecialchars($annasUrl) ?>" class="btn btn-secondary mb-4 ms-2">Search Anna's Archive</a>
    <button type="button" id="annasMetaBtn" class="btn btn-secondary mb-4 ms-2">Get Metadata</button>
    <button type="button" id="googleMetaBtn" class="btn btn-secondary mb-4 ms-2">Metadata Google</button>
    <?php if ($missingFile): ?>
        <button type="button" id="uploadFileButton" class="btn btn-secondary mb-4 ms-2">Upload File</button>
        <input type="file" id="bookFileInput" style="display:none">
        <div id="uploadMessage" class="mt-2 mb-2 h2"></div>
    <?php endif; ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <?php if (!empty($book['has_cover'])): ?>
                <img src="<?= htmlspecialchars(getLibraryPath() . '/' . $book['path'] . '/cover.jpg') ?>" alt="Cover" class="img-fluid">
            <?php else: ?>
                <div class="text-muted">No cover</div>
            <?php endif; ?>
        </div>
        <div class="col-md-9">
            <p><strong>Author(s):</strong>
                <?php if (!empty($book['author_data'])): ?>
                    <?php
                        $links = [];
                        foreach (explode('|', $book['author_data']) as $pair) {
                            list($aid, $aname) = explode(':', $pair, 2);
                            $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                            $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
                        }
                        echo implode(', ', $links);
                    ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </p>
            <p><strong>Series:</strong>
                <?php if (!empty($book['series'])): ?>
                    <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                        <?= htmlspecialchars($book['series']) ?>
                    </a>
                    <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                        (<?= htmlspecialchars($book['series_index']) ?>)
                    <?php endif; ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </p>
            <?php if (!empty($tags)): ?>
                <p><strong>Tags:</strong> <?= htmlspecialchars($tags) ?></p>
            <?php endif; ?>
            <div id="recommendSection"<?php if (!empty($savedRecommendations)): ?> data-saved="<?= htmlspecialchars($savedRecommendations, ENT_QUOTES) ?>"<?php endif; ?>></div>
        </div>
    </div>

    <!-- Edit form -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="card-title mb-4">
                <i class="fa-solid fa-pen-to-square me-2"></i> Edit Book Metadata
            </h2>
            <?php if ($updated): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check me-2"></i> Book updated successfully
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="mb-3">
                <div class="mb-3">
                    <label for="title" class="form-label">
                        <i class="fa-solid fa-book me-1 text-primary"></i> Title
                    </label>
                    <input type="text" id="title" name="title" value="<?= htmlspecialchars($book['title']) ?>" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="authors" class="form-label">
                        <i class="fa-solid fa-user me-1 text-primary"></i> Author(s)
                    </label>
                    <input type="text" id="authors" name="authors" value="<?= htmlspecialchars($book['authors']) ?>" class="form-control" placeholder="Separate multiple authors with commas" list="authorSuggestionsEdit">
                    <datalist id="authorSuggestionsEdit"></datalist>
                </div>
                <div class="mb-3">
                    <label for="authorSort" class="form-label">
                        <i class="fa-solid fa-user-pen me-1 text-primary"></i> Author Sort
                    </label>
                    <input type="text" id="authorSort" name="author_sort" value="<?= htmlspecialchars($book['author_sort']) ?>" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="series" class="form-label">
                        <i class="fa-solid fa-layer-group me-1 text-primary"></i> Series
                    </label>
                    <div class="input-group">
                        <select id="series" name="series_id" class="form-select">
                            <option value=""<?= empty($book['series_id']) ? ' selected' : '' ?>>None</option>
                            <?php foreach ($seriesList as $s): ?>
                                <option value="<?= htmlspecialchars($s['id']) ?>"<?= (int)$book['series_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                            <option value="new">Add new series...</option>
                        </select>
                        <button type="button" id="addSeriesBtn" class="btn btn-outline-primary"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" id="editSeriesBtn" class="btn btn-outline-secondary"><i class="fa-solid fa-pen"></i></button>
                    </div>
                    <input type="text" id="newSeriesInput" name="new_series" class="form-control mt-2" placeholder="New series name" style="display:none">
                </div>
                <div class="mb-3">
                    <label for="seriesIndex" class="form-label">
                        <i class="fa-solid fa-hashtag me-1 text-primary"></i> Number in Series
                    </label>
                    <input type="number" step="0.1" id="seriesIndex" name="series_index" value="<?= htmlspecialchars($book['series_index']) ?>" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label">
                        <i class="fa-solid fa-align-left me-1 text-primary"></i> Description
                    </label>
                    <textarea id="description" name="description" class="form-control" rows="16"><?= htmlspecialchars($description) ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="cover" class="form-label">
                        <i class="fa-solid fa-image me-1 text-primary"></i> Cover Image
                    </label>
                    <input type="file" id="cover" name="cover" class="form-control">
                </div>
                <?php if (!empty($book['has_cover'])): ?>
                    <div class="mb-3">
                        <p class="mb-1"><i class="fa-solid fa-eye me-1 text-success"></i> Current Cover:</p>
                        <div class="position-relative d-inline-block">
                            <img id="coverImagePreview" src="<?= htmlspecialchars(getLibraryPath() . '/' . $book['path'] . '/cover.jpg') ?>" alt="Cover" class="img-thumbnail shadow-sm" style="max-width: 200px;">
                            <div id="coverDimensions" class="position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 1.2rem;">Loading...</div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between">
                    <a href="list_books.php" class="btn btn-secondary">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to list
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Anna's Archive Metadata Modal -->
    <div class="modal fade" id="annasModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Anna's Archive Results</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="annasResults">Loading...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Google Books Metadata Modal -->
    <div class="modal fade" id="googleModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Google Books Results</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="googleResults">Loading...</div>
          </div>
        </div>
      </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/book.js"></script>
</body>
</html>
