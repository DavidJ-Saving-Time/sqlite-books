<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

try {
    $stmt = $pdo->query('SELECT id, title FROM books ORDER BY title');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['id'] . " - " . $row['title'] . "\n";
    }
} catch (PDOException $e) {
    echo 'Query failed: ' . $e->getMessage();
}
?>
