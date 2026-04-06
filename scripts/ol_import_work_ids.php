<?php
/**
 * Fetches Open Library Work IDs for every book in a user's Calibre library
 * and stores them in the identifiers table (type = 'olid').
 *
 * Usage:
 *   php scripts/ol_import_work_ids.php <username> [--dry-run] [--force] [--retry-failed] [--delay=N]
 *
 * Options:
 *   --dry-run       Show what would be stored without writing to the DB
 *   --force         Re-fetch ALL books (including those already matched or marked not-found)
 *   --retry-failed  Re-fetch only books previously marked as NOT_FOUND
 *   --delay=N       Seconds to sleep between API calls (default: 1)
 *
 * Books with no OL match are stored as olid='NOT_FOUND' so they are skipped on
 * future runs unless --retry-failed or --force is passed.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI only\n");
}

// ── Args ──────────────────────────────────────────────────────────────────────
$args        = array_slice($argv, 1);
$user        = null;
$dryRun      = false;
$force       = false;
$retryFailed = false;
$delay       = 1;

foreach ($args as $arg) {
    if ($arg === '--dry-run')                    { $dryRun      = true; }
    elseif ($arg === '--force')                  { $force       = true; }
    elseif ($arg === '--retry-failed')           { $retryFailed = true; }
    elseif (str_starts_with($arg, '--delay='))   { $delay = max(0, (int)substr($arg, 8)); }
    elseif (!str_starts_with($arg, '-'))         { $user = $arg; }
}

if ($user === null) {
    fwrite(STDERR, "Usage: php ol_import_work_ids.php <username> [--dry-run] [--force] [--retry-failed] [--delay=N]\n");
    exit(1);
}

// ── Load users.json ───────────────────────────────────────────────────────────
$usersFile = __DIR__ . '/../users.json';
$users     = json_decode(file_get_contents($usersFile), true);
if (!isset($users[$user])) {
    fwrite(STDERR, "Unknown user: $user\n");
    exit(1);
}

$dbPath = $users[$user]['prefs']['db_path'] ?? '';
if ($dbPath === '' || !file_exists($dbPath)) {
    fwrite(STDERR, "DB not found for user $user: $dbPath\n");
    exit(1);
}

// ── Open DB ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (Exception $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Fetch books ───────────────────────────────────────────────────────────────
if ($force) {
    $skipClause = '';
} elseif ($retryFailed) {
    // Only books previously marked as not found
    $skipClause = "AND b.id IN (SELECT book FROM identifiers WHERE type = 'olid' AND val = 'NOT_FOUND')";
} else {
    // Skip books that already have any olid value (real or NOT_FOUND)
    $skipClause = "AND b.id NOT IN (SELECT book FROM identifiers WHERE type = 'olid')";
}

$books = $pdo->query("
    SELECT b.id, b.title,
           GROUP_CONCAT(a.name, ', ') AS authors,
           (SELECT val FROM identifiers WHERE book = b.id AND type = 'isbn' LIMIT 1) AS isbn
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    WHERE 1=1 $skipClause
    GROUP BY b.id
    ORDER BY b.title COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

$total   = count($books);
$matched = 0;
$skipped = 0;
$failed  = 0;

echo "User: $user | DB: $dbPath\n";
$modeLabel = $force ? ' [--force]' : ($retryFailed ? ' [--retry-failed]' : '');
echo "Books to process: $total" . $modeLabel . ($dryRun ? " [DRY RUN]" : "") . "\n";
echo str_repeat('─', 70) . "\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

function ol_fetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'calibre-nilla/1.0 (library management; contact via github)',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/**
 * Normalise a string for comparison: lowercase, strip punctuation, collapse spaces.
 */
function normalise(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Score a candidate result against the book we're looking for.
 * Returns 0–100. Threshold for acceptance is 50.
 */
function score_match(string $bookTitle, string $bookAuthors, array $candidate): int {
    $score = 0;

    $normBookTitle = normalise($bookTitle);
    $normCandTitle = normalise($candidate['title'] ?? '');

    // Exact title match
    if ($normBookTitle === $normCandTitle) {
        $score += 60;
    } elseif (str_contains($normCandTitle, $normBookTitle) || str_contains($normBookTitle, $normCandTitle)) {
        $score += 35;
    } else {
        // Word overlap
        $bookWords  = array_filter(explode(' ', $normBookTitle), fn($w) => strlen($w) > 2);
        $candWords  = array_filter(explode(' ', $normCandTitle), fn($w) => strlen($w) > 2);
        $overlap    = count(array_intersect($bookWords, $candWords));
        $score += min(30, $overlap * 10);
    }

    // Author match (only if we have author info)
    $candAuthors = normalise($candidate['authors'] ?? '');
    if ($bookAuthors !== '' && $candAuthors !== '') {
        $bookAuthorWords = array_filter(explode(' ', normalise($bookAuthors)), fn($w) => strlen($w) > 2);
        foreach ($bookAuthorWords as $word) {
            if (str_contains($candAuthors, $word)) {
                $score += 20;
                break;
            }
        }
    } elseif ($bookAuthors === '') {
        // No author to compare — don't penalise
        $score += 10;
    }

    return min(100, $score);
}

/**
 * Search OL by title+author and return best matching Work ID, or null.
 */
function find_work_id(string $title, string $authors, string $isbn, int $delay): ?string {
    // Try ISBN first if available
    if ($isbn !== '') {
        $data = ol_fetch('https://openlibrary.org/api/books?bibkeys=ISBN:' . urlencode($isbn) . '&format=json&jscmd=data');
        if ($data !== null && !empty($data)) {
            foreach ($data as $entry) {
                if (!empty($entry['works'][0]['key'])) {
                    $key = preg_replace('#^/works/#', '', $entry['works'][0]['key']);
                    return $key; // e.g. OL12345W
                }
            }
        }
        sleep($delay);
    }

    // Search by title + author
    $q   = $title . ($authors !== '' ? ' ' . $authors : '');
    $url = 'https://openlibrary.org/search.json?q=' . urlencode($q) . '&limit=20&fields=key,title,author_name,editions';
    $data = ol_fetch($url);
    if ($data === null || empty($data['docs'])) {
        return null;
    }

    $best      = null;
    $bestScore = 0;

    foreach ($data['docs'] as $doc) {
        $candidate = [
            'title'   => $doc['title'] ?? '',
            'authors' => implode(', ', (array)($doc['author_name'] ?? [])),
        ];
        $s = score_match($title, $authors, $candidate);
        if ($s > $bestScore) {
            $bestScore = $s;
            $best      = $doc;
        }
    }

    if ($best === null || $bestScore < 50) {
        return null;
    }

    // Extract Work ID from key e.g. "/works/OL25564014W" → "OL25564014W"
    $key = $best['key'] ?? '';
    return preg_replace('#^/works/#', '', $key) ?: null;
}

// ── Main loop ─────────────────────────────────────────────────────────────────
$insertStmt = $pdo->prepare(
    "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (:book, 'olid', :val)"
);

foreach ($books as $i => $book) {
    $num     = $i + 1;
    $title   = $book['title'];
    $authors = $book['authors'] ?? '';
    $isbn    = $book['isbn']    ?? '';
    $id      = (int)$book['id'];

    echo sprintf("[%d/%d] %-50s %s\n", $num, $total, mb_substr($title, 0, 50), $authors ? "($authors)" : '');

    $workId = find_work_id($title, $authors, $isbn, $delay);

    if ($workId === null) {
        echo "       ✗ No match — marking NOT_FOUND\n";
        if (!$dryRun) {
            $insertStmt->execute([':book' => $id, ':val' => 'NOT_FOUND']);
        }
        $failed++;
    } else {
        echo "       ✓ $workId\n";
        if (!$dryRun) {
            $insertStmt->execute([':book' => $id, ':val' => $workId]);
        }
        $matched++;
    }

    if ($num < $total) {
        sleep($delay);
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo str_repeat('─', 70) . "\n";
echo "Done. Matched: $matched | Not found: $failed" . ($dryRun ? " [DRY RUN — nothing written]" : "") . "\n";
