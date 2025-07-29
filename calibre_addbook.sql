INSERT INTO books(title, series_index, author_sort) VALUES (?, ?, ?);

UPDATE books SET title=? WHERE id=?;
UPDATE books SET sort=? WHERE id=?;
UPDATE books SET last_modified=? WHERE id=?;
INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?);

INSERT INTO authors(name,sort) VALUES (?,?);

DELETE FROM books_authors_link WHERE book=?;
INSERT INTO books_authors_link(book,author) VALUES(?, ?);

UPDATE books SET author_sort=? WHERE id=?;
UPDATE books SET last_modified=? WHERE id=?;
UPDATE books SET path=? WHERE id=?;
UPDATE books SET last_modified=? WHERE id=?;
UPDATE books SET has_cover=? WHERE id=?;
UPDATE books SET last_modified=? WHERE id=?;
UPDATE books SET timestamp=? WHERE id=?;
UPDATE books SET last_modified=? WHERE id=?;

INSERT INTO publishers(name) VALUES (?);
DELETE FROM books_publishers_link WHERE book=?;
INSERT INTO books_publishers_link(book,publisher) VALUES(?, ?);
UPDATE books SET last_modified=? WHERE id=?;

INSERT OR REPLACE INTO comments(book,text) VALUES (?,?);
UPDATE books SET last_modified=? WHERE id=?;

DELETE FROM books_languages_link WHERE book=?;
INSERT INTO books_languages_link(book,lang_code) VALUES(?, ?);
UPDATE books SET last_modified=? WHERE id=?;

UPDATE books SET pubdate=? WHERE id=?;
UPDATE books SET last_modified=? WHERE id=?;

DELETE FROM identifiers WHERE book=?;
INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, ?, ?);
UPDATE books SET last_modified=? WHERE id=?;

SELECT sort, series_index, author_sort, uuid, has_cover FROM books WHERE id=?;
INSERT OR REPLACE INTO data (book,format,uncompressed_size,name) VALUES (?,?,?,?);
UPDATE books SET last_modified=? WHERE id=?;


