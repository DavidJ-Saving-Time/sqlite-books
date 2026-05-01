<?php
/**
 * Parses BSFA-Award-for-Best-Novel.txt (Wikipedia HTML table) and imports entries
 * into book_awards.
 *
 * CLI:  php scripts/import_bsfa_awards.php [/path/to/metadata.db]
 * Web:  load in browser while logged in (uses current user's library DB)
 *
 * Books are matched by title (case-insensitive exact match).
 * Unmatched titles are reported at the end — nothing is skipped silently.
 *
 * Award created: "BSFA Award for Best Novel"
 *
 * Winners are identified by * after the author name.
 * rowspan on year <th> cells is handled correctly.
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
$pdo->prepare("INSERT OR IGNORE INTO awards (name) VALUES (?)")->execute(['BSFA Award for Best Novel']);
$bsfaId = (int)$pdo->query("SELECT id FROM awards WHERE name = 'BSFA Award for Best Novel'")->fetchColumn();

// ── Parse the HTML file ───────────────────────────────────────────────────────
$htmlFile = __DIR__ . '/../BSFA-Award-for-Best-Novel.txt';
if (!file_exists($htmlFile)) {
    die("ERROR: BSFA-Award-for-Best-Novel.txt not found at $htmlFile\n");
}

$html = file_get_contents($htmlFile);

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>');
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$rows  = $xpath->query('//tbody/tr');

$currentYear  = null;
$novelRowspan = 0;
$currentNovel = null;

$entries = [];

/**
 * Extract the primary novel title from a <td> node.
 */
function extractNovelTitle(DOMNode $td, DOMXPath $xpath): string
{
    $iNodes = $xpath->query('.//i', $td);
    if ($iNodes->length > 0) {
        $raw = trim($iNodes->item(0)->textContent);
        $raw = preg_replace('/\xc2\xa0/', ' ', $raw);
        return trim($raw);
    }
    $text = preg_replace('/\s*\(also known as.*$/i', '', $td->textContent);
    return trim($text);
}

foreach ($rows as $row) {
    // ── Check for a <th> in this row (year cell) ──────────────────────────────
    $thNodes = $xpath->query('th', $row);
    if ($thNodes->length > 0) {
        $th = $thNodes->item(0);
        if (preg_match('/\b(\d{4})\b/', $th->textContent, $m)) {
            $currentYear = (int)$m[1];
        }
        $novelRowspan = 0;
        $currentNovel = null;
    }

    if ($currentYear === null) continue;

    // ── Collect <td> children ─────────────────────────────────────────────────
    $tds = [];
    foreach ($row->childNodes as $node) {
        if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'td') {
            $tds[] = $node;
        }
    }

    if (empty($tds)) continue;

    // ── Author is always the first <td> ──────────────────────────────────────
    $authorTd = $tds[0];
    $isWinner = str_contains($authorTd->textContent, '*');

    // ── Novel cell: tds[1], or carried via rowspan ────────────────────────────
    if ($novelRowspan > 0) {
        $novelRowspan--;
        $title = $currentNovel;
    } else {
        if (count($tds) > 1) {
            $novelTd = $tds[1];

            if ($novelTd->hasAttribute('rowspan')) {
                $span = (int)$novelTd->getAttribute('rowspan');
                if ($span > 1) {
                    $novelRowspan = $span - 1;
                }
            }

            $title        = extractNovelTitle($novelTd, $xpath);
            $currentNovel = $title;
        } else {
            continue;
        }
    }

    if ($title === '') continue;

    $entries[] = ['year' => $currentYear, 'title' => $title, 'result' => $isWinner ? 'won' : 'nominated'];
}

// ── Match titles to library and insert ───────────────────────────────────────
$findBook = $pdo->prepare("SELECT id, title FROM books WHERE LOWER(title) = LOWER(?) LIMIT 1");
$insert   = $pdo->prepare("INSERT OR IGNORE INTO book_awards (book_id, award_id, year, category, result) VALUES (?,?,?,NULL,?)");

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

    $insert->execute([$book['id'], $bsfaId, $e['year'], $e['result']]);
    $changed = (int)$pdo->query("SELECT changes()")->fetchColumn();

    if ($changed > 0) {
        $inserted++;
        echo "  [+] {$e['year']} BSFA Award ({$e['result']}): {$book['title']}\n";
    } else {
        $skipped++;
        echo "  [=] {$e['year']} BSFA Award ({$e['result']}): {$book['title']} (already exists)\n";
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
        $flag = $e['result'] === 'won' ? '*' : ' ';
        echo "  [{$e['year']}]{$flag} {$e['title']}\n";
    }
}

if (!$isCli) {
    require_once __DIR__ . '/../cache.php';
    invalidateCache('awards');
    echo "\nCache invalidated.\n";
}

echo "\nDone.\n";
