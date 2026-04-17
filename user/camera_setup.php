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
$setup_title = 'TOEIC Simulator Equipment Setup';
$setup_subtitle = 'Test your equipment before starting the TOEIC Listening & Reading simulator';
$microphone_reason = 'Required for proctoring integrity checks and listening playback compatibility';
$consent_label = 'I Consent to Required Simulator Permissions';
$ready_message = 'All equipment checks passed. You can now begin the TOEIC Listening & Reading simulator. Please ensure you have adequate lighting and are in a quiet environment.';

// Redirect if no test session
if (!$test_session) {
    header("Location: index.php");
    exit();
}

// DEV BYPASS: Skip camera setup for automated testing
// Only works if DEV_BYPASS_TOKEN env var is set AND token matches
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

// Check if setup already completed
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
        // Setup already completed, redirect to test
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
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($website_title); ?> - Equipment Setup</title>
    <?php if (function_exists('getFaviconHTML')) echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/proctor.css', 'css/proctor.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/dark-user.css', 'css/dark-user.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">

    <!-- MediaPipe -->
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/control_utils/control_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/face_detection.js" crossorigin="anonymous"></script>
    
    <style>
        body {
            background: linear-gradient(180deg, #faf6ee 0%, #f5efe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .setup-container {
            background: linear-gradient(180deg, rgba(255, 253, 248, 0.98), rgba(252, 248, 240, 0.98));
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(21,39,66,0.16);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
            border: 1px solid rgba(23,38,63,0.08);
        }
        
        .setup-header {
            background: linear-gradient(135deg, #152742 0%, #21385c 58%, #c5851c 170%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .setup-body {
            padding: 40px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        
        .step {
            flex: 1;
            text-align: center;
            padding: 15px;
            background: rgba(23,38,63,0.06);
            margin: 0 5px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .step.active {
            background: linear-gradient(135deg, #152742 0%, #21385c 58%, #c5851c 170%);
            color: white;
            transform: scale(1.05);
        }
        
        .step.completed {
            background: #10b981;
            color: white;
        }
        
        .step-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .video-container {
            position: relative;
            width: 100%;
            max-width: 640px;
            margin: 0 auto 30px;
            border-radius: 15px;
            overflow: hidden;
            background: #000;
            aspect-ratio: 4/3;
        }
        
        .video-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .video-overlay {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: bold;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .status-success {
            background: #10b981;
            color: white;
        }
        
        .status-warning {
            background: #f59e0b;
            color: white;
        }
        
        .status-error {
            background: #ef4444;
            color: white;
        }
        
        .checklist {
            background: rgba(23,38,63,0.04);
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .checklist-item {
            display: flex;
            align-items: center;
            padding: 10px;
            margin: 10px 0;
            background: rgba(255,255,255,0.72);
            border-radius: 8px;
        }
        
        .checklist-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .checklist-icon.pending {
            background: rgba(23,38,63,0.08);
            color: #999;
        }
        
        .checklist-icon.success {
            background: #10b981;
            color: white;
        }
        
        .checklist-icon.error {
            background: #ef4444;
            color: white;
        }
        
        .btn-start {
            background: linear-gradient(135deg, #152742 0%, #21385c 58%, #c5851c 170%);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 50px;
            font-size: 18px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-start:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }
        
        .btn-start:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .face-detection-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        
        .alert-box {
            display: none;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .alert-box.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1><i class="fas fa-camera"></i> <?php echo htmlspecialchars($setup_title); ?></h1>
            <p class="mb-0"><?php echo htmlspecialchars($setup_subtitle); ?></p>
        </div>
        
        <div class="setup-body">
            <div class="alert alert-danger d-none" id="secureContextWarning">
                <i class="fas fa-lock me-2"></i>
                <strong>HTTPS required:</strong> Browser only allows camera and microphone access on a secure connection. Open this page with <code>https://</code> on the production domain, or use <code>localhost</code> during local development.
            </div>

            <!-- Step -1: Preparation Checklist -->
            <div id="preparationStep">
                <h3 class="mb-2"><i class="fas fa-clipboard-list me-2" style="color:#667eea;"></i>Persiapan Sebelum Ujian</h3>
                <p class="text-muted mb-4">Pastikan kondisi di bawah ini terpenuhi agar sistem proctoring dapat berjalan dengan baik.</p>

                <div class="checklist">
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background:#f59e0b; color:white;">
                            <i class="fas fa-sun"></i>
                        </div>
                        <div>
                            <strong>Pencahayaan Terang</strong>
                            <div class="text-muted small">Duduklah di ruangan dengan cahaya yang cukup — menghadap sumber cahaya (jendela atau lampu). Ruangan gelap akan menyebabkan sistem tidak dapat mendeteksi wajahmu dan ujian dapat dihentikan secara otomatis.</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background:#8b5cf6; color:white;">
                            <i class="fas fa-volume-mute"></i>
                        </div>
                        <div>
                            <strong>Ruangan Tenang</strong>
                            <div class="text-muted small">Pilih tempat yang sepi dan bebas gangguan. Hindari ruangan dengan kebisingan tinggi selama ujian berlangsung.</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background:#10b981; color:white;">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <strong>Wajah Terlihat Jelas</strong>
                            <div class="text-muted small">Posisikan wajahmu tepat di depan kamera. Jangan memakai topi, kacamata hitam, atau penutup wajah lainnya selama ujian.</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background:#ef4444; color:white;">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div>
                            <strong>Tidak Ada Orang Lain</strong>
                            <div class="text-muted small">Pastikan kamu berada sendiri di ruangan selama ujian. Kehadiran orang lain dapat memicu pelanggaran proctoring.</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background:#3b82f6; color:white;">
                            <i class="fas fa-wifi"></i>
                        </div>
                        <div>
                            <strong>Koneksi Internet Stabil</strong>
                            <div class="text-muted small">Gunakan koneksi internet yang stabil selama ujian. Jangan beralih ke tab/aplikasi lain — hal ini akan dicatat sebagai pelanggaran.</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Penting:</strong> Jika kondisi pencahayaan kurang atau wajah tidak terdeteksi secara konsisten, ujian dapat dihentikan secara otomatis oleh sistem proctoring.
                </div>

                <div class="text-center mt-4">
                    <button class="btn btn-start" onclick="proceedToConsent()">
                        <i class="fas fa-arrow-right me-2"></i>Saya Sudah Siap
                    </button>
                </div>
            </div>

            <!-- Step 0: Permission Consent -->
            <div id="permissionStep" style="display:none;">
                <h3 class="mb-3"><i class="fas fa-shield-alt me-2"></i>Required Permissions</h3>
                <p class="text-muted mb-4">To ensure exam integrity and proper test functionality, we need your permission to access:</p>

                <div class="checklist">
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background: #667eea; color: white;">
                            <i class="fas fa-video"></i>
                        </div>
                        <div>
                            <strong>Camera Access</strong>
                            <div class="text-muted small">Required to verify your identity and monitor test-taking</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background: #667eea; color: white;">
                            <i class="fas fa-microphone"></i>
                        </div>
                        <div>
                            <strong>Microphone Access</strong>
                            <div class="text-muted small"><?php echo htmlspecialchars($microphone_reason); ?></div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon" style="background: #667eea; color: white;">
                            <i class="fas fa-face-grin"></i>
                        </div>
                        <div>
                            <strong>Face Detection</strong>
                            <div class="text-muted small">To verify that you are present during the test</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Privacy Note:</strong> Your camera feed is used only for proctoring during this exam. It will not be stored or shared with third parties.
                </div>

                <div class="text-center mt-4">
                    <button class="btn btn-start" onclick="proceedToSetup()">
                        <i class="fas fa-check me-2"></i><?php echo htmlspecialchars($consent_label); ?>
                    </button>
                    <button class="btn btn-outline-secondary ms-2" onclick="rejectPermissions()">
                        <i class="fas fa-times me-2"></i>Cancel Test
                    </button>
                </div>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator" id="stepIndicator" style="display: none;">
                <div class="step active" id="step1">
                    <div class="step-icon"><i class="fas fa-video"></i></div>
                    <div>Camera</div>
                </div>
                <div class="step" id="step2">
                    <div class="step-icon"><i class="fas fa-microphone"></i></div>
                    <div>Microphone</div>
                </div>
                <div class="step" id="step3">
                    <div class="step-icon"><i class="fas fa-user"></i></div>
                    <div>Face Check</div>
                </div>
            </div>
            
            <!-- Step 1: Camera Test -->
            <div id="cameraStep">
                <h3 class="mb-3">Step 1: Camera Test</h3>
                <p class="text-muted">Please allow camera access when prompted</p>
                
                <div class="video-container">
                    <video id="cameraPreview" autoplay playsinline></video>
                    <canvas id="faceCanvas" class="face-detection-canvas"></canvas>
                    <div class="video-overlay">
                        <i class="fas fa-circle text-success"></i> Camera Preview
                    </div>
                </div>
                
                <div class="checklist">
                    <div class="checklist-item">
                        <div class="checklist-icon pending" id="cameraAccessIcon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div>
                            <strong>Camera Access</strong>
                            <div class="text-muted small">Grant camera permission</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon pending" id="cameraWorkingIcon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div>
                            <strong>Camera Working</strong>
                            <div class="text-muted small">Camera is functioning properly</div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button class="btn btn-start" id="btnCamera" onclick="testCamera()">
                        <i class="fas fa-play me-2"></i>Start Camera Test
                    </button>
                </div>
            </div>
            
            <!-- Step 2: Microphone Test -->
            <div id="micStep" style="display: none;">
                <h3 class="mb-3">Step 2: Microphone Test</h3>
                <p class="text-muted">Please allow microphone access when prompted</p>
                
                <div class="text-center py-5">
                    <i class="fas fa-microphone fa-5x text-primary mb-4"></i>
                    <div id="micStatus" class="status-badge status-warning">
                        <i class="fas fa-hourglass-half"></i> Waiting for permission...
                    </div>
                </div>
                
                <div class="checklist">
                    <div class="checklist-item">
                        <div class="checklist-icon pending" id="micAccessIcon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div>
                            <strong>Microphone Access</strong>
                            <div class="text-muted small">Grant microphone permission</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon pending" id="micWorkingIcon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div>
                            <strong>Microphone Working</strong>
                            <div class="text-muted small">Speak to test microphone</div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button class="btn btn-start" id="btnMic" onclick="testMicrophone()" disabled style="opacity: 0.6; cursor: not-allowed;">
                        <i class="fas fa-play me-2"></i>Start Microphone Test
                    </button>
                </div>
            </div>
            
            <!-- Step 3: Face Detection Test -->
            <div id="faceStep" style="display: none;">
                <h3 class="mb-3">Step 3: Face Detection Test</h3>
                <p class="text-muted">Position your face in front of the camera</p>

                <div class="video-container">
                    <video id="facePreview" autoplay playsinline></video>
                    <canvas id="faceDetectCanvas" class="face-detection-canvas"></canvas>
                    <div class="video-overlay">
                        <i class="fas fa-user"></i> <span id="faceStatus">Detecting face...</span>
                    </div>
                </div>

                <div class="alert-box alert-success" id="faceSuccessAlert">
                    <i class="fas fa-check-circle"></i> Face detected successfully! You can proceed with the test.
                </div>

                <div class="alert-box alert-danger" id="faceFailAlert">
                    <i class="fas fa-exclamation-triangle"></i> No face detected. Please ensure good lighting and position your face clearly in front of the camera.
                </div>

                <div class="text-center mt-4">
                    <button class="btn btn-start" id="btnStartTest" onclick="completeSetup()" disabled style="opacity: 0.6; cursor: not-allowed; pointer-events: none;">
                        <i class="fas fa-check me-2"></i>Complete Setup & Start Test
                    </button>
                </div>

                <div class="text-center mt-3">
                    <a href="<?php echo htmlspecialchars($redirect_target); ?>?section=<?php echo htmlspecialchars($section); ?>&test_session=<?php echo htmlspecialchars($test_session); ?>&setup_complete=1<?php echo htmlspecialchars($redirect_query, ENT_QUOTES); ?>&skip_proctoring=1"
                       class="btn btn-outline-secondary btn-sm"
                       id="btnSkipProctoring" style="display:none;">
                        <i class="fas fa-forward me-1"></i>Lewati Proctoring — Test Tanpa Kamera
                    </a>
                </div>
            </div>

            <!-- Summary: All Permissions Granted -->
            <div id="summaryStep" style="display: none;">
                <h3 class="mb-4 text-center"><i class="fas fa-check-circle text-success me-2"></i>Setup Complete</h3>

                <div class="checklist">
                    <div class="checklist-item">
                        <div class="checklist-icon success">
                            <i class="fas fa-video"></i>
                        </div>
                        <div>
                            <strong>Camera</strong>
                            <div class="text-muted small">Permission granted and working</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon success">
                            <i class="fas fa-microphone"></i>
                        </div>
                        <div>
                            <strong>Microphone</strong>
                            <div class="text-muted small">Permission granted and working</div>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <div class="checklist-icon success">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <strong>Face Detection</strong>
                            <div class="text-muted small">Face verified and ready</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-success mt-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Ready to start:</strong> <?php echo htmlspecialchars($ready_message); ?>
                </div>

                <div class="text-center mt-4">
                    <button class="btn btn-start" onclick="redirectToTest()">
                        <i class="fas fa-play me-2"></i>Start Test
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const TEST_SESSION = "<?php echo htmlspecialchars($test_session, ENT_QUOTES); ?>";
        const SECTION = "<?php echo htmlspecialchars($section, ENT_QUOTES); ?>";
        const TEST_REDIRECT = "<?php echo htmlspecialchars($redirect_target, ENT_QUOTES); ?>";

        let cameraStream = null;
        let faceDetector = null;
        let faceDetected = false;
        let faceDetectionInterval = null;
        let faceDetectionState = 'idle';
        let faceDetectionStartedAt = 0;
        let faceDetectionFrameInFlight = false;
        let permissionsConsented = false;

        function isLocalDevelopmentHost() {
            const host = window.location.hostname;
            return host === 'localhost' || host === '127.0.0.1' || host === '::1';
        }

        function requiresSecureContextForMedia() {
            return !window.isSecureContext && !isLocalDevelopmentHost();
        }

        function getInsecureContextMessage(mediaLabel) {
            return `${mediaLabel} is blocked because this page is running on an insecure connection (${window.location.protocol}//${window.location.host}). Open the TOEIC site with HTTPS first, then try again.`;
        }

        function showSecureContextWarning() {
            const warning = document.getElementById('secureContextWarning');
            if (warning) {
                warning.classList.remove('d-none');
            }
        }

        // Step -1: Preparation → Consent
        function proceedToConsent() {
            document.getElementById('preparationStep').style.display = 'none';
            document.getElementById('permissionStep').style.display = 'block';
        }

        // Step 0: Permission Consent
        function proceedToSetup() {
            permissionsConsented = true;
            document.getElementById('permissionStep').style.display = 'none';
            document.getElementById('stepIndicator').style.display = 'flex';
            document.getElementById('cameraStep').style.display = 'block';

            if (requiresSecureContextForMedia()) {
                showSecureContextWarning();
            }
        }

        function rejectPermissions() {
            if (confirm('Are you sure you want to cancel this exam? You will not be able to take the test without granting required permissions.')) {
                window.location.href = 'index.php';
            }
        }

        function setStartTestButtonEnabled(enabled) {
            const btnStartTest = document.getElementById('btnStartTest');
            btnStartTest.disabled = !enabled;
            btnStartTest.style.opacity = enabled ? '1' : '0.6';
            btnStartTest.style.cursor = enabled ? 'pointer' : 'not-allowed';
            btnStartTest.style.pointerEvents = enabled ? 'auto' : 'none';
            btnStartTest.classList.toggle('disabled', !enabled);
        }

        function updateFaceDetectionUi(state, message) {
            const faceStatus = document.getElementById('faceStatus');
            const faceSuccessAlert = document.getElementById('faceSuccessAlert');
            const faceFailAlert = document.getElementById('faceFailAlert');

            faceDetectionState = state;

            switch (state) {
                case 'detected':
                    faceDetected = true;
                    faceStatus.innerHTML = '<i class="fas fa-check-circle text-success"></i> ' + (message || 'Face detected!');
                    faceSuccessAlert.classList.add('show');
                    faceFailAlert.classList.remove('show');
                    setStartTestButtonEnabled(true);
                    break;
                case 'detector_error':
                    faceDetected = false;
                    faceStatus.innerHTML = '<i class="fas fa-triangle-exclamation text-danger"></i> Face detector unavailable';
                    faceSuccessAlert.classList.remove('show');
                    faceFailAlert.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (message || 'Face detector could not be started. Check your connection and retry.');
                    faceFailAlert.classList.add('show');
                    setStartTestButtonEnabled(false);
                    break;
                case 'not_found':
                    faceDetected = false;
                    faceStatus.innerHTML = '<i class="fas fa-search"></i> Detecting face...';
                    faceSuccessAlert.classList.remove('show');
                    faceFailAlert.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (message || 'No face detected. Please ensure good lighting and position your face clearly in front of the camera.');
                    faceFailAlert.classList.add('show');
                    setStartTestButtonEnabled(false);
                    break;
                default:
                    faceDetected = false;
                    faceStatus.innerHTML = '<i class="fas fa-search"></i> ' + (message || 'Detecting face...');
                    faceSuccessAlert.classList.remove('show');
                    faceFailAlert.classList.remove('show');
                    setStartTestButtonEnabled(false);
                    break;
            }
        }

        function stopFaceDetectionLoop() {
            if (faceDetectionInterval) {
                clearInterval(faceDetectionInterval);
                faceDetectionInterval = null;
            }
            faceDetectionFrameInFlight = false;
        }
        
        // Initialize Face Detector
        function initFaceDetector() {
            if (faceDetector) {
                return faceDetector;
            }

            try {
                faceDetector = new FaceDetection({
                    locateFile: (file) => {
                        return `https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/${file}`;
                    }
                });

                faceDetector.setOptions({
                    model: 'short',
                    minDetectionConfidence: 0.5
                });

                faceDetector.onResults(onFaceResults);
                return faceDetector;
            } catch (error) {
                console.error('Face detector initialization error:', error);
                updateFaceDetectionUi('detector_error', 'Face detector could not be initialized. Check your internet connection and retry.');
                return null;
            }
        }
        
        function onFaceResults(results) {
            const canvas = document.getElementById('faceDetectCanvas');
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const detections = Array.isArray(results && results.detections) ? results.detections : [];

            if (detections.length > 0) {
                console.log('Face detected! Enabling button...');
                updateFaceDetectionUi('detected', 'Face detected!');

                // Draw bounding box
                const detection = detections[0];
                const bbox = detection.boundingBox;

                ctx.strokeStyle = '#10b981';
                ctx.lineWidth = 4;
                ctx.strokeRect(bbox.xMin, bbox.yMin, bbox.width, bbox.height);
            } else {
                const elapsed = Date.now() - faceDetectionStartedAt;
                if (elapsed < 3000) {
                    updateFaceDetectionUi('detecting', 'Detecting face...');
                } else {
                    console.log('No face detected. Disabling button...');
                    updateFaceDetectionUi('not_found', 'No face detected. Please ensure good lighting and position your face clearly in front of the camera.');
                }
            }
        }
        
        // Step 1: Test Camera
        async function testCamera() {
            const btn = document.getElementById('btnCamera');
            if (requiresSecureContextForMedia()) {
                showSecureContextWarning();
                alert(getInsecureContextMessage('Camera access'));
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Starting camera...';
            
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'user'
                    },
                    audio: false
                });
                
                const video = document.getElementById('cameraPreview');
                video.srcObject = cameraStream;
                
                await video.play();
                
                // Update UI
                document.getElementById('cameraAccessIcon').className = 'checklist-icon success';
                document.getElementById('cameraAccessIcon').innerHTML = '<i class="fas fa-check"></i>';
                
                document.getElementById('cameraWorkingIcon').className = 'checklist-icon success';
                document.getElementById('cameraWorkingIcon').innerHTML = '<i class="fas fa-check"></i>';
                
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Camera Ready';
                btn.className = 'btn btn-success';
                
                // Move to next step after 1.5s
                setTimeout(() => {
                    console.log('Moving to microphone step...');
                    document.getElementById('cameraStep').style.display = 'none';
                    document.getElementById('micStep').style.display = 'block';
                    document.getElementById('step1').classList.add('completed');
                    document.getElementById('step2').classList.add('active');

                    // Enable microphone button
                    const btnMic = document.getElementById('btnMic');
                    console.log('Enabling microphone button...', btnMic);
                    btnMic.disabled = false;
                    btnMic.style.cursor = 'pointer';
                    btnMic.style.opacity = '1';
                    btnMic.classList.remove('disabled');
                    btnMic.style.pointerEvents = 'auto';
                    console.log('Microphone button enabled', btnMic.disabled);
                }, 1500);
                
            } catch (error) {
                console.error('Camera error:', error);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-redo me-2"></i>Retry Camera Test';

                document.getElementById('cameraAccessIcon').className = 'checklist-icon error';
                document.getElementById('cameraAccessIcon').innerHTML = '<i class="fas fa-times"></i>';

                let errorMsg = 'Camera access denied or not available.';
                if (requiresSecureContextForMedia()) {
                    showSecureContextWarning();
                    errorMsg = getInsecureContextMessage('Camera access');
                } else if (error.name === 'NotAllowedError') {
                    errorMsg = 'Camera permission was denied. Please go to your browser settings, allow camera access for this site, and try again.';
                } else if (error.name === 'NotFoundError') {
                    errorMsg = 'No camera device found. Please connect a camera and try again.';
                } else if (error.name === 'NotReadableError') {
                    errorMsg = 'Camera is already in use by another application. Please close other apps using the camera and try again.';
                }

                alert(errorMsg);
            }
        }
        
        // Step 2: Test Microphone
        async function testMicrophone() {
            const btn = document.getElementById('btnMic');
            if (requiresSecureContextForMedia()) {
                showSecureContextWarning();
                alert(getInsecureContextMessage('Microphone access'));
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Requesting microphone access...';

            // First, request ONLY microphone permission explicitly
            try {
                // Request audio FIRST to get permission prompt
                console.log('Requesting microphone permission...');
                const micStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    },
                    video: false  // Explicitly no video
                });

                console.log('Microphone permission granted');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Testing microphone...';

                // Now test the microphone
                await testMicrophoneAudio(micStream, btn);

            } catch (error) {
                console.error('Microphone error:', error);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-redo me-2"></i>Retry Microphone Test';

                document.getElementById('micAccessIcon').className = 'checklist-icon error';
                document.getElementById('micAccessIcon').innerHTML = '<i class="fas fa-times"></i>';

                let errorMsg = 'Microphone access denied or not available.';
                if (requiresSecureContextForMedia()) {
                    showSecureContextWarning();
                    errorMsg = getInsecureContextMessage('Microphone access');
                } else if (error.name === 'NotAllowedError') {
                    errorMsg = 'Microphone permission was denied. Please go to your browser settings, allow microphone access for this site, and try again.';
                } else if (error.name === 'NotFoundError') {
                    errorMsg = 'No microphone device found. Please connect a microphone and try again.';
                } else if (error.name === 'NotReadableError') {
                    errorMsg = 'Microphone is already in use by another application. Please close other apps using the microphone and try again.';
                }

                alert(errorMsg);
            }
        }

        // Test microphone audio after permission granted
        async function testMicrophoneAudio(micStream, btn) {
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const analyser = audioContext.createAnalyser();
                const microphone = audioContext.createMediaStreamSource(micStream);
                const scriptProcessor = audioContext.createScriptProcessor(2048, 1, 1);
                
                analyser.smoothingTimeConstant = 0.8;
                analyser.fftSize = 1024;
                
                microphone.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(audioContext.destination);
                
                let silenceCounter = 0;
                let soundDetected = false;
                let testTimeout = null;

                scriptProcessor.onaudioprocess = function() {
                    const array = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(array);

                    let values = 0;
                    const length = array.length;

                    for (let i = 0; i < length; i++) {
                        values += array[i];
                    }

                    const average = values / length;

                    if (average > 10) {
                        soundDetected = true;
                        document.getElementById('micStatus').innerHTML = '<i class="fas fa-volume-up text-success"></i> Sound detected!';
                        document.getElementById('micStatus').className = 'status-badge status-success';

                        if (testTimeout) clearTimeout(testTimeout);
                        completeMicrophoneTest(micStream, scriptProcessor, btn);
                    } else {
                        silenceCounter++;
                        if (silenceCounter > 50 && !soundDetected) {
                            document.getElementById('micStatus').innerHTML = '<i class="fas fa-volume-mute"></i> Listening... (Please speak into microphone)';
                            document.getElementById('micStatus').className = 'status-badge status-warning';
                        }
                    }
                };

                // Auto-complete after 10 seconds even without detected sound
                testTimeout = setTimeout(() => {
                    if (!soundDetected) {
                        document.getElementById('micStatus').innerHTML = '<i class="fas fa-check text-success"></i> Microphone is working!';
                        document.getElementById('micStatus').className = 'status-badge status-success';
                        completeMicrophoneTest(micStream, scriptProcessor, btn);
                    }
                }, 10000);
                
            } catch (error) {
                console.error('Audio processing error:', error);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-redo me-2"></i>Retry Microphone Test';

                document.getElementById('micAccessIcon').className = 'checklist-icon error';
                document.getElementById('micAccessIcon').innerHTML = '<i class="fas fa-times"></i>';

                alert('Error testing microphone. Please try again.');
            }
        }

        // Complete microphone test and move to next step
        function completeMicrophoneTest(micStream, scriptProcessor, btn) {
            micStream.getTracks().forEach(track => track.stop());
            scriptProcessor.disconnect();

            document.getElementById('micAccessIcon').className = 'checklist-icon success';
            document.getElementById('micAccessIcon').innerHTML = '<i class="fas fa-check"></i>';

            document.getElementById('micWorkingIcon').className = 'checklist-icon success';
            document.getElementById('micWorkingIcon').innerHTML = '<i class="fas fa-check"></i>';

            btn.innerHTML = '<i class="fas fa-check me-2"></i>Microphone Ready';
            btn.className = 'btn btn-success';

            // Move to next step
            setTimeout(() => {
                document.getElementById('micStep').style.display = 'none';
                document.getElementById('faceStep').style.display = 'block';
                document.getElementById('step2').classList.add('completed');
                document.getElementById('step3').classList.add('active');

                // Start face detection
                startFaceDetection();
            }, 1500);
        }

        // Step 3: Face Detection - Reuse camera stream
        function startFaceDetection() {
            if (requiresSecureContextForMedia()) {
                showSecureContextWarning();
                updateFaceDetectionUi('detector_error', getInsecureContextMessage('Face detection'));
                return;
            }

            const detector = initFaceDetector();
            if (!detector) {
                return;
            }

            const video = document.getElementById('facePreview');
            const canvas = document.getElementById('faceDetectCanvas');
            video.muted = true;
            video.playsInline = true;
            faceDetectionStartedAt = Date.now();
            updateFaceDetectionUi('detecting', 'Detecting face...');
            stopFaceDetectionLoop();

            // Set canvas size
            canvas.width = 640;
            canvas.height = 480;

            // Try to reuse existing camera stream first
            if (cameraStream && cameraStream.active) {
                video.srcObject = cameraStream;
                startFaceDetectionLoop(video);
            } else {
                // If no existing stream, request new one
                navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    }
                }).then(stream => {
                    cameraStream = stream;
                    video.srcObject = stream;
                    startFaceDetectionLoop(video);
                }).catch(error => {
                    console.error('Face detection error:', error);
                    updateFaceDetectionUi('detector_error', 'Could not start face detection. Please ensure the camera is available and try again.');
                    if (error.name === 'NotAllowedError') {
                        alert('Camera access was denied. Please check your browser permissions and try again.');
                    } else {
                        alert('Could not start face detection. Please ensure camera is working.');
                    }
                });
            }
        }

        function startFaceDetectionLoop(video) {
            function beginLoop() {
                if (faceDetectionInterval) {
                    return;
                }

                // Send frames to face detector every 200ms
                faceDetectionInterval = setInterval(async () => {
                    if (!video.paused && !video.ended) {
                        if (faceDetectionFrameInFlight) {
                            return;
                        }

                        if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || video.videoWidth === 0 || video.videoHeight === 0) {
                            return;
                        }

                        faceDetectionFrameInFlight = true;
                        try {
                            await faceDetector.send({ image: video });
                        } catch (error) {
                            console.error('Face detection frame error:', error);
                            updateFaceDetectionUi('detector_error', 'Face detector lost access to the video stream. Please retry the setup.');
                            stopFaceDetectionLoop();
                        } finally {
                            faceDetectionFrameInFlight = false;
                        }
                    }
                }, 200);
            }

            // If video is already playing, start immediately
            if (!video.paused && video.readyState >= 2) {
                beginLoop();
            } else {
                // Wait for video to start playing
                video.addEventListener('playing', beginLoop, { once: true });
                // Also try to play it
                video.play().catch(() => {});
            }
        }
        
        // Save permissions to database
        async function savePermissions(permissions) {
            try {
                const response = await fetch('../api/ajax_proctor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_permissions',
                        testSession: TEST_SESSION,
                        camera: permissions.camera,
                        microphone: permissions.microphone
                    })
                });

                if (!response.ok) {
                    console.warn('Failed to persist permissions. HTTP status:', response.status);
                    return false;
                }

                return true;
            } catch (error) {
                console.error('Failed to save permissions:', error);
                return false;
            }
        }
        
        // Complete setup and show summary
        async function completeSetup() {
            if (!faceDetected) {
                alert('Please ensure your face is clearly visible before proceeding.');
                return;
            }

            let initSucceeded = false;

            try {
                console.log('Completing setup...');
                const btn = document.getElementById('btnStartTest');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';

                // Initialize proctoring session
                const response = await fetch('../api/ajax_proctor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'init',
                        testSession: TEST_SESSION
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    console.error('Setup failed:', result);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check me-2"></i>Complete Setup & Start Test';
                    const errorMsg = result.error || 'Unknown error';
                    alert('Failed to initialize proctoring session.\n\nError: ' + errorMsg + '\n\nPlease try again or contact support.');
                    return;
                }

                initSucceeded = true;

                const permissionsSaved = await savePermissions({ camera: true, microphone: true });
                if (!permissionsSaved) {
                    console.warn('Permissions update did not complete cleanly; continuing with redirect fallback.');
                }

                stopFaceDetectionLoop();
                console.log('Setup completed successfully');

                const faceStep = document.getElementById('faceStep');
                const summaryStep = document.getElementById('summaryStep');
                const step3 = document.getElementById('step3');

                if (!faceStep || !summaryStep || !step3) {
                    console.warn('Setup summary DOM is incomplete. Redirecting directly to the TOEIC test.');
                    redirectToTest();
                    return;
                }

                faceStep.style.display = 'none';
                step3.classList.add('completed');
                summaryStep.style.display = 'block';

                window.setTimeout(() => {
                    if (summaryStep.style.display === 'block') {
                        redirectToTest();
                    }
                }, 1200);
            } catch (error) {
                console.error('Setup error:', error);

                if (initSucceeded) {
                    console.warn('Initialization succeeded before the UI failed. Falling back to direct TOEIC redirect.');
                    redirectToTest();
                    return;
                }

                const btn = document.getElementById('btnStartTest');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Complete Setup & Start Test';
                const errorMessage = (error && error.message) ? error.message : 'Please try again.';
                alert('Error completing setup.\n\n' + errorMessage);
            }
        }

        // Redirect to test from summary
        function redirectToTest() {
            window.location.href = `${TEST_REDIRECT}?section=${SECTION}&test_session=${TEST_SESSION}&setup_complete=1<?php echo $redirect_query; ?>`;
        }
        
        // Auto-enable microphone button after camera test
        function enableMicButton() {
            document.getElementById('btnMic').disabled = false;
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (requiresSecureContextForMedia()) {
                showSecureContextWarning();
            }
        });
    </script>
</body>
</html>
