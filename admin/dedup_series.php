<?php
require_once '../db.php';
requireLogin();

$pdo = getDatabaseConnection();

/**
 * Normalize a series name for grouping:
 *  - lowercase
 *  - strip leading articles (the / a / an)
 *  - remove punctuation (apostrophes, colons, dashes, etc.)
 *  - collapse whitespace
 *
 * Examples:
 *   "The Locked Tomb"     → "locked tomb"
 *   "Locked Tomb"         → "locked tomb"
 *   "Wheel of Time, The"  → "wheel of time the"
 *   "Disc World"          → "disc world"
 *   "Discworld"           → "discworld"
 */
function seriesDedupKey(string $name): string {
    $s = mb_strtolower($name);
    $s = preg_replace('/^(the|a|an)\s+/u', '', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// All series with book counts and a comma-separated author list
$rows = $pdo->query("
    SELECT s.id, s.name,
           COUNT(DISTINCT bsl.book) AS book_count,
           GROUP_CONCAT(DISTINCT a.name) AS authors
    FROM series s
    LEFT JOIN books_series_link bsl ON bsl.series = s.id
    LEFT JOIN books_authors_link bal ON bal.book = bsl.book
    LEFT JOIN authors a ON a.id = bal.author
    GROUP BY s.id, s.name
    ORDER BY s.name COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

// Group by exact normalized key
$buckets = [];
foreach ($rows as $row) {
    $key = seriesDedupKey($row['name']);
    $buckets[$key][] = $row;
}

// Fuzzy-merge buckets whose keys are within Levenshtein distance 2
// (catches "Disc World" vs "Discworld", small typos)
// Uses a simple union-find approach.
$keys   = array_keys($buckets);
$parent = array_combine($keys, $keys);

function ufFind(array &$parent, string $x): string {
    while ($parent[$x] !== $x) {
        $parent[$x] = $parent[$parent[$x]];
        $x = $parent[$x];
    }
    return $x;
}

$n = count($keys);
for ($i = 0; $i < $n; $i++) {
    $ka = $keys[$i];
    if (strlen($ka) < 4) continue; // skip very short keys — too many false positives
    for ($j = $i + 1; $j < $n; $j++) {
        $kb = $keys[$j];
        if (abs(strlen($ka) - strlen($kb)) > 3) continue; // quick length filter
        if (levenshtein($ka, $kb) <= 2) {
            $ra = ufFind($parent, $ka);
            $rb = ufFind($parent, $kb);
            if ($ra !== $rb) $parent[$rb] = $ra;
        }
    }
}

$merged = [];
foreach ($buckets as $key => $members) {
    $root = ufFind($parent, $key);
    foreach ($members as $m) $merged[$root][] = $m;
}
$buckets = $merged;

// Only keep groups with 2+ distinct series entries
$groups = array_filter($buckets, fn($g) => count($g) > 1);

// Within each group: most books first (= suggested keep)
foreach ($groups as &$g) {
    usort($g, fn($a, $b) => $b['book_count'] - $a['book_count'] ?: strcmp($a['name'], $b['name']));
}
unset($g);

// Sort groups: most total books first
uasort($groups, function ($a, $b) {
    $ta = array_sum(array_column($a, 'book_count'));
    $tb = array_sum(array_column($b, 'book_count'));
    return $tb - $ta ?: strcmp($a[0]['name'], $b[0]['name']);
});

$totalGroups = count($groups);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Deduplicate Series</title>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
  <style>
    .dup-group {
      border: 1px solid var(--bs-border-color);
      border-radius: .5rem;
      margin-bottom: 1rem;
      overflow: hidden;
    }
    .dup-group-header {
      background: var(--bs-tertiary-bg);
      padding: .45rem .75rem;
      font-size: .75rem;
      color: var(--bs-secondary-color);
      font-family: monospace;
      letter-spacing: .02em;
    }
    .dup-series-row {
      display: flex;
      align-items: center;
      gap: .6rem;
      padding: .4rem .75rem;
      border-top: 1px solid var(--bs-border-color);
      flex-wrap: wrap;
    }
    .dup-series-row:first-of-type { border-top: none; }
    .dup-series-name { flex: 1; font-size: .9rem; min-width: 0; }
    .dup-series-authors { font-size: .72rem; color: var(--bs-secondary-color); }
    .dup-book-count { font-size: .75rem; color: var(--bs-secondary-color); white-space: nowrap; }
    .merge-result { font-size: .8rem; }
    .merge-result.ok   { color: var(--bs-success-text-emphasis); }
    .merge-result.warn { color: var(--bs-warning-text-emphasis); }
    .merge-result.err  { color: var(--bs-danger-text-emphasis); }
    .dup-group.merged  { opacity: .45; pointer-events: none; }
  </style>
</head>
<body style="padding-top:80px">
<?php include '../navbar.php'; ?>

<div class="container my-4" style="max-width:860px">
  <div class="d-flex align-items-center gap-3 mb-1 flex-wrap">
    <h2 class="mb-0"><i class="fa-solid fa-layer-group me-2"></i>Deduplicate Series</h2>
    <span class="badge bg-secondary"><?= $totalGroups ?> group<?= $totalGroups !== 1 ? 's' : '' ?></span>
  </div>
  <p class="text-muted small mb-4">
    Series whose names differ only by a leading article or minor punctuation/spelling
    (e.g. <em>The Locked Tomb</em> / <em>Locked Tomb</em>).
    Select which name to keep and click <strong>Merge</strong> — all books are moved automatically.
  </p>

<?php if ($totalGroups === 0): ?>
  <div class="alert alert-success">
    <i class="fa-solid fa-circle-check me-2"></i>No duplicate series variants found.
  </div>
<?php else: ?>
  <div class="mb-3 d-flex align-items-center gap-2">
    <input type="text" id="filterInput" class="form-control form-control-sm"
           placeholder="Filter series…" style="max-width:260px" autocomplete="off">
    <span class="text-muted small" id="visibleCount"><?= $totalGroups ?> shown</span>
  </div>

  <div id="groupList">
  <?php
  $gIdx = 0;
  foreach ($groups as $key => $members):
    $totalBooks = array_sum(array_column($members, 'book_count'));
    $gIdx++;
  ?>
  <div class="dup-group"
       data-group-names="<?= htmlspecialchars(strtolower(implode(' ', array_column($members, 'name'))), ENT_QUOTES) ?>">
    <div class="dup-group-header">
      key: <strong><?= htmlspecialchars($key) ?></strong>
      <span class="ms-2 opacity-75"><?= $totalBooks ?> book<?= $totalBooks !== 1 ? 's' : '' ?> total</span>
    </div>
    <?php foreach ($members as $mi => $m): ?>
    <div class="dup-series-row">
      <input type="radio"
             class="form-check-input flex-shrink-0 keep-radio"
             name="keep_<?= $gIdx ?>"
             value="<?= (int)$m['id'] ?>"
             data-name="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>"
             <?= $mi === 0 ? 'checked' : '' ?>>
      <span class="dup-series-name">
        <a href="/list_books.php?series_id=<?= (int)$m['id'] ?>"
           class="text-decoration-none" target="_blank">
          <?= htmlspecialchars($m['name']) ?>
        </a>
        <?php if ($mi === 0): ?>
          <span class="badge bg-success-subtle text-success-emphasis ms-1" style="font-size:.65rem">suggested</span>
        <?php endif; ?>
        <?php if ($m['authors']): ?>
          <span class="dup-series-authors ms-1">— <?= htmlspecialchars($m['authors']) ?></span>
        <?php endif; ?>
      </span>
      <span class="dup-book-count">
        <?= (int)$m['book_count'] ?> book<?= $m['book_count'] != 1 ? 's' : '' ?>
      </span>
    </div>
    <?php endforeach; ?>
    <div class="dup-series-row justify-content-between">
      <button class="btn btn-sm btn-primary merge-btn"
              data-group="<?= $gIdx ?>"
              data-member-ids="<?= htmlspecialchars(implode(',', array_column($members, 'id')), ENT_QUOTES) ?>">
        <i class="fa-solid fa-code-merge me-1"></i>Merge
      </button>
      <span class="merge-result" id="result_<?= $gIdx ?>"></span>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const filterInput  = document.getElementById('filterInput');
const visibleCount = document.getElementById('visibleCount');

function applyFilter() {
  const q = filterInput ? filterInput.value.toLowerCase() : '';
  let shown = 0;
  document.querySelectorAll('.dup-group').forEach(g => {
    const match = !q || g.dataset.groupNames.includes(q);
    g.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  if (visibleCount) visibleCount.textContent = shown + ' shown';
}
filterInput?.addEventListener('input', applyFilter);

document.querySelectorAll('.merge-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const gIdx      = btn.dataset.group;
    const allIds    = btn.dataset.memberIds.split(',').map(Number);
    const keepRadio = document.querySelector(`input[name="keep_${gIdx}"]:checked`);
    if (!keepRadio) return;

    const keepId   = parseInt(keepRadio.value, 10);
    const mergeIds = allIds.filter(id => id !== keepId);
    if (!mergeIds.length) return;

    const resultEl = document.getElementById('result_' + gIdx);
    btn.disabled = true;
    resultEl.className = 'merge-result';
    resultEl.textContent = 'Merging…';

    const fd = new FormData();
    fd.append('keep_id', keepId);
    mergeIds.forEach(id => fd.append('merge_ids[]', id));

    try {
      const res  = await fetch('../json_endpoints/merge_series.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        resultEl.className = 'merge-result ok';
        resultEl.textContent = `✓ Merged — ${data.books_moved} book${data.books_moved !== 1 ? 's' : ''} moved`;
        btn.closest('.dup-group').classList.add('merged');
        applyFilter();
      } else {
        resultEl.className = 'merge-result err';
        resultEl.textContent = '✗ ' + (data.error || 'Unknown error');
        btn.disabled = false;
      }
    } catch {
      resultEl.className = 'merge-result err';
      resultEl.textContent = '✗ Request failed';
      btn.disabled = false;
    }
  });
});
</script>
</body>
</html>
