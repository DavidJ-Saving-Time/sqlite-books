<?php
/**
 * ingest_book.php — Per-page PDF ingest with exact page ranges + optional printed page offset.
 *
 * CLI:
 *   php ingest_book.php /path/to/book.pdf "Book Title" "Author" 1995 --page-offset=-22
 *
 * ENV:
 *   OPENAI_API_KEY=...
 *   OPENAI_EMBED_MODEL=text-embedding-3-large   (or -small; MUST match your ask/query)
 *
 * Requires: poppler-utils (pdftotext, pdfinfo), PHP PDO SQLite
 */

ini_set('memory_limit', '1G');

if ($argc < 3) {
  fwrite(STDERR, "Usage: php ingest_book.php /path/to/book.pdf \"Book Title\" \"Author\" [Year] [--page-offset=N]\n");
  exit(1);
}

$pdfPath    = $argv[1];
$bookTitle  = $argv[2];
$bookAuthor = $argv[3] ?? "";
$bookYear   = (int)($argv[4] ?? 0);
$displayOffset = 0;

for ($i = 5; $i < $argc; $i++) {
  if (preg_match('/^--page-offset=(-?\d+)/', $argv[$i], $m)) $displayOffset = (int)$m[1];
}

if (!is_file($pdfPath)) {
  fwrite(STDERR, "ERROR: PDF not found: $pdfPath\n"); exit(1);
}

$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) { fwrite(STDERR, "ERROR: Set OPENAI_API_KEY.\n"); exit(1); }
$embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small';

$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- Base schema ---
$db->exec("
CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  author TEXT,
  year INTEGER,
  display_offset INTEGER DEFAULT 0,
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

// --- OCR hint: if needed, run ocrmypdf yourself before this script ---

// --- Exact page count via pdfinfo ---
$info = [];
exec(sprintf('pdfinfo %s 2>/dev/null', escapeshellarg($pdfPath)), $info, $rc);
$pagesCount = 0;
foreach ($info as $ln) {
  if (preg_match('/^Pages:\s+(\d+)/', $ln, $m)) { $pagesCount = (int)$m[1]; break; }
}
if ($pagesCount < 1) { fwrite(STDERR, "ERROR: Could not read page count (pdfinfo).\n"); exit(1); }

// --- Extract text per page (exact mapping: pdf_page -> text) ---
$pages = [];
for ($p = 1; $p <= $pagesCount; $p++) {
  $tmp = tempnam(sys_get_temp_dir(), 'pg_');
  $cmd = sprintf('pdftotext -layout -enc UTF-8 -f %d -l %d %s %s',
                 $p, $p, escapeshellarg($pdfPath), escapeshellarg($tmp));
  exec($cmd, $_, $rc);
  $txt = file_exists($tmp) ? file_get_contents($tmp) : '';
  @unlink($tmp);
  $pages[$p] = normalize_whitespace($txt ?? '');
}

// --- Insert item (book) row with display_offset ---
$insItem = $db->prepare("INSERT INTO items (title, author, year, display_offset) VALUES (:t,:a,:y,:o)");
$insItem->execute([':t'=>$bookTitle, ':a'=>$bookAuthor, ':y'=>$bookYear, ':o'=>$displayOffset]);
$itemId = (int)$db->lastInsertId();

// --- Populate page_map with PDF labels or detected headers/footers ---
$labels = [];
$script = __DIR__ . '/research/extract_page_labels.py';
if (is_file($script)) {
  $out = trim(shell_exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($pdfPath)));
  $labels = json_decode($out, true) ?: [];
}

$insMap = $db->prepare("INSERT INTO page_map (item_id,pdf_page,display_label,display_number,method,confidence)
                         VALUES (:i,:p,:l,:n,:m,:c)");
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

// --- Build chunks by concatenating sequential pages until ~1000 tokens ---
$targetTokens = 1000;
$chunks = build_chunks_from_pages($pages, $targetTokens);

// --- Embed chunks in batches ---
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
  usleep(200000); // throttle a bit
}

recompute_chunk_display_ranges($db, $itemId);

echo "Ingest complete.\n";
echo "Book ID: $itemId\n";
echo "Pages: $pagesCount | Chunks: ".count($chunks)."\n";

// --------------- Helpers ---------------

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
  // Optional: split any >1600 token monsters by paragraph
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

function ensure_chunk_label_cols(PDO $db): void {
  $cols = $db->query("PRAGMA table_info(chunks)")->fetchAll(PDO::FETCH_ASSOC);
  $names = array_column($cols, 'name');
  if (!in_array('display_start', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_start INTEGER");
  if (!in_array('display_end', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_end INTEGER");
  if (!in_array('display_start_label', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_start_label TEXT");
  if (!in_array('display_end_label', $names, true)) $db->exec("ALTER TABLE chunks ADD COLUMN display_end_label TEXT");
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
