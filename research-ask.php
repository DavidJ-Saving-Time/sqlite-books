<?php
/**
 * ask.php — Retrieval-augmented QA over your ingested books.
 *
 * Examples:
 *   php ask.php "Explain remanence vs coercivity in thin films."
 *   php ask.php "..." --book-id=5 --max-chunks=10
 *   php ask.php "..." --use=claude               # use OpenRouter (Claude Sonnet 4)
 *   php ask.php "..." --use=openai --model=gpt-4o-mini
 *
 * ENV:
 *   OPENAI_API_KEY=sk-...             (required for embeddings; also for OpenAI answering)
 *   OPENAI_EMBED_MODEL=text-embedding-3-small   (MUST match ingest model)//
 *   OPENROUTER_API_KEY=...            (required only if --use=claude)
 *
 * DB:
 *   library.sqlite with tables: items, chunks (created by your ingest script)
 */

ini_set('memory_limit', '1G');

if ($argc < 2) {
  fwrite(STDERR, "Usage: php ask.php \"your question\" [--book-id=ID] [--max-chunks=8] [--use=claude|openai] [--model=MODEL]\n");
  exit(1);
}

$question   = $argv[1];
$bookIds = [];
$maxChunks  = 8;
$useWhich   = 'claude';                 // default to Claude via OpenRouter (change if you want)
$modelName  = null;                     // optional override for answering model

for ($i=2; $i<$argc; $i++) {
//  if (preg_match('/^--book-id=(\d+)/', $argv[$i], $m)) $bookId = (int)$m[1];
  if (preg_match('/^--book-id=(\d+)/', $argv[$i], $m)) {
    $bookIds[] = (int)$m[1];
}
  if (preg_match('/^--max-chunks=(\d+)/', $argv[$i], $m)) $maxChunks = max(1,(int)$m[1]);
  if (preg_match('/^--use=(\w+)/', $argv[$i], $m)) $useWhich = strtolower($m[1]);
  if (preg_match('/^--model=(.+)/', $argv[$i], $m)) $modelName = trim($m[1]);
}

$openaiKey   = getenv('OPENAI_API_KEY');
$embedModel  = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
$orKey       = getenv('OPENROUTER_API_KEY');

if (!$openaiKey) die("Set OPENAI_API_KEY for embeddings.\n");
if ($useWhich === 'claude' && !$orKey) die("Set OPENROUTER_API_KEY when using --use=claude.\n");

// DB connect
$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1) Embed the question (OpenAI embeddings)
$qVec = embed_with_openai($question, $embedModel, $openaiKey);

// 2) Pull candidate chunks (optionally filter by book)
$sql = "SELECT c.id, c.item_id, c.section, c.page_start, c.page_end, c.text, c.embedding,
               i.title, i.author, i.year
        FROM chunks c JOIN items i ON c.item_id = i.id";
$params = [];
//if ($bookId !== null) { $sql .= " WHERE c.item_id = :bid"; $params[':bid'] = $bookId; }

if (!empty($bookIds)) {
    $in = implode(',', array_fill(0, count($bookIds), '?'));
    $sql .= " WHERE c.item_id IN ($in)";
    $params = $bookIds;
}



$stmt = $db->prepare($sql);
$stmt->execute($params);

// 3) Compute cosine similarity and rank
$top = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $vec = unpack_floats($row['embedding']);
  if (!$vec) continue;
  $sim = cosine($qVec, $vec);
  $row['sim'] = $sim;
  $top[] = $row;
}
usort($top, fn($a,$b)=> $b['sim']<=>$a['sim']);
$top = array_slice($top, 0, $maxChunks);

// Optional guardrail: if best similarity is very low, bail out
if (empty($top) || $top[0]['sim'] < 0.25) {
  echo "Q: $question\n\nNot in library (retrieval too weak).\n";
  exit(0);
}

// 4) Build grounded prompt text (user message will carry Q + context)
$sys = "You are a research assistant. Answer ONLY using the provided context. ".
       "If not answerable, reply exactly: Not in library. ".
       "Cite every factual claim like [Title, Year, p.X–Y]. ".
       "Start with 3–5 bullet points, then details.";

$ctx = "";
foreach ($top as $i=>$c) {
  $meta = sprintf("%s (%s%s) p.%d–%d",
    $c['title'],
    $c['author'] ? $c['author'].", " : "",
    $c['year'] ?: "n.d.",
    $c['page_start'] ?: 0, $c['page_end'] ?: 0
  );
  $ctx .= "\n[CTX $i] {$meta}\n{$c['text']}\n";
}
$user = "Question: ".$question."\n\nContext:\n".$ctx;

// 5) Generate answer via chosen provider
$answer = '';
$maxOut = 2000; // or make this a CLI flag: --max-out=2000

if ($useWhich === 'claude') {
  // OpenRouter (Claude Sonnet 4 by default)
  $answerModel = $modelName ?: 'anthropic/claude-sonnet-4';
  $answer = generate_with_openrouter($answerModel, $sys, $user, $orKey, 0.1, $maxOut);
} else {
  // OpenAI Responses API (fallback)
  $answerModel = $modelName ?: 'gpt-4o-mini';
  $answer = generate_with_openai($answerModel, $sys, $user, $openaiKey, 0.1, $maxOut);
}

// 6) Output neatly with sources
echo "Q: $question\n\n";
echo trim($answer)."\n\n";
echo "Sources used:\n";
foreach ($top as $c) {
  $meta = sprintf("- %s (%s%s) p.%d–%d [sim=%.3f]",
      $c['title'],
      $c['author'] ? $c['author'].", " : "",
      $c['year'] ?: "n.d.",
      $c['page_start'] ?: 0, $c['page_end'] ?: 0,
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
  $input = $system."\n\n".$user; // simple concat; Responses API also supports messages, but keep it minimal
  $payload = ['model'=>$model, 'input'=>$input, 'temperature'=>$temp, 'max_output_tokens'=>$maxTokens];
  $res = http_post_json("https://api.openai.com/v1/responses", $payload, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey"
  ]);
  if (isset($res['output'][0]['content'][0]['text'])) return $res['output'][0]['content'][0]['text'];
  if (isset($res['content'][0]['text'])) return $res['content'][0]['text'];
  // fallback parse for chat-like shapes
  if (isset($res['choices'][0]['message']['content'])) return $res['choices'][0]['message']['content'];
  return json_encode($res);
}

// OpenRouter (Chat Completions) answering — Claude Sonnet 4, etc.
function generate_with_openrouter(
    string $model,
    string $system,
    string $user,
    string $apiKey,
    float $temp = 0.1,
    int $maxTokens = 2000
): string {
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user]
        ],
        'temperature' => $temp,
        'max_tokens'  => $maxTokens,
        // ask OpenRouter to include token usage in the response
        'usage'       => ['include' => true],
    ];

    $res = http_post_json("https://openrouter.ai/api/v1/chat/completions", $payload, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey",
        // optional analytics headers:
        "HTTP-Referer: https://your-site.example",
        "X-Title: Your App Name"
    ]);

    // ---- usage + finish reason logging ----
    $usage  = $res['usage'] ?? [];
    // OpenRouter may use either set of keys; handle both
    $in  = $usage['input_tokens']      ?? $usage['prompt_tokens']     ?? '?';
    $out = $usage['output_tokens']     ?? $usage['completion_tokens'] ?? '?';
    $tot = $usage['total_tokens']      ?? (($in !== '?' && $out !== '?') ? ($in + $out) : '?');

    if ($in !== '?' || $out !== '?') {
        fwrite(STDERR, "[Token usage] Input: $in | Output: $out | Total: $tot\n");
    } else {
        fwrite(STDERR, "[Token usage] (not provided)\n");
    }

    $finish = $res['choices'][0]['finish_reason'] ?? ($res['finish_reason'] ?? null);
    if ($finish) {
        fwrite(STDERR, "[Finish reason] $finish\n");
    }
    if ($finish === 'length' || (is_numeric($out) && (int)$out === (int)$maxTokens)) {
        fwrite(STDERR, "[Warning] Output hit the max_tokens limit ($maxTokens) — may be truncated.\n");
    }

    // ---- return content ----
    if (isset($res['choices'][0]['message']['content'])) {
        return $res['choices'][0]['message']['content'];
    }
    // fallback: dump JSON if shape is unexpected
    return json_encode($res);
}
function http_post_json(string $url, array $payload, array $headers): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 120
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new Exception("cURL error: ".curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code: $res");
  $json = json_decode($res, true);
  return is_array($json) ? $json : [];
}

