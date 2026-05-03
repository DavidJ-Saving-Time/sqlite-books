<?php
/**
 * review-pages.php — Admin tool to fix per-page display labels for citations.
 * - Seeds page_map from chunks + items.display_offset if empty.
 * - Autodetect roman→arabic split (heuristic; no PDF path required).
 * - Bulk roman→arabic rule + per-page manual edits.
 * - Recomputes chunks.display_start/display_end for citation ranges.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/db.php';

$db = getResearchDb();
ensureResearchSchema($db);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// --- Roman helpers ---
function int_to_roman(int $n, bool $lower=false): string {
  $map = [1000=>'M',900=>'CM',500=>'D',400=>'CD',100=>'C',90=>'XC',50=>'L',40=>'XL',10=>'X',9=>'IX',5=>'V',4=>'IV',1=>'I'];
  $s=''; foreach($map as $v=>$sym){ while($n>=$v){ $s.=$sym; $n-=$v; } }
  return $lower ? strtolower($s) : $s;
}

function roman_to_int(string $roman): int {
  $map = ['I'=>1,'V'=>5,'X'=>10,'L'=>50,'C'=>100,'D'=>500,'M'=>1000];
  $roman = strtoupper($roman);
  $total = 0; $prev = 0;
  for ($i = strlen($roman)-1; $i >= 0; $i--) {
    $curr = $map[$roman[$i]] ?? 0;
    if ($curr < $prev) $total -= $curr; else $total += $curr;
    $prev = $curr;
  }
  return $total;
}

// --- Recompute chunk citation ranges from page_map, fallback to display_offset ---
function recompute_chunk_display_ranges(PDO $db, int $itemId): void {
  $dispOffset = (int)$db->query("SELECT display_offset FROM items WHERE id=" . $itemId)->fetchColumn();
  $q   = $db->prepare("SELECT id, page_start, page_end FROM chunks WHERE item_id = ?");
  $q->execute([$itemId]);
  $sel = $db->prepare("SELECT display_label, display_number FROM page_map WHERE item_id = ? AND pdf_page = ?");
  $upd = $db->prepare("UPDATE chunks SET display_start=?, display_end=?, display_start_label=?, display_end_label=? WHERE id=?");
  while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $s = (int)$row['page_start']; $e = (int)$row['page_end'];
    $sel->execute([$itemId, $s]); $start = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
    $sel->execute([$itemId, $e]); $end   = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
    $startLabel = $start['display_label'] ?? null;
    $startNum   = $start['display_number'] ?? null;
    if ($startLabel === null) { $startNum = $s + $dispOffset; $startLabel = (string)$startNum; }
    if ($startNum === null && preg_match('/^[ivxlcdm]+$/i', $startLabel)) $startNum = roman_to_int($startLabel);
    $endLabel = $end['display_label'] ?? null;
    $endNum   = $end['display_number'] ?? null;
    if ($endLabel === null) { $endNum = $e + $dispOffset; $endLabel = (string)$endNum; }
    if ($endNum === null && preg_match('/^[ivxlcdm]+$/i', $endLabel)) $endNum = roman_to_int($endLabel);
    $upd->execute([$startNum, $endNum, $startLabel, $endLabel, $row['id']]);
  }
}

// --- Utilities to get text covering a PDF page ---
function text_for_page(PDO $db, int $itemId, int $pdfPage, int $limitChars=4000): string {
  $q = $db->prepare("SELECT text FROM chunks
                     WHERE item_id = ? AND ? BETWEEN page_start AND page_end
                     ORDER BY (page_end - page_start) ASC LIMIT 1");
  $q->execute([$itemId, $pdfPage]);
  $t = (string)$q->fetchColumn();
  if ($t === '') return '';
  $t = preg_replace('/[ \t]+/u', ' ', $t);
  $t = trim($t);
  if (mb_strlen($t,'UTF-8') > $limitChars) $t = mb_substr($t,0,$limitChars,'UTF-8');
  return $t;
}

// --- Heuristic autodetect of roman→arabic split using DB text ---
function autodetect_split(PDO $db, int $itemId): array {
  $N = 40;
  $maxPdf = (int)$db->query("SELECT MAX(page_end) FROM chunks WHERE item_id=" . $itemId)->fetchColumn();
  if ($maxPdf <= 0) return [null, "No pages found."];

  $end = min($N, $maxPdf);

  $frontSignals = [
    '/^\s*contents\s*$/mi',
    '/^\s*table of contents\s*$/mi',
    '/^\s*acknowledg(e)?ments\s*$/mi',
    '/^\s*preface\s*$/mi',
    '/^\s*list of (figures|tables)\s*$/mi',
    '/copyright/i'
  ];
  $mainSignals = [
    '/^\s*chapter\s+\d+\b/mi',
    '/^\s*introduction\s*$/mi',
    '/^\s*part\s+[ivxlcdm]+\b/mi',
    '/^\s*book\s+[ivxlcdm]+\b/mi',
    '/^\s*section\s+\d+(\.\d+)*\b/mi'
  ];

  $frontSeen = 0;
  $splitAt   = null;

  for ($p=1; $p <= $end; $p++) {
    $t = text_for_page($db, $itemId, $p, 6000);
    if ($t === '') continue;

    $isFront = false;
    foreach ($frontSignals as $rx) { if (preg_match($rx, $t)) { $isFront = true; break; } }
    if ($isFront) $frontSeen++;

    $isMain = false;
    foreach ($mainSignals as $rx) { if (preg_match($rx, $t)) { $isMain = true; break; } }

    if ($isMain && $frontSeen >= 1) { $splitAt = $p; break; }
    if ($splitAt === null && preg_match('/^\s*\d+\s+[A-Z][^\n]{5,80}$/m', $t)) {
      $splitAt = $p; break;
    }
  }

  if ($splitAt === null) return [null, "Couldn't find a clear split in first $end pages."];

  $romanUntilPdf = max(0, $splitAt - 1);
  return [$romanUntilPdf, "Detected split at PDF page $splitAt (roman until $romanUntilPdf)."];
}

// --- Load items for picker ---
$items  = $db->query("SELECT id, title, author, year FROM items ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$itemId = isset($_GET['item']) ? (int)$_GET['item'] : 0;

// --- Seed page_map if empty for selected item (using display_offset) ---
if ($itemId) {
  $hasMap = (int)$db->query("SELECT COUNT(*) FROM page_map WHERE item_id=" . $itemId)->fetchColumn();
  if ($hasMap === 0) {
    $maxPdf = (int)$db->query("SELECT MAX(page_end) FROM chunks WHERE item_id=" . $itemId)->fetchColumn();
    if ($maxPdf > 0) {
      $dispOffset = (int)$db->query("SELECT display_offset FROM items WHERE id=" . $itemId)->fetchColumn();
      $db->beginTransaction();
      $ins = $db->prepare("INSERT INTO page_map
          (item_id, pdf_page, display_label, display_number, method, confidence)
          VALUES (?, ?, ?, ?, 'offset', 0.40)
          ON CONFLICT (item_id, pdf_page) DO NOTHING");
      for ($p=1; $p <= $maxPdf; $p++) {
        $num = $p + $dispOffset;
        $ins->execute([$itemId, $p, (string)$num, $num]);
      }
      $db->commit();
      recompute_chunk_display_ranges($db, $itemId);
    }
  }
}

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $itemId = (int)($_POST['item_id'] ?? 0);

  if ($action === 'update_one') {
    $p   = (int)$_POST['pdf_page'];
    $lab = trim($_POST['display_label'] ?? '');
    $num = trim($_POST['display_number'] ?? '');
    $num = ($num === '') ? null : (int)$num;

    $stmt = $db->prepare("INSERT INTO page_map (item_id, pdf_page, display_label, display_number, method, confidence)
                          VALUES (?, ?, ?, ?, 'manual', 1.0)
                          ON CONFLICT (item_id, pdf_page) DO UPDATE SET
                            display_label   = EXCLUDED.display_label,
                            display_number  = EXCLUDED.display_number,
                            method          = 'manual',
                            confidence      = 1.0");
    $stmt->execute([$itemId, $p, $lab, $num]);

    recompute_chunk_display_ranges($db, $itemId);
    $_SESSION['flash'] = "Updated page $p.";
    header("Location: ?item=" . $itemId . "&pg=" . max(1,(int)($_GET['pg']??1)));
    exit;
  }

  if ($action === 'bulk_rule') {
    $romanStartPdf     = max(1, (int)($_POST['roman_start_pdf']   ?? 1));
    $romanUntilPdf     = max(0, (int)($_POST['roman_until_pdf']   ?? 0));
    $romanStartAt      = max(1, (int)($_POST['roman_start_at']    ?? 1));
    $romanLower        = isset($_POST['roman_case']) && $_POST['roman_case'] === 'lower';
    $romanPrefix       = trim($_POST['roman_prefix'] ?? '');
    $arabicStartPdf    = (int)($_POST['arabic_start_pdf']    ?? 0);
    if ($arabicStartPdf <= 0) $arabicStartPdf = $romanUntilPdf + 1;
    $arabicStartNumber = max(1, (int)($_POST['arabic_start_number'] ?? 1));
    $arabicPrefix      = trim($_POST['arabic_prefix'] ?? '');

    $maxPdf = (int)$db->query("SELECT MAX(pdf_page) FROM page_map WHERE item_id=" . $itemId)->fetchColumn();
    if ($maxPdf <= 0) {
      $maxPdf = (int)$db->query("SELECT MAX(page_end) FROM chunks WHERE item_id=" . $itemId)->fetchColumn();
    }

    $db->beginTransaction();
    if ($romanUntilPdf > 0) {
      $stmt = $db->prepare("INSERT INTO page_map (item_id, pdf_page, display_label, display_number, method, confidence)
          VALUES (?, ?, ?, NULL, 'rule', 0.95)
          ON CONFLICT (item_id, pdf_page) DO UPDATE SET
            display_label  = EXCLUDED.display_label,
            display_number = NULL,
            method         = 'rule',
            confidence     = 0.95");
      for ($p=$romanStartPdf; $p <= min($romanUntilPdf, $maxPdf); $p++) {
        $n     = $romanStartAt + ($p - $romanStartPdf);
        $label = $romanPrefix . int_to_roman($n, $romanLower);
        $stmt->execute([$itemId, $p, $label]);
      }
    }
    $stmt = $db->prepare("INSERT INTO page_map (item_id, pdf_page, display_label, display_number, method, confidence)
        VALUES (?, ?, ?, ?, 'rule', 0.95)
        ON CONFLICT (item_id, pdf_page) DO UPDATE SET
          display_label  = EXCLUDED.display_label,
          display_number = EXCLUDED.display_number,
          method         = 'rule',
          confidence     = 0.95");
    $arabicN = $arabicStartNumber;
    for ($p=max(1,$arabicStartPdf); $p <= $maxPdf; $p++, $arabicN++) {
      $label = $arabicPrefix . $arabicN;
      $stmt->execute([$itemId, $p, $label, $arabicN]);
    }
    $db->commit();

    recompute_chunk_display_ranges($db, $itemId);
    $_SESSION['flash'] = "Applied roman→arabic rule and recomputed chunk citation ranges.";
    header("Location: ?item=" . $itemId);
    exit;
  }

  if ($action === 'autodetect') {
    [$romanUntil, $msg] = autodetect_split($db, $itemId);
    if ($romanUntil === null) {
      $_SESSION['flash'] = "Autodetect: $msg";
      header("Location: ?item=" . $itemId);
      exit;
    }
    $maxPdf = (int)$db->query("SELECT MAX(pdf_page) FROM page_map WHERE item_id=" . $itemId)->fetchColumn();
    if ($maxPdf <= 0) {
      $maxPdf = (int)$db->query("SELECT MAX(page_end) FROM chunks WHERE item_id=" . $itemId)->fetchColumn();
    }

    $db->beginTransaction();
    $stmtR = $db->prepare("INSERT INTO page_map (item_id, pdf_page, display_label, display_number, method, confidence)
        VALUES (?, ?, ?, NULL, 'autodetect', 0.90)
        ON CONFLICT (item_id, pdf_page) DO UPDATE SET
          display_label  = EXCLUDED.display_label,
          display_number = NULL,
          method         = 'autodetect',
          confidence     = 0.90");
    for ($p=1; $p <= min($romanUntil, $maxPdf); $p++) {
      $label = int_to_roman(1 + ($p-1), true);
      $stmtR->execute([$itemId, $p, $label]);
    }
    $stmtA  = $db->prepare("INSERT INTO page_map (item_id, pdf_page, display_label, display_number, method, confidence)
        VALUES (?, ?, ?, ?, 'autodetect', 0.90)
        ON CONFLICT (item_id, pdf_page) DO UPDATE SET
          display_label  = EXCLUDED.display_label,
          display_number = EXCLUDED.display_number,
          method         = 'autodetect',
          confidence     = 0.90");
    $arabicN = 1;
    for ($p=$romanUntil+1; $p <= $maxPdf; $p++, $arabicN++) {
      $stmtA->execute([$itemId, $p, (string)$arabicN, $arabicN]);
    }
    $db->commit();

    recompute_chunk_display_ranges($db, $itemId);
    $_SESSION['flash'] = "Autodetect applied: $msg";
    header("Location: ?item=" . $itemId);
    exit;
  }
}

// --- Pagination + fetch rows for UI ---
$pg        = max(1, (int)($_GET['pg'] ?? 1));
$per       = 50;
$offset    = ($pg-1)*$per;
$pages     = [];
$totalRows = 0;

if ($itemId) {
  $totalRows = (int)$db->query("SELECT COUNT(*) FROM page_map WHERE item_id=" . $itemId)->fetchColumn();
  if ($totalRows > 0) {
    $stmt = $db->prepare("SELECT pdf_page, display_label, display_number, method, confidence
                          FROM page_map WHERE item_id = ? ORDER BY pdf_page LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $itemId, PDO::PARAM_INT);
    $stmt->bindValue(2, $per,    PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

function snippet_for_page(PDO $db, int $itemId, int $pdfPage): string {
  $q = $db->prepare("SELECT text FROM chunks
                     WHERE item_id = ? AND ? BETWEEN page_start AND page_end
                     ORDER BY (page_end - page_start) ASC LIMIT 1");
  $q->execute([$itemId, $pdfPage]);
  $t = $q->fetchColumn();
  if (!$t) return '';
  $t = preg_replace('/\s+/', ' ', $t);
  return mb_substr($t, 0, 180, 'UTF-8') . (mb_strlen($t,'UTF-8')>180 ? '…' : '');
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Review Page Labels</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/css/research-theme.css">
</head>
<body class="p-4">
  <div class="container">
    <h1 class="mb-4">Review Page Labels</h1>

    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="alert alert-info"><?= h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>

    <form method="get" class="row g-2 mb-3">
      <div class="col-auto">
        <select name="item" class="form-select" onchange="this.form.submit()">
          <option value="0">Select a book…</option>
          <?php foreach ($items as $it): ?>
            <option value="<?= (int)$it['id'] ?>" <?= $itemId==(int)$it['id']?'selected':'' ?>>
              <?= h("#{$it['id']} {$it['title']} ".($it['author']?"— {$it['author']}":"").($it['year']?" ({$it['year']})":"")) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($itemId): ?>
      <div class="col-auto">
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="autodetect">
          <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
          <button class="btn btn-warning">Autodetect roman→arabic split</button>
        </form>
      </div>
      <?php endif; ?>
    </form>

    <?php if ($itemId): ?>
      <div class="card mb-4">
        <div class="card-header">Bulk roman → arabic rule</div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="action" value="bulk_rule">
            <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
            <div class="col-md-2">
              <label class="form-label">Roman starts at PDF page</label>
              <input class="form-control" type="number" name="roman_start_pdf" value="1">
            </div>
            <div class="col-md-2">
              <label class="form-label">Roman until PDF page</label>
              <input class="form-control" type="number" name="roman_until_pdf" placeholder="e.g. 12">
            </div>
            <div class="col-md-2">
              <label class="form-label">Roman starts at</label>
              <input class="form-control" type="number" name="roman_start_at" value="1">
            </div>
            <div class="col-md-2">
              <label class="form-label">Roman case</label>
              <select class="form-select" name="roman_case">
                <option value="lower" selected>lower (i, ii…)</option>
                <option value="upper">UPPER (I, II…)</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Roman prefix</label>
              <input class="form-control" type="text" name="roman_prefix" placeholder="">
            </div>
            <div class="col-md-2">
              <label class="form-label">Arabic starts at PDF page</label>
              <input class="form-control" type="number" name="arabic_start_pdf" placeholder="auto">
            </div>
            <div class="col-md-2">
              <label class="form-label">Arabic page number starts at</label>
              <input class="form-control" type="number" name="arabic_start_number" value="1">
            </div>
            <div class="col-md-2">
              <label class="form-label">Arabic prefix</label>
              <input class="form-control" type="text" name="arabic_prefix" placeholder="">
            </div>
            <div class="col-12">
              <button class="btn btn-primary">Apply rule &amp; recompute</button>
            </div>
          </form>
        </div>
      </div>

      <?php
        $pagesCount = ($totalRows > 0) ? (int)ceil($totalRows / $per) : 1;
      ?>

      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>PDF page</th>
              <th>Display label</th>
              <th>Display #</th>
              <th>Method</th>
              <th>Conf.</th>
              <th>Snippet</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pages as $row): ?>
              <tr>
                <td><?= (int)$row['pdf_page'] ?></td>
                <form method="post">
                  <input type="hidden" name="action" value="update_one">
                  <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
                  <input type="hidden" name="pdf_page" value="<?= (int)$row['pdf_page'] ?>">
                  <td class="ra-labcell">
                    <input class="form-control form-control-sm" name="display_label"
                           value="<?= h($row['display_label']) ?>" placeholder="e.g. xii or 201">
                  </td>
                  <td class="ra-numcell">
                    <input class="form-control form-control-sm" name="display_number"
                           value="<?= h($row['display_number']) ?>" placeholder="">
                  </td>
                  <td><?= h($row['method']) ?></td>
                  <td><?= h(number_format((float)$row['confidence'],2)) ?></td>
                  <td class="ra-snip"><?= h(snippet_for_page($db, $itemId, (int)$row['pdf_page'])) ?></td>
                  <td><button class="btn btn-sm btn-outline-secondary">Save</button></td>
                </form>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pagesCount > 1): ?>
      <nav>
        <ul class="pagination">
          <?php for ($i=1; $i<=$pagesCount; $i++): ?>
            <li class="page-item <?= $i==$pg?'active':'' ?>">
              <a class="page-link" href="?item=<?= (int)$itemId ?>&pg=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
      <?php endif; ?>

    <?php else: ?>
      <p>Select a book to begin.</p>
    <?php endif; ?>
  </div>
</body>
</html>
