<?php
/**
 * Handles the send_to_device POST action for book.php.
 *
 * Expects in scope: $pdo, $id, $book, $ebookFileRel
 * Sets in scope:    $sendMessage
 */

$remoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
$device    = getUserPreference(currentUser(), 'DEVICE', getPreference('DEVICE', ''));

if ($remoteDir === '' || $device === '') {
    $sendMessage = ['type' => 'danger', 'text' => 'Remote device not configured.'];
} elseif ($ebookFileRel === '') {
    $sendMessage = ['type' => 'danger', 'text' => 'No book file to send.'];
} else {
    try {
        $genreId   = ensureMultiValueColumn($pdo, '#genre', 'Genre');
        $valTable  = "custom_column_{$genreId}";
        $linkTable = "books_custom_column_{$genreId}_link";
        $gstmt     = $pdo->prepare("SELECT gv.value FROM $linkTable l JOIN $valTable gv ON l.value = gv.id WHERE l.book = ? LIMIT 1");
        $gstmt->execute([$id]);
        $genre = $gstmt->fetchColumn() ?: 'Unknown';
    } catch (PDOException $e) {
        $genre = 'Unknown';
    }

    $author = trim(explode(',', $book['authors'])[0] ?? '');
    if ($author === '') { $author = 'Unknown'; }

    $genreDir  = safe_filename($genre)  ?: 'Unknown';
    $authorDir = safe_filename($author) ?: 'Unknown';

    $series    = trim($book['series'] ?? '');
    $seriesDir = $series !== '' ? '/' . safe_filename($series) : '';
    $remotePath = rtrim($remoteDir, '/') . '/' . $genreDir . '/' . $authorDir . $seriesDir;

    $localFile      = getLibraryPath() . '/' . $ebookFileRel;
    $ext            = pathinfo($ebookFileRel, PATHINFO_EXTENSION);
    $remoteFileName = safe_filename($book['title']) ?: 'book';

    if ($series !== '' && $book['series_index'] !== null && $book['series_index'] !== '') {
        $seriesIdxStr = (string)$book['series_index'];
        if (strpos($seriesIdxStr, '.') !== false) {
            [$whole, $decimal] = explode('.', $seriesIdxStr, 2);
            $seriesIdxStr = str_pad($whole, 2, '0', STR_PAD_LEFT);
            $decimal = rtrim($decimal, '0');
            if ($decimal !== '') { $seriesIdxStr .= '.' . $decimal; }
        } else {
            $seriesIdxStr = str_pad($seriesIdxStr, 2, '0', STR_PAD_LEFT);
        }
        $remoteFileName = $seriesIdxStr . ' - ' . $remoteFileName;
    }
    if ($ext !== '') { $remoteFileName .= '.' . $ext; }

    $identity       = '/home/david/.ssh/id_rsa';
    $sshTarget      = 'root@' . $device;
    $sshOpts        = '-o ConnectTimeout=10 -o BatchMode=yes -o StrictHostKeyChecking=accept-new';
    $remoteFullPath = $remotePath . '/' . $remoteFileName;

    $mkdirCmd = sprintf(
        'ssh %s -i %s %s %s 2>&1',
        $sshOpts,
        escapeshellarg($identity),
        escapeshellarg($sshTarget),
        escapeshellarg('mkdir -p ' . escapeshellarg($remotePath))
    );
    exec($mkdirCmd, $out1, $ret1);

    if ($ret1 !== 0) {
        $sendMessage = [
            'type'   => 'danger',
            'text'   => 'Failed to create directory on device.',
            'detail' => implode("\n", $out1),
        ];
    } else {
        $scpCmd = sprintf(
            'scp %s -i %s %s %s:%s 2>&1',
            $sshOpts,
            escapeshellarg($identity),
            escapeshellarg($localFile),
            escapeshellarg($sshTarget),
            escapeshellarg($remoteFullPath)
        );
        exec($scpCmd, $out2, $ret2);

        if ($ret2 === 0) {
            $sendMessage = [
                'type' => 'success',
                'text' => 'Sent to ' . $device . ':' . $remoteFullPath,
            ];
            $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $syncUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . '/json_endpoints/sync_device.php';
            $cookie  = 'user=' . rawurlencode(currentUser());
            exec(sprintf('curl -s --max-time 60 -b %s -X POST %s > /dev/null 2>&1 &',
                escapeshellarg($cookie), escapeshellarg($syncUrl)));
        } else {
            $sendMessage = [
                'type'   => 'danger',
                'text'   => 'scp failed (exit ' . $ret2 . ').',
                'detail' => implode("\n", $out2),
            ];
        }
    }
}
