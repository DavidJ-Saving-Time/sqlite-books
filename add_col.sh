#!/bin/bash
# Ensure new files are group writable
umask 0002
# Determine DB path from PHP preferences
DB=$(php -r "require 'db.php'; echo currentDatabasePath();")

# Get the next custom column ID
NEXT_ID=$(sqlite3 "$DB" "SELECT COALESCE(MAX(id), 0) + 1 FROM custom_columns;")

# Insert into custom_columns (no search_terms column)
sqlite3 "$DB" "INSERT INTO custom_columns (id, label, name, datatype, is_multiple, editable, display, normalized) VALUES ($NEXT_ID, 'shelfs', 'myshelf', 'text', 0, 1, '{}', 1);"

# Create the value and link tables for the new column
sqlite3 "$DB" "CREATE TABLE custom_column_${NEXT_ID} (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL COLLATE NOCASE, link TEXT NOT NULL DEFAULT '', UNIQUE(value));"
sqlite3 "$DB" "CREATE TABLE books_custom_column_${NEXT_ID}_link (id INTEGER PRIMARY KEY AUTOINCREMENT, book INTEGER NOT NULL, value INTEGER NOT NULL, UNIQUE(book, value));"

# Create the tag browser views if they don't already exist
sqlite3 "$DB" "CREATE VIEW IF NOT EXISTS tag_browser_custom_column_${NEXT_ID} AS
    SELECT
        id,
        value,
        (SELECT COUNT(id) FROM books_custom_column_${NEXT_ID}_link WHERE value=custom_column_${NEXT_ID}.id) count,
        (SELECT AVG(r.rating)
            FROM books_custom_column_${NEXT_ID}_link,
                 books_ratings_link AS bl,
                 ratings AS r
            WHERE books_custom_column_${NEXT_ID}_link.value=custom_column_${NEXT_ID}.id
              AND bl.book=books_custom_column_${NEXT_ID}_link.book
              AND r.id = bl.rating
              AND r.rating <> 0) avg_rating,
        value AS sort
    FROM custom_column_${NEXT_ID};"

sqlite3 "$DB" "CREATE VIEW IF NOT EXISTS tag_browser_filtered_custom_column_${NEXT_ID} AS
    SELECT
        id,
        value,
        (SELECT COUNT(books_custom_column_${NEXT_ID}_link.id)
         FROM books_custom_column_${NEXT_ID}_link
         WHERE value=custom_column_${NEXT_ID}.id AND books_list_filter(book)) count,
        (SELECT AVG(r.rating)
            FROM books_custom_column_${NEXT_ID}_link,
                 books_ratings_link AS bl,
                 ratings AS r
            WHERE books_custom_column_${NEXT_ID}_link.value=custom_column_${NEXT_ID}.id
              AND bl.book=books_custom_column_${NEXT_ID}_link.book
              AND r.id = bl.rating
              AND r.rating <> 0
              AND books_list_filter(bl.book)) avg_rating,
        value AS sort
    FROM custom_column_${NEXT_ID};"

# Populate default NULL entry for existing books
sqlite3 "$DB" "INSERT INTO books_custom_column_${NEXT_ID}_link (book, value) SELECT id, NULL FROM books;"

echo "Custom column #airecomend (airecomends) created with ID: $NEXT_ID"
