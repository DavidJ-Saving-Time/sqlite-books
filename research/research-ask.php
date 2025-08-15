<?php
/**
 * research-ask.php — Web interface for retrieval-augmented QA over the ingested books.
 *
 * This is a browser-friendly version of the CLI script located at the project root.
 * It embeds the user's question, finds relevant chunks from library.sqlite, and
 * generates an answer using either OpenRouter (Claude) or OpenAI.
 * Optional parameters min_distinct and per_book_cap (default 3) control
 * the minimum number of distinct books and the cap per book when selecting context.
 * Pass show_pdf_pages=1 to include raw PDF page numbers alongside adjusted ones.
 *
 * Requirements:
 *   - PHP PDO SQLite
 *   - OPENAI_API_KEY (for embeddings, and OpenAI answering)
 *   - Optional: OPENROUTER_API_KEY when using Claude via OpenRouter
 *   - Optional: OPENAI_EMBED_MODEL (defaults to text-embedding-3-large)
 */

ini_set('memory_limit', '1G');
require_once __DIR__ . '/../db.php';

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
$minDistinct = max(1, (int)($req['min_distinct'] ?? $req['min-distinct'] ?? 3));
$perBookCap  = max(1, (int)($req['per_book_cap'] ?? $req['per-book-cap'] ?? 3));
$showPdfPages = !empty($req['show_pdf_pages'] ?? $req['show-pdf-pages']);

// Fetch list of all books for the selection modal
$bookList = [];
// Fetch list of existing notes for saving answers
$noteList = [];
try {
    // Books are stored in the embedding database used for retrieval
    $dbList = new PDO('sqlite:' . __DIR__ . '/../library.sqlite');
    $stmt = $dbList->query('SELECT id, title FROM items ORDER BY title');
    $bookList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore if the database is unavailable
}

try {
    // Notes live in the main metadata database
    $noteDb = getDatabaseConnection();
    $noteStmt = $noteDb->query('SELECT id, title FROM notepad ORDER BY title');
    $noteList = $noteStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore if the database is unavailable
}

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

        $sql = "SELECT
          c.id, c.item_id, c.section, c.page_start, c.page_end, c.text, c.embedding,
          c.display_start, c.display_end,
          i.title, i.author, i.year, COALESCE(i.display_offset,0) AS display_offset,
          i.library_book_id
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

        // 3) Compute cosine similarity and rank
        $top = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vec = unpack_floats($row['embedding']);
            if (!$vec) continue;
            $row['sim'] = cosine($qVec, $vec);
            $top[] = $row;
        }
        usort($top, fn($a,$b)=> $b['sim']<=>$a['sim']);

        // Enforce per-book cap and ensure a minimum number of distinct books
        $grouped = [];
        foreach ($top as $row) {
            $bid = $row['item_id'];
            if (!isset($grouped[$bid])) $grouped[$bid] = [];
            if (count($grouped[$bid]) < $perBookCap) {
                $grouped[$bid][] = $row;
            }
        }

        // Take one chunk from each book (best similarity) up to minDistinct
        $firstChunks = [];
        foreach ($grouped as $rows) {
            $firstChunks[] = $rows[0];
        }
        usort($firstChunks, fn($a,$b)=> $b['sim'] <=> $a['sim']);
        $initial  = array_slice($firstChunks, 0, $minDistinct);
        $selected = $initial;
        foreach ($initial as $r) {
            $bid = $r['item_id'];
            array_shift($grouped[$bid]);
            if (empty($grouped[$bid])) unset($grouped[$bid]);
        }

        // Fill remaining slots by highest-similarity chunks respecting per-book cap
        $remaining = $maxChunks - count($selected);
        if ($remaining > 0) {
            $rest = [];
            foreach ($grouped as $rows) {
                foreach ($rows as $r) $rest[] = $r;
            }
            usort($rest, fn($a,$b)=> $b['sim'] <=> $a['sim']);
            $selected = array_merge($selected, array_slice($rest, 0, $remaining));
        }

        $top = $selected;

        if (empty($top) || $top[0]['sim'] < 0.25) {
            $answer = 'Not in library (retrieval too weak).';
        } else {
            // 4) Build grounded prompt
            $sys = "You are a research assistant. Answer ONLY using the provided context. " .
                   "If not answerable, reply exactly: Not in library. " .
                   "Use Oxford referencing style with footnotes. For each factual claim, add a superscript number and provide " .
                   "a footnote in the format: Author, *Title* (Year), pp. X–Y. Use Markdown footnote syntax. " .
                   "Start with 3–5 bullet points, then details.";

            $ctx = '';
            foreach ($top as $i=>$c) {
 $pdfStart = (int)$c['page_start'];
$pdfEnd   = (int)$c['page_end'];

// Prefer the true printed page numbers if present
$dispStart = (isset($c['display_start']) && $c['display_start'] !== null) ? (int)$c['display_start'] : null;
$dispEnd   = (isset($c['display_end'])   && $c['display_end']   !== null) ? (int)$c['display_end']   : null;

// Fallback to legacy offset only if display_* are missing
if ($dispStart === null || $dispEnd === null) {
    $dispStart = $pdfStart + (int)$c['display_offset'];
    $dispEnd   = $pdfEnd   + (int)$c['display_offset'];
}

$pageStr = $showPdfPages
    ? sprintf('pp.%s–%s (PDF %d–%d)', $dispStart, $dispEnd, $pdfStart, $pdfEnd)
    : sprintf('pp.%s–%s', $dispStart, $dispEnd);

$meta = sprintf('%s, %s (%s) %s',
    $c['author'] ?: 'Unknown',
    $c['title'],
    $c['year'] ?: 'n.d.',
    $pageStr
);

$ctx .= "\n[CTX $i] {$meta}\n{$c['text']}\n";
    $pdfUrl = null;
    if (!empty($c['library_book_id'])) {
        $pdfUrl = pdf_url_for_book((int)$c['library_book_id']);
        if ($pdfUrl && $pdfStart) {
            $pdfUrl .= '#page=' . $pdfStart;
        }
    }

    $sources[] = [
        'text' => sprintf('%s, %s (%s) %s [sim=%.3f]',
            $c['author'] ?: 'Unknown',
            $c['title'],
            $c['year'] ?: 'n.d.',
            $pageStr,
            $c['sim']
        ),
        'url' => $pdfUrl
    ];
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
                foreach ($sources as $s) {
                    echo $s['text'];
                    if (!empty($s['url'])) echo ' ' . $s['url'];
                    echo "\n";
                }
            }
        }
        exit;
    }
}

$sources_note_html = '';
if ($sources) {
    $sources_note_html .= "<h2>Sources used</h2><ul>";
    foreach ($sources as $s) {
        $text = htmlspecialchars($s['text'], ENT_QUOTES);
        if (!empty($s['url'])) {
            $url = htmlspecialchars($s['url'], ENT_QUOTES);
            $sources_note_html .= "<li><a href=\"{$url}\" target=\"_blank\">{$text}</a></li>";
        } else {
            $sources_note_html .= "<li>{$text}</li>";
        }
    }
    $sources_note_html .= '</ul>';
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
<body class="pt-5">
<?php include 'navbar.php'; ?>
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
            <div class="input-group">
                <input type="text" id="book_id" name="book_id" class="form-control" value="<?= htmlspecialchars($_REQUEST['book_id'] ?? '') ?>">
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#bookModal">Select</button>
            </div>
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
    <div class="row">
        <div class="col-md-2 mb-3">
            <label for="min_distinct" class="form-label">Min Distinct</label>
            <input type="number" id="min_distinct" name="min_distinct" class="form-control" value="<?= htmlspecialchars($_REQUEST['min_distinct'] ?? ($_REQUEST['min-distinct'] ?? '3')) ?>">
        </div>
        <div class="col-md-2 mb-3">
            <label for="per_book_cap" class="form-label">Per Book Cap</label>
            <input type="number" id="per_book_cap" name="per_book_cap" class="form-control" value="<?= htmlspecialchars($_REQUEST['per_book_cap'] ?? ($_REQUEST['per-book-cap'] ?? '3')) ?>">
        </div>
    </div>
    <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" id="show_pdf_pages" name="show_pdf_pages" value="1" <?= (!empty($_REQUEST['show_pdf_pages'] ?? $_REQUEST['show-pdf-pages'] ?? '')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="show_pdf_pages">Show PDF page numbers</label>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Ask</button>
</form>

<!-- Book selection modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-labelledby="bookModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bookModalLabel">Select Books</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="bookSearch" class="form-control mb-3" placeholder="Filter books">
        <div class="list-group" id="bookList">
          <?php foreach ($bookList as $b): ?>
            <label class="list-group-item">
              <input class="form-check-input me-1 book-checkbox" type="checkbox" value="<?= (int)$b['id'] ?>">
              <?= htmlspecialchars($b['id']) ?> – <?= htmlspecialchars($b['title']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="applyBooks" data-bs-dismiss="modal">Apply</button>
      </div>
    </div>
  </div>
</div>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($answer): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Answer</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="saveAnswerBtn" data-bs-toggle="modal" data-bs-target="#saveNoteModal">
            <i class="fa-solid fa-floppy-disk"></i> Save
        </button>
    </div>
    <div class="card-body">
        <div id="answer-md"></div>
    </div>
</div>

<!-- Save answer modal -->
<div class="modal fade" id="saveNoteModal" tabindex="-1" aria-labelledby="saveNoteLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="saveNoteLabel">Save to Notepad</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="noteSelect" class="form-label">Existing Note</label>
          <select id="noteSelect" class="form-select">
            <option value="">-- New Note --</option>
            <?php foreach ($noteList as $n): ?>
              <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3" id="newNoteTitleGroup">
          <label for="newNoteTitle" class="form-label">Title</label>
          <input type="text" id="newNoteTitle" class="form-control" placeholder="Enter title">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="saveNoteConfirm">Save</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($sources): ?>
<div class="card">
    <div class="card-header">Sources used</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($sources as $s): ?>
        <li class="list-group-item">
            <?php if (!empty($s['url'])): ?>
                <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank"><?= htmlspecialchars($s['text']) ?></a>
            <?php else: ?>
                <?= htmlspecialchars($s['text']) ?>
            <?php endif; ?>
        </li>
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
    const answerHTML = DOMPurify.sanitize(marked.parse(rawAns));
    const questionFirstLine = <?= json_encode(preg_split('/\r?\n/', $question)[0] ?? '') ?>;
    const sourcesHTML = <?= json_encode($sources_note_html) ?>;
    document.getElementById('answer-md').innerHTML = answerHTML;

    document.getElementById('noteSelect').addEventListener('change', function(){
      document.getElementById('newNoteTitleGroup').style.display = this.value === '' ? '' : 'none';
    });

    document.getElementById('saveNoteConfirm').addEventListener('click', async () => {
      const noteId = document.getElementById('noteSelect').value;
      const title = document.getElementById('newNoteTitle').value.trim();
      const params = new URLSearchParams();
      const header = `<h1>${DOMPurify.sanitize(questionFirstLine)}</h1>`;
      const fullHtml = header + answerHTML + sourcesHTML;
      params.append('text', fullHtml);
      if (noteId) {
        params.append('mode', 'append');
        params.append('id', noteId);
      } else {
        params.append('mode', 'new');
        params.append('title', title);
      }
      try {
        const res = await fetch('/json_endpoints/save_note.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString()
        });
        const data = await res.json();
        if (data.status === 'ok') {
          alert('Saved');
          const modalEl = document.getElementById('saveNoteModal');
          const modal = bootstrap.Modal.getInstance(modalEl);
          modal.hide();
        } else {
          alert('Error: ' + (data.error || 'unknown'));
        }
      } catch (e) {
        alert('Error saving note');
      }
    });
  </script>
  <?php endif; ?>
<script>
// Apply selected book IDs to input field
document.getElementById('applyBooks').addEventListener('click', () => {
  const ids = Array.from(document.querySelectorAll('.book-checkbox:checked'))
    .map(cb => cb.value)
    .join(',');
  document.getElementById('book_id').value = ids;
});

// Simple filter for the book list
document.getElementById('bookSearch').addEventListener('input', (e) => {
  const term = e.target.value.toLowerCase();
  document.querySelectorAll('#bookList label').forEach(lbl => {
    lbl.style.display = lbl.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
});
</script>
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

function pdf_url_for_book(int $bookId): ?string {
  static $pdo = null;
  try {
    if ($pdo === null) {
      $pdo = getDatabaseConnection();
    }
    // Get relative directory for the book
    $stmt = $pdo->prepare('SELECT path FROM books WHERE id = ?');
    $stmt->execute([$bookId]);
    $path = $stmt->fetchColumn();
    if (!$path) return null;

    $library = getLibraryPath();
    $dir = rtrim($library, '/') . '/' . $path;

    // Try using names recorded in the data table first
    $stmt = $pdo->prepare('SELECT name FROM data WHERE book = ? AND format = "PDF" ORDER BY id DESC');
    $stmt->execute([$bookId]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $name) {
      $candidate = $dir . '/' . $name . '.pdf';
      if (is_file($candidate)) {
        $rel = $path . '/' . $name . '.pdf';
        $encoded = implode('/', array_map(function ($seg) {
          $enc = rawurlencode($seg);
          return str_replace(['%28','%29'], ['(',')'], $enc);
        }, explode('/', $rel)));
        return getLibraryWebPath() . '/' . $encoded;
      }
    }

    // Fallback: scan directory for any PDF files
    if (is_dir($dir)) {
      $files = glob($dir . '/*.pdf');
      if ($files) {
        // Prefer the longest filename (likely "Title - Author.pdf")
        usort($files, fn($a, $b) => strlen(basename($b)) <=> strlen(basename($a)));
        $file = basename($files[0]);
        $rel = $path . '/' . $file;
        $encoded = implode('/', array_map(function ($seg) {
          $enc = rawurlencode($seg);
          return str_replace(['%28','%29'], ['(',')'], $enc);
        }, explode('/', $rel)));
        return getLibraryWebPath() . '/' . $encoded;
      }
    }
  } catch (Exception $e) {
    // ignore and fall through to null return
  }
  return null;
}
?>
