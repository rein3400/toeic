<?php
/**
 * Admin balance tool: fix option-length leak in TOEIC bank.
 *
 * Run in browser:
 *   /admin/balance_option_lengths.php
 *
 * Shows:
 *   - total flagged soal
 *   - preview 5 soal (lama vs baru)
 *   - button "Apply to Database" that does the update in a transaction
 *
 * Safety:
 *   - Refuses to apply if any test session is active.
 *   - Uses transaction: rollback on any error.
 *   - Works on toeic_soal_listening and toeic_soal_reading.
 *   - Snapshots in toeic_test_questions keep finished tests safe.
 */

require_once __DIR__ . '/../includes/session_handler.php';
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Admin login required.');
}

// -------- Helper functions --------
function median(array $xs): float {
    if (!$xs) return 0.0;
    sort($xs);
    $n = count($xs);
    return $n % 2 ? (float)$xs[(int)floor($n / 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
}

function classify(string $q, bool $blankD): string {
    if ($blankD) return 'listening_part2';
    if (str_contains($q, 'Choose the statement that best describes the photo')) return 'listening_part1';
    return 'formal';
}

function normalizeEnd(string $s): string {
    $s = rtrim($s);
    // Strip trailing punctuation so appended phrase can close the sentence cleanly.
    return preg_replace('/[.,;:!?]+$/u', '', $s);
}

function pickPhrase(array $pool, int $qi): string {
    return $pool[$qi % count($pool)];
}

// Phrase format: ", text." (comma leading, period closing). Append via
// normalizeEnd(base) . phrase  →  "laptop., as shown..." becomes "laptop, as shown...".
$pools = [
    'listening_part1' => [
        ', as shown in the image.',
        ', in the foreground.',
        ', in the scene.',
        ', as displayed.',
        ' in this setting.',
        ' throughout the workspace.',
        ' in the picture.',
        ', at the location.',
    ],
    'listening_part2' => [
        ', as she mentioned.',
        ', like he said.',
        ', I think.',
        ' for now.',
        ', in that case.',
        ' if that works.',
        ', the way I see it.',
        ', based on what we know.',
    ],
    'formal' => [
        ', as described in the notice.',
        ', according to the manager.',
        ', for this transaction.',
        ', based on the latest policy.',
        ', pending further review.',
        ' over the standard review window.',
        ' on the proposed schedule.',
        ' as currently written.',
        ', under the current guidelines.',
        ', in the attached memorandum.',
        ', before the stated deadline.',
        ', unless otherwise directed.',
    ],
];

// Load all questions directly from the DB (no CSV, no a.txt needed).
$rows = [];
foreach ([['table' => 'toeic_soal_listening'],
          ['table' => 'toeic_soal_reading']] as $src) {
    $tbl = $src['table'];
    $res = $conn->query("SELECT id_soal AS qid, pertanyaan AS question, opsi_a, opsi_b, opsi_c, opsi_d, jawaban_benar AS answer FROM $tbl");
    if (!$res) continue;
    while ($row = $res->fetch_assoc()) {
        $row['table'] = $tbl;
        $rows[] = $row;
    }
}

// Build patches
$patches = [];
$skipped = [];
$qiGlobal = 0;
foreach ($rows as $r) {
    $qid = (int)$r['qid'];
    $ans = $r['answer'];
    $q = $r['question'];
    // DB cols are opsi_a/b/c/d. Coalesce NULL → '' for PHP 8.1+ trim safety.
    $a = (string)($r['opsi_a'] ?? '');
    $b = (string)($r['opsi_b'] ?? '');
    $c = (string)($r['opsi_c'] ?? '');
    $d = (string)($r['opsi_d'] ?? '');
    if ($a === '' || $b === '' || $c === '') {
        $skipped[$qid] = 'missing option text';
        continue;
    }
    $blankD = trim($d) === '';
    $sec = classify($q, $blankD);
    $pool = $pools[$sec];

    $opts = ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d];
    if (!isset($opts[$ans]) || $opts[$ans] === '') {
        $skipped[$qid] = 'missing correct text';
        continue;
    }

    $distractorKeys = array_values(array_filter(array_keys($opts), fn($k) => $k !== $ans && (!$blankD || $k !== 'D')));
    if (count($distractorKeys) < 2) {
        $skipped[$qid] = 'too few distractors';
        continue;
    }

    $correctLen = mb_strlen($opts[$ans]);
    $medianOld = median(array_map(fn($k) => mb_strlen($opts[$k]), $distractorKeys));
    if ($medianOld === 0.0 || $correctLen <= $medianOld * 1.3) continue;

    $tolerance = $sec === 'listening_part2' ? 1.5 : 1.3;
    $targetLen = (int)ceil($correctLen / $tolerance);
    $cap = (int)ceil($correctLen * 1.05);

    $newOpts = $opts;
    $qi = $qiGlobal;
    // Pad each distractor: normalize trailing punctuation on the current
    // string, then append the next phrase. Each phrase is self-contained
    // (", text.") so chained appends read cleanly: "laptop, in the scene,
    // as displayed."
    foreach ($distractorKeys as $dk) {
        $buf = $opts[$dk];
        $appends = 0;
        while (mb_strlen($buf) < $targetLen && mb_strlen($buf) < $cap && $appends < 10) {
            $buf = normalizeEnd($buf) . pickPhrase($pool, $qi);
            $qi++;
            $appends++;
        }
        $newOpts[$dk] = $buf;
    }

    // bump if still short: add one more phrase to smallest distractors
    $lens = array_map(fn($k) => mb_strlen($newOpts[$k]), $distractorKeys);
    sort($lens);
    $m = $lens[(int)floor(count($lens) / 2)] ?? 0;
    if ($m === 0 || $correctLen > $m * $tolerance) {
        foreach ($distractorKeys as $dk) {
            if (mb_strlen($newOpts[$dk]) < $cap) {
                $newOpts[$dk] = normalizeEnd($newOpts[$dk]) . pickPhrase($pool, $qi);
                $qi++;
            }
        }
    }

    $finalLens = array_map(fn($k) => mb_strlen($newOpts[$k]), $distractorKeys);
    sort($finalLens);
    $finalMedian = $finalLens[(int)floor(count($finalLens) / 2)] ?? 0;
    $maxDist = max($finalLens);
    $reasons = [];
    if ($finalMedian === 0 || $correctLen > $finalMedian * $tolerance) {
        $reasons[] = 'correct still too long';
    }
    if ($maxDist > 0 && $correctLen < $maxDist / 1.3) {
        $reasons[] = 'reverse-leak';
    }
    if ($reasons) {
        $skipped[$qid] = implode(' + ', $reasons);
        continue;
    }
    if ($newOpts === $opts) continue;

    $patches[$qid] = [
        'table' => $r['table'],
        'sec' => $sec,
        'q' => $q,
        'ans' => $ans,
        'old' => $opts,
        'new' => $newOpts,
    ];
    $qiGlobal = $qi;
}

// Apply mode
$applied = null;
$applyError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {
    $active = 0;
    $res = $conn->query("SELECT COUNT(*) AS n FROM toeic_test_sessions WHERE status IN ('active','in_progress')");
    if ($res) $active = (int)$res->fetch_assoc()['n'];

    if ($active > 0) {
        $applyError = "Tidak bisa apply: masih ada $active sesi aktif.";
    } elseif (empty($patches)) {
        $applyError = 'Tidak ada soal yang perlu diperbaiki.';
    } else {
        try {
            $conn->begin_transaction();
            $updated = 0;
            foreach ($patches as $qid => $p) {
                $tbl = $p['table'];
                $new = $p['new'];
                $stmt = $conn->prepare("UPDATE $tbl SET opsi_a=?, opsi_b=?, opsi_c=?, opsi_d=? WHERE id_soal=?");
                $stmt->bind_param('ssssi', $new['A'], $new['B'], $new['C'], $new['D'], $qid);
                $stmt->execute();
                $updated += $stmt->affected_rows;
                $stmt->close();
            }
            $conn->commit();
            $applied = $updated;
        } catch (Throwable $e) {
            $conn->rollback();
            $applyError = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Balance Option Lengths</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 20px; background: #f5f5f5; }
        .wrap { max-width: 900px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; }
        h1 { margin-top: 0; }
        .stats { display: flex; gap: 20px; margin: 20px 0; }
        .stat { background: #eee; padding: 10px 15px; border-radius: 6px; }
        .stat b { display: block; font-size: 1.4em; }
        .card { border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin: 10px 0; }
        .card b { color: #c00; }
        .correct { color: #080; font-weight: bold; }
        .old { color: #666; }
        .new { color: #000; }
        button { background: #0066cc; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 1em; }
        button:hover { background: #0055aa; }
        .danger { background: #c00; }
        .danger:hover { background: #900; }
        .note { background: #fffbe6; border-left: 4px solid #f0ad4e; padding: 10px; margin: 15px 0; }
        .error { background: #fee; border-left: 4px solid #c00; padding: 10px; }
        .ok { background: #efe; border-left: 4px solid #0a0; padding: 10px; }
        table { border-collapse: collapse; width: 100%; margin-top: 8px; }
        td, th { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Balance Opsi Panjang</h1>
    <p>Tool ini memperbaiki soal yang memiliki opsi benar terlalu panjang dibanding pilihan salah. Siswa bisa menebak jawaban dengan memilih opsi terpanjang.</p>

    <div class="stats">
        <div class="stat"><b><?= count($rows) ?></b>Soal flagged</div>
        <div class="stat"><b><?= count($patches) ?></b>Akan diperbaiki</div>
        <div class="stat"><b><?= count($skipped) ?></b>Dilewatkan</div>
    </div>

    <div class="note">
        <strong>Keamanan:</strong> Test history siswa tetap aman karena setiap sesi menyimpan snapshot soal di tabel <code>toiec_test_questions</code>. Tool akan menolak apply jika masih ada sesi yang sedang berjalan.
    </div>

    <?php if ($applied !== null): ?>
        <div class="ok">Berhasil update <?= $applied ?> soal. Refresh halaman ini untuk melihat hasil terbaru.</div>
    <?php elseif ($applyError): ?>
        <div class="error"><?= htmlspecialchars($applyError) ?></div>
    <?php endif; ?>

    <?php if ($patches): ?>
    <h3>Preview 5 soal yang akan diperbaiki</h3>
    <?php $i=0; foreach (array_slice($patches, 0, 5, true) as $qid => $p): $i++; ?>
        <div class="card">
            <strong>#QID <?= $qid ?> [<?= htmlspecialchars($p['sec']) ?>]</strong><br>
            <?=
                htmlspecialchars($p['q']) ?>
            <table>
                <tr><th></th><th>Lama</th><th>Baru</th></tr>
                <?php foreach (['A','B','C','D'] as $L): ?>
                <tr>
                    <td><?= $L ?><?= $L === $p['ans'] ? ' ✓' : '' ?></td>
                    <td class="old"><?= htmlspecialchars($p['old'][$L]) ?> <span>(<?= mb_strlen($p['old'][$L]) ?>)</span></td>
                    <td class="new"><?= htmlspecialchars($p['new'][$L]) ?> <span>(<?= mb_strlen($p['new'][$L]) ?>)</span><?= $p['old'][$L] !== $p['new'][$L] ? ' <b>[ubah]</b>' : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endforeach; ?>

    <form method="post" onsubmit="return confirm('Yakin update production DB? Pastikan tidak ada sesi aktif.');">
        <button type="submit" name="apply" class="danger">Apply <?= count($patches) ?> perubahan ke Database</button>
    </form>
    <?php else: ?>
        <div class="ok">Tidak ada soal yang perlu diperbaiki.</div>
    <?php endif; ?>
</div>
</body>
</html>
