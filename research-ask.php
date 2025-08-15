<?php
/**
 * ask.php — Retrieval-augmented QA with printed page numbers.
 *
 * Examples:
 *   php ask.php "What boundary conditions apply in rectangular waveguides?"
 *   php ask.php "..." --book-id=12 --book-id=19
 *   php ask.php "..." --use=claude --model=anthropic/claude-sonnet-4 --max-chunks=10 --max-out=2000 --show-pdf-pages
 *
 * ENV:
 *   OPENAI_API_KEY=...                 (required for embeddings; also for OpenAI answering if used)
 *   OPENAI_EMBED_MODEL=text-embedding-3-large   (MUST match ingest model)
 *   OPENROUTER_API_KEY=...             (required if --use=claude)
 */

ini_set('memory_limit', '1G');

if ($argc < 2) {
  fwrite(STDERR, "Usage: php ask.php \"question\" [--book-id=N ...] [--max-chunks=8] [--use=claude|openai] [--model=ID] [--max-out=1200] [--show-pdf-pages]\n");
  exit(1);
}


$verbose = false;
$question    = $argv[1];
$bookIds     = [];
$maxChunks   = 12;
$useWhich    = 'claude'; // default to OpenRouter Claude
$modelName   = 'anthropic/claude-sonnet-4';
$maxOut      = 2000;
$showPdf     = false;

$minDistinct = 3;   // default; change if you like
$perBookCap  = 3;   // default per-book cap

for ($i=2; $i<$argc; $i++) {
  if (preg_match('/^--book-id=(\d+)/', $argv[$i], $m)) $bookIds[] = (int)$m[1];
  if (preg_match('/^--max-chunks=(\d+)/', $argv[$i], $m)) $maxChunks = max(1,(int)$m[1]);
  if (preg_match('/^--use=(\w+)/', $argv[$i], $m))       $useWhich = strtolower($m[1]);
  if (preg_match('/^--model=(.+)/', $argv[$i], $m))      $modelName = trim($m[1]);
  if (preg_match('/^--max-out=(\d+)/', $argv[$i], $m))   $maxOut = max(1,(int)$m[1]);
  if (preg_match('/^--min-distinct=(\d+)/', $argv[$i], $m)) $minDistinct = max(1,(int)$m[1]);
  if (preg_match('/^--per-book-cap=(\d+)/',  $argv[$i], $m)) $perBookCap  = max(1,(int)$m[1]);
  if ($argv[$i] === '--verbose') $verbose = true;
  if ($argv[$i] === '--show-pdf-pages') $showPdf = true;

}

$openaiKey  = getenv('OPENAI_API_KEY') ?: die("Set OPENAI_API_KEY\n");
$embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small';
$orKey      = getenv('OPENROUTER_API_KEY');

if ($useWhich === 'claude' && !$orKey) die("Set OPENROUTER_API_KEY for --use=claude\n");

// --- DB connect
$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1) Embed the question with the SAME embedding model used at ingest
$qVec = embed_with_openai($question, $embedModel, $openaiKey);

// 2) Load candidate chunks (JOIN items to get display_offset)
$sql = "SELECT c.id, c.item_id, c.section, c.page_start, c.page_end, c.text, c.embedding,
               i.title, i.author, i.year, i.display_offset
        FROM chunks c
        JOIN items i ON c.item_id = i.id";
$params = [];
if (!empty($bookIds)) {
  $in = implode(',', array_fill(0, count($bookIds), '?'));
  $sql .= " WHERE c.item_id IN ($in)";
  $params = $bookIds;
}
$stmt = $db->prepare($sql);
$stmt->execute($params);

// 3) Cosine similarity & rank
$top = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $vec = unpack_floats($row['embedding']);
  if (!$vec) continue;
  $sim = cosine($qVec, $vec);
  $row['sim'] = $sim;
  $top[] = $row;
}
// Sort all candidates by similarity (desc)
usort($top, fn($a,$b)=> $b['sim'] <=> $a['sim']);

// Diversity-aware selection:
//  1) ensure at least $minDistinct different books (one chunk per unseen book)
//  2) then fill remaining slots up to $maxChunks, but no more than $perBookCap per book
$selected = [];
$seenBooks = [];
$perCount = [];

// Pass A: hit minDistinct with best-from-new-books
foreach ($top as $row) {
  if (count($selected) >= $maxChunks) break;
  $book = (int)$row['item_id'];
  if (!isset($seenBooks[$book])) {
    $selected[] = $row;
    $seenBooks[$book] = true;
    $perCount[$book] = 1;
    if (count($seenBooks) >= $minDistinct && count($selected) >= $minDistinct) break;
  }
}

// Pass B: fill up to maxChunks with best remaining, respecting per-book cap
foreach ($top as $row) {
  if (count($selected) >= $maxChunks) break;
  $book = (int)$row['item_id'];
  if (!isset($perCount[$book])) $perCount[$book] = 0;
  // skip if we already picked this exact chunk (by id)
  $already = false;
  foreach ($selected as $s) { if ((int)$s['id'] === (int)$row['id']) { $already = true; break; } }
  if ($already) continue;

  if ($perCount[$book] < $perBookCap) {
    $selected[] = $row;
    $perCount[$book]++;
  }
}

// Pass C (relax): if still short (e.g., tiny corpus), just take best remaining regardless of caps
if (count($selected) < $maxChunks) {
  foreach ($top as $row) {
    if (count($selected) >= $maxChunks) break;
    $already = false;
    foreach ($selected as $s) { if ((int)$s['id'] === (int)$row['id']) { $already = true; break; } }
    if (!$already) $selected[] = $row;
  }
}

// Use $selected as your final top list
$top = $selected;
$distinctBooks = count(array_unique(array_map(fn($r)=> (int)$r['item_id'], $top)));
if ($verbose) {
    $byBook = [];
    foreach ($top as $r) { $byBook[$r['item_id']] = ($byBook[$r['item_id']] ?? 0) + 1; }
    $pairs = [];
    foreach ($byBook as $bookId => $cnt) { $pairs[] = "$bookId:$cnt"; }
    fwrite(STDERR, "[DEBUG] Context chunks: ".count($top)." | distinct books: $distinctBooks | per-book: ".implode(", ", $pairs)."\n");
}

if ($verbose) {
    $peek = array_slice($top, 0, min(5, count($top)));
    $titles = array_map(
        fn($t)=> $t['title']." (id ".$t['item_id'].") p.".$t['page_start']."–".$t['page_end']." (sim=".round($t['sim'],3).")",
        $peek
    );
    fwrite(STDERR, "[DEBUG] Top preview: ".implode(" | ", $titles)."\n");
}

// Optional: bail if still weak
if (empty($top) || $top[0]['sim'] < 0.25) {
  echo "Q: $question\n\nNot in library (retrieval too weak).\n";
  exit(0);
}

// (Optional) Debug: show distinct book count in context
$distinctBooks = count(array_unique(array_map(fn($r)=> (int)$r['item_id'], $top)));
fwrite(STDERR, "[DEBUG] Context chunks: ".count($top)." | distinct books: $distinctBooks\n");


// Optional guardrail: if best similarity is too low, bail out
if (empty($top) || $top[0]['sim'] < 0.25) {
  echo "Q: $question\n\nNot in library (retrieval too weak).\n";
  exit(0);
}

// 4) Build grounded prompt
$sys = "You are a research assistant. 
Answer ONLY using the provided context. 
If the answer is not in the provided context, reply exactly: Not in library. 

Tone & style:
- Maintain an academic, professional, yet readable style.
- Avoid overly complex or artificial wording; aim for clarity and flow.
- Paraphrase where appropriate but remain faithful to the meaning of the source.

Grounding rules:
- All factual claims must be supported by the provided context.
- Each bullet point and each main paragraph must include at least one short direct quotation from the provided context in quotation marks.
- You may paraphrase and explain, but the quotation must clearly show the source of the fact.
- If you cannot find sufficient support in the context, you must reply: Not in library.

Output structure:
1. Start with 3–5 concise bullet points summarising the key answer.
2. Follow with detailed paragraphs expanding on the points.

Referencing rules:
- Use Oxford referencing style with footnotes.
- For every factual claim, include a superscript number and a corresponding footnote.
- Use Markdown footnote syntax for footnotes.
- Footnote format: Author, *Title* (Year), pp. X–Y.

Examples:

Correct output:
- The discipline is defined as \"the study of matter and energy\"[^1], which underpins other sciences.
- Einstein's \"theory of relativity\"[^2] changed our view of space and time.
- Experiments confirm \"the speed of light is constant\"[^3] in all frames of reference.

Physics, described as \"the study of matter and energy\"[^1], forms a foundational pillar of modern science.  
Einstein's \"theory of relativity\"[^2] provided groundbreaking insights into the nature of space and time, altering long-held assumptions.  
It is now accepted that \"the speed of light is constant\"[^3] regardless of the observer's motion, a result verified in numerous experiments.

[^1]: Smith, *History of Science* (2010), pp. 45–46.  
[^2]: Johnson, *Physics Explained* (2015), pp. 120–122.  
[^3]: Lee, *Modern Physics* (2018), pp. 300–305.

Incorrect output:
- No quotation marks in bullet points or paragraphs.
- References in brackets instead of footnotes.
- Bullet points without grounding in the provided context.
- Using more or fewer than 3–5 bullet points.
- Replying with anything other than 'Not in library' when context support is missing.";

$ctx = "";
foreach ($top as $i=>$c) {
  // Printed pages = PDF page + display_offset
  $dispStart = (int)$c['page_start'] + (int)$c['display_offset'];
  $dispEnd   = (int)$c['page_end']   + (int)$c['display_offset'];

  // clamp (fallback to pdf pages if offset sends it <= 0)
  if ($dispStart <= 0 && $c['page_start'] !== null) $dispStart = (int)$c['page_start'];
  if ($dispEnd   <= 0 && $c['page_end']   !== null) $dispEnd   = (int)$c['page_end'];

  $printed = "p.$dispStart-$dispEnd";
  $pdfTag  = $showPdf ? " [PDF p.{$c['page_start']}–{$c['page_end']}]" : "";

  $meta = sprintf("%s (%s%s) %s%s",
                  $c['title'],
                  $c['author'] ? $c['author'].", " : "",
                  $c['year'] ?: "n.d.",
                  $printed,
                  $pdfTag);
  $ctx .= "\n[CTX $i] {$meta}\n{$c['text']}\n";
}

$user = "Question: ".$question."\n\nContext:\n".$ctx;

// 5) Generate via chosen provider
$answer = '';
if ($useWhich === 'claude') {
  $answerModel = $modelName ?: 'anthropic/claude-3.7-sonnet';
  $answer = generate_with_openrouter($answerModel, $sys, $user, $orKey, 0.1, $maxOut);
} else {
  $answerModel = $modelName ?: 'gpt-4o-mini';
  $answer = generate_with_openai($answerModel, $sys, $user, $openaiKey, 0.1, $maxOut);
}

// 6) Print neatly with printed pages in Sources
echo "Q: $question\n\n";
echo trim($answer)."\n\n";
echo "Sources used:\n";
foreach ($top as $c) {
  $dispStart = (int)$c['page_start'] + (int)$c['display_offset'];
  $dispEnd   = (int)$c['page_end']   + (int)$c['display_offset'];
  if ($dispStart <= 0 && $c['page_start'] !== null) $dispStart = (int)$c['page_start'];
  if ($dispEnd   <= 0 && $c['page_end']   !== null) $dispEnd   = (int)$c['page_end'];
  $printed = "p.$dispStart-$dispEnd";
  $pdfPart = $showPdf ? " [PDF p.{$c['page_start']}–{$c['page_end']}]" : "";

  $meta = sprintf("- %s (%s%s) %s%s [sim=%.3f]",
      $c['title'],
      $c['author'] ? $c['author'].", " : "",
      $c['year'] ?: "n.d.",
      $printed,
      $pdfPart,
      $c['sim']);
  echo $meta."\n";
}

// ---------- helpers ----------

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

// OpenAI (Responses API) answering
function generate_with_openai(string $model, string $system, string $user, string $apiKey, float $temp=0.1, int $maxTokens=1200): string {
  $input = $system."\n\n".$user;
  $payload = ['model'=>$model, 'input'=>$input, 'temperature'=>$temp, 'max_output_tokens'=>$maxTokens];
  $res = http_post_json("https://api.openai.com/v1/responses", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey
  "]);
  if (isset($res['output'][0]['content'][0]['text'])) return $res['output'][0]['content'][0]['text'];
  if (isset($res['content'][0]['text'])) return $res['content'][0]['text'];
  if (isset($res['choices'][0]['message']['content'])) return $res['choices'][0]['message']['content'];
  return json_encode($res);
}

// OpenRouter (Chat Completions) answering — Claude Sonnet 4, etc. (with usage logs)
function generate_with_openrouter(string $model, string $system, string $user, string $apiKey, float $temp=0.1, int $maxTokens=1200): string {
  $payload = [
    'model' => $model,
    'messages' => [
      ['role'=>'system', 'content'=>$system],
      ['role'=>'user',   'content'=>$user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTokens,
    'usage'       => ['include' => true],
  ];
  $res = http_post_json("https://openrouter.ai/api/v1/chat/completions", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey",
    "HTTP-Referer: https://your-site.example",
    "X-Title: Your App Name"
  ]);

  if (isset($res['usage'])) {
    $in  = $res['usage']['input_tokens']  ?? $res['usage']['prompt_tokens'] ?? '?';
    $out = $res['usage']['output_tokens'] ?? $res['usage']['completion_tokens'] ?? '?';
    $fin = $res['choices'][0]['finish_reason'] ?? '';
    fwrite(STDERR, "[Token usage] Input: $in | Output: $out | Finish: $fin\n");
    if (is_numeric($out) && (int)$out === (int)$maxTokens) {
      fwrite(STDERR, "[Warning] Output hit max_tokens=$maxTokens — may be truncated.\n");
    }
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
