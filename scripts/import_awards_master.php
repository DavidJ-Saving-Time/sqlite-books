<?php
/**
 * Imports all awards from awards-master.tsv into book_awards.
 *
 * This replaces the individual per-award import scripts. Run
 * export_awards_master.php first to (re)generate the master list.
 *
 * CLI:  php scripts/import_awards_master.php [/path/to/metadata.db] [--dry-run]
 * Web:  load in browser while logged in — add ?dry_run=1 for dry run
 *
 * TSV columns: award  year  author  title  result
 *
 * - Award names are upserted automatically from the file — no hardcoding.
 * - Books matched by title (case-insensitive exact match).
 * - INSERT OR IGNORE means re-running is safe (idempotent).
 * - Unmatched titles reported at the end.
 *
 * Run init_schema.php first to ensure the awards tables exist.
 */

$isCli  = php_sapi_name() === 'cli';
$dryRun = $isCli ? in_array('--dry-run', $argv ?? [], true) : isset($_GET['dry_run']);

if ($dryRun) {
    echo "DRY RUN — no changes will be written to the database.\n\n";
}

if ($isCli) {
    $dbArg = array_values(array_filter($argv ?? [], fn($a) => $a !== '--dry-run' && !str_starts_with($a, '-')))[1] ?? '';
    if (!empty($dbArg) && file_exists($dbArg)) {
        $pdo = new PDO('sqlite:' . $dbArg);
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

// ── Load master TSV ───────────────────────────────────────────────────────────
$tsvFile = __DIR__ . '/../awards-master.tsv';
if (!file_exists($tsvFile)) {
    die("ERROR: awards-master.tsv not found.\nRun export_awards_master.php first.\n");
}

$lines = file($tsvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = array_shift($lines); // skip header row

// Validate expected columns
$cols = explode("\t", $header);
if ($cols !== ['award', 'year', 'author', 'title', 'result']) {
    die("ERROR: Unexpected TSV header: $header\nExpected: award\\tyear\\tauthor\\ttitle\\tresult\n");
}

// ── Author fuzzy-matching helpers ─────────────────────────────────────────────
// Handles: accented characters, punctuation in initials, parenthetical notes
// like "(translator)" or "(as T. Kingfisher)", and multi-author strings joined
// with " and ". Returns the set of lowercase words (≥3 chars) after normalising.

function authorWordBag(string $name): array
{
    static $accentMap = [
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
    $name = strtr($name, $accentMap);               // normalise accents
    $name = preg_replace('/[^a-zA-Z\s]/', ' ', $name); // keep only letters
    $words = preg_split('/\s+/', strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($words, fn($w) => strlen($w) >= 3));
}

// Returns true when $tsvAuthor plausibly refers to one of $libraryAuthors.
// $tsvAuthor may contain:
//  - parentheticals: "Ken Liu (translator)", "Ursula Vernon (as T. Kingfisher)"
//  - multi-author:   "Robert Jordan and Brandon Sanderson"
// We include words from parentheticals so pen-name notes still match.
function authorsMatch(string $tsvAuthor, array $libraryAuthors): bool
{
    $parts = preg_split('/\s+and\s+/i', $tsvAuthor, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $part) {
        $tsvWords = authorWordBag($part);
        if (empty($tsvWords)) continue;
        foreach ($libraryAuthors as $libAuthor) {
            $libWords = authorWordBag($libAuthor);
            if (!empty(array_intersect($tsvWords, $libWords))) {
                return true;
            }
        }
    }
    return false;
}

// ── Prepared statements ───────────────────────────────────────────────────────
$upsertAward = $pdo->prepare("INSERT OR IGNORE INTO awards (name) VALUES (?)");
$getAwardId  = $pdo->prepare("SELECT id FROM awards WHERE name = ? COLLATE NOCASE");
// Fetch all books matching the title, with their authors for PHP-side fuzzy matching.
$findBooksByTitle = $pdo->prepare(
    "SELECT b.id, b.title, GROUP_CONCAT(a.name, '|||') AS authors
     FROM books b
     JOIN books_authors_link bal ON bal.book = b.id
     JOIN authors a ON a.id = bal.author
     WHERE LOWER(b.title) = LOWER(?)
     GROUP BY b.id"
);
$checkExists = $pdo->prepare("SELECT 1 FROM book_awards WHERE book_id=? AND award_id=? AND year=? AND category IS NULL");
$insert      = $pdo->prepare("INSERT INTO book_awards (book_id, award_id, year, category, result) VALUES (?,?,?,NULL,?)");

// ── Cache award IDs to avoid repeated lookups ─────────────────────────────────
$awardIdCache = [];

function getAwardId(PDO $pdo, string $name, array &$cache, $upsert, $get): int
{
    if (!isset($cache[$name])) {
        $upsert->execute([$name]);
        $get->execute([$name]);
        $cache[$name] = (int)$get->fetchColumn();
    }
    return $cache[$name];
}

// ── Process rows ──────────────────────────────────────────────────────────────
$inserted = 0;
$skipped  = 0;
$notFound = [];

foreach ($lines as $lineNum => $line) {
    $parts = explode("\t", $line);
    if (count($parts) < 5) continue;

    [$awardName, $year, $author, $title, $result] = array_map('trim', $parts);

    if ($awardName === '' || $title === '') continue;

    // Normalise typographic quotes so titles like "The Liar\u2019s Key" match
    // a library entry stored with a straight apostrophe (and vice-versa).
    $title = strtr($title, [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        "\u{2032}" => "'", "\u{2033}" => '"',
    ]);

    $year    = (int)$year;
    $awardId = getAwardId($pdo, $awardName, $awardIdCache, $upsertAward, $getAwardId);

    // Find all library books with this title, then pick the one whose author(s)
    // fuzzy-match the TSV author (handles accents, initials, multi-author strings,
    // parenthetical notes like "(translator)"). Falls back to first title match
    // only when the TSV provides no author at all.
    $findBooksByTitle->execute([$title]);
    $candidates = $findBooksByTitle->fetchAll(PDO::FETCH_ASSOC);

    $book = null;
    if ($author !== '') {
        foreach ($candidates as $c) {
            if (authorsMatch($author, explode('|||', $c['authors']))) {
                $book = $c;
                break;
            }
        }
    } else {
        $book = $candidates[0] ?? null;
    }

    if (!$book) {
        $notFound[] = ['award' => $awardName, 'year' => $year, 'author' => $author, 'title' => $title, 'result' => $result];
        continue;
    }

    // Explicit existence check — INSERT OR IGNORE is unreliable when category IS NULL
    // because SQLite treats each NULL as distinct in UNIQUE constraints.
    $checkExists->execute([$book['id'], $awardId, $year]);
    $alreadyExists = (bool)$checkExists->fetchColumn();

    if ($alreadyExists) {
        $skipped++;
        echo "  [=] {$year} {$awardName} ({$result}): {$book['title']} (already exists)\n";
    } else {
        $inserted++;
        echo "  [+] {$year} {$awardName} ({$result}): {$book['title']}\n";
        if (!$dryRun) {
            $insert->execute([$book['id'], $awardId, $year, $result]);
        }
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
if ($dryRun) {
    echo "Would insert : $inserted\n";
    echo "Already exist: $skipped\n";
} else {
    echo "Inserted : $inserted\n";
    echo "Skipped  : $skipped (already in DB)\n";
}
echo "Not found: " . count($notFound) . " (not in library)\n";

if ($notFound) {
    echo "\n--- Titles not found in your library ---\n";
    // Group by award for readability
    $byAward = [];
    foreach ($notFound as $e) {
        $byAward[$e['award']][] = $e;
    }
    foreach ($byAward as $awardName => $entries) {
        echo "\n  {$awardName}:\n";
        foreach ($entries as $e) {
            $flag = $e['result'] === 'won' ? '*' : ($e['result'] === 'special citation' ? 'c' : ' ');
            echo "    [{$e['year']}]{$flag} {$e['title']} — {$e['author']}\n";
        }
    }
}

if (!$isCli && !$dryRun) {
    require_once __DIR__ . '/../cache.php';
    invalidateCache('awards');
    echo "\nCache invalidated.\n";
}

echo "\nDone.\n";
