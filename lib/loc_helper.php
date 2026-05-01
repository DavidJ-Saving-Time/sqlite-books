<?php
/**
 * Shared LOC SRU helpers used by loc_import_stream.php and auto_ingest_stream.php.
 */

function normLoc(string $s): string {
    $s = strtolower($s);
    $s = str_replace('&', ' and ', $s);
    // Strip publishing descriptor subtitles that add no matching value:
    // ": a novel", ": a memoir", ": an epic", ": the story", etc.
    $s = preg_replace('/\s*:\s*(?:a|an|the)\s+\w+\s*$/i', '', $s);
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\b(the|a|an)\b/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** "King, Stephen, 1947-" → "Stephen King" */
function invertLocAuthor(string $raw): string {
    $parts = explode(',', $raw);
    if (count($parts) < 2) return trim($raw);
    $surname = trim($parts[0]);
    $given   = trim($parts[1]);
    $given   = preg_replace('/\s*\(.*?\)\s*/u', '', $given);
    $given   = preg_replace('/\s*\d{4}[-–]\d*\s*$/', '', $given);
    $given   = trim($given);
    return $given ? $given . ' ' . $surname : $surname;
}

/** Last meaningful word of "Firstname Lastname" for CQL dc.creator query. */
function extractSurname(string $author): string {
    $words = array_values(array_filter(
        preg_split('/[\s,]+/', $author), fn($w) => strlen($w) > 1
    ));
    return !empty($words) ? end($words) : $author;
}

function normIsbn(string $s): string {
    return preg_replace('/[^0-9X]/i', '', strtoupper(trim($s)));
}

/** Score a single title candidate against the normalised library title; returns 0–40. */
function scoreTitleMatch(string $nb, string $locTitle): int {
    $nl = normLoc($locTitle);
    if ($nb === '' || $nl === '') return 0;
    if ($nb === $nl) return 40;
    $bw = array_unique(array_filter(preg_split('/\s+/', $nb), fn($w) => strlen($w) >= 3));
    $lw = array_unique(array_filter(preg_split('/\s+/', $nl), fn($w) => strlen($w) >= 3));
    if (!$bw || !$lw) return 0;
    $ratio = count(array_intersect($bw, $lw)) / count($bw);
    return $ratio >= 1.0 ? 35 : ($ratio >= 0.7 ? 25 : ($ratio >= 0.4 ? 15 : 0));
}

/** Score a LOC MODS result against a library book; returns 0–120. */
function scoreLocResult(array $loc, string $bookTitle, string $bookAuthor, array $bookIsbns): int {
    $score = 0;

    // Title (0–40) — try main title and all alternatives, keep best
    $nb         = normLoc($bookTitle);
    $candidates = array_merge([$loc['title'] ?? ''], $loc['alt_titles'] ?? []);
    $titleScore = 0;
    foreach ($candidates as $c) $titleScore = max($titleScore, scoreTitleMatch($nb, $c));
    $score += $titleScore;

    // Author (0–30)
    $inverted = invertLocAuthor($loc['author'] ?? '');
    $nla = normLoc($inverted);
    $nba = normLoc($bookAuthor);
    if ($nla !== '' && $nba !== '') {
        $lt     = array_unique(array_filter(preg_split('/\s+/', $nla), fn($w) => strlen($w) >= 3));
        $bt     = array_unique(array_filter(preg_split('/\s+/', $nba), fn($w) => strlen($w) >= 3));
        if ($lt && $bt) {
            $common = count(array_intersect($lt, $bt));
            $needed = count($bt);
            $score += $common >= $needed ? 30
                   : ($common >= max(1, $needed - 1) ? 20
                   : ($common >= 1 ? 10 : 0));
        }
    }

    // ISBN match (0–40)
    $locIsbns = array_filter(array_map('normIsbn', $loc['isbn'] ?? []), fn($i) => strlen($i) >= 10);
    $bookNorm = array_filter(array_map('normIsbn', $bookIsbns),          fn($i) => strlen($i) >= 10);
    foreach ($bookNorm as $bi) {
        if (in_array($bi, $locIsbns)) { $score += 40; break; }
    }

    // First edition (+10)
    $ed = strtolower($loc['edition'] ?? '');
    if ($ed !== '' && (str_contains($ed, 'first') || str_contains($ed, '1st'))) {
        $score += 10;
    }

    return min($score, 120);
}

/** Parse MODS XML → array of result records. */
function parseMods(string $xml): array {
    $results = [];
    try {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) return [];
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('zs',   'http://www.loc.gov/zing/srw/');
        $xp->registerNamespace('mods', 'http://www.loc.gov/mods/v3');

        foreach ($xp->query('//zs:record/zs:recordData/mods:mods') as $mods) {
            $r   = [];
            $ns  = $xp->query('mods:titleInfo[not(@type)]/mods:nonSort',  $mods)->item(0)?->textContent ?? '';
            $t   = $xp->query('mods:titleInfo[not(@type)]/mods:title',    $mods)->item(0)?->textContent ?? '';
            $sub = $xp->query('mods:titleInfo[not(@type)]/mods:subTitle', $mods)->item(0)?->textContent ?? '';
            $r['title'] = trim($ns . $t . ($sub !== '' ? ': ' . $sub : ''));

            $altTitles = [];
            foreach ($xp->query('mods:titleInfo[@type="alternative"]/mods:title', $mods) as $at) {
                $v = trim($at->textContent);
                if ($v !== '') $altTitles[] = $v;
            }
            if ($sub !== '') $altTitles[] = trim($sub);
            $r['alt_titles'] = $altTitles;

            $nameMain = $xp->query('mods:name[@type="personal"][@usage="primary"]/mods:namePart[not(@type)]', $mods)->item(0)?->textContent ?? '';
            $nameDate = $xp->query('mods:name[@type="personal"][@usage="primary"]/mods:namePart[@type="date"]', $mods)->item(0)?->textContent ?? '';
            $r['author'] = trim($nameMain . ($nameDate ? ', ' . $nameDate : ''));

            $r['publisher'] = $xp->query('mods:originInfo[not(@eventType)]/mods:agent/mods:namePart', $mods)->item(0)?->textContent ?? '';
            $r['date']      = $xp->query('mods:originInfo[not(@eventType)]/mods:dateIssued',          $mods)->item(0)?->textContent ?? '';
            $r['edition']   = $xp->query('mods:originInfo[not(@eventType)]/mods:edition',             $mods)->item(0)?->textContent ?? '';
            $r['place']     = $xp->query('mods:originInfo[not(@eventType)]/mods:place/mods:placeTerm[@type="text"]', $mods)->item(0)?->textContent ?? '';

            $r['lccn'] = trim($xp->query('mods:identifier[@type="lccn"]', $mods)->item(0)?->textContent ?? '');
            $isbns = [];
            foreach ($xp->query('mods:identifier[@type="isbn"]', $mods) as $n) $isbns[] = $n->textContent;
            $r['isbn'] = $isbns;

            $r['lcc'] = $xp->query('mods:classification[@authority="lcc"]', $mods)->item(0)?->textContent ?? '';
            $r['ddc'] = $xp->query('mods:classification[@authority="ddc"]', $mods)->item(0)?->textContent ?? '';

            $subjects = [];
            foreach ($xp->query('mods:subject', $mods) as $subj) {
                $terms = [];
                foreach ($xp->query('mods:topic|mods:geographic|mods:temporal', $subj) as $st) $terms[] = trim($st->textContent);
                if ($terms) $subjects[] = implode(' — ', $terms);
            }
            $r['subjects'] = $subjects;

            $genres = [];
            foreach ($xp->query('mods:genre', $mods) as $g) {
                $v = trim($g->textContent);
                if ($v) $genres[] = $v;
            }
            $r['genres'] = $genres;

            if ($r['title']) $results[] = $r;
        }
    } catch (Exception $e) { /* malformed XML */ }
    return $results;
}

/**
 * Query LOC SRU at lx2.loc.gov.
 * Returns array of parsed records, false if rate-limited/CAPTCHA/service down, [] if not found.
 */
function locQuerySRU(string $title, string $surname, int $max = 5): array|false {
    $cqlParts = [];
    if ($title   !== '') $cqlParts[] = 'dc.title = "'   . str_replace('"', '', $title)   . '"';
    if ($surname !== '') $cqlParts[] = 'dc.creator = "' . str_replace('"', '', $surname) . '"';
    if (empty($cqlParts)) return [];

    $url = 'http://lx2.loc.gov:210/lcdb?operation=searchRetrieve&version=1.1'
         . '&query=' . urlencode(implode(' AND ', $cqlParts))
         . '&maximumRecords=' . $max . '&recordSchema=mods';

    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => [
            'User-Agent: calibre-nilla/1.0 (principle3@gmail.com)',
            'From: principle3@gmail.com',
        ],
    ]]);

    // Retry up to 3 times for transient connection drops
    $body = false;
    for ($try = 1; $try <= 3; $try++) {
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && strlen($body) > 0) break;
        if ($try < 3) sleep(2);
    }

    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#HTTP/\S+\s+(429|503)#', $h)) return false;
        }
    }

    // Connection failure — treat as service outage, not "not found", to avoid stamping loc_checked
    if ($body === false || $body === '') return false;

    $sniff = ltrim(substr($body, 0, 100));
    if (stripos($sniff, '<!doctype') === 0 || stripos($sniff, '<html') === 0) return false;

    return parseMods($body);
}

/** Save LOC data identifiers to the Calibre DB for a book. */
function applyLocData(PDO $pdo, int $bookId, array $loc, string $today): void {
    $ins = $pdo->prepare("INSERT OR IGNORE  INTO identifiers (book, type, val) VALUES (?, ?, ?)");
    $upd = $pdo->prepare("INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, ?, ?)");

    if (($loc['lccn'] ?? '') !== '') $upd->execute([$bookId, 'lccn', trim($loc['lccn'])]);
    if (($loc['lcc']  ?? '') !== '') $upd->execute([$bookId, 'lcc',  trim($loc['lcc'])]);

    foreach ($loc['isbn'] ?? [] as $isbn) {
        $n = normIsbn($isbn);
        if (strlen($n) >= 10) $ins->execute([$bookId, 'isbn', $n]);
    }

    if (!empty($loc['subjects'])) {
        $upd->execute([$bookId, 'loc_subjects', implode(', ', array_slice($loc['subjects'], 0, 10))]);
    }

    if (!empty($loc['genres'])) {
        $meaningful = array_filter($loc['genres'], fn($g) => strlen($g) > 6 && strtolower($g) !== 'fiction' && strtolower($g) !== 'text');
        if ($meaningful) {
            $upd->execute([$bookId, 'loc_genres', implode(', ', array_values($meaningful))]);
        }
    }

    $upd->execute([$bookId, 'loc_checked', $today]);
}
