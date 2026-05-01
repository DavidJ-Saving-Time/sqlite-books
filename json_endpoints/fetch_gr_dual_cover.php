<?php
/**
 * Fetch both GR cover sources for a book:
 *   local  — gr_covers/{gr_id}.jpg (already on disk)
 *   cdn    — download gr_image_url identifier to cache
 *
 * POST book_id
 *
 * Returns:
 *   { local: {url, width, height, size_kb} | null,
 *     cdn:   {url, width, height, size_kb} | null,
 *     cdn_error: "..." (if CDN download failed) }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    echo json_encode(['error' => 'Invalid book_id']);
    exit;
}

$pdo = getDatabaseConnection();

$rows = $pdo->prepare(
    "SELECT type, val FROM identifiers WHERE book = ? AND type IN ('goodreads', 'gr_image_url')"
);
$rows->execute([$bookId]);
$ids = [];
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ids[$r['type']] = $r['val'];
}

$grId       = $ids['goodreads']    ?? '';
$grImageUrl = $ids['gr_image_url'] ?? '';

if ($grId === '' && $grImageUrl === '') {
    echo json_encode(['error' => 'No Goodreads ID or GR image URL for this book']);
    exit;
}

$user     = currentUser();
$appRoot  = __DIR__ . '/..';
$cacheDir = $appRoot . '/cache/' . $user;
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? $appRoot, '/');

function gr_image_dimensions(string $path): array
{
    $data = @file_get_contents($path, false, null, 0, 65536);
    if ($data === false) return [0, 0];
    if (substr($data, 0, 4) === "\x89PNG" && strlen($data) >= 24) {
        $w = unpack('N', substr($data, 16, 4))[1];
        $h = unpack('N', substr($data, 20, 4))[1];
        return [(int)$w, (int)$h];
    }
    if (substr($data, 0, 2) === "\xff\xd8") {
        $i   = 2;
        $len = strlen($data);
        while ($i + 4 < $len) {
            if (ord($data[$i]) !== 0xFF) break;
            $marker = ord($data[$i + 1]);
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                if ($i + 9 <= $len) {
                    $h = unpack('n', substr($data, $i + 5, 2))[1];
                    $w = unpack('n', substr($data, $i + 7, 2))[1];
                    return [(int)$w, (int)$h];
                }
                break;
            }
            if ($i + 4 > $len) break;
            $segLen = unpack('n', substr($data, $i + 2, 2))[1];
            $i += 2 + $segLen;
        }
    }
    return [0, 0];
}

function path_to_web(string $path, string $docRoot): string
{
    return '/' . ltrim(str_replace($docRoot, '', $path), '/');
}

$result = ['local' => null, 'cdn' => null];

// ── Local cover from gr_covers/ ───────────────────────────────────────────────
if ($grId !== '') {
    $localPath = $appRoot . '/gr_covers/' . (int)$grId . '.jpg';
    if (file_exists($localPath)) {
        [$w, $h] = gr_image_dimensions($localPath);
        $result['local'] = [
            'url'     => path_to_web($localPath, $docRoot),
            'width'   => $w,
            'height'  => $h,
            'size_kb' => (int)(filesize($localPath) / 1024),
        ];
    }
}

// ── CDN cover from gr_image_url ───────────────────────────────────────────────
if ($grImageUrl !== '') {
    $cdnFile = $cacheDir . '/cover_dl_gr_cdn_' . $bookId . '.jpg';
    $ctx     = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0\r\n",
    ]]);
    $imgData = @file_get_contents($grImageUrl, false, $ctx);
    if ($imgData !== false && strlen($imgData) > 1000) {
        file_put_contents($cdnFile, $imgData);
        [$w, $h] = gr_image_dimensions($cdnFile);
        $result['cdn'] = [
            'url'     => path_to_web($cdnFile, $docRoot),
            'width'   => $w,
            'height'  => $h,
            'size_kb' => (int)(strlen($imgData) / 1024),
        ];
    } else {
        $result['cdn_error'] = 'Failed to download CDN image';
    }
}

echo json_encode($result);
