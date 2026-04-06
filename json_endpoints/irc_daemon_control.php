<?php
/**
 * Controls the IRC DCC daemon process (irc_dcc_daemon.py).
 *
 * GET Parameters:
 * - action: One of 'status', 'start', or 'stop' (default: 'status').
 *
 * Returns JSON:
 * - status:  { running: bool, pid: int|null }
 * - start:   { ok: bool, pid?: int, error?: string }
 * - stop:    { ok: bool, error?: string }
 */
require_once __DIR__ . '/../db.php';
requireLogin();

header('Content-Type: application/json');

$scriptPath = '/srv/http/calibre-nilla/irc_dcc_daemon.py';
$pidFile    = '/srv/http/calibre-nilla/ircLog/irc_dcc_daemon.pid';
$logFile    = '/srv/http/calibre-nilla/ircLog/dcc_log';

function daemonPid(string $pidFile): ?int {
    if (!file_exists($pidFile)) return null;
    $pid = (int) trim(file_get_contents($pidFile));
    if ($pid > 0 && file_exists("/proc/$pid")) return $pid;
    return null;
}

$action = $_GET['action'] ?? 'status';

switch ($action) {
    case 'status':
        $pid = daemonPid($pidFile);
        echo json_encode(['running' => $pid !== null, 'pid' => $pid]);
        break;

    case 'start':
        if (daemonPid($pidFile) !== null) {
            echo json_encode(['ok' => false, 'error' => 'Already running']);
            break;
        }
        @mkdir(dirname($logFile), 0775, true);
        $cmd = "nohup python3 " . escapeshellarg($scriptPath)
             . " >> " . escapeshellarg($logFile) . " 2>&1 & echo $!";
        $pid = (int) shell_exec($cmd);
        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            echo json_encode(['ok' => true, 'pid' => $pid]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to start daemon']);
        }
        break;

    case 'stop':
        $pid = daemonPid($pidFile);
        if ($pid === null) {
            echo json_encode(['ok' => false, 'error' => 'Not running']);
            break;
        }
        posix_kill($pid, SIGTERM);
        @unlink($pidFile);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
