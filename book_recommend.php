<?php
/**
 * Generate book recommendations using OpenRouter.
 *
 * Set the environment variable OPENROUTER_API_KEY with your API key.
 */
function get_book_recommendations(string $preferences): string {
    $apiKey = getenv('OPENROUTER_API_KEY') ?: 'your_api_key_here';

    $payload = [
        'model' => 'anthropic/claude-sonnet-4',
        'messages' => [
            [
                'role' => 'system',
                'content' => "You are a knowledgeable book recommendation assistant. Suggest books tailored to the user's taste with a short explanation for each recommendation."
            ],
            [
                'role' => 'user',
                'content' => "I enjoy {$preferences}. Can you recommend 5 similar books with a one-sentence reason for each?"
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 500
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

    return trim($data['choices'][0]['message']['content']);
}
