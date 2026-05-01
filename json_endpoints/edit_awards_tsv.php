<?php
/**
 * GET  ?q=term        — search awards-master.tsv, return up to 50 matching rows as JSON
 * POST {index,award,year,author,title,result} — update that row in the TSv
 * DELETE ?index=N     — remove that row from the TSV
 */
require_once __DIR__ . '/../db.php';
requireLogin();

header('Content-Type: application/json');

$tsvFile = __DIR__ . '/../awards-master.tsv';

if (!file_exists($tsvFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'awards-master.tsv not found — run Step 1 first']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Shared: load TSV lines (header kept separately) ───────────────────────────
function loadTsv(string $file): array
{
    $raw    = file($file, FILE_IGNORE_NEW_LINES);
    $header = array_shift($raw);
    return [$header, $raw];
}

function writeTsv(string $file, string $header, array $lines): void
{
    file_put_contents($file, $header . "\n" . implode("\n", array_values($lines)) . "\n");
}

function cleanField(string $s): string
{
    return str_replace(["\t", "\n", "\r"], ' ', trim($s));
}

// ── GET: search ───────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $q = strtolower(trim($_GET['q'] ?? ''));

    [$header, $lines] = loadTsv($tsvFile);

    $results  = [];
    $overflow = false;

    foreach ($lines as $i => $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 5) continue;
        [$award, $year, $author, $title, $result] = $parts;

        if ($q !== '' &&
            !str_contains(strtolower($award),  $q) &&
            !str_contains(strtolower($title),  $q) &&
            !str_contains(strtolower($author), $q)) {
            continue;
        }

        if (count($results) >= 50) { $overflow = true; break; }

        $results[] = [
            'index'  => $i,
            'award'  => trim($award),
            'year'   => (int)$year,
            'author' => trim($author),
            'title'  => trim($title),
            'result' => trim($result),
        ];
    }

    echo json_encode(['rows' => $results, 'overflow' => $overflow]);
    exit;
}

// ── POST: update a row ────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!isset($data['index'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing index']);
        exit;
    }

    [$header, $lines] = loadTsv($tsvFile);
    $idx = (int)$data['index'];

    if ($idx < 0 || $idx >= count($lines)) {
        http_response_code(400);
        echo json_encode(['error' => 'Index out of range']);
        exit;
    }

    $award  = cleanField($data['award']  ?? '');
    $year   = (int)($data['year']        ?? 0);
    $author = cleanField($data['author'] ?? '');
    $title  = cleanField($data['title']  ?? '');
    $result = cleanField($data['result'] ?? '');

    if ($award === '' || $title === '' || $year === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'award, year and title are required']);
        exit;
    }

    $lines[$idx] = implode("\t", [$award, $year, $author, $title, $result]);
    writeTsv($tsvFile, $header, $lines);

    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE: remove a row ──────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $idx = isset($_GET['index']) ? (int)$_GET['index'] : -1;

    [$header, $lines] = loadTsv($tsvFile);

    if ($idx < 0 || $idx >= count($lines)) {
        http_response_code(400);
        echo json_encode(['error' => 'Index out of range']);
        exit;
    }

    unset($lines[$idx]);
    writeTsv($tsvFile, $header, $lines);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
