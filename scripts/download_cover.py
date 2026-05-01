#!/usr/bin/env python3
"""
Download a hi-res cover for a single book from Amazon (via ASIN) or Goodreads (via GR ID).

Tries Amazon first if ASIN is available, falls back to Goodreads.

Usage:
    python3 download_cover.py --book-id N --db-path /path/to/metadata.db --out-file /tmp/cover.jpg

Outputs JSON to stdout:
    {"ok": true, "source": "amazon", "width": 600, "height": 900, "size_kb": 120}
    {"ok": false, "error": "No Amazon or Goodreads ID found"}
"""

import argparse
import json
import os
import re
import sqlite3
import struct
import sys

import requests
from bs4 import BeautifulSoup

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.5",
    "Accept-Encoding": "gzip, deflate, br",
}

TIMEOUT = 20


def fail(msg):
    print(json.dumps({"ok": False, "error": msg}))
    sys.exit(1)


def get_identifiers(db_path, book_id):
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    rows = con.execute(
        "SELECT type, val FROM identifiers WHERE book = ?", (book_id,)
    ).fetchall()
    con.close()
    return {row["type"]: row["val"] for row in rows}


def fetch_url(url):
    """GET with headers, return bytes or None."""
    try:
        r = requests.get(url, headers=HEADERS, timeout=TIMEOUT)
        if r.status_code == 200 and len(r.content) > 1000:
            return r.content
    except Exception:
        pass
    return None


def fetch_amazon_cover(asin):
    """Scrape the Amazon product page for the highest-resolution cover image."""
    try:
        r = requests.get(
            f"https://www.amazon.com/dp/{asin}", headers=HEADERS, timeout=TIMEOUT
        )
        if r.status_code != 200:
            return None, None
        soup = BeautifulSoup(r.text, "html.parser")

        img = soup.find("img", id="landingImage") or soup.find("img", id="main-image")
        if img:
            # data-old-hires is the full-resolution image (typically _SL1500_)
            hires = img.get("data-old-hires", "").strip()
            if hires:
                data = fetch_url(hires)
                if data:
                    return data, "amazon-page"

            # data-a-dynamic-image lists all responsive sizes — pick the largest
            dynamic = img.get("data-a-dynamic-image", "")
            if dynamic:
                sizes = json.loads(dynamic)
                best_url = max(sizes, key=lambda u: sizes[u][0] * sizes[u][1])
                data = fetch_url(best_url)
                if data:
                    return data, "amazon-page"

        # og:image fallback — strip size token to get the largest available variant
        og = soup.find("meta", property="og:image")
        if og and og.get("content", "").startswith("http"):
            url = og["content"]
            clean = re.sub(r"\._[A-Z]{1,3}\d*_", "", url)
            data = fetch_url(clean) or fetch_url(url)
            if data:
                return data, "amazon-page"

        # Last resort: src on the img tag or the old thumbnail URL pattern
        if img:
            src = img.get("src", "").strip()
            if src:
                data = fetch_url(src)
                if data:
                    return data, "amazon-page"

    except Exception:
        pass

    # Final fallback: old thumbnail URL (low-res, last resort only)
    direct = fetch_url(
        f"https://images-na.ssl-images-amazon.com/images/P/{asin}.01.LZZZZZZZ.jpg"
    )
    if direct:
        return direct, "amazon-direct"

    return None, None


def fetch_goodreads_cover(gr_id):
    """
    Fetch og:image from Goodreads book page.
    Strip ._SX999_ / ._SY999_ size suffixes to get the full-resolution version.
    """
    try:
        r = requests.get(
            f"https://www.goodreads.com/book/show/{gr_id}",
            headers=HEADERS,
            timeout=TIMEOUT,
        )
        if r.status_code != 200:
            return None, None

        soup = BeautifulSoup(r.text, "html.parser")
        og = soup.find("meta", property="og:image")
        if not og:
            return None, None

        url = og.get("content", "").strip()
        if not url:
            return None, None

        # Strip embedded size tokens like ._SY475_, ._SX318_, ._SS150_, etc.
        clean_url = re.sub(r"\._[A-Z]{1,3}\d*_", "", url)

        if clean_url != url:
            data = fetch_url(clean_url)
            if data:
                return data, clean_url

        # Fall back to original og:image URL
        data = fetch_url(url)
        if data:
            return data, url

    except Exception:
        pass

    return None, None


def image_dimensions(data):
    """Return (width, height) from raw JPEG or PNG bytes. Returns (0, 0) on failure."""
    try:
        if data[:4] == b"\x89PNG":
            w = struct.unpack(">I", data[16:20])[0]
            h = struct.unpack(">I", data[20:24])[0]
            return w, h
        if data[:2] == b"\xff\xd8":
            i = 2
            while i + 4 < len(data):
                if data[i] != 0xFF:
                    break
                marker = data[i + 1]
                if marker in (0xC0, 0xC1, 0xC2):
                    h = struct.unpack(">H", data[i + 5 : i + 7])[0]
                    w = struct.unpack(">H", data[i + 7 : i + 9])[0]
                    return w, h
                seg_len = struct.unpack(">H", data[i + 2 : i + 4])[0]
                i += 2 + seg_len
    except Exception:
        pass
    return 0, 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--book-id", type=int, required=True)
    parser.add_argument("--db-path", required=True)
    parser.add_argument("--out-file", required=True)
    parser.add_argument("--source", choices=["amazon", "kindle", "goodreads", "gr_image_url"], default=None,
                        help="Force a specific source instead of auto-selecting")
    args = parser.parse_args()

    if not os.path.exists(args.db_path):
        fail(f"Database not found: {args.db_path}")

    ids = get_identifiers(args.db_path, args.book_id)
    asin         = ids.get("amazon") or ids.get("asin")
    kindle_asin  = ids.get("kindle_asin")
    gr_id        = ids.get("goodreads")
    gr_image_url = ids.get("gr_image_url")

    if not asin and not kindle_asin and not gr_id and not gr_image_url:
        fail("No Amazon, Kindle, Goodreads, or GR image URL found for this book")

    img_data = None
    source   = None

    if args.source == "amazon":
        if not asin:
            fail("No Amazon/ASIN identifier found for this book")
        img_data, source = fetch_amazon_cover(asin)
        if img_data:
            source = "amazon"
    elif args.source == "kindle":
        if not kindle_asin:
            fail("No Kindle ASIN identifier found for this book")
        img_data, source = fetch_amazon_cover(kindle_asin)
        if img_data:
            source = "kindle"
    elif args.source == "goodreads":
        if not gr_id:
            fail("No Goodreads identifier found for this book")
        img_data, source = fetch_goodreads_cover(gr_id)
        if img_data:
            source = "goodreads"
    elif args.source == "gr_image_url":
        if not gr_image_url:
            fail("No GR image URL identifier found for this book")
        img_data = fetch_url(gr_image_url)
        source = "gr_image_url" if img_data else None
    else:
        # Auto: Amazon first, GR image URL fallback, Goodreads page last
        if asin:
            img_data, source = fetch_amazon_cover(asin)
            if img_data:
                source = "amazon"
        if not img_data and gr_image_url:
            img_data = fetch_url(gr_image_url)
            if img_data:
                source = "gr_image_url"
        if not img_data and gr_id:
            img_data, source = fetch_goodreads_cover(gr_id)
            if img_data:
                source = "goodreads"

    if not img_data:
        source_name = {"amazon": "Amazon", "kindle": "Kindle", "goodreads": "Goodreads", "gr_image_url": "GR image URL"}.get(args.source or "", "any source")
        fail(f"Could not download cover from {source_name}")

    out_dir = os.path.dirname(args.out_file)
    if out_dir:
        os.makedirs(out_dir, exist_ok=True)

    with open(args.out_file, "wb") as f:
        f.write(img_data)

    w, h = image_dimensions(img_data)

    print(json.dumps({
        "ok":      True,
        "source":  "amazon" if source and source.startswith("amazon") else source,
        "width":   w,
        "height":  h,
        "size_kb": len(img_data) // 1024,
    }))


if __name__ == "__main__":
    main()
