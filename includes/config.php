<?php
// ============================================================
// JSON ERROR GUARD
// Buffers all output so PHP fatal errors / exceptions cannot
// leak HTML into AJAX JSON responses.
// ============================================================
ob_start();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type: application/json') !== false) {
                ob_clean();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Server error: ' . $error['message']]);
                return;
            }
        }
    }
});

set_exception_handler(function (Throwable $e) {
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type: application/json') !== false) {
            ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
            exit();
        }
    }
    // Non-JSON page: re-throw so PHP's default handler shows it
    throw $e;
});

/**
 * Database Configuration for Railway Deployment
 * Automatically detects Railway environment variables
 */

// Log all errors; suppress display on AJAX/API endpoints to prevent warnings leaking into JSON
error_reporting(E_ALL);
ini_set('log_errors', 1);
$_requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($_requestUri, 'ajax_') !== false || strpos($_requestUri, '/api/') !== false) {
    ini_set('display_errors', 0);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

// Set timezone to Jakarta (WIB/GMT+7)
date_default_timezone_set('Asia/Jakarta');

// Load .env file if it exists (Simple Loader)
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!getenv($name)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$db_host = '';
$db_user = '';
$db_pass = '';
$db_name = '';
$db_port = '3306';
$db_connection_failed = false;
$db_connection_error_message = '';

// 1. Try Standard MySQL Variables (Railway Recommended)
if (getenv('MYSQLHOST')) {
    $db_host = getenv('MYSQLHOST');
    $db_port = getenv('MYSQLPORT') ?: '3306';
    $db_user = getenv('MYSQLUSER');
    $db_pass = getenv('MYSQLPASSWORD');
    $db_name = getenv('MYSQLDATABASE');
} 
// 2. Try DATABASE_URL (Alternative Railway Variable)
elseif (getenv('DATABASE_URL')) {
    $url = parse_url(getenv('DATABASE_URL'));
    $db_host = $url['host'];
    $db_port = $url['port'] ?? '3306';
    $db_user = $url['user'];
    $db_pass = $url['pass'];
    $db_name = ltrim($url['path'], '/');
}
// 3. Detect Railway but Missing Variables
elseif (getenv('RAILWAY_ENVIRONMENT')) {
    die("<h1>Configuration Error</h1><p>Railway environment detected, but database variables are missing.</p><p>Please go to your Railway Project -> <strong>OSGLI Service</strong> -> <strong>Variables</strong> and add <code>MYSQLHOST</code>, <code>MYSQLUSER</code>, <code>MYSQLPASSWORD</code>, <code>MYSQLDATABASE</code>.</p>");
}
// 4. Local Development Fallback
else {
    $db_host = 'localhost';
    $db_port = '3306';
    $db_user = '';
    $db_pass = '';
    $db_name = '';
}

// Create connection
try {
    $conn = mysqli_init();
    if (!$conn) throw new Exception("mysqli_init failed");

    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    
    // Suppress warnings to avoid exposing password in error trace
    @$conn->real_connect($db_host, $db_user, $db_pass, $db_name, (int)$db_port);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Validate DB selection
    if (empty($db_name) || !$conn->select_db($db_name)) {
        // Try fallback 'railway'
        if ($conn->select_db('railway')) {
            $db_name = 'railway';
        } else {
             // Try to find any DB
             $res = $conn->query("SHOW DATABASES");
             $dbs = [];
             while($row = $res->fetch_row()) {
                 if (!in_array($row[0], ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                     $dbs[] = $row[0];
                 }
             }
             if (!empty($dbs)) {
                 $conn->select_db($dbs[0]);
                 $db_name = $dbs[0];
             } else {
                 throw new Exception("No database selected/available.");
             }
        }
    }

    $conn->set_charset("utf8mb4");
    $conn->query("SET collation_connection = 'utf8mb4_general_ci'");
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

} catch (Exception $e) {
    $conn = null;
    $db_connection_failed = true;
    $db_connection_error_message = $e->getMessage();

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $publicGracefulPaths = ['/', '/index.php', '/login.php', '/register.php', '/checkout-va.php', '/logout.php'];
    $canRenderWithoutDb = $requestMethod === 'GET' && in_array($requestPath, $publicGracefulPaths, true);

    if ($canRenderWithoutDb) {
        error_log("Database unavailable for public page {$requestPath}: {$db_connection_error_message}");
    } else {
        // Safe error message (hides password)
        $safe_host = htmlspecialchars($db_host);
        $safe_user = htmlspecialchars($db_user);
        $safe_error = htmlspecialchars($db_connection_error_message);

        die("
            <div style='font-family:sans-serif; padding:20px; border:1px solid red; background:#fff0f0; border-radius:8px;'>
                <h3 style='color:red;'>Database Connection Error</h3>
                <p><strong>Error:</strong> $safe_error</p>
                <hr>
                <p><strong>Debug Info:</strong></p>
                <ul>
                    <li>Host: $safe_host</li>
                    <li>User: $safe_user</li>
                    <li>Port: $db_port</li>
                    <li>Database: $db_name</li>
                </ul>
                <p><em>Please check your Railway Variables.</em></p>
            </div>
        ");
    }
}

// ============================================================
// TEST TYPE CONSTANTS
// ============================================================

// Test Format Types
define('TEST_FORMAT_TOEIC', 'toeic');

// Proctoring Configuration
// Options: 'strict' (terminates on violation) or 'flexible' (warnings only)
define('PROCTORING_MODE', 'flexible');

// Test Format Display Names
define('TEST_FORMATS', [
    TEST_FORMAT_TOEIC => [
        'name' => 'TOEIC',
        'full_name' => 'Test of English for International Communication',
        'description' => 'Listening and Reading test for workplace English proficiency',
        'sections' => ['listening', 'reading'],
        'total_questions' => 200,
        'duration_minutes' => 120,
        'score_range' => '10-990',
        'icon' => 'fa-briefcase'
    ]
]);

// TOEIC Section Configuration  
define('TOEIC_SECTIONS', [
    'listening' => [
        'name' => 'Listening',
        'parts' => [
            1 => ['name' => 'Photographs', 'questions' => 6],
            2 => ['name' => 'Question-Response', 'questions' => 25],
            3 => ['name' => 'Conversations', 'questions' => 39],
            4 => ['name' => 'Talks', 'questions' => 30]
        ],
        'total_questions' => 100, // Standard ETS
        'duration_minutes' => 45,
        'score_max' => 495
    ],
    'reading' => [
        'name' => 'Reading',
        'parts' => [
            5 => ['name' => 'Incomplete Sentences', 'questions' => 30],
            6 => ['name' => 'Text Completion', 'questions' => 16],
            7 => ['name' => 'Reading Comprehension', 'questions' => 54]
        ],
        'total_questions' => 100, // Standard ETS
        'duration_minutes' => 75,
        'score_max' => 495
    ]
]);

// ============================================================
// HELPER FUNCTION FOR TEST FORMAT
// ============================================================

if (!function_exists('getTestFormatInfo')) {
    /**
     * Get test format configuration
     * @param string $format Test format key
     * @return array|null
     */
    function getTestFormatInfo($format) {
        return TEST_FORMATS[$format] ?? null;
    }
}

if (!function_exists('getAvailableTestFormats')) {
    /**
     * Get all available test formats
     * @return array
     */
    function getAvailableTestFormats() {
        return TEST_FORMATS;
    }
}
// Redeploy trigger Sat Jan 24 09:16:17 SEAST 2026

// ============================================================
// TRIPAY CONFIGURATION (Payment Gateway)
// ============================================================

/**
 * Load Tripay settings using the cached getSiteSetting() with fallback to env vars.
 * No extra DB query — piggybacks on the bulk settings cache in settings.php.
 */
function loadTripaySettings() {
    require_once __DIR__ . '/settings.php';

    $db_api_key       = getSiteSetting('tripay_api_key', '');
    $db_private_key   = getSiteSetting('tripay_private_key', '');
    $db_merchant_code = getSiteSetting('tripay_merchant_code', '');
    $db_is_production = getSiteSetting('tripay_is_production', '');

    return [
        'api_key'       => $db_api_key       ?: (getenv('TRIPAY_API_KEY') ?: ''),
        'private_key'   => $db_private_key   ?: (getenv('TRIPAY_PRIVATE_KEY') ?: ''),
        'merchant_code' => $db_merchant_code ?: (getenv('TRIPAY_MERCHANT_CODE') ?: ''),
        'is_production' => $db_is_production !== ''
            ? ($db_is_production === '1')
            : filter_var(getenv('TRIPAY_IS_PRODUCTION') ?: false, FILTER_VALIDATE_BOOLEAN)
    ];
}

// Load Tripay settings (DB takes precedence over env vars)
$tripaySettings = loadTripaySettings();

define('TRIPAY_API_KEY', $tripaySettings['api_key']);
define('TRIPAY_PRIVATE_KEY', $tripaySettings['private_key']);
define('TRIPAY_MERCHANT_CODE', $tripaySettings['merchant_code']);
define('TRIPAY_IS_PRODUCTION', $tripaySettings['is_production']);



if (!defined('FEATURE_SECURE_AUDIO')) {
    define('FEATURE_SECURE_AUDIO', filter_var(getenv('FEATURE_SECURE_AUDIO') ?: true, FILTER_VALIDATE_BOOLEAN));
}

if (!defined('FEATURE_ANTI_CHEAT')) {
    define('FEATURE_ANTI_CHEAT', filter_var(getenv('FEATURE_ANTI_CHEAT') ?: true, FILTER_VALIDATE_BOOLEAN));
}

if (!defined('FEATURE_PROCTORING')) {
    $env = getenv('FEATURE_PROCTORING');
    // Default: ON. Only OFF if env var is explicitly '0' or 'false'
    if ($env === '0' || strtolower($env) === 'false') {
        define('FEATURE_PROCTORING', false);
    } else {
        define('FEATURE_PROCTORING', true);
    }
}

// Proctoring AI Configuration
if (!defined('PROCTOR_AI_TIMEOUT_MS')) {
    define('PROCTOR_AI_TIMEOUT_MS', (int)(getenv('PROCTOR_AI_TIMEOUT_MS') ?: 5000));
}

// Heartbeat Configuration (for sync failure detection)
if (!defined('PROCTOR_HEARTBEAT_TIMEOUT_SECONDS')) {
    define('PROCTOR_HEARTBEAT_TIMEOUT_SECONDS', (int)(getenv('PROCTOR_HEARTBEAT_TIMEOUT_SECONDS') ?: 90));
}

// TOEIC is the canonical and only supported exam product in this repository.
// Keep the env override for operational control, but default to enabled.
if (!defined('FEATURE_TOEIC')) {
    $featureToeicEnv = getenv('FEATURE_TOEIC');
    if ($featureToeicEnv === false || $featureToeicEnv === '') {
        define('FEATURE_TOEIC', true);
    } else {
        define('FEATURE_TOEIC', filter_var($featureToeicEnv, FILTER_VALIDATE_BOOLEAN));
    }
}

// Backward compatibility alias: TOEIC_ENABLED mirrors FEATURE_TOEIC
// Use FEATURE_TOEIC (canonical) in new code; TOEIC_ENABLED is deprecated
if (!defined('TOEIC_ENABLED')) {
    define('TOEIC_ENABLED', FEATURE_TOEIC);
}

// Legacy multi-product support is disabled by default in the TOEIC-only product.
if (!defined('FEATURE_ITP')) {
    define('FEATURE_ITP', filter_var(getenv('FEATURE_ITP') ?: false, FILTER_VALIDATE_BOOLEAN));
}

// Backward compatibility alias: ITP_ENABLED mirrors FEATURE_ITP
// Use FEATURE_ITP (canonical) in new code; ITP_ENABLED is deprecated
if (!defined('ITP_ENABLED')) {
    define('ITP_ENABLED', FEATURE_ITP);
}

if (!function_exists('writeSecureAudioLog')) {
    function writeSecureAudioLog($message) {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/secure_audio.log';
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($logFile, $formattedMessage, FILE_APPEND);
    }
}
