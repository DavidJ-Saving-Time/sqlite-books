<?php
require_once '../db.php';
require_once '../cache.php';
requireLogin();

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$books = $pdo->query("
    SELECT b.id, b.title, b.path, b.timestamp AS added,
           GROUP_CONCAT(a.name, ' & ') AS author
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    GROUP BY b.id
    ORDER BY LOWER(b.title), LOWER(b.author_sort)
")->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($books as $row) {
    $key = strtolower(trim($row['title'])) . '|||' . strtolower(trim($row['author'] ?? ''));
    $groups[$key][] = $row;
}

$dupes = [];
foreach ($groups as $key => $rows) {
    if (count($rows) < 2) continue;
    $dupes[] = ['title' => $rows[0]['title'], 'author' => $rows[0]['author'] ?? '', 'books' => $rows];
}

$bookIds = [];
foreach ($dupes as $g) foreach ($g['books'] as $b) $bookIds[] = (int)$b['id'];

$filesByBook  = [];
$identsByBook = [];

if ($bookIds) {
    $in = implode(',', $bookIds);
    foreach ($pdo->query("SELECT book, format, uncompressed_size FROM data WHERE book IN ($in) ORDER BY format")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $filesByBook[(int)$r['book']][] = $r;
    foreach ($pdo->query("SELECT book, type, val FROM identifiers WHERE book IN ($in) ORDER BY type")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $identsByBook[(int)$r['book']][] = $r;
}

foreach ($dupes as &$g) {
    foreach ($g['books'] as &$b) {
        $bid = (int)$b['id'];
        $b['formats']     = $filesByBook[$bid]  ?? [];
        $b['identifiers'] = $identsByBook[$bid] ?? [];
        $diskSize = 0;
        if ($b['path'] && $libraryPath) {
            $dir = $libraryPath . '/' . $b['path'];
            if (is_dir($dir))
                foreach (glob($dir . '/*') ?: [] as $f)
                    if (is_file($f)) $diskSize += filesize($f);
        }
        $b['disk_size'] = $diskSize;
    }
    unset($b);
}
unset($g);

function fmtSize(int $bytes): string {
    if ($bytes <= 0) return '—';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
  <meta charset="utf-8">
  <title>Duplicate Books</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css">
</head>
<body class="pt-5">
<?php include '../navbar.php'; ?>

<div class="container py-4" style="max-width:960px">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Duplicate Books</h1>
    <a href="../preferences.php" class="btn btn-sm btn-outline-secondary">← Preferences</a>
  </div>
  <p class="text-muted small mb-4">
    Books sharing the same title and author (case-insensitive).
    Showing file formats, disk size, and stored identifiers to help you decide which copy to keep.
    Deleting removes the database entry only — files on disk are not touched.
  </p>

  <?php if (empty($dupes)): ?>
    <div class="alert alert-success">No duplicate books found.</div>
  <?php else: ?>
    <p class="fw-semibold mb-3"><?= count($dupes) ?> duplicate group<?= count($dupes) !== 1 ? 's' : '' ?> found</p>

    <?php foreach ($dupes as $group): ?>
      <div class="card mb-3" style="border-left:3px solid var(--bs-warning)">
        <div class="card-header py-2 px-3">
          <span class="fw-semibold"><?= htmlspecialchars($group['title']) ?></span>
          <span class="text-muted ms-2 small"><?= htmlspecialchars($group['author']) ?></span>
        </div>
        <div class="card-body p-0">
          <?php foreach ($group['books'] as $idx => $b): ?>
            <div class="px-3 py-2 d-flex flex-wrap align-items-start gap-3<?= $idx > 0 ? ' border-top' : '' ?>">

              <div style="min-width:180px;flex:1">
                <a href="../book.php?id=<?= $b['id'] ?>" target="_blank" class="fw-semibold text-decoration-none">
                  <?= htmlspecialchars($b['title']) ?>
                </a>
                <div class="text-muted small mt-1">
                  ID&nbsp;<?= (int)$b['id'] ?> &nbsp;·&nbsp; added <?= htmlspecialchars(substr($b['added'] ?? '', 0, 10)) ?>
                </div>
              </div>

              <div style="min-width:140px">
                <?php if ($b['formats']): ?>
                  <?php foreach ($b['formats'] as $fmt): ?>
                    <span class="badge bg-secondary me-1" style="font-size:.72rem">
                      <?= htmlspecialchars($fmt['format']) ?><?= $fmt['uncompressed_size'] > 0 ? ' · ' . fmtSize((int)$fmt['uncompressed_size']) : '' ?>
                    </span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="text-muted small">no files</span>
                <?php endif; ?>
                <div class="text-muted small mt-1">Disk: <?= fmtSize($b['disk_size']) ?></div>
              </div>

              <div style="min-width:160px;flex:1">
                <?php if ($b['identifiers']): ?>
                  <?php foreach ($b['identifiers'] as $ident): ?>
                    <span class="badge bg-info text-dark me-1 mb-1" style="font-size:.7rem">
                      <?= htmlspecialchars($ident['type']) ?>:&nbsp;<?= htmlspecialchars($ident['val']) ?>
                    </span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="text-muted small">no identifiers</span>
                <?php endif; ?>
              </div>

              <div>
                <button class="btn btn-sm btn-outline-danger btn-delete"
                        data-book-id="<?= (int)$b['id'] ?>"
                        data-title="<?= htmlspecialchars($b['title'], ENT_QUOTES) ?>">
                  <i class="fa-solid fa-trash-can me-1"></i>Delete
                </button>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
document.querySelectorAll('.btn-delete').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id    = btn.dataset.bookId;
    const title = btn.dataset.title;
    if (!confirm(`Delete "${title}" (ID ${id}) from the database?\n\nFiles on disk are not deleted.`)) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Deleting…';

    try {
      const fd = new FormData();
      fd.append('book_id', id);
      const res  = await fetch('../json_endpoints/delete_book.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.status === 'ok') {
        const row  = btn.closest('.px-3.py-2');
        const card = btn.closest('.card');
        row.remove();
        if (card.querySelectorAll('.btn-delete').length === 0) card.remove();
      } else {
        alert('Error: ' + (data.error ?? 'unknown'));
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i>Delete';
      }
    } catch (e) {
      alert('Request failed: ' + e.message);
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i>Delete';
    }
  });
});
</script>
</body>
</html>
