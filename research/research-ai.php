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
 * The SQLite schema is created automatically.
 */

ini_set('memory_limit', '1G');

function out($msg) {
    echo $msg . "\n"; @ob_flush(); @flush();
}

$statusMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && $_POST['delete_id'] !== '') {
    $deleteId = (int)$_POST['delete_id'];
    $dbPath = __DIR__ . '/../library.sqlite';
    if (!is_file($dbPath)) {
        $errorMessage = 'Database not found. Cannot delete book.';
    } else {
        try {
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->beginTransaction();

            if (!table_exists($db, 'items')) {
                throw new RuntimeException('Required table "items" is missing.');
            }

            $sel = $db->prepare('SELECT title FROM items WHERE id = :id');
            $sel->execute([':id' => $deleteId]);
            $book = $sel->fetch(PDO::FETCH_ASSOC);

            if (!$book) {
                $db->rollBack();
                $errorMessage = 'Book not found; nothing deleted.';
            } else {
                $debugDeletes = [];
                $params = [':id' => $deleteId];

                if (table_exists($db, 'chunks')) {
                    $db->prepare('DELETE FROM chunks WHERE item_id = :id')->execute($params);
                } else {
                    $debugDeletes[] = 'Skipped deleting from missing table "chunks".';
                }

                if (table_exists($db, 'page_map')) {
                    $db->prepare('DELETE FROM page_map WHERE item_id = :id')->execute($params);
                } else {
                    $debugDeletes[] = 'Skipped deleting from missing table "page_map".';
                }

                $db->prepare('DELETE FROM items WHERE id = :id')->execute($params);
                $db->commit();

                $statusMessage = sprintf('Deleted "%s" (ID %d) and related embeddings.', $book['title'], $deleteId);
                if ($debugDeletes) {
                    $statusMessage .= ' ' . implode(' ', $debugDeletes);
                }
            }
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $errorMessage = 'Failed to delete book: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !(isset($_POST['delete_id']) && $_POST['delete_id'] !== '')) {
    echo "<pre>"; // easier to show streaming output

    // ---- Collect form data ----
    $bookTitle  = trim($_POST['title'] ?? '');
    $bookAuthor = trim($_POST['author'] ?? '');
    $bookYear   = (int)($_POST['year'] ?? 0);
    $displayOffset = (int)($_POST['page_offset'] ?? 0);
    $libraryBookId = isset($_POST['library_book_id']) && $_POST['library_book_id'] !== ''
        ? (int)$_POST['library_book_id'] : null;

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
    $db->exec("
CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  author TEXT,
  year INTEGER,
  display_offset INTEGER DEFAULT 0,
  library_book_id INTEGER,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS chunks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL,
  section TEXT,
  page_start INTEGER,
  page_end INTEGER,
  text TEXT NOT NULL,
  embedding BLOB,
  token_count INTEGER,
  display_start INTEGER,
  display_end INTEGER,
  display_start_label TEXT,
  display_end_label TEXT,
  FOREIGN KEY(item_id) REFERENCES items(id)
);
CREATE INDEX IF NOT EXISTS idx_chunks_item ON chunks(item_id);
CREATE TABLE IF NOT EXISTS page_map (
  item_id INTEGER NOT NULL,
  pdf_page INTEGER NOT NULL,
  display_label TEXT,
  display_number INTEGER,
  method TEXT,
  confidence REAL,
  PRIMARY KEY (item_id, pdf_page)
);
");
    ensure_chunk_label_cols($db);
    ensure_library_book_id_col($db);
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
    $insItem = $db->prepare("INSERT INTO items (title, author, year, display_offset, library_book_id) VALUES (:t,:a,:y,:o,:l)");
    $insItem->execute([':t'=>$bookTitle, ':a'=>$bookAuthor, ':y'=>$bookYear, ':o'=>$displayOffset, ':l'=>$libraryBookId]);
    $itemId = (int)$db->lastInsertId();

    // ---- Populate page_map with PDF labels or detected headers/footers ----
    $labels = [];
    $script = __DIR__ . '/extract_page_labels.py';
    if (is_file($script)) {
        $outLabels = trim(shell_exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($tmpPdf)));
        $labels = json_decode($outLabels, true) ?: [];
    }
    $insMap = $db->prepare("INSERT INTO page_map (item_id,pdf_page,display_label,display_number,method,confidence) VALUES (:i,:p,:l,:n,:m,:c)");
    for ($p=1; $p <= $pagesCount; $p++) {
        $label = $labels[$p] ?? detect_header_footer_label($pages[$p]);
        $method = isset($labels[$p]) ? 'pdf_label' : ($label ? 'header' : 'offset');
        $conf = isset($labels[$p]) ? 1.0 : ($label ? 0.6 : 0.4);
        $num = null;
        if ($label !== null) {
            if (preg_match('/^\d+$/', $label)) {
                $num = (int)$label;
            } elseif (preg_match('/^[ivxlcdm]+$/i', $label)) {
                $num = roman_to_int($label);
            } else {
                $label = null;
            }
        }
        if ($label === null) {
            $num = $p + $displayOffset;
            $label = (string)$num;
            $method = 'offset';
            $conf = 0.4;
        }
        $insMap->execute([':i'=>$itemId, ':p'=>$p, ':l'=>$label, ':n'=>$num, ':m'=>$method, ':c'=>$conf]);
    }

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
            $stmt = $db->prepare("INSERT INTO chunks (item_id, section, page_start, page_end, text, embedding, token_count, display_start, display_end, display_start_label, display_end_label)
                                  VALUES (:item,:section,:ps,:pe,:text,:emb,:tok,NULL,NULL,NULL,NULL)");
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

    recompute_chunk_display_ranges($db, $itemId);
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
        $rows = $dbList->query('SELECT id, title, author, year, library_book_id, created_at FROM items ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
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
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container py-4">
<?php if ($statusMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($statusMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
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
        <label for="library_book_id" class="form-label">Library Book ID</label>
        <input class="form-control" type="number" name="library_book_id" id="library_book_id">
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
            <th>Library ID</th>
            <th>Pages</th>
            <th>Chunks</th>
            <th>Endpoint</th>
            <th>Ingested</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ingestedBooks as $b): ?>
        <tr>
            <td><?= htmlspecialchars($b['id']) ?></td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td><?= htmlspecialchars($b['author']) ?></td>
            <td><?= htmlspecialchars($b['year']) ?></td>
            <td><?= htmlspecialchars($b['library_book_id'] ?? '') ?></td>
            <td><?= htmlspecialchars($b['pages'] ?: 'n/a') ?></td>
            <td><?= htmlspecialchars($b['chunks']) ?></td>
            <td><?= htmlspecialchars($b['endpoint']) ?></td>
            <td><?= htmlspecialchars($b['created_at']) ?></td>
            <td>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this book and all embeddings?');">
                    <input type="hidden" name="delete_id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="fa-solid fa-trash-can me-1"></i>Delete
                    </button>
                </form>
            </td>
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

function table_exists(PDO $db, string $table): bool {
  $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
  $stmt->execute([':name' => $table]);
  return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensure_chunk_label_cols(PDO $db): void {
  $cols = $db->query("PRAGMA table_info(chunks)")->fetchAll(PDO::FETCH_ASSOC);
  $names = array_column($cols, 'name');
  if (!in_array('display_start', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_start INTEGER");
  if (!in_array('display_end', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_end INTEGER");
  if (!in_array('display_start_label', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_start_label TEXT");
  if (!in_array('display_end_label', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_end_label TEXT");
}

function ensure_library_book_id_col(PDO $db): void {
  $cols = $db->query("PRAGMA table_info(items)")->fetchAll(PDO::FETCH_ASSOC);
  $names = array_column($cols, 'name');
  if (!in_array('library_book_id', $names, true)) {
    $db->exec("ALTER TABLE items ADD COLUMN library_book_id INTEGER");
  }
}

function roman_to_int(string $roman): int {
  $map = ['I'=>1,'V'=>5,'X'=>10,'L'=>50,'C'=>100,'D'=>500,'M'=>1000];
  $roman = strtoupper($roman);
  $total = 0; $prev = 0;
  for ($i = strlen($roman)-1; $i >= 0; $i--) {
    $curr = $map[$roman[$i]] ?? 0;
    if ($curr < $prev) $total -= $curr; else $total += $curr;
    $prev = $curr;
  }
  return $total;
}

function detect_header_footer_label(string $txt): ?string {
  $lines = preg_split('/\n/u', trim($txt));
  if (!$lines) return null;
  $candidates = array_merge(array_slice($lines, 0, 2), array_slice($lines, -2));
  foreach ($candidates as $l) {
    $l = trim($l);
    if ($l === '') continue;
    if (preg_match('/^\d{1,4}$/', $l)) return $l;
    if (preg_match('/^[ivxlcdm]+$/i', $l)) return $l;
  }
  return null;
}

function recompute_chunk_display_ranges(PDO $db, int $itemId): void {
  $dispOffset = (int)$db->query("SELECT display_offset FROM items WHERE id=".$itemId)->fetchColumn();
  $q = $db->prepare("SELECT id, page_start, page_end FROM chunks WHERE item_id=:i");
  $q->execute([':i'=>$itemId]);
  $sel = $db->prepare("SELECT display_label, display_number FROM page_map WHERE item_id=:i AND pdf_page=:p");
  $upd = $db->prepare("UPDATE chunks SET display_start=:ds, display_end=:de, display_start_label=:dsl, display_end_label=:del WHERE id=:cid");
  while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $s = (int)$row['page_start']; $e = (int)$row['page_end'];
    $sel->execute([':i'=>$itemId, ':p'=>$s]); $start = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
    $sel->execute([':i'=>$itemId, ':p'=>$e]); $end = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
    $startLabel = $start['display_label'] ?? null;
    $startNum = $start['display_number'] ?? null;
    if ($startLabel === null) { $startNum = $s + $dispOffset; $startLabel = (string)$startNum; }
    if ($startNum === null && preg_match('/^[ivxlcdm]+$/i', $startLabel)) $startNum = roman_to_int($startLabel);
    $endLabel = $end['display_label'] ?? null;
    $endNum = $end['display_number'] ?? null;
    if ($endLabel === null) { $endNum = $e + $dispOffset; $endLabel = (string)$endNum; }
    if ($endNum === null && preg_match('/^[ivxlcdm]+$/i', $endLabel)) $endNum = roman_to_int($endLabel);
    $upd->execute([':ds'=>$startNum, ':de'=>$endNum, ':dsl'=>$startLabel, ':del'=>$endLabel, ':cid'=>$row['id']]);
  }
}
?>
