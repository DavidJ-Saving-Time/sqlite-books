<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) { $name = $t; }
    }
    $name = preg_replace('/[^A-Za-z0-9 _.\'-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book_id']);
    exit;
}

$pdo = getDatabaseConnection();

$stmt = $pdo->prepare('
    SELECT b.id, b.title, b.path, b.series_index,
           (SELECT GROUP_CONCAT(a.name, "|") FROM books_authors_link ba JOIN authors a ON ba.author = a.id WHERE ba.book = b.id) AS authors,
           (SELECT s.name FROM books_series_link bsl JOIN series s ON bsl.series = s.id WHERE bsl.book = b.id LIMIT 1) AS series
    FROM books b WHERE b.id = ?
');
$stmt->execute([$bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    http_response_code(404);
    echo json_encode(['error' => 'Book not found']);
    exit;
}

$remoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
$device    = getUserPreference(currentUser(), 'DEVICE', getPreference('DEVICE', ''));

if ($remoteDir === '' || $device === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Remote device not configured.']);
    exit;
}

$bookRelPath = ltrim((string)($book['path'] ?? ''), '/');
$ebookFileRel = firstBookFile($bookRelPath) ?? '';
if ($ebookFileRel === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No book file found.']);
    exit;
}

// Resolve genre
try {
    $genreColId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    $valTable  = "custom_column_{$genreColId}";
    $linkTable = "books_custom_column_{$genreColId}_link";
    $gstmt = $pdo->prepare("SELECT gv.value FROM $linkTable l JOIN $valTable gv ON l.value = gv.id WHERE l.book = ? LIMIT 1");
    $gstmt->execute([$bookId]);
    $genre = $gstmt->fetchColumn() ?: 'Unknown';
} catch (PDOException $e) {
    $genre = 'Unknown';
}

$author = trim(explode('|', $book['authors'] ?? '')[0] ?? '');
if ($author === '') { $author = 'Unknown'; }

$genreDir  = safe_filename($genre);  if ($genreDir  === '') { $genreDir  = 'Unknown'; }
$authorDir = safe_filename($author); if ($authorDir === '') { $authorDir = 'Unknown'; }
$seriesDir = '';
$series = trim($book['series'] ?? '');
if ($series !== '') { $seriesDir = '/' . safe_filename($series); }

$remotePath = rtrim($remoteDir, '/') . '/' . $genreDir . '/' . $authorDir . $seriesDir;

$localFile      = getLibraryPath() . '/' . $ebookFileRel;
$ext            = pathinfo($ebookFileRel, PATHINFO_EXTENSION);
$remoteFileName = safe_filename($book['title']);
if ($remoteFileName === '') { $remoteFileName = 'book'; }

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

$mkdirCmd = sprintf('ssh %s -i %s %s %s 2>&1',
    $sshOpts, escapeshellarg($identity), escapeshellarg($sshTarget),
    escapeshellarg('mkdir -p ' . escapeshellarg($remotePath)));
exec($mkdirCmd, $out1, $ret1);

if ($ret1 !== 0) {
    echo json_encode(['error' => 'Failed to create directory on device.', 'detail' => implode("\n", $out1)]);
    exit;
}

$scpCmd = sprintf('scp %s -i %s %s %s:%s 2>&1',
    $sshOpts, escapeshellarg($identity), escapeshellarg($localFile),
    escapeshellarg($sshTarget), escapeshellarg($remoteFullPath));
exec($scpCmd, $out2, $ret2);

if ($ret2 === 0) {
    echo json_encode(['status' => 'ok', 'destination' => $device . ':' . $remoteFullPath, 'device_path' => $remoteFullPath]);
} else {
    echo json_encode(['error' => 'scp failed (exit ' . $ret2 . ').', 'detail' => implode("\n", $out2)]);
}
