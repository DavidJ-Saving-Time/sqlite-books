#!/usr/bin/env python3
"""
Fetch https://www.goodreads.com/work/shelves/{gr_id} for every book that has a
GR identifier and save the top-3 community shelf tags as Calibre tags,
replacing any existing tags on the book.

Reading-status shelves (to-read, currently-reading, read, etc.) and other
non-genre shelves are filtered out before picking the top 3.

Usage:
    python3 scrape_goodreads_shelves.py              # all users in users.json
    python3 scrape_goodreads_shelves.py david        # one user
    python3 scrape_goodreads_shelves.py /path/to/metadata.db
    python3 scrape_goodreads_shelves.py --dry-run [user]   # print without saving
"""

import json
import os
import random
import re
import sqlite3
import sys
import time
from datetime import datetime

import requests
from bs4 import BeautifulSoup

# ── Config ────────────────────────────────────────────────────────────────────

PERMANENT_ERRORS = {"404 not found"}

DELAY_MIN     = 5
DELAY_MAX     = 10
BATCH_SIZE    = 50
BATCH_PAUSE   = 30
TOP_N         = 3
SCRIPT_DIR    = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR      = os.path.dirname(SCRIPT_DIR)
DATA_DIR      = os.path.join(ROOT_DIR, "data")
USERS_JSON    = os.path.join(ROOT_DIR, "users.json")
PROGRESS_FILE = os.path.join(DATA_DIR, "shelves_progress.json")
LOG_FILE      = os.path.join(DATA_DIR, "shelves_log.tsv")

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.5",
}

# Shelves that indicate reading status or ownership — not genres
SKIP_SHELVES = {
    "to-read", "currently-reading", "read", "re-read", "did-not-finish", "dnf",
    "owned", "own", "owned-books", "bought", "wishlist", "wish-list",
    "favorites", "favourite", "favourites", "favorite",
    "library", "borrowed", "library-book",
    "ebook", "ebooks", "e-book", "e-books", "kindle", "kobo",
    "audiobook", "audiobooks", "audio", "audible",
    "kindle-unlimited", "kindle-unlimited-books", "ku",
    "default", "general", "shelf",
    "abandoned", "gave-up", "stopped",
    "not-finished", "unfinished",
    "maybe", "maybe-read", "possibly",
    "on-hold", "on-deck", "next",
    "2024", "2023", "2022", "2021", "2020",  # year shelves
}

# ── Fetch + parse ─────────────────────────────────────────────────────────────

def fetch_shelves(gr_id):
    """
    Fetch the GR work/shelves page and return a list of (shelf_name, count)
    tuples sorted by count descending, filtered of non-genre shelves.
    Returns (list, error_string_or_None).
    """
    url = f"https://www.goodreads.com/work/shelves/{gr_id}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=20)
    except Exception as e:
        return None, str(e)

    if r.status_code == 404:
        return None, "404 not found"
    if r.status_code != 200:
        return None, f"HTTP {r.status_code}"

    soup = BeautifulSoup(r.text, "html.parser")
    shelves = []
    for el in soup.select(".shelfStat"):
        name_tag = el.select_one("a.mediumText")
        if not name_tag:
            continue
        name = name_tag.get_text(strip=True).lower()
        if name in SKIP_SHELVES:
            continue
        count_div = el.select_one(".smallText")
        count_text = count_div.get_text(strip=True) if count_div else ""
        count_m = re.search(r"([\d,]+)", count_text)
        count = int(count_m.group(1).replace(",", "")) if count_m else 0
        shelves.append((name, count))

    shelves.sort(key=lambda x: x[1], reverse=True)
    return shelves, None

# ── Database ──────────────────────────────────────────────────────────────────

def _title_sort_fn(title):
    if not title:
        return title
    for article in ("the ", "a ", "an "):
        if title.lower().startswith(article):
            return title[len(article):].strip() + ", " + title[:len(article)].strip()
    return title

def open_db(db_path):
    con = sqlite3.connect(db_path)
    con.create_function("title_sort", 1, _title_sort_fn)
    con.execute("PRAGMA foreign_keys = ON")
    return con

def get_books_with_work_id(db_path):
    con = open_db(db_path)
    con.row_factory = sqlite3.Row
    rows = con.execute("""
        SELECT b.id, b.title, i.val AS gr_work_id
        FROM books b
        JOIN identifiers i ON i.book = b.id AND i.type = 'gr_work_id'
        ORDER BY b.id
    """).fetchall()
    con.close()
    return [dict(r) for r in rows]

def save_tags(db_path, book_id, tags):
    """Replace all existing tags on the book with the supplied list."""
    con = open_db(db_path)
    try:
        # Remove all existing tags for this book
        con.execute("DELETE FROM books_tags_link WHERE book = ?", (book_id,))
        for tag_name in tags:
            con.execute("INSERT OR IGNORE INTO tags (name, link) VALUES (?, '')", (tag_name,))
            row = con.execute(
                "SELECT id, name FROM tags WHERE name = ? COLLATE NOCASE", (tag_name,)
            ).fetchone()
            tag_id = row[0]
            # Normalise casing in the tags table to match what we store
            if row[1] != tag_name:
                con.execute("UPDATE tags SET name = ? WHERE id = ?", (tag_name, tag_id))
            con.execute(
                "INSERT OR IGNORE INTO books_tags_link (book, tag) VALUES (?, ?)",
                (book_id, tag_id),
            )
        con.commit()
    finally:
        con.close()

# ── Logging ───────────────────────────────────────────────────────────────────

def write_log_header():
    if not os.path.exists(LOG_FILE):
        with open(LOG_FILE, "w", encoding="utf-8") as f:
            f.write("book_id\ttitle\tgr_id\ttags_saved\terror\n")

def write_log(book_id, title, gr_id, tags, error=""):
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(f"{book_id}\t{title}\t{gr_id}\t{', '.join(tags)}\t{error}\n")

# ── Progress ──────────────────────────────────────────────────────────────────

def load_progress():
    if os.path.exists(PROGRESS_FILE):
        with open(PROGRESS_FILE, encoding="utf-8") as f:
            return json.load(f)
    return {"done_ids": []}

def save_progress(progress):
    with open(PROGRESS_FILE, "w", encoding="utf-8") as f:
        json.dump({**progress, "updated": datetime.now().isoformat()}, f)

# ── Main ──────────────────────────────────────────────────────────────────────

def resolve_db_paths(argv):
    args = [a for a in argv[1:] if not a.startswith("--")]
    if args:
        arg = args[0]
        if arg.endswith(".db") or os.sep in arg:
            return [(arg, arg)]
        if not os.path.exists(USERS_JSON):
            print(f"ERROR: {USERS_JSON} not found"); sys.exit(1)
        users = json.load(open(USERS_JSON))
        if arg not in users:
            print(f"ERROR: user '{arg}' not in users.json"); sys.exit(1)
        return [(arg, users[arg]["prefs"]["db_path"])]
    if not os.path.exists(USERS_JSON):
        print("ERROR: pass a db path or users.json must exist"); sys.exit(1)
    users = json.load(open(USERS_JSON))
    paths = []
    for uname, udata in users.items():
        db = udata.get("prefs", {}).get("db_path", "")
        if db and os.path.exists(db):
            paths.append((uname, db))
    return paths


def process_db(label, db_path, progress, dry_run=False):
    rows = get_books_with_work_id(db_path)
    done = set(progress["done_ids"])
    todo = [r for r in rows if r["id"] not in done]
    total = len(todo)

    if total == 0:
        print(f"[{label}] Nothing to do.")
        return

    mode = "DRY RUN — " if dry_run else ""
    print(f"[{label}] {mode}{total} books to process  (DB: {db_path})")

    ok_count = err_count = 0

    for i, book in enumerate(todo, 1):
        bid      = book["id"]
        title    = book["title"] or ""
        work_id  = book["gr_work_id"]

        print(f"  [{i}/{total}] {title[:55]!r}  work:{work_id} ... ", end="", flush=True)

        shelves, err = fetch_shelves(work_id)

        if err:
            print(f"ERROR: {err}")
            write_log(bid, title, work_id, [], err)
            err_count += 1
            if err in PERMANENT_ERRORS:
                done.add(bid)  # bad work ID — no point retrying
        else:
            top = [name for name, _ in shelves[:TOP_N]]
            if not top:
                print("no genre shelves found")
                write_log(bid, title, work_id, [], "no_genre_shelves")
            else:
                if not dry_run:
                    save_tags(db_path, bid, top)
                    counts_val = ";".join(f"{name}:{cnt}" for name, cnt in shelves[:TOP_N])
                    con = open_db(db_path)
                    con.execute(
                        "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_shelf_counts', ?)",
                        (bid, counts_val),
                    )
                    con.commit()
                    con.close()
                write_log(bid, title, work_id, top)
                counts = [f"{name}({cnt})" for name, cnt in shelves[:TOP_N]]
                print(", ".join(counts))
                ok_count += 1
            done.add(bid)

        progress["done_ids"] = list(done)

        if i % 10 == 0:
            save_progress(progress)

        if i % BATCH_SIZE == 0:
            print(f"\n  ── Batch {i // BATCH_SIZE} done: {ok_count} ok, {err_count} errors ──")
            print(f"  Pausing {BATCH_PAUSE}s ...\n")
            time.sleep(BATCH_PAUSE)
        else:
            time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    save_progress(progress)
    print(f"\n[{label}] Done: {ok_count} updated, {err_count} errors")


def main():
    dry_run = "--dry-run" in sys.argv

    db_paths = resolve_db_paths(sys.argv)
    write_log_header()
    progress = load_progress()

    for label, db_path in db_paths:
        if not os.path.exists(db_path):
            print(f"[{label}] DB not found: {db_path} — skipping")
            continue
        process_db(label, db_path, progress, dry_run=dry_run)

    print(f"\nAll done. Log: {LOG_FILE}")


if __name__ == "__main__":
    main()
