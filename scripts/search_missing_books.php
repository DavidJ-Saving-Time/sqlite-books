<?php
/**
 * Reads missing-books.txt (output from import_awards_master.php --dry-run),
 * searches each missing title against the Meilisearch IRC index, and writes
 * all matching lines to missing-books-results.txt.
 *
 * CLI:  php scripts/search_missing_books.php [input.txt] [output.txt]
 *
 * Defaults:
 *   input  → missing-books.txt
 *   output → missing-books-results.txt
 *
 * Input format (lines to parse):
 *   [YEAR]* Title — Author   (winner)
 *   [YEAR]  Title — Author   (nominated / special citation)
 *
 * Other lines (headers, summaries, blank) are skipped automatically.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use Meilisearch\Client;

$inputFile  = $argv[1] ?? __DIR__ . '/../data/missing-books.txt';
$outputFile = $argv[2] ?? __DIR__ . '/../data/missing-books-results.txt';

if (!file_exists($inputFile)) {
    die("ERROR: Input file not found: $inputFile\n");
}

// ── Parse missing titles from the dry-run output ──────────────────────────────
// Lines look like:  "    [1987]* The Handmaid's Tale — Margaret Atwood"
//               or: "    [1987]  The Ragged Astronauts — Bob Shaw"

$books = []; // [['title' => ..., 'author' => ..., 'year' => ..., 'won' => bool]]

foreach (file($inputFile, FILE_IGNORE_NEW_LINES) as $line) {
    if (!preg_match('/^\s+\[(\d{4})\]([* c])\s+(.+?)\s+—\s+(.+)$/', $line, $m)) continue;
    $books[] = [
        'year'   => (int)$m[1],
        'won'    => trim($m[2]) !== '',
        'title'  => trim($m[3]),
        'author' => trim($m[4]),
    ];
}

if (empty($books)) {
    die("No parseable book lines found in: $inputFile\n");
}

echo "Parsed " . count($books) . " missing titles.\n\n";

// ── Connect to Meilisearch ────────────────────────────────────────────────────
$client = new Client('http://localhost:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
$index  = $client->index('lines');

// ── Search each title ─────────────────────────────────────────────────────────
$out      = [];
$found    = 0;
$notFound = 0;

foreach ($books as $i => $book) {
    $term = $book['title'];

    // Progress indicator
    $n = $i + 1;
    $total = count($books);
    echo "\r[$n/$total] Searching: " . mb_substr($term, 0, 50) . str_repeat(' ', 20);

    try {
        $results = $index->search($term, ['limit' => 50]);
        $hits    = $results->getHits();
    } catch (Exception $e) {
        echo "\nERROR searching \"$term\": " . $e->getMessage() . "\n";
        continue;
    }

    // Filter: ALL title words must appear AND author surname must appear
    $titleWords = array_filter(preg_split('/\s+/', strtolower($term)));
    $authorParts = array_filter(preg_split('/\s+/', strtolower($book['author'])));
    $surname     = end($authorParts); // last word of author name

    $scored = [];
    foreach ($hits as $hit) {
        $text       = trim($hit['text'] ?? '');
        $normalized = strtolower($text);

        // Every title word must be present
        foreach ($titleWords as $w) {
            if (strpos($normalized, $w) === false) continue 2;
        }

        // Author surname must be present
        if ($surname && strpos($normalized, $surname) === false) continue;

        // Must be an ebook format (epub/mobi/azw/azw3/kfx) — skip pdf/html/doc etc.
        if (!preg_match('/\b(epub|mobi|azw3?|kfx)\b/i', $text)) continue;

        // Priority scoring:
        //   bit 1 (2): preferred bot (!TrainFiles or !Bsk)
        //   bit 0 (1): preferred quality (v5 or retail)
        // Higher score = shown first; score 0 = discarded
        $preferredBot     = (bool)preg_match('/^!(TrainFiles|Bsk)\b/i', $text);
        $preferredQuality = (bool)preg_match('/\bv5\b|retail/i', $text);

        $score = ($preferredBot ? 2 : 0) + ($preferredQuality ? 1 : 0);

        // Only keep results that meet at least one preferred criterion
        if ($score === 0) continue;

        $scored[] = ['text' => $text, 'score' => $score];
    }

    if (empty($scored)) {
        $notFound++;
        continue;
    }

    // Sort highest score first, take only the top result
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $matched = array_column(array_slice($scored, 0, 1), 'text');

    $found++;
    $out[] = $matched[0];
}

echo "\n\n";

// ── Write output ──────────────────────────────────────────────────────────────
file_put_contents($outputFile, implode("\n", $out));

echo "Results  : $found title(s) with matches\n";
echo "No match : $notFound title(s) not found in Meilisearch\n";
echo "Output   : $outputFile\n";
