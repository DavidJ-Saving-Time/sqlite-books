<?php
require_once __DIR__ . '/../db.php';

$pdo = getDatabaseConnection();
initializeCustomColumns($pdo);

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

echo "Schema initialized\n";
