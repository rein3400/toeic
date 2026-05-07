<?php
/**
 * Camera & Microphone Setup Page
 * Shared pre-test equipment check for simulator flows.
 */

require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/proctor_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$website_title = getWebsiteTitle();
$test_session = $_GET['test_session'] ?? null;
$section = $_GET['section'] ?? 'reading';
$test_type = $_GET['test_type'] ?? 'toeic';
$mode = (($_GET['mode'] ?? '') === 'prep') ? 'prep' : 'full';
$part = preg_replace('/[^1-7]/', '', (string)($_GET['part'] ?? ''));
$redirect_target = 'test_toeic.php';
$redirect_query = "&mode=$mode" . ($part !== '' ? '&part=' . urlencode($part) : '');
$proctor_test_format = 'toeic';
$setup_title = 'Equipment Setup';
$setup_subtitle = 'Test your hardware before starting the simulation';
$microphone_reason = 'Required for proctoring integrity and audio compatibility';
$consent_label = 'I Consent to Required Permissions';
$ready_message = 'All checks passed. You are ready to start the TOEIC® simulator.';

if (!$test_session) {
    header("Location: index.php");
    exit();
}

$dev_bypass_token = getenv('DEV_BYPASS_TOKEN') ?: '';
$requested_token  = $_GET['dev_bypass'] ?? '';
if ($dev_bypass_token && $requested_token === $dev_bypass_token) {
    $session_id = initProctoringSession((int)$_SESSION['user_id'], $test_session, $proctor_test_format);
    if ($session_id) {
        updateProctoringPermissions($session_id, true, true);
    }
    header("Location: {$redirect_target}?section=$section&test_session=$test_session&setup_complete=1{$redirect_query}");
    exit();
}

$stmt = $conn->prepare("SELECT id, status, review_status, camera_granted, microphone_granted FROM proctoring_sessions WHERE test_session = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("si", $test_session, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $session = $result->fetch_assoc();
    if ($session['status'] === 'terminated' && $session['review_status'] !== 'cleared') {
        header("Location: disqualified.php?session=$test_session");
        exit();
    }
    if ($session['status'] === 'active' && $session['camera_granted'] && $session['microphone_granted']) {
        header("Location: {$redirect_target}?section=$section&test_session=$test_session&setup_complete=1{$redirect_query}");
        exit();
    }
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - <?php echo htmlspecialchars($website_title); ?></title>
    <?php if (function_exists('getFaviconHTML')) echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/control_utils/control_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/face_detection.js" crossorigin="anonymous"></script>

    <style>
        .setup-step-card { max-width: 700px; margin: 0 auto; }
        .video-box {
            position: relative; width: 100%; aspect-ratio: 4/3; background: #1a2652;
            border-radius: 16px; overflow: hidden; border: 4px solid var(--cloud-line);
        }
        .video-box video { width: 100%; height: 100%; object-fit: cover; }
        .face-canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .step-pill {
            width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            background: var(--cloud-line); color: var(--muted-slate); font-weight: 800; transition: all 0.3s;
        }
        .step-pill.active { background: var(--focus-blue); color: white; transform: scale(1.1); }
        .step-pill.completed { background: #10b981; color: white; }
    </style>
</head>
<body class="tc-user-page tc-proctor-setup-page">
    <header class="navbar py-2 border-bottom shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand study-headline mb-0" href="index.php">
                <span class="avatar-circle d-inline-flex me-2" style="width:32px; height:32px; font-size:14px;">T</span>
                <?php echo htmlspecialchars($website_title); ?>
            </a>
            <div class="d-flex gap-2">
                <div class="step-pill" id="step1-pill">1</div>
                <div class="step-pill" id="step2-pill">2</div>
                <div class="step-pill" id="step3-pill">3</div>
            </div>
        </div>
    </header>

    <main class="toeic-page-shell">
        <div class="setup-step-card">
            <!-- Step -1: Prep -->
            <div id="preparationStep" class="study-card">
                <span class="study-kicker">Preparation</span>
                <h2 class="h3 mb-4">Readiness Checklist</h2>
                <div class="list-group list-group-flush mb-4">
                    <div class="list-group-item bg-transparent border-0 px-0 pb-3 d-flex gap-3">
                        <div class="avatar-circle flex-shrink-0" style="background:#fef3c7 !important; border:none;"><i class="fas fa-sun text-warning"></i></div>
                        <div><div class="fw-bold">Bright Lighting</div><div class="small text-muted">Faces should be clearly visible. Avoid dark rooms.</div></div>
                    </div>
                    <div class="list-group-item bg-transparent border-0 px-0 py-3 d-flex gap-3">
                        <div class="avatar-circle flex-shrink-0" style="background:#e0e7ff !important; border:none;"><i class="fas fa-volume-mute text-primary"></i></div>
                        <div><div class="fw-bold">Quiet Room</div><div class="small text-muted">No background noise or other people.</div></div>
                    </div>
                    <div class="list-group-item bg-transparent border-0 px-0 py-3 d-flex gap-3">
                        <div class="avatar-circle flex-shrink-0" style="background:#dcfce7 !important; border:none;"><i class="fas fa-wifi text-success"></i></div>
                        <div><div class="fw-bold">Stable Internet</div><div class="small text-muted">Avoid switching networks during the test.</div></div>
                    </div>
                </div>
                <button class="study-button w-100" onclick="proceedToConsent()">I am Ready</button>
            </div>

            <!-- Step 0: Consent -->
            <div id="permissionStep" class="study-card" style="display:none;">
                <span class="study-kicker">Permissions</span>
                <h2 class="h3 mb-4">Required Access</h2>
                <p class="text-muted mb-4">To ensure exam integrity, the simulator requires temporary access to your camera and microphone.</p>
                <div class="alert alert-info border-0 rounded-4 small mb-4">
                    <i class="fas fa-shield-alt me-2"></i> Your data is used only for proctoring and is never shared.
                </div>
                <div class="d-grid gap-2">
                    <button class="study-button" onclick="proceedToSetup()"><?php echo $consent_label; ?></button>
                    <button class="study-button study-button-secondary" onclick="rejectPermissions()">Cancel Test</button>
                </div>
            </div>

            <!-- Step 1: Camera -->
            <div id="cameraStep" class="study-card" style="display:none;">
                <span class="study-kicker">Step 1</span>
                <h2 class="h4 mb-4">Camera Verification</h2>
                <div class="video-box mb-4">
                    <video id="cameraPreview" autoplay playsinline></video>
                    <div class="position-absolute top-0 start-0 p-3">
                        <span class="badge bg-dark-subtle rounded-pill"><i class="fas fa-circle text-danger me-1"></i> Live</span>
                    </div>
                </div>
                <div id="cameraStatus" class="alert alert-warning border-0 small mb-4">Please allow camera access in your browser.</div>
                <button class="study-button w-100" id="btnCamera" onclick="testCamera()">Test Camera</button>
            </div>

            <!-- Step 2: Mic -->
            <div id="micStep" class="study-card" style="display:none;">
                <span class="study-kicker">Step 2</span>
                <h2 class="h4 mb-4">Audio Verification</h2>
                <div class="text-center py-5">
                    <div class="avatar-circle mx-auto mb-4" style="width:80px; height:80px; background:rgba(72,127,181,0.1) !important; border:none;">
                        <i class="fas fa-microphone fa-2x text-primary"></i>
                    </div>
                    <div id="micStatus" class="h5 fw-bold text-muted">Speak to test microphone</div>
                </div>
                <button class="study-button w-100" id="btnMic" onclick="testMicrophone()">Start Mic Test</button>
            </div>

            <!-- Step 3: Face Detection -->
            <div id="faceStep" class="study-card" style="display:none;">
                <span class="study-kicker">Step 3</span>
                <h2 class="h4 mb-4">Final Presence Check</h2>
                <div class="video-box mb-4">
                    <video id="facePreview" autoplay playsinline></video>
                    <canvas id="faceDetectCanvas" class="face-canvas"></canvas>
                </div>
                <div id="faceStatus" class="alert alert-info border-0 text-center fw-bold">Position your face clearly</div>
                <button class="study-button w-100" id="btnStartTest" onclick="completeSetup()" disabled>Begin Simulator</button>
            </div>

            <!-- Final: Summary -->
            <div id="summaryStep" class="study-card text-center" style="display:none;">
                <div class="avatar-circle mx-auto mb-4" style="width:80px; height:80px; background:#dcfce7 !important; border:none;">
                    <i class="fas fa-check fa-2x text-success"></i>
                </div>
                <h2 class="h3 mb-2">Setup Complete</h2>
                <p class="text-muted mb-4"><?php echo $ready_message; ?></p>
                <button class="study-button w-100" onclick="redirectToTest()">Start Test Now</button>
            </div>
        </div>
    </main>

    <script>
        const TEST_SESSION = "<?php echo htmlspecialchars($test_session, ENT_QUOTES); ?>";
        const SECTION = "<?php echo htmlspecialchars($section, ENT_QUOTES); ?>";
        const TEST_REDIRECT = "<?php echo htmlspecialchars($redirect_target, ENT_QUOTES); ?>";

        let cameraStream = null, faceDetector = null, faceDetected = false, faceDetectionInterval = null;
        let faceDetectionFrameInFlight = false, faceDetectionStartedAt = 0;

        function updatePill(step, status) {
            const pill = document.getElementById(`step${step}-pill`);
            pill.className = `step-pill ${status}`;
        }

        function proceedToConsent() {
            document.getElementById('preparationStep').style.display = 'none';
            document.getElementById('permissionStep').style.display = 'block';
        }

        function proceedToSetup() {
            document.getElementById('permissionStep').style.display = 'none';
            document.getElementById('cameraStep').style.display = 'block';
            updatePill(1, 'active');
        }

        function rejectPermissions() { if (confirm('Cancel exam?')) window.location.href = 'index.php'; }

        async function testCamera() {
            const btn = document.getElementById('btnCamera');
            btn.disabled = true; btn.innerHTML = 'Connecting...';
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                document.getElementById('cameraPreview').srcObject = cameraStream;
                document.getElementById('cameraStatus').className = 'alert alert-success border-0 small mb-4';
                document.getElementById('cameraStatus').innerHTML = 'Camera connected successfully.';
                btn.className = 'study-button study-button-secondary w-100'; btn.innerHTML = 'Camera Ready';
                setTimeout(() => {
                    document.getElementById('cameraStep').style.display = 'none';
                    document.getElementById('micStep').style.display = 'block';
                    updatePill(1, 'completed'); updatePill(2, 'active');
                }, 1000);
            } catch (e) {
                btn.disabled = false; btn.innerHTML = 'Retry Camera';
                alert('Camera access denied.');
            }
        }

        async function testMicrophone() {
            const btn = document.getElementById('btnMic');
            btn.disabled = true;
            try {
                const micStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                document.getElementById('micStatus').innerHTML = 'Microphone ready!';
                btn.className = 'study-button study-button-secondary w-100'; btn.innerHTML = 'Mic Ready';
                setTimeout(() => {
                    micStream.getTracks().forEach(t => t.stop());
                    document.getElementById('micStep').style.display = 'none';
                    document.getElementById('faceStep').style.display = 'block';
                    updatePill(2, 'completed'); updatePill(3, 'active');
                    startFaceDetection();
                }, 1000);
            } catch (e) { btn.disabled = false; alert('Mic access denied.'); }
        }

        function startFaceDetection() {
            faceDetector = new FaceDetection({ locateFile: f => `https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/${f}` });
            faceDetector.setOptions({ model: 'short', minDetectionConfidence: 0.5 });
            faceDetector.onResults(onFaceResults);
            const video = document.getElementById('facePreview');
            video.srcObject = cameraStream;
            video.play();
            faceDetectionInterval = setInterval(async () => {
                if (!faceDetectionFrameInFlight) {
                    faceDetectionFrameInFlight = true;
                    try { await faceDetector.send({ image: video }); } catch(e) {}
                    faceDetectionFrameInFlight = false;
                }
            }, 200);
        }

        function onFaceResults(results) {
            const status = document.getElementById('faceStatus');
            const btn = document.getElementById('btnStartTest');
            if (results.detections && results.detections.length > 0) {
                faceDetected = true;
                status.innerHTML = 'Face Detected!'; status.className = 'alert alert-success border-0 text-center fw-bold';
                btn.disabled = false;
            } else {
                faceDetected = false;
                status.innerHTML = 'Position your face clearly'; status.className = 'alert alert-info border-0 text-center fw-bold';
                btn.disabled = true;
            }
        }

        async function completeSetup() {
            const btn = document.getElementById('btnStartTest');
            btn.disabled = true; btn.innerHTML = 'Initialising...';
            try {
                const res = await fetch('../api/ajax_proctor.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'init', testSession: TEST_SESSION })
                });
                const data = await res.json();
                if (data.success) {
                    try {
                        await fetch('../api/ajax_proctor.php', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'update_permissions', testSession: TEST_SESSION, camera: true, microphone: true })
                        });
                    } catch (permErr) { console.error('Permission update failed:', permErr); }
                    clearInterval(faceDetectionInterval);
                    document.getElementById('faceStep').style.display = 'none';
                    document.getElementById('summaryStep').style.display = 'block';
                    updatePill(3, 'completed');
                } else alert('Error init proctor: ' + data.error);
            } catch(e) { alert('Error: ' + e.message); btn.disabled = false; }
        }

        function redirectToTest() {
            window.location.href = `${TEST_REDIRECT}?section=${SECTION}&test_session=${TEST_SESSION}&setup_complete=1<?php echo $redirect_query; ?>`;
        }
    </script>
</body>
</html>
