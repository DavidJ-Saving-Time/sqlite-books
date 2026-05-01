<?php
/**
 * Schema initialiser — web front-end for setting up or upgrading a user's library DB.
 *
 * Reached automatically when getDatabaseConnection() detects a missing or outdated
 * schema_version.  Safe to re-run: all DDL uses IF NOT EXISTS / ALTER TABLE guards.
 */
require_once __DIR__ . '/db.php';
requireLogin(); // auth required, but checkSchemaVersion() is skipped (we're init_schema.php)

$pdo      = getDatabaseConnection();
$redirect = preg_replace('/[^a-zA-Z0-9\/_\-?=&%.]/', '', $_GET['redirect'] ?? '/list_books.php');
if (!$redirect) $redirect = '/list_books.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function tableExists(PDO $pdo, string $table): bool {
    return (bool)$pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table)
    )->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    if (!tableExists($pdo, $table)) return false;
    foreach ($pdo->query("PRAGMA table_info(" . $pdo->quote($table) . ")")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['name'] === $column) return true;
    }
    return false;
}

// ── Run setup ────────────────────────────────────────────────────────────────
$error = null;
$ran   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (val INTEGER NOT NULL DEFAULT 0)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS author_identifiers (
            author_id INTEGER NOT NULL REFERENCES authors(id) ON DELETE CASCADE,
            type      TEXT NOT NULL,
            val       TEXT NOT NULL,
            PRIMARY KEY (author_id, type)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS awards (
            id   INTEGER PRIMARY KEY,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS book_awards (
            id       INTEGER PRIMARY KEY,
            book_id  INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
            award_id INTEGER NOT NULL REFERENCES awards(id) ON DELETE CASCADE,
            year     INTEGER,
            category TEXT,
            result   TEXT NOT NULL DEFAULT 'nominated',
            UNIQUE(book_id, award_id, year, category)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_book_awards_book  ON book_awards(book_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_book_awards_award ON book_awards(award_id)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS book_reviews (
            id           INTEGER PRIMARY KEY,
            book         INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
            source       TEXT NOT NULL DEFAULT 'goodreads',
            reviewer     TEXT,
            reviewer_url TEXT,
            rating       INTEGER,
            review_date  TEXT,
            text         TEXT,
            like_count   INTEGER NOT NULL DEFAULT 0,
            spoiler      INTEGER NOT NULL DEFAULT 0,
            gr_review_id TEXT UNIQUE
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_book_reviews_book ON book_reviews(book)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gr_similar_books (
            id              INTEGER PRIMARY KEY,
            source_work_id  TEXT NOT NULL,
            gr_book_id      TEXT NOT NULL,
            gr_work_id      TEXT,
            title           TEXT,
            author          TEXT,
            series          TEXT,
            series_position TEXT,
            gr_rating       REAL,
            gr_rating_count INTEGER,
            cover_url       TEXT,
            description     TEXT,
            fetched_at      TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(source_work_id, gr_book_id)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gr_similar_source  ON gr_similar_books(source_work_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gr_similar_book_id ON gr_similar_books(gr_book_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gr_similar_work_id ON gr_similar_books(gr_work_id)");

        // Migration: add gr_work_id to pre-existing gr_similar_books table
        try { $pdo->exec("ALTER TABLE gr_similar_books ADD COLUMN gr_work_id TEXT"); } catch (PDOException $e) {}

        // Indexes for Calibre join tables
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_authors_link_book   ON books_authors_link(book)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_authors_link_author ON books_authors_link(author)");
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_series_link_book   ON books_series_link(book)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_series_link_series ON books_series_link(series)");
        } catch (PDOException $e) {}

        // Indexes for custom column link tables (one pair per custom column)
        try {
            $stmt = $pdo->query("SELECT id FROM custom_columns");
            while (($id = $stmt->fetchColumn()) !== false) {
                $link = "books_custom_column_{$id}_link";
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$link}_book  ON {$link}(book)");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$link}_value ON {$link}(value)");
            }
        } catch (PDOException $e) {}

        // Set schema version
        $pdo->exec("DELETE FROM schema_version");
        $pdo->exec("INSERT INTO schema_version (val) VALUES (" . APP_SCHEMA_VERSION . ")");

        $ran = true;
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}

// ── Check current state ───────────────────────────────────────────────────────
$checks = [
    ['table' => 'schema_version',     'label' => 'Schema version tracking',              'type' => 'table'],
    ['table' => 'author_identifiers', 'label' => 'Author external identifiers (OL/VIAF)', 'type' => 'table'],
    ['table' => 'awards',             'label' => 'Awards registry',                       'type' => 'table'],
    ['table' => 'book_awards',        'label' => 'Book–award nominations/wins',            'type' => 'table'],
    ['table' => 'book_reviews',       'label' => 'Goodreads community reviews',            'type' => 'table'],
    ['table' => 'gr_similar_books',   'label' => 'Goodreads similar books cache',          'type' => 'table'],
    ['table' => 'gr_similar_books',   'label' => 'Similar books: gr_work_id column',       'type' => 'column', 'column' => 'gr_work_id'],
    // These are self-healing in getDatabaseConnection() — shown for info only
    ['table' => 'notepad',            'label' => 'Notepad (self-healing)',                 'type' => 'table', 'selfheal' => true],
    ['table' => 'shelves',            'label' => 'Shelves (self-healing)',                 'type' => 'table', 'selfheal' => true],
    ['table' => 'books_fts',          'label' => 'Full-text search index (self-healing)',  'type' => 'table', 'selfheal' => true],
];

foreach ($checks as &$c) {
    if ($c['type'] === 'column') {
        $c['ok'] = columnExists($pdo, $c['table'], $c['column']);
    } else {
        $c['ok'] = tableExists($pdo, $c['table']);
    }
}
unset($c);

$currentVersion = 0;
try {
    if (tableExists($pdo, 'schema_version')) {
        $currentVersion = (int)$pdo->query("SELECT val FROM schema_version LIMIT 1")->fetchColumn();
    }
} catch (PDOException $e) {}

$allReady = ($currentVersion >= APP_SCHEMA_VERSION);
$dbPath   = currentDatabasePath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Setup</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<div class="container" style="max-width:680px;padding-top:2rem;padding-bottom:3rem">

    <h4 class="mb-1"><i class="fa-duotone fa-solid fa-database me-2"></i>Database Setup</h4>
    <p class="text-muted small mb-3" style="word-break:break-all"><?= htmlspecialchars($dbPath) ?></p>

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($ran && !$error): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <span>Schema initialised — version <?= APP_SCHEMA_VERSION ?>. <a href="<?= htmlspecialchars($redirect) ?>">Continue &rarr;</a></span>
    </div>
    <?php endif; ?>

    <?php if (!$ran && !$allReady): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>This library database needs to be set up before you can use the application.</span>
    </div>
    <?php endif; ?>

    <!-- Version badge -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-muted small">Schema version in DB:</span>
        <span class="badge <?= $allReady ? 'bg-success' : 'bg-secondary' ?> fs-6"><?= $currentVersion ?></span>
        <span class="text-muted small">Required:</span>
        <span class="badge bg-primary fs-6"><?= APP_SCHEMA_VERSION ?></span>
    </div>

    <!-- Table status -->
    <table class="table table-sm table-bordered mb-4" style="font-size:0.875rem">
        <thead class="table-dark">
            <tr>
                <th style="width:2rem"></th>
                <th>Component</th>
                <th style="width:6rem">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
            <tr class="<?= $c['ok'] ? '' : 'table-warning' ?>">
                <td class="text-center">
                    <?php if ($c['ok']): ?>
                        <i class="fa-solid fa-circle-check text-success"></i>
                    <?php elseif (!empty($c['selfheal'])): ?>
                        <i class="fa-solid fa-circle-xmark text-secondary"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-circle-xmark text-danger"></i>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($c['label']) ?>
                    <?php if (!empty($c['selfheal'])): ?>
                        <span class="text-muted" style="font-size:0.75rem">(created automatically on connection)</span>
                    <?php endif; ?>
                </td>
                <td class="<?= $c['ok'] ? 'text-success' : 'text-danger' ?> fw-semibold">
                    <?= $c['ok'] ? 'Present' : 'Missing' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($allReady && !$ran): ?>
    <div class="d-flex align-items-center gap-3">
        <span class="text-success fw-semibold"><i class="fa-solid fa-circle-check me-1"></i>Everything is up to date.</span>
        <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-sm btn-outline-secondary">Continue &rarr;</a>
    </div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>
            <?= $ran ? 'Re-run Setup' : 'Initialise Database' ?>
        </button>
        <?php if ($ran): ?>
        <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-success ms-2">Continue &rarr;</a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
</body>
</html>
