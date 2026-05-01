<?php
/**
 * SSE stream: auto-ingest ebook files from the autobooks directory into the library.
 *
 * GET (no params)
 *
 * Events emitted:
 *   status       {message}
 *   scan_done    {count}
 *   processing   {n, total, filename, title, author}
 *   duplicate    {n, total, filename, title, author, existing_id, existing_title}
 *   ingest_ok    {n, total, filename, title, author, book_id}
 *   ingest_error {n, total, filename, title, author, error}
 *   done         {processed, ingested, skipped, errors}
 *   error        {message}
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();
require_once __DIR__ . '/../lib/book_ingest.php';
require_once __DIR__ . '/../lib/loc_helper.php';

set_time_limit(0);
ignore_user_abort(true);

define('AUTOBOOKS_DIR', '/mnt/library/autobooks');
define('INGEST_SUPPORTED_EXTS', ['epub', 'mobi', 'azw3', 'kfx', 'rar', 'zip']);

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── Metadata extraction ───────────────────────────────────────────────────────

function parseEbookMeta(string $filePath): array
{
    $out = shell_exec('LANG=C ebook-meta ' . escapeshellarg($filePath) . ' 2>/dev/null');
    if (!$out) return [];

    $result     = [];
    $currentKey = null;
    $out        = preg_replace('/\x1b\[[0-9;]*m/', '', $out);

    foreach (preg_split("/\r\n|\r|\n/", $out) as $line) {
        if (preg_match('/^\s*([^:]+?)\s*:\s*(.*)$/', $line, $m)) {
            $k = strtolower(trim($m[1]));
            $v = trim($m[2]);
            if ($k === 'author(s)') $k = 'authors';
            if ($k === 'series' && preg_match('/^(.*)\[(.+)\]\s*$/', $v, $sm)) {
                $v = trim($sm[1]);
            }
            $result[$k] = $v;
            $currentKey = $k;
        } elseif ($currentKey === 'comments') {
            $t = trim($line);
            if ($t !== '') $result['comments'] = trim(($result['comments'] ?? '') . "\n" . $t);
        }
    }

    if (!empty($result['authors'])) {
        $clean  = preg_replace('/\[[^\]]*\]/', '', $result['authors']);
        $auths  = array_filter(array_map('trim', preg_split('/\s*&\s*|\s+and\s+/', $clean)), 'strlen');
        // Flip "Surname, Firstname" to "Firstname Surname"
        $auths  = array_map(function ($a) {
            $parts = explode(',', $a, 2);
            return count($parts) === 2
                ? trim($parts[1]) . ' ' . trim($parts[0])
                : $a;
        }, array_values($auths));
        $result['authors'] = $auths;
    }

    return $result;
}

/**
 * Parse "Author - [Series -] Title.ext" filename.
 * Splits on ALL " - " separators: first part is author, last part is title,
 * middle parts (if any) are treated as series and ignored for ingestion.
 */
function parseIngestFilename(string $fname): array
{
    $base = pathinfo($fname, PATHINFO_FILENAME);
    $base = preg_replace('/\s*\([a-z0-9]+\)$/i', '', $base); // strip trailing "(epub)"
    $base = preg_replace('/(\s*\([^)]*\))+$/', '', $base);    // strip trailing (...)
    $base = trim($base);

    $parts = preg_split('/ - /', $base);
    if (count($parts) < 2) return ['title' => $base, 'author' => '', 'series' => ''];

    $author = trim($parts[0]);
    $title  = trim($parts[count($parts) - 1]);
    $series = count($parts) > 2
        ? trim(implode(' - ', array_slice($parts, 1, -1)))
        : '';

    $title = preg_replace('/^\[[^\]]*\]\s*-\s*/', '', $title); // strip leading [tag] -

    // Tier 1a: ALL CAPS "SURNAME, FIRSTNAME" → "Firstname Surname"
    if (preg_match('/^([A-Z][A-Z]+),\s*([A-Z][A-Za-z\s.]+)$/', $author, $m)) {
        $author = ucwords(strtolower(trim($m[2]))) . ' ' . ucwords(strtolower(trim($m[1])));
    }

    // Tier 1b: strip .txt / .html conversion artefacts from title
    $title = preg_replace('/\s*\.(?:txt|html?)\b.*/i', '', $title);

    // Tier 1c: move trailing article to front ("Shining, The" → "The Shining")
    $title = preg_replace('/^(.*),\s*(The|A|An)$/i', '$2 $1', $title);
    $title = preg_replace('/^(.+)\s+(The|A|An)$/i', '$2 $1', $title);

    return ['title' => trim($title), 'author' => $author, 'series' => $series];
}

/**
 * Word-overlap similarity between two strings. Returns 0.0–1.0.
 * Only words ≥3 chars are considered; case-insensitive.
 */
function wordSim(string $a, string $b): float
{
    $words = fn(string $s) => array_unique(array_values(array_filter(
        preg_split('/[\s,.\-]+/', strtolower($s)),
        fn($w) => strlen($w) >= 3
    )));
    $wa = $words($a);
    $wb = $words($b);
    if (empty($wa) || empty($wb)) return 0.0;
    $common = count(array_intersect($wa, $wb));
    return $common / max(count($wa), count($wb));
}

/**
 * Detect and correct transposed title/author using the filename as ground truth.
 *
 * Returns [$resolvedTitle, $resolvedAuthor, $wasTransposed].
 * If the swapped assignment scores noticeably better against the filename,
 * title and author are flipped. A minimum swapped score of 0.35 is required
 * to avoid swapping when neither orientation matches well.
 */
function resolveMetaFromFilename(
    string $metaTitle, string $metaAuthor,
    string $fnTitle,   string $fnAuthor
): array {
    // If either side of meta is empty, no useful comparison possible
    if ($metaTitle === '' || $metaAuthor === '') {
        $title  = $metaTitle  !== '' ? $metaTitle  : $fnTitle;
        $author = $metaAuthor !== '' ? $metaAuthor : $fnAuthor;
        return [$title, $author, false];
    }

    $normalScore  = wordSim($metaTitle, $fnTitle)  + wordSim($metaAuthor, $fnAuthor);
    $swappedScore = wordSim($metaTitle, $fnAuthor) + wordSim($metaAuthor, $fnTitle);

    if ($swappedScore > $normalScore && $swappedScore >= 0.35) {
        // Meta has title and author reversed relative to the filename
        return [$metaAuthor, $metaTitle, true];
    }

    return [$metaTitle, $metaAuthor, false];
}

function cleanTitle(string $t): string
{
    $t = preg_replace('/\s*\([^)]*\)/u', '', $t); // strip (anything)
    $t = preg_replace('/\s*:.*$/u', '', $t);       // strip subtitle after colon
    return trim($t);
}

// ── Duplicate detection ───────────────────────────────────────────────────────

function normTitleForDupe(string $t): string
{
    $t = strtolower(trim($t));
    $t = preg_replace('/\([^)]*\)/', '', $t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    $t = trim(preg_replace('/^(the|a|an)\s+/', '', trim($t)));
    return $t;
}

/**
 * Returns true if the author strings share at least one significant word (≥4 chars).
 * Handles "Dean Koontz" matching "Dean R. Koontz", initials stripped, etc.
 */
function authorsMatch(string $a, string $b): bool
{
    $words = fn(string $s) => array_filter(
        preg_split('/[\s,.]+/', strtolower($s)),
        fn($w) => strlen($w) >= 4
    );
    $wa = $words($a);
    $wb = $words($b);
    foreach ($wa as $w) {
        if (in_array($w, $wb)) return true;
    }
    return false;
}

/**
 * Look up a duplicate from pre-built in-memory indexes (built once before the main loop).
 * $exactIndex: strtolower(title) → [rows]
 * $normIndex:  normTitleForDupe(title) → [rows]
 */
function findDuplicate(array $exactIndex, array $normIndex, string $title, string $author): ?array
{
    foreach ($exactIndex[strtolower($title)] ?? [] as $row) {
        if (authorsMatch($author, $row['authors'] ?? '')) return $row;
    }

    $norm = normTitleForDupe($title);
    if ($norm === '') return null;
    foreach ($normIndex[$norm] ?? [] as $row) {
        if (authorsMatch($author, $row['authors'] ?? '')) return $row;
    }

    return null;
}

// ── OL-mirror helpers (Tier 2 / 3) ───────────────────────────────────────────

/**
 * Build an ILIKE pattern from an author name: all name parts joined with %.
 * If the first part is a single initial, the pattern has no leading %.
 */
function buildOLAuthorPattern(string $author): string
{
    $parts = preg_split('/[\s.]+/', strtolower($author), -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_values(array_filter($parts));
    if (count($parts) < 2) return '';
    $firstIsInitial = strlen($parts[0]) === 1;
    return ($firstIsInitial ? '' : '%') . implode('%', $parts) . '%';
}

/**
 * Tier 2: query OL authors by name pattern; return the most-prolific name only
 * when it has ≥2× the works of the next candidate (clear dominant).
 */
function canonicalizeAuthorViaOL(PDO $pg, string $author): ?string
{
    $pattern = buildOLAuthorPattern($author);
    if ($pattern === '') return null;
    try {
        $pg->exec("SET statement_timeout = '3000'");
        $stmt = $pg->prepare("
            SELECT a.data->>'name' AS name, COUNT(aw.work_key) AS wc
            FROM authors a
            JOIN author_works aw ON aw.author_key = a.key
            WHERE a.data->>'name' ILIKE ?
            GROUP BY a.key, a.data->>'name'
            ORDER BY wc DESC
            LIMIT 10
        ");
        $stmt->execute([$pattern]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    if (empty($rows)) return null;
    if (count($rows) === 1) return $rows[0]['name'];
    if ((int)$rows[0]['wc'] >= 2 * (int)$rows[1]['wc']) return $rows[0]['name'];
    return null;
}

/**
 * Tier 3: two-phase OL work lookup.
 * Phase 1: get work keys for matching author (indexed, fast).
 * Phase 2: fetch those works and PHP-filter by title words.
 * Returns ['title' => canonical OL title, 'olid' => 'OL12345W'] or null.
 */
function olLookupWork(PDO $pg, string $title, string $author): ?array
{
    if ($title === '' || $author === '') return null;
    $pattern = buildOLAuthorPattern($author);
    if ($pattern === '') return null;

    try {
        $pg->exec("SET statement_timeout = '4000'");
        $stmt = $pg->prepare("
            SELECT aw.work_key
            FROM authors a
            JOIN author_works aw ON aw.author_key = a.key
            WHERE a.data->>'name' ILIKE ?
            LIMIT 500
        ");
        $stmt->execute([$pattern]);
        $workKeys = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'work_key');
    } catch (Exception $e) {
        return null;
    }
    if (empty($workKeys)) return null;

    $normSearch  = normTitleForDupe($title);
    if ($normSearch === '') return null;
    $searchWords = array_values(array_filter(
        preg_split('/\s+/', $normSearch),
        fn($w) => strlen($w) >= 3
    ));
    if (empty($searchWords)) return null;

    try {
        $placeholders = implode(',', array_fill(0, count($workKeys), '?'));
        $stmt = $pg->prepare("SELECT key, data->>'title' AS title FROM works WHERE key IN ($placeholders)");
        $stmt->execute($workKeys);
        $works = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }

    foreach ($works as $work) {
        $normWork = normTitleForDupe($work['title'] ?? '');
        if ($normWork === '') continue;

        // Forward check: all library title words must appear in OL title
        $matched = true;
        foreach ($searchWords as $word) {
            if (!str_contains($normWork, $word)) { $matched = false; break; }
        }
        if (!$matched) continue;

        // Reverse check: library title words must cover a meaningful fraction of
        // OL title words — prevents "dark" matching "Tales of the Dark III".
        // Single-word searches are the danger zone: require ≥50% coverage of OL words.
        $workWords = array_values(array_filter(
            preg_split('/\s+/', $normWork), fn($w) => strlen($w) >= 3
        ));
        if (!empty($workWords)) {
            $coverage = count(array_intersect($searchWords, $workWords)) / count($workWords);
            if (count($searchWords) === 1 && $coverage < 0.5) continue;
            // Multi-word: OL title shouldn't have more than 3× the library title's word count
            if (count($searchWords) > 1 && count($workWords) > count($searchWords) * 3) continue;
        }

        return ['title' => $work['title'], 'olid' => basename($work['key'])];
    }

    return null;
}

// ── Scan ──────────────────────────────────────────────────────────────────────

if (!is_dir(AUTOBOOKS_DIR)) {
    sse('error', ['message' => 'Autobooks directory not found: ' . AUTOBOOKS_DIR]);
    exit;
}

sse('status', ['message' => 'Scanning ' . AUTOBOOKS_DIR . '…']);

$files = [];
foreach (new DirectoryIterator(AUTOBOOKS_DIR) as $entry) {
    if ($entry->isDot() || !$entry->isFile()) continue;
    $ext = strtolower($entry->getExtension());
    if (!in_array($ext, INGEST_SUPPORTED_EXTS)) continue;
    $files[] = ['path' => $entry->getPathname(), 'name' => $entry->getFilename(), 'ext' => $ext];
}
sort($files); // deterministic order

sse('scan_done', ['count' => count($files)]);

if (empty($files)) {
    sse('done', ['processed' => 0, 'ingested' => 0, 'skipped' => 0, 'errors' => 0]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
} catch (Exception $e) {
    sse('error', ['message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$libraryPath = getLibraryPath();
$total    = count($files);
$ingested = 0;
$skipped  = 0;
$errCount = 0;

// Build duplicate-detection indexes once — one DB query for the whole run
sse('status', ['message' => 'Building library index…']);
$exactIndex = [];
$normIndex  = [];
foreach ($pdo->query("
    SELECT b.id, b.title, GROUP_CONCAT(a.name, ', ') AS authors
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    GROUP BY b.id
")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $exactIndex[strtolower($row['title'])][] = $row;
    $norm = normTitleForDupe($row['title']);
    if ($norm !== '') $normIndex[$norm][] = $row;
}

// Open PG connection once for Tier 2/3 (author canonicalization + OL work lookup)
$pgPdo = null;
try {
    $pgPdo = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
    $pgPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    // OL mirror unavailable — Tier 2/3 improvements skipped
}

foreach ($files as $idx => $f) {
    $n = $idx + 1;

    // Extract archives once — the same path is used for metadata reading AND ingestion,
    // avoiding a second decompression inside processBookFromPath.
    $ingestPath    = $f['path'];
    $archiveTmpDir = null;
    if (in_array($f['ext'], ['zip', 'rar'])) {
        $extracted = extractArchiveBook($f['path'], $f['ext']);
        if ($extracted) {
            $ingestPath    = $extracted['path'];
            $archiveTmpDir = $extracted['tmpDir'];
        }
    }

    // Always parse the filename — ground truth for transpose detection and fallback
    $parsed   = parseIngestFilename($f['name']);
    $fnTitle  = cleanTitle($parsed['title']);
    $fnAuthor = normalizeAuthorName($parsed['author']);

    // Extract embedded metadata from the (possibly already-extracted) file
    $metaTitle   = '';
    $metaAuthor  = '';
    $description = '';
    if (file_exists($ingestPath)) {
        $meta        = parseEbookMeta($ingestPath);
        $metaTitle   = cleanTitle(trim($meta['title'] ?? ''));
        $rawAuthors  = $meta['authors'] ?? [];
        // Normalize here so wordSim comparison runs on equivalent strings (fix #1)
        $metaAuthor  = normalizeAuthorName(
            is_array($rawAuthors) ? implode(', ', $rawAuthors) : trim($rawAuthors)
        );
        $description = trim($meta['comments'] ?? '');
    }

    // Resolve title/author: detect transposed metadata using filename as reference
    [$title, $author, $wasTransposed] = resolveMetaFromFilename($metaTitle, $metaAuthor, $fnTitle, $fnAuthor);

    // Final fallback to filename if still empty after resolution
    if ($title  === '') $title  = $fnTitle;
    if ($author === '') $author = $fnAuthor;

    // Normalise author: fix bare initials, transliterate accents, collapse spaces
    $author = normalizeAuthorName($author);

    // Tier 2: canonicalize author via OL mirror (dominant result only)
    $olid = null;
    if ($pgPdo && $author !== '') {
        $canonical = canonicalizeAuthorViaOL($pgPdo, $author);
        if ($canonical !== null) {
            $author = normalizeAuthorName($canonical);
        }

        // Tier 3: OL work lookup → canonical title + olid
        if ($title !== '') {
            $olWork = olLookupWork($pgPdo, $title, $author);
            if ($olWork !== null) {
                $olid = $olWork['olid'];
                // Only replace title if OL title is genuinely similar to the filename
                // title (our ground truth). Guards against wrong OL matches corrupting
                // the title when the lookup finds a different book.
                $simScore = wordSim($fnTitle ?: $title, cleanTitle($olWork['title']));
                if ($simScore >= 0.5) {
                    $title = cleanTitle($olWork['title']);
                }
            }
        }
    }

    sse('processing', [
        'n' => $n, 'total' => $total,
        'filename'    => $f['name'],
        'title'       => $title,
        'author'      => $author,
        'transposed'  => $wasTransposed,
    ]);

    if ($title === '' || $author === '') {
        if ($archiveTmpDir) cleanupTmpDir($archiveTmpDir);
        sse('ingest_error', [
            'n' => $n, 'total' => $total,
            'filename' => $f['name'], 'title' => $title, 'author' => $author,
            'error' => 'Could not determine title or author — rename to "Author - Title.ext" and retry',
        ]);
        $errCount++;
        continue;
    }

    // Duplicate check
    $dup = findDuplicate($exactIndex, $normIndex, $title, $author);
    if ($dup) {
        if ($archiveTmpDir) cleanupTmpDir($archiveTmpDir);
        sse('duplicate', [
            'n' => $n, 'total' => $total,
            'filename' => $f['name'], 'title' => $title, 'author' => $author,
            'existing_id' => (int)$dup['id'], 'existing_title' => $dup['title'],
        ]);
        $skipped++;
        @unlink($f['path']); // remove duplicate from autobooks
        continue;
    }

    // Ingest — pass the already-extracted path (no second decompression for archives).
    // For archives we manage deletion ourselves; for plain files processBookFromPath does it.
    $result = processBookFromPath(
        $pdo, $libraryPath,
        $title, $author,
        '', '', '', $description,
        $ingestPath,
        true,                   // $autoIngest — adds identifiers row
        $archiveTmpDir === null // $deleteSrc — only for non-archives
    );

    // Archive cleanup: tmpDir always removed; original archive deleted only on success
    if ($archiveTmpDir) {
        cleanupTmpDir($archiveTmpDir);
        if (empty($result['errors'])) {
            @unlink($f['path']);
        }
    }

    if (empty($result['errors'])) {
        $ingested++;
        $bookId = (int)($result['book_id'] ?? 0);

        // Write OL identifier if Tier 3 found a match
        if ($olid !== null && $bookId) {
            try {
                $pdo->prepare("INSERT OR IGNORE INTO identifiers (book, type, val) VALUES (?, 'olid', ?)")
                    ->execute([$bookId, $olid]);
            } catch (Exception $e) { /* non-fatal */ }
        }

        // LOC lookup — same scoring rules as loc_import_stream.php
        // ≥70: auto-apply (lccn, lcc, isbns, subjects, genres, loc_checked)
        // 40–69: leave unchecked so next bulk LOC import run picks it up for review
        // <40: save loc_checked (not found)
        $locLccn  = '';
        $locScore = 0;
        if ($bookId && $title !== '' && $author !== '') {
            $locResults = locQuerySRU($title, extractSurname($author), 5);
            if (is_array($locResults) && !empty($locResults)) {
                $bestScore  = -1;
                $bestResult = null;
                foreach ($locResults as $loc) {
                    $s = scoreLocResult($loc, $title, $author, []);
                    if ($s > $bestScore) { $bestScore = $s; $bestResult = $loc; }
                }
                if ($bestScore >= 70 && $bestResult !== null) {
                    try {
                        applyLocData($pdo, $bookId, $bestResult, date('Y-m-d'));
                        $locLccn  = $bestResult['lccn'] ?? '';
                        $locScore = $bestScore;
                    } catch (Exception $e) { /* non-fatal */ }
                } elseif ($bestScore < 40) {
                    try {
                        $pdo->prepare("INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'loc_checked', ?)")
                            ->execute([$bookId, date('Y-m-d')]);
                    } catch (Exception $e) { /* non-fatal */ }
                }
                // 40–69: no action — bulk LOC import will queue for review
            }
        }

        sse('ingest_ok', [
            'n' => $n, 'total' => $total,
            'filename'   => $f['name'], 'title' => $title, 'author' => $author,
            'book_id'    => $bookId,
            'transposed' => $wasTransposed,
            'olid'       => $olid,
            'lccn'       => $locLccn,
            'loc_score'  => $locScore,
        ]);
    } else {
        $errCount++;
        sse('ingest_error', [
            'n' => $n, 'total' => $total,
            'filename' => $f['name'], 'title' => $title, 'author' => $author,
            'error' => implode('; ', $result['errors']),
        ]);
    }
}

sse('done', [
    'processed' => $total,
    'ingested'  => $ingested,
    'skipped'   => $skipped,
    'errors'    => $errCount,
]);
