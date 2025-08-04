<?php
require_once __DIR__ . '/metadata/metadata_sources.php';

function annas_archive_info(string $md5): array {
    $md5 = trim($md5);
    if ($md5 === '') {
        return [];
    }
    $apiKey = getenv('ANNA_API_KEY');
    if (!$apiKey) {
        return [];
    }

    $url = 'https://annas-archive-api.p.rapidapi.com/info?md5=' . urlencode($md5);

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
        curl_close($curl);
        return [];
    }
    curl_close($curl);

    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
}
?>
