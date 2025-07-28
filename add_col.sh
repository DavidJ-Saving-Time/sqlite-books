#!/bin/bash
# Ensure new files are group writable
umask 0002
# Determine DB path from PHP preferences
DB=$(php -r "require 'db.php'; echo currentDatabasePath();")

# Get the next custom column ID
NEXT_ID=$(sqlite3 "$DB" "SELECT COALESCE(MAX(id), 0) + 1 FROM custom_columns;")

# Insert into custom_columns (no search_terms column)
sqlite3 "$DB" "INSERT INTO custom_columns (id, label, name, datatype, mark_for_delete, editable, is_multiple, normalized, display) VALUES ($NEXT_ID, 'shelfs', 'myshelf', 'text', 0, 1, 0, 1, '{}');"

# Create the value and link tables for the new column
sqlite3 "$DB" "CREATE TABLE custom_column_${NEXT_ID} (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL COLLATE NOCASE, link TEXT NOT NULL DEFAULT '', UNIQUE(value));"
sqlite3 "$DB" "CREATE TABLE books_custom_column_${NEXT_ID}_link (id INTEGER PRIMARY KEY AUTOINCREMENT, book INTEGER NOT NULL, value INTEGER NOT NULL, UNIQUE(book, value));"

# Populate default NULL entry for existing books
sqlite3 "$DB" "INSERT INTO books_custom_column_${NEXT_ID}_link (book, value) SELECT id, NULL FROM books;"

echo "Custom column #airecomend (airecomends) created with ID: $NEXT_ID"
