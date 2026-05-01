<?php
/**
 * Parses Locus-Best-Horror-Novel.txt (two Wikipedia HTML tables) and imports
 * winners into book_awards. Every entry is a winner.
 *
 * CLI:  php scripts/import_locus_horror_awards.php [/path/to/metadata.db]
 * Web:  load in browser while logged in (uses current user's library DB)
 *
 * Columns in source tables: Year | Novel | Author | Ref
 * Both tables are parsed automatically via //tbody/tr.
 * "No award" years are skipped automatically.
 *
 * Award created: "Locus Best Horror Novel"
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
$pdo->prepare("INSERT OR IGNORE INTO awards (name) VALUES (?)")->execute(['Locus Best Horror Novel']);
$locusId = (int)$pdo->query("SELECT id FROM awards WHERE name = 'Locus Best Horror Novel'")->fetchColumn();

// ── Parse the HTML file ───────────────────────────────────────────────────────
$htmlFile = __DIR__ . '/../Locus-Best-Horror-Novel.txt';
if (!file_exists($htmlFile)) {
    die("ERROR: Locus-Best-Horror-Novel.txt not found at $htmlFile\n");
}

$html = file_get_contents($htmlFile);

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>');
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$rows  = $xpath->query('//tbody/tr'); // picks up rows from both tables

$entries = [];

foreach ($rows as $row) {
    // Year is in a <th> element
    $thNodes = $xpath->query('th', $row);
    if ($thNodes->length === 0) continue;

    $th = $thNodes->item(0);
    if (!preg_match('/\b(\d{4})\b/', $th->textContent, $m)) continue;
    $year = (int)$m[1];

    // Collect <td> children
    $tds = [];
    foreach ($row->childNodes as $node) {
        if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'td') {
            $tds[] = $node;
        }
    }

    if (empty($tds)) continue;

    // Skip "No award" rows
    $firstTd = $tds[0];
    if ($firstTd->hasAttribute('colspan') || stripos(trim($firstTd->textContent), 'no award') !== false) {
        echo "  [~] {$year}: no award — skipped\n";
        continue;
    }

    // Column order: Novel (tds[0]), Author (tds[1])
    $iNodes = $xpath->query('.//i', $firstTd);
    if ($iNodes->length > 0) {
        $title = trim(preg_replace('/\xc2\xa0/', ' ', $iNodes->item(0)->textContent));
    } else {
        $title = trim($firstTd->textContent);
    }

    if ($title === '') continue;

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

    $insert->execute([$book['id'], $locusId, $e['year']]);
    $changed = (int)$pdo->query("SELECT changes()")->fetchColumn();

    if ($changed > 0) {
        $inserted++;
        echo "  [+] {$e['year']} Locus Best Horror Novel (won): {$book['title']}\n";
    } else {
        $skipped++;
        echo "  [=] {$e['year']} Locus Best Horror Novel (won): {$book['title']} (already exists)\n";
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
