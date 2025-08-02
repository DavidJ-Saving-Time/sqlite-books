<?php
require __DIR__ . '/vendor/autoload.php';

use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;

// 1. Connect to your Meilisearch server
$client = new Client('http://127.0.0.1:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
$index  = $client->index('lines');

$limit    = 1000;
$offset   = 0;
$toDelete = [];

while (true) {
    // 2. Build a DocumentsQuery instance for this page
    $query = (new DocumentsQuery())
        ->setLimit($limit)
        ->setOffset($offset);

    // 3. Fetch documents for this page
    $results = $index->getDocuments($query);
    $docs    = $results->getResults();

    // 4. Stop if no more docs
    if (empty($docs)) {
        break;
    }

    foreach ($docs as $doc) {
        // 5. Mark for deletion if text does NOT start with "!"
        if (strpos($doc['text'], '!') !== 0) {
            $toDelete[] = $doc['id'];
        }
    }

    $offset += $limit;
}

// 6. Send one batch‐delete request if needed
if (!empty($toDelete)) {
    $task = $index->deleteDocuments($toDelete);
    echo "Enqueued deletion task UID: {$task['taskUid']}\n";
} else {
    echo "No documents to delete.\n";
}


