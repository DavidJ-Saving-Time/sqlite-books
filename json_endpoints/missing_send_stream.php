<?php
/**
 * SSE stream: send queued IRC download requests and report progress.
 *
 * GET ?token=TOKEN&limit=10
 *
 * Events:
 *   queue_loaded      {total, sent, pending}
 *   sending           {n, total, cmd}
 *   send_result       {ok, status}
 *   waiting           {elapsed, remaining}
 *   transfer_received {name, elapsed}
 *   transfer_timeout  {}
 *   countdown         {seconds}
 *   skipped           {cmd}
 *   stopped           {}
 *   send_done         {sent_count, remaining}
 *   error             {message}
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();

ignore_user_abort(true);  // keep running even if browser tab closes
set_time_limit(0);

$token     = preg_replace('/[^a-z0-9]/i', '', trim($_GET['token'] ?? ''));
$limit     = max(1, min(50, (int)($_GET['limit'] ?? 10)));
$inputFile = __DIR__ . '/../data/missing-books-results.txt';
$sentFile  = __DIR__ . '/../data/missing-books-sent.txt';
$stopFlag  = sys_get_temp_dir() . '/calibre_nilla_stop_' . $token;

const API_BASE      = 'https://node2.nilla.local';
const POLL_INTERVAL = 5;
const MIN_DELAY     = 5;
const MAX_DELAY     = 15;
const TIMEOUT       = 300;

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function isStopped(string $flag): bool {
    if (file_exists($flag)) {
        @unlink($flag);
        return true;
    }
    return false;
}

function curlGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return $err ? null : $body;
}

function getNewestMtime(): int {
    $body = curlGet(API_BASE . '/downloaded-files');
    if (!$body) return 0;
    $files = json_decode($body, true);
    if (!is_array($files)) return 0;
    $newest = 0;
    foreach ($files as $f) {
        $mt = strtotime($f['modified'] ?? '') ?: 0;
        if ($mt > $newest) $newest = $mt;
    }
    return $newest;
}

function pollForTransfer(int $baseline, int $maxWait, string $stopFlag): ?array {
    $deadline = time() + $maxWait;
    while (time() < $deadline) {
        if (isStopped($stopFlag)) return ['stopped' => true];
        sleep(POLL_INTERVAL);

        $body = curlGet(API_BASE . '/downloaded-files');
        if ($body) {
            foreach (json_decode($body, true) ?? [] as $f) {
                $mt = strtotime($f['modified'] ?? '') ?: 0;
                if ($mt > $baseline) {
                    return ['name' => $f['name'] ?? '?', 'elapsed' => time() - ($deadline - $maxWait)];
                }
            }
        }

        sse('waiting', ['elapsed' => time() - ($deadline - $maxWait), 'remaining' => max(0, $deadline - time())]);
    }
    return null;
}

// ── Load queue ────────────────────────────────────────────────────────────────

if (!file_exists($inputFile)) {
    sse('error', ['message' => 'Queue file not found: missing-books-results.txt']);
    exit;
}

$allLines = array_values(array_filter(
    array_map('trim', file($inputFile, FILE_IGNORE_NEW_LINES)),
    fn($l) => $l !== ''
));

$sentLines = [];
if (file_exists($sentFile)) {
    $sentLines = array_flip(array_filter(array_map('trim', file($sentFile, FILE_IGNORE_NEW_LINES)), 'strlen'));
}

$queue = array_values(array_filter($allLines, fn($l) => !isset($sentLines[$l])));

sse('queue_loaded', [
    'total'   => count($allLines),
    'sent'    => count($sentLines),
    'pending' => count($queue),
]);

if (empty($queue)) {
    sse('send_done', ['sent_count' => 0, 'remaining' => 0]);
    exit;
}

$toSend    = array_slice($queue, 0, $limit);
$sentFh    = fopen($sentFile, 'a');
$sentCount = 0;

// ── Send loop ─────────────────────────────────────────────────────────────────

foreach ($toSend as $idx => $cmd) {
    if (isStopped($stopFlag)) {
        sse('stopped', []);
        fclose($sentFh);
        exit;
    }

    sse('sending', ['n' => $idx + 1, 'total' => count($toSend), 'cmd' => $cmd]);

    $baseline = getNewestMtime();

    $body = curlGet(API_BASE . '/request-file?cmd=' . rawurlencode($cmd));
    $resp = $body ? (json_decode($body, true) ?? []) : [];
    $ok   = empty($resp['error']);

    sse('send_result', ['ok' => $ok, 'status' => $resp['status'] ?? ($resp['error'] ?? ($ok ? 'ok' : 'failed'))]);

    if (!$ok) {
        sse('skipped', ['cmd' => $cmd]);
        fwrite($sentFh, $cmd . "\n");
        fflush($sentFh);
        $sentCount++;
        continue;
    }

    // Poll for incoming transfer
    $result = pollForTransfer($baseline, TIMEOUT, $stopFlag);

    if ($result === null) {
        sse('transfer_timeout', []);
    } elseif (!empty($result['stopped'])) {
        fwrite($sentFh, $cmd . "\n");
        fflush($sentFh);
        sse('stopped', []);
        fclose($sentFh);
        exit;
    } else {
        sse('transfer_received', ['name' => $result['name'], 'elapsed' => $result['elapsed']]);
    }

    fwrite($sentFh, $cmd . "\n");
    fflush($sentFh);
    $sentCount++;

    // Delay before next request
    if ($idx < count($toSend) - 1) {
        $delay = random_int(MIN_DELAY, MAX_DELAY);
        for ($s = $delay; $s > 0; $s--) {
            if (isStopped($stopFlag)) {
                sse('stopped', []);
                fclose($sentFh);
                exit;
            }
            sleep(1);
            sse('countdown', ['seconds' => $s - 1]);
        }
    }
}

fclose($sentFh);
sse('send_done', ['sent_count' => $sentCount, 'remaining' => count($queue) - $sentCount]);
