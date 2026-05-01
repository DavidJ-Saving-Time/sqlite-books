<?php
/**
 * Parses all award source files and writes a master TSV (or JSON) containing
 * every entry (winners, nominees, special citations) across all awards.
 *
 * Output file: awards-master.tsv  (or awards-master.json with --json flag)
 *
 * CLI:  php scripts/export_awards_master.php [--json]
 * Web:  load in browser while logged in — downloads awards-master.tsv
 *
 * Columns (TSV): award  year  author  title  result
 */

$isCli     = php_sapi_name() === 'cli';
$asJson    = $isCli ? in_array('--json', $argv ?? [], true) : (isset($_GET['format']) && $_GET['format'] === 'json');
$outExt    = $asJson ? 'json' : 'tsv';
$outFile   = __DIR__ . '/../data/awards-master.' . $outExt;
$sourceDir = __DIR__ . '/../data/awards';

if (!$isCli) {
    require_once __DIR__ . '/../db.php';
    requireLogin();
}

// ── Shared DOM helpers ────────────────────────────────────────────────────────

function normalizeQuotes(string $s): string
{
    return strtr($s, [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        "\u{2032}" => "'", "\u{2033}" => '"',
    ]);
}

function extractTitle(DOMNode $td, DOMXPath $xp): string
{
    $iNodes = $xp->query('.//i', $td);
    if ($iNodes->length > 0) {
        return normalizeQuotes(trim(preg_replace('/\xc2\xa0/', ' ', $iNodes->item(0)->textContent)));
    }
    return normalizeQuotes(trim(preg_replace('/\s*\(also known as.*$/i', '', $td->textContent)));
}

function extractAuthor(DOMNode $td): string
{
    // Strip * winner marker, citation footnote refs like [64], and normalise whitespace.
    $text = str_replace('*', '', $td->textContent);
    $text = preg_replace('/\[\w+\]/', '', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function loadDom(string $file): ?array
{
    if (!file_exists($file)) {
        fwrite(STDERR, "WARNING: file not found: $file\n");
        return null;
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><html><body>' . file_get_contents($file) . '</body></html>');
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    return [$dom, $xp];
}

// ── Parser A: Hugo / Nebula / BSFA / BFA / Clarke ────────────────────────────
// Year | Author(*) | Title | Publisher | Ref
// Winner: * in author text.  Rowspan on year <th> and occasionally on title <td>.

function parseStarTable(string $htmlFile, string $awardName): array
{
    [$dom, $xp] = loadDom($htmlFile) ?? [null, null];
    if (!$dom) return [];

    $rows         = $xp->query('//tbody/tr');
    $currentYear  = null;
    $novelRowspan = 0;
    $currentTitle = null;
    $entries      = [];

    foreach ($rows as $row) {
        $thNodes = $xp->query('th', $row);
        if ($thNodes->length > 0) {
            if (preg_match('/\b(\d{4})\b/', $thNodes->item(0)->textContent, $m)) {
                $currentYear = (int)$m[1];
            }
            $novelRowspan = 0;
            $currentTitle = null;
        }
        if ($currentYear === null) continue;

        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'td') $tds[] = $n;
        }
        if (empty($tds)) continue;

        $author   = extractAuthor($tds[0]);
        $isWinner = str_contains($tds[0]->textContent, '*');

        if ($novelRowspan > 0) {
            $novelRowspan--;
            $title = $currentTitle;
        } elseif (count($tds) > 1) {
            $td = $tds[1];
            if ($td->hasAttribute('rowspan') && (int)$td->getAttribute('rowspan') > 1) {
                $novelRowspan = (int)$td->getAttribute('rowspan') - 1;
            }
            $title = $currentTitle = extractTitle($td, $xp);
        } else {
            continue;
        }

        if ($title === '') continue;
        $entries[] = ['award' => $awardName, 'year' => $currentYear, 'author' => $author, 'title' => $title, 'result' => $isWinner ? 'won' : 'nominated'];
    }
    return $entries;
}

// ── Parser B: Philip K. Dick ──────────────────────────────────────────────────
// Year | Author | Title | Ref
// Winner: <b> in author td.  Yellow+no-bold: special citation.

function parseBoldTable(string $htmlFile, string $awardName): array
{
    [$dom, $xp] = loadDom($htmlFile) ?? [null, null];
    if (!$dom) return [];

    $rows         = $xp->query('//tbody/tr');
    $currentYear  = null;
    $novelRowspan = 0;
    $currentTitle = null;
    $entries      = [];

    foreach ($rows as $row) {
        $thNodes = $xp->query('th', $row);
        if ($thNodes->length > 0) {
            if (preg_match('/\b(\d{4})\b/', $thNodes->item(0)->textContent, $m)) {
                $currentYear = (int)$m[1];
            }
            $novelRowspan = 0;
            $currentTitle = null;
        }
        if ($currentYear === null) continue;

        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'td') $tds[] = $n;
        }
        if (empty($tds)) continue;

        $author   = extractAuthor($tds[0]);
        $hasBold  = $xp->query('.//b', $tds[0])->length > 0;
        $isYellow = str_contains($row->getAttribute('style') ?? '', 'lightyellow');
        $result   = $hasBold ? 'won' : ($isYellow ? 'special citation' : 'nominated');

        if ($novelRowspan > 0) {
            $novelRowspan--;
            $title = $currentTitle;
        } elseif (count($tds) > 1) {
            $td = $tds[1];
            if ($td->hasAttribute('rowspan') && (int)$td->getAttribute('rowspan') > 1) {
                $novelRowspan = (int)$td->getAttribute('rowspan') - 1;
            }
            $title = $currentTitle = extractTitle($td, $xp);
        } else {
            continue;
        }

        if ($title === '') continue;
        $entries[] = ['award' => $awardName, 'year' => $currentYear, 'author' => $author, 'title' => $title, 'result' => $result];
    }
    return $entries;
}

// ── Parser C: Locus (winners only, one row per year) ─────────────────────────
// Year | Novel/Work | Author | Ref — no nominees, skip "Not awarded" / "No award"

function parseLocusTable(string $htmlFile, string $awardName): array
{
    [$dom, $xp] = loadDom($htmlFile) ?? [null, null];
    if (!$dom) return [];

    $rows    = $xp->query('//tbody/tr');
    $entries = [];

    foreach ($rows as $row) {
        $thNodes = $xp->query('th', $row);
        if ($thNodes->length === 0) continue;
        if (!preg_match('/\b(\d{4})\b/', $thNodes->item(0)->textContent, $m)) continue;
        $year = (int)$m[1];

        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'td') $tds[] = $n;
        }
        if (empty($tds)) continue;

        $first = $tds[0];
        if ($first->hasAttribute('colspan') || preg_match('/no\s+award/i', trim($first->textContent))) continue;

        $iNodes = $xp->query('.//i', $first);
        $title  = $iNodes->length > 0
            ? trim(preg_replace('/\xc2\xa0/', ' ', $iNodes->item(0)->textContent))
            : trim($first->textContent);

        // Author is tds[1] for Locus format
        $author = isset($tds[1]) ? trim(preg_replace('/\s+/', ' ', $tds[1]->textContent)) : '';

        if ($title === '') continue;
        $entries[] = ['award' => $awardName, 'year' => $year, 'author' => $author, 'title' => $title, 'result' => 'won'];
    }
    return $entries;
}

// ── Parser D: Gemmell plain-text ──────────────────────────────────────────────

function parseGemmellText(string $txtFile): array
{
    if (!file_exists($txtFile)) {
        fwrite(STDERR, "WARNING: file not found: $txtFile\n");
        return [];
    }
    $legendId      = 'Gemmell Fantasy';
    $morningstarId = 'Gemmell Morningstar';
    $currentYear   = null;
    $mode          = 'none';
    $entries       = [];

    foreach (file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^\d{4}$/', $line))       { $currentYear = (int)$line; $mode = 'none'; continue; }
        if (preg_match('/^The \d{4}/i', $line))     continue;
        if (preg_match('/^Cover art:/i', $line))    { $mode = 'coverart'; continue; }
        if ($mode === 'coverart')                   continue;

        if (preg_match('/^Best novel:\s+(.+?)\s+for\s+(.+)$/i', $line, $m)) {
            $mode = 'legend';
            $entries[] = ['award' => $legendId, 'year' => $currentYear, 'author' => trim($m[1]), 'title' => normalizeQuotes(trim($m[2])), 'result' => 'won'];
            continue;
        }
        if (preg_match('/^Best newcomer:\s+(.+?)\s+for\s+(.+)$/i', $line, $m)) {
            $mode = 'morningstar';
            $entries[] = ['award' => $morningstarId, 'year' => $currentYear, 'author' => trim($m[1]), 'title' => normalizeQuotes(trim($m[2])), 'result' => 'won'];
            continue;
        }
        if (preg_match('/^Nominated:\s+(.+?)\s+for\s+(.+)$/i', $line, $m)) {
            $award     = ($mode === 'morningstar') ? $morningstarId : $legendId;
            $entries[] = ['award' => $award, 'year' => $currentYear, 'author' => trim($m[1]), 'title' => normalizeQuotes(trim($m[2])), 'result' => 'nominated'];
        }
    }
    return $entries;
}

// ── Parser E: World Fantasy tab-separated text ────────────────────────────────
// Format: YEAR<TAB>(tie) Title, Author

function parseWorldFantasyText(string $txtFile): array
{
    if (!file_exists($txtFile)) {
        fwrite(STDERR, "WARNING: file not found: $txtFile\n");
        return [];
    }
    $entries = [];
    foreach (file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode("\t", $line, 2);
        if (count($parts) !== 2) continue;
        $year  = (int)trim($parts[0]);
        $rest  = preg_replace('/^\(tie\)\s*/i', '', trim($parts[1]));
        $last  = strrpos($rest, ',');
        if ($last === false || $year === 0) continue;
        $title  = trim(substr($rest, 0, $last));
        $author = trim(substr($rest, $last + 1));
        if ($title === '') continue;
        $entries[] = ['award' => 'World Fantasy Award', 'year' => $year, 'author' => $author, 'title' => $title, 'result' => 'won'];
    }
    return $entries;
}

// ── Parser F2: Goodreads direct-scrape TSV ───────────────────────────────────
// Produced by goodreads-scrape.py.  Columns: award<TAB>year<TAB>author<TAB>title<TAB>result

function parseGoodreadsTsv(string $tsvFile): array
{
    if (!file_exists($tsvFile)) {
        fwrite(STDERR, "WARNING: file not found: $tsvFile\n");
        return [];
    }
    $entries = [];
    foreach (file($tsvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 5) continue;
        [$award, $year, $author, $title, $result] = $parts;
        $entries[] = [
            'award'  => trim($award),
            'year'   => (int)$year,
            'author' => trim($author),
            'title'  => normalizeQuotes(trim($title)),
            'result' => trim($result),
        ];
    }
    return $entries;
}

// ── Parser G: Booker Prize (winners only, year in <th>, author in tds[0]) ────
// Handles rowspan ties (e.g. 1974 co-winners). All rows are winners.

function parseBookerTable(string $htmlFile, string $awardName): array
{
    [$dom, $xp] = loadDom($htmlFile) ?? [null, null];
    if (!$dom) return [];

    $rows        = $xp->query('//tbody/tr');
    $currentYear = null;
    $entries     = [];

    foreach ($rows as $row) {
        $thNodes = $xp->query('th', $row);
        if ($thNodes->length > 0) {
            if (preg_match('/\b(\d{4})\b/', $thNodes->item(0)->textContent, $m)) {
                $currentYear = (int)$m[1];
            }
        }
        if ($currentYear === null) continue;

        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'td') $tds[] = $n;
        }
        if (count($tds) < 2) continue;

        if ($tds[0]->hasAttribute('colspan') || preg_match('/not\s+award/i', trim($tds[0]->textContent))) continue;

        $author = extractAuthor($tds[0]);
        $title  = extractTitle($tds[1], $xp);
        if ($title === '' || $author === '') continue;

        $entries[] = ['award' => $awardName, 'year' => $currentYear, 'author' => $author, 'title' => $title, 'result' => 'won'];
    }
    return $entries;
}

// ── Parser H: Pulitzer Prize 1910-1970 (winners only, year in <td><b>) ───────
// Year | Author | Work | Genre — all entries are winners; "Not awarded" rows have colspan.

function parsePulitzerEarlyTable(string $htmlFile, string $awardName): array
{
    [$dom, $xp] = loadDom($htmlFile) ?? [null, null];
    if (!$dom) return [];

    $rows    = $xp->query('//tbody/tr');
    $entries = [];

    foreach ($rows as $row) {
        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'td') $tds[] = $n;
        }
        if (empty($tds)) continue;

        // "Not awarded" rows have colspan on first td or contain the phrase
        if ($tds[0]->hasAttribute('colspan') || preg_match('/not\s+award/i', trim($tds[0]->textContent))) continue;
        if (count($tds) < 3) continue;

        // Year is in first td (wrapped in <b>)
        if (!preg_match('/\b(\d{4})\b/', $tds[0]->textContent, $m)) continue;
        $year = (int)$m[1];

        $author = extractAuthor($tds[1]);
        $title  = extractTitle($tds[2], $xp);

        if ($title === '' || $author === '') continue;
        $entries[] = ['award' => $awardName, 'year' => $year, 'author' => $author, 'title' => $title, 'result' => 'won'];
    }
    return $entries;
}

// ── Parser H: Pulitzer Prize 1980s+ (winners + nominees, year in <th rowspan>) ─
// Yellow-bg rows (background:#fff7c9) are winners; title <i><b>…</b></i> = winner,
// plain <i>…</i> = nominee.

function parsePulitzerModernTable(string $htmlFile, string $awardName): array
{
    [$dom, $xp] = loadDom($htmlFile) ?? [null, null];
    if (!$dom) return [];

    $rows        = $xp->query('//tbody/tr');
    $currentYear = null;
    $entries     = [];

    foreach ($rows as $row) {
        $thNodes = $xp->query('th', $row);
        if ($thNodes->length > 0) {
            if (preg_match('/\b(\d{4})\b/', $thNodes->item(0)->textContent, $m)) {
                $currentYear = (int)$m[1];
            }
        }
        if ($currentYear === null) continue;

        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'td') $tds[] = $n;
        }
        if (count($tds) < 2) continue;

        // Skip "Not awarded"
        if ($tds[0]->hasAttribute('colspan') || preg_match('/not\s+award/i', trim($tds[0]->textContent))) continue;

        $author = extractAuthor($tds[0]);
        $title  = extractTitle($tds[1], $xp);
        if ($title === '' || $author === '') continue;

        // Winner detection: yellow background OR bold title
        $style    = $row->getAttribute('style') ?? '';
        $isWinner = str_contains($style, '#fff7c9') || $xp->query('.//b', $tds[1])->length > 0;

        $entries[] = ['award' => $awardName, 'year' => $currentYear, 'author' => $author, 'title' => $title, 'result' => $isWinner ? 'won' : 'nominated'];
    }
    return $entries;
}

// ── Parser F: Goodreads Choice Awards ────────────────────────────────────────
// Two tables, same file.  Each row: th[rowspan] Year | th Category | td Author | td Title
// Winners only (no nominees listed).

function parseGoodreadsTable(string $htmlFile, array $allowedCategories): array
{
    if (!file_exists($htmlFile)) {
        fwrite(STDERR, "WARNING: file not found: $htmlFile\n");
        return [];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><html><body>' . file_get_contents($htmlFile) . '</body></html>');
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $entries     = [];
    $seen        = [];   // dedup across both tables (2010s + 2020s)
    $currentYear = null;

    foreach ($xp->query('//tbody/tr') as $row) {
        $ths = [];
        $tds = [];
        foreach ($row->childNodes as $n) {
            if ($n->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($n->nodeName);
            if ($tag === 'th') $ths[] = $n;
            if ($tag === 'td') $tds[] = $n;
        }

        $categoryTh = null;
        foreach ($ths as $th) {
            $text = trim($th->textContent);
            if (preg_match('/\b(\d{4})\b/', $text, $m)) {
                $currentYear = (int)$m[1];
            } else {
                $categoryTh = $th;
            }
        }

        if ($currentYear === null || $categoryTh === null || count($tds) < 2) continue;

        $rawCategory = trim(preg_replace('/\s+/', ' ', $categoryTh->textContent));

        if (!isset($allowedCategories[$rawCategory])) continue;

        $awardName = 'Goodreads Choice: ' . $allowedCategories[$rawCategory];

        // Author: normalise whitespace from nested spans/links
        $author = trim(preg_replace('/\s+/', ' ', $tds[0]->textContent));

        // Title: prefer <i> content (same as other parsers)
        $iNodes = $xp->query('.//i', $tds[1]);
        $title  = $iNodes->length > 0
            ? trim(preg_replace('/[\xc2\xa0\s]+/', ' ', $iNodes->item(0)->textContent))
            : trim($tds[1]->textContent);

        if ($title === '' || $author === '') continue;

        $key = $awardName . '|' . $currentYear . '|' . $title;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $entries[] = [
            'award'  => $awardName,
            'year'   => $currentYear,
            'author' => $author,
            'title'  => $title,
            'result' => 'won',
        ];
    }

    return $entries;
}

// ── Collect all entries ───────────────────────────────────────────────────────

$all = [];

$all = array_merge($all, parseStarTable("$sourceDir/hugo-awards.txt",              'Hugo Award'));
$all = array_merge($all, parseStarTable("$sourceDir/nebular-awards.txt",           'Nebula Award'));
$all = array_merge($all, parseStarTable("$sourceDir/BSFA-Award-for-Best-Novel.txt",'BSFA Award for Best Novel'));
$all = array_merge($all, parseStarTable("$sourceDir/BFA-Award-for-Best-Novel.txt", 'BFA Award for Best Novel'));
$all = array_merge($all, parseStarTable("$sourceDir/Arthur-C-Clarke-award.txt",    'Arthur C. Clarke Award'));
$all = array_merge($all, parseBoldTable("$sourceDir/Philip-K-Dick-Award.txt",      'Philip K. Dick Award'));
$all = array_merge($all, parseLocusTable("$sourceDir/Locus-Award-for-Best-Fantasy-Novel.txt",        'Locus Best Fantasy Novel'));
$all = array_merge($all, parseLocusTable("$sourceDir/Locus-Award-for-Best-Science-Fiction-Novel.txt",'Locus Best Science Fiction Novel'));
$all = array_merge($all, parseLocusTable("$sourceDir/Locus-Best-Horror-Novel.txt", 'Locus Best Horror Novel'));
$all = array_merge($all, parseGemmellText("$sourceDir/gemmell-award-winners.txt"));
$all = array_merge($all, parseWorldFantasyText("$sourceDir/world-fantasy-award.txt"));
$all = array_merge($all, parseStarTable("$sourceDir/John-W.-Campbell-Memorial-Award.txt", 'Campbell Memorial'));
$all = array_merge($all, parsePulitzerEarlyTable("$sourceDir/Pulitzer-Prize-1910-1970.txt",  'Pulitzer Prize'));
$all = array_merge($all, parsePulitzerModernTable("$sourceDir/Pulitzer-Prize-1980s.txt",     'Pulitzer Prize'));
$all = array_merge($all, parseBookerTable("$sourceDir/booker-prize.txt",                     'Booker Prize'));
$all = array_merge($all, parseGoodreadsTsv("$sourceDir/goodreads-fantasy-science-fiction-paranormal-fantasy.txt"));
$all = array_merge($all, parseGoodreadsTable("$sourceDir/Goodreads-Choice-Awards.txt", [
    'Fiction'                               => 'Fiction',
    'Fantasy'                               => 'Fantasy',
    'Goodreads Debut Author'                => 'Debut Novel',
    'Debut Novel'                           => 'Debut Novel',
    'Horror'                                => 'Horror',
    'Humor'                                 => 'Humor',
    'Science Fiction'                       => 'Science Fiction',
    'Young Adult Fantasy & Science Fiction' => 'Young Adult Fantasy & Science Fiction',
    'Young Adult Fantasy'                   => 'Young Adult Fantasy & Science Fiction',
    'Young Adult Fiction'                   => 'Young Adult Fiction',
]));

// ── Derive "Hugo and Nebula" joint winners ────────────────────────────────────
// Index Hugo and Nebula won entries by normalised title so we can cross-reference.
$hugoWins   = [];
$nebulaWins = [];
foreach ($all as $entry) {
    if ($entry['result'] !== 'won') continue;
    $key = strtolower(normalizeQuotes($entry['title']));
    if ($entry['award'] === 'Hugo Award')   $hugoWins[$key]   = $entry;
    if ($entry['award'] === 'Nebula Award') $nebulaWins[$key] = $entry;
}
foreach ($hugoWins as $key => $entry) {
    if (isset($nebulaWins[$key])) {
        $all[] = [
            'award'  => 'Hugo and Nebula',
            'year'   => $entry['year'],
            'author' => $entry['author'],
            'title'  => $entry['title'],
            'result' => 'won',
        ];
    }
}

// Sort by award name, then year, then result (won first)
$resultOrder = ['won' => 0, 'special citation' => 1, 'nominated' => 2];
usort($all, function ($a, $b) use ($resultOrder) {
    $cmp = strcmp($a['award'], $b['award']);
    if ($cmp !== 0) return $cmp;
    $cmp = $a['year'] <=> $b['year'];
    if ($cmp !== 0) return $cmp;
    return ($resultOrder[$a['result']] ?? 9) <=> ($resultOrder[$b['result']] ?? 9);
});

// ── Write output ──────────────────────────────────────────────────────────────

if ($asJson) {
    $output = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    $lines = ["award\tyear\tauthor\ttitle\tresult"];
    foreach ($all as $e) {
        $clean = fn(string $s) => str_replace(["\t", "\n", "\r"], ' ', $s);
        $lines[] = implode("\t", [
            $clean($e['award']),
            $e['year'],
            $clean($e['author'] ?? ''),
            $clean($e['title']),
            $e['result'],
        ]);
    }
    $output = implode("\n", $lines) . "\n";
}

file_put_contents($outFile, $output);

$count = count($all);

if ($isCli) {
    echo "Written $count entries to: $outFile\n";
} else {
    $filename = 'awards-master.' . $outExt;
    $mime     = $asJson ? 'application/json' : 'text/tab-separated-values';
    header("Content-Type: $mime; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo $output;
}
