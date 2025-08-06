<?php
// Lightweight file-based cache utility
// Stores JSON-encoded results with a TTL in a per-user subdirectory under cache/.

require_once __DIR__ . '/db.php';

const CACHE_DIR = __DIR__ . '/cache';
const CACHE_TTL = 3600; // default TTL in seconds

/**
 * Fetch a value from cache or generate it using the callback.
 *
 * @param string   $key     Cache key (filename without extension)
 * @param callable $create  Callback to generate the value if cache is missing or stale
 * @param int      $ttl     Time to live in seconds
 *
 * @return mixed The cached or freshly generated value
 */
function getCachedData(string $key, callable $create, int $ttl = CACHE_TTL) {
    $user = currentUser() ?: 'global';
    $dir = CACHE_DIR . '/' . $user;
    $file = $dir . '/' . $key . '.json';
    if (is_file($file) && (time() - filemtime($file) < $ttl)) {
        $data = json_decode((string)file_get_contents($file), true);
        if ($data !== null) {
            return $data;
        }
    }
    $value = $create();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($file, json_encode($value));
    return $value;
}

/**
 * Remove a cached file by key.
 */
function invalidateCache(string $key): void {
    $user = currentUser();
    if (!$user) {
        return;
    }
    $file = CACHE_DIR . '/' . $user . '/' . $key . '.json';
    if (is_file($file)) {
        unlink($file);
    }
}

/**
 * Clear all cached files for the current user.
 */
function clearUserCache(): void {
    $user = currentUser();
    if (!$user) {
        return;
    }
    $dir = CACHE_DIR . '/' . $user;
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

// Domain-specific helpers --------------------------------------------------

/** Fetch shelves with book counts, cached. */
function getCachedShelves(PDO $pdo, int $ttl = CACHE_TTL): array {
    return getCachedData('shelves', function () use ($pdo) {
        $shelfId = getCustomColumnId($pdo, 'shelf');
        $shelfValueTable = "custom_column_{$shelfId}";
        $shelfLinkTable  = "books_custom_column_{$shelfId}_link";
        try {
            $stmt = $pdo->query(
                "SELECT s.name AS value, COUNT(bsl.book) AS book_count\n" .
                "FROM shelves s\n" .
                "LEFT JOIN $shelfValueTable sv ON sv.value = s.name\n" .
                "LEFT JOIN $shelfLinkTable bsl ON bsl.value = sv.id\n" .
                "GROUP BY s.name\n" .
                "ORDER BY s.name"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }, $ttl);
}

/** Fetch statuses with book counts, cached. */
function getCachedStatuses(PDO $pdo, int $ttl = CACHE_TTL): array {
    return getCachedData('statuses', function () use ($pdo) {
        $statusId = getCustomColumnId($pdo, 'status');
        $statusTable = 'books_custom_column_' . $statusId . '_link';
        try {
            $stmt = $pdo->query(
                "SELECT cv.value, COUNT(sc.book) AS book_count\n" .
                "FROM custom_column_{$statusId} cv\n" .
                "LEFT JOIN $statusTable sc ON sc.value = cv.id\n" .
                "GROUP BY cv.id\n" .
                "ORDER BY cv.value COLLATE NOCASE"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }, $ttl);
}

/** Fetch genres with book counts, cached. */
function getCachedGenres(PDO $pdo, int $ttl = CACHE_TTL): array {
    return getCachedData('genres', function () use ($pdo) {
        $genreColumnId = getCustomColumnId($pdo, 'genre');
        $genreLinkTable = "books_custom_column_{$genreColumnId}_link";
        try {
            $stmt = $pdo->query(
                "SELECT gv.id, gv.value, COUNT(gl.book) AS book_count\n" .
                "FROM custom_column_{$genreColumnId} gv\n" .
                "LEFT JOIN $genreLinkTable gl ON gl.value = gv.id\n" .
                "GROUP BY gv.id\n" .
                "ORDER BY gv.value COLLATE NOCASE"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }, $ttl);
}

/** Total number of books in the library, cached. */
function getTotalLibraryBooks(PDO $pdo, int $ttl = CACHE_TTL): int {
    return (int)getCachedData('total_books', function () use ($pdo) {
        return (int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
    }, $ttl);
}
