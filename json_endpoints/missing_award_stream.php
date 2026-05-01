<?php
/**
 * SSE stream: find IRC download commands for award-winning/nominated books
 * not yet in the library.
 *
 * GET ?awards[]=Name&won_only=1&delay=400
 *
 * Phase 1 — run import_awards_master.php --dry-run, parse the not-found list,
 *            filter by selected awards and won/nominated preference.
 * Phase 2 — search each title in Meilisearch, filter/score results.
 * Phase 3 — verify candidates against Open Library.
 *
 * Events emitted:
 *   status          {phase, message}
 *   books_loaded    {count}
 *   find_progress   {n, total, title}
 *   found           {line, title, author, award, year, ext}
 *   verify_progress {n, total, title}
 *   verify_result   {title, kept, worksKey?}
 *   done            {kept:[], stats:{books,candidates,verified,dropped}}
 *   error           {message}
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();
require_once __DIR__ . '/../vendor/autoload.php';
use Meilisearch\Client;

set_time_limit(600);

$selectedAwards = array_map('trim', (array)($_GET['awards'] ?? []));
$wonOnly        = !empty($_GET['won_only']);
$delayMs        = max(100, (int)($_GET['delay'] ?? 400));

// ── SSE helper ────────────────────────────────────────────────────────────────

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── Helpers (shared logic with missing_author_stream.php) ─────────────────────

function latinize(string $s): string {
    static $map = [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
        'Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I',
        'Î'=>'I','Ï'=>'I','Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O',
        'Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'Ý'=>'Y','Þ'=>'TH','ß'=>'ss','à'=>'a','á'=>'a','â'=>'a','ã'=>'a',
        'ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e',
        'ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'d','ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u',
        'ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y',
    ];
    return strtr($s, $map);
}

function normTitle(string $t): string {
    $t = latinize(strtolower(trim($t)));
    $t = preg_replace('/\([^)]*\)/', '', $t);
    $t = preg_replace('/\bv\d+(\.\d+)*\b/', '', $t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    $t = trim($t);
    $t = preg_replace('/^(the|a|an)\s+/', '', $t);
    return trim($t);
}

function parseLine(string $line): ?array {
    if (!preg_match('/^!\S+\s+(.+)$/', $line, $m)) return null;
    $rest = trim(preg_replace('/\s*::INFO::.*$/i', '', $m[1]));

    if (preg_match('/\.(epub|mobi|azw3?|kfx)$/i', $rest, $extM)) {
        $ext  = strtolower($extM[1]);
        $rest = trim(substr($rest, 0, -strlen($extM[0])));
    } elseif (preg_match('/\(([a-z0-9]+)\)\.rar$/i', $rest, $extM)
              && preg_match('/^(epub|mobi|azw3?|kfx)$/i', $extM[1])) {
        $ext  = strtolower($extM[1]);
        $rest = trim(preg_replace('/\s*\([^)]+\)\.rar$/i', '', $rest));
    } else {
        return null;
    }

    $rest = trim(preg_replace('/(\s*\([^)]*\))+$/', '', $rest));
    $rest = trim(preg_replace('/\s+v\d+(\.\d+)*$/i', '', $rest));

    $dash = strpos($rest, ' - ');
    if ($dash === false) return null;

    $lineAuthor = trim(substr($rest, 0, $dash));
    $titleRaw   = trim(substr($rest, $dash + 3));
    $titleRaw   = trim(preg_replace('/^\[[^\]]*\]\s*-\s*/', '', $titleRaw));
    if (preg_match('/^(.+?\d[\d.]*)\s+-\s+(.+)$/', $titleRaw, $sm)) {
        $titleRaw = trim($sm[2]);
    }

    if ($titleRaw === '' || $lineAuthor === '') return null;
    return ['author' => $lineAuthor, 'title' => $titleRaw, 'ext' => $ext];
}

function scoreLine(string $text, string $ext): int {
    $preferredBot     = (bool)preg_match('/^!(TrainFiles|Bsk|peapod|Oatmeal)\b/i', $text);
    $preferredQuality = (bool)preg_match('/\bv5\b|retail/i', $text);
    $preferredFormat  = $ext === 'epub';
    return 1 + ($preferredBot ? 2 : 0) + ($preferredQuality ? 1 : 0) + ($preferredFormat ? 1 : 0);
}

function authorMatches(string $lineAuthor, string $queryAuthor): bool {
    $lineNorm = latinize(strtolower($lineAuthor));
    $words    = array_filter(
        preg_split('/[\s.]+/', latinize(strtolower($queryAuthor))),
        fn($w) => strlen($w) >= 3
    );
    foreach ($words as $word) {
        if (strpos($lineNorm, $word) === false) return false;
    }
    return !empty($words);
}

function findOLWorksKey(string $title, string $author): ?string {
    $url = 'https://openlibrary.org/search.json?'
         . 'title='   . urlencode($title)
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
        $normResult = normTitle($doc['title'] ?? '');
        if ($normResult === '' || $normSearch === '') continue;
        if ($normResult === $normSearch
            || str_contains($normResult, $normSearch)
            || str_contains($normSearch, $normResult)) {
            return $key;
        }
    }
    return null;
}

// ── Phase 1: Run dry-run import to get not-found list ─────────────────────────

sse('status', ['phase' => 'load', 'message' => 'Running awards dry-run to find missing titles…']);

$phpBin = PHP_BINARY;
if (stripos(basename($phpBin), 'fpm') !== false || !is_executable($phpBin)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php83'] as $try) {
        if (is_executable($try)) { $phpBin = $try; break; }
    }
}

$dbPath = currentDatabasePath();
$script = realpath(__DIR__ . '/../scripts/import_awards_master.php');

if (!$script) {
    sse('error', ['message' => 'import_awards_master.php not found']);
    exit;
}

$cmd   = implode(' ', array_map('escapeshellarg', [$phpBin, $script, $dbPath, '--dry-run'])) . ' 2>&1';
$lines = [];
exec($cmd, $lines);

if (empty($lines)) {
    sse('error', ['message' => 'Dry-run produced no output']);
    exit;
}

// Parse the not-found section
// Format:
//   --- Titles not found in your library ---
//     AwardName:
//       [YEAR]* Title — Author   (* = won, c = special citation, space = nominated)
$books       = [];
$inSection   = false;
$currentAward = '';

foreach ($lines as $line) {
    if (str_contains($line, 'Titles not found in your library')) {
        $inSection = true;
        continue;
    }
    if (!$inSection) continue;

    // Award group header: "  Hugo Award:"
    if (preg_match('/^\s{2}(.+):$/', $line, $m)) {
        $currentAward = trim($m[1]);
        continue;
    }

    // Book entry: "    [1983]* Startide Rising — David Brin"
    if (!preg_match('/^\s+\[(\d{4})\]([* c])\s+(.+?)\s+—\s+(.+)$/', $line, $m)) continue;

    $year   = (int)$m[1];
    $flag   = trim($m[2]);
    $title  = trim($m[3]);
    $author = trim($m[4]);
    $result = $flag === '*' ? 'won' : ($flag === 'c' ? 'special citation' : 'nominated');

    if ($wonOnly && $result !== 'won') continue;
    if (!empty($selectedAwards) && !in_array($currentAward, $selectedAwards, true)) continue;

    $books[] = compact('title', 'author', 'year', 'result', 'currentAward');
}

if (empty($books)) {
    sse('done', ['kept' => [], 'stats' => ['books' => 0, 'candidates' => 0, 'verified' => 0, 'dropped' => 0]]);
    exit;
}

sse('books_loaded', ['count' => count($books)]);
sse('status', ['phase' => 'find', 'message' => 'Searching IRC index for ' . count($books) . ' titles…']);

// ── Phase 2: Search Meilisearch per title ─────────────────────────────────────

try {
    $msClient = new Client('http://localhost:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
    $index    = $msClient->index('lines');
} catch (Exception $e) {
    sse('error', ['message' => 'Meilisearch error: ' . $e->getMessage()]);
    exit;
}

$candidates = []; // normTitle => best candidate

foreach ($books as $i => $book) {
    sse('find_progress', ['n' => $i + 1, 'total' => count($books), 'title' => $book['title']]);

    try {
        $hits = $index->search($book['title'], ['limit' => 100])->getHits();
    } catch (Exception $e) {
        continue;
    }

    $normSearch = normTitle($book['title']);

    foreach ($hits as $hit) {
        $text = trim($hit['text'] ?? '');

        if (stripos($text, '!Dumbledore') === 0) continue;
        if (preg_match('/\[(?:FR|DE|ES|IT|NL|PL|PT|RU|TR|JA|ZH)\]/i', $text)) continue;
        if (preg_match('/::INFO::\s+([\d.]+)\s*(KB|MB)/i', $text, $szM)) {
            $kb = strtoupper($szM[2]) === 'MB' ? (float)$szM[1] * 1024 : (float)$szM[1];
            if ($kb < 200) continue;
        }

        $parsed = parseLine($text);
        if ($parsed === null) continue;

        // Title must roughly match
        $normParsed = normTitle($parsed['title']);
        if ($normParsed === '' || $normSearch === '') continue;
        if (!str_contains($normParsed, $normSearch) && !str_contains($normSearch, $normParsed)) continue;

        // Author surname must appear
        if (!authorMatches($parsed['author'], $book['author'])) continue;

        $score = scoreLine($text, $parsed['ext']);
        $key   = $normSearch;

        if (!isset($candidates[$key]) || $score > $candidates[$key]['score']) {
            $candidates[$key] = [
                'line'   => $text,
                'title'  => $book['title'],
                'author' => $book['author'],
                'award'  => $book['currentAward'],
                'year'   => $book['year'],
                'ext'    => $parsed['ext'],
                'score'  => $score,
            ];
        }
    }
}

$candidateList = array_values($candidates);

sse('status', ['phase' => 'verify', 'message' => count($candidateList) . ' candidates found, verifying with Open Library…']);

foreach ($candidateList as $c) {
    sse('found', [
        'line'   => $c['line'],
        'title'  => $c['title'],
        'author' => $c['author'],
        'award'  => $c['award'],
        'year'   => $c['year'],
        'ext'    => $c['ext'],
    ]);
}

if (empty($candidateList)) {
    sse('done', ['kept' => [], 'stats' => [
        'books'      => count($books),
        'candidates' => 0,
        'verified'   => 0,
        'dropped'    => 0,
    ]]);
    exit;
}

// ── Phase 3: OL verify ────────────────────────────────────────────────────────

$kept = [];
foreach ($candidateList as $idx => $c) {
    sse('verify_progress', ['n' => $idx + 1, 'total' => count($candidateList), 'title' => $c['title']]);

    $cleanTitle = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $c['title']));
    $cleanTitle = trim(preg_replace('/^\s*-\s*|\s*-\s*$/', '', $cleanTitle));

    $worksKey = findOLWorksKey($cleanTitle, $c['author']);

    if ($worksKey !== null) {
        $kept[] = $c['line'];
        sse('verify_result', [
            'line'     => $c['line'],
            'title'    => $c['title'],
            'worksKey' => $worksKey,
            'kept'     => true,
        ]);
    } else {
        sse('verify_result', ['title' => $c['title'], 'kept' => false]);
    }

    if ($delayMs > 0 && $idx < count($candidateList) - 1) usleep($delayMs * 1000);
}

sse('done', [
    'kept'  => $kept,
    'stats' => [
        'books'      => count($books),
        'candidates' => count($candidateList),
        'verified'   => count($kept),
        'dropped'    => count($candidateList) - count($kept),
    ],
]);
