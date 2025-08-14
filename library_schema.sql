CREATE TABLE items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  author TEXT,
  year INTEGER,
  display_offset INTEGER DEFAULT 0,
  library_book_id INTEGER,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE sqlite_sequence(name,seq);
CREATE TABLE chunks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL,
  section TEXT,
  page_start INTEGER,
  page_end INTEGER,
  text TEXT NOT NULL,
  embedding BLOB,                 -- store as binary-packed floats (smaller) or JSON
  token_count INTEGER,
  display_start INTEGER,
  display_end INTEGER,
  display_start_label TEXT,
  display_end_label TEXT,
  FOREIGN KEY(item_id) REFERENCES items(id)
);
CREATE INDEX idx_chunks_item ON chunks(item_id);
CREATE TABLE page_map (
  item_id INTEGER NOT NULL,
  pdf_page INTEGER NOT NULL,
  display_label TEXT,
  display_number INTEGER,
  method TEXT,
  confidence REAL,
  PRIMARY KEY (item_id, pdf_page)
);
