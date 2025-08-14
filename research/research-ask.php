<?php
/**
 * research-ask.php — Web interface for retrieval-augmented QA over the ingested books.
 *
 * This is a browser-friendly version of the CLI script located at the project root.
 * It embeds the user's question, finds relevant chunks from library.sqlite, and
 * generates an answer using either OpenRouter (Claude) or OpenAI.
 *
 * Requirements:
 *   - PHP PDO SQLite
 *   - OPENAI_API_KEY (for embeddings, and OpenAI answering)
 *   - Optional: OPENROUTER_API_KEY when using Claude via OpenRouter
 *   - Optional: OPENAI_EMBED_MODEL (defaults to text-embedding-3-large)
 */

ini_set('memory_limit', '1G');

$answer  = '';
$sources = [];
$error   = '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$req    = ($method === 'POST') ? $_POST : $_GET;

$question  = trim($req['question'] ?? '');
$bookIds   = [];
if (isset($req['book_id']) || isset($req['book-id'])) {
    $raw = $req['book_id'] ?? $req['book-id'];
    if (is_array($raw)) {
        $bookIds = array_map('intval', $raw);
    } else {
        $bookIds = array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
    }
} elseif (isset($req['book_ids'])) { // backward compatibility
    $bookIds = array_filter(array_map('intval', preg_split('/\s*,\s*/', $req['book_ids'], -1, PREG_SPLIT_NO_EMPTY)));
}
$maxChunks  = max(1, (int)($req['max_chunks'] ?? $req['max-chunks'] ?? 8));
$maxTokens  = max(1, (int)($req['max_tokens'] ?? $req['max-tokens'] ?? 2000));
$useWhich   = strtolower(trim($req['use'] ?? 'claude'));
$modelName  = trim($req['model'] ?? '');

if ($question !== '') {
    try {
        if ($question === '') {
            throw new Exception('Question is required.');
        }

        $openaiKey  = getenv('OPENAI_API_KEY');
        $embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
        $orKey      = getenv('OPENROUTER_API_KEY');

        if (!$openaiKey) {
            throw new Exception('Set OPENAI_API_KEY for embeddings.');
        }
        if ($useWhich === 'claude' && !$orKey) {
            throw new Exception('Set OPENROUTER_API_KEY when using Claude.');
        }

        $db = new PDO('sqlite:' . __DIR__ . '/../library.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 1) Embed the question
        $qVec = embed_with_openai($question, $embedModel, $openaiKey);

        // 2) Fetch candidate chunks
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

        // 3) Compute cosine similarity and rank
        $top = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vec = unpack_floats($row['embedding']);
            if (!$vec) continue;
            $row['sim'] = cosine($qVec, $vec);
            $top[] = $row;
        }
        usort($top, fn($a,$b)=> $b['sim']<=>$a['sim']);
        $top = array_slice($top, 0, $maxChunks);

        if (empty($top) || $top[0]['sim'] < 0.25) {
            $answer = 'Not in library (retrieval too weak).';
        } else {
            // 4) Build grounded prompt
            $sys = "You are a research assistant. Answer ONLY using the provided context. " .
                   "If not answerable, reply exactly: Not in library. " .
                   "Cite every factual claim like [Title, Year, p.X–Y]. " .
                   "Start with 3–5 bullet points, then details.";

            $ctx = '';
            foreach ($top as $i=>$c) {
                $meta = sprintf("%s (%s%s) p.%d–%d",
                    $c['title'],
                    $c['author'] ? $c['author'] . ', ' : '',
                    $c['year'] ?: 'n.d.',
                    $c['page_start'] ?: 0, $c['page_end'] ?: 0
                );
                $ctx .= "\n[CTX $i] {$meta}\n{$c['text']}\n";
                $sources[] = sprintf('%s (%s%s) p.%d–%d [sim=%.3f]',
                    $c['title'],
                    $c['author'] ? $c['author'] . ', ' : '',
                    $c['year'] ?: 'n.d.',
                    $c['page_start'] ?: 0, $c['page_end'] ?: 0,
                    $c['sim']);
            }
            $user = "Question: " . $question . "\n\nContext:\n" . $ctx;

            // 5) Generate answer
            $maxOut = $maxTokens;
            if ($useWhich === 'claude') {
                $answerModel = $modelName ?: 'anthropic/claude-sonnet-4';
                $answer = generate_with_openrouter($answerModel, $sys, $user, $orKey, 0.1, $maxOut);
            } else {
                $answerModel = $modelName ?: 'gpt-4o-mini';
                $answer = generate_with_openai($answerModel, $sys, $user, $openaiKey, 0.1, $maxOut);
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    if ($method === 'GET') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($error) {
            echo "Error: $error\n";
        } else {
            echo "Q: $question\n\n";
            echo trim($answer) . "\n\n";
            if ($sources) {
                echo "Sources used:\n";
                foreach ($sources as $s) echo $s . "\n";
            }
        }
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Research Ask</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body>
<div class="container my-4">
<h1 class="mb-4"><i class="fa-solid fa-magnifying-glass"></i> Research Ask</h1>
<form method="post" class="mb-4">
    <div class="mb-3">
        <label for="question" class="form-label">Question</label>
        <textarea id="question" name="question" class="form-control" rows="3" required><?= htmlspecialchars($_REQUEST['question'] ?? '') ?></textarea>
    </div>
    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="book_id" class="form-label">Book IDs (comma separated)</label>
            <input type="text" id="book_id" name="book_id" class="form-control" value="<?= htmlspecialchars($_REQUEST['book_id'] ?? '') ?>">
        </div>
        <div class="col-md-2 mb-3">
            <label for="max_chunks" class="form-label">Max Chunks</label>
            <input type="number" id="max_chunks" name="max_chunks" class="form-control" value="<?= htmlspecialchars($_REQUEST['max_chunks'] ?? '8') ?>">
        </div>
        <div class="col-md-2 mb-3">
            <label for="max_tokens" class="form-label">Max Tokens</label>
            <input type="number" id="max_tokens" name="max_tokens" class="form-control" value="<?= htmlspecialchars($_REQUEST['max_tokens'] ?? '2000') ?>">
        </div>
        <div class="col-md-2 mb-3">
            <label for="use" class="form-label">Provider</label>
            <input type="text" id="use" name="use" class="form-control" value="<?= htmlspecialchars($_REQUEST['use'] ?? 'claude') ?>">
        </div>
        <div class="col-md-2 mb-3">
            <label for="model" class="form-label">Model</label>
            <select id="model" name="model" class="form-select">
                <option value="" <?= (($_REQUEST['model'] ?? '') === '') ? 'selected' : '' ?>>Auto</option>
                <option value="deepseek/deepseek-r1-0528:free" <?= (($_REQUEST['model'] ?? '') === 'deepseek/deepseek-r1-0528:free') ? 'selected' : '' ?>>deepseek/deepseek-r1-0528:free</option>
                <option value="deepseek/deepseek-r1" <?= (($_REQUEST['model'] ?? '') === 'deepseek/deepseek-r1') ? 'selected' : '' ?>>deepseek/deepseek-r1</option>
                <option value="anthropic/claude-3.7-sonnet" <?= (($_REQUEST['model'] ?? '') === 'anthropic/claude-3.7-sonnet') ? 'selected' : '' ?>>anthropic/claude-3.7-sonnet</option>
                <option value="mistralai/mistral-medium-3.1" <?= (($_REQUEST['model'] ?? '') === 'mistralai/mistral-medium-3.1') ? 'selected' : '' ?>>mistralai/mistral-medium-3.1</option>
                <option value="google/gemini-2.5-flash" <?= (($_REQUEST['model'] ?? '') === 'google/gemini-2.5-flash') ? 'selected' : '' ?>>google/gemini-2.5-flash</option>
                <option value="anthropic/claude-sonnet-4" <?= (($_REQUEST['model'] ?? '') === 'anthropic/claude-sonnet-4') ? 'selected' : '' ?>>anthropic/claude-sonnet-4</option>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Ask</button>
</form>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($answer): ?>
<div class="card mb-3">
    <div class="card-header">Answer</div>
    <div class="card-body">
        <div id="answer-md"></div>
    </div>
</div>
<?php endif; ?>
<?php if ($sources): ?>
<div class="card">
    <div class="card-header">Sources used</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($sources as $s): ?>
        <li class="list-group-item"><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($answer): ?>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
<script>
  const rawAns = <?= json_encode($answer) ?>;
  document.getElementById('answer-md').innerHTML = DOMPurify.sanitize(marked.parse(rawAns));
</script>
<?php endif; ?>
</body>
</html>
<?php
function cosine(array $a, array $b): float {
  $dot=0; $na=0; $nb=0; $n=min(count($a),count($b));
  for ($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i]; $na+=$a[$i]*$a[$i]; $nb+=$b[$i]*$b[$i]; }
  return $dot / (sqrt($na)*sqrt($nb) + 1e-8);
}
function unpack_floats($bin): array {
  if ($bin === null) return [];
  return array_values(unpack('g*', $bin));
}
function embed_with_openai(string $text, string $model, string $apiKey): array {
  $payload = ['model'=>$model, 'input'=>$text];
  $res = http_post_json('https://api.openai.com/v1/embeddings', $payload, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
  ]);
  return $res['data'][0]['embedding'] ?? [];
}
function generate_with_openai(string $model, string $system, string $user, string $apiKey, float $temp=0.1, int $maxTokens=1200): string {
  $input = $system . "\n\n" . $user;
  $payload = ['model'=>$model, 'input'=>$input, 'temperature'=>$temp, 'max_output_tokens'=>$maxTokens];
  $res = http_post_json('https://api.openai.com/v1/responses', $payload, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
  ]);
  if (isset($res['output'][0]['content'][0]['text'])) return $res['output'][0]['content'][0]['text'];
  if (isset($res['content'][0]['text'])) return $res['content'][0]['text'];
  if (isset($res['choices'][0]['message']['content'])) return $res['choices'][0]['message']['content'];
  return json_encode($res);
}
function generate_with_openrouter(string $model, string $system, string $user, string $apiKey, float $temp=0.1, int $maxTokens=2000): string {
  $payload = [
    'model' => $model,
    'messages' => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTokens,
    'usage'       => ['include' => true],
  ];
  $res = http_post_json('https://openrouter.ai/api/v1/chat/completions', $payload, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'HTTP-Referer: https://your-site.example',
    'X-Title: Your App Name'
  ]);
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
    CURLOPT_TIMEOUT        => 120
  ]);
  $res = curl_exec($ch);
  if ($res === false) throw new Exception('cURL error: ' . curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception('HTTP ' . $code . ': ' . $res);
  $json = json_decode($res, true);
  return is_array($json) ? $json : [];
}
?>
