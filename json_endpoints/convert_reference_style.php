<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$id    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$style = $_POST['style'] ?? 'harvard';
if ($id <= 0 || $style !== 'harvard') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDatabaseConnection();
$stmt = $pdo->prepare('SELECT text FROM notepad WHERE id = ?');
$stmt->execute([$id]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$note) {
    http_response_code(404);
    echo json_encode(['error' => 'Note not found']);
    exit;
}

$html = $note['text'];

$apiKey = getenv('OPENROUTER_API_KEY');
if (!$apiKey) {
    // Without an API key we cannot convert; return original text.
    echo json_encode(['status' => 'error', 'error' => 'Missing API key', 'text' => $html]);
    exit;
}

$payload = [
    'model' => 'anthropic/claude-sonnet-4',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Convert Oxford-style footnote citations in the provided HTML into Harvard referencing with in-text citations.'
        ],
        [
            'role' => 'user',
            'content' => $html
        ]
    ],
    'temperature' => 0.2,
    'max_tokens' => 2000
];

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$apiKey}",
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['status' => 'error', 'error' => $error, 'text' => $html]);
    exit;
}
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 400) {
    echo json_encode(['status' => 'error', 'error' => 'API request failed', 'text' => $html]);
    exit;
}

$data = json_decode($response, true);
$newText = $data['choices'][0]['message']['content'] ?? '';
if ($newText === '') {
    echo json_encode(['status' => 'error', 'error' => 'Invalid API response', 'text' => $html]);
    exit;
}

$stmt = $pdo->prepare('UPDATE notepad SET text = ?, last_edited = CURRENT_TIMESTAMP WHERE id = ?');
$stmt->execute([$newText, $id]);

echo json_encode(['status' => 'ok', 'text' => $newText]);
?>
