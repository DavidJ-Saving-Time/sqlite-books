<?php
/**
 * Remove errored book IDs from a Goodreads progress file so they get retried on next run.
 *
 * POST ?step=1|2|3
 *
 * Reads the relevant log TSV, finds rows where the error column is a real fetch error
 * (not a legitimate empty-result like "no_results" or "no_genre_shelves"), and removes
 * those book IDs from done_ids in the matching progress JSON.
 *
 * Returns: {"ok": true, "purged": N, "remaining_done": M}
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$step            = (int)($_POST['step'] ?? 0);
$includePermanent = !empty($_POST['permanent']); // also purge 404/wrong-ID errors
if (!in_array($step, [1, 2, 3], true)) {
    echo json_encode(['error' => 'Invalid step']);
    exit;
}

$dir = __DIR__ . '/../data';

// Errors that mean the GR ID itself is wrong — retrying will never help.
// These must match the PERMANENT_ERRORS sets in the Python scripts exactly.
const PERMANENT_ERRORS = [
    '404 not found',
    'book node not found in Apollo state',
    '__NEXT_DATA__ not found',
];

$config = [
    1 => [
        'log'      => $dir . '/goodreads_notfound.tsv',
        'progress' => $dir . '/goodreads_progress.json',
        // Step 1: error rows are in notfound log; only fetch_error: rows are network errors.
        // Columns: book_id, book_title, book_authors, reason
        'id_col'   => 0,
        'err_col'  => 3,
        'is_error' => fn(string $val): bool => str_starts_with($val, 'fetch_error:'),
    ],
    2 => [
        'log'      => $dir . '/scrape_gr_log.tsv',
        'progress' => $dir . '/scrape_gr_progress.json',
        // Step 2: columns: book_id, title, gr_id, saved, error
        'id_col'  => 0,
        'err_col' => 4,
        'is_error' => fn(string $val): bool =>
            $val !== '' && ($includePermanent || !in_array($val, PERMANENT_ERRORS, true)),
    ],
    3 => [
        'log'      => $dir . '/shelves_log.tsv',
        'progress' => $dir . '/shelves_progress.json',
        // Step 3: columns: book_id, title, gr_id, tags_saved, error
        // Never retry "no_genre_shelves" — that's a legitimate empty result.
        'id_col'  => 0,
        'err_col' => 4,
        'is_error' => fn(string $val): bool =>
            $val !== ''
            && $val !== 'no_genre_shelves'
            && ($includePermanent || !in_array($val, PERMANENT_ERRORS, true)),
    ],
];

['log' => $logFile, 'progress' => $progressFile,
 'id_col' => $idCol, 'err_col' => $errCol, 'is_error' => $isError] = $config[$step];

if (!file_exists($logFile)) {
    echo json_encode(['error' => 'Log file not found — nothing to retry']);
    exit;
}

if (!file_exists($progressFile)) {
    echo json_encode(['error' => 'Progress file not found — nothing to purge']);
    exit;
}

// Read log, collect error IDs (only fetch/network errors, not legitimate empty results).
// The log is append-only; a book may appear multiple times. If it has a later success entry
// after an error entry, we leave it alone.

$errorIds   = [];   // book_id => true
$successIds = [];   // book_id => true  (appeared with no error after an error)

$fh = fopen($logFile, 'r');
$header = true;
while (($row = fgetcsv($fh, 0, "\t")) !== false) {
    if ($header) { $header = false; continue; }
    if (count($row) <= max($idCol, $errCol)) continue;

    $bid = (int)$row[$idCol];
    $errVal = trim($row[$errCol]);

    if ($isError($errVal)) {
        $errorIds[$bid] = true;
        unset($successIds[$bid]);   // clear any prior success marker
    } else {
        // Successful or legitimate-empty row — if this book was previously errored,
        // it was later re-processed successfully; don't touch it.
        if (isset($errorIds[$bid])) {
            $successIds[$bid] = true;
            unset($errorIds[$bid]);
        }
    }
}
fclose($fh);

if (empty($errorIds)) {
    echo json_encode(['ok' => true, 'purged' => 0, 'message' => 'No fetch errors found in log']);
    exit;
}

// Remove error IDs from done_ids in the progress file.
$progress = json_decode(file_get_contents($progressFile), true);
if (!is_array($progress) || !isset($progress['done_ids'])) {
    echo json_encode(['error' => 'Progress file is malformed']);
    exit;
}

$before = count($progress['done_ids']);
$progress['done_ids'] = array_values(
    array_filter($progress['done_ids'], fn($id) => !isset($errorIds[$id]))
);
$purged = $before - count($progress['done_ids']);

file_put_contents($progressFile, json_encode($progress));

echo json_encode([
    'ok'             => true,
    'purged'         => $purged,
    'remaining_done' => count($progress['done_ids']),
]);
