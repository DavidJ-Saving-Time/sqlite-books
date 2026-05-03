<?php
/**
 * research-ask.php — Web interface for retrieval-augmented QA over the ingested books.
 *
 * Uses PostgreSQL + pgvector for hybrid retrieval (BM25 via tsvector + ANN via <=> operator).
 * Optional parameter min_distinct (default 3) controls the minimum number of distinct books
 * when selecting context. Pass show_pdf_pages=1 to include raw PDF page numbers alongside
 * adjusted ones.
 *
 * Requirements:
 *   - PHP PDO pgsql + pgvector extension installed in PostgreSQL
 *   - OPENAI_API_KEY (for embeddings)
 *   - OPENROUTER_API_KEY (for Claude-based generation)
 *   - Optional: OPENAI_EMBED_MODEL (defaults to text-embedding-3-large)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/db.php';

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
} elseif (isset($req['book_ids'])) {
    $bookIds = array_filter(array_map('intval', preg_split('/\s*,\s*/', $req['book_ids'], -1, PREG_SPLIT_NO_EMPTY)));
}
$maxChunks   = max(1, (int)($req['max_chunks'] ?? $req['max-chunks'] ?? 8));
$maxTokens   = max(1, (int)($req['max_tokens'] ?? $req['max-tokens'] ?? 2000));
$useWhich    = strtolower(trim($req['use'] ?? 'claude'));
$modelName   = trim($req['model'] ?? '');
$minDistinct = max(1, (int)($req['min_distinct'] ?? $req['min-distinct'] ?? 3));
$showPdfPages = !empty($req['show_pdf_pages'] ?? $req['show-pdf-pages']);
$simpleTerms  = !empty($req['simple_terms'] ?? $req['simple-terms']);


function ftsTermsFromQuestion(string $q, int $maxTerms = 12): array {
    preg_match_all('/[[:alnum:]]+/u', mb_strtolower($q, 'UTF-8'), $m);
    $terms = $m[0] ?? [];
    $out = []; $seen = [];
    foreach ($terms as $t) {
        if (strlen($t) < 2) continue;
        if (isset($seen[$t])) continue;
        $seen[$t] = true;
        $out[] = $t;
        if (count($out) >= $maxTerms) break;
    }
    return $out;
}

/**
 * Postgres FTS: try AND (plainto_tsquery) first, fall back to OR (to_tsquery with |).
 * Returns up to 100 chunk IDs ranked by ts_rank.
 */
function ftsSparseTop100(PDO $db, string $question, array $bookIds = []): array {
    $bookFilter  = '';
    $extraParams = [];
    if (!empty($bookIds)) {
        $in = implode(',', array_fill(0, count($bookIds), '?'));
        $bookFilter  = " AND item_id IN ($in)";
        $extraParams = array_values($bookIds);
    }

    // AND search — plainto_tsquery treats multiple words as AND
    $sql = "SELECT id FROM chunks
            WHERE text_search @@ plainto_tsquery('english', ?)
            $bookFilter
            ORDER BY ts_rank(text_search, plainto_tsquery('english', ?)) DESC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$question], $extraParams, [$question]));
    $ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    if ($ids) return $ids;

    // OR fallback — terms are [[:alnum:]] only, safe to build literal tsquery
    $terms = ftsTermsFromQuestion($question);
    if (!$terms) return [];
    $tsExpr = implode(' | ', $terms);
    $sql = "SELECT id FROM chunks
            WHERE text_search @@ to_tsquery('english', ?)
            $bookFilter
            ORDER BY ts_rank(text_search, to_tsquery('english', ?)) DESC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$tsExpr], $extraParams, [$tsExpr]));
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
}

function rrfFuse(array $bm25Ids, array $denseIds, int $k=60): array {
    $ranks = [];
    foreach ($bm25Ids  as $i => $id) { $ranks[$id][0] = $i + 1; }
    foreach ($denseIds as $i => $id) { $ranks[$id][1] = $i + 1; }

    $scores = [];
    foreach ($ranks as $id => $r) {
        $rs = $r[0] ?? null; $rd = $r[1] ?? null;
        $s  = 0.0;
        if ($rs) $s += 1.0 / ($k + $rs);
        if ($rd) $s += 1.0 / ($k + $rd);
        $scores[$id] = $s;
    }
    arsort($scores, SORT_NUMERIC);
    return array_keys($scores);
}

// Fetch list of all books for the selection modal
$bookList = [];
try {
    $dbBooks = getResearchDb();
    ensureResearchSchema($dbBooks);
    $stmt = $dbBooks->query('SELECT id, title, author FROM items ORDER BY author, title');
    $bookList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore if the database is unavailable
}

// Titles of the books selected for this query (used in the auto-saved note title)
$selectedBookTitles = [];
if ($bookIds) {
    $bookTitleById = array_column($bookList, 'title', 'id');
    foreach ($bookIds as $bid) {
        if (isset($bookTitleById[$bid])) {
            $selectedBookTitles[] = $bookTitleById[$bid];
        }
    }
}

if ($question !== '') {
    try {
        $openaiKey  = getenv('OPENAI_API_KEY');
        $embedModel = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
        $orKey      = getenv('OPENROUTER_API_KEY');

        if (!$openaiKey) throw new Exception('Set OPENAI_API_KEY for embeddings.');
        if (!$orKey)     throw new Exception('Set OPENROUTER_API_KEY.');

        $db = getResearchDb();

        // 1) Embed the question
        $qVec        = embed_with_openai($question, $embedModel, $openaiKey);
        $qVecLiteral = floatsToVector($qVec);

        // 2) Hybrid retrieval

        // Sparse: BM25 via Postgres FTS
        $bm25Ids = ftsSparseTop100($db, $question, $bookIds);

        // Dense: pgvector ANN — top 100 by cosine distance
        $sql    = "SELECT id FROM chunks WHERE embedding IS NOT NULL";
        $params = [];
        if (!empty($bookIds)) {
            $in      = implode(',', array_fill(0, count($bookIds), '?'));
            $sql    .= " AND item_id IN ($in)";
            $params  = array_values($bookIds);
        }
        $sql    .= " ORDER BY embedding <=> ?::vector LIMIT 100";
        $params[] = $qVecLiteral;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $denseIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        // Fuse with RRF
        $fusedIds     = rrfFuse($bm25Ids, $denseIds, 60);
        $candidateIds = array_slice($fusedIds, 0, 200);

        // Load candidate rows with metadata + cosine similarity from pgvector
        $candidates = [];
        if (!empty($candidateIds)) {
            $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
            $sql = "SELECT
                c.id, c.item_id, c.section, c.page_start, c.page_end, c.text,
                c.display_start, c.display_end,
                i.title, i.author, i.year, COALESCE(i.display_offset,0) AS display_offset,
                i.library_book_id,
                CASE WHEN c.embedding IS NOT NULL
                     THEN 1 - (c.embedding <=> ?::vector)
                     ELSE 0.0 END AS sim
              FROM chunks c
              JOIN items i ON c.item_id = i.id
              WHERE c.id IN ($placeholders)";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge([$qVecLiteral], $candidateIds));
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3) Final ranking by similarity
        $top      = [];
        $bookIdSet = !empty($bookIds) ? array_fill_keys($bookIds, true) : null;
        foreach ($candidates as $row) {
            if ($bookIdSet !== null && !isset($bookIdSet[(int)$row['item_id']])) continue;
            $row['sim'] = (float)$row['sim'];
            $top[] = $row;
        }
        usort($top, fn($a,$b) => $b['sim'] <=> $a['sim']);

        // Group chunks by book
        $grouped = [];
        foreach ($top as $row) {
            $bid = $row['item_id'];
            if (!isset($grouped[$bid])) $grouped[$bid] = [];
            $grouped[$bid][] = $row;
        }

        // Take one chunk from each book (best similarity) up to minDistinct
        $firstChunks = [];
        foreach ($grouped as $rows) {
            $firstChunks[] = $rows[0];
        }
        usort($firstChunks, fn($a,$b) => $b['sim'] <=> $a['sim']);
        $initial  = array_slice($firstChunks, 0, $minDistinct);
        $selected = $initial;
        foreach ($initial as $r) {
            $bid = $r['item_id'];
            array_shift($grouped[$bid]);
            if (empty($grouped[$bid])) unset($grouped[$bid]);
        }

        // Fill remaining slots by highest-similarity chunks
        $remaining = $maxChunks - count($selected);
        if ($remaining > 0) {
            $rest = [];
            foreach ($grouped as $rows) {
                foreach ($rows as $r) $rest[] = $r;
            }
            usort($rest, fn($a,$b) => $b['sim'] <=> $a['sim']);
            $selected = array_merge($selected, array_slice($rest, 0, $remaining));
        }

        $top = $selected;

        if (empty($top) || $top[0]['sim'] < 0.25) {
            $answer = 'Not in library (retrieval too weak).';
        } else {
            // 4) Build grounded prompt
            $sysSimple = "
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

            $sysDetailed = "You are a research assistant.
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

            $sys = $simpleTerms ? $sysSimple : $sysDetailed;

            $ctx = '';
            foreach ($top as $i => $c) {
                $pdfStart = (int)$c['page_start'];
                $pdfEnd   = (int)$c['page_end'];

                $dispStart = (isset($c['display_start']) && $c['display_start'] !== null) ? (int)$c['display_start'] : null;
                $dispEnd   = (isset($c['display_end'])   && $c['display_end']   !== null) ? (int)$c['display_end']   : null;

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
            $answerModel = $modelName ?: 'anthropic/claude-sonnet-4.6';
            if (str_starts_with($answerModel, 'ollama/')) {
                $ollamaModel = substr($answerModel, strlen('ollama/'));
                $answer = generate_with_ollama($ollamaModel, $sys, $user, 0.1, $maxTokens);
            } else {
                $answer = generate_with_openrouter($answerModel, $sys, $user, $orKey, 0.1, $maxTokens);
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Lora:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/research-theme.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="ra-page">

  <!-- Header -->
  <header class="ra-header">
    <div class="ra-header-rule">Archive</div>
    <h1>Research Ask</h1>
    <p>Pose a question. Surface what the sources know.</p>
  </header>

  <!-- Form -->
  <form method="post" class="ra-form">

    <!-- Question -->
    <div class="ra-field">
      <label class="ra-label" for="question">Your Question</label>
      <textarea id="question" name="question" class="ra-question" rows="4"
        placeholder="What would you like to know from your library?" required><?= htmlspecialchars($_REQUEST['question'] ?? '') ?></textarea>
    </div>

    <!-- Main settings grid -->
    <div class="ra-settings-grid">

      <!-- Books -->
      <div class="ra-field ra-field--flush">
        <label class="ra-label">Sources</label>
        <button class="ra-books-btn" type="button" data-bs-toggle="modal" data-bs-target="#bookModal">
          <i class="fa-solid fa-book ra-icon-xs"></i> Select Books
        </button>
        <input type="hidden" id="book_id" name="book_id" value="<?= htmlspecialchars($_REQUEST['book_id'] ?? '') ?>">
        <div id="selectedBooksList"></div>
      </div>

      <!-- Model -->
      <div class="ra-field ra-field--flush">
        <label class="ra-label" for="model">Model</label>
        <div class="ra-select-wrap">
          <select id="model" name="model" class="ra-select">
            <option value="" <?= (($_REQUEST['model'] ?? '') === '') ? 'selected' : '' ?>>Auto (claude-sonnet-4.6)</option>
            <optgroup label="── Anthropic">
            <option value="anthropic/claude-opus-4.6" <?= (($_REQUEST['model'] ?? '') === 'anthropic/claude-opus-4.6') ? 'selected' : '' ?>>claude-opus-4.6</option>
            <option value="anthropic/claude-sonnet-4.6" <?= (($_REQUEST['model'] ?? '') === 'anthropic/claude-sonnet-4.6') ? 'selected' : '' ?>>claude-sonnet-4.6</option>
            </optgroup>
            <optgroup label="── OpenAI">
            <option value="openai/gpt-5.4" <?= (($_REQUEST['model'] ?? '') === 'openai/gpt-5.4') ? 'selected' : '' ?>>gpt-5.4</option>
            <option value="openai/gpt-5.4-mini" <?= (($_REQUEST['model'] ?? '') === 'openai/gpt-5.4-mini') ? 'selected' : '' ?>>gpt-5.4-mini</option>
            </optgroup>
            <optgroup label="── Google">
            <option value="google/gemini-3.1-pro-preview" <?= (($_REQUEST['model'] ?? '') === 'google/gemini-3.1-pro-preview') ? 'selected' : '' ?>>gemini-3.1-pro-preview</option>
            <option value="google/gemini-3.1-flash-lite-preview" <?= (($_REQUEST['model'] ?? '') === 'google/gemini-3.1-flash-lite-preview') ? 'selected' : '' ?>>gemini-3.1-flash-lite</option>
            </optgroup>
            <optgroup label="── xAI">
            <option value="x-ai/grok-4.20-beta" <?= (($_REQUEST['model'] ?? '') === 'x-ai/grok-4.20-beta') ? 'selected' : '' ?>>grok-4.20-beta</option>
            </optgroup>
            <optgroup label="── Mistral">
            <option value="mistralai/mistral-small-2603" <?= (($_REQUEST['model'] ?? '') === 'mistralai/mistral-small-2603') ? 'selected' : '' ?>>mistral-small-4</option>
            </optgroup>
            <optgroup label="── DeepSeek">
            <option value="deepseek/deepseek-r1-0528" <?= (($_REQUEST['model'] ?? '') === 'deepseek/deepseek-r1-0528') ? 'selected' : '' ?>>deepseek-r1-0528</option>
            <option value="deepseek/deepseek-v3.2" <?= (($_REQUEST['model'] ?? '') === 'deepseek/deepseek-v3.2') ? 'selected' : '' ?>>deepseek-v3.2</option>
            </optgroup>
            <optgroup label="── Free">
            <option value="meta-llama/llama-3.3-70b-instruct:free" <?= (($_REQUEST['model'] ?? '') === 'meta-llama/llama-3.3-70b-instruct:free') ? 'selected' : '' ?>>llama-3.3-70b (free)</option>
            <option value="qwen/qwen3-coder:free" <?= (($_REQUEST['model'] ?? '') === 'qwen/qwen3-coder:free') ? 'selected' : '' ?>>qwen3-coder (free)</option>
            </optgroup>
            <optgroup label="── Local (Ollama)">
            <option value="ollama/llama3:instruct" <?= (($_REQUEST['model'] ?? '') === 'ollama/llama3:instruct') ? 'selected' : '' ?>>llama3:instruct (local 8B)</option>
            <option value="ollama/llama3.2:latest" <?= (($_REQUEST['model'] ?? '') === 'ollama/llama3.2:latest') ? 'selected' : '' ?>>llama3.2 (local 3B)</option>
            <option value="ollama/qwen2.5:3b-instruct" <?= (($_REQUEST['model'] ?? '') === 'ollama/qwen2.5:3b-instruct') ? 'selected' : '' ?>>qwen2.5:3b (local 3B)</option>
            <option value="ollama/phi3:mini" <?= (($_REQUEST['model'] ?? '') === 'ollama/phi3:mini') ? 'selected' : '' ?>>phi3:mini (local 3.8B)</option>
            </optgroup>
          </select>
        </div>
      </div>

    </div><!-- /settings-grid -->

    <!-- Toggles -->
    <div class="ra-toggles">
      <label class="ra-toggle-label">
        <input type="checkbox" id="show_pdf_pages" name="show_pdf_pages" value="1" <?= (!empty($_REQUEST['show_pdf_pages'] ?? '')) ? 'checked' : '' ?>>
        <span class="ra-toggle-track"></span>
        Show PDF pages
      </label>
      <label class="ra-toggle-label">
        <input type="checkbox" id="simple_terms" name="simple_terms" value="1" <?= (!empty($_REQUEST['simple_terms'] ?? '')) ? 'checked' : '' ?>>
        <span class="ra-toggle-track"></span>
        In simple terms
      </label>
    </div>

    <!-- Advanced -->
    <details class="ra-advanced">
      <summary>Advanced options</summary>
      <div class="ra-advanced-grid">
        <div class="ra-field ra-field--flush">
          <label class="ra-label" for="max_chunks">Max Chunks</label>
          <input type="number" id="max_chunks" name="max_chunks" class="ra-input"
            value="<?= htmlspecialchars($_REQUEST['max_chunks'] ?? '8') ?>">
        </div>
        <div class="ra-field ra-field--flush">
          <label class="ra-label" for="max_tokens">Max Tokens</label>
          <input type="number" id="max_tokens" name="max_tokens" class="ra-input"
            value="<?= htmlspecialchars($_REQUEST['max_tokens'] ?? '2000') ?>">
        </div>
        <div class="ra-field ra-field--flush">
          <label class="ra-label" for="min_distinct">Min Distinct Books</label>
          <input type="number" id="min_distinct" name="min_distinct" class="ra-input"
            value="<?= htmlspecialchars($_REQUEST['min_distinct'] ?? '3') ?>">
        </div>
      </div>
    </details>

    <button type="submit" class="ra-submit" id="askSubmitBtn">Ask the Archive</button>

  </form>

  <!-- Error -->
  <?php if ($error): ?>
  <div class="ra-error"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Answer -->
  <?php if ($answer): ?>
  <section class="ra-answer-section">
    <div class="ra-answer-header">
      <h2>Answer</h2>
      <button class="ra-save-btn" type="button" id="saveNoteBtn">
        <i class="fa-solid fa-floppy-disk"></i> Save to Notes
      </button>
    </div>
    <div id="answer-md"></div>
  </section>
  <?php endif; ?>

  <!-- Sources -->
  <?php if ($sources): ?>
  <section class="ra-sources-section">
    <div class="ra-sources-header">
      <h2>Sources consulted</h2>
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

<!-- Book selection modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-labelledby="bookModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bookModalLabel">Select Sources</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="bookSearch" class="form-control mb-3" placeholder="Filter by title or author…">
        <div class="list-group" id="bookList">
          <?php foreach ($bookList as $b): ?>
            <label class="list-group-item d-flex align-items-center gap-2">
              <input class="book-checkbox" type="checkbox" value="<?= (int)$b['id'] ?>" data-title="<?= htmlspecialchars($b['title']) ?>">
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
        <button type="button" class="btn btn-primary" id="applyBooks" data-bs-dismiss="modal">Apply Selection</button>
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
  const questionFirstLine = <?= json_encode(preg_split('/\r?\n/', $question)[0] ?? '') ?>;
  const sourcesHTML = <?= json_encode($sources_note_html) ?>;
  document.getElementById('answer-md').innerHTML = answerHTML;
  document.querySelector('.ra-answer-section').scrollIntoView({ behavior: 'smooth', block: 'start' });

  (async () => {
    const saveBtn = document.getElementById('saveNoteBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const params = new URLSearchParams();
    const selectedBookTitles = <?= json_encode($selectedBookTitles) ?>;
    const bookSuffix = selectedBookTitles.length ? ' [' + selectedBookTitles.join(', ') + ']' : '';
    params.append('mode', 'new');
    params.append('title', (questionFirstLine.trim() || 'Research Answer') + bookSuffix);
    params.append('text', `<h1>${DOMPurify.sanitize(questionFirstLine)}</h1>` + answerHTML + sourcesHTML);
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
const bookTitleMap = <?= json_encode(array_column($bookList, 'title', 'id')) ?>;
let selectedBooks = {};

function renderSelectedBooks() {
  const container = document.getElementById('selectedBooksList');
  const ids = Object.keys(selectedBooks);
  if (ids.length === 0) {
    container.innerHTML = '<span class="ra-all-books">All ingested books</span>';
    return;
  }
  container.innerHTML = ids.map(id =>
    `<span class="ra-book-tag">
      ${selectedBooks[id]}
      <button type="button" class="ra-book-tag-remove" data-id="${id}" aria-label="Remove">✕</button>
    </span>`
  ).join('');
  container.querySelectorAll('[data-id]').forEach(btn => {
    btn.addEventListener('click', () => {
      delete selectedBooks[btn.dataset.id];
      const cb = document.querySelector(`.book-checkbox[value="${btn.dataset.id}"]`);
      if (cb) cb.checked = false;
      document.getElementById('book_id').value = Object.keys(selectedBooks).join(',');
      renderSelectedBooks();
    });
  });
}

(function() {
  const existing = document.getElementById('book_id').value;
  if (!existing) { renderSelectedBooks(); return; }
  existing.split(',').map(s => s.trim()).filter(Boolean).forEach(id => {
    if (bookTitleMap[id] !== undefined) selectedBooks[id] = bookTitleMap[id];
  });
  renderSelectedBooks();
})();

document.getElementById('bookModal').addEventListener('show.bs.modal', () => {
  document.querySelectorAll('.book-checkbox').forEach(cb => {
    cb.checked = !!selectedBooks[cb.value];
  });
});

document.getElementById('applyBooks').addEventListener('click', () => {
  selectedBooks = {};
  document.querySelectorAll('.book-checkbox:checked').forEach(cb => {
    selectedBooks[cb.value] = cb.dataset.title;
  });
  document.getElementById('book_id').value = Object.keys(selectedBooks).join(',');
  renderSelectedBooks();
});

document.getElementById('bookSearch').addEventListener('input', e => {
  const term = e.target.value.toLowerCase();
  document.querySelectorAll('#bookList label').forEach(lbl => {
    lbl.style.display = lbl.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
});

document.querySelector('form').addEventListener('submit', () => {
  const btn = document.getElementById('askSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = `
    <span class="ra-loading">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83">
          <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite"/>
        </path>
      </svg>
      Searching the Archive…
    </span>`;
});
</script>
</body>
</html>
<?php
function embed_with_openai(string $text, string $model, string $apiKey): array {
  $payload = ['model'=>$model, 'input'=>$text];
  $res = http_post_json('https://api.openai.com/v1/embeddings', $payload, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
  ]);
  return $res['data'][0]['embedding'] ?? [];
}
function generate_with_ollama(string $model, string $system, string $user, float $temp=0.1, int $maxTokens=2000): string {
  $payload = [
    'model' => $model,
    'messages' => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user]
    ],
    'temperature' => $temp,
    'max_tokens'  => $maxTokens,
    'stream'      => false,
  ];
  $res = http_post_json('http://localhost:11434/v1/chat/completions', $payload, [
    'Content-Type: application/json',
  ]);
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
        $encoded = implode('/', array_map(function ($seg) {
          $enc = rawurlencode($seg);
          return str_replace(['%28','%29'], ['(',')'], $enc);
        }, explode('/', $rel)));
        return getLibraryWebPath() . '/' . $encoded;
      }
    }

    if (is_dir($dir)) {
      $files = glob($dir . '/*.pdf');
      if ($files) {
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
    // ignore
  }
  return null;
}
?>
