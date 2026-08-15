<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/toeic_helper.php';
require_once __DIR__ . '/includes/toeic_sw_helper.php';
require_once __DIR__ . '/includes/db_utils.php';
require_once __DIR__ . '/includes/CertificateAccess.php';

$website_title = getWebsiteTitle();
$uid = getUsersIdColumn($conn);

$search_name = trim($_GET['certificate'] ?? $_POST['certificate'] ?? '');
$session = trim($_GET['session'] ?? $search_name);
$result = null;
$domain = null;
$level = null;

if ($session) {
    // Try R+L first, then S+W. Both share the public session namespace but live
    // in separate result tables, so fall back instead of failing.
    $stmt = $conn->prepare("
        SELECT tr.*, u.full_name
        FROM toeic_test_results tr
        JOIN users u ON tr.user_id = u.{$uid}
        WHERE tr.test_session = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $session);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($result) {
            $domain = 'rl';
        }
    }

    if (!$result) {
        ensureToeicSwSchema($conn);

        $stmt = $conn->prepare("
            SELECT tr.*, u.full_name
            FROM toeic_sw_test_results tr
            JOIN users u ON tr.user_id = u.{$uid}
            WHERE tr.test_session = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $session);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($result) {
                $domain = 'sw';
            }
        }
    }

    if ($result) {
        $certificateAccess = getCertificateAccessState($result, $result['status'] ?? null, $domain);
        if ($certificateAccess['allowed']) {
            if ($domain === 'sw') {
                $level = getToeicSwLevel((int)$result['total_score']);
            } else {
                $level = getTOEICScoreLevel((int)$result['total_score']);
            }
        } else {
            $result = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate - <?php echo htmlspecialchars($website_title); ?></title>
    <meta name="description" content="Verify a TOEIC practice certificate using its unique certificate ID.">
    <?php if (function_exists('getFaviconHTML')) echo getFaviconHTML(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f0f4ff;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }
        .verify-hero {
            background: linear-gradient(135deg, #0a2540 0%, #1e3a5f 50%, #1e63d6 100%);
            padding: 3rem 1.5rem 5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .verify-hero::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 40px;
            background: #f0f4ff;
            border-radius: 50% 50% 0 0 / 100% 100% 0 0;
        }
        .verify-hero .hero-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            font-size: 1.75rem;
            color: #fff;
        }
        .verify-hero h1 {
            font-size: 1.85rem;
            font-weight: 800;
            color: #fff;
            margin: 0 0 0.5rem;
            letter-spacing: -0.025em;
        }
        .verify-hero p {
            color: rgba(255,255,255,0.7);
            font-size: 0.95rem;
            margin: 0;
            max-width: 440px;
            margin-inline: auto;
            line-height: 1.5;
        }
        .verify-container {
            max-width: 700px;
            margin: -2.5rem auto 0;
            padding: 0 1.25rem 3rem;
            position: relative;
            z-index: 2;
        }
        .verify-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 32px rgba(0,0,0,0.06), 0 1px 4px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .card-body {
            padding: 2rem 2.25rem 2.25rem;
        }
        .search-section {
            padding: 2rem 2.25rem 0;
        }
        .search-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        .search-form {
            display: flex;
            gap: 0.6rem;
            align-items: stretch;
        }
        .search-input-wrapper {
            flex: 1;
            position: relative;
        }
        .search-input-wrapper .search-icon-inside {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.85rem;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            color: #1f2937;
            background: #f8fafc;
            outline: none;
            transition: all 0.25s ease;
        }
        .search-input:focus {
            border-color: #1e63d6;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(30,99,214,0.08);
        }
        .search-btn {
            padding: 0.85rem 1.6rem;
            background: linear-gradient(135deg, #1e63d6, #3b82f6);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-weight: 600;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .verified-banner {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            padding: 0.85rem 2.25rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border-bottom: 1px solid #a7f3d0;
        }
        .verified-banner.error-banner {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-bottom-color: #fca5a5;
        }
        .verified-badge {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #16a34a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .verified-badge.error-badge { background: #dc2626; }
        .verified-text { font-size: 0.82rem; font-weight: 600; color: #166534; }
        .verified-text.error-text { color: #991b1b; }
        .direct-result { text-align: center; padding: 0.5rem 0 0; }
        .result-user-name {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1f2937;
            margin: 0 0 0.15rem;
            letter-spacing: -0.015em;
        }
        .result-user-sub { font-size: 0.85rem; color: #64748b; margin: 0 0 1.5rem; }
        .score-ring-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .score-ring-svg {
            width: 150px;
            height: 150px;
            transform: rotate(-90deg);
            filter: drop-shadow(0 2px 8px rgba(30,99,214,0.15));
        }
        .score-ring-bg { fill: none; stroke: #e2e8f0; stroke-width: 8; }
        .score-ring-progress {
            fill: none;
            stroke: #1e63d6;
            stroke-width: 8;
            stroke-linecap: round;
            stroke-dasharray: 377;
            stroke-dashoffset: 377;
        }
        .score-ring-center { position: absolute; text-align: center; }
        .score-ring-center .score-value {
            font-size: 2.6rem;
            font-weight: 900;
            color: #1e63d6;
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .score-ring-center .score-label {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .result-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .detail-card {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 1rem;
            text-align: center;
        }
        .detail-card .detail-label {
            font-size: 0.68rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.35rem;
        }
        .detail-card .detail-value {
            font-size: 0.92rem;
            font-weight: 700;
            color: #1f2937;
        }
        .level-badge {
            display: inline-block;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 0.35rem 1.1rem;
            border-radius: 999px;
            background: #eff6ff;
            color: #1e63d6;
        }
        .session-stamp {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.72rem;
            color: #94a3b8;
            background: #f1f5f9;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-family: 'Consolas', monospace;
            border: 1px solid #e2e8f0;
        }
        .session-stamp i { font-size: 0.62rem; color: #16a34a; }
        .disclaimer-bar {
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            padding: 1rem 2.25rem;
        }
        .disclaimer-text {
            font-size: 0.72rem;
            color: #94a3b8;
            line-height: 1.6;
            text-align: center;
            margin: 0;
        }
        .empty-state { text-align: center; padding: 2.5rem 1.5rem; }
        .empty-state .empty-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.15rem;
            color: #cbd5e1;
            font-size: 1.6rem;
        }
        .empty-state h4 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 0.4rem;
        }
        .empty-state p {
            font-size: 0.88rem;
            color: #64748b;
            margin: 0;
            line-height: 1.5;
        }
        .verify-footer { text-align: center; padding: 1.75rem 0 0; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: #64748b;
            font-size: 0.88rem;
            font-weight: 500;
            text-decoration: none;
            padding: 0.55rem 1.1rem;
            border-radius: 12px;
            border: 1px solid transparent;
        }
        .back-link:hover { color: #1e63d6; background: #eff6ff; border-color: #dbeafe; }
        @media (max-width: 640px) {
            .verify-hero { padding: 2.25rem 1.25rem 4rem; }
            .verify-hero h1 { font-size: 1.4rem; }
            .verify-container { padding: 0 0.85rem 2rem; }
            .card-body, .search-section, .verified-banner, .disclaimer-bar {
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }
            .search-form { flex-direction: column; }
            .search-btn { justify-content: center; padding: 0.9rem; }
            .result-details { grid-template-columns: 1fr; }
            .result-user-name { font-size: 1.15rem; }
            .score-ring-svg { width: 130px; height: 130px; }
            .score-ring-center .score-value { font-size: 2.2rem; }
        }
    </style>
</head>
<body>
    <div class="verify-hero">
        <div class="hero-icon">
            <?php if ($session && $result): ?>
                <i class="fas fa-shield-halved"></i>
            <?php elseif ($session && !$result): ?>
                <i class="fas fa-triangle-exclamation"></i>
            <?php else: ?>
                <i class="fas fa-magnifying-glass-chart"></i>
            <?php endif; ?>
        </div>
        <h1>
            <?php if ($session && $result): ?>
                Result Verified
            <?php elseif ($session && !$result): ?>
                Verification Failed
            <?php else: ?>
                Verify TOEIC Certificate
            <?php endif; ?>
        </h1>
        <p>
            <?php if ($session && $result): ?>
                This practice assessment result has been verified as authentic.
            <?php elseif ($session && !$result): ?>
                The requested certificate could not be found in our records.
            <?php else: ?>
                Enter the certificate ID or scan its QR code to verify the result.
            <?php endif; ?>
        </p>
    </div>

    <div class="verify-container">
        <?php if ($session && $result): ?>
            <div class="verify-card">
                <div class="verified-banner">
                    <div class="verified-badge"><i class="fas fa-check"></i></div>
                    <span class="verified-text">Verified Practice Assessment Result</span>
                </div>
                <div class="card-body">
                    <div class="direct-result">
                        <h2 class="result-user-name"><?php echo htmlspecialchars($result['full_name']); ?></h2>
                        <p class="result-user-sub"><?php echo $domain === 'sw' ? 'TOEIC Speaking &amp; Writing' : 'TOEIC Listening &amp; Reading'; ?> Certificate</p>

                        <?php
                            $total = (int)$result['total_score'];
                            $max = $domain === 'sw' ? 400 : 990;
                            $percent = min(100, max(0, ($total / $max) * 100));
                            $circumference = 2 * 3.14159 * 60;
                            $offset = $circumference - ($percent / 100) * $circumference;
                        ?>
                        <div class="score-ring-wrapper">
                            <svg class="score-ring-svg" viewBox="0 0 140 140">
                                <circle class="score-ring-bg" cx="70" cy="70" r="60" />
                                <circle class="score-ring-progress" cx="70" cy="70" r="60"
                                    style="stroke-dashoffset: <?php echo round($offset); ?>;" />
                            </svg>
                            <div class="score-ring-center">
                                <div class="score-value"><?php echo $total; ?></div>
                                <div class="score-label">of <?php echo $max; ?></div>
                            </div>
                        </div>

                        <div style="margin-bottom: 1.5rem;">
                            <span class="level-badge"><?php echo htmlspecialchars($level[0]); ?> &mdash; <?php echo htmlspecialchars($level[1]); ?></span>
                        </div>

                        <div class="result-details">
                            <div class="detail-card">
                                <div class="detail-label">Date</div>
                                <div class="detail-value"><?php echo date('j M Y', strtotime($result['completed_at'])); ?></div>
                            </div>
                            <div class="detail-card">
                                <div class="detail-label">Level</div>
                                <div class="detail-value"><?php echo htmlspecialchars($level[0]); ?></div>
                            </div>
                            <div class="detail-card">
                                <div class="detail-label">Session</div>
                                <div class="detail-value">#<?php echo htmlspecialchars(substr($result['test_session'], -8)); ?></div>
                            </div>
                        </div>

                        <div class="session-stamp">
                            <i class="fas fa-circle-check"></i>
                            Verified on <?php echo date('j M Y, H:i'); ?>
                        </div>
                    </div>
                </div>
                <div class="disclaimer-bar">
                    <p class="disclaimer-text">
                        <i class="fas fa-info-circle"></i>
                        This is a practice assessment result. It is not an official TOEIC&reg; score report.
                        TOEIC&reg; is a registered trademark of Educational Testing Service (ETS).
                    </p>
                </div>
            </div>

        <?php elseif ($session && !$result): ?>
            <div class="verify-card">
                <div class="verified-banner error-banner">
                    <div class="verified-badge error-badge"><i class="fas fa-xmark"></i></div>
                    <span class="verified-text error-text">Certificate could not be verified</span>
                </div>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-icon" style="background: #fee2e2; color: #f87171;">
                            <i class="fas fa-file-circle-xmark"></i>
                        </div>
                        <h4>Result Not Found</h4>
                        <p>The certificate ID does not match any record in our system.<br>Please double-check the link and try again.</p>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="verify-card">
                <div class="search-section">
                    <div class="search-label">
                        <i class="fas fa-search"></i>
                        Verify by Certificate ID
                    </div>
                    <form method="GET" class="search-form" id="searchForm">
                        <div class="search-input-wrapper">
                            <input
                                type="text"
                                name="certificate"
                                class="search-input"
                                id="searchInput"
                                placeholder="Enter certificate or session ID"
                                value="<?php echo htmlspecialchars($search_name); ?>"
                                autofocus
                                autocomplete="off">
                            <i class="fas fa-fingerprint search-icon-inside"></i>
                        </div>
                        <button type="submit" class="search-btn" id="searchBtn">
                            <i class="fas fa-arrow-right"></i>
                            <span>Search</span>
                        </button>
                    </form>
                </div>
                <div class="card-body" style="padding-top: 0.5rem;">
                    <div class="empty-state" style="padding: 1.75rem 1.5rem;">
                        <div class="empty-icon" style="background: #eff6ff; color: #1e63d6;">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <h4>Enter a Certificate ID</h4>
                        <p>Use the complete session ID printed on the certificate or scan its QR code.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="verify-footer">
            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</body>
</html>
