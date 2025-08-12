<?php
/**
 * ingest_book.php
 *
 * CLI usage:
 *   php ingest_book.php /path/to/book.pdf "Book Title" "Author" 2020
 *
 * ENV:
 *   OPENAI_API_KEY=sk-...
 *   OPENAI_EMBED_MODEL=text-embedding-3-small (default)
 *
 * Requires:
 *   - poppler-utils (pdftotext) installed
 *   - PHP PDO SQLite extension
 */

ini_set('memory_limit', '1G');

if ($argc < 3) {
  fwrite(STDERR, "Usage: php ingest_book.php /path/to/book.pdf \"Book Title\" \"Author\" [Year]\n");
  exit(1);
}

$pdfPath   = $argv[1];
$bookTitle = $argv[2];
$bookAuthor= $argv[3] ?? "";
$bookYear  = (int)($argv[4] ?? 0);

$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
  fwrite(STDERR, "ERROR: Set OPENAI_API_KEY in your environment.\n");
  exit(1);
}
$embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small'; // see OpenAI docs

$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- Schema ---
$db->exec("
CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  author TEXT,
  year INTEGER,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS chunks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL,
  section TEXT,
  page_start INTEGER,
  page_end INTEGER,
  text TEXT NOT NULL,
  embedding BLOB,                 -- store as binary-packed floats (smaller) or JSON
  token_count INTEGER,
  FOREIGN KEY(item_id) REFERENCES items(id)
);
CREATE INDEX IF NOT EXISTS idx_chunks_item ON chunks(item_id);
");

// --- Extract text with page breaks ---
$tmpTxt = tempnam(sys_get_temp_dir(), 'pdftxt_');
$cmd = sprintf('pdftotext -layout -enc UTF-8 "%s" "%s"', addslashes($pdfPath), addslashes($tmpTxt));
exec($cmd, $o, $rc);
if ($rc !== 0 || !file_exists($tmpTxt)) {
  fwrite(STDERR, "ERROR: pdftotext failed. Is poppler-utils installed?\n");
  exit(1);
}
$full = file_get_contents($tmpTxt);
unlink($tmpTxt);

// Heuristic page splitting: pdftotext separates pages by form feed when using -layout sometimes,
// but not guaranteed. We'll fallback to a layout-based split by long runs of newlines.
$pages = preg_split("/\f|\n{3,}/u", $full);
if (count($pages) < 2) {
  // As a fallback, split every ~2000 chars to avoid one mega-page
  $pages = chunk_str_by_chars($full, 2000);
}

// Insert item (book) row
$insItem = $db->prepare("INSERT INTO items (title, author, year) VALUES (:t, :a, :y)");
$insItem->execute([':t'=>$bookTitle, ':a'=>$bookAuthor, ':y'=>$bookYear]);
$itemId = (int)$db->lastInsertId();

// Build chunks around ~1000 tokens (approx by characters, then tighten)
$targetTokens = 1000;
$chunks = build_chunks_from_pages($pages, $targetTokens);

// Embed in batches (the API lets you send multiple inputs per request)
$batchSize = 64;
for ($i = 0; $i < count($chunks); $i += $batchSize) {
  $batch = array_slice($chunks, $i, $batchSize);
  $vectors = create_embeddings_batch(array_column($batch, 'text'), $embedModel, $apiKey);
  foreach ($batch as $j => $chunk) {
    $embedding = $vectors[$j] ?? null;
    if (!$embedding) continue;
    // Pack floats into binary to save space
    $bin = pack_floats($embedding);

    $stmt = $db->prepare("
      INSERT INTO chunks (item_id, section, page_start, page_end, text, embedding, token_count)
      VALUES (:item, :section, :ps, :pe, :text, :emb, :tok)
    ");
    $stmt->bindValue(':item', $itemId, PDO::PARAM_INT);
    $stmt->bindValue(':section', $chunk['section']);
    $stmt->bindValue(':ps', $chunk['page_start'], PDO::PARAM_INT);
    $stmt->bindValue(':pe', $chunk['page_end'], PDO::PARAM_INT);
    $stmt->bindValue(':text', $chunk['text']);
    $stmt->bindValue(':emb', $bin, PDO::PARAM_LOB);
    $stmt->bindValue(':tok', $chunk['approx_tokens'], PDO::PARAM_INT);
    $stmt->execute();
  }
  // gentle pacing for rate limits
  usleep(200000); // 200ms
}

echo "Ingest complete.\n";
echo "Book ID: $itemId\n";
echo "Chunks stored: ".count($chunks)."\n";

// --------------- Helpers ---------------

function chunk_str_by_chars(string $s, int $chars): array {
  $out = [];
  $len = mb_strlen($s, 'UTF-8');
  for ($i=0; $i<$len; $i+=$chars) {
    $out[] = mb_substr($s, $i, $chars, 'UTF-8');
  }
  return $out;
}

/**
 * Build ~1000-token chunks:
 * - concatenate sequential pages until ~1000 tokens (rough approx = words)
 * - keep metadata: page_start/page_end
 */
function build_chunks_from_pages(array $pages, int $targetTokens): array {
  $chunks = [];
  $cur = "";
  $startPage = 1;
  for ($i=0; $i<count($pages); $i++) {
    $p = trim(normalize_whitespace($pages[$i]));
    if ($p === '') continue;
    $try = $cur ? ($cur."\n\n".$p) : $p;
    if (approx_token_count($try) > $targetTokens && $cur) {
      // finalize current chunk
      $chunks[] = [
        'text' => $cur,
        'page_start' => $startPage,
        'page_end' => $i,
        'approx_tokens' => approx_token_count($cur),
        'section' => infer_section_title($cur)
      ];
      $cur = $p;
      $startPage = $i+1;
    } else {
      $cur = $try;
    }
  }
  if ($cur) {
    $chunks[] = [
      'text' => $cur,
      'page_start' => $startPage,
      'page_end' => count($pages),
      'approx_tokens' => approx_token_count($cur),
      'section' => infer_section_title($cur)
    ];
  }
  // Optional: split any monster chunks > 1600 tokens
  $refined = [];
  foreach ($chunks as $c) {
    if ($c['approx_tokens'] <= 1600) { $refined[] = $c; continue; }
    // split by paragraph
    $paras = preg_split("/\n{2,}/u", $c['text']);
    $acc = "";
    foreach ($paras as $para) {
      $t = $acc ? ($acc."\n\n".$para) : $para;
      if (approx_token_count($t) > 1000 && $acc) {
        $refined[] = [
          'text'=>$acc,
          'page_start'=>$c['page_start'],
          'page_end'=>$c['page_end'],
          'approx_tokens'=>approx_token_count($acc),
          'section'=>infer_section_title($acc)
        ];
        $acc = $para;
      } else {
        $acc = $t;
      }
    }
    if ($acc) {
      $refined[] = [
        'text'=>$acc,
        'page_start'=>$c['page_start'],
        'page_end'=>$c['page_end'],
        'approx_tokens'=>approx_token_count($acc),
        'section'=>infer_section_title($acc)
      ];
    }
  }
  return $refined;
}

function normalize_whitespace(string $s): string {
  // collapse multiple spaces/indent columns; keep paragraphs
  $s = preg_replace("/[ \t]+/u", " ", $s);
  // remove trailing spaces on lines
  $s = preg_replace("/[ \t]*\n/u", "\n", $s);
  // collapse 4+ newlines to 2 (para separator)
  $s = preg_replace("/\n{4,}/u", "\n\n", $s);
  return trim($s);
}

function infer_section_title(string $chunk): string {
  // take the first non-empty line up to ~80 chars as a "section hint"
  foreach (preg_split("/\n/u", $chunk) as $line) {
    $line = trim($line);
    if ($line !== '' && mb_strlen($line,'UTF-8') > 3) {
      return mb_substr($line, 0, 80, 'UTF-8');
    }
  }
  return null;
}

function approx_token_count(string $s): int {
  // super rough: tokens ≈ words * 1.3; words ≈ whitespace splits
  $words = preg_split("/\s+/u", trim($s));
  $w = max(1, count($words));
  return (int)round($w * 1.3);
}

/**
 * Batch embeddings call. See: Embeddings guide & API reference.
 * Returns: array of vectors (float[]).
 */
function create_embeddings_batch(array $texts, string $model, string $apiKey): array {
  $payload = [
    'model' => $model,
    'input' => array_map(function($t){
      // OpenAI recommends <= ~8192 tokens per input; we trimmed earlier.
      return $t;
    }, $texts),
  ];
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
  if ($res === false) {
    throw new Exception("cURL error: ".curl_error($ch));
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($code < 200 || $code >= 300) {
    throw new Exception("Embeddings API error ($code): $res");
  }
  $json = json_decode($res, true);
  $out = [];
  foreach ($json['data'] ?? [] as $row) {
    $out[] = $row['embedding'] ?? null;
  }
  return $out;
}

function pack_floats(array $floats): string {
  // pack as little-endian floats
  $bin = '';
  foreach ($floats as $f) {
    $bin .= pack('g', (float)$f);
  }
  return $bin;
}

