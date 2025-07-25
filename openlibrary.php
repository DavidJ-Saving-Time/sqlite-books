<?php
function fetch_openlibrary_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT      => 'sqlite-books/1.0'
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function search_openlibrary(string $query): array {
    $url = 'https://openlibrary.org/search.json?q=' . urlencode($query);
    $data = fetch_openlibrary_json($url);
    if ($data === null || !isset($data['docs'])) {
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

    $data = fetch_openlibrary_json($url);
    if ($data === null) {
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
