<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

function makeClickableLinks(string $text): string {
    return preg_replace_callback(
        '~(?<!["\'])\b(https?://[^\s<]+|www\.[^\s<]+)~i',
        function ($m) {
            $url = $m[0];
            $href = preg_match('~^https?://~i', $url) ? $url : 'http://' . $url;
            $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '" target="_blank">' . $escapedUrl . '</a>';
        },
        $text
    );
}


function italicizeBrackets(string $text): string {
    // [Title, 2010, p.164–165]  or  [Title, 2010, p.44]
    // Be generous about dash types (&ndash;, —, −, -) and spacing.
    $pattern = '/\[\s*(?<title>[^,\]]+)\s*,\s*(?<year>\d{4})'
             . '(?:\s*,\s*(?<pages>p{1,2}\.?\s*\d+(?:\s*(?:,|&ndash;|&#8211;|&#x2013;|&#8212;|—|-|\x{2212}|\p{Pd})\s*\d+)*))?'
             . '\s*\]/u';

    return preg_replace_callback($pattern, function ($m) {
        $title = trim($m['title']);
        $year  = $m['year'];
        $pagesRaw = isset($m['pages']) ? trim($m['pages']) : '';

        $pages = '';
        if ($pagesRaw !== '') {
            // Drop leading p./pp.
            $numPart = preg_replace('/^p{1,2}\.?\s*/iu', '', $pagesRaw);

            // Normalise all dash variants to a true en dash
            $numPart = preg_replace('/(&ndash;|&#8211;|&#x2013;|&#8212;|—|-|\x{2212}|\p{Pd})/u', '–', $numPart);
            // Tighten spaces around the dash
            $numPart = preg_replace('/\s*–\s*/u', '–', $numPart);

            // Decide p. vs pp. (range or multiple pages -> pp.)
            $label = (str_contains($numPart, '–') || str_contains($numPart, ',')) ? 'pp.' : 'p.';
            $pages = ", {$label} {$numPart}";
        }

        return sprintf(
            '<span class="reference text-muted" style="font-variant: small-caps;">'
          . '<cite class="fst-italic">%s</cite> (%s)%s'
          . '</span>',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($year, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($pages, ENT_QUOTES, 'UTF-8')
        );
    }, $text);
}

function debugReveal(string $text): string {
    $map = [
        "\r\n" => "␍␊\n",
        "\r"   => "␍\n",
        "\n"   => "␊\n",
        "\t"   => "␉",
        "\u{00A0}" => "⍽", // NBSP
    ];
    $vis = strtr($text, $map);
    // show HTML tags literally
    $vis = htmlspecialchars($vis, ENT_QUOTES, 'UTF-8');
    return "<pre style='white-space:pre-wrap;border:1px dashed #ccc;padding:8px'>{$vis}</pre>";
}



function formatSourcesUsedInHtml(string $html): string {
    // Match a paragraph that starts with "Sources used:" and has one or more bullet lines after,
    // all within the same <p>…</p>. We don't touch anything else.
    $pattern = '~<p[^>]*>\s*Sources\s+used\s*:?\s*(?:<br\s*/?>\s*[-•–*].*?)+\s*</p>~isu';

    return preg_replace_callback($pattern, function ($m) {
        $block = $m[0];

        // Get the inside of <p>…</p>
        $inner = preg_replace('~^<p[^>]*>|</p>$~i', '', $block);

        // Split by <br>
        $lines = preg_split('~<br\s*/?>~i', $inner);
        if (!$lines || stripos(trim($lines[0]), 'sources used') === false) {
            return $block; // safety: keep original if unexpected
        }

        // Build output
        $out = [];
        $out[] = '<h6 class="text-uppercase text-muted mt-3 mb-2">Sources used</h6>';
        $out[] = '<ul class="list-unstyled">';

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim(html_entity_decode($lines[$i], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($line === '' || !preg_match('/^[-•–*]\s+/u', $line)) {
                continue; // skip non-bullets in that paragraph
            }
            // Strip leading bullet
            $item = preg_replace('/^[-•–*]\s+/u', '', $line);
            // Drop trailing [sim=...]
            $item = preg_replace('/\s*\[sim=[^\]]+\]\s*$/iu', '', $item);

            // Expect: Title (Author(s), Year) [pages...]
            $rparen = mb_strrpos($item, ')');
            if ($rparen === false) {
                $out[] = '<li class="mb-1">'.htmlspecialchars($item, ENT_QUOTES, 'UTF-8').'</li>';
                continue;
            }
            $before = trim(mb_substr($item, 0, $rparen + 1)); // "Title (Author(s), Year)"
            $after  = trim(mb_substr($item, $rparen + 1));    // "p.xxx–yyy" (optional)

            if (!preg_match('/^(?<title>.+)\s*\(\s*(?<inside>.+)\s*\)$/u', $before, $mm)) {
                $out[] = '<li class="mb-1">'.htmlspecialchars($item, ENT_QUOTES, 'UTF-8').'</li>';
                continue;
            }

            $title  = trim($mm['title']);
            $inside = trim($mm['inside']);

            // inside = "Author(s), 2010"  → split at last comma
            $lastComma = mb_strrpos($inside, ',');
            if ($lastComma === false) {
                $out[] = '<li class="mb-1">'.htmlspecialchars($item, ENT_QUOTES, 'UTF-8').'</li>';
                continue;
            }
            $author = trim(mb_substr($inside, 0, $lastComma));
            $year   = trim(mb_substr($inside, $lastComma + 1));

            // Pages
            $pagesOut = '';
            if ($after !== '') {
                $after = preg_replace('/^[,;\.\s]+/u', '', $after);
                if (preg_match('/^p{1,2}\.?\s*[\d,\s\-\x{2013}\x{2014}]+$/iu', $after)) {
                    $numPart = preg_replace('/^p{1,2}\.?\s*/iu', '', $after);
                    // normalize any dash to en dash
                    $numPart = preg_replace('/(-|\x{2014}|\x{2013}|\x{2212}|\p{Pd})/u', '–', $numPart);
                    // tighten spaces around en dash
                    $numPart = preg_replace('/\s*–\s*/u', '–', $numPart);
                    $label = (mb_strpos($numPart, '–') !== false || mb_strpos($numPart, ',') !== false) ? 'pp.' : 'p.';
                    $pagesOut = ", {$label} {$numPart}";
                }
            }

            $out[] = sprintf(
                '<li class="mb-1"><span class="reference text-muted" style="font-variant: small-caps;">%s, <cite class="fst-italic">%s</cite> (%s)%s</span></li>',
                htmlspecialchars($author, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($title,  ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($year,   ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($pagesOut, ENT_QUOTES, 'UTF-8')
            );
        }

        $out[] = '</ul>';
        return implode("\n", $out);
    }, $html);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = trim($_POST['title'] ?? '');
    $text  = $_POST['text'] ?? '';
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE notepad SET title = :title, text = :text, last_edited = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':title' => $title, ':text' => $text, ':id' => $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO notepad (title, text) VALUES (:title, :text)');
        $stmt->execute([':title' => $title, ':text' => $text]);
        $id = (int)$pdo->lastInsertId();
    }
    header('Location: notepad.php?id=' . $id);
    exit;
}

if ($action === 'view' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM notepad WHERE id = ?');
    $stmt->execute([$id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        header('Location: notepad.php');
        exit;
    }
    $title = $note['title'];
    
    //$text  = italicizeBrackets($note['text']);
    
    $text = $note['text'];
$text = italicizeBrackets($text);      // styles [ ... ] inline refs
$text = formatSourcesUsedInHtml($text);     // formats "Sources used:" section
    
    
    //$text  = formatSourcesUsed($note['text']);
} elseif ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM notepad WHERE id = ?');
    $stmt->execute([$id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        header('Location: notepad.php');
        exit;
    }
    $title = $note['title'];
    $text  = $note['text'];
} elseif ($action === 'new') {
    $title = '';
    $text  = '';
} else {
    $notes = $pdo->query('SELECT id, title, time, last_edited FROM notepad ORDER BY last_edited DESC')->fetchAll(PDO::FETCH_ASSOC);
    try {
        $notesId  = ensureSingleValueColumn($pdo, '#notes', 'Notes');
        $valTable  = "custom_column_{$notesId}";
        $linkTable = "books_custom_column_{$notesId}_link";
        $bookNotes = $pdo->query(
            "SELECT b.id, b.title, v.value AS note
             FROM $linkTable l
             JOIN $valTable v ON l.value = v.id
             JOIN books b ON l.book = b.id
             ORDER BY b.title COLLATE NOCASE"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $bookNotes = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notepad</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <script src="node_modules/tinymce/tinymce.min.js" referrerpolicy="origin"></script>

<style>
    /* Ensure TinyMCE doesn't leave an invisible gap before rendering */
    .tox-tinymce {
        visibility: visible !important;
        opacity: 1 !important;
        transition: none !important;
    }

    /* Optional: remove extra spacing if TinyMCE adds padding */
    .tox.tox-tinymce {
        margin-top: 0 !important;
    }
  .reference {
    display: inline-block;
    padding-left: .75rem;
    border-left: 3px solid rgba(0,0,0,.1); /* looks nice with Bootstrap */
  }

  @media print {
    body {
      background: #fff !important;
      padding-top: 0 !important;
    }
    nav.navbar,
    .no-print {
      display: none !important;
    }
    .bg-white {
      box-shadow: none !important;
    }
  }
    </style>
<?php if (($id > 0 && $action !== 'view') || $action === 'new'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof tinymce !== 'undefined') {
        tinymce.init({
            selector: '#noteEditor',
            license_key: 'gpl',
            promotion: false,
            branding: false,
            height: 600
        });
    } else {
        console.error("TinyMCE not loaded!");
    }
});
</script>
<?php endif; ?>


</head>
<body class="pt-5 bg-light">
    <?php include 'navbar_other.php'; ?>

    <div class="container my-4">
        <?php if ($action === 'view' && $id > 0): ?>

            <div class="bg-white p-4 shadow rounded">
                <h2><?= $title ?></h2>
                <div><?= $text ?></div>
                <div class="d-flex justify-content-between align-items-center mt-3 no-print">
                    <a href="notepad.php" class="btn btn-secondary">Back</a>
                    <div>
                        <button type="button" onclick="window.print()" class="btn btn-outline-secondary me-2">Print</button>
                        <a href="notepad.php?id=<?= (int)$id ?>" class="btn btn-primary">Edit</a>
                    </div>
                </div>
            </div>

        <?php elseif ($id > 0 || $action === 'new'): ?>

            <form method="post" class="bg-white p-4 shadow rounded">
                <?php if ($id > 0): ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?= $title ?? '' ?>" required>
                </div>

                <div class="mb-3">
                    <label for="noteEditor" class="form-label">Note</label>
                    <textarea id="noteEditor" name="text"><?= $text ?? '' ?></textarea>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="notepad.php" class="btn btn-secondary">Back</a>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>

        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="m-0">Notepad</h1>
                <a class="btn btn-success" href="notepad.php?action=new">
                    <i class="fa-solid fa-plus me-1"></i> New Note
                </a>
            </div>

            <?php if (empty($notes)): ?>
                <p class="text-muted">No notes found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover bg-white shadow-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Last Edited</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notes as $n): ?>
                                <tr>
                                    <td><?= $n['title'] ?></td>
                                    <td><?= $n['last_edited'] ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary me-1" href="notepad.php?id=<?= (int)$n['id'] ?>&action=view">View</a>
                                        <a class="btn btn-sm btn-outline-primary me-1" href="notepad.php?id=<?= (int)$n['id'] ?>">Edit</a>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-note" data-note-id="<?= (int)$n['id'] ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h2 class="mt-4">Book Notes</h2>
                <?php if (empty($bookNotes)): ?>
                    <p class="text-muted">No book notes found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover bg-white shadow-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Book</th>
                                    <th>Excerpt</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookNotes as $bn): ?>
                                    <tr>
                                        <td><?= $bn['title'] ?></td>
                                        <td><?= mb_strimwidth(strip_tags($bn['note']), 0, 100, '...') ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="notes.php?id=<?= (int)$bn['id'] ?>">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
    document.addEventListener('click', async ev => {
        const btn = ev.target.closest('.delete-note');
        if (!btn) return;
        if (!confirm('Are you sure you want to delete this note?')) return;
        const id = btn.dataset.noteId;
        try {
            const res = await fetch('json_endpoints/delete_note.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ id })
            });
            const data = await res.json();
            if (data.status === 'ok') {
                btn.closest('tr').remove();
            }
        } catch (err) {
            console.error(err);
        }
    });
    </script>
</body>
</html>
