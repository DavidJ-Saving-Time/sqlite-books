<?php
/**
 * add_book.php
 * A script to replicate Calibre's "Add Book" feature.
 *
 * Usage:
 * php add_book.php "Title" "Author1, Author2" "/path/to/file.epub" "tag1,tag2"
 */

if ($argc < 4) {
    die("Usage: php add_book.php \"Title\" \"Author(s)\" \"/path/to/file.ext\" \"[tags]\"\n");
}

$title = $argv[1];
$authors_str = $argv[2];
$file_path = $argv[3];
$tags_str = $argc > 4 ? $argv[4] : "";

$libraryPath = "/home/david/nilla";  // Adjust this to your Calibre library path
$dbPath = $libraryPath . "./metadata.old.db";

if (!file_exists($dbPath)) {
    die("Error: metadata.db not found at $dbPath\n");
}

// --- Database Setup ---
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Register Calibre-like functions
$pdo->sqliteCreateFunction('title_sort', function ($title) {
    $title = trim($title ?? '');
    if ($title === '') return '';
    if (preg_match('/^(a|an|the)\s+(.+)/i', $title, $m)) {
        return $m[2] . ', ' . ucfirst(strtolower($m[1]));
    }
    return $title;
}, 1);

$pdo->sqliteCreateFunction('author_sort', function ($author) {
    $author = trim($author ?? '');
    if ($author === '') return '';
    $parts = explode(' ', $author);
    return count($parts) > 1 ? array_pop($parts) . ', ' . implode(' ', $parts) : $author;
}, 1);

$pdo->sqliteCreateFunction('uuid4', function () {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}, 0);

// --- Helper Functions ---
function safe_filename($name, $max_length = 150) {
    $name = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

// --- Author Handling ---
$authors = array_map('trim', preg_split('/,|;/', $authors_str));
$first_author = $authors[0];
$author_folder_name = safe_filename($first_author . (count($authors) > 1 ? " et al." : ""));

// Insert authors into DB
$pdo->beginTransaction();
foreach ($authors as $author) {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO authors (name, sort) VALUES (?, author_sort(?))");
    $stmt->execute([$author, $author]);
}
$pdo->commit();

// --- Insert Book ---
$book_path = safe_filename($title);
$stmt = $pdo->prepare("
    INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path, uuid)
    VALUES (?, title_sort(?), author_sort(?), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, ?, uuid4())
");
$stmt->execute([$title, $title, $first_author, $book_path]);
$bookId = $pdo->lastInsertId();

// Link book to authors
foreach ($authors as $author) {
    $pdo->exec("INSERT INTO books_authors_link (book, author)
                SELECT $bookId, id FROM authors WHERE name=" . $pdo->quote($author));
}

// --- Add Tags (Optional) ---
if (!empty($tags_str)) {
    $tags = array_map('trim', preg_split('/,|;/', $tags_str));
    foreach ($tags as $tag) {
        $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES (" . $pdo->quote($tag) . ")");
        $pdo->exec("INSERT INTO books_tags_link (book, tag)
                    SELECT $bookId, id FROM tags WHERE name=" . $pdo->quote($tag));
    }
}

// --- Create Folders ---
$author_folder = $libraryPath . "/" . $author_folder_name;
if (!is_dir($author_folder)) mkdir($author_folder, 0777, true);

$book_folder = $author_folder . "/" . safe_filename($title) . " ($bookId)";
if (!is_dir($book_folder)) mkdir($book_folder, 0777, true);

// --- Copy File ---
$ext = pathinfo($file_path, PATHINFO_EXTENSION);
$dest_file = $book_folder . "/" . safe_filename($title) . " - " . safe_filename($first_author) . "." . $ext;
if (!copy($file_path, $dest_file)) {
    die("Error: Failed to copy file to $dest_file\n");
}

// --- Create metadata.opf ---
$opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package version="2.0" xmlns="http://www.idpf.org/2007/opf">
  <metadata>
    <dc:title>$title</dc:title>
    <dc:creator opf:role="aut">$first_author</dc:creator>
    <dc:language>eng</dc:language>
    <meta name="calibre:timestamp" content="""" . date("Y-m-d\TH:i:s") . """+00:00"/>
  </metadata>
</package>
XML;

file_put_contents("$book_folder/metadata.opf", $opf);

echo "Book added: $title (ID $bookId) by $authors_str\n";
echo "Stored at: $dest_file\n";
?>

