<?php
/**
 * research/db.php — Shared PostgreSQL connection for the research subsystem.
 *
 * Uses the standard PG* env vars.  To keep the research DB separate from the
 * semantic/journal DB, set PGDATABASE_RESEARCH (falls back to PGDATABASE, then
 * the literal string 'research').
 */

function getResearchDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host   = getenv('PGHOST')              ?: 'localhost';
    $port   = getenv('PGPORT')              ?: '5432';
    $dbname = getenv('PGDATABASE_RESEARCH') ?: (getenv('PGDATABASE') ?: 'research');
    $user   = getenv('PGUSER_RESEARCH')     ?: (getenv('PGUSER') ?: 'journal_user');
    $pass   = getenv('PGPASSWORD_RESEARCH') ?: (getenv('PGPASSWORD') ?: '');

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname}",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

/**
 * Create all research tables + indexes if they don't already exist.
 * Safe to call on every request — all statements are idempotent.
 */
function ensureResearchSchema(PDO $db): void {
    $db->exec("CREATE EXTENSION IF NOT EXISTS vector");

    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            id              SERIAL PRIMARY KEY,
            title           TEXT NOT NULL,
            author          TEXT,
            year            INTEGER,
            display_offset  INTEGER NOT NULL DEFAULT 0,
            library_book_id INTEGER,
            created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS chunks (
            id                  SERIAL PRIMARY KEY,
            item_id             INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
            section             TEXT,
            page_start          INTEGER,
            page_end            INTEGER,
            text                TEXT NOT NULL,
            embedding           vector,
            token_count         INTEGER,
            display_start       INTEGER,
            display_end         INTEGER,
            display_start_label TEXT,
            display_end_label   TEXT
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_chunks_item ON chunks(item_id)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS page_map (
            item_id         INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
            pdf_page        INTEGER NOT NULL,
            display_label   TEXT,
            display_number  INTEGER,
            method          TEXT,
            confidence      REAL,
            PRIMARY KEY (item_id, pdf_page)
        )
    ");

    // Generated tsvector column for full-text search (Postgres 12+).
    // The DO block silently skips if the column already exists.
    $db->exec("
        DO \$\$ BEGIN
            ALTER TABLE chunks
                ADD COLUMN text_search tsvector
                GENERATED ALWAYS AS (to_tsvector('english', coalesce(text, ''))) STORED;
        EXCEPTION WHEN duplicate_column THEN NULL;
        END \$\$
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS chunks_fts_idx ON chunks USING GIN(text_search)");
}

/**
 * Format a PHP float array as a pgvector literal: '[0.123,0.456,...]'
 * Safe to interpolate directly into SQL — only digits, dots, commas, brackets.
 */
function floatsToVector(array $floats): string {
    $parts = [];
    foreach ($floats as $f) {
        $s = rtrim(number_format((float)$f, 8, '.', ''), '0');
        $parts[] = rtrim($s, '.');
    }
    return '[' . implode(',', $parts) . ']';
}
