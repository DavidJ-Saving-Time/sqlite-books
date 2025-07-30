<?php
function search_google_books(string $query): array {
    $apiKey = getenv('GOOGLE_BOOKS_API');
    if (!$apiKey) {
        return [];
    }

    $url = 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($query) .
           '&maxResults=20&key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'sqlite-books/1.0',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return [];
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['items'])) {
        return [];
    }
    $books = [];
    foreach ($data['items'] as $item) {
        $info = $item['volumeInfo'] ?? [];
        $title = $info['title'] ?? '';
        $authors = isset($info['authors']) ? implode(', ', (array)$info['authors']) : '';
        $published = $info['publishedDate'] ?? '';
        $year = '';
        if ($published !== '') {
            if (preg_match('/(\d{4})/', $published, $m)) {
                $year = $m[1];
            }
        }
        $img = '';
        if (!empty($info['imageLinks']['thumbnail'])) {
            $img = $info['imageLinks']['thumbnail'];
            if (strpos($img, 'http://') === 0) {
                $img = 'https://' . substr($img, 7);
            }
        }
        $description = '';
        if (!empty($info['description'])) {
            $description = strip_tags($info['description']);
        } elseif (!empty($item['searchInfo']['textSnippet'])) {
            $description = strip_tags($item['searchInfo']['textSnippet']);
        }
        $books[] = [
            'title' => $title,
            'author' => $authors,
            'year' => $year,
            'imgUrl' => $img,
            'description' => $description,
            'md5' => ''
        ];
    }
    return $books;
}
?>
