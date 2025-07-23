<?php
function search_openlibrary(string $query): array {
    $url = 'https://openlibrary.org/search.json?q=' . urlencode($query);
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => [
                'Accept: application/json'
            ]
        ]
    ];
    $context = stream_context_create($options);
    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        return [];
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['docs'])) {
        return [];
    }
    $results = [];
    foreach ($data['docs'] as $doc) {
        $results[] = [
            'title' => $doc['title'] ?? '',
            'authors' => isset($doc['author_name']) ? implode(', ', (array)$doc['author_name']) : '',
            'cover_id' => $doc['cover_i'] ?? null
        ];
    }
    return $results;
}
?>
