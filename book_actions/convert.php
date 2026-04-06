<?php
/**
 * Handles convert_to_pdf and convert_to_epub POST actions for book.php.
 *
 * Expects in scope: $pdo, $id, $book, $convertRequested, $convertEpubRequested,
 *                   $ebookFileRel (may be updated on success)
 * Sets in scope:    $conversionMessage, $epubFileRel, $pdfFileRel, $ebookFileRel
 */

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
            $inputFile   = $libraryPath . '/' . $epubRelative;
            $outputDir   = dirname($inputFile);
            $outputFile  = $outputDir . '/' . pathinfo($inputFile, PATHINFO_FILENAME) . '.pdf';
            if (file_exists($outputFile)) {
                $conversionMessage = ['type' => 'warning', 'text' => 'PDF already exists: ' . basename($outputFile)];
            } else {
                $cmd = 'LANG=C ebook-convert ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
                $cmdOutput = [];
                $exitCode  = 0;
                exec($cmd, $cmdOutput, $exitCode);
                clearstatcache(true, $outputFile);
                if ($exitCode === 0 && file_exists($outputFile)) {
                    @chmod($outputFile, 0664);
                    $pdfName = pathinfo($outputFile, PATHINFO_FILENAME);
                    $pdfSize = filesize($outputFile) ?: 0;
                    try {
                        $ins = $pdo->prepare(
                            'INSERT OR IGNORE INTO data (book, format, uncompressed_size, name)
                             VALUES (:book, :fmt, :size, :name)'
                        );
                        $ins->execute([':book' => $id, ':fmt' => 'PDF', ':size' => $pdfSize, ':name' => $pdfName]);
                        $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')
                            ->execute([$id]);
                    } catch (PDOException $e) {
                        $conversionMessage = ['type' => 'warning', 'text' => 'PDF created but DB not updated: ' . $e->getMessage()];
                        goto conversionDone;
                    }
                    $pdfFileRel        = substr($outputFile, strlen($libraryPath) + 1);
                    $pdfExists         = true;
                    $conversionMessage = ['type' => 'success', 'text' => 'PDF created and registered: ' . basename($outputFile)];
                } else {
                    if ($exitCode !== 0 && file_exists($outputFile)) {
                        @unlink($outputFile);
                    }
                    $error = '';
                    foreach ($cmdOutput as $line) {
                        $line = trim($line);
                        if ($line !== '') { $error = $line; break; }
                    }
                    $conversionMessage = ['type' => 'danger', 'text' => $error ?: 'ebook-convert failed to create PDF.'];
                }
            }
        }
    }
    conversionDone:
}

if ($convertEpubRequested) {
    $bookRelPath = ltrim((string)($book['path'] ?? ''), '/');
    if ($bookRelPath === '') {
        $conversionMessage = ['type' => 'danger', 'text' => 'Book path is not set in the library.'];
    } elseif (findBookFileByExtension($bookRelPath, 'epub') !== null) {
        $conversionMessage = ['type' => 'warning', 'text' => 'An EPUB file already exists for this book.'];
    } else {
        $sourceRel = null;
        foreach (['azw3', 'mobi', 'pdf'] as $tryExt) {
            $found = findBookFileByExtension($bookRelPath, $tryExt);
            if ($found !== null) { $sourceRel = $found; break; }
        }
        if ($sourceRel === null) {
            $sourceRel = firstBookFile($bookRelPath);
        }
        if ($sourceRel === null) {
            $conversionMessage = ['type' => 'danger', 'text' => 'No source file found to convert.'];
        } else {
            $libraryPath = getLibraryPath();
            $inputFile   = $libraryPath . '/' . $sourceRel;
            $outputFile  = $libraryPath . '/' . $bookRelPath . '/' . pathinfo($inputFile, PATHINFO_FILENAME) . '.epub';
            if (file_exists($outputFile)) {
                $conversionMessage = ['type' => 'warning', 'text' => 'EPUB already exists on disk: ' . basename($outputFile)];
            } else {
                $cmd = 'LANG=C ebook-convert ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
                $cmdOutput = [];
                $exitCode  = 0;
                exec($cmd, $cmdOutput, $exitCode);
                clearstatcache(true, $outputFile);
                if ($exitCode === 0 && file_exists($outputFile)) {
                    @chmod($outputFile, 0664);
                    $epubName = pathinfo($outputFile, PATHINFO_FILENAME);
                    $epubSize = filesize($outputFile) ?: 0;
                    try {
                        $ins = $pdo->prepare(
                            'INSERT OR IGNORE INTO data (book, format, uncompressed_size, name)
                             VALUES (:book, :fmt, :size, :name)'
                        );
                        $ins->execute([':book' => $id, ':fmt' => 'EPUB', ':size' => $epubSize, ':name' => $epubName]);
                        $pdo->prepare('UPDATE books SET last_modified = CURRENT_TIMESTAMP WHERE id = ?')
                            ->execute([$id]);
                    } catch (PDOException $e) {
                        $conversionMessage = ['type' => 'warning', 'text' => 'EPUB created but DB not updated: ' . $e->getMessage()];
                        goto epubConversionDone;
                    }
                    $epubFileRel  = substr($outputFile, strlen($libraryPath) + 1);
                    $ebookFileRel = $ebookFileRel ?: $epubFileRel;
                    $conversionMessage = ['type' => 'success', 'text' => 'EPUB created and registered: ' . basename($outputFile)
                        . ' (converted from ' . strtoupper(pathinfo($sourceRel, PATHINFO_EXTENSION)) . ')'];
                } else {
                    if ($exitCode !== 0 && file_exists($outputFile)) { @unlink($outputFile); }
                    $error = '';
                    foreach ($cmdOutput as $line) {
                        $line = trim($line);
                        if ($line !== '') { $error = $line; break; }
                    }
                    $conversionMessage = ['type' => 'danger', 'text' => $error ?: 'ebook-convert failed to create EPUB.'];
                }
            }
        }
        epubConversionDone:
    }
}
