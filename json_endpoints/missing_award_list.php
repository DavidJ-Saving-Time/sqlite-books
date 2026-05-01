<?php
/**
 * Return a JSON list of award-winning/nominated books not in the library.
 * Runs import_awards_master.php --dry-run and parses the not-found section.
 *
 * GET ?awards[]=Name&won_only=1
 * Returns: [{award, year, title, author, result}, ...]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$selectedAwards = array_map('trim', (array)($_GET['awards'] ?? []));
$wonOnly        = !empty($_GET['won_only']);

$phpBin = PHP_BINARY;
if (stripos(basename($phpBin), 'fpm') !== false || !is_executable($phpBin)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php83'] as $try) {
        if (is_executable($try)) { $phpBin = $try; break; }
    }
}

$dbPath = currentDatabasePath();
$script = realpath(__DIR__ . '/../scripts/import_awards_master.php');

if (!$script) {
    echo json_encode(['error' => 'import_awards_master.php not found']);
    exit;
}

$cmd   = implode(' ', array_map('escapeshellarg', [$phpBin, $script, $dbPath, '--dry-run'])) . ' 2>&1';
$lines = [];
exec($cmd, $lines);

if (empty($lines)) {
    echo json_encode(['error' => 'Dry-run produced no output']);
    exit;
}

$books        = [];
$inSection    = false;
$currentAward = '';

foreach ($lines as $line) {
    if (str_contains($line, 'Titles not found in your library')) {
        $inSection = true;
        continue;
    }
    if (!$inSection) continue;

    if (preg_match('/^\s{2}(.+):$/', $line, $m)) {
        $currentAward = trim($m[1]);
        continue;
    }

    if (!preg_match('/^\s+\[(\d{4})\]([* c])\s+(.+?)\s+—\s+(.+)$/', $line, $m)) continue;

    $year   = (int)$m[1];
    $flag   = trim($m[2]);
    $title  = trim($m[3]);
    $author = trim($m[4]);
    $result = $flag === '*' ? 'won' : ($flag === 'c' ? 'special citation' : 'nominated');

    if ($wonOnly && $result !== 'won') continue;
    if (!empty($selectedAwards) && !in_array($currentAward, $selectedAwards, true)) continue;

    $books[] = [
        'award'  => $currentAward,
        'year'   => $year,
        'title'  => $title,
        'author' => $author,
        'result' => $result,
    ];
}

echo json_encode($books);
