#!/usr/bin/env python3
"""
Find Goodreads IDs for Calibre books that don't have one yet.

Searches Goodreads for each book, auto-saves confident matches directly to the
SQLite database (Goodreads ID + series info if the book has none), and logs
everything for review.

Usage:
    python3 find_goodreads_ids.py                  # all users in users.json
    python3 find_goodreads_ids.py david            # one user
    python3 find_goodreads_ids.py /path/to/metadata.db

Output files (created next to this script):
    goodreads_saved.tsv      — matches written to the DB
    goodreads_review.tsv     — low-confidence matches for manual checking
    goodreads_notfound.tsv   — no Goodreads results at all
    goodreads_progress.json  — checkpoint; delete to restart from scratch
"""

import json
import os
import random
import re
import sqlite3
import sys
import time
import unicodedata
from datetime import datetime

import requests
from bs4 import BeautifulSoup

# ── Config ────────────────────────────────────────────────────────────────────

DELAY_MIN      = 5      # seconds between requests (min)
DELAY_MAX      = 10     # seconds between requests (max)
BATCH_SIZE     = 50     # log progress every N books
BATCH_PAUSE    = 30     # extra pause (seconds) after each batch
MAX_RESULTS    = 15     # how many GR results to consider per book
SCRIPT_DIR     = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR       = os.path.dirname(SCRIPT_DIR)
DATA_DIR       = os.path.join(ROOT_DIR, "data")
USERS_JSON     = os.path.join(ROOT_DIR, "users.json")
PROGRESS_FILE  = os.path.join(DATA_DIR, "goodreads_progress.json")
SAVED_LOG      = os.path.join(DATA_DIR, "goodreads_saved.tsv")
REVIEW_LOG     = os.path.join(DATA_DIR, "goodreads_review.tsv")
NOTFOUND_LOG   = os.path.join(DATA_DIR, "goodreads_notfound.tsv")

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.5",
}

# ── Text normalisation for matching ──────────────────────────────────────────

def _strip_accents(s):
    return "".join(
        c for c in unicodedata.normalize("NFD", s)
        if unicodedata.category(c) != "Mn"
    )

def normalise_title(t):
    """Lowercase, strip series suffix like '(Dune, #1)', strip punctuation."""
    t = t.strip()
    t = re.sub(r"\s*\([^)]*#\d[^)]*\)\s*$", "", t)
    t = _strip_accents(t.lower())
    t = re.sub(r"[^\w\s]", "", t)
    return re.sub(r"\s+", " ", t).strip()

def normalise_author(a):
    """Lowercase word bag, strip accents and punctuation."""
    a = _strip_accents(a.lower())
    a = re.sub(r"[^\w\s]", "", a)
    return set(w for w in a.split() if len(w) >= 3)

AUDIOBOOK_TITLE_WORDS = {
    "unabridged", "abridged", "audiobook", "audio cd", "audio book",
    "mp3 cd", "audible", "narrated by",
}

# Regex that matches Amazon-catalog / marketplace-style GR entries whose titles
# have been augmented with binding, edition, or bundle information.  These are
# not proper GR book pages and should never be selected as a match.
_MARKETPLACE_RE = re.compile(
    r'\b(hardcover|paperback|mass\s+market|spiral[\s\-]?bound|audio\s+cd)\b'  # binding types
    r'|\bbook[s]?\s+set\b'          # "Book Set", "Books Set"
    r'|\bset\s+of\s+\d+'            # "Set of 2", "Set of 3"
    r'|\b\d+\s+books?\b',           # "3 Books", "6 Book"
    re.IGNORECASE,
)

def is_likely_audiobook(gr_title):
    """Return True if the GR title contains audiobook keywords."""
    t = gr_title.lower()
    return any(w in t for w in AUDIOBOOK_TITLE_WORDS)

def _candidate_is_audio(c):
    """Return True if the candidate is an audiobook — either via title keywords
    or via the narrator-role flag set by _parse_gr_search_page (catches audio
    editions like GR ID 204732 whose title is just 'The Broker' with no keywords
    but whose search-result row shows a Narrator contributor role)."""
    return is_likely_audiobook(c.get("title", "")) or c.get("narrator_role", False)

def is_marketplace_listing(gr_title):
    """
    Return True if the GR title looks like an Amazon-catalog / marketplace entry
    rather than a genuine GR book page.  These entries typically have binding
    keywords ("Hardcover", "Paperback"), bundle descriptions ("Set of 2 Novels"),
    or book-count phrases ("3 Books", "6 BOOKS") appended to the real title.
    """
    return bool(_MARKETPLACE_RE.search(gr_title))

def is_foreign_script(text):
    """
    Return True if the text contains more than a couple of non-Latin characters.
    Catches Japanese, Chinese, Korean, Cyrillic, Arabic, Hebrew, etc. editions
    whose titles on GR are written in the foreign script.
    Allows a few accented Latin chars (café, naïve, etc.) without triggering.
    """
    non_latin = sum(1 for c in text if ord(c) > 0x024F)
    return non_latin > 2

def title_match(book_title, gr_title):
    bt = normalise_title(book_title)
    gt = normalise_title(gr_title)
    if bt == gt:
        return True
    # Only allow substring matching when the shorter string is long enough
    # to avoid "It" matching "hitchhikers", "We" matching "between", etc.
    shorter = bt if len(bt) <= len(gt) else gt
    if len(shorter) < 5 or len(shorter.split()) < 2:
        return False
    return bt in gt or gt in bt

def title_exact(book_title, gr_title):
    return normalise_title(book_title) == normalise_title(gr_title)

def author_match(book_authors_str, gr_author):
    bag_b = normalise_author(book_authors_str)
    bag_g = normalise_author(gr_author)
    if not bag_b or not bag_g:
        return True
    return len(bag_b & bag_g) >= 1

# ── Series parsing ────────────────────────────────────────────────────────────

def parse_gr_series(gr_title):
    """
    Extract (series_name, series_index) from a Goodreads title.
    'Dune (Dune, #1)'                   → ('Dune', 1.0)
    'The Name of the Wind (Kingkiller Chronicle, #1)' → ('Kingkiller Chronicle', 1.0)
    'Dune'                              → (None, None)
    """
    m = re.search(r"\(([^,#()]+),\s*#([\d.]+(?:-[\d.]+)?)\)\s*$", gr_title.strip())
    if m:
        # index can be "1", "1.5", "1-2" — take the first numeric part
        idx_str = m.group(2).split("-")[0]
        return m.group(1).strip(), float(idx_str)
    return None, None

# ── Calibre SQLite title_sort (needed by the series INSERT trigger) ───────────

def _title_sort_fn(title):
    """Python equivalent of Calibre's title_sort() SQLite function."""
    if not title:
        return title
    for article in ("the ", "a ", "an "):
        if title.lower().startswith(article):
            return title[len(article):].strip() + ", " + title[:len(article)].strip()
    return title

def _open_db(db_path):
    con = sqlite3.connect(db_path)
    con.create_function("title_sort", 1, _title_sort_fn)
    return con

# ── Goodreads ISBN lookup ─────────────────────────────────────────────────────

def lookup_by_isbn(isbn):
    """
    Try https://www.goodreads.com/book/isbn/{isbn}.
    If Goodreads redirects to /book/show/{id}, parse that page for metadata
    and return a candidate dict (same shape as search results).
    Returns (candidate_dict, None) on success, (None, error_string) on failure,
    (None, None) if the URL didn't resolve to a book page.
    """
    url = f"https://www.goodreads.com/book/isbn/{requests.utils.quote(isbn)}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=20, allow_redirects=True)
    except Exception as e:
        return None, str(e)

    if r.status_code == 404:
        return None, None  # ISBN not found on GR — not an error, just no match

    if r.status_code != 200:
        return None, f"HTTP {r.status_code}"

    # Check the final URL after redirects
    m = re.search(r"goodreads\.com/book/show/(\d+)", r.url)
    if not m:
        return None, None  # didn't redirect to a book page

    gr_id = m.group(1)

    # Parse __NEXT_DATA__ for title / author / ratings
    title_val = author_val = avg_rating = rating_count = pub_year = ""
    nd_m = re.search(r'<script[^>]+id="__NEXT_DATA__"[^>]*>(.+?)</script>', r.text, re.S)
    if nd_m:
        try:
            nd = json.loads(nd_m.group(1))
            apollo = nd.get("props", {}).get("pageProps", {}).get("apolloState", {})
            for key, node in apollo.items():
                if key.startswith("Book:") and "title" in node:
                    title_val    = node.get("title", "")
                    stats        = node.get("stats", {})
                    avg_rating   = str(round(float(stats["averageRating"]), 2)) if stats.get("averageRating") else ""
                    rating_count = str(stats.get("ratingsCount", ""))
                    break
            for key, node in apollo.items():
                if key.startswith("Contributor:") and "name" in node:
                    author_val = node["name"]
                    break
            for key, node in apollo.items():
                if key.startswith("BookDetails:") and node.get("publicationTime"):
                    pub_year = str(datetime.fromtimestamp(node["publicationTime"] / 1000).year)
                    break
        except Exception:
            pass

    # Fallback: get title from <title> tag
    if not title_val:
        t_m = re.search(r"<title[^>]*>([^<]+)</title>", r.text, re.I)
        if t_m:
            title_val = re.sub(r"\s*[|\-].*$", "", t_m.group(1).strip())

    if not title_val:
        return None, None  # couldn't parse anything useful

    return {
        "id": gr_id, "title": title_val, "author": author_val,
        "avg_rating": avg_rating, "rating_count": rating_count,
        "pub_year": pub_year,
    }, None


# ── Goodreads search ──────────────────────────────────────────────────────────

def _parse_gr_search_page(html):
    """Parse GR search result rows from raw HTML.  Returns a list of candidate dicts."""
    soup = BeautifulSoup(html, "html.parser")
    rows = soup.select('tr[itemtype="http://schema.org/Book"]')
    results = []
    for row in rows[:MAX_RESULTS]:
        anchor = row.find("div", class_="u-anchorTarget")
        gr_id  = anchor["id"].strip() if anchor and anchor.get("id", "").isdigit() else None
        if not gr_id:
            continue

        title_tag = row.find("a", class_="bookTitle")
        gr_title  = (title_tag.get("title") or title_tag.get_text()).strip() if title_tag else ""
        if not gr_title:
            continue

        author_span = row.select_one('a.authorName span[itemprop="name"]')
        gr_author   = author_span.get_text().strip() if author_span else ""

        # Community rating + count: "3.89 avg rating — 6,177 ratings"
        avg_rating   = ""
        rating_count = ""
        minirating = row.select_one(".minirating")
        if minirating:
            mt = minirating.get_text()
            m = re.search(r"([\d.]+)\s+avg rating\s*[—–-]\s*([\d,]+)\s+rating", mt)
            if m:
                avg_rating   = m.group(1)
                rating_count = m.group(2).replace(",", "")

        # Publication year: "published 2005" in the grey stats line.
        # Also detect narrator role: some audiobook entries on GR show
        # "(Narrator)" in a .greyText.smallText span instead of the normal
        # ratings/edition stats (e.g. GR ID 204732 "The Broker" audiobook).
        pub_year = ""
        narrator_role = False
        for grey in row.select(".greyText.smallText"):
            gt = grey.get_text(separator=" ")
            if not pub_year:
                ym = re.search(r"\bpublished\s+(\d{4})\b", gt)
                if ym:
                    pub_year = ym.group(1)
            if re.search(r'\bnarrator\b', gt, re.IGNORECASE):
                narrator_role = True

        results.append({
            "id": gr_id, "title": gr_title, "author": gr_author,
            "avg_rating": avg_rating, "rating_count": rating_count,
            "pub_year": pub_year, "narrator_role": narrator_role,
        })

    return results


def search_goodreads(title, authors, rejected_ids=None):
    """
    Search GR by title + author.  If that returns only candidates with very
    few ratings (all < 50), retry with title only — GR often returns different
    (and more canonical) results when the author name isn't included, because
    the extra terms cause marketplace/catalog listings to rank higher.

    rejected_ids: set of GR ID strings that have already been proven wrong for
    this book (e.g. previously detected as an audiobook).  They are excluded
    from results AND from the fallback-trigger check so a rejected ID cannot
    prevent the title-only retry from firing.

    Returns (list of candidates, error_string_or_None).
    Candidates from both passes are merged (deduped by GR ID).
    """
    rejected = rejected_ids or set()

    def _fetch(q):
        url = f"https://www.goodreads.com/search?q={requests.utils.quote(q)}"
        try:
            r = requests.get(url, headers=HEADERS, timeout=20)
        except Exception as e:
            return None, str(e)
        if r.status_code != 200:
            return None, f"HTTP {r.status_code}"
        return _parse_gr_search_page(r.text), None

    results, err = _fetch(f"{title} {authors}".strip())
    if err:
        return None, err

    # Strip previously-rejected IDs before any threshold checks
    if rejected and results:
        results = [c for c in results if c["id"] not in rejected]

    # Trigger a title-only retry when the title+author search hasn't surfaced a
    # confident canonical entry.  We require at least one EXACT-title match with
    # adequate ratings — a fuzzy match (e.g. omnibus "The Client / The Street
    # Lawyer" matching the title "The Street Lawyer") does not count, because
    # the real canonical entry often only appears in the title-only results.
    LOW_RATING_THRESHOLD = 50
    bt_len = len(normalise_title(title))
    matching = [c for c in (results or [])
                if (title_match(title, c["title"]) or title_exact(title, c["title"]))
                and not _candidate_is_audio(c)
                and not is_marketplace_listing(c["title"])
                and len(normalise_title(c["title"])) <= bt_len * 1.8]
    exact_ok = [c for c in matching
                if title_exact(title, c["title"]) and _rating_count(c) >= LOW_RATING_THRESHOLD]
    if not exact_ok:
        title_only, err2 = _fetch(title)
        if not err2 and title_only:
            # Strip rejected IDs from title-only results before merging
            if rejected:
                title_only = [c for c in title_only if c["id"] not in rejected]
            # Merge, deduplicating by GR ID (title-only results appended)
            seen = {c["id"] for c in (results or [])}
            for c in title_only:
                if c["id"] not in seen:
                    results = (results or []) + [c]
                    seen.add(c["id"])

    return results or [], None

# ── Matching logic ────────────────────────────────────────────────────────────

def _rating_count(c):
    """Return rating count as int for sorting (higher = more likely canonical edition)."""
    try:
        return int(c.get("rating_count") or 0)
    except (ValueError, TypeError):
        return 0

def _best_from_pool(pool):
    """
    Pick the best candidate from a filtered pool.

    Primary sort: rating count (higher is better).
    Bonus: the candidate with the lowest numeric GR ID gets a boost equal to
    25% of the pool's highest rating count (minimum 50).  This reflects the
    observation that lower GR IDs tend to be the original/canonical edition —
    the boost lets it win when ratings are comparable, but a candidate with
    substantially more ratings still takes priority.
    """
    if not pool:
        return None
    max_rc  = max(_rating_count(c) for c in pool)
    bonus   = max(int(max_rc * 0.25), 50)
    try:
        min_id = min(int(c["id"]) for c in pool)
    except (ValueError, TypeError):
        min_id = None

    def key(c):
        rc = _rating_count(c)
        try:
            if min_id is not None and int(c["id"]) == min_id:
                rc += bonus
        except (ValueError, TypeError):
            pass
        return rc

    return max(pool, key=key)

def find_best_match(book_title, book_authors, candidates):
    """
    Return (candidate, 'high'|'low'|None).

    Priority order:
      1. Exact title + author match, non-audiobook  → high (pick highest rating count)
      2. Fuzzy title + author match, non-audiobook  → high (pick highest rating count)
      3. Exact title match only, non-audiobook      → low
      4. Fuzzy title match only, non-audiobook      → low
      5. No title match at all                      → (None, None)  — don't guess
    Audiobook candidates (title contains unabridged/abridged/etc.) are skipped entirely.
    """
    if not candidates:
        return None, None

    # Split into clean pool: exclude audiobooks, foreign-script editions, and
    # marketplace/catalog listings (bundles, "Hardcover By Author", etc.)
    clean = [c for c in candidates
             if not _candidate_is_audio(c)
             and not is_foreign_script(c["title"])
             and not is_marketplace_listing(c["title"])]

    if not clean:
        return None, None

    # 1. Exact title + author
    pool = [c for c in clean if title_exact(book_title, c["title"]) and author_match(book_authors, c["author"])]
    if pool:
        return _best_from_pool(pool), "high"

    # 2. Fuzzy title + author
    pool = [c for c in clean if title_match(book_title, c["title"]) and author_match(book_authors, c["author"])]
    if pool:
        return _best_from_pool(pool), "high"

    # 3. Exact title only
    pool = [c for c in clean if title_exact(book_title, c["title"])]
    if pool:
        return _best_from_pool(pool), "low"

    # 4. Fuzzy title only
    pool = [c for c in clean if title_match(book_title, c["title"])]
    if pool:
        return _best_from_pool(pool), "low"

    # 5. Nothing matched — don't fall back to first result
    return None, None

# ── Database helpers ──────────────────────────────────────────────────────────

def get_books_without_goodreads(db_path):
    con = _open_db(db_path)
    con.row_factory = sqlite3.Row
    cur = con.execute("""
        SELECT b.id, b.title, GROUP_CONCAT(a.name, ', ') AS authors,
               (SELECT val FROM identifiers
                WHERE book = b.id
                  AND type IN ('isbn13', 'isbn')
                  AND val NOT LIKE 'NOT%'
                ORDER BY CASE type WHEN 'isbn13' THEN 0 ELSE 1 END
                LIMIT 1) AS isbn,
               (SELECT GROUP_CONCAT(val, ',') FROM identifiers
                WHERE book = b.id AND type = 'gr_rejected') AS gr_rejected_ids
        FROM books b
        LEFT JOIN books_authors_link bal ON bal.book = b.id
        LEFT JOIN authors a              ON a.id    = bal.author
        WHERE b.id NOT IN (
            SELECT book FROM identifiers WHERE type = 'goodreads'
        )
        GROUP BY b.id
        ORDER BY b.id
    """)
    rows = [dict(r) for r in cur.fetchall()]
    con.close()
    return rows

def save_match(db_path, book_id, gr_id, gr_title, avg_rating="", rating_count="", pub_year=""):
    """
    Save the Goodreads identifier and, if the book has no series yet, also
    save the series name and index parsed from the Goodreads title.

    Returns a string describing what was saved, e.g. "id+series" or "id only".
    """
    series_name, series_index = parse_gr_series(gr_title)

    con = _open_db(db_path)
    try:
        # Always save the GR identifier
        con.execute(
            "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'goodreads', ?)",
            (book_id, gr_id),
        )
        if avg_rating:
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_rating', ?)",
                (book_id, avg_rating),
            )
        if rating_count:
            con.execute(
                "INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'gr_rating_count', ?)",
                (book_id, rating_count),
            )
        if pub_year:
            # Only set pubdate if the book currently has the Calibre default (year 101 = "not set")
            cur = con.execute("SELECT pubdate FROM books WHERE id = ?", (book_id,))
            row = cur.fetchone()
            if row and row[0] and str(row[0]).startswith("0101-"):
                con.execute(
                    "UPDATE books SET pubdate = ? WHERE id = ?",
                    (f"{pub_year}-01-01T00:00:00+00:00", book_id),
                )

        series_saved = False
        if series_name:
            # Only set series if the book doesn't already have one
            existing_series = con.execute(
                "SELECT id FROM books_series_link WHERE book = ?", (book_id,)
            ).fetchone()

            if not existing_series:
                # Find or create the series row
                # INSERT OR IGNORE so we don't clobber an existing series entry;
                # the series_insert_trg trigger sets sort = title_sort(name)
                con.execute(
                    "INSERT OR IGNORE INTO series (name, sort, link) VALUES (?, ?, '')",
                    (series_name, _title_sort_fn(series_name)),
                )
                series_id = con.execute(
                    "SELECT id FROM series WHERE name = ? COLLATE NOCASE", (series_name,)
                ).fetchone()[0]

                # Link the book to the series
                con.execute(
                    "INSERT OR IGNORE INTO books_series_link (book, series) VALUES (?, ?)",
                    (book_id, series_id),
                )

                # Set the series index on the book row
                con.execute(
                    "UPDATE books SET series_index = ? WHERE id = ?",
                    (series_index, book_id),
                )
                series_saved = True

        con.commit()
    finally:
        con.close()

    if series_saved:
        return f"id+series ({series_name} #{series_index:g})"
    return "id only"

# ── Logging helpers ───────────────────────────────────────────────────────────

def _tsv_row(*fields):
    return "\t".join(str(f).replace("\t", " ") for f in fields) + "\n"

def _write_header(path, *cols):
    if not os.path.exists(path):
        with open(path, "w", encoding="utf-8") as f:
            f.write(_tsv_row(*cols))

def log_saved(book_id, book_title, book_authors, gr_id, gr_title, gr_author, what_saved):
    with open(SAVED_LOG, "a", encoding="utf-8") as f:
        f.write(_tsv_row(book_id, book_title, book_authors, gr_id, gr_title, gr_author, what_saved))

def log_review(book_id, book_title, book_authors, gr_id, gr_title, gr_author, reason):
    with open(REVIEW_LOG, "a", encoding="utf-8") as f:
        f.write(_tsv_row(book_id, book_title, book_authors, gr_id, gr_title, gr_author, reason))

def log_notfound(book_id, book_title, book_authors, reason):
    with open(NOTFOUND_LOG, "a", encoding="utf-8") as f:
        f.write(_tsv_row(book_id, book_title, book_authors, reason))

# ── Progress checkpoint ───────────────────────────────────────────────────────

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
            paths = [(arg, arg)]
        else:
            if not os.path.exists(USERS_JSON):
                print(f"ERROR: {USERS_JSON} not found"); sys.exit(1)
            users = json.load(open(USERS_JSON))
            if arg not in users:
                print(f"ERROR: user '{arg}' not in users.json"); sys.exit(1)
            paths = [(arg, users[arg]["prefs"]["db_path"])]
    else:
        if not os.path.exists(USERS_JSON):
            print("ERROR: pass a db path or users.json must exist"); sys.exit(1)
        users = json.load(open(USERS_JSON))
        for uname, udata in users.items():
            db = udata.get("prefs", {}).get("db_path", "")
            if db and os.path.exists(db):
                paths.append((uname, db))

    return paths


def process_db(label, db_path, progress):
    books = get_books_without_goodreads(db_path)
    done  = set(progress["done_ids"])
    todo  = [b for b in books if b["id"] not in done]
    total = len(todo)

    if total == 0:
        print(f"[{label}] All books already have a Goodreads ID.")
        return

    print(f"[{label}] {total} books to process  (DB: {db_path})")

    saved = reviewed = notfound = 0

    for i, book in enumerate(todo, 1):
        bid     = book["id"]
        title   = book["title"] or ""
        authors = book["authors"] or ""
        isbn    = book.get("isbn") or ""

        print(f"  [{i}/{total}] {title[:55]!r} by {authors[:35]!r} ... ", end="", flush=True)

        # ── Step 1: try ISBN direct lookup ────────────────────────────────────
        if isbn:
            print(f"\n    ISBN {isbn} → ", end="", flush=True)
            isbn_candidate, isbn_err = lookup_by_isbn(isbn)
            if isbn_err:
                print(f"isbn_error ({isbn_err}), falling back to search")
            elif isbn_candidate:
                gr_title  = isbn_candidate.get("title", "")
                gr_author = isbn_candidate.get("author", "")
                # Apply the same filters as the search path
                if is_likely_audiobook(gr_title):
                    print(f"isbn resolved to audiobook ({gr_title[:40]!r}), falling back to search")
                elif is_foreign_script(gr_title):
                    print(f"isbn resolved to foreign-script edition ({gr_title[:40]!r}), falling back to search")
                elif not title_match(title, gr_title):
                    print(f"isbn title mismatch ({gr_title[:40]!r}), falling back to search")
                else:
                    what = save_match(db_path, bid, isbn_candidate["id"], gr_title,
                                      isbn_candidate.get("avg_rating", ""),
                                      isbn_candidate.get("rating_count", ""),
                                      isbn_candidate.get("pub_year", ""))
                    log_saved(bid, title, authors, isbn_candidate["id"],
                              gr_title, gr_author, f"isbn_match/{what}")
                    print(f"SAVED {isbn_candidate['id']} [isbn_match/{what}]  ({gr_title[:35]})")
                    saved += 1
                    done.add(bid)
                    progress["done_ids"] = list(done)
                    if i % 10 == 0:
                        save_progress(progress)
                    if i % BATCH_SIZE == 0:
                        print(f"\n  ── Batch {i // BATCH_SIZE} done: {saved} saved, {reviewed} review, {notfound} not found ──")
                        print(f"  Pausing {BATCH_PAUSE}s ...\n")
                        time.sleep(BATCH_PAUSE)
                    else:
                        time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))
                    continue
            else:
                print(f"no isbn match, falling back to search")

        # ── Step 2: title + author search ────────────────────────────────────
        rejected_ids = set(filter(None, (book.get("gr_rejected_ids") or "").split(",")))

        candidates, err = search_goodreads(title, authors, rejected_ids=rejected_ids)

        if err:
            print(f"ERROR ({err})")
            log_notfound(bid, title, authors, f"fetch_error: {err}")
            notfound += 1
        elif not candidates:
            print("no results")
            log_notfound(bid, title, authors, "no_results")
            notfound += 1
            done.add(bid)
        else:
            best, confidence = find_best_match(title, authors, candidates)
            if best is None:
                # Results existed but all were audiobooks or nothing matched title
                audio_skipped   = sum(1 for c in candidates if is_likely_audiobook(c["title"]))
                foreign_skipped = sum(1 for c in candidates if is_foreign_script(c["title"]))
                if audio_skipped + foreign_skipped == len(candidates):
                    reason = "all_filtered(audiobook/foreign)"
                else:
                    reason = "no_title_match"
                log_notfound(bid, title, authors, reason)
                print(f"no match ({reason}, {len(candidates)} results)")
                notfound += 1
            elif confidence == "high":
                what = save_match(db_path, bid, best["id"], best["title"],
                                  best.get("avg_rating", ""), best.get("rating_count", ""),
                                  best.get("pub_year", ""))
                log_saved(bid, title, authors, best["id"], best["title"], best["author"], what)
                print(f"SAVED {best['id']} [{what}]  ({best['title'][:35]})")
                saved += 1
            else:
                log_review(bid, title, authors,
                           best["id"], best["title"], best["author"], "low_confidence")
                print(f"review → {best['id']}  ({best['title'][:35]})")
                reviewed += 1
            done.add(bid)

        progress["done_ids"] = list(done)

        if i % 10 == 0:
            save_progress(progress)

        if i % BATCH_SIZE == 0:
            print(f"\n  ── Batch {i // BATCH_SIZE} done: "
                  f"{saved} saved, {reviewed} review, {notfound} not found ──")
            print(f"  Pausing {BATCH_PAUSE}s ...\n")
            time.sleep(BATCH_PAUSE)
        else:
            time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    save_progress(progress)
    print(f"\n[{label}] Done: {saved} saved, {reviewed} need review, {notfound} not found")


def refetch_book(book_id, db_path):
    """
    Re-run the GR search for a single Calibre book ID and save the best match,
    overwriting any existing GR identifier.  Shows all candidates so you can
    verify the right one was chosen.
    """
    con = _open_db(db_path)
    con.row_factory = sqlite3.Row
    row = con.execute("""
        SELECT b.id, b.title, GROUP_CONCAT(a.name, ', ') AS authors
        FROM books b
        LEFT JOIN books_authors_link bal ON bal.book = b.id
        LEFT JOIN authors a              ON a.id    = bal.author
        WHERE b.id = ?
        GROUP BY b.id
    """, (book_id,)).fetchone()
    con.close()

    if not row:
        print(f"ERROR: book ID {book_id} not found in {db_path}")
        return

    title   = row["title"] or ""
    authors = row["authors"] or ""
    print(f"Book  : {title!r}  by {authors!r}")

    candidates, err = search_goodreads(title, authors)
    if err:
        print(f"Search error: {err}")
        return
    if not candidates:
        print("No results returned from Goodreads.")
        return

    # Show all candidates so the user can see what was available
    print(f"\nCandidates ({len(candidates)} returned, filtered shown with *):")
    best, confidence = find_best_match(title, authors, candidates)
    for c in candidates:
        filtered = is_likely_audiobook(c["title"]) or is_foreign_script(c["title"])
        chosen   = (best is not None and c["id"] == best["id"])
        flag = "» CHOSEN" if chosen else ("  FILTERED" if filtered else "")
        rc   = f"  ({c['rating_count']} ratings)" if c.get("rating_count") else ""
        print(f"  {'*' if not filtered else ' '} [{c['id']}] {c['title'][:60]}  —  {c['author'][:30]}{rc}  {flag}")

    if best is None:
        print("\nNo suitable match found after filtering — nothing saved.")
        return

    print(f"\nConfidence : {confidence}")
    print(f"Saving GR ID {best['id']} for book {book_id} …")
    what = save_match(db_path, book_id, best["id"], best["title"],
                      best.get("avg_rating", ""), best.get("rating_count", ""),
                      best.get("pub_year", ""))
    _write_header(SAVED_LOG, "book_id","book_title","book_authors","gr_id","gr_title","gr_author","saved")
    log_saved(book_id, title, authors, best["id"], best["title"], best["author"], what)
    print(f"Done: {what}")


def main():
    args = sys.argv[1:]

    # --book-id N [user_or_db]: re-fetch a single book by Calibre ID
    if "--book-id" in args:
        idx = args.index("--book-id")
        if idx + 1 >= len(args):
            print("ERROR: --book-id requires a numeric book ID"); sys.exit(1)
        try:
            book_id = int(args[idx + 1])
        except ValueError:
            print("ERROR: --book-id value must be an integer"); sys.exit(1)
        # remaining args after the flag+value are optional user/db
        remaining = args[:idx] + args[idx + 2:]
        db_paths = resolve_db_paths([sys.argv[0]] + remaining)
        if not db_paths:
            print("ERROR: could not resolve a database path"); sys.exit(1)
        label, db_path = db_paths[0]
        if not os.path.exists(db_path):
            print(f"ERROR: DB not found: {db_path}"); sys.exit(1)
        print(f"DB    : {db_path}")
        refetch_book(book_id, db_path)
        sys.exit(0)

    db_paths = resolve_db_paths(sys.argv)

    _write_header(SAVED_LOG,    "book_id","book_title","book_authors","gr_id","gr_title","gr_author","saved")
    _write_header(REVIEW_LOG,   "book_id","book_title","book_authors","gr_id","gr_title","gr_author","reason")
    _write_header(NOTFOUND_LOG, "book_id","book_title","book_authors","reason")

    progress = load_progress()

    for label, db_path in db_paths:
        if not os.path.exists(db_path):
            print(f"[{label}] DB not found: {db_path} — skipping")
            continue
        process_db(label, db_path, progress)

    print("\nAll done.")
    print(f"  Saved:      {SAVED_LOG}")
    print(f"  Review:     {REVIEW_LOG}")
    print(f"  Not found:  {NOTFOUND_LOG}")


if __name__ == "__main__":
    main()
