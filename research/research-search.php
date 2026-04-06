<?php
require_once '../db.php';
require_once '../cache.php';
requireLogin();

mb_internal_encoding('UTF-8');

/** URL-encode a filesystem path for use as an href, preserving slashes */
function url_path_encode(string $path): string {
    $parts = explode('/', ltrim($path, '/'));
    $parts = array_map('rawurlencode', $parts);
    return '/' . implode('/', $parts);
}

/** Try to extract a page number like "Page 67", "page 12:", "Page 10 of 300" */
function extract_page_num_from_text(string $line): ?int {
    if (preg_match('/\b[Pp]age\s+(\d{1,6})(?=\D|$)/u', $line, $m)) {
        $n = (int)$m[1];
        return $n > 0 ? $n : null;
    }
    return null;
}

/** Inputs */
$searchTerm = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$shelfName  = isset($_GET['shelf']) ? trim((string)$_GET['shelf']) : '';
$bookId     = isset($_GET['book']) ? (int)$_GET['book'] : 0;
$ctx        = 5;

/** Collect directories to search */
$libraryPath    = rtrim(getLibraryPath(), '/');
$libraryWebPath = rtrim(getLibraryWebPath(), '/');
$pdo            = getDatabaseConnection();

// Shelf list for the filter dropdown (best-effort — empty if column missing)
$shelfList = [];
try {
    $shelves   = getCachedShelves($pdo);
    $shelfList = array_column($shelves, 'value');
} catch (Exception $e) {}

// Validate shelf selection — '' means "All Books"
if ($shelfName !== '' && !in_array($shelfName, $shelfList, true)) {
    $shelfName = '';
}

// Fetch the book list (filtered by shelf when one is selected)
$books = [];
try {
    if ($shelfName === '') {
        $rows = $pdo->query(
            'SELECT b.id, b.title, b.path FROM books b ORDER BY b.title COLLATE NOCASE'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $shelfId        = getCustomColumnId($pdo, 'shelf');
        $shelfTable     = "custom_column_{$shelfId}";
        $shelfLinkTable = "books_custom_column_{$shelfId}_link";
        $stmt = $pdo->prepare(
            "SELECT b.id, b.title, b.path
             FROM books b
             JOIN {$shelfLinkTable} sl ON sl.book = b.id
             JOIN {$shelfTable}     sv ON sl.value = sv.id
             WHERE sv.value = :shelf
             ORDER BY b.title COLLATE NOCASE"
        );
        $stmt->execute([':shelf' => $shelfName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $books = $rows;
} catch (Exception $e) {
    $books = [];
}

// Resolve filesystem directories to pass to rga
$bookDirs = [];
foreach ($books as $row) {
    if ($bookId && (int)$row['id'] !== $bookId) continue;
    $full = $libraryPath . '/' . $row['path'];
    if (is_dir($full)) $bookDirs[] = $full;
}

// Pre-fill book autocomplete display label when a book filter is active
$prefillBookLabel = '';
if ($bookId) {
    try {
        $s = $pdo->prepare(
            'SELECT b.title, GROUP_CONCAT(a.name, ", ") AS author
             FROM books b
             LEFT JOIN books_authors_link bal ON bal.book = b.id
             LEFT JOIN authors a ON a.id = bal.author
             WHERE b.id = ? GROUP BY b.id'
        );
        $s->execute([$bookId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $prefillBookLabel = $row['title'] . ($row['author'] ? ' — ' . $row['author'] : '');
        }
    } catch (Exception $e) {}
}

/** Run your exact rga and parse JSON -> blocks */
$results = []; // $results[$file][] = ['page'=>int|null,'lines'=>[ ['is_match'=>bool,'html'=>string,'raw'=>string]... ]];

if ($searchTerm !== '' && $bookDirs) {
    // EXACT command you had (no extra flags)
    $cmd = 'env LANG=C.UTF-8 rga --json -i -n -C5 -H -- '
         . escapeshellarg($searchTerm) . ' '
         . implode(' ', array_map('escapeshellarg', $bookDirs)) . ' 2>&1';

    $output = shell_exec($cmd);

    // Collect per file lines, then build blocks around each MATCH line_number
    $files = []; // file => ['lines'=>[ln=>...], 'match_lines'=>[ln=>true]]
    if ($output) {
        // IMPORTANT: don't add --type or --max-columns filters — that broke it earlier
        foreach (preg_split("/(\r\n|\r|\n)/", $output) as $line) {
            if ($line === '') continue;
            $j = json_decode($line, true);
            if (!is_array($j) || !isset($j['type'])) continue;
            if ($j['type'] !== 'match' && $j['type'] !== 'context') continue;

            $file = $j['data']['path']['text'] ?? null;
            if (!$file) continue;

            $ln = (int)($j['data']['line_number'] ?? 0); // text line index (NOT a PDF page)
            $raw = rtrim((string)($j['data']['lines']['text'] ?? ''), "\r\n");
            $isMatch = ($j['type'] === 'match');

            // Highlight using submatches (byte offsets) when it's a match
            $html = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
            if ($isMatch && !empty($j['data']['submatches'])) {
                $out = '';
                $pos = 0;
                foreach ($j['data']['submatches'] as $sm) {
                    $s = (int)$sm['start']; $e = (int)$sm['end'];
                    if ($s < $pos) continue;
                    $out .= htmlspecialchars(substr($raw, $pos, $s - $pos), ENT_QUOTES, 'UTF-8');
                    $out .= '<mark>' . htmlspecialchars(substr($raw, $s, $e - $s), ENT_QUOTES, 'UTF-8') . '</mark>';
                    $pos = $e;
                }
                $out .= htmlspecialchars(substr($raw, $pos), ENT_QUOTES, 'UTF-8');
                $html = $out;
            }

            if (!isset($files[$file])) $files[$file] = ['lines' => [], 'match_lines' => []];

            // Keep first-seen content; upgrade context→match if same ln reappears as match
            if (!isset($files[$file]['lines'][$ln])) {
                $files[$file]['lines'][$ln] = ['raw' => $raw, 'html' => $html, 'is_match' => $isMatch];
            } else {
                if ($isMatch && !$files[$file]['lines'][$ln]['is_match']) {
                    $files[$file]['lines'][$ln]['is_match'] = true;
                    $files[$file]['lines'][$ln]['html'] = $html;
                }
            }
            if ($isMatch) $files[$file]['match_lines'][$ln] = true;
        }
    }

    // Build readable blocks: for each match line, take [ln-ctx .. ln+ctx]
    foreach ($files as $file => $data) {
        if (empty($data['match_lines'])) continue;

        ksort($data['lines'], SORT_NUMERIC);
        $matchLines = array_keys($data['match_lines']);
        sort($matchLines, SORT_NUMERIC);

        foreach ($matchLines as $mLn) {
            $start = max(1, $mLn - $ctx);
            $end   = $mLn + $ctx;

            $blockLines = [];
            for ($i = $start; $i <= $end; $i++) {
                if (isset($data['lines'][$i])) {
                    $blockLines[] = [
                        'is_match' => $data['lines'][$i]['is_match'],
                        'html'     => $data['lines'][$i]['html'],
                        'raw'      => $data['lines'][$i]['raw'],
                    ];
                }
            }
            if (!$blockLines) continue;

            // Try to infer a PDF page number from any line's text in the block
            $page = null;
            foreach ($blockLines as $BL) {
                $p = extract_page_num_from_text($BL['raw']);
                if ($p !== null) { $page = $p; break; }
            }

            $results[$file][] = [
                'page'  => $page,       // null if not detected
                'lines' => $blockLines,
            ];
        }
    }

    // Sort files naturally (case-insensitive)
    uksort($results, fn($a,$b)=>strnatcasecmp($a,$b));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Search — Research</title>
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
    <h1><i class="fa-solid fa-magnifying-glass me-2 ra-icon-sm"></i>Search</h1>
    <p>Full-text search across your library</p>
  </header>

  <form method="get">

    <div class="ra-field">
      <label class="ra-label" for="q">Query</label>
      <input type="text" class="ra-search-input" name="q" id="q" autofocus
             placeholder="Search inside books…"
             value="<?= htmlspecialchars($searchTerm) ?>">
    </div>

    <div class="ra-settings-grid ra-settings-grid--compact">
      <div>
        <label class="ra-label" for="shelf">Shelf</label>
        <div class="ra-select-wrap">
          <select class="ra-select" name="shelf" id="shelf">
            <option value=""<?= $shelfName === '' ? ' selected' : '' ?>>All Books</option>
            <?php foreach ($shelfList as $s): ?>
              <option value="<?= htmlspecialchars($s) ?>"<?= $s === $shelfName ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div>
        <label class="ra-label" for="book_search">Filter by Book</label>
        <div class="ra-book-filter">
          <input type="text" class="ra-input" id="book_search" autocomplete="off"
                 placeholder="All books — type to filter…"
                 value="<?= htmlspecialchars($prefillBookLabel) ?>">
          <input type="hidden" name="book" id="book_id" value="<?= $bookId ?: '' ?>">
          <button type="button" id="clearBook" class="ra-clear-btn--bordered" title="Clear book filter">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>
    </div>

    <button type="submit" class="ra-submit">
      <i class="fa-solid fa-magnifying-glass me-2"></i>Search the Archive
    </button>

  </form>

  <?php if ($searchTerm !== ''): ?>

    <?php if (empty($bookDirs)): ?>
      <div class="ra-error mt-4">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        No book directories found to search.
        <?= $libraryPath === '' ? 'The library path is not configured.' : 'Library: <code class="ra-text-dim">' . htmlspecialchars($libraryPath) . '</code>' ?>
      </div>

    <?php elseif ($results): ?>
      <div class="ra-section-rule"></div>

      <div class="ra-results-stats">
        <?= count($results) ?> file<?= count($results) !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
        <?= array_sum(array_map('count', $results)) ?> match<?= array_sum(array_map('count', $results)) !== 1 ? 'es' : '' ?>
        <?php if ($searchTerm !== ''): ?>
          &nbsp;·&nbsp; <span class="ra-accent"><?= htmlspecialchars($searchTerm) ?></span>
        <?php endif; ?>
      </div>

      <?php foreach ($results as $file => $blocks): ?>
        <?php
          if (strpos($file, $libraryPath . '/') === 0) {
            $relative = substr($file, strlen($libraryPath) + 1);
            $display  = $relative;
            $fileUrl  = url_path_encode($libraryWebPath . '/' . $relative);
          } else {
            $relative = $file;
            $display  = $file;
            $fileUrl  = url_path_encode($file);
          }
        ?>
        <div class="ra-search-result">
          <div class="ra-search-file-header">
            <a class="ra-search-file-name" href="<?= htmlspecialchars($fileUrl) ?>">
              <i class="fa-solid fa-file me-1 ra-text-muted"></i><?= htmlspecialchars($display) ?>
            </a>
            <span class="ra-match-count">
              <?= count($blocks) ?> match<?= count($blocks) !== 1 ? 'es' : '' ?>
            </span>
          </div>

          <?php foreach ($blocks as $blk): ?>
            <?php
              $hasPage = ($blk['page'] !== null);
              $openAt  = $fileUrl . ($hasPage ? '#page=' . (int)$blk['page'] : '');
            ?>
            <div class="ra-match-block">
              <div class="ra-match-header">
                <span class="ra-match-label">
                  <?= $hasPage ? '<i class="fa-regular fa-file-pdf me-1"></i>Page ' . (int)$blk['page'] : 'Match' ?>
                </span>
                <a class="ra-btn" href="<?= htmlspecialchars($openAt) ?>">
                  <i class="fa-solid fa-arrow-up-right-from-square me-1"></i><?= $hasPage ? 'Open at page ' . (int)$blk['page'] : 'Open file' ?>
                </a>
              </div>
              <pre class="ra-match-pre"><?php
                foreach ($blk['lines'] as $L) {
                  if ($L['is_match']) {
                    echo '<em class="ra-match-arrow">▶</em>' . $L['html'] . "\n";
                  } else {
                    echo '  ' . $L['html'] . "\n";
                  }
                }
              ?></pre>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

    <?php else: ?>
      <p class="ra-empty-msg">
        No results found for <span class="ra-accent">"<?= htmlspecialchars($searchTerm) ?>"</span>
      </p>
    <?php endif; ?>

  <?php endif; ?>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/book-autocomplete.js"></script>
<script>
const bookAc = new BookAutocomplete({
    input:  '#book_search',
    hidden: '#book_id',
});
document.getElementById('clearBook').addEventListener('click', () => bookAc.clear());
</script>
</body>
</html>

