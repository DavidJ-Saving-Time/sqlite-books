<?php
/**
 * SSE stream: find books missing from library by author, then verify via OL.
 *
 * GET ?author=Name[&delay=400]
 *
 * Events emitted:
 *   status          {phase, message}
 *   library_loaded  {count, author}
 *   find_done       {count, owned, library}
 *   found           {line, title, ext}
 *   verify_progress {n, total, title}
 *   verify_result   {line?, title, worksKey?, kept}
 *   done            {kept:[], stats:{library,found,verified,dropped}}
 *   error           {message}
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();
require_once __DIR__ . '/../vendor/autoload.php';
use Meilisearch\Client;

set_time_limit(300);

$author = normalizeAuthorName(trim($_GET['author'] ?? ''));

// ── SSE helper ────────────────────────────────────────────────────────────────

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

if ($author === '') {
    sse('error', ['message' => 'Author name is required']);
    exit;
}

// ── Helpers (mirrored from find_missing_by_author.php / verify_missing_books.php) ──

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

/** All meaningful words in the query must appear in the IRC line's author field. */
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

/**
 * Build an ILIKE pattern from an author name that includes every part (initials too).
 * "R. J. Barker"     → "r%j%barker%"   (anchored at start when first part is an initial)
 * "Jonathan Kellerman" → "%jonathan%kellerman%"
 * This is far more selective than using only words ≥4 chars, because it includes
 * middle initials that disambiguate authors who share a surname.
 */
function buildAuthorPattern(string $author): string {
    $parts = preg_split('/[\s.]+/', latinize(strtolower($author)), -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_values(array_filter($parts));
    if (empty($parts)) return '%';
    $firstIsInitial = strlen($parts[0]) === 1;
    return ($firstIsInitial ? '' : '%') . implode('%', $parts) . '%';
}

/**
 * Look up a works key in the local Open Library PostgreSQL mirror.
 * Author-first strategy: fetch all works by the matching author (small set, indexed
 * by author_key), then do PHP normTitle comparison — avoids slow GIN scans on
 * common-word titles like "Call of the Bone Ships".
 */
function findOLWorksKeyLocal(PDO $pgPdo, string $title, string $author): ?string {
    $normSearch = normTitle($title);
    if ($normSearch === '') return null;

    $authorPattern = buildAuthorPattern($author);
    if ($authorPattern === '%') return null;

    try {
        $pgPdo->exec("SET statement_timeout = '30000'");

        // Phase 1: get work keys for matching author (indexed, no works table scan)
        $stmt = $pgPdo->prepare("
            SELECT aw.work_key
            FROM authors a
            JOIN author_works aw ON aw.author_key = a.key
            WHERE a.data->>'name' ILIKE ?
            LIMIT 500
        ");
        $stmt->execute([$authorPattern]);
        $workKeys = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'work_key');
        if (empty($workKeys)) return null;

        // Phase 2: fetch titles for those work keys, filter in PHP
        $ph   = implode(',', array_fill(0, count($workKeys), '?'));
        $stmt = $pgPdo->prepare("SELECT key, data->>'title' AS title FROM works WHERE key IN ({$ph})");
        $stmt->execute($workKeys);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }

    foreach ($rows as $row) {
        $normResult = normTitle($row['title'] ?? '');
        if ($normResult === '') continue;
        if ($normResult === $normSearch
            || str_contains($normResult, $normSearch)
            || str_contains($normSearch, $normResult)) {
            return $row['key'];
        }
    }
    return null;
}

// Removes middle initials when the first part is a full word.
// "Dean R. Koontz" → "Dean Koontz", "R. F. Kuang" → unchanged (first part is itself an initial).
function stripMiddleInitials(string $name): string {
    $parts = explode(' ', $name);
    if (count($parts) < 3) return $name;
    if (preg_match('/^[A-Za-z]\.$/', $parts[0])) return $name;
    $kept = [];
    foreach ($parts as $i => $part) {
        if ($i === 0 || !preg_match('/^[A-Za-z]\.$/', $part)) $kept[] = $part;
    }
    return count($kept) < 2 ? $name : implode(' ', $kept);
}

// ── Phase 1: Load library titles ──────────────────────────────────────────────

sse('status', ['phase' => 'find', 'message' => "Loading library for \"{$author}\"..."]);

try {
    $pdo = getDatabaseConnection();
} catch (Exception $e) {
    sse('error', ['message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Search under both the given name and a version with middle initials stripped
// so "Dean R. Koontz" correctly finds "Dean Koontz" in the library.
$stripped = stripMiddleInitials($author);
$patterns = array_unique(['%' . $author . '%', '%' . $stripped . '%']);

$titleSet = [];
$stmt     = $pdo->prepare("
    SELECT GROUP_CONCAT(b.title, '||') AS titles
    FROM authors a
    JOIN books_authors_link bal ON bal.author = a.id
    JOIN books b ON b.id = bal.book
    WHERE LOWER(a.name) LIKE LOWER(:pat)
    GROUP BY a.id
    ORDER BY COUNT(b.id) DESC
    LIMIT 1
");
foreach ($patterns as $pat) {
    $stmt->execute([':pat' => $pat]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['titles']) {
        foreach (array_filter(explode('||', $row['titles']), 'strlen') as $t) {
            $titleSet[normTitle($t)] = true;
        }
    }
}
$libraryCount = count($titleSet);

sse('library_loaded', ['count' => $libraryCount, 'author' => $author]);

// ── Phase 1: Search Meilisearch ───────────────────────────────────────────────

sse('status', ['phase' => 'find', 'message' => "Searching IRC index..."]);

try {
    $msClient = new Client('http://localhost:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
    $hits     = $msClient->index('lines')->search($author, ['limit' => 500])->getHits();
} catch (Exception $e) {
    sse('error', ['message' => 'Meilisearch error: ' . $e->getMessage()]);
    exit;
}

sse('status', ['phase' => 'find', 'message' => count($hits) . " raw results, filtering..."]);

$candidates = []; // normTitle => best candidate
$ownedCount = 0;

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
    if (!authorMatches($parsed['author'], $author)) continue;

    $score = scoreLine($text, $parsed['ext']);
    $normT = normTitle($parsed['title']);

    $owned = isset($titleSet[$normT]);
    if (!$owned) {
        foreach ($titleSet as $on => $_) {
            if ($on !== '' && $normT !== '' && (str_contains($normT, $on) || str_contains($on, $normT))) {
                $owned = true; break;
            }
        }
    }

    if ($owned) { $ownedCount++; continue; }

    if (!isset($candidates[$normT]) || $score > $candidates[$normT]['score']) {
        $candidates[$normT] = [
            'line'  => $text,
            'title' => $parsed['title'],
            'ext'   => $parsed['ext'],
            'score' => $score,
        ];
    }
}

$candidateList = array_values($candidates);

sse('find_done', [
    'count'   => count($candidateList),
    'owned'   => $ownedCount,
    'library' => $libraryCount,
]);

foreach ($candidateList as $c) {
    sse('found', ['line' => $c['line'], 'title' => $c['title'], 'ext' => $c['ext']]);
}

if (empty($candidateList)) {
    sse('done', ['kept' => [], 'stats' => [
        'library'  => $libraryCount,
        'found'    => 0,
        'verified' => 0,
        'dropped'  => 0,
    ]]);
    exit;
}

// ── Phase 2: Verify against local Open Library mirror ────────────────────────

sse('status', ['phase' => 'verify', 'message' => "Verifying " . count($candidateList) . " titles against local OL database..."]);

try {
    $pgPdo = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
    $pgPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    sse('error', ['message' => 'OL database error: ' . $e->getMessage()]);
    exit;
}

$kept = [];
foreach ($candidateList as $idx => $c) {
    sse('verify_progress', ['n' => $idx + 1, 'total' => count($candidateList), 'title' => $c['title']]);

    $worksKey = findOLWorksKeyLocal($pgPdo, $c['title'], $author);

    if ($worksKey !== null) {
        $kept[] = $c['line'];
        sse('verify_result', ['line' => $c['line'], 'title' => $c['title'], 'worksKey' => $worksKey, 'kept' => true]);
    } else {
        sse('verify_result', ['title' => $c['title'], 'kept' => false]);
    }
}

sse('done', [
    'kept'  => $kept,
    'stats' => [
        'library'  => $libraryCount,
        'found'    => count($candidateList),
        'verified' => count($kept),
        'dropped'  => count($candidateList) - count($kept),
    ],
]);
