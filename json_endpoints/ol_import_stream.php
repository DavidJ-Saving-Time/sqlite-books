<?php
/**
 * SSE stream: run an OL import script (step 1/2/3) and forward its output.
 *
 * GET ?step=1|2|3 [&delay=N] [&force=1] [&retry_failed=1] [&skip_partial=1] [&token=TOKEN]
 */

// Suppress PHP errors from corrupting the SSE stream
ini_set('display_errors', '0');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();

ignore_user_abort(true);
set_time_limit(0);

$user    = currentUser();
$step    = (int)($_GET['step'] ?? 0);
$token   = preg_replace('/[^a-z0-9]/i', '', trim($_GET['token'] ?? ''));
$delay         = max(0, min(10, (int)($_GET['delay'] ?? 2)));
$force         = !empty($_GET['force']);
$retry         = !empty($_GET['retry_failed']);
$partial       = !empty($_GET['skip_partial']);
$preferEnglish = isset($_GET['prefer_english']) ? (bool)(int)$_GET['prefer_english'] : true;
$stopFlag = $token !== '' ? sys_get_temp_dir() . '/calibre_nilla_stop_' . $token : '';

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function classifyLine(string $line): string {
    $t = ltrim($line);
    if (preg_match('/^\[(\d+)\/(\d+)\]/', $line))           return 'ol-book';
    if (preg_match('/[✓]/', $line))                          return 'ol-match';
    if (preg_match('/[✗]/', $line))                          return 'ol-nomatch';
    if (str_starts_with($t, '+'))                            return 'ol-write';
    if (str_starts_with($t, '·'))                            return 'ol-skip';
    if (str_starts_with($t, '—'))                            return 'ol-none';
    if (preg_match('/^Done\./', $line))                      return 'ol-summary';
    if (preg_match('/^(User|DB|Books|Authors)\s*:/', $line)) return 'ol-heading';
    if (preg_match('/─{5,}/', $line))                        return 'ol-sep';
    return 'ol-detail';
}

// Confirm SSE works before any subprocess
sse('started', ['step' => $step, 'user' => $user]);

if (!in_array($step, [1, 2, 3], true)) {
    sse('error', ['message' => 'Invalid step (must be 1, 2, or 3)']);
    exit;
}

$scripts = [
    1 => 'ol_import_work_ids.php',
    2 => 'ol_import_author_ids.php',
    3 => 'ol_import_identifiers.php',
];

$script = realpath(__DIR__ . '/../scripts/' . $scripts[$step]);
if (!$script || !file_exists($script)) {
    sse('error', ['message' => 'Script not found: ' . $scripts[$step]]);
    exit;
}

// Under PHP-FPM, PHP_BINARY may be the FPM binary, not the CLI binary.
// Try to find an actual php cli binary.
$phpBin = PHP_BINARY;
if (stripos(basename($phpBin), 'fpm') !== false || !is_executable($phpBin)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php83'] as $try) {
        if (is_executable($try)) { $phpBin = $try; break; }
    }
}

// Build args list; use popen so stderr comes through the same pipe
$args = [$phpBin, $script, $user, "--delay={$delay}"];
if ($force)                          $args[] = '--force';
if ($retry  && $step === 1)          $args[] = '--retry-failed';
if ($partial && $step === 2)         $args[] = '--skip-partial';
if (in_array($step, [1, 3], true))   $args[] = $preferEnglish ? '--prefer-english' : '--no-prefer-english';

// popen with 2>&1 merges stderr into stdout so subprocess errors are visible
$cmdStr = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
$pipe   = popen($cmdStr, 'r');

if (!$pipe) {
    sse('error', ['message' => 'popen failed — command: ' . $cmdStr]);
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

    $cls  = classifyLine($line);
    $data = ['text' => $line, 'cls' => $cls];

    if (preg_match('/^\[(\d+)\/(\d+)\]/', $line, $m)) {
        $data['progress'] = ['n' => (int)$m[1], 'total' => (int)$m[2]];
    }

    sse('line', $data);
}

$exitCode = pclose($pipe);
sse('done', ['exit_code' => ($exitCode === -1 ? 0 : $exitCode)]);
