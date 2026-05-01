<?php
/**
 * Parses gemmell-award-winners.txt and imports entries into book_awards.
 *
 * CLI:  php scripts/import_gemmell_awards.php [/path/to/metadata.db]
 * Web:  load in browser while logged in (uses current user's library DB)
 *
 * Books are matched by title (case-insensitive exact match).
 * Unmatched titles are reported at the end — nothing is skipped silently.
 *
 * Award names created:
 *   "Gemmell Fantasy"     — Best Novel (Legend Award)
 *   "Gemmell Morningstar" — Best Newcomer (Morningstar Award)
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

// ── Upsert award names ────────────────────────────────────────────────────────
foreach (['Gemmell Fantasy', 'Gemmell Morningstar'] as $name) {
    $pdo->prepare("INSERT OR IGNORE INTO awards (name) VALUES (?)")->execute([$name]);
}
$legendId      = (int)$pdo->query("SELECT id FROM awards WHERE name = 'Gemmell Fantasy'")->fetchColumn();
$morningstarId = (int)$pdo->query("SELECT id FROM awards WHERE name = 'Gemmell Morningstar'")->fetchColumn();

// ── Parse the text file ───────────────────────────────────────────────────────
$txtFile = __DIR__ . '/../gemmell-award-winners.txt';
if (!file_exists($txtFile)) {
    die("ERROR: gemmell-award-winners.txt not found at $txtFile\n");
}

$lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$currentYear = null;
$mode        = 'none'; // none | legend | morningstar | coverart
$entries     = [];     // [{award_id, year, title, result}]

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // ── Year header ───────────────────────────────────────────────────────────
    if (preg_match('/^\d{4}$/', $line)) {
        $currentYear = (int)$line;
        $mode        = 'none';
        continue;
    }

    // ── Skip prose description lines ──────────────────────────────────────────
    if (preg_match('/^The \d{4}/i', $line)) {
        continue;
    }

    // ── Cover art — switch mode and skip ─────────────────────────────────────
    if (preg_match('/^Cover art:/i', $line)) {
        $mode = 'coverart';
        continue;
    }
    if ($mode === 'coverart') {
        continue; // also skip any "Nominated:" lines that follow cover art
    }

    // ── Best Novel winner (Legend Award) ─────────────────────────────────────
    if (preg_match('/^Best novel:\s+(.+?)\s+for\s+(.+)$/i', $line, $m)) {
        $mode      = 'legend';
        $entries[] = ['award_id' => $legendId, 'year' => $currentYear, 'title' => trim($m[2]), 'result' => 'won'];
        continue;
    }

    // ── Best Newcomer winner (Morningstar Award) ──────────────────────────────
    if (preg_match('/^Best newcomer:\s+(.+?)\s+for\s+(.+)$/i', $line, $m)) {
        $mode      = 'morningstar';
        $entries[] = ['award_id' => $morningstarId, 'year' => $currentYear, 'title' => trim($m[2]), 'result' => 'won'];
        continue;
    }

    // ── Nominated ─────────────────────────────────────────────────────────────
    if (preg_match('/^Nominated:\s+(.+?)\s+for\s+(.+)$/i', $line, $m)) {
        $awardId   = ($mode === 'morningstar') ? $morningstarId : $legendId;
        $entries[] = ['award_id' => $awardId, 'year' => $currentYear, 'title' => trim($m[2]), 'result' => 'nominated'];
        continue;
    }
}

// ── Match titles to library and insert ───────────────────────────────────────
$findBook = $pdo->prepare("SELECT id, title FROM books WHERE LOWER(title) = LOWER(?) LIMIT 1");
$insert   = $pdo->prepare("INSERT OR IGNORE INTO book_awards (book_id, award_id, year, category, result) VALUES (?,?,?,NULL,?)");

$inserted  = 0;
$skipped   = 0; // already existed (OR IGNORE)
$notFound  = [];

foreach ($entries as $e) {
    $findBook->execute([$e['title']]);
    $book = $findBook->fetch(PDO::FETCH_ASSOC);

    $awardName = $e['award_id'] === $legendId ? 'Gemmell Fantasy' : 'Gemmell Morningstar';

    if (!$book) {
        $notFound[] = $e;
        continue;
    }

    $before = (int)$pdo->query("SELECT changes()")->fetchColumn();
    $insert->execute([$book['id'], $e['award_id'], $e['year'], $e['result']]);
    $changed = (int)$pdo->query("SELECT changes()")->fetchColumn();

    if ($changed > 0) {
        $inserted++;
        echo "  [+] {$e['year']} {$awardName} ({$e['result']}): {$book['title']}\n";
    } else {
        $skipped++;
        echo "  [=] {$e['year']} {$awardName} ({$e['result']}): {$book['title']} (already exists)\n";
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
        $awardName = $e['award_id'] === $legendId ? 'Gemmell Fantasy' : 'Gemmell Morningstar';
        echo "  [{$e['year']}] {$awardName} ({$e['result']}): {$e['title']}\n";
    }
}

// Invalidate awards cache if running via web
if (!$isCli) {
    require_once __DIR__ . '/../cache.php';
    invalidateCache('awards');
    echo "\nCache invalidated.\n";
}

echo "\nDone.\n";
