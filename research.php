<?php
require_once 'db.php';
require_once 'cache.php';
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
$shelfName  = isset($_GET['shelf']) ? trim((string)$_GET['shelf']) : 'school';
$bookId     = isset($_GET['book']) ? (int)$_GET['book'] : 0;
$ctx        = 5;     // keep exactly -C5 like your original
$case       = 'i';   // keep -i like your original

/** Collect directories for the selected shelf */
$libraryPath     = rtrim(getLibraryPath(), '/'); // absolute FS path (inside web root)
$libraryWebPath  = rtrim(getLibraryWebPath(), '/');
$pdo             = getDatabaseConnection();
$shelves = getCachedShelves($pdo);
$shelfList = array_column($shelves, 'value');
if (!in_array($shelfName, $shelfList, true)) {
    $shelfName = $shelfList[0] ?? '';
}
$shelfId = getCustomColumnId($pdo, 'shelf');
$shelfTable = "custom_column_{$shelfId}";
$shelfLinkTable = "books_custom_column_{$shelfId}_link";

$stmt = $pdo->prepare(
    "SELECT b.id, b.title, b.path
     FROM books b
     JOIN {$shelfLinkTable} sl ON sl.book = b.id
     JOIN {$shelfTable} sv ON sl.value = sv.id
     WHERE sv.value = :shelf
     ORDER BY b.title"
);
$stmt->execute([':shelf' => $shelfName]);

$books = [];
$bookDirs = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $books[] = $row;
    if ($bookId && (int)$row['id'] !== $bookId) continue;
    $full = $libraryPath . '/' . $row['path'];
    if (is_dir($full)) $bookDirs[] = $full;
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Research</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <style>
        pre { white-space: pre-wrap; }
        mark { padding: 0 .1em; }
    </style>
</head>
<body class="pt-5">
<?php include 'research/navbar.php'; ?>
<div class="container my-4">
    <h1>Research</h1>

    <form class="mb-4" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-8">
                <label class="form-label">Query</label>
                <input type="text" class="form-control" name="q" autofocus
                       placeholder="Search inside books..."
                       value="<?= htmlspecialchars($searchTerm) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Shelf</label>
                <select class="form-select" name="shelf">
                    <?php foreach ($shelfList as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"<?= $s === $shelfName ? ' selected' : '' ?>>
                            <?= htmlspecialchars($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label d-block">&nbsp;</label>
                <button class="btn btn-primary w-100" type="submit">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>Search
                </button>
            </div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col-md-12">
                <label class="form-label">Book</label>
                <select class="form-select" name="book">
                    <option value="0">All</option>
                    <?php foreach ($books as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === $bookId ? ' selected' : '' ?>>
                            <?= htmlspecialchars($b['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <?php if ($searchTerm !== ''): ?>
        <?php if ($results): ?>
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
                <div class="mb-4">
                    <h5><a href="<?= htmlspecialchars($fileUrl) ?>"><?= htmlspecialchars($display) ?></a></h5>

                    <?php foreach ($blocks as $blk): ?>
                        <?php
                            $hasPage = ($blk['page'] !== null);
                            $openAt  = $fileUrl . ($hasPage ? '#page=' . (int)$blk['page'] : '');
                        ?>
                        <div class="border rounded mb-3">
                            <div class="bg-light px-2 py-1 small d-flex justify-content-between align-items-center">
                                <span class="fw-bold">
                                    <?= $hasPage ? 'Page ' . (int)$blk['page'] : 'Match' ?>
                                </span>
                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($openAt) ?>">
                                    <?= $hasPage ? 'Open at page ' . (int)$blk['page'] : 'Open file' ?>
                                </a>
                            </div>
                            <pre class="m-0 p-2"><?php
                                foreach ($blk['lines'] as $L) {
                                    echo ($L['is_match'] ? '▶ ' : '  ') . $L['html'] . "\n";
                                }
                            ?></pre>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-warning">No results found.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>

