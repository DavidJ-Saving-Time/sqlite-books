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

// --- Schema (adds display_offset if missing) ---
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
  FOREIGN KEY(item_id) REFERENCES items(id)
);
CREATE INDEX IF NOT EXISTS idx_chunks_item ON chunks(item_id);
");

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
  usleep(200000); // throttle a bit
}

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
