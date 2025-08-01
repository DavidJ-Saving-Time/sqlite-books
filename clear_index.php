<?php
require_once 'vendor/autoload.php';

use Meilisearch\Client;

$client = new Client('http://127.0.0.1:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');
$client->index('lines')->deleteAllDocuments();

echo "✅ Index cleared.\n";
