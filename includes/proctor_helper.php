<?php
/**
 * Proctoring Helper Functions
 * Handles event logging, integrity scoring, and AI analysis integration.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_helper.php';

// ============================================
// SETTINGS READER (DB-backed with fallback)
// ============================================

/**
 * Get a proctoring setting from database with static cache.
 * Falls back to constant or default if not found in DB.
 */
function getProctoringSetting($key, $default = null) {
    global $conn;
    static $cache = [];
    
    // Return cached value if available
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    
    // Try to read from database
    $value = $default;
    if ($conn) {
        $stmt = $conn->prepare("SELECT setting_value FROM proctoring_settings WHERE setting_key = ?");
        if ($stmt) {
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $value = $row['setting_value'];
            }
            $stmt->close();
        }
    }
    
    // Cache the result
    $cache[$key] = $value;
    return $value;
}

if (!function_exists('getProctoringIntegrityThreshold')) {
    function getProctoringIntegrityThreshold() {
        return max(0, min(100, (int)getProctoringSetting('integrity_threshold', 40)));
    }
}

if (!function_exists('getProctoringHeartbeatSeverity')) {
    function getProctoringHeartbeatSeverity($failures) {
        $failures = (int)$failures;
        if ($failures >= 3) {
            return 'critical';
        }
        if ($failures >= 1) {
            return 'warning';
        }
        return 'ok';
    }
}

if (!function_exists('syncToeicTestSessionStatusForProctoring')) {
    function syncToeicTestSessionStatusForProctoring($test_session, $status) {
        global $conn;

        if (!$conn || !$test_session) {
            return false;
        }

        $allowed = ['active', 'terminated'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        if ($status === 'active') {
            $stmt = $conn->prepare("
                UPDATE toeic_test_sessions
                SET status = 'active',
                    completed_at = NULL
                WHERE test_session = ?
            ");
            $stmt->bind_param("s", $test_session);
            return $stmt->execute();
        }

        $stmt = $conn->prepare("
            UPDATE toeic_test_sessions
            SET status = 'terminated',
                completed_at = COALESCE(completed_at, NOW())
            WHERE test_session = ? AND status = 'active'
        ");
        $stmt->bind_param("s", $test_session);
        return $stmt->execute();
    }
}

if (!function_exists('syncToeicTestSessionStatusForProctoringSession')) {
    function syncToeicTestSessionStatusForProctoringSession($session_id, $status) {
        global $conn;

        if (!$conn) {
            return false;
        }

        $stmt = $conn->prepare("SELECT test_session FROM proctoring_sessions WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['test_session'])) {
            return false;
        }

        return syncToeicTestSessionStatusForProctoring($row['test_session'], $status);
    }
}

// ============================================
// SESSION MANAGEMENT
// ============================================

/**
 * Initialize or retrieve a proctoring session
 */
function initProctoringSession($user_id, $test_session, $format) {
    global $conn;

    if (!$conn) {
        error_log('initProctoringSession: No database connection');
        return false;
    }

    // Check if exists
    $stmt = $conn->prepare("SELECT id FROM proctoring_sessions WHERE test_session = ?");
    if (!$stmt) {
        error_log('initProctoringSession: Prepare failed - ' . $conn->error);
        return false;
    }

    $stmt->bind_param("s", $test_session);
    if (!$stmt->execute()) {
        error_log('initProctoringSession: Execute failed - ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        error_log('initProctoringSession: Session exists - ID: ' . $row['id']);
        return $row['id'];
    }

    // Create new session
    error_log('initProctoringSession: Creating new session for user=' . $user_id . ', test=' . $test_session);

    // Look up voucher_code from user_purchases for this user+exam_type
    $voucher_code = null;
    $has_voucher_col = false;
    if ($conn) {
        $col_check = $conn->query("SHOW COLUMNS FROM proctoring_sessions LIKE 'voucher_code'");
        $has_voucher_col = ($col_check && $col_check->num_rows > 0);

        if ($has_voucher_col) {
            $target_col = 'exam_type';
            $col_check2 = $conn->query("SHOW COLUMNS FROM user_purchases LIKE 'exam_type'");
            if (!$col_check2 || $col_check2->num_rows === 0) {
                $target_col = 'test_type';
            }
            $vc_stmt = $conn->prepare("SELECT transaction_ref FROM user_purchases WHERE user_id = ? AND $target_col = ? AND status IN ('active','used') ORDER BY id DESC LIMIT 1");
            if ($vc_stmt) {
                $vc_stmt->bind_param("is", $user_id, $format);
                $vc_stmt->execute();
                $vc_row = $vc_stmt->get_result()->fetch_assoc();
                $vc_stmt->close();
                if ($vc_row && !empty($vc_row['transaction_ref']) && strpos($vc_row['transaction_ref'], 'VOUCHER-') === 0) {
                    $voucher_code = substr($vc_row['transaction_ref'], 8); // strip 'VOUCHER-' prefix
                }
            }
        }
    }

    if ($has_voucher_col && $voucher_code) {
        $stmt = $conn->prepare("
            INSERT INTO proctoring_sessions (user_id, test_session, test_format, started_at, voucher_code)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        if (!$stmt) {
            error_log('initProctoringSession: INSERT prepare failed - ' . $conn->error);
            return false;
        }
        $stmt->bind_param("isss", $user_id, $test_session, $format, $voucher_code);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO proctoring_sessions (user_id, test_session, test_format, started_at)
            VALUES (?, ?, ?, NOW())
        ");
        if (!$stmt) {
            error_log('initProctoringSession: INSERT prepare failed - ' . $conn->error);
            return false;
        }
        $stmt->bind_param("iss", $user_id, $test_session, $format);
    }

    if (!$stmt->execute()) {
        error_log('initProctoringSession: INSERT execute failed - ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $session_id = $conn->insert_id;
    $stmt->close();

    if ($session_id) {
        error_log('initProctoringSession: New session created - ID: ' . $session_id);
        return $session_id;
    }

    error_log('initProctoringSession: INSERT executed but no insert_id returned');
    return false;
}

/**
 * Update permissions status
 */
function updateProctoringPermissions($session_id, $camera, $mic) {
    global $conn;
    $stmt = $conn->prepare("
        UPDATE proctoring_sessions 
        SET camera_granted = ?, microphone_granted = ? 
        WHERE id = ?
    ");
    $c = $camera ? 1 : 0;
    $m = $mic ? 1 : 0;
    $stmt->bind_param("iii", $c, $m, $session_id);
    return $stmt->execute();
}

// ============================================
// EVENT LOGGING
// ============================================

/**
 * Log a proctoring event
 */
function logProctoringEvent($session_id, $type, $severity = 'medium', $metadata = [], $snapshot = null) {
    global $conn;

    $meta_json = is_array($metadata) ? json_encode($metadata) : $metadata;

    // Calculate impact from severity
    $impact = getSeverityImpact($severity);

    // Calculate event time (relative to start)
    $start_q = $conn->query("SELECT started_at FROM proctoring_sessions WHERE id = $session_id");
    $start_row = $start_q->fetch_assoc();
    $start_ts = strtotime($start_row['started_at']);
    $event_time = time() - $start_ts;

    $stmt = $conn->prepare("
        INSERT INTO proctoring_events
        (session_id, event_type, severity, event_time, metadata, snapshot_path, ai_score_impact)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("ississi", $session_id, $type, $severity, $event_time, $meta_json, $snapshot, $impact);
    $stmt->execute();

    // Update main integrity score
    if ($impact > 0) {
        updateIntegrityScore($session_id, $impact);
    }

    return $conn->insert_id;
}

/**
 * Calculate impact based on severity
 */
function getSeverityImpact($severity) {
    switch ($severity) {
        case 'critical': return 15;
        case 'high': return 10;
        case 'medium': return 3;
        case 'low': return 1;
        default: return 0;
    }
}

/**
 * Get current integrity score for session
 */
function getProctoringSessionScore($session_id) {
    global $conn;
    
    $q = $conn->query("SELECT integrity_score FROM proctoring_sessions WHERE id = $session_id");
    if ($row = $q->fetch_assoc()) {
        return ['score' => (int)$row['integrity_score']];
    }
    
    return ['score' => 100];
}

/**
 * Update the session's integrity score
 */
function updateIntegrityScore($session_id, $impact_deduction) {
    global $conn;

    if ($impact_deduction <= 0) return;

    $stmt = $conn->prepare("
        UPDATE proctoring_sessions
        SET integrity_score = GREATEST(0, integrity_score - ?)
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $impact_deduction, $session_id);
    $stmt->execute();

    // Check for critical failure (Integrity < 40)
    checkAndEnforceTermination($session_id);
}

/**
 * Check if session should be terminated based on score
 */
function checkAndEnforceTermination($session_id) {
    global $conn;
    $threshold = getProctoringIntegrityThreshold();
    $stmt = $conn->prepare("SELECT integrity_score, status FROM proctoring_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['integrity_score'] < $threshold && $row['status'] !== 'terminated') {
        terminateProctoringSession($session_id, 'integrity_threshold_breached');
        $stmt = $conn->prepare("UPDATE proctoring_sessions SET notes = 'Auto-terminated due to low integrity score' WHERE id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    return false;
}

// ============================================
// AI ANALYSIS (REAL-TIME)
// ============================================

/**
 * Analyze a batch of events using AI with retry logic
 * Timeout configurable via PROCTOR_AI_TIMEOUT_MS constant (default 5000ms)
 * Retries up to 2 times on transient failures (3 total attempts)
 */
function analyzeEventsWithAI($session_id, $recent_events) {
    global $conn;
    
    $config = getActiveAIProvider();
    if (!$config) return ['action' => 'continue'];
    
    // Configurable timeout (milliseconds) from DB or constant, default 5000ms
    $timeout_ms = (int)getProctoringSetting('ai_timeout_ms', 5000);
    if (defined('PROCTOR_AI_TIMEOUT_MS')) {
        $timeout_ms = (int)PROCTOR_AI_TIMEOUT_MS; // constant overrides DB
    }
    
    // Prepare data for AI
    $event_summary = array_map(function($e) {
        return sprintf("[%ds] %s (%s)", $e['event_time'], $e['event_type'], $e['severity']);
    }, $recent_events);
    
    $prompt = "You are an AI Exam Proctor. Analyze these recent events:\n" . 
              implode("\n", $event_summary) . "\n\n" .
              "Determine if this pattern indicates cheating.\n" .
              "Respond JSON: { \"risk_score\": 0-100, \"action\": \"continue|warning|alert\" }";
    
    $max_attempts = 3;
    $last_error = null;
    
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        try {
            $start = microtime(true);
            $response = callAI($prompt, $config, 100, [], $timeout_ms); // Pass timeout_ms
            $duration = (int)((microtime(true) - $start) * 1000);
            
            if (empty(trim($response))) {
                throw new Exception("Empty AI response (attempt $attempt)");
            }
            
            $json = parseAIJSON($response);
            
            // Validate minimum keys for events analysis
            if (!isset($json['action']) && !isset($json['risk_score'])) {
                throw new Exception("Invalid AI JSON: missing action/risk_score (attempt $attempt)");
            }
            
            // Log AI decision (only once on success)
            $stmt = $conn->prepare("
                INSERT INTO proctoring_ai_logs 
                (session_id, request_payload, response_payload, response_time_ms, window_score, action_taken)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $req_json = json_encode($recent_events);
            $res_json = json_encode($json);
            $score = $json['risk_score'] ?? 0;
            $action = $json['action'] ?? 'continue';
            
            $stmt->bind_param("issiis", $session_id, $req_json, $res_json, $duration, $score, $action);
            $stmt->execute();
            
            return $json;
            
        } catch (Exception $e) {
            $last_error = $e->getMessage();
            error_log("AI Proctor Error (session $session_id, attempt $attempt/$max_attempts): " . $last_error);
            
            // Brief pause before retry (except on last attempt)
            if ($attempt < $max_attempts) {
                usleep(200000); // 200ms
            }
        }
    }
    
    // All attempts failed - fail-open, do not terminate exam
    error_log("AI Proctor: All $max_attempts attempts failed for session $session_id. Failing open.");
    return ['action' => 'continue'];
}

/**
 * Analyze a specific snapshot with Vision AI
 * Uses same timeout logic as analyzeEventsWithAI
 */
function analyzeSnapshotWithAI($session_id, $snapshot_path, $reason = 'manual_check') {
    global $conn;
    
    $config = getActiveAIProvider();
    if (!$config) return ['verdict' => 'error'];
    
    if (!file_exists($snapshot_path)) return ['verdict' => 'error', 'msg' => 'File not found'];
    
    // Configurable timeout (milliseconds) from DB or constant, default 5000ms
    $timeout_ms = (int)getProctoringSetting('ai_timeout_ms', 5000);
    if (defined('PROCTOR_AI_TIMEOUT_MS')) {
        $timeout_ms = (int)PROCTOR_AI_TIMEOUT_MS; // constant overrides DB
    }
    
    $image_data = base64_encode(file_get_contents($snapshot_path));
    
    $prompt = "You are a strict exam proctor. Analyze this image captured during an online exam.\n" .
              "Reason for capture: {$reason}\n" .
              "Check for:\n" .
              "1. Multiple people in frame.\n" .
              "2. Electronic devices (phones, tablets, earbuds).\n" .
              "3. Books, notes, or cheat sheets.\n" .
              "4. User looking away significantly or leaving the frame.\n" .
              "5. User wearing headphones (unless allowed).\n\n" .
              "Return JSON ONLY: { \"detected\": [\"list\", \"items\"], \"risk_score\": 0-100, \"verdict\": \"clean|suspicious|cheating\", \"reason\": \"short explanation\" }";

    try {
        $start = microtime(true);
        // Call AI with image and timeout
        $response = callAI($prompt, $config, 300, [$image_data], $timeout_ms);
        $duration = (int)((microtime(true) - $start) * 1000);
        
        $json = parseAIJSON($response);
        
        // Log AI decision
        $stmt = $conn->prepare("
            INSERT INTO proctoring_ai_logs 
            (session_id, request_payload, response_payload, response_time_ms, window_score, action_taken)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $req_json = json_encode(['type' => 'snapshot_analysis', 'reason' => $reason]);
        $res_json = json_encode($json);
        $score = $json['risk_score'] ?? 0;
        $action = $json['verdict'] ?? 'unknown';
        
        $stmt->bind_param("issiis", $session_id, $req_json, $res_json, $duration, $score, $action);
        $stmt->execute();
        
        return $json;
        
    } catch (Exception $e) {
        error_log("AI Vision Proctor Error: " . $e->getMessage());
        return ['verdict' => 'error'];
    }
}

function parseAIJSON($response) {
    // Robust JSON extraction supporting markdown code fences and surrounding text
    
    // 1. Try to extract from markdown code fences first (```json ... ```)
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $response, $matches)) {
        $candidate = trim($matches[1]);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    
    // 2. Try to find first JSON object { ... }
    if (preg_match('/\{[^{}]*+(?:\{[^{}]*+\}[^{}]*+)*+\}/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    
    // 3. Try to find first JSON array [ ... ]
    if (preg_match('/\[[^\[\]]*+(?:\[[^\[\]]*+\][^\[\]]*+)*+\]/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    
    // 4. Fallback: greedy object match (legacy behavior)
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    
    return [];
}

// ============================================
// VIDEO MANAGEMENT
// ============================================

/**
 * Save a video chunk
 */
function saveVideoChunk($session_id, $index, $file, $duration) {
    global $conn;
    
    $upload_dir = __DIR__ . '/../uploads/proctoring/chunks/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $filename = "sess_{$session_id}_chunk_{$index}.webm";
    $path = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $path)) {
        $size = filesize($path) / 1024; // KB
        
        $stmt = $conn->prepare("
            INSERT INTO proctoring_video_chunks 
            (session_id, chunk_index, chunk_path, chunk_size_kb, start_time, end_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $start = $index * $duration;
        $end = $start + $duration;
        
        $stmt->bind_param("iisiis", $session_id, $index, $path, $size, $start, $end);
        $stmt->execute();
        return true;
    }
    return false;
}

/**
 * Finalize video (Combine chunks or delete based on score)
 */
function finalizeProctoringSession($session_id) {
    global $conn;
    
    // Get session score
    $q = $conn->query("SELECT integrity_score FROM proctoring_sessions WHERE id = $session_id");
    $row = $q->fetch_assoc();
    $score = $row['integrity_score'];
    
    // Threshold: clean sessions drop chunks immediately; flagged sessions keep evidence.
    if ($score >= 70) {
        // Delete chunks immediately
        deleteSessionChunks($session_id);
        if (function_exists('checkColumnExists') && checkColumnExists($conn, 'proctoring_sessions', 'video_status')) {
            $conn->query("UPDATE proctoring_sessions SET video_status = 'deleted' WHERE id = $session_id");
        }
        return "clean";
    } else {
        // Keep chunks for review (or stitch them if ffmpeg available)
        // For now, we keep chunks and mark as stored
        if (
            function_exists('checkColumnExists') &&
            checkColumnExists($conn, 'proctoring_sessions', 'video_status') &&
            checkColumnExists($conn, 'proctoring_sessions', 'video_delete_after')
        ) {
            $conn->query("UPDATE proctoring_sessions SET video_status = 'stored', video_delete_after = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = $session_id");
        }
        return "flagged";
    }
}

function deleteSessionChunks($session_id) {
    global $conn;
    $q = $conn->query("SELECT chunk_path FROM proctoring_video_chunks WHERE session_id = $session_id");
    while ($row = $q->fetch_assoc()) {
        if (file_exists($row['chunk_path'])) unlink($row['chunk_path']);
    }
    $conn->query("DELETE FROM proctoring_video_chunks WHERE session_id = $session_id");
}

// ============================================
// HEARTBEAT TRACKING
// ============================================

/**
 * Update heartbeat on successful sync
 * Resets failure counter and updates the last heartbeat timestamp.
 */
function updateHeartbeat($session_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE proctoring_sessions 
        SET last_heartbeat_at = NOW(),
            sync_failures = 0
        WHERE id = ?
    ");
    $stmt->bind_param("i", $session_id);
    return $stmt->execute();
}

/**
 * Record a sync failure (called when client reports failure)
 */
function recordSyncFailure($session_id) {
    global $conn;
    
    // Get current failure count
    $q = $conn->query("SELECT sync_failures FROM proctoring_sessions WHERE id = $session_id");
    $row = $q->fetch_assoc();
    $new_failures = ($row['sync_failures'] ?? 0) + 1;
    
    $status = getProctoringHeartbeatSeverity($new_failures);
    
    $stmt = $conn->prepare("
        UPDATE proctoring_sessions 
        SET sync_failures = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $new_failures, $session_id);
    $stmt->execute();
    
    // Log the failure event
    if ($status === 'critical') {
        logProctoringEvent($session_id, 'heartbeat_critical', 'high', [
            'failures' => $new_failures,
            'message' => 'Client reported critical sync failure'
        ]);
    }
    
    return $status;
}

/**
 * Check for stale sessions (server-side cron job)
 * Returns array of stale session IDs
 */
function checkStaleHeartbeats($timeout_seconds = 90) {
    global $conn;
    
    $stale_sessions = [];
    
    // Find active sessions with stale heartbeat
    $q = $conn->query("
        SELECT id, user_id, test_session, last_heartbeat_at, sync_failures
        FROM proctoring_sessions
        WHERE status = 'active'
          AND (last_heartbeat_at IS NULL OR last_heartbeat_at < DATE_SUB(NOW(), INTERVAL $timeout_seconds SECOND))
    ");
    
    while ($row = $q->fetch_assoc()) {
        $session_id = $row['id'];
        $new_failures = ($row['sync_failures'] ?? 0) + 1;
        
        $new_status = getProctoringHeartbeatSeverity($new_failures);
        
        // Update session
        $conn->query("UPDATE proctoring_sessions 
                      SET sync_failures = $new_failures
                      WHERE id = $session_id");
        
        // Log event
        logProctoringEvent($session_id, 'heartbeat_timeout', 'high', [
            'last_sync' => $row['last_heartbeat_at'],
            'failures' => $new_failures,
            'timeout_seconds' => $timeout_seconds
        ]);
        
        $stale_sessions[] = [
            'id' => $session_id,
            'user_id' => $row['user_id'],
            'test_session' => $row['test_session'],
            'failures' => $new_failures,
            'status' => $new_status
        ];
    }
    
    return $stale_sessions;
}

/**
 * Get heartbeat status for a session
 */
function getHeartbeatStatus($session_id) {
    global $conn;

    $q = $conn->query("
        SELECT last_heartbeat_at, sync_failures
        FROM proctoring_sessions
        WHERE id = $session_id
    ");

    if ($row = $q->fetch_assoc()) {
        return [
            'last_sync' => $row['last_heartbeat_at'],
            'failures' => (int)$row['sync_failures'],
            'status' => getProctoringHeartbeatSeverity($row['sync_failures'])
        ];
    }

    return null;
}

/**
 * Save snapshot image
 */
function saveSnapshot($session_id, $imageData, $event_type = 'snapshot') {
    global $conn;
    
    $upload_dir = __DIR__ . '/../uploads/proctoring/snapshots/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $filename = 'snapshot_' . $session_id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $filepath = $upload_dir . $filename;
    $relative_path = 'uploads/proctoring/snapshots/' . $filename;
    
    if (file_put_contents($filepath, $imageData)) {
        $stmt = $conn->prepare("
            INSERT INTO proctoring_events (session_id, event_type, severity, snapshot_path)
            VALUES (?, ?, 'medium', ?)
        ");
        $stmt->bind_param("iss", $session_id, $event_type, $relative_path);
        $stmt->execute();
        
        return $conn->insert_id;
    }
    
    return false;
}

/**
 * Terminate proctoring session
 */
function terminateProctoringSession($session_id, $reason = 'user_ended') {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE proctoring_sessions
        SET ended_at = NOW(),
            ended_by = 'system',
            termination_reason = ?,
            status = 'terminated'
        WHERE id = ?
    ");
    $stmt->bind_param("si", $reason, $session_id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        syncToeicTestSessionStatusForProctoringSession($session_id, 'terminated');
    }

    return $ok;
}

/**
 * Update proctoring heartbeat
 */
function updateProctoringHeartbeat($session_id, $lastActivity) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE proctoring_sessions
        SET last_heartbeat_at = FROM_UNIXTIME(?),
            sync_failures = 0
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $lastActivity, $session_id);
    return $stmt->execute();
}

/**
 * Log exam anomaly (for backward compatibility)
 */
function logExamAnomaly($user_id, $test_session, $type, $details) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO exam_anomalies (user_id, test_session, event_type, details)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $user_id, $test_session, $type, $details);
    return $stmt->execute();
}

?>
