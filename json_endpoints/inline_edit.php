<?php
/**
 * Inline edit endpoint for the simple list view.
 * Handles title, author, and series edits with filesystem rename support.
 *
 * POST params: book_id, field (title|author|series), value, [series_index]
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) $name = $t;
    }
    $name = preg_replace('/[^A-Za-z0-9 _.\'-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

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

$bookId      = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$field       = $_POST['field'] ?? '';
$value       = trim($_POST['value'] ?? '');
$seriesIndex = trim($_POST['series_index'] ?? '');

if ($bookId <= 0 || !in_array($field, ['title', 'author', 'series', 'subseries', 'goodreads'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDatabaseConnection();

// Subseries configuration (mirrors book.php setup)
$hasSubseries        = false;
$subseriesIsCustom   = false;
$subseriesLinkTable  = '';
$subseriesValueTable = '';
$subseriesIndexColumn = null;
$subseriesIndexExists = false;
try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $hasSubseries        = true;
        $subseriesIsCustom   = true;
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
        $subTable     = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) {
            $hasSubseries = true;
            foreach ($pdo->query('PRAGMA table_info(books)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if ($col['name'] === 'subseries_index') { $subseriesIndexExists = true; break; }
            }
        }
    }
} catch (PDOException $e) {}

$bookStmt = $pdo->prepare('SELECT id, title, path, author_sort FROM books WHERE id = ?');
$bookStmt->execute([$bookId]);
$book = $bookStmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    http_response_code(404);
    echo json_encode(['error' => 'Book not found']);
    exit;
}

$response  = ['status' => 'ok'];
$fsRename  = null;
$fsWarning = null;

try {
    $pdo->beginTransaction();

    if ($field === 'title') {
        $pdo->prepare('UPDATE books SET title = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$value, $bookId]);
        $response['title'] = $value;

    } elseif ($field === 'author') {
        if ($value === '') throw new RuntimeException('Author cannot be empty');

        $authorsList = preg_split('/\s*(?:,|;| and )\s*/i', $value);
        $authorsList = array_values(array_filter(array_map('trim', $authorsList), 'strlen'));
        if (empty($authorsList)) $authorsList = [$value];
        $primaryAuthor = $authorsList[0];

        $insertAuthor = $pdo->prepare(
            'INSERT INTO authors (name, sort)
             VALUES (:name, author_sort(:name))
             ON CONFLICT(name) DO UPDATE SET sort = excluded.sort'
        );
        foreach ($authorsList as $a) {
            $insertAuthor->execute([':name' => $a]);
        }
        $pdo->prepare('DELETE FROM books_authors_link WHERE book = ?')->execute([$bookId]);
        foreach ($authorsList as $a) {
            $aid = $pdo->query('SELECT id FROM authors WHERE name=' . $pdo->quote($a))->fetchColumn();
            if ($aid !== false) {
                $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (?, ?)')->execute([$bookId, $aid]);
            }
        }
        $sortStmt = $pdo->prepare('SELECT author_sort(:name)');
        $sortStmt->execute([':name' => $primaryAuthor]);
        $authorSort = (string)$sortStmt->fetchColumn();
        $pdo->prepare('UPDATE books SET author_sort = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$authorSort, $bookId]);

        // Filesystem rename — identical logic to save_metadata.php
        $oldPath       = $book['path'];
        $oldBookFolder = $oldPath !== '' ? basename($oldPath) : safe_filename($book['title']) . ' (' . $bookId . ')';
        $oldAuthorDir  = $oldPath !== '' ? dirname($oldPath) : '';
        $newAuthorFolder = safe_filename($primaryAuthor);
        $newPath       = $newAuthorFolder . '/' . $oldBookFolder;

        if ($newPath !== $oldPath) {
            $libraryPath  = rtrim(getLibraryPath(), '/');
            $safeRoot     = $libraryPath . '/';
            $oldFullPath  = $oldPath !== '' ? $libraryPath . '/' . $oldPath : '';
            $newFullPath  = $libraryPath . '/' . $newPath;
            $newAuthorFullDir = dirname($newFullPath);

            $resolvedParent = realpath($newAuthorFullDir) ?: $newAuthorFullDir;
            if (!str_starts_with(rtrim($resolvedParent, '/') . '/', $safeRoot)) {
                throw new RuntimeException('Refusing rename: destination escapes library root.');
            }
            if (is_dir($newFullPath) && count(array_diff(scandir($newFullPath) ?: [], ['.', '..'])) > 0) {
                throw new RuntimeException('Refusing rename: destination directory already exists and is non-empty.');
            }

            $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$newPath, $bookId]);

            $fsRename = [
                'oldFull'      => $oldFullPath,
                'newFull'      => $newFullPath,
                'newAuthorDir' => $newAuthorFullDir,
                'oldAuthorDir' => $oldAuthorDir !== '' ? $libraryPath . '/' . $oldAuthorDir : '',
            ];
        }

        // Re-fetch authors for response
        $authRows = $pdo->prepare(
            'SELECT a.id, a.name FROM authors a
             JOIN books_authors_link l ON a.id = l.author
             WHERE l.book = ? ORDER BY l.id'
        );
        $authRows->execute([$bookId]);
        $rows = $authRows->fetchAll(PDO::FETCH_ASSOC);
        $response['authors']    = implode('|', array_column($rows, 'name'));
        $response['author_ids'] = implode('|', array_column($rows, 'id'));

    } elseif ($field === 'series') {
        $pdo->prepare('DELETE FROM books_series_link WHERE book = ?')->execute([$bookId]);

        if ($value !== '') {
            $stmt = $pdo->prepare('SELECT id FROM series WHERE name = ?');
            $stmt->execute([$value]);
            $seriesId = $stmt->fetchColumn();
            if ($seriesId === false) {
                $pdo->prepare('INSERT INTO series (name, sort) VALUES (?, ?)')->execute([$value, $value]);
                $seriesId = (int)$pdo->lastInsertId();
            }
            $idx = $seriesIndex !== '' ? (float)$seriesIndex : 1.0;
            $pdo->prepare('INSERT OR REPLACE INTO books_series_link (book, series) VALUES (?, ?)')->execute([$bookId, $seriesId]);
            $pdo->prepare('UPDATE books SET series_index = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$idx, $bookId]);
            $response['series']       = $value;
            $response['series_id']    = (int)$seriesId;
            $response['series_index'] = $idx;
        } else {
            $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookId]);
            $response['series']       = '';
            $response['series_id']    = null;
            $response['series_index'] = null;
        }

    } elseif ($field === 'goodreads') {
        if ($value !== '') {
            $pdo->prepare("INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'goodreads', ?)")
                ->execute([$bookId, $value]);
        } else {
            $pdo->prepare("DELETE FROM identifiers WHERE book = ? AND type = 'goodreads'")
                ->execute([$bookId]);
        }
        $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookId]);
        $response['goodreads'] = $value;
        $response['gr_unmark'] = true; // signal JS to call gr_unmark_done

    } elseif ($field === 'subseries') {
        if (!$hasSubseries) throw new RuntimeException('Subseries not configured');
        $idx = $seriesIndex !== '' ? (float)$seriesIndex : null;

        if ($subseriesIsCustom) {
            $pdo->prepare("DELETE FROM $subseriesLinkTable WHERE book = ?")->execute([$bookId]);
            if ($value !== '') {
                $pdo->prepare("INSERT OR IGNORE INTO $subseriesValueTable (value) VALUES (?)")->execute([$value]);
                $subIdStmt = $pdo->prepare("SELECT id FROM $subseriesValueTable WHERE value = ?");
                $subIdStmt->execute([$value]);
                $subId = (int)$subIdStmt->fetchColumn();
                if ($subseriesIndexColumn && $idx !== null) {
                    $pdo->prepare("INSERT INTO $subseriesLinkTable (book, value, $subseriesIndexColumn) VALUES (?, ?, ?)")->execute([$bookId, $subId, $idx]);
                } else {
                    $pdo->prepare("INSERT INTO $subseriesLinkTable (book, value) VALUES (?, ?)")->execute([$bookId, $subId]);
                }
            }
        } else {
            $pdo->prepare('DELETE FROM books_subseries_link WHERE book = ?')->execute([$bookId]);
            if ($value !== '') {
                $pdo->prepare('INSERT OR IGNORE INTO subseries (name, sort) VALUES (?, ?)')->execute([$value, $value]);
                $subIdStmt = $pdo->prepare('SELECT id FROM subseries WHERE name = ?');
                $subIdStmt->execute([$value]);
                $subId = (int)$subIdStmt->fetchColumn();
                $pdo->prepare('INSERT OR REPLACE INTO books_subseries_link (book, subseries) VALUES (?, ?)')->execute([$bookId, $subId]);
            }
            if ($subseriesIndexExists) {
                $pdo->prepare('UPDATE books SET subseries_index = ? WHERE id = ?')->execute([$value !== '' ? $idx : null, $bookId]);
            }
        }
        $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')->execute([$bookId]);
        $response['subseries']       = $value;
        $response['subseries_index'] = $idx;
    }

    $pdo->commit();

    // Filesystem rename post-commit (author change only)
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
                $moved = copyDirRecursive($oldFullPath, $newFullPath)
                      && deleteDirRecursive($oldFullPath);
            }
            if (!$moved) {
                $phpErr = error_get_last()['message'] ?? 'unknown error';
                $fsWarning = 'Database updated but could not move directory: '
                    . $oldFullPath . ' → ' . $newFullPath . '. Error: ' . $phpErr;
            } else {
                if ($oldAuthorDir !== '' && is_dir($oldAuthorDir)) {
                    $entries = array_diff(scandir($oldAuthorDir) ?: [], ['.', '..']);
                    if (count($entries) === 0) rmdir($oldAuthorDir);
                }
            }
        } elseif (!is_dir($newFullPath)) {
            mkdir($newFullPath, 0777, true);
        }
    }

    if ($fsWarning) $response['fs_warning'] = $fsWarning;
    echo json_encode($response);

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
