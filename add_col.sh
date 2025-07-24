!/bin/bash
DB="metadata.old.db"

# Get the next custom column ID
NEXT_ID=$(sqlite3 "$DB" "SELECT COALESCE(MAX(id), 0) + 1 FROM custom_columns;")

# Insert into custom_columns (no search_terms column)
sqlite3 "$DB" "INSERT INTO custom_columns (id, label, name, datatype, mark_for_delete, editable, is_multiple, normalized, display) VALUES ($NEXT_ID, 'shelfs', 'myshelf', 'text', 0, 1, 0, 1, '{}');"

# Create the books_custom_column_X table
sqlite3 "$DB" "CREATE TABLE books_custom_column_${NEXT_ID} (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT);"

# Populate with NULL for all existing books
sqlite3 "$DB" "INSERT INTO books_custom_column_${NEXT_ID} (book, value) SELECT id, NULL FROM books;"

echo "Custom column #airecomend (airecomends) created with ID: $NEXT_ID"
