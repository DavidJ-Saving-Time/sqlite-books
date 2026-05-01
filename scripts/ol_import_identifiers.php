<?php
/**
 * For every book that already has a real OLID, fetch its editions from
 * OpenLibrary and import any identifiers (ISBN, LibraryThing, Amazon,
 * Google Books, OCLC, Goodreads, etc.) that are not yet in the Calibre identifiers table.
 *
 * Usage:
 *   php scripts/ol_import_identifiers.php <username> [--dry-run] [--force] [--delay=N]
 *
 * Options:
 *   --dry-run   Show what would be written without touching the DB
 *   --force     Overwrite identifiers that already exist in the DB
 *   --delay=N   Seconds to sleep between OL requests (default: 1)
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI only\n");
}

// ── Args ──────────────────────────────────────────────────────────────────────
$args          = array_slice($argv, 1);
$user          = null;
$dryRun        = false;
$force         = false;
$delay         = 1;
$preferEnglish = true;   // default: prefer English-language editions

foreach ($args as $arg) {
    if ($arg === '--dry-run')                      { $dryRun        = true; }
    elseif ($arg === '--force')                    { $force         = true; }
    elseif ($arg === '--prefer-english')           { $preferEnglish = true; }
    elseif ($arg === '--no-prefer-english')        { $preferEnglish = false; }
    elseif (str_starts_with($arg, '--delay='))     { $delay         = max(0, (int)substr($arg, 8)); }
    elseif (!str_starts_with($arg, '-'))           { $user          = $arg; }
}

if ($user === null) {
    fwrite(STDERR, "Usage: php ol_import_identifiers.php <username> [--dry-run] [--force] [--delay=N]\n");
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

// ── Fetch books that have a real OLID ─────────────────────────────────────────
$skipClause = $force ? '' : "AND b.id NOT IN (SELECT book FROM identifiers WHERE type = 'ol_ids_fetched')";

$books = $pdo->query("
    SELECT b.id, b.title, i.val AS olid
    FROM books b
    JOIN identifiers i ON i.book = b.id AND i.type = 'olid'
    WHERE i.val != 'NOT_FOUND'
    $skipClause
    ORDER BY b.title COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($books);
echo "User: $user | DB: $dbPath\n";
echo "Books with OLIDs: $total" . ($dryRun ? " [DRY RUN]" : "") . ($force ? " [--force, re-fetching all]" : " [skipping already-fetched]") . ($preferEnglish ? " [prefer English]" : "") . "\n";
echo str_repeat('─', 70) . "\n";

// ── Calibre type → OL identifier key mapping ──────────────────────────────────
// OL stores these under edition['identifiers'][key] as arrays
// isbn_10 and isbn_13 are top-level on the edition, not inside 'identifiers'
const OL_ID_MAP = [
    'librarything' => 'librarything',
    'amazon'      => 'amazon',
    'google'      => 'google',
    'goodreads'   => 'goodreads',
    'oclc'        => 'oclc_numbers', // top-level array on edition
];

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

/**
 * Return true if an OL edition is English (or has no language listed).
 * No-language editions are treated as English since most un-tagged OL records are English.
 */
function is_english_edition(array $ed): bool {
    $langs = $ed['languages'] ?? [];
    if (empty($langs)) return true;
    foreach ($langs as $lang) {
        if (($lang['key'] ?? '') === '/languages/eng') return true;
    }
    return false;
}

/**
 * Collect identifiers from all editions of a work.
 * Returns ['calibre_type' => 'value', ...] — one value per type, first found wins.
 * When $preferEnglish is true, English-language editions are checked first.
 */
function collect_identifiers(string $workOlid, bool $preferEnglish = true): array {
    $found = [];

    // If it's an edition OLID (OL...M), follow it to the work first
    if (preg_match('/^OL\d+M$/', $workOlid)) {
        $ed      = ol_fetch("https://openlibrary.org/books/{$workOlid}.json");
        $workKey = $ed['works'][0]['key'] ?? '';
        if (!$workKey) return [];
        $workOlid = ltrim(str_replace('/works/', '', $workKey), '/');
    }

    // Fetch up to 50 editions so we have a good chance of finding English ones
    $data = ol_fetch("https://openlibrary.org/works/{$workOlid}/editions.json?limit=50");
    if (!$data) return [];

    $editions = $data['entries'] ?? [];

    // Sort English editions to the front so identifiers are taken from them first
    if ($preferEnglish && count($editions) > 1) {
        usort($editions, fn($a, $b) => (int)!is_english_edition($a) - (int)!is_english_edition($b));
    }

    foreach ($editions as $ed) {
        // ISBN-10 and ISBN-13 are top-level arrays
        if (!isset($found['isbn']) && !empty($ed['isbn_10'])) {
            $found['isbn'] = $ed['isbn_10'][0];
        }
        if (!isset($found['isbn13']) && !empty($ed['isbn_13'])) {
            $found['isbn13'] = $ed['isbn_13'][0];
        }

        // identifiers object
        $ids = $ed['identifiers'] ?? [];
        foreach (OL_ID_MAP as $calibreType => $olKey) {
            if (!isset($found[$calibreType]) && !empty($ids[$olKey])) {
                $found[$calibreType] = (string)$ids[$olKey][0];
            }
        }

        // oclc_numbers is top-level
        if (!isset($found['oclc']) && !empty($ed['oclc_numbers'])) {
            $found['oclc'] = (string)$ed['oclc_numbers'][0];
        }

        // Stop early if we have everything
        $allKeys = array_merge(['isbn', 'isbn13'], array_keys(OL_ID_MAP));
        if (count($found) === count($allKeys)) break;
    }

    return $found;
}

// ── Prepare statements ────────────────────────────────────────────────────────
$existsStmt = $pdo->prepare(
    "SELECT val FROM identifiers WHERE book = :book AND type = :type"
);
$insertStmt = $pdo->prepare(
    "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (:book, :type, :val)"
);
$markStmt = $pdo->prepare(
    "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (:book, 'ol_ids_fetched', '1')"
);

// ── Main loop ─────────────────────────────────────────────────────────────────
$totalWritten = 0;
$totalSkipped = 0;

foreach ($books as $i => $book) {
    $num   = $i + 1;
    $id    = (int)$book['id'];
    $olid  = $book['olid'];
    $title = $book['title'];

    echo sprintf("[%d/%d] %s (%s)\n", $num, $total, mb_substr($title, 0, 55), $olid);

    $found = collect_identifiers($olid, $preferEnglish);

    if (empty($found)) {
        echo "       — No identifiers found on OL\n";
    } else {
        foreach ($found as $type => $val) {
            $existsStmt->execute([':book' => $id, ':type' => $type]);
            $existing = $existsStmt->fetchColumn();

            if ($existing !== false && !$force) {
                echo "       · $type already set ($existing) — skipping\n";
                $totalSkipped++;
                continue;
            }

            $action = ($existing !== false) ? 'overwrite' : 'new';
            echo "       + $type = $val" . ($action === 'overwrite' ? " (was: $existing)" : "") . "\n";

            if (!$dryRun) {
                $insertStmt->execute([':book' => $id, ':type' => $type, ':val' => $val]);
            }
            $totalWritten++;
        }
    }

    if (!$dryRun) {
        $markStmt->execute([':book' => $id]);
    }

    if ($num < $total) {
        sleep($delay);
    }
}

echo str_repeat('─', 70) . "\n";
$label = $dryRun ? " [DRY RUN — nothing written]" : "";
echo "Done. Written: $totalWritten | Skipped (already set): $totalSkipped{$label}\n";
