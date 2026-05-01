<?php
require_once 'db.php';
require_once 'openlibrary.php';

$key     = isset($_GET['key'])      ? trim((string)$_GET['key'])   : '';
$title   = isset($_GET['title'])    ? (string)$_GET['title']       : '';
$authors = isset($_GET['authors'])  ? (string)$_GET['authors']     : '';
$coverId = isset($_GET['cover_id']) ? (string)$_GET['cover_id']    : '';

// Normalise key format
if ($key !== '' && !str_starts_with($key, '/works/')) {
    $key = '/works/' . ltrim($key, '/');
}

function get_work_local(string $key): array {
    try {
        $pgPdo = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
        $pgPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        return [];
    }

    $stmt = $pgPdo->prepare("SELECT data FROM works WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];

    $data = json_decode($row['data'], true);
    if (!is_array($data)) return [];

    $description = '';
    if (isset($data['description'])) {
        if (is_array($data['description'])) {
            $description = $data['description']['value'] ?? '';
        } elseif (is_string($data['description'])) {
            $description = $data['description'];
        }
    }

    $aStmt = $pgPdo->prepare("
        SELECT a.data->>'name' AS name
        FROM author_works aw
        JOIN authors a ON a.key = aw.author_key
        WHERE aw.work_key = ?
        LIMIT 10
    ");
    $aStmt->execute([$key]);
    $authorNames = array_column($aStmt->fetchAll(PDO::FETCH_ASSOC), 'name');

    return [
        'title'       => $data['title'] ?? '',
        'description' => $description,
        'subjects'    => is_array($data['subjects'] ?? null) ? $data['subjects'] : [],
        'covers'      => is_array($data['covers']   ?? null) ? $data['covers']   : [],
        'authors'     => $authorNames,
        'source'      => 'local',
    ];
}

$work = [];
$source = 'none';
if ($key !== '') {
    $work = get_work_local($key);
    if (empty($work)) {
        $work = get_openlibrary_work($key);
        $source = 'api';
    } else {
        $source = 'local';
    }
}

$workTitle = $work['title'] ?? $title;
$description = $work['description'] ?? '';
$subjects = $work['subjects'] ?? [];
$covers   = $work['covers']   ?? [];

// Authors: prefer what came from the local DB; fall back to URL param
$authorDisplay = '';
if (!empty($work['authors'])) {
    $authorDisplay = implode(', ', $work['authors']);
} elseif ($authors !== '') {
    $authorDisplay = $authors;
}

if (!$coverId && !empty($covers)) {
    $coverId = (string)($covers[0]);
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
  <title><?= htmlspecialchars($workTitle) ?></title>
  <link rel="stylesheet" href="/theme.css.php">
</head>
<body>
<?php include "navbar_other.php"; ?>
<div class="container my-4">
  <a href="javascript:history.back()" class="btn btn-secondary mb-3">Back</a>

  <?php if ($key !== ''): ?>
  <div class="mb-2">
    <span class="badge <?= $source === 'local' ? 'bg-success' : 'bg-secondary' ?> me-2">
      <?= $source === 'local' ? 'Local OL mirror' : 'Live API' ?>
    </span>
    <a href="https://openlibrary.org<?= htmlspecialchars($key) ?>" target="_blank" rel="noopener" class="small text-muted">
      <?= htmlspecialchars($key) ?>
    </a>
  </div>
  <?php endif; ?>

  <h1 class="mb-4"><?= htmlspecialchars($workTitle) ?></h1>
  <div class="row mb-4">
    <div class="col-md-3">
      <?php if ($coverId): ?>
        <img src="https://covers.openlibrary.org/b/id/<?= htmlspecialchars($coverId) ?>-L.jpg" class="img-fluid" alt="Cover">
      <?php endif; ?>
    </div>
    <div class="col-md-9">
      <?php if ($authorDisplay !== ''): ?>
        <p><strong>Author(s):</strong> <?= htmlspecialchars($authorDisplay) ?></p>
      <?php endif; ?>
      <?php if ($description !== ''): ?>
        <p><?= nl2br(htmlspecialchars($description)) ?></p>
      <?php endif; ?>
      <?php if (!empty($subjects)): ?>
        <p><strong>Subjects:</strong> <?= htmlspecialchars(implode(', ', $subjects)) ?></p>
      <?php endif; ?>
      <?php $coverUrl = $coverId ? 'https://covers.openlibrary.org/b/id/' . urlencode($coverId) . '-L.jpg' : ''; ?>
      <button id="addBtn" type="button" class="btn btn-primary mt-3"
              data-title="<?= htmlspecialchars($workTitle, ENT_QUOTES) ?>"
              data-authors="<?= htmlspecialchars($authorDisplay, ENT_QUOTES) ?>"
              data-thumbnail="<?= htmlspecialchars($coverUrl, ENT_QUOTES) ?>"
              data-description="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
        Add to Library
      </button>
      <div id="addResult" class="mt-2"></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/openlibrary_view.js"></script>
</body>
</html>
