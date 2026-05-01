<?php
/**
 * Look up a book in the Library of Congress full catalog via the Z39.50 SRU interface.
 * Returns up to 5 matching MODS records as JSON.
 *
 * GET ?title=The+Stand&author=Stephen+King
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$title  = trim($_GET['title']  ?? '');
$author = trim($_GET['author'] ?? '');

if ($title === '' && $author === '') {
    echo json_encode(['results' => [], 'error' => 'title or author required']);
    exit;
}

// Build CQL query: use full title + author surname for precision
$parts = [];
if ($title !== '') {
    $safeTitle = str_replace('"', '', $title);
    $parts[] = 'dc.title = "' . $safeTitle . '"';
}
if ($author !== '') {
    // Last significant word = surname (handles "Stephen King", "R. J. Barker", "J. R. R. Tolkien")
    $words   = array_values(array_filter(preg_split('/[\s,]+/', $author), fn($w) => strlen($w) > 1));
    $surname = !empty($words) ? end($words) : $author;
    $parts[] = 'dc.creator = "' . str_replace('"', '', $surname) . '"';
}

$cql = implode(' AND ', $parts);
$url = 'http://lx2.loc.gov:210/lcdb?operation=searchRetrieve&version=1.1'
     . '&query='          . urlencode($cql)
     . '&maximumRecords=5&recordSchema=mods';

$ctx = stream_context_create(['http' => [
    'timeout'       => 20,
    'ignore_errors' => true,
    'header'        => [
        'User-Agent: calibre-nilla/1.0 (principle3@gmail.com)',
        'From: principle3@gmail.com',
    ],
]]);

// Retry up to 3 times — the SRU gateway drops connections intermittently
$xml      = false;
$attempts = 3;
for ($try = 1; $try <= $attempts; $try++) {
    $xml = @file_get_contents($url, false, $ctx);
    if ($xml !== false && strlen($xml) > 0) break;
    if ($try < $attempts) sleep(2);
}

if ($xml === false || $xml === '') {
    echo json_encode(['results' => [], 'error' => 'LOC catalog unavailable after 3 attempts — try again shortly']);
    exit;
}

// HTML response = CAPTCHA or rate-limit page
$sniff = ltrim(substr($xml, 0, 100));
if (stripos($sniff, '<!doctype') === 0 || stripos($sniff, '<html') === 0) {
    echo json_encode(['results' => [], 'error' => 'LOC returned a CAPTCHA/rate-limit page — wait ~1 hour']);
    exit;
}

// Check HTTP status for 429/503
if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#HTTP/\S+\s+(429|503)#', $h)) {
            echo json_encode(['results' => [], 'error' => 'LOC rate limited (429/503) — wait ~1 hour']);
            exit;
        }
    }
}

try {
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('zs',   'http://www.loc.gov/zing/srw/');
    $xpath->registerNamespace('mods', 'http://www.loc.gov/mods/v3');

    // Check for diagnostics (error responses)
    $diag = $xpath->query('//zs:diagnostics');
    if ($diag->length > 0) {
        $msg = $xpath->query('//zs:diagnostics//*[local-name()="message"]')->item(0)?->textContent ?? 'SRU error';
        echo json_encode(['results' => [], 'error' => $msg]);
        exit;
    }

    $total   = (int)($xpath->query('//zs:numberOfRecords')->item(0)?->textContent ?? 0);
    $records = $xpath->query('//zs:record/zs:recordData/mods:mods');
    $results = [];

    foreach ($records as $mods) {
        $r = [];

        // Title — include subtitle; collect alternative titles
        $ns  = $xpath->query('mods:titleInfo[not(@type)]/mods:nonSort', $mods)->item(0)?->textContent ?? '';
        $t   = $xpath->query('mods:titleInfo[not(@type)]/mods:title',   $mods)->item(0)?->textContent ?? '';
        $sub = $xpath->query('mods:titleInfo[not(@type)]/mods:subTitle', $mods)->item(0)?->textContent ?? '';
        $r['title'] = trim($ns . $t . ($sub !== '' ? ': ' . $sub : ''));
        $altTitles = [];
        foreach ($xpath->query('mods:titleInfo[@type="alternative"]/mods:title', $mods) as $at) {
            $v = trim($at->textContent);
            if ($v !== '') $altTitles[] = $v;
        }
        if ($sub !== '') $altTitles[] = trim($sub);
        $r['alt_titles'] = $altTitles;

        // Primary author
        $nameMain = $xpath->query('mods:name[@type="personal"][@usage="primary"]/mods:namePart[not(@type)]', $mods)->item(0)?->textContent ?? '';
        $nameDate = $xpath->query('mods:name[@type="personal"][@usage="primary"]/mods:namePart[@type="date"]', $mods)->item(0)?->textContent ?? '';
        $r['author'] = trim($nameMain . ($nameDate ? ', ' . $nameDate : ''));

        // Publisher and date
        $r['publisher'] = $xpath->query('mods:originInfo/mods:agent/mods:namePart', $mods)->item(0)?->textContent ?? '';
        $r['date']      = $xpath->query('mods:originInfo/mods:dateIssued',          $mods)->item(0)?->textContent ?? '';
        $r['edition']   = $xpath->query('mods:originInfo/mods:edition',             $mods)->item(0)?->textContent ?? '';
        $r['place']     = $xpath->query('mods:originInfo/mods:place/mods:placeTerm[@type="text"]', $mods)->item(0)?->textContent ?? '';

        // Identifiers
        $r['lccn'] = trim($xpath->query('mods:identifier[@type="lccn"]', $mods)->item(0)?->textContent ?? '');
        $isbns = [];
        foreach ($xpath->query('mods:identifier[@type="isbn"]', $mods) as $n) $isbns[] = $n->textContent;
        $r['isbn'] = $isbns;

        // Classification
        $r['lcc'] = $xpath->query('mods:classification[@authority="lcc"]', $mods)->item(0)?->textContent ?? '';
        $r['ddc'] = $xpath->query('mods:classification[@authority="ddc"]', $mods)->item(0)?->textContent ?? '';

        // Subjects
        $subjects = [];
        foreach ($xpath->query('mods:subject', $mods) as $subj) {
            $topics = [];
            foreach ($xpath->query('mods:topic|mods:geographic|mods:temporal', $subj) as $st) {
                $topics[] = $st->textContent;
            }
            if ($topics) $subjects[] = implode(' — ', $topics);
        }
        $r['subjects'] = $subjects;

        // Genres
        $genres = [];
        foreach ($xpath->query('mods:genre', $mods) as $g) $genres[] = $g->textContent;
        $r['genres'] = $genres;

        // Physical description
        $r['extent'] = $xpath->query('mods:physicalDescription/mods:extent', $mods)->item(0)?->textContent ?? '';

        // LOC permalink
        $r['loc_url'] = $r['lccn'] ? 'https://lccn.loc.gov/' . rawurlencode(trim($r['lccn'])) : '';

        if ($r['title']) $results[] = $r;
    }

    echo json_encode(['results' => $results, 'total' => $total]);

} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
