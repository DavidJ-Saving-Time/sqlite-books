<?php
/**
 * Searches an IRC log index via Meilisearch and supports autocomplete.
 *
 * Expects an HTTP GET request (also accepts POST for "q").
 *
 * Query Parameters:
 * - q: Search term.
 * - requireAllWords: Optional flag to require all words.
 * - autocomplete: If present, returns suggestions instead of matches.
 *
 * Returns:
 * {"matches":array} for searches or an array of suggestions when autocomplete is used.
 */
use Meilisearch\Client;

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php'; // make sure Meilisearch PHP SDK is loaded

// Accept q via GET or POST so this script can power both search and autocomplete
$searchTerm = isset($_REQUEST['q']) ? trim((string)$_REQUEST['q']) : '';
$requireAllWords = !empty($_REQUEST['requireAllWords']);
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

    // If autocomplete is requested, return a simple array of suggestions
    if (isset($_REQUEST['autocomplete'])) {
        $results = $index->search($searchTerm, [
            'limit' => 10,
            'attributesToRetrieve' => ['text'],
        ]);

        $suggestions = [];
        foreach ($results->getHits() as $hit) {
            $text = trim($hit['text'] ?? '');
            if ($text !== '') {
                $suggestions[] = $text;
            }
        }

        echo json_encode($suggestions);
        exit;
    }

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
