<?php
/**
 * expand.php — Grounded expansion (outline → write sections → stitch → bibliography).
 * Works with OpenAI embeddings + OpenRouter (Claude Sonnet 4) for writing.
 *
 * Usage:
 *   php expand.php draft.txt --guided --book-id=12 --book-id=19 --target-words=5000 --max-chunks=10 --out=expanded.md --verbose
 *
 * ENV:
 *   OPENAI_API_KEY        = for embeddings
 *   OPENAI_EMBED_MODEL    = must match ingest (e.g., text-embedding-3-large OR -small)
 *   OPENROUTER_API_KEY    = for OpenRouter answering (Claude Sonnet 4)
 */

ini_set('memory_limit', '1G');

if ($argc < 2) {
  fwrite(STDERR, "Usage: php expand.php draft.txt [--guided] [--book-id=N ...] [--target-words=5000] [--max-chunks=10] [--out=expanded.md] [--verbose]\n");
  exit(1);
}

$draftPath   = $argv[1];
$bookIds     = [];
$targetWords = 5000;
$maxChunks   = 10;
$outPath     = 'expanded.md';
$guided      = false;
$verbose     = false;

for ($i=2; $i<$argc; $i++) {
  if (preg_match('/^--book-id=(\d+)/', $argv[$i], $m)) $bookIds[] = (int)$m[1];
  if (preg_match('/^--target-words=(\d+)/', $argv[$i], $m)) $targetWords = max(1000, (int)$m[1]);
  if (preg_match('/^--max-chunks=(\d+)/', $argv[$i], $m)) $maxChunks = max(6, (int)$m[1]);
  if (preg_match('/^--out=(.+)/', $argv[$i], $m)) $outPath = trim($m[1]);
  if ($argv[$i] === '--guided')  $guided = true;
  if ($argv[$i] === '--verbose') $verbose = true;
}

if (!is_file($draftPath)) die("Draft file not found: $draftPath\n");

$openaiKey  = getenv('OPENAI_API_KEY') ?: die("Set OPENAI_API_KEY\n");
$embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
$orKey      = getenv('OPENROUTER_API_KEY') ?: die("Set OPENROUTER_API_KEY\n");

// ---------- Load draft ----------
$draft = trim(file_get_contents($draftPath));
if ($draft === '') die("Draft is empty.\n");

// ---------- DB ----------
$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Pull corpus (optionally filter by books)
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
$corpus = [];
foreach ($rows as $r) {
  $v = unpack_floats($r['embedding']);
  if ($v) { $r['vec'] = $v; $corpus[] = $r; }
}
if ($verbose) fwrite(STDERR, "[DEBUG] Loaded chunks: ".count($corpus)." from ".(empty($bookIds)?"ALL books":("books: ".implode(',', $bookIds)))."\n");
unset($rows);

// ---------- Phase 1: Outline (guided or AI) ----------
if ($guided) {
  $sectionsTarget = parse_headings_outline($draft, $targetWords);
  if ($verbose) fwrite(STDERR, "[DEBUG] Guided sections: ".count($sectionsTarget)."\n");
} else {
  $sectionsTarget = plan_outline_with_claude($draft, $targetWords, $orKey, $verbose);
}
if (empty($sectionsTarget)) {
  fwrite(STDERR, "Outline failed. Writing a single expanded section instead.\n");
  $sectionsTarget = [[ 'title'=>'Expanded Discussion', 'claims'=>[], 'target'=>$targetWords ]];
}

// ---------- Phase 2: Write sections (grounded) ----------
$pieces = [];
$biblio = []; // bibliography entries de-duped by source_id or text

foreach ($sectionsTarget as $idx=>$s) {
  $brief  = $s['title'];
  $claims = $s['claims'];
  $target = max(350, (int)$s['target']);

  // Build retrieval query text from title + claims
  $queryText = $brief . "\n" . implode("\n", array_map(fn($c)=>"- ".$c, $claims));
  $qVec = embed_with_openai($queryText, $embedModel, $openaiKey);

  // Retrieve top-K
  $scored = [];
  foreach ($corpus as $row) {
    $sim = cosine($qVec, $row['vec']);
    $scored[] = ['sim'=>$sim, 'row'=>$row];
  }
  usort($scored, fn($a,$b)=> $b['sim']<=>$a['sim']);
  $top = array_slice($scored, 0, $maxChunks);

  if ($verbose) {
    $best = $top[0]['sim'] ?? 0;
    $peek = array_slice($top, 0, 3);
    $titles = array_map(fn($t)=>$t['row']['title']." p.".$t['row']['page_start']."–".$t['row']['page_end']." (sim=".round($t['sim'],3).")", $peek);
    fwrite(STDERR, "[DEBUG] Section '{$brief}' best sim: ".round($best,3)." | Top: ".implode(" | ", $titles)."\n");
  }

  // Basic guardrail (looser threshold so it doesn't bail too easily)
  if (empty($top) || $top[0]['sim'] < 0.15) {
    $pieces[] = "### {$brief}\n\n_Not in library (insufficient evidence for this section)._";
    continue;
  }

  // Build context
  $ctx = "Section brief: {$brief}\n";
  if (!empty($claims)) {
    $ctx .= "Claims to cover:\n";
    foreach ($claims as $c) $ctx .= "- {$c}\n";
  }
  $ctx .= "\nContext (book excerpts with metadata):\n";
  foreach ($top as $i=>$t) {
    $c = $t['row'];
    $meta = sprintf("%s (%s%s) p.%d–%d [source_id=%d]",
      $c['title'], $c['author'] ? $c['author'].", " : "", $c['year'] ?: "n.d.",
      (int)$c['page_start'], (int)$c['page_end'], (int)$c['item_id']
    );
    $ctx .= "\n[CTX $i] $meta\n{$c['text']}\n";
  }

  // Ask Claude to write grounded prose with Oxford footnotes + bibliography (robust JSON handling)
  $rawOrJson = write_section_with_claude_json($ctx, $target, $orKey, 0.15, 2200, $verbose);

  if ($verbose) fwrite(STDERR, "[DEBUG] Writer returned (first 300): ".substr($rawOrJson,0,300)."...\n");

  $parsed = json_decode($rawOrJson, true);
  if (!is_array($parsed) || !isset($parsed['text'])) {
    $pieces[] = "### {$brief}\n\n_Not in library (writer returned no usable text)._\n";
    continue;
  }

  // Text with footnotes
  $sectionText = "### {$brief}\n\n".$parsed['text']."\n";
  $pieces[] = $sectionText;

  // Collect bibliography
  foreach (($parsed['bibliography'] ?? []) as $b) {
    $sid  = $b['source_id'] ?? null;
    $text = trim($b['text'] ?? '');
    if ($text === '') continue;
    $key = $sid !== null ? "sid:$sid" : "txt:".md5($text);
    $biblio[$key] = $text;
  }
}

// ---------- Phase 3: Stitch + bibliography ----------
$out = [];
$out[] = "# Expanded Draft";
$out[] = "";
$out[] = $pieces ? implode("\n\n", $pieces) : "_No content produced._";
$out[] = "";
$out[] = "## Bibliography";
$bib = array_values(array_unique(array_filter(array_map('trim', array_values($biblio)))));
sort($bib, SORT_NATURAL | SORT_FLAG_CASE);
if (empty($bib)) $out[] = "(No sources referenced.)";
else foreach ($bib as $b) $out[] = "- ".$b;

file_put_contents($outPath, implode("\n", $out)."\n");
echo "Written to $outPath\n";

// ================== helpers ==================

function parse_headings_outline(string $draft, int $targetWords): array {
  // Use '# ' and '## ' as section markers; bullets as claims
  $lines = preg_split("/\R/u", $draft);
  $sections = [];
  $current = null;

  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === '') continue;
    if (preg_match('/^#{1,2}\s+(.*)$/u', $ln, $m)) {
      if ($current) $sections[] = $current;
      $title = trim($m[1]);
      $current = ['title'=>$title, 'claims'=>[], 'target'=>0];
    } elseif (preg_match('/^-\s+(.*)$/u', $ln, $m)) {
      if ($current) $current['claims'][] = trim($m[1]);
    }
  }
  if ($current) $sections[] = $current;
  if (empty($sections)) return [];

  // Distribute words across sections (min 350 each)
  $per = max(350, (int)floor($targetWords / max(1,count($sections))));
  foreach ($sections as &$s) { $s['target'] = $per; }
  return $sections;
}

function plan_outline_with_claude(string $draft, int $targetWords, string $apiKey, bool $verbose=false): array {
  $sys = "You are an academic planning assistant for a historian. "
       . "Given the user's draft, produce an outline to expand it to ~{$targetWords} words. "
       . "Return STRICT JSON with: sections: [{title, claims: [..], target}]. Do not write prose.";
  $user = "Draft:\n".$draft."\n\nOutput schema:\n{\"sections\":[{\"title\":\"Intro\",\"claims\":[\"...\"],\"target\":400}]}";

  $payload = [
    'model' => 'anthropic/claude-sonnet-4',
    'messages' => [
      ['role'=>'system','content'=>$sys],
      ['role'=>'user','content'=>$user]
    ],
    'temperature' => 0.2,
    'max_tokens'  => 1200,
    'response_format' => ['type'=>'json_object'],
    'usage' => ['include'=>true],
  ];
  $res = http_post_json("https://openrouter.ai/api/v1/chat/completions", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer ".$apiKey,
    "HTTP-Referer: https://your-site.example",
    "X-Title: Expand Planner"
  ]);

  if ($verbose && isset($res['usage'])) {
    $in  = $res['usage']['input_tokens']  ?? $res['usage']['prompt_tokens'] ?? '?';
    $out = $res['usage']['output_tokens'] ?? $res['usage']['completion_tokens'] ?? '?';
    fwrite(STDERR, "[Token usage] Outline Input: $in | Output: $out\n");
  }

  $content = $res['choices'][0]['message']['content'] ?? '{}';
  $json = json_decode($content, true);
  if (!is_array($json)) {
    // try to extract fenced json
    if (preg_match('/```json\s*(\{.*?\})\s*```/is', $content, $m)) {
      $json = json_decode($m[1], true);
    }
  }
  $out = [];
  foreach (($json['sections'] ?? []) as $s) {
    $title  = trim($s['title'] ?? '');
    $claims = array_values(array_filter(array_map('trim', $s['claims'] ?? [])));
    $target = (int)($s['target'] ?? 0);
    if ($title === '' || $target <= 0) continue;
    $out[] = ['title'=>$title, 'claims'=>$claims, 'target'=>$target];
  }
  return $out;
}

function write_section_with_claude_json(string $ctx, int $targetWords, string $apiKey, float $temp=0.1, int $maxTok=2200, bool $verbose=false): string {
  $sys = "You are a historian's assistant. Write ~{$targetWords} words ONLY using the provided context. "
       . "If evidence is missing, reply exactly: Not in library. "
       . "Use Oxford footnotes with page numbers, placing ~1 footnote every 120–180 words from varied sources when possible. "
       . "Do not invent sources or details. "
       . "Return JSON with keys: {\"text\":\"<markdown with footnotes>\", \"bibliography\":[{\"source_id\":<int|null>,\"text\":\"Oxford bibliography\"}]}.";

  // Ask for fenced JSON in case route ignores response_format
  $user = $ctx . "\n\nFormat the entire response as fenced JSON:\n```json\n{ \"text\": \"...\", \"bibliography\": [{\"source_id\":123, \"text\":\"...\"}] }\n```";

  $payload = [
    'model' => 'anthropic/claude-sonnet-4',
    'messages' => [
      ['role'=>'system','content'=>$sys],
      ['role'=>'user','content'=>$user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTok,
    'usage' => ['include'=>true],
  ];
  $res = http_post_json("https://openrouter.ai/api/v1/chat/completions", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer ".$apiKey,
    "HTTP-Referer: https://your-site.example",
    "X-Title: Expand Writer"
  ]);

  if ($verbose && isset($res['usage'])) {
    $in  = $res['usage']['input_tokens']  ?? $res['usage']['prompt_tokens'] ?? '?';
    $out = $res['usage']['output_tokens'] ?? $res['usage']['completion_tokens'] ?? '?';
    $fin = $res['choices'][0]['finish_reason'] ?? '';
    fwrite(STDERR, "[Token usage] Writer Input: $in | Output: $out | Finish: $fin\n");
  }

  $raw = $res['choices'][0]['message']['content'] ?? "";
  if ($raw === "") return "{}";

  // Try direct JSON first
  $json = json_decode($raw, true);
  if (is_array($json)) return $raw;

  // Extract fenced ```json ... ```
  if (preg_match('/```json\s*(\{.*?\})\s*```/is', $raw, $m)) {
    $try = trim($m[1]);
    $json2 = json_decode($try, true);
    if (is_array($json2)) return $try;
  }

  // Last resort: wrap plain text in JSON so caller can continue
  return json_encode(['text'=>$raw, 'bibliography'=>[]], JSON_UNESCAPED_UNICODE);
}

function embed_with_openai(string $text, string $model, string $apiKey): array {
  $payload = ['model'=>$model, 'input'=>$text];
  $res = http_post_json("https://api.openai.com/v1/embeddings", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer ".$apiKey
  ]);
  return $res['data'][0]['embedding'] ?? [];
}

function cosine(array $a, array $b): float {
  $dot=0; $na=0; $nb=0; $n=min(count($a),count($b));
  for ($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i]; $na+=$a[$i]*$a[$i]; $nb+=$b[$i]*$b[$i]; }
  return $dot / (sqrt($na)*sqrt($nb) + 1e-8);
}

function unpack_floats($bin): array {
  if ($bin === null) return [];
  return array_values(unpack('g*', $bin)); // little-endian float32
}

function http_post_json(string $url, array $payload, array $headers): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 240
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new Exception("cURL error: ".curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code: $res");
  $json = json_decode($res, true);
  return is_array($json) ? $json : [];
}

