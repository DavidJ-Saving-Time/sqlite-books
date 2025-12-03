# Handling delete failures in `research/research-ai.php`

## Why the fresh database helps
- `research/research-ai.php` creates the core tables (`items`, `chunks`, `page_map`) and then enforces the expected columns and triggers every time it ingests a book. A brand-new database starts with the exact schema the script expects, including the FTS5 table and triggers, which avoids mismatches with leftover shadow tables from older runs.
- On delete, the script checks for those FTS5 triggers, rebuilds them if needed, and even tries a full FTS rebuild once before giving up. If your existing database contains an older or corrupted `chunks_fts` layout, that rebuild loop can still fail with `SQL logic error`; a fresh database removes that historical mismatch.

## Trade-offs
- Dropping the existing `library.sqlite` removes all ingested books. You’ll need to re-upload everything so the embeddings, chunks, and page maps are regenerated and indexed correctly.
- If you do keep the old database, the only in-app remediation is the automatic FTS rebuild during delete; deeper corruption would require manual cleanup of `chunks_fts` and its shadow tables.

## Why a brand-new database can still fail
- The ingest path in `research/research-ai.php` already creates the core tables, ensures the `chunks` columns are up to date, and recreates the `chunks_fts` virtual table plus its triggers before it inserts the first row. That means a brand-new database starts with the exact schema the delete routine expects, so schema drift is not what’s breaking deletes on a fresh file.【F:research/research-ai.php†L332-L371】【F:research/research-ai.php†L963-L1053】
- The delete handler repeats the same guardrails: it verifies the `chunks_fts` columns, rebuilds the virtual table and triggers if they don’t match, and even does a full drop-and-backfill retry if SQLite still reports a logic error. When that retry also fails, the tables and triggers are already aligned and the remaining fault sits inside the SQLite FTS5 engine itself (for example, a host build missing FTS5 support or refusing to maintain the FTS shadow tables).【F:research/research-ai.php†L68-L130】【F:research/research-ai.php†L963-L1053】
- In practice, seeing “SQL logic error” on a fresh database with one ingested book means the PHP/SQLite build cannot execute the FTS5 maintenance statements the triggers issue during delete, even though the schema was created by the same script. The fix would need to target the SQLite/FTS5 build (upgrading PHP’s bundled SQLite, enabling the FTS5 module, or using an external SQLite binary) rather than reinitializing the database.
