<?php
/**
 * SSE stream: run scripts/fetch_wikipedia_bulk.php and forward output line-by-line.
 *
 * GET ?delay=N [&limit=N] [&refetch=1] [&dry_run=1] [&token=TOKEN]
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
$delay    = max(0, min(30, (int)($_GET['delay']  ?? 2)));
$limit    = max(0, (int)($_GET['limit']  ?? 0));
$refetch  = !empty($_GET['refetch']);
$dryRun   = !empty($_GET['dry_run']);
$stopFlag = $token !== '' ? sys_get_temp_dir() . '/calibre_nilla_stop_' . $token : '';
$dbPath   = currentDatabasePath();

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function classifyWikiLine(string $line): array {
    $t = ltrim($line);
    if (preg_match('/^\[(\d+)\/(\d+)\]/', $line, $m)) {
        return ['cls' => 'wi-book', 'progress' => ['n' => (int)$m[1], 'total' => (int)$m[2]]];
    }
    if (str_starts_with($t, '→'))                          return ['cls' => 'wi-saved',   'progress' => null];
    if (preg_match('/\[(not found|no title|person page|author not|no extract)/i', $t))
                                                           return ['cls' => 'wi-skip',    'progress' => null];
    if (preg_match('/RATE.LIMITED/i', $t))                 return ['cls' => 'wi-error',   'progress' => null];
    if (preg_match('/\[ERROR/i', $t))                      return ['cls' => 'wi-error',   'progress' => null];
    if (preg_match('/^Done:/i', $t))                       return ['cls' => 'wi-summary', 'progress' => null];
    if (preg_match('/^Cache (cleared|saved|loaded)/i', $t))return ['cls' => 'wi-info',    'progress' => null];
    if (preg_match('/^Books to process:/i', $t))           return ['cls' => 'wi-heading', 'progress' => null];
    if (preg_match('/^Nothing to do/i', $t))               return ['cls' => 'wi-summary', 'progress' => null];
    return ['cls' => 'wi-detail', 'progress' => null];
}

sse('started', ['user' => $user]);

$script = realpath(__DIR__ . '/../scripts/fetch_wikipedia_bulk.php');
if (!$script || !file_exists($script)) {
    sse('error', ['message' => 'Script not found: scripts/fetch_wikipedia_bulk.php']);
    exit;
}

if (!file_exists($dbPath)) {
    sse('error', ['message' => 'Database not found: ' . $dbPath]);
    exit;
}

$phpBin = PHP_BINARY;
if (stripos(basename($phpBin), 'fpm') !== false || !is_executable($phpBin)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php83'] as $try) {
        if (is_executable($try)) { $phpBin = $try; break; }
    }
}

$args = [$phpBin, $script, '--db', $dbPath, '--delay', (string)$delay];
if ($limit > 0) { $args[] = '--limit'; $args[] = (string)$limit; }
if ($refetch)   $args[] = '--refetch';
if ($dryRun)    $args[] = '--dry-run';

$cmdStr = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
$pipe   = popen($cmdStr, 'r');

if (!$pipe) {
    sse('error', ['message' => 'popen failed']);
    exit;
}

stream_set_blocking($pipe, true);

while (!feof($pipe)) {
    if ($stopFlag !== '' && file_exists($stopFlag)) {
        @unlink($stopFlag);
        pclose($pipe);
        sse('stopped', []);
        exit;
    }

    $line = fgets($pipe);
    if ($line === false) break;
    $line = rtrim($line);
    if ($line === '') continue;

    $info = classifyWikiLine($line);
    $data = ['text' => $line, 'cls' => $info['cls']];
    if ($info['progress']) $data['progress'] = $info['progress'];

    sse('line', $data);
}

$exitCode = pclose($pipe);
sse('done', ['exit_code' => ($exitCode === -1 ? 0 : $exitCode)]);
