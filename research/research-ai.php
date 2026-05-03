<?php
/**
 * research-ai.php — Web front-end for PDF/EPUB ingestion.
 *
 * Splits books into chunks, embeds via OpenAI, and stores in PostgreSQL
 * (pgvector) instead of SQLite.  All other logic (PDF/EPUB extraction,
 * chunking, page-label detection) is unchanged.
 *
 * Requirements:
 *   - poppler-utils (pdftotext, pdfinfo)
 *   - ebook-convert (from Calibre) for EPUB extraction
 *   - PHP PDO pgsql + pgvector extension installed in the DB
 *   - OPENAI_API_KEY set in environment
 *   - PGHOST / PGPORT / PGDATABASE_RESEARCH / PGUSER / PGPASSWORD
 *   - Optional: OPENAI_EMBED_MODEL (default text-embedding-3-small)
 */

require_once __DIR__ . '/../db.php';   // Calibre DB (for getLibraryPath etc.)
require_once __DIR__ . '/db.php';      // Research PG connection

ini_set('memory_limit', '1G');

function out($msg) {
    echo $msg . "\n"; @ob_flush(); @flush();
}

function ensure_extension(string $path, string $extension): string {
    $extension = ltrim($extension, '.');
    if ($extension === '') return $path;
    $lowerPath = strtolower($path);
    $targetSuffix = '.' . strtolower($extension);
    if (substr($lowerPath, -strlen($targetSuffix)) === $targetSuffix) return $path;
    $newPath = $path . $targetSuffix;
    if (@rename($path, $newPath)) return $newPath;
    return $path;
}

$statusMessage = null;
$errorMessage  = null;

$prefillTitle    = isset($_GET['title'])           ? trim((string)$_GET['title'])  : '';
$prefillAuthor   = isset($_GET['author'])          ? trim((string)$_GET['author']) : '';
$prefillYear     = '';
if (isset($_GET['year'])) {
    $yc = trim((string)$_GET['year']);
    if ($yc !== '' && preg_match('/^-?\d{1,4}$/', $yc)) $prefillYear = $yc;
}
$prefillLibraryId = '';
if (isset($_GET['library_book_id'])) {
    $lc = trim((string)$_GET['library_book_id']);
    if ($lc !== '' && ctype_digit($lc)) $prefillLibraryId = $lc;
}
$prefillFilePath = '';
if (isset($_GET['pdf_path'])) {
    $prefillFilePath = str_replace(["\r", "\n"], '', trim((string)$_GET['pdf_path']));
}
$prefillFileUrl = '';
if (isset($_GET['pdf_url'])) {
    $prefillFileUrl = str_replace(["\r", "\n"], '', trim((string)$_GET['pdf_url']));
}
if ($prefillFileUrl === '' && $prefillFilePath !== '') {
    $prefillFileUrl = rtrim(getLibraryWebPath(), '/') . '/' . ltrim($prefillFilePath, '/');
}
$prefillPageOffset = '0';
if (isset($_GET['page_offset'])) {
    $oc = trim((string)$_GET['page_offset']);
    if ($oc === '' || preg_match('/^-?\d+$/', $oc)) $prefillPageOffset = $oc === '' ? '0' : $oc;
}
$hasPrefill = ($prefillTitle !== '' || $prefillAuthor !== '' || $prefillYear !== ''
    || $prefillLibraryId !== '' || $prefillFilePath !== '' || $prefillPageOffset !== '0');

// ── Delete ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && $_POST['delete_id'] !== '') {
    $deleteId = (int)$_POST['delete_id'];
    try {
        $db  = getResearchDb();
        $sel = $db->prepare('SELECT title FROM items WHERE id = ?');
        $sel->execute([$deleteId]);
        $book = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$book) {
            $errorMessage = 'Book not found; nothing deleted.';
        } else {
            // ON DELETE CASCADE removes chunks + page_map automatically
            $db->prepare('DELETE FROM items WHERE id = ?')->execute([$deleteId]);
            $statusMessage = sprintf('Deleted "%s" (ID %d) and related embeddings.', $book['title'], $deleteId);
        }
    } catch (Exception $e) {
        $errorMessage = 'Failed to delete book: ' . $e->getMessage();
    }
}

// ── Edit metadata ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id']) && $_POST['edit_id'] !== '') {
    $editId    = (int)$_POST['edit_id'];
    $newTitle  = trim($_POST['edit_title'] ?? '');
    $newAuthor = trim($_POST['edit_author'] ?? '');
    $newYearRaw      = trim($_POST['edit_year'] ?? '');
    $newLibraryIdRaw = trim($_POST['edit_library_book_id'] ?? '');

    if ($newTitle === '') {
        $errorMessage = 'Title is required when editing a book.';
    } else {
        $newYear      = $newYearRaw      === '' ? null : (int)$newYearRaw;
        $newLibraryId = $newLibraryIdRaw === '' ? null : (int)$newLibraryIdRaw;
        try {
            $db = getResearchDb();
            $sel = $db->prepare('SELECT id FROM items WHERE id = ?');
            $sel->execute([$editId]);
            if (!$sel->fetch()) {
                $errorMessage = 'Book not found; nothing updated.';
            } else {
                $db->prepare('UPDATE items SET title = ?, author = ?, year = ?, library_book_id = ? WHERE id = ?')
                   ->execute([$newTitle, $newAuthor, $newYear, $newLibraryId, $editId]);
                $statusMessage = 'Book details updated successfully.';
            }
        } catch (Exception $e) {
            $errorMessage = 'Failed to edit book: ' . $e->getMessage();
        }
    }
}

// ── Ingest ────────────────────────────────────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !(isset($_POST['delete_id']) && $_POST['delete_id'] !== '')
    && !(isset($_POST['edit_id'])   && $_POST['edit_id']   !== '')
) {
    echo "<pre>";

    $bookTitle     = trim($_POST['title']  ?? '');
    $bookAuthor    = trim($_POST['author'] ?? '');
    $bookYear      = (int)($_POST['year']  ?? 0);
    $displayOffset = (int)($_POST['page_offset'] ?? 0);
    $libraryBookId = isset($_POST['library_book_id']) && $_POST['library_book_id'] !== ''
        ? (int)$_POST['library_book_id'] : null;
    $libraryFilePath = str_replace(["\r", "\n"], '', trim($_POST['library_file_path'] ?? ''));

    $hasUpload = isset($_FILES['book_file']) && is_array($_FILES['book_file'])
        && ($_FILES['book_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $originalName    = $hasUpload ? ($_FILES['book_file']['name'] ?? '') : basename($libraryFilePath);
    $sourceExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($bookTitle === '') { out('Title is required.'); exit; }
    if ($hasUpload && ($_FILES['book_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        out('Upload failed: ' . $_FILES['book_file']['error']); exit;
    }
    if (!$hasUpload && $libraryFilePath === '') { out('Title and file are required.'); exit; }

    $tmpBookPath = null;
    if ($hasUpload) {
        $tmpBookPath = tempnam(sys_get_temp_dir(), 'upload_book_');
        if ($tmpBookPath === false) { out('Failed to create temporary file.'); exit; }
        $tmpBookPath = ensure_extension($tmpBookPath, $sourceExtension);
        if (!move_uploaded_file($_FILES['book_file']['tmp_name'], $tmpBookPath)) {
            out('Failed to move uploaded file.'); exit;
        }
        out('File uploaded.');
    } else {
        $libraryBase   = rtrim(getLibraryPath(), '/');
        $candidatePath = $libraryBase . '/' . ltrim($libraryFilePath, '/');
        $resolvedPath  = realpath($candidatePath);
        if ($resolvedPath === false || strpos($resolvedPath, $libraryBase) !== 0) {
            out('Library file path is invalid.'); exit;
        }
        if (!is_file($resolvedPath)) { out('Library file not found.'); exit; }
        $tmpBookPath = tempnam(sys_get_temp_dir(), 'library_book_');
        if ($tmpBookPath === false) { out('Failed to create temporary file.'); exit; }
        $tmpBookPath = ensure_extension($tmpBookPath, $sourceExtension);
        if (!copy($resolvedPath, $tmpBookPath)) { out('Failed to access library file.'); exit; }
        out('Using library file from library.');
    }

    $detectedType = detect_file_type($tmpBookPath, $hasUpload ? ($_FILES['book_file']['name'] ?? '') : ($libraryFilePath ?? ''));
    if ($detectedType === null) {
        out('Unsupported file type. Only PDF and EPUB are allowed.');
        if ($tmpBookPath !== null && file_exists($tmpBookPath)) @unlink($tmpBookPath);
        exit;
    }
    if ($detectedType === 'epub') $tmpBookPath = ensure_extension($tmpBookPath, 'epub');
    elseif ($detectedType === 'pdf') $tmpBookPath = ensure_extension($tmpBookPath, 'pdf');

    $apiKey     = getenv('OPENAI_API_KEY');
    if (!$apiKey) { out('ERROR: Set OPENAI_API_KEY.'); exit; }
    $embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small';

    // ── Open DB and ensure schema ─────────────────────────────────────────────
    try {
        $db = getResearchDb();
        ensureResearchSchema($db);
    } catch (Exception $e) {
        out('ERROR: Could not connect to research database: ' . $e->getMessage());
        out('Check PGHOST, PGPORT, PGDATABASE_RESEARCH, PGUSER, PGPASSWORD env vars and that the pgvector extension is installed.');
        if ($tmpBookPath !== null && file_exists($tmpBookPath)) @unlink($tmpBookPath);
        exit;
    }
    out('Database ready.');

    $pages = [];
    $pagesCount  = 0;
    $tmpTextPath = null;

    try {

    if ($detectedType === 'pdf') {
        $info = [];
        exec(sprintf('pdfinfo %s 2>/dev/null', escapeshellarg($tmpBookPath)), $info, $rc);
        $pagesCount = 0;
        foreach ($info as $ln) if (preg_match('/^Pages:\s+(\d+)/', $ln, $m)) { $pagesCount = (int)$m[1]; break; }
        if ($pagesCount < 1) { out('ERROR: Could not read page count.'); exit; }
        out("Pages: $pagesCount");

        for ($p = 1; $p <= $pagesCount; $p++) {
            $tmp = tempnam(sys_get_temp_dir(), 'pg_');
            $cmd = sprintf('pdftotext -layout -enc UTF-8 -f %d -l %d %s %s', $p, $p, escapeshellarg($tmpBookPath), escapeshellarg($tmp));
            exec($cmd, $_, $rc);
            $txt = file_exists($tmp) ? file_get_contents($tmp) : '';
            @unlink($tmp);
            $pages[$p] = normalize_whitespace($txt ?? '');
        }
        out('Text extracted.');
    } else {
        $tmpTextBase = tempnam(sys_get_temp_dir(), 'epub_txt_');
        if ($tmpTextBase === false) { out('Failed to create temporary EPUB text file.'); exit; }
        $tmpTextPath = $tmpTextBase . '.txt';
        @unlink($tmpTextBase);

        $cmd = sprintf('ebook-convert %s %s 2>&1', escapeshellarg($tmpBookPath), escapeshellarg($tmpTextPath));
        $convertOutput = [];
        exec($cmd, $convertOutput, $rc);
        if ($rc === 0 && file_exists($tmpTextPath)) {
            $rawText = file_get_contents($tmpTextPath) ?: '';
            @unlink($tmpTextPath);
            $pages = split_epub_text($rawText);
        } else {
            $errorDetail = $convertOutput ? (' Details: ' . substr(implode('\n', $convertOutput), 0, 500)) : '';
            out('ebook-convert failed to extract text.' . $errorDetail);
            if ($tmpTextPath !== null && file_exists($tmpTextPath)) @unlink($tmpTextPath);
            $fallbackPages = extract_epub_sections($tmpBookPath);
            if ($fallbackPages === null) { out('ERROR: EPUB text extraction failed.'); exit; }
            $pages = $fallbackPages;
        }
        $pagesCount = count($pages);
        if ($pagesCount < 1) { out('ERROR: EPUB appears empty after extraction.'); exit; }
        out("Sections: $pagesCount");
    }

    // ── Insert item ───────────────────────────────────────────────────────────
    $insItem = $db->prepare(
        "INSERT INTO items (title, author, year, display_offset, library_book_id)
         VALUES (?, ?, ?, ?, ?) RETURNING id"
    );
    $insItem->execute([$bookTitle, $bookAuthor ?: null, $bookYear ?: null, $displayOffset, $libraryBookId]);
    $itemId = (int)$insItem->fetchColumn();

    // ── Populate page_map ─────────────────────────────────────────────────────
    $insMap = $db->prepare(
        "INSERT INTO page_map (item_id, pdf_page, display_label, display_number, method, confidence)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($detectedType === 'pdf') {
        $labels = [];
        $script = __DIR__ . '/extract_page_labels.py';
        if (is_file($script)) {
            $outLabels = trim(shell_exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($tmpBookPath)));
            $labels = json_decode($outLabels, true) ?: [];
        }
        for ($p = 1; $p <= $pagesCount; $p++) {
            $label  = $labels[$p] ?? detect_header_footer_label($pages[$p]);
            $method = isset($labels[$p]) ? 'pdf_label' : ($label ? 'header' : 'offset');
            $conf   = isset($labels[$p]) ? 1.0 : ($label ? 0.6 : 0.4);
            $num    = null;
            if ($label !== null) {
                if (preg_match('/^\d+$/', $label)) $num = (int)$label;
                elseif (preg_match('/^[ivxlcdm]+$/i', $label)) $num = roman_to_int($label);
                else $label = null;
            }
            if ($label === null) {
                $num = $p + $displayOffset; $label = (string)$num;
                $method = 'offset'; $conf = 0.4;
            }
            $insMap->execute([$itemId, $p, $label, $num, $method, $conf]);
        }
    } else {
        for ($p = 1; $p <= $pagesCount; $p++) {
            $insMap->execute([$itemId, $p, 'Section ' . $p, $p, 'section', 0.8]);
        }
    }

    // ── Build + embed chunks ──────────────────────────────────────────────────
    $targetTokens = 1000;
    $chunks = build_chunks_from_pages($pages, $targetTokens);
    out('Chunks built: ' . count($chunks));

    $insChunk = $db->prepare(
        "INSERT INTO chunks
             (item_id, section, page_start, page_end, text, embedding, token_count,
              display_start, display_end, display_start_label, display_end_label)
         VALUES (?, ?, ?, ?, ?, ?::vector, ?, NULL, NULL, NULL, NULL)"
    );

    $batchSize = 64;
    for ($i = 0; $i < count($chunks); $i += $batchSize) {
        $batch   = array_slice($chunks, $i, $batchSize);
        $vectors = create_embeddings_batch(array_column($batch, 'text'), $embedModel, $apiKey);
        foreach ($batch as $j => $chunk) {
            $embedding = $vectors[$j] ?? null;
            if (!$embedding) continue;
            $insChunk->execute([
                $itemId,
                $chunk['section'],
                $chunk['page_start'],
                $chunk['page_end'],
                $chunk['text'],
                floatsToVector($embedding),
                $chunk['approx_tokens'],
            ]);
        }
        out('Embedded batch ' . (($i / $batchSize) + 1));
        usleep(200000);
    }

    recompute_chunk_display_ranges($db, $itemId);
    out("Ingest complete. Book ID: $itemId");
    out("Pages: $pagesCount | Chunks: " . count($chunks));

    } catch (Exception $e) {
        out('ERROR: ' . $e->getMessage());
    }

    echo "</pre>";
    if ($tmpBookPath !== null && file_exists($tmpBookPath)) @unlink($tmpBookPath);
    if ($tmpTextPath !== null && file_exists($tmpTextPath)) @unlink($tmpTextPath);
    exit;
}

// ── Ingested books list ───────────────────────────────────────────────────────
$ingestedBooks = [];
try {
    $dbList = getResearchDb();
    $rows   = $dbList->query(
        'SELECT id, title, author, year, library_book_id, created_at FROM items ORDER BY created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $id        = (int)$r['id'];
        $r['pages']    = (int)$dbList->query("SELECT MAX(page_end)  FROM chunks WHERE item_id = $id")->fetchColumn();
        $r['chunks']   = (int)$dbList->query("SELECT COUNT(*)       FROM chunks WHERE item_id = $id")->fetchColumn();
        $r['endpoint'] = 'openai/v1/embeddings';
        $ingestedBooks[] = $r;
    }
} catch (Exception $e) {
    $ingestedBooks = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Ingest — Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Lora:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/research-theme.css">
</head>
<body>
<?php include 'navbar.php'; ?>
<main class="ra-page">

  <header class="ra-header">
    <div class="ra-header-rule"></div>
    <h1><i class="fa-solid fa-microchip me-2 ra-icon-sm"></i>AI Ingest</h1>
    <p>Embed a PDF or EPUB into the research index for semantic search and retrieval</p>
  </header>

  <?php if ($statusMessage): ?>
    <div class="ra-status ra-status--compact">
      <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($statusMessage) ?>
    </div>
  <?php endif; ?>

  <?php if ($errorMessage): ?>
    <div class="ra-error ra-error--compact">
      <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($errorMessage) ?>
    </div>
  <?php endif; ?>

  <?php if ($hasPrefill): ?>
    <div class="ra-status ra-status--compact ra-status--mid">
      <i class="fa-solid fa-circle-info me-2"></i>Form pre-filled with data from the selected library book.
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <?php if ($prefillFilePath !== ''): ?>
      <input type="hidden" name="library_file_path" value="<?= htmlspecialchars($prefillFilePath) ?>">
    <?php endif; ?>

    <div class="ra-settings-grid">

      <div class="ra-full-col">
        <label class="ra-label" for="book_file">
          <i class="fa-solid fa-file-arrow-up me-1"></i>Book File
          <span class="ra-label-hint">PDF or EPUB</span>
        </label>
        <input type="hidden" name="library_file_path" id="library_file_path"
               value="<?= htmlspecialchars($prefillFilePath) ?>">
        <div id="resolved_file_banner" class="ra-file-banner<?= $prefillFilePath === '' ? ' d-none' : '' ?>">
          <i class="fa-solid fa-file-pdf ra-icon-accent"></i>
          <span id="resolved_file_label" class="ra-file-path">
            <?= htmlspecialchars($prefillFilePath) ?>
          </span>
          <button type="button" id="clear_resolved_file" class="ra-clear-btn" title="Remove — upload a file instead">✕</button>
        </div>
        <input class="ra-file-input<?= $prefillFilePath !== '' ? ' d-none' : '' ?>" type="file" name="book_file" id="book_file"
               accept="application/pdf,application/epub+zip"
               <?= $prefillFilePath === '' ? 'required' : '' ?>>
      </div>

      <div>
        <label class="ra-label" for="title">Title <span class="ra-required">*</span></label>
        <input class="ra-input" type="text" name="title" id="title"
               value="<?= htmlspecialchars($prefillTitle) ?>" required placeholder="Book title…">
      </div>

      <div>
        <label class="ra-label" for="author">Author</label>
        <input class="ra-input" type="text" name="author" id="author"
               value="<?= htmlspecialchars($prefillAuthor) ?>" placeholder="Author name…">
      </div>

      <div>
        <label class="ra-label" for="year">Year</label>
        <input class="ra-input" type="number" name="year" id="year"
               value="<?= htmlspecialchars($prefillYear) ?>" placeholder="e.g. 1859">
      </div>

      <div class="position-relative">
        <label class="ra-label" for="library_book_search">Library Book</label>
        <input class="ra-input" type="text" id="library_book_search" autocomplete="off"
               placeholder="Type title or author to search…" value="">
        <input type="hidden" name="library_book_id" id="library_book_id" value="<?= htmlspecialchars($prefillLibraryId) ?>">
      </div>

      <div>
        <label class="ra-label" for="page_offset">
          Page Offset
          <span class="ra-hint ra-hint--inline">subtract from physical page numbers</span>
        </label>
        <input class="ra-input" type="number" name="page_offset" id="page_offset"
               value="<?= htmlspecialchars($prefillPageOffset) ?>" placeholder="0">
      </div>

    </div>

    <div class="ra-submit-row">
      <button class="ra-submit" type="submit">
        <i class="fa-solid fa-upload me-2"></i>Ingest Book
      </button>
    </div>
  </form>

  <?php if ($ingestedBooks): ?>
    <div class="ra-section-rule"></div>
    <div class="ra-sources-section">
      <div class="ra-answer-header">
        <span><i class="fa-solid fa-database me-2 ra-accent"></i>Ingested Books</span>
        <span class="ra-meta-sm"><?= count($ingestedBooks) ?> item<?= count($ingestedBooks) !== 1 ? 's' : '' ?></span>
      </div>
      <table class="ra-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Author</th>
            <th>Year</th>
            <th>Lib ID</th>
            <th>Pages</th>
            <th>Chunks</th>
            <th>Endpoint</th>
            <th>Ingested</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ingestedBooks as $b): ?>
          <tr>
            <td class="ra-td-id"><?= htmlspecialchars($b['id']) ?></td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td class="ra-td-author"><?= htmlspecialchars($b['author'] ?? '') ?></td>
            <td class="ra-td-mono"><?= htmlspecialchars($b['year'] ?? '') ?></td>
            <td class="ra-td-mono-dim"><?= htmlspecialchars($b['library_book_id'] ?? '') ?></td>
            <td class="ra-td-mono"><?= htmlspecialchars($b['pages'] ?: '—') ?></td>
            <td class="ra-td-mono"><?= htmlspecialchars($b['chunks']) ?></td>
            <td class="ra-td-sm"><?= htmlspecialchars($b['endpoint']) ?></td>
            <td class="ra-td-sm"><?= htmlspecialchars($b['created_at']) ?></td>
            <td class="text-nowrap">
              <button type="button"
                      class="ra-btn me-1"
                      data-bs-toggle="modal"
                      data-bs-target="#editBookModal"
                      data-id="<?= (int)$b['id'] ?>"
                      data-title="<?= htmlspecialchars($b['title']) ?>"
                      data-author="<?= htmlspecialchars($b['author'] ?? '') ?>"
                      data-year="<?= htmlspecialchars($b['year'] ?? '') ?>"
                      data-library="<?= htmlspecialchars($b['library_book_id'] ?? '') ?>">
                <i class="fa-solid fa-pen-to-square me-1"></i>Edit
              </button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this book and all embeddings?');">
                <input type="hidden" name="delete_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="ra-btn ra-btn-danger">
                  <i class="fa-solid fa-trash-can me-1"></i>Delete
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="ra-empty-msg">No books ingested yet.</p>
  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Edit Book Modal -->
<div class="modal fade" id="editBookModal" tabindex="-1" aria-labelledby="editBookModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editBookModalLabel">
          <i class="fa-solid fa-pen-to-square me-2 ra-accent"></i>Edit Book
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="edit_id" id="edit_id">
          <div class="mb-3">
            <label for="edit_title" class="ra-label">Title</label>
            <input class="ra-input" type="text" name="edit_title" id="edit_title" required>
          </div>
          <div class="mb-3">
            <label for="edit_author" class="ra-label">Author</label>
            <input class="ra-input" type="text" name="edit_author" id="edit_author">
          </div>
          <div class="mb-3">
            <label for="edit_year" class="ra-label">Year</label>
            <input class="ra-input" type="number" name="edit_year" id="edit_year">
          </div>
          <div class="mb-3">
            <label for="edit_library_book_id" class="ra-label">Library Book ID</label>
            <input class="ra-input" type="number" name="edit_library_book_id" id="edit_library_book_id">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="ra-btn" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="ra-submit ra-btn-save">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="/js/book-autocomplete.js"></script>
<script>
function clearResolvedFile() {
  document.getElementById('library_file_path').value = '';
  const banner    = document.getElementById('resolved_file_banner');
  const fileInput = document.getElementById('book_file');
  banner.style.display    = 'none';
  fileInput.style.display = '';
  fileInput.setAttribute('required', '');
  fileInput.value = '';
}

const lbAc = new BookAutocomplete({
  input:   '#library_book_search',
  hidden:  '#library_book_id',
  params:  { with_files: 1 },
  onSelect(book) {
    const titleEl  = document.getElementById('title');
    const authorEl = document.getElementById('author');
    if (titleEl  && !titleEl.value)  titleEl.value  = book.title;
    if (authorEl && !authorEl.value) authorEl.value = book.author || '';

    const fileInput   = document.getElementById('book_file');
    const filePath    = document.getElementById('library_file_path');
    const banner      = document.getElementById('resolved_file_banner');
    const bannerLabel = document.getElementById('resolved_file_label');
    if (book.file) {
      filePath.value          = book.file;
      bannerLabel.textContent = book.file;
      const icon = banner.querySelector('i');
      if (icon) icon.className = book.file.endsWith('.epub') ? 'fa-solid fa-book' : 'fa-solid fa-file-pdf';
      banner.style.display    = 'flex';
      fileInput.removeAttribute('required');
      fileInput.style.display = 'none';
      fileInput.value         = '';
    }
  },
  onClear: clearResolvedFile,
});

document.getElementById('library_book_search').addEventListener('input', () => {
  if (document.getElementById('library_file_path').value) clearResolvedFile();
});
document.getElementById('clear_resolved_file').addEventListener('click', () => { lbAc.clear(); });

<?php if ($prefillLibraryId !== ''): ?>
lbAc.selectById(<?= (int)$prefillLibraryId ?>, { with_files: 1 });
<?php endif; ?>

const editModal = document.getElementById('editBookModal');
if (editModal) {
    editModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        if (!button) return;
        editModal.querySelector('#edit_id').value            = button.getAttribute('data-id')      || '';
        editModal.querySelector('#edit_title').value         = button.getAttribute('data-title')   || '';
        editModal.querySelector('#edit_author').value        = button.getAttribute('data-author')  || '';
        editModal.querySelector('#edit_year').value          = button.getAttribute('data-year')    || '';
        editModal.querySelector('#edit_library_book_id').value = button.getAttribute('data-library') || '';
    });
}
</script>
</body>
</html>
<?php
// ── Pure-PHP helpers (no DB dependency) ──────────────────────────────────────

function detect_file_type(string $path, string $originalName = ''): ?string {
    $mime = mime_content_type($path) ?: '';
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($mime === 'application/pdf'      || $ext === 'pdf')  return 'pdf';
    if ($mime === 'application/epub+zip' || $ext === 'epub') return 'epub';
    return null;
}

function extract_epub_sections(string $epubPath): ?array {
    $zip = new ZipArchive();
    if ($zip->open($epubPath) !== true) return null;
    $opfPath = read_epub_opf_path($zip);
    if ($opfPath === null) { $zip->close(); return null; }
    $opfXml = $zip->getFromName($opfPath);
    if ($opfXml === false) { $zip->close(); return null; }
    $opf = @simplexml_load_string($opfXml);
    if ($opf === false) { $zip->close(); return null; }
    $manifest = [];
    foreach ($opf->manifest->item as $item) {
        $id = (string)$item['id']; $href = (string)$item['href']; $media = (string)$item['media-type'];
        if ($id !== '' && $href !== '') $manifest[$id] = ['href' => $href, 'media' => $media];
    }
    $pages   = [];
    $baseDir = rtrim(dirname($opfPath), '/\\');
    foreach ($opf->spine->itemref as $itemref) {
        $idref = (string)$itemref['idref'];
        if ($idref === '' || !isset($manifest[$idref])) continue;
        $href  = $manifest[$idref]['href'];
        $media = $manifest[$idref]['media'];
        $isHtml = preg_match('/html/i', $media) || preg_match('/\.(x?html)$/i', $href);
        if (!$isHtml) continue;
        $zipPath = ($baseDir === '' || $baseDir === '.') ? $href : $baseDir . '/' . $href;
        $zipPath = ltrim($zipPath, '/');
        $content = $zip->getFromName($zipPath);
        if ($content === false) continue;
        $norm = normalize_whitespace(extract_text_from_html($content));
        if ($norm !== '') $pages[] = $norm;
    }
    $zip->close();
    if (!$pages) return null;
    return array_combine(range(1, count($pages)), $pages);
}

function read_epub_opf_path(ZipArchive $zip): ?string {
    $containerXml = $zip->getFromName('META-INF/container.xml');
    if ($containerXml === false) return null;
    $container = @simplexml_load_string($containerXml);
    if ($container === false) return null;
    foreach ($container->rootfiles->rootfile as $rootfile) {
        $path = (string)$rootfile['full-path'];
        if ($path !== '') return $path;
    }
    return null;
}

function extract_text_from_html(string $html): string {
    $html = preg_replace('/<(br|hr)\s*\\?\/?>/i', "\n", $html);
    $html = preg_replace('/<\/(p|div|h[1-6]|li|blockquote|section|article|tr)>/i', "</$1>\n", $html);
    $html = preg_replace('/<(p|div|h[1-6]|li|blockquote|section|article|tr)[^>]*>/i', "\n<$1>", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($text);
}

function split_epub_text(string $text): array {
    $text  = str_replace(["\r\n", "\r"], "\n", $text);
    $parts = preg_split('/\n{2,}/u', $text);
    $pages = []; $i = 1;
    foreach ($parts as $part) {
        $norm = normalize_whitespace($part);
        if ($norm === '') continue;
        $pages[$i++] = $norm;
    }
    if (!$pages && trim($text) !== '') $pages[1] = normalize_whitespace($text);
    return $pages;
}

function normalize_whitespace(string $s): string {
    if ($s === '') return '';
    $s = preg_replace("/[ \t]+/u",   " ",    $s);
    $s = preg_replace("/[ \t]*\n/u", "\n",   $s);
    $s = preg_replace("/\n{4,}/u",   "\n\n", $s);
    return trim($s);
}

function approx_token_count(string $s): int {
    $words = preg_split("/\s+/u", trim($s));
    $w = max(1, count(array_filter($words)));
    return (int)round($w * 1.3);
}

function infer_section_title(string $chunk): ?string {
    foreach (preg_split("/\n/u", $chunk) as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line, 'UTF-8') > 3) return mb_substr($line, 0, 80, 'UTF-8');
    }
    return null;
}

function build_chunks_from_pages(array $pagesByNum, int $targetTokens): array {
    $chunks = []; $cur = ""; $startPage = null; $lastPage = null;
    foreach ($pagesByNum as $pageNum => $pageText) {
        $p = trim($pageText);
        if ($p === '') continue;
        $try = $cur ? ($cur . "\n\n" . $p) : $p;
        if (approx_token_count($try) > $targetTokens && $cur) {
            $chunks[] = ['text'=>$cur,'page_start'=>$startPage,'page_end'=>$lastPage,'approx_tokens'=>approx_token_count($cur),'section'=>infer_section_title($cur)];
            $cur = $p; $startPage = $pageNum; $lastPage = $pageNum;
        } else {
            if ($cur === "") $startPage = $pageNum;
            $cur = $try; $lastPage = $pageNum;
        }
    }
    if ($cur) $chunks[] = ['text'=>$cur,'page_start'=>$startPage,'page_end'=>$lastPage,'approx_tokens'=>approx_token_count($cur),'section'=>infer_section_title($cur)];

    $refined = [];
    foreach ($chunks as $c) {
        if ($c['approx_tokens'] <= 1600) { $refined[] = $c; continue; }
        $paras = preg_split("/\n{2,}/u", $c['text']);
        $acc = "";
        foreach ($paras as $para) {
            $t = $acc ? ($acc . "\n\n" . $para) : $para;
            if (approx_token_count($t) > 1000 && $acc) {
                $refined[] = ['text'=>$acc,'page_start'=>$c['page_start'],'page_end'=>$c['page_end'],'approx_tokens'=>approx_token_count($acc),'section'=>infer_section_title($acc)];
                $acc = $para;
            } else { $acc = $t; }
        }
        if ($acc) $refined[] = ['text'=>$acc,'page_start'=>$c['page_start'],'page_end'=>$c['page_end'],'approx_tokens'=>approx_token_count($acc),'section'=>infer_section_title($acc)];
    }
    return $refined;
}

function create_embeddings_batch(array $texts, string $model, string $apiKey): array {
    $payload = ['model' => $model, 'input' => array_values($texts)];
    $ch = curl_init("https://api.openai.com/v1/embeddings");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer $apiKey"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new Exception("cURL error: " . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) throw new Exception("Embeddings API error ($code): $res");
    $json = json_decode($res, true);
    $out  = [];
    foreach ($json['data'] ?? [] as $row) $out[] = $row['embedding'] ?? null;
    return $out;
}

function roman_to_int(string $roman): int {
    $map   = ['I'=>1,'V'=>5,'X'=>10,'L'=>50,'C'=>100,'D'=>500,'M'=>1000];
    $roman = strtoupper($roman);
    $total = 0; $prev = 0;
    for ($i = strlen($roman) - 1; $i >= 0; $i--) {
        $curr = $map[$roman[$i]] ?? 0;
        if ($curr < $prev) $total -= $curr; else $total += $curr;
        $prev = $curr;
    }
    return $total;
}

function detect_header_footer_label(string $txt): ?string {
    $lines = preg_split('/\n/u', trim($txt));
    if (!$lines) return null;
    $candidates = array_merge(array_slice($lines, 0, 2), array_slice($lines, -2));
    foreach ($candidates as $l) {
        $l = trim($l);
        if ($l === '') continue;
        if (preg_match('/^\d{1,4}$/', $l))      return $l;
        if (preg_match('/^[ivxlcdm]+$/i', $l))   return $l;
    }
    return null;
}

function recompute_chunk_display_ranges(PDO $db, int $itemId): void {
    $stmt = $db->prepare("SELECT display_offset FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $dispOffset = (int)$stmt->fetchColumn();

    $q = $db->prepare("SELECT id, page_start, page_end FROM chunks WHERE item_id = ?");
    $q->execute([$itemId]);

    $sel = $db->prepare("SELECT display_label, display_number FROM page_map WHERE item_id = ? AND pdf_page = ?");
    $upd = $db->prepare("UPDATE chunks SET display_start = ?, display_end = ?, display_start_label = ?, display_end_label = ? WHERE id = ?");

    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $s = (int)$row['page_start']; $e = (int)$row['page_end'];

        $sel->execute([$itemId, $s]); $start = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
        $sel->execute([$itemId, $e]); $end   = $sel->fetch(PDO::FETCH_ASSOC) ?: [];

        $startLabel = $start['display_label'] ?? null;
        $startNum   = $start['display_number'] ?? null;
        if ($startLabel === null) { $startNum = $s + $dispOffset; $startLabel = (string)$startNum; }
        if ($startNum === null && preg_match('/^[ivxlcdm]+$/i', $startLabel)) $startNum = roman_to_int($startLabel);

        $endLabel = $end['display_label'] ?? null;
        $endNum   = $end['display_number'] ?? null;
        if ($endLabel === null) { $endNum = $e + $dispOffset; $endLabel = (string)$endNum; }
        if ($endNum === null && preg_match('/^[ivxlcdm]+$/i', $endLabel)) $endNum = roman_to_int($endLabel);

        $upd->execute([$startNum, $endNum, $startLabel, $endLabel, (int)$row['id']]);
    }
}
?>
