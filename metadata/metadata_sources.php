<?php
// Unified metadata source search functions

/**
 * Search Anna's Archive for books matching a query.
 *
 * @param string $query
 * @return array
 */
function search_annas_archive(string $query, array &$debug = [], string $author = ''): array {
    $apiKey = getenv('ANNA_API_KEY');
    $debug['has_api_key'] = !empty($apiKey);
    if (!$apiKey) {
        return [];
    }

    $url = 'https://annas-archive-api.p.rapidapi.com/search?q=' . urlencode($query)
        . '&page=1&sort=mostRelevant&source=libgenLi,libgenRs,zLibrary,sciHub';
    if ($author !== '') {
        $url .= '&author=' . urlencode($author);
    }
    $debug['url'] = $url;

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
            'Content-Type: application/json',
            'x-rapidapi-host: annas-archive-api.p.rapidapi.com',
            'x-rapidapi-key: ' . $apiKey,
        ],
    ]);

    $response = curl_exec($curl);
    $debug['curl_error'] = curl_error($curl) ?: null;
    $debug['http_code']  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $debug['raw_response'] = is_string($response) ? substr($response, 0, 2000) : '(no response)';

    if ($response === false) {
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['books']) || !is_array($data['books'])) {
        return [];
    }

    $results = [];
    foreach (array_slice($data['books'], 0, 5) as $book) {
        $md5 = $book['md5'] ?? '';
        $results[] = [
            'title'       => $book['title'] ?? '',
            'authors'     => $book['author'] ?? '',
            'year'        => $book['year'] ?? '',
            'description' => '',
            'cover'       => $book['imgUrl'] ?? '',
            'genre'       => $book['genre'] ?? '',
            'size'        => $book['size'] ?? '',
            'format'      => $book['format'] ?? '',
            'md5'         => $md5,
            'source_id'   => 'annas_archive',
            'source_link' => $md5 !== '' ? 'https://annas-archive.org/md5/' . $md5 : '',
            'isbn'        => '',
            'publisher'   => '',
            'series'      => '',
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
        . '&maxResults=20&langRestrict=en&key=' . urlencode($apiKey);
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
function fetch_openlibrary_by_olid(string $olid): array {
    $olid = preg_replace('/[^A-Za-z0-9]/', '', $olid);
    if ($olid === '') return [];

    // Fetch work
    $work = fetch_openlibrary_json("https://openlibrary.org/works/{$olid}.json");
    if (!$work) return [];

    $description = '';
    if (!empty($work['description'])) {
        $description = is_array($work['description'])
            ? ($work['description']['value'] ?? '')
            : (string)$work['description'];
    }

    $cover = '';
    foreach ((array)($work['covers'] ?? []) as $cid) {
        if ((int)$cid > 0) {
            $cover = "https://covers.openlibrary.org/b/id/{$cid}-L.jpg";
            break;
        }
    }

    $subjects = array_slice((array)($work['subjects'] ?? []), 0, 20);

    // Fetch first edition for ISBN, publisher, publish date
    $isbn      = '';
    $publisher = '';
    $year      = '';
    $editions  = fetch_openlibrary_json("https://openlibrary.org/works/{$olid}/editions.json?limit=10");
    if ($editions && !empty($editions['entries'])) {
        foreach ($editions['entries'] as $ed) {
            // Prefer editions with an ISBN
            $isbn13 = $ed['isbn_13'][0] ?? '';
            $isbn10 = $ed['isbn_10'][0] ?? '';
            $edIsbn = $isbn13 ?: $isbn10;
            if ($edIsbn !== '') {
                $isbn = $edIsbn;
                $publisher = $ed['publishers'][0] ?? '';
                $pubDate   = $ed['publish_date'] ?? '';
                if ($pubDate && preg_match('/(\d{4})/', $pubDate, $m)) $year = $m[1];
                break;
            }
        }
        // If no edition had an ISBN, still grab publisher/year from first entry
        if ($isbn === '' && isset($editions['entries'][0])) {
            $first     = $editions['entries'][0];
            $publisher = $first['publishers'][0] ?? '';
            $pubDate   = $first['publish_date'] ?? '';
            if ($pubDate && preg_match('/(\d{4})/', $pubDate, $m)) $year = $m[1];
        }
    }

    return [[
        'title'       => $work['title'] ?? '',
        'authors'     => '',   // already known from Calibre
        'year'        => $year,
        'description' => $description,
        'subjects'    => $subjects,
        'cover'       => $cover,
        'key'         => "/works/{$olid}",
        'source_id'   => 'openlibrary',
        'source_link' => "https://openlibrary.org/works/{$olid}",
        'isbn'        => $isbn,
        'publisher'   => $publisher,
        'series'      => '',
    ]];
}

function search_openlibrary_isbn(string $isbn): array {
    $isbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));
    if ($isbn === '') {
        return [];
    }

    $url = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . urlencode($isbn)
         . '&format=json&jscmd=data';
    $data = fetch_openlibrary_json($url);
    if ($data === null || empty($data)) {
        return [];
    }

    $results = [];
    foreach ($data as $key => $book) {
        $cover = '';
        if (!empty($book['cover']['large'])) {
            $cover = $book['cover']['large'];
        } elseif (!empty($book['cover']['medium'])) {
            $cover = $book['cover']['medium'];
        }

        $authors = '';
        if (!empty($book['authors']) && is_array($book['authors'])) {
            $authors = implode(', ', array_column($book['authors'], 'name'));
        }

        $description = '';
        if (!empty($book['excerpts'][0]['text'])) {
            $description = $book['excerpts'][0]['text'];
        }

        $publisher = '';
        if (!empty($book['publishers'][0]['name'])) {
            $publisher = $book['publishers'][0]['name'];
        }

        $year = '';
        if (!empty($book['publish_date'])) {
            if (preg_match('/(\d{4})/', $book['publish_date'], $m)) {
                $year = $m[1];
            }
        }

        $isbnVal = '';
        if (!empty($book['identifiers']['isbn_13'][0])) {
            $isbnVal = $book['identifiers']['isbn_13'][0];
        } elseif (!empty($book['identifiers']['isbn_10'][0])) {
            $isbnVal = $book['identifiers']['isbn_10'][0];
        }

        $subjects = [];
        if (!empty($book['subjects']) && is_array($book['subjects'])) {
            $subjects = array_column($book['subjects'], 'name');
        }

        // Prefer the works key over the edition key so we get a valid OLID
        $worksKey = $book['works'][0]['key'] ?? '';
        $olKey    = $worksKey !== '' ? $worksKey : ($book['key'] ?? '');

        $results[] = [
            'title'       => $book['title'] ?? '',
            'authors'     => $authors,
            'year'        => $year,
            'description' => $description,
            'subjects'    => $subjects,
            'cover'       => $cover,
            'key'         => $olKey,
            'source_id'   => 'openlibrary',
            'source_link' => $worksKey !== '' ? 'https://openlibrary.org' . $worksKey : ($olKey !== '' ? 'https://openlibrary.org' . $olKey : ''),
            'isbn'        => $isbnVal,
            'publisher'   => $publisher,
            'series'      => '',
        ];
    }

    return $results;
}

function search_openlibrary(string $query, int $limit = 20): array {
    $url = 'https://openlibrary.org/search.json?q=' . urlencode($query) . '&limit=' . $limit;
    $data = fetch_openlibrary_json($url);
    if ($data === null || !isset($data['docs'])) {
        return [];
    }

    $results = [];
    foreach ($data['docs'] as $doc) {
        $cover = '';
        $coverId = $doc['cover_i'] ?? null;
        if ($coverId) {
            $cover = 'https://covers.openlibrary.org/b/id/' . $coverId . '-L.jpg';
        }

        $description = '';
        $subjects = [];
        $key = $doc['key'] ?? '';
        if ($key !== '') {
            $work = fetch_openlibrary_json('https://openlibrary.org' . $key . '.json');
            if ($work !== null) {
                if (isset($work['description'])) {
                    if (is_array($work['description'])) {
                        $description = $work['description']['value'] ?? '';
                    } elseif (is_string($work['description'])) {
                        $description = $work['description'];
                    }
                }
                if (!empty($work['subjects']) && is_array($work['subjects'])) {
                    $subjects = $work['subjects'];
                }
            }
        }
        if ($description === '') {
            if (!empty($doc['subtitle'])) {
                $description = $doc['subtitle'];
            } elseif (!empty($doc['first_sentence'])) {
                $description = is_array($doc['first_sentence']) ? $doc['first_sentence'][0] : $doc['first_sentence'];
            }
        }

        $results[] = [
            'title' => $doc['title'] ?? '',
            'authors' => isset($doc['author_name']) ? implode(', ', (array)$doc['author_name']) : '',
            'year' => $doc['first_publish_year'] ?? '',
            'description' => $description,
            'subjects' => $subjects,
            'cover' => $cover,
            'cover_id' => $coverId,
            'key' => $key,
            'source_id' => 'openlibrary',
            'source_link' => $key !== '' ? 'https://openlibrary.org' . $key : '',
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
function fetch_amazon_html(string $url, array $headers, ?array &$error = null) {
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
    if ($html === false) {
        $error = [
            'type' => 'curl',
            'code' => curl_errno($ch),
            'message' => curl_error($ch),
        ];
    } else {
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status >= 400) {
            $error = [
                'type' => 'http',
                'code' => $status,
            ];
        } elseif (stripos($html, 'cloudflare') !== false) {
            $error = [
                'type' => 'cloudflare',
                'code' => $status,
            ];
        }
    }
    curl_close($ch);
    return $html;
}

/**
 * Search Amazon Books by scraping result pages.
 *
 * @param string $query
 * @return array
 */
function search_amazon_books(string $query, ?array &$error = null): array {
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
    $searchHtml = fetch_amazon_html($searchUrl, $headers, $error);
    if ($searchHtml === false || $error !== null) {
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
        $itemHtml = fetch_amazon_html('https://www.amazon.com' . $link, $headers, $itemError);
        if ($itemHtml === false || $itemError !== null) {
            if ($error === null && $itemError !== null) {
                $error = $itemError;
            }
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
