<?php
require_once 'db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

function findBookFileByExtension(string $relativePath, string $extension): ?string {
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '') {
        return null;
    }

    $library = getLibraryPath();
    $dir = rtrim($library . '/' . $relativePath, '/');
    if (!is_dir($dir)) {
        return null;
    }

    $extension = strtolower($extension);
    foreach (glob($dir . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === $extension) {
            if (strpos($file, $library . '/') === 0) {
                return substr($file, strlen($library) + 1);
            }
            return ltrim($relativePath . '/' . basename($file), '/');
        }
    }

    return null;
}

$pdo = getDatabaseConnection();

$hasSubseries = false;
$subseriesIsCustom = false;
$subseriesLinkTable = '';
$subseriesValueTable = '';
$subseriesIndexColumn = null; // column name for custom subseries index
$subseriesIndexExists = false; // whether any subseries index column exists
try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $hasSubseries = true;
        $subseriesIsCustom = true;
        $subseriesValueTable = "custom_column_{$subseriesColumnId}";
        $subseriesLinkTable  = "books_custom_column_{$subseriesColumnId}_link";
        $cols = $pdo->query("PRAGMA table_info($subseriesLinkTable)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if (in_array($col['name'], ['book_index', 'sort', 'extra'], true)) {
                $subseriesIndexColumn = $col['name'];
                $subseriesIndexExists = true;
                break;
            }
        }
    } else {
        $subTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) {
            $hasSubseries = true;
            $cols = $pdo->query('PRAGMA table_info(books)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if ($col['name'] === 'subseries_index') {
                    $subseriesIndexExists = true;
                    break;
                }
            }
        }
    }
} catch (PDOException $e) {
    $hasSubseries = false;
}

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

if ($hasSubseries) {
    if ($subseriesIsCustom) {
        $idxField = $subseriesIndexColumn ? ", bssl.$subseriesIndexColumn AS idx" : ", NULL AS idx";
        $subStmt = $pdo->prepare("SELECT ss.id, ss.value AS name$idxField FROM $subseriesLinkTable bssl JOIN $subseriesValueTable ss ON bssl.value = ss.id WHERE bssl.book = :book LIMIT 1");
        $subStmt->execute([':book' => $id]);
        $row = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $book['subseries_id'] = $row['id'];
            $book['subseries'] = $row['name'];
            $book['subseries_index'] = $row['idx'];
        } else {
            $book['subseries_id'] = null;
            $book['subseries'] = null;
            $book['subseries_index'] = null;
        }
    } else {
        $idxSelect = $subseriesIndexExists ? 'b.subseries_index' : 'NULL AS subseries_index';
        $subStmt = $pdo->prepare("SELECT ss.id, ss.name, $idxSelect FROM books b LEFT JOIN books_subseries_link bssl ON bssl.book = b.id LEFT JOIN subseries ss ON bssl.subseries = ss.id WHERE b.id = :id");
        $subStmt->execute([':id' => $id]);
        $row = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $book['subseries_id'] = $row['id'];
            $book['subseries'] = $row['name'];
            $book['subseries_index'] = $row['subseries_index'];
        } else {
            $book['subseries_id'] = null;
            $book['subseries'] = null;
            $book['subseries_index'] = null;
        }
    }
}
$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$description = $commentStmt->fetchColumn() ?: '';
$notes = '';

$returnPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
$returnItem = isset($_GET['item']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['item']) : '';
$backToListUrl = 'list_books.php';
$backParams = [];
if ($returnPage) {
    $backParams['page'] = $returnPage;
}
if ($backParams) {
    $backToListUrl .= '?' . http_build_query($backParams);
}
if ($returnItem !== '') {
    $backToListUrl .= '#' . $returnItem;
}

$updated = false;
$sendMessage = null;
$conversionMessage = null;
$convertRequested = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_to_pdf']));
$sendRequested = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_to_device']));

if ($convertRequested) {
    $bookRelPath = ltrim((string)($book['path'] ?? ''), '/');
    if ($bookRelPath === '') {
        $conversionMessage = ['type' => 'danger', 'text' => 'Book path is not set in the library.'];
    } else {
        $epubRelative = findBookFileByExtension($bookRelPath, 'epub');
        if ($epubRelative === null) {
            $conversionMessage = ['type' => 'danger', 'text' => 'No EPUB file found to convert.'];
        } else {
            $libraryPath = getLibraryPath();
            $inputFile = $libraryPath . '/' . $epubRelative;
            $outputDir = dirname($inputFile);
            $outputFile = $outputDir . '/' . pathinfo($inputFile, PATHINFO_FILENAME) . '.pdf';
            if (file_exists($outputFile)) {
                $conversionMessage = ['type' => 'warning', 'text' => 'PDF already exists: ' . basename($outputFile)];
            } else {
                $cmd = 'LANG=C ebook-convert ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
                $cmdOutput = [];
                $exitCode = 0;
                exec($cmd, $cmdOutput, $exitCode);
                clearstatcache(true, $outputFile);
                if ($exitCode === 0 && file_exists($outputFile)) {
                    @chmod($outputFile, 0664);
                    $conversionMessage = ['type' => 'success', 'text' => 'PDF created: ' . basename($outputFile)];
                } else {
                    if ($exitCode !== 0 && file_exists($outputFile)) {
                        @unlink($outputFile);
                    }
                    $error = '';
                    foreach ($cmdOutput as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $error = $line;
                            break;
                        }
                    }
                    if ($error === '') {
                        $error = 'ebook-convert failed to create PDF.';
                    }
                    $conversionMessage = ['type' => 'danger', 'text' => $error];
                }
            }
        }
    }
}

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$convertRequested) {
    $title = $_POST['title'] ?? '';
    $authorsInput = trim($_POST['authors'] ?? '');
    $authorSortInput = trim($_POST['author_sort'] ?? '');
    $descriptionInput = trim($_POST['description'] ?? '');
    $seriesNameInput = trim($_POST['series'] ?? '');
    $seriesIndexInput = trim($_POST['series_index'] ?? '');
    $subseriesNameInput = trim($_POST['subseries'] ?? '');
    $subseriesIndexInput = trim($_POST['subseries_index'] ?? '');
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
    if ($seriesNameInput === '') {
        $pdo->prepare('DELETE FROM books_series_link WHERE book = :book')
            ->execute([':book' => $id]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM series WHERE name = :name');
        $stmt->execute([':name' => $seriesNameInput]);
        $seriesId = $stmt->fetchColumn();
        if ($seriesId === false) {
            $stmt = $pdo->prepare('INSERT INTO series (name, sort) VALUES (:name, :sort)');
            $stmt->execute([':name' => $seriesNameInput, ':sort' => $seriesNameInput]);
            $seriesId = $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM books_series_link WHERE book = :book')
            ->execute([':book' => $id]);
        $pdo->prepare('INSERT OR REPLACE INTO books_series_link (book, series) VALUES (:book, :series)')
            ->execute([':book' => $id, ':series' => $seriesId]);
    }
    $pdo->prepare('UPDATE books SET series_index = :idx WHERE id = :id')
        ->execute([':idx' => $seriesIndex, ':id' => $id]);

    if ($hasSubseries) {
        $subIndex = $subseriesIndexExists ? ($subseriesIndexInput !== '' ? (float)$subseriesIndexInput : ($book['subseries_index'] !== null ? (float)$book['subseries_index'] : null)) : null;
        if ($subseriesNameInput === '') {
            if ($subseriesIsCustom) {
                $pdo->prepare("DELETE FROM $subseriesLinkTable WHERE book = :book")
                    ->execute([':book' => $id]);
            } else {
                $pdo->prepare('DELETE FROM books_subseries_link WHERE book = :book')
                    ->execute([':book' => $id]);
                if ($subseriesIndexExists) {
                    $pdo->prepare('UPDATE books SET subseries_index = NULL WHERE id = :id')
                        ->execute([':id' => $id]);
                }
            }
        } else {
            if ($subseriesIsCustom) {
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO $subseriesValueTable (value) VALUES (:val)");
                $stmt->execute([':val' => $subseriesNameInput]);
                $stmt = $pdo->prepare("SELECT id FROM $subseriesValueTable WHERE value = :val");
                $stmt->execute([':val' => $subseriesNameInput]);
                $subId = (int)$stmt->fetchColumn();
                $pdo->prepare("DELETE FROM $subseriesLinkTable WHERE book = :book")
                    ->execute([':book' => $id]);
                if ($subseriesIndexColumn && $subIndex !== null) {
                    $pdo->prepare("INSERT INTO $subseriesLinkTable (book, value, $subseriesIndexColumn) VALUES (:book, :val, :idx)")
                        ->execute([':book' => $id, ':val' => $subId, ':idx' => $subIndex]);
                } else {
                    $pdo->prepare("INSERT INTO $subseriesLinkTable (book, value) VALUES (:book, :val)")
                        ->execute([':book' => $id, ':val' => $subId]);
                }
            } else {
                $stmt = $pdo->prepare('INSERT OR IGNORE INTO subseries (name, sort) VALUES (:name, :sort)');
                $stmt->execute([':name' => $subseriesNameInput, ':sort' => $subseriesNameInput]);
                $stmt = $pdo->prepare('SELECT id FROM subseries WHERE name = :name');
                $stmt->execute([':name' => $subseriesNameInput]);
                $subId = (int)$stmt->fetchColumn();
                $pdo->prepare('DELETE FROM books_subseries_link WHERE book = :book')
                    ->execute([':book' => $id]);
                $pdo->prepare('INSERT OR REPLACE INTO books_subseries_link (book, subseries) VALUES (:book, :sub)')
                    ->execute([':book' => $id, ':sub' => $subId]);
                if ($subseriesIndexExists) {
                    $pdo->prepare('UPDATE books SET subseries_index = :idx WHERE id = :id')
                        ->execute([':idx' => $subIndex, ':id' => $id]);
                }
            }
        }
    }

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
            $libraryPath = rtrim(getLibraryPath(), '/');
            $oldFullPath = $oldPath !== '' ? $libraryPath . '/' . $oldPath : '';
            $newFullPath = $libraryPath . '/' . $newPath;
            $newAuthorDir = dirname($newFullPath);

            // Create target author directory if needed
            if (!is_dir($newAuthorDir)) {
                mkdir($newAuthorDir, 0777, true);
            }

            // Move existing directory if present
            if ($oldFullPath !== '' && is_dir($oldFullPath)) {
                rename($oldFullPath, $newFullPath);

                // Remove old author directory if empty
                $oldAuthorDir = $oldAuthorFolder !== '' ? $libraryPath . '/' . $oldAuthorFolder : '';
                if ($oldAuthorDir !== '' && is_dir($oldAuthorDir)) {
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

// Prepare subseries fields if available
$subseriesSelect = '';
$subseriesJoin = '';
if ($hasSubseries) {
    if ($subseriesIsCustom) {
        $idxExpr = $subseriesIndexColumn ? "bssl.$subseriesIndexColumn" : 'NULL';
        $subseriesSelect = ", $idxExpr AS subseries_index, ss.id AS subseries_id, ss.value AS subseries";
        $subseriesJoin = " LEFT JOIN $subseriesLinkTable bssl ON bssl.book = b.id LEFT JOIN $subseriesValueTable ss ON bssl.value = ss.id";
    } else {
        $idxExpr = $subseriesIndexExists ? 'b.subseries_index' : 'NULL';
        $subseriesSelect = ", $idxExpr AS subseries_index, ss.id AS subseries_id, ss.name AS subseries";
        $subseriesJoin = " LEFT JOIN books_subseries_link bssl ON bssl.book = b.id LEFT JOIN subseries ss ON bssl.subseries = ss.id";
    }
}

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
            LIMIT 1) AS publisher" . $subseriesSelect . "
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id" . $subseriesJoin . "
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
$epubFileRel = '';
if (!$missingFile && $book['path'] !== '') {
    $epubPath = findBookFileByExtension($book['path'], 'epub');
    if ($epubPath !== null) {
        $epubFileRel = $epubPath;
    }
}
$pdfExists = false;
if ($epubFileRel !== '') {
    $libraryBasePath = rtrim(getLibraryPath(), '/');
    $epubFullPath = $libraryBasePath . '/' . ltrim($epubFileRel, '/');
    $pdfFullPath = dirname($epubFullPath) . '/' . pathinfo($epubFullPath, PATHINFO_FILENAME) . '.pdf';
    if (file_exists($pdfFullPath)) {
        $pdfExists = true;
    }
}

if ($sendRequested) {
    $remoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
    $device    = getUserPreference(currentUser(), 'DEVICE', getPreference('DEVICE', ''));
    if ($remoteDir === '' || $device === '') {
        $sendMessage = ['type' => 'danger', 'text' => 'Remote device not configured.'];
    } elseif ($ebookFileRel === '') {
        $sendMessage = ['type' => 'danger', 'text' => 'No book file to send.'];
    } else {
        try {
            $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
            $valTable = "custom_column_{$genreId}";
            $linkTable = "books_custom_column_{$genreId}_link";
            $gstmt = $pdo->prepare("SELECT gv.value FROM $linkTable l JOIN $valTable gv ON l.value = gv.id WHERE l.book = ? LIMIT 1");
            $gstmt->execute([$id]);
            $genre = $gstmt->fetchColumn() ?: 'Unknown';
        } catch (PDOException $e) {
            $genre = 'Unknown';
        }
        $author = trim(explode(',', $book['authors'])[0] ?? '');
        if ($author === '') { $author = 'Unknown'; }

        $genreDir  = safe_filename($genre);
        if ($genreDir === '') { $genreDir = 'Unknown'; }
        $authorDir = safe_filename($author);
        if ($authorDir === '') { $authorDir = 'Unknown'; }
        $seriesDir = '';
        $series = trim($book['series'] ?? '');
        if ($series !== '') {
            $seriesDir = '/' . safe_filename($series);
        }
        $remotePath = rtrim($remoteDir, '/') . '/' . $genreDir . '/' . $authorDir . $seriesDir;

        $localFile = getLibraryPath() . '/' . $ebookFileRel;
        $baseName  = basename($ebookFileRel);
        $nameOnly  = pathinfo($baseName, PATHINFO_FILENAME);
        $ext       = pathinfo($baseName, PATHINFO_EXTENSION);
        $remoteFileName = safe_filename($nameOnly);
        if ($remoteFileName === '') { $remoteFileName = 'book'; }
        if ($series !== '' && $book['series_index'] !== null && $book['series_index'] !== '') {
            $seriesIdxStr = (string)$book['series_index'];
            if (strpos($seriesIdxStr, '.') !== false) {
                [$whole, $decimal] = explode('.', $seriesIdxStr, 2);
                $seriesIdxStr = str_pad($whole, 2, '0', STR_PAD_LEFT);
                $decimal = rtrim($decimal, '0');
                if ($decimal !== '') {
                    $seriesIdxStr .= '.' . $decimal;
                }
            } else {
                $seriesIdxStr = str_pad($seriesIdxStr, 2, '0', STR_PAD_LEFT);
            }
            $remoteFileName = $seriesIdxStr . ' - ' . $remoteFileName;
        }
        if ($ext !== '') { $remoteFileName .= '.' . $ext; }

        $identity  = '/home/david/.ssh/id_rsa';
        $sshTarget = 'root@' . $device;

        $mkdirCmd = sprintf(
            'ssh -i %s %s %s',
            escapeshellarg($identity),
            escapeshellarg($sshTarget),
            escapeshellarg('mkdir -p ' . escapeshellarg($remotePath))
        );
        exec($mkdirCmd, $out1, $ret1);

        $scpCmd = sprintf(
            'scp -i %s %s %s:%s',
            escapeshellarg($identity),
            escapeshellarg($localFile),
            escapeshellarg($sshTarget),
            escapeshellarg($remotePath . '/' . $remoteFileName)
        );
        exec($scpCmd, $out2, $ret2);

        if ($ret1 === 0 && $ret2 === 0) {
            $sendMessage = ['type' => 'success', 'text' => 'Book sent to device.'];
        } else {
            $cmds = $mkdirCmd . '; ' . $scpCmd;
            $sendMessage = [
                'type' => 'danger',
                'text' => 'Failed to send book to device. Commands: ' . $cmds,
            ];
        }
    }
}
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
    <script src="node_modules/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
    <style>
        #description { min-height: 200px; resize: vertical; }
    </style>
    <script>
        tinymce.init({
            selector: '#description',
            license_key: 'gpl',
            promotion: false,
            branding: false,
            height: 400
        });
    </script>
</head>
<body class="pt-5" data-book-id="<?= (int)$book['id'] ?>" data-search-query="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>"<?php if($ebookFileRel): ?> data-ebook-file="<?= htmlspecialchars($ebookFileRel) ?>"<?php endif; ?><?php if(!empty($book['isbn'])): ?> data-isbn="<?= htmlspecialchars($book['isbn']) ?>"<?php endif; ?>>
<?php include "navbar_other.php"; ?>
<div class="container my-4">
    <a href="<?= htmlspecialchars($backToListUrl) ?>" class="btn btn-secondary mb-3">
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
        <?php if ($epubFileRel && !$pdfExists): ?>
            <form method="post" class="btn-group me-2 mb-2">
                <input type="hidden" name="convert_to_pdf" value="1">
                <button type="submit" class="btn btn-secondary">
                    <i class="fa-solid fa-file-pdf me-1"></i> Convert to PDF
                </button>
            </form>
        <?php endif; ?>
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
                        <img id="coverImagePreview" src="<?= htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') ?>" alt="Cover" class="card-img-top img-thumbnail">
                        <div id="coverDimensions" class="position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 1.2rem;">Loading...</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-muted">No cover</div>
            <?php endif; ?>

            <?php if ($ebookFileRel): ?>
                <button type="button" id="extractCoverBtn" class="btn btn-secondary mb-4">Extract Cover</button>
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
                    <?php if (!empty($book['series']) || !empty($book['subseries'])): ?>
                        <?php if (!empty($book['series'])): ?>
                            <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                                <?= htmlspecialchars($book['series']) ?>
                            </a>
                            <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                                (<?= htmlspecialchars($book['series_index']) ?>)
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($book['subseries'])): ?>
                            <?php if (!empty($book['series'])): ?>&gt; <?php endif; ?>
                            <?= htmlspecialchars($book['subseries']) ?>
                            <?php if ($book['subseries_index'] !== null && $book['subseries_index'] !== ''): ?>
                                (<?= htmlspecialchars($book['subseries_index']) ?>)
                            <?php endif; ?>
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
                    <?php if ($conversionMessage): ?>
                        <div class="alert alert-<?= htmlspecialchars($conversionMessage['type']) ?>">
                            <i class="fa-solid <?= $conversionMessage['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
                            <?= htmlspecialchars($conversionMessage['text']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($sendMessage): ?>
                        <div class="alert alert-<?= htmlspecialchars($sendMessage['type']) ?>">
                            <i class="fa-solid <?= $sendMessage['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
                            <?= htmlspecialchars($sendMessage['text']) ?>
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
                                <div class="mb-3 position-relative">
                                    <label for="seriesInput" class="form-label">
                                        <i class="fa-solid fa-layer-group me-1 text-primary"></i> Series
                                    </label>
                                    <input type="text" id="seriesInput" name="series" value="<?= htmlspecialchars($book['series']) ?>" class="form-control" autocomplete="off">
                                    <ul id="seriesSuggestions" class="list-group position-absolute w-100" style="z-index:1000; display:none;"></ul>
                                </div>
                                <div class="mb-3">
                                    <label for="seriesIndex" class="form-label">
                                        <i class="fa-solid fa-hashtag me-1 text-primary"></i> Number in Series
                                    </label>
                                    <input type="number" step="0.1" id="seriesIndex" name="series_index" value="<?= htmlspecialchars($book['series_index']) ?>" class="form-control">
                                </div>
                                <?php if ($hasSubseries): ?>
                                <div class="mb-3 position-relative">
                                    <label for="subseriesInput" class="form-label">
                                        <i class="fa-solid fa-diagram-project me-1 text-primary"></i> Subseries
                                    </label>
                                    <input type="text" id="subseriesInput" name="subseries" value="<?= htmlspecialchars($book['subseries'] ?? '') ?>" class="form-control" autocomplete="off">
                                    <ul id="subseriesSuggestions" class="list-group position-absolute w-100" style="z-index:1000; display:none;"></ul>
                                </div>
                                <?php if ($subseriesIndexExists): ?>
                                <div class="mb-3">
                                    <label for="subseriesIndex" class="form-label">
                                        <i class="fa-solid fa-hashtag me-1 text-primary"></i> Number in Subseries
                                    </label>
                                    <input type="number" step="0.1" id="subseriesIndex" name="subseries_index" value="<?= htmlspecialchars($book['subseries_index'] ?? '') ?>" class="form-control">
                                </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <button type="button" id="swapSeriesSubseriesBtn" class="btn btn-outline-secondary">
                                        <i class="fa-solid fa-right-left me-1"></i> Swap Series/Subseries
                                    </button>
                                </div>
                                <?php endif; ?>
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
                                <a href="<?= htmlspecialchars($backToListUrl) ?>" class="btn btn-secondary">
                                    <i class="fa-solid fa-arrow-left me-1"></i> Back to list
                                </a>
                                <a href="list_books.php?search=<?= urlencode($book['title']) ?>&source=local" class="btn btn-secondary ms-2">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i> Back to book
                                </a>
                            </div>
                            <div>
                                <?php if ($ebookFileRel): ?>
                                    <button type="submit" name="send_to_device" value="1" class="btn btn-outline-success me-2">
                                        <i class="fa-solid fa-paper-plane me-1"></i> Send to device
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-save me-1"></i> Save
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>



    <?php include 'metadata_modal.php'; ?>
    <?php include 'cover_modal.php'; ?>
  </div>
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/book.js"></script>
</body>
</html>
