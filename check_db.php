<?php
// check_calibre_db.php
// Verifies if the database schema matches Calibre's expectations.

$dbPath = 'ebooks/metadata.db';

try {
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $errors = [];

    // 1. Check required core tables
    $requiredTables = ['books', 'authors', 'tags', 'custom_columns'];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if (!$stmt->fetchColumn()) {
            $errors[] = "Missing required table: $table";
        }
    }

    // 2. Check custom column structure
    $stmt = $pdo->query("SELECT id, label, is_multiple FROM custom_columns");
    $customCols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($customCols as $col) {
        $id = (int)$col['id'];
        $label = $col['label'];
        $isMulti = (int)$col['is_multiple'];

        // Check value table
        $valueTable = "custom_column_$id";
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$valueTable'");
        $valueTableDef = $stmt->fetchColumn();
        if (!$valueTableDef) {
            $errors[] = "Missing value table for custom column '$label': $valueTable";
        } else {
            // Ensure correct columns: id, value, link
            if (!preg_match('/\bvalue\b/i', $valueTableDef)) {
                $errors[] = "Value table $valueTable does not have 'value' column.";
            }
        }

        // Check link table
        $linkTable = "books_custom_column_{$id}_link";
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$linkTable'");
        $linkTableDef = $stmt->fetchColumn();
        if (!$linkTableDef) {
            $errors[] = "Missing link table for custom column '$label': $linkTable";
        } else {
            if (!preg_match('/\bbook\b/i', $linkTableDef) || !preg_match('/\bvalue\b/i', $linkTableDef)) {
                $errors[] = "Link table $linkTable must have 'book' and 'value' columns.";
            }
        }

        // Check is_multiple flag consistency
        if ($isMulti !== 0 && $isMulti !== 1) {
            $errors[] = "Custom column '$label' (ID $id) has invalid is_multiple value: $isMulti.";
        }
    }

    // 3. Report results
    if (empty($errors)) {
        echo "Database structure matches Calibre's expectations.\n";
    } else {
        echo "Issues detected in the database schema:\n";
        foreach ($errors as $err) {
            echo "- $err\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

