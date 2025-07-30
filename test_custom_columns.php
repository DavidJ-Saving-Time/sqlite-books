<?php
require 'db.php'; // Include your db.php where the functions are defined

// Path to your Calibre metadata database
$dbPath = 'ebooks/metadata.db';

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Run only the initialization code
    initializeCustomColumns($pdo);

    echo "Custom columns initialized successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

