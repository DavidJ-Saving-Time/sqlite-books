<?php
use Meilisearch\Client;

header('Content-Type: application/json');

require_once 'vendor/autoload.php'; // make sure Meilisearch PHP SDK is loaded

$searchTerm = isset($_POST['q']) ? trim($_POST['q']) : '';
$requireAllWords = !empty($_POST['requireAllWords']);
$matchLimit = 1000;
$matches = [];

// Normalize for highlighting
function normalize($text) {
    return strtolower(str_replace(['-', '_'], ' ', $text));
}

function highlightWords($line, $searchTerm) {
    $line = htmlspecialchars($line);
    $searchWords = preg_split('/\s+/', preg_quote(normalize($searchTerm), '/'));

    foreach ($searchWords as $word) {
        if ($word === '') continue;
        $line = preg_replace_callback('/(' . preg_quote($word, '/') . ')/i', function ($match) {
            return '<mark>' . $match[1] . '</mark>';
        }, $line);
    }

    return $line;
}

if ($searchTerm !== '') {
    // Connect to Meilisearch
    $client = new Client('http://localhost:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
    $index = $client->index('lines');

    // Perform the fuzzy search
    $results = $index->search($searchTerm, [
        'limit' => $matchLimit
    ]);

    foreach ($results->getHits() as $hit) {
        $text = trim($hit['text'] ?? '');
        $normalized = strtolower($text);

        if ($requireAllWords) {
            $allWords = preg_split('/\s+/', strtolower($searchTerm));
            $matched = true;

            foreach ($allWords as $word) {
                if ($word && strpos($normalized, $word) === false) {
                    $matched = false;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }
        }

        $matches[] = highlightWords($text, $searchTerm);
    }
}

echo json_encode([
    'matches' => $matches
]);
