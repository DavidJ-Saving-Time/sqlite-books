<?php
/**
 * SSE stream: run scrape_similar_books.php and forward its output line-by-line.
 *
 * GET ?force=1 [&delay=N] [&dry_run=1] [&token=TOKEN]
 */

ini_set('display_errors', '0');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();

ignore_user_abort(true);
set_time_limit(0);

$user     = currentUser();
$token    = preg_replace('/[^a-z0-9]/i', '', trim($_GET['token']   ?? ''));
$force    = !empty($_GET['force']);
$dryRun   = !empty($_GET['dry_run']);
$delay    = max(0, min(30, (int)($_GET['delay'] ?? 5)));
$stopFlag = $token !== '' ? sys_get_temp_dir() . '/calibre_nilla_stop_' . $token : '';

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function classifyLine(string $line): array {
    $t = ltrim($line);
    if (preg_match('/^\[(\d+)\/(\d+)\]/', $line, $m)) {
        return ['cls' => 'gr-book', 'progress' => ['n' => (int)$m[1], 'total' => (int)$m[2]]];
    }
    if (str_starts_with($t, '→') || preg_match('/saved \d+ similar/i', $t)) {
        return ['cls' => 'gr-saved',   'progress' => null];
    }
    if (str_starts_with($t, '·'))                         return ['cls' => 'gr-skip',    'progress' => null];
    if (str_starts_with($t, 'ERROR'))                     return ['cls' => 'gr-error',   'progress' => null];
    if (preg_match('/^Done:/i', $t))                      return ['cls' => 'gr-summary', 'progress' => null];
    if (preg_match('/─{5,}/', $t))                        return ['cls' => 'gr-detail',  'progress' => null];
    return ['cls' => 'gr-detail', 'progress' => null];
}

sse('started', ['user' => $user]);

// If force: wipe progress file so the script starts fresh
if ($force) {
    $progressFile = realpath(__DIR__ . '/../data') . '/similar_books_progress.json';
    if (file_exists($progressFile)) {
        @unlink($progressFile);
    }
}

$script = realpath(__DIR__ . '/../scripts/scrape_similar_books.php');
if (!$script) {
    sse('error', ['message' => 'Script not found: scripts/scrape_similar_books.php']);
    exit;
}

// Find PHP CLI binary (not FPM)
$phpBin = PHP_BINARY;
if (stripos(basename($phpBin), 'fpm') !== false || !is_executable($phpBin)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php83'] as $try) {
        if (is_executable($try)) { $phpBin = $try; break; }
    }
}

$args = [$phpBin, $script, $user, "--delay={$delay}"];
if ($force)  $args[] = '--force';
if ($dryRun) $args[] = '--dry-run';

$cmdStr = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['redirect', 1],
];
$proc = proc_open($cmdStr, $descriptors, $pipes);

if (!is_resource($proc)) {
    sse('error', ['message' => 'proc_open failed — command: ' . $cmdStr]);
    exit;
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], true);

while (!feof($pipes[1])) {
    if ($stopFlag !== '' && file_exists($stopFlag)) {
        @unlink($stopFlag);
        proc_terminate($proc, 15);
        fclose($pipes[1]);
        proc_close($proc);
        sse('stopped', []);
        exit;
    }

    $line = fgets($pipes[1]);
    if ($line === false) break;
    $line = rtrim($line);
    if ($line === '') continue;

    ['cls' => $cls, 'progress' => $progress] = classifyLine($line);
    $data = ['text' => $line, 'cls' => $cls];
    if ($progress) $data['progress'] = $progress;
    sse('line', $data);
}

fclose($pipes[1]);
$exitCode = proc_close($proc);
sse('done', ['exit_code' => ($exitCode === -1 ? 0 : $exitCode)]);
