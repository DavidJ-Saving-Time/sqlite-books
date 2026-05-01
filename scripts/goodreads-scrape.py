#!/usr/bin/env python3

import requests
import time
from bs4 import BeautifulSoup

HEADERS = {"User-Agent": "Mozilla/5.0"}

CATEGORIES = {
    "fantasy":            "Goodreads Choice: Fantasy",
    "science-fiction":    "Goodreads Choice: Science Fiction",
    "paranormal-fantasy": "Goodreads Choice: Paranormal Fantasy",
}

OUTPUT_FILE = "goodreads-fantasy-science-fiction-paranormal-fantasy.txt"
DELAY = 2  # seconds between requests

rows = []

for year in range(2011, 2026):
    for slug, award_name in CATEGORIES.items():
        url = f"https://www.goodreads.com/choiceawards/best-{slug}-books-{year}"
        print(f"Fetching {url} ...", end=" ", flush=True)

        try:
            r = requests.get(url, headers=HEADERS, timeout=15)
        except Exception as e:
            print(f"ERROR ({e}) — skipping")
            time.sleep(DELAY)
            continue

        if r.status_code != 200:
            print(f"HTTP {r.status_code} — skipping")
            time.sleep(DELAY)
            continue

        soup  = BeautifulSoup(r.text, "html.parser")
        books = soup.select(".pollAnswer")

        if not books:
            print("no entries — skipping")
            time.sleep(DELAY)
            continue

        print(f"{len(books)} entries")

        for i, b in enumerate(books):
            img = b.select_one("img")
            alt = (img["alt"] if img else "").strip()

            if " by " in alt:
                title, author = alt.rsplit(" by ", 1)
            else:
                title, author = alt, ""

            title  = title.strip()
            author = author.strip()
            result = "won" if i == 0 else "nominated"

            if not title:
                continue

            rows.append(f"{award_name}\t{year}\t{author}\t{title}\t{result}")
            print(f"  {'*' if result == 'won' else ' '} {author} — {title}")

        time.sleep(DELAY)

with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
    f.write("\n".join(rows) + "\n")

print(f"\nDone — {len(rows)} entries saved to {OUTPUT_FILE}")
