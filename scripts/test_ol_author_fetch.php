<?php
/**
 * Test script: walks the OL Work → Author chain for a sample of books.
 *
 * Usage:
 *   php scripts/test_ol_author_fetch.php <username> [--limit=N]
 *
 * Fetches N books (default 5) that have an olid, then for each:
 *   1. Fetches the OL Work JSON
 *   2. Extracts author key(s)
 *   3. Fetches each OL Author JSON
 *   4. Dumps everything so you can see what's available
 */

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$args   = array_slice($argv, 1);
$user   = null;
$limit  = 5;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) $limit = max(1, (int)substr($arg, 8));
    elseif (!str_starts_with($arg, '-'))   $user  = $arg;
}

if (!$user) {
    fwrite(STDERR, "Usage: php test_ol_author_fetch.php <username> [--limit=N]\n");
    exit(1);
}

$usersFile = __DIR__ . '/../users.json';
$users     = json_decode(file_get_contents($usersFile), true);
if (!isset($users[$user])) { fwrite(STDERR, "Unknown user: $user\n"); exit(1); }

$dbPath = $users[$user]['prefs']['db_path'] ?? '';
if (!file_exists($dbPath)) { fwrite(STDERR, "DB not found: $dbPath\n"); exit(1); }

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Grab a sample of books that have an olid, with their authors
$books = $pdo->query("
    SELECT b.id, b.title,
           GROUP_CONCAT(a.name, ', ') AS authors,
           i.val AS olid
    FROM identifiers i
    JOIN books b ON b.id = i.book
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    WHERE i.type = 'olid'
    GROUP BY b.id
    ORDER BY RANDOM()
    LIMIT $limit
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($books)) {
    echo "No books with OLIDs found for user $user.\n";
    exit(0);
}

// ── HTTP helper ───────────────────────────────────────────────────────────────
function ol_get(string $url): ?array {
    echo "  GET $url\n";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'calibre-nilla-test/1.0',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "  HTTP $code\n";
    if (!$resp || $code !== 200) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function section(string $title): void {
    echo "\n" . str_repeat('═', 70) . "\n$title\n" . str_repeat('─', 70) . "\n";
}

function dump_field(string $label, mixed $val, int $truncate = 300): void {
    if (is_array($val)) {
        $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    $val = (string)$val;
    if (strlen($val) > $truncate) $val = substr($val, 0, $truncate) . '…';
    echo "  $label: $val\n";
}

// ── Main ──────────────────────────────────────────────────────────────────────
foreach ($books as $book) {
    section("BOOK: {$book['title']} (id={$book['id']})");
    echo "  Calibre authors : {$book['authors']}\n";
    echo "  OLID            : {$book['olid']}\n";

    // ── Step 1: Fetch Work ────────────────────────────────────────────────────
    echo "\n[Step 1] Fetch Work JSON\n";
    $workUrl  = "https://openlibrary.org/works/{$book['olid']}.json";
    $work     = ol_get($workUrl);

    if (!$work) {
        echo "  ✗ Failed to fetch work\n";
        sleep(1);
        continue;
    }

    dump_field('title',       $work['title'] ?? '');
    dump_field('description', $work['description'] ?? '');
    dump_field('subjects',    array_slice((array)($work['subjects'] ?? []), 0, 5));
    dump_field('covers',      $work['covers'] ?? []);

    $authorEntries = $work['authors'] ?? [];
    echo "  authors array   : " . json_encode($authorEntries) . "\n";

    if (empty($authorEntries)) {
        echo "  ✗ No authors in work JSON\n";
        sleep(1);
        continue;
    }

    // ── Step 2: Fetch each Author ─────────────────────────────────────────────
    foreach ($authorEntries as $entry) {
        // Works can nest author key under 'author.key' or directly under 'key'
        $authorKey = $entry['author']['key'] ?? $entry['key'] ?? null;
        if (!$authorKey) {
            echo "  ✗ Could not find author key in entry: " . json_encode($entry) . "\n";
            continue;
        }

        $authorId  = preg_replace('#^/authors/#', '', $authorKey); // e.g. OL7085760A
        echo "\n[Step 2] Fetch Author JSON — $authorId\n";

        sleep(1); // be nice to OL
        $author = ol_get("https://openlibrary.org/authors/{$authorId}.json");

        if (!$author) {
            echo "  ✗ Failed to fetch author\n";
            continue;
        }

        echo "\n  ── Raw fields available ──\n";
        foreach (array_keys($author) as $field) {
            dump_field($field, $author[$field], 200);
        }

        // Highlight the fields most useful for our author_identifiers / author enrichment
        echo "\n  ── Key fields for storage ──\n";
        dump_field('OL Author ID',  $authorId);
        dump_field('name',          $author['name'] ?? '');
        dump_field('personal_name', $author['personal_name'] ?? '');
        dump_field('birth_date',    $author['birth_date'] ?? '');
        dump_field('death_date',    $author['death_date'] ?? '');
        dump_field('bio',           $author['bio'] ?? '');
        dump_field('photos',        $author['photos'] ?? []);
        dump_field('wikipedia',     $author['wikipedia'] ?? '');
        dump_field('links',         $author['links'] ?? []);

        // remote_ids — OL bundles third-party IDs here
        $remoteIds = $author['remote_ids'] ?? [];
        dump_field('remote_ids (raw)', $remoteIds);
        $goodreads = $remoteIds['goodreads'] ?? null;
        $storageNote = $goodreads
            ? "  → Store as author_identifiers type='goodreads' val='$goodreads'"
            : "  → Not available";
        echo "  Goodreads ID    : " . ($goodreads ?? '(none)') . "\n$storageNote\n";
    }

    sleep(1);
}

echo "\n" . str_repeat('═', 70) . "\nDone.\n";
