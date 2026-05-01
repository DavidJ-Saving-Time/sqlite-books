<?php
/**
 * SSE stream: run awards export or import script and forward output.
 *
 * GET ?step=1|2 [&dry_run=1] [&token=TOKEN]
 *
 * Step 1: php scripts/export_awards_master.php
 * Step 2: php scripts/import_awards_master.php /path/to/db [--dry-run]
 *
 * Both scripts complete in well under a second, so we use exec() to capture
 * all output at once (avoids popen pipe/EOF/blocking issues entirely).
 */

ini_set('display_errors', '0');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();

set_time_limit(120);

$step   = (int)($_GET['step'] ?? 0);
$dryRun = !empty($_GET['dry_run']);

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function classifyAwardLine(string $line): string {
    $t = ltrim($line);
    if (str_starts_with($t, 'DRY RUN'))                                                   return 'aw-dryrun';
    if (preg_match('/^\[=\]/', $t))                                                        return 'aw-skip';
    if (preg_match('/^\[\+\]/', $t))                                                       return 'aw-insert';
    if (preg_match('/^---/', $t))                                                          return 'aw-heading';
    if (preg_match('/^(Would insert|Already exist|Inserted|Skipped|Not found)/', $t))      return 'aw-summary';
    if (preg_match('/^\[\d{4}\]/', $t))                                                    return 'aw-notfound';
    if (preg_match('/^Done\.$/', rtrim($t)))                                               return 'aw-done';
    if (preg_match('/^(Cache invalidated|Written \d+ entries)/', $t))                     return 'aw-done';
    return 'aw-detail';
}

sse('started', ['step' => $step]);

if (!in_array($step, [1, 2], true)) {
    sse('error', ['message' => 'Invalid step (must be 1 or 2)']);
    exit;
}

// PHP CLI binary detection (PHP_BINARY may be php-fpm under FPM)
$phpBin = PHP_BINARY;
if (stripos(basename($phpBin), 'fpm') !== false || !is_executable($phpBin)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php83'] as $try) {
        if (is_executable($try)) { $phpBin = $try; break; }
    }
}

$scriptDir = realpath(__DIR__ . '/../scripts');

if ($step === 1) {
    $script = $scriptDir . '/export_awards_master.php';
    if (!file_exists($script)) {
        sse('error', ['message' => 'Script not found: export_awards_master.php']);
        exit;
    }
    $args = [$phpBin, $script];
} else {
    $script = $scriptDir . '/import_awards_master.php';
    if (!file_exists($script)) {
        sse('error', ['message' => 'Script not found: import_awards_master.php']);
        exit;
    }
    $dbPath = currentDatabasePath();
    $args   = [$phpBin, $script, $dbPath];
    if ($dryRun) $args[] = '--dry-run';
}

$cmdStr  = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
$lines   = [];
$exitCode = 0;

exec($cmdStr, $lines, $exitCode);

if (empty($lines)) {
    sse('error', ['message' => 'Script produced no output — command: ' . $cmdStr]);
    exit;
}

foreach ($lines as $line) {
    $line = rtrim($line);
    if ($line === '') continue;
    sse('line', ['text' => $line, 'cls' => classifyAwardLine($line)]);
}

sse('done', ['exit_code' => $exitCode]);
