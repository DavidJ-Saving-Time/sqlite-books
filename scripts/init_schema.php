<?php
require_once __DIR__ . '/../db.php';

$pdo = getDatabaseConnection();
initializeCustomColumns($pdo);

echo "Schema initialized\n";
