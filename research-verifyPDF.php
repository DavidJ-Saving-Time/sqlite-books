<?php
/**
 * verify_random_pages.php - quick sanity check that DB page ranges match the PDF.
 *
 * Usage:
 *   php verify_random_pages.php --item-id=8 --n=5 [--pdf="/path/to/book.pdf"]
 *
 * What it does:
 *   - Reads random chunks for items.id
 *   - Shows DB's page_start-page_end (and printed pages if you set display_offset)
 *   - Prints a snippet from the DB chunk
 *   - If --pdf is given, extracts the same PDF page(s) with pdftotext and prints a snippet to compare
 */

ini_set('memory_limit', '512M');

$itemId = null;
$n      = 5;
$pdf    = null;

for ($i=1; $i<$argc; $i++) {
  if (preg_match('/^--item-id=(\d+)/', $argv[$i], $m)) $itemId = (int)$m[1];
  if (preg_match('/^--n=(\d+)/', $argv[$i], $m))       $n = max(1,(int)$m[1]);
  if (preg_match('/^--pdf=(.+)/', $argv[$i], $m))      $pdf = $m[1];
}

if (!$itemId) die("Usage: php verify_random_pages.php --item-id=ID --n=5 [--pdf=\"/path/to/book.pdf\"]\n");

$db = new PDO('sqlite:library.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// get book meta incl. display_offset (even if you don’t use it, it’s nice to see)
$book = $db->query("SELECT id, title, author, year, COALESCE(display_offset,0) AS display_offset FROM items WHERE id=".(int)$itemId)->fetch(PDO::FETCH_ASSOC);
if (!$book) die("No book with id=$itemId\n");

echo "Book: {$book['title']} ".($book['author']? "({$book['author']})":"")." ".($book['year']?:'')."\n";
echo "item_id={$book['id']} | display_offset={$book['display_offset']}\n\n";

// sample N random chunks for this book
$stmt = $db->prepare("
  SELECT id, page_start, page_end, text
  FROM chunks
  WHERE item_id = :id
  ORDER BY RANDOM()
  LIMIT :n
");
$stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
$stmt->bindValue(':n',  $n,      PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "No chunks for item_id=$itemId\n"; exit; }

$counter = 1;
foreach ($rows as $r) {
  $dbStart = (int)$r['page_start'];
  $dbEnd   = (int)$r['page_end'];
  $dispStart = $dbStart + (int)$book['display_offset'];
  $dispEnd   = $dbEnd   + (int)$book['display_offset'];

  // make clean snippets
  $dbSnippet = snippet($r['text']);

  echo "[Check {$counter}/{$n}] chunk_id={$r['id']}\n";
  echo " DB says: PDF p.$dbStart-$dbEnd";
  echo " | Printed p.".($dispStart>0?$dispStart:$dbStart)."-".($dispEnd>0?$dispEnd:$dbEnd)."\n";
  echo " DB snippet: $dbSnippet\n";

  if ($pdf) {
    // extract start & end pages (start page is often enough; we'll grab both briefly)
    $pdfSnip1 = extract_pdf_snippet($pdf, $dbStart);
    $pdfSnip2 = ($dbEnd !== $dbStart) ? extract_pdf_snippet($pdf, $dbEnd) : null;

    echo " PDF p.$dbStart snippet: ".($pdfSnip1!==null ? $pdfSnip1 : "[empty]")."\n";
    if ($pdfSnip2 !== null) {
      echo " PDF p.$dbEnd   snippet: ".($pdfSnip2!==null ? $pdfSnip2 : "[empty]")."\n";
    }
  }

  echo str_repeat('-', 80)."\n";
  $counter++;
}

// ------------- helpers -------------
function snippet(string $s, int $len=160): string {
  $s = preg_replace('/\s+/u', ' ', trim($s));
  if ($s === '') return '[empty]';
  return mb_substr($s, 0, $len, 'UTF-8').(mb_strlen($s,'UTF-8')>$len?'…':'');
}

function extract_pdf_snippet(string $pdf, int $page, int $len=160): ?string {
  if ($page < 1) return null;
  $tmp = tempnam(sys_get_temp_dir(), 'pg_');
  $cmd = sprintf('pdftotext -layout -enc UTF-8 -f %d -l %d %s %s',
                 $page, $page, escapeshellarg($pdf), escapeshellarg($tmp));
  exec($cmd, $_, $rc);
  $txt = @file_get_contents($tmp);
  @unlink($tmp);
  if ($txt === false) return null;
  $txt = preg_replace('/\s+/u', ' ', trim($txt));
  if ($txt === '') return '[empty page text]';
  return mb_substr($txt, 0, $len, 'UTF-8').(mb_strlen($txt,'UTF-8')>$len?'…':'');
}

