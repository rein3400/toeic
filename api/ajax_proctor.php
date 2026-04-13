<?php
/**
 * AJAX Proctoring Endpoint
 * Handles proctoring events, video chunks, and integrity scoring
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/proctor_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Support both JSON body (camera_setup.php) and FormData (ProctorSDK)
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_POST['action'] ?? '');
$test_session = $input['testSession'] ?? ($_POST['test_session'] ?? ($_SESSION['test_session'] ?? $_SESSION['toeic_test_session'] ?? $_SESSION['test_session_2026'] ?? null));

if (!$test_session) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No test session']);
    exit;
}

$user_id = $_SESSION['user_id'];
$test_format = 'toeic';
$integrity_threshold = (int)getProctoringSetting('integrity_threshold', 40);

// Resolve session_id from test_session for all non-init actions
$session_id = null;
$stmt_sess = $conn->prepare("SELECT id FROM proctoring_sessions WHERE test_session = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
if ($stmt_sess) {
    $stmt_sess->bind_param("si", $test_session, $user_id);
    $stmt_sess->execute();
    $res_sess = $stmt_sess->get_result();
    if ($row_sess = $res_sess->fetch_assoc()) {
        $session_id = $row_sess['id'];
    }
    $stmt_sess->close();
}

try {
    switch ($action) {
        case 'init':
            // Initialize proctoring session (get-or-create)
            $session_id = initProctoringSession($user_id, $test_session, $test_format);

            if (!$session_id) {
                throw new Exception('Failed to initialize proctoring session: ' . ($conn ? $conn->error : 'No database connection'));
            }

            echo json_encode(['success' => true, 'session_id' => $session_id]);
            break;

        case 'log_event':
            // Log proctoring event
            if (!$session_id) throw new Exception('Proctoring session not found');
            $events = $input['events'] ?? [];
            $logged = 0;

            foreach ($events as $event) {
                $type = $event['type'] ?? 'unknown';
                $severity = $event['severity'] ?? 'medium';
                $metadata = $event['metadata'] ?? [];

                // Log event (handles impact calculation and score update internally)
                logProctoringEvent($session_id, $type, $severity, $metadata);
                logExamAnomaly($user_id, $test_session, $type, json_encode($metadata));
                $logged++;
            }

            echo json_encode([
                'success' => true,
                'logged' => $logged
            ]);
            break;

        case 'update_permissions':
            // Update camera/mic permissions
            if (!$session_id) {
                $session_id = initProctoringSession($user_id, $test_session, $test_format);
            }
            if (!$session_id) {
                throw new Exception('Failed to resolve proctoring session');
            }
            $camera = $input['camera'] ?? ($_POST['camera'] ?? false);
            $microphone = $input['microphone'] ?? ($_POST['microphone'] ?? false);
            
            updateProctoringPermissions($session_id, $camera, $microphone);
            echo json_encode(['success' => true]);
            break;

        case 'upload_video_chunk':
            // Upload video chunk
            if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
                $chunk = file_get_contents($_FILES['video']['tmp_name']);
                $chunkIndex = $input['chunkIndex'] ?? 0;
                $totalChunks = $input['totalChunks'] ?? 1;
                
                saveVideoChunk($session_id, $chunk, $chunkIndex);
                echo json_encode(['success' => true, 'chunk' => $chunkIndex]);
            } else {
                throw new Exception('No video file uploaded');
            }
            break;

        case 'upload_snapshot':
            // Upload snapshot + LLM validation for snapshot-eligible proctoring alerts
            if (!$session_id) throw new Exception('Proctoring session not found');

            $snapshot_data = null;
            if (isset($_FILES['snapshot']) && $_FILES['snapshot']['error'] === UPLOAD_ERR_OK) {
                $snapshot_data = file_get_contents($_FILES['snapshot']['tmp_name']);
            }
            if (!$snapshot_data) throw new Exception('No snapshot uploaded');

            $event_type = $_POST['event_type'] ?? ($input['event_type'] ?? 'snapshot');
            $detector_event_type = $_POST['detector_event_type'] ?? ($input['detector_event_type'] ?? $event_type);
            $detector_severity = $_POST['detector_severity'] ?? ($input['detector_severity'] ?? 'medium');
            $detector_metadata_raw = $_POST['detector_metadata'] ?? ($input['detector_metadata'] ?? []);
            $detector_metadata = is_array($detector_metadata_raw)
                ? $detector_metadata_raw
                : (json_decode((string)$detector_metadata_raw, true) ?: []);

            // Save snapshot to disk + DB
            $imageId = saveSnapshot($session_id, $snapshot_data, $event_type);
            $response = ['success' => true, 'image_id' => $imageId];

            if ($imageId && function_exists('reviewSnapshotViolation')) {
                $snap_stmt = $conn->prepare("SELECT snapshot_path FROM proctoring_events WHERE id = ?");
                if ($snap_stmt) {
                    $snap_stmt->bind_param("i", $imageId);
                    $snap_stmt->execute();
                    $snap_row = $snap_stmt->get_result()->fetch_assoc();
                    $snap_stmt->close();

                    if ($snap_row && $snap_row['snapshot_path']) {
                        $full_path = __DIR__ . '/../' . $snap_row['snapshot_path'];
                        $review = reviewSnapshotViolation(
                            $session_id,
                            $imageId,
                            $full_path,
                            $detector_event_type,
                            $detector_severity,
                            $detector_metadata
                        );

                        $decision = $review['decision'] ?? [];
                        $ai_result = $review['ai_result'] ?? [];

                        $response['ai_verdict'] = $ai_result['verdict'] ?? 'error';
                        $response['ai_detected'] = $ai_result['detected'] ?? [];
                        $response['review_status'] = $decision['review_status'] ?? 'error';
                        $response['review_verdict'] = $decision['review_verdict'] ?? 'error';
                        $response['review_reason'] = $decision['reason'] ?? 'Snapshot review failed';
                        $response['enforced_severity'] = $decision['enforced_severity'] ?? null;
                        $response['score_impact'] = (int)($decision['score_impact'] ?? 0);
                        $response['warn_student'] = ($decision['review_verdict'] ?? '') === 'uncertain' && getSnapshotReviewWarningEnabled();

                        if (($decision['review_verdict'] ?? '') === 'valid_violation') {
                            logExamAnomaly($user_id, $test_session, 'validated_snapshot_violation', json_encode([
                                'snapshot_event_id' => $imageId,
                                'detector_event_type' => $detector_event_type,
                                'reason' => $decision['reason'] ?? '',
                                'detected' => $decision['detected'] ?? [],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        }
                    }
                }
            }

            // Check if termination threshold reached after validated penalty only
            $snap_score = getProctoringSessionScore($session_id);
            $snap_status_stmt = $conn->prepare("SELECT status FROM proctoring_sessions WHERE id = ?");
            $snap_status_stmt->bind_param("i", $session_id);
            $snap_status_stmt->execute();
            $snap_status_row = $snap_status_stmt->get_result()->fetch_assoc();
            $snap_status_stmt->close();

            if (($snap_score && $snap_score['score'] < $integrity_threshold) ||
                ($snap_status_row && $snap_status_row['status'] === 'terminated')) {
                $response['ai_action'] = 'terminate';
            }

            echo json_encode($response);
            break;

        case 'update_score':
            // Update integrity score
            $score = (int)($input['score'] ?? 100);
            $reason = $input['reason'] ?? 'manual_update';
            
            updateIntegrityScore($session_id, $score, $reason);
            echo json_encode(['success' => true, 'score' => $score]);
            break;

        case 'terminate':
            // Terminate session
            $reason = $input['reason'] ?? 'user_ended';
            
            terminateProctoringSession($session_id, $reason);
            echo json_encode(['success' => true]);
            break;

        case 'batch_sync':
            // Batch sync events from client
            if (!$session_id) throw new Exception('Proctoring session not found');
            $eventsJson = $_POST['events'] ?? '[]';
            $events = json_decode($eventsJson, true) ?? [];
            $logged = 0;

            foreach ($events as $event) {
                $type = $event['type'] ?? 'unknown';
                $severity = $event['severity'] ?? 'medium';
                $metadata = $event['metadata'] ?? [];

                // Log event (handles impact calculation and score update internally)
                logProctoringEvent($session_id, $type, $severity, $metadata);
                logExamAnomaly($user_id, $test_session, $type, json_encode($metadata));
                $logged++;
            }

            $sync_response = ['success' => true, 'logged' => $logged];

            // Check termination threshold (score never exposed to client)
            $sync_score = getProctoringSessionScore($session_id);
            $sync_stmt = $conn->prepare("SELECT status FROM proctoring_sessions WHERE id = ?");
            if ($sync_stmt) {
                $sync_stmt->bind_param("i", $session_id);
                $sync_stmt->execute();
                $sync_row = $sync_stmt->get_result()->fetch_assoc();
                $sync_stmt->close();

                if (($sync_score && $sync_score['score'] < $integrity_threshold) ||
                    ($sync_row && $sync_row['status'] === 'terminated')) {
                    $sync_response['ai_action'] = 'terminate';
                }
            }

            echo json_encode($sync_response);
            break;

        case 'heartbeat':
            // Heartbeat to keep session alive
            if (!$session_id) throw new Exception('Proctoring session not found');
            $lastActivity = time();

            updateProctoringHeartbeat($session_id, $lastActivity);

            $hb_response = ['success' => true, 'timestamp' => $lastActivity];

            // Check if session was terminated or score below threshold
            $hb_stmt = $conn->prepare("SELECT status, integrity_score FROM proctoring_sessions WHERE id = ?");
            if ($hb_stmt) {
                $hb_stmt->bind_param("i", $session_id);
                $hb_stmt->execute();
                $hb_row = $hb_stmt->get_result()->fetch_assoc();
                $hb_stmt->close();

                if ($hb_row && ($hb_row['status'] === 'terminated' || (int)$hb_row['integrity_score'] < $integrity_threshold)) {
                    $hb_response['ai_action'] = 'terminate';
                }
            }

            echo json_encode($hb_response);
            break;

        case 'sync_failure':
            if (!$session_id) throw new Exception('Proctoring session not found');

            $status = recordSyncFailure($session_id);
            $failure_response = ['success' => true, 'status' => $status];

            $sync_stmt = $conn->prepare("SELECT status, integrity_score FROM proctoring_sessions WHERE id = ?");
            if ($sync_stmt) {
                $sync_stmt->bind_param("i", $session_id);
                $sync_stmt->execute();
                $sync_row = $sync_stmt->get_result()->fetch_assoc();
                $sync_stmt->close();

                if ($sync_row && ($sync_row['status'] === 'terminated' || (int)$sync_row['integrity_score'] < $integrity_threshold)) {
                    $failure_response['ai_action'] = 'terminate';
                }
            }

            echo json_encode($failure_response);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
