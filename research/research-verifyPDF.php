<?php
/**
 * research-verifyPDF.php — Browser tool to validate PDF page numbers against the database.
 *
 * Allows selecting a book (by item_id) and optionally uploading a PDF of the book.
 * Samples random chunks from the database and shows the stored page numbers alongside
 * snippets extracted from the provided PDF so you can visually compare them.
 */

ini_set('memory_limit', '512M');

$error   = '';
$results = [];
$book    = null;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$req    = ($method === 'POST') ? $_POST : $_GET;

$itemId = (int)($req['item_id'] ?? $req['item-id'] ?? 0);
$n      = max(1, (int)($req['n'] ?? 5));
$pdf    = null;

// Allow either a file upload or a direct path
if (!empty($_FILES['pdf']['tmp_name'])) {
    $pdf = $_FILES['pdf']['tmp_name'];
} elseif (!empty($req['pdf'])) {
    $pdf = $req['pdf'];
}

if ($itemId) {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/../library.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare('SELECT id, title, author, year, COALESCE(display_offset,0) AS display_offset FROM items WHERE id=?');
        $stmt->execute([$itemId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$book) throw new Exception('No book with id=' . $itemId);

        $stmt = $db->prepare('SELECT id, page_start, page_end, text FROM chunks WHERE item_id = :id ORDER BY RANDOM() LIMIT :n');
        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $stmt->bindValue(':n',  $n,      PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) throw new Exception('No chunks for this item.');

        foreach ($rows as $r) {
            $dbStart = (int)$r['page_start'];
            $dbEnd   = (int)$r['page_end'];
            $dispStart = $dbStart + (int)$book['display_offset'];
            $dispEnd   = $dbEnd   + (int)$book['display_offset'];

            $dbSnippet = snippet($r['text']);

            $pdfSnip1 = $pdfSnip2 = null;
            if ($pdf) {
                $pdfSnip1 = extract_pdf_snippet($pdf, $dbStart);
                if ($dbEnd !== $dbStart) {
                    $pdfSnip2 = extract_pdf_snippet($pdf, $dbEnd);
                }
            }

            $results[] = [
                'chunk_id'   => $r['id'],
                'db_start'   => $dbStart,
                'db_end'     => $dbEnd,
                'disp_start' => $dispStart,
                'disp_end'   => $dispEnd,
                'db_snippet' => $dbSnippet,
                'pdf_snip1'  => $pdfSnip1,
                'pdf_snip2'  => $pdfSnip2,
            ];
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify PDF Pages</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-3">
<h1 class="mb-4">Verify PDF Page Numbers</h1>
<form method="post" enctype="multipart/form-data" class="mb-4">
  <div class="mb-3">
    <label for="item_id" class="form-label">Item ID</label>
    <input type="number" class="form-control" id="item_id" name="item_id" required value="<?= htmlspecialchars($itemId ?: '') ?>">
  </div>
  <div class="mb-3">
    <label for="n" class="form-label">Number of samples</label>
    <input type="number" class="form-control" id="n" name="n" value="<?= htmlspecialchars($n) ?>">
  </div>
  <div class="mb-3">
    <label for="pdf" class="form-label">PDF file (optional)</label>
    <input type="file" class="form-control" id="pdf" name="pdf" accept="application/pdf">
  </div>
  <button type="submit" class="btn btn-primary">Check</button>
</form>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($book && !$error): ?>
<h2><?= htmlspecialchars($book['title']) ?><?= $book['author'] ? ' (' . htmlspecialchars($book['author']) . ')' : '' ?></h2>
<p>Item ID: <?= $book['id'] ?> | Display offset: <?= $book['display_offset'] ?></p>
<?php if ($results): ?>
<div class="table-responsive">
<table class="table table-sm table-bordered align-middle">
  <thead class="table-light">
    <tr>
      <th>#</th>
      <th>Chunk ID</th>
      <th>DB Pages</th>
      <th>Printed Pages</th>
      <th>DB Snippet</th>
      <?php if ($pdf): ?><th>PDF Snippet(s)</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($results as $idx => $r): ?>
    <tr>
      <td><?= $idx+1 ?></td>
      <td><?= $r['chunk_id'] ?></td>
      <td>p.<?= $r['db_start'] ?>-<?php if ($r['db_end'] !== $r['db_start']) echo $r['db_end']; else echo $r['db_start']; ?></td>
      <td>p.<?= $r['disp_start'] ?>-<?php if ($r['disp_end'] !== $r['disp_start']) echo $r['disp_end']; else echo $r['disp_start']; ?></td>
      <td><?= htmlspecialchars($r['db_snippet']) ?></td>
      <?php if ($pdf): ?>
      <td>
        <div>p.<?= $r['db_start'] ?>: <?= htmlspecialchars($r['pdf_snip1'] ?? '[empty]') ?></div>
        <?php if ($r['pdf_snip2'] !== null): ?>
        <div>p.<?= $r['db_end'] ?>: <?= htmlspecialchars($r['pdf_snip2'] ?? '[empty]') ?></div>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
<?php
function snippet(string $s, int $len=160): string {
    $s = preg_replace('/\s+/u', ' ', trim($s));
    if ($s === '') return '[empty]';
    return mb_substr($s, 0, $len, 'UTF-8') . (mb_strlen($s, 'UTF-8') > $len ? '…' : '');
}
function extract_pdf_snippet(string $pdf, int $page, int $len=160): ?string {
    if ($page < 1) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'pg_');
    $cmd = sprintf('pdftotext -layout -enc UTF-8 -f %d -l %d %s %s',
                   $page, $page, escapeshellarg($pdf), escapeshellarg($tmp));
    exec($cmd, $_, $rc);
    $txt = @file_get_contents($tmp);
    @unlink($tmp);
    if ($txt === false) return null;
    $txt = preg_replace('/\s+/u', ' ', trim($txt));
    if ($txt === '') return '[empty page text]';
    return mb_substr($txt, 0, $len, 'UTF-8') . (mb_strlen($txt, 'UTF-8') > $len ? '…' : '');
}
?>
