<?php
// Ensure new files are group writable
umask(0002);
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
        if (in_array($ext, ['epub', 'mobi', 'azw3', 'txt'])) {
            return true;
        }
    }
    return false;
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
        $pdo->sqliteCreateFunction('title_sort', function ($title) {
            $title = trim($title ?? '');
            if ($title === '') {
                return '';
            }
            // Handle common English leading articles for basic sorting
            if (preg_match('/^(a|an|the)\s+(.+)/i', $title, $m)) {
                return $m[2] . ', ' . ucfirst(strtolower($m[1]));
            }
            return $title;
        }, 1);

        // Register a PHP implementation of Calibre's author_sort function so
        // SQL statements can use author_sort() just like in Calibre.
        $pdo->sqliteCreateFunction('author_sort', function ($author) {
            $author = trim($author ?? '');
            if ($author === '') {
                return '';
            }
            if (strpos($author, ',') !== false) {
                return $author;
            }
            $parts = preg_split('/\s+/', $author);
            if (count($parts) > 1) {
                $last = array_pop($parts);
                return $last . ', ' . implode(' ', $parts);
            }
            return $author;
        }, 1);


        // Provide a uuid4() function used by triggers in the Calibre schema.
        // Generates a version 4 UUID string in the standard 8-4-4-4-12 format.
        $pdo->sqliteCreateFunction('uuid4', function () {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // set version to 0100
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // set variant to 10
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }, 0);

        initializeCustomColumns($pdo);

        return $pdo;
    } catch (PDOException $e) {
        die('Connection failed: ' . $e->getMessage());
    }
}


function initializeCustomColumns(PDO $pdo): void {
    try {
        // 1. Ensure the shelves table (custom app table, not part of Calibre itself)
        $pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
        foreach (['Physical', 'Ebook Calibre', 'PDFs'] as $def) {
            $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$def]);
        }

        ensureCustomColumnsIndex($pdo);

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

    } catch (PDOException $e) {
        error_log("initializeCustomColumns error: " . $e->getMessage());
    }
}


function ensureSingleValueColumn(PDO $pdo, string $label, string $name = null): int {
    $label = ltrim($label, '#');
    if ($name === null) $name = $label;

    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = ?");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        $pdo->prepare(
            "INSERT INTO custom_columns
            (label, name, datatype, is_multiple, editable, display, normalized)
            VALUES (?, ?, 'text', 0, 1, '{}', 0)"
        )->execute([$label, $name]);
        $id = $pdo->lastInsertId();
    }

    $valueTable = "custom_column_$id";
    $linkTable  = "books_custom_column_{$id}_link";
    ensureSingleValueTable($pdo, $valueTable, $linkTable);
    ensureCustomColumnExtras($pdo, (int)$id);

    return (int)$id;
}

function ensureMultiValueColumn(PDO $pdo, string $label, string $name = null): int {
    $label = ltrim($label, '#');
    if ($name === null) $name = $label;

    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = ?");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        $pdo->prepare(
            "INSERT INTO custom_columns
            (label, name, datatype, is_multiple, editable, display, normalized)
            VALUES (?, ?, 'text', 1, 1, '{}', 1)"
        )->execute([$label, $name]);
        $id = $pdo->lastInsertId();
    }

    $valueTable = "custom_column_$id";
    $linkTable  = "books_custom_column_{$id}_link";
    ensureMultiValueTables($pdo, $valueTable, $linkTable);
    ensureCustomColumnExtras($pdo, (int)$id);

    return (int)$id;
}

function insertDefaultSingleValue(PDO $pdo, int $columnId, string $defaultValue): void {
    $valueTable = "custom_column_$columnId";
    $linkTable  = "books_custom_column_{$columnId}_link";

    // Ensure default value exists
    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (?)")
        ->execute([$defaultValue]);

    // Get the ID of the default value
    $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = ?");
    $stmt->execute([$defaultValue]);
    $valueId = $stmt->fetchColumn();

    // Assign default value to books without any entry
    $pdo->exec(
        "INSERT INTO $linkTable (book, value)
         SELECT id, $valueId FROM books
         WHERE id NOT IN (SELECT book FROM $linkTable)"
    );
}

function insertDefaultMultiValue(PDO $pdo, int $columnId, string $defaultValue): void {
    $valueTable = "custom_column_$columnId";
    $linkTable  = "books_custom_column_{$columnId}_link";

    // Ensure default value exists
    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (?)")
        ->execute([$defaultValue]);

    // Get the ID of the default value
    $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = ?");
    $stmt->execute([$defaultValue]);
    $valueId = $stmt->fetchColumn();

    // Assign default value to books without any entry
    $pdo->exec(
        "
        INSERT INTO $linkTable (book, value)
        SELECT id, $valueId FROM books
        WHERE id NOT IN (SELECT book FROM $linkTable)
    "
    );
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function viewExists(PDO $pdo, string $view): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='view' AND name=?");
    $stmt->execute([$view]);
    return $stmt->fetchColumn() !== false;
}

function tableColumns(PDO $pdo, string $table): array {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info('$table')") as $row) {
        $cols[] = $row['name'];
    }
    return $cols;
}

function ensureSingleValueTable(PDO $pdo, string $valueTable, string $linkTable): void {
    $expectedOld = ['book', 'value'];
    $expectedNew = ['id', 'value', 'link'];

    $needsMigration = false;
    if (tableExists($pdo, $valueTable)) {
        $cols = tableColumns($pdo, $valueTable);
        $sorted = $cols;
        sort($sorted);
        $expOld = $expectedOld;
        sort($expOld);
        $expNew = $expectedNew;
        sort($expNew);
        if ($sorted === $expOld) {
            $backup = $valueTable . '_backup_' . time();
            $pdo->exec("ALTER TABLE $valueTable RENAME TO $backup");
            $needsMigration = $backup;
        } elseif ($sorted === $expNew) {
            // Already in new format
            $backup = null;
        } else {
            $backup = $valueTable . '_backup_' . time();
            $pdo->exec("ALTER TABLE $valueTable RENAME TO $backup");
            $needsMigration = $backup;
        }
    } else {
        $backup = null;
    }

    ensureMultiValueTables($pdo, $valueTable, $linkTable);

    if ($needsMigration) {
        $pdo->exec("INSERT OR IGNORE INTO $valueTable (value) SELECT DISTINCT value FROM $needsMigration");
        $pdo->exec(
            "INSERT INTO $linkTable (book, value) " .
            "SELECT b.book, v.id FROM $needsMigration b JOIN $valueTable v ON v.value = b.value"
        );
    }
}

function ensureMultiValueValueTable(PDO $pdo, string $table): void {
    $expected = ['id', 'value', 'link'];

    if (tableExists($pdo, $table)) {
        $cols = tableColumns($pdo, $table);
        $sorted = $cols;
        sort($sorted);
        $exp = $expected;
        sort($exp);
        if ($sorted === $exp) {
            return;
        }

        $backup = $table . '_backup_' . time();
        $pdo->exec("ALTER TABLE $table RENAME TO $backup");
    } else {
        $backup = null;
    }

    $pdo->exec(
        "CREATE TABLE $table (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            value TEXT NOT NULL COLLATE NOCASE,
            link TEXT NOT NULL DEFAULT '',
            UNIQUE(value)
        )"
    );

    if ($backup) {
        $cols = tableColumns($pdo, $backup);
        $common = array_intersect($cols, $expected);
        if ($common) {
            $colList = implode(',', $common);
            $pdo->exec("INSERT INTO $table ($colList) SELECT $colList FROM $backup");
        }
    }
}

function ensureMultiValueLinkTable(PDO $pdo, string $table): void {
    $expected = ['id', 'book', 'value'];

    if (tableExists($pdo, $table)) {
        $cols = tableColumns($pdo, $table);
        $sorted = $cols;
        sort($sorted);
        $exp = $expected;
        sort($exp);
        if ($sorted === $exp) {
            return;
        }

        $backup = $table . '_backup_' . time();
        $pdo->exec("ALTER TABLE $table RENAME TO $backup");
    } else {
        $backup = null;
    }

    $pdo->exec(
        "CREATE TABLE $table (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book INTEGER NOT NULL,
            value INTEGER NOT NULL,
            UNIQUE(book, value)
        )"
    );

    if ($backup) {
        $cols = tableColumns($pdo, $backup);
        $common = array_intersect($cols, $expected);
        if ($common) {
            $colList = implode(',', $common);
            $pdo->exec("INSERT INTO $table ($colList) SELECT $colList FROM $backup");
        }
    }
}

function ensureMultiValueTables(PDO $pdo, string $valueTable, string $linkTable): void {
    ensureMultiValueValueTable($pdo, $valueTable);
    ensureMultiValueLinkTable($pdo, $linkTable);
}

/**
 * Ensure indexes and triggers for a custom column exist.
 */
function ensureCustomColumnExtras(PDO $pdo, int $id): void {
    $valueTable = "custom_column_$id";
    $linkTable  = "books_custom_column_{$id}_link";

    // Indexes for faster lookups
    $pdo->exec("CREATE INDEX IF NOT EXISTS custom_column_{$id}_idx ON $valueTable (value COLLATE NOCASE)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS books_custom_column_{$id}_link_aidx ON $linkTable (value)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS books_custom_column_{$id}_link_bidx ON $linkTable (book)");

    // Triggers for referential integrity
    $pdo->exec("DROP TRIGGER IF EXISTS fkc_update_{$linkTable}_a");
    $pdo->exec(
        "CREATE TRIGGER fkc_update_{$linkTable}_a " .
        "BEFORE UPDATE OF book ON $linkTable " .
        "BEGIN " .
            "SELECT CASE WHEN (SELECT id FROM books WHERE id=NEW.book) IS NULL " .
                "THEN RAISE(ABORT, 'Foreign key violation: book not in books') END;" .
        " END;"
    );

    $pdo->exec("DROP TRIGGER IF EXISTS fkc_update_{$linkTable}_b");
    $pdo->exec(
        "CREATE TRIGGER fkc_update_{$linkTable}_b " .
        "BEFORE UPDATE OF value ON $linkTable " .
        "BEGIN " .
            "SELECT CASE WHEN (SELECT id FROM $valueTable WHERE id=NEW.value) IS NULL " .
                "THEN RAISE(ABORT, 'Foreign key violation: value not in $valueTable') END;" .
        " END;"
    );

    $pdo->exec("DROP TRIGGER IF EXISTS fkc_insert_{$linkTable}");
    $pdo->exec(
        "CREATE TRIGGER fkc_insert_{$linkTable} " .
        "BEFORE INSERT ON $linkTable " .
        "BEGIN " .
            "SELECT CASE " .
                "WHEN (SELECT id FROM books WHERE id=NEW.book) IS NULL " .
                    "THEN RAISE(ABORT, 'Foreign key violation: book not in books') " .
                "WHEN (SELECT id FROM $valueTable WHERE id=NEW.value) IS NULL " .
                    "THEN RAISE(ABORT, 'Foreign key violation: value not in $valueTable') " .
            "END;" .
        " END;"
    );

    $pdo->exec("DROP TRIGGER IF EXISTS fkc_delete_{$linkTable}");
    $pdo->exec(
        "CREATE TRIGGER fkc_delete_{$linkTable} " .
        "AFTER DELETE ON $valueTable " .
        "BEGIN DELETE FROM $linkTable WHERE value=OLD.id; END;"
    );

    ensureCustomColumnViews($pdo, $id);
}

function ensureCustomColumnViews(PDO $pdo, int $id): void {
    $baseView = "tag_browser_custom_column_{$id}";
    $filteredView = "tag_browser_filtered_custom_column_{$id}";

    if (!viewExists($pdo, $baseView)) {
        $pdo->exec(
            "CREATE VIEW " . $baseView . " AS " .
            "SELECT id, value, " .
            "(SELECT COUNT(id) FROM books_custom_column_{$id}_link WHERE value=custom_column_{$id}.id) count, " .
            "(SELECT AVG(r.rating) FROM books_custom_column_{$id}_link, books_ratings_link AS bl, ratings AS r " .
                "WHERE books_custom_column_{$id}_link.value=custom_column_{$id}.id " .
                "AND bl.book=books_custom_column_{$id}_link.book " .
                "AND r.id = bl.rating " .
                "AND r.rating <> 0) avg_rating, " .
            "value AS sort FROM custom_column_{$id}"
        );
    }

    if (!viewExists($pdo, $filteredView)) {
        $pdo->exec(
            "CREATE VIEW " . $filteredView . " AS " .
            "SELECT id, value, " .
            "(SELECT COUNT(books_custom_column_{$id}_link.id) FROM books_custom_column_{$id}_link " .
                "WHERE value=custom_column_{$id}.id AND books_list_filter(book)) count, " .
            "(SELECT AVG(r.rating) FROM books_custom_column_{$id}_link, books_ratings_link AS bl, ratings AS r " .
                "WHERE books_custom_column_{$id}_link.value=custom_column_{$id}.id " .
                "AND bl.book=books_custom_column_{$id}_link.book " .
                "AND r.id = bl.rating " .
                "AND r.rating <> 0 " .
                "AND books_list_filter(bl.book)) avg_rating, " .
            "value AS sort FROM custom_column_{$id}"
        );
    }
}

function ensureCustomColumnsIndex(PDO $pdo): void {
    $pdo->exec("CREATE INDEX IF NOT EXISTS custom_columns_idx ON custom_columns (label)");
}
