<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';

$test_session = $_GET['session'] ?? '';
// Sanitize
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $test_session)) $test_session = '';

unset($_SESSION['test_session'], $_SESSION['test_session_2026'], $_SESSION['toeic_test_session']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian Dihentikan</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT,WONK@9..144,650..800,35,0&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-frontend.css', '../assets/css/toeic-frontend.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/dark-user.css', 'css/dark-user.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('user/css/mobile-responsive.css', 'css/mobile-responsive.css')); ?>" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #faf6ee 0%, #f5efe2 100%);
            color: var(--toeic-ink);
            font-family: var(--rg-font);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
            padding: 20px;
        }
        .card {
            background: linear-gradient(180deg, rgba(255, 253, 248, 0.98), rgba(252, 248, 240, 0.98));
            padding: 40px;
            border-radius: 28px;
            box-shadow: 0 25px 50px -12px rgba(21,39,66,0.18);
            max-width: 520px;
            width: 100%;
            border: 1px solid rgba(239,68,68,0.2);
        }
        h1 { color: #b42318; font-size: 2rem; margin-bottom: 10px; }
        p { color: var(--toeic-muted); line-height: 1.6; margin-bottom: 16px; }
        .icon { font-size: 3.5rem; margin-bottom: 16px; display: block; }
        .btn-danger {
            background: #ef4444; color: white;
            padding: 12px 24px; text-decoration: none;
            border-radius: 8px; font-weight: bold;
            display: inline-block; margin-top: 8px;
            transition: background 0.2s; border: none; cursor: pointer;
        }
        .btn-danger:hover { background: #dc2626; }
        .btn-success {
            background: #10b981; color: white;
            padding: 12px 24px; text-decoration: none;
            border-radius: 8px; font-weight: bold;
            display: inline-block; margin-top: 8px;
            transition: background 0.2s; border: none; cursor: pointer;
            font-size: 1rem;
        }
        .btn-success:hover { background: #059669; }
        #statusBox {
            background: rgba(21,39,66,0.04);
            border-radius: 10px;
            padding: 14px;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        #statusBox.cleared {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.4);
        }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.5; } }
    </style>
    <link href="<?php echo htmlspecialchars(getVersionedAssetUrl('assets/css/toeic-redesign.css', '../assets/css/toeic-redesign.css')); ?>" rel="stylesheet">
</head>
<body>
    <div class="card disqualified-card">
        <span class="icon">🚫</span>
        <h1>Ujian Dihentikan</h1>
        <p>
            Sesi ujian Anda dihentikan oleh sistem proctoring karena terdeteksi pelanggaran integritas.
        </p>
        <p>
            Insiden ini telah dicatat. Jika Anda merasa ini adalah kesalahan, hubungi administrator.
        </p>

        <?php if ($test_session): ?>
        <div id="statusBox">
            <span class="pulse" id="statusText">
                ⏳ Menunggu tinjauan admin...
            </span>
        </div>
        <div id="resumeSection" style="display:none; margin-top:16px;">
            <p style="color:#10b981; font-weight:bold;">
                ✅ Admin telah mengizinkan Anda melanjutkan ujian.
            </p>
            <a href="test_toeic.php?test_session=<?php echo htmlspecialchars($test_session); ?>&resume=1" class="btn-success">
                Lanjutkan Ujian Sekarang
            </a>
        </div>
        <?php endif; ?>

        <br>
        <a href="index.php" class="btn-danger">Kembali ke Dashboard</a>
    </div>

    <?php if ($test_session): ?>
    <script>
    const TEST_SESSION = <?php echo json_encode($test_session); ?>;
    let pollInterval = null;

    function checkClearance() {
        fetch('ajax_check_proctor_status.php?test_session=' + encodeURIComponent(TEST_SESSION))
            .then(r => r.json())
            .then(data => {
                if (data.cleared) {
                    clearInterval(pollInterval);
                    document.getElementById('statusBox').classList.add('cleared');
                    document.getElementById('statusText').innerHTML = '✅ Status: Diizinkan lanjut (Score: ' + data.integrity_score + ')';
                    document.getElementById('resumeSection').style.display = 'block';
                }
            })
            .catch(() => {});
    }

    // Poll setiap 10 detik
    checkClearance();
    pollInterval = setInterval(checkClearance, 10000);
    </script>
    <?php endif; ?>
</body>
</html>
