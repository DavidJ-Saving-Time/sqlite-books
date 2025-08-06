<?php
// One-time cleanup script to remove orphaned entries from Calibre
// book custom column tables across all user databases. Orphaned rows
// can appear if books or custom column values were deleted without
// cleaning up their links.
//
// Run this script once as an admin to tidy existing databases:
//   php scripts/fix_orphaned_custom_columns.php
//
// The script reads users.json to locate each user's database and scans
// all tables named books_custom_column_*. For each table it removes rows
// whose book id no longer exists in the books table or whose value id no
// longer exists in the corresponding custom_column_X table.

/**
 * Return a list of unique database paths from users.json.
 */
function getDatabasePaths(): array {
    $file = __DIR__ . '/../users.json';
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    $paths = [];
    foreach ($data as $user) {
        $path = $user['prefs']['db_path'] ?? null;
        if ($path) {
            $paths[$path] = true; // use keys to deduplicate
        }
    }
    return array_keys($paths);
}

/**
 * Open a PDO connection to the given SQLite database.
 */
function connect(string $path): ?PDO {
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    } catch (PDOException $e) {
        fwrite(STDERR, "Failed to connect to {$path}: " . $e->getMessage() . "\n");
        return null;
    }
}

/**
 * Remove orphaned custom column entries for the provided connection.
 */
function cleanupDatabase(PDO $pdo): int {
    $totalRemoved = 0;

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

    return $totalRemoved;
}

$paths = getDatabasePaths();
if (!$paths) {
    fwrite(STDERR, "No database paths found in users.json\n");
    exit(1);
}

$grandTotal = 0;
foreach ($paths as $dbPath) {
    $fullPath = __DIR__ . '/../' . $dbPath;
    echo "Processing {$dbPath}\n";
    $pdo = connect($fullPath);
    if (!$pdo) {
        continue;
    }
    try {
        $removed = cleanupDatabase($pdo);
        $grandTotal += $removed;
        echo "Total orphaned rows removed from {$dbPath}: {$removed}\n";
    } catch (PDOException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    }
}

echo "Grand total orphaned rows removed: {$grandTotal}\n";
