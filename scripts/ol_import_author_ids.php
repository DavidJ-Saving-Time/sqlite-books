<?php
/**
 * Fetches OL Author ID, Goodreads ID, and Wikidata ID for every author
 * in a user's Calibre library and stores them in author_identifiers.
 *
 * Strategy:
 *   For each author → find one of their books that has an olid →
 *   fetch the Work JSON → extract the OL author key →
 *   fetch the Author JSON → extract remote_ids →
 *   write olaid, goodreads, wikidata to author_identifiers.
 *
 * Usage:
 *   php scripts/ol_import_author_ids.php <username> [--dry-run] [--force] [--skip-partial] [--delay=N]
 *
 * Options:
 *   --dry-run       Show what would be stored without writing to the DB
 *   --force         Re-fetch all authors (ignores existing olaid/bio/photo)
 *   --skip-partial  Skip any author that already has an olaid, even if bio/photo are missing
 *                   (default: re-fetch authors with olaid but incomplete bio/photo)
 *   --delay=N       Seconds between API calls (default: 1)
 */

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$args        = array_slice($argv, 1);
$user        = null;
$dryRun      = false;
$force       = false;
$skipPartial = false;
$delay       = 1;

foreach ($args as $arg) {
    if ($arg === '--dry-run')                  { $dryRun      = true; }
    elseif ($arg === '--force')                { $force       = true; }
    elseif ($arg === '--skip-partial')         { $skipPartial = true; }
    elseif (str_starts_with($arg, '--delay=')) { $delay = max(0, (int)substr($arg, 8)); }
    elseif (!str_starts_with($arg, '-'))       { $user        = $arg; }
}

if (!$user) {
    fwrite(STDERR, "Usage: php ol_import_author_ids.php <username> [--dry-run] [--force] [--skip-partial] [--delay=N]\n");
    exit(1);
}

$usersFile = __DIR__ . '/../users.json';
$users     = json_decode(file_get_contents($usersFile), true);
if (!isset($users[$user])) { fwrite(STDERR, "Unknown user: $user\n"); exit(1); }

$dbPath = $users[$user]['prefs']['db_path'] ?? '';
if (!file_exists($dbPath)) { fwrite(STDERR, "DB not found: $dbPath\n"); exit(1); }

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

// ── HTTP helper ───────────────────────────────────────────────────────────────
function ol_get(string $url): ?array {
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
    if (!$resp || $code !== 200) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

// ── Fetch all authors that have at least one book with an olid ────────────────
if ($force) {
    $skipClause = '';
} elseif ($skipPartial) {
    // Skip any author that already has an olaid, even if bio/photo are missing
    $skipClause = "AND a.id NOT IN (SELECT author_id FROM author_identifiers WHERE type = 'olaid')";
} else {
    // Default: only skip authors that have olaid AND bio AND photo (re-fetch incomplete records)
    $skipClause = "AND a.id NOT IN (
        SELECT ai1.author_id FROM author_identifiers ai1
        WHERE ai1.type = 'olaid'
          AND EXISTS (SELECT 1 FROM author_identifiers WHERE author_id = ai1.author_id AND type = 'bio')
          AND EXISTS (SELECT 1 FROM author_identifiers WHERE author_id = ai1.author_id AND type = 'photo')
    )";
}

$authors = $pdo->query("
    SELECT a.id, a.name,
           (SELECT i.val FROM identifiers i
            JOIN books_authors_link bal2 ON bal2.book = i.book
            WHERE bal2.author = a.id AND i.type = 'olid' AND i.val != 'NOT_FOUND'
            LIMIT 1) AS sample_olid
    FROM authors a
    JOIN books_authors_link bal ON bal.author = a.id
    WHERE EXISTS (
        SELECT 1 FROM identifiers i
        JOIN books_authors_link bal3 ON bal3.book = i.book
        WHERE bal3.author = a.id AND i.type = 'olid' AND i.val != 'NOT_FOUND'
    )
    $skipClause
    GROUP BY a.id
    ORDER BY a.sort COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

$total   = count($authors);
$matched = 0;
$skipped = 0;
$failed  = 0;

echo "User   : $user\n";
echo "DB     : $dbPath\n";
echo "Authors: $total" . ($dryRun ? " [DRY RUN]" : "") . "\n";
echo str_repeat('─', 70) . "\n";

// ── Prepared statements ───────────────────────────────────────────────────────
$upsert = $pdo->prepare(
    "INSERT OR REPLACE INTO author_identifiers (author_id, type, val) VALUES (:author_id, :type, :val)"
);

function store(PDOStatement $stmt, int $authorId, string $type, string $val, bool $dryRun): void {
    if ($val === '') return;
    if ($dryRun) {
        echo "    [dry] author_identifiers($authorId, '$type', '$val')\n";
    } else {
        $stmt->execute([':author_id' => $authorId, ':type' => $type, ':val' => $val]);
    }
}

// ── Main loop ─────────────────────────────────────────────────────────────────
foreach ($authors as $i => $author) {
    $num      = $i + 1;
    $authorId = (int)$author['id'];
    $name     = $author['name'];
    $olid     = $author['sample_olid'];

    echo sprintf("[%d/%d] %s\n", $num, $total, $name);

    if (!$olid) {
        echo "       ✗ No OLID found for any book by this author\n";
        $skipped++;
        continue;
    }

    echo "       Work OLID: $olid\n";

    // Step 1: fetch Work to get OL author key
    $work = ol_get("https://openlibrary.org/works/{$olid}.json");
    sleep($delay);

    if (!$work) {
        echo "       ✗ Failed to fetch work\n";
        $failed++;
        continue;
    }

    $authorEntries = $work['authors'] ?? [];
    if (empty($authorEntries)) {
        echo "       ✗ No authors in work JSON\n";
        $failed++;
        continue;
    }

    // Pick the best matching author entry by name if multiple exist
    $authorKey = null;
    if (count($authorEntries) === 1) {
        $authorKey = $authorEntries[0]['author']['key'] ?? $authorEntries[0]['key'] ?? null;
    } else {
        // Multiple authors on the work — try to match by name via author JSON
        foreach ($authorEntries as $entry) {
            $key = $entry['author']['key'] ?? $entry['key'] ?? null;
            if (!$key) continue;
            $olaId  = preg_replace('#^/authors/#', '', $key);
            $adata  = ol_get("https://openlibrary.org/authors/{$olaId}.json");
            sleep($delay);
            if (!$adata) continue;
            $olName = strtolower(trim($adata['name'] ?? ''));
            $caName = strtolower(trim($name));
            // Accept if any word longer than 3 chars from Calibre name appears in OL name
            $words  = array_filter(explode(' ', $caName), fn($w) => strlen($w) > 3);
            foreach ($words as $word) {
                if (str_contains($olName, $word)) {
                    $authorKey = $key;
                    break 2;
                }
            }
        }
        // Fallback to first entry if nothing matched
        if (!$authorKey) {
            $authorKey = $authorEntries[0]['author']['key'] ?? $authorEntries[0]['key'] ?? null;
        }
    }

    if (!$authorKey) {
        echo "       ✗ Could not resolve author key\n";
        $failed++;
        continue;
    }

    $olaId = preg_replace('#^/authors/#', '', $authorKey);
    echo "       OL Author  : $olaId\n";

    // Step 2: fetch Author JSON
    $authorData = ol_get("https://openlibrary.org/authors/{$olaId}.json");
    sleep($delay);

    if (!$authorData) {
        echo "       ✗ Failed to fetch author JSON\n";
        $failed++;
        continue;
    }

    $remoteIds = $authorData['remote_ids'] ?? [];
    $goodreads = (string)($remoteIds['goodreads'] ?? '');
    $wikidata  = (string)($remoteIds['wikidata']  ?? '');

    // Bio
    $bioVal = $authorData['bio'] ?? '';
    $bio    = is_array($bioVal) ? (string)($bioVal['value'] ?? '') : (string)$bioVal;

    // Photo — first positive photo ID from the photos array
    $photo = '';
    foreach ((array)($authorData['photos'] ?? []) as $pid) {
        if ((int)$pid > 0) {
            $photo = "https://covers.openlibrary.org/a/id/{$pid}-L.jpg";
            break;
        }
    }

    echo "       Goodreads  : " . ($goodreads ?: '—') . "\n";
    echo "       Wikidata   : " . ($wikidata  ?: '—') . "\n";
    echo "       Bio        : " . ($bio   ? mb_substr($bio, 0, 60) . '…' : '—') . "\n";
    echo "       Photo      : " . ($photo ?: '—') . "\n";

    store($upsert, $authorId, 'olaid',     $olaId,     $dryRun);
    store($upsert, $authorId, 'goodreads', $goodreads, $dryRun);
    store($upsert, $authorId, 'wikidata',  $wikidata,  $dryRun);
    store($upsert, $authorId, 'bio',       $bio,       $dryRun);
    store($upsert, $authorId, 'photo',     $photo,     $dryRun);

    echo "       ✓ Stored\n";
    $matched++;
}

echo str_repeat('─', 70) . "\n";
echo "Done. Matched: $matched | Skipped: $skipped | Failed: $failed"
   . ($dryRun ? " [DRY RUN — nothing written]" : "") . "\n";
