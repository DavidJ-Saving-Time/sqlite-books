<?php
// One-time cleanup script to remove orphaned entries from Calibre
// book custom column tables. Orphaned rows can appear if books or
// custom column values were deleted without cleaning up their links.
//
// Run this script once as an admin to tidy existing databases:
//   php scripts/fix_orphaned_custom_columns.php
//
// The script connects to the configured database and scans all tables
// named books_custom_column_*. For each table it removes rows whose
// book id no longer exists in the books table or whose value id no
// longer exists in the corresponding custom_column_X table.

require_once __DIR__ . '/../db.php';

$pdo = getDatabaseConnection();

$totalRemoved = 0;

try {
    // Fetch all book custom column tables
    $stmt = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'books_custom_column_%'"
    );
    $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    foreach ($tables as $table) {
        if (!preg_match('/books_custom_column_(\d+)(?:_link)?$/', $table, $m)) {
            continue;
        }
        $id = (int)$m[1];
        $valueTable = "custom_column_{$id}";

        // Ensure the value table exists before proceeding
        $exists = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
        );
        $exists->execute([$valueTable]);
        if (!$exists->fetchColumn()) {
            continue; // Skip if the value table is missing
        }

        // Remove link rows referencing missing books
        $removedBooks = $pdo->exec(
            "DELETE FROM {$table} WHERE book NOT IN (SELECT id FROM books)"
        );
        if ($removedBooks === false) {
            $removedBooks = 0;
        }

        // Remove link rows referencing missing values
        $removedValues = $pdo->exec(
            "DELETE FROM {$table} WHERE value NOT IN (SELECT id FROM {$valueTable})"
        );
        if ($removedValues === false) {
            $removedValues = 0;
        }

        $totalRemoved += $removedBooks + $removedValues;
        echo sprintf(
            "%s: removed %d book orphans, %d value orphans\n",
            $table,
            $removedBooks,
            $removedValues
        );
    }

    echo "Total orphaned rows removed: {$totalRemoved}\n";
} catch (PDOException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
