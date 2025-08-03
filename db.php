<?php
// Ensure new files are group writable
umask(0002);

require_once __DIR__ . '/TitleSortClass.php';
require_once __DIR__ . '/AuthorSortClass.php';
// Simple cookie based login
function currentUser(): ?string {
    return $_COOKIE['user'] ?? null;
}

function requireLogin(): string {
    $user = currentUser();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function getPreferences(): array {
    $file = __DIR__ . '/preferences.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function savePreferences(array $prefs): bool {
    $file = __DIR__ . '/preferences.json';
    return file_put_contents($file, json_encode($prefs, JSON_PRETTY_PRINT)) !== false;
}

function getPreference(string $key, $default = null) {
    $prefs = getPreferences();
    return $prefs[$key] ?? $default;
}

function setPreference(string $key, $value): bool {
    $prefs = getPreferences();
    $prefs[$key] = $value;
    return savePreferences($prefs);
}

function getUsers(): array {
    $file = __DIR__ . '/users.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function saveUsers(array $users): bool {
    $file = __DIR__ . '/users.json';
    return file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT)) !== false;
}

function getUser(string $username): ?array {
    $users = getUsers();
    return $users[$username] ?? null;
}

function validateUser(string $username, string $password): bool {
    $user = getUser($username);
    return $user && ($user['password'] === $password);
}

function getUserPreference(string $username, string $key, $default = null) {
    $user = getUser($username);
    if ($user && isset($user['prefs'][$key])) {
        return $user['prefs'][$key];
    }
    return $default;
}

function setUserPreference(string $username, string $key, $value): bool {
    $users = getUsers();
    if (!isset($users[$username])) {
        return false;
    }
    $users[$username]['prefs'][$key] = $value;
    return saveUsers($users);
}

function currentDatabasePath(): string {
    $user = currentUser();
    if ($user) {
        $path = getUserPreference($user, 'db_path');
        if ($path) {
            return $path;
        }
    }
    return getPreference('db_path', 'metadata.old.db');
}

function getLibraryPath(): string {
    $user = currentUser();
    if ($user) {
        $path = getUserPreference($user, 'library_path');
        if ($path) {
            return rtrim($path, '/');
        }
    }
    return rtrim(getPreference('library_path', 'ebooks'), '/');
}

function bookHasFile(string $relativePath): bool {
    $library = getLibraryPath();
    $dir = $library . '/' . $relativePath;
    if (!is_dir($dir)) {
        return false;
    }
    foreach (glob($dir . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['epub', 'mobi', 'azw3', 'txt', 'pdf', 'docx'])) {
            return true;
        }
    }
    return false;
}

function firstBookFile(string $relativePath): ?string {
    $library = getLibraryPath();
    $dir = $library . '/' . $relativePath;
    if (!is_dir($dir)) {
        return null;
    }
       $allowed = ['epub', 'mobi', 'azw3', 'pdf', 'txt', 'docx'];
    foreach (glob($dir . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            return substr($file, strlen($library) + 1);
        }
    }
    return null;
}

function getDatabaseConnection(?string $path = null) {
    $path = $path ?? currentDatabasePath();
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Enforce foreign key constraints for the connection
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Register a PHP implementation of Calibre's title_sort function so
        // database triggers referring to title_sort() work correctly.
        $sorter = new TitleSort();
        $pdo->sqliteCreateFunction('title_sort', function ($title) use ($sorter) {
            return $sorter->sort($title);
        }, 1);

        // Register a PHP implementation of Calibre's author_sort function so
        // SQL statements can use author_sort() just like in Calibre.
        $authorSorter = new AuthorSort('invert');
        $pdo->sqliteCreateFunction('author_sort', function ($author) use ($authorSorter) {
            $author = trim($author ?? '');
            if ($author === '') {
                return '';
            }
            return $authorSorter->sort($author);
        }, 1);

        // Register a Levenshtein distance function so SQL queries can perform
        // fuzzy matching on strings when searching.
        $pdo->sqliteCreateFunction('levenshtein', function ($a, $b) {
            return levenshtein((string)$a, (string)$b);
        }, 2);

        
        
        // Provide a uuid4() function used by triggers in the Calibre schema.
        // Generates a version 4 UUID string in the standard 8-4-4-4-12 format.
        $pdo->sqliteCreateFunction('uuid4', function () {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // set version to 0100
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // set variant to 10
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }, 0);

        initializeCustomColumns($pdo);

        // Ensure indexes used by book list queries
        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS books_authors_link_bidx ON books_authors_link (book)');
            $genreId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'genre'")->fetchColumn();
            if ($genreId) {
                $pdo->exec("CREATE INDEX IF NOT EXISTS books_custom_column_{$genreId}_link_bidx ON books_custom_column_{$genreId}_link (book)");
            }
        } catch (PDOException $e) {
            error_log('Index creation failed: ' . $e->getMessage());
        }

        initializeFts($pdo);

        return $pdo;
    } catch (PDOException $e) {
        die('Connection failed: ' . $e->getMessage());
    }
}


function initializeCustomColumns(PDO $pdo): void {
    try {
        // 0. Ensure the notepad table for personal text entries
        $pdo->exec("CREATE TABLE IF NOT EXISTS notepad (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            text TEXT NOT NULL,
            time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_edited TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 1. Ensure the shelves table (custom app table, not part of Calibre itself)
        $pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
        foreach (['Physical', 'Ebook Calibre'] as $def) {
            $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$def]);
        }

        // 2. Ensure Shelf column (single-value)
        $shelfId = ensureSingleValueColumn($pdo, 'shelf', 'Shelf');
        insertDefaultSingleValue($pdo, $shelfId, 'Ebook Calibre');

        // 3. Ensure Recommendation column (single-value)
        $recommendationId = ensureSingleValueColumn($pdo, 'recommendation', 'Recommendation');

        // 4. Ensure Genre column (multi-value)
        $genreId = ensureMultiValueColumn($pdo, 'genre', 'Genre');

        // 5. Ensure Status column (multi-value)
        $statusId = ensureMultiValueColumn($pdo, 'status', 'Status');
        insertDefaultMultiValue($pdo, $statusId, 'Want to Read');

        // 6. Ensure Notes column (single-value)
        ensureSingleValueColumn($pdo, 'notes', 'Notes');

    } catch (PDOException $e) {
        error_log("initializeCustomColumns error: " . $e->getMessage());
    }
}

function initializeFts(PDO $pdo): void {
    try {
        $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS books_fts USING fts5(title, author)');

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS books_ai AFTER INSERT ON books BEGIN
    INSERT INTO books_fts(rowid, title, author)
    VALUES (
        new.id,
        new.title,
        COALESCE((SELECT GROUP_CONCAT(a.name, ' ')
                  FROM authors a
                  JOIN books_authors_link bal ON a.id = bal.author
                  WHERE bal.book = new.id), '')
    );
END;
SQL);

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS books_au AFTER UPDATE ON books BEGIN
    DELETE FROM books_fts WHERE rowid = old.id;
    INSERT INTO books_fts(rowid, title, author)
    VALUES (
        new.id,
        new.title,
        COALESCE((SELECT GROUP_CONCAT(a.name, ' ')
                  FROM authors a
                  JOIN books_authors_link bal ON a.id = bal.author
                  WHERE bal.book = new.id), '')
    );
END;
SQL);

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS books_ad AFTER DELETE ON books BEGIN
    DELETE FROM books_fts WHERE rowid = old.id;
END;
SQL);

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS bal_ai AFTER INSERT ON books_authors_link BEGIN
    DELETE FROM books_fts WHERE rowid = new.book;
    INSERT INTO books_fts(rowid, title, author)
    VALUES (
        new.book,
        (SELECT title FROM books WHERE id = new.book),
        COALESCE((SELECT GROUP_CONCAT(a.name, ' ')
                  FROM authors a
                  JOIN books_authors_link bal2 ON a.id = bal2.author
                  WHERE bal2.book = new.book), '')
    );
END;
SQL);

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS bal_au AFTER UPDATE ON books_authors_link BEGIN
    DELETE FROM books_fts WHERE rowid = old.book;
    INSERT INTO books_fts(rowid, title, author)
    SELECT b.id, b.title, COALESCE((
        SELECT GROUP_CONCAT(a.name, ' ')
        FROM authors a
        JOIN books_authors_link bal2 ON a.id = bal2.author
        WHERE bal2.book = b.id), '')
    FROM books b WHERE b.id = new.book;
END;
SQL);

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS bal_ad AFTER DELETE ON books_authors_link BEGIN
    DELETE FROM books_fts WHERE rowid = old.book;
    INSERT INTO books_fts(rowid, title, author)
    SELECT b.id, b.title, COALESCE((
        SELECT GROUP_CONCAT(a.name, ' ')
        FROM authors a
        JOIN books_authors_link bal2 ON a.id = bal2.author
        WHERE bal2.book = b.id), '')
    FROM books b WHERE b.id = old.book;
END;
SQL);

        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS authors_au AFTER UPDATE OF name ON authors BEGIN
    DELETE FROM books_fts WHERE rowid IN (
        SELECT book FROM books_authors_link WHERE author = new.id
    );
    INSERT INTO books_fts(rowid, title, author)
    SELECT b.id, b.title, COALESCE((
        SELECT GROUP_CONCAT(a.name, ' ')
        FROM authors a
        JOIN books_authors_link bal ON a.id = bal.author
        WHERE bal.book = b.id), '')
    FROM books b WHERE b.id IN (
        SELECT book FROM books_authors_link WHERE author = new.id
    );
END;
SQL);

        $pdo->exec(<<<'SQL'
INSERT INTO books_fts(rowid, title, author)
SELECT b.id, b.title, COALESCE((
    SELECT GROUP_CONCAT(a.name, ' ')
    FROM authors a
    JOIN books_authors_link bal ON a.id = bal.author
    WHERE bal.book = b.id), '')
FROM books b
WHERE b.id NOT IN (SELECT rowid FROM books_fts);
SQL);
    } catch (PDOException $e) {
        error_log('initializeFts error: ' . $e->getMessage());
    }
}

function ensureSingleValueColumn(PDO $pdo, string $label, string $name = null): int {
    $label = ltrim($label, '#');
    if ($name === null) $name = $label;

    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = ?");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        // Insert column using Calibre's default SQL
        $pdo->prepare(
            "INSERT INTO custom_columns
            (label, name, datatype, is_multiple, editable, display, normalized)
            VALUES (?, ?, 'text', False, True,
            '{\"use_decorations\": false, \"description\": \"\", \"web_search_template\": \"\"}', True)"
        )->execute([$label, $name]);

        $id = $pdo->lastInsertId();
    }

    // Create Calibre-compatible table and views
    createCalibreColumnTables($pdo, $id, false);

    return (int)$id;
}

function ensureMultiValueColumn(PDO $pdo, string $label, string $name = null): int {
    $label = ltrim($label, '#');
    if ($name === null) $name = $label;

    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = ?");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        // Insert column using Calibre's default SQL
        $pdo->prepare(
            "INSERT INTO custom_columns
            (label, name, datatype, is_multiple, editable, display, normalized)
            VALUES (?, ?, 'text', True, True,
            '{\"is_names\": false, \"description\": \"\", \"web_search_template\": \"\"}', True)"
        )->execute([$label, $name]);

        $id = $pdo->lastInsertId();
    }

    // Create Calibre-compatible table and views
    createCalibreColumnTables($pdo, $id, true);

    return (int)$id;
}

function createCalibreColumnTables(PDO $pdo, int $id, bool $isMulti): void {
    $customTable = "custom_column_$id";
    $linkTable   = "books_custom_column_{$id}_link";
    $view1       = "tag_browser_custom_column_$id";
    $view2       = "tag_browser_filtered_custom_column_$id";

    // Create value table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$customTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            value TEXT NOT NULL COLLATE NOCASE,
            link TEXT NOT NULL DEFAULT '',
            UNIQUE(value)
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS {$customTable}_idx ON {$customTable} (value COLLATE NOCASE);");

    // Create link table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$linkTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book INTEGER NOT NULL,
            value INTEGER NOT NULL,
            UNIQUE(book, value)
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS {$linkTable}_aidx ON {$linkTable} (value);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS {$linkTable}_bidx ON {$linkTable} (book);");

    // Drop and recreate views (ensures consistency)
    $pdo->exec("DROP VIEW IF EXISTS {$view1};");
    $pdo->exec("DROP VIEW IF EXISTS {$view2};");

    $pdo->exec("
        CREATE VIEW {$view1} AS SELECT
            id,
            value,
            (SELECT COUNT(id) FROM {$linkTable} WHERE value={$customTable}.id) count,
            (SELECT AVG(r.rating)
             FROM {$linkTable},
                  books_ratings_link as bl,
                  ratings as r
             WHERE {$linkTable}.value={$customTable}.id and bl.book={$linkTable}.book and
                   r.id = bl.rating and r.rating <> 0) avg_rating,
            value AS sort
        FROM {$customTable};
    ");

    $pdo->exec("
        CREATE VIEW {$view2} AS SELECT
            id,
            value,
            (SELECT COUNT({$linkTable}.id) FROM {$linkTable} WHERE value={$customTable}.id AND
            books_list_filter(book)) count,
            (SELECT AVG(r.rating)
             FROM {$linkTable},
                  books_ratings_link as bl,
                  ratings as r
             WHERE {$linkTable}.value={$customTable}.id AND bl.book={$linkTable}.book AND
                   r.id = bl.rating AND r.rating <> 0 AND
                   books_list_filter(bl.book)) avg_rating,
            value AS sort
        FROM {$customTable};
    ");
}

function insertDefaultSingleValue(PDO $pdo, int $columnId, string $defaultValue): void {
    $customTable = "custom_column_$columnId";
    $linkTable   = "books_custom_column_{$columnId}_link";

    // Ensure default value exists in the value table
    $pdo->prepare("INSERT OR IGNORE INTO $customTable (value) VALUES (?)")
        ->execute([$defaultValue]);

    // Get the ID of the default value
    $stmt = $pdo->prepare("SELECT id FROM $customTable WHERE value = ?");
    $stmt->execute([$defaultValue]);
    $valueId = $stmt->fetchColumn();

    // Assign the default value to all books not yet linked
    $pdo->exec("
        INSERT OR IGNORE INTO $linkTable (book, value)
        SELECT id, $valueId FROM books
        WHERE id NOT IN (SELECT book FROM $linkTable)
    ");
}

function insertDefaultMultiValue(PDO $pdo, int $columnId, string $defaultValue): void {
    $customTable = "custom_column_$columnId";
    $linkTable   = "books_custom_column_{$columnId}_link";

    // Ensure default value exists
    $pdo->prepare("INSERT OR IGNORE INTO $customTable (value) VALUES (?)")
        ->execute([$defaultValue]);

    // Get the ID of the default value
    $stmt = $pdo->prepare("SELECT id FROM $customTable WHERE value = ?");
    $stmt->execute([$defaultValue]);
    $valueId = $stmt->fetchColumn();

    // Assign default value to books without any entry
    $pdo->exec("
        INSERT OR IGNORE INTO $linkTable (book, value)
        SELECT id, $valueId FROM books
        WHERE id NOT IN (SELECT book FROM $linkTable)
    ");
}