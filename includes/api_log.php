<?php
function log_api_request($url, $data, $headers, $provider) {
    $log_file = __DIR__ . '/../admin/groq_debug.log';
    $log_message = date('Y-m-d H:i:s') . " - Request to $provider\n";
    $log_message .= "URL: $url\n";
    $log_message .= "Data: " . json_encode($data) . "\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => fopen($log_file, 'a')
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $log_message .= "HTTP Code: $http_code\n";
    $log_message .= "cURL Error: $curl_error\n";
    $log_message .= "Response: $response\n----------------\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);

    if ($curl_error) {
        return ['success' => false, 'error' => 'cURL Error: ' . $curl_error . '. See groq_debug.log for details.'];
    }
    if ($http_code !== 200) {
        return ['success' => false, 'error' => "HTTP Error $http_code: $response. See groq_debug.log for details."];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid JSON response from API.'];
    }

    $content = '';
    if ($provider === 'gemini') {
        $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    } else { // OpenAI, Groq, OpenRouter
        $content = $decoded['choices'][0]['message']['content'] ?? '';
    }

    if (empty($content)) {
        return ['success' => false, 'error' => 'Empty content in API response.'];
    }

    return ['success' => true, 'data' => $content];
}
?>