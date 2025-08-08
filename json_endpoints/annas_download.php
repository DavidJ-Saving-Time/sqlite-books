<?php
/**
 * Proxies a download request to Anna's Archive using an MD5 identifier.
 *
 * Expects an HTTP GET request.
 *
 * Query Parameters:
 * - md5: MD5 hash of the desired file.
 *
 * Returns:
 * The raw JSON response from Anna's Archive,
 * or {"error":"message"} if the request fails.
 */
header('Content-Type: application/json');

$md5 = trim($_GET['md5'] ?? '');
if ($md5 === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing md5 parameter']);
    exit;
}

$apiKey = getenv('ANNA_API_KEY');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured']);
    exit;
}

$url = 'https://annas-archive-api.p.rapidapi.com/download?md5=' . urlencode($md5);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => [
        'x-rapidapi-host: annas-archive-api.p.rapidapi.com',
        'x-rapidapi-key: ' . $apiKey
    ],
]);

$response = curl_exec($curl);
if ($response === false) {
    $err = curl_error($curl);
    curl_close($curl);
    http_response_code(500);
    echo json_encode(['error' => $err]);
    exit;
}

$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

http_response_code($httpCode);
echo $response;
