<?php
require_once __DIR__ . '/db.php'; // Include your db.php where the functions are defined

try {
    $pdo = getDatabaseConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Run only the initialization code
    initializeCustomColumns($pdo);

    echo "Custom columns initialized successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

