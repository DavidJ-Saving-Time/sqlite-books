<?php
// Path to your Calibre metadata database
$dbPath = 'ebooks/metadata.db';

// Column settings (change these two values)
$columnLabel = 'mycolumn';   // Internal label (e.g., #mycolumn)
$columnName  = 'My Column';  // Display name shown in Calibre

try {
    $db = new PDO("sqlite:" . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --------------------------------------------------
    // Find the next available custom column number
    // --------------------------------------------------
    $sqlCheck = "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'custom_column_%'";
    $existingTables = $db->query($sqlCheck)->fetchAll(PDO::FETCH_COLUMN);

    $maxNumber = 0;
    foreach ($existingTables as $table) {
        if (preg_match('/custom_column_(\d+)/', $table, $matches)) {
            $num = (int)$matches[1];
            if ($num > $maxNumber) {
                $maxNumber = $num;
            }
        }
    }
    $nextNumber = $maxNumber + 1;

    // Table and view names
    $customTable = "custom_column_" . $nextNumber;
    $linkTable   = "books_custom_column_" . $nextNumber . "_link";
    $view1       = "tag_browser_custom_column_" . $nextNumber;
    $view2       = "tag_browser_filtered_custom_column_" . $nextNumber;

    // --------------------------------------------------
    // Insert into custom_columns
    // --------------------------------------------------
    $sqlInsert = "
        INSERT INTO custom_columns(label, name, datatype, is_multiple, editable, display, normalized)
        VALUES (:label, :name, 'text', False, True,
                '{\"use_decorations\": false, \"description\": \"\", \"web_search_template\": \"\"}',
                True);
    ";
    $stmt = $db->prepare($sqlInsert);
    $stmt->execute([
        ':label' => $columnLabel,
        ':name'  => $columnName
    ]);

    // --------------------------------------------------
    // Create custom column table
    // --------------------------------------------------
    $db->exec("
        CREATE TABLE {$customTable} (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            value TEXT NOT NULL COLLATE NOCASE,
            link  TEXT NOT NULL DEFAULT '',
            UNIQUE(value)
        );
    ");

    // --------------------------------------------------
    // Create link table
    // --------------------------------------------------
    $db->exec("
        CREATE TABLE {$linkTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book INTEGER NOT NULL,
            value INTEGER NOT NULL,
            UNIQUE(book, value)
        );
    ");

    // --------------------------------------------------
    // Create views
    // --------------------------------------------------
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

    echo "Custom column '{$columnName}' (label: '{$columnLabel}') added as {$customTable}.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

