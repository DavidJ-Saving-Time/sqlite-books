<?php
require_once 'db.php';
requireLogin();

// Accept /works/OL123W, OL123W, or just 123
$raw = trim($_GET['id'] ?? '');
$workKey = '';
if ($raw !== '') {
    if (str_starts_with($raw, '/works/')) {
        $workKey = $raw;
    } elseif (preg_match('/^OL\d+W$/i', $raw)) {
        $workKey = '/works/' . strtoupper($raw);
    } elseif (preg_match('/^\d+$/', $raw)) {
        $workKey = '/works/OL' . $raw . 'W';
    }
}

// AJAX: editions-only request
if (isset($_GET['editions']) && $workKey !== '') {
    header('Content-Type: application/json');
    set_time_limit(120);
    try {
        $pg = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
        $pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pg->exec("SET statement_timeout = '15000'");
        $stmt = $pg->prepare("
            SELECT e.key, e.data, e.revision, e.last_modified
            FROM editions e
            WHERE e.data->'works' @> jsonb_build_array(jsonb_build_object('key', ?::text))
            ORDER BY (e.data->>'publish_date') DESC NULLS LAST
            LIMIT 200
        ");
        $stmt->execute([$workKey]);
        $editions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $d = json_decode($er['data'], true);
            $d['_key']           = $er['key'];
            $d['_revision']      = $er['revision'];
            $d['_last_modified'] = $er['last_modified'];
            $editions[] = $d;
        }
        echo json_encode(['editions' => $editions, 'error' => null]);
    } catch (PDOException $e) {
        echo json_encode(['editions' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

$work      = null;
$authors   = [];
$error     = '';

if ($workKey !== '') {
    try {
        $pg = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
        $pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pg->exec("SET statement_timeout = '15000'");

        // Work
        $stmt = $pg->prepare("SELECT data, revision, last_modified FROM works WHERE key = ?");
        $stmt->execute([$workKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $work = json_decode($row['data'], true);
            $work['_revision']      = $row['revision'];
            $work['_last_modified'] = $row['last_modified'];
        } else {
            $error = "No work found for key <code>" . htmlspecialchars($workKey) . "</code>.";
        }

        if ($work) {
            // Authors via author_works join
            $stmt = $pg->prepare("
                SELECT a.key, a.data, a.revision, a.last_modified
                FROM author_works aw
                JOIN authors a ON a.key = aw.author_key
                WHERE aw.work_key = ?
                ORDER BY a.key
            ");
            $stmt->execute([$workKey]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $d = json_decode($ar['data'], true);
                $d['_key']           = $ar['key'];
                $d['_revision']      = $ar['revision'];
                $d['_last_modified'] = $ar['last_modified'];
                $authors[] = $d;
            }
        }

    } catch (PDOException $e) {
        $error = "Database error: " . htmlspecialchars($e->getMessage());
    }
}

// Helpers
function olText(mixed $v): string {
    if (is_string($v)) return $v;
    if (is_array($v) && isset($v['value'])) return $v['value'];
    return '';
}

function olCoverUrl(int $id, string $size = 'M'): string {
    return "https://covers.openlibrary.org/b/id/{$id}-{$size}.jpg";
}

function olAuthorPhotoUrl(int $id, string $size = 'M'): string {
    return "https://covers.openlibrary.org/a/id/{$id}-{$size}.jpg";
}

function olDate(mixed $v): string {
    $s = is_array($v) ? ($v['value'] ?? '') : (string)$v;
    return $s ? substr($s, 0, 10) : '';
}

$title = $work ? htmlspecialchars($work['title'] ?? 'Untitled') : 'OL Work Viewer';
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
  <meta charset="utf-8">
  <title><?= $title ?> — OL Work</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css">
  <style>
    .section-label { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:var(--bs-secondary-color); font-weight:600; }
    .cover-lg { max-height:280px; max-width:180px; border-radius:4px; object-fit:cover; }
    .author-photo { width:56px; height:56px; border-radius:50%; object-fit:cover; }
    .edition-row { border-top:1px solid var(--bs-border-color); }
    .edition-row:first-child { border-top:none; }
    .tag { font-size:.72rem; }
  </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<div class="container py-4" style="max-width:1000px">

  <!-- Search bar -->
  <form method="get" class="d-flex gap-2 mb-4" style="max-width:480px">
    <input type="text" name="id" value="<?= htmlspecialchars($raw) ?>"
           class="form-control form-control-sm font-monospace"
           placeholder="OL27448W or /works/OL27448W">
    <button class="btn btn-sm btn-primary" type="submit">Load</button>
  </form>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php elseif (!$work && $workKey === ''): ?>
    <p class="text-muted">Enter an OpenLibrary works ID above to view local data.</p>
  <?php endif; ?>

  <?php if ($work): ?>

  <!-- ── Work header ── -->
  <div class="d-flex gap-4 mb-4 align-items-start">
    <?php
    $covers = $work['covers'] ?? [];
    $coverId = is_array($covers) ? ($covers[0] ?? null) : null;
    if ($coverId && $coverId > 0):
    ?>
      <img src="<?= olCoverUrl((int)$coverId, 'M') ?>" class="cover-lg flex-shrink-0" alt="cover">
    <?php endif; ?>

    <div class="flex-grow-1">
      <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
        <div>
          <h1 class="h3 mb-0"><?= htmlspecialchars($work['title'] ?? '') ?></h1>
          <?php if (!empty($work['subtitle'])): ?>
            <div class="text-muted fs-6"><?= htmlspecialchars($work['subtitle']) ?></div>
          <?php endif; ?>
        </div>
        <a href="https://openlibrary.org<?= htmlspecialchars($workKey) ?>" target="_blank" rel="noopener"
           class="btn btn-sm btn-outline-secondary flex-shrink-0">
          <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open on OL
        </a>
      </div>

      <div class="mt-2 d-flex flex-wrap gap-2">
        <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($workKey) ?></span>
        <span class="badge bg-secondary">Rev <?= (int)$work['_revision'] ?></span>
        <span class="badge bg-secondary">Modified <?= htmlspecialchars($work['_last_modified']) ?></span>
        <?php if (!empty($work['first_publish_date'])): ?>
          <span class="badge bg-info text-dark">First published <?= htmlspecialchars($work['first_publish_date']) ?></span>
        <?php endif; ?>
      </div>

      <?php $desc = olText($work['description'] ?? ''); if ($desc): ?>
        <p class="mt-3 mb-0 small"><?= nl2br(htmlspecialchars($desc)) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4 mb-4">

    <!-- ── Authors ── -->
    <?php if ($authors): ?>
    <div class="col-12">
      <div class="section-label mb-2">Authors (<?= count($authors) ?>)</div>
      <div class="d-flex flex-wrap gap-3">
        <?php foreach ($authors as $a): ?>
          <?php
          $photoId = is_array($a['photos'] ?? null) ? ($a['photos'][0] ?? null) : null;
          $bio = olText($a['bio'] ?? '');
          ?>
          <div class="card p-2" style="max-width:300px;min-width:200px;flex:1">
            <div class="d-flex gap-2 align-items-start">
              <?php if ($photoId && $photoId > 0): ?>
                <img src="<?= olAuthorPhotoUrl((int)$photoId, 'S') ?>" class="author-photo flex-shrink-0" alt="">
              <?php else: ?>
                <div class="author-photo flex-shrink-0 bg-secondary d-flex align-items-center justify-content-center text-white">
                  <i class="fa-solid fa-user"></i>
                </div>
              <?php endif; ?>
              <div class="min-width-0">
                <div class="fw-semibold"><?= htmlspecialchars($a['name'] ?? '') ?></div>
                <?php if (!empty($a['personal_name']) && $a['personal_name'] !== ($a['name'] ?? '')): ?>
                  <div class="text-muted small"><?= htmlspecialchars($a['personal_name']) ?></div>
                <?php endif; ?>
                <div class="font-monospace text-muted" style="font-size:.68rem"><?= htmlspecialchars($a['_key']) ?></div>
                <?php if (!empty($a['birth_date']) || !empty($a['death_date'])): ?>
                  <div class="small text-muted">
                    <?= htmlspecialchars($a['birth_date'] ?? '?') ?><?= !empty($a['death_date']) ? ' – ' . htmlspecialchars($a['death_date']) : '' ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($a['alternate_names'])): ?>
                  <div class="small text-muted mt-1">Also: <?= htmlspecialchars(implode(', ', array_slice($a['alternate_names'], 0, 4))) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($bio): ?>
              <p class="small mt-2 mb-0 text-muted" style="font-size:.75rem;max-height:80px;overflow:hidden">
                <?= htmlspecialchars(substr($bio, 0, 300)) ?><?= strlen($bio) > 300 ? '…' : '' ?>
              </p>
            <?php endif; ?>
            <?php if (!empty($a['wikipedia'])): ?>
              <a href="<?= htmlspecialchars($a['wikipedia']) ?>" target="_blank" rel="noopener" class="small mt-1 d-block">Wikipedia</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Subjects ── -->
    <?php if (!empty($work['subjects'])): ?>
    <div class="col-md-6">
      <div class="section-label mb-2">Subjects (<?= count($work['subjects']) ?>)</div>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($work['subjects'] as $s): ?>
          <span class="badge bg-primary tag"><?= htmlspecialchars($s) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Subject places / times / people ── -->
    <?php
    $extras = [];
    if (!empty($work['subject_places'])) $extras['Places'] = $work['subject_places'];
    if (!empty($work['subject_times']))  $extras['Time periods'] = $work['subject_times'];
    if (!empty($work['subject_people'])) $extras['People'] = $work['subject_people'];
    ?>
    <?php foreach ($extras as $label => $tags): ?>
    <div class="col-md-6">
      <div class="section-label mb-2"><?= $label ?> (<?= count($tags) ?>)</div>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tags as $t): ?>
          <span class="badge bg-success tag"><?= htmlspecialchars($t) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- ── Links / identifiers on work ── -->
    <?php if (!empty($work['links']) || !empty($work['identifiers'])): ?>
    <div class="col-12">
      <div class="section-label mb-2">Links & Identifiers</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($work['links'] ?? [] as $link): ?>
          <a href="<?= htmlspecialchars($link['url'] ?? '#') ?>" target="_blank" rel="noopener"
             class="badge bg-secondary text-decoration-none tag">
            <?= htmlspecialchars($link['title'] ?? $link['url'] ?? '') ?>
            <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size:.6rem"></i>
          </a>
        <?php endforeach; ?>
        <?php foreach ($work['identifiers'] ?? [] as $type => $vals): ?>
          <?php foreach ((array)$vals as $v): ?>
            <span class="badge bg-info text-dark tag"><?= htmlspecialchars($type) ?>: <?= htmlspecialchars($v) ?></span>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── All covers ── -->
    <?php if (count($covers) > 1): ?>
    <div class="col-12">
      <div class="section-label mb-2">Covers (<?= count($covers) ?>)</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($covers as $cid): ?>
          <?php if ($cid > 0): ?>
            <a href="<?= olCoverUrl((int)$cid, 'L') ?>" target="_blank" rel="noopener">
              <img src="<?= olCoverUrl((int)$cid, 'S') ?>" style="height:80px;border-radius:3px" alt="">
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── Editions (loaded async) ── -->
  <div id="editions-label" class="section-label mb-2">Editions</div>
  <div id="editions-container">
    <div class="d-flex align-items-center gap-2 text-muted small">
      <div class="spinner-border spinner-border-sm" role="status"></div>
      Loading editions — this can take up to 90 seconds on an unindexed table…
    </div>
  </div>

  <script>
  (function () {
    const workKey = <?= json_encode($workKey) ?>;
    if (!workKey) return;
    const url = 'ol_work_view.php?id=' + encodeURIComponent(workKey) + '&editions=1';
    fetch(url, { signal: AbortSignal.timeout(100000) })
      .then(r => r.json())
      .then(({ editions, error }) => {
        const label = document.getElementById('editions-label');
        const container = document.getElementById('editions-container');
        if (error) {
          container.innerHTML = '<div class="alert alert-warning small">Editions query failed: ' + escHtml(error) + '</div>';
          return;
        }
        label.textContent = 'Editions (' + editions.length + (editions.length === 200 ? ', showing first 200' : '') + ')';
        if (!editions.length) {
          container.innerHTML = '<p class="text-muted small">No editions found locally for this work.</p>';
          return;
        }
        const rows = editions.map(ed => {
          const coverId = Array.isArray(ed.covers) ? ed.covers[0] : null;
          const isbns = [...(ed.isbn_13 || []), ...(ed.isbn_10 || [])];
          const langs = (ed.languages || []).map(l => (l.key || '').split('/').pop());
          const pages = ed.number_of_pages || null;
          const format = ed.physical_format || '';
          const publisher = (ed.publishers || []).join(', ').substring(0, 60);
          const edKey = ed._key;
          const olEdId = edKey.split('/').pop();
          const title = ed.title || '';
          const subtitle = ed.subtitle || '';

          const coverHtml = (coverId && coverId > 0)
            ? `<img src="https://covers.openlibrary.org/b/id/${coverId}-S.jpg" style="height:54px;width:36px;object-fit:cover;border-radius:2px;flex-shrink:0" alt="">`
            : `<div style="height:54px;width:36px;flex-shrink:0;background:var(--bs-secondary-bg);border-radius:2px"></div>`;

          const badges = [
            ed.publish_date ? `<span class="badge bg-secondary tag">${escHtml(ed.publish_date)}</span>` : '',
            publisher ? `<span class="badge bg-secondary tag">${escHtml(publisher)}</span>` : '',
            format ? `<span class="badge bg-info text-dark tag">${escHtml(format)}</span>` : '',
            pages ? `<span class="badge bg-secondary tag">${pages}pp</span>` : '',
            ...langs.map(l => `<span class="badge bg-success tag">${escHtml(l)}</span>`),
            ...isbns.map(i => `<span class="badge bg-warning text-dark tag font-monospace">${escHtml(i)}</span>`),
          ].join('');

          return `<div class="edition-row px-3 py-2 d-flex gap-3 align-items-start">
            ${coverHtml}
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                <div>
                  <span class="fw-semibold small">${escHtml(title)}</span>
                  ${subtitle ? `<span class="text-muted small">: ${escHtml(subtitle)}</span>` : ''}
                </div>
                <a href="https://openlibrary.org${escHtml(edKey)}" target="_blank" rel="noopener"
                   class="text-muted font-monospace flex-shrink-0" style="font-size:.7rem">${escHtml(olEdId)}</a>
              </div>
              <div class="d-flex flex-wrap gap-1 mt-1">${badges}</div>
            </div>
          </div>`;
        });
        container.innerHTML = '<div class="card"><div class="card-body p-0">' + rows.join('') + '</div></div>';
      })
      .catch(err => {
        document.getElementById('editions-container').innerHTML =
          '<div class="alert alert-warning small">Could not load editions: ' + escHtml(String(err)) + '</div>';
      });

    function escHtml(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
  }());
  </script>

  <?php endif; // $work ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
