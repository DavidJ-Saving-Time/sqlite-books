<?php
// Unified metadata source search functions

/**
 * Search Anna's Archive for books matching a query.
 *
 * @param string $query
 * @return array
 */
function search_annas_archive(string $query): array {
    $apiKey = getenv('ANNA_API_KEY');
    if (!$apiKey) {
        return [];
    }

    $url = 'https://annas-archive-api.p.rapidapi.com/search?q=' . urlencode($query)
        . '&skip=0&limit=20&source=libgenLi,libgenRs,zLibrary,sciHub';

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'x-rapidapi-host: annas-archive-api.p.rapidapi.com',
            'x-rapidapi-key: ' . $apiKey,
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    if ($response === false) {
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['books']) || !is_array($data['books'])) {
        return [];
    }

    $results = [];
    foreach ($data['books'] as $book) {
        $md5 = $book['md5'] ?? '';
        $results[] = [
            'title' => $book['title'] ?? '',
            'authors' => $book['author'] ?? '',
            'year' => $book['year'] ?? '',
            'description' => '',
            'cover' => $book['imgUrl'] ?? '',
            'source_id' => 'annas_archive',
            'source_link' => $md5 !== '' ? 'https://annas-archive.org/md5/' . $md5 : '',
            'isbn' => '',
            'publisher' => '',
            'series' => '',
        ];
    }

    return $results;
}

/**
 * Search Google Books for titles matching a query.
 *
 * @param string $query
 * @return array
 */
function search_google_books(string $query): array {
    $apiKey = getenv('GOOGLE_BOOKS_API');
    if (!$apiKey) {
        return [];
    }

    $url = 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($query)
        . '&maxResults=20&key=' . urlencode($apiKey);
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
        $published = $info['publishedDate'] ?? '';
        $year = '';
        if ($published !== '' && preg_match('/(\d{4})/', $published, $m)) {
            $year = $m[1];
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

        $isbn = '';
        if (!empty($info['industryIdentifiers']) && is_array($info['industryIdentifiers'])) {
            foreach ($info['industryIdentifiers'] as $id) {
                if (!empty($id['identifier'])) {
                    $isbn = $id['identifier'];
                    if (($id['type'] ?? '') === 'ISBN_13') {
                        break;
                    }
                }
            }
        }

        $books[] = [
            'title' => $info['title'] ?? '',
            'authors' => isset($info['authors']) ? implode(', ', (array)$info['authors']) : '',
            'year' => $year,
            'description' => $description,
            'cover' => $img,
            'source_id' => 'google_books',
            'source_link' => $info['infoLink'] ?? '',
            'isbn' => $isbn,
            'publisher' => $info['publisher'] ?? '',
            'series' => '',
        ];
    }

    return $books;
}

/**
 * Helper to fetch JSON from OpenLibrary.
 */
function fetch_openlibrary_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT => 'sqlite-books/1.0',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/**
 * Search OpenLibrary.
 *
 * @param string $query
 * @param int $limit
 * @return array
 */
function search_openlibrary(string $query, int $limit = 20): array {
    $url = 'https://openlibrary.org/search.json?q=' . urlencode($query) . '&limit=' . $limit;
    $data = fetch_openlibrary_json($url);
    if ($data === null || !isset($data['docs'])) {
        return [];
    }

    $results = [];
    foreach ($data['docs'] as $doc) {
        $cover = '';
        if (!empty($doc['cover_i'])) {
            $cover = 'https://covers.openlibrary.org/b/id/' . $doc['cover_i'] . '-L.jpg';
        }
        $description = '';
        if (!empty($doc['subtitle'])) {
            $description = $doc['subtitle'];
        } elseif (!empty($doc['first_sentence'])) {
            $description = is_array($doc['first_sentence']) ? $doc['first_sentence'][0] : $doc['first_sentence'];
        }

        $results[] = [
            'title' => $doc['title'] ?? '',
            'authors' => isset($doc['author_name']) ? implode(', ', (array)$doc['author_name']) : '',
            'year' => $doc['first_publish_year'] ?? '',
            'description' => $description,
            'cover' => $cover,
            'source_id' => 'openlibrary',
            'source_link' => isset($doc['key']) ? 'https://openlibrary.org' . $doc['key'] : '',
            'isbn' => isset($doc['isbn'][0]) ? $doc['isbn'][0] : '',
            'publisher' => isset($doc['publisher'][0]) ? $doc['publisher'][0] : '',
            'series' => isset($doc['series'][0]) ? $doc['series'][0] : '',
        ];
    }

    return $results;
}

/**
 * Helper to fetch Amazon HTML.
 */
function fetch_amazon_html(string $url, array $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 10,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

/**
 * Search Amazon Books by scraping result pages.
 *
 * @param string $query
 * @return array
 */
function search_amazon_books(string $query): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $headers = [
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/png,image/svg+xml,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
    ];

    $searchUrl = 'https://www.amazon.com/s?k=' . urlencode($query)
        . '&i=digital-text&sprefix=' . urlencode($query) . '%2Cdigital-text&ref=nb_sb_noss';
    $searchHtml = fetch_amazon_html($searchUrl, $headers);
    if ($searchHtml === false) {
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($searchHtml);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//div[@data-component-type='s-search-result']");

    $results = [];
    $max = min(3, $nodes->length);
    for ($i = 0; $i < $max; $i++) {
        $div = $nodes->item($i);
        $linkNode = $xpath->query(".//a[contains(@href,'digital-text')]", $div)->item(0);
        if (!$linkNode) {
            continue;
        }
        $link = $linkNode->getAttribute('href');
        $itemHtml = fetch_amazon_html('https://www.amazon.com' . $link, $headers);
        if ($itemHtml === false) {
            continue;
        }

        $itemDom = new DOMDocument();
        $itemDom->loadHTML($itemHtml);
        $itemXpath = new DOMXPath($itemDom);
        $container = $itemXpath->query("//div[@cel_widget_id='dpx-ppd_csm_instrumentation_wrapper']")->item(0);
        if (!$container) {
            continue;
        }

        $titleNode = $itemXpath->query(".//span[@id='productTitle']", $container)->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';

        $authorNodes = $itemXpath->query(".//span[@class='author']", $container);
        $authors = [];
        foreach ($authorNodes as $a) {
            $text = trim($a->textContent);
            if ($text !== '' && $text !== '{') {
                $authors[] = $text;
            }
        }

        $descNode = $itemXpath->query(".//div[@data-feature-name='bookDescription']", $container)->item(0);
        if (!$descNode) {
            continue; // skip if no description
        }
        $description = trim(preg_replace('/\s+/', ' ', $descNode->textContent));

        $coverNode = $itemXpath->query(".//img[contains(@class,'a-dynamic-image')]", $container)->item(0);
        $cover = $coverNode ? $coverNode->getAttribute('src') : '';

        $results[] = [
            'title' => $title,
            'authors' => implode(', ', $authors),
            'year' => '',
            'description' => $description,
            'cover' => $cover,
            'source_id' => 'amazon',
            'source_link' => 'https://www.amazon.com' . $link,
            'isbn' => '',
            'publisher' => '',
            'series' => '',
        ];
    }

    return $results;
}

?>
