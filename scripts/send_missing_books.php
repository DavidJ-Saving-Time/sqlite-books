<?php
/**
 * Sends each line from missing-books-results.txt to the IRC request-file API,
 * then waits for a new file to appear in /downloaded-files before sending the
 * next request. A 5–15 second delay is added after a confirmed transfer.
 *
 * CLI:  php scripts/send_missing_books.php [options]
 *
 * Options:
 *   --limit N      Max number of requests to send this run (default: 10)
 *   --user NAME    Only send lines for this IRC bot (e.g. --user Bsk)
 *   --input FILE   Input file (default: missing-books-results.txt)
 *   --sent FILE    Progress tracking file (default: missing-books-sent.txt)
 *   --timeout N    Seconds to wait for a transfer before giving up (default: 300)
 *   --dry-run      Print commands without sending anything
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// ── Parse arguments ───────────────────────────────────────────────────────────

$args      = $argv;
$limit     = 10;
$dryRun    = false;
$maxWait   = 300;
$inputFile = __DIR__ . '/../data/missing-books-results.txt';
$sentFile  = __DIR__ . '/../data/missing-books-sent.txt';
$botUser   = null;

for ($i = 1; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--limit':   $limit     = (int)($args[++$i] ?? 10);   break;
        case '--user':    $botUser   = $args[++$i] ?? null;         break;
        case '--input':   $inputFile = $args[++$i];                 break;
        case '--sent':    $sentFile  = $args[++$i];                 break;
        case '--timeout': $maxWait   = (int)($args[++$i] ?? 300);  break;
        case '--dry-run': $dryRun    = true;                        break;
    }
}

const API_BASE         = 'https://node2.nilla.local';
const POLL_INTERVAL    = 5;   // seconds between /downloaded-files polls
const MIN_POST_DELAY   = 5;   // seconds to wait after a confirmed transfer
const MAX_POST_DELAY   = 15;

// ── Load input and sent tracking ──────────────────────────────────────────────

if (!file_exists($inputFile)) {
    die("ERROR: Input file not found: $inputFile\n");
}

$allLines = array_values(array_filter(
    array_map('trim', file($inputFile, FILE_IGNORE_NEW_LINES)),
    fn($l) => $l !== ''
));

if (empty($allLines)) {
    die("ERROR: Input file is empty.\n");
}

$sentLines = [];
if (file_exists($sentFile)) {
    $sentLines = array_flip(array_filter(
        array_map('trim', file($sentFile, FILE_IGNORE_NEW_LINES)),
        fn($l) => $l !== ''
    ));
}

// Filter to a specific IRC bot if --user was given
if ($botUser !== null) {
    $prefix   = '!' . $botUser;
    $allLines = array_values(array_filter($allLines, fn($l) => stripos($l, $prefix) === 0));
    if (empty($allLines)) {
        die("ERROR: No lines found starting with {$prefix}\n");
    }
}

$queue = array_values(array_filter($allLines, fn($l) => !isset($sentLines[$l])));

$total       = count($allLines);
$alreadySent = count($sentLines);
$remaining   = count($queue);

echo "Input    : $inputFile\n";
if ($botUser !== null) echo "Bot      : !{$botUser}\n";
echo "Total    : $total lines\n";
echo "Sent     : $alreadySent already sent\n";
echo "Remaining: $remaining to send\n";
echo "Limit    : $limit this run\n";
echo "Timeout  : {$maxWait}s per request\n";
if ($dryRun) echo "Mode     : DRY RUN\n";
echo "\n";

if ($remaining === 0) {
    echo "Nothing left to send.\n";
    exit(0);
}

$toSend = array_slice($queue, 0, $limit);

// ── cURL helpers ──────────────────────────────────────────────────────────────

function curlGet(string $url): ?string
{
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

function sendRequest(string $cmd): bool
{
    $body = curlGet(API_BASE . '/request-file?cmd=' . rawurlencode($cmd));
    if ($body === null) {
        echo " [ERROR: curl failed]";
        return false;
    }
    $data = json_decode($body, true);
    $msg  = $data['status'] ?? $data['error'] ?? 'ok';
    echo " [$msg]";
    return empty($data['error']);
}

/**
 * Fetch the most recent file modification time from /downloaded-files.
 * Returns a Unix timestamp, or 0 on failure.
 */
function getNewestFileMtime(): int
{
    $body = curlGet(API_BASE . '/downloaded-files');
    if ($body === null) return 0;
    $files = json_decode($body, true);
    if (!is_array($files) || empty($files)) return 0;

    $newest = 0;
    foreach ($files as $f) {
        $mt = strtotime($f['modified'] ?? '') ?: 0;
        if ($mt > $newest) $newest = $mt;
    }
    return $newest;
}

/**
 * Poll /downloaded-files until a file with mtime > $baseline appears,
 * or until $maxWait seconds have elapsed.
 *
 * Returns ['name' => string, 'elapsed' => int] on success, or null on timeout.
 */
function waitForTransfer(int $baseline, int $maxWait): ?array
{
    $deadline = time() + $maxWait;
    $dots     = 0;

    while (time() < $deadline) {
        sleep(POLL_INTERVAL);

        $body = curlGet(API_BASE . '/downloaded-files');
        if ($body === null) {
            echo "\r  [poll error, retrying...]" . str_repeat(' ', 10);
            continue;
        }

        $files = json_decode($body, true);
        if (!is_array($files)) continue;

        foreach ($files as $f) {
            $mt = strtotime($f['modified'] ?? '') ?: 0;
            if ($mt > $baseline) {
                $elapsed = time() - ($deadline - $maxWait);
                return ['name' => $f['name'] ?? '?', 'elapsed' => $elapsed];
            }
        }

        $remaining = $deadline - time();
        $dots = ($dots + 1) % 4;
        echo "\r  Waiting for transfer" . str_repeat('.', $dots) . str_repeat(' ', 4 - $dots)
           . " ({$remaining}s remaining)" . str_repeat(' ', 5);
    }

    return null; // timed out
}

// ── Main loop ─────────────────────────────────────────────────────────────────

$sentFh    = $dryRun ? null : fopen($sentFile, 'a');
$sentCount = 0;

foreach ($toSend as $idx => $cmd) {
    $n = $idx + 1;
    echo sprintf("[%d/%d] %s", $n, count($toSend), $cmd);

    if ($dryRun) {
        echo " [dry run]\n";
        $sentCount++;
        continue;
    }

    // Snapshot current newest file before sending
    $baselineMtime = getNewestFileMtime();

    $ok = sendRequest($cmd);
    echo "\n";

    if (!$ok) {
        echo "  Request failed — skipping.\n\n";
        continue;
    }

    // Wait for a new file to appear in /downloaded-files
    echo "  Watching for incoming transfer (timeout {$maxWait}s)...\n";
    $result = waitForTransfer($baselineMtime, $maxWait);
    echo "\r" . str_repeat(' ', 60) . "\r";

    if ($result === null) {
        echo "  [TIMEOUT] No transfer detected after {$maxWait}s — moving on.\n\n";
        // Still record as sent so we don't re-request on the next run
        fwrite($sentFh, $cmd . "\n");
        fflush($sentFh);
        $sentCount++;
        continue;
    }

    echo "  [OK] Received: {$result['name']} (after {$result['elapsed']}s)\n";

    // Record as sent
    fwrite($sentFh, $cmd . "\n");
    fflush($sentFh);
    $sentCount++;

    // Short delay before next request (skip after the last one)
    if ($idx < count($toSend) - 1) {
        $delay = random_int(MIN_POST_DELAY, MAX_POST_DELAY);
        for ($s = $delay; $s > 0; $s--) {
            echo "\r  Next request in {$s}s..." . str_repeat(' ', 10);
            sleep(1);
        }
        echo "\r" . str_repeat(' ', 40) . "\r";
    }
}

if ($sentFh) fclose($sentFh);

echo "\n";
echo "Sent this run  : $sentCount\n";
$newRemaining = $remaining - $sentCount;
echo "Still remaining: $newRemaining\n";

if ($newRemaining > 0) {
    echo "\nRun again to send more.\n";
} else {
    echo "\nAll done — every line has been sent.\n";
}
