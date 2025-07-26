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
    return dirname(currentDatabasePath());
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
    // Ensure shelves table and default entries
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
        foreach (['Physical','Ebook Calibre','PDFs'] as $def) {
            $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$def]);
        }

        // Shelf assignment column
        $pdo->exec("CREATE TABLE IF NOT EXISTS books_custom_column_11 (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
        $pdo->exec("INSERT INTO books_custom_column_11 (book, value)\n                SELECT id, 'Ebook Calibre' FROM books\n                WHERE id NOT IN (SELECT book FROM books_custom_column_11)");

        // Recommendation storage column
        $pdo->exec("CREATE TABLE IF NOT EXISTS books_custom_column_10 (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");

        // Ensure genre and status custom columns exist
        $genreInfo = ensureMultivalueColumn($pdo, 'genre');
        $statusInfo = ensureMultivalueColumn($pdo, 'status');

        // Default status value
        [$statusId, $statusValueTable, $statusLinkTable] = $statusInfo;
        $pdo->prepare("INSERT OR IGNORE INTO $statusValueTable (value) VALUES ('Want to Read')")->execute();
        $defaultId = $pdo->query("SELECT id FROM $statusValueTable WHERE value = 'Want to Read'")->fetchColumn();
        $pdo->exec("INSERT INTO $statusLinkTable (book, value)\n                SELECT id, $defaultId FROM books\n                WHERE id NOT IN (SELECT book FROM $statusLinkTable)");
    } catch (PDOException $e) {
        // Ignore initialization errors to avoid blocking the application
    }
}

function getCustomColumnId(PDO $pdo, string $label): ?int {
    $stmt = $pdo->prepare('SELECT id FROM custom_columns WHERE label = :label');
    $stmt->execute([':label' => $label]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function ensureMultivalueColumn(PDO $pdo, string $label): array {
    $id = getCustomColumnId($pdo, $label);
    if ($id === null) {
        $id = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM custom_columns")->fetchColumn();
        $insert = $pdo->prepare("INSERT INTO custom_columns (id, label, name, datatype, mark_for_delete, editable, is_multiple, normalized, display) VALUES (:id, :label, :label, 'text', 0, 1, 1, 1, '{}')");
        $insert->execute([':id' => $id, ':label' => $label]);
    }

    $valueTable = 'custom_column_' . (int)$id;
    $linkTable = 'books_custom_column_' . (int)$id . '_link';
    $pdo->exec("CREATE TABLE IF NOT EXISTS $valueTable (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL COLLATE NOCASE, link TEXT NOT NULL DEFAULT '', UNIQUE(value))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS $linkTable (book INTEGER REFERENCES books(id) ON DELETE CASCADE, value INTEGER REFERENCES $valueTable(id), PRIMARY KEY(book,value))");

    return [$id, $valueTable, $linkTable];
}
?>
