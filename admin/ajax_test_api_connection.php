<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$provider = $_POST['provider'] ?? '';
$api_key = $_POST['api_key'] ?? '';
$llm = $_POST['llm'] ?? '';
$reasoning_effort = $_POST['reasoning_effort'] ?? 'none';

if (!$provider || !$api_key || !$llm) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Add debugging
error_log("Testing API connection - Provider: $provider, Model: $llm");

// Test connection based on provider
try {
    switch (strtolower($provider)) {
        case 'openai':
            $result = testOpenAI($api_key, $llm, $reasoning_effort);
            break;
        case 'gemini':
            $result = testGemini($api_key, $llm);
            break;
        case 'groq':
            $result = testGroq($api_key, $llm);
            break;
        case 'openrouter':
            $result = testOpenRouter($api_key, $llm);
            break;
        default:
            $result = ['success' => false, 'error' => 'Unknown provider: ' . $provider];
    }
    
    error_log("API test result: " . json_encode($result));
    echo json_encode($result);
} catch (Exception $e) {
    error_log("API test exception: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage()]);
}

function testOpenAI($api_key, $model, $reasoning_effort = 'none') {
    $use_responses_api = strpos($model, 'gpt-5') === 0;
    $url = $use_responses_api
        ? 'https://api.openai.com/v1/responses'
        : 'https://api.openai.com/v1/chat/completions';

    if ($use_responses_api) {
        $data = [
            'model' => $model,
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Reply with exactly the word OK.'
                ]]
            ]],
            'max_output_tokens' => 32
        ];

        if (!empty($reasoning_effort) && $reasoning_effort !== 'none') {
            $data['reasoning'] = ['effort' => $reasoning_effort];
        }
    } else {
        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'Reply with exactly the word OK.']
            ],
            'max_tokens' => 16,
            'temperature' => 0.1
        ];
    }
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ];
    
    return makeCurlRequest($url, $data, $headers, 'openai');
}

function testGemini($api_key, $model) {
    // Fix Gemini model name if needed
    if ($model === 'gemini-pro') {
        $model = 'gemini-1.5-flash';
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$api_key";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => 'Test']
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 5
        ]
    ];
    
    $headers = [
        'Content-Type: application/json'
    ];
    
    return makeCurlRequest($url, $data, $headers, 'gemini');
}

function testGroq($api_key, $model) {
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Test']
        ],
        'max_tokens' => 5,
        'temperature' => 0.1
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ];
    
    return makeCurlRequest($url, $data, $headers, 'groq');
}

function testOpenRouter($api_key, $model) {
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    
    // Fix model name format for OpenRouter
    if (strpos($model, 'openrouter-') === 0) {
        // Convert old format to new format
        $model = str_replace('openrouter-', '', $model);
        switch ($model) {
            case 'llama-3':
                $model = 'meta-llama/llama-3.1-8b-instruct:free';
                break;
            case 'mistral':
                $model = 'mistralai/mistral-7b-instruct:free';
                break;
            case 'gpt-4':
                $model = 'openai/gpt-4o-mini';
                break;
            case 'mixtral':
                $model = 'mistralai/mixtral-8x7b-instruct:nitro';
                break;
            case 'gemini':
                $model = 'google/gemini-pro-1.5';
                break;
        }
    }
    
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Test']
        ],
        'max_tokens' => 5,
        'temperature' => 0.1
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'web-production-331e0.up.railway.app'),
        'X-Title: API Connection Test'
    ];
    
    return makeCurlRequest($url, $data, $headers, 'openrouter');
}

function makeCurlRequest($url, $data, $headers, $provider) {
    $log_file = __DIR__ . '/../logs/curl.log';
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
    $ch = curl_init();
    
    $log_handle = fopen($log_file, 'a');
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TOEIC-App/1.0');
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $log_handle);
    
    // Add debugging
    error_log("Making request to: $url");
    error_log("Request data: " . json_encode($data));
    error_log("Request headers: " . json_encode($headers));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    // Log the response body
    fwrite($log_handle, "\nRESPONSE BODY:\n");
    fwrite($log_handle, $response);
    
    rewind($log_handle);
    $verbose_log = stream_get_contents($log_handle);
    fclose($log_handle);
    
    curl_close($ch);
    
    // Add debugging
    error_log("Response code: $http_code");
    error_log("Response: " . substr($response, 0, 500));
    
    if ($error) {
        error_log("CURL Error: $error");
        return ['success' => false, 'error' => 'CURL Error: ' . $error];
    }
    
    if ($http_code === 401) {
        return ['success' => false, 'error' => 'Invalid API key'];
    }
    
    if ($http_code === 403) {
        return ['success' => false, 'error' => 'Access forbidden - check API key permissions'];
    }
    
    if ($http_code === 429) {
        return ['success' => false, 'error' => 'Rate limit exceeded'];
    }
    
    if ($http_code === 404) {
        return ['success' => false, 'error' => 'Model not found - check model name'];
    }
    
    if ($http_code !== 200) {
        $decoded = json_decode($response, true);
        $error_msg = 'HTTP ' . $http_code;
        
        if ($decoded && isset($decoded['error'])) {
            if (is_array($decoded['error'])) {
                $error_msg .= ': ' . ($decoded['error']['message'] ?? 'Unknown error');
            } else {
                $error_msg .= ': ' . $decoded['error'];
            }
        } else {
            $error_msg .= ' - ' . substr($response, 0, 200);
        }
        
        return ['success' => false, 'error' => $error_msg];
    }
    
    $decoded = json_decode($response, true);
    if (!$decoded) {
        return ['success' => false, 'error' => 'Invalid JSON response: ' . substr($response, 0, 100)];
    }
    
    // Check if we got a valid response based on provider
    $content = '';
    if ($provider === 'gemini') {
        if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            $content = $decoded['candidates'][0]['content']['parts'][0]['text'];
        } else {
            return ['success' => false, 'error' => 'Unexpected Gemini response format: ' . json_encode($decoded)];
        }
    } elseif ($provider === 'openai' && isset($decoded['output'])) {
        foreach ($decoded['output'] as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (($contentItem['type'] ?? '') === 'output_text' && isset($contentItem['text'])) {
                    $content .= $contentItem['text'];
                }
            }
        }
        if ($content === '' && isset($decoded['output_text'])) {
            $content = $decoded['output_text'];
        }
    } else {
        // OpenAI, Groq, OpenRouter format
        if (isset($decoded['choices'][0]['message']['content'])) {
            $content = $decoded['choices'][0]['message']['content'];
        } else {
            return ['success' => false, 'error' => 'Unexpected response format: ' . json_encode($decoded)];
        }
    }
    
    // Check if we got any content back
    if (empty($content)) {
        return ['success' => false, 'error' => 'Empty response from API'];
    }
    
    return [
        'success' => true, 
        'message' => 'Connection successful',
        'response_preview' => substr($content, 0, 50) . (strlen($content) > 50 ? '...' : '')
    ];
}
?>
