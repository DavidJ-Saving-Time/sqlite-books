<?php
require_once 'db.php';
$pdo = getDatabaseConnection();
// this shows how to create multi-value and single-value calibre multi-value structure for single-value
// Create single-value column: shelf
$pdo->exec("
    INSERT INTO custom_columns
    (label, name, datatype, mark_for_delete, editable, display, is_multiple, normalized)
    VALUES ('shelf', 'Shelf', 'text', 0, 1, '{}', 0, 0)
");
$shelfId = $pdo->lastInsertId();

// Single-value column table (different structure)
$pdo->exec("
    CREATE TABLE custom_column_{$shelfId} (
        book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE,
        value TEXT
    )
    ");

// Create multi-value custom column: #genre
$pdo->exec("
    INSERT INTO custom_columns
    (label, name, datatype, mark_for_delete, editable, display, is_multiple, normalized)
    VALUES ('#genre', 'Genre', 'text', 0, 1, '{}', 1, 1)
");
$genreId = $pdo->lastInsertId();

$pdo->exec("
    CREATE TABLE custom_column_{$genreId} (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        value TEXT NOT NULL COLLATE NOCASE,
        link TEXT NOT NULL DEFAULT '',
        UNIQUE(value)
    )
");
$pdo->exec("
    CREATE TABLE books_custom_column_{$genreId}_link (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        book INTEGER NOT NULL,
        value INTEGER NOT NULL,
        UNIQUE(book, value)
    )
");
