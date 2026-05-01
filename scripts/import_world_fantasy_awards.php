<?php
/**
 * Parses world-fantasy-award.txt and imports winners into book_awards.
 *
 * File format (tab-separated):
 *   YEAR<TAB>Title, Author
 *   YEAR<TAB>(tie) Title, Author
 *
 * Every entry is a winner. Ties are handled naturally (multiple rows per year).
 *
 * CLI:  php scripts/import_world_fantasy_awards.php [/path/to/metadata.db]
 * Web:  load in browser while logged in (uses current user's library DB)
 *
 * Award created: "World Fantasy Award"
 *
 * Run init_schema.php first to ensure the awards tables exist.
 */

$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    if (!empty($argv[1]) && file_exists($argv[1])) {
        $pdo = new PDO('sqlite:' . $argv[1]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        require_once __DIR__ . '/../db.php';
        $pdo = getDatabaseConnection();
    }
} else {
    require_once __DIR__ . '/../db.php';
    requireLogin();
    $pdo = getDatabaseConnection();
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Ensure tables exist ───────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS awards (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS book_awards (
    id       INTEGER PRIMARY KEY,
    book_id  INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    award_id INTEGER NOT NULL REFERENCES awards(id) ON DELETE CASCADE,
    year     INTEGER,
    category TEXT,
    result   TEXT NOT NULL DEFAULT 'nominated',
    UNIQUE(book_id, award_id, year, category)
)");

// ── Upsert award name ─────────────────────────────────────────────────────────
$pdo->prepare("INSERT OR IGNORE INTO awards (name) VALUES (?)")->execute(['World Fantasy Award']);
$awardId = (int)$pdo->query("SELECT id FROM awards WHERE name = 'World Fantasy Award'")->fetchColumn();

// ── Parse the text file ───────────────────────────────────────────────────────
$txtFile = __DIR__ . '/../world-fantasy-award.txt';
if (!file_exists($txtFile)) {
    die("ERROR: world-fantasy-award.txt not found at $txtFile\n");
}

$entries = [];

foreach (file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $parts = explode("\t", $line, 2);
    if (count($parts) !== 2) continue;

    $year    = (int)trim($parts[0]);
    $rest    = trim($parts[1]);

    // Strip "(tie) " prefix
    $rest = preg_replace('/^\(tie\)\s*/i', '', $rest);

    // Split "Title, Author" — title is everything before the last ", Author" segment.
    // Author names contain commas (e.g. "Robert R. McCammon") so we can't just split on
    // the first comma. Instead we split on the LAST comma.
    $lastComma = strrpos($rest, ',');
    if ($lastComma === false) continue; // no comma — can't parse, skip

    $title = trim(substr($rest, 0, $lastComma));

    if ($title === '' || $year === 0) continue;

    $entries[] = ['year' => $year, 'title' => $title];
}

// ── Match titles to library and insert ───────────────────────────────────────
$findBook = $pdo->prepare("SELECT id, title FROM books WHERE LOWER(title) = LOWER(?) LIMIT 1");
$insert   = $pdo->prepare("INSERT OR IGNORE INTO book_awards (book_id, award_id, year, category, result) VALUES (?,?,?,NULL,'won')");

$inserted = 0;
$skipped  = 0;
$notFound = [];

foreach ($entries as $e) {
    $findBook->execute([$e['title']]);
    $book = $findBook->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        $notFound[] = $e;
        continue;
    }

    $insert->execute([$book['id'], $awardId, $e['year']]);
    $changed = (int)$pdo->query("SELECT changes()")->fetchColumn();

    if ($changed > 0) {
        $inserted++;
        echo "  [+] {$e['year']} World Fantasy Award (won): {$book['title']}\n";
    } else {
        $skipped++;
        echo "  [=] {$e['year']} World Fantasy Award (won): {$book['title']} (already exists)\n";
    }
}

// ── Report unmatched titles ───────────────────────────────────────────────────
echo "\n";
echo "Inserted : $inserted\n";
echo "Skipped  : $skipped (already in DB)\n";
echo "Not found: " . count($notFound) . " (not in library)\n";

if ($notFound) {
    echo "\n--- Titles not found in your library ---\n";
    foreach ($notFound as $e) {
        echo "  [{$e['year']}] {$e['title']}\n";
    }
}

if (!$isCli) {
    require_once __DIR__ . '/../cache.php';
    invalidateCache('awards');
    echo "\nCache invalidated.\n";
}

echo "\nDone.\n";
