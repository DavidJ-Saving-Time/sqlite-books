<?php
/**
 * research-ai.php — Web front-end for PDF ingestion.
 *
 * This script provides a simple HTML interface for uploading a PDF and
 * supplying the same options that the CLI version accepts. The ingest logic
 * remains the same: the uploaded PDF is split into chunks, embedded via the
 * OpenAI Embeddings API, and stored in library.sqlite.
 *
 * Requirements:
 *   - poppler-utils (pdftotext, pdfinfo)
 *   - PHP PDO SQLite
 *   - OPENAI_API_KEY set in environment
 *   - Optional: OPENAI_EMBED_MODEL (default text-embedding-3-small)
 *
 * Database schema is defined in library_schema.sql.
 */

ini_set('memory_limit', '1G');

function out($msg) {
    echo $msg . "\n"; @ob_flush(); @flush();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<pre>"; // easier to show streaming output

    // ---- Collect form data ----
    $bookTitle  = trim($_POST['title'] ?? '');
    $bookAuthor = trim($_POST['author'] ?? '');
    $bookYear   = (int)($_POST['year'] ?? 0);
    $displayOffset = (int)($_POST['page_offset'] ?? 0);

    if ($bookTitle === '' || !isset($_FILES['pdf'])) {
        out('Title and PDF are required.');
        exit;
    }
    if ($_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        out('Upload failed: ' . $_FILES['pdf']['error']);
        exit;
    }

    $tmpPdf = sys_get_temp_dir() . '/' . basename($_FILES['pdf']['name']);
    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $tmpPdf)) {
        out('Failed to move uploaded file.');
        exit;
    }
    out('PDF uploaded.');

    // ---- API key and model ----
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) { out('ERROR: Set OPENAI_API_KEY.'); exit; }
    $embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small';

    // ---- Open DB and ensure schema ----
    $dbPath = __DIR__ . '/../library.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents(__DIR__ . '/../library_schema.sql');
    if ($schema) $db->exec($schema);
    out('Database ready.');

    // ---- Page count via pdfinfo ----
    $info = [];
    exec(sprintf('pdfinfo %s 2>/dev/null', escapeshellarg($tmpPdf)), $info, $rc);
    $pagesCount = 0;
    foreach ($info as $ln) if (preg_match('/^Pages:\s+(\d+)/', $ln, $m)) { $pagesCount = (int)$m[1]; break; }
    if ($pagesCount < 1) { out('ERROR: Could not read page count.'); exit; }
    out("Pages: $pagesCount");

    // ---- Extract text per page ----
    $pages = [];
    for ($p = 1; $p <= $pagesCount; $p++) {
        $tmp = tempnam(sys_get_temp_dir(), 'pg_');
        $cmd = sprintf('pdftotext -layout -enc UTF-8 -f %d -l %d %s %s',
                       $p, $p, escapeshellarg($tmpPdf), escapeshellarg($tmp));
        exec($cmd, $_, $rc);
        $txt = file_exists($tmp) ? file_get_contents($tmp) : '';
        @unlink($tmp);
        $pages[$p] = normalize_whitespace($txt ?? '');
    }
    out('Text extracted.');

    // ---- Insert item ----
    $insItem = $db->prepare("INSERT INTO items (title, author, year, display_offset) VALUES (:t,:a,:y,:o)");
    $insItem->execute([':t'=>$bookTitle, ':a'=>$bookAuthor, ':y'=>$bookYear, ':o'=>$displayOffset]);
    $itemId = (int)$db->lastInsertId();

    // ---- Build chunks ----
    $targetTokens = 1000;
    $chunks = build_chunks_from_pages($pages, $targetTokens);
    out('Chunks built: ' . count($chunks));

    // ---- Embed chunks in batches ----
    $batchSize = 64;
    for ($i = 0; $i < count($chunks); $i += $batchSize) {
        $batch = array_slice($chunks, $i, $batchSize);
        $vectors = create_embeddings_batch(array_column($batch, 'text'), $embedModel, $apiKey);
        foreach ($batch as $j => $chunk) {
            $embedding = $vectors[$j] ?? null; if (!$embedding) continue;
            $bin = pack_floats($embedding);
            $stmt = $db->prepare("INSERT INTO chunks (item_id, section, page_start, page_end, text, embedding, token_count)
                                  VALUES (:item,:section,:ps,:pe,:text,:emb,:tok)");
            $stmt->bindValue(':item', $itemId, PDO::PARAM_INT);
            $stmt->bindValue(':section', $chunk['section']);
            $stmt->bindValue(':ps', $chunk['page_start'], PDO::PARAM_INT);
            $stmt->bindValue(':pe', $chunk['page_end'], PDO::PARAM_INT);
            $stmt->bindValue(':text', $chunk['text']);
            $stmt->bindValue(':emb', $bin, PDO::PARAM_LOB);
            $stmt->bindValue(':tok', $chunk['approx_tokens'], PDO::PARAM_INT);
            $stmt->execute();
        }
        out('Embedded batch ' . (($i/$batchSize)+1));
        usleep(200000); // throttle a bit
    }

    out("Ingest complete. Book ID: $itemId");
    out("Pages: $pagesCount | Chunks: " . count($chunks));
    echo "</pre>";
    @unlink($tmpPdf);
    exit;
}

$ingestedBooks = [];
$dbListPath = __DIR__ . '/../library.sqlite';
if (is_file($dbListPath)) {
    try {
        $dbList = new PDO('sqlite:' . $dbListPath);
        $dbList->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $dbList->query('SELECT id, title, author, year, created_at FROM items ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $r['pages'] = (int)$dbList->query('SELECT MAX(page_end) FROM chunks WHERE item_id = ' . $id)->fetchColumn();
            $r['chunks'] = (int)$dbList->query('SELECT COUNT(*) FROM chunks WHERE item_id = ' . $id)->fetchColumn();
            $r['endpoint'] = 'openai/v1/embeddings';
            $ingestedBooks[] = $r;
        }
    } catch (Exception $e) {
        $ingestedBooks = [];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Research AI Ingest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body>
<div class="container py-4">
<h1 class="mb-4"><i class="fa-solid fa-file-pdf me-2"></i>Research AI PDF Ingest</h1>
<form method="POST" enctype="multipart/form-data">
    <div class="mb-3">
        <label for="pdf" class="form-label">PDF File</label>
        <input class="form-control" type="file" name="pdf" id="pdf" accept="application/pdf" required>
    </div>
    <div class="mb-3">
        <label for="title" class="form-label">Title</label>
        <input class="form-control" type="text" name="title" id="title" required>
    </div>
    <div class="mb-3">
        <label for="author" class="form-label">Author</label>
        <input class="form-control" type="text" name="author" id="author">
    </div>
    <div class="mb-3">
        <label for="year" class="form-label">Year</label>
        <input class="form-control" type="number" name="year" id="year">
    </div>
    <div class="mb-3">
        <label for="page_offset" class="form-label">Page Offset</label>
        <input class="form-control" type="number" name="page_offset" id="page_offset" value="0">
    </div>
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-upload me-2"></i>Ingest</button>
</form>
<?php if ($ingestedBooks): ?>
<h2 class="mt-5">Ingested Books</h2>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Author</th>
            <th>Year</th>
            <th>Pages</th>
            <th>Chunks</th>
            <th>Endpoint</th>
            <th>Ingested</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ingestedBooks as $b): ?>
        <tr>
            <td><?= htmlspecialchars($b['id']) ?></td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td><?= htmlspecialchars($b['author']) ?></td>
            <td><?= htmlspecialchars($b['year']) ?></td>
            <td><?= htmlspecialchars($b['pages'] ?: 'n/a') ?></td>
            <td><?= htmlspecialchars($b['chunks']) ?></td>
            <td><?= htmlspecialchars($b['endpoint']) ?></td>
            <td><?= htmlspecialchars($b['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p class="mt-5">No books ingested yet.</p>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
function normalize_whitespace(string $s): string {
  if ($s === '') return '';
  $s = preg_replace("/[ \t]+/u", " ", $s);
  $s = preg_replace("/[ \t]*\n/u", "\n", $s);
  $s = preg_replace("/\n{4,}/u", "\n\n", $s);
  return trim($s);
}

function approx_token_count(string $s): int {
  $words = preg_split("/\s+/u", trim($s));
  $w = max(1, count(array_filter($words)));
  return (int)round($w * 1.3);
}

function infer_section_title(string $chunk): ?string {
  foreach (preg_split("/\n/u", $chunk) as $line) {
    $line = trim($line);
    if ($line !== '' && mb_strlen($line,'UTF-8') > 3) return mb_substr($line, 0, 80, 'UTF-8');
  }
  return null;
}

function build_chunks_from_pages(array $pagesByNum, int $targetTokens): array {
  $chunks = []; $cur = ""; $startPage = null; $lastPage = null;
  foreach ($pagesByNum as $pageNum => $pageText) {
    $p = trim($pageText);
    if ($p === '') continue;
    $try = $cur ? ($cur."\n\n".$p) : $p;
    if (approx_token_count($try) > $targetTokens && $cur) {
      $chunks[] = [
        'text' => $cur,
        'page_start' => $startPage,
        'page_end' => $lastPage,
        'approx_tokens' => approx_token_count($cur),
        'section' => infer_section_title($cur)
      ];
      $cur = $p; $startPage = $pageNum; $lastPage = $pageNum;
    } else {
      if ($cur === "") $startPage = $pageNum;
      $cur = $try; $lastPage = $pageNum;
    }
  }
  if ($cur) {
    $chunks[] = [
      'text' => $cur,
      'page_start' => $startPage,
      'page_end' => $lastPage,
      'approx_tokens' => approx_token_count($cur),
      'section' => infer_section_title($cur)
    ];
  }
  $refined = [];
  foreach ($chunks as $c) {
    if ($c['approx_tokens'] <= 1600) { $refined[] = $c; continue; }
    $paras = preg_split("/\n{2,}/u", $c['text']);
    $acc = "";
    foreach ($paras as $para) {
      $t = $acc ? ($acc."\n\n".$para) : $para;
      if (approx_token_count($t) > 1000 && $acc) {
        $refined[] = [
          'text'=>$acc, 'page_start'=>$c['page_start'], 'page_end'=>$c['page_end'],
          'approx_tokens'=>approx_token_count($acc), 'section'=>infer_section_title($acc)
        ];
        $acc = $para;
      } else {
        $acc = $t;
      }
    }
    if ($acc) {
      $refined[] = [
        'text'=>$acc, 'page_start'=>$c['page_start'], 'page_end'=>$c['page_end'],
        'approx_tokens'=>approx_token_count($acc), 'section'=>infer_section_title($acc)
      ];
    }
  }
  return $refined;
}

function create_embeddings_batch(array $texts, string $model, string $apiKey): array {
  $payload = ['model' => $model, 'input' => array_values($texts)];
  $ch = curl_init("https://api.openai.com/v1/embeddings");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Authorization: Bearer $apiKey"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 120
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new Exception("cURL error: ".curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception("Embeddings API error ($code): $res");
  $json = json_decode($res, true);
  $out = [];
  foreach ($json['data'] ?? [] as $row) $out[] = $row['embedding'] ?? null;
  return $out;
}

function pack_floats(array $floats): string {
  $bin = '';
  foreach ($floats as $f) $bin .= pack('g', (float)$f); // little-endian float32
  return $bin;
}
?>
