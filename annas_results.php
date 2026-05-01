<?php
require_once 'db.php';
requireLogin();
require_once 'annas_archive.php';
require_once 'metadata/metadata_sources.php';

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$author = isset($_GET['author']) ? trim((string)$_GET['author']) : '';
$sort = $_GET['sort'] ?? 'author_series';
$source = 'annas';
$books = [];
$debugInfo = [];
if ($search !== '') {
    $books = search_annas_archive($search, $debugInfo, $author);
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
    <title>Anna's Archive Results</title>
    <link rel="stylesheet" href="/theme.css.php">
    <script src="js/search.js"></script>
</head>
<body class="pt-5">
<?php include "navbar_other.php"; ?>
<div class="container my-4">
    <h1 class="mb-4">Anna's Archive Results</h1>

    <?php if (!empty($debugInfo)): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark fw-bold">Debug Info</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Search term</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($search) ?></dd>
                <dt class="col-sm-3">URL called</dt>
                <dd class="col-sm-9"><code><?= htmlspecialchars($debugInfo['url'] ?? '') ?></code></dd>
                <dt class="col-sm-3">HTTP status</dt>
                <dd class="col-sm-9"><?= htmlspecialchars((string)($debugInfo['http_code'] ?? 'n/a')) ?></dd>
                <dt class="col-sm-3">cURL error</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($debugInfo['curl_error'] ?? 'none') ?></dd>
                <dt class="col-sm-3">API key set</dt>
                <dd class="col-sm-9"><?= $debugInfo['has_api_key'] ? 'Yes' : '<strong class="text-danger">NO</strong>' ?></dd>
                <dt class="col-sm-3">Raw response</dt>
                <dd class="col-sm-9"><pre class="small mb-0" style="max-height:300px;overflow:auto"><?= htmlspecialchars($debugInfo['raw_response'] ?? '') ?></pre></dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Cover</th>
                <th class="title-col">Title</th>
                <th>Author(s)</th>
                <th>Genre</th>
                <th>Year</th>
                <th>Size</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td>
                    <?php if (!empty($book['cover'])): ?>
                        <img src="<?= htmlspecialchars($book['cover']) ?>" alt="Cover" class="img-thumbnail" style="width: 150px; height: auto;">
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td class="title-col">
                    <?php if (!empty($book['md5'])): ?>
                        <a href="https://annas-archive.org/md5/<?= urlencode($book['md5']) ?>" target="_blank">
                            <?= htmlspecialchars($book['title']) ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($book['title']) ?>
                    <?php endif; ?>
                </td>
                <td><?= ($book['authors'] ?? '') !== '' ? htmlspecialchars($book['authors']) : '&mdash;' ?></td>
                <td><?= ($book['genre'] ?? '') !== '' ? htmlspecialchars($book['genre']) : '&mdash;' ?></td>
                <td><?= ($book['year'] ?? '') !== '' ? htmlspecialchars($book['year']) : '&mdash;' ?></td>
                <td><?= ($book['size'] ?? '') !== '' ? htmlspecialchars($book['size']) : '&mdash;' ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-primary annas-add"
                            data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                            data-authors="<?= htmlspecialchars($book['authors'] ?? '', ENT_QUOTES) ?>"
                            data-thumbnail="<?= htmlspecialchars($book['cover'] ?? '', ENT_QUOTES) ?>"
                            data-description="">
                        Add to Library
                    </button>
                    <span class="annas-add-result ms-1"></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/annas_results.js"></script>
</body>
</html>
