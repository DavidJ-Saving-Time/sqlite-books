import csv
import os

# Ensure new files are group writable
os.umask(0o002)
import sqlite3
import uuid
import json
from datetime import datetime

# === CONFIGURATION ===
LIBRARY_PATH = "/home/david/nilla"  # Path to your Calibre library
CSV_FILE = "./thestory.csv"                   # Path to your CSV file
CUSTOM_COLUMN_ID = 2                     # Your custom column ID (genre)
CUSTOM_COLUMN_VALUE = "The StoryGraph"

# === HELPER FUNCTIONS ===
def safe_filename(name, max_length=150):
    """Make a safe folder/file name, truncated to avoid OS filename limits."""
    name = "".join(c for c in name if c.isalnum() or c in " _-").rstrip()
    return name[:max_length]

def title_sort(title: str) -> str:
    """Emulate Calibre's title_sort function."""
    if not title:
        return ''
    title = title.strip()
    articles = ['a', 'an', 'the']
    for article in articles:
        if title.lower().startswith(article + ' '):
            return title[len(article):].strip() + ', ' + article.capitalize()
    return title

def author_sort(author: str) -> str:
    """Sort author name (Last, First)."""
    if not author:
        return ''
    author = author.strip()
    if ',' in author:
        return author
    parts = author.split()
    if len(parts) > 1:
        return parts[-1] + ', ' + ' '.join(parts[:-1])
    return author

def uuid4_sqlite() -> str:
    """Generate a UUIDv4 string."""
    return str(uuid.uuid4())

# === MAIN SCRIPT ===
prefs_file = os.path.join(os.path.dirname(__file__), "preferences.json")
db_path = None
if os.path.exists(prefs_file):
    try:
        with open(prefs_file, "r", encoding="utf-8") as f:
            db_path = json.load(f).get("db_path")
    except Exception:
        db_path = None
if not db_path:
    db_path = os.path.join(LIBRARY_PATH, "metadata.db")
else:
    LIBRARY_PATH = os.path.dirname(db_path)
print(f"Using Calibre DB: {db_path}")

conn = sqlite3.connect(db_path)
conn.create_function('title_sort', 1, title_sort)
conn.create_function('author_sort', 1, author_sort)
conn.create_function('uuid4', 0, uuid4_sqlite)
cur = conn.cursor()

# Determine if custom column is multi-value
cur.execute("SELECT datatype, is_multiple FROM custom_columns WHERE id=?;", (CUSTOM_COLUMN_ID,))
col_info = cur.fetchone()
if not col_info:
    raise RuntimeError(f"No custom column with ID {CUSTOM_COLUMN_ID} found in Calibre DB.")
datatype, is_multiple = col_info
is_multi = (is_multiple == 1)
print(f"Custom column #{CUSTOM_COLUMN_ID} is {'multi-value' if is_multi else 'single-value'} ({datatype}).")

with open(CSV_FILE, newline='', encoding='utf-8') as csvfile:
    reader = csv.DictReader(csvfile)
    for row in reader:
        title = row['Title'].strip()
        authors_str = row['Authors'].strip() if row['Authors'] else "Unknown"
        isbn = row['ISBN/UID'].strip() if row['ISBN/UID'] else None
        tags = row['Tags'].strip() if row['Tags'] else ""
        review = row['Review'].strip() if row['Review'] else ""
        star = row['Star Rating'].strip() if row['Star Rating'] else None
        date_added = row['Date Added'].replace('/', '-') if row['Date Added'] else datetime.today().strftime('%Y-%m-%d')

        # Split multiple authors by comma or semicolon
        authors_list = [a.strip() for a in authors_str.replace(';', ',').split(',') if a.strip()]
        first_author = authors_list[0]
        folder_author = safe_filename(first_author + (" et al." if len(authors_list) > 1 else ""))

        # 1. Insert each author into DB
        for author in authors_list:
            cur.execute("INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?));", (author, author))
        conn.commit()

        # 2. Insert book
        cur.execute("""
            INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, isbn, uuid)
            VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, ?, ?, uuid4());
        """, (title, title, first_author, safe_filename(title), isbn))
        conn.commit()
        book_id = cur.lastrowid

        # 3. Link all authors to the book
        for author in authors_list:
            cur.execute("""
                INSERT INTO books_authors_link (book, author)
                SELECT ?, id FROM authors WHERE name=?;
            """, (book_id, author))
        conn.commit()

        # 4. Insert tags
        if tags:
            cur.execute("INSERT OR IGNORE INTO tags (name) VALUES (?);", (tags,))
            cur.execute("""
                INSERT INTO books_tags_link (book, tag)
                SELECT ?, id FROM tags WHERE name=?;
            """, (book_id, tags))
            conn.commit()

        # 5. Insert review
        if review:
            cur.execute("INSERT INTO comments (book, text) VALUES (?, ?);", (book_id, review))
            conn.commit()

        # 6. Set custom column value
        if is_multi:
            custom_value_table = f"custom_column_{CUSTOM_COLUMN_ID}"
            custom_link_table = f"books_custom_column_{CUSTOM_COLUMN_ID}_link"
            cur.execute(f"INSERT OR IGNORE INTO {custom_value_table} (value) VALUES (?);", (CUSTOM_COLUMN_VALUE,))
            cur.execute(f"""
                INSERT OR IGNORE INTO {custom_link_table} (book, value)
                SELECT ?, id FROM {custom_value_table} WHERE value=?;
            """, (book_id, CUSTOM_COLUMN_VALUE))
        else:
            custom_value_table = f"custom_column_{CUSTOM_COLUMN_ID}"
            custom_link_table = f"books_custom_column_{CUSTOM_COLUMN_ID}_link"
            cur.execute(f"INSERT OR IGNORE INTO {custom_value_table} (value) VALUES (?);", (CUSTOM_COLUMN_VALUE,))
            cur.execute(f"""
                INSERT OR REPLACE INTO {custom_link_table} (book, value)
                SELECT ?, id FROM {custom_value_table} WHERE value=?;
            """, (book_id, CUSTOM_COLUMN_VALUE))
        conn.commit()

        # 7. Create folder structure
        author_folder = os.path.join(LIBRARY_PATH, folder_author)
        os.makedirs(author_folder, exist_ok=True)
        book_folder = os.path.join(author_folder, f"{safe_filename(title)} ({book_id})")
        os.makedirs(book_folder, exist_ok=True)

        # 8. Create metadata.opf
        opf_content = f"""<?xml version="1.0" encoding="UTF-8"?>
<package version="2.0" xmlns="http://www.idpf.org/2007/opf">
  <metadata>
    <dc:title>{title}</dc:title>
    <dc:creator opf:role="aut">{first_author}</dc:creator>
    {"<dc:identifier id=\"isbn\">" + isbn + "</dc:identifier>" if isbn else ""}
    <dc:language>eng</dc:language>
    <meta name="calibre:timestamp" content="{date_added}T00:00:00+00:00"/>
    {"<meta name=\"calibre:tags\" content=\"" + tags + "\"/>" if tags else ""}
    {"<meta name=\"calibre:rating\" content=\"" + star + "\"/>" if star else ""}
    {"<dc:description>" + review + "</dc:description>" if review else ""}
    <meta name="calibre:custom:genre" content="{CUSTOM_COLUMN_VALUE}"/>
  </metadata>
</package>
"""
        with open(os.path.join(book_folder, "metadata.opf"), "w", encoding="utf-8") as f:
            f.write(opf_content)

        print(f"Added book '{title}' (ID {book_id}) to Calibre, author folder: '{folder_author}'.")

conn.close()

