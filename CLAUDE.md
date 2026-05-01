# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

calibre-nilla is a PHP web application for browsing and managing an eBook library using Calibre's SQLite database format. It extends Calibre's schema with custom columns (genres, shelves, reading status, recommendations, notes) and adds multi-user support, full-text search, AI recommendations, and external API integrations (Open Library, Google Books, Anna's Archive, OpenRouter).

## Setup and Initialization

Install dependencies:
```
composer install
npm install
```

Initialize schema for each Calibre library (creates custom columns, FTS tables, indexes):
```
php scripts/init_schema.php
```

Clean up orphaned custom column entries after upgrades:
```
php scripts/fix_orphaned_custom_columns.php
```

There is no build step for PHP. The app runs directly via a web server (Apache/Nginx) pointing to `/srv/http/calibre-nilla/`.

## Environment Variables

| Variable | Purpose |
|---|---|
| `OPENROUTER_API_KEY` | AI book recommendations |
| `GOOGLE_BOOKS_API` | Google Books metadata lookup |
| `ANNA_API_KEY` | Anna's Archive search and download |
| `OPENAI_API_KEY` | Research AI (optional) |

## Architecture

### Authentication
- Cookie-based (`$_COOKIE['user']`), no PHP sessions
- User accounts and per-user preferences stored in `users.json`
- Each user has their own `db_path` pointing to their Calibre `metadata.db`
- `db.php` provides `currentUser()`, `requireLogin()`, `getUserPreference()`, `setUserPreference()`

### Database
- SQLite via PDO, accessed through `getDatabaseConnection()` in `db.php`
- Each user's DB path comes from their `users.json` preferences
- The DB is Calibre-compatible with additional application tables: `shelves`, `notepad`, `books_fts` (FTS5)
- Custom columns follow Calibre's pattern: `custom_columns` table + `custom_column_N` value tables + `books_custom_column_N_link` join tables
- Custom PHP SQLite functions registered on each connection: `title_sort()`, `author_sort()`, `levenshtein()`, `uuid4()`
- Foreign keys are enabled on every connection

### Routing and Entry Points
No routing framework — each `.php` file in the root is an endpoint:
- `list_books.php` — main book browser with filtering/sorting/pagination
- `book.php` — book detail and edit page
- `notes.php` — TinyMCE per-book notes editor (linked from book detail page)
- `notepad.php` — per-book annotations stored as Calibre custom columns
- `preferences.php` — user settings and maintenance tools
- `add_physical_books.php` — book file upload and import
- `research.php` — 301 redirect to `research/research-search.php`

The research subsystem and WordPro notes app each live in their own subdirectory with their own navbar.

### JSON API Endpoints
All AJAX operations go to `json_endpoints/*.php`. Each endpoint handles one operation (e.g., `add_genre.php`, `delete_shelf.php`, `save_note.php`, `recommend.php`). They return JSON and are called from frontend JS.

### Caching
File-based cache in `cache/[username]/` directories. `cache.php` provides `getCachedShelves()`, `getCachedStatuses()`, `getCachedGenres()`, `getTotalLibraryBooks()`, and `invalidateCache()`. Always invalidate relevant cache keys after writes.

### Frontend
- Bootstrap 5 + Font Awesome via CDN
- TinyMCE (from `node_modules/`) for rich text editing
- pdfjs-dist (from `node_modules/`) for PDF viewing
- `js/theme.js`, `js/search.js`, `js/navbar.js`, `js/book.js` — key JS files
- Templates in `templates/` (`book_row.php`, `book_tile.php`) render individual book entries

### FTS (Full-Text Search)
`books_fts` is an FTS5 virtual table over book titles and authors, maintained by triggers. When modifying book insertion/deletion logic, ensure FTS triggers stay in sync (recent work in commits around `6914fff`).

## Research Subsystem (`research/`)

The `research/` directory is a self-contained RAG (retrieval-augmented generation) subsystem for doing academic research against ingested book content. It has its own navbar (`research/navbar.php`) and connects directly to `library.sqlite` (the root-level file, not a user's Calibre DB).

### Additional Environment Variables

| Variable | Purpose |
|---|---|
| `OPENAI_EMBED_MODEL` | Embedding model (default: `text-embedding-3-large`) |
| `OPENROUTER_API_KEY` | Used here too, for Claude-based generation |

### research/ Schema (in `library.sqlite`)

The research subsystem adds its own tables to `library.sqlite`:

- **`items`** — ingested books: `id`, `title`, `author`, `year`, `display_offset`, `created_at`, `library_book_id` (foreign key to Calibre `books.id`)
- **`chunks`** — text passages: `id`, `item_id`, `section`, `page_start`, `page_end`, `text`, `embedding` (binary blob of packed float32 values), `display_start`, `display_end`, `display_start_label`, `display_end_label`
- **`chunks_fts`** — FTS5 virtual table over `chunks.text`
- **`page_map`** — fine-grained per-page label mapping: `item_id`, `pdf_page`, `display_label`, `display_number`, `method`, `confidence`

**Page numbering:** `items.display_offset` is a simple integer shift (PDF page + offset = printed page). `page_map` overrides this with per-page labels supporting roman numerals and arbitrary prefixes. `review-pages.php` manages this table and recomputes `chunks.display_start/display_end` after any changes.

**Embeddings:** stored as binary blobs packed with PHP's `pack('g*', ...)` (little-endian float32). Unpacked with `unpack('g*', $blob)`. Cosine similarity is computed in PHP at query time against the full corpus loaded into memory.

### research/ Pages

| File | Purpose |
|---|---|
| `research-search.php` | Full-text search across library book files using `rga` (ripgrep-all). Filters by shelf or individual book. Results show context blocks with page number detection and links to open the file at that page. |
| `research-ai.php` | Upload PDF/EPUB, split into chunks, embed via OpenAI, store in `library.sqlite`. Requires `poppler-utils` (`pdftotext`, `pdfinfo`) and `ebook-convert` (Calibre) for EPUB. Can prefill from query params: `title`, `author`, `year`, `library_book_id`, `pdf_path`, `pdf_url`. Library book autocomplete uses `json_endpoints/library_book_search.php`. |
| `research-ask.php` | RAG question answering. Embeds the question, retrieves top-K chunks by cosine similarity (with FTS pre-filter), sends context to Claude (OpenRouter), returns a sourced answer. Supports filtering by `book_id`, `min_distinct`, `show_pdf_pages`, `simple_terms`. |
| `review-pages.php` | Admin UI for the `page_map` table. Supports autodetect of roman→arabic splits, bulk rule application, and manual per-page label edits. Recomputes `chunks.display_start/display_end` after changes. |
| `extract_page_labels.py` | Python helper (requires `pypdf`) called during ingestion to extract PDF built-in page labels. Prints a JSON object mapping PDF page index (1-based) to label string. |

### research/ Shared Assets

- `css/research-theme.css` — dark archival design system shared across all research pages. Defines CSS variables, `ra-*` component classes, Bootstrap overrides, and search-result classes.
- `js/book-autocomplete.js` — reusable `BookAutocomplete` class. Instantiate with `{ input, hidden, params, onSelect, onClear }`. Call `selectById(id)` for programmatic prefill. Used on `research-ai.php` and `research-search.php`. Fetches from `json_endpoints/library_book_search.php`.
- `json_endpoints/library_book_search.php` — searches the user's Calibre DB by title/author (`?q=`), or fetches a single book (`?by_id=N`). Add `?with_files=1` to include the preferred PDF/EPUB relative path.

### research/ Workflow

1. Ingest a book via `research-ai.php` → creates `items` + `chunks` rows with embeddings
2. Optionally fix page labels via `review-pages.php` (autodetect or manual)
3. Query via `research-ask.php` (semantic Q&A) or `research-search.php` (full-text grep)

## WordPro Notes App (`notes/`)

A self-contained personal note-taking application accessible at `/notes/` (labelled "WordPro" in all navbars). Independent of the book library — notes are not linked to specific books.

### notes/ Schema (in the user's Calibre DB)

- **`notepad`** — `id`, `title`, `text` (HTML from TinyMCE), `time` (created), `last_edited`
- **`notepad_fts`** — FTS5 virtual table over `notepad(title, text)` with `content='notepad'`
- Three triggers keep FTS in sync: `notepad_ai` (insert), `notepad_au` (update), `notepad_ad` (delete)

### notes/ Files

| File | Purpose |
|---|---|
| `notes/index.php` | Single-page app: multi-tab TinyMCE editor, sidebar note list, search. All UI state in JS (`notesCache`, `openNotes`, `activeId`). Last open note persisted in `localStorage`. |
| `notes/api.php` | REST JSON API. Routes via `PATH_INFO`. `GET /` lists notes; `GET /?q=` searches via FTS5 with `snippet()`; `GET /{id}` fetches one; `POST /0` creates; `POST /{id}` updates; `DELETE /{id}` deletes. |

### notes/ Key Behaviours

- **Multi-tab editing**: multiple notes open simultaneously, Alt+1–9 to switch tabs
- **Ctrl+S** saves the active note
- **View/Edit toggle**: view mode renders HTML full-width, hides sidebar, disables save
- **Search**: FTS5 MATCH with `<mark>`-highlighted snippets returned by `snippet()` function
- TinyMCE stores content as HTML. View mode renders it directly — no Markdown conversion
- The `notepad` table is also used by `json_endpoints/save_note.php` to let `research-ask.php` save answers directly into notes

## PWA

The app ships as a Progressive Web App (Option A — manifest + service worker):

| File | Purpose |
|---|---|
| `manifest.json` | Web App Manifest: name, icons, `start_url=/list_books.php`, `display=standalone`, dark theme color |
| `sw.js` | Service worker: cache-first for static assets (JS/CSS/images/fonts + `theme.css.php`), network-first for PHP pages with offline fallback, SSE streams and `/json_endpoints/` are never cached |
| `offline.html` | Shown when a PHP page is requested offline and no cached copy exists |
| `app-icons/icon.svg` | Source SVG icon (open book, dark background) |
| `app-icons/icon-192.png` | Rasterised at 192×192 (generated via `rsvg-convert`) |
| `app-icons/icon-512.png` | Rasterised at 512×512 (generated via `rsvg-convert`) |

`navbar.php` emits the `<link rel="manifest">`, `<meta name="theme-color">`, and service worker registration `<script>` so every page that includes it gets PWA support automatically.

To bump the SW cache (e.g. after a major static asset change), increment the `CACHE` version string in `sw.js` (e.g. `nilla-v1` → `nilla-v2`).

## Future: Tauri Desktop Wrapper

**Plan:** wrap calibre-nilla in [Tauri](https://tauri.app/) to ship it as a native desktop app (Windows/macOS/Linux) for users who want a proper app icon, offline-first experience, and OS-level file access without running a web server manually.

**Key decisions to make when starting this work:**

- **Backend**: Tauri's sidecar feature can bundle a local PHP-FPM + Nginx/Caddy process; the Tauri shell just opens `http://localhost:<port>`. No PHP-to-Rust rewrite needed.
- **File access**: Calibre `metadata.db` and library path are currently configured per-user in `users.json`. For single-user desktop mode, a Tauri plugin (`tauri-plugin-dialog`) can let the user pick the library folder on first launch and store it in Tauri's app config directory.
- **Auth**: Cookie-based multi-user auth can be removed or simplified to a single auto-logged-in user for desktop builds.
- **SSE streams** (`auto_ingest_stream.php`, `similar_send_stream.php`): Tauri's webview supports `EventSource` natively — no changes needed.
- **IRC daemon** (`irc_dcc_daemon.py`): can run as a Tauri sidecar process; `DOWNLOAD_DIR` paths would need to resolve relative to the app data directory.
- **PWA → Tauri migration**: the existing `manifest.json` and `sw.js` can be kept for browser users; Tauri ignores them. Shared codebase, two distribution channels.

**Rough effort estimate:** medium (1–2 weeks) — mostly Tauri project scaffolding, sidecar bundling, and packaging; zero PHP changes required.
