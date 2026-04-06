<?php
require __DIR__ . '/vendor/autoload.php';

use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;

$client    = new Client('http://127.0.0.1:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
$index     = $client->index('lines');

$fetchSize  = 1000;   // documents fetched per GET
$deleteSize = 500;    // IDs sent per delete batch
$offset     = 0;
$toDelete   = [];
$totalDeleted = 0;

while (true) {
    $query = (new DocumentsQuery())
        ->setLimit($fetchSize)
        ->setOffset($offset);

    $docs = $index->getDocuments($query)->getResults();

    if (empty($docs)) {
        break;
    }

    foreach ($docs as $doc) {
        if (strpos($doc['text'] ?? '', '!') !== 0) {
            $toDelete[] = $doc['id'];
        }

        // Flush a batch when we reach deleteSize
        if (count($toDelete) >= $deleteSize) {
            $task = $index->deleteDocuments($toDelete);
            $totalDeleted += count($toDelete);
            echo "Enqueued task {$task['taskUid']} ({$totalDeleted} queued so far)\n";
            $toDelete = [];
        }
    }

    $offset += $fetchSize;
}

// Flush any remaining IDs
if (!empty($toDelete)) {
    $task = $index->deleteDocuments($toDelete);
    $totalDeleted += count($toDelete);
    echo "Enqueued task {$task['taskUid']} ({$totalDeleted} queued so far)\n";
}

echo $totalDeleted > 0 ? "Done. Total enqueued for deletion: {$totalDeleted}\n" : "No documents to delete.\n";


