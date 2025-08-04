<?php
// Simple Amazon book metadata scraper in PHP
// Usage: /metadata/amazon.php?q=search+terms

header('Content-Type: application/json');
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($query === '') {
    echo json_encode([]);
    exit;
}

// Basic headers mimicking a modern browser. Amazon sometimes prefers a wider
// set, but these work for simple scraping as long as we use curl and force
// IPv4 resolution.
$headers = [
    'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/png,image/svg+xml,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: gzip, deflate, br'
];

// Helper to fetch a URL using curl. Using file_get_contents() failed in some
// environments with "Network is unreachable" because it attempted an IPv6
// connection. Curl allows us to force IPv4 and to automatically handle
// compression.
function fetch($url, $headers)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // avoid IPv6 "Network unreachable"
        CURLOPT_ENCODING => '', // handle gzip/deflate
        CURLOPT_TIMEOUT => 10
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

$searchUrl = 'https://www.amazon.com/s?k='.urlencode($query).'&i=digital-text&sprefix='.urlencode($query).'%2Cdigital-text&ref=nb_sb_noss';
$searchHtml = fetch($searchUrl, $headers);
if ($searchHtml === false) {
    echo json_encode([]);
    exit;
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
    if (!$linkNode) continue;
    $link = $linkNode->getAttribute('href');
    $itemHtml = fetch('https://www.amazon.com'.$link, $headers);
    if ($itemHtml === false) continue;

    $itemDom = new DOMDocument();
    $itemDom->loadHTML($itemHtml);
    $itemXpath = new DOMXPath($itemDom);
    $container = $itemXpath->query("//div[@cel_widget_id='dpx-ppd_csm_instrumentation_wrapper']")->item(0);
    if (!$container) continue;

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
    if (!$descNode) continue; // ignore if no description
    $description = trim(preg_replace('/\s+/', ' ', $descNode->textContent));

    $ratingNode = $itemXpath->query(".//span[contains(@class,'a-icon-alt')]", $container)->item(0);
    $rating = 0;
    if ($ratingNode && preg_match('/([0-9]+)/', $ratingNode->textContent, $m)) {
        $rating = (int)$m[1];
    }

    $coverNode = $itemXpath->query(".//img[contains(@class,'a-dynamic-image')]", $container)->item(0);
    $cover = $coverNode ? $coverNode->getAttribute('src') : '';

    $results[] = [
        'title' => $title,
        'authors' => $authors,
        'description' => $description,
        'rating' => $rating,
        'cover' => $cover,
        'url' => 'https://www.amazon.com'.$link,
        'source' => [
            'id' => 'amazon',
            'description' => 'Amazon Books',
            'link' => 'https://amazon.com/'
        ]
    ];
}

echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
