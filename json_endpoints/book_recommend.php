<?php
/**
 * Helper function to generate book recommendations using OpenRouter.
 *
 * Not a standalone HTTP endpoint. Set the OPENROUTER_API_KEY environment variable.
 */
function get_book_recommendations(string $preferences): string {
    $apiKey = getenv('OPENROUTER_API_KEY') ?: 'your_api_key_here';

    $payload = [
        'model' => 'openai/gpt-5.4-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => "You are a knowledgeable book recommendation assistant. You MUST respond with a valid JSON object only — no markdown, no code fences, no explanation. Use this exact structure: {\"recommendations\":[{\"title\":\"Book Name\",\"author\":\"Author Name\",\"reason\":\"Why this book fits.\"}]}"
            ],
            [
                'role' => 'user',
                'content' => "I enjoy {$preferences}. Recommend up to 7 similar books. Return up to 7 recommendations. If you are unsure about a title or author, omit it.Ensure each recommendation is a real book with a correct author. Return only the JSON object."
            ]
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.7,
        'max_tokens' => 1000
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
        throw new Exception('Curl error: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        throw new Exception('API request failed with status ' . $status . ': ' . $response);
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        throw new Exception('Invalid API response');
    }

    $content = trim($data['choices'][0]['message']['content']);

    // Validate the returned JSON has the expected structure
    $decoded = json_decode($content, true);
    if (!is_array($decoded) || !isset($decoded['recommendations']) || !is_array($decoded['recommendations'])) {
        throw new Exception('AI returned invalid recommendation structure — try again');
    }
    foreach ($decoded['recommendations'] as $item) {
        if (!isset($item['title']) || !isset($item['author'])) {
            throw new Exception('AI returned malformed recommendation items — try again');
        }
    }

    return $content;
}
