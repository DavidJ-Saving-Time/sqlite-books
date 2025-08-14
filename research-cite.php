<?php
/**
 * cite.php — Create Oxford footnotes + bibliography from your ingested books.
 *
 * Example:
 *   php cite.php draft.txt --book-id=2 --book-id=3 --max-chunks=8 --out=out.md
 *
 * Output:
 *   - Prints your draft with [^1], [^2] markers after each paragraph,
 *   - Lists footnotes after each paragraph block,
 *   - Prints a unique bibliography at the end.
 *
 * Requirements:
 *   - library.sqlite (tables: items, chunks) from your ingest step
 *   - OPENAI_API_KEY + OPENAI_EMBED_MODEL for embeddings
 *   - OPENROUTER_API_KEY for answering (Claude Sonnet 4)
 */

ini_set('memory_limit', '1G');

if ($argc < 2) {
  fwrite(STDERR, "Usage: php cite.php /path/to/draft.txt [--book-id=N ...] [--max-chunks=8] [--out=out.md]\n");
  exit(1);
}

$draftPath  = $argv[1];
$bookIds    = [];
$maxChunks  = 8;
$outPath    = null;

for ($i=2; $i<$argc; $i++) {
  if (preg_match('/^--book-id=(\d+)/', $argv[$i], $m)) $bookIds[] = (int)$m[1];
  if (preg_match('/^--max-chunks=(\d+)/', $argv[$i], $m)) $maxChunks = max(1,(int)$m[1]);
  if (preg_match('/^--out=(.+)/', $argv[$i], $m)) $outPath = trim($m[1]);
}

if (!is_file($draftPath)) die("Draft file not found: $draftPath\n");

$openaiKey  = getenv('OPENAI_API_KEY') ?: die("Set OPENAI_API_KEY\n");
$embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
$orKey      = getenv('OPENROUTER_API_KEY') ?: die("Set OPENROUTER_API_KEY\n");

// Load draft and split into paragraphs (blank-line separated)
$raw = file_get_contents($draftPath);
$paras = preg_split("/\R{2,}/u", trim($raw));
$paras = array_values(array_filter(array_map('trim', $paras), fn($p)=>$p!==''));

// DB connect
$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Preload chunk rows (filter by selected book IDs if provided)
$sql = "SELECT c.id, c.item_id, c.section, c.page_start, c.page_end, c.text, c.embedding,
               i.title, i.author, i.year
        FROM chunks c JOIN items i ON c.item_id = i.id";
$params = [];
if (!empty($bookIds)) {
  $in = implode(',', array_fill(0, count($bookIds), '?'));
  $sql .= " WHERE c.item_id IN ($in)";
  $params = $bookIds;
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unpack embeddings once to speed up
$corpus = [];
foreach ($rows as $r) {
  $r['vec'] = unpack_floats($r['embedding']);
  if (!empty($r['vec'])) $corpus[] = $r;
}
unset($rows);

// For collecting a global bibliography (unique by item_id)
$biblioByItem = [];
$footnoteCounter = 1;

// Build output
$out = [];
foreach ($paras as $idx=>$para) {
  // 1) Embed paragraph
  $qVec = embed_with_openai($para, $embedModel, $openaiKey);

  // 2) Retrieve top-K chunks
  $scored = [];
  foreach ($corpus as $r) {
    $sim = cosine($qVec, $r['vec']);
    $scored[] = ['sim'=>$sim, 'row'=>$r];
  }
  usort($scored, fn($a,$b)=> $b['sim']<=>$a['sim']);
  $top = array_slice($scored, 0, $maxChunks);

  // If retrieval is weak, skip citing for this paragraph
  if (empty($top) || $top[0]['sim'] < 0.20) {
    $out[] = $para . " [^" . $footnoteCounter . "]";
    $out[] = "\n[^{$footnoteCounter}]: No suitable source found in selected books.";
    $footnoteCounter++;
    continue;
  }

  // 3) Build system + user messages; ask for strict JSON
$system =
"You are an academic assistant for a historian. " .
"Read the provided draft text and match ideas or facts with supporting sources ONLY from the provided context. " .
"Use Oxford-style footnotes (superscript numbers) within the draft text, placed immediately after the relevant sentence or clause. " .
"After the annotated text, produce a bibliography titled 'References' in Oxford format, listing only sources actually cited in the text. " .
"Use exact page ranges from the context metadata (p.xx–yy). " .
"Do NOT invent sources, authors, years, publishers, or page numbers. " .
"If publisher/place is unknown, omit it rather than guessing. " .
"Return the result as plain readable text — NOT JSON — with the structure:\n" .
"---\n" .
"[Annotated Draft Text]\n" .
"\n" .
"References\n" .
"[Oxford-formatted bibliography]\n" .
"---";

  // Build context blocks
  $ctx = "Paragraph:\n".$para."\n\nContext:\n";
  foreach ($top as $i=>$t) {
    $c = $t['row'];
    $meta = sprintf("%s (%s%s) p.%d–%d [source_id=%d]",
      $c['title'],
      $c['author'] ? $c['author'].", " : "",
      $c['year'] ?: "n.d.",
      (int)$c['page_start'], (int)$c['page_end'],
      (int)$c['item_id']
    );
    $ctx .= "\n[CTX $i] $meta\n{$c['text']}\n";
  }

  // 4) Ask Claude (OpenRouter) for JSON
  $jsonStr = generate_with_openrouter_json('anthropic/claude-sonnet-4', $system, $ctx, $orKey, 0.1, 1000);

  $fnThisPara = [];
  $bibThisPara = [];

  $parsed = json_decode($jsonStr, true);
  if (is_array($parsed) && isset($parsed['footnotes'])) {
    foreach ($parsed['footnotes'] as $fn) {
      if (!isset($fn['text'])) continue;
      $sid = $fn['source_id'] ?? null;
      $text = trim($fn['text']);
      if ($text !== '') $fnThisPara[] = ['sid'=>$sid, 'text'=>$text];
    }
    foreach (($parsed['bibliography'] ?? []) as $b) {
      if (!isset($b['text'])) continue;
      $sid = $b['source_id'] ?? null;
      $text = trim($b['text']);
      if ($text !== '') {
        // keep globally unique by source_id if present; else by text
        $key = $sid !== null ? "sid:$sid" : "txt:".md5($text);
        $biblioByItem[$key] = $text;
        $bibThisPara[] = ['sid'=>$sid, 'text'=>$text];
      }
    }
  } else {
    // Fallback: if model didn’t return JSON, add a diagnostic footnote
    $fnThisPara[] = ['sid'=>null, 'text'=>"Could not parse citation JSON. Raw: ".substr($jsonStr,0,200)."…"];
  }

  // 5) Attach footnote markers and the notes
  if (empty($fnThisPara)) {
    $out[] = $para . " [^" . $footnoteCounter . "]";
    $out[] = "\n[^{$footnoteCounter}]: No matching source returned.";
    $footnoteCounter++;
  } else {
    // If multiple footnotes returned, number them consecutively
    $markers = [];
    foreach ($fnThisPara as $k=>$fn) {
      $num = $footnoteCounter++;
      $markers[] = "[^$num]";
      $out[] = "\n[^{$num}]: ".$fn['text'];
    }
    // Append markers to paragraph line
    $outPara = $para.' '.implode('', $markers);
    array_splice($out, count($out), 0, $outPara); // ensure paragraph precedes notes
  }
  // Blank line between paragraph blocks
  $out[] = "";
}

// 6) Append Bibliography
$out[] = "## Bibliography";
$bib = array_values(array_unique(array_filter(array_map('trim', array_values($biblioByItem)))));
sort($bib, SORT_NATURAL | SORT_FLAG_CASE);
if (empty($bib)) {
  $out[] = "(No sources referenced.)";
} else {
  foreach ($bib as $b) $out[] = "- ".$b;
}

// Save or print
$result = implode("\n", $out)."\n";
if ($outPath) {
  file_put_contents($outPath, $result);
  echo "Written to $outPath\n";
} else {
  echo $result;
}


// ----------------- helpers -----------------

function cosine(array $a, array $b): float {
  $dot=0; $na=0; $nb=0; $n=min(count($a),count($b));
  for ($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i]; $na+=$a[$i]*$a[$i]; $nb+=$b[$i]*$b[$i]; }
  return $dot / (sqrt($na)*sqrt($nb) + 1e-8);
}

function unpack_floats($bin): array {
  if ($bin === null) return [];
  return array_values(unpack('g*', $bin)); // little-endian float32
}

function embed_with_openai(string $text, string $model, string $apiKey): array {
  $payload = ['model'=>$model, 'input'=>$text];
  $res = http_post_json("https://api.openai.com/v1/embeddings", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey"
  ]);
  return $res['data'][0]['embedding'] ?? [];
}

function generate_with_openrouter_json(string $model, string $system, string $user, string $apiKey, float $temp=0.1, int $maxTokens=1000): string {
  $payload = [
    'model' => $model,
    'messages' => [
      ['role'=>'system','content'=>$system],
      ['role'=>'user','content'=>$user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTokens,
    'response_format' => ['type'=>'json_object'], // ask for strict JSON if supported
    'usage' => ['include' => true],
  ];
  $res = http_post_json("https://openrouter.ai/api/v1/chat/completions", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey",
    "HTTP-Referer: https://your-site.example",
    "X-Title: Historian Cite Assistant"
  ]);

  // Optional debug
  if (isset($res['usage'])) {
    $in  = $res['usage']['input_tokens']  ?? $res['usage']['prompt_tokens'] ?? '?';
    $out = $res['usage']['output_tokens'] ?? $res['usage']['completion_tokens'] ?? '?';
    fwrite(STDERR, "[Token usage] Input: $in | Output: $out\n");
  }
  if (isset($res['choices'][0]['message']['content'])) return $res['choices'][0]['message']['content'];
  return json_encode($res);
}

function http_post_json(string $url, array $payload, array $headers): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 180
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new Exception("cURL error: ".curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code: $res");
  $json = json_decode($res, true);
  return is_array($json) ? $json : [];
}

