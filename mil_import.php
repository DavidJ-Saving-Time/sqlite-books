<?php
require_once 'vendor/autoload.php';

use Meilisearch\Client;

// === Settings ===
$directoryPath = '/srv/http/calibre-nilla/dcc_list'; // <-- Update this path
$filePattern = '*.txt';               // Adjust if needed
$batchSize = 1000;

// === Connect to Meilisearch ===
$client = new Client('http://127.0.0.1:7700', 'pqpv3Qse4V0YQDgfLmpGYt8nmYyKIVb2Mp0XFkUWu3s');

$index = $client->index('lines');

// Set searchable/displayed fields
$index->updateSettings([
    'searchableAttributes' => ['text'],
    'displayedAttributes' => ['text'],
]);

// === Process All Files ===
$totalImported = 0;
$totalSkipped = 0;
$batch = [];

$files = glob("$directoryPath/$filePattern");

if (empty($files)) {
    echo "⚠️ No matching files found in $directoryPath\n";
    exit;
}

foreach ($files as $filePath) {
    $file = new SplFileObject($filePath);
    $fileImported = 0;
    $fileSkipped = 0;

    while (!$file->eof()) {
        $line = trim($file->fgets());

        if ($line === '') {
            $fileSkipped++;
            continue;
        }

        // Remove non-printable characters
        $line = preg_replace('/[[:^print:]]/', '', $line);

        // Convert to valid UTF-8
        $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');

        // Skip bad encoding
        if (!mb_check_encoding($line, 'UTF-8')) {
            $fileSkipped++;
            continue;
        }

        $id = md5($line); // hash as unique ID
        $batch[] = ['id' => $id, 'text' => $line];
        $fileImported++;
        $totalImported++;

        if (count($batch) >= $batchSize) {
            $index->addDocuments($batch);
            $batch = [];
        }
    }

    echo "📄 Processed: " . basename($filePath) . " — ✅ Imported: $fileImported, ❌ Skipped: $fileSkipped\n";
    $totalSkipped += $fileSkipped;
}

// Final batch
if (!empty($batch)) {
    $index->addDocuments($batch);
}

echo "🎉 All done. Total imported: $totalImported, Total skipped: $totalSkipped\n";
