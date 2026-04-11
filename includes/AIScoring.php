<?php
/**
 * AI Scoring Helper Class
 * Handles communication with various AI providers (OpenAI, Groq, Gemini)
 */
class AIScoring {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Get the currently active AI provider
     */
    public function getActiveProvider() {
        $stmt = $this->conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'active_ai_api'");
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return str_replace('ai_api_', '', $row['setting_value']);
        }
        return 'openai'; // default
    }
    
    /**
     * Get settings for a specific provider
     */
    public function getProviderSettings($provider) {
        $key = 'ai_api_' . strtolower($provider);
        $stmt = $this->conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $settings = json_decode($row['setting_value'], true);
            if (is_array($settings) && strtolower($provider) === 'openai') {
                if (empty($settings['llm'])) {
                    $settings['llm'] = 'gpt-5.4';
                }
                if (!isset($settings['reasoning_effort']) || $settings['reasoning_effort'] === '') {
                    $settings['reasoning_effort'] = 'high';
                }
            }
            return $settings;
        }
        return null;
    }
    
    /**
     * Generate AI response
     * @param string $prompt User prompt
     * @param string $system_prompt System prompt/instructions
     * @param string|null $provider Provider override (optional)
     * @param array|null $override_settings Settings override (optional, for testing)
     */
    public function generateResponse($prompt, $system_prompt = "You are a helpful assistant.", $provider = null, $override_settings = null) {
        if ($override_settings) {
            $settings = $override_settings;
            $current_provider = $provider;
        } else {
            $current_provider = $provider ?: $this->getActiveProvider();
            $settings = $this->getProviderSettings($current_provider);
        }
        
        if (!$settings || empty($settings['api_key'])) {
            return ['success' => false, 'error' => 'API key not configured for ' . $current_provider];
        }
        
        $api_key = $settings['api_key'];
        $model = $settings['llm'];
        $reasoning_effort = $settings['reasoning_effort'] ?? 'none';
        $prov = strtolower($current_provider);
        
        $url = '';
        $headers = ['Content-Type: application/json'];
        $data = [];
        
        // Configuration for different providers
        if ($prov === 'openai' || $prov === 'groq' || $prov === 'openrouter') {
            if ($prov === 'openai' && strpos($model, 'gpt-5') === 0) {
                $url = 'https://api.openai.com/v1/responses';
                $headers[] = 'Authorization: Bearer ' . $api_key;
                $data = [
                    'model' => $model,
                    'input' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $system_prompt . "\n\n" . $prompt
                        ]]
                    ]],
                    'max_output_tokens' => 1024
                ];
                if (!empty($reasoning_effort) && $reasoning_effort !== 'none') {
                    $data['reasoning'] = ['effort' => $reasoning_effort];
                }
            } elseif ($prov === 'openai') {
                $url = 'https://api.openai.com/v1/chat/completions';
                $headers[] = 'Authorization: Bearer ' . $api_key;
                $data = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system_prompt],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.3
                ];
            } elseif ($prov === 'groq') $url = 'https://api.groq.com/openai/v1/chat/completions';
            elseif ($prov === 'openrouter') {
                $url = 'https://openrouter.ai/api/v1/chat/completions';
                $headers[] = 'HTTP-Referer: https://toeic.osgli.example';
            }

            if ($prov === 'groq' || $prov === 'openrouter') {
                $headers[] = 'Authorization: Bearer ' . $api_key;
                $data = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system_prompt],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.3
                ];
            }
        } elseif ($prov === 'gemini') {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
            $data = [
                'contents' => [
                    ['parts' => [['text' => $system_prompt . "\n\n" . $prompt]]]
                ]
            ];
        } else {
            return ['success' => false, 'error' => 'Unsupported provider: ' . $prov];
        }
        
        // Execute Request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60s timeout
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            return ['success' => false, 'error' => 'Network error: ' . $curl_error];
        }
        
        $json = json_decode($response, true);
        
        // Handle API errors
        if (isset($json['error'])) {
            $msg = is_string($json['error']) ? $json['error'] : json_encode($json['error']);
            return ['success' => false, 'error' => 'API Error: ' . $msg];
        }
        
        // Extract text response
        $text_response = '';
        if ($prov === 'gemini') {
            $text_response = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } elseif ($prov === 'openai' && isset($json['output'])) {
            foreach ($json['output'] as $outputItem) {
                foreach (($outputItem['content'] ?? []) as $contentItem) {
                    if (($contentItem['type'] ?? '') === 'output_text' && isset($contentItem['text'])) {
                        $text_response .= $contentItem['text'];
                    }
                }
            }
            if ($text_response === '' && isset($json['output_text'])) {
                $text_response = $json['output_text'];
            }
        } else {
            $text_response = $json['choices'][0]['message']['content'] ?? '';
        }
        
        if (empty($text_response)) {
            return ['success' => false, 'error' => 'Empty response from API', 'raw' => $response];
        }
        
        return ['success' => true, 'response' => $text_response];
    }
    
    /**
     * Score a Writing Task
     */
    public function scoreWritingTask($prompt, $student_response, $rubric = null) {
        $system_prompt = "You are an expert English writing examiner. " .
            "Score the student's essay using a clear 0-30 scale for task completion, organization, vocabulary, and grammar. " .
            "Return ONLY a JSON object with the following fields: " .
            "score (integer 0-30), level (Good, Fair, etc.), feedback (string), corrections (array of strings).";
            
        $user_prompt = "Task Prompt: $prompt\n\nStudent Response:\n$student_response\n\n" . 
                       ($rubric ? "Rubric: $rubric" : "");
                       
        return $this->generateResponse($user_prompt, $system_prompt);
    }
    
    /**
     * Score a Speaking Task (Text input only for now)
     */
    public function scoreSpeakingTask($prompt, $student_transcript, $rubric = null) {
        $system_prompt = "You are an expert spoken-English examiner. " .
            "Score the student's response transcript using a clear 0-30 scale for fluency, coherence, and language control. " .
            "Return ONLY a JSON object with the following fields: " .
            "score (integer 0-30), level (Good, Fair, etc.), feedback (string), delivery_tips (array).";
            
        $user_prompt = "Task Prompt: $prompt\n\nStudent Transcript:\n$student_transcript\n\n" . 
                       ($rubric ? "Rubric: $rubric" : "");
                       
        return $this->generateResponse($user_prompt, $system_prompt);
    }
}
?>
