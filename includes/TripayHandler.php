<?php
/**
 * Tripay Handler Class (Standalone Implementation)
 * Handles Closed Payment transaction creation and Callback verification.
 * 
 * API Documentation: https://tripay.co.id/developer
 */

class TripayHandler {
    private $apiKey;
    private $privateKey;
    private $merchantCode;
    private $isProduction;
    private $baseUrl;
    private $lastError = '';

    public function __construct() {
        $this->apiKey = defined('TRIPAY_API_KEY') ? TRIPAY_API_KEY : '';
        $this->privateKey = defined('TRIPAY_PRIVATE_KEY') ? TRIPAY_PRIVATE_KEY : '';
        $this->merchantCode = defined('TRIPAY_MERCHANT_CODE') ? TRIPAY_MERCHANT_CODE : '';
        $this->isProduction = defined('TRIPAY_IS_PRODUCTION') ? TRIPAY_IS_PRODUCTION : false;
        
        $this->baseUrl = $this->isProduction 
            ? 'https://tripay.co.id/api/' 
            : 'https://tripay.co.id/api-sandbox/';
    }

    /**
     * Set credentials manually
     */
    public function setCredentials($apiKey, $privateKey, $merchantCode, $isProduction) {
        $this->apiKey = $apiKey;
        $this->privateKey = $privateKey;
        $this->merchantCode = $merchantCode;
        $this->isProduction = $isProduction;
        
        $this->baseUrl = $this->isProduction 
            ? 'https://tripay.co.id/api/' 
            : 'https://tripay.co.id/api-sandbox/';
    }

    /**
     * Generate Signature for API request
     * Signature = HMAC-SHA256(private_key, merchant_code + merchant_ref + amount)
     */
    public function generateSignature($merchantRef, $amount) {
        $data = $this->merchantCode . $merchantRef . $amount;
        return hash_hmac('sha256', $data, $this->privateKey);
    }

    /**
     * Create Closed Payment Transaction
     * 
     * @param array $params Transaction parameters
     *   - merchant_ref: string (your unique reference)
     *   - amount: int (payment amount)
     *   - customer_name: string
     *   - customer_email: string
     *   - customer_phone: string (optional)
     *   - order_items: array of {name, price, quantity}
     *   - callback_url: string
     *   - return_url: string
     *   - expired_time: int (seconds, default 86400 = 24 hours)
     *   - method: string (default 'QRIS')
     * @return array|null Response data or null on failure
     */
    public function createTransaction($params) {
        $method = $params['method'] ?? 'QRIS';
        $merchantRef = $params['merchant_ref'];
        $amount = (int) $params['amount'];
        $expiredTime = $params['expired_time'] ?? (time() + 86400); // Unix timestamp: 24h from now
        
        $signature = $this->generateSignature($merchantRef, $amount);
        
        $payload = [
            'method' => $method,
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'customer_name' => $params['customer_name'] ?? 'Customer',
            'customer_email' => $params['customer_email'] ?? '',
            'order_items' => $params['order_items'] ?? [],
            'callback_url' => $params['callback_url'] ?? '',
            'return_url' => $params['return_url'] ?? '',
            'expired_time' => $expiredTime,
            'signature' => $signature
        ];

        // Only include customer_phone if non-empty — Tripay rejects empty string
        if (!empty($params['customer_phone'])) {
            $payload['customer_phone'] = $params['customer_phone'];
        }

        $endpoint = 'transaction/create';
        $this->log("Request [$endpoint]: " . json_encode($payload));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log("Curl Error [$endpoint]: $error");
            $this->lastError = "cURL error: $error";
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        $this->log("Response [$endpoint] ($httpCode): " . $result);

        if ($httpCode >= 200 && $httpCode < 300) {
            $response = json_decode($result, true);
            if ($response && $response['success']) {
                return $response['data'] ?? null;
            }
            $msg = $response['message'] ?? 'Unknown error';
            $this->log("API Error [$endpoint]: $msg");
            $this->lastError = "Tripay API: $msg";
        } else {
            $this->log("HTTP Error [$endpoint]: $httpCode - " . $result);
            $this->lastError = "HTTP $httpCode: " . substr($result, 0, 200);
        }

        return null;
    }

    public function getLastError(): string {
        return $this->lastError;
    }

    /**
     * Get Transaction Detail
     * 
     * @param string $reference Tripay transaction reference
     * @return array|null
     */
    public function getTransactionDetail($reference) {
        $endpoint = 'transaction/detail?reference=' . $reference;
        $this->log("Request [$endpoint]");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log("Curl Error [$endpoint]: $error");
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);

        $this->log("Response [$endpoint] ($httpCode): " . $result);

        if ($httpCode >= 200 && $httpCode < 300) {
            $response = json_decode($result, true);
            if ($response && $response['success']) {
                return $response['data'] ?? null;
            }
             $this->log("API Error [$endpoint]: " . ($response['message'] ?? 'Unknown error'));
        } else {
             $this->log("HTTP Error [$endpoint]: $httpCode - " . $result);
        }

        return null;
    }

    /**
     * Get Available Payment Channels
     * 
     * @param string $code Filter by payment code (optional)
     * @return array|null
     */
    public function getPaymentChannels($code = null) {
        $endpoint = 'merchant/payment-channel';
        if ($code) {
            $endpoint .= '?code=' . $code;
        }

        $this->log("Request [$endpoint]");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
             $error = curl_error($ch);
            $this->log("Curl Error [$endpoint]: $error");
            curl_close($ch);
            return null;
        }

        curl_close($ch);
        
        $this->log("Response [$endpoint] ($httpCode): " . $result);

        $response = json_decode($result, true);
        if ($response && $response['success']) {
            return $response['data'] ?? [];
        } else {
             $this->log("API Error [$endpoint]: " . ($response['message'] ?? 'Unknown error'));
        }

        return null;
    }

    /**
     * Verify Callback Signature
     * Callback signature is HMAC-SHA256 of private_key with the request body as data
     * 
     * @param string $callbackSignature Signature from X-Callback-Signature header
     * @param string $requestBody Raw request body
     * @return bool
     */
    public function verifyCallbackSignature($callbackSignature, $requestBody) {
        $computedSignature = hash_hmac('sha256', $requestBody, $this->privateKey);
        return hash_equals($computedSignature, $callbackSignature);
    }

    /**
     * Parse callback event
     * 
     * @param string $requestBody Raw request body
     * @return array|null Parsed callback data
     */
    public function parseCallback($requestBody) {
        $this->log("Callback Received: " . $requestBody);
        $data = json_decode($requestBody, true);
        
        if (!$data) {
            $this->log("Callback Parse Error: Invalid JSON");
            return null;
        }

        return [
            'event' => $data['event'] ?? '',
            'reference' => $data['reference'] ?? '',
            'merchant_ref' => $data['merchant_ref'] ?? '',
            'status' => $data['status'] ?? '', // PAID, UNPAID, EXPIRED, FAILED
            'amount' => $data['amount'] ?? 0,
            'fee' => $data['fee'] ?? 0,
            'total_fee' => $data['total_fee'] ?? 0,
            'net_amount' => $data['net_amount'] ?? 0,
            'payment_method' => $data['payment_method'] ?? '',
            'payment_code' => $data['payment_code'] ?? '',
            'paid_at' => $data['paid_at'] ?? null,
            'expired_at' => $data['expired_at'] ?? null,
            'customer_name' => $data['customer_name'] ?? '',
            'customer_email' => $data['customer_email'] ?? '',
        ];
    }

    /**
     * Log helper
     */
    public function log($message) {
        $logFile = __DIR__ . '/../logs/tripay_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        // Create logs directory if not exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
?>
