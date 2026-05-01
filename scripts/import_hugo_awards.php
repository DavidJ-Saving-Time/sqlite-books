<?php
/**
 * Parses hugo-awards.txt (Wikipedia HTML table) and imports entries into book_awards.
 *
 * CLI:  php scripts/import_hugo_awards.php [/path/to/metadata.db]
 * Web:  load in browser while logged in (uses current user's library DB)
 *
 * Books are matched by title (case-insensitive exact match).
 * Unmatched titles are reported at the end — nothing is skipped silently.
 *
 * Award created: "Hugo Award" (Best Novel)
 *
 * Winners are identified by * after the author name.
 * rowspan on year <th> and novel <td> cells are handled correctly.
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
$pdo->prepare("INSERT OR IGNORE INTO awards (name) VALUES (?)")->execute(['Hugo Award']);
$hugoId = (int)$pdo->query("SELECT id FROM awards WHERE name = 'Hugo Award'")->fetchColumn();

// ── Parse the HTML file ───────────────────────────────────────────────────────
$htmlFile = __DIR__ . '/../hugo-awards.txt';
if (!file_exists($htmlFile)) {
    die("ERROR: hugo-awards.txt not found at $htmlFile\n");
}

$html = file_get_contents($htmlFile);

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>');
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$rows  = $xpath->query('//tbody/tr');

$currentYear  = null;
$novelRowspan = 0;   // remaining rowspan count for the current novel
$currentNovel = null; // novel title being carried via rowspan

$entries = []; // [{year, title, result}]

/**
 * Extract the primary novel title from a <td> node.
 * Prefers text inside the first <i> element; falls back to trimmed text content.
 * Strips " (also known as ...)" and similar suffixes.
 */
function extractNovelTitle(DOMNode $td, DOMXPath $xpath): string
{
    // First <i> child (possibly nested inside a span) holds the canonical title
    $iNodes = $xpath->query('.//i', $td);
    if ($iNodes->length > 0) {
        $raw = trim($iNodes->item(0)->textContent);
        // Remove trailing footnote markers, non-breaking spaces etc.
        $raw = preg_replace('/\xc2\xa0/', ' ', $raw); // &nbsp;
        return trim($raw);
    }
    // Fallback: full text content, strip " (also known as ...)"
    $text = preg_replace('/\s*\(also known as.*$/i', '', $td->textContent);
    return trim($text);
}

foreach ($rows as $row) {
    // ── Check for a <th> in this row (year cell) ──────────────────────────────
    $thNodes = $xpath->query('th', $row);
    if ($thNodes->length > 0) {
        $th = $thNodes->item(0);
        // Extract 4-digit year from text content
        if (preg_match('/\b(\d{4})\b/', $th->textContent, $m)) {
            $currentYear = (int)$m[1];
        }
        // Reset novel rowspan tracking on new year
        $novelRowspan = 0;
        $currentNovel = null;
    }

    if ($currentYear === null) continue;

    // ── Collect <td> children (direct only) ──────────────────────────────────
    $tds = [];
    foreach ($row->childNodes as $node) {
        if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'td') {
            $tds[] = $node;
        }
    }

    if (empty($tds)) continue; // header-only row

    // ── Author is always the first <td> ──────────────────────────────────────
    $authorTd   = $tds[0];
    $authorText = $authorTd->textContent;
    $isWinner   = str_contains($authorText, '*');

    // ── Novel cell: may be tds[1], or carried via rowspan ────────────────────
    $novelTdIndex = 1;

    if ($novelRowspan > 0) {
        // Novel is carried from a previous row's rowspan — no novel td in this row
        $novelRowspan--;
        $title = $currentNovel;
    } else {
        // Look for a novel td (second td)
        if (count($tds) > 1) {
            $novelTd = $tds[1];

            // Check if this td has a rowspan
            if ($novelTd->hasAttribute('rowspan')) {
                $span = (int)$novelTd->getAttribute('rowspan');
                if ($span > 1) {
                    $novelRowspan = $span - 1;
                }
            }

            $title         = extractNovelTitle($novelTd, $xpath);
            $currentNovel  = $title;
        } else {
            // Single-td row and no rowspan — shouldn't happen, skip
            continue;
        }
    }

    if ($title === '') continue;

    $result    = $isWinner ? 'won' : 'nominated';
    $entries[] = ['year' => $currentYear, 'title' => $title, 'result' => $result];
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

    $insert->execute([$book['id'], $hugoId, $e['year'], $e['result']]);
    $changed = (int)$pdo->query("SELECT changes()")->fetchColumn();

    if ($changed > 0) {
        $inserted++;
        echo "  [+] {$e['year']} Hugo Award ({$e['result']}): {$book['title']}\n";
    } else {
        $skipped++;
        echo "  [=] {$e['year']} Hugo Award ({$e['result']}): {$book['title']} (already exists)\n";
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

// Invalidate awards cache if running via web
if (!$isCli) {
    require_once __DIR__ . '/../cache.php';
    invalidateCache('awards');
    echo "\nCache invalidated.\n";
}

echo "\nDone.\n";
