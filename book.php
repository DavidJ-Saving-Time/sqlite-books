<?php
require_once 'db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    $name = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid book ID');
}

// Fetch basic book info for editing
$stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$stmt->execute([$id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
// Prefer ISBN stored in identifiers table if present
$isbnStmt = $pdo->prepare('SELECT val FROM identifiers WHERE book = ? AND type = "isbn" COLLATE NOCASE');
$isbnStmt->execute([$id]);
$isbnVal = $isbnStmt->fetchColumn();
if ($isbnVal !== false && $isbnVal !== null) {
    $book['isbn'] = $isbnVal;
}
if (!$book) {
    die('Book not found');
}
$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$description = $commentStmt->fetchColumn() ?: '';
$notes = '';

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
    $publisherInput = trim($_POST['publisher'] ?? '');
    $pubdateInput = trim($_POST['pubdate'] ?? '');
    $pubDate = $book['pubdate'];
    $isbnInput = trim($_POST['isbn'] ?? '');
    $notesInput = trim($_POST['notes'] ?? '');

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

    // Update notes in custom column
    try {
        $notesId = ensureSingleValueColumn($pdo, '#notes', 'Notes');
        $notesValTable  = "custom_column_{$notesId}";
        $notesLinkTable = "books_custom_column_{$notesId}_link";
        $pdo->prepare("DELETE FROM $notesLinkTable WHERE book = :book")
            ->execute([':book' => $id]);
        if ($notesInput !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO $notesValTable (value) VALUES (:val)")
                ->execute([':val' => $notesInput]);
            $valStmt = $pdo->prepare("SELECT id FROM $notesValTable WHERE value = :val");
            $valStmt->execute([':val' => $notesInput]);
            $valId = $valStmt->fetchColumn();
            if ($valId !== false) {
                $pdo->prepare("INSERT INTO $notesLinkTable (book, value) VALUES (:book, :val)")
                    ->execute([':book' => $id, ':val' => $valId]);
            }
        }
    } catch (PDOException $e) {
        // Ignore errors updating notes
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

    // Update publisher information
    if ($publisherInput !== '') {
        $pdo->prepare('INSERT OR IGNORE INTO publishers(name) VALUES (?)')->execute([$publisherInput]);
        $pdo->prepare('DELETE FROM books_publishers_link WHERE book=?')->execute([$id]);
        $pdo->prepare('INSERT INTO books_publishers_link(book,publisher) SELECT ?, id FROM publishers WHERE name=?')
            ->execute([$id, $publisherInput]);
    } else {
        $pdo->prepare('DELETE FROM books_publishers_link WHERE book=?')->execute([$id]);
    }

    // Update publication date
    if ($pubdateInput !== '') {
        $pubDate = preg_match('/^\d{4}$/', $pubdateInput) ? $pubdateInput . '-01-01' : $pubdateInput;
        $pdo->prepare('UPDATE books SET pubdate=? WHERE id=?')->execute([$pubDate, $id]);
    }

    // Update ISBN stored in both books table and identifiers table
    $pdo->prepare('UPDATE books SET isbn=? WHERE id=?')->execute([$isbnInput, $id]);
    $pdo->prepare('DELETE FROM identifiers WHERE book=? AND type="isbn"')->execute([$id]);
    if ($isbnInput !== '') {
        $pdo->prepare('INSERT INTO identifiers (book, type, val) VALUES (?, "isbn", ?)')
            ->execute([$id, $isbnInput]);
    }

    // If authors changed adjust the filesystem path accordingly
    if ($authorsInput !== '') {
        $oldPath = $book['path'];
        $oldBookFolder = $oldPath !== '' ? basename($oldPath) : safe_filename($title) . ' (' . $id . ')';
        $oldAuthorFolder = $oldPath !== '' ? dirname($oldPath) : '';
        $newAuthorFolder = safe_filename($primaryAuthor . (count($authorsList) > 1 ? ' et al.' : ''));
        $newPath = $newAuthorFolder . '/' . $oldBookFolder;

        if ($newPath !== $oldPath) {
            $libraryPath = getLibraryPath();
            $oldFullPath = $oldPath !== '' ? $libraryPath . '/' . $oldPath : '';
            $newFullPath = $libraryPath . '/' . $newPath;

            // Create target author directory if needed
            if (!is_dir(dirname($newFullPath))) {
                mkdir(dirname($newFullPath), 0777, true);
            }

            // Move existing directory if present
            if ($oldFullPath !== '' && is_dir($oldFullPath)) {
                rename($oldFullPath, $newFullPath);

                // Remove old author directory if empty
                $oldAuthorDir = $libraryPath . '/' . $oldAuthorFolder;
                if (is_dir($oldAuthorDir)) {
                    $entries = array_diff(scandir($oldAuthorDir), ['.', '..']);
                    if (count($entries) === 0) {
                        rmdir($oldAuthorDir);
                    }
                }
            } else if (!is_dir($newFullPath)) {
                // If original folder missing just create the new one
                mkdir($newFullPath, 0777, true);
            }

            $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$newPath, $id]);
            $book['path'] = $newPath; // For subsequent operations
        }
    }

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
    $notes = $notesInput;
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
        s.name AS series,
        (SELECT name FROM publishers WHERE publishers.id IN
            (SELECT publisher FROM books_publishers_link WHERE book = b.id)
            LIMIT 1) AS publisher
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id
    WHERE b.id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);
// Pull ISBN from identifiers table if available
$isbnStmt = $pdo->prepare('SELECT val FROM identifiers WHERE book = ? AND type = "isbn" COLLATE NOCASE');
$isbnStmt->execute([$id]);
$isbnVal = $isbnStmt->fetchColumn();
if ($isbnVal !== false && $isbnVal !== null) {
    $book['isbn'] = $isbnVal;
}
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

// Extract publication year for display
$pubYear = '';
if (!empty($book['pubdate'])) {
    try {
        $dt = new DateTime($book['pubdate']);
        $pubYear = $dt->format('Y');
    } catch (Exception $e) {
        if (preg_match('/^\d{4}/', $book['pubdate'], $m)) {
            $pubYear = $m[0];
        }
    }
}

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

// Fetch notes if present
try {
    $notesId = ensureSingleValueColumn($pdo, '#notes', 'Notes');
    $valTable  = "custom_column_{$notesId}";
    $linkTable = "books_custom_column_{$notesId}_link";
    $notesStmt = $pdo->prepare("SELECT v.value FROM $linkTable l JOIN $valTable v ON l.value = v.id WHERE l.book = ?");
    $notesStmt->execute([$id]);
    $notes = $notesStmt->fetchColumn() ?: '';
} catch (PDOException $e) {
    $notes = '';
}

$missingFile = !bookHasFile($book['path']);

$libraryDirPath = getLibraryPath();
if (!empty($book['path'])) {
    $libraryDirPath .= '/' . $book['path'];
} else {
    $authorList = array_map('trim', explode(',', $book['authors'] ?? ''));
    $firstAuthor = $authorList[0] ?? '';
    $authorFolderName = safe_filename($firstAuthor . (count($authorList) > 1 ? ' et al.' : ''));
    $bookFolderName = safe_filename($book['title']) . ' (' . $book['id'] . ')';
    $libraryDirPath .= '/' . $authorFolderName . '/' . $bookFolderName;
}
$ebookFileRel = $missingFile ? '' : firstBookFile($book['path']);
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
<body class="pt-5" data-book-id="<?= (int)$book['id'] ?>" data-search-query="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>"<?php if($ebookFileRel): ?> data-ebook-file="<?= htmlspecialchars($ebookFileRel) ?>"<?php endif; ?><?php if(!empty($book['isbn'])): ?> data-isbn="<?= htmlspecialchars($book['isbn']) ?>"<?php endif; ?>>
<?php include "navbar_other.php"; ?>
<div class="container my-4">
    <a href="list_books.php" class="btn btn-secondary mb-3">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to list
    </a>
    <a href="list_books.php?search=<?= urlencode($book['title']) ?>&source=local" class="btn btn-secondary mb-3 ms-2">
        <i class="fa-solid fa-magnifying-glass me-1"></i> Back to book
    </a>

    <!-- Book Title and Info -->
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
    <p class="mb-4 text-muted">
        <?php if (!empty($book['isbn'])): ?>
            <strong>ISBN:</strong> <?= htmlspecialchars($book['isbn']) ?><br>
        <?php endif; ?>
        <?php if ($formattedPubdate): ?>
            <strong>Published:</strong> <?= htmlspecialchars($formattedPubdate) ?>
        <?php endif; ?>
    </p>

    <!-- Actions Toolbar -->
    <div class="btn-toolbar mb-4 flex-wrap">
        <div class="btn-group me-2 mb-2">
            <button type="button" id="recommendBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary">
                Get Recommendations
            </button>
            <button type="button" id="synopsisBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary">
                Generate Synopsis
            </button>
        </div>
        <div class="btn-group me-2 mb-2">
            <a href="<?= htmlspecialchars($annasUrl) ?>" class="btn btn-secondary">Anna's Archive</a>
            <button type="button" id="metadataBtn" class="btn btn-secondary">Get Metadata</button>
            <?php if (!$missingFile && $ebookFileRel): ?>
            <button type="button" id="ebookMetaBtn" class="btn btn-secondary">File Metadata</button>
            <?php endif; ?>
        </div>
        <?php if ($missingFile): ?>
            <div class="btn-group mb-2">
                <button type="button" id="uploadFileButton" class="btn btn-secondary">Upload File</button>
                <input type="file" id="bookFileInput" style="display:none">
            </div>
        <?php endif; ?>
    </div>
    <?php if ($missingFile): ?>
        <div id="uploadMessage" class="mt-2 mb-2 h2"></div>
    <?php endif; ?>

    <!-- Two-column layout -->
    <div class="row">
        <!-- Left Column: Book Metadata -->
        <div class="col-lg-4 mb-4">
            <?php if (!empty($book['has_cover'])): ?>
                <div class="card shadow-sm mb-4">
                    <div class="position-relative">
                        <img id="coverImagePreview" src="<?= htmlspecialchars(getLibraryPath() . '/' . $book['path'] . '/cover.jpg') ?>" alt="Cover" class="card-img-top img-thumbnail">
                        <div id="coverDimensions" class="position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 1.2rem;">Loading...</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-muted">No cover</div>
            <?php endif; ?>

            <div class="border p-3 rounded bg-light shadow-sm">
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
            </div>
        </div>

        <!-- Right Column: Edit Form with Tabs -->
        <div class="col-lg-8">
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

                    <!-- Tabbed Form -->
                    <ul class="nav nav-tabs mb-3" id="editBookTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBasic">Basic Info</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSeries">Series</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDescription">Description & Cover</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabNotes">Notes</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRecommendations">Recommendations</button>
                        </li>
                    </ul>
                    <form method="post" enctype="multipart/form-data">
                        <div class="tab-content">
                            <!-- Basic Info -->
                            <div class="tab-pane fade show active" id="tabBasic">
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
                                    <div class="input-group">
                                        <input type="text" id="authors" name="authors" value="<?= htmlspecialchars($book['authors']) ?>" class="form-control" placeholder="Separate multiple authors with commas" list="authorSuggestionsEdit">
                                        <button type="button" id="applyAuthorSortBtn" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i></button>
                                    </div>
                                    <datalist id="authorSuggestionsEdit"></datalist>
                                </div>
                                <div class="mb-3">
                                    <label for="authorSort" class="form-label">
                                        <i class="fa-solid fa-user-pen me-1 text-primary"></i> Author Sort
                                    </label>
                                    <input type="text" id="authorSort" name="author_sort" value="<?= htmlspecialchars($book['author_sort']) ?>" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="libraryBasePath" class="form-label">
                                        <i class="fa-solid fa-folder-open me-1 text-primary"></i> Library Base Directory
                                    </label>
                                    <input type="text" id="libraryBasePath" class="form-control" value="<?= htmlspecialchars($libraryDirPath) ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label for="publisher" class="form-label">
                                        <i class="fa-solid fa-building me-1 text-primary"></i> Publisher
                                    </label>
                                    <input type="text" id="publisher" name="publisher" value="<?= htmlspecialchars($book['publisher'] ?? '') ?>" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="pubdate" class="form-label">
                                        <i class="fa-solid fa-calendar me-1 text-primary"></i> Publication Year
                                    </label>
                                    <input type="text" id="pubdate" name="pubdate" value="<?= htmlspecialchars($pubYear) ?>" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="isbn" class="form-label">
                                        <i class="fa-solid fa-barcode me-1 text-primary"></i> ISBN
                                    </label>
                                    <input type="text" id="isbn" name="isbn" value="<?= htmlspecialchars($book['isbn']) ?>" class="form-control">
                                </div>
                            </div>

                            <!-- Series Info -->
                            <div class="tab-pane fade" id="tabSeries">
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
                            </div>

                            <!-- Description & Cover -->
                            <div class="tab-pane fade" id="tabDescription">
                                <div class="mb-3">
                                    <label for="description" class="form-label">
                                        <i class="fa-solid fa-align-left me-1 text-primary"></i> Description
                                    </label>
                                    <textarea id="description" name="description" class="form-control" rows="10"><?= htmlspecialchars($description) ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="cover" class="form-label">
                                        <i class="fa-solid fa-image me-1 text-primary"></i> Cover Image
                                    </label>
                                    <input type="file" id="cover" name="cover" class="form-control">
                                    <div id="isbnCover" class="mt-2"></div>
                                </div>
                            </div>
                            <!-- Notes -->
                            <div class="tab-pane fade" id="tabNotes">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">
                                        <i class="fa-solid fa-note-sticky me-1 text-primary"></i> Notes
                                    </label>
                                    <textarea id="notes" name="notes" class="form-control" rows="10"><?= htmlspecialchars($notes) ?></textarea>
                                </div>
                            </div>
                            <!-- Recommendations -->
                            <div class="tab-pane fade" id="tabRecommendations">
                                <div id="recommendSection"<?php if (!empty($savedRecommendations)): ?> data-saved="<?= htmlspecialchars($savedRecommendations, ENT_QUOTES) ?>"<?php endif; ?>></div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <div>
                                <a href="list_books.php" class="btn btn-secondary">
                                    <i class="fa-solid fa-arrow-left me-1"></i> Back to list
                                </a>
                                <a href="list_books.php?search=<?= urlencode($book['title']) ?>&source=local" class="btn btn-secondary ms-2">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i> Back to book
                                </a>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-save me-1"></i> Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>



    <?php include 'metadata_modal.php'; ?>
  </div>
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/book.js"></script>
</body>
</html>
