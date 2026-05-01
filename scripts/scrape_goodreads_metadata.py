#!/usr/bin/env python3
"""
Fetch and save Goodreads metadata for every book that already has a Goodreads ID.

For each book, fetches https://www.goodreads.com/book/show/{gr_id} and saves:
  - Description (replaces existing — GR text is cleaner)
  - Series + index (only if the book has none)
  - Genres → Calibre tags (additive, never removes existing tags)
  - ISBN, ISBN13, ASIN → identifiers (INSERT OR IGNORE — won't overwrite user data)
  - Publisher (replaces)
  - Publication date (replaces — existing data is unreliable)
  - Page count → stored as gr_pages identifier
  - Avg rating + count → stored as gr_rating / gr_rating_count identifiers (replaces)

Usage:
    python3 scrape_goodreads_metadata.py              # all users in users.json
    python3 scrape_goodreads_metadata.py david        # one user
    python3 scrape_goodreads_metadata.py /path/to/metadata.db
"""

import json
import os
import random
import re
import sqlite3
import sys
import time
import unicodedata
from datetime import datetime, timezone

import requests
from bs4 import BeautifulSoup

# ── Config ────────────────────────────────────────────────────────────────────

# Errors that mean the GR ID is wrong or the page will never exist — don't retry these.
PERMANENT_ERRORS = {
    "404 not found",
    "book node not found in Apollo state",
    "__NEXT_DATA__ not found",
}

DELAY_MIN     = 5
DELAY_MAX     = 10
BATCH_SIZE    = 50
BATCH_PAUSE   = 30
SCRIPT_DIR    = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR      = os.path.dirname(SCRIPT_DIR)
DATA_DIR      = os.path.join(ROOT_DIR, "data")
USERS_JSON    = os.path.join(ROOT_DIR, "users.json")
PROGRESS_FILE = os.path.join(DATA_DIR, "scrape_gr_progress.json")
LOG_FILE      = os.path.join(DATA_DIR, "scrape_gr_log.tsv")

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.5",
}

# ── SQLite helpers ────────────────────────────────────────────────────────────

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

# ── Goodreads fetch + parse ───────────────────────────────────────────────────

def fetch_book_page(gr_id, _redirected=False):
    """
    Fetch a Goodreads book page and return the parsed data dict, or None on failure.
    Returns (data_dict, error_string).
    data_dict keys: description, series_name, series_index, genres,
                    isbn, isbn13, asin, publisher, pub_timestamp_ms,
                    num_pages, avg_rating, rating_count
    """
    url = f"https://www.goodreads.com/book/show/{gr_id}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=20)
    except Exception as e:
        return None, str(e)

    if r.status_code == 404:
        return None, "404 not found"
    if r.status_code != 200:
        return None, f"HTTP {r.status_code}"

    m = re.search(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>',
                  r.text, re.DOTALL)
    if not m:
        return None, "__NEXT_DATA__ not found"

    nd = json.loads(m.group(1))
    apollo = nd.get("props", {}).get("pageProps", {}).get("apolloState", {})
    if not apollo:
        return None, "apolloState missing"

    # Find the Book node for this exact legacy ID via ROOT_QUERY
    book_ref = None
    for key, val in apollo.get("ROOT_QUERY", {}).items():
        if key.startswith("getBookByLegacyId(") and isinstance(val, dict):
            book_ref = val.get("__ref")
            break

    if not book_ref or book_ref not in apollo:
        return None, "book node not found in Apollo state"

    book = apollo[book_ref]

    # ── Description ──────────────────────────────────────────────────────────
    # Prefer HTML version (Calibre stores HTML in comments); fall back to stripped
    description = book.get("description", "")
    # Also check for stripped version; we'll use HTML as primary
    for k, v in book.items():
        if k.startswith('description(') and '"stripped":false' in k and isinstance(v, str):
            description = v
            break
    # If we only found the stripped key, use it
    if not description:
        for k, v in book.items():
            if k.startswith('description(') and isinstance(v, str):
                description = v
                break

    # ── Series ───────────────────────────────────────────────────────────────
    series_name  = None
    series_index = None
    book_series  = book.get("bookSeries") or []
    if book_series:
        entry = book_series[0]
        pos = str(entry.get("userPosition") or "1")
        # userPosition can be "1", "1.5", "1-2" (omnibus), "0.5", etc.
        # Take the first numeric part
        m = re.match(r"[\d.]+", pos)
        series_index = float(m.group()) if m else 1.0
        series_ref   = (entry.get("series") or {}).get("__ref")
        if series_ref and series_ref in apollo:
            series_name = apollo[series_ref].get("title", "")

    # ── Genres ───────────────────────────────────────────────────────────────
    genres = []
    for bg in book.get("bookGenres") or []:
        name = (bg.get("genre") or {}).get("name", "")
        if name:
            genres.append(name)

    # ── Details ──────────────────────────────────────────────────────────────
    details = book.get("details", {}) or {}
    isbn      = details.get("isbn", "") or ""
    isbn13    = details.get("isbn13", "") or ""
    asin      = details.get("asin", "") or ""   # fallback only
    publisher = details.get("publisher", "") or ""
    pub_ms    = details.get("publicationTime")   # Unix ms or None
    num_pages = details.get("numPages")          # int or None

    # ── Language ─────────────────────────────────────────────────────────────
    lang_obj  = details.get("language") or {}
    language  = (lang_obj.get("name") or "").strip() if isinstance(lang_obj, dict) else str(lang_obj).strip()
    is_english = not language or language.lower().startswith("eng") or language.lower() == "english"

    # ── Re-fetch English edition if this page is non-English ─────────────────
    fetched_gr_id = str(gr_id)
    if not is_english and not _redirected:
        en_link = re.search(r'<link[^>]+hrefLang="en"[^>]+href="([^"]+)"', r.text)
        if not en_link:
            en_link = re.search(r'<link[^>]+href="([^"]+)"[^>]+hrefLang="en"', r.text)
        if en_link:
            en_url = en_link.group(1)
            try:
                r2 = requests.get(en_url, headers=HEADERS, timeout=20)
                if r2.status_code == 200:
                    id_m = re.search(r'/book/show/(\d+)', r2.url)
                    if id_m:
                        fetched_gr_id = id_m.group(1)
                    print(f"[redirected to EN, id={fetched_gr_id}] ", end="", flush=True)
                    return fetch_book_page(fetched_gr_id, _redirected=True)
            except Exception:
                pass  # fall through and use original page

    # ── Links: Kindle ASIN + Amazon physical ASIN ────────────────────────────
    def _extract_asin(url):
        m = re.search(r'/(?:gp/product|dp)/([A-Z0-9]{10})(?:[/?]|$)', url or "")
        return m.group(1) if m else ""

    links_node  = book.get("links") or {}
    kindle_asin = ""
    amazon_asin = ""

    primary = links_node.get("primaryAffiliateLink") or {}
    if primary.get("__typename") == "KindleLink":
        kindle_asin = _extract_asin(primary.get("url", ""))

    for link in links_node.get("secondaryAffiliateLinks") or []:
        if link.get("ref") == "x_gr_bb_amazon":
            candidate = _extract_asin(link.get("url", ""))
            # Kindle ASINs start with 'B' — skip them here, physical ASINs are numeric
            if candidate and not candidate.startswith("B"):
                amazon_asin = candidate
            break

    # Prefer link-extracted physical ASIN; fall back to details.asin
    # If the fallback is itself a Kindle ASIN (starts with B), promote it to
    # kindle_asin and leave asin empty — don't store a digital ID as the physical one
    asin = amazon_asin or asin
    if asin.startswith("B") and not kindle_asin:
        kindle_asin = asin
        asin = ""
    elif asin.startswith("B"):
        asin = ""

    # ── Format (filter out audiobooks) ───────────────────────────────────────
    book_format  = (details.get("format") or "").strip()
    AUDIO_FORMATS = {"audiobook", "audio cd", "mp3 cd", "audible audio", "audio cassette"}
    is_audiobook  = book_format.lower() in AUDIO_FORMATS or "audio" in book_format.lower()

    # ── Work stats + work ID (via work ref) ──────────────────────────────────
    avg_rating       = None
    rating_count     = None
    ratings_dist     = ""
    gr_work_id       = ""
    original_pub_year = ""
    work_ref = book.get("work", {}).get("__ref") if isinstance(book.get("work"), dict) else None
    if work_ref and work_ref in apollo:
        work_node    = apollo[work_ref]
        stats        = work_node.get("stats", {}) or {}
        avg_rating   = stats.get("averageRating")
        rating_count = stats.get("ratingsCount")
        dist         = stats.get("ratingsCountDist")
        if isinstance(dist, list) and dist:
            ratings_dist = ",".join(str(int(x)) for x in dist)
        legacy = work_node.get("legacyId")
        if legacy:
            gr_work_id = str(legacy)
        work_details = work_node.get("details", {}) or {}
        orig_ms = work_details.get("publicationTime")
        if orig_ms:
            try:
                original_pub_year = str(datetime.fromtimestamp(orig_ms / 1000, tz=timezone.utc).year)
            except Exception:
                pass

    # ── Reviews (top 5 most-liked, already sorted by GR) ─────────────────────
    reviews = []
    review_edges = ((apollo.get("ROOT_QUERY") or {}).get("getReviews") or {}).get("edges") or []
    for edge in review_edges[:5]:
        rev_ref = edge.get("node", {}).get("__ref")
        if not rev_ref or rev_ref not in apollo:
            continue
        rev = apollo[rev_ref]
        if rev.get("spoilerStatus"):
            continue   # skip spoilers

        reviewer_name = ""
        reviewer_url  = ""
        user_ref = rev.get("creator", {}).get("__ref")
        if user_ref and user_ref in apollo:
            user = apollo[user_ref]
            reviewer_name = user.get("name", "")
            reviewer_url  = user.get("webUrl", "")

        created_ms = rev.get("createdAt")
        review_date = ""
        if created_ms:
            review_date = datetime.fromtimestamp(
                created_ms / 1000, tz=timezone.utc
            ).strftime("%Y-%m-%d")

        reviews.append({
            "gr_review_id": rev.get("id", ""),
            "reviewer":     reviewer_name,
            "reviewer_url": reviewer_url,
            "rating":       rev.get("rating"),
            "review_date":  review_date,
            "text":         rev.get("text", ""),
            "like_count":   rev.get("likeCount", 0),
            "spoiler":      1 if rev.get("spoilerStatus") else 0,
        })
        if len(reviews) >= 5:
            break

    image_url = (book.get("imageUrl") or "").strip()

    return {
        "description":        description,
        "series_name":        series_name,
        "series_index":       series_index,
        "genres":             genres,
        "isbn":               isbn.strip(),
        "isbn13":             isbn13.strip(),
        "asin":               asin.strip(),
        "kindle_asin":        kindle_asin.strip(),
        "image_url":          image_url,
        "original_pub_year":  original_pub_year,
        "ratings_dist":       ratings_dist,
        "publisher":    publisher.strip(),
        "pub_ms":       pub_ms,
        "num_pages":    num_pages,
        "avg_rating":   str(round(avg_rating, 2)) if avg_rating else "",
        "rating_count": str(int(rating_count))    if rating_count else "",
        "gr_work_id":   gr_work_id,
        "reviews":      reviews,
        "language":      language,
        "is_english":    is_english,
        "book_format":   book_format,
        "is_audiobook":  is_audiobook,
        "fetched_gr_id": fetched_gr_id,
    }, None

# ── Database writes ───────────────────────────────────────────────────────────

def ms_to_calibre_date(ms):
    """Convert Unix milliseconds to Calibre pubdate string, or None if invalid."""
    try:
        dt = datetime.fromtimestamp(ms / 1000, tz=timezone.utc)
        if not (1000 <= dt.year <= 2100):
            return None
        return dt.strftime("%Y-%m-%dT%H:%M:%S+00:00")
    except (ValueError, OSError, OverflowError):
        return None

def _wipe_and_reset(db_path, book_id, rejected_gr_id=None):
    """
    Wipe all identifiers for a book and remove it from both progress caches so
    find_goodreads_ids.py and scrape_goodreads_metadata.py re-process it fresh.
    Called when OL has given us a bad ID (audiobook edition or Apollo mismatch).

    If rejected_gr_id is supplied it is recorded as a 'gr_rejected' identifier
    so find_goodreads_ids.py will never select that GR ID for this book again.
    Existing gr_rejected entries are preserved across wipes (they accumulate).
    """
    con = open_db(db_path)
    try:
        # Preserve any previously-rejected IDs, wipe everything else
        con.execute("DELETE FROM identifiers WHERE book = ? AND type != 'gr_rejected'", (book_id,))
        if rejected_gr_id:
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_rejected', ?)",
                (book_id, str(rejected_gr_id)),
            )
        con.commit()
    finally:
        con.close()

    for progress_path in (PROGRESS_FILE,
                           os.path.join(DATA_DIR, "goodreads_progress.json")):
        if os.path.exists(progress_path):
            try:
                with open(progress_path, encoding="utf-8") as f:
                    prog = json.load(f)
                done = prog.get("done_ids", [])
                if book_id in done:
                    done.remove(book_id)
                    prog["done_ids"] = done
                    with open(progress_path, "w", encoding="utf-8") as f:
                        json.dump(prog, f)
            except (OSError, ValueError):
                pass


def save_metadata(db_path, book_id, data, original_gr_id=None):
    """Write all scraped fields to the DB. Returns list of what was saved."""
    saved = []
    if data.get("is_audiobook"):
        fmt = data.get("book_format", "unknown")
        print(f"[audiobook={fmt}] ", end="")
        _wipe_and_reset(db_path, book_id, rejected_gr_id=original_gr_id)
        return [f"SKIPPED(audiobook={fmt}) — all identifiers wiped, will re-scrape", "RESET"]
    if not data.get("is_english", True):
        lang = data.get("language", "unknown")
        # Clear text fields — don't overwrite English content with foreign-language data
        data = {**data, "description": "", "genres": [], "reviews": []}
        saved.append(f"SKIPPED_TEXT(lang={lang})")
    con = open_db(db_path)
    try:
        # ── Update GR ID if re-fetch resolved a different one ─────────────────
        fetched_id = data.get("fetched_gr_id")
        if fetched_id and original_gr_id and fetched_id != str(original_gr_id):
            con.execute(
                "UPDATE identifiers SET val = ? WHERE book = ? AND type = 'goodreads'",
                (fetched_id, book_id),
            )
            saved.append(f"gr_id_updated({original_gr_id}→{fetched_id})")

        # ── Description ──────────────────────────────────────────────────────
        if data["description"]:
            con.execute(
                "INSERT OR REPLACE INTO comments (book, text) VALUES (?, ?)",
                (book_id, data["description"]),
            )
            saved.append("description")

        # ── Series (only if book has none) ───────────────────────────────────
        if data["series_name"]:
            existing = con.execute(
                "SELECT id FROM books_series_link WHERE book = ?", (book_id,)
            ).fetchone()
            if not existing:
                con.execute(
                    "INSERT OR IGNORE INTO series (name, sort, link) VALUES (?, ?, '')",
                    (data["series_name"], _title_sort_fn(data["series_name"])),
                )
                sid = con.execute(
                    "SELECT id FROM series WHERE name = ? COLLATE NOCASE",
                    (data["series_name"],),
                ).fetchone()[0]
                con.execute(
                    "INSERT OR IGNORE INTO books_series_link (book, series) VALUES (?, ?)",
                    (book_id, sid),
                )
                con.execute(
                    "UPDATE books SET series_index = ? WHERE id = ?",
                    (data["series_index"], book_id),
                )
                saved.append(f"series({data['series_name']} #{data['series_index']:g})")

        # ── Genres → tags (additive) ──────────────────────────────────────────
        added_genres = []
        for genre in data["genres"]:
            con.execute("INSERT OR IGNORE INTO tags (name, link) VALUES (?, '')", (genre,))
            tag_id = con.execute(
                "SELECT id FROM tags WHERE name = ? COLLATE NOCASE", (genre,)
            ).fetchone()[0]
            result = con.execute(
                "INSERT OR IGNORE INTO books_tags_link (book, tag) VALUES (?, ?)",
                (book_id, tag_id),
            )
            if result.rowcount:
                added_genres.append(genre)
        if added_genres:
            saved.append(f"genres({', '.join(added_genres[:3])}{'…' if len(added_genres) > 3 else ''})")

        # ── Identifiers: ISBN, ISBN13 (don't overwrite existing user data) ─────
        for id_type, val in [("isbn", data["isbn"]), ("isbn13", data["isbn13"])]:
            if not val:
                continue
            existing = con.execute(
                "SELECT val FROM identifiers WHERE book = ? AND type = ?", (book_id, id_type)
            ).fetchone()
            if not existing:
                con.execute(
                    "INSERT INTO identifiers (book, type, val) VALUES (?, ?, ?)",
                    (book_id, id_type, val),
                )
                saved.append(id_type)

        # ── Identifiers: ASIN + Kindle ASIN (always overwrite — GR is authoritative) ──
        for id_type, val in [("asin", data["asin"]), ("kindle_asin", data.get("kindle_asin", ""))]:
            if not val:
                continue
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, ?, ?)",
                (book_id, id_type, val),
            )
            saved.append(id_type)

        # ── Publisher (replace) ───────────────────────────────────────────────
        if data["publisher"]:
            con.execute("INSERT OR IGNORE INTO publishers (name, sort, link) VALUES (?, ?, '')",
                        (data["publisher"], _title_sort_fn(data["publisher"])))
            pub_id = con.execute(
                "SELECT id FROM publishers WHERE name = ? COLLATE NOCASE", (data["publisher"],)
            ).fetchone()[0]
            con.execute("DELETE FROM books_publishers_link WHERE book = ?", (book_id,))
            con.execute(
                "INSERT INTO books_publishers_link (book, publisher) VALUES (?, ?)",
                (book_id, pub_id),
            )
            saved.append(f"publisher({data['publisher']})")

        # ── Publication date (replace) ────────────────────────────────────────
        if data["pub_ms"]:
            calibre_date = ms_to_calibre_date(data["pub_ms"])
            if calibre_date:
                con.execute("UPDATE books SET pubdate = ? WHERE id = ?", (calibre_date, book_id))
                saved.append(f"pubdate({calibre_date[:10]})")

        # ── Page count ───────────────────────────────────────────────────────
        if data["num_pages"]:
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_pages', ?)",
                (book_id, str(data["num_pages"])),
            )
            saved.append(f"pages({data['num_pages']})")

        # ── Work ID ───────────────────────────────────────────────────────────
        if data.get("gr_work_id"):
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_work_id', ?)",
                (book_id, data["gr_work_id"]),
            )
            saved.append(f"gr_work_id({data['gr_work_id']})")

        # ── Rating + count (replace) ──────────────────────────────────────────
        if data["avg_rating"]:
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_rating', ?)",
                (book_id, data["avg_rating"]),
            )
            saved.append(f"rating({data['avg_rating']})")
        if data["rating_count"]:
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_rating_count', ?)",
                (book_id, data["rating_count"]),
            )
        if data.get("ratings_dist"):
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_ratings_dist', ?)",
                (book_id, data["ratings_dist"]),
            )
            saved.append(f"ratings_dist")

        # ── Cover image URL ───────────────────────────────────────────────────
        if data.get("image_url"):
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_image_url', ?)",
                (book_id, data["image_url"]),
            )
            saved.append("gr_image_url")

        # ── Original publication year ─────────────────────────────────────────
        if data.get("original_pub_year"):
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_orig_pub_year', ?)",
                (book_id, data["original_pub_year"]),
            )
            saved.append(f"orig_pub_year({data['original_pub_year']})")

        # ── Reviews ───────────────────────────────────────────────────────────
        new_reviews = 0
        for rev in data.get("reviews", []):
            if not rev.get("gr_review_id") or not rev.get("text"):
                continue
            result = con.execute("""
                INSERT OR IGNORE INTO book_reviews
                    (book, source, reviewer, reviewer_url, rating, review_date,
                     text, like_count, spoiler, gr_review_id)
                VALUES (?, 'goodreads', ?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                book_id,
                rev["reviewer"], rev["reviewer_url"],
                rev["rating"],   rev["review_date"],
                rev["text"],     rev["like_count"],
                rev["spoiler"],  rev["gr_review_id"],
            ))
            if result.rowcount:
                new_reviews += 1
        if new_reviews:
            saved.append(f"reviews({new_reviews})")

        con.commit()
    finally:
        con.close()

    return saved

# ── Logging ───────────────────────────────────────────────────────────────────

def _tsv(*fields):
    return "\t".join(str(f).replace("\t", " ") for f in fields) + "\n"

def write_log_header():
    if not os.path.exists(LOG_FILE):
        with open(LOG_FILE, "w", encoding="utf-8") as f:
            f.write(_tsv("book_id", "title", "gr_id", "saved", "error"))

def write_log(book_id, title, gr_id, saved, error=""):
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(_tsv(book_id, title, gr_id, "|".join(saved), error))

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
    paths = []
    if len(argv) > 1:
        arg = argv[1]
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
    for uname, udata in users.items():
        db = udata.get("prefs", {}).get("db_path", "")
        if db and os.path.exists(db):
            paths.append((uname, db))
    return paths


def process_db(label, db_path, progress):
    con = open_db(db_path)
    con.row_factory = sqlite3.Row
    rows = con.execute("""
        SELECT b.id, b.title, i.val AS gr_id
        FROM books b
        JOIN identifiers i ON i.book = b.id AND i.type = 'goodreads'
        ORDER BY b.id
    """).fetchall()
    con.close()

    done = set(progress["done_ids"])
    todo = [dict(r) for r in rows if r["id"] not in done]
    total = len(todo)

    if total == 0:
        print(f"[{label}] Nothing to do (all books already processed).")
        return

    print(f"[{label}] {total} books to scrape  (DB: {db_path})")

    ok_count = err_count = 0

    for i, book in enumerate(todo, 1):
        bid   = book["id"]
        title = book["title"] or ""
        gr_id = book["gr_id"]

        print(f"  [{i}/{total}] {title[:55]!r}  GR:{gr_id} ... ", end="", flush=True)

        data, err = fetch_book_page(gr_id)

        if err:
            print(f"ERROR: {err}")
            write_log(bid, title, gr_id, [], err)
            err_count += 1
            if err == "book node not found in Apollo state":
                _wipe_and_reset(db_path, bid, rejected_gr_id=gr_id)
                print(f"  → all identifiers wiped, will re-scrape")
                # do NOT add to done — let find_goodreads_ids re-match it
            elif err in PERMANENT_ERRORS:
                done.add(bid)  # bad GR ID — no point retrying
        else:
            if not data.get("is_english", True) and not data.get("is_audiobook"):
                print(f"[lang={data.get('language','?')}] ", end="")
            saved = save_metadata(db_path, bid, data, original_gr_id=gr_id)
            display = [s for s in saved if s != "RESET"]
            print(", ".join(display) if display else "nothing new")
            write_log(bid, title, gr_id, display)
            ok_count += 1
            if "RESET" not in saved:
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


def test_gr_id(gr_id):
    """Fetch a single GR ID, print all parsed fields, and exit. Does not touch the DB."""
    print(f"Fetching GR ID: {gr_id} ...")
    data, err = fetch_book_page(gr_id)
    if err:
        print(f"ERROR: {err}")
        return
    print(f"\n  fetched_gr_id : {data['fetched_gr_id']}")
    print(f"  gr_work_id    : {data.get('gr_work_id') or '(not found)'}")
    print(f"  language      : {data['language'] or '(not set)'}")
    print(f"  is_english    : {data['is_english']}")
    print(f"  is_audiobook  : {data['is_audiobook']}  (format: {data['book_format'] or '(not set)'})")
    print(f"  series        : {data['series_name']} #{data['series_index']}" if data['series_name'] else "  series        : (none)")
    print(f"  publisher     : {data['publisher'] or '(none)'}")
    print(f"  pub_ms        : {data['pub_ms']}  → {ms_to_calibre_date(data['pub_ms']) or 'invalid'}" if data['pub_ms'] else "  pub_ms        : (none)")
    print(f"  num_pages     : {data['num_pages'] or '(none)'}")
    print(f"  avg_rating    : {data['avg_rating'] or '(none)'}  ({data['rating_count'] or '?'} ratings)")
    print(f"  isbn          : {data['isbn'] or '(none)'}")
    print(f"  isbn13        : {data['isbn13'] or '(none)'}")
    print(f"  asin          : {data['asin'] or '(none)'}")
    print(f"  kindle_asin   : {data.get('kindle_asin') or '(none)'}")
    print(f"  image_url     : {data.get('image_url') or '(none)'}")
    print(f"  orig_pub_year : {data.get('original_pub_year') or '(none)'}")
    print(f"  ratings_dist  : {data.get('ratings_dist') or '(none)'}")
    print(f"  genres        : {', '.join(data['genres']) or '(none)'}")
    print(f"  reviews       : {len(data['reviews'])} found")
    print(f"  description   : {(data['description'] or '')[:200].strip()}{'…' if len(data['description'] or '') > 200 else ''}")


def main():
    # --test GR_ID: fetch and print a single GR page without writing to DB
    args = sys.argv[1:]
    if "--test" in args:
        idx = args.index("--test")
        if idx + 1 >= len(args):
            print("ERROR: --test requires a GR ID argument"); sys.exit(1)
        test_gr_id(args[idx + 1])
        sys.exit(0)

    db_paths = resolve_db_paths(sys.argv)
    write_log_header()
    progress = load_progress()

    for label, db_path in db_paths:
        if not os.path.exists(db_path):
            print(f"[{label}] DB not found: {db_path} — skipping")
            continue
        process_db(label, db_path, progress)

    print(f"\nAll done. Log: {LOG_FILE}")


if __name__ == "__main__":
    main()
