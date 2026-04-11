<?php
/**
 * AJAX Endpoint for Proctoring System
 * Handles event reports, snapshots, and AI analysis requests.
 */

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/proctor_helper.php';

header('Content-Type: application/json');

// 1. Auth Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$test_session = $_POST['test_session'] ?? '';
$action = $_POST['action'] ?? '';

if (empty($test_session)) {
    echo json_encode(['error' => 'Missing session']);
    exit;
}

// Sanitize test_session to prevent path traversal
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $test_session)) {
    echo json_encode(['error' => 'Invalid session ID format']);
    exit;
}

// 2. Initialize/Get Session ID
$session_id = initProctoringSession($user_id, $test_session, $_SESSION['test_format'] ?? 'unknown');

if (!$session_id) {
    echo json_encode(['error' => 'Session init failed']);
    exit;
}

// 3. Handle Actions
switch ($action) {

    case 'init':
        echo json_encode(['status' => 'active', 'session_id' => $session_id]);
        break;

    case 'update_permissions':
        $cam = $_POST['camera'] === 'true';
        $mic = $_POST['mic'] === 'true';
        updateProctoringPermissions($session_id, $cam, $mic);
        echo json_encode(['status' => 'ok']);
        break;

    case 'log_event':
        $type = $_POST['type'] ?? 'unknown';
        $severity = $_POST['severity'] ?? 'low';
        $metadata = isset($_POST['metadata']) ? json_decode($_POST['metadata'], true) : [];

        $snapshot_path = null;

        // Handle snapshot upload
        if (isset($_FILES['snapshot']) && $_FILES['snapshot']['error'] === UPLOAD_ERR_OK) {
            $dir = __DIR__ . '/../uploads/proctoring/snapshots/' . $test_session . '/';
            if (!is_dir($dir))
                mkdir($dir, 0755, true);

            $filename = time() . '_' . $type . '.jpg';
            $path = $dir . $filename;

            if (move_uploaded_file($_FILES['snapshot']['tmp_name'], $path)) {
                $snapshot_path = $path;
            }
        }

        $event_id = logProctoringEvent($session_id, $type, $severity, $metadata, $snapshot_path);

        $ai_analysis = null;
        // Trigger AI Analysis for High Severity with Snapshot
        if ($snapshot_path && ($severity === 'high' || $severity === 'critical')) {
            $ai_analysis = analyzeSnapshotWithAI($session_id, $snapshot_path, $type);

            // If AI confirms cheating, force high impact
            if (($ai_analysis['verdict'] ?? '') === 'cheating') {
                logProctoringEvent($session_id, 'ai_flagged_cheating', 'critical', ['reason' => $ai_analysis['reason'] ?? 'AI detected violation']);
            }
        }

        // Check current status
        $q = $conn->query("SELECT integrity_score FROM proctoring_sessions WHERE id = $session_id");
        $sess_data = $q->fetch_assoc();

        if ($sess_data['integrity_score'] < 40) {
            echo json_encode(['status' => 'logged', 'ai_action' => 'terminate']);
            exit;
        }

        echo json_encode(['status' => 'logged', 'id' => $event_id, 'ai_analysis' => $ai_analysis]);
        break;

    case 'batch_sync':
        // Handle 30s batch sync
        $events = isset($_POST['events']) ? json_decode($_POST['events'], true) : [];
        $ai_result = ['action' => 'continue'];

        // Check status first
        $q = $conn->query("SELECT integrity_score FROM proctoring_sessions WHERE id = $session_id");
        $sess_data = $q->fetch_assoc();
        if ($sess_data['integrity_score'] < 40) {
            echo json_encode(['status' => 'synced', 'ai_action' => 'terminate']);
            exit;
        }

        if (!empty($events)) {
            // Log all events
            foreach ($events as $e) {
                logProctoringEvent($session_id, $e['type'], $e['severity'], $e['metadata'] ?? []);
            }

            // Run AI Analysis on this batch
            $ai_result = analyzeEventsWithAI($session_id, $events);
        }

        // Update heartbeat on successful sync
        updateHeartbeat($session_id);

        // Fetch updated integrity score
        $q_score = $conn->query("SELECT integrity_score FROM proctoring_sessions WHERE id = $session_id");
        $updated_score = $q_score->fetch_assoc()['integrity_score'];

        echo json_encode([
            'status' => 'synced',
            'integrity_score' => (int) $updated_score,
            'ai_action' => $ai_result['action'] ?? 'continue',
            'heartbeat' => 'ok'
        ]);
        break;

    case 'sync_failure':
        // Client reports a sync failure
        $status = recordSyncFailure($session_id);
        echo json_encode([
            'status' => 'recorded',
            'heartbeat_status' => $status
        ]);
        break;

    case 'heartbeat_status':
        // Check current heartbeat status
        $hb = getHeartbeatStatus($session_id);
        echo json_encode([
            'status' => 'ok',
            'heartbeat' => $hb
        ]);
        break;

    case 'upload_chunk':
        // Handle video chunk
        if (isset($_FILES['chunk'])) {
            $index = (int) ($_POST['index'] ?? 0);
            $success = saveVideoChunk($session_id, $index, $_FILES['chunk'], 30); // 30s chunks
            echo json_encode(['status' => $success ? 'ok' : 'error']);
        } else {
            echo json_encode(['error' => 'No file']);
        }
        break;

    case 'finalize':
        $result = finalizeProctoringSession($session_id);
        echo json_encode(['status' => 'finalized', 'result' => $result]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
?>