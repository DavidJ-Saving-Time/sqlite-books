<?php
require_once '../db.php';
requireLogin();

$pdo = getDatabaseConnection();

/**
 * Compute a grouping key for an author name by:
 *  - removing periods
 *  - expanding run-together initials (JK → J K, RA → R A)
 *  - stripping single-letter middle initials
 *  - lowercasing
 *
 * Examples:
 *   "Robert R. McCammon" → "robert mccammon"
 *   "J. K. Rowling"      → "j rowling"
 *   "JK Rowling"         → "j rowling"
 *   "R.A. Salvatore"     → "r salvatore"
 *   "George R. R. Martin"→ "george martin"
 */
function authorDedupKey(string $name): string {
    $s = str_replace('.', ' ', $name);
    // Expand 2-4 consecutive uppercase letters standing alone as a word (e.g. "JK" → "J K")
    $s = preg_replace_callback('/\b([A-Z]{2,4})\b/', fn($m) => implode(' ', str_split($m[1])), $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));

    $parts = array_values(array_filter(explode(' ', $s), 'strlen'));
    if (count($parts) <= 1) return strtolower($s);

    $first   = array_shift($parts);
    $last    = array_pop($parts);
    // Keep multi-letter middle words; drop single-letter initials
    $middles = array_values(array_filter($parts, fn($p) => strlen($p) > 1));

    $kept = array_merge([$first], $middles, [$last]);
    return strtolower(implode(' ', $kept));
}

// Load all authors with book counts
$rows = $pdo->query("
    SELECT a.id, a.name, COUNT(bal.book) AS book_count
    FROM authors a
    LEFT JOIN books_authors_link bal ON bal.author = a.id
    GROUP BY a.id, a.name
    ORDER BY a.name
")->fetchAll(PDO::FETCH_ASSOC);

// Group by normalized key; only keep groups with 2+ variants
$buckets = [];
foreach ($rows as $row) {
    $key = authorDedupKey($row['name']);
    $buckets[$key][] = $row;
}
$groups = array_filter($buckets, fn($g) => count($g) > 1);

// Within each group: sort by book count desc (most-books first = suggested keep)
foreach ($groups as &$g) {
    usort($g, fn($a, $b) => $b['book_count'] - $a['book_count'] ?: strcmp($a['name'], $b['name']));
}
unset($g);

// Sort groups by total books desc so most-relevant appear first
uasort($groups, function($a, $b) {
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
  <title>Deduplicate Authors</title>
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
    .dup-author-row {
      display: flex;
      align-items: center;
      gap: .6rem;
      padding: .4rem .75rem;
      border-top: 1px solid var(--bs-border-color);
    }
    .dup-author-row:first-of-type { border-top: none; }
    .dup-author-name { flex: 1; font-size: .9rem; }
    .dup-book-count { font-size: .75rem; color: var(--bs-secondary-color); white-space: nowrap; }
    .merge-result { font-size: .8rem; }
    .merge-result.ok   { color: var(--bs-success-text-emphasis); }
    .merge-result.warn { color: var(--bs-warning-text-emphasis); }
    .merge-result.err  { color: var(--bs-danger-text-emphasis); }
    .dup-group.merged { opacity: .45; pointer-events: none; }
  </style>
</head>
<body style="padding-top:80px">
<?php include '../navbar.php'; ?>

<div class="container my-4" style="max-width:860px">
  <div class="d-flex align-items-center gap-3 mb-1 flex-wrap">
    <h2 class="mb-0"><i class="fa-solid fa-users-between-lines me-2"></i>Deduplicate Authors</h2>
    <span class="badge bg-secondary"><?= $totalGroups ?> group<?= $totalGroups !== 1 ? 's' : '' ?></span>
  </div>
  <p class="text-muted small mb-4">
    Groups of authors whose names differ only by middle initials or initial formatting
    (e.g. <em>Robert R. McCammon</em> / <em>Robert McCammon</em>,
          <em>J. K. Rowling</em> / <em>JK Rowling</em>).
    Select which name to keep and click <strong>Merge</strong> — books and filesystem directories are moved automatically.
  </p>

<?php if ($totalGroups === 0): ?>
  <div class="alert alert-success">
    <i class="fa-solid fa-circle-check me-2"></i>No duplicate author variants found.
  </div>
<?php else: ?>
  <div class="mb-3 d-flex align-items-center gap-2">
    <input type="text" id="filterInput" class="form-control form-control-sm" placeholder="Filter authors…" style="max-width:260px" autocomplete="off">
    <span class="text-muted small" id="visibleCount"><?= $totalGroups ?> shown</span>
  </div>

  <div id="groupList">
  <?php
  $gIdx = 0;
  foreach ($groups as $key => $members):
    $totalBooks = array_sum(array_column($members, 'book_count'));
    $gIdx++;
  ?>
  <div class="dup-group" data-group-names="<?= htmlspecialchars(strtolower(implode(' ', array_column($members, 'name'))), ENT_QUOTES) ?>">
    <div class="dup-group-header">
      key: <strong><?= htmlspecialchars($key) ?></strong>
      <span class="ms-2 opacity-75"><?= $totalBooks ?> book<?= $totalBooks !== 1 ? 's' : '' ?> total</span>
    </div>
    <?php foreach ($members as $mi => $m): ?>
    <div class="dup-author-row">
      <input type="radio"
             class="form-check-input flex-shrink-0 keep-radio"
             name="keep_<?= $gIdx ?>"
             value="<?= (int)$m['id'] ?>"
             data-name="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>"
             <?= $mi === 0 ? 'checked' : '' ?>>
      <span class="dup-author-name">
        <?= htmlspecialchars($m['name']) ?>
        <?php if ($mi === 0): ?><span class="badge bg-success-subtle text-success-emphasis ms-1" style="font-size:.65rem">suggested</span><?php endif; ?>
      </span>
      <span class="dup-book-count">
        <?= (int)$m['book_count'] ?> book<?= $m['book_count'] !== 1 ? 's' : '' ?>
      </span>
    </div>
    <?php endforeach; ?>
    <div class="dup-author-row justify-content-between">
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
// Filter
const filterInput   = document.getElementById('filterInput');
const visibleCount  = document.getElementById('visibleCount');

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

// Merge
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
      const res  = await fetch('../json_endpoints/merge_authors.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        const msg = `✓ Merged — ${data.books_moved} book${data.books_moved !== 1 ? 's' : ''} moved`;
        resultEl.className = 'merge-result ok';
        resultEl.textContent = data.fs_warning ? msg + ' (⚠ fs: ' + data.fs_warning + ')' : msg;
        if (data.fs_warning) resultEl.className = 'merge-result warn';
        btn.closest('.dup-group').classList.add('merged');
        applyFilter();
      } else {
        resultEl.className = 'merge-result err';
        resultEl.textContent = '✗ ' + (data.error || 'Unknown error');
        btn.disabled = false;
      }
    } catch (e) {
      resultEl.className = 'merge-result err';
      resultEl.textContent = '✗ Request failed';
      btn.disabled = false;
    }
  });
});
</script>
</body>
</html>
