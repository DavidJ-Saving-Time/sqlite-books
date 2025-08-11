<?php
/* IAfront.php — Internet Archive Lending Dev UI (clean reset)
 *
 * Requires IAClient.php in the same directory and Apache env:
 *   SetEnv IA_EMAIL you@example.com
 *   SetEnv IA_PASSWORD "superSecretPassword"
 */

require __DIR__ . '/IAClient.php';

/* ---------- tiny FS cache + JSON fetch (only caches IA GET endpoints) ---------- */
function ia_cache_dir(): string {
    $d = sys_get_temp_dir() . '/ia_cache';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}
function ia_cache_path(string $url): string {
    return ia_cache_dir() . '/' . sha1($url) . '.json';
}
/** Cached GET for IA endpoints; other URLs are fetched live. */
function http_get_json(string $url, int $timeout = 20): array {
    // TTL by endpoint
    $ttl = 0;
    if (preg_match('~^https://archive\.org/services/search/v1/scrape~', $url)) $ttl = 600;       // 10 min
    elseif (preg_match('~^https://archive\.org/advancedsearch\.php~', $url))   $ttl = 600;       // 10 min
    elseif (preg_match('~^https://archive\.org/metadata/~', $url))            $ttl = 900;       // 15 min

    if ($ttl > 0) {
        $path = ia_cache_path($url);
        if (is_file($path) && (time() - filemtime($path) < $ttl)) {
            $blob = @file_get_contents($path);
            $arr  = @json_decode($blob, true);
            if (is_array($arr) && isset($arr['status'])) return $arr;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'IA-Lending-UI/0.6',
        CURLOPT_HEADER         => true,
        CURLOPT_ENCODING       => '',                 // accept gzip/deflate/br
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP error: $err");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($resp, $headerSize);
    curl_close($ch);

    $json = json_decode($body, true);
    $res  = ['status' => $status, 'body' => $body, 'json' => $json];

    if ($ttl > 0 && $status >= 200 && $status < 500) {
        @file_put_contents(ia_cache_path($url), json_encode($res));
    }
    return $res;
}

/* ---------------- IA helpers (no format filtering; OA shows full list) ---------------- */

// Exact identifier → minimal metadata row
function ia_fetch_by_ident(string $ident): ?array {
    $ident = trim($ident);
    if ($ident === '') return null;
    $url = 'https://archive.org/metadata/' . rawurlencode($ident);
    $res = http_get_json($url);
    if ($res['status'] !== 200 || !is_array($res['json'])) return null;
    $m = $res['json']['metadata'] ?? [];
    $creator = $m['creator'] ?? '';
    if (is_array($creator)) $creator = implode('; ', $creator);
    return [
        'identifier' => $m['identifier'] ?? $ident,
        'title'      => $m['title'] ?? '',
        'creator'    => $creator,
        'mediatype'  => $m['mediatype'] ?? '',
        'year'       => $m['year'] ?? '',
    ];
}

// List ALL files for an identifier (Open Access direct links; no filtering)
function ia_list_all_files(string $ident): array {
    $url = 'https://archive.org/metadata/' . rawurlencode($ident);
    $res = http_get_json($url);
    if ($res['status'] !== 200 || empty($res['json']['files'])) return [];
    $out = [];
    foreach ($res['json']['files'] as $f) {
        $name = $f['name'] ?? '';
        if (!$name) continue;
        $out[] = [
            'name'   => $name,
            'format' => $f['format'] ?? '',
            'size'   => isset($f['size']) ? (int)$f['size'] : null,
            'url'    => 'https://archive.org/download/' . rawurlencode($ident) . '/' . rawurlencode($name),
        ];
    }
    return $out;
}

// Availability (safe): 200 => lending endpoints exist; 404 => likely OA/non-loan
function ia_safe_availability(IAClient $ia, string $ident): array {
    try {
        $json = $ia->availability($ident);
        return ['status' => 200, 'json' => $json];
    } catch (\Throwable $e) {
        if (strpos($e->getMessage(), 'HTTP 404') !== false) return ['status' => 404];
        return ['status' => 500, 'error' => $e->getMessage()];
    }
}

/* Search v1 (with collection) + Advanced fallback; returns ['items'=>[], 'total'=>int] */
function ia_search_books(string $query, int $rows = 50, int $page = 1): array {
    $q = trim($query);
    if ($q === '') return ['items'=>[], 'total'=>0];

    // Identifier fast paths
    if (strpos($q, ',') !== false && strpos($q, ' ') === false) {
        $items = [];
        foreach (explode(',', $q) as $id) {
            $one = ia_fetch_by_ident(trim($id));
            if ($one) $items[] = $one;
        }
        if ($items) return ['items'=>$items, 'total'=>count($items)];
    }
    if (strpos($q, ' ') === false) {
        $one = ia_fetch_by_ident($q);
        if ($one) return ['items'=>[$one], 'total'=>1];
    }

    // Search v1 (three variants; we filter to texts after)
    $variants = [
        'title:("' . $q . '")',
        'title:(' . preg_replace('/\s+/u', ' ', $q) . ')',
        $q,
    ];
    foreach ($variants as $qstr) {
        $url = 'https://archive.org/services/search/v1/scrape?' . http_build_query([
            'fields' => 'identifier,title,creator,mediatype,year,collection',
            'count'  => $rows,
            'page'   => max(1, $page),
            'q'      => $qstr,
        ]);
        $res = http_get_json($url);
        if ($res['status'] !== 200 || empty($res['json']['items'])) continue;

        $all   = $res['json']['items'];
        $total = intval($res['json']['total'] ?? count($all));
        $items = array_values(array_filter($all, fn($it)=>($it['mediatype'] ?? '') === 'texts'));
        if (!empty($items)) {
            $norm = array_map(function($it){
                $creator = $it['creator'] ?? '';
                if (is_array($creator)) $creator = implode('; ', $creator);
                $coll = $it['collection'] ?? [];
                if (!is_array($coll) && $coll !== null) $coll = [$coll];
                return [
                    'identifier'  => $it['identifier'] ?? '',
                    'title'       => $it['title'] ?? '',
                    'creator'     => $creator,
                    'mediatype'   => $it['mediatype'] ?? '',
                    'year'        => $it['year'] ?? '',
                    'collections' => $coll,
                ];
            }, $items);
            return ['items'=>$norm, 'total'=>$total];
        }
    }

    // Advanced fallback (also request collection; filter after)
    $phrase = '"' . $q . '"';
    $url = 'https://archive.org/advancedsearch.php?' . http_build_query([
        'q'      => "title:($phrase)",
        'fl'     => ['identifier','title','creator','mediatype','year','collection'],
        'rows'   => $rows,
        'page'   => $page,
        'output' => 'json',
    ]);
    $res = http_get_json($url);
    if ($res['status'] === 200 && isset($res['json']['response']['docs'])) {
        $docs = array_values(array_filter($res['json']['response']['docs'], fn($d)=>($d['mediatype'] ?? '') === 'texts'));
        if ($docs) {
            $norm = array_map(function ($d) {
                $creator = $d['creator'] ?? '';
                if (is_array($creator)) $creator = implode('; ', $creator);
                $coll = $d['collection'] ?? [];
                if (!is_array($coll) && $coll !== null) $coll = [$coll];
                return [
                    'identifier'  => $d['identifier'] ?? '',
                    'title'       => $d['title'] ?? '',
                    'creator'     => $creator,
                    'mediatype'   => $d['mediatype'] ?? '',
                    'year'        => $d['year'] ?? '',
                    'collections' => $coll,
                ];
            }, $docs);
            $total = intval($res['json']['response']['numFound'] ?? count($norm));
            return ['items'=>$norm, 'total'=>$total];
        }
    }

    return ['items'=>[], 'total'=>0];
}

/* ---------------- Controller ---------------- */

$email = getenv('IA_EMAIL') ?: '';
$pass  = getenv('IA_PASSWORD') ?: '';
if (!$email || !$pass) {
    http_response_code(500);
    echo "Missing IA_EMAIL / IA_PASSWORD env vars.";
    exit;
}

$ia = new IAClient($email, $pass);

$p = max(1, intval($_GET['p'] ?? 1));
$result = null;
$error  = null;
$items  = [];
$total  = 0;

try {
    // Actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['identifier'])) {
        $ident = trim($_POST['identifier']);
        switch ($_POST['action']) {
            case 'availability':
                $result = ia_safe_availability($ia, $ident);
                if ($result['status'] === 404) $result = ['note' => 'Open Access (no loans endpoint)'];
                break;

            case 'borrow':
                $result = $ia->borrow($ident);
                break;

case 'media':
    $format = isset($_POST['format']) ? trim($_POST['format']) : 'pdf';

    // If the user asked for the LICENSE, always use the loans API.
    if ($format === 'lcpl') {
        try { $ia->borrow($ident); } catch (\Throwable $e) {}   // best-effort
        $r = $ia->mediaUrl($ident, 'lcpl');                     // fetch license URL
        $result = $r ?: ['status'=>500, 'headers'=>'No lcpl media_url'];
        break;
    }

    // Otherwise (pdf/epub/lcp_pdf): ALWAYS try OA files first (list all files).
    $files = ia_list_all_files($ident);
    if (!empty($files)) {
        $result = ['status'=>200, 'files'=>$files, 'note'=>'Open Access — direct downloads below'];
        break;
    }

    // No OA files? Treat as lending and use the selected format.
    try { $ia->borrow($ident); } catch (\Throwable $e) {}
    $r = $ia->mediaUrl($ident, $format);
    $result = $r ?: ['status'=>500, 'headers'=>'No media_url'];
    break;


                // Otherwise try lending flow (you control the format; no auto-fallbacks)
                try { $ia->borrow($ident); } catch (\Throwable $e) {}
                $r = $ia->mediaUrl($ident, $format);
                $result = $r ?: ['status'=>500, 'headers'=>'No media_url'];
                break;

            case 'return':
                $result = $ia->returnLoan($ident);
                break;
        }
    }

    // Search
    if (isset($_GET['q']) && $_GET['q'] !== '') {
        $search = ia_search_books($_GET['q'], 50, $p);
        $items  = $search['items'];
        $total  = $search['total'];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* ---------------- View ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>IA Lending – Dev UI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body { font: 14px/1.4 system-ui, sans-serif; margin: 2rem; }
    form.inline { display: inline; margin-right: .25rem; }
    table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
    th, td { border: 1px solid #ddd; padding: .5rem; vertical-align: top; }
    th { background: #f7f7f7; text-align: left; }
    .row-actions { white-space: nowrap; }
    .msg { margin-top: 1rem; padding: .75rem; border-radius: .5rem; }
    .err { background: #fee; border: 1px solid #fbb; }
    .ok  { background: #f6fff6; border: 1px solid #bde5bd; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; white-space: pre-wrap; }
    .controls { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
    input[type="text"]{ padding:.5rem; width: 520px; }
    button { padding: .4rem .7rem; cursor: pointer; }
    select { padding:.3rem; }
    .cover { width:64px; }
    .cover img { width:48px; height:48px; object-fit:cover; border-radius:6px; }
    .pager { margin: 10px 0; display:flex; gap:12px; align-items:center; }
    .badge { border:1px solid #ccc; padding:1px 6px; border-radius:10px; margin-left:6px; font-size:12px; color:#555; }
  </style>
</head>
<body>
  <h1>Internet Archive – Lending Dev UI</h1>

  <form method="get" class="controls">
    <label for="q"><strong>Search books or paste identifiers</strong>:</label>
    <input id="q" name="q" type="text" value="<?= isset($_GET['q'])?h($_GET['q']):'' ?>"
           placeholder='Try: "wuthering heights"  or  mobydick00melv  or  id1,id2,id3' />
    <input type="hidden" name="p" value="1" />
    <button type="submit">Go</button>
  </form>

  <?php if (isset($_GET['q']) && $_GET['q'] !== ''): ?>
    <?php $p = max(1, intval($_GET['p'] ?? 1)); $prev = max(1, $p-1); $next = $p+1; ?>
    <div class="pager">
      <a href="?q=<?= urlencode($_GET['q']) ?>&p=<?= $prev ?>">&laquo; Prev</a>
      <span>Page <?= $p ?><?= $total ? " of ~".ceil($total/50) : "" ?></span>
      <a href="?q=<?= urlencode($_GET['q']) ?>&p=<?= $next ?>">Next &raquo;</a>
      <?php if ($total): ?><span style="color:#666">Total: <?= (int)$total ?></span><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="msg err"><strong>Error:</strong> <?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="msg ok">
      <strong>Result:</strong>
      <div class="mono"><?= h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>

      <?php if (is_array($result) && !empty($result['files'])): ?>
        <div style="margin-top:.5rem">
          <strong>Files (open access):</strong><br>
          <?php foreach ($result['files'] as $f): ?>
            <a href="<?= h($f['url']) ?>" target="_blank" rel="noopener">
              <?= h($f['name']) ?><?= $f['format'] ? ' — '.h($f['format']) : '' ?><?= isset($f['size']) && $f['size'] ? ' — '.number_format($f['size']).' bytes' : '' ?>
            </a><br>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (is_array($result) && isset($result['location']) && $result['location']): ?>
        <p><a href="<?= h($result['location']) ?>" target="_blank" rel="noopener">Open media link</a></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($items && count($items)): ?>
    <table>
      <thead>
        <tr>
          <th class="cover">Cover</th>
          <th>Identifier</th>
          <th>Title</th>
          <th>Creator</th>
          <th>Year</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $doc): ?>
        <?php
          $id    = $doc['identifier']  ?? '';
          $tit   = $doc['title']       ?? '';
          $auth  = $doc['creator']     ?? '';
          $yr    = $doc['year']        ?? '';
          $cols  = $doc['collections'] ?? [];
          $badges= [];
          if ($cols) {
            if (in_array('inlibrary', $cols, true) || in_array('printdisabled', $cols, true)) $badges[] = 'Lending';
            if (preg_grep('/^oapen/i', $cols) || in_array('opensource', $cols, true)) $badges[] = 'Open Access';
          }
          $cols_json = $cols ? json_encode(array_values($cols)) : '[]';
        ?>
        <tr>
          <td class="cover">
            <img src="<?= 'https://archive.org/services/img/'.h($id) ?>" alt="">
          </td>
          <td class="mono"><?= h($id) ?></td>
          <td>
            <a href="<?= 'https://archive.org/details/'.h($id) ?>" target="_blank" rel="noopener">
              <?= h($tit) ?>
            </a>
            <?php foreach ($badges as $b): ?>
              <span class="badge"><?= h($b) ?></span>
            <?php endforeach; ?>
          </td>
          <td><?= h($auth) ?></td>
          <td><?= h($yr) ?></td>

          <td class="row-actions">
            <form method="post" class="inline">
              <input type="hidden" name="identifier" value="<?= h($id) ?>">
              <input type="hidden" name="action" value="availability">
              <button>Availability</button>
            </form>

            <form method="post" class="inline">
              <input type="hidden" name="identifier" value="<?= h($id) ?>">
              <input type="hidden" name="action" value="borrow">
              <button>Borrow</button>
            </form>

            <form method="post" class="inline">
              <input type="hidden" name="identifier" value="<?= h($id) ?>">
              <input type="hidden" name="collections" value='<?= h($cols_json) ?>'>
              <input type="hidden" name="action" value="media">
<select name="format" title="format">
  <option value="pdf">pdf</option>
  <option value="epub">epub</option>
  <option value="lcp_pdf">lcp_pdf</option>
  <option value="lcpl">lcpl (license)</option> <!-- add this -->
</select>
              <button>Get link</button>
            </form>

            <form method="post" class="inline">
              <input type="hidden" name="identifier" value="<?= h($id) ?>">
              <input type="hidden" name="action" value="return">
              <button>Return</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php elseif (isset($_GET['q'])): ?>
    <p>No results.</p>
  <?php endif; ?>

  <p style="margin-top:2rem;color:#666">
    Titles are clickable; thumbs via <span class="mono">/services/img/{identifier}</span>.
  </p>
</body>
</html>
