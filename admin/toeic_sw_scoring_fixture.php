<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/csrf_helper.php';
require_once '../includes/toeic_sw_helper.php';
require_once '../includes/toeic_sw_scorer.php';
require_once '../includes/toeic_sw_scoring_fixture.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$website_title = getWebsiteTitle();
ensureToeicSwSchema($conn);

$test_session = trim((string)($_GET['session'] ?? ''));
$session = $test_session !== '' ? toeicSwFixtureSession($conn, $test_session) : null;
$status = $test_session !== '' ? toeicSwFixtureStatus($conn, $test_session) : null;

function toeicSwFixtureH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrfMeta() ?>
    <title>TOEIC SW Scoring Fixture - <?php echo toeicSwFixtureH($website_title); ?></title>
    <?php echo getFaviconHTML(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <style>
        .fixture-actions { display: flex; flex-wrap: wrap; gap: .75rem; }
        .fixture-status { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem; }
        .fixture-stat { border: 1px solid rgba(255,255,255,.12); border-radius: 8px; padding: .85rem; }
        .fixture-log { min-height: 170px; max-height: 320px; overflow: auto; white-space: pre-wrap; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content">
            <div class="admin-header">
                <h1><i class="fas fa-vial me-3"></i>TOEIC SW Scoring Fixture</h1>
                <p class="admin-subtitle mb-0">Seed text-only transcripts and written answers, then run the normal AI scoring pipeline item by item.</p>
            </div>

            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <a class="btn btn-outline-light btn-sm" href="toeic_sw_results.php">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                    <?php if ($test_session !== ''): ?>
                        <a class="btn btn-outline-info btn-sm" href="toeic_sw_result_detail.php?session=<?php echo urlencode($test_session); ?>">
                            Detail
                        </a>
                    <?php endif; ?>
                </div>

                <div class="content-card mb-4">
                    <label class="form-label fw-bold" for="fixtureSession">TOEIC SW session</label>
                    <div class="input-group mb-3">
                        <input id="fixtureSession" class="form-control" value="<?php echo toeicSwFixtureH($test_session); ?>" placeholder="toeic_sw_YYYYMMDD_HHMMSS_...">
                        <button id="openSession" class="btn btn-outline-light" type="button">Open</button>
                    </div>
                    <div class="small text-muted">
                        Fixture ini menimpa transcript speaking dan jawaban writing pada sesi yang sudah selesai. Kontennya sengaja mirip dengan prompt, tetapi tidak identik, agar lebih dekat dengan respons test nyata.
                    </div>
                </div>

                <?php if ($test_session !== '' && !$session): ?>
                    <div class="alert alert-danger">Session not found.</div>
                <?php endif; ?>

                <?php if ($session): ?>
                    <div class="content-card mb-4">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="text-muted small text-uppercase">Session</div>
                                <code><?php echo toeicSwFixtureH($test_session); ?></code>
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted small text-uppercase">User</div>
                                <div class="fw-bold"><?php echo (int)$session['user_id']; ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted small text-uppercase">Package</div>
                                <div class="fw-bold"><?php echo (int)$session['package_number']; ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted small text-uppercase">Status</div>
                                <div class="fw-bold"><?php echo toeicSwFixtureH($session['status']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small text-uppercase">Current Score</div>
                                <div class="fw-bold" id="scoreText">
                                    <?php
                                    $result = $status['result'] ?? null;
                                    echo $result ? ('S ' . (int)$result['speaking_scaled'] . ' / W ' . (int)$result['writing_scaled'] . ' / Total ' . (int)$result['total_score']) : '-';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card mb-4">
                        <div class="fixture-actions mb-3">
                            <button id="seedFixture" class="btn btn-warning" type="button">
                                <i class="fas fa-pen-nib me-2"></i>Seed Text Fixture
                            </button>
                            <button id="scoreFixture" class="btn btn-primary" type="button">
                                <i class="fas fa-play me-2"></i>Score Pending Items
                            </button>
                            <button id="refreshStatus" class="btn btn-outline-light" type="button">
                                <i class="fas fa-rotate me-2"></i>Refresh
                            </button>
                        </div>

                        <div class="fixture-status mb-3">
                            <div class="fixture-stat">
                                <div class="text-muted small text-uppercase">Scored</div>
                                <div class="h4 mb-0" id="countScored"><?php echo (int)($status['counts']['scored'] ?? 0); ?></div>
                            </div>
                            <div class="fixture-stat">
                                <div class="text-muted small text-uppercase">Needs Rescore</div>
                                <div class="h4 mb-0" id="countNeeds"><?php echo (int)($status['counts']['needs_rescore'] ?? 0); ?></div>
                            </div>
                            <div class="fixture-stat">
                                <div class="text-muted small text-uppercase">Feedback Rows</div>
                                <div class="h4 mb-0" id="countTotal"><?php echo (int)($status['counts']['total'] ?? 0); ?></div>
                            </div>
                        </div>

                        <pre id="fixtureLog" class="fixture-log p-3 bg-dark text-light rounded">Ready.</pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
const sessionInput = document.getElementById('fixtureSession');
const logBox = document.getElementById('fixtureLog');
const buttons = ['seedFixture', 'scoreFixture', 'refreshStatus'].map(id => document.getElementById(id)).filter(Boolean);

function logLine(message) {
    if (!logBox) return;
    const time = new Date().toLocaleTimeString();
    logBox.textContent += `\n[${time}] ${message}`;
    logBox.scrollTop = logBox.scrollHeight;
}

function setBusy(isBusy) {
    buttons.forEach(button => button.disabled = isBusy);
}

function currentSession() {
    return (sessionInput?.value || '').trim();
}

function renderStatus(data) {
    const counts = data.counts || {};
    const result = data.result || null;
    const scored = document.getElementById('countScored');
    const needs = document.getElementById('countNeeds');
    const total = document.getElementById('countTotal');
    const score = document.getElementById('scoreText');
    if (scored) scored.textContent = counts.scored ?? 0;
    if (needs) needs.textContent = counts.needs_rescore ?? 0;
    if (total) total.textContent = counts.total ?? 0;
    if (score && result) {
        score.textContent = `S ${Number(result.speaking_scaled || 0)} / W ${Number(result.writing_scaled || 0)} / Total ${Number(result.total_score || 0)}`;
    }
}

async function fixturePost(action) {
    const response = await fetch('ajax_toeic_sw_scoring_fixture.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({
            csrf_token: csrfToken,
            action,
            test_session: currentSession(),
        }),
    });
    const data = await response.json();
    if (!data.success) {
        throw new Error(data.error || 'Request failed');
    }
    renderStatus(data);
    return data;
}

document.getElementById('openSession')?.addEventListener('click', () => {
    const session = currentSession();
    if (session) {
        window.location.href = `toeic_sw_scoring_fixture.php?session=${encodeURIComponent(session)}`;
    }
});

document.getElementById('refreshStatus')?.addEventListener('click', async () => {
    try {
        setBusy(true);
        const data = await fixturePost('status');
        logLine(`Status refreshed: ${data.counts?.needs_rescore || 0} pending.`);
    } catch (error) {
        logLine(`ERROR: ${error.message}`);
    } finally {
        setBusy(false);
    }
});

document.getElementById('seedFixture')?.addEventListener('click', async () => {
    try {
        setBusy(true);
        if (logBox) logBox.textContent = 'Seeding text-only fixture...';
        const data = await fixturePost('seed');
        logLine(`Seeded ${data.speaking || 0} speaking transcripts and ${data.writing || 0} writing answers.`);
        logLine(`${data.different || 0} seeded responses passed the not-identical check.`);
    } catch (error) {
        logLine(`ERROR: ${error.message}`);
    } finally {
        setBusy(false);
    }
});

document.getElementById('scoreFixture')?.addEventListener('click', async () => {
    try {
        setBusy(true);
        logLine('Scoring pending items with the normal TOEIC SW scorer...');
        let safety = 0;
        while (safety < 25) {
            safety++;
            const data = await fixturePost('score_next');
            if (!data.processed) {
                logLine('No pending scoring item remains.');
                break;
            }
            logLine(`Scored ${data.section} Q${data.question_order}: normalized ${data.normalized_score}. Pending ${data.counts?.needs_rescore || 0}.`);
            if (data.scored_status !== 'scored') {
                logLine(`Stopped because the scorer kept this item as ${data.scored_status || 'unknown'}: ${data.fallback_reason || 'no reason returned'}`);
                break;
            }
            if ((data.counts?.needs_rescore || 0) <= 0) {
                logLine('All fixture items are scored.');
                break;
            }
        }
        if (safety >= 25) {
            logLine('Stopped after safety limit; refresh and continue if items remain.');
        }
    } catch (error) {
        logLine(`ERROR: ${error.message}`);
    } finally {
        setBusy(false);
    }
});
</script>
</body>
</html>
