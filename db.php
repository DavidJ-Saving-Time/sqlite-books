<?php
function getDatabaseConnection($path = 'metadata.old.db') {
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

        // Reading status column metadata
        $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = 'status'");
        $stmt->execute();
        $statusId = $stmt->fetchColumn();
        if ($statusId === false) {
            $statusId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM custom_columns")->fetchColumn();
            $insert = $pdo->prepare("INSERT INTO custom_columns (id, label, name, datatype, mark_for_delete, editable, is_multiple, normalized, display) VALUES (:id, 'status', 'status', 'text', 0, 1, 0, 1, '{}')");
            $insert->execute([':id' => $statusId]);
        }
        $statusTable = 'books_custom_column_' . (int)$statusId;
        $pdo->exec("CREATE TABLE IF NOT EXISTS $statusTable (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
        $pdo->exec("INSERT INTO $statusTable (book, value)\n                SELECT id, 'Want to Read' FROM books\n                WHERE id NOT IN (SELECT book FROM $statusTable)");
    } catch (PDOException $e) {
        // Ignore initialization errors to avoid blocking the application
    }
}
?>
