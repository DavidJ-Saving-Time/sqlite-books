<?php
require_once __DIR__ . '/metadata/metadata_sources.php';

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
