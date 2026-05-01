<?php
/**
 * Post-processes a missing-books results file by verifying each title against
 * the Open Library search API. Lines where no matching OL work is found are
 * dropped — this filters out magazines, short stories, and junk results.
 *
 * CLI:  php scripts/verify_missing_books.php [options]
 *
 * Options:
 *   --input  FILE   Input file  (default: author-missing-results.txt)
 *   --output FILE   Output file (default: author-missing-verified.txt)
 *   --delay  N      Milliseconds between API calls (default: 500)
 *   --verbose       Print each line's outcome
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// ── Arguments ─────────────────────────────────────────────────────────────────

$inputFile  = __DIR__ . '/../data/author-missing-results.txt';
$outputFile = __DIR__ . '/../data/author-missing-verified.txt';
$delayMs    = 500;
$verbose    = false;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--input':   $inputFile  = $argv[++$i];               break;
        case '--output':  $outputFile = $argv[++$i];               break;
        case '--delay':   $delayMs    = (int)($argv[++$i] ?? 500); break;
        case '--verbose': $verbose    = true;                       break;
    }
}

if (!file_exists($inputFile)) {
    die("ERROR: Input file not found: $inputFile\n");
}

$lines = array_values(array_filter(
    array_map('trim', file($inputFile, FILE_IGNORE_NEW_LINES)),
    fn($l) => $l !== ''
));

if (empty($lines)) {
    die("ERROR: Input file is empty.\n");
}

echo "Input   : $inputFile (" . count($lines) . " lines)\n";
echo "Output  : $outputFile\n";
echo "Delay   : {$delayMs}ms between requests\n\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

function normTitle(string $t): string {
    $t = strtolower(trim($t));
    $t = preg_replace('/\([^)]*\)/', '', $t);         // remove ALL (parenthetical) groups
    $t = preg_replace('/\bv\d+(\.\d+)*\b/', '', $t); // strip v1.0, v5.5 etc.
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);       // collapse non-alphanum first
    $t = trim($t);
    $t = preg_replace('/^(the|a|an)\s+/', '', $t);    // article strip after cleanup
    return trim($t);
}

/**
 * Parse author and title from an IRC bot line.
 * Format: !BotName Author - Title (version).ext  ::INFO:: ...
 * Returns ['author' => string, 'title' => string] or null.
 */
function parseAuthorTitle(string $line): ?array {
    if (!preg_match('/^!\S+\s+(.+)$/', $line, $m)) return null;
    $rest = trim(preg_replace('/\s*::INFO::.*$/i', '', $m[1]));

    // Strip extension (.epub/.mobi/.azw3/.kfx or format-in-paren .rar)
    if (preg_match('/\.(epub|mobi|azw3?|kfx)$/i', $rest)) {
        $rest = trim(preg_replace('/\.(epub|mobi|azw3?|kfx)$/i', '', $rest));
    } elseif (preg_match('/\([a-z0-9]+\)\.rar$/i', $rest, $em)
              && preg_match('/^(epub|mobi|azw3?|kfx)$/i', trim($em[0], '().rar'))) {
        $rest = trim(preg_replace('/\s*\([^)]+\)\.rar$/i', '', $rest));
    } else {
        return null;
    }

    // Strip all trailing parenthetical groups and bare version tags
    $rest = trim(preg_replace('/(\s*\([^)]*\))+$/', '', $rest));
    $rest = trim(preg_replace('/\s+v\d+(\.\d+)*$/i', '', $rest));

    // Split on first " - "
    $dash = strpos($rest, ' - ');
    if ($dash === false) return null;

    $author   = trim(substr($rest, 0, $dash));
    $titleRaw = trim(substr($rest, $dash + 3));

    // Strip bracketed series prefix [Series 01] -
    $titleRaw = trim(preg_replace('/^\[[^\]]*\]\s*-\s*/', '', $titleRaw));
    // Strip unbracketed series prefix "Series Name 01 - "
    if (preg_match('/^(.+?\d[\d.]*)\s+-\s+(.+)$/', $titleRaw, $sm)) {
        $titleRaw = trim($sm[2]);
    }

    if ($author === '' || $titleRaw === '') return null;

    return ['author' => $author, 'title' => $titleRaw];
}

/**
 * Search Open Library for a title+author and return the best matching works key,
 * or null if nothing credible is found.
 */
function findOLWorksKey(string $title, string $author): ?string {
    $url = 'https://openlibrary.org/search.json?'
         . 'title=' . urlencode($title)
         . '&author=' . urlencode($author)
         . '&limit=5&fields=key,title';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT      => 'calibre-nilla/1.0',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return null;
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['docs'])) return null;

    $normSearch = normTitle($title);

    foreach ($data['docs'] as $doc) {
        $key = $doc['key'] ?? '';
        if (!str_starts_with($key, '/works/')) continue;

        // Title must be a reasonable match
        $normResult = normTitle($doc['title'] ?? '');
        if ($normResult === '' || $normSearch === '') continue;

        // Accept if normalised titles share enough content:
        // either exact match, or one contains the other (handles subtitle variants)
        if ($normResult === $normSearch
            || str_contains($normResult, $normSearch)
            || str_contains($normSearch, $normResult)) {
            return $key;
        }
    }

    return null;
}

// ── Main loop ─────────────────────────────────────────────────────────────────

$kept    = [];
$stats   = ['kept' => 0, 'dropped' => 0, 'unparseable' => 0];
$total   = count($lines);

foreach ($lines as $i => $line) {
    $n = $i + 1;
    echo "\r[{$n}/{$total}] " . mb_substr($line, 0, 60) . str_repeat(' ', 20);

    $parsed = parseAuthorTitle($line);
    if ($parsed === null) {
        $stats['unparseable']++;
        if ($verbose) echo "\n  [skip:parse] $line\n";
        continue;
    }

    // Strip all (parenthetical) groups from title before sending to OL —
    // they're series info and confuse the search
    $cleanTitle = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $parsed['title']));
    // Also strip any orphaned leading/trailing " - " left behind
    $cleanTitle = trim(preg_replace('/^\s*-\s*|\s*-\s*$/', '', $cleanTitle));

    $worksKey = findOLWorksKey($cleanTitle, $parsed['author']);

    if ($worksKey !== null) {
        $stats['kept']++;
        $kept[] = $line;
        if ($verbose) echo "\n  [keep] {$parsed['author']} - {$parsed['title']} → $worksKey\n";
    } else {
        $stats['dropped']++;
        if ($verbose) echo "\n  [drop] {$parsed['author']} - {$parsed['title']}\n";
    }

    if ($delayMs > 0) usleep($delayMs * 1000);
}

echo "\n\n";

file_put_contents($outputFile, implode("\n", $kept) . "\n");

echo "Kept        : {$stats['kept']}\n";
echo "Dropped     : {$stats['dropped']}\n";
echo "Unparseable : {$stats['unparseable']}\n";
echo "Output      : $outputFile\n";
echo "\nFeed into send_missing_books.php --input author-missing-verified.txt\n";
