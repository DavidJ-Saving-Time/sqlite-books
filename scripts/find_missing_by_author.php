<?php
/**
 * Searches the Meilisearch IRC index for books by authors already in your
 * Calibre library, then filters out titles you already own.
 *
 * IRC line format: !BotName Author Name - Title (version).ext
 *
 * CLI:  php scripts/find_missing_by_author.php [options] [/path/to/metadata.db]
 *
 * Options:
 *   --limit N       Only process the first N authors (default: all)
 *   --author NAME   Only process authors whose name contains NAME (substring)
 *   --min-books N   Skip authors with fewer than N books in library (default: 1)
 *   --output FILE   Output file (default: author-missing-results.txt)
 *   --verbose       Print each candidate line as it is found
 *
 * Output format is identical to missing-books-results.txt so it can be fed
 * straight into send_missing_books.php.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
use Meilisearch\Client;

// ── Parse arguments ───────────────────────────────────────────────────────────

$authorFilter = null;
$limit        = PHP_INT_MAX;
$minBooks     = 1;
$outputFile   = __DIR__ . '/../data/author-missing-results.txt';
$verbose      = false;
$debug        = false;
$dbPath       = null;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--limit':     $limit        = (int)($argv[++$i] ?? PHP_INT_MAX); break;
        case '--author':    $authorFilter = $argv[++$i] ?? null;               break;
        case '--db':        $dbPath       = $argv[++$i] ?? null;               break;
        case '--min-books': $minBooks     = (int)($argv[++$i] ?? 1);           break;
        case '--output':    $outputFile   = $argv[++$i];                       break;
        case '--verbose':   $verbose      = true;                               break;
        case '--debug':     $verbose      = true; $debug = true;               break;
        default:
            if (!str_starts_with($argv[$i], '-') && file_exists($argv[$i])) {
                $dbPath = $argv[$i];
            }
    }
}

// ── Connect to Calibre DB ─────────────────────────────────────────────────────

if ($dbPath && file_exists($dbPath)) {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    require_once __DIR__ . '/../db.php';
    $pdo = getDatabaseConnection();
}

// ── Load authors and their books from library ─────────────────────────────────
// a.name is the display name e.g. "Iain M. Banks" (not the sort form "Banks, Iain M.")

$rows = $pdo->query("
    SELECT a.id, a.name,
           GROUP_CONCAT(b.title, '||') AS titles
    FROM authors a
    JOIN books_authors_link bal ON bal.author = a.id
    JOIN books b ON b.id = bal.book
    GROUP BY a.id
    ORDER BY a.name COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

$authors = [];
foreach ($rows as $row) {
    $titles = array_filter(explode('||', $row['titles'] ?? ''), 'strlen');
    if (count($titles) < $minBooks) continue;
    if ($authorFilter !== null && stripos($row['name'], $authorFilter) === false) continue;

    $titleSet = [];
    foreach ($titles as $t) {
        $titleSet[normTitle($t)] = true;
    }

    $authors[] = [
        'name'     => $row['name'],
        'titles'   => $titles,
        'titleSet' => $titleSet,
    ];
}

if (empty($authors)) {
    die("No matching authors found in library.\n");
}

$authors = array_slice($authors, 0, $limit);

echo "Authors to scan : " . count($authors) . "\n";
echo "Output          : $outputFile\n";
if ($authorFilter) echo "Filter          : \"$authorFilter\"\n";
echo "\n";

// ── Connect to Meilisearch ────────────────────────────────────────────────────

$client = new Client('http://localhost:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
$index  = $client->index('lines');

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Normalise a title for loose comparison:
 * strip all parenthetical groups, leading articles, version tags, punctuation.
 */
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
 * Parse an IRC bot line. Handles formats like:
 *   !Bsk Author - Title.epub  ::INFO:: 338.3KB
 *   !Bsk Author - Title (retail).epub  ::INFO:: 1.0MB
 *   !Bsk Author - [Series 01] - Title (retail).epub  ::INFO:: 977.3KB
 *   !Bsk Author - [Series 01] - Title (retail) (azw3).rar  ::INFO:: 1.3MB
 *
 * Returns ['author' => string, 'title' => string, 'ext' => string]
 * or null if the line can't be parsed.
 */
function parseLine(string $line, bool $debug = false): ?array {
    // Must start with !BotName
    if (!preg_match('/^!\S+\s+(.+)$/', $line, $m)) {
        if ($debug) echo "  [parse:no-bot-prefix]\n";
        return null;
    }
    $rest = $m[1];

    // Strip ::INFO:: file-size suffix and anything after it
    $rest = trim(preg_replace('/\s*::INFO::.*$/i', '', $rest));

    // Determine ebook format and strip the file extension:
    //   Case 1: ends in .epub / .mobi / .azw3 / .azw / .kfx
    //   Case 2: ends in (format).rar  e.g. "(retail) (azw3).rar"
    $ext = null;

    if (preg_match('/\.(epub|mobi|azw3?|kfx)$/i', $rest, $extM)) {
        $ext  = strtolower($extM[1]);
        $rest = trim(substr($rest, 0, -strlen($extM[0])));
    } elseif (preg_match('/\(([a-z0-9]+)\)\.rar$/i', $rest, $extM)
              && preg_match('/^(epub|mobi|azw3?|kfx)$/i', $extM[1])) {
        $ext  = strtolower($extM[1]);
        // strip the "(format).rar" from the end
        $rest = trim(substr($rest, 0, -strlen($extM[0]) - 1)); // -1 for the dot
        $rest = trim($rest);
    } else {
        if ($debug) echo "  [parse:no-ebook-ext] ..." . mb_substr($rest, -40) . "\n";
        return null;
    }

    // Strip all trailing parenthetical groups — these are metadata like
    // (retail), (v5), (v5 retail), (Retail), etc.
    $rest = trim(preg_replace('/(\s*\([^)]*\))+$/', '', $rest));

    // Split on first " - " → left = author, right = title (may include series prefix)
    $dashPos = strpos($rest, ' - ');
    if ($dashPos === false) {
        if ($debug) echo "  [parse:no-dash-sep] $rest\n";
        return null;
    }

    $author   = trim(substr($rest, 0, $dashPos));
    $titleRaw = trim(substr($rest, $dashPos + 3));

    // Strip leading bracketed series: "[Shannara 01] - " or "[Series 01-02] - "
    $titleRaw = trim(preg_replace('/^\[[^\]]*\]\s*-\s*/', '', $titleRaw));

    // Strip unbracketed series prefix where the segment before " - " contains a digit
    // e.g. "Science In The Capital 01 - Green Earth" → "Green Earth"
    if (preg_match('/^(.+?\d[\d.]*)\s+-\s+(.+)$/', $titleRaw, $sm)) {
        $titleRaw = trim($sm[2]);
    }

    if ($titleRaw === '' || $author === '') {
        if ($debug) echo "  [parse:empty-field] author='$author' title='$titleRaw'\n";
        return null;
    }

    return ['author' => $author, 'title' => $titleRaw, 'ext' => $ext];
}

/**
 * Score a line for output priority.
 * Base score 1 = any valid ebook line.
 * Bonus points for preferred bot, quality markers, and epub format.
 */
function scoreLine(string $text, string $ext): int {
    $preferredBot     = (bool)preg_match('/^!(TrainFiles|Bsk)\b/i', $text);
    $preferredQuality = (bool)preg_match('/\bv5\b|retail/i', $text);
    $preferredFormat  = $ext === 'epub';
    return 1 + ($preferredBot ? 2 : 0) + ($preferredQuality ? 1 : 0) + ($preferredFormat ? 1 : 0);
}

/**
 * Check whether two author strings refer to the same person.
 * Handles "Iain M. Banks" vs "Iain Banks" vs "I. M. Banks" loosely
 * by requiring the surname to match.
 */
function authorMatches(string $lineAuthor, string $libraryAuthor): bool {
    // Extract last word of each as surname proxy
    $words = fn(string $s) => preg_split('/[\s.]+/', strtolower(trim($s)), -1, PREG_SPLIT_NO_EMPTY);

    $lineWords    = array_values(array_filter($words($lineAuthor)));
    $libraryWords = array_values(array_filter($words($libraryAuthor)));

    if (empty($lineWords) || empty($libraryWords)) return false;

    // Surname = last token
    $lineSurname    = end($lineWords);
    $librarySurname = end($libraryWords);

    if ($lineSurname !== $librarySurname) return false;

    // If surnames match and at least one first initial matches, accept
    $lineFirst    = $lineWords[0][0]    ?? '';
    $libraryFirst = $libraryWords[0][0] ?? '';

    return $lineFirst === $libraryFirst;
}

// ── Main loop ─────────────────────────────────────────────────────────────────

$out   = [];
$stats = ['authors' => 0, 'hits' => 0, 'owned' => 0, 'no_results' => 0];

foreach ($authors as $i => $author) {
    $stats['authors']++;
    $n     = $i + 1;
    $total = count($authors);
    echo "\r[{$n}/{$total}] " . mb_substr($author['name'], 0, 50) . str_repeat(' ', 20);

    // Search with the full author name as Calibre stores it (display form)
    try {
        $results = $index->search($author['name'], ['limit' => 100]);
        $hits    = $results->getHits();
    } catch (Exception $e) {
        echo "\nERROR: " . $e->getMessage() . "\n";
        continue;
    }

    if ($debug) {
        echo "\n  Raw hits from Meilisearch: " . count($hits) . "\n";
        foreach (array_slice($hits, 0, 10) as $h) {
            echo "    " . mb_substr($h['text'] ?? '', 0, 120) . "\n";
        }
    }

    $candidates = []; // score => [lines]

    foreach ($hits as $hit) {
        $text = trim($hit['text'] ?? '');

        // Skip bots we don't want
        if (stripos($text, '!Dumbledore') === 0) continue;

        // Skip small files when size is known (likely short stories)
        if (preg_match('/::INFO::\s+([\d.]+)\s*(KB|MB)/i', $text, $szM)) {
            $sizeKB = strtoupper($szM[2]) === 'MB' ? (float)$szM[1] * 1024 : (float)$szM[1];
            if ($sizeKB < 200) continue;
        }

        // Skip non-English editions
        if (preg_match('/\[(?:FR|DE|ES|IT|NL|PL|PT|RU|TR|JA|ZH)\]/i', $text)) continue;

$parsed = parseLine($text, $debug);
        if ($parsed === null) {
            if ($debug) echo "  [skip:parse] " . mb_substr($text, 0, 100) . "\n";
            continue;
        }

        // Confirm the author field in the line is actually this author
        if (!authorMatches($parsed['author'], $author['name'])) {
            if ($debug) echo "  [skip:author «{$parsed['author']}»] " . mb_substr($text, 0, 80) . "\n";
            continue;
        }

        $score = scoreLine($text, $parsed['ext']);

        $stats['hits']++;

        // Check against owned titles
        $normTitle = normTitle($parsed['title']);
        $owned     = isset($author['titleSet'][$normTitle]);

        if (!$owned) {
            // Partial match: owned title contained in found title or vice versa
            foreach ($author['titleSet'] as $ownedNorm => $_) {
                if ($ownedNorm !== '' && $normTitle !== '' &&
                    (str_contains($normTitle, $ownedNorm) || str_contains($ownedNorm, $normTitle))) {
                    $owned = true;
                    break;
                }
            }
        }

        if ($owned) {
            $stats['owned']++;
            continue;
        }

        $candidates[$score][] = ['line' => $text, 'title' => $normTitle];

        if ($verbose) {
            echo "\n  [NEW] {$parsed['author']} - {$parsed['title']} ({$parsed['ext']})";
        }
    }

    if (empty($candidates)) {
        $stats['no_results']++;
        continue;
    }

    // Highest score first, deduplicate by normalised title
    krsort($candidates);
    $seenTitles = [];
    foreach ($candidates as $group) {
        foreach ($group as $c) {
            if (isset($seenTitles[$c['title']])) continue;
            $seenTitles[$c['title']] = true;
            $out[] = $c['line'];
        }
    }
}

echo "\n\n";

// ── Write output ──────────────────────────────────────────────────────────────

file_put_contents($outputFile, implode("\n", $out) . "\n");

echo "Authors scanned  : {$stats['authors']}\n";
echo "Lines matched    : {$stats['hits']}\n";
echo "Already owned    : {$stats['owned']}\n";
echo "Potentially new  : " . count($out) . " unique lines\n";
echo "No IRC results   : {$stats['no_results']}\n";
echo "Output           : $outputFile\n";
echo "\nFeed into send_missing_books.php to request downloads.\n";
