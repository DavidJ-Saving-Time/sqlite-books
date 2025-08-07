<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$hasSubseries = false;
$subseriesIsCustom = false;
$subseriesLinkTable = '';
$subseriesValueTable = '';

try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $hasSubseries = true;
        $subseriesIsCustom = true;
        $subseriesValueTable = "custom_column_{$subseriesColumnId}";
        $subseriesLinkTable  = "books_custom_column_{$subseriesColumnId}_link";
    } else {
        $subTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) {
            $hasSubseries = true;
        }
    }

    if ($hasSubseries) {
        if ($subseriesIsCustom) {
            $sql = "SELECT s.id, s.name, REPLACE(GROUP_CONCAT(DISTINCT ss.id || ':' || ss.value), ',', '|') AS subseries_list
                    FROM series s
                    LEFT JOIN books_series_link bsl ON bsl.series = s.id
                    LEFT JOIN $subseriesLinkTable bssl ON bssl.book = bsl.book
                    LEFT JOIN $subseriesValueTable ss ON bssl.value = ss.id
                    GROUP BY s.id, s.name
                    ORDER BY s.sort";
        } else {
            $sql = "SELECT s.id, s.name, REPLACE(GROUP_CONCAT(DISTINCT ss.id || ':' || ss.name), ',', '|') AS subseries_list
                    FROM series s
                    LEFT JOIN books_series_link bsl ON bsl.series = s.id
                    LEFT JOIN books_subseries_link bssl ON bssl.book = bsl.book
                    LEFT JOIN subseries ss ON bssl.subseries = ss.id
                    GROUP BY s.id, s.name
                    ORDER BY s.sort";
        }
        $series = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $series = $pdo->query('SELECT id, name FROM series ORDER BY sort')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $series = $pdo->query('SELECT id, name FROM series ORDER BY sort')->fetchAll(PDO::FETCH_ASSOC);
    $hasSubseries = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Series</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5 bg-light">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Series</h1>
    <?php if (empty($series)): ?>
        <p class="text-muted">No series found.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($series as $s): ?>
                <?php $subs = ($hasSubseries && isset($s['subseries_list']) && $s['subseries_list'] !== '') ? explode('|', $s['subseries_list']) : []; ?>
                <li class="list-group-item">
                    <a href="list_books.php?series_id=<?= (int)$s['id'] ?>">
                        <?= htmlspecialchars($s['name']) ?>
                    </a>
                    <?php if (!empty($subs)): ?>
                        <ul class="mt-2">
                            <?php foreach ($subs as $sub): ?>
                                <?php list($sid, $sname) = explode(':', $sub, 2); ?>
                                <li><?= htmlspecialchars($sname) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
