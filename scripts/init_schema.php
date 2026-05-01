<?php
require_once __DIR__ . '/../db.php';

$pdo = getDatabaseConnection();
initializeCustomColumns($pdo);

// Author identifiers table (stores OL author IDs, VIAF, etc. keyed by authors.id)
$pdo->exec('CREATE TABLE IF NOT EXISTS author_identifiers (
    author_id INTEGER NOT NULL REFERENCES authors(id) ON DELETE CASCADE,
    type      TEXT NOT NULL,
    val       TEXT NOT NULL,
    PRIMARY KEY (author_id, type)
)');

// Awards lookup table
$pdo->exec('CREATE TABLE IF NOT EXISTS awards (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
)');

// Book–award join: tracks each nomination/win per book
$pdo->exec('CREATE TABLE IF NOT EXISTS book_awards (
    id       INTEGER PRIMARY KEY,
    book_id  INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    award_id INTEGER NOT NULL REFERENCES awards(id) ON DELETE CASCADE,
    year     INTEGER,
    category TEXT,
    result   TEXT NOT NULL DEFAULT \'nominated\',
    UNIQUE(book_id, award_id, year, category)
)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_book_awards_book   ON book_awards(book_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_book_awards_award  ON book_awards(award_id)');

// Ensure indexes exist for common lookup tables
try {
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_authors_link_book ON books_authors_link(book)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_authors_link_author ON books_authors_link(author)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_series_link_book ON books_series_link(book)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_series_link_series ON books_series_link(series)');

    $stmt = $pdo->query('SELECT id FROM custom_columns');
    while (($id = $stmt->fetchColumn()) !== false) {
        $link = "books_custom_column_{$id}_link";
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$link}_book ON {$link}(book)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$link}_value ON {$link}(value)");
    }
} catch (PDOException $e) {
    error_log('Index creation failed: ' . $e->getMessage());
}

// Goodreads community reviews (top 5 most-liked per book)
$pdo->exec('CREATE TABLE IF NOT EXISTS book_reviews (
    id           INTEGER PRIMARY KEY,
    book         INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    source       TEXT NOT NULL DEFAULT \'goodreads\',
    reviewer     TEXT,
    reviewer_url TEXT,
    rating       INTEGER,
    review_date  TEXT,
    text         TEXT,
    like_count   INTEGER NOT NULL DEFAULT 0,
    spoiler      INTEGER NOT NULL DEFAULT 0,
    gr_review_id TEXT UNIQUE
)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_book_reviews_book ON book_reviews(book)');

// Goodreads similar books (scraped from /book/similar/{work_id})
$pdo->exec('CREATE TABLE IF NOT EXISTS gr_similar_books (
    id              INTEGER PRIMARY KEY,
    source_work_id  TEXT NOT NULL,
    gr_book_id      TEXT NOT NULL,
    title           TEXT,
    author          TEXT,
    series          TEXT,
    series_position TEXT,
    gr_rating       REAL,
    gr_rating_count INTEGER,
    cover_url       TEXT,
    description     TEXT,
    fetched_at      TEXT NOT NULL DEFAULT (datetime(\'now\')),
    gr_work_id      TEXT,
    UNIQUE(source_work_id, gr_book_id)
)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_gr_similar_source  ON gr_similar_books(source_work_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_gr_similar_book_id ON gr_similar_books(gr_book_id)');

// Migration: add gr_work_id to pre-existing gr_similar_books tables (no-op for new DBs)
try { $pdo->exec("ALTER TABLE gr_similar_books ADD COLUMN gr_work_id TEXT"); } catch (PDOException $e) {}
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_gr_similar_work_id ON gr_similar_books(gr_work_id)');

// Mark schema version so the web app knows setup is complete
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (val INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('DELETE FROM schema_version');
$pdo->exec('INSERT INTO schema_version (val) VALUES (' . APP_SCHEMA_VERSION . ')');

echo "Schema initialized (version " . APP_SCHEMA_VERSION . ")\n";
