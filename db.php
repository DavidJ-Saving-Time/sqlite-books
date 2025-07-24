<?php
function getDatabaseConnection($path = 'metadata.old.db') {
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

        return $pdo;
    } catch (PDOException $e) {
        die('Connection failed: ' . $e->getMessage());
    }
}
?>
