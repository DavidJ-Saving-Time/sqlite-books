<?php
/**
 * Shared book ingestion helpers.
 * Used by add_physical_books.php and json_endpoints/auto_ingest_stream.php.
 */

function touchLastModified(PDO $pdo, int $bookId): void
{
    $pdo->prepare('UPDATE books SET last_modified=CURRENT_TIMESTAMP WHERE id=?')->execute([$bookId]);
}

function safe_filename(string $name, int $max_length = 150): string
{
    static $map = [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
        'Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I',
        'Î'=>'I','Ï'=>'I','Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O',
        'Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'Ý'=>'Y','Þ'=>'TH','ß'=>'ss','à'=>'a','á'=>'a','â'=>'a','ã'=>'a',
        'ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e',
        'ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'d','ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u',
        'ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y',
    ];
    $name = strtr($name, $map);
    $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', $name);
    $name = substr(trim($name), 0, $max_length);
    return $name !== '' ? $name : 'untitled';
}

/**
 * Extract the first supported ebook file from a zip or rar archive.
 * Returns ['path' => string, 'ext' => string, 'tmpDir' => string] or null.
 * Caller must call cleanupTmpDir() on ['tmpDir'] when done.
 */
function extractArchiveBook(string $srcPath, string $ext): ?array
{
    $tmpDir    = sys_get_temp_dir() . '/' . uniqid('extract_', true);
    mkdir($tmpDir);
    $supported = ['epub', 'mobi', 'azw3', 'pdf', 'txt'];
    $found     = '';
    $foundExt  = '';

    if ($ext === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($srcPath) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $e    = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($e, $supported)) {
                    $zip->extractTo($tmpDir, $name);
                    $found    = $tmpDir . '/' . $name;
                    $foundExt = $e;
                    break;
                }
            }
            $zip->close();
        }
    } else {
        if (class_exists('RarArchive')) {
            $rar = RarArchive::open($srcPath);
            if ($rar) {
                foreach ($rar->getEntries() as $entry) {
                    $name = $entry->getName();
                    $e    = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($e, $supported)) {
                        $entry->extract($tmpDir);
                        $found    = $tmpDir . '/' . $name;
                        $foundExt = $e;
                        break;
                    }
                }
                $rar->close();
            }
        } elseif (trim(shell_exec('command -v unrar') ?? '')) {
            $list = shell_exec('unrar lb ' . escapeshellarg($srcPath)) ?? '';
            foreach (preg_split('/\r?\n/', $list) as $name) {
                $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($name !== '' && in_array($e, $supported)) {
                    shell_exec('unrar x -inul ' . escapeshellarg($srcPath) . ' '
                        . escapeshellarg($name) . ' ' . escapeshellarg($tmpDir));
                    $found    = $tmpDir . '/' . $name;
                    $foundExt = $e;
                    break;
                }
            }
        }
    }

    if ($found === '' || !file_exists($found)) {
        cleanupTmpDir($tmpDir);
        return null;
    }

    return ['path' => $found, 'ext' => $foundExt, 'tmpDir' => $tmpDir];
}

function cleanupTmpDir(string $tmpDir): void
{
    if (!is_dir($tmpDir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($tmpDir);
}

/**
 * Ingest a book from a local file path into the Calibre library.
 *
 * @param bool $autoIngest  Adds an identifiers row (type=auto_ingest, val=1) when true.
 * @param bool $deleteSrc   Deletes $srcPath on successful ingestion when true.
 */
function processBookFromPath(
    PDO    $pdo,
    string $libraryPath,
    string $title,
    string $authors_str,
    string $tags_str,
    string $series_str,
    string $series_index_str,
    string $description_str,
    string $srcPath,
    bool   $autoIngest = false,
    bool   $deleteSrc  = false
): array {
    $errors   = [];
    $message  = '';
    $bookLink = '';
    $bookId   = 0;

    if ($title === '' || $authors_str === '') {
        $errors[] = 'Title and authors are required.';
    }
    if (!file_exists($srcPath)) {
        $errors[] = 'Source file not found: ' . $srcPath;
    }

    if (!$errors) {
        $tmpDir = null;
        try {
            $pdo->beginTransaction();

            $authors = array_values(array_unique(array_filter(
                array_map('trim', preg_split('/,|;/', $authors_str)),
                'strlen'
            )));
            if (empty($authors)) {
                throw new Exception('No valid author names found in: ' . $authors_str);
            }
            $firstAuthor = $authors[0];

            $series      = trim($series_str);
            $seriesIndex = $series_index_str !== '' ? (float)$series_index_str : 1.0;
            $description = trim($description_str);

            foreach ($authors as $author) {
                $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?))')
                    ->execute([$author, $author]);
            }

            $tmpPath = safe_filename($title);
            $pdo->prepare(
                'INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, uuid)'
                . ' VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, ?, uuid4())'
            )->execute([$title, $title, $firstAuthor, $seriesIndex, $tmpPath]);
            $bookId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)')->execute([$bookId]);
            touchLastModified($pdo, $bookId);

            foreach ($authors as $author) {
                $pdo->exec('INSERT OR IGNORE INTO books_authors_link (book, author)'
                    . ' SELECT ' . $bookId . ', id FROM authors WHERE name=' . $pdo->quote($author));
            }
            touchLastModified($pdo, $bookId);

            $tags = [];
            if ($tags_str !== '') {
                $tags = array_values(array_filter(array_map('trim', preg_split('/,|;/', $tags_str)), 'strlen'));
                foreach ($tags as $tag) {
                    $pdo->exec('INSERT OR IGNORE INTO tags (name) VALUES (' . $pdo->quote($tag) . ')');
                    $pdo->exec('INSERT INTO books_tags_link (book, tag)'
                        . ' SELECT ' . $bookId . ', id FROM tags WHERE name=' . $pdo->quote($tag));
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
            $bookFolderName   = safe_filename($title) . " ($bookId)";
            $bookPath         = $authorFolderName . '/' . $bookFolderName;
            $fullBookFolder   = $libraryPath . '/' . $bookPath;

            if (!is_dir(dirname($fullBookFolder))) mkdir(dirname($fullBookFolder), 0777, true);
            if (!is_dir($fullBookFolder))           mkdir($fullBookFolder, 0777, true);

            $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$bookPath, $bookId]);
            $pdo->prepare('UPDATE books SET timestamp=CURRENT_TIMESTAMP WHERE id=?')->execute([$bookId]);
            touchLastModified($pdo, $bookId);

            // Resolve the actual file to copy (handles rar/zip archives)
            $ext       = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            $finalFile = $srcPath;
            $finalExt  = $ext;

            if (in_array($ext, ['zip', 'rar'])) {
                $extracted = extractArchiveBook($srcPath, $ext);
                if ($extracted) {
                    $finalFile = $extracted['path'];
                    $finalExt  = $extracted['ext'];
                    $tmpDir    = $extracted['tmpDir'];
                }
            }

            $baseFileName = safe_filename($title, 110) . ' - ' . safe_filename($firstAuthor, 110);
            $destFile     = $fullBookFolder . '/' . $baseFileName . '.' . $finalExt;

            // Try rename (fast, same filesystem); fall back to copy for cross-device moves
            if (!@rename($finalFile, $destFile)) {
                if (!copy($finalFile, $destFile)) {
                    throw new Exception('Failed to copy file to library: ' . $destFile);
                }
            }

            if ($tmpDir) {
                cleanupTmpDir($tmpDir);
                $tmpDir = null;
            }

            // Extract cover
            $coverFile = $fullBookFolder . '/cover.jpg';
            shell_exec('LANG=C ebook-meta --get-cover=' . escapeshellarg($coverFile)
                . ' ' . escapeshellarg($destFile) . ' 2>/dev/null');
            if (file_exists($coverFile) && filesize($coverFile) > 0) {
                $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$bookId]);
                touchLastModified($pdo, $bookId);
            } else {
                @unlink($coverFile);
            }

            if (!file_exists($destFile)) {
                throw new Exception('Book file was not written to library path: ' . $destFile);
            }

            $pdo->prepare('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)')
                ->execute([$bookId, strtoupper($finalExt), filesize($destFile), $baseFileName]);
            touchLastModified($pdo, $bookId);

            if ($autoIngest) {
                $pdo->prepare('INSERT OR IGNORE INTO identifiers (book, type, val) VALUES (?, ?, ?)')
                    ->execute([$bookId, 'auto_ingest', '1']);
                touchLastModified($pdo, $bookId);
            }

            // Write OPF metadata sidecar
            $uuidStmt = $pdo->prepare('SELECT uuid FROM books WHERE id = ?');
            $uuidStmt->execute([$bookId]);
            $uuid = $uuidStmt->fetchColumn();

            $tagsXml     = implode('', array_map(
                fn($t) => '    <dc:subject>' . htmlspecialchars($t) . "</dc:subject>\n", $tags
            ));
            $timestamp      = date('Y-m-d\TH:i:s');
            $descriptionXml = $description !== ''
                ? '    <dc:description>' . htmlspecialchars($description) . "</dc:description>\n" : '';
            $seriesXml = $series !== ''
                ? '    <meta name="calibre:series" content="' . htmlspecialchars($series) . "\"/>\n"
                . '    <meta name="calibre:series_index" content="' . htmlspecialchars((string)$seriesIndex) . "\"/>\n"
                : '';
            $opf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<package version=\"2.0\" xmlns=\"http://www.idpf.org/2007/opf\">\n"
                . "  <metadata xmlns:dc=\"http://purl.org/dc/elements/1.1/\""
                . " xmlns:opf=\"http://www.idpf.org/2007/opf\">\n"
                . '    <dc:title>' . htmlspecialchars($title) . "</dc:title>\n"
                . '    <dc:creator opf:role="aut">' . htmlspecialchars($firstAuthor) . "</dc:creator>\n"
                . $tagsXml . $descriptionXml
                . "    <dc:language>eng</dc:language>\n"
                . "    <dc:identifier opf:scheme=\"uuid\">$uuid</dc:identifier>\n"
                . "    <meta name=\"calibre:timestamp\" content=\"$timestamp+00:00\"/>\n"
                . $seriesXml
                . "  </metadata>\n</package>";
            file_put_contents($fullBookFolder . '/metadata.opf', $opf);

            $pdo->commit();
            $message  = 'Book added successfully.';
            $bookLink = 'list_books.php?search=' . urlencode($title);

            if ($deleteSrc && file_exists($srcPath)) {
                @unlink($srcPath);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($tmpDir) cleanupTmpDir($tmpDir);
            // Remove any partially created library folder so no orphan dirs are left
            if (isset($fullBookFolder) && is_dir($fullBookFolder)) {
                cleanupTmpDir($fullBookFolder);
                $authorDir = dirname($fullBookFolder);
                if (is_dir($authorDir) && count(scandir($authorDir)) === 2) {
                    @rmdir($authorDir);
                }
            }
            $errors[] = $e->getMessage();
        }
    }

    return [
        'message' => $message,
        'link'    => $bookLink,
        'errors'  => $errors,
        'title'   => $title,
        'book_id' => $bookId,
    ];
}
