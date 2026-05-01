<?php
/**
 * Merge one or more author records into a single "keep" author.
 * Moves books_authors_link entries, updates books.path + author_sort,
 * attempts filesystem directory moves.
 *
 * POST: keep_id=<int>  merge_ids[]=<int> ...
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

function safe_filename(string $name, int $max = 150): string {
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) $name = $t;
    }
    $name = preg_replace('/[^A-Za-z0-9 _.\'-]/', '', $name);
    return substr(trim($name), 0, $max);
}

function copyDirRecursive(string $src, string $dst): bool {
    if (!mkdir($dst, 0777, true) && !is_dir($dst)) return false;
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item; $d = $dst . '/' . $item;
        if (is_dir($s)) { if (!copyDirRecursive($s, $d)) return false; }
        else { if (!copy($s, $d)) return false; }
    }
    return true;
}

function deleteDirRecursive(string $dir): bool {
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        if (is_dir($p)) deleteDirRecursive($p); else unlink($p);
    }
    return rmdir($dir);
}

$keepId   = isset($_POST['keep_id']) ? (int)$_POST['keep_id'] : 0;
$mergeIds = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['merge_ids'] ?? [])),
    fn($id) => $id > 0 && $id !== $keepId
)));

if ($keepId <= 0 || empty($mergeIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDatabaseConnection();

$keepRow = $pdo->prepare('SELECT id, name FROM authors WHERE id = ?');
$keepRow->execute([$keepId]);
$keepAuthor = $keepRow->fetch(PDO::FETCH_ASSOC);
if (!$keepAuthor) {
    http_response_code(404);
    echo json_encode(['error' => 'Keep author not found']);
    exit;
}

$in = implode(',', array_fill(0, count($mergeIds), '?'));
$mergeStmt = $pdo->prepare("SELECT id, name FROM authors WHERE id IN ($in)");
$mergeStmt->execute($mergeIds);
$mergeMap = [];
foreach ($mergeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mergeMap[(int)$r['id']] = $r['name'];
}
if (count($mergeMap) !== count($mergeIds)) {
    http_response_code(404);
    echo json_encode(['error' => 'One or more merge authors not found']);
    exit;
}

$libraryPath = rtrim(getLibraryPath(), '/') . '/';
$keepDirName = safe_filename($keepAuthor['name']);
$fsWarnings  = [];
$booksMoved  = 0;

// Collect filesystem moves to do after commit
// keyed by bookId: [oldFull, newFull, oldAuthorFull]
$fsMoves = [];

try {
    $pdo->beginTransaction();

    foreach ($mergeIds as $mergeId) {
        $mergeDirName = safe_filename($mergeMap[$mergeId]);

        $booksStmt = $pdo->prepare(
            'SELECT b.id, b.path FROM books b
             JOIN books_authors_link bal ON bal.book = b.id
             WHERE bal.author = ?'
        );
        $booksStmt->execute([$mergeId]);
        $books = $booksStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($books as $book) {
            $bookId  = (int)$book['id'];
            $oldPath = $book['path'] ?? '';

            // Redirect or drop the author link
            $linked = $pdo->prepare(
                'SELECT 1 FROM books_authors_link WHERE book = ? AND author = ?'
            );
            $linked->execute([$bookId, $keepId]);
            if ($linked->fetchColumn()) {
                $pdo->prepare('DELETE FROM books_authors_link WHERE book = ? AND author = ?')
                    ->execute([$bookId, $mergeId]);
            } else {
                $pdo->prepare('UPDATE books_authors_link SET author = ? WHERE book = ? AND author = ?')
                    ->execute([$keepId, $bookId, $mergeId]);
                $booksMoved++;
            }

            // Move path if the book lives under the merge author's directory
            if ($oldPath !== '' && dirname($oldPath) === $mergeDirName) {
                $bookFolder = basename($oldPath);
                $newPath    = $keepDirName . '/' . $bookFolder;
                $pdo->prepare('UPDATE books SET path = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$newPath, $bookId]);
                $fsMoves[$bookId] = [
                    'oldFull'      => $libraryPath . $oldPath,
                    'newFull'      => $libraryPath . $newPath,
                    'oldAuthorDir' => $libraryPath . $mergeDirName,
                ];
            }

            // Recalculate author_sort from current primary author (lowest link id)
            $primStmt = $pdo->prepare(
                'SELECT a.name FROM authors a
                 JOIN books_authors_link bal ON a.id = bal.author
                 WHERE bal.book = ? ORDER BY bal.id LIMIT 1'
            );
            $primStmt->execute([$bookId]);
            $primName = $primStmt->fetchColumn();
            if ($primName !== false) {
                $sortStmt = $pdo->prepare('SELECT author_sort(?)');
                $sortStmt->execute([$primName]);
                $pdo->prepare('UPDATE books SET author_sort = ? WHERE id = ?')
                    ->execute([(string)$sortStmt->fetchColumn(), $bookId]);
            }
        }

        // Delete merge author only if no remaining links
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM books_authors_link WHERE author = ?');
        $remaining->execute([$mergeId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM authors WHERE id = ?')->execute([$mergeId]);
        }
    }

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Filesystem moves (best-effort, post-commit)
$cleanupDirs = [];
foreach ($fsMoves as $paths) {
    ['oldFull' => $old, 'newFull' => $new, 'oldAuthorDir' => $oldAuthorDir] = $paths;
    if (!is_dir($old)) continue;

    if (!is_dir(dirname($new))) mkdir(dirname($new), 0777, true);

    if (is_dir($new) && count(array_diff(scandir($new) ?: [], ['.', '..'])) > 0) {
        $fsWarnings[] = 'Destination occupied: ' . basename($new);
        continue;
    }

    $moved = rename($old, $new) ?: (copyDirRecursive($old, $new) && deleteDirRecursive($old));
    if ($moved) {
        $cleanupDirs[$oldAuthorDir] = true;
    } else {
        $fsWarnings[] = 'Could not move ' . basename($old);
    }
}

// Remove now-empty old author directories
foreach (array_keys($cleanupDirs) as $dir) {
    if (is_dir($dir) && count(array_diff(scandir($dir) ?: [], ['.', '..'])) === 0) {
        rmdir($dir);
    }
}

$response = ['ok' => true, 'books_moved' => $booksMoved];
if ($fsWarnings) $response['fs_warning'] = implode('; ', $fsWarnings);
echo json_encode($response);
