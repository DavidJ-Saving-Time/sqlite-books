<?php
// Path to your Calibre metadata.db
$dbPath = 'ebooks2/metadata.db';

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch all custom columns
    $columns = $pdo->query("SELECT id, label, name FROM custom_columns")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($columns)) {
        echo "No custom columns found.\n";
        exit;
    }

    foreach ($columns as $col) {
        $id = (int)$col['id'];
        $label = $col['label'];
        $name = $col['name'];
        $errors = [];

        // Expected tables and views
        $customTable = "custom_column_$id";
        $linkTable   = "books_custom_column_{$id}_link";
        $view1       = "tag_browser_custom_column_$id";
        $view2       = "tag_browser_filtered_custom_column_$id";

        // Check if value table exists
        if (!tableExists($pdo, $customTable)) {
            $errors[] = "Missing table: $customTable";
        } else {
            // Check columns of custom_column_X
            $expected = ['id', 'value', 'link'];
            $cols = tableColumns($pdo, $customTable);
            if (array_diff($expected, $cols)) {
                $errors[] = "$customTable has incorrect columns: " . implode(', ', $cols);
            }
        }

        // Check if link table exists
        if (!tableExists($pdo, $linkTable)) {
            $errors[] = "Missing table: $linkTable";
        } else {
            $expected = ['id', 'book', 'value'];
            $cols = tableColumns($pdo, $linkTable);
            if (array_diff($expected, $cols)) {
                $errors[] = "$linkTable has incorrect columns: " . implode(', ', $cols);
            }
        }

        // Check if views exist
        if (!viewExists($pdo, $view1)) {
            $errors[] = "Missing view: $view1";
        }
        if (!viewExists($pdo, $view2)) {
            $errors[] = "Missing view: $view2";
        }

        // Report results
        if (!empty($errors)) {
            echo "Custom Column #$id ('$label' / '$name') has issues:\n";
            foreach ($errors as $e) {
                echo "  - $e\n";
            }
        } else {
            echo "Custom Column #$id ('$label' / '$name') is OK.\n";
        }
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Helper functions
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function tableColumns(PDO $pdo, string $table): array {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info('$table')") as $row) {
        $cols[] = $row['name'];
    }
    return $cols;
}

function viewExists(PDO $pdo, string $view): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='view' AND name=?");
    $stmt->execute([$view]);
    return $stmt->fetchColumn() !== false;
}
?>

