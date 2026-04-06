<?php
/**
 * Handles the metadata save POST action for book.php.
 *
 * Expects in scope: $pdo, $id, $book, $hasSubseries, $subseriesIsCustom,
 *                   $subseriesLinkTable, $subseriesValueTable,
 *                   $subseriesIndexColumn, $subseriesIndexExists
 * Sets in scope:    $updated, $fsWarning, $description, $notes
 */

function copyDirRecursive(string $src, string $dst): bool {
    if (!mkdir($dst, 0777, true) && !is_dir($dst)) return false;
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) { if (!copyDirRecursive($s, $d)) return false; }
        else { if (!copy($s, $d)) return false; }
    }
    return true;
}

function deleteDirRecursive(string $dir): bool {
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) deleteDirRecursive($path);
        else unlink($path);
    }
    return rmdir($dir);
}

try {
    $title              = $_POST['title'] ?? '';
    $authorsInput       = trim($_POST['authors'] ?? '');
    $authorSortInput    = trim($_POST['author_sort'] ?? '');
    $descriptionInput   = trim($_POST['description'] ?? '');
    $seriesNameInput    = trim($_POST['series'] ?? '');
    $seriesIndexInput   = trim($_POST['series_index'] ?? '');
    $subseriesNameInput = trim($_POST['subseries'] ?? '');
    $subseriesIndexInput = trim($_POST['subseries_index'] ?? '');
    $publisherInput     = trim($_POST['publisher'] ?? '');
    $pubdateInput       = trim($_POST['pubdate'] ?? '');
    $isbnInput          = trim($_POST['isbn'] ?? '');
    $olidInput          = trim($_POST['olid'] ?? '');
    $olNotFound         = !empty($_POST['ol_not_found']);
    $langInput          = strtolower(trim($_POST['language'] ?? ''));
    $notesInput         = trim($_POST['notes'] ?? '');

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE books SET title = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$title, $id]);

    if ($authorsInput !== '') {
        $authorsList = preg_split('/\s*(?:,|;| and )\s*/i', $authorsInput);
        $authorsList = array_filter(array_map('trim', $authorsList), 'strlen');
        if (empty($authorsList)) {
            $authorsList = [$authorsInput];
        }
        $primaryAuthor = $authorsList[0];
        $insertAuthor  = $pdo->prepare(
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
                $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (:book, :author)')
                    ->execute([':book' => $id, ':author' => $aid]);
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
        $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text)
                       ON CONFLICT(book) DO UPDATE SET text = excluded.text')
            ->execute([':book' => $id, ':text' => $descriptionInput]);
    } else {
        $pdo->prepare('DELETE FROM comments WHERE book = ?')->execute([$id]);
    }

    // Update notes custom column
    try {
        $notesId        = ensureSingleValueColumn($pdo, '#notes', 'Notes');
        $notesValTable  = "custom_column_{$notesId}";
        $notesLinkTable = "books_custom_column_{$notesId}_link";
        $pdo->prepare("DELETE FROM $notesLinkTable WHERE book = :book")->execute([':book' => $id]);
        if ($notesInput !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO $notesValTable (value) VALUES (:val)")->execute([':val' => $notesInput]);
            $valId = $pdo->prepare("SELECT id FROM $notesValTable WHERE value = :val");
            $valId->execute([':val' => $notesInput]);
            $valId = $valId->fetchColumn();
            if ($valId !== false) {
                $pdo->prepare("INSERT INTO $notesLinkTable (book, value) VALUES (:book, :val)")
                    ->execute([':book' => $id, ':val' => $valId]);
            }
        }
    } catch (PDOException $e) {}

    // Series
    $seriesIndex = $seriesIndexInput !== '' ? (float)$seriesIndexInput : (float)$book['series_index'];
    if ($seriesNameInput === '') {
        $pdo->prepare('DELETE FROM books_series_link WHERE book = :book')->execute([':book' => $id]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM series WHERE name = :name');
        $stmt->execute([':name' => $seriesNameInput]);
        $seriesId = $stmt->fetchColumn();
        if ($seriesId === false) {
            $stmt = $pdo->prepare('INSERT INTO series (name, sort) VALUES (:name, :sort)');
            $stmt->execute([':name' => $seriesNameInput, ':sort' => $seriesNameInput]);
            $seriesId = $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM books_series_link WHERE book = :book')->execute([':book' => $id]);
        $pdo->prepare('INSERT OR REPLACE INTO books_series_link (book, series) VALUES (:book, :series)')
            ->execute([':book' => $id, ':series' => $seriesId]);
    }
    $pdo->prepare('UPDATE books SET series_index = :idx WHERE id = :id')
        ->execute([':idx' => $seriesIndex, ':id' => $id]);

    // Subseries
    if ($hasSubseries) {
        $subIndex = $subseriesIndexExists
            ? ($subseriesIndexInput !== '' ? (float)$subseriesIndexInput : ($book['subseries_index'] !== null ? (float)$book['subseries_index'] : null))
            : null;
        if ($subseriesNameInput === '') {
            if ($subseriesIsCustom) {
                $pdo->prepare("DELETE FROM $subseriesLinkTable WHERE book = :book")->execute([':book' => $id]);
            } else {
                $pdo->prepare('DELETE FROM books_subseries_link WHERE book = :book')->execute([':book' => $id]);
                if ($subseriesIndexExists) {
                    $pdo->prepare('UPDATE books SET subseries_index = NULL WHERE id = :id')->execute([':id' => $id]);
                }
            }
        } else {
            if ($subseriesIsCustom) {
                $pdo->prepare("INSERT OR IGNORE INTO $subseriesValueTable (value) VALUES (:val)")->execute([':val' => $subseriesNameInput]);
                $stmt = $pdo->prepare("SELECT id FROM $subseriesValueTable WHERE value = :val");
                $stmt->execute([':val' => $subseriesNameInput]);
                $subId = (int)$stmt->fetchColumn();
                $pdo->prepare("DELETE FROM $subseriesLinkTable WHERE book = :book")->execute([':book' => $id]);
                if ($subseriesIndexColumn && $subIndex !== null) {
                    $pdo->prepare("INSERT INTO $subseriesLinkTable (book, value, $subseriesIndexColumn) VALUES (:book, :val, :idx)")
                        ->execute([':book' => $id, ':val' => $subId, ':idx' => $subIndex]);
                } else {
                    $pdo->prepare("INSERT INTO $subseriesLinkTable (book, value) VALUES (:book, :val)")
                        ->execute([':book' => $id, ':val' => $subId]);
                }
            } else {
                $pdo->prepare('INSERT OR IGNORE INTO subseries (name, sort) VALUES (:name, :sort)')
                    ->execute([':name' => $subseriesNameInput, ':sort' => $subseriesNameInput]);
                $stmt = $pdo->prepare('SELECT id FROM subseries WHERE name = :name');
                $stmt->execute([':name' => $subseriesNameInput]);
                $subId = (int)$stmt->fetchColumn();
                $pdo->prepare('DELETE FROM books_subseries_link WHERE book = :book')->execute([':book' => $id]);
                $pdo->prepare('INSERT OR REPLACE INTO books_subseries_link (book, subseries) VALUES (:book, :sub)')
                    ->execute([':book' => $id, ':sub' => $subId]);
                if ($subseriesIndexExists) {
                    $pdo->prepare('UPDATE books SET subseries_index = :idx WHERE id = :id')
                        ->execute([':idx' => $subIndex, ':id' => $id]);
                }
            }
        }
    }

    // Publisher
    if ($publisherInput !== '') {
        $pdo->prepare('INSERT OR IGNORE INTO publishers(name) VALUES (?)')->execute([$publisherInput]);
        $pdo->prepare('DELETE FROM books_publishers_link WHERE book=?')->execute([$id]);
        $pdo->prepare('INSERT INTO books_publishers_link(book,publisher) SELECT ?, id FROM publishers WHERE name=?')
            ->execute([$id, $publisherInput]);
    } else {
        $pdo->prepare('DELETE FROM books_publishers_link WHERE book=?')->execute([$id]);
    }

    // Publication date
    if ($pubdateInput !== '') {
        $pubDate = preg_match('/^\d{4}$/', $pubdateInput) ? $pubdateInput . '-01-01' : $pubdateInput;
        $pdo->prepare('UPDATE books SET pubdate=? WHERE id=?')->execute([$pubDate, $id]);
    }

    // ISBN
    $pdo->prepare('UPDATE books SET isbn=? WHERE id=?')->execute([$isbnInput, $id]);
    $pdo->prepare('DELETE FROM identifiers WHERE book=? AND type="isbn"')->execute([$id]);
    if ($isbnInput !== '') {
        $pdo->prepare('INSERT INTO identifiers (book, type, val) VALUES (?, "isbn", ?)')->execute([$id, $isbnInput]);
    }

    // Open Library Work ID
    $pdo->prepare('DELETE FROM identifiers WHERE book=? AND type="olid"')->execute([$id]);
    if ($olNotFound) {
        $pdo->prepare('INSERT INTO identifiers (book, type, val) VALUES (?, "olid", "NOT_FOUND")')->execute([$id]);
    } elseif ($olidInput !== '') {
        $pdo->prepare('INSERT INTO identifiers (book, type, val) VALUES (?, "olid", ?)')->execute([$id, $olidInput]);
    }

    // Language
    $pdo->prepare('DELETE FROM books_languages_link WHERE book=?')->execute([$id]);
    if ($langInput !== '') {
        $pdo->prepare('INSERT OR IGNORE INTO languages (lang_code) VALUES (?)')->execute([$langInput]);
        $pdo->prepare('INSERT INTO books_languages_link (book, lang_code, item_order) SELECT ?, id, 0 FROM languages WHERE lang_code=?')
            ->execute([$id, $langInput]);
    }

    // Filesystem rename (if author changed)
    $fsRename = null;
    if ($authorsInput !== '') {
        $oldPath         = $book['path'];
        $oldBookFolder   = $oldPath !== '' ? basename($oldPath) : safe_filename($title) . ' (' . $id . ')';
        $oldAuthorFolder = $oldPath !== '' ? dirname($oldPath) : '';
        $newAuthorFolder = safe_filename($primaryAuthor);
        $newPath         = $newAuthorFolder . '/' . $oldBookFolder;

        if ($newPath !== $oldPath) {
            $libraryPath    = rtrim(getLibraryPath(), '/');
            $safeRoot       = $libraryPath . '/';
            $oldFullPath    = $oldPath !== '' ? $libraryPath . '/' . $oldPath : '';
            $newFullPath    = $libraryPath . '/' . $newPath;
            $newAuthorDir   = dirname($newFullPath);

            $resolvedParent = realpath($newAuthorDir) ?: $newAuthorDir;
            if (!str_starts_with(rtrim($resolvedParent, '/') . '/', $safeRoot)) {
                throw new RuntimeException("Refusing rename: destination escapes library root.");
            }
            if (is_dir($newFullPath) && count(array_diff(scandir($newFullPath) ?: [], ['.', '..'])) > 0) {
                throw new RuntimeException("Refusing rename: destination directory already exists and is non-empty ($newPath).");
            }

            $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$newPath, $id]);
            $book['path'] = $newPath;

            $fsRename = [
                'oldFull'      => $oldFullPath,
                'newFull'      => $newFullPath,
                'newAuthorDir' => $newAuthorDir,
                'oldAuthorDir' => $oldAuthorFolder !== '' ? $libraryPath . '/' . $oldAuthorFolder : '',
            ];
        }
    }

    // Cover upload
    if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        $libraryPath = getLibraryPath();
        $destDir     = $libraryPath . '/' . $book['path'];
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        move_uploaded_file($_FILES['cover']['tmp_name'], $destDir . '/cover.jpg');
        $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$id]);
    }

    $pdo->commit();

    // Filesystem rename (post-commit)
    $fsWarning = null;
    if ($fsRename !== null) {
        [
            'oldFull'      => $oldFullPath,
            'newFull'      => $newFullPath,
            'newAuthorDir' => $newAuthorDir,
            'oldAuthorDir' => $oldAuthorDir,
        ] = $fsRename;

        if (!is_dir($newAuthorDir)) {
            mkdir($newAuthorDir, 0777, true);
        }
        if ($oldFullPath !== '' && is_dir($oldFullPath)) {
            $moved = rename($oldFullPath, $newFullPath);
            if (!$moved) {
                // Fallback: recursive copy then delete (handles cross-permission rename failures)
                $moved = copyDirRecursive($oldFullPath, $newFullPath)
                      && deleteDirRecursive($oldFullPath);
            }
            if (!$moved) {
                $phpErr = error_get_last()['message'] ?? 'unknown error';
                $fsWarning = "Database updated but could not move directory on disk: "
                    . htmlspecialchars($oldFullPath) . " → " . htmlspecialchars($newFullPath)
                    . ". Error: " . htmlspecialchars($phpErr) . ". Please move it manually.";
            } else {
                if ($oldAuthorDir !== '' && is_dir($oldAuthorDir)) {
                    $entries = array_diff(scandir($oldAuthorDir) ?: [], ['.', '..']);
                    if (count($entries) === 0) {
                        rmdir($oldAuthorDir);
                    }
                }
            }
        } elseif (!is_dir($newFullPath)) {
            mkdir($newFullPath, 0777, true);
        }
    }

    $updated     = true;
    $description = $descriptionInput;
    $notes       = $notesInput;

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $fsWarning = htmlspecialchars($e->getMessage());
}
