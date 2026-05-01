<?php
/**
 * Dry-run sampler for the Open Library import pipeline.
 *
 * Picks a random sample of books, shows current identifiers, then runs the
 * OL lookup (step 1: find work ID, step 3: collect edition identifiers) and
 * shows exactly what would be added or changed. Nothing is written to the DB.
 *
 * Usage:
 *   php scripts/ol_import_test.php <username> [--count=N] [--seed=N] [--delay=N] [--book=ID]
 *
 * Options:
 *   --count=N   Number of books to sample (default: 10)
 *   --seed=N    Random seed for reproducible results
 *   --delay=N   Seconds between OL API calls (default: 1)
 *   --book=ID   Test a specific book by Calibre book ID instead of random sample
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI only\n");
}

// ── Args ──────────────────────────────────────────────────────────────────────
$args   = array_slice($argv, 1);
$user   = null;
$count  = 10;
$seed   = null;
$delay  = 1;
$bookId = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--count='))      { $count  = max(1, (int)substr($arg, 8)); }
    elseif (str_starts_with($arg, '--seed='))   { $seed   = (int)substr($arg, 7); }
    elseif (str_starts_with($arg, '--delay='))  { $delay  = max(0, (int)substr($arg, 8)); }
    elseif (str_starts_with($arg, '--book='))   { $bookId = (int)substr($arg, 7); }
    elseif (!str_starts_with($arg, '-'))        { $user   = $arg; }
}

if ($user === null) {
    fwrite(STDERR, "Usage: php ol_import_test.php <username> [--count=N] [--seed=N] [--delay=N] [--book=ID]\n");
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
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

// ── Load all books with key identifiers ──────────────────────────────────────
$books = $pdo->query("
    SELECT b.id, b.title,
           GROUP_CONCAT(a.name, ', ')                                          AS authors,
           MAX(CASE WHEN i.type = 'olid'       THEN i.val END)                 AS olid,
           MAX(CASE WHEN i.type = 'isbn'       THEN i.val END)                 AS isbn,
           MAX(CASE WHEN i.type = 'isbn13'     THEN i.val END)                 AS isbn13,
           MAX(CASE WHEN i.type = 'goodreads'  THEN i.val END)                 AS goodreads,
           MAX(CASE WHEN i.type = 'amazon'     THEN i.val END)                 AS amazon,
           MAX(CASE WHEN i.type = 'asin'       THEN i.val END)                 AS asin,
           MAX(CASE WHEN i.type = 'librarything' THEN i.val END)               AS librarything,
           MAX(CASE WHEN i.type = 'google'     THEN i.val END)                 AS google,
           MAX(CASE WHEN i.type = 'oclc'       THEN i.val END)                 AS oclc,
           MAX(CASE WHEN i.type = 'ol_ids_fetched' THEN i.val END)             AS ol_ids_fetched
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    LEFT JOIN identifiers i ON i.book = b.id
    GROUP BY b.id
    ORDER BY b.id
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($books);
if ($total === 0) {
    echo "No books found in DB.\n";
    exit(0);
}

// ── Select sample ─────────────────────────────────────────────────────────────
if ($bookId !== null) {
    // Single specific book
    $found = false;
    foreach ($books as $idx => $b) {
        if ((int)$b['id'] === $bookId) {
            $sample = [$idx];
            $found  = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "Book ID $bookId not found in DB.\n");
        exit(1);
    }
    $count = 1;
} else {
    // Random sample
    if ($seed !== null) {
        mt_srand($seed);
    }
    $indices = array_keys($books);
    shuffle($indices);
    $sample = array_slice($indices, 0, min($count, $total));
    sort($sample);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function ol_fetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'calibre-nilla/1.0 (personal library tool; principle3@gmail.com)',
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

function normalise(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function score_match(string $bookTitle, string $bookAuthors, array $candidate): int {
    $score = 0;
    $normBookTitle = normalise($bookTitle);
    $normCandTitle = normalise($candidate['title'] ?? '');
    if ($normBookTitle === $normCandTitle) {
        $score += 60;
    } elseif (str_contains($normCandTitle, $normBookTitle) || str_contains($normBookTitle, $normCandTitle)) {
        $score += 35;
    } else {
        $bookWords = array_filter(explode(' ', $normBookTitle), fn($w) => strlen($w) > 2);
        $candWords = array_filter(explode(' ', $normCandTitle), fn($w) => strlen($w) > 2);
        $overlap   = count(array_intersect($bookWords, $candWords));
        $score += min(30, $overlap * 10);
    }
    $candAuthors = normalise($candidate['authors'] ?? '');
    if ($bookAuthors !== '' && $candAuthors !== '') {
        $bookAuthorWords = array_filter(explode(' ', normalise($bookAuthors)), fn($w) => strlen($w) > 2);
        foreach ($bookAuthorWords as $word) {
            if (str_contains($candAuthors, $word)) { $score += 20; break; }
        }
    } elseif ($bookAuthors === '') {
        $score += 10;
    }
    return min(100, $score);
}

/**
 * Search OL for a work ID, return [workId|null, score, matchTitle].
 */
function find_work_id(string $title, string $authors, string $isbn): array {
    if ($isbn !== '') {
        $data = ol_fetch('https://openlibrary.org/api/books?bibkeys=ISBN:' . urlencode($isbn) . '&format=json&jscmd=data');
        if (!empty($data)) {
            foreach ($data as $entry) {
                if (!empty($entry['works'][0]['key'])) {
                    $key = preg_replace('#^/works/#', '', $entry['works'][0]['key']);
                    return [$key, 100, $title . ' (via ISBN)'];
                }
            }
        }
    }
    $q    = $title . ($authors !== '' ? ' ' . $authors : '') . ' -subject:audiobooks';
    $url  = 'https://openlibrary.org/search.json?q=' . urlencode($q) . '&limit=20&fields=key,title,author_name,language&language=eng';
    $data = ol_fetch($url);
    if (empty($data['docs'])) return [null, 0, ''];
    $best = null; $bestScore = 0; $bestTitle = '';
    foreach ($data['docs'] as $doc) {
        $candidate = [
            'title'   => $doc['title'] ?? '',
            'authors' => implode(', ', (array)($doc['author_name'] ?? [])),
        ];
        $s = score_match($title, $authors, $candidate);
        // Boost English-only works to avoid picking foreign-language works with English translations
        $langs = (array)($doc['language'] ?? []);
        if (!empty($langs) && $langs === ['eng']) {
            $s = min(100, $s + 15);
        }
        if ($s > $bestScore) {
            $bestScore = $s;
            $best      = $doc;
            $bestTitle = $candidate['title'];
        }
    }
    if ($best === null || $bestScore < 50) return [null, $bestScore, $bestTitle];
    $key = preg_replace('#^/works/#', '', $best['key'] ?? '');
    return [$key ?: null, $bestScore, $bestTitle];
}

function is_english_edition(array $ed): bool {
    $langs = $ed['languages'] ?? [];
    if (empty($langs)) return true;
    foreach ($langs as $lang) {
        if (($lang['key'] ?? '') === '/languages/eng') return true;
    }
    return false;
}

function collect_identifiers(string $workOlid): array {
    if (preg_match('/^OL\d+M$/', $workOlid)) {
        $ed      = ol_fetch("https://openlibrary.org/books/{$workOlid}.json");
        $workKey = $ed['works'][0]['key'] ?? '';
        if (!$workKey) return [];
        $workOlid = ltrim(str_replace('/works/', '', $workKey), '/');
    }
    $data = ol_fetch("https://openlibrary.org/works/{$workOlid}/editions.json?limit=50");
    if (!$data) return [];
    $editions = $data['entries'] ?? [];
    usort($editions, fn($a, $b) => (int)!is_english_edition($a) - (int)!is_english_edition($b));
    $found = [];
    $olMap = [
        'librarything' => 'librarything',
        'amazon'       => 'amazon',
        'google'       => 'google',
        'goodreads'    => 'goodreads',
    ];
    foreach ($editions as $ed) {
        if (!isset($found['isbn'])   && !empty($ed['isbn_10']))    $found['isbn']   = $ed['isbn_10'][0];
        if (!isset($found['isbn13']) && !empty($ed['isbn_13']))    $found['isbn13'] = $ed['isbn_13'][0];
        $ids = $ed['identifiers'] ?? [];
        foreach ($olMap as $calibreType => $olKey) {
            if (!isset($found[$calibreType]) && !empty($ids[$olKey])) {
                $found[$calibreType] = (string)$ids[$olKey][0];
            }
        }
        if (!isset($found['oclc']) && !empty($ed['oclc_numbers'])) {
            $found['oclc'] = (string)$ed['oclc_numbers'][0];
        }
        if (count($found) === 7) break;
    }
    return $found;
}

function val_or_none(?string $v): string {
    return ($v !== null && $v !== '') ? $v : '(none)';
}

// ── Run ───────────────────────────────────────────────────────────────────────
echo "User: $user | DB: $dbPath\n";
echo "Sampling $count of $total books [DRY RUN — no writes]\n";
echo str_repeat('─', 70) . "\n\n";

foreach (array_values($sample) as $sIdx => $bookIdx) {
    $book    = $books[$bookIdx];
    $num     = $sIdx + 1;
    $id      = (int)$book['id'];
    $title   = $book['title'];
    $authors = $book['authors'] ?? '';
    $isbn    = $book['isbn'] ?? '';
    $olid    = $book['olid'] ?? '';

    echo sprintf("[%d/%d] %s\n", $num, $count, $title);
    echo sprintf("       by %s\n", $authors ?: '(no author)');
    echo "\n";

    // ── Current identifiers ───────────────────────────────────────────────────
    $currentFields = ['olid', 'isbn', 'isbn13', 'goodreads', 'amazon', 'asin', 'librarything', 'google', 'oclc'];
    echo "  Current identifiers:\n";
    foreach ($currentFields as $f) {
        $v = $book[$f] ?? null;
        if ($v !== null && $v !== '' && $v !== 'NOT_FOUND') {
            echo sprintf("    %-14s %s\n", $f . ':', $v);
        }
    }
    $hasOlIdsFetched = !empty($book['ol_ids_fetched']);
    if ($hasOlIdsFetched) {
        echo "    ol_ids_fetched : yes (step 3 already ran)\n";
    }
    echo "\n";

    // ── Step 1: Work ID (always re-run search to check if current OLID is still best) ──
    echo "  Step 1 — Find Work ID:\n";
    [$foundId, $score, $matchTitle] = find_work_id($title, $authors, $isbn);
    if ($delay > 0) sleep($delay);

    $resolvedOlid = $olid;
    if ($olid === '' || $olid === 'NOT_FOUND') {
        if ($foundId) {
            echo "    ✓ Would set olid = $foundId  (score: $score, matched: \"$matchTitle\")\n";
            $resolvedOlid = $foundId;
        } else {
            echo "    ✗ No match found" . ($score > 0 ? " (best score: $score, closest: \"$matchTitle\")" : "") . "\n";
        }
    } else {
        // Compare existing OLID against what the updated search returns
        if ($foundId && $foundId !== $olid) {
            echo "    ⚠ Current: $olid\n";
            echo "      New search returns: $foundId  (score: $score, matched: \"$matchTitle\")\n";
            echo "      → Would be updated on a --force rerun\n";
            $resolvedOlid = $foundId;
        } elseif ($foundId === $olid) {
            echo "    ✓ Current olid confirmed: $olid  (score: $score)\n";
        } else {
            echo "    ? Current olid: $olid  (new search found no match — existing kept)\n";
        }
    }
    echo "\n";

    // ── Step 3: Edition identifiers ───────────────────────────────────────────
    if ($resolvedOlid !== '' && $resolvedOlid !== 'NOT_FOUND') {
        echo "  Step 3 — Edition identifiers from OL:\n";
        $found = collect_identifiers($resolvedOlid);
        if (empty($found)) {
            echo "    — No identifiers found on OL\n";
        } else {
            $idFields = ['isbn', 'isbn13', 'librarything', 'amazon', 'google', 'goodreads', 'oclc'];
            foreach ($idFields as $f) {
                if (!array_key_exists($f, $found)) continue;
                $olVal = $found[$f];
                $dbVal = $book[$f] ?? null;
                if ($dbVal !== null && $dbVal !== '') {
                    if ($dbVal === $olVal) {
                        echo sprintf("    ✓ %-14s DB matches OL (%s)\n", $f . ':', $dbVal);
                    } else {
                        echo sprintf("    ⚠ %-14s DB has %s — OL would set %s (needs --force to fix)\n", $f . ':', $dbVal, $olVal);
                    }
                } else {
                    echo sprintf("    + %-14s would set = %s\n", $f . ':', $olVal);
                }
            }
        }
        if ($delay > 0) sleep($delay);
    } else {
        echo "  Step 3 — Skipped (no OLID available)\n";
    }

    echo "\n" . str_repeat('─', 70) . "\n\n";
}

echo "Done. Sample complete — nothing was written to the DB.\n";
