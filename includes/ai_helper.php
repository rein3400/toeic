<?php
// AI Helper Functions for TOEIC content generation and paraphrasing

/**
 * Get active AI provider configuration from site_settings
 */
function getActiveAIProvider()
{
    global $conn;

    try {
        $active_key = '';
        $result = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key = 'active_ai_api'");
        if ($result && $result->num_rows > 0) {
            $active_key = $result->fetch_assoc()['setting_value'];
        }

        $candidate_keys = array_filter([$active_key, 'ai_api_openai']);
        foreach ($candidate_keys as $candidate_key) {
            $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param("s", $candidate_key);
            $stmt->execute();
            $config_result = $stmt->get_result();

            if ($config_result->num_rows === 0) {
                $stmt->close();
                continue;
            }

            $config = json_decode($config_result->fetch_assoc()['setting_value'], true);
            $stmt->close();

            if (!$config || !isset($config['provider']) || !isset($config['api_key'])) {
                continue;
            }

            if (empty($config['llm']) && strtolower($config['provider']) === 'openai') {
                $config['llm'] = 'gpt-5.4';
            }
            if (!isset($config['reasoning_effort']) && strtolower($config['provider']) === 'openai') {
                $config['reasoning_effort'] = 'high';
            }

            return $config;
        }

    } catch (Exception $e) {
        error_log("AI Provider Error: " . $e->getMessage());
    }

    return null;
}

/**
 * Call AI API with prompt
 * 
 * @param string $prompt
 * @param array $config
 * @param int $max_tokens
 * @param array $images
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms (60s)
 */
function callAI($prompt, $config, $max_tokens = 2000, $images = [], $timeout_ms = null)
{
    $provider = $config['provider'] ?? '';
    $api_key = $config['api_key'] ?? '';
    $model = $config['llm'] ?? '';

    switch (strtolower($provider)) {
        case 'groq':
            return callGroqAPI($prompt, $api_key, $model, $max_tokens, $timeout_ms);
        case 'openai':
            return callOpenAIAPI($prompt, $api_key, $model, $max_tokens, $images, $timeout_ms, $config['reasoning_effort'] ?? 'none');
        case 'gemini':
            return callGeminiAPI($prompt, $api_key, $model, $max_tokens, $timeout_ms);
        case 'openrouter':
            return callOpenRouterAPI($prompt, $api_key, $model, $max_tokens, $images, $timeout_ms);
        default:
            throw new Exception("Unsupported AI provider: " . $provider);
    }
}

/**
 * Call Groq API
 * 
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms
 */
function callGroqAPI($prompt, $api_key, $model, $max_tokens, $timeout_ms = null)
{
    $url = "https://api.groq.com/openai/v1/chat/completions";

    // Force override decommissioned model
    if ($model === 'llama3-70b-8192') {
        $model = 'llama-3.3-70b-versatile';
    }

    $data = [
        'model' => $model ?: 'llama-3.3-70b-versatile',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => $max_tokens,
        'temperature' => 0.7
    ];

    $response = makeHttpRequest($url, $api_key, $data, true, $timeout_ms);

    if (isset($response['choices'][0]['message']['content'])) {
        return $response['choices'][0]['message']['content'];
    }

    throw new Exception("Invalid Groq API response");
}

/**
 * Call OpenAI API
 * 
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms
 */
function callOpenAIAPI($prompt, $api_key, $model, $max_tokens, $images = [], $timeout_ms = null, $reasoning_effort = 'none')
{
    $selected_model = $model ?: 'gpt-5.4';
    $has_reasoning = !empty($reasoning_effort) && $reasoning_effort !== 'none';
    $use_responses_api = strpos($selected_model, 'gpt-5') === 0 || !empty($images) || $has_reasoning;

    if ($use_responses_api) {
        $url = "https://api.openai.com/v1/responses";
        $content = [[
            'type' => 'input_text',
            'text' => $prompt
        ]];

        foreach ($images as $img_base64) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => strpos($img_base64, 'data:') === 0 ? $img_base64 : "data:image/jpeg;base64,{$img_base64}"
            ];
        }

        $data = [
            'model' => $selected_model,
            'input' => [[
                'role' => 'user',
                'content' => $content
            ]],
            'max_output_tokens' => $max_tokens
        ];

        if ($has_reasoning) {
            $data['reasoning'] = ['effort' => $reasoning_effort];
        }

        $response = makeHttpRequest($url, $api_key, $data, true, $timeout_ms);
        $responseText = extractOpenAIResponsesText($response);
        if ($responseText !== '') {
            return $responseText;
        }
    } else {
        $url = "https://api.openai.com/v1/chat/completions";

        $data = [
            'model' => $selected_model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $max_tokens,
            'temperature' => 0.7
        ];

        $response = makeHttpRequest($url, $api_key, $data, true, $timeout_ms);

        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }
    }

    throw new Exception("Invalid OpenAI API response");
}

function extractOpenAIResponsesText(array $response): string
{
    if (!empty($response['output_text'])) {
        return (string)$response['output_text'];
    }

    $chunks = [];
    foreach (($response['output'] ?? []) as $outputItem) {
        foreach (($outputItem['content'] ?? []) as $contentItem) {
            if (($contentItem['type'] ?? '') === 'output_text' && isset($contentItem['text'])) {
                $chunks[] = $contentItem['text'];
            }
        }
    }

    return implode("", $chunks);
}

/**
 * Call Gemini API
 * 
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms
 */
function callGeminiAPI($prompt, $api_key, $model, $max_tokens, $timeout_ms = null)
{
    $model_name = $model ?: 'gemini-pro';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model_name}:generateContent?key={$api_key}";

    $data = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => $max_tokens,
            'temperature' => 0.7
        ]
    ];

    $response = makeHttpRequest($url, '', $data, false, $timeout_ms); // Gemini uses key in URL

    if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
        return $response['candidates'][0]['content']['parts'][0]['text'];
    }

    throw new Exception("Invalid Gemini API response");
}

/**
 * Call OpenRouter API
 * 
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms
 */
function callOpenRouterAPI($prompt, $api_key, $model, $max_tokens, $images = [], $timeout_ms = null)
{
    $url = "https://openrouter.ai/api/v1/chat/completions";

    // OpenRouter requires a model, default to a reliable free model
    $default_model = 'meta-llama/llama-3.1-8b-instruct:free';
    
    // Construct message content
    $content = [['type' => 'text', 'text' => $prompt]];
    
    if (!empty($images)) {
        foreach ($images as $img_base64) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => strpos($img_base64, 'data:') === 0 ? $img_base64 : "data:image/jpeg;base64,{$img_base64}"
                ]
            ];
        }
    }

    $data = [
        'model' => $model ?: $default_model,
        'messages' => [
            ['role' => 'user', 'content' => $content]
        ],
        'max_tokens' => $max_tokens,
        'temperature' => 0.7
    ];

    // OpenRouter uses custom headers
    $response = makeHttpRequestWithHeaders($url, $api_key, $data, [
        'HTTP-Referer' => 'https://toeic.osgli.example',
        'X-Title' => 'TOEIC Content Generator'
    ], $timeout_ms);

    if (isset($response['choices'][0]['message']['content'])) {
        return $response['choices'][0]['message']['content'];
    }

    throw new Exception("Invalid OpenRouter API response");
}

/**
 * Make HTTP request to API with custom headers
 * 
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms (60s)
 */
function makeHttpRequestWithHeaders($url, $api_key, $data, $custom_headers = [], $timeout_ms = null)
{
    $ch = curl_init($url);

    $headers = ['Content-Type: application/json'];
    if ($api_key) {
        $headers[] = "Authorization: Bearer {$api_key}";
    }

    // Add custom headers
    foreach ($custom_headers as $key => $value) {
        $headers[] = "{$key}: {$value}";
    }

    // Determine actual timeout: use provided value if valid (>0), else 60s
    $timeout_seconds = 60;
    if (is_numeric($timeout_ms) && $timeout_ms > 0) {
        $timeout_seconds = $timeout_ms / 1000;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT_MS => is_numeric($timeout_ms) && $timeout_ms > 0 ? (int)$timeout_ms : 60000,
        CURLOPT_CONNECTTIMEOUT_MS => is_numeric($timeout_ms) && $timeout_ms > 0 ? (int)$timeout_ms : 60000,
        CURLOPT_NOSIGNAL => 1
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL Error: " . $error);
    }

    if ($http_code !== 200) {
        error_log("API Error Response: " . $response);
        throw new Exception("HTTP Error: " . $http_code . " - " . $response);
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        throw new Exception("Failed to decode API response");
    }

    return $decoded;
}

/**
 * Make HTTP request to API (legacy function for backward compatibility)
 * 
 * @param int|null $timeout_ms Timeout in milliseconds; if null/<=0, defaults to 60000ms (60s)
 */
function makeHttpRequest($url, $api_key, $data, $use_auth_header = true, $timeout_ms = null)
{
    $ch = curl_init($url);

    $headers = ['Content-Type: application/json'];
    if ($use_auth_header && $api_key) {
        $headers[] = "Authorization: Bearer {$api_key}";
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT_MS => is_numeric($timeout_ms) && $timeout_ms > 0 ? (int)$timeout_ms : 60000,
        CURLOPT_CONNECTTIMEOUT_MS => is_numeric($timeout_ms) && $timeout_ms > 0 ? (int)$timeout_ms : 60000,
        CURLOPT_NOSIGNAL => 1
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL Error: " . $error);
    }

    if ($http_code !== 200) {
        error_log("API Error Response: " . $response);
        throw new Exception("API returned status code: " . $http_code);
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        throw new Exception("Failed to decode API response");
    }

    return $decoded;
}

/**
 * Paraphrase a single TOEIC-style question
 */
function paraphraseQuestion($question_data, $variations = 2, $difficulty = 'same')
{
    $config = getActiveAIProvider();
    if (!$config) {
        throw new Exception("AI provider not configured");
    }

    $difficulty_instruction = '';
    switch ($difficulty) {
        case 'easier':
            $difficulty_instruction = 'Make the paraphrased versions slightly easier to understand.';
            break;
        case 'harder':
            $difficulty_instruction = 'Make the paraphrased versions slightly more challenging.';
            break;
        default:
            $difficulty_instruction = 'Maintain the same difficulty level.';
    }

    $prompt = buildParaphrasePrompt($question_data, $variations, $difficulty_instruction);

    try {
        $response = callAI($prompt, $config);
        return parseParaphraseResponse($response, $question_data);
    } catch (Exception $e) {
        error_log("Paraphrase Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Build paraphrase prompt for AI
 */
function buildParaphrasePrompt($q, $variations, $difficulty_instruction)
{
    $prompt = <<<PROMPT
You are a TOEIC question generator expert. Your task is to paraphrase the following TOEIC-style question while maintaining its educational value and correctness.

Original Question:
{$q['question']}

Options:
A) {$q['option_a']}
B) {$q['option_b']}
C) {$q['option_c']}
D) {$q['option_d']}

Correct Answer: {$q['correct_answer']}

Requirements:
1. Generate exactly {$variations} paraphrased version(s)
2. {$difficulty_instruction}
3. Keep the same correct answer logic
4. Use similar academic vocabulary
5. Maintain professional TOEIC style
6. Ensure all options remain plausible

Return ONLY a JSON array in this exact format:
[
  {
    "question": "paraphrased question text",
    "option_a": "paraphrased option A",
    "option_b": "paraphrased option B",
    "option_c": "paraphrased option C",
    "option_d": "paraphrased option D",
    "correct_answer": "{$q['correct_answer']}"
  }
]

Return ONLY the JSON array, no explanations.
PROMPT;

    return $prompt;
}

/**
 * Parse AI response and extract paraphrased questions
 */
function parseParaphraseResponse($response, $original)
{
    // Try to extract JSON from response
    $json_start = strpos($response, '[');
    $json_end = strrpos($response, ']');

    if ($json_start === false || $json_end === false) {
        throw new Exception("No JSON array found in AI response");
    }

    $json_str = substr($response, $json_start, $json_end - $json_start + 1);
    $parsed = json_decode($json_str, true);

    if (!$parsed || !is_array($parsed)) {
        throw new Exception("Failed to parse AI response as JSON");
    }

    // Validate each paraphrased question
    $validated = [];
    foreach ($parsed as $q) {
        if (
            isset($q['question']) && isset($q['option_a']) && isset($q['option_b'])
            && isset($q['option_c']) && isset($q['option_d']) && isset($q['correct_answer'])
        ) {
            $validated[] = $q;
        }
    }

    if (empty($validated)) {
        throw new Exception("No valid paraphrased questions in AI response");
    }

    return $validated;
}

/**
 * Paraphrase multiple questions in batch
 */
function paraphraseQuestionsBatch($questions, $variations = 2, $difficulty = 'same')
{
    $all_paraphrased = [];

    foreach ($questions as $index => $question) {
        try {
            // Skip if question has an error
            if (isset($question['error'])) {
                $all_paraphrased[] = $question;
                continue;
            }

            $paraphrased = paraphraseQuestion($question, $variations, $difficulty);

            // Add original + paraphrased versions
            $all_paraphrased[] = $question; // Keep original
            foreach ($paraphrased as $p) {
                $all_paraphrased[] = $p;
            }

            // Small delay to avoid rate limits
            if ($index < count($questions) - 1) {
                usleep(500000); // 0.5 second delay
            }

        } catch (Exception $e) {
            error_log("Failed to paraphrase question {$index}: " . $e->getMessage());
            // Keep original on error
            $all_paraphrased[] = $question;
        }
    }

    return $all_paraphrased;
}
?>
