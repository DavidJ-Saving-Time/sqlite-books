<?php
/**
 * research-book.php — Fixed-question book analysis: synopsis, characters, and themes.
 *
 * Instead of semantic retrieval, uses uniform coverage sampling across the book
 * (front-weighted: 30% of selections from the first 20% of the book).
 * No embeddings are computed at query time — just even sampling + generation.
 *
 * Requirements:
 *   - OPENROUTER_API_KEY (for generation)
 *   - PHP PDO pgsql
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/db.php';

const FIXED_QUESTION = 'Give me a synopsis of this book, and a list of the main characters, along with any central themes running through the book.';

$answer  = '';
$sources = [];
$error   = '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$req    = ($method === 'POST') ? $_POST : $_GET;

$bookId    = (int)($req['book_id'] ?? 0);
$maxTokens = max(1, (int)($req['max_tokens'] ?? 3000));
$modelName = trim($req['model'] ?? '');
$totalChunks = max(4, min(40, (int)($req['total_chunks'] ?? 20)));
$showPdfPages = !empty($req['show_pdf_pages'] ?? $req['show-pdf-pages']);

/**
 * Pick $k items evenly spaced from $arr.
 */
function evenSample(array $arr, int $k): array {
    $n = count($arr);
    if ($n === 0 || $k <= 0) return [];
    if ($k >= $n) return $arr;
    if ($k === 1) return [$arr[0]];
    $out = [];
    for ($i = 0; $i < $k; $i++) {
        $idx  = (int)round($i * ($n - 1) / ($k - 1));
        $out[] = $arr[$idx];
    }
    return $out;
}

/**
 * Select $totalChunks chunks from $allChunks using front-weighted uniform sampling.
 * frontFrac of the book → frontRatio of the selections (to capture character introductions).
 */
function sampleChunks(array $allChunks, int $totalChunks, float $frontFrac = 0.20, float $frontRatio = 0.30): array {
    $n = count($allChunks);
    if ($n === 0) return [];

    $splitAt   = max(1, (int)round($n * $frontFrac));
    $frontPart = array_slice($allChunks, 0, $splitAt);
    $restPart  = array_slice($allChunks, $splitAt);

    $frontN = max(1, (int)round($totalChunks * $frontRatio));
    $restN  = $totalChunks - $frontN;

    $selected = array_merge(
        evenSample($frontPart, $frontN),
        evenSample($restPart,  $restN)
    );

    usort($selected, fn($a, $b) => (int)$a['page_start'] <=> (int)$b['page_start']);
    return $selected;
}

// Fetch book list for the picker
$bookList = [];
try {
    $dbBooks = getResearchDb();
    ensureResearchSchema($dbBooks);
    $stmt = $dbBooks->query('SELECT id, title, author FROM items ORDER BY author, title');
    $bookList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // DB unavailable — page still renders, error shown on submit
}

$selectedBookTitle = '';
if ($bookId) {
    foreach ($bookList as $b) {
        if ((int)$b['id'] === $bookId) { $selectedBookTitle = $b['title']; break; }
    }
}

if ($method === 'POST' && $bookId > 0) {
    try {
        $orKey = getenv('OPENROUTER_API_KEY');
        if (!$orKey) throw new Exception('Set OPENROUTER_API_KEY.');

        $db = getResearchDb();

        // Fetch all chunks for this book ordered by page position
        $stmt = $db->prepare(
            "SELECT c.id, c.item_id, c.section, c.page_start, c.page_end, c.text,
                    c.display_start, c.display_end,
                    i.title, i.author, i.year, COALESCE(i.display_offset,0) AS display_offset,
                    i.library_book_id
             FROM chunks c
             JOIN items i ON c.item_id = i.id
             WHERE c.item_id = ?
             ORDER BY c.page_start"
        );
        $stmt->execute([$bookId]);
        $allChunks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allChunks)) {
            throw new Exception('No chunks found for this book. Has it been ingested?');
        }

        $selected = sampleChunks($allChunks, $totalChunks);

        // Resolve the PDF base URL once — all chunks are from the same book
        $firstChunk   = $selected[0];
        $bookTitle    = $firstChunk['title'];
        $bookAuthor   = $firstChunk['author'] ?: 'Unknown';
        $bookYear     = $firstChunk['year'] ?: 'n.d.';
        $pdfBaseUrl   = (!empty($firstChunk['library_book_id']))
            ? pdf_url_for_book((int)$firstChunk['library_book_id'])
            : null;

        // Build context and source list
        $ctx = '';
        foreach ($selected as $i => $c) {
            $pdfStart  = (int)$c['page_start'];
            $pdfEnd    = (int)$c['page_end'];
            $dispStart = ($c['display_start'] !== null) ? (int)$c['display_start'] : $pdfStart + (int)$c['display_offset'];
            $dispEnd   = ($c['display_end']   !== null) ? (int)$c['display_end']   : $pdfEnd   + (int)$c['display_offset'];

            $pageStr = $showPdfPages
                ? sprintf('pp.%s–%s (PDF %d–%d)', $dispStart, $dispEnd, $pdfStart, $pdfEnd)
                : sprintf('pp.%s–%s', $dispStart, $dispEnd);

            $meta = sprintf('%s, %s (%s) %s', $bookAuthor, $bookTitle, $bookYear, $pageStr);

            $ctx .= "\n[CTX $i] {$meta}\n{$c['text']}\n";

            $pdfUrl = $pdfBaseUrl ? ($pdfBaseUrl . ($pdfStart ? '#page=' . $pdfStart : '')) : null;
            $sources[] = ['text' => $meta, 'url' => $pdfUrl];
        }

        $sys = "

                    You are a literary research assistant.
Answer ONLY using the provided context chunks.
If the provided chunks are insufficient to produce a meaningful synopsis, reply exactly: Not in library.

Tone & style:
- Write in a clear, engaging, and readable style suited to a book discovery platform.
- Avoid overly academic or artificial wording; aim for natural flow and clarity.
- Paraphrase where appropriate but remain faithful to the meaning of the source.

Grounding rules:
- All factual claims must be supported by the provided context.
- Key claims, character descriptions, and theme identifications should be grounded with a direct quotation from the provided context where possible.
- Quotations must appear in quotation marks.
- You may paraphrase and explain, but quotations must clearly show the source of the fact.
- Do not add outside knowledge.

Output structure — you must always produce exactly these four sections in this order:

1. Quick Summary
   - 3–5 concise bullet points summarising the book in plain, accessible language.

2. Synopsis
   - A clear narrative summary of the book covering its arc from beginning to end.
   - Ground key plot points with direct quotations from the chunks where possible.

3. Main Characters
   - A list of the main characters, each with a short description of their role and defining traits.
   - Ground each character description with at least one direct quotation where possible.

4. Central Themes
   - A list of the central themes running through the book, each with a short explanation.
   - Ground each theme with at least one direct quotation from the chunks where possible.

Referencing rules:
- Use Oxford referencing style with footnotes.
- For every factual claim or quotation, include a superscript number and a corresponding footnote.
- Use Markdown footnote syntax.
- Footnote format: Author, *Title* (Year), pp. X–Y.

Examples:

Correct output:
- The story opens with \"a man standing alone at the edge of the world\"[^1], setting a tone of isolation.
- The central conflict is described as \"a war not of armies but of ideas\"[^2].

Hugh, described as \"practical and strong-willed\"[^1], leads his group through the nuclear war and an alien future society.

The theme of racial hierarchy is explored through \"a deliberate inversion of power\"[^2], holding a mirror up to the reader's assumptions.

[^1]: Heinlein, *Farnham's Freehold* (1964), pp. 45–46.
[^2]: Heinlein, *Farnham's Freehold* (1964), pp. 120–122.

Incorrect output:
- Bullet points with no grounding in the provided context.
- Character descriptions with no supporting quotation.
- Themes identified from outside knowledge rather than the chunks.
- Sections missing or produced in the wrong order.
- Replying with anything other than 'Not in library' when chunk coverage is insufficient for a meaningful synopsis.
        
        
        ";

        $user = "Book: " . $bookTitle . " by " . $bookAuthor . "\n\nContext passages:\n" . $ctx . "\n\nQuestion: " . FIXED_QUESTION;

        $answerModel = $modelName ?: 'anthropic/claude-opus-4.6';
        if (str_starts_with($answerModel, 'ollama/')) {
            $answer = generate_with_ollama(substr($answerModel, 7), $sys, $user, 0.3, $maxTokens);
        } else {
            $answer = generate_with_openrouter($answerModel, $sys, $user, $orKey, 0.3, $maxTokens);
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} elseif ($method === 'POST' && $bookId === 0) {
    $error = 'Please select a book before analysing.';
}

$sources_note_html = '';
if ($sources) {
    $sources_note_html .= "<h2>Passages consulted</h2><ul>";
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
<title>Book Analysis — Research</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Lora:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/research-theme.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="ra-page">

  <header class="ra-header">
    <div class="ra-header-rule">Archive</div>
    <h1>Book Analysis</h1>
    <p>Synopsis, characters, and themes drawn from the ingested text.</p>
  </header>

  <form method="post" class="ra-form">

    <!-- Fixed question display -->
    <div class="ra-field">
      <label class="ra-label">Question</label>
      <div class="ra-fixed-question"><?= htmlspecialchars(FIXED_QUESTION) ?></div>
      <input type="hidden" name="show_pdf_pages" value="<?= $showPdfPages ? '1' : '0' ?>">
    </div>

    <!-- Main settings grid -->
    <div class="ra-settings-grid">

      <!-- Book selector -->
      <div class="ra-field ra-field--flush">
        <label class="ra-label">Book <span class="ra-required">*</span></label>
        <button class="ra-books-btn" type="button" data-bs-toggle="modal" data-bs-target="#bookModal">
          <i class="fa-solid fa-book ra-icon-xs"></i> Select Book
        </button>
        <input type="hidden" id="book_id" name="book_id" value="<?= $bookId ?: '' ?>">
        <div id="selectedBookDisplay">
          <?php if ($selectedBookTitle): ?>
            <span class="ra-book-tag">
              <?= htmlspecialchars($selectedBookTitle) ?>
              <button type="button" class="ra-book-tag-remove" id="clearBook" aria-label="Remove">✕</button>
            </span>
          <?php else: ?>
            <span class="ra-all-books">No book selected</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Model -->
      <div class="ra-field ra-field--flush">
        <label class="ra-label" for="model">Model</label>
        <div class="ra-select-wrap">
          <select id="model" name="model" class="ra-select">
            <option value="" <?= ($modelName === '') ? 'selected' : '' ?>>Auto (claude-opus-4.6)</option>
            <optgroup label="── Anthropic">
            <option value="anthropic/claude-opus-4.6"   <?= ($modelName === 'anthropic/claude-opus-4.6')   ? 'selected' : '' ?>>claude-opus-4.6</option>
            <option value="anthropic/claude-sonnet-4.6" <?= ($modelName === 'anthropic/claude-sonnet-4.6') ? 'selected' : '' ?>>claude-sonnet-4.6</option>
            </optgroup>
            <optgroup label="── OpenAI">
            <option value="openai/gpt-5.4"      <?= ($modelName === 'openai/gpt-5.4')      ? 'selected' : '' ?>>gpt-5.4</option>
            <option value="openai/gpt-5.4-mini" <?= ($modelName === 'openai/gpt-5.4-mini') ? 'selected' : '' ?>>gpt-5.4-mini</option>
            </optgroup>
            <optgroup label="── Google">
            <option value="google/gemini-3.1-pro-preview"        <?= ($modelName === 'google/gemini-3.1-pro-preview')        ? 'selected' : '' ?>>gemini-3.1-pro-preview</option>
            <option value="google/gemini-3.1-flash-lite-preview" <?= ($modelName === 'google/gemini-3.1-flash-lite-preview') ? 'selected' : '' ?>>gemini-3.1-flash-lite</option>
            </optgroup>
            <optgroup label="── DeepSeek">
            <option value="deepseek/deepseek-r1-0528" <?= ($modelName === 'deepseek/deepseek-r1-0528') ? 'selected' : '' ?>>deepseek-r1-0528</option>
            <option value="deepseek/deepseek-v3.2"    <?= ($modelName === 'deepseek/deepseek-v3.2')    ? 'selected' : '' ?>>deepseek-v3.2</option>
            </optgroup>
            <optgroup label="── Free">
            <option value="meta-llama/llama-3.3-70b-instruct:free" <?= ($modelName === 'meta-llama/llama-3.3-70b-instruct:free') ? 'selected' : '' ?>>llama-3.3-70b (free)</option>
            </optgroup>
            <optgroup label="── Local (Ollama)">
            <option value="ollama/llama3:instruct"     <?= ($modelName === 'ollama/llama3:instruct')     ? 'selected' : '' ?>>llama3:instruct (local 8B)</option>
            <option value="ollama/llama3.2:latest"     <?= ($modelName === 'ollama/llama3.2:latest')     ? 'selected' : '' ?>>llama3.2 (local 3B)</option>
            <option value="ollama/qwen2.5:3b-instruct" <?= ($modelName === 'ollama/qwen2.5:3b-instruct') ? 'selected' : '' ?>>qwen2.5:3b (local 3B)</option>
            <option value="ollama/phi3:mini"           <?= ($modelName === 'ollama/phi3:mini')           ? 'selected' : '' ?>>phi3:mini (local 3.8B)</option>
            </optgroup>
          </select>
        </div>
      </div>

    </div><!-- /settings-grid -->

    <!-- Advanced -->
    <details class="ra-advanced">
      <summary>Advanced options</summary>
      <div class="ra-advanced-grid">
        <div class="ra-field ra-field--flush">
          <label class="ra-label" for="total_chunks">Passages Sampled</label>
          <input type="number" id="total_chunks" name="total_chunks" class="ra-input"
                 value="<?= htmlspecialchars((string)$totalChunks) ?>" min="4" max="40">
        </div>
        <div class="ra-field ra-field--flush">
          <label class="ra-label" for="max_tokens">Max Tokens</label>
          <input type="number" id="max_tokens" name="max_tokens" class="ra-input"
                 value="<?= htmlspecialchars((string)$maxTokens) ?>">
        </div>
      </div>
    </details>

    <button type="submit" class="ra-submit" id="analyseBtn">
      <i class="fa-solid fa-book-open me-2"></i>Analyse Book
    </button>

  </form>

  <?php if ($error): ?>
  <div class="ra-error"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($answer): ?>
  <section class="ra-answer-section">
    <div class="ra-answer-header">
      <h2><?= htmlspecialchars($selectedBookTitle ?: 'Analysis') ?></h2>
      <button class="ra-save-btn" type="button" id="saveNoteBtn">
        <i class="fa-solid fa-floppy-disk"></i> Save to Notes
      </button>
    </div>
    <div id="answer-md"></div>
  </section>
  <?php endif; ?>

  <?php if ($sources): ?>
  <section class="ra-sources-section">
    <div class="ra-sources-header">
      <h2>Passages consulted (<?= count($sources) ?>)</h2>
    </div>
    <?php foreach ($sources as $s): ?>
    <div class="ra-source-item">
      <?php if (!empty($s['url'])): ?>
        <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank"><?= htmlspecialchars($s['text']) ?></a>
      <?php else: ?>
        <?= htmlspecialchars($s['text']) ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

</main>

<!-- Book selection modal (single-select) -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-labelledby="bookModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bookModalLabel">Select Book</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="bookSearch" class="form-control mb-3" placeholder="Filter by title or author…">
        <div class="list-group" id="bookList">
          <?php foreach ($bookList as $b): ?>
            <label class="list-group-item d-flex align-items-center gap-2">
              <input class="book-radio" type="radio" name="book_pick"
                     value="<?= (int)$b['id'] ?>"
                     data-title="<?= htmlspecialchars($b['title']) ?>"
                     <?= ((int)$b['id'] === $bookId) ? 'checked' : '' ?>>
              <span>
                <?php if (!empty($b['author'])): ?>
                  <span class="book-author-tag"><?= htmlspecialchars($b['author']) ?> — </span>
                <?php endif; ?>
                <?= htmlspecialchars($b['title']) ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="applyBook" data-bs-dismiss="modal">Select</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($answer): ?>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
<script>
  const rawAns = <?= json_encode($answer) ?>;
  const answerHTML = DOMPurify.sanitize(marked.parse(rawAns));
  const sourcesHTML = <?= json_encode($sources_note_html) ?>;
  document.getElementById('answer-md').innerHTML = answerHTML;
  document.querySelector('.ra-answer-section').scrollIntoView({ behavior: 'smooth', block: 'start' });

  (async () => {
    const saveBtn = document.getElementById('saveNoteBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const params = new URLSearchParams();
    params.append('mode', 'new');
    params.append('title', <?= json_encode($selectedBookTitle ? $selectedBookTitle . ' — Analysis' : 'Book Analysis') ?>);
    params.append('text', `<h1>${DOMPurify.sanitize(<?= json_encode($selectedBookTitle ?: 'Book Analysis') ?>)}</h1>` + answerHTML + sourcesHTML);
    try {
      const res  = await fetch('/json_endpoints/save_note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      });
      const data = await res.json();
      if (data.status === 'ok') {
        saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Saved to Notes';
        saveBtn.style.borderColor = 'var(--accent)';
        saveBtn.style.color = 'var(--accent)';
      } else {
        saveBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Save failed';
      }
    } catch (e) {
      saveBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Save failed';
    }
  })();
</script>
<?php endif; ?>

<script>
let selectedBookId    = <?= $bookId ?: 'null' ?>;
let selectedBookTitle = <?= json_encode($selectedBookTitle) ?>;

function renderSelectedBook() {
  const display = document.getElementById('selectedBookDisplay');
  const input   = document.getElementById('book_id');
  if (selectedBookId) {
    input.value  = selectedBookId;
    display.innerHTML = `<span class="ra-book-tag">
      ${selectedBookTitle}
      <button type="button" class="ra-book-tag-remove" id="clearBook" aria-label="Remove">✕</button>
    </span>`;
    document.getElementById('clearBook').addEventListener('click', () => {
      selectedBookId    = null;
      selectedBookTitle = '';
      renderSelectedBook();
    });
  } else {
    input.value = '';
    display.innerHTML = '<span class="ra-all-books">No book selected</span>';
  }
}

document.getElementById('bookModal').addEventListener('show.bs.modal', () => {
  document.querySelectorAll('.book-radio').forEach(r => {
    r.checked = (parseInt(r.value) === selectedBookId);
  });
});

document.getElementById('applyBook').addEventListener('click', () => {
  const checked = document.querySelector('.book-radio:checked');
  if (checked) {
    selectedBookId    = parseInt(checked.value);
    selectedBookTitle = checked.dataset.title;
    renderSelectedBook();
  }
});

document.getElementById('bookSearch').addEventListener('input', e => {
  const term = e.target.value.toLowerCase();
  document.querySelectorAll('#bookList label').forEach(lbl => {
    lbl.style.display = lbl.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
});

// Clear button wired up on initial render if book pre-selected
(function() {
  const cb = document.getElementById('clearBook');
  if (cb) cb.addEventListener('click', () => {
    selectedBookId    = null;
    selectedBookTitle = '';
    renderSelectedBook();
  });
})();

document.querySelector('form').addEventListener('submit', () => {
  const btn = document.getElementById('analyseBtn');
  btn.disabled = true;
  btn.innerHTML = `
    <span class="ra-loading">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83">
          <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite"/>
        </path>
      </svg>
      Analysing…
    </span>`;
});
</script>
</body>
</html>
<?php
function generate_with_ollama(string $model, string $system, string $user, float $temp=0.3, int $maxTokens=3000): string {
  $payload = [
    'model'    => $model,
    'messages' => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTokens,
    'stream'      => false,
  ];
  $res = http_post_json('http://localhost:11434/v1/chat/completions', $payload, ['Content-Type: application/json']);
  if (isset($res['choices'][0]['message']['content'])) return $res['choices'][0]['message']['content'];
  return json_encode($res);
}

function generate_with_openrouter(string $model, string $system, string $user, string $apiKey, float $temp=0.3, int $maxTokens=3000): string {
  $payload = [
    'model'    => $model,
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
    CURLOPT_TIMEOUT        => 180
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
    if ($pdo === null) $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare('SELECT path FROM books WHERE id = ?');
    $stmt->execute([$bookId]);
    $path = $stmt->fetchColumn();
    if (!$path) return null;
    $library = getLibraryPath();
    $dir = rtrim($library, '/') . '/' . $path;
    $stmt = $pdo->prepare('SELECT name FROM data WHERE book = ? AND format = "PDF" ORDER BY id DESC');
    $stmt->execute([$bookId]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $name) {
      $candidate = $dir . '/' . $name . '.pdf';
      if (is_file($candidate)) {
        $rel = $path . '/' . $name . '.pdf';
        $encoded = implode('/', array_map(function($seg) {
          return str_replace(['%28','%29'], ['(',')'], rawurlencode($seg));
        }, explode('/', $rel)));
        return getLibraryWebPath() . '/' . $encoded;
      }
    }
    if (is_dir($dir)) {
      $files = glob($dir . '/*.pdf');
      if ($files) {
        usort($files, fn($a,$b) => strlen(basename($b)) <=> strlen(basename($a)));
        $rel = $path . '/' . basename($files[0]);
        $encoded = implode('/', array_map(function($seg) {
          return str_replace(['%28','%29'], ['(',')'], rawurlencode($seg));
        }, explode('/', $rel)));
        return getLibraryWebPath() . '/' . $encoded;
      }
    }
  } catch (Exception $e) { /* ignore */ }
  return null;
}
?>
