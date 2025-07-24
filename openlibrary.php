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
            'cover_id' => $doc['cover_i'] ?? null,
            'key' => $doc['key'] ?? '',
            'year' => $doc['first_publish_year'] ?? null
        ];
    }
    return $results;
}

function get_openlibrary_work(string $key): array {
    $key = trim($key);
    if ($key === '') {
        return [];
    }
    // Ensure the key starts with '/works/'
    if (strpos($key, '/works/') !== 0) {
        $key = '/works/' . ltrim($key, '/');
    }
    $url = 'https://openlibrary.org' . $key . '.json';

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
    if (!is_array($data)) {
        return [];
    }

    $description = '';
    if (isset($data['description'])) {
        if (is_array($data['description'])) {
            $description = $data['description']['value'] ?? '';
        } elseif (is_string($data['description'])) {
            $description = $data['description'];
        }
    }

    $subjects = [];
    if (!empty($data['subjects']) && is_array($data['subjects'])) {
        $subjects = $data['subjects'];
    }

    return [
        'title' => $data['title'] ?? '',
        'description' => $description,
        'subjects' => $subjects,
        'covers' => $data['covers'] ?? []
    ];
}
?>
