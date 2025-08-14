<?php
/**
 * research-cite.php — Web interface for creating Oxford footnotes and bibliography
 * from draft text using the ingested books.
 *
 * This is a browser-friendly version of the CLI script located at the project root.
 * It embeds each paragraph of the draft, retrieves relevant chunks from
 * library.sqlite, and uses OpenRouter (Claude) to produce citations constrained
 * to the provided context.
 *
 * Requirements:
 *   - PHP PDO SQLite
 *   - OPENAI_API_KEY + OPENAI_EMBED_MODEL for embeddings
 *   - OPENROUTER_API_KEY for generation via Claude
 */

ini_set('memory_limit', '1G');

$result = '';
$error  = '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$req    = ($method === 'POST') ? $_POST : $_GET;

$draft   = trim($req['draft'] ?? '');
$bookIds = [];
if (isset($req['book_id']) || isset($req['book-id'])) {
    $raw = $req['book_id'] ?? $req['book-id'];
    if (is_array($raw)) {
        $bookIds = array_map('intval', $raw);
    } else {
        $bookIds = array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
    }
}
$maxChunks = max(1, (int)($req['max_chunks'] ?? $req['max-chunks'] ?? 8));

if ($draft !== '') {
    try {
        $openaiKey  = getenv('OPENAI_API_KEY');
        $embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
        $orKey      = getenv('OPENROUTER_API_KEY');
        if (!$openaiKey) throw new Exception('Set OPENAI_API_KEY.');
        if (!$orKey)     throw new Exception('Set OPENROUTER_API_KEY.');

        // Load draft paragraphs
        $paras = preg_split("/\R{2,}/u", trim($draft));
        $paras = array_values(array_filter(array_map('trim', $paras), fn($p)=>$p!==''));

        // DB connection
        $db = new PDO('sqlite:' . __DIR__ . '/../library.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Preload chunk rows
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

        // Unpack embeddings once
        $corpus = [];
        foreach ($rows as $r) {
            $r['vec'] = unpack_floats($r['embedding']);
            if (!empty($r['vec'])) $corpus[] = $r;
        }
        unset($rows);

        $biblioByItem = [];
        $footnoteCounter = 1;
        $out = [];

        foreach ($paras as $para) {
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

            if (empty($top) || $top[0]['sim'] < 0.20) {
                $out[] = $para . " [^" . $footnoteCounter . "]";
                $out[] = "\n[^{$footnoteCounter}]: No suitable source found in selected books.";
                $footnoteCounter++;
                $out[] = '';
                continue;
            }

            // 3) Build system + user messages
            $system = "You are an academic assistant for a historian. "
                ."Match the paragraph with supporting sources ONLY from the provided context. "
                ."Return Oxford-style footnotes and bibliography entries using ONLY sources present in context. "
                ."Use exact page ranges from context metadata. "
                ."Do NOT invent sources, authors, years, publishers, or page numbers. "
                ."If publisher/place is unknown, omit it rather than guessing. "
                ."Output STRICT JSON (no extra text) with this schema:\n"
                ."{\"footnotes\": [{\"source_id\": <item_id>, \"text\": \"Oxford footnote text\"}], "
                ."\"bibliography\": [{\"source_id\": <item_id>, \"text\": \"Oxford bibliography entry\"}]}";

            // Build context blocks
            $ctx = "Paragraph:\n".$para."\n\nContext:\n";
            foreach ($top as $i=>$t) {
                $c = $t['row'];
                $meta = sprintf("%s (%s%s) p.%d–%d [source_id=%d]",
                    $c['title'],
                    $c['author'] ? $c['author'].", " : "",
                    $c['year'] ?: 'n.d.',
                    (int)$c['page_start'], (int)$c['page_end'],
                    (int)$c['item_id']
                );
                $ctx .= "\n[CTX $i] $meta\n{$c['text']}\n";
            }

            // 4) Ask Claude for JSON
            $jsonStr = generate_with_openrouter_json('anthropic/claude-sonnet-4', $system, $ctx, $orKey, 0.1, 1000);
            $parsed = json_decode($jsonStr, true);

            $fnThisPara = [];
            $bibThisPara = [];
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
                        $key = $sid !== null ? "sid:$sid" : "txt:".md5($text);
                        $biblioByItem[$key] = $text;
                        $bibThisPara[] = ['sid'=>$sid, 'text'=>$text];
                    }
                }
            } else {
                $fnThisPara[] = ['sid'=>null, 'text'=>"Could not parse citation JSON. Raw: ".substr($jsonStr,0,200)."…"];
            }

            // 5) Attach footnote markers and the notes
            if (empty($fnThisPara)) {
                $out[] = $para . " [^" . $footnoteCounter . "]";
                $out[] = "\n[^{$footnoteCounter}]: No matching source returned.";
                $footnoteCounter++;
            } else {
                $markers = [];
                foreach ($fnThisPara as $k=>$fn) {
                    $num = $footnoteCounter++;
                    $markers[] = "[^$num]";
                    $out[] = "\n[^{$num}]: ".$fn['text'];
                }
                $outPara = $para.' '.implode('', $markers);
                array_splice($out, count($out), 0, $outPara);
            }
            $out[] = '';
        }

        // 6) Append Bibliography
        $out[] = '## Bibliography';
        $bib = array_values(array_unique(array_filter(array_map('trim', array_values($biblioByItem)))));
        sort($bib, SORT_NATURAL | SORT_FLAG_CASE);
        if (empty($bib)) {
            $out[] = '(No sources referenced.)';
        } else {
            foreach ($bib as $b) $out[] = '- ' . $b;
        }

        $result = implode("\n", $out)."\n";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    if ($method === 'GET') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($error) {
            echo "Error: $error\n";
        } else {
            echo $result;
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
<title>Research Cite</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container my-4">
<h1 class="mb-4"><i class="fa-solid fa-quote-right"></i> Research Cite</h1>
<form method="post" class="mb-4">
    <div class="mb-3">
        <label for="draft" class="form-label">Draft Text</label>
        <textarea id="draft" name="draft" class="form-control" rows="6" required><?= htmlspecialchars($_REQUEST['draft'] ?? '') ?></textarea>
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
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-quote-right"></i> Cite</button>
</form>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($result && !$error): ?>
<div class="card">
    <div class="card-header">Cited Draft</div>
    <div class="card-body">
        <div id="result-md"></div>
    </div>
</div>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($result && !$error): ?>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
<script>
  const rawResult = <?= json_encode($result) ?>;
  document.getElementById('result-md').innerHTML = DOMPurify.sanitize(marked.parse(rawResult));
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
function generate_with_openrouter_json(string $model, string $system, string $user, string $apiKey, float $temp=0.1, int $maxTokens=1000): string {
  $payload = [
    'model' => $model,
    'messages' => [
      ['role'=>'system','content'=>$system],
      ['role'=>'user','content'=>$user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTokens,
    'response_format' => ['type'=>'json_object'],
    'usage' => ['include' => true],
  ];
  $res = http_post_json('https://openrouter.ai/api/v1/chat/completions', $payload, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'HTTP-Referer: https://your-site.example',
    'X-Title: Historian Cite Assistant'
  ]);
  if (isset($res['usage'])) {
    $in  = $res['usage']['input_tokens']  ?? $res['usage']['prompt_tokens'] ?? '?';
    $out = $res['usage']['output_tokens'] ?? $res['usage']['completion_tokens'] ?? '?';
    error_log("[Token usage] Input: $in | Output: $out");
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
  if ($res === false) throw new Exception('cURL error: '.curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception('HTTP '.$code.': '.$res);
  $json = json_decode($res, true);
  return is_array($json) ? $json : [];
}
?>
