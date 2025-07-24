-- BEGIN TRANSACTION to ensure everything is inserted together
BEGIN TRANSACTION;

-- 1. Insert the new book into the books table
INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path)
VALUES ('My New Book', 'My New Book', 'John Doe', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, 'My_New_Book');

-- 2. Get the new book ID (for use in linking)
-- In sqlite3 shell, you can retrieve it with: SELECT last_insert_rowid();
-- Let's assume it's NEW_BOOK_ID

-- 3. Insert author if not already present
INSERT OR IGNORE INTO authors (name, sort)
VALUES ('John Doe', 'John Doe');

-- 4. Link the new book to the author
INSERT INTO books_authors_link (book, author)
SELECT last_insert_rowid(), id FROM authors WHERE name = 'John Doe';

-- 5. Insert NULL entries for all custom columns
-- This dynamically creates INSERT statements for each books_custom_column_X table
-- For sqlite3 shell:
--    .output /tmp/custom_inserts.sql
--    SELECT 'INSERT INTO ' || name || ' (book, value) VALUES (' || last_insert_rowid() || ', NULL);'
--    FROM sqlite_master
--    WHERE name LIKE 'books_custom_column_%';
--    .output stdout
--    .read /tmp/custom_inserts.sql
--
-- (The above lines are a trick to auto-generate the inserts)

-- END TRANSACTION
COMMIT;

