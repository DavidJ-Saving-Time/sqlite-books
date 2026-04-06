<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$devicePath = trim($_POST['device_path'] ?? '');
if ($devicePath === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No device path specified.']);
    exit;
}

$device    = getUserPreference(currentUser(), 'DEVICE',     getPreference('DEVICE',     ''));
$remoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));

if ($device === '' || $remoteDir === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Remote device not configured. Set DEVICE and REMOTE_DIR in Preferences.']);
    exit;
}

// Security: reject paths outside the configured remote directory
$remoteBase = rtrim($remoteDir, '/');
if (!str_starts_with($devicePath, $remoteBase . '/')) {
    http_response_code(403);
    echo json_encode(['error' => 'Path is outside the configured remote directory.']);
    exit;
}

$identity  = '/home/david/.ssh/id_rsa';
$sshTarget = 'root@' . $device;
$sshOpts   = '-o ConnectTimeout=10 -o BatchMode=yes -o StrictHostKeyChecking=accept-new';

$rmCmd = sprintf(
    'ssh %s -i %s %s %s 2>&1',
    $sshOpts,
    escapeshellarg($identity),
    escapeshellarg($sshTarget),
    escapeshellarg('rm -f ' . escapeshellarg($devicePath))
);

exec($rmCmd, $output, $exitCode);

if ($exitCode !== 0) {
    echo json_encode(['error' => 'SSH command failed.', 'detail' => implode("\n", $output)]);
    exit;
}

// Remove empty parent directories up to (but not including) remoteBase
$parentDir = dirname($devicePath);
if ($parentDir !== $remoteBase && str_starts_with($parentDir, $remoteBase . '/')) {
    $pruneCmd = sprintf(
        'ssh %s -i %s %s %s 2>&1',
        $sshOpts,
        escapeshellarg($identity),
        escapeshellarg($sshTarget),
        escapeshellarg(
            'dir=' . escapeshellarg($parentDir) . '; base=' . escapeshellarg($remoteBase) . '; ' .
            'while [ "$(dirname "$dir")" != "$base" ] && [ -d "$dir" ]; do rmdir "$dir" 2>/dev/null || break; dir=$(dirname "$dir"); done'
        )
    );
    exec($pruneCmd); // best-effort — ignore exit code
}

// Trigger background sync to update the cache
$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$syncUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . '/json_endpoints/sync_device.php';
$cookie  = 'user=' . rawurlencode(currentUser());
exec(sprintf('curl -s --max-time 60 -b %s -X POST %s > /dev/null 2>&1 &',
    escapeshellarg($cookie), escapeshellarg($syncUrl)));

echo json_encode(['status' => 'ok', 'removed' => $devicePath]);
