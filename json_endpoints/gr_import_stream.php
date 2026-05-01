<?php
/**
 * SSE stream: run a Goodreads import script (step 1/2/3) and forward its output.
 *
 * GET ?step=1|2|3 [&force=1] [&dry_run=1] [&token=TOKEN]
 */

ini_set('display_errors', '0');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();

ignore_user_abort(true);
set_time_limit(0);

$user    = currentUser();
$step    = (int)($_GET['step']  ?? 0);
$token   = preg_replace('/[^a-z0-9]/i', '', trim($_GET['token']  ?? ''));
$force   = !empty($_GET['force']);
$dryRun  = !empty($_GET['dry_run']);
$stopFlag = $token !== '' ? sys_get_temp_dir() . '/calibre_nilla_stop_' . $token : '';

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function classifyLine(string $line): array {
    // Returns ['cls' => ..., 'progress' => [...] | null]
    $progress = null;

    if (preg_match('/^\s*\[(\d+)\/(\d+)\]/', $line, $m)) {
        $progress = ['n' => (int)$m[1], 'total' => (int)$m[2]];
        return ['cls' => 'gr-book', 'progress' => $progress];
    }
    if (preg_match('/SAVED\s+\d+/i', $line))                         return ['cls' => 'gr-saved',   'progress' => null];
    if (preg_match('/\b(saved|updated|ok)\b/i', $line))              return ['cls' => 'gr-saved',   'progress' => null];
    if (preg_match('/review\s*→/u', $line))                          return ['cls' => 'gr-review',  'progress' => null];
    if (preg_match('/no (results|match|genre shelves)/i', $line))    return ['cls' => 'gr-miss',    'progress' => null];
    if (preg_match('/Nothing to do|already processed/i', $line))     return ['cls' => 'gr-skip',    'progress' => null];
    if (preg_match('/^ERROR/i', ltrim($line)))                       return ['cls' => 'gr-error',   'progress' => null];
    if (preg_match('/──.*(Batch|done|Pausing)/u', $line))            return ['cls' => 'gr-batch',   'progress' => null];
    if (preg_match('/^(\s*\[.*\]\s*)?(Done:|All done)/i', $line))    return ['cls' => 'gr-summary', 'progress' => null];

    return ['cls' => 'gr-detail', 'progress' => null];
}

sse('started', ['step' => $step, 'user' => $user]);

if (!in_array($step, [1, 2, 3], true)) {
    sse('error', ['message' => 'Invalid step (must be 1, 2, or 3)']);
    exit;
}

$scriptDir   = realpath(__DIR__ . '/../scripts');
$progressDir = realpath(__DIR__ . '/../data');

$scripts = [
    1 => ['find_goodreads_ids.py',       'goodreads_progress.json'],
    2 => ['scrape_goodreads_metadata.py', 'scrape_gr_progress.json'],
    3 => ['scrape_goodreads_shelves.py',  'shelves_progress.json'],
];

[$scriptFile, $progressFile] = $scripts[$step];
$scriptPath   = $scriptDir . '/' . $scriptFile;
$progressPath = $progressDir . '/' . $progressFile;

if (!file_exists($scriptPath)) {
    sse('error', ['message' => 'Script not found: ' . $scriptFile]);
    exit;
}

// Force: delete progress file so the script starts from scratch
if ($force && file_exists($progressPath)) {
    @unlink($progressPath);
}

$args = ['python3', $scriptPath, $user];
if ($step === 3 && $dryRun) {
    $args[] = '--dry-run';
}

$cmdStr = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

$descriptors = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['redirect', 1] // stderr → stdout
];

$proc = proc_open($cmdStr, $descriptors, $pipes);

if (!is_resource($proc)) {
    sse('error', ['message' => 'proc_open failed — command: ' . $cmdStr]);
    exit;
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], true);

while (!feof($pipes[1])) {
    // Check stop flag
    if ($stopFlag !== '' && file_exists($stopFlag)) {
        @unlink($stopFlag);
        proc_terminate($proc, 15); // SIGTERM
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
