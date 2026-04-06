<?php
/**
 * Clears the current user's file-based cache.
 *
 * Deletes all JSON cache files in cache/{user}/ except device_books.json,
 * which is only refreshed during a device sync.
 *
 * POST (no parameters).
 *
 * Returns JSON:
 * - { ok: true }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$user = currentUser();
$dir  = CACHE_DIR . '/' . $user;

if (is_dir($dir)) {
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_file($file) && basename($file) !== 'device_books.json') {
            unlink($file);
        }
    }
}

echo json_encode(['ok' => true]);
