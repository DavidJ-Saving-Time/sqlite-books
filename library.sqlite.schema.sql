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
  embedding BLOB,
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
CREATE VIRTUAL TABLE chunks_fts USING fts5(
  id UNINDEXED,
  item_id UNINDEXED,
  pages UNINDEXED,
  text,
  tokenize='porter'
)
/* chunks_fts(id,item_id,pages,text) */;
CREATE TABLE IF NOT EXISTS 'chunks_fts_data'(id INTEGER PRIMARY KEY, block BLOB);
CREATE TABLE IF NOT EXISTS 'chunks_fts_idx'(segid, term, pgno, PRIMARY KEY(segid, term)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS 'chunks_fts_content'(id INTEGER PRIMARY KEY, c0, c1, c2, c3);
CREATE TABLE IF NOT EXISTS 'chunks_fts_docsize'(id INTEGER PRIMARY KEY, sz BLOB);
CREATE TABLE IF NOT EXISTS 'chunks_fts_config'(k PRIMARY KEY, v) WITHOUT ROWID;
CREATE TRIGGER chunks_ai AFTER INSERT ON chunks BEGIN
  INSERT INTO chunks_fts(rowid, id, text, item_id, pages)
  VALUES (
    new.id,
    new.id,
    new.text,
    new.item_id,
    CASE
      WHEN new.display_start_label IS NOT NULL AND new.display_end_label IS NOT NULL
           AND new.display_start_label <> new.display_end_label
        THEN new.display_start_label || '–' || new.display_end_label
      WHEN new.page_start IS NOT NULL AND new.page_end IS NOT NULL AND new.page_start <> new.page_end
        THEN printf('%d–%d', new.page_start, new.page_end)
      WHEN new.page_start IS NOT NULL
        THEN CAST(new.page_start AS TEXT)
      ELSE ''
    END
  );
END;
CREATE TRIGGER chunks_ad AFTER DELETE ON chunks BEGIN
  INSERT INTO chunks_fts(chunks_fts, rowid, text) VALUES('delete', old.id, old.text);
END;
CREATE TRIGGER chunks_au AFTER UPDATE ON chunks BEGIN
  INSERT INTO chunks_fts(chunks_fts, rowid, text) VALUES('delete', old.id, old.text);
  INSERT INTO chunks_fts(rowid, id, text, item_id, pages)
  VALUES (
    new.id,
    new.id,
    new.text,
    new.item_id,
    CASE
      WHEN new.display_start_label IS NOT NULL AND new.display_end_label IS NOT NULL
           AND new.display_start_label <> new.display_end_label
        THEN new.display_start_label || '–' || new.display_end_label
      WHEN new.page_start IS NOT NULL AND new.page_end IS NOT NULL AND new.page_start <> new.page_end
        THEN printf('%d–%d', new.page_start, new.page_end)
      WHEN new.page_start IS NOT NULL
        THEN CAST(new.page_start AS TEXT)
      ELSE ''
    END
  );
END;
