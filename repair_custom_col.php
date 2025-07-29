<?php
// Path to your Calibre metadata.db
$dbPath = 'ebooks/metadata.db';

// Columns to repair
$columnsToRepair = [
    ['label' => 'shelf',          'name' => 'Shelf'],
    ['label' => 'recommendation', 'name' => 'Recommendation']
];

try {
    $db = new PDO("sqlite:" . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ($columnsToRepair as $col) {
        $columnLabel = $col['label'];
        $columnName  = $col['name'];

        // Fetch column ID
        $stmt = $db->prepare("SELECT id FROM custom_columns WHERE label = :label");
        $stmt->execute([':label' => $columnLabel]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            echo "Column '{$columnLabel}' not found. Skipping.\n";
            continue;
        }

        $customTable = "custom_column_" . $id;
        $linkTable   = "books_custom_column_" . $id . "_link";
        $view1       = "tag_browser_custom_column_" . $id;
        $view2       = "tag_browser_filtered_custom_column_" . $id;

        echo "Repairing column '{$columnName}' (label: '{$columnLabel}', ID: {$id})...\n";

        // Backup old tables
        if (tableExists($db, $customTable)) {
            $db->exec("ALTER TABLE {$customTable} RENAME TO {$customTable}_backup;");
            echo "  - Backed up {$customTable}.\n";
        }
        if (tableExists($db, $linkTable)) {
            $db->exec("ALTER TABLE {$linkTable} RENAME TO {$linkTable}_backup;");
            echo "  - Backed up {$linkTable}.\n";
        }

        // Drop views
        $db->exec("DROP VIEW IF EXISTS {$view1};");
        $db->exec("DROP VIEW IF EXISTS {$view2};");

        // Recreate custom column table
        $db->exec("
            CREATE TABLE {$customTable} (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT NOT NULL COLLATE NOCASE,
                link  TEXT NOT NULL DEFAULT '',
                UNIQUE(value)
            );
        ");
        echo "  - Recreated {$customTable}.\n";

        // Recreate link table
        $db->exec("
            CREATE TABLE {$linkTable} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                book INTEGER NOT NULL,
                value INTEGER NOT NULL,
                UNIQUE(book, value)
            );
        ");
        echo "  - Recreated {$linkTable}.\n";

        // Recreate views
        $db->exec("
            CREATE VIEW {$view1} AS SELECT
                id,
                value,
                (SELECT COUNT(id) FROM {$linkTable} WHERE value={$customTable}.id) count,
                (SELECT AVG(r.rating)
                 FROM {$linkTable},
                      books_ratings_link as bl,
                      ratings as r
                 WHERE {$linkTable}.value={$customTable}.id and bl.book={$linkTable}.book and
                       r.id = bl.rating and r.rating <> 0) avg_rating,
                value AS sort
            FROM {$customTable};
        ");

        $db->exec("
            CREATE VIEW {$view2} AS SELECT
                id,
                value,
                (SELECT COUNT({$linkTable}.id) FROM {$linkTable} WHERE value={$customTable}.id AND
                books_list_filter(book)) count,
                (SELECT AVG(r.rating)
                 FROM {$linkTable},
                      books_ratings_link as bl,
                      ratings as r
                 WHERE {$linkTable}.value={$customTable}.id AND bl.book={$linkTable}.book AND
                       r.id = bl.rating AND r.rating <> 0 AND
                       books_list_filter(bl.book)) avg_rating,
                value AS sort
            FROM {$customTable};
        ");
        echo "  - Recreated views.\n";

        // Migrate data if backup exists
        if (tableExists($db, $customTable . "_backup")) {
            $backupCols = tableColumns($db, $customTable . "_backup");
            if (in_array('book', $backupCols) && in_array('value', $backupCols)) {
                // Insert unique values
                $db->exec("
                    INSERT OR IGNORE INTO {$customTable} (value)
                    SELECT DISTINCT value FROM {$customTable}_backup WHERE value IS NOT NULL;
                ");

                // Insert link mappings
                $db->exec("
                    INSERT OR IGNORE INTO {$linkTable} (book, value)
                    SELECT b.book, c.id
                    FROM {$customTable}_backup AS b
                    JOIN {$customTable} AS c ON c.value = b.value;
                ");
                echo "  - Migrated data from backup.\n";
            }
        }

        echo "Finished repairing '{$columnName}' (label: '{$columnLabel}').\n\n";
    }

    echo "All repairs complete.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function tableColumns(PDO $db, string $table): array {
    $cols = [];
    foreach ($db->query("PRAGMA table_info('$table')") as $row) {
        $cols[] = $row['name'];
    }
    return $cols;
}
?>

