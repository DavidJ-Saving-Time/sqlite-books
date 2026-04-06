<?php
/**
 * Syncs the user's reading device with the library over SSH.
 *
 * Connects to the configured remote device (DEVICE preference) via SSH,
 * lists all ebook files under REMOTE_DIR, and fuzzy-matches them against
 * the Calibre library by author and title. For each matched book it reads
 * the KOReader sidecar .lua file to extract reading progress (percent,
 * pages) and the last-accessed date from the summary block.
 *
 * Books with ≥90% progress are automatically marked as 'Read' in the
 * library, unless their current status is 'read challange'.
 *
 * Results are cached to cache/{user}/device_books.json for use by
 * list_books.php (on-device filtering, progress bars, recently-read list).
 *
 * POST (no parameters required — reads from user preferences).
 *
 * Returns JSON:
 * - Success: { status: 'ok', count: int, synced_at: string, marked_read: int, books: array }
 * - Failure: { error: string, detail?: string }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$remoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
$device    = getUserPreference(currentUser(), 'DEVICE',     getPreference('DEVICE', ''));

if ($remoteDir === '' || $device === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Remote device not configured. Set DEVICE and REMOTE_DIR in Preferences.']);
    exit;
}

$identity  = '/home/david/.ssh/id_rsa';
$sshTarget = 'root@' . $device;
$sshOpts   = '-o ConnectTimeout=10 -o BatchMode=yes -o StrictHostKeyChecking=accept-new';

// List all book files under the remote directory
$findCmd = sprintf(
    'ssh %s -i %s %s %s 2>&1',
    $sshOpts,
    escapeshellarg($identity),
    escapeshellarg($sshTarget),
    escapeshellarg('find ' . escapeshellarg($remoteDir) . ' -type f \( -iname "*.epub" -o -iname "*.mobi" -o -iname "*.azw3" -o -iname "*.pdf" -o -iname "*.cbz" -o -iname "*.cbr" \) | sort')
);

exec($findCmd, $output, $exitCode);

if ($exitCode !== 0) {
    echo json_encode([
        'error'  => 'SSH command failed (exit ' . $exitCode . ').',
        'detail' => implode("\n", $output),
    ]);
    exit;
}

// Normalise a string for fuzzy matching:
// lowercase, transliterate accented chars, strip leading series-index prefix,
// keep only a-z0-9 and spaces.
function norm(string $s): string {
    $s = trim($s);
    // Transliterate accented/Unicode chars to ASCII equivalents so that e.g.
    // "García" (library) matches "Garcia" (device path via safe_filename iconv).
    // Must happen before strtolower so iconv sees the original casing.
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    // Strip leading series-index prefix:
    //   "01 - Title", "2.5 - Title"  (dash required when no decimal)
    //   "13.00 Title"                (decimal present, dash optional)
    $s = preg_replace('/^\d+\.\d+\s*(?:[-–]\s*)?|^\d+\s*[-–]\s*/', '', $s);
    // Normalise article placement so "Doubt Factory, The" and "The Doubt Factory"
    // both reduce to "doubt factory":
    //   strip trailing ", the" / ", a" / ", an"  (Calibre sort format)
    $s = preg_replace('/,\s*(the|an|a)$/', '', $s);
    //   strip leading "the " / "a " / "an "
    $s = preg_replace('/^(the|an|a)\s+/', '', $s);
    // Strip apostrophes to nothing so "Carl's" → "carls" not "carl s".
    $s = preg_replace("/[']/", '', $s);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

// Build a lookup index from the library DB: norm(author)||norm(title) → array of matches
$pdo = getDatabaseConnection();
$libraryIndex      = []; // norm_key => [['id' => int, 'title' => string, 'genre' => string], ...]
$libraryTitleIndex = []; // norm(title) => [entry, ...] — fallback when author doesn't match
$authorNorms       = []; // set of all normalised author names (and surnames) for suffix stripping

// Fetch each book's first genre (for disambiguation when author+title collides)
$bookGenres = [];
try {
    $genreColId = $pdo->query("SELECT id FROM custom_columns WHERE label = 'genre' LIMIT 1")->fetchColumn();
    if ($genreColId !== false) {
        $gs = $pdo->query("SELECT l.book, v.value FROM custom_column_{$genreColId} v
                           JOIN books_custom_column_{$genreColId}_link l ON v.id = l.value");
        foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $g) {
            if (!isset($bookGenres[$g['book']])) {
                $bookGenres[$g['book']] = $g['value'];
            }
        }
    }
} catch (Exception $e) {}

$stmt = $pdo->query('
    SELECT b.id, b.title,
           GROUP_CONCAT(a.name, "|") AS authors
    FROM books b
    LEFT JOIN books_authors_link ba ON ba.book = b.id
    LEFT JOIN authors a             ON a.id    = ba.author
    GROUP BY b.id
');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $normTitle  = norm($row['title']);
    $bookGenre  = $bookGenres[$row['id']] ?? '';
    $entry      = ['id' => (int)$row['id'], 'title' => $row['title'], 'genre' => $bookGenre];

    // Title-only fallback index (used when author matching fails)
    $libraryTitleIndex[$normTitle][] = $entry;

    foreach (array_filter(explode('|', $row['authors'] ?? ''), 'strlen') as $authorName) {
        $normAuthor = norm($authorName);
        $authorNorms[$normAuthor] = true;

        $words = explode(' ', $normAuthor);
        $authorNorms[end($words)] = true;

        $libraryIndex[$normAuthor . '||' . $normTitle][] = $entry;

        $surnameKey = end($words) . '||' . $normTitle;
        // Only add surname entry if it doesn't already point here (avoid duplicate entries)
        $alreadyPresent = false;
        foreach ($libraryIndex[$surnameKey] ?? [] as $e) {
            if ($e['id'] === $entry['id']) { $alreadyPresent = true; break; }
        }
        if (!$alreadyPresent) {
            $libraryIndex[$surnameKey][] = $entry;
        }
    }
}

// Strip a trailing " - Author Name" suffix from a title if the suffix matches a
// known author. Uses norm() on the suffix to match against $authorNorms.
function stripAuthorSuffix(string $title, array $authorNorms): string {
    // Find the last " - " in the string
    $pos = strrpos($title, ' - ');
    if ($pos === false) return $title;
    $suffix = substr($title, $pos + 3); // everything after " - "
    $normSuffix = norm($suffix);
    if ($normSuffix === '') return $title;
    // Match full suffix, or just the last word (surname) of the suffix
    $suffixWords = explode(' ', $normSuffix);
    $suffixSurname = end($suffixWords);
    if (isset($authorNorms[$normSuffix]) || isset($authorNorms[$suffixSurname])) {
        return rtrim(substr($title, 0, $pos));
    }
    return $title;
}

// Parse device paths into structured entries and attempt library matching
$remoteBase = rtrim($remoteDir, '/');
$books = [];

foreach ($output as $line) {
    $line = trim($line);
    if ($line === '') continue;

    $rel      = ltrim(substr($line, strlen($remoteBase)), '/');
    $parts    = explode('/', $rel);
    $filename = array_pop($parts);
    $genre    = $parts[0] ?? '';
    $author   = $parts[1] ?? '';
    $series   = count($parts) >= 3 ? $parts[2] : '';
    $ext      = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
    $rawTitle = pathinfo($filename, PATHINFO_FILENAME);
    // Strip leading series index: "01 - Title", "13.00 Title", "2.5 - Title"
    $title = preg_replace('/^\d+\.\d+\s*(?:[-–]\s*)?|^\d+\s*[-–]\s*/', '', $rawTitle);
    // Strip trailing " - Author Name" if the suffix matches a known library author
    $title = stripAuthorSuffix($title, $authorNorms);

    // Attempt library match
    $libraryId    = null;
    $libraryTitle = null;
    $normT = norm($title);
    $normA = norm($author);
    $aWords = explode(' ', $normA);

    // Try full-author match first, then surname-only, then title-only fallback
    $candidates = null;
    foreach ([$normA, end($aWords)] as $aKey) {
        $key = $aKey . '||' . $normT;
        if (!empty($libraryIndex[$key])) {
            $candidates = $libraryIndex[$key];
            break;
        }
    }
    // Title-only fallback: only use when exactly one library book has this title
    // (avoids false positives for common titles)
    if ($candidates === null && !empty($libraryTitleIndex[$normT]) && count($libraryTitleIndex[$normT]) === 1) {
        $candidates = $libraryTitleIndex[$normT];
    }
    if ($candidates) {
        $match = $candidates[0]; // default: first match
        if (count($candidates) > 1) {
            // Disambiguate by genre directory on device
            $normGenre = norm($genre);
            foreach ($candidates as $c) {
                if (norm($c['genre']) === $normGenre) {
                    $match = $c;
                    break;
                }
            }
        }
        $libraryId    = $match['id'];
        $libraryTitle = $match['title'];
    }

    $books[] = [
        'path'          => $line,
        'genre'         => $genre,
        'author'        => $author,
        'series'        => $series,
        'title'         => $title,
        'format'        => $ext,
        'library_id'    => $libraryId,
        'library_title' => $libraryTitle,
    ];
}

// Fetch KOReader sidecar .lua files for library-matched books.
// Each book at "path/stem.epub" has its sidecar at "path/stem.sdr/metadata.epub.lua".

// Default lua fields on every book
foreach ($books as &$b) {
    $b['lua_exists']        = false;
    $b['lua_pages']         = null;
    $b['lua_percent']       = null;
    $b['lua_last_accessed'] = null;
}
unset($b);

// Build index → lua path for matched books only
$luaPathMap = []; // books index => remote lua path
foreach ($books as $i => $b) {
    if ($b['library_id'] === null) continue;
    $stem = pathinfo(basename($b['path']), PATHINFO_FILENAME);
    $ext  = strtolower(pathinfo($b['path'], PATHINFO_EXTENSION));
    $luaPathMap[$i] = dirname($b['path']) . '/' . $stem . '.sdr/metadata.' . $ext . '.lua';
}

if (!empty($luaPathMap)) {
    // Build a sh script and pipe it via proc_open — avoids double-escaping the
    // paths through two layers of shell argument quoting.
    $scriptLines = [];
    foreach ($luaPathMap as $i => $lp) {
        // Single-quote the path for the remote sh (escapeshellarg does exactly this)
        $qp = escapeshellarg($lp);
        $scriptLines[] = "echo __IDX_$i";
        $scriptLines[] = "if [ -f $qp ]; then echo __EXISTS; cat $qp; fi";
        $scriptLines[] = "echo __END_$i";
    }
    $script = implode("\n", $scriptLines) . "\n";

    $sshCmd = sprintf('ssh %s -i %s %s sh 2>/dev/null',
        $sshOpts,
        escapeshellarg($identity),
        escapeshellarg($sshTarget)
    );

    $proc = proc_open($sshCmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    $luaLines = [];
    $luaExit  = 1;
    if (is_resource($proc)) {
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $luaExit  = proc_close($proc);
        $luaLines = $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
    }

    if ($luaExit === 0) {
        $curIdx     = null;
        $curExists  = false;
        $curContent = [];

        foreach ($luaLines as $line) {
            if (preg_match('/^__IDX_(\d+)$/', $line, $m)) {
                $curIdx     = (int)$m[1];
                $curExists  = false;
                $curContent = [];
            } elseif ($line === '__EXISTS' && $curIdx !== null) {
                $curExists = true;
            } elseif (preg_match('/^__END_(\d+)$/', $line, $m) && (int)$m[1] === $curIdx) {
                if ($curExists) {
                    $books[$curIdx]['lua_exists'] = true;
                    $c = implode("\n", $curContent);
                    if (preg_match('/\["doc_pages"\]\s*=\s*(\d+)/', $c, $p)) {
                        $books[$curIdx]['lua_pages'] = (int)$p[1];
                    } elseif (preg_match('/\["pages"\]\s*=\s*(\d+)/', $c, $p)) {
                        $books[$curIdx]['lua_pages'] = (int)$p[1];
                    }
                    if (preg_match('/\["percent_finished"\]\s*=\s*([\d.]+)/', $c, $p)) {
                        $books[$curIdx]['lua_percent'] = (float)$p[1];
                    }
                    if (preg_match('/\["summary"\]\s*=\s*\{[^}]*\["modified"\]\s*=\s*"([^"]+)"/s', $c, $p)) {
                        $books[$curIdx]['lua_last_accessed'] = $p[1];
                    }
                }
                $curIdx = null;
            } elseif ($curIdx !== null && $curExists) {
                $curContent[] = $line;
            }
        }
    }
}

// Mark books ≥90% complete as 'Read' in the library DB
$markedRead = 0;
try {
    $statusColId = $pdo->query("SELECT id FROM custom_columns WHERE label = 'status' LIMIT 1")->fetchColumn();
    if ($statusColId !== false) {
        $linkTable  = "books_custom_column_{$statusColId}_link";
        $valueTable = "custom_column_{$statusColId}";
        $readId          = $pdo->query("SELECT id FROM {$valueTable} WHERE value = 'Read' LIMIT 1")->fetchColumn();
        $readChallengeId = $pdo->query("SELECT id FROM {$valueTable} WHERE value = 'read challange' LIMIT 1")->fetchColumn();

        if ($readId !== false) {
            $getStatus = $pdo->prepare(
                "SELECT value FROM {$linkTable} WHERE book = ?"
            );
            $update = $pdo->prepare(
                "UPDATE {$linkTable} SET value = ? WHERE book = ?"
            );
            $insert = $pdo->prepare(
                "INSERT INTO {$linkTable} (book, value) VALUES (?, ?)"
            );

            foreach ($books as $b) {
                if (empty($b['lua_exists']))         continue;
                if ($b['library_id'] === null)       continue;
                if (($b['lua_percent'] ?? 0) < 0.9) continue;

                $bookId = (int)$b['library_id'];
                $getStatus->execute([$bookId]);
                $currentValueId = $getStatus->fetchColumn();

                if ($currentValueId === $readId)                                    continue; // already Read
                if ($readChallengeId !== false && $currentValueId === $readChallengeId) continue; // in read challenge

                if ($currentValueId !== false) {
                    $update->execute([$readId, $bookId]);
                } else {
                    $insert->execute([$bookId, $readId]);
                }
                $markedRead++;
            }

            if ($markedRead > 0) {
                invalidateCache('statuses');
            }
        }
    }
} catch (Exception $e) {
    // Non-fatal: sync still succeeds even if status update fails
}

// Save to cache
$cacheDir = __DIR__ . '/../cache/' . currentUser();
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/device_books.json';
file_put_contents($cacheFile, json_encode([
    'synced_at' => date('c'),
    'device'    => $device,
    'count'     => count($books),
    'books'     => $books,
], JSON_PRETTY_PRINT));

echo json_encode([
    'status'      => 'ok',
    'count'       => count($books),
    'synced_at'   => date('c'),
    'marked_read' => $markedRead,
    'books'       => $books,
]);
