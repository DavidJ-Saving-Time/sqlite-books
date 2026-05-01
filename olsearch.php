<?php
require_once 'db.php';
requireLogin();

$qTitle  = trim($_GET['title']  ?? '');
$qAuthor = trim($_GET['author'] ?? '');
$searched = $qTitle !== '' || $qAuthor !== '';

// ── Query helpers ─────────────────────────────────────────────────────────────

function olSearch(PDO $pg, string $title, string $author): array {
    $hasTitle  = $title  !== '';
    $hasAuthor = $author !== '';
    if (!$hasTitle && !$hasAuthor) return [];

    // Words ≥3 chars for title ILIKE conditions
    $titleWords = $hasTitle ? array_values(array_filter(
        preg_split('/\s+/', $title), fn($w) => strlen($w) >= 3
    )) : [];

    // All non-empty parts from author for PHP-side filtering (≥2 chars)
    $authorWords = $hasAuthor ? array_values(array_filter(
        preg_split('/[\s.]+/', strtolower($author)), fn($w) => strlen($w) >= 2
    )) : [];

    // ── Strategy selection ────────────────────────────────────────────────────
    // Title + Author → author-first: get that author's work keys (bounded, indexed),
    //   then fetch+PHP-filter by title words. Avoids LIMIT-100 random truncation on
    //   common title words like "The Stand".
    // Title only  → title-first GIN scan, LIMIT 100.
    // Author only → author-first, return all works up to 30.

    if ($hasTitle && $hasAuthor) {
        // Phase 1: author → work keys
        $parts = array_values(array_filter(
            preg_split('/[\s.]+/', strtolower($author)), fn($p) => $p !== ''
        ));
        if (empty($parts)) return [];
        $firstIsInitial = strlen($parts[0]) === 1;
        $authorPattern  = ($firstIsInitial ? '' : '%') . implode('%', $parts) . '%';

        $stmt = $pg->prepare("
            SELECT aw.work_key
            FROM authors a JOIN author_works aw ON aw.author_key = a.key
            WHERE a.data->>'name' ILIKE ?
            LIMIT 1000
        ");
        $stmt->execute([$authorPattern]);
        $workKeys = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'work_key');
        if (empty($workKeys)) return [];

        // Phase 2: fetch all those works
        $ph   = implode(',', array_fill(0, count($workKeys), '?'));
        $stmt = $pg->prepare("
            SELECT w.key,
                   w.data->>'title'              AS title,
                   w.data->>'first_publish_date' AS year,
                   w.data->>'covers'             AS covers,
                   w.data->>'description'        AS description,
                   w.data->>'subjects'           AS subjects
            FROM works w WHERE w.key IN ({$ph})
        ");
        $stmt->execute($workKeys);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        // Phase 3: PHP title filter by significant words (≥3 chars)
        if (!empty($titleWords)) {
            $rows = array_values(array_filter($rows, function ($row) use ($titleWords) {
                $t = strtolower($row['title'] ?? '');
                foreach ($titleWords as $w) {
                    if (stripos($t, $w) === false) return false;
                }
                return true;
            }));
        }
        if (empty($rows)) return [];

        // Phase 4: batch-fetch authors for surviving rows
        $ph    = implode(',', array_fill(0, count($rows), '?'));
        $aStmt = $pg->prepare("
            SELECT aw.work_key, a.data->>'name' AS name
            FROM author_works aw JOIN authors a ON a.key = aw.author_key
            WHERE aw.work_key IN ({$ph})
        ");
        $aStmt->execute(array_column($rows, 'key'));
        $authorMap = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $authorMap[$ar['work_key']][] = $ar['name'];
        }

        return array_slice(array_map(fn($row) => formatRow($row, $authorMap), $rows), 0, 30);
    }

    if ($hasTitle) {
        // Title-only: GIN trigram scan — LIMIT 100 is fine since no author to pin results
        $where  = [];
        $params = [];
        foreach ($titleWords as $w) {
            $where[]  = "w.data->>'title' ILIKE ?";
            $params[] = '%' . $w . '%';
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $pg->prepare("
            SELECT w.key,
                   w.data->>'title'              AS title,
                   w.data->>'first_publish_date' AS year,
                   w.data->>'covers'             AS covers,
                   w.data->>'description'        AS description,
                   w.data->>'subjects'           AS subjects
            FROM works w {$whereSQL} LIMIT 100
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        $ph = implode(',', array_fill(0, count($rows), '?'));
        $aStmt = $pg->prepare("
            SELECT aw.work_key, a.data->>'name' AS name
            FROM author_works aw JOIN authors a ON a.key = aw.author_key
            WHERE aw.work_key IN ({$ph})
        ");
        $aStmt->execute(array_column($rows, 'key'));
        $authorMap = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $authorMap[$ar['work_key']][] = $ar['name'];
        }

        return array_slice(array_map(fn($row) => formatRow($row, $authorMap), $rows), 0, 30);
    }

    // ── Author-only: author pattern → work keys → fetch works ────────────────
    $parts = array_values(array_filter(
        preg_split('/[\s.]+/', strtolower($author)), fn($p) => $p !== ''
    ));
    if (empty($parts)) return [];
    $firstIsInitial = strlen($parts[0]) === 1;
    $authorPattern  = ($firstIsInitial ? '' : '%') . implode('%', $parts) . '%';

    $stmt = $pg->prepare("
        SELECT aw.work_key
        FROM authors a JOIN author_works aw ON aw.author_key = a.key
        WHERE a.data->>'name' ILIKE ?
        LIMIT 500
    ");
    $stmt->execute([$authorPattern]);
    $workKeys = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'work_key');
    if (empty($workKeys)) return [];

    $ph = implode(',', array_fill(0, count($workKeys), '?'));
    $stmt = $pg->prepare("
        SELECT w.key,
               w.data->>'title'              AS title,
               w.data->>'first_publish_date' AS year,
               w.data->>'covers'             AS covers,
               w.data->>'description'        AS description,
               w.data->>'subjects'           AS subjects
        FROM works w WHERE w.key IN ({$ph}) LIMIT 30
    ");
    $stmt->execute($workKeys);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return [];

    $ph = implode(',', array_fill(0, count($rows), '?'));
    $aStmt = $pg->prepare("
        SELECT aw.work_key, a.data->>'name' AS name
        FROM author_works aw JOIN authors a ON a.key = aw.author_key
        WHERE aw.work_key IN ({$ph})
        LIMIT 300
    ");
    $aStmt->execute(array_column($rows, 'key'));
    $authorMap = [];
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
        $authorMap[$ar['work_key']][] = $ar['name'];
    }

    return array_map(fn($row) => formatRow($row, $authorMap), $rows);
}

function formatRow(array $row, array $authorMap): array {
    $covers = json_decode($row['covers'] ?? '[]', true) ?: [];
    $coverId = '';
    foreach ($covers as $cid) {
        if ((int)$cid > 0) { $coverId = (string)$cid; break; }
    }
    $description = '';
    $rawDesc = $row['description'] ?? '';
    if ($rawDesc !== '') {
        $d = json_decode($rawDesc, true);
        $description = is_array($d) ? ($d['value'] ?? '') : $rawDesc;
    }
    $year = '';
    if ($row['year'] && preg_match('/(\d{4})/', $row['year'], $m)) $year = $m[1];
    return [
        'key'         => $row['key'],
        'title'       => $row['title'] ?? '',
        'authors'     => $authorMap[$row['key']] ?? [],
        'year'        => $year,
        'cover_id'    => $coverId,
        'description' => $description,
        'subjects'    => array_slice(json_decode($row['subjects'] ?? '[]', true) ?: [], 0, 8),
    ];
}

// ── Run search ────────────────────────────────────────────────────────────────

$results = [];
$elapsed = null;
$error   = null;

if ($searched) {
    try {
        $pg = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
        $pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pg->exec("SET statement_timeout = '30000'"); // 30 s — HDD mirror
        $t0 = microtime(true);
        $results = olSearch($pg, $qTitle, $qAuthor);
        $elapsed = round((microtime(true) - $t0) * 1000);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'canceling statement') || str_contains($e->getMessage(), 'timeout')) {
            $error = 'Search timed out (30s) — try adding an author name or use a more distinctive title word.';
        } else {
            $error = $e->getMessage();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Local Open Library Search</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container-fluid" style="padding-top:5rem">

  <!-- Search form -->
  <div class="row justify-content-center mb-4">
    <div class="col-lg-8">
      <h5 class="mb-3">
        <i class="fa-solid fa-database me-2 text-muted"></i>Local Open Library Mirror
      </h5>
      <form method="get" action="olsearch.php" class="d-flex flex-column gap-2">
        <div class="input-group">
          <span class="input-group-text"><i class="fa-solid fa-book"></i></span>
          <input type="text" name="title" class="form-control" placeholder="Title…" value="<?= htmlspecialchars($qTitle) ?>" autocomplete="off">
        </div>
        <div class="input-group">
          <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
          <input type="text" name="author" class="form-control" placeholder="Author…" value="<?= htmlspecialchars($qAuthor) ?>" autocomplete="off">
        </div>
        <div>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</button>
          <?php if ($searched): ?>
            <a href="olsearch.php" class="btn btn-outline-secondary ms-2">Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
      </div>
    </div>
  <?php elseif ($searched): ?>
  <div class="row justify-content-center">
    <div class="col-lg-8">

      <!-- Result count -->
      <p class="text-muted small mb-3">
        <?php if (empty($results)): ?>
          No results found.
        <?php else: ?>
          <?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?>
          <?php if (count($results) === 30): ?>(showing first 30)<?php endif; ?>
          — <?= $elapsed ?>ms
        <?php endif; ?>
      </p>

      <?php foreach ($results as $book): ?>
        <?php
          $olKey  = $book['key'];
          $olId   = preg_replace('/[^A-Za-z0-9]/', '', basename($olKey));
          $olUrl  = 'https://openlibrary.org' . $olKey;
          $imgUrl = $book['cover_id']
              ? 'https://covers.openlibrary.org/b/id/' . $book['cover_id'] . '-M.jpg'
              : '';
        ?>
        <div class="card mb-3">
          <div class="card-body d-flex gap-3">

            <!-- Cover -->
            <div class="flex-shrink-0" style="width:80px">
              <?php if ($imgUrl): ?>
                <img src="<?= htmlspecialchars($imgUrl) ?>" class="img-fluid rounded" alt="" style="max-height:110px;width:auto;object-fit:cover">
              <?php else: ?>
                <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="height:110px;width:72px">
                  <i class="fa-solid fa-book text-muted"></i>
                </div>
              <?php endif; ?>
            </div>

            <!-- Details -->
            <div class="flex-grow-1 min-width-0">
              <div class="fw-semibold">
                <a href="<?= htmlspecialchars($olUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($book['title']) ?></a>
              </div>

              <?php if (!empty($book['authors'])): ?>
                <div class="text-muted small"><?= htmlspecialchars(implode(', ', $book['authors'])) ?></div>
              <?php endif; ?>

              <?php if ($book['year']): ?>
                <div class="text-muted small"><?= htmlspecialchars($book['year']) ?></div>
              <?php endif; ?>

              <?php if ($book['description'] !== ''): ?>
                <p class="small mt-1 mb-1" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">
                  <?= htmlspecialchars($book['description']) ?>
                </p>
              <?php endif; ?>

              <?php if (!empty($book['subjects'])): ?>
                <div class="mt-1">
                  <?php foreach ($book['subjects'] as $s): ?>
                    <span class="badge bg-secondary me-1 mb-1"><?= htmlspecialchars($s) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <!-- Action buttons -->
              <div class="mt-2 d-flex gap-2 flex-wrap">
                <a href="<?= htmlspecialchars($olUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                  <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open Library
                </a>
                <button type="button" class="btn btn-sm btn-outline-primary js-use-ol"
                        data-olid="<?= htmlspecialchars($olId, ENT_QUOTES) ?>"
                        data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                        data-authors="<?= htmlspecialchars(implode(', ', $book['authors']), ENT_QUOTES) ?>"
                        data-cover="<?= htmlspecialchars($imgUrl, ENT_QUOTES) ?>"
                        data-description="<?= htmlspecialchars($book['description'], ENT_QUOTES) ?>">
                  <i class="fa-solid fa-database me-1"></i>Use for book…
                </button>
              </div>
            </div>

          </div>
        </div>
      <?php endforeach; ?>

    </div>
  </div>
  <?php endif; ?>

</div>

<!-- "Use for book" modal -->
<div class="modal fade" id="useForBookModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Apply to library book</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Search your library for the book to update:</p>
        <input type="text" id="useBookSearch" class="form-control mb-3" placeholder="Type title or author…" autocomplete="off">
        <div id="useBookResults" class="list-group" style="max-height:300px;overflow-y:auto"></div>
        <div id="useBookActions" class="mt-3 d-none">
          <p class="small text-muted mb-2">Selected: <strong id="useBookSelectedTitle"></strong></p>
          <div class="d-flex gap-2 flex-wrap" id="useBookBtns"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
<script>
(function () {
    const modal     = new bootstrap.Modal(document.getElementById('useForBookModal'));
    const searchEl  = document.getElementById('useBookSearch');
    const resultsEl = document.getElementById('useBookResults');
    const actionsEl = document.getElementById('useBookActions');
    const btnsEl    = document.getElementById('useBookBtns');
    const selTitle  = document.getElementById('useBookSelectedTitle');

    let pendingOl = {};  // the OL data to apply
    let selectedBookId = null;

    // Open modal when "Use for book…" clicked
    document.addEventListener('click', e => {
        const btn = e.target.closest('.js-use-ol');
        if (!btn) return;
        pendingOl = {
            olid:        btn.dataset.olid,
            title:       btn.dataset.title,
            authors:     btn.dataset.authors,
            cover:       btn.dataset.cover,
            description: btn.dataset.description,
        };
        selectedBookId = null;
        searchEl.value = btn.dataset.title || '';
        resultsEl.innerHTML = '';
        actionsEl.classList.add('d-none');
        modal.show();
        searchEl.focus();
        if (searchEl.value) doSearch(searchEl.value);
    });

    // Live search within library
    let searchTimer;
    searchEl.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => doSearch(searchEl.value.trim()), 300);
    });

    function doSearch(q) {
        if (!q) { resultsEl.innerHTML = ''; return; }
        resultsEl.innerHTML = '<div class="list-group-item text-muted small">Searching…</div>';
        fetch('json_endpoints/library_book_search.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.books || !data.books.length) {
                    resultsEl.innerHTML = '<div class="list-group-item text-muted small">No matches</div>';
                    return;
                }
                resultsEl.innerHTML = data.books.map(b =>
                    `<button type="button" class="list-group-item list-group-item-action small js-pick-book"
                             data-book-id="${b.id}" data-book-title="${escHTML(b.title)}">
                       <strong>${escHTML(b.title)}</strong>
                       <span class="text-muted ms-2">${escHTML(b.authors || '')}</span>
                     </button>`
                ).join('');
            })
            .catch(() => { resultsEl.innerHTML = '<div class="list-group-item text-danger small">Error</div>'; });
    }

    // Pick a library book
    resultsEl.addEventListener('click', e => {
        const btn = e.target.closest('.js-pick-book');
        if (!btn) return;
        selectedBookId = btn.dataset.bookId;
        selTitle.textContent = btn.dataset.bookTitle;
        actionsEl.classList.remove('d-none');

        const btns = [];
        if (pendingOl.cover)
            btns.push(makeApplyBtn('<i class="fa-solid fa-image me-1"></i>Use Cover',       'primary',   { imgurl: pendingOl.cover }));
        if (pendingOl.description)
            btns.push(makeApplyBtn('<i class="fa-solid fa-align-left me-1"></i>Use Desc',   'secondary', { description: pendingOl.description }));
        if (pendingOl.cover && pendingOl.description)
            btns.push(makeApplyBtn('<i class="fa-solid fa-circle-check me-1"></i>Use Both', 'success',   { imgurl: pendingOl.cover, description: pendingOl.description }));
        if (pendingOl.olid)
            btns.push(makeApplyBtn('<i class="fa-solid fa-key me-1"></i>Use OLID',          'secondary', { olid: pendingOl.olid }));
        btnsEl.innerHTML = '';
        btns.forEach(b => btnsEl.appendChild(b));
    });

    function makeApplyBtn(label, variant, payload) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = `btn btn-sm btn-outline-${variant}`;
        b.innerHTML = label;
        b.addEventListener('click', () => applyToBook(payload, b));
        return b;
    }

    function applyToBook(payload, btn) {
        if (!selectedBookId) return;
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        const form = new URLSearchParams({ book_id: selectedBookId, ...payload });
        fetch('json_endpoints/update_metadata.php', {
            method: 'POST',
            body: form,
        })
        .then(r => r.json())
        .then(d => {
            btn.innerHTML = d.success
                ? '<i class="fa-solid fa-check me-1"></i>Done'
                : '<i class="fa-solid fa-xmark me-1"></i>Failed';
        })
        .catch(() => { btn.innerHTML = '<i class="fa-solid fa-xmark me-1"></i>Error'; })
        .finally(() => { btn.disabled = false; });
    }

    function escHTML(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
}());
</script>
</body>
</html>
